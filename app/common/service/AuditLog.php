<?php

declare(strict_types=1);

namespace app\common\service;

use InvalidArgumentException;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Orm\Connection;

/** 人员操作审计与业务变更共用事务；显式允许字段避免记录凭据和任意业务载荷。 */
final class AuditLog
{
    /** 审计上下文与单条事实分开限额，默认100项列表仍受1MiB响应预算约束。 */
    private const CONTEXT_BYTES = 8192;
    private const FACT_BYTES = 4096;

    /**
     * @param string|Identity|array<string, mixed> $actorId 认证身份或可信持久来源保留真实人员；设备事件可传明确设备标识。
     * @param array<string, string|int> $details 只接受不含秘密的角色、版本与身份上下文。
     * @throws InvalidArgumentException 事件或附加字段不在允许范围。
     */
    public static function append(Connection $connection, ?string $tenantId, string|Identity|array $actorId, string $action, string $subjectId, string $result = 'success', array $details = [], string $realm = 'iot'): void
    {
        $actorKey = is_string($actorId) ? $actorId : '';
        if (!is_string($actorId)) {
            $context = $actorId instanceof Identity ? IdentityService::context($actorId, $tenantId ?? '') : $actorId;
            if ($actorId instanceof Identity) {
                $actorKey = $actorId->subject();
            }
            if ($context !== []) {
                $actorKey = $context['actor_id'];
                $details += ['actor_realm' => $context['actor_realm'], 'customer_id' => $context['customer_id'], 'session_id' => $context['session_id'],
                    'source_session_id' => $context['source_session_id'], 'impersonation_id' => $context['impersonation_id'], 'scope_key' => $context['key']];
                if ($context['impersonation_id'] !== '') {
                    $details['context'] = 'customer-impersonation';
                }
            }
        }
        if (!in_array($realm, ['iot', 'broker', 'admin', 'customer'], true)) {
            throw new InvalidArgumentException('audit_realm_invalid');
        }
        if (!preg_match('/^[a-z][a-z0-9_.]{1,79}$/D', $action) || !in_array($result, ['success', 'denied', 'unknown', 'failed'], true)) {
            throw new InvalidArgumentException('审计动作或结果无效');
        }
        foreach ($details as $key => $value) {
            if (!in_array($key, ['role', 'previous_role', 'version', 'context', 'reason', 'device_id', 'ownership_id', 'deadline_at', 'attempt_id', 'scheduled_at', 'claimed_at', 'mqtt_reason', 'kind', 'result_status', 'content_hash', 'switch_id', 'source_version', 'target_version', 'boundary_sequence', 'support_id', 'grantee_id', 'expires_at', 'control_allowed', 'alarm_allowed', 'export_allowed', 'actor_realm', 'customer_id', 'session_id', 'source_session_id', 'impersonation_id', 'scope_key'], true)
                || (!is_string($value) && !is_int($value)) || strlen((string) $value) > ($key === 'reason' && str_starts_with($action, 'support.') ? 400 : 100)) {
                throw new InvalidArgumentException('审计附加字段不在脱敏允许范围');
            }
        }
        $details += ['context' => $tenantId === null ? 'identity' : 'tenant-member'];
        $connection->table($realm . '_audit')->insert([
            'id' => bin2hex(random_bytes(16)), 'tenant_id' => $tenantId, 'actor_id' => $actorKey,
            'action' => $action, 'subject_id' => $subjectId, 'result' => $result,
            'details' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => time(),
        ]);
    }

    /**
     * 新身份审计共用Broker的有界键集查询；平台查询两端脱敏事实，客户只查询当前租户。
     * @param string $source 详情的准确审计表来源；列表留空，不能跨来源复用相同事件ID。
     * @param bool $brokerOnly 只查询Broker类别并额外要求资源读取权限；不把普通审计权限扩展为资源能力。
     * @return array<string, mixed> 每页及详情均重新授权，游标绑定会话、来源与本次权限。
     */
    public static function search(Connection $connection, Identity $identity, string $realm, string $tenantId, string $query, string $eventId = '', string $source = '', bool $brokerOnly = false): array
    {
        $access = RoleService::readContext($connection, $identity, $realm, $tenantId, 'audit.read');
        if ($brokerOnly && !in_array($realm . '.broker.read', $access['permissions'], true)) {
            throw new HttpError(403, 'permission_denied');
        }
        if (($eventId === '') !== ($source === '') || ($source !== '' && !in_array($source, $realm === 'admin' ? ['admin', 'customer'] : ['customer'], true))) {
            throw new HttpError(400, 'audit_source_invalid');
        }
        $result = self::events($connection, $realm, $access, $query, $eventId, true, $source, $brokerOnly);
        if ($access !== RoleService::readContext($connection, $identity, $realm, $tenantId, 'audit.read')) {
            throw new HttpError(403, 'authorization_changed');
        }
        return $result;
    }

    /**
     * 保存已经由调用者确认的事实；审计去重不授权重执行外部动作，也不代替同步提交或资源释放证明。
     * 同步事件只写180天日志；异步事件锁定原操作，冻结身份、授权来源、准确目标和确认影响。
     * @param array<string, mixed> $operation 完整白名单操作上下文，最多8KiB；重试传入原冻结上下文。
     * @param string $eventKey 与stage相同；本票每操作最多五份阶段回执，不能随每次轮询增加键。
     * @param array{request_id:string,stage:string,result:string,facts:array<string,string|bool>} $event 请求ID仅作首次传输关联，不进入逻辑去重摘要。
     * @return array<string, mixed> 原事件身份及当前操作阶段；duplicate/expired不表示可再次执行动作。
     */
    public static function recordBroker(Connection $connection, string $realm, array $operation, string $eventKey, array $event): array
    {
        self::realm($realm);
        $context = self::operationContext($operation);
        if (isset($context['identity']) && $context['identity']['realm'] !== $realm) {
            throw new InvalidArgumentException('broker_audit_identity_invalid');
        }
        self::shape($event, ['request_id', 'stage', 'result', 'facts']);
        $stage = $event['stage'];
        if (!self::identifier($event['request_id']) || !is_string($stage) || $eventKey !== $stage
            || !in_array($stage, ['accepted', 'executing', 'unknown', 'completed', 'failed'], true)
            || !in_array($event['result'], match ($stage) {
                'accepted', 'executing' => ['pending'], 'unknown' => ['unknown'], 'completed' => ['success'], default => ['failed', 'denied'],
            }, true) || !is_array($event['facts'])) {
            throw new InvalidArgumentException('broker_audit_event_invalid');
        }
        $facts = $event['facts'];
        foreach ($facts as $key => $value) {
            if (!in_array($key, ['reason', 'store_confirmed', 'resources_released', 'observation_isolated'], true)
                || ($key === 'reason' ? !self::code($value, 100) : !is_bool($value))) {
                throw new InvalidArgumentException('broker_audit_facts_invalid');
            }
        }
        ksort($facts);
        if (strlen(self::json($facts)) > self::FACT_BYTES || ($context['mode'] === 'sync' && !in_array($stage, ['completed', 'failed'], true))) {
            throw new InvalidArgumentException('broker_audit_event_invalid');
        }
        if ($context['mode'] === 'async' && $stage === 'completed'
            && (($facts['store_confirmed'] ?? false) !== true || ($facts['resources_released'] ?? false) !== true
                || ($context['target']['observation_run'] !== '' && ($facts['observation_isolated'] ?? false) !== true))) {
            throw new InvalidArgumentException('broker_audit_completion_unproven');
        }
        $contextJson = self::json($context);
        $contentHash = hash('sha256', self::json([$context, $eventKey, $stage, $event['result'], $facts]));
        return $connection->transaction(static function (Connection $transaction) use ($realm, $context, $contextJson, $contentHash, $eventKey, $event, $stage, $facts): array {
            $now = time();
            $receipt = ['event_id' => bin2hex(random_bytes(16)), 'operation_id' => $context['operation_id'], 'stage' => $stage,
                'result' => $event['result'], 'created_at' => $now, 'content_hash' => $contentHash];
            $currentStage = $stage;
            $currentResult = $event['result'];
            $version = 1;
            $receipts = [];
            if ($context['mode'] === 'async') {
                $table = $realm . '_broker_operations';
                $candidate = ['operation_id' => $context['operation_id'], 'context_json' => $contextJson, 'context_hash' => hash('sha256', $contextJson),
                    'stage' => 'accepted', 'result' => 'pending', 'version' => 0, 'receipts_json' => '{}', 'created_at' => $now, 'updated_at' => $now];
                // 唯一键只有operation_id；冲突只同值更新主键，绝不覆盖已有上下文或阶段。
                if ($transaction->driverName() === 'mysql') {
                    $transaction->table($table)->upsertAnyUnique([$candidate], ['operation_id']);
                } else {
                    $transaction->table($table)->upsert([$candidate], ['operation_id'], ['operation_id']);
                }
                $query = $transaction->table($table)->where('operation_id', '=', $context['operation_id']);
                $stored = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
                if ($stored === null || $stored['context_hash'] !== $candidate['context_hash'] || $stored['context_json'] !== $contextJson) {
                    throw new HttpError(409, 'broker_audit_operation_conflict');
                }
                $receipts = json_decode((string) $stored['receipts_json'], true, 8, JSON_THROW_ON_ERROR);
                if (isset($receipts[$eventKey])) {
                    $original = $receipts[$eventKey];
                    if ($original['content_hash'] !== $contentHash) {
                        throw new HttpError(409, 'broker_audit_event_conflict');
                    }
                    $expired = (int) $original['created_at'] <= $now - 180 * 86400
                        || $transaction->table($realm . '_audit')->where('id', '=', $original['event_id'])->first() === null;
                    unset($original['content_hash']);
                    return $original + ['duplicate' => true, 'expired' => $expired, 'current_stage' => $stored['stage'], 'current_result' => $stored['result'], 'version' => (int) $stored['version']];
                }
                if ($receipts === [] && $stage !== 'accepted') {
                    throw new HttpError(409, 'broker_audit_operation_not_accepted');
                }
                if (in_array($stored['stage'], ['completed', 'failed'], true) && in_array($stage, ['completed', 'failed'], true) && $stored['stage'] !== $stage) {
                    throw new HttpError(409, 'broker_audit_terminal_conflict');
                }
                $ranks = ['accepted' => 0, 'executing' => 1, 'unknown' => 2, 'completed' => 3, 'failed' => 3];
                if ($ranks[$stage] < $ranks[$stored['stage']]) {
                    $currentStage = $stored['stage'];
                    $currentResult = $stored['result'];
                }
                $version = (int) $stored['version'] + 1;
                $receipts[$eventKey] = $receipt;
                if (count($receipts) > 5 || strlen(self::json($receipts)) > self::CONTEXT_BYTES) {
                    throw new InvalidArgumentException('broker_audit_receipt_budget');
                }
                $transaction->table($table)->where('operation_id', '=', $context['operation_id'])->update([
                    'stage' => $currentStage, 'result' => $currentResult, 'version' => $version, 'receipts_json' => self::json($receipts), 'updated_at' => $now,
                ]);
            }
            $transaction->table($realm . '_audit')->insert([
                'id' => $receipt['event_id'], 'tenant_id' => $context['tenant_id'], 'actor_id' => $context['actor_id'], 'action' => $context['action'],
                'subject_id' => $context['subject_id'], 'result' => $event['result'], 'category' => 'broker', 'operation_id' => $context['operation_id'],
                'event_key' => $eventKey, 'request_id' => $event['request_id'], 'stage' => $stage,
                'details' => self::json(($context['mode'] === 'sync' ? ['facts' => $facts, 'operation' => $context] : ['facts' => $facts])
                    + (isset($context['identity']) ? ['identity' => $context['identity']] : [])), 'created_at' => $now,
            ]);
            unset($receipt['content_hash']);
            return $receipt + ['duplicate' => false, 'expired' => false, 'current_stage' => $currentStage, 'current_result' => $currentResult, 'version' => $version];
        }, $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default');
    }

    /** 原异步操作的只读事实；调用者仍须授权，读取不能解除外部动作的未知提交。 */
    public static function brokerOperation(Connection $connection, string $realm, string $operationId): ?array
    {
        self::realm($realm);
        if (!self::identifier($operationId)) {
            throw new InvalidArgumentException('broker_audit_operation_invalid');
        }
        $row = $connection->table($realm . '_broker_operations')->where('operation_id', '=', $operationId)->first();
        if ($row === null) {
            return null;
        }
        return json_decode((string) $row['context_json'], true, 8, JSON_THROW_ON_ERROR) + [
            'current_stage' => $row['stage'], 'current_result' => $row['result'], 'version' => (int) $row['version'],
        ];
    }

    /**
     * 当前独立、平台或单租户授权的事件列表/详情；SQL先限定宿主及归属，游标不是授权凭据。
     * @param array<string, mixed> $authorization 本次请求的人员、角色、动作目录及唯一支持来源。
     * @param string $query 原始查询串，只接受固定筛选；UTC秒闭区间，默认20、最多100项。
     * @return array<string, mixed> 有界键集页，或重新授权读取的完整脱敏详情；无全表计数。
     */
    public static function brokerSearch(Connection $connection, string $realm, array $authorization, string $query, string $eventId = ''): array
    {
        self::realm($realm);
        $access = self::queryAuthorization($realm, $authorization);
        return self::events($connection, $realm, $access, $query, $eventId);
    }

    /** 固定SQL投影、筛选及排序共用一个分页所有者；应用来源作为相同时间与ID之后的稳定收尾。 */
    private static function events(Connection $connection, string $realm, array $access, string $query, string $eventId, bool $application = false, string $source = '', bool $brokerOnly = false): array
    {
        $error = $application ? 'audit' : 'broker_audit';
        $input = self::queryInput($query, $error);
        $limit = isset($input['limit']) ? (int) $input['limit'] : 20;
        $cursor = $input['cursor'] ?? '';
        unset($input['limit'], $input['cursor']);
        ksort($input);
        $binding = hash('sha256', self::json([...[$realm, $access, $input, $limit, $application], ...($brokerOnly ? ['broker'] : [])]));
        $predicates = [];
        $parameters = [];
        if ($brokerOnly || (!$application && $access['scope'] !== 'standalone')) {
            $predicates[] = "a.category = 'broker'";
        } elseif ($application && !in_array($realm . '.broker.read', $access['permissions'], true)) {
            // 普通审计入口也执行资源权限过滤，不能绕过Broker专用入口读取同一事件。
            $predicates[] = "a.category <> 'broker'";
        }
        if ($application ? $realm === 'customer' : $access['scope'] === 'tenant') {
            $predicates[] = 'a.tenant_id = ?';
            $parameters[] = $application ? $access['identity']['tenant_id'] : $access['tenant_id'];
        }
        foreach ($input as $field => $value) {
            $predicates[] = 'a.' . ($field === 'from' || $field === 'to' ? 'created_at' : $field) . ($field === 'from' ? ' >= ?' : ($field === 'to' ? ' <= ?' : ' = ?'));
            $parameters[] = $value;
        }
        if ($eventId !== '') {
            if (!self::identifier($eventId)) {
                throw new HttpError(400, $error . '_id_invalid');
            }
            if ($cursor !== '') {
                throw new HttpError(400, $error . '_cursor_invalid');
            }
            $predicates[] = 'a.id = ?';
            $parameters[] = $eventId;
            if ($application) {
                $predicates[] = 'a.audit_realm = ?';
                $parameters[] = $source;
            }
        } elseif ($cursor !== '') {
            $after = self::cursor($cursor, $error);
            if (($after['context'] ?? null) !== $binding || count($after) !== ($application ? 4 : 3) || !self::identifier($after['id'] ?? null)
                || ($application && !in_array($after['audit_realm'] ?? null, $realm === 'admin' ? ['admin', 'customer'] : ['customer'], true))
                || !is_int($after['created_at'] ?? null) || $after['created_at'] < 0 || $after['created_at'] > 253402300799) {
                throw new HttpError(400, $error . '_cursor_invalid');
            }
            $predicates[] = '(a.created_at < ? OR (a.created_at = ? AND a.id < ?)' . ($application ? ' OR (a.created_at = ? AND a.id = ? AND a.audit_realm < ?)' : '') . ')';
            array_push($parameters, $after['created_at'], $after['created_at'], $after['id']);
            if ($application) {
                array_push($parameters, $after['created_at'], $after['id'], $after['audit_realm']);
            }
        }
        $where = $predicates === [] ? '' : ' WHERE ' . implode(' AND ', $predicates);
        $order = ' ORDER BY a.created_at DESC, a.id DESC' . ($application ? ', a.audit_realm DESC' : '');
        $maximum = $eventId === '' ? $limit + 1 : 1;
        $sql = 'SELECT a.* FROM ' . $realm . '_audit a' . $where . $order . ' LIMIT ' . $maximum;
        if ($application) {
            $fields = 'id, tenant_id, actor_id, action, subject_id, result, details, created_at';
            $branches = [];
            $bound = [];
            foreach ($source !== '' ? [$source] : ($realm === 'admin' ? ['admin', 'customer'] : ['customer']) as $tableRealm) {
                $projection = 'SELECT ' . $fields . ', category, operation_id, request_id, stage'
                    . ", '" . $tableRealm . "' AS audit_realm FROM " . $tableRealm . '_audit';
                // 每个来源先按索引限定至limit+1，再合并至多202行；不把整张双端日志表物化后排序。
                $branches[] = 'SELECT * FROM (SELECT a.* FROM (' . $projection . ') a' . $where . $order . ' LIMIT ' . $maximum . ') audit_part';
                array_push($bound, ...$parameters);
            }
            $sql = 'SELECT a.* FROM (' . implode(' UNION ALL ', $branches) . ') a' . $order . ' LIMIT ' . $maximum;
            $parameters = $bound;
        }
        $rows = $connection->query($sql, $parameters);
        $items = [];
        $bytes = 1024;
        $more = $eventId === '' && count($rows) > $limit;
        foreach ($rows as $row) {
            if ($eventId === '' && count($items) >= $limit) {
                break;
            }
            $item = self::eventProjection($connection, $application ? $row['audit_realm'] : $realm, $row, $eventId !== '');
            // 按HTTP宿主的实际JSON转义计算；旧记录中的斜线也计入1MiB响应上限。
            $size = strlen(json_encode($item, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) + 1;
            if ($bytes + $size > 1048576) {
                $more = true;
                break;
            }
            $items[] = $item;
            $bytes += $size;
        }
        if ($eventId !== '') {
            if ($items === []) {
                throw new HttpError(404, $error . '_not_found');
            }
            return ['found' => true, 'item' => $items[0]];
        }
        $last = $items === [] ? null : $items[count($items) - 1];
        return ['items' => $items, 'next_cursor' => $more && $last !== null ? base64_encode(self::json(['context' => $binding, 'created_at' => $last['created_at'], 'id' => $last['id']]
                + ($application ? ['audit_realm' => $last['audit_realm']] : []))) : null,
            'has_more' => $more, 'total' => null, 'limit' => $limit];
    }

    private static function eventProjection(Connection $connection, string $realm, array $row, bool $detail): array
    {
        $details = json_decode((string) $row['details'], true, 8, JSON_THROW_ON_ERROR);
        $operation = null;
        if ($row['category'] === 'broker' && isset($details['facts'])) {
            if ($detail && isset($details['operation'])) {
                $operation = $details['operation'] + ['current_stage' => $row['stage'], 'current_result' => $row['result'], 'version' => 1];
            } elseif ($detail && $row['operation_id'] !== null) {
                $operation = self::brokerOperation($connection, $realm, $row['operation_id']);
            }
            $origin = $details['identity'] ?? [];
            $details = $details['facts'];
            if ($origin !== []) {
                $details += ['actor_realm' => $origin['actor_realm'], 'customer_id' => $origin['customer_id'], 'session_id' => $origin['session_id'],
                    'source_session_id' => $origin['source_session_id'], 'impersonation_id' => $origin['impersonation_id'], 'scope_key' => $origin['key'],
                    'context' => $origin['impersonation_id'] !== '' ? 'customer-impersonation' : ($origin['realm'] === 'admin' ? 'platform' : 'tenant-member')];
            }
        }
        $item = ['id' => $row['id'], 'tenant_id' => $row['tenant_id'], 'actor_id' => $row['actor_id'], 'action' => $row['action'],
            'subject_id' => $row['subject_id'], 'result' => $row['result'], 'details' => $details, 'created_at' => (int) $row['created_at'],
            'category' => $row['category'], 'operation_id' => $row['operation_id'], 'request_id' => $row['request_id'], 'stage' => $row['stage']];
        if (isset($row['audit_realm'])) {
            $item['audit_realm'] = $row['audit_realm'];
        }
        return $detail ? $item + ['operation' => $operation] : $item;
    }

    private static function queryInput(string $query, string $error): array
    {
        if (strlen($query) > 8192 || substr_count($query, '&') > 9 || preg_match('/%(?![a-fA-F0-9]{2})/', $query) === 1) {
            throw new HttpError(400, $error . '_query_invalid');
        }
        $input = [];
        $seen = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $parts = explode('=', $pair, 2);
            $field = urldecode($parts[0]);
            $value = urldecode($parts[1] ?? '');
            if (!in_array($field, ['actor_id', 'subject_id', 'operation_id', 'action', 'stage', 'result', 'from', 'to', 'limit', 'cursor'], true)
                || isset($seen[$field])) {
                throw new HttpError(400, $error . '_query_invalid');
            }
            $seen[$field] = true;
            if ($field === 'cursor') {
                if (strlen($value) > 2048) {
                    throw new HttpError(400, $error . '_cursor_invalid');
                }
            } elseif ($field === 'limit') {
                if (preg_match('/^[1-9][0-9]{0,2}$/D', $value) !== 1 || (int) $value > 100) {
                    throw new HttpError(400, $error . '_query_invalid');
                }
            } elseif ($value === '') {
                continue;
            } elseif ($field === 'from' || $field === 'to') {
                if (preg_match('/^(?:0|[1-9][0-9]{0,11})$/D', $value) !== 1 || (int) $value > 253402300799) {
                    throw new HttpError(400, $error . '_query_invalid');
                }
            } elseif (($field === 'operation_id' && !self::identifier($value))
                || ($field === 'actor_id' && !self::text($value, 32)) || ($field === 'subject_id' && !self::text($value, 100))
                || ($field === 'action' && !self::code($value, 80))
                || ($field === 'stage' && !in_array($value, ['accepted', 'executing', 'unknown', 'completed', 'failed'], true))
                || ($field === 'result' && !in_array($value, ['pending', 'success', 'failed', 'denied', 'unknown'], true))) {
                throw new HttpError(400, $error . '_query_invalid');
            }
            $input[$field] = $field === 'from' || $field === 'to' ? (int) $value : $value;
        }
        if (isset($input['from'], $input['to']) && $input['from'] > $input['to']) {
            throw new HttpError(422, 'invalid_time_range');
        }
        return $input;
    }

    private static function queryAuthorization(string $realm, array $authorization): array
    {
        self::shape($authorization, ['scope', 'actor_id', 'role', 'permissions', 'tenant_id', 'support_id', 'support_version', 'support_expires_at']);
        if (!in_array($authorization['scope'], ['standalone', 'platform', 'tenant'], true) || !self::identifier($authorization['actor_id'])
            || !self::code($authorization['role'], 64) || !self::identifier($authorization['tenant_id'], true)
            || !self::identifier($authorization['support_id'], true) || !self::optionalNumber($authorization['support_version'])
            || !self::optionalNumber($authorization['support_expires_at'])
            || (($authorization['scope'] === 'standalone') !== ($realm === 'broker'))
            || (($authorization['scope'] === 'tenant') !== ($authorization['tenant_id'] !== null))
            || ($authorization['scope'] !== 'tenant' && $authorization['support_id'] !== null)) {
            throw new InvalidArgumentException('broker_audit_authorization_invalid');
        }
        $authorization['permissions'] = self::permissions($authorization['permissions']);
        ksort($authorization);
        return $authorization;
    }

    private static function operationContext(array $operation): array
    {
        self::shape($operation, [...['operation_id', 'mode', 'origin_request_id', 'actor_id', 'tenant_id', 'action', 'subject_id', 'authorization', 'target', 'impact'],
            ...(array_key_exists('identity', $operation) ? ['identity'] : [])]);
        if (!self::identifier($operation['operation_id']) || !self::identifier($operation['origin_request_id'])
            || !in_array($operation['mode'], ['sync', 'async'], true) || !self::text($operation['actor_id'], 32)
            || !self::identifier($operation['tenant_id'], true) || !self::code($operation['action'], 80) || !self::text($operation['subject_id'], 100)
            || !is_array($operation['authorization']) || !is_array($operation['target']) || !is_array($operation['impact'])) {
            throw new InvalidArgumentException('broker_audit_context_invalid');
        }
        if (isset($operation['identity'])) {
            $identity = $operation['identity'];
            if (!is_array($identity)) {
                throw new InvalidArgumentException('broker_audit_identity_invalid');
            }
            self::shape($identity, ['realm', 'actor_id', 'actor_realm', 'customer_id', 'session_id', 'source_session_id', 'impersonation_id', 'tenant_id', 'key']);
            if (!in_array($identity['realm'], ['admin', 'customer'], true) || !in_array($identity['actor_realm'], ['admin', 'customer'], true)
                || !self::identifier($identity['actor_id']) || !self::identifier($identity['session_id'])
                || $identity['actor_id'] !== $operation['actor_id'] || $identity['tenant_id'] !== ($operation['tenant_id'] ?? '')
                || ($identity['realm'] === 'admin' ? ($identity['tenant_id'] !== '' || $identity['customer_id'] !== '' || $identity['actor_realm'] !== 'admin')
                    : (!self::identifier($identity['tenant_id']) || !self::identifier($identity['customer_id'])))
                || ($identity['source_session_id'] === '' ? ($identity['impersonation_id'] !== '' || $identity['actor_realm'] !== $identity['realm']
                    || ($identity['realm'] === 'customer' && $identity['actor_id'] !== $identity['customer_id']))
                    : (!self::identifier($identity['source_session_id']) || $identity['realm'] !== 'customer' || $identity['actor_realm'] !== 'admin'
                        || $identity['impersonation_id'] !== $identity['session_id']))
                || $identity['key'] !== hash('sha256', json_encode([$identity['realm'], $identity['actor_id'], $identity['realm'] === 'customer' ? $identity['customer_id'] : $identity['actor_id'],
                    $identity['session_id'], $identity['source_session_id'], $identity['tenant_id']], JSON_THROW_ON_ERROR))) {
                throw new InvalidArgumentException('broker_audit_identity_invalid');
            }
            ksort($identity);
            $operation['identity'] = $identity;
        } elseif (array_key_exists('identity', $operation)) {
            throw new InvalidArgumentException('broker_audit_identity_invalid');
        }
        $authorization = $operation['authorization'];
        self::shape($authorization, ['source', 'role', 'permissions', 'required_action', 'decision', 'support_id', 'support_version', 'support_expires_at']);
        if (!self::code($authorization['source'], 64) || !self::code($authorization['role'], 64) || !self::code($authorization['required_action'], 80)
            || !in_array($authorization['decision'], ['allowed', 'denied'], true) || !self::identifier($authorization['support_id'], true)
            || !self::optionalNumber($authorization['support_version']) || !self::optionalNumber($authorization['support_expires_at'])
            || ($authorization['support_id'] !== null && $operation['tenant_id'] === null)) {
            throw new InvalidArgumentException('broker_audit_context_invalid');
        }
        $authorization['permissions'] = self::permissions($authorization['permissions']);
        ksort($authorization);
        $target = $operation['target'];
        self::shape($target, ['kind', 'node_id', 'node_run_id', 'observation_run', 'generation']);
        if (!self::code($target['kind'], 80) || !self::text($target['node_id'], 64, true)
            || ($target['node_run_id'] !== '' && !self::identifier($target['node_run_id']))
            || ($target['observation_run'] !== '' && !self::identifier($target['observation_run']))
            || !is_int($target['generation']) || $target['generation'] < 0) {
            throw new InvalidArgumentException('broker_audit_target_invalid');
        }
        ksort($target);
        $impact = $operation['impact'];
        self::shape($impact, ['confirmed', 'effect', 'target_count', 'proof_hash']);
        if (!is_bool($impact['confirmed']) || !self::code($impact['effect'], 100) || !is_int($impact['target_count']) || $impact['target_count'] < 0 || $impact['target_count'] > 100
            || !is_string($impact['proof_hash']) || ($impact['proof_hash'] !== '' && preg_match('/^[a-f0-9]{64}$/D', $impact['proof_hash']) !== 1)) {
            throw new InvalidArgumentException('broker_audit_impact_invalid');
        }
        ksort($impact);
        $operation['authorization'] = $authorization;
        $operation['target'] = $target;
        $operation['impact'] = $impact;
        ksort($operation);
        if (strlen(self::json($operation)) > self::CONTEXT_BYTES) {
            throw new InvalidArgumentException('broker_audit_context_budget');
        }
        return $operation;
    }

    private static function permissions(mixed $permissions): array
    {
        if (!is_array($permissions) || !array_is_list($permissions) || count($permissions) > 64) {
            throw new InvalidArgumentException('broker_audit_permissions_invalid');
        }
        foreach ($permissions as $permission) {
            if (!self::code($permission, 80)) {
                throw new InvalidArgumentException('broker_audit_permissions_invalid');
            }
        }
        $permissions = array_values(array_unique($permissions));
        sort($permissions);
        return $permissions;
    }

    private static function shape(array $value, array $fields): void
    {
        if (count($value) !== count($fields)) {
            throw new InvalidArgumentException('broker_audit_shape_invalid');
        }
        foreach ($fields as $field) {
            if (!array_key_exists($field, $value)) {
                throw new InvalidArgumentException('broker_audit_shape_invalid');
            }
        }
    }

    private static function realm(string $realm): void
    {
        if (!in_array($realm, ['iot', 'broker', 'admin', 'customer'], true)) {
            throw new InvalidArgumentException('audit_realm_invalid');
        }
    }

    private static function identifier(mixed $value, bool $nullable = false): bool
    {
        return ($nullable && $value === null) || (is_string($value) && preg_match('/^[a-f0-9]{32}$/D', $value) === 1);
    }

    private static function optionalNumber(mixed $value): bool
    {
        return $value === null || (is_int($value) && $value > 0 && $value <= 253402300799);
    }

    private static function text(mixed $value, int $maximum, bool $empty = false): bool
    {
        return is_string($value) && ($empty || $value !== '') && strlen($value) <= $maximum
            && preg_match('//u', $value) === 1 && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1;
    }

    private static function code(mixed $value, int $maximum): bool
    {
        return is_string($value) && strlen($value) <= $maximum && preg_match('/^[a-z][a-z0-9_.:-]*$/D', $value) === 1;
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function cursor(string $value, string $error): array
    {
        $decoded = base64_decode($value, true);
        try {
            $cursor = $decoded === false ? null : json_decode($decoded, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpError(400, $error . '_cursor_invalid');
        }
        if (!is_array($cursor) || base64_encode($decoded) !== $value) {
            throw new HttpError(400, $error . '_cursor_invalid');
        }
        return $cursor;
    }

    /**
     * 受控维护命令单次删除至多1000条创建满180天的审计；不清理接收账本或其他业务表。
     * 每批事务独立提交，不推进外部游标；失败或进程中断后可重跑，未知提交也不会扩展删除范围。
     * @return array{deleted: int, has_more: bool, cutoff: int}
     */
    public static function prune(Connection $connection, int $batch, string $realm = 'iot'): array
    {
        self::realm($realm);
        if ($batch < 1 || $batch > 1000) {
            throw new InvalidArgumentException('审计清理批次必须为1至1000');
        }
        $cutoff = time() - 180 * 86400;
        return $connection->transaction(static function (Connection $transaction) use ($batch, $cutoff, $realm): array {
            $rows = $transaction->table($realm . '_audit')->where('created_at', '<=', $cutoff)->orderBy('created_at')->orderBy('id')->limit($batch)->get();
            $deleted = 0;
            foreach ($rows as $row) {
                $deleted += $transaction->table($realm . '_audit')->where('id', '=', $row['id'])->where('created_at', '<=', $cutoff)->delete();
            }
            $remaining = $transaction->table($realm . '_audit')->where('created_at', '<=', $cutoff)->limit(1)->first();
            return ['deleted' => $deleted, 'has_more' => $remaining !== null, 'cutoff' => $cutoff];
        }, $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default');
    }
}
