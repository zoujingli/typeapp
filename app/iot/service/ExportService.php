<?php

declare(strict_types=1);

namespace app\iot\service;

use app\common\service\AuditLog;
use app\common\service\IdentityService;
use app\common\service\RoleService;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Orm\Connection;
use Type\Orm\Outbox\Store;
use Type\Queue\JobContext;
use Type\Runtime\ExecutionScope;

/** 历史导出的业务事实与文件所有者；固定快照、可恢复进度和当前授权不交给浏览器或Redis保存。 */
final class ExportService
{
    private const MAX_ROWS = 100000;
    private const MAX_BYTES = 104857600;
    private string $directory;

    /** 文件根由启动配置解析；所有执行角色须共享该私有目录，不对外提供静态下载路由。 */
    public function __construct(string $directory)
    {
        if ($directory === '' || str_contains($directory, "\0")) {
            throw new InvalidArgumentException('导出文件目录无效');
        }
        $this->directory = rtrim($directory, '/\\');
    }

    /**
     * 当前筛选覆盖全部匹配行，不接受页面游标；单条INSERT SELECT冻结一次数据库语句看到的事实与模型。
     * 先作保守字节估算，快照后再核验实际计数；并发插入不会变成被截断的成功任务。
     * @param array<string, mixed> $filters 已应用的历史筛选，不含分页和曲线统计。
     * @return array<string, mixed> 服务端任务及冻结筛选。
     * @throws HttpError 无权限、筛选无效、预计超限或保留任务容量已满。
     */
    public function create(Connection $connection, Identity $identity, string $tenantId, string $deviceId, string $kind, array $filters, string $timezone, string $id): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new HttpError(422, 'export_identity_invalid');
        }
        if (!in_array($kind, ['records', 'minutes'], true) || array_intersect(array_keys($filters), ['page', 'per_page', 'cursor']) !== []) {
            throw new HttpError(422, 'export_filter_invalid');
        }
        try {
            new DateTimeZone($timezone);
        } catch (\Exception $invalidZone) {
            throw new HttpError(422, 'export_timezone_invalid');
        }
        if (strlen($timezone) > 100) {
            throw new HttpError(422, 'export_timezone_invalid');
        }
        HistoryService::filters($filters);
        $sort = (string) ($filters['sort'] ?? 'sampled_desc');
        if (!in_array($sort, $kind === 'minutes' ? ['sampled_asc', 'sampled_desc'] : ['sampled_asc', 'sampled_desc', 'received_asc', 'received_desc'], true)) {
            throw new HttpError(422, 'export_filter_invalid');
        }
        $window = $kind === 'minutes' ? AggregateService::window($filters) : HistoryService::window($filters);
        $frozen = $filters + ['sort' => $sort, 'from' => $window['from'], 'to' => $window['to']];
        ksort($frozen);
        $requestHash = hash('sha256', json_encode([$tenantId, $deviceId, $kind, $frozen, $timezone], JSON_THROW_ON_ERROR));
        return $connection->transaction(function (Connection $transaction) use ($identity, $tenantId, $deviceId, $kind, $frozen, $window, $timezone, $id, $requestHash): array {
            RoleService::lockAuthorization();
            self::capacityLock($transaction);
            $context = self::authorize($transaction, $identity, $tenantId, 'create');
            $existing = $transaction->table('iot_exports')->where('id', '=', $id)->first();
            if ($existing !== null) {
                if ($existing['scope_key'] !== $context['identity']['key'] || !hash_equals($existing['request_hash'], $requestHash)) {
                    throw new HttpError(409, 'export_identity_conflict');
                }
                return self::decode($existing);
            }
            HistoryService::requireDeviceScope($transaction, $tenantId, $deviceId);
            // 文件与快照都计入保留任务额度，避免快速完成后在24小时内无限累积磁盘。
            $retained = $transaction->table('iot_exports')->where('expires_at', '>', time());
            if ((int) $retained->aggregate('COUNT') >= 100 || (int) $retained->where('tenant_id', '=', $tenantId)->aggregate('COUNT') >= 20) {
                throw new HttpError(429, 'export_capacity_exceeded');
            }
            $source = self::source($transaction, $tenantId, $deviceId, $kind, $frozen, $window);
            $estimated = $transaction->query('SELECT COUNT(*) AS total, COALESCE(SUM(' . $source['bytes'] . '), 0) AS bytes FROM ' . $source['from'] . ' WHERE ' . $source['where'], $source['parameters'])[0];
            if ((int) $estimated['total'] > self::MAX_ROWS || (int) $estimated['bytes'] + 4096 > self::MAX_BYTES) {
                throw new HttpError(422, 'export_limit_exceeded');
            }
            $now = time();
            $transaction->table('iot_exports')->insert(['id' => $id, 'tenant_id' => $tenantId, 'device_id' => $deviceId,
                'actor_id' => $identity->subject(), 'source_context' => json_encode($context['identity'], JSON_THROW_ON_ERROR), 'scope_key' => $context['identity']['key'],
                'request_hash' => $requestHash, 'kind' => $kind, 'filters_json' => json_encode($frozen, JSON_THROW_ON_ERROR), 'timezone' => $timezone,
                'status' => 'queued', 'error_code' => '', 'total_rows' => 0, 'completed_rows' => 0, 'estimated_bytes' => 0,
                'file_bytes' => 0, 'file_hash' => '', 'step' => 0, 'last_time' => 0, 'last_id' => '', 'created_at' => $now, 'updated_at' => $now, 'expires_at' => $now + 86400]);
            $transaction->execute('INSERT INTO iot_export_rows (export_id, source_id, position_time, product_id, model_version, ownership_id, sampled_at, received_at, ended_at, sequence, current_advanced, values_json, model_definition) SELECT ?, '
                . $source['columns'] . ' FROM ' . $source['from'] . ' WHERE ' . $source['where'] . ' LIMIT 100001', [$id, ...$source['parameters']]);
            $length = $transaction->driverName() === 'sqlite' ? 'LENGTH(CAST(values_json AS BLOB)) + LENGTH(CAST(model_definition AS BLOB))' : 'OCTET_LENGTH(values_json) + OCTET_LENGTH(model_definition)';
            $actual = $transaction->query('SELECT COUNT(*) AS total, COALESCE(SUM(2 * (' . $length . ') + 640), 0) AS bytes FROM iot_export_rows WHERE export_id = ?', [$id])[0];
            if ((int) $actual['total'] > self::MAX_ROWS || (int) $actual['bytes'] + 4096 > self::MAX_BYTES) {
                throw new HttpError(422, 'export_limit_exceeded');
            }
            $transaction->table('iot_exports')->where('id', '=', $id)->update(['total_rows' => (int) $actual['total'], 'estimated_bytes' => (int) $actual['bytes'] + 4096]);
            self::intent($transaction, $id, 0);
            AuditLog::append($transaction, $tenantId, $identity, 'export.create', $id, 'success', [], 'customer');
            return self::decode($transaction->table('iot_exports')->where('id', '=', $id)->first());
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /** @return array<string, mixed> 当前租户及准确会话来源的有界任务列表；失效文件明确显示expired。 */
    public function search(Connection $connection, Identity $identity, string $tenantId, int $page, int $perPage): array
    {
        $context = self::authorize($connection, $identity, $tenantId, 'read');
        $result = $connection->table('iot_exports')->where('tenant_id', '=', $tenantId)->where('scope_key', '=', $context['identity']['key'])->orderBy('created_at', 'DESC')->orderBy('id')->paginate($page, $perPage);
        $items = [];
        foreach ($result->items() as $row) {
            $items[] = self::decode($row);
        }
        return ['items' => $items, 'total' => $result->total(), 'page' => $result->number(), 'per_page' => $result->perPage(), 'context' => $context];
    }

    /**
     * 取消与每次分块写入锁同一业务行；确认提交之后删除私有文件，未知提交交给有界清理恢复。
     * @return array<string, mixed> 实际状态。
     */
    public function cancel(Connection $connection, Identity $identity, string $tenantId, string $id): array
    {
        self::authorize($connection, $identity, $tenantId, 'cancel');
        $result = $connection->transaction(function (Connection $transaction) use ($identity, $tenantId, $id): array {
            RoleService::lockAuthorization();
            $row = self::record($transaction, $tenantId, $id, true);
            $context = self::authorize($transaction, $identity, $tenantId, 'cancel');
            self::requireOrigin($row, $context);
            if ($row['status'] === 'cancelled') {
                return self::decode($row);
            }
            if (!in_array($row['status'], ['queued', 'running'], true)) {
                throw new HttpError(409, 'export_not_cancelable');
            }
            $transaction->table('iot_exports')->where('id', '=', $id)->update(['status' => 'cancelled', 'error_code' => '', 'updated_at' => time()]);
            AuditLog::append($transaction, $tenantId, $identity, 'export.cancel', $id, 'success', [], 'customer');
            return self::decode($transaction->table('iot_exports')->where('id', '=', $id)->first());
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
        $this->removeFile($id);
        return $result;
    }

    /**
     * 卡住或隔离的任务显式恢复现有进度，不重查筛选、不扩展24小时期限；旧步骤因编号和消费凭据失效。
     * @return array<string, mixed> 恢复后的任务。
     */
    public function resume(Connection $connection, Identity $identity, string $tenantId, string $id): array
    {
        self::authorize($connection, $identity, $tenantId, 'create');
        return $connection->transaction(function (Connection $transaction) use ($identity, $tenantId, $id): array {
            RoleService::lockAuthorization();
            self::capacityLock($transaction);
            $row = self::record($transaction, $tenantId, $id, true);
            $context = self::authorize($transaction, $identity, $tenantId, 'create');
            self::requireOrigin($row, $context);
            if (!in_array($row['status'], ['queued', 'running'], true) || (int) $row['updated_at'] > time() - 60 || (int) $row['expires_at'] <= time()) {
                throw new HttpError(409, 'export_not_resumable');
            }
            $step = (int) $row['step'] + 1;
            $transaction->table('iot_exports')->where('id', '=', $id)->update(['status' => 'queued', 'step' => $step, 'updated_at' => time()]);
            self::intent($transaction, $id, $step);
            AuditLog::append($transaction, $tenantId, $identity, 'export.resume', $id, 'success', [], 'customer');
            return self::decode($transaction->table('iot_exports')->where('id', '=', $id)->first());
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /**
     * 当前用户权限、不可变租户快照和有效期每次重验；返回的路径只交给HTTP流工厂，不能形成公开静态链接。
     * @return array{path: string, bytes: int, name: string}
     */
    public function download(Connection $connection, Identity $identity, string $tenantId, string $id): array
    {
        $context = self::authorize($connection, $identity, $tenantId, 'download');
        $row = self::record($connection, $tenantId, $id);
        self::requireOrigin($row, $context);
        if ((int) $row['expires_at'] <= time()) {
            throw new HttpError(410, 'export_expired');
        }
        if ($row['status'] !== 'succeeded') {
            throw new HttpError(409, 'export_not_ready');
        }
        $path = $this->path($id);
        if (!is_file($path) || filesize($path) !== (int) $row['file_bytes'] || !hash_equals($row['file_hash'], hash_file('sha256', $path))) {
            throw new HttpError(409, 'export_file_unavailable');
        }
        // 摘要校验可能等待文件I/O，取得下载流前再次核验来源和当前权限。
        self::authorize($connection, $identity, $tenantId, 'download');
        // 归属证明来自创建时的租户快照，设备转移和源数据到期不会将文件转给新租户。
        // 设备、产品、模型与归属阶段由服务端固定，下载参数不能重写。
        return ['path' => $path, 'bytes' => (int) $row['file_bytes'], 'name' => 'history-' . $row['kind'] . '-' . $id . '.csv'];
    }

    /**
     * 队列公开Job入口每次推进至多100行；文件先持久化再提交偏移，重投从已提交偏移截断恢复。
     * 文件非阻塞排他锁排除同任务并行写入；数据库行锁排除取消/恢复与写入交错，Outbox与进度同事务提交。
     * @param array{id: string, step: int} $payload 消息仅携带服务端任务身份。
     */
    public function advance(Connection $connection, JobContext $context, array $payload): void
    {
        if (!is_string($payload['id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $payload['id']) || !is_int($payload['step'] ?? null)) {
            throw new RuntimeException('export_message_invalid');
        }
        $id = $payload['id'];
        $admitted = $connection->transaction(static function (Connection $transaction) use ($payload, $context, $id): string {
            RoleService::lockAuthorization();
            self::capacityLock($transaction);
            $query = $transaction->table('iot_exports')->where('id', '=', $id);
            $row = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
            if ($row === null || !in_array($row['status'], ['queued', 'running'], true) || (int) $row['step'] !== $payload['step']) {
                (new Store('iot_export_outbox'))->consumed($transaction, $context->message()->id(), 'export-terminal-or-superseded');
                return 'skip';
            }
            if (!self::sourceAuthorized($transaction, $row)) {
                self::revoked($transaction, $row, $context);
                return 'revoked';
            }
            return $context->scope()->run(static function (ExecutionScope $scope) use ($transaction, $query, $row): string {
                $active = $transaction->table('iot_exports')->where('status', '=', 'running');
                if ($row['status'] === 'queued' && ((int) $active->aggregate('COUNT') >= 10 || (int) $active->where('tenant_id', '=', $row['tenant_id'])->aggregate('COUNT') >= 2)) {
                    throw new RuntimeException('export_waiting_for_capacity');
                }
                $query->update(['status' => 'running']);
                return 'ready';
            }, ['tenant_id' => (string) $row['tenant_id']]);
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
        if ($admitted !== 'ready') {
            if ($admitted === 'revoked') {
                $this->removeFile($id);
            }
            return;
        }
        $terminal = $connection->transaction(function (Connection $transaction) use ($context, $payload, $id): bool {
            $context->assertActive();
            RoleService::lockAuthorization();
            $query = $transaction->table('iot_exports')->where('id', '=', $id);
            $row = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
            $store = new Store('iot_export_outbox');
            if ($row === null || !in_array($row['status'], ['queued', 'running'], true)) {
                $store->consumed($transaction, $context->message()->id(), 'export-terminal');
                return $row === null || $row['status'] !== 'succeeded';
            }
            if ((int) $row['step'] !== $payload['step']) {
                $store->consumed($transaction, $context->message()->id(), 'export-superseded');
                return false;
            }
            if ((int) $row['expires_at'] <= time()) {
                $query->update(['status' => 'expired', 'updated_at' => time()]);
                $store->consumed($transaction, $context->message()->id(), 'export-expired');
                return true;
            }
            if (!self::sourceAuthorized($transaction, $row)) {
                self::revoked($transaction, $row, $context);
                return true;
            }
            // 只绑定刚刚重验的服务端来源；消息关联值不参与授权，异常也恢复外层绑定。
            return $context->scope()->run(function (ExecutionScope $scope) use ($store, $transaction, $context, $payload, $id, $row, $query): bool {
                if (!$store->consumed($transaction, $context->message()->id(), 'export-step:' . $payload['step'])) {
                    return false;
                }
                // 业务行锁内先验证终态再创建文件，取消/清理之后的旧任务不会重新留下空文件。
                if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
                    throw new RuntimeException('export_storage_unavailable');
                }
                $file = @fopen($this->path($id), 'c+b');
                if ($file === false) {
                    throw new RuntimeException('export_storage_unavailable');
                }
                try {
                    if (!flock($file, LOCK_EX | LOCK_NB)) {
                        throw new RuntimeException('export_file_busy');
                    }
                    $filters = json_decode($row['filters_json'], true, 12, JSON_THROW_ON_ERROR);
                    $descending = str_ends_with($filters['sort'], 'desc');
                    $where = 'export_id = ?';
                    $parameters = [$id];
                    if ($row['last_id'] !== '') {
                        $operator = $descending ? '<' : '>';
                        $where .= ' AND (position_time ' . $operator . ' ? OR (position_time = ? AND source_id ' . $operator . ' ?))';
                        array_push($parameters, (int) $row['last_time'], (int) $row['last_time'], $row['last_id']);
                    }
                    $direction = $descending ? 'DESC' : 'ASC';
                    $rows = $transaction->query('SELECT * FROM iot_export_rows WHERE ' . $where . ' ORDER BY position_time ' . $direction . ', source_id ' . $direction . ' LIMIT 100', $parameters);
                    $buffer = (int) $row['file_bytes'] === 0 ? "\xEF\xBB\xBF" . self::csv(['数据类型', '记录标识', '设备标识', '产品标识', '模型版本', '归属阶段', '采样时间', '首次接收或最近修正时间', '分钟结束', '业务序号', '当前值推进', '属性或六项统计', '当时物模型', '时区']) : '';
                    $zone = new DateTimeZone($row['timezone']);
                    foreach ($rows as $snapshot) {
                        $context->assertActive();
                        $buffer .= self::line($snapshot, $row, $zone);
                    }
                    $completed = (int) $row['completed_rows'] + count($rows);
                    $bytes = (int) $row['file_bytes'] + strlen($buffer);
                    if ($completed > self::MAX_ROWS || $bytes > self::MAX_BYTES || ($rows === [] && $completed !== (int) $row['total_rows'])) {
                        $query->update(['status' => 'failed', 'error_code' => 'export_limit_or_snapshot_invalid', 'updated_at' => time()]);
                        return true;
                    }
                    $offset = (int) $row['file_bytes'];
                    $size = fstat($file)['size'];
                    if ($size < $offset) {
                        $query->update(['status' => 'failed', 'error_code' => 'export_file_unavailable', 'updated_at' => time()]);
                        return true;
                    }
                    if (!@ftruncate($file, $offset) || @fseek($file, $offset) !== 0) {
                        throw new RuntimeException('export_storage_unavailable');
                    }
                    $written = 0;
                    while ($written < strlen($buffer)) {
                        $context->assertActive();
                        $count = @fwrite($file, substr($buffer, $written));
                        if ($count === false || $count === 0) {
                            throw new RuntimeException('export_storage_unavailable');
                        }
                        $written += $count;
                    }
                    if (!@fflush($file) || !@fsync($file)) {
                        throw new RuntimeException('export_storage_unavailable');
                    }
                    $context->assertActive();
                    $done = $completed === (int) $row['total_rows'];
                    $changes = ['status' => $done ? 'succeeded' : 'running', 'completed_rows' => $completed, 'file_bytes' => $bytes,
                        'step' => $payload['step'] + 1, 'updated_at' => time(), 'file_hash' => $done ? hash_file('sha256', $this->path($id)) : ''];
                    if ($done) {
                        $changes['expires_at'] = time() + 86400;
                    }
                    if ($rows !== []) {
                        $last = $rows[count($rows) - 1];
                        $changes['last_time'] = (int) $last['position_time'];
                        $changes['last_id'] = $last['source_id'];
                    }
                    $query->update($changes);
                    if (!$done) {
                        self::intent($transaction, $id, $payload['step'] + 1);
                    }
                    return false;
                } finally {
                    flock($file, LOCK_UN);
                    fclose($file);
                }
            }, ['tenant_id' => (string) $row['tenant_id']]);
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
        if ($terminal) {
            $this->removeFile($id);
        }
    }

    /**
     * 单轮最多100个任务、每任务至多1000条快照；失败/取消和到期资源均可在中断后重复回收。
     * 已消费Outbox使用组件原有保留清理，未确认效果不删除。
     * @return array{tasks: int, rows: int, outbox: int}
     */
    public function clean(Connection $connection, int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('导出清理批次必须为1至100');
        }
        $rows = $connection->query("SELECT id FROM iot_exports e WHERE ((status IN ('failed', 'cancelled', 'expired') OR expires_at <= ?) AND cleaned_at = 0) OR expires_at <= ? ORDER BY expires_at, id LIMIT " . $limit, [time(), time() - 86400]);
        $removed = 0;
        foreach ($rows as $candidate) {
            $deleted = $connection->transaction(function (Connection $transaction) use ($candidate): int {
                $query = $transaction->table('iot_exports')->where('id', '=', $candidate['id']);
                $row = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
                if ($row === null) {
                    return -1;
                }
                // 候选选择后生成可能已成功并获得新的24小时期限，必须在行锁内再次核验。
                if (!in_array($row['status'], ['failed', 'cancelled', 'expired'], true) && (int) $row['expires_at'] > time()) {
                    return -1;
                }
                if ((int) $row['expires_at'] <= time()) {
                    $query->update(['status' => 'expired']);
                }
                $snapshots = $transaction->table('iot_export_rows')->where('export_id', '=', $candidate['id'])->orderBy('source_id')->limit(1000)->get();
                foreach ($snapshots as $snapshot) {
                    $transaction->table('iot_export_rows')->where('export_id', '=', $candidate['id'])->where('source_id', '=', $snapshot['source_id'])->delete();
                }
                return count($snapshots);
            }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
            if ($deleted < 0) {
                continue;
            }
            $removed += $deleted;
            $this->removeFile($candidate['id']);
            $remaining = $connection->table('iot_export_rows')->where('export_id', '=', $candidate['id'])->limit(1)->first();
            $connection->table('iot_exports')->where('id', '=', $candidate['id'])->update(['file_bytes' => 0, 'cleaned_at' => $remaining === null ? time() : 0]);
            if ($remaining === null) {
                // 文件删除确定成功后才移除最后一份资源定位事实，存储失败仍可在下一轮重试。
                $connection->table('iot_exports')->where('id', '=', $candidate['id'])->where('expires_at', '<=', time() - 86400)->delete();
            }
        }
        return ['tasks' => count($rows), 'rows' => $removed, 'outbox' => (new Store('iot_export_outbox'))->collect($connection, $limit)];
    }

    /**
     * 只记录已确定的文件系统失败；数据库未知提交与租约丢失由队列重投/显式恢复，不伪装成确定失败。
     * @param array{id: string, step: int} $payload 当前任务步骤。
     */
    public function fail(Connection $connection, JobContext $context, array $payload, string $code): void
    {
        $failed = $connection->transaction(static function (Connection $transaction) use ($context, $payload, $code): bool {
            $query = $transaction->table('iot_exports')->where('id', '=', $payload['id']);
            $row = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
            if ($row === null || !in_array($row['status'], ['queued', 'running'], true) || (int) $row['step'] !== $payload['step']) {
                return false;
            }
            $query->update(['status' => 'failed', 'error_code' => $code, 'updated_at' => time()]);
            (new Store('iot_export_outbox'))->consumed($transaction, $context->message()->id(), 'export-storage-failed');
            return true;
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
        if ($failed) {
            $this->removeFile($payload['id']);
        }
    }

    private static function source(Connection $connection, string $tenantId, string $deviceId, string $kind, array $filters, array $window): array
    {
        $minutes = $kind === 'minutes';
        [$where, $parameters] = $minutes ? AggregateService::conditions($connection, $tenantId, $deviceId, $filters, $window)
            : HistoryService::conditions($connection, $tenantId, $deviceId, $filters, $window);
        $alias = $minutes ? 'a' : 'f';
        $value = $alias . ($minutes ? '.fields_json' : '.values_json');
        $position = $minutes ? 'a.window_start' : (str_starts_with($filters['sort'], 'received') ? 'f.received_at' : 'f.sampled_at');
        $columns = $minutes ? 'a.id, ' . $position . ', a.product_id, a.model_version, a.ownership_id, a.window_start, a.updated_at, a.window_end, \'\', 0, a.fields_json, m.definition'
            : 'f.message_id, ' . $position . ', f.product_id, f.model_version, f.ownership_id, f.sampled_at, f.received_at, 0, f.sequence, f.current_advanced, f.values_json, m.definition';
        $length = $connection->driverName() === 'sqlite' ? 'LENGTH(CAST(' . $value . ' AS BLOB)) + LENGTH(CAST(m.definition AS BLOB))' : 'OCTET_LENGTH(' . $value . ') + OCTET_LENGTH(m.definition)';
        return ['where' => $where, 'parameters' => $parameters, 'columns' => $columns, 'bytes' => '2 * (' . $length . ') + 640',
            'from' => ($minutes ? 'iot_minute_aggregates a' : 'iot_ingestion_facts f') . ' JOIN iot_models m ON m.tenant_id = ' . $alias . '.tenant_id AND m.product_id = ' . $alias . '.product_id AND m.model_version = ' . $alias . '.model_version'];
    }

    private static function line(array $snapshot, array $job, DateTimeZone $zone): string
    {
        $values = json_decode($snapshot['values_json'], true, 32, JSON_THROW_ON_ERROR);
        if ($job['kind'] === 'minutes') {
            foreach ($values as $field => $statistics) {
                $values[$field]['avg'] = (float) $statistics['sum'] / (float) $statistics['count'];
            }
        }
        return self::csv([$job['kind'] === 'minutes' ? '分钟统计' : '原始上报', $snapshot['source_id'], $job['device_id'], $snapshot['product_id'], (string) $snapshot['model_version'],
            $snapshot['ownership_id'], self::date((int) $snapshot['sampled_at'], $zone), self::date((int) $snapshot['received_at'], $zone),
            (int) $snapshot['ended_at'] === 0 ? '' : self::date((int) $snapshot['ended_at'], $zone), $snapshot['sequence'],
            $job['kind'] === 'minutes' ? '不适用' : ((int) $snapshot['current_advanced'] === 1 ? '已推进' : '未推进'),
            json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), $snapshot['model_definition'], $job['timezone']]);
    }

    /** 每格显式文本化，保留大序号和前导零；双引号、换行按CSV转义，公式起始字符不执行。 */
    private static function csv(array $values): string
    {
        $cells = [];
        foreach ($values as $value) {
            $cells[] = '"' . str_replace('"', '""', "'" . (string) $value) . '"';
        }
        return implode(',', $cells) . "\r\n";
    }

    private static function date(int $timestamp, DateTimeZone $zone): string
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($zone)->format('Y-m-d H:i:s P');
    }

    private static function capacityLock(Connection $connection): void
    {
        $connection->execute('UPDATE iot_export_capacity SET version = version + 1 WHERE id = 1');
    }

    /** 每次HTTP请求重验准确客户会话、模拟来源及独立动作节点；写入先锁授权和业务行。 */
    private static function authorize(Connection $connection, Identity $identity, string $tenantId, string $action): array
    {
        $current = (new IdentityService('customer'))->refresh($identity);
        if ($current === null) {
            throw new HttpError(401, 'unauthenticated');
        }
        $permissions = RoleService::permissions($current, 'customer', $tenantId);
        if (!in_array('customer.exports.' . $action, $permissions, true)) {
            throw new HttpError(403, 'permission_denied');
        }
        return ['permissions' => $permissions, 'identity' => IdentityService::context($current, $tenantId)];
    }

    /** 不能用同租户其他客户、同账号其他会话或普通/模拟身份接管已有私有任务。 */
    private static function requireOrigin(array $row, array $context): void
    {
        if ($row['scope_key'] !== $context['identity']['key']) {
            throw new HttpError(404, 'export_not_found');
        }
    }

    /** 领取、重投和写盘前均从持久来源恢复准确会话，不回退到同账号的其他登录。 */
    private static function sourceAuthorized(Connection $connection, array $row): bool
    {
        $source = json_decode($row['source_context'], true, 8, JSON_THROW_ON_ERROR);
        $current = (new IdentityService('customer'))->resume($source);
        if ($current === null || IdentityService::context($current, $row['tenant_id'])['key'] !== $row['scope_key']) {
            return false;
        }
        try {
            return in_array('customer.exports.create', RoleService::permissions($current, 'customer', $row['tenant_id']), true);
        } catch (HttpError $denied) {
            return false;
        }
    }

    /** 只停止尚未输出的后续步骤，保留已提交进度及真实来源；回收仍由文件所有者执行。 */
    private static function revoked(Connection $connection, array $row, JobContext $context): void
    {
        $connection->table('iot_exports')->where('id', '=', $row['id'])->update(['status' => 'failed', 'error_code' => 'export_permission_revoked', 'updated_at' => time()]);
        (new Store('iot_export_outbox'))->consumed($connection, $context->message()->id(), 'export-permission-revoked');
        $source = json_decode($row['source_context'], true, 8, JSON_THROW_ON_ERROR);
        AuditLog::append($connection, $row['tenant_id'], new Identity($row['actor_id'], ['realm:customer'], $source), 'export.stopped', $row['id'], 'denied', ['reason' => 'export_permission_revoked'], 'customer');
    }

    private static function intent(Connection $connection, string $id, int $step): void
    {
        (new Store('iot_export_outbox'))->enqueue($connection, $id . ':' . $step, 'iot.export', 1, ['id' => $id, 'step' => $step]);
    }

    private static function record(Connection $connection, string $tenantId, string $id, bool $lock = false): array
    {
        $query = $connection->table('iot_exports')->where('tenant_id', '=', $tenantId)->where('id', '=', $id);
        $row = ($lock && $connection->driverName() !== 'sqlite' ? $query->lockForUpdate() : $query)->first();
        if ($row === null) {
            throw new HttpError(404, 'export_not_found');
        }
        return $row;
    }

    private static function decode(array $row): array
    {
        $row['filters'] = json_decode($row['filters_json'], true, 12, JSON_THROW_ON_ERROR);
        unset($row['filters_json'], $row['file_hash'], $row['last_time'], $row['last_id'], $row['step'], $row['cleaned_at'], $row['source_context'], $row['scope_key'], $row['request_hash']);
        foreach (['total_rows', 'completed_rows', 'estimated_bytes', 'file_bytes', 'created_at', 'updated_at', 'expires_at'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        if ($row['expires_at'] <= time()) {
            $row['status'] = 'expired';
        }
        return $row;
    }

    private function path(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new InvalidArgumentException('导出标识无效');
        }
        return $this->directory . '/' . $id . '.csv';
    }

    private function removeFile(string $id): void
    {
        $path = $this->path($id);
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException('export_cleanup_failed');
        }
    }
}
