<?php

declare(strict_types=1);

namespace app\iot\service;

use InvalidArgumentException;
use LogicException;
use stdClass;
use Type\Orm\Connection;
use Type\Orm\Database;
use Type\Orm\Migration\Migration;
use Type\Orm\Migration\Migrator;
use Type\Orm\Sqlite\SqliteDriver;
use Type\Runtime\ExecutionScope;

/**
 * 一台设备一个归属阶段的本地持久缓存；只消费平台业务回执，不解释MQTT确认或网络超时。
 * SQLite FULL事务共同保存序号、原始JSON和额度；永久拒绝进入独立有界异常，不伪装成功。
 */
final class DeviceBuffer
{
    private ?Database $database = null;
    private ?ExecutionScope $scope = null;
    private ?Connection $connection = null;

    /**
     * 文件路径相对当前工作目录解析，父目录须已存在；同一文件的设备、阶段和额度不能隐式改变。
     * 默认8640条、8640000字节指完整业务JSON的载荷空间，SQLite索引、WAL和恢复空间另计。
     * exceptionBytes只限制异常原载荷，元数据另计；最少容纳一条16KiB载荷。
     * @throws InvalidArgumentException 身份、路径或有界额度无效。
     * @throws LogicException 文件已属于其他身份或使用不同额度。
     */
    public function __construct(
        string $filename,
        private string $deviceId,
        private string $ownershipId,
        private int $maximumRecords = 8640,
        private int $maximumBytes = 8640000,
        private int $maximumExceptions = 128,
        private int $exceptionBytes = 1048576,
        array $provisioning = [],
        array $supportedModels = []
    ) {
        if (!preg_match('/^[a-f0-9]{32}$/D', $deviceId) || !preg_match('/^[a-f0-9]{32}$/D', $ownershipId)
            || $filename === '' || str_contains($filename, "\0") || in_array(basename($filename), ['.', '..'], true)
            || $maximumRecords < 1 || $maximumRecords > 86400 || $maximumBytes < 128 || $maximumBytes > 1073741824
            || $maximumExceptions < 1 || $maximumExceptions > 1000 || $exceptionBytes < 16384 || $exceptionBytes > 16384000) {
            throw new InvalidArgumentException('device_buffer_configuration_invalid');
        }
        $directory = realpath(dirname($filename));
        if ($directory === false) {
            throw new InvalidArgumentException('device_buffer_directory_missing');
        }
        try {
            $driver = new SqliteDriver($directory . '/' . basename($filename), 1000, true);
            (new Migrator($driver))->run([new Migration('001_device_buffer', '保存设备原始上报、持久序号与终局异常', [
                'CREATE TABLE device_buffer_state (id INTEGER NOT NULL PRIMARY KEY CHECK (id = 1), device_id TEXT NOT NULL, ownership_id TEXT NOT NULL, last_sequence TEXT NOT NULL, maximum_records INTEGER NOT NULL, maximum_bytes INTEGER NOT NULL, maximum_exceptions INTEGER NOT NULL, exception_bytes INTEGER NOT NULL, pending_count INTEGER NOT NULL DEFAULT 0, pending_bytes INTEGER NOT NULL DEFAULT 0, capacity_reason TEXT NOT NULL DEFAULT \'\', exception_count INTEGER NOT NULL DEFAULT 0, exception_payload_bytes INTEGER NOT NULL DEFAULT 0, not_admitted INTEGER NOT NULL DEFAULT 0, accepted_total INTEGER NOT NULL DEFAULT 0, rejected_total INTEGER NOT NULL DEFAULT 0, exceptions_dropped INTEGER NOT NULL DEFAULT 0, counters_saturated INTEGER NOT NULL DEFAULT 0)',
                'CREATE TABLE device_buffer_messages (sequence TEXT NOT NULL PRIMARY KEY, model_version INTEGER NOT NULL, payload TEXT NOT NULL, payload_bytes INTEGER NOT NULL, content_hash TEXT NOT NULL)',
                'CREATE INDEX device_buffer_message_order ON device_buffer_messages (length(sequence), sequence)',
                'CREATE TABLE device_buffer_exceptions (sequence TEXT NOT NULL PRIMARY KEY, model_version INTEGER NOT NULL, payload TEXT NOT NULL, payload_bytes INTEGER NOT NULL, content_hash TEXT NOT NULL, code TEXT NOT NULL, received_at INTEGER NOT NULL)',
            ], true), new Migration('002_device_commands', '动作前同步保存指令去重和未知结果，终局回执确认后仍保留24小时', [
                'CREATE TABLE device_commands (id TEXT NOT NULL PRIMARY KEY, content_hash TEXT NOT NULL, deadline_at INTEGER NOT NULL, retain_until INTEGER NOT NULL, status TEXT NOT NULL, receipt TEXT NOT NULL, result_hash TEXT NOT NULL, pending INTEGER NOT NULL DEFAULT 1)',
                'CREATE INDEX device_commands_pending ON device_commands (pending, id)',
            ], true), new Migration('003_device_models', '原子保存设备当前模型、切换边界及待业务确认的结果', [
                'ALTER TABLE device_buffer_state ADD COLUMN model_version INTEGER NULL',
                "ALTER TABLE device_buffer_state ADD COLUMN model_start_sequence TEXT NOT NULL DEFAULT '0'",
                'CREATE TABLE device_model_switches (id TEXT NOT NULL PRIMARY KEY, content_hash TEXT NOT NULL, receipt TEXT NOT NULL, result_hash TEXT NOT NULL, pending INTEGER NOT NULL DEFAULT 1)',
                'CREATE INDEX device_model_switch_pending ON device_model_switches (pending, id)',
            ], true), new Migration('004_device_transfer_freeze', '持久冻结采样与新动作，保留原阶段缓存及查询结果直到正式切换', [
                'ALTER TABLE device_buffer_state ADD COLUMN transfer_request TEXT NULL',
                'ALTER TABLE device_buffer_state ADD COLUMN transfer_hash TEXT NULL',
                'ALTER TABLE device_buffer_state ADD COLUMN transfer_boundary TEXT NULL',
            ], true), new Migration('005_device_transfer_switch', '持久新归属配置及最终确认，旧缓存和结果仍保留原字节', [
                'ALTER TABLE device_buffer_state ADD COLUMN transfer_provision TEXT NULL',
                'ALTER TABLE device_buffer_state ADD COLUMN transfer_activation TEXT NULL',
                'ALTER TABLE device_buffer_state ADD COLUMN transfer_activation_pending INTEGER NOT NULL DEFAULT 0',
            ], true)]);
            $this->database = new Database($driver, 1, 0);
            $this->scope = new ExecutionScope();
            $this->connection = $this->database->connect($this->scope);
            if ($provisioning !== []) {
                $this->provisionTransfer($provisioning, $supportedModels);
            }
            $this->connection->transaction(function (Connection $transaction): void {
                $state = $transaction->table('device_buffer_state')->where('id', '=', 1)->first();
                if ($state === null) {
                    $transaction->table('device_buffer_state')->insert(['id' => 1, 'device_id' => $this->deviceId, 'ownership_id' => $this->ownershipId,
                        'last_sequence' => '0', 'maximum_records' => $this->maximumRecords, 'maximum_bytes' => $this->maximumBytes,
                        'maximum_exceptions' => $this->maximumExceptions, 'exception_bytes' => $this->exceptionBytes]);
                } elseif ($state['device_id'] !== $this->deviceId || $state['ownership_id'] !== $this->ownershipId
                    || (int) $state['maximum_records'] !== $this->maximumRecords || (int) $state['maximum_bytes'] !== $this->maximumBytes
                    || (int) $state['maximum_exceptions'] !== $this->maximumExceptions || (int) $state['exception_bytes'] !== $this->exceptionBytes) {
                    throw new LogicException('device_buffer_identity_or_capacity_mismatch');
                }
            }, 'immediate');
        } catch (\Throwable $failure) {
            $this->close();
            throw $failure;
        }
    }

    /**
     * 生成一次业务身份并保存完整原字节；返回值只表示本地同步入队，不代表平台接收。
     * 模型可随明确的设备切换变化，但已入队记录永远保持原模型；归属阶段不能在此切换。
     * @return array{sequence:string,model_version:int,payload:string,payload_bytes:int,content_hash:string}|null
     *                                                                                                            null表示条数或字节容量不足，持久增加not_admitted，原缓存和序号保持不变。
     * @throws InvalidArgumentException 消息封装或16KiB业务上限无效；物模型值仍由平台校验。
     * @throws LogicException 已关闭或38位持久序号耗尽；后者拒绝新身份，不回绕。
     */
    public function enqueue(int $modelVersion, string $type, int $sampledAt, stdClass $values, string $identifier = ''): ?array
    {
        if ($modelVersion < 1 || !in_array($type, ['telemetry', 'event'], true) || $sampledAt < 0
            || ($type === 'telemetry' && $identifier !== '') || ($type === 'event' && !preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/D', $identifier))) {
            throw new InvalidArgumentException('device_buffer_sample_invalid');
        }
        // 先冻结调用者可变对象；事务里只使用已校验、至多16KiB的值，重发从不重新序列化。
        $valuesJson = json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($valuesJson) > 16384) {
            throw new InvalidArgumentException('device_buffer_payload_too_large');
        }
        return $this->connected()->transaction(function (Connection $transaction) use ($modelVersion, $type, $sampledAt, $valuesJson, $identifier): ?array {
            $state = $this->state($transaction);
            if ($state['transfer_request'] !== null) {
                throw new LogicException('device_transfer_frozen');
            }
            if ($state['model_version'] !== null && (int) $state['model_version'] !== $modelVersion) {
                throw new LogicException('device_model_binding_mismatch');
            }
            $sequence = self::increment((string) $state['last_sequence']);
            $envelope = ['app_version' => 1, 'type' => $type, 'device_id' => $this->deviceId, 'ownership_id' => $this->ownershipId,
                'model_version' => $modelVersion, 'sequence' => $sequence, 'sampled_at' => $sampledAt,
                'values' => json_decode($valuesJson, false, 32, JSON_THROW_ON_ERROR)];
            if ($type === 'event') {
                $envelope['identifier'] = $identifier;
            }
            $payload = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $bytes = strlen($payload);
            if ($bytes > 16384) {
                throw new InvalidArgumentException('device_buffer_payload_too_large');
            }
            if ((int) $state['pending_count'] >= $this->maximumRecords || (int) $state['pending_bytes'] + $bytes > $this->maximumBytes) {
                self::count($transaction, 'not_admitted');
                $transaction->table('device_buffer_state')->where('id', '=', 1)->update(['capacity_reason' => (int) $state['pending_count'] >= $this->maximumRecords ? 'records' : 'bytes']);
                return null;
            }
            $record = ['sequence' => $sequence, 'model_version' => $modelVersion, 'payload' => $payload,
                'payload_bytes' => $bytes, 'content_hash' => IngestionService::contentHash($payload)];
            $transaction->table('device_buffer_messages')->insert($record);
            $transaction->table('device_buffer_state')->where('id', '=', 1)->update(['last_sequence' => $sequence,
                'pending_count' => (int) $state['pending_count'] + 1, 'pending_bytes' => (int) $state['pending_bytes'] + $bytes, 'capacity_reason' => '']);
            return $record;
        }, 'immediate');
    }

    /** 初次配置只初始化一次；重启后返回持久确认的新模型，旧配置不能倒退已经切换的设备。 */
    public function modelVersion(int $initial): int
    {
        if ($initial < 1 || $initial > 2147483646) {
            throw new InvalidArgumentException('device_buffer_model_invalid');
        }
        return $this->connected()->transaction(function (Connection $transaction) use ($initial): int {
            $state = $this->state($transaction);
            if ($state['model_version'] === null) {
                $transaction->table('device_buffer_state')->where('id', '=', 1)->update(['model_version' => $initial]);
                return $initial;
            }
            return (int) $state['model_version'];
        }, 'immediate');
    }

    /** 明确支持后原子切换并保存原结果；重复或重启不重新切换，旧缓存字节与全阶段序号保持不变。 */
    public function switchModel(string $payload, array $supportedModels): array
    {
        $hash = IngestionService::contentHash($payload);
        $request = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
        $fields = ['app_version', 'type', 'device_id', 'ownership_id', 'switch_id', 'source_version', 'target_version', 'source_start', 'structure_hash'];
        if (count($request) !== count($fields) || array_diff(array_keys($request), $fields) !== [] || ($request['app_version'] ?? null) !== 1
            || ($request['type'] ?? '') !== 'model_switch' || ($request['device_id'] ?? '') !== $this->deviceId || ($request['ownership_id'] ?? '') !== $this->ownershipId
            || !is_string($request['switch_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $request['switch_id'])
            || !is_int($request['source_version'] ?? null) || !is_int($request['target_version'] ?? null) || $request['source_version'] < 1 || $request['target_version'] < 1
            || !is_string($request['source_start'] ?? null) || !preg_match('/^(0|[1-9][0-9]{0,37})$/D', $request['source_start'])
            || !is_string($request['structure_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $request['structure_hash'])) {
            throw new InvalidArgumentException('device_model_switch_invalid');
        }
        return $this->connected()->transaction(function (Connection $transaction) use ($request, $hash, $supportedModels): array {
            $stored = $transaction->table('device_model_switches')->where('id', '=', $request['switch_id'])->first();
            if ($stored !== null) {
                if ($stored['content_hash'] !== $hash) {
                    throw new InvalidArgumentException('device_model_switch_conflict');
                }
                $transaction->table('device_model_switches')->where('id', '=', $stored['id'])->update(['pending' => 1]);
                return json_decode($stored['receipt'], true, 32, JSON_THROW_ON_ERROR);
            }
            if ((int) $transaction->table('device_model_switches')->aggregate('COUNT') >= 1000) {
                throw new LogicException('device_model_switch_capacity_full');
            }
            $state = $this->state($transaction);
            $supported = ($supportedModels[$request['target_version']] ?? '') === $request['structure_hash'];
            $consistent = $state['transfer_request'] === null && (int) $state['model_version'] === $request['source_version'] && $state['model_start_sequence'] === $request['source_start']
                && $transaction->table('device_model_switches')->where('pending', '=', 1)->limit(1)->first() === null;
            $code = !$consistent ? 'model_state_conflict' : (!$supported ? 'model_unsupported' : 'model_switched');
            $receipt = ['app_version' => 1, 'type' => 'model_switch_receipt', 'device_id' => $this->deviceId, 'ownership_id' => $this->ownershipId,
                'switch_id' => $request['switch_id'], 'source_version' => $request['source_version'], 'target_version' => $request['target_version'],
                'structure_hash' => $request['structure_hash'], 'boundary_sequence' => $state['last_sequence'],
                'status' => $code === 'model_switched' ? 'confirmed' : 'rejected', 'code' => $code];
            $encoded = json_encode($receipt, JSON_THROW_ON_ERROR);
            $transaction->table('device_model_switches')->insert(['id' => $request['switch_id'], 'content_hash' => $hash, 'receipt' => $encoded,
                'result_hash' => IngestionService::contentHash($encoded)]);
            if ($code === 'model_switched') {
                $transaction->table('device_buffer_state')->where('id', '=', 1)->update(['model_version' => $request['target_version'], 'model_start_sequence' => $state['last_sequence']]);
            }
            return $receipt;
        }, 'immediate');
    }

    /** 有界读取未取得平台业务ACK的模型确认；PUBACK不改变本地确认状态。 */
    public function modelReceipts(int $limit = 1): array
    {
        self::limit($limit);
        return $this->connected()->table('device_model_switches')->where('pending', '=', 1)->orderBy('id')->limit($limit)->get();
    }

    /** 精确原结果的业务ACK才解除新版本发送等待；重复ACK和旧切换ACK均不倒退当前版本。 */
    public function acknowledgeModel(array $receipt): bool
    {
        $fields = ['app_version', 'type', 'device_id', 'ownership_id', 'switch_id', 'result_hash'];
        if (count($receipt) !== count($fields) || array_diff(array_keys($receipt), $fields) !== [] || ($receipt['app_version'] ?? null) !== 1
            || ($receipt['type'] ?? '') !== 'model_switch_ack' || ($receipt['device_id'] ?? '') !== $this->deviceId || ($receipt['ownership_id'] ?? '') !== $this->ownershipId
            || !is_string($receipt['switch_id'] ?? null) || !is_string($receipt['result_hash'] ?? null)) {
            throw new InvalidArgumentException('device_model_ack_invalid');
        }
        return $this->connected()->transaction(static function (Connection $transaction) use ($receipt): bool {
            $record = $transaction->table('device_model_switches')->where('id', '=', $receipt['switch_id'])->first();
            if ($record === null || $record['result_hash'] !== $receipt['result_hash']) {
                throw new InvalidArgumentException('device_model_ack_invalid');
            }
            return $transaction->table('device_model_switches')->where('id', '=', $receipt['switch_id'])->where('pending', '=', 1)->update(['pending' => 0]) === 1;
        }, 'immediate');
    }

    /**
     * 动作前同步记录未知；返回execute=true才允许执行一次。可信时间由获准TLS的一次性挑战调用者提供。
     * 同ID重收只返回原结果，掉电留下的unknown永不再次动作；容量不足则拒绝新动作并保留全部原记录。
     * trustedTime为动作截止校验的上界；cleanupTime为保留期清理的下界，不提供下界则不清理。
     * @return array{execute:bool,receipt:array<string,mixed>}
     */
    public function beginCommand(string $payload, int $modelVersion, ?int $trustedTime, ?int $cleanupTime = null): array
    {
        $hash = IngestionService::contentHash($payload);
        $object = json_decode($payload, false, 32, JSON_THROW_ON_ERROR);
        $command = get_object_vars($object);
        $fields = ['app_version', 'type', 'command_id', 'device_id', 'ownership_id', 'model_version', 'identifier', 'values', 'issued_at', 'deadline_at'];
        if (count($command) !== count($fields) || array_diff(array_keys($command), $fields) !== []
            || ($command['app_version'] ?? null) !== 1 || ($command['type'] ?? null) !== 'command'
            || ($command['device_id'] ?? null) !== $this->deviceId || ($command['ownership_id'] ?? null) !== $this->ownershipId
            || !is_string($command['command_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $command['command_id'])
            || !is_int($command['model_version'] ?? null) || $command['model_version'] < 1
            || !is_string($command['identifier'] ?? null) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/D', $command['identifier'])
            || !($command['values'] ?? null) instanceof stdClass || !is_int($command['issued_at'] ?? null) || $command['issued_at'] < 1
            || !is_int($command['deadline_at'] ?? null) || $command['issued_at'] > PHP_INT_MAX - 86460 || $command['deadline_at'] !== $command['issued_at'] + 60
            || ($trustedTime !== null && $trustedTime < 1)
            || ($cleanupTime !== null && ($trustedTime === null || $cleanupTime < 1 || $cleanupTime > $trustedTime))) {
            throw new InvalidArgumentException('device_command_invalid');
        }
        return $this->connected()->transaction(function (Connection $transaction) use ($command, $hash, $modelVersion, $trustedTime, $cleanupTime): array {
            $stored = $transaction->table('device_commands')->where('id', '=', $command['command_id'])->first();
            if ($stored !== null) {
                if (!hash_equals($stored['content_hash'], $hash)) {
                    throw new InvalidArgumentException('device_command_content_conflict');
                }
                $transaction->table('device_commands')->where('id', '=', $stored['id'])->update(['pending' => 1]);
                return ['execute' => false, 'receipt' => get_object_vars(json_decode($stored['receipt'], false, 32, JSON_THROW_ON_ERROR))];
            }
            if ($cleanupTime !== null) {
                // 只清理已经平台确认的终局结果；未知和待发结果不因时间流逝被清成已完成。
                $transaction->execute("DELETE FROM device_commands WHERE retain_until <= ? AND pending = 0 AND status <> 'unknown'", [$cleanupTime]);
            }
            if ((int) $transaction->table('device_commands')->aggregate('COUNT') >= 10000) {
                throw new LogicException('device_command_capacity_full');
            }
            $code = $trustedTime === null ? 'clock_untrusted' : ($trustedTime >= $command['deadline_at'] ? 'command_expired'
                : ($trustedTime < $command['issued_at'] ? 'clock_untrusted' : ($modelVersion !== $command['model_version'] ? 'model_mismatch' : 'execution_unknown')));
            if ($this->state($transaction)['transfer_request'] !== null) {
                $code = 'device_transfer_frozen';
            }
            $execute = $code === 'execution_unknown';
            $receipt = ['app_version' => 1, 'type' => 'command_receipt', 'device_id' => $this->deviceId, 'ownership_id' => $this->ownershipId,
                'command_id' => $command['command_id'], 'content_hash' => $hash, 'status' => $execute ? 'unknown' : 'rejected', 'code' => $code,
                'started_at' => $execute ? $trustedTime : null, 'finished_at' => null, 'result' => new stdClass()];
            $encoded = json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $transaction->table('device_commands')->insert(['id' => $command['command_id'], 'content_hash' => $hash, 'deadline_at' => $command['deadline_at'],
                'retain_until' => max($command['deadline_at'], $trustedTime ?? $command['deadline_at']) + 86400, 'status' => $receipt['status'], 'receipt' => $encoded,
                'result_hash' => IngestionService::contentHash($encoded)]);
            return ['execute' => $execute, 'receipt' => $receipt];
        }, 'immediate');
    }

    /** 执行者取得实际结果后同步保存；失败写盘不能再次动作，仍由原unknown事实保护去重。 */
    public function completeCommand(string $id, string $status, string $code, stdClass $result, int $finishedAt): array
    {
        if (!in_array($status, ['succeeded', 'failed'], true) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code)
            || strlen(json_encode($result, JSON_THROW_ON_ERROR)) > 4096) {
            throw new InvalidArgumentException('device_command_result_invalid');
        }
        return $this->connected()->transaction(static function (Connection $transaction) use ($id, $status, $code, $result, $finishedAt): array {
            $stored = $transaction->table('device_commands')->where('id', '=', $id)->first();
            $receipt = $stored === null ? null : json_decode($stored['receipt'], false, 32, JSON_THROW_ON_ERROR);
            if ($receipt === null || $receipt->status !== 'unknown' || $receipt->started_at === null || $finishedAt < $receipt->started_at) {
                throw new LogicException('device_command_result_conflict');
            }
            $receipt->status = $status;
            $receipt->code = $code;
            $receipt->result = $result;
            $receipt->finished_at = $finishedAt;
            $encoded = json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $transaction->table('device_commands')->where('id', '=', $id)->update(['receipt' => $encoded, 'status' => $status,
                'retain_until' => max((int) $stored['retain_until'], $finishedAt + 86400), 'result_hash' => IngestionService::contentHash($encoded), 'pending' => 1]);
            return get_object_vars($receipt);
        }, 'immediate');
    }

    /** 最多100条未获平台业务确认的原始执行回执；不因MQTT PUBACK清除。 */
    public function commandReceipts(int $limit = 1): array
    {
        self::limit($limit);
        return $this->connected()->table('device_commands')->where('pending', '=', 1)->orderBy('id')->limit($limit)->get();
    }

    /** 只查询已持久化的原结果，不走动作入口、不要求可信时间；找不到结果绝不等于未执行。 */
    public function queryCommand(array $query): array
    {
        $fields = ['app_version', 'type', 'device_id', 'ownership_id', 'command_id', 'content_hash', 'query_id'];
        if (count($query) !== count($fields) || array_diff(array_keys($query), $fields) !== []
            || ($query['app_version'] ?? null) !== 1 || ($query['type'] ?? null) !== 'command_query'
            || ($query['device_id'] ?? null) !== $this->deviceId || ($query['ownership_id'] ?? null) !== $this->ownershipId
            || !is_string($query['command_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $query['command_id'])
            || !is_string($query['query_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $query['query_id'])
            || !is_string($query['content_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $query['content_hash'])) {
            throw new InvalidArgumentException('device_command_query_invalid');
        }
        return $this->connected()->transaction(static function (Connection $transaction) use ($query): array {
            $record = $transaction->table('device_commands')->where('id', '=', $query['command_id'])->first();
            if ($record !== null && json_decode($record['receipt'], true, 32, JSON_THROW_ON_ERROR)['ownership_id'] !== $query['ownership_id']) {
                $record = null;
            }
            if ($record !== null && !hash_equals($record['content_hash'], $query['content_hash'])) {
                throw new InvalidArgumentException('device_command_content_conflict');
            }
            if ($record !== null) {
                $transaction->table('device_commands')->where('id', '=', $record['id'])->update(['pending' => 1]);
            }
            return array_replace($query, ['type' => 'command_query_result', 'receipt' => $record === null ? null : json_decode($record['receipt'], false, 32, JSON_THROW_ON_ERROR)]);
        }, 'immediate');
    }

    /** 仅精确匹配当前结果摘要的业务确认可以停止回执重发；去重与结果继续留在SQLite。 */
    public function acknowledgeCommand(array $ack): bool
    {
        $fields = ['app_version', 'type', 'device_id', 'ownership_id', 'command_id', 'result_hash'];
        if (count($ack) !== count($fields) || array_diff(array_keys($ack), $fields) !== []
            || ($ack['app_version'] ?? null) !== 1 || ($ack['type'] ?? null) !== 'command_receipt_ack'
            || ($ack['device_id'] ?? null) !== $this->deviceId || ($ack['ownership_id'] ?? null) !== $this->ownershipId
            || !is_string($ack['command_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $ack['command_id'])
            || !is_string($ack['result_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $ack['result_hash'])) {
            throw new InvalidArgumentException('device_command_ack_invalid');
        }
        return $this->connected()->transaction(static fn (Connection $transaction): bool => $transaction->execute(
            'UPDATE device_commands SET pending = 0 WHERE id = ? AND result_hash = ? AND pending = 1',
            [$ack['command_id'], $ack['result_hash']]
        ) === 1, 'immediate');
    }

    /** @return list<array{sequence:string,model_version:int,payload:string,payload_bytes:int,content_hash:string}> 按十进制序号取最多100条原字节；读取不改变确认状态和身份。 */
    public function pending(int $limit = 1): array
    {
        self::limit($limit);
        return $this->connected()->query('SELECT * FROM device_buffer_messages ORDER BY length(sequence), sequence LIMIT ' . $limit);
    }

    /**
     * 调用方先验证消息来自获准TLS连接的本设备下行Topic，再传入完整平台业务回执。
     * accepted精确匹配后删除；rejected同样匹配后移入终局异常。没有回执、超时及未知提交不调用此方法。
     * @param array<string,mixed> $receipt ingestion_receipt，不接受MQTT ACK或其他状态类型。
     * @return bool 本次处理了待确认记录；不存在或已经处理的序号返回false，不重复累计。
     * @throws InvalidArgumentException 封装、身份、内容摘要或原因不匹配；事务不改变原缓存。
     */
    public function applyReceipt(array $receipt): bool
    {
        $fields = ['app_version', 'type', 'device_id', 'ownership_id', 'sequence', 'content_hash', 'status', 'code', 'received_at'];
        if (count($receipt) !== count($fields) || array_diff(array_keys($receipt), $fields) !== []
            || ($receipt['app_version'] ?? null) !== 1 || ($receipt['type'] ?? null) !== 'ingestion_receipt'
            || ($receipt['device_id'] ?? null) !== $this->deviceId || ($receipt['ownership_id'] ?? null) !== $this->ownershipId
            || !is_string($receipt['sequence'] ?? null) || !preg_match('/^[1-9][0-9]{0,37}$/D', $receipt['sequence'])
            || !is_string($receipt['content_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $receipt['content_hash'])
            || !is_int($receipt['received_at'] ?? null) || $receipt['received_at'] < 1
            || !in_array($receipt['status'] ?? null, ['accepted', 'rejected'], true)
            || ($receipt['status'] === 'accepted' && ($receipt['code'] ?? null) !== 'accepted')
            || ($receipt['status'] === 'rejected' && !in_array($receipt['code'] ?? null, ['content_conflict', 'device_unavailable', 'invalid_envelope', 'model_mismatch', 'clock_ahead', 'sample_expired', 'invalid_model_values', 'transfer_frozen'], true))) {
            throw new InvalidArgumentException('device_buffer_receipt_invalid');
        }
        return $this->connected()->transaction(function (Connection $transaction) use ($receipt): bool {
            $record = $transaction->table('device_buffer_messages')->where('sequence', '=', $receipt['sequence'])->first();
            if ($record === null) {
                return false;
            }
            if (!hash_equals($record['content_hash'], $receipt['content_hash'])) {
                throw new InvalidArgumentException('device_buffer_receipt_content_mismatch');
            }
            if ($receipt['status'] === 'rejected') {
                $state = $this->state($transaction);
                while ((int) $state['exception_count'] >= $this->maximumExceptions
                    || (int) $state['exception_payload_bytes'] + (int) $record['payload_bytes'] > $this->exceptionBytes) {
                    $oldest = $transaction->query('SELECT sequence, payload_bytes FROM device_buffer_exceptions ORDER BY received_at, length(sequence), sequence LIMIT 1')[0];
                    $transaction->table('device_buffer_exceptions')->where('sequence', '=', $oldest['sequence'])->delete();
                    $state['exception_count'] = (int) $state['exception_count'] - 1;
                    $state['exception_payload_bytes'] = (int) $state['exception_payload_bytes'] - (int) $oldest['payload_bytes'];
                    self::count($transaction, 'exceptions_dropped');
                }
                $transaction->table('device_buffer_exceptions')->insert($record + ['code' => $receipt['code'], 'received_at' => $receipt['received_at']]);
                $transaction->table('device_buffer_state')->where('id', '=', 1)->update(['exception_count' => (int) $state['exception_count'] + 1,
                    'exception_payload_bytes' => (int) $state['exception_payload_bytes'] + (int) $record['payload_bytes']]);
                self::count($transaction, 'rejected_total');
            } else {
                self::count($transaction, 'accepted_total');
            }
            $transaction->table('device_buffer_messages')->where('sequence', '=', $record['sequence'])->delete();
            $transaction->execute('UPDATE device_buffer_state SET pending_count = pending_count - 1, pending_bytes = pending_bytes - ?, capacity_reason = \'\' WHERE id = 1', [(int) $record['payload_bytes']]);
            return true;
        }, 'immediate');
    }

    /** @return list<array<string,mixed>> 最近永久拒绝的原载荷、固定身份、原因及平台时间；超额只淘汰已终局异常并累计exceptions_dropped。 */
    public function exceptions(int $limit = 100): array
    {
        self::limit($limit);
        return $this->connected()->query('SELECT * FROM device_buffer_exceptions ORDER BY received_at DESC, length(sequence) DESC, sequence DESC LIMIT ' . $limit);
    }

    /**
     * @return array<string,mixed> 设备/阶段、持久序号、各自额度/占用及累计计数；不含秘密、载荷或Topic。
     *                             full表示已触及额度或上次因容量拒绝；capacity_reason区分条数与字节，完成一条回执后解除该观察。
     *                             计数饱和时counters_saturated为true，累计值表示下界；不会整数回绕成零。
     */
    public function statistics(): array
    {
        $state = $this->state($this->connected());
        $transfer = $state['transfer_request'] === null ? null : json_decode($state['transfer_request'], true, 16, JSON_THROW_ON_ERROR);
        $state['transfer_id'] = $transfer['transfer_id'] ?? null;
        unset($state['id'], $state['transfer_request'], $state['transfer_hash'], $state['transfer_provision'], $state['transfer_activation']);
        $state['full'] = $state['capacity_reason'] !== '' || (int) $state['pending_count'] >= $this->maximumRecords || (int) $state['pending_bytes'] >= $this->maximumBytes;
        $state['remaining_bytes'] = $this->maximumBytes - (int) $state['pending_bytes'];
        $state['counters_saturated'] = (int) $state['counters_saturated'] === 1;
        return $state;
    }

    /**
     * 冻结一份有界缓存观察并持久分配序号；即使采样缓存已满也可执行。
     * 观察不进入可靠采样队列、不证明数据已接收；发送失败允许下次产生更新观察，原采样不变。
     * @return array<string,mixed> device_status封装，和可靠采样共用跨重启序号但不消费其空间。
     */
    public function snapshot(int $modelVersion): array
    {
        if ($modelVersion < 1) {
            throw new InvalidArgumentException('device_buffer_model_invalid');
        }
        return $this->connected()->transaction(function (Connection $transaction) use ($modelVersion): array {
            $state = $this->state($transaction);
            $sequence = self::increment((string) $state['last_sequence']);
            $transaction->table('device_buffer_state')->where('id', '=', 1)->update(['last_sequence' => $sequence]);
            $values = [];
            foreach (['maximum_records', 'maximum_bytes', 'pending_count', 'pending_bytes', 'not_admitted', 'exception_count',
                'accepted_total', 'rejected_total', 'exceptions_dropped'] as $field) {
                $values[$field] = (int) $state[$field];
            }
            $values['full'] = $state['capacity_reason'] !== '' || (int) $state['pending_count'] >= $this->maximumRecords || (int) $state['pending_bytes'] >= $this->maximumBytes;
            $values['capacity_reason'] = (string) $state['capacity_reason'];
            $values['counters_saturated'] = (int) $state['counters_saturated'] === 1;
            return ['app_version' => 1, 'type' => 'device_status', 'device_id' => $this->deviceId, 'ownership_id' => $this->ownershipId,
                'model_version' => $modelVersion, 'sequence' => $sequence, 'sampled_at' => time(), 'values' => $values];
        }, 'immediate');
    }

    /** 获准下行的原冻结意图与序号边界同一FULL事务保存；重复不会换目标，重启也不恢复采样或新动作。 */
    public function freezeTransfer(string $payload): void
    {
        $request = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
        $fields = ['app_version', 'type', 'device_id', 'ownership_id', 'transfer_id', 'source_model_version', 'target_tenant_id', 'target_product_id', 'target_model_version', 'structure_hash'];
        if (count($request) !== count($fields) || array_diff(array_keys($request), $fields) !== [] || ($request['app_version'] ?? null) !== 1
            || ($request['type'] ?? '') !== 'transfer_freeze' || ($request['device_id'] ?? '') !== $this->deviceId || ($request['ownership_id'] ?? '') !== $this->ownershipId
            || !is_int($request['source_model_version'] ?? null) || !is_int($request['target_model_version'] ?? null) || $request['target_model_version'] < 1
            || !is_string($request['structure_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $request['structure_hash'])) {
            throw new InvalidArgumentException('device_transfer_invalid');
        }
        foreach (['transfer_id', 'target_tenant_id', 'target_product_id'] as $field) {
            if (!is_string($request[$field]) || !preg_match('/^[a-f0-9]{32}$/D', $request[$field])) {
                throw new InvalidArgumentException('device_transfer_invalid');
            }
        }
        $hash = IngestionService::contentHash($payload);
        $this->connected()->transaction(function (Connection $transaction) use ($request, $payload, $hash): void {
            $state = $this->state($transaction);
            if ($state['transfer_hash'] !== null) {
                if ($state['transfer_hash'] !== $hash) {
                    throw new LogicException('device_transfer_conflict');
                }
                return;
            }
            if ((int) $state['model_version'] !== $request['source_model_version']) {
                throw new LogicException('device_transfer_model_mismatch');
            }
            $transaction->table('device_buffer_state')->where('id', '=', 1)->update(['transfer_request' => $payload, 'transfer_hash' => $hash, 'transfer_boundary' => $state['last_sequence']]);
        }, 'immediate');
    }

    /** 冻结后的有序排空观察；不进入可靠采样缓存，发送失败下次重报，不取消冻结或改变原缓存字节。 */
    public function transferStatus(array $supportedModels): ?array
    {
        return $this->connected()->transaction(function (Connection $transaction) use ($supportedModels): ?array {
            $state = $this->state($transaction);
            if ((int) $state['transfer_activation_pending'] === 1) {
                return json_decode($state['transfer_activation'], true, 16, JSON_THROW_ON_ERROR);
            }
            if ($state['transfer_request'] === null) {
                return null;
            }
            $request = json_decode($state['transfer_request'], true, 16, JSON_THROW_ON_ERROR);
            $sequence = self::increment($state['last_sequence']);
            $transaction->table('device_buffer_state')->where('id', '=', 1)->update(['last_sequence' => $sequence]);
            return ['app_version' => 1, 'type' => 'transfer_status', 'device_id' => $this->deviceId, 'ownership_id' => $this->ownershipId,
                'transfer_id' => $request['transfer_id'], 'model_version' => (int) $state['model_version'], 'structure_hash' => $request['structure_hash'],
                'sequence' => $sequence, 'boundary_sequence' => $state['transfer_boundary'],
                'supported' => ($supportedModels[$request['target_model_version']] ?? '') === $request['structure_hash'], 'pending_count' => (int) $state['pending_count'],
                'pending_command_receipts' => (int) $transaction->table('device_commands')->where('pending', '=', 1)->aggregate('COUNT'),
                'unresolved_commands' => (int) $transaction->table('device_commands')->where('status', '=', 'unknown')->aggregate('COUNT'),
                'model_pending' => $transaction->table('device_model_switches')->where('pending', '=', 1)->limit(1)->first() !== null];
        }, 'immediate');
    }

    /**
     * 受控凭据配置渠道明确指定原冻结和目标阶段；SQLite同事务切换身份并保存待确认回执。
     * 重复原配置可在重启后继续，旧终局异常及已对账结果保持原字节，序号持续单调不复用。
     */
    public function provisionTransfer(array $provision, array $supportedModels): void
    {
        $fields = ['app_version', 'type', 'device_id', 'transfer_id', 'source_ownership_id', 'ownership_id', 'tenant_id', 'product_id', 'model_version', 'structure_hash'];
        if (count($provision) !== count($fields) || array_diff(array_keys($provision), $fields) !== [] || ($provision['app_version'] ?? null) !== 1
            || ($provision['type'] ?? '') !== 'transfer_provision' || ($provision['device_id'] ?? '') !== $this->deviceId
            || !is_int($provision['model_version']) || $provision['model_version'] < 1 || !is_string($provision['structure_hash'])
            || !preg_match('/^[a-f0-9]{64}$/D', $provision['structure_hash']) || ($supportedModels[$provision['model_version']] ?? '') !== $provision['structure_hash']) {
            throw new InvalidArgumentException('device_transfer_provision_invalid');
        }
        foreach (['transfer_id', 'source_ownership_id', 'ownership_id', 'tenant_id', 'product_id'] as $field) {
            if (!is_string($provision[$field]) || !preg_match('/^[a-f0-9]{32}$/D', $provision[$field])) {
                throw new InvalidArgumentException('device_transfer_provision_invalid');
            }
        }
        if ($provision['ownership_id'] === $provision['source_ownership_id'] || !in_array($this->ownershipId, [$provision['source_ownership_id'], $provision['ownership_id']], true)) {
            throw new InvalidArgumentException('device_transfer_provision_invalid');
        }
        $this->connected()->transaction(function (Connection $transaction) use ($provision): void {
            $state = $transaction->table('device_buffer_state')->where('id', '=', 1)->first();
            if ($state === null || $state['device_id'] !== $this->deviceId || (int) $state['maximum_records'] !== $this->maximumRecords
                || (int) $state['maximum_bytes'] !== $this->maximumBytes || (int) $state['maximum_exceptions'] !== $this->maximumExceptions
                || (int) $state['exception_bytes'] !== $this->exceptionBytes) {
                throw new LogicException('device_buffer_identity_or_capacity_mismatch');
            }
            $encoded = json_encode((object) $provision, JSON_THROW_ON_ERROR);
            if ($state['ownership_id'] === $provision['ownership_id']) {
                if ($state['transfer_provision'] === null || IngestionService::contentHash($state['transfer_provision']) !== IngestionService::contentHash($encoded)) {
                    throw new LogicException('device_transfer_conflict');
                }
                return;
            }
            $request = $state['transfer_request'] === null ? null : json_decode($state['transfer_request'], true, 16, JSON_THROW_ON_ERROR);
            if ($state['ownership_id'] !== $provision['source_ownership_id'] || $request === null || $request['transfer_id'] !== $provision['transfer_id']
                || $request['target_tenant_id'] !== $provision['tenant_id'] || $request['target_product_id'] !== $provision['product_id']
                || $request['target_model_version'] !== $provision['model_version'] || $request['structure_hash'] !== $provision['structure_hash']) {
                throw new LogicException('device_transfer_conflict');
            }
            if ((int) $state['pending_count'] !== 0 || $transaction->table('device_commands')->where('pending', '=', 1)->limit(1)->first() !== null
                || $transaction->table('device_commands')->where('status', '=', 'unknown')->limit(1)->first() !== null
                || $transaction->table('device_model_switches')->where('pending', '=', 1)->limit(1)->first() !== null) {
                throw new LogicException('device_transfer_not_drained');
            }
            $receipt = ['app_version' => 1, 'type' => 'transfer_activated', 'device_id' => $this->deviceId,
                'ownership_id' => $provision['ownership_id'], 'source_ownership_id' => $provision['source_ownership_id'],
                'transfer_id' => $provision['transfer_id'], 'model_version' => $provision['model_version'], 'structure_hash' => $provision['structure_hash']];
            $transaction->table('device_buffer_state')->where('id', '=', 1)->update(['ownership_id' => $provision['ownership_id'],
                'model_version' => $provision['model_version'], 'model_start_sequence' => $state['last_sequence'], 'transfer_provision' => $encoded,
                'transfer_activation' => json_encode($receipt, JSON_THROW_ON_ERROR), 'transfer_activation_pending' => 1]);
        }, 'immediate');
        $this->ownershipId = $provision['ownership_id'];
    }

    /** 平台已同步保存新阶段后，精确业务确认才解除本地冻结；丢失确认继续重投同一回执。 */
    public function acknowledgeTransfer(array $receipt): bool
    {
        return $this->connected()->transaction(function (Connection $transaction) use ($receipt): bool {
            $state = $this->state($transaction);
            if ($state['transfer_activation'] === null) {
                return false;
            }
            $expected = json_decode($state['transfer_activation'], true, 16, JSON_THROW_ON_ERROR);
            $expected['type'] = 'transfer_activated_ack';
            if (IngestionService::contentHash(json_encode((object) $receipt, JSON_THROW_ON_ERROR)) !== IngestionService::contentHash(json_encode($expected, JSON_THROW_ON_ERROR))) {
                throw new InvalidArgumentException('device_transfer_ack_invalid');
            }
            if ((int) $state['transfer_activation_pending'] !== 1) {
                return false;
            }
            $transaction->table('device_buffer_state')->where('id', '=', 1)->update(['transfer_activation_pending' => 0,
                'transfer_request' => null, 'transfer_hash' => null, 'transfer_boundary' => null]);
            return true;
        }, 'immediate');
    }

    /** 归还本地数据库租约；幂等且不把未确认缓存标记为完成。 */
    public function close(): void
    {
        $this->connection = null;
        $this->scope?->close();
        $this->scope = null;
        $this->database?->close();
        $this->database = null;
    }

    /** 析构只释放本地资源，不发送或制造平台回执。 */
    public function __destruct()
    {
        $this->close();
    }

    private function connected(): Connection
    {
        if ($this->connection === null) {
            throw new LogicException('device_buffer_closed');
        }
        return $this->connection;
    }

    private function state(Connection $connection): array
    {
        return $connection->query('SELECT * FROM device_buffer_state WHERE id = 1')[0];
    }

    /** 十进制逐位进位，不经过机器整数、浮点或动态引用。 */
    private static function increment(string $number): string
    {
        if (!preg_match('/^(?:0|[1-9][0-9]{0,37})$/D', $number) || $number === str_repeat('9', 38)) {
            throw new LogicException('device_buffer_sequence_exhausted');
        }
        for ($position = strlen($number) - 1; $position >= 0; $position--) {
            if ($number[$position] !== '9') {
                // 不原位写入共享字符串：已返回的原记录及PDO值必须继续持有原序号。
                return substr($number, 0, $position) . chr(ord($number[$position]) + 1) . str_repeat('0', strlen($number) - $position - 1);
            }
        }
        return '1' . str_repeat('0', strlen($number));
    }

    /** 列名仅来自此类固定调用，计数达到有符号64位上限后保持明确的饱和事实。 */
    private static function count(Connection $connection, string $column): void
    {
        $connection->execute('UPDATE device_buffer_state SET counters_saturated = CASE WHEN ' . $column . ' = 9223372036854775807 THEN 1 ELSE counters_saturated END, '
            . $column . ' = CASE WHEN ' . $column . ' < 9223372036854775807 THEN ' . $column . ' + 1 ELSE ' . $column . ' END WHERE id = 1');
    }

    private static function limit(int $limit): void
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('device_buffer_page_invalid');
        }
    }
}
