<?php

declare(strict_types=1);

namespace app\iot\service;

use app\common\service\AuditLog;
use app\common\service\IdentityService;
use app\common\service\RoleService;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Orm\Connection;

/** 单属性规则、版本连续状态和告警的持久所有者；alarm完成事实与状态变化同事务。 */
final class AlarmService
{
    /**
     * 首次确认保存人员和时间，重复请求返回原记录；仅修改确认字段，与设备恢复互不覆盖。
     * 授权锁先于告警锁，事务内重新授权；等待恢复写入后才建立MySQL一致性快照。
     * @return array<string, mixed> 当前告警详情。
     * @throws HttpError 当前权限不足或原租户告警不存在。
     */
    public static function acknowledge(Connection $connection, Identity $identity, string $tenantId, string $alarmId): array
    {
        try {
            self::authorize($connection, $identity, $tenantId, 'customer.alarms.acknowledge');
            return $connection->transaction(static function (Connection $transaction) use ($identity, $tenantId, $alarmId): array {
                RoleService::lockAuthorization($transaction);
                $query = $transaction->table('iot_alarms')->where('id', '=', $alarmId)->where('tenant_id', '=', $tenantId);
                $alarm = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
                $context = self::authorize($transaction, $identity, $tenantId, 'customer.alarms.acknowledge');
                if ($alarm === null) {
                    throw new HttpError(404, 'alarm_not_found');
                }
                if ($alarm['acknowledged_at'] === null) {
                    $query->update(['acknowledged_by' => $identity->subject(), 'acknowledged_at' => time()]);
                    AuditLog::append($transaction, $tenantId, $identity, 'alarm.acknowledged', $alarmId, 'success', ['version' => (int) $alarm['rule_version']], 'customer');
                }
                return self::readAlarms($transaction, $tenantId, [], $alarmId, $context);
            }, $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default');
        } catch (HttpError $failure) {
            AuditLog::append(
                $connection,
                $tenantId,
                $identity,
                'alarm.acknowledged',
                $alarmId,
                $failure->status() === 403 ? 'denied' : 'failed',
                ['reason' => $failure->errorCode()],
                'customer'
            );
            throw $failure;
        }
    }

    /**
     * 查询当前规则或指定规则的发布历史；规则条件冻结，连续状态单独返回。
     * @param array<string, mixed> $filters name、device_id、field及分页。
     * @return array<string, mixed> 有界分页及本次成员权限。
     * @throws HttpError 无权限、规则不存在或筛选无效。
     */
    public static function rules(Connection $connection, Identity $identity, string $tenantId, array $filters, string $ruleId = ''): array
    {
        $context = self::authorize($connection, $identity, $tenantId, 'customer.alarms.read');
        self::filters($filters, ['name', 'device_id', 'field']);
        $where = 'r.tenant_id = ?';
        $parameters = [$tenantId];
        if ($ruleId !== '') {
            self::findRule($connection, $tenantId, $ruleId);
            $where .= ' AND r.id = ?';
            $parameters[] = $ruleId;
        } else {
            $where .= ' AND v.version = r.version';
        }
        foreach (['device_id', 'field'] as $key) {
            if (($filters[$key] ?? '') !== '') {
                $where .= ' AND r.' . $key . ' = ?';
                $parameters[] = $filters[$key];
            }
        }
        if (($filters['name'] ?? '') !== '') {
            $where .= ' AND v.name LIKE ?';
            $parameters[] = '%' . $filters['name'] . '%';
        }
        $join = ' FROM iot_alarm_rules r JOIN iot_alarm_rule_versions v ON v.rule_id = r.id'
            . ' JOIN iot_alarm_states s ON s.rule_id = v.rule_id AND s.rule_version = v.version WHERE ' . $where;
        $page = self::page(
            $connection,
            'r.*, v.version AS rule_version, v.definition, v.starts_after, v.ends_after, v.published_at, v.actor_id, v.retired_at, v.end_reason,'
            . ' s.last_sequence, s.last_sampled_at, s.last_received_at, s.trigger_count, s.recovery_count, s.active_alarm_id, s.finalized',
            $join,
            $ruleId === '' ? 'r.created_at DESC, r.id' : 'v.version DESC',
            $parameters,
            $filters
        );
        $items = [];
        foreach ($page['items'] as $row) {
            $items[] = self::decodeRule($row);
        }
        $page['items'] = $items;
        return $page + ['context' => $context];
    }

    /**
     * 发布一个不可变条件版本；设备、归属、模型和属性保持固定，变更监控范围须新建规则。
     * 设备写锁捕获已接收序号边界，旧积压仍由旧版本处理，不把发布前历史追认为新规则样本。
     * @param array<string, mixed> $data name、lower、upper、hysteresis、enabled；新建另含device_id和field，编辑含version。
     * @return array<string, mixed> 当前规则及新发布版本。
     * @throws HttpError 无权限、设备/属性无效、回差区间无效、规则额度或版本冲突。
     */
    public static function publish(Connection $connection, Identity $identity, string $tenantId, array $data, string $ruleId = ''): array
    {
        try {
            self::authorize($connection, $identity, $tenantId, 'customer.alarms.manage');
            return $connection->transaction(static function (Connection $transaction) use ($identity, $tenantId, $data, $ruleId): array {
                RoleService::lockAuthorization($transaction);
                // MySQL一致性读快照须在设备锁之后建立，才能看到等待期间提交的序号与告警状态。
                $rule = $ruleId === '' ? [] : self::findRule($transaction, $tenantId, $ruleId, true);
                $deviceId = $ruleId === '' ? ($data['device_id'] ?? '') : $rule['device_id'];
                $device = self::lockDevice($transaction, $deviceId);
                self::authorize($transaction, $identity, $tenantId, 'customer.alarms.manage');
                if ($device['tenant_id'] !== $tenantId) {
                    throw new HttpError(404, 'device_not_found');
                }
                if ($ruleId !== '' && ($device['ownership_id'] !== $rule['ownership_id']
                    || $device['product_id'] !== $rule['product_id'] || (int) $device['model_version'] !== (int) $rule['model_version'])) {
                    throw new HttpError(409, 'alarm_device_scope_changed');
                }
                $field = $ruleId === '' ? ($data['field'] ?? '') : $rule['field'];
                $model = ProductService::publishedModel($transaction, $tenantId, $device['product_id'], (int) $device['model_version']);
                $property = null;
                foreach ($model['definition']['properties'] as $candidate) {
                    if ($candidate['identifier'] === $field && in_array($candidate['type'], ['integer', 'number'], true)) {
                        $property = $candidate;
                    }
                }
                if ($property === null) {
                    throw new HttpError(422, 'alarm_numeric_field_required');
                }
                $definition = self::definition($data) + ['property' => $property];
                $now = time();
                $current = $transaction->table('iot_current_data')->where('device_id', '=', $deviceId)->where('ownership_id', '=', $device['ownership_id'])
                    ->where('model_version', '=', $device['model_version'])->first();
                $boundary = $current['sequence'] ?? '0';
                $number = 1;
                if ($ruleId === '') {
                    if ((int) $transaction->table('iot_alarm_rules')->where('device_id', '=', $deviceId)->where('ownership_id', '=', $device['ownership_id'])->aggregate('COUNT') >= 64) {
                        throw new HttpError(409, 'alarm_rule_limit');
                    }
                    $rule = ['id' => bin2hex(random_bytes(16)), 'tenant_id' => $tenantId, 'device_id' => $deviceId, 'ownership_id' => $device['ownership_id'],
                        'product_id' => $device['product_id'], 'model_version' => (int) $device['model_version'], 'field' => $field,
                        'version' => 1, 'created_at' => $now, 'updated_at' => $now];
                    $transaction->table('iot_alarm_rules')->insert($rule);
                } else {
                    if ((int) ($data['version'] ?? 0) !== (int) $rule['version'] || (int) $rule['version'] >= 2147483646) {
                        throw new HttpError(409, 'stale_version');
                    }
                    $number = (int) $rule['version'] + 1;
                    $transaction->table('iot_alarm_rule_versions')->where('rule_id', '=', $ruleId)->where('version', '=', $rule['version'])
                        ->update(['ends_after' => $boundary, 'retired_at' => $now, 'end_reason' => $definition['enabled'] ? 'rule_changed' : 'rule_disabled']);
                    self::finishRetired($transaction, $rule, (int) $rule['version'], '');
                    $transaction->table('iot_alarm_rules')->where('id', '=', $ruleId)->update(['version' => $number, 'updated_at' => $now]);
                }
                $transaction->table('iot_alarm_rule_versions')->insert(['rule_id' => $rule['id'], 'version' => $number, 'name' => $definition['name'],
                    'definition' => json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                    'starts_after' => $boundary, 'ends_after' => null, 'published_at' => $now, 'actor_id' => $identity->subject(), 'retired_at' => null, 'end_reason' => null]);
                $transaction->table('iot_alarm_states')->insert(['rule_id' => $rule['id'], 'rule_version' => $number, 'last_sequence' => null,
                    'last_sampled_at' => null, 'last_received_at' => null, 'trigger_count' => 0, 'recovery_count' => 0, 'active_alarm_id' => null, 'finalized' => 0]);
                AuditLog::append($transaction, $tenantId, $identity, 'alarm.rule_published', $rule['id'], 'success', ['version' => $number], 'customer');
                return ['id' => $rule['id'], 'version' => $number, 'definition' => $definition, 'starts_after' => $boundary];
            }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
        } catch (HttpError $failure) {
            if ($connection->table('iot_tenants')->where('id', '=', $tenantId)->first() !== null) {
                AuditLog::append(
                    $connection,
                    $tenantId,
                    $identity,
                    'alarm.rule_published',
                    $ruleId === '' ? $tenantId : $ruleId,
                    $failure->status() === 403 ? 'denied' : 'failed',
                    ['reason' => $failure->errorCode()],
                    'customer'
                );
            }
            throw $failure;
        }
    }

    /**
     * 处理最多100条待办；设备写锁内按十进制序号选择同归属最早前进事实，防止同秒或多进程逆序丢计数。
     * 平台接收回执不等待本角色；不修改aggregate/current的完成事实，异常及进程中断整条回滚。
     * @return array{examined: int, completed: int, already_completed: int, has_more: bool}
     */
    public static function run(Connection $connection, int $batch = 100): array
    {
        $facts = IngestionService::pending($connection, 'alarm', $batch);
        $completed = 0;
        foreach ($facts as $candidate) {
            $changed = $connection->transaction(static function (Connection $transaction) use ($candidate): bool {
                self::lockDevice($transaction, $candidate['device_id']);
                $messageId = $candidate['message_id'];
                if ($candidate['current_advanced']) {
                    $rows = $transaction->query('SELECT f.message_id FROM iot_ingestion_facts f WHERE f.device_id = ? AND f.ownership_id = ? AND f.current_advanced = 1'
                        . " AND NOT EXISTS (SELECT 1 FROM iot_ingestion_completed c WHERE c.message_id = f.message_id AND c.consumer = 'alarm')"
                        . ' ORDER BY LENGTH(f.sequence), f.sequence LIMIT 1', [$candidate['device_id'], $candidate['ownership_id']]);
                    if ($rows === []) {
                        return false;
                    }
                    $messageId = (string) $rows[0]['message_id'];
                }
                return IngestionService::consume($transaction, $messageId, 'alarm', static function (Connection $effect, array $fact): void {
                    self::apply($effect, $fact);
                });
            }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
            if ($changed) {
                $completed++;
            }
        }
        return ['examined' => count($facts), 'completed' => $completed, 'already_completed' => count($facts) - $completed,
            'has_more' => IngestionService::pending($connection, 'alarm', 1) !== []];
    }

    /**
     * 告警列表及详情读取冻结版本；历史归属不依赖设备当前租户，不提供人工确认或伪装恢复。
     * @param array<string, mixed> $filters device_id、rule_id、status、from/to及分页。
     * @return array<string, mixed> 列表含context；指定ID返回独立详情。
     * @throws HttpError 无权限、时间无效或告警不存在。
     */
    public static function alarms(Connection $connection, Identity $identity, string $tenantId, array $filters, string $alarmId = ''): array
    {
        $context = self::authorize($connection, $identity, $tenantId, 'customer.alarms.read');
        return self::readAlarms($connection, $tenantId, $filters, $alarmId, $context);
    }

    /** 已获查询或确认授权后的同一投影；确认节点不隐式要求查询节点。 */
    private static function readAlarms(Connection $connection, string $tenantId, array $filters, string $alarmId, array $context): array
    {
        self::filters($filters, ['device_id', 'rule_id', 'status', 'from', 'to']);
        $where = 'a.tenant_id = ?';
        $parameters = [$tenantId];
        foreach (['device_id', 'rule_id', 'status'] as $key) {
            if (($filters[$key] ?? '') !== '') {
                $where .= ' AND a.' . $key . ' = ?';
                $parameters[] = $filters[$key];
            }
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (isset($filters[$key])) {
                $where .= ' AND a.created_at ' . $operator . ' ?';
                $parameters[] = $filters[$key];
            }
        }
        if ($alarmId !== '') {
            $where .= ' AND a.id = ?';
            $parameters[] = $alarmId;
        }
        $join = ' FROM iot_alarms a JOIN iot_alarm_rule_versions v ON v.rule_id = a.rule_id AND v.version = a.rule_version WHERE ' . $where;
        $page = self::page($connection, 'a.*, v.definition, v.retired_at AS rule_retired_at', $join, 'a.created_at DESC, a.id', $parameters, $filters);
        $items = [];
        foreach ($page['items'] as $row) {
            $row['definition'] = json_decode((string) $row['definition'], true, 16, JSON_THROW_ON_ERROR);
            $row['trigger'] = json_decode((string) $row['trigger_json'], true, 8, JSON_THROW_ON_ERROR);
            $row['recovery'] = $row['recovery_json'] === null ? null : json_decode((string) $row['recovery_json'], true, 8, JSON_THROW_ON_ERROR);
            unset($row['trigger_json'], $row['recovery_json']);
            foreach (['rule_version', 'model_version', 'created_at'] as $key) {
                $row[$key] = (int) $row[$key];
            }
            foreach (['ended_at', 'rule_retired_at', 'acknowledged_at'] as $key) {
                $row[$key] = $row[$key] === null ? null : (int) $row[$key];
            }
            unset($row['trigger_notified'], $row['end_notified']);
            $items[] = $row;
        }
        if ($alarmId !== '') {
            if ($items === []) {
                throw new HttpError(404, 'alarm_not_found');
            }
            return $items[0];
        }
        $page['items'] = $items;
        return $page + ['context' => $context];
    }

    /** 即时重验客户会话、模拟来源及当前租户权限；写入方在授权锁和业务锁之后调用。 */
    private static function authorize(Connection $connection, Identity $identity, string $tenantId, string $permission): array
    {
        $current = (new IdentityService('customer'))->refresh($connection, $identity);
        if ($current === null) {
            throw new HttpError(401, 'unauthenticated');
        }
        $permissions = RoleService::permissions($connection, $current, 'customer', $tenantId);
        if (!in_array($permission, $permissions, true)) {
            throw new HttpError(403, 'permission_denied');
        }
        return ['permissions' => $permissions, 'menus' => RoleService::menus('customer', $permissions), 'identity' => IdentityService::context($current, $tenantId)];
    }

    /** 已接受且确实推进当前序号的实时遥测才参与；以首次接收时间判定，工作进程迟启不丢失待办。 */
    private static function apply(Connection $connection, array $fact): void
    {
        if ($fact['type'] !== 'telemetry' || !$fact['current_advanced'] || $fact['sampled_at'] < $fact['received_at'] - 30 || $fact['sampled_at'] > $fact['received_at'] + 5) {
            return;
        }
        $rules = $connection->query(
            'SELECT * FROM iot_alarm_rules WHERE tenant_id = ? AND device_id = ? AND ownership_id = ? AND product_id = ? AND model_version = ? ORDER BY id LIMIT 64',
            [$fact['tenant_id'], $fact['device_id'], $fact['ownership_id'], $fact['product_id'], $fact['model_version']]
        );
        foreach ($rules as $rule) {
            $versions = $connection->query('SELECT * FROM iot_alarm_rule_versions WHERE rule_id = ? AND ' . self::beforeSequence('starts_after')
                . ' ORDER BY version DESC LIMIT 1', [$rule['id'], strlen($fact['sequence']), strlen($fact['sequence']), $fact['sequence']]);
            if ($versions === []) {
                continue;
            }
            $version = $versions[0];
            $definition = json_decode((string) $version['definition'], true, 16, JSON_THROW_ON_ERROR);
            if ($definition['enabled'] && array_key_exists($rule['field'], $fact['values'])) {
                self::sample($connection, $rule, $version, $definition, $fact);
            }
            self::finishRetired($connection, $rule, (int) $version['version'], $fact['message_id']);
        }
    }

    /** 同设备写锁覆盖计数和唯一活动指针；有效样本间采样/首次接收间隔>30秒或时钟倒退均重置计数。 */
    private static function sample(Connection $connection, array $rule, array $version, array $definition, array $fact): void
    {
        $query = $connection->table('iot_alarm_states')->where('rule_id', '=', $rule['id'])->where('rule_version', '=', $version['version']);
        $state = $query->first();
        if ((int) $state['finalized'] === 1 || ($state['last_sequence'] !== null && !self::greater($fact['sequence'], (string) $state['last_sequence']))) {
            return;
        }
        $value = (float) $fact['values'][$rule['field']];
        $continuous = $state['last_sampled_at'] !== null && $fact['sampled_at'] >= (int) $state['last_sampled_at']
            && $fact['sampled_at'] - (int) $state['last_sampled_at'] <= 30 && $fact['received_at'] >= (int) $state['last_received_at']
            && $fact['received_at'] - (int) $state['last_received_at'] <= 30;
        $triggerCount = $continuous ? (int) $state['trigger_count'] : 0;
        $recoveryCount = $continuous ? (int) $state['recovery_count'] : 0;
        $activeId = $state['active_alarm_id'];
        if ($activeId === null) {
            $outside = ($definition['lower'] !== null && $value < $definition['lower']) || ($definition['upper'] !== null && $value > $definition['upper']);
            $triggerCount = $outside ? $triggerCount + 1 : 0;
            $recoveryCount = 0;
            if ($triggerCount === 3) {
                $activeId = bin2hex(random_bytes(16));
                $connection->table('iot_alarms')->insert(['id' => $activeId, 'tenant_id' => $rule['tenant_id'], 'device_id' => $rule['device_id'],
                    'ownership_id' => $rule['ownership_id'], 'product_id' => $rule['product_id'], 'model_version' => (int) $rule['model_version'],
                    'field' => $rule['field'], 'rule_id' => $rule['id'], 'rule_version' => (int) $version['version'], 'status' => 'active',
                    'trigger_json' => self::sampleJson($fact, $value), 'recovery_json' => null, 'created_at' => $fact['received_at'], 'ended_at' => null, 'end_reason' => null]);
                NoticeService::enqueue($connection, $activeId, 'triggered');
                $triggerCount = 0;
            }
        } else {
            $recovered = ($definition['lower'] === null || $value >= $definition['lower'] + $definition['hysteresis'])
                && ($definition['upper'] === null || $value <= $definition['upper'] - $definition['hysteresis']);
            $recoveryCount = $recovered ? $recoveryCount + 1 : 0;
            $triggerCount = 0;
            if ($recoveryCount === 3) {
                $connection->table('iot_alarms')->where('id', '=', $activeId)->where('status', '=', 'active')->update(['status' => 'ended',
                    'recovery_json' => self::sampleJson($fact, $value), 'ended_at' => $fact['received_at'], 'end_reason' => 'value_recovered']);
                NoticeService::enqueue($connection, $activeId, 'ended');
                $activeId = null;
                $recoveryCount = 0;
            }
        }
        $query->update(['last_sequence' => $fact['sequence'], 'last_sampled_at' => $fact['sampled_at'], 'last_received_at' => $fact['received_at'],
            'trigger_count' => $triggerCount, 'recovery_count' => $recoveryCount, 'active_alarm_id' => $activeId]);
    }

    /** 版本边界内的已接收有效事实全部完成后结束旧活动；缺测或断流本身永不调用本结束分支。 */
    private static function finishRetired(Connection $connection, array $rule, int $number, string $excludedMessage): void
    {
        $version = $connection->table('iot_alarm_rule_versions')->where('rule_id', '=', $rule['id'])->where('version', '=', $number)->first();
        if ($version['retired_at'] === null) {
            return;
        }
        $state = $connection->table('iot_alarm_states')->where('rule_id', '=', $rule['id'])->where('rule_version', '=', $number);
        $current = $state->first();
        if ((int) $current['finalized'] === 1) {
            return;
        }
        $start = (string) $version['starts_after'];
        $end = (string) $version['ends_after'];
        $pending = $connection->query(
            'SELECT f.message_id FROM iot_ingestion_facts f WHERE f.device_id = ? AND f.ownership_id = ? AND f.product_id = ? AND f.model_version = ?'
            . " AND f.type = 'telemetry' AND f.current_advanced = 1 AND f.sampled_at >= f.received_at - 30 AND f.sampled_at <= f.received_at + 5"
            . ' AND f.message_id <> ? AND NOT ' . self::atMostSequence('f.sequence') . ' AND ' . self::atMostSequence('f.sequence')
            . " AND NOT EXISTS (SELECT 1 FROM iot_ingestion_completed c WHERE c.message_id = f.message_id AND c.consumer = 'alarm') LIMIT 1",
            [$rule['device_id'], $rule['ownership_id'], $rule['product_id'], $rule['model_version'], $excludedMessage, strlen($start), strlen($start), $start, strlen($end), strlen($end), $end]
        );
        if ($pending !== []) {
            return;
        }
        if ($current['active_alarm_id'] !== null) {
            $connection->table('iot_alarms')->where('id', '=', $current['active_alarm_id'])->where('status', '=', 'active')
                ->update(['status' => 'ended', 'ended_at' => (int) $version['retired_at'], 'end_reason' => $version['end_reason']]);
            NoticeService::enqueue($connection, (string) $current['active_alarm_id'], 'ended');
        }
        $state->update(['active_alarm_id' => null, 'trigger_count' => 0, 'recovery_count' => 0, 'finalized' => 1]);
    }

    private static function definition(array $data): array
    {
        $lower = $data['lower'] ?? null;
        $upper = $data['upper'] ?? null;
        $hysteresis = $data['hysteresis'] ?? 0;
        $name = $data['name'] ?? '';
        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 100 || !is_bool($data['enabled'] ?? true)
            || (!is_int($hysteresis) && !is_float($hysteresis)) || !is_finite((float) $hysteresis) || $hysteresis < 0 || ($lower === null && $upper === null)) {
            throw new HttpError(422, 'alarm_interval_invalid');
        }
        foreach ([$lower, $upper] as $bound) {
            if ($bound !== null && ((!is_int($bound) && !is_float($bound)) || !is_finite((float) $bound))) {
                throw new HttpError(422, 'alarm_interval_invalid');
            }
        }
        if (($lower !== null && !is_finite((float) $lower + (float) $hysteresis)) || ($upper !== null && !is_finite((float) $upper - (float) $hysteresis))
            || ($lower !== null && $upper !== null && (float) $lower + (float) $hysteresis > (float) $upper - (float) $hysteresis)) {
            throw new HttpError(422, 'alarm_interval_invalid');
        }
        return ['name' => trim($name), 'lower' => $lower, 'upper' => $upper, 'hysteresis' => (float) $hysteresis, 'enabled' => $data['enabled'] ?? true];
    }

    private static function lockDevice(Connection $connection, string $deviceId): array
    {
        $query = $connection->table('iot_devices')->where('id', '=', $deviceId);
        $device = ($connection->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
        if ($device === null) {
            throw new HttpError(404, 'device_not_found');
        }
        return $device;
    }

    private static function findRule(Connection $connection, string $tenantId, string $ruleId, bool $lock = false): array
    {
        $query = $connection->table('iot_alarm_rules')->where('tenant_id', '=', $tenantId)->where('id', '=', $ruleId);
        $rule = ($lock && $connection->driverName() !== 'sqlite' ? $query->lockForUpdate() : $query)->first();
        if ($rule === null) {
            throw new HttpError(404, 'alarm_rule_not_found');
        }
        return $rule;
    }

    private static function decodeRule(array $row): array
    {
        $row['definition'] = json_decode((string) $row['definition'], true, 16, JSON_THROW_ON_ERROR);
        foreach (['version', 'rule_version', 'model_version', 'created_at', 'updated_at', 'published_at', 'trigger_count', 'recovery_count', 'finalized'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        foreach (['retired_at', 'last_sampled_at', 'last_received_at'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        }
        return $row;
    }

    private static function sampleJson(array $fact, float $value): string
    {
        return json_encode(['message_id' => $fact['message_id'], 'sequence' => $fact['sequence'], 'sampled_at' => $fact['sampled_at'],
            'received_at' => $fact['received_at'], 'value' => $value], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function greater(string $left, string $right): bool
    {
        return strlen($left) > strlen($right) || (strlen($left) === strlen($right) && strcmp($left, $right) > 0);
    }

    private static function beforeSequence(string $column): string
    {
        return '(LENGTH(' . $column . ') < ? OR (LENGTH(' . $column . ') = ? AND ' . $column . ' < ?))';
    }

    private static function atMostSequence(string $column): string
    {
        return '(LENGTH(' . $column . ') < ? OR (LENGTH(' . $column . ') = ? AND ' . $column . ' <= ?))';
    }

    private static function filters(array $filters, array $allowed): void
    {
        if (array_diff(array_keys($filters), [...$allowed, 'page', 'per_page']) !== [] || (isset($filters['from'], $filters['to']) && $filters['from'] > $filters['to'])) {
            throw new HttpError(422, 'alarm_filter_invalid');
        }
        foreach (['device_id', 'rule_id'] as $key) {
            if (($filters[$key] ?? '') !== '' && (!is_string($filters[$key]) || !preg_match('/^[a-f0-9]{32}$/D', $filters[$key]))) {
                throw new HttpError(422, 'alarm_filter_invalid');
            }
        }
        if (($filters['status'] ?? '') !== '' && !in_array($filters['status'], ['active', 'ended'], true)) {
            throw new HttpError(422, 'alarm_filter_invalid');
        }
    }

    private static function page(Connection $connection, string $columns, string $join, string $order, array $parameters, array $filters): array
    {
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 20);
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) {
            throw new HttpError(422, 'invalid_pagination');
        }
        return ['items' => $connection->query('SELECT ' . $columns . $join . ' ORDER BY ' . $order . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $parameters),
            'total' => (int) $connection->query('SELECT COUNT(*) AS total' . $join, $parameters)[0]['total'], 'page' => $page, 'per_page' => $perPage];
    }
}
