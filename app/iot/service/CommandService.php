<?php

declare(strict_types=1);

namespace app\iot\service;

use app\common\service\AuditLog;
use app\common\service\IdentityService;
use app\common\service\RoleService;
use Closure;
use LogicException;
use stdClass;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Orm\Connection;

/** 指令的受理、固定重投查询与设备执行证据；协议确认从不替代实际执行结果。 */
final class CommandService
{
    /** 受理前锁定租户与设备并重验权限、在线观察和绑定模型；截止固定为本次受理后60秒。 */
    public static function create(Connection $connection, Identity $identity, string $tenantId, string $deviceId, string $identifier, mixed $values, string $commandId, int $version): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $commandId)) {
            throw new HttpError(422, 'command_identity_invalid');
        }
        return self::write($connection, $identity, $tenantId, 'create', $commandId, static function (Connection $transaction, Identity $current, array $permissions) use ($tenantId, $deviceId, $identifier, $values, $commandId, $version): array {
            $deviceQuery = $transaction->table('iot_devices')->where('id', '=', $deviceId);
            $device = ($transaction->driverName() === 'sqlite' ? $deviceQuery : $deviceQuery->lockForUpdate())->first();
            $existingQuery = $transaction->table('iot_commands')->where('id', '=', $commandId);
            $existing = ($transaction->driverName() === 'sqlite' ? $existingQuery : $existingQuery->lockForUpdate())->first();
            if ($existing !== null) {
                $previous = json_decode($existing['payload'], false, 32, JSON_THROW_ON_ERROR);
                if ($existing['tenant_id'] !== $tenantId || $existing['device_id'] !== $deviceId || (int) $existing['request_version'] !== $version || !self::sameSource($existing, $current, $tenantId)
                    || $existing['identifier'] !== $identifier || IngestionService::contentHash(json_encode((object) $values, JSON_THROW_ON_ERROR))
                    !== IngestionService::contentHash(json_encode($previous->values, JSON_THROW_ON_ERROR))) {
                    throw new HttpError(409, 'command_identity_conflict');
                }
                return self::project($existing);
            }
            if ($device === null || $device['tenant_id'] !== $tenantId) {
                throw new HttpError(404, 'device_not_found');
            }
            if ($version < 1 || $version >= 2147483646 || (int) $device['version'] !== $version) {
                throw new HttpError(409, 'stale_version');
            }
            $observed = DeviceService::observation($transaction, $deviceId, $device);
            if ((int) $device['transfer_frozen'] === 1) {
                throw new HttpError(409, 'transfer_control_frozen');
            }
            if ($device['model_switch_id'] !== null) {
                throw new HttpError(409, 'model_switch_in_progress');
            }
            if ((int) $device['recovery_verified'] !== 1 || $device['lifecycle'] !== 'enabled' || $observed === null || $observed['connection']['status'] !== 'online') {
                throw new HttpError(409, 'command_device_not_online');
            }
            $model = ProductService::publishedModel($transaction, $tenantId, $device['product_id'], (int) $device['model_version']);
            $parameters = ModelDefinition::validateValues($model['definition'], 'command', $identifier, $values);
            $now = time();
            $id = $commandId;
            $payload = json_encode(['app_version' => 1, 'type' => 'command', 'command_id' => $id,
                'device_id' => $deviceId, 'ownership_id' => $device['ownership_id'], 'model_version' => (int) $device['model_version'],
                'identifier' => $identifier, 'values' => (object) $parameters, 'issued_at' => $now, 'deadline_at' => $now + 60], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            if (strlen($payload) > 16384) {
                throw new HttpError(422, 'command_payload_too_large');
            }
            $record = ['id' => $id, 'tenant_id' => $tenantId, 'device_id' => $deviceId, 'ownership_id' => $device['ownership_id'],
                'model_version' => (int) $device['model_version'], 'identifier' => $identifier, 'payload' => $payload,
                'content_hash' => IngestionService::contentHash($payload), 'actor_id' => $current->subject(),
                'source_context' => json_encode(IdentityService::context($current, $tenantId), JSON_THROW_ON_ERROR), 'request_version' => $version,
                'accepted_at' => $now, 'deadline_at' => $now + 60, 'next_action_at' => $now];
            $transaction->table('iot_commands')->insert($record);
            $transaction->table('iot_devices')->where('id', '=', $deviceId)->update(['version' => $version + 1]);
            AuditLog::append($transaction, $tenantId, $current, 'command.accepted', $id, 'success', self::auditDetails($record), 'customer');
            return self::project($transaction->table('iot_commands')->where('id', '=', $id)->first());
        });
    }

    /**
     * 仅取消尚无发送领取的指令；一旦可能送达，必须保留实际回执或未知，不能承诺撤回设备效果。
     * 原取消ID绑定本次真实来源，重复提交返回持久结果。
     */
    public static function cancel(Connection $connection, Identity $identity, string $tenantId, string $deviceId, string $commandId, string $cancelId): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $cancelId)) {
            throw new HttpError(422, 'command_cancel_identity_invalid');
        }
        return self::write($connection, $identity, $tenantId, 'cancel', $commandId, static function (Connection $transaction, Identity $current, array $permissions) use ($tenantId, $deviceId, $commandId, $cancelId): array {
            $query = $transaction->table('iot_commands')->where('id', '=', $commandId)->where('tenant_id', '=', $tenantId)->where('device_id', '=', $deviceId);
            $record = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
            if ($record === null) {
                throw new HttpError(404, 'command_not_found');
            }
            $context = IdentityService::context($current, $tenantId);
            if ($record['cancel_id'] !== null) {
                if ($record['cancel_id'] !== $cancelId || json_decode($record['cancel_context'], true, 8, JSON_THROW_ON_ERROR)['key'] !== $context['key']) {
                    throw new HttpError(409, 'command_cancel_identity_conflict');
                }
                return self::project($record);
            }
            if ($record['dispatch_at'] !== null) {
                throw new HttpError(409, 'command_already_dispatched');
            }
            if (self::terminal($record)) {
                throw new HttpError(409, 'command_result_known');
            }
            if ($transaction->table('iot_commands')->where('cancel_id', '=', $cancelId)->first() !== null) {
                throw new HttpError(409, 'command_cancel_identity_conflict');
            }
            $transaction->table('iot_commands')->where('id', '=', $commandId)->update(['cancel_id' => $cancelId,
                'cancelled_at' => time(), 'cancelled_by' => $current->subject(), 'cancel_context' => json_encode($context, JSON_THROW_ON_ERROR),
                'schedule_stage' => 6, 'next_action_at' => null, 'manual_query_id' => null]);
            AuditLog::append(
                $transaction,
                $tenantId,
                $current,
                'command.cancelled',
                $commandId,
                'success',
                ['context' => 'device-command', 'device_id' => $deviceId, 'ownership_id' => $record['ownership_id']],
                'customer'
            );
            return self::project($transaction->table('iot_commands')->where('id', '=', $commandId)->first());
        });
    }

    /** 只读持久事实，不主动联系设备；只读成员可查看，响应按稳定时间和ID分页。 */
    public static function history(Connection $connection, Identity $identity, string $tenantId, string $deviceId, int $page = 1, int $perPage = 20): array
    {
        $context = self::authorize($connection, $identity, $tenantId, 'read');
        if ($connection->table('iot_devices')->where('tenant_id', '=', $tenantId)->where('id', '=', $deviceId)->first() === null
            && $connection->table('iot_device_ownerships')->where('tenant_id', '=', $tenantId)->where('device_id', '=', $deviceId)->limit(1)->first() === null) {
            throw new HttpError(404, 'device_not_found');
        }
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) {
            throw new HttpError(422, 'invalid_pagination');
        }
        $pagination = $connection->table('iot_commands')->where('tenant_id', '=', $tenantId)->where('device_id', '=', $deviceId)
            ->orderBy('accepted_at', 'DESC')->orderBy('id', 'DESC')->paginate($page, $perPage);
        $result = ['items' => $pagination->items(), 'total' => $pagination->total(), 'page' => $pagination->number(), 'per_page' => $pagination->perPage()];
        foreach ($result['items'] as $index => $record) {
            $result['items'][$index] = self::project($record) + ['timeline' => self::attempts($connection, $record['id'], 1, 20)];
        }
        return $result + ['context' => $context];
    }

    /** 按原ID读取获准租户与设备的持久事实；不发起设备查询，不受列表分页位置影响。 */
    public static function detail(Connection $connection, Identity $identity, string $tenantId, string $deviceId, string $commandId): array
    {
        self::authorize($connection, $identity, $tenantId, 'read');
        $record = $connection->table('iot_commands')->where('id', '=', $commandId)->where('tenant_id', '=', $tenantId)->where('device_id', '=', $deviceId)->first();
        if ($record === null) {
            throw new HttpError(404, 'command_not_found');
        }
        return self::project($record);
    }

    /** 人员仅在三次自动查询节点以后主动对账；同请求ID确认原受理，并发请求合并到一个待发查询。 */
    public static function query(Connection $connection, Identity $identity, string $tenantId, string $deviceId, string $commandId, string $queryId): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $queryId)) {
            throw new HttpError(422, 'command_query_identity_invalid');
        }
        return self::write($connection, $identity, $tenantId, 'query', $commandId, static function (Connection $transaction, Identity $current, array $permissions) use ($tenantId, $deviceId, $commandId, $queryId): array {
            $deviceQuery = $transaction->table('iot_devices')->where('id', '=', $deviceId);
            $device = ($transaction->driverName() === 'sqlite' ? $deviceQuery : $deviceQuery->lockForUpdate())->first();
            $lookup = $transaction->table('iot_commands')->where('id', '=', $commandId)->where('tenant_id', '=', $tenantId)->where('device_id', '=', $deviceId);
            $record = ($transaction->driverName() === 'sqlite' ? $lookup : $lookup->lockForUpdate())->first();
            if ($record === null) {
                throw new HttpError(404, 'command_not_found');
            }
            $existing = $transaction->table('iot_command_attempts')->where('id', '=', $queryId)->first();
            if ($existing !== null) {
                if ($existing['command_id'] !== $commandId || !self::sameSource($existing, $current, $tenantId) || $existing['trigger_kind'] !== 'manual') {
                    throw new HttpError(409, 'command_query_identity_conflict');
                }
                return self::projectAttempt($existing);
            }
            $now = time();
            if (self::terminal($record)) {
                throw new HttpError(409, 'command_result_known');
            }
            if ($record['dispatch_at'] === null) {
                throw new HttpError(409, 'command_not_dispatched');
            }
            if ($now < (int) $record['accepted_at'] + 300) {
                throw new HttpError(409, 'command_auto_query_pending');
            }
            if ($now >= (int) $record['accepted_at'] + 180 * 86400) {
                throw new HttpError(409, 'command_retention_expired');
            }
            if (!self::available(DeviceService::observation($transaction, $deviceId, $device), $record)) {
                throw new HttpError(409, 'command_device_not_online');
            }
            $recent = $transaction->table('iot_command_attempts')->where('command_id', '=', $commandId)->where('kind', '=', 'query')
                ->where('scheduled_at', '>', $now - 10)->limit(1)->first();
            if ($record['manual_query_id'] !== null || $recent !== null) {
                throw new HttpError(409, 'command_query_in_progress');
            }
            $attempt = ['id' => $queryId, 'command_id' => $commandId, 'kind' => 'query', 'trigger_kind' => 'manual',
                'actor_id' => $current->subject(), 'source_context' => json_encode(IdentityService::context($current, $tenantId), JSON_THROW_ON_ERROR), 'scheduled_at' => $now, 'state' => 'queued'];
            $transaction->table('iot_command_attempts')->insert($attempt);
            $transaction->table('iot_commands')->where('id', '=', $commandId)->update(['manual_query_id' => $queryId]);
            AuditLog::append($transaction, $tenantId, $current, 'command.query_requested', $commandId, 'unknown', self::auditDetails($record, $attempt) + ['attempt_id' => $queryId], 'customer');
            return self::projectAttempt($transaction->table('iot_command_attempts')->where('id', '=', $queryId)->first());
        });
    }

    /** 查询每次领取、协议确认及结果证据；读取时间线不会向设备发送消息。 */
    public static function timeline(Connection $connection, Identity $identity, string $tenantId, string $deviceId, string $commandId, int $page = 1, int $perPage = 20): array
    {
        self::authorize($connection, $identity, $tenantId, 'read');
        if ($connection->table('iot_commands')->where('id', '=', $commandId)->where('tenant_id', '=', $tenantId)->where('device_id', '=', $deviceId)->first() === null) {
            throw new HttpError(404, 'command_not_found');
        }
        return self::attempts($connection, $commandId, $page, $perPage);
    }

    /** 创建满180天后按记录预算删除；清理事实明确保留未知，不将保留期结束写成执行成功。 */
    public static function prune(Connection $connection, int $batch): array
    {
        if ($batch < 1 || $batch > 1000) {
            throw new \InvalidArgumentException('指令清理批次必须为1至1000');
        }
        $cutoff = time() - 180 * 86400;
        return $connection->transaction(static function (Connection $transaction) use ($batch, $cutoff): array {
            $rows = $transaction->table('iot_commands')->where('accepted_at', '<=', $cutoff)->orderBy('accepted_at')->orderBy('id')->limit($batch);
            $records = ($transaction->driverName() === 'sqlite' ? $rows : $rows->lockForUpdate())->get();
            $deleted = 0;
            $unknown = 0;
            $evidence = 0;
            $budget = $batch;
            foreach ($records as $record) {
                if ($budget < 1) {
                    break;
                }
                $attempts = $transaction->table('iot_command_attempts')->where('command_id', '=', $record['id'])->orderBy('scheduled_at')->orderBy('id')->limit($budget)->get();
                foreach ($attempts as $attempt) {
                    $evidence += $transaction->table('iot_command_attempts')->where('id', '=', $attempt['id'])->delete();
                    $budget--;
                }
                if ($budget < 1 || $transaction->table('iot_command_attempts')->where('command_id', '=', $record['id'])->limit(1)->first() !== null) {
                    continue;
                }
                $uncertain = !self::terminal($record);
                if ($uncertain && $transaction->table('iot_command_uncertainties')->where('command_id', '=', $record['id'])->first() === null) {
                    // 指令载荷和时间线仍按180天清理；只保留不能被清理伪装解决的归属阻塞身份。
                    $transaction->table('iot_command_uncertainties')->insert(['command_id' => $record['id'], 'device_id' => $record['device_id'], 'ownership_id' => $record['ownership_id']]);
                }
                AuditLog::append(
                    $transaction,
                    $record['tenant_id'],
                    self::actor($record),
                    'command.retention_expired',
                    $record['id'],
                    $uncertain ? 'unknown' : 'success',
                    self::auditDetails($record) + ['result_status' => $record['result_status'] ?? 'unknown'],
                    'customer'
                );
                $deleted += $transaction->table('iot_commands')->where('id', '=', $record['id'])->where('accepted_at', '<=', $cutoff)->delete();
                $unknown += $uncertain ? 1 : 0;
                $budget--;
            }
            return ['deleted' => $deleted, 'deleted_unknown' => $unknown, 'deleted_evidence' => $evidence,
                'has_more' => $transaction->table('iot_commands')->where('accepted_at', '<=', $cutoff)->limit(1)->first() !== null, 'cutoff' => $cutoff];
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /** 固定节点只领取一次；恢复时跳过错过的节点，不集中补发、不延长原截止。 */
    public static function claim(Connection $transaction): ?array
    {
        self::transaction($transaction);
        RoleService::lockAuthorization($transaction);
        $now = time();
        $query = $transaction->table('iot_commands')->whereNull('manual_query_id', true)->orderBy('accepted_at')->orderBy('id')->limit(1);
        $record = $query->first();
        if ($record === null) {
            $query = $transaction->table('iot_commands')->where('next_action_at', '<=', $now)->orderBy('next_action_at')->orderBy('id')->limit(1);
            $record = $query->first();
        }
        if ($record === null) {
            return null;
        }
        // 接收回执同样按设备→指令加锁；候选只用于定位，等待后重读当前指令和设备。
        $deviceQuery = $transaction->table('iot_devices')->where('id', '=', $record['device_id']);
        $lockedDevice = ($transaction->driverName() === 'sqlite' ? $deviceQuery : $deviceQuery->lockForUpdate())->first();
        $recordQuery = $transaction->table('iot_commands')->where('id', '=', $record['id']);
        $record = ($transaction->driverName() === 'sqlite' ? $recordQuery : $recordQuery->lockForUpdate())->first();
        if ($record === null || ($record['manual_query_id'] === null && ($record['next_action_at'] === null || (int) $record['next_action_at'] > time()))) {
            return null;
        }
        $device = DeviceService::observation($transaction, $record['device_id'], $lockedDevice);
        $available = self::available($device, $record);
        $terminal = self::terminal($record);
        if ($record['manual_query_id'] !== null) {
            $attempt = $transaction->table('iot_command_attempts')->where('id', '=', $record['manual_query_id'])->first();
            $transaction->table('iot_commands')->where('id', '=', $record['id'])->update(['manual_query_id' => null]);
        } else {
            $offsets = [0, 10, 30, 60, 120, 300];
            $stage = (int) $record['schedule_stage'];
            while ($stage < 5 && $now >= (int) $record['accepted_at'] + $offsets[$stage + 1]) {
                self::attempt($transaction, $record, $stage < 3 ? 'send' : 'query', 'automatic', (int) $record['accepted_at'] + $offsets[$stage], 'schedule_missed');
                $stage++;
            }
            if ($stage >= 6) {
                $transaction->table('iot_commands')->where('id', '=', $record['id'])->update(['next_action_at' => null]);
                return null;
            }
            $attempt = self::attempt($transaction, $record, $stage < 3 ? 'send' : 'query', 'automatic', (int) $record['accepted_at'] + $offsets[$stage], 'queued');
            $transaction->table('iot_commands')->where('id', '=', $record['id'])->update(['schedule_stage' => $stage + 1,
                'next_action_at' => $stage === 5 || $terminal ? null : (int) $record['accepted_at'] + $offsets[$stage + 1]]);
        }
        $sourceAllowed = true;
        $sourceExpires = PHP_INT_MAX;
        // 自动结果对账跟踪已受理效果；新的执行与主动查询重验准确会话，不能换用同账号其他来源。
        if ($attempt['kind'] === 'send' || $attempt['trigger_kind'] === 'manual') {
            try {
                $source = json_decode($attempt['source_context'], true, 8, JSON_THROW_ON_ERROR);
                $current = (new IdentityService('customer'))->resume($transaction, $source);
                if ($current === null) {
                    throw new HttpError(401, 'unauthenticated');
                }
                $permissions = RoleService::permissions($transaction, $current, 'customer', $record['tenant_id']);
                if (!in_array('customer.commands.' . ($attempt['kind'] === 'send' ? 'create' : 'query'), $permissions, true)) {
                    throw new HttpError(403, 'permission_denied');
                }
                $sourceExpires = (int) $current->attributes()['expires_at'];
            } catch (HttpError $revoked) {
                $sourceAllowed = false;
            }
        }
        $now = time();
        $neverSent = $record['dispatch_at'] === null && !$terminal && (!$sourceAllowed || $now >= (int) $record['deadline_at']);
        $state = $neverSent ? (!$sourceAllowed ? 'source_permission_revoked' : 'deadline_elapsed') : (!$sourceAllowed ? 'source_permission_revoked' : ($terminal ? 'result_known' : (!$available || ($attempt['kind'] === 'send' && ($device['model_switch_id'] !== null || (int) $device['transfer_frozen'] === 1 || (int) $device['model_version'] !== (int) $record['model_version'])) ? 'device_unavailable' : ($now >= (int) $record['accepted_at'] + 180 * 86400 ? 'retention_expired'
            : ($attempt['kind'] === 'send' && ($now >= (int) $record['deadline_at'] || $record['device_received_at'] !== null) ? 'execution_evidence_or_expired' : 'claimed')))));
        $transaction->table('iot_command_attempts')->where('id', '=', $attempt['id'])->update(['state' => $state, 'claimed_at' => $state === 'claimed' ? $now : null]);
        AuditLog::append(
            $transaction,
            $record['tenant_id'],
            self::actor($attempt),
            'command.' . $attempt['kind'] . '_claimed',
            $record['id'],
            'unknown',
            self::auditDetails($record, $attempt) + ['attempt_id' => $attempt['id'], 'scheduled_at' => (int) $attempt['scheduled_at'], 'claimed_at' => $now, 'reason' => $state],
            'customer'
        );
        if ($neverSent) {
            // 没有任何发送领取才可确认未下发；已领取可能具有外部效果，必须保持未知并继续对账。
            $transaction->table('iot_commands')->where('id', '=', $record['id'])->update(['dispatch_stopped_at' => $now,
                'dispatch_stop_reason' => !$sourceAllowed ? 'source_permission_revoked' : 'deadline_elapsed', 'schedule_stage' => 6, 'next_action_at' => null]);
        }
        if ($state !== 'claimed') {
            return null;
        }
        if ($attempt['kind'] === 'send' && $record['dispatch_at'] === null) {
            $transaction->table('iot_commands')->where('id', '=', $record['id'])->update(['dispatch_at' => $now]);
        }
        $payload = $attempt['kind'] === 'send' ? $record['payload'] : json_encode(['app_version' => 1, 'type' => 'command_query',
            'device_id' => $record['device_id'], 'ownership_id' => $record['ownership_id'], 'command_id' => $record['id'],
            'content_hash' => $record['content_hash'], 'query_id' => $attempt['id']], JSON_THROW_ON_ERROR);
        return ['id' => $record['id'], 'attempt_id' => $attempt['id'], 'kind' => $attempt['kind'], 'topic' => DeviceService::topics($device)['subscribe'],
            'payload' => $payload, 'deadline_at' => min($sourceExpires, $attempt['kind'] === 'send' ? (int) $record['deadline_at'] : $now + 30)];
    }

    /** MQTT PUBACK只证明Broker响应；失败或丢失确认保持未知，不制造设备结果。 */
    public static function transport(Connection $transaction, string $id, int $reason, string $attemptId = '', string $state = 'mqtt_acknowledged'): array
    {
        self::transaction($transaction);
        if (!preg_match('/^[a-f0-9]{32}$/D', $id) || $reason < 0 || $reason > 255 || !in_array($state, ['mqtt_acknowledged', 'transport_unknown', 'deadline_elapsed'], true)) {
            throw new LogicException('command_transport_invalid');
        }
        $record = $transaction->table('iot_commands')->where('id', '=', $id)->first();
        $query = $transaction->table('iot_command_attempts')->where('command_id', '=', $id)->where('state', '=', 'claimed');
        $attempt = ($attemptId === '' ? $query->orderBy('claimed_at', 'DESC')->orderBy('id')->limit(1) : $query->where('id', '=', $attemptId))->first();
        if ($record === null || $attempt === null) {
            return ['recorded' => false];
        }
        $now = time();
        $transaction->table('iot_command_attempts')->where('id', '=', $attempt['id'])->where('state', '=', 'claimed')->update(['state' => $state,
            'transport_at' => $now, 'mqtt_reason' => $state === 'mqtt_acknowledged' ? $reason : null]);
        if ($attempt['kind'] === 'send' && $state === 'mqtt_acknowledged') {
            $transaction->execute('UPDATE iot_commands SET mqtt_at = ?, mqtt_reason = ? WHERE id = ? AND dispatch_at IS NOT NULL AND mqtt_at IS NULL', [$now, $reason, $id]);
        }
        AuditLog::append(
            $transaction,
            $record['tenant_id'],
            self::actor($attempt),
            'command.' . $attempt['kind'] . '_transport',
            $id,
            $state === 'mqtt_acknowledged' && $reason < 128 ? 'success' : 'unknown',
            self::auditDetails($record, $attempt) + ['attempt_id' => $attempt['id'], 'reason' => $state, 'mqtt_reason' => $reason],
            'customer'
        );
        return ['recorded' => true];
    }

    /** 同一受控上行接收入口处理时间挑战和执行回执；Topic、身份、内容均需匹配持久指令。 */
    public static function accept(Connection $transaction, string $topic, array $data, int $qos, int $receivedAt): array
    {
        self::transaction($transaction);
        $none = ['topic' => '', 'receipt' => null, 'code' => 'invalid_command_receipt'];
        if ($qos !== 1 || ($data['app_version'] ?? null) !== 1 || !is_string($data['device_id'] ?? null) || !is_string($data['ownership_id'] ?? null)) {
            return $none;
        }
        $deviceQuery = $transaction->table('iot_devices')->where('id', '=', $data['device_id'])->where('ownership_id', '=', $data['ownership_id'])->where('lifecycle', '=', 'enabled')->where('recovery_verified', '=', 1);
        $device = ($transaction->driverName() === 'sqlite' ? $deviceQuery : $deviceQuery->lockForUpdate())->first();
        if ($device === null || DeviceService::topics($device)['publish'] !== $topic) {
            return $none;
        }
        $down = DeviceService::topics($device)['subscribe'];
        if (($data['type'] ?? '') === 'time_request') {
            if (count($data) !== 5 || !is_string($data['nonce'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $data['nonce'])) {
                return $none;
            }
            return ['topic' => $down, 'code' => 'accepted', 'receipt' => ['app_version' => 1, 'type' => 'time_response',
                'device_id' => $device['id'], 'ownership_id' => $device['ownership_id'], 'nonce' => $data['nonce'], 'server_time' => time()]];
        }
        if (($data['type'] ?? '') === 'command_query_result') {
            $queryFields = ['app_version', 'type', 'device_id', 'ownership_id', 'command_id', 'content_hash', 'query_id', 'receipt'];
            if (count($data) !== count($queryFields) || array_diff(array_keys($data), $queryFields) !== []
                || !is_string($data['query_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $data['query_id'])
                || !is_string($data['command_id'] ?? null) || !is_string($data['content_hash'] ?? null)
                || ($data['receipt'] !== null && !$data['receipt'] instanceof stdClass)) {
                return $none;
            }
            $recordQuery = $transaction->table('iot_commands')->where('id', '=', $data['command_id'])->where('device_id', '=', $device['id'])->where('ownership_id', '=', $device['ownership_id']);
            $record = ($transaction->driverName() === 'sqlite' ? $recordQuery : $recordQuery->lockForUpdate())->first();
            $attemptQuery = $transaction->table('iot_command_attempts')->where('id', '=', $data['query_id'])->where('command_id', '=', $data['command_id'])->where('kind', '=', 'query')->whereNull('claimed_at', true);
            $attempt = ($transaction->driverName() === 'sqlite' ? $attemptQuery : $attemptQuery->lockForUpdate())->first();
            if ($record === null || $attempt === null || !hash_equals($record['content_hash'], $data['content_hash'])) {
                return $none;
            }
            $response = ['topic' => '', 'receipt' => null, 'code' => 'command_query_observed'];
            $code = 'result_not_retained';
            if ($data['receipt'] !== null) {
                $inner = get_object_vars($data['receipt']);
                if (($inner['type'] ?? '') !== 'command_receipt' || ($inner['command_id'] ?? '') !== $record['id']) {
                    return $none;
                }
                $response = self::accept($transaction, $topic, $inner, $qos, $receivedAt);
                if ($response['receipt'] === null) {
                    return $none;
                }
                $code = 'result_found';
            }
            $transaction->table('iot_command_attempts')->where('id', '=', $attempt['id'])->update(['response_at' => $receivedAt, 'response_code' => $code]);
            $transaction->table('iot_commands')->where('id', '=', $record['id'])->update(['last_query_code' => $code]);
            AuditLog::append(
                $transaction,
                $record['tenant_id'],
                self::actor($attempt),
                'command.query_response',
                $record['id'],
                'unknown',
                self::auditDetails($record, $attempt) + ['attempt_id' => $attempt['id'], 'reason' => $code],
                'customer'
            );
            return $response;
        }
        $fields = ['app_version', 'type', 'device_id', 'ownership_id', 'command_id', 'content_hash', 'status', 'code', 'started_at', 'finished_at', 'result'];
        if (count($data) !== count($fields) || array_diff(array_keys($data), $fields) !== [] || ($data['type'] ?? '') !== 'command_receipt'
            || !is_string($data['command_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $data['command_id'])
            || !is_string($data['content_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $data['content_hash'])
            || !in_array($data['status'] ?? '', ['succeeded', 'failed', 'rejected', 'unknown'], true)
            || !is_string($data['code'] ?? null) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $data['code'])
            || (!$data['result'] instanceof stdClass) || strlen(json_encode($data['result'], JSON_THROW_ON_ERROR)) > 4096
            || ($data['started_at'] !== null && (!is_int($data['started_at']) || $data['started_at'] < 1))
            || ($data['finished_at'] !== null && (!is_int($data['finished_at']) || $data['finished_at'] < 1))) {
            return $none;
        }
        $query = $transaction->table('iot_commands')->where('id', '=', $data['command_id'])->where('device_id', '=', $device['id'])->where('ownership_id', '=', $device['ownership_id']);
        $record = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
        if ($record === null || !hash_equals($record['content_hash'], $data['content_hash']) || $record['dispatch_at'] === null
            || ($data['started_at'] !== null && ($data['started_at'] < (int) $record['accepted_at'] || $data['started_at'] >= (int) $record['deadline_at']))
            || (in_array($data['status'], ['succeeded', 'failed'], true) && ($data['started_at'] === null || $data['finished_at'] === null || $data['finished_at'] < $data['started_at']))
            || ($data['status'] === 'rejected' && $data['started_at'] !== null)) {
            return $none;
        }
        $hash = IngestionService::contentHash(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        if ($record['result_hash'] !== null && $record['result_status'] !== 'unknown' && !hash_equals($record['result_hash'], $hash)) {
            return $none;
        }
        if ($record['result_hash'] === null || $record['result_status'] === 'unknown') {
            $transaction->table('iot_commands')->where('id', '=', $record['id'])->update(['device_received_at' => $record['device_received_at'] ?? $receivedAt,
                'result_status' => $data['status'], 'result_code' => $data['code'], 'started_at' => $data['started_at'], 'finished_at' => $data['finished_at'],
                'result_json' => json_encode($data['result'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'result_hash' => $hash, 'result_received_at' => $receivedAt]);
            if ($data['status'] !== 'unknown') {
                $transaction->table('iot_commands')->where('id', '=', $record['id'])->update(['schedule_stage' => 6, 'next_action_at' => null]);
            }
            if ($record['result_hash'] !== $hash) {
                AuditLog::append(
                    $transaction,
                    $record['tenant_id'],
                    self::actor($record),
                    'command.device_result',
                    $record['id'],
                    $data['status'] === 'unknown' ? 'unknown' : 'success',
                    self::auditDetails($record) + ['result_status' => $data['status']],
                    'customer'
                );
            }
        }
        // 每次覆盖nonce使重复回执也取得覆盖本次响应的同步持久证明。
        $transaction->table('iot_commands')->where('id', '=', $record['id'])->update(['receipt_nonce' => bin2hex(random_bytes(16))]);
        return ['topic' => $down, 'code' => 'accepted', 'receipt' => ['app_version' => 1, 'type' => 'command_receipt_ack',
            'device_id' => $device['id'], 'ownership_id' => $device['ownership_id'], 'command_id' => $record['id'], 'result_hash' => $hash]];
    }

    private static function available(?array $device, array $record): bool
    {
        return $device !== null && $device['tenant_id'] === $record['tenant_id'] && $device['ownership_id'] === $record['ownership_id']
            && (int) $device['recovery_verified'] === 1 && $device['lifecycle'] === 'enabled' && $device['connection']['status'] === 'online';
    }

    private static function auditDetails(array $record, ?array $attempt = null): array
    {
        $source = $attempt ?? $record;
        $origin = json_decode($source['source_context'], true, 8, JSON_THROW_ON_ERROR);
        $context = $attempt !== null && $attempt['kind'] === 'query' && $attempt['trigger_kind'] === 'automatic' ? 'system-command-reconciliation'
            : ($origin['impersonation_id'] === '' ? 'device-command' : 'customer-impersonation');
        return ['context' => $context, 'actor_realm' => $origin['actor_realm'], 'customer_id' => $origin['customer_id'], 'session_id' => $origin['session_id'],
            'source_session_id' => $origin['source_session_id'], 'impersonation_id' => $origin['impersonation_id'], 'scope_key' => $origin['key'],
            'device_id' => $record['device_id'], 'ownership_id' => $record['ownership_id'],
            'deadline_at' => (int) $record['deadline_at'], 'content_hash' => $record['content_hash']];
    }

    private static function attempt(Connection $transaction, array $record, string $kind, string $trigger, int $scheduledAt, string $state): array
    {
        $attempt = ['id' => bin2hex(random_bytes(16)), 'command_id' => $record['id'], 'kind' => $kind, 'trigger_kind' => $trigger,
            'actor_id' => $record['actor_id'], 'source_context' => $record['source_context'], 'scheduled_at' => $scheduledAt, 'state' => $state];
        $transaction->table('iot_command_attempts')->insert($attempt);
        return $attempt;
    }

    private static function attempts(Connection $connection, string $id, int $page, int $perPage): array
    {
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) {
            throw new HttpError(422, 'invalid_pagination');
        }
        $records = $connection->table('iot_command_attempts')->where('command_id', '=', $id)->orderBy('scheduled_at', 'DESC')->orderBy('id')->paginate($page, $perPage);
        $items = $records->items();
        foreach ($items as $index => $row) {
            $items[$index] = self::projectAttempt($row);
        }
        return ['items' => $items, 'total' => $records->total(), 'page' => $page, 'per_page' => $perPage];
    }

    private static function project(array $record): array
    {
        foreach (['model_version', 'request_version', 'accepted_at', 'deadline_at', 'dispatch_at', 'mqtt_at', 'mqtt_reason', 'device_received_at', 'started_at', 'finished_at', 'result_received_at', 'cancelled_at', 'dispatch_stopped_at'] as $key) {
            $record[$key] = $record[$key] === null ? null : (int) $record[$key];
        }
        $record['values'] = json_decode($record['payload'], false, 32, JSON_THROW_ON_ERROR)->values;
        $record['result'] = $record['result_json'] === null ? null : json_decode($record['result_json'], false, 32, JSON_THROW_ON_ERROR);
        $record['execution'] = $record['cancelled_at'] !== null ? 'cancelled' : ($record['dispatch_stopped_at'] !== null ? 'not_dispatched'
            : ($record['result_status'] ?? (time() >= $record['deadline_at'] ? 'unknown' : 'pending')));
        $record['unknown_reason'] = $record['execution'] !== 'unknown' ? null : ($record['started_at'] !== null ? 'execution_started_without_result'
            : ($record['last_query_code'] === 'result_not_retained' ? 'result_not_retained' : 'execution_receipt_missing'));
        $record['manual_query_after'] = (int) $record['accepted_at'] + 300;
        $record['retain_until'] = (int) $record['accepted_at'] + 180 * 86400;
        unset($record['payload'], $record['result_json'], $record['receipt_nonce'], $record['result_hash'], $record['schedule_stage'], $record['next_action_at'], $record['source_context'], $record['cancel_context']);
        return $record;
    }

    /** 来源只用于内部复核与审计，列表不公开会话关联信息。 */
    private static function projectAttempt(array $record): array
    {
        unset($record['source_context']);
        foreach (['scheduled_at', 'claimed_at', 'transport_at', 'mqtt_reason', 'response_at'] as $field) {
            $record[$field] = $record[$field] === null ? null : (int) $record[$field];
        }
        return $record;
    }

    private static function terminal(array $record): bool
    {
        return $record['cancelled_at'] !== null || $record['dispatch_stopped_at'] !== null || in_array($record['result_status'], ['succeeded', 'failed', 'rejected'], true);
    }

    private static function actor(array $record): string
    {
        return json_decode($record['source_context'], true, 8, JSON_THROW_ON_ERROR)['actor_id'];
    }

    private static function sameSource(array $record, Identity $identity, string $tenantId): bool
    {
        return json_decode($record['source_context'], true, 8, JSON_THROW_ON_ERROR)['key'] === IdentityService::context($identity, $tenantId)['key'];
    }

    /** @param Closure(Connection, Identity, list<string>): array<string, mixed> $operation 原业务与当前授权共享事务。 */
    private static function write(Connection $connection, Identity $identity, string $tenantId, string $action, string $id, Closure $operation): array
    {
        try {
            return RoleService::authorized($connection, $identity, null, 'customer.commands.' . $action, $operation, 'customer', $tenantId);
        } catch (HttpError $failure) {
            AuditLog::append(
                $connection,
                $tenantId,
                $identity,
                'command.' . $action,
                $id,
                in_array($failure->status(), [401, 403, 404], true) ? 'denied' : 'failed',
                ['context' => 'device-command', 'reason' => $failure->errorCode()],
                'customer'
            );
            throw $failure;
        }
    }

    private static function authorize(Connection $connection, Identity $identity, string $tenantId, string $action): array
    {
        $current = (new IdentityService('customer'))->refresh($connection, $identity);
        if ($current === null) {
            throw new HttpError(401, 'unauthenticated');
        }
        $permissions = RoleService::permissions($connection, $current, 'customer', $tenantId);
        if (!in_array('customer.commands.' . $action, $permissions, true)) {
            throw new HttpError(403, 'permission_denied');
        }
        return ['permissions' => $permissions, 'menus' => RoleService::menus('customer', $permissions), 'identity' => IdentityService::context($current, $tenantId)];
    }

    private static function transaction(Connection $connection): void
    {
        if ($connection->transactionDepth() < 1) {
            throw new LogicException('command_transaction_required');
        }
    }
}
