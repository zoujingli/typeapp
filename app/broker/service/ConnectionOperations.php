<?php

declare(strict_types=1);

namespace app\broker\service;

use app\common\service\AuditLog;
use Type\Core\Http\HttpError;
use Type\Orm\Connection;

/**
 * 精确连接管理操作及结果对账；断开保留会话，终止会话与清保留复用同一表。
 * 受理不等于已完成；断开完成要求观察行消失，终止还要求持久会话删除证明，清保留要求原件代次删除证明，失联节点保持待处理。
 */
final class ConnectionOperations
{
    private const OFFLINE_OWNER = '00000000000000000000000000000000';
    private const STORE_NODE = 'store';

    /**
     * @param array{tenant_id:?string,actor_id:string,actor_realm:string,permissions:list<string>,identity:?array<string,mixed>} $context 已重新授权的人员范围。
     * @param array<string, mixed> $payload 客户端 JSON；只接受会话代次、节点与可选操作标识。
     * @return array<string, mixed> 冻结操作及当前阶段，不含 Client ID 或秘密。
     */
    public static function request(Connection $connection, array $context, string $ownerId, array $payload, string $requestId): array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $ownerId) !== 1) {
            throw new HttpError(400, 'broker_connection_id_invalid');
        }
        if (array_diff(array_keys($payload), ['session_id', 'session_generation', 'node_id', 'node_run_id', 'operation_id']) !== []) {
            throw new HttpError(422, 'broker_connection_invalid');
        }
        $sessionId = $payload['session_id'] ?? null;
        $generation = $payload['session_generation'] ?? null;
        $nodeId = $payload['node_id'] ?? null;
        $nodeRunId = $payload['node_run_id'] ?? '';
        $operationId = $payload['operation_id'] ?? bin2hex(random_bytes(16));
        if (!is_string($sessionId) || ($sessionId !== '' && preg_match('/^[a-f0-9]{32}$/D', $sessionId) !== 1)
            || !is_int($generation) || $generation < 0
            || !is_string($nodeId) || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $nodeId) !== 1
            || !is_string($nodeRunId) || ($nodeRunId !== '' && preg_match('/^[a-f0-9]{32}$/D', $nodeRunId) !== 1)
            || !is_string($operationId) || preg_match('/^[a-f0-9]{32}$/D', $operationId) !== 1) {
            throw new HttpError(422, 'broker_connection_invalid');
        }
        $existing = $connection->table('broker_connection_operations')->where('id', '=', $operationId)->first();
        if ($existing !== null) {
            if ($existing['kind'] !== 'disconnect' || $existing['owner_id'] !== $ownerId || $existing['session_id'] !== $sessionId
                || (int) $existing['session_generation'] !== $generation || $existing['node_id'] !== $nodeId
                || ($nodeRunId !== '' && $existing['node_run_id'] !== $nodeRunId)
                || $existing['actor_id'] !== $context['actor_id'] || $existing['actor_realm'] !== $context['actor_realm']
                || ($existing['tenant_id'] ?? null) !== $context['tenant_id']) {
                throw new HttpError(409, 'broker_operation_conflict');
            }
            return self::result($connection, $context, $operationId);
        }
        $row = $connection->table('broker_resource_connections')->where('owner_id', '=', $ownerId)->first();
        if ($row === null || !self::visible($row, $context)) {
            throw new HttpError(404, 'broker_connection_not_found');
        }
        if ($row['session_id'] !== $sessionId || (int) $row['session_generation'] !== $generation || $row['node_id'] !== $nodeId
            || ($nodeRunId !== '' && $row['node_run_id'] !== $nodeRunId)) {
            throw new HttpError(409, 'broker_connection_stale');
        }
        $operation = self::operation($context, $operationId, $requestId, $row, $generation, 'disconnect');
        $now = time();
        $connection->transaction(static function (Connection $transaction) use ($operationId, $ownerId, $sessionId, $generation, $row, $context, $now): void {
            $transaction->table('broker_connection_operations')->insert([
                'id' => $operationId, 'kind' => 'disconnect', 'owner_id' => $ownerId, 'session_id' => $sessionId,
                'session_generation' => $generation, 'node_id' => $row['node_id'], 'node_run_id' => $row['node_run_id'],
                'observation_run' => $row['observation_run'], 'actor_id' => $context['actor_id'],
                'actor_realm' => $context['actor_realm'], 'tenant_id' => $context['tenant_id'], 'requested_at' => $now,
                'completed_at' => null, 'outcome' => 'pending',
            ]);
        }, $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default');
        AuditLog::recordBroker($connection, $context['actor_realm'], $operation, 'accepted', [
            'request_id' => $requestId, 'stage' => 'accepted', 'result' => 'pending', 'facts' => [],
        ]);
        AuditLog::recordBroker($connection, $context['actor_realm'], $operation, 'executing', [
            'request_id' => $requestId, 'stage' => 'executing', 'result' => 'pending', 'facts' => [],
        ]);
        return self::result($connection, $context, $operationId);
    }

    /**
     * 从已授权的持久会话详情与订阅列表生成终止影响预览；不含载荷、遗嘱主题或秘密。
     *
     * @param array<string, mixed> $session 持久会话详情。
     * @param list<array<string, mixed>> $subscriptions 已按会话筛选的订阅元数据。
     * @return array<string, mixed>
     */
    public static function preview(array $session, array $subscriptions): array
    {
        $counts = is_array($session['counts'] ?? null) ? $session['counts'] : [];
        $items = [];
        foreach ($subscriptions as $subscription) {
            if (count($items) >= 20) {
                break;
            }
            $items[] = [
                'filter' => $subscription['filter'] ?? '',
                'filter_bytes' => (int) ($subscription['filter_bytes'] ?? 0),
                'qos' => (int) ($subscription['qos'] ?? 0),
                'shared' => ($subscription['shared'] ?? false) === true,
            ];
        }
        return [
            'session_id' => $session['id'],
            'session_generation' => (int) ($session['session_generation'] ?? 0),
            'client_id' => $session['client_id'] ?? '',
            'node_id' => $session['node_id'] ?? '',
            'node_run_id' => $session['node_run_id'] ?? '',
            'owner_id' => $session['owner_id'] ?? null,
            'state' => $session['state'] ?? '',
            'expiry' => (int) ($session['expiry'] ?? 0),
            'durable' => ($session['durable'] ?? false) === true,
            'source' => $session['source'] ?? 'durable_store',
            'subscriptions' => $items,
            'counts' => [
                'subscriptions' => (int) ($counts['subscriptions'] ?? count($items)),
                'pending_messages' => (int) ($counts['pending_messages'] ?? 0),
                'pending_bytes' => (int) ($counts['pending_bytes'] ?? 0),
                'pending_qos1' => (int) ($counts['pending_qos1'] ?? 0),
                'pending_qos2' => (int) ($counts['pending_qos2'] ?? 0),
                'pending_shared' => (int) ($counts['pending_shared'] ?? 0),
                'shared_qos2_inflight' => (int) ($counts['shared_qos2_inflight'] ?? 0),
                'will_pending' => (int) ($counts['will_pending'] ?? 0),
            ],
            'impact' => [
                'effect' => 'terminate_session',
                'session_erased' => true,
                'deliveries_abandoned' => true,
                'other_sessions_unaffected' => true,
                'shared_qos2_inflight_not_retargeted' => true,
                'will_expires' => (int) ($counts['will_pending'] ?? 0) > 0,
            ],
            'observed_at' => (int) ($session['observed_at'] ?? time()),
        ];
    }

    /**
     * 明确确认后冻结精确会话终止；提交重新核对代次，不能用 Client ID 代替会话标识。
     *
     * @param array{tenant_id:?string,actor_id:string,actor_realm:string,permissions:list<string>,identity:?array<string,mixed>} $context 已重新授权的人员范围。
     * @param array<string, mixed> $session 当前持久会话详情。
     * @param array<string, mixed>|null $live 匹配该会话的实时连接观察；离线时为空。
     * @param array<string, mixed> $payload 必须含 confirmed=true 与当前代次。
     * @return array<string, mixed>
     */
    public static function requestTerminate(Connection $connection, array $context, string $sessionId, array $session, ?array $live, array $payload, string $requestId): array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $sessionId) !== 1) {
            throw new HttpError(400, 'broker_session_id_invalid');
        }
        if (array_diff(array_keys($payload), ['session_generation', 'node_id', 'node_run_id', 'operation_id', 'confirmed']) !== []) {
            throw new HttpError(422, 'broker_session_invalid');
        }
        if (($payload['confirmed'] ?? null) !== true) {
            throw new HttpError(422, 'broker_session_confirm_required');
        }
        $generation = $payload['session_generation'] ?? null;
        $nodeId = $payload['node_id'] ?? null;
        $nodeRunId = $payload['node_run_id'] ?? '';
        $operationId = $payload['operation_id'] ?? bin2hex(random_bytes(16));
        if (!is_int($generation) || $generation < 0
            || !is_string($nodeId) || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $nodeId) !== 1
            || !is_string($nodeRunId) || ($nodeRunId !== '' && preg_match('/^[a-f0-9]{32}$/D', $nodeRunId) !== 1)
            || !is_string($operationId) || preg_match('/^[a-f0-9]{32}$/D', $operationId) !== 1) {
            throw new HttpError(422, 'broker_session_invalid');
        }
        $existing = $connection->table('broker_connection_operations')->where('id', '=', $operationId)->first();
        if ($existing !== null) {
            if ($existing['kind'] !== 'session_terminate' || $existing['session_id'] !== $sessionId
                || (int) $existing['session_generation'] !== $generation || $existing['node_id'] !== $nodeId
                || ($nodeRunId !== '' && $existing['node_run_id'] !== $nodeRunId)
                || $existing['actor_id'] !== $context['actor_id'] || $existing['actor_realm'] !== $context['actor_realm']
                || ($existing['tenant_id'] ?? null) !== $context['tenant_id']) {
                throw new HttpError(409, 'broker_operation_conflict');
            }
            return self::result($connection, $context, $operationId);
        }
        if (!self::visible($session, $context) || ($session['id'] ?? '') !== $sessionId) {
            throw new HttpError(404, 'broker_session_not_found');
        }
        if ((int) ($session['session_generation'] ?? -1) !== $generation || ($session['node_id'] ?? '') !== $nodeId
            || ($nodeRunId !== '' && ($session['node_run_id'] ?? '') !== $nodeRunId)) {
            throw new HttpError(409, 'broker_session_stale');
        }
        if ($live !== null && ((string) ($live['session_id'] ?? '') !== $sessionId
            || (int) ($live['session_generation'] ?? -1) !== $generation || ($live['node_id'] ?? '') !== $nodeId)) {
            throw new HttpError(409, 'broker_session_stale');
        }
        $ownerId = $live !== null ? (string) $live['owner_id'] : self::OFFLINE_OWNER;
        $observationRun = $live !== null ? (string) $live['observation_run'] : self::OFFLINE_OWNER;
        $storedRun = $live !== null ? (string) $live['node_run_id'] : (string) ($session['node_run_id'] ?? '');
        if ($storedRun === '') {
            $storedRun = self::OFFLINE_OWNER;
        }
        $target = [
            'owner_id' => $ownerId, 'session_id' => $sessionId, 'node_id' => $nodeId, 'node_run_id' => $storedRun,
            'observation_run' => $observationRun,
        ];
        $operation = self::operation($context, $operationId, $requestId, $target, $generation, 'session_terminate');
        $now = time();
        $connection->transaction(static function (Connection $transaction) use ($operationId, $ownerId, $sessionId, $generation, $nodeId, $storedRun, $observationRun, $context, $now): void {
            $transaction->table('broker_connection_operations')->insert([
                'id' => $operationId, 'kind' => 'session_terminate', 'owner_id' => $ownerId, 'session_id' => $sessionId,
                'session_generation' => $generation, 'node_id' => $nodeId, 'node_run_id' => $storedRun,
                'observation_run' => $observationRun, 'actor_id' => $context['actor_id'],
                'actor_realm' => $context['actor_realm'], 'tenant_id' => $context['tenant_id'], 'requested_at' => $now,
                'completed_at' => null, 'outcome' => 'pending',
            ]);
        }, $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default');
        AuditLog::recordBroker($connection, $context['actor_realm'], $operation, 'accepted', [
            'request_id' => $requestId, 'stage' => 'accepted', 'result' => 'pending', 'facts' => [],
        ]);
        AuditLog::recordBroker($connection, $context['actor_realm'], $operation, 'executing', [
            'request_id' => $requestId, 'stage' => 'executing', 'result' => 'pending', 'facts' => [],
        ]);
        return self::result($connection, $context, $operationId);
    }

    /**
     * 从已授权的保留原件详情生成清除影响预览；不含载荷、属性或秘密。
     *
     * @param array<string, mixed> $retained 持久保留详情。
     * @return array<string, mixed>
     */
    public static function previewRetained(array $retained): array
    {
        $counts = is_array($retained['counts'] ?? null) ? $retained['counts'] : [];
        return [
            'id' => $retained['id'],
            'topic' => $retained['topic'] ?? '',
            'topic_bytes' => (int) ($retained['topic_bytes'] ?? 0),
            'qos' => (int) ($retained['qos'] ?? 0),
            'generation' => (int) ($retained['generation'] ?? 0),
            'byte_size' => (int) ($retained['byte_size'] ?? ($counts['byte_size'] ?? 0)),
            'state' => $retained['state'] ?? '',
            'expires_at' => $retained['expires_at'] ?? null,
            'client_id' => $retained['client_id'] ?? null,
            'source' => $retained['source'] ?? 'durable_store',
            'counts' => [
                'pending_deliveries' => (int) ($counts['pending_deliveries'] ?? 0),
                'snapshot_references' => (int) ($counts['snapshot_references'] ?? 0),
                'byte_size' => (int) ($counts['byte_size'] ?? ($retained['byte_size'] ?? 0)),
            ],
            'impact' => [
                'effect' => 'clear_retained',
                'future_replay_cancelled' => true,
                'no_ordinary_publish' => true,
                'existing_deliveries_kept' => true,
                'concurrent_replace_protected' => true,
            ],
            'observed_at' => (int) ($retained['observed_at'] ?? time()),
        ];
    }

    /**
     * 明确确认后冻结精确保留原件清除；提交重新核对代次，不能用 Topic 通配代替原件标识。
     *
     * @param array{tenant_id:?string,actor_id:string,actor_realm:string,permissions:list<string>,identity:?array<string,mixed>} $context 已重新授权的人员范围。
     * @param array<string, mixed> $retained 当前保留原件详情。
     * @param array<string, mixed> $payload 必须含 confirmed=true 与当前代次。
     * @return array<string, mixed>
     */
    public static function requestClear(Connection $connection, array $context, string $resourceId, array $retained, array $payload, string $requestId): array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $resourceId) !== 1) {
            throw new HttpError(400, 'broker_resource_id_invalid');
        }
        if (array_diff(array_keys($payload), ['generation', 'operation_id', 'confirmed']) !== []) {
            throw new HttpError(422, 'broker_retained_invalid');
        }
        if (($payload['confirmed'] ?? null) !== true) {
            throw new HttpError(422, 'broker_retained_confirm_required');
        }
        $generation = $payload['generation'] ?? null;
        $operationId = $payload['operation_id'] ?? bin2hex(random_bytes(16));
        if (!is_int($generation) || $generation < 0
            || !is_string($operationId) || preg_match('/^[a-f0-9]{32}$/D', $operationId) !== 1) {
            throw new HttpError(422, 'broker_retained_invalid');
        }
        $existing = $connection->table('broker_connection_operations')->where('id', '=', $operationId)->first();
        if ($existing !== null) {
            if ($existing['kind'] !== 'retain_clear' || $existing['session_id'] !== $resourceId
                || (int) $existing['session_generation'] !== $generation
                || $existing['actor_id'] !== $context['actor_id'] || $existing['actor_realm'] !== $context['actor_realm']
                || ($existing['tenant_id'] ?? null) !== $context['tenant_id']) {
                throw new HttpError(409, 'broker_operation_conflict');
            }
            return self::result($connection, $context, $operationId);
        }
        if (($retained['id'] ?? '') !== $resourceId) {
            throw new HttpError(404, 'broker_resource_not_found');
        }
        if ((int) ($retained['generation'] ?? -1) !== $generation) {
            throw new HttpError(409, 'broker_retained_stale');
        }
        $target = [
            'owner_id' => $resourceId, 'session_id' => $resourceId, 'node_id' => self::STORE_NODE,
            'node_run_id' => self::OFFLINE_OWNER, 'observation_run' => self::OFFLINE_OWNER,
        ];
        $operation = self::operation($context, $operationId, $requestId, $target, $generation, 'retain_clear');
        $now = time();
        $storeNode = self::STORE_NODE;
        $offline = self::OFFLINE_OWNER;
        $connection->transaction(static function (Connection $transaction) use ($operationId, $resourceId, $generation, $context, $now, $storeNode, $offline): void {
            $transaction->table('broker_connection_operations')->insert([
                'id' => $operationId, 'kind' => 'retain_clear', 'owner_id' => $resourceId, 'session_id' => $resourceId,
                'session_generation' => $generation, 'node_id' => $storeNode, 'node_run_id' => $offline,
                'observation_run' => $offline, 'actor_id' => $context['actor_id'],
                'actor_realm' => $context['actor_realm'], 'tenant_id' => $context['tenant_id'], 'requested_at' => $now,
                'completed_at' => null, 'outcome' => 'pending',
            ]);
        }, $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default');
        AuditLog::recordBroker($connection, $context['actor_realm'], $operation, 'accepted', [
            'request_id' => $requestId, 'stage' => 'accepted', 'result' => 'pending', 'facts' => [],
        ]);
        AuditLog::recordBroker($connection, $context['actor_realm'], $operation, 'executing', [
            'request_id' => $requestId, 'stage' => 'executing', 'result' => 'pending', 'facts' => [],
        ]);
        return self::result($connection, $context, $operationId);
    }

    /**
     * @param array{tenant_id:?string,actor_id:string,actor_realm:string,permissions:list<string>,identity:?array<string,mixed>} $context 当前授权范围。
     * @return array<string, mixed> 当前阶段与脱敏目标；跨租户按不存在处理。
     */
    public static function result(Connection $connection, array $context, string $operationId): array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $operationId) !== 1) {
            throw new HttpError(400, 'broker_operation_id_invalid');
        }
        $row = $connection->table('broker_connection_operations')->where('id', '=', $operationId)->first();
        if ($row === null || $row['actor_realm'] !== $context['actor_realm']
            || ($context['tenant_id'] !== null && ($row['tenant_id'] ?? null) !== $context['tenant_id'])) {
            throw new HttpError(404, 'broker_operation_not_found');
        }
        $audit = AuditLog::brokerOperation($connection, $context['actor_realm'], $operationId);
        $run = $connection->table('broker_resource_runs')->where('run_id', '=', $row['observation_run'])->first();
        $reachable = $run !== null && (int) $run['expires_at'] > time();
        return [
            'operation_id' => $row['id'], 'kind' => $row['kind'],
            'stage' => $audit['current_stage'] ?? ($row['completed_at'] === null ? 'executing' : 'completed'),
            'result' => $audit['current_result'] ?? ($row['completed_at'] === null ? 'pending' : 'success'),
            'outcome' => $row['outcome'],
            'owner_id' => $row['owner_id'], 'session_id' => $row['session_id'],
            'session_generation' => (int) $row['session_generation'],
            'node_id' => $row['node_id'], 'node_run_id' => $row['node_run_id'],
            'observation_run' => $row['observation_run'],
            'node_reachable' => $reachable,
            'requested_at' => (int) $row['requested_at'],
            'completed_at' => $row['completed_at'] === null ? null : (int) $row['completed_at'],
        ];
    }

    /**
     * 本节点尚未完成的下一项指定种类操作；其它节点的意图不能被领取。
     * @return array{id:string,owner_id:string,session_id:string,session_generation:int,actor:string}|null
     */
    public static function next(Connection $connection, string $nodeId, string $kind = 'disconnect'): ?array
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $nodeId) !== 1 || !in_array($kind, ['disconnect', 'session_terminate', 'retain_clear'], true)) {
            throw new \InvalidArgumentException('broker_disconnect_node_invalid');
        }
        if ($kind === 'retain_clear') {
            $rows = $connection->query(
                'SELECT id, owner_id, session_id, session_generation, actor_id FROM broker_connection_operations '
                . 'WHERE completed_at IS NULL AND kind = ? ORDER BY requested_at, id LIMIT 1',
                [$kind]
            );
        } else {
            $rows = $connection->query(
                'SELECT id, owner_id, session_id, session_generation, actor_id FROM broker_connection_operations '
                . 'WHERE completed_at IS NULL AND kind = ? AND node_id = ? ORDER BY requested_at, id LIMIT 1',
                [$kind, $nodeId]
            );
        }
        if ($rows === []) {
            return null;
        }
        return [
            'id' => $rows[0]['id'], 'owner_id' => $rows[0]['owner_id'], 'session_id' => $rows[0]['session_id'],
            'session_generation' => (int) $rows[0]['session_generation'], 'actor' => $rows[0]['actor_id'],
        ];
    }

    /**
     * 观察行消失后保存完成并写异步审计；仍有观察时返回假供 Broker 重试。
     * 离线会话终止不要求实时连接观察。
     */
    public static function complete(Connection $connection, string $id, string $outcome): bool
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            throw new \InvalidArgumentException('broker_disconnect_complete_invalid');
        }
        $row = $connection->table('broker_connection_operations')->where('id', '=', $id)->first();
        if ($row === null) {
            return true;
        }
        if ($row['completed_at'] !== null) {
            return true;
        }
        $allowed = match ($row['kind']) {
            'session_terminate' => ['terminated', 'missing'],
            'retain_clear' => ['cleared', 'missing'],
            default => ['disconnected', 'missing'],
        };
        if (!in_array($outcome, $allowed, true)) {
            throw new \InvalidArgumentException('broker_disconnect_complete_invalid');
        }
        if ($row['kind'] !== 'retain_clear' && ($row['kind'] !== 'session_terminate' || $row['owner_id'] !== self::OFFLINE_OWNER)) {
            $observed = $connection->table('broker_resource_connections')->where('owner_id', '=', $row['owner_id'])
                ->where('observation_run', '=', $row['observation_run'])->first();
            if ($observed !== null) {
                return false;
            }
        }
        $realm = $row['actor_realm'];
        $saved = AuditLog::brokerOperation($connection, $realm, $id);
        if ($saved === null) {
            throw new \RuntimeException('broker_disconnect_operation_missing');
        }
        $operation = $saved;
        unset($operation['current_stage'], $operation['current_result'], $operation['version']);
        $requestId = bin2hex(random_bytes(16));
        $connection->transaction(static function (Connection $transaction) use ($id, $outcome): void {
            $query = $transaction->table('broker_connection_operations')->where('id', '=', $id)->whereNull('completed_at');
            ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
            $transaction->table('broker_connection_operations')->where('id', '=', $id)->whereNull('completed_at')
                ->update(['completed_at' => time(), 'outcome' => $outcome]);
        }, $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default');
        $reason = match ($outcome) {
            'disconnected' => 'connection_disconnected',
            'terminated' => 'session_terminated',
            'cleared' => 'retained_cleared',
            default => match ($row['kind']) {
                'session_terminate' => 'session_missing',
                'retain_clear' => 'retained_missing',
                default => 'connection_missing',
            },
        };
        AuditLog::recordBroker($connection, $realm, $operation, 'completed', [
            'request_id' => $requestId, 'stage' => 'completed', 'result' => 'success',
            'facts' => ['store_confirmed' => true, 'resources_released' => true, 'observation_isolated' => true,
                'reason' => $reason],
        ]);
        return true;
    }

    /** @param array<string, mixed> $row 实时连接观察。 */
    private static function visible(array $row, array $context): bool
    {
        if ($context['tenant_id'] === null) {
            return true;
        }
        return ($row['resource_scope'] ?? null) === 'iot:' . $context['tenant_id'];
    }

    /**
     * @param array<string, mixed> $row 已核对的连接观察或会话目标。
     * @return array<string, mixed>
     */
    private static function operation(array $context, string $operationId, string $requestId, array $row, int $generation, string $kind = 'disconnect'): array
    {
        $terminate = $kind === 'session_terminate';
        $clear = $kind === 'retain_clear';
        $action = $clear ? 'broker.retained.clear' : ($terminate ? 'broker.session.terminate' : 'broker.connection.disconnect');
        $subject = $clear || $terminate ? $row['session_id'] : $row['owner_id'];
        $operation = [
            'operation_id' => $operationId, 'mode' => 'async', 'origin_request_id' => $requestId,
            'actor_id' => $context['actor_id'], 'tenant_id' => $context['tenant_id'],
            'action' => $action,
            'subject_id' => $subject,
            'authorization' => [
                'source' => $context['actor_realm'] === 'broker' ? 'broker-admin' : ($context['actor_realm'] === 'admin' ? 'platform' : 'customer-member'),
                'role' => $context['actor_realm'] === 'broker' ? 'broker_admin' : 'rbac',
                'permissions' => $context['permissions'],
                'required_action' => ($context['actor_realm'] === 'broker' ? '' : $context['actor_realm'] . '.') . 'broker.write',
                'decision' => 'allowed', 'support_id' => null, 'support_version' => null, 'support_expires_at' => null,
            ],
            'target' => [
                'kind' => $clear ? 'retained' : ($terminate ? 'session' : 'connection'), 'node_id' => $row['node_id'], 'node_run_id' => $row['node_run_id'],
                'observation_run' => $row['observation_run'], 'generation' => $generation,
            ],
            'impact' => [
                'confirmed' => true, 'effect' => $clear ? 'clear_retained' : ($terminate ? 'terminate_session' : 'disconnect_connection'), 'target_count' => 1,
                'proof_hash' => hash('sha256', json_encode([$row['owner_id'], $row['session_id'], $generation, $row['node_id'], $row['node_run_id']], JSON_THROW_ON_ERROR)),
            ],
        ];
        if ($context['identity'] !== null) {
            $operation['identity'] = $context['identity'];
            $operation['authorization']['source'] = ($context['identity']['impersonation_id'] ?? '') === ''
                ? $operation['authorization']['source'] : 'customer-impersonation';
        }
        return $operation;
    }
}
