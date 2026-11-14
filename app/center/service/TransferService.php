<?php

declare(strict_types=1);

namespace app\center\service;

use app\center\model\ApplicationGrant;
use app\center\model\ClientApplication;
use app\center\model\Device;
use app\center\model\DeviceConnection;
use app\center\model\DeviceCredential;
use app\center\model\DeviceOwnership;
use app\center\model\DeviceReassignment;
use app\common\service\AuditLog;
use app\common\service\IdentityService;
use app\common\service\RoleService;
use app\platform\model\DeviceDirectory;
use app\platform\model\Tenant;
use app\platform\mqtt\service\AccessService;
use app\platform\mqtt\service\ConnectionOperations;
use app\platform\mqtt\service\ResourceQueries;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Orm\Connection;
use Type\Orm\Db;
use Type\Orm\ModelConditions;
use Type\Runtime\ExecutionScope;

/**
 * 平台结束使用关系、旧授权隔离及目标客户接收的持久所有者。
 */
final class TransferService
{
    private const RELATION_FIELDS = ['id', 'device_id', 'device_name', 'source_tenant_id', 'target_tenant_id', 'ownership_id', 'new_ownership_id',
        'kind', 'status', 'requires_store', 'isolation_id', 'access_version', 'version', 'created_at', 'updated_at'];

    /**
     * 原始通信的当前关系逐动作核对；未完成转换的旧设备不可接入。
     */
    public static function ownershipCurrent(Connection $connection, array $device): bool
    {
        if ($device['transfer_id'] !== null) {
            return false;
        }
        return (bool)ExecutionScope::current()->run(static function () use ($connection, $device): bool {
            $ownership = DeviceOwnership::query()->master()->onConnection($connection)->where('id', '=', $device['ownership_id'])
                ->where('device_id', '=', $device['id'])->first();
            return $ownership !== null && $ownership->getEndedAt() === null
                && ($ownership->getExpiresAt() === null || $ownership->getExpiresAt() > time());
        }, ['tenant_id' => $device['tenant_id']]);
    }

    /**
     * 平台显式设置使用期限；保存原授权来源，到期收尾不重新借用已退出的人员会话。
     */
    public static function expiry(Identity $identity, string $deviceId, int $version, ?int $expiresAt): array
    {
        if ($expiresAt !== null && ($expiresAt <= time() || $expiresAt > 4102444800)) {
            throw new HttpError(422, 'device_expiry_invalid');
        }
        return RoleService::authorized($identity, null, 'platform.devices.expiry', static function (Identity $current, array $permissions) use ($deviceId, $version, $expiresAt): array {
            $connection = Db::connection('default', true);
            $row = self::relationshipDevice($connection, $deviceId);
            self::version($row, $version);
            if (!self::ownershipCurrent($connection, $row)) {
                throw new HttpError(409, 'device_ownership_unavailable');
            }
            return ExecutionScope::current()->run(static function (ExecutionScope $scope) use ($row, $expiresAt, $current): array {
                $ownership = DeviceOwnership::query()->master()->findOrFail($row['ownership_id']);
                $ownership->setExpiresAt($expiresAt);
                $ownership->setExpiryContext($expiresAt === null ? null : json_encode(IdentityService::context($current, $row['tenant_id']), JSON_THROW_ON_ERROR));
                $ownership->save();
                $device = Device::query()->master()->findOrFail($row['id']);
                $device->touch();
                AuditLog::append(
                    Db::connection('default', true),
                    $row['tenant_id'],
                    $current,
                    'device.usage_expiry',
                    $row['id'],
                    'success',
                    ['ownership_id' => $row['ownership_id'], 'version' => $device->getVersion(), 'expires_at' => $expiresAt ?? 0],
                    'platform'
                );
                return ['ownership' => $ownership->toArray(), 'device_version' => $device->getVersion()];
            }, ['tenant_id' => $row['tenant_id']]);
        }, 'platform', 'platform');
    }

    /**
     * 平台发起关系结束或转配；同一请求 ID 重试仅返回原结果，不能改变目标或复用旧版本。
     * @param array{id:string,version:int,target_tenant_id?:string} $data 已校验的请求；目标缺省表示只结束使用
     */
    public static function reassign(Identity $identity, string $deviceId, array $data): array
    {
        $kind = isset($data['target_tenant_id']) ? 'reassign' : 'end';
        return RoleService::authorized(
            $identity,
            null,
            $kind === 'reassign' ? 'platform.reassignments.create' : 'platform.devices.end',
            static function (Identity $current, array $permissions) use ($deviceId, $data, $kind): array {
                $connection = Db::connection('default', true);
                $row = self::relationshipDevice($connection, $deviceId);
                $context = IdentityService::context($current, $row['tenant_id']);
                $existing = DeviceReassignment::query()->master()->find($data['id']);
                if ($existing !== null) {
                    $origin = json_decode($existing->getSourceContext(), true, 8, JSON_THROW_ON_ERROR);
                    if ($existing->getDeviceId() !== $deviceId || $existing->getTargetTenantId() !== ($data['target_tenant_id'] ?? null)
                        || $existing->getRequestVersion() !== $data['version'] || $origin['actor_id'] !== $context['actor_id'] || $existing->getKind() !== $kind) {
                        throw new HttpError(409, 'reassignment_request_conflict');
                    }
                    return self::relationshipProjection($connection, $existing);
                }
                self::version($row, $data['version']);
                return self::beginRelationship($connection, $row, $data['id'], $data['target_tenant_id'] ?? null, $kind, $context);
            },
            'platform',
            'platform'
        );
    }

    /**
     * Broker 既有撤权轮询每次最多结束一条到期关系；新认证/动作另行即时拒绝，不依赖此轮询的进度。
     * 上一发布或设备撤权未完成时保留到期关系待下一轮，不伪造成功也不绕过发布串行边界。
     */
    public static function expireOwnership(Connection $connection): void
    {
        try {
            $connection->transaction(static function (Connection $transaction): void {
                RoleService::lockAuthorization();
                $due = $transaction->query('SELECT d.* FROM center_device_ownership o JOIN center_device d ON d.ownership_id = o.id'
                    . ' WHERE o.ended_at IS NULL AND o.expires_at <= ? AND d.transfer_id IS NULL AND d.recovery_verified = 1 ORDER BY o.expires_at, o.id LIMIT 1', [time()]);
                if ($due === []) {
                    return;
                }
                $row = self::relationshipDevice($transaction, $due[0]['id']);
                $ownership = ExecutionScope::current()->run(static function () use ($transaction, $row): DeviceOwnership {
                    return DeviceOwnership::query()->master()->onConnection($transaction)->findOrFail($row['ownership_id']);
                }, ['tenant_id' => $row['tenant_id']]);
                if ($ownership->getExpiryContext() === null) {
                    throw new \RuntimeException('device_expiry_context_missing');
                }
                self::beginRelationship(
                    $transaction,
                    $row,
                    bin2hex(random_bytes(16)),
                    null,
                    'expire',
                    json_decode($ownership->getExpiryContext(), true, 8, JSON_THROW_ON_ERROR)
                );
            }, $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default');
        } catch (HttpError $failure) {
            if (!in_array($failure->errorCode(), ['broker_access_publish_paused', 'device_authorization_pending'], true)) {
                throw $failure;
            }
        }
    }

    /**
     * 两端仅查看自身可见的操作与真实阶段；查询不发起持久清理。
     */
    public static function reassignments(Identity $identity, string $realm, string $tenant, int $page, int $perPage, string $id = ''): array
    {
        $context = RoleService::readContext($identity, $realm, $tenant, ($realm === 'system' ? 'center' : 'platform') . '.reassignments.read');
        $context['menus'] = RoleService::menus($realm, $context['permissions']);
        $connection = Db::connection('default', true);
        if ($id !== '') {
            return ['operation' => self::relationshipProjection($connection, self::visibleRelationship($id, $realm, $tenant)), 'context' => $context];
        }
        $query = DeviceReassignment::query()->master();
        if ($realm === 'system') {
            $query = $query->whereGroup(static fn (ModelConditions $group): ModelConditions => $group->where('source_tenant_id', '=', $tenant)->orWhere('target_tenant_id', '=', $tenant));
        }
        $rows = \_query($query, ['page' => $page, 'page_size' => $perPage])
            ->order(['created_at' => 'created_at', 'id' => 'id'], ['created_at' => 'DESC', 'id' => 'ASC'])
            ->paginatePage($perPage, 100, 100000);
        $items = [];
        foreach ($rows->items() as $model) {
            $items[] = self::relationshipProjection($connection, $model);
        }
        return ['items' => $items, 'total' => $rows->total(), 'page' => $page, 'per_page' => $perPage, 'context' => $context];
    }

    /**
     * 显式推进持久资源核验。外部查询不占管理事务；重新授权和版本核对后才登记精确清理意图。
     * @param list<string> $worker 当前应用的持久存储命令；历史未知时空命令明确拒绝
     */
    public static function advanceRelationship(Identity $identity, string $id, int $version, array $worker): array
    {
        RoleService::readContext($identity, 'platform', '', 'reassignments.advance');
        $connection = Db::connection('default', true);
        $record = self::visibleRelationship($id, 'platform', '');
        if ($record->getVersion() !== $version) {
            throw new HttpError(409, 'stale_version');
        }
        if ($record->getStatus() !== 'isolating' || !self::relationshipIsolated($connection, $record)) {
            return self::relationshipProjection($connection, $record);
        }
        $proofs = [];
        if ($record->getRequiresStore()) {
            if ($worker === []) {
                throw new HttpError(503, 'reassignment_store_required');
            }
            $sourceTenant = $record->getSourceTenantId();
            $topics = DeviceService::topics(['id' => $record->getDeviceId(), 'tenant_id' => $sourceTenant, 'ownership_id' => $record->getOwnershipId()]);
            foreach ($topics as $direction => $topic) {
                $result = ResourceQueries::query(
                    $connection,
                    $worker,
                    'retained',
                    'topic=' . rawurlencode($topic) . '&limit=2',
                    '',
                    ['all_metadata' => false, 'resource_scope' => 'center:' . $sourceTenant, 'topic_namespace' => $topic],
                    'reassignment:' . $id
                );
                if ($result['has_more'] || count($result['items']) > 1) {
                    throw new \RuntimeException('reassignment_retained_identity_conflict');
                }
                $proofs[$direction] = $result['items'][0] ?? null;
                if ($proofs[$direction] !== null && $proofs[$direction]['topic'] !== $topic) {
                    throw new \RuntimeException('reassignment_retained_topic_conflict');
                }
            }
        }
        return RoleService::authorized($identity, null, 'platform.reassignments.advance', static function (Identity $current, array $permissions) use ($id, $version, $proofs): array {
            $connection = Db::connection('default', true);
            $record = self::visibleRelationship($id, 'platform', '');
            if ($record->getVersion() !== $version) {
                throw new HttpError(409, 'stale_version');
            }
            if ($record->getStatus() !== 'isolating' || !self::relationshipIsolated($connection, $record)) {
                return self::relationshipProjection($connection, $record);
            }
            $stored = json_decode($record->getRetainedJson(), true, 16, JSON_THROW_ON_ERROR);
            $complete = true;
            foreach ($proofs as $direction => $item) {
                $existing = $stored[$direction] ?? null;
                if ($existing !== null && isset($existing['operation_id'])) {
                    $operation = $connection->table('platform_mqtt_connection_operation')->where('id', '=', $existing['operation_id'])->first();
                    if ($operation === null || $operation['completed_at'] === null) {
                        $complete = false;
                        continue;
                    }
                }
                if ($item === null) {
                    $stored[$direction] = ['state' => 'absent', 'checked_at' => time()] + ($existing ?? []);
                    continue;
                }
                // 清理目标已由旧关系精确 Topic 约束；操作者仍是平台身份，不伪装为客户会话。
                $origin = IdentityService::context($current);
                unset($origin['actor_name'], $origin['expires_at']);
                $context = ['tenant_id' => null, 'actor_id' => $origin['actor_id'], 'actor_realm' => 'platform', 'permissions' => $permissions,
                    'required_action' => 'platform.reassignments.advance', 'identity' => $origin];
                $operation = ConnectionOperations::requestClear(
                    $connection,
                    $context,
                    $item['id'],
                    $item,
                    ['confirmed' => true, 'generation' => (int)$item['generation'], 'operation_id' => bin2hex(random_bytes(16))],
                    bin2hex(random_bytes(16))
                );
                $stored[$direction] = ['state' => 'clearing', 'operation_id' => $operation['operation_id'], 'resource_id' => $item['id'], 'generation' => (int)$item['generation']];
                $complete = false;
            }
            $encoded = json_encode($stored, JSON_THROW_ON_ERROR);
            $changed = $encoded !== $record->getRetainedJson();
            if ($complete) {
                $record->setStatus($record->getTargetTenantId() === null ? 'ended' : 'awaiting_acceptance');
                $changed = true;
                if ($record->getTargetTenantId() === null) {
                    ExecutionScope::current()->run(static function () use ($record, $id): void {
                        $device = Device::query()->master()->find($record->getDeviceId());
                        if ($device !== null && $device->getTransferId() === $id) {
                            $device->setTransferId(null);
                            $device->save();
                        }
                    }, ['tenant_id' => $record->getSourceTenantId()]);
                }
                self::relationshipAudit($connection, $record, IdentityService::context($current, $record->getSourceTenantId()), 'reassignment.isolated');
            }
            if ($changed) {
                $record->setRetainedJson($encoded);
                $record->save();
            }
            return self::relationshipProjection($connection, $record);
        }, 'platform', 'platform');
    }

    /**
     * 目标客户确认后才建立新关系和一次性凭据；丢失响应用既有轮换恢复，不重放秘密。
     */
    public static function acceptRelationship(Identity $identity, string $tenant, string $id, int $version, string $acceptId): array
    {
        return RoleService::authorized($identity, null, 'center.reassignments.accept', static function (Identity $current, array $permissions) use ($tenant, $id, $version, $acceptId): array {
            $connection = Db::connection('default', true);
            $record = self::visibleRelationship($id, 'system', $tenant);
            if ($record->getTargetTenantId() !== $tenant) {
                throw new HttpError(403, 'reassignment_target_required');
            }
            $context = IdentityService::context($current, $tenant);
            if ($record->getStatus() === 'completed') {
                $origin = json_decode($record->getAcceptContext(), true, 8, JSON_THROW_ON_ERROR);
                if ($record->getAcceptId() !== $acceptId || $record->getAcceptVersion() !== $version || $origin['actor_id'] !== $context['actor_id']
                    || $origin['user_id'] !== $context['user_id']) {
                    throw new HttpError(409, 'reassignment_request_conflict');
                }
                return ['operation' => self::relationshipProjection($connection, $record), 'credential' => null, 'replayed' => true];
            }
            if ($record->getVersion() !== $version) {
                throw new HttpError(409, 'stale_version');
            }
            if ($record->getStatus() !== 'awaiting_acceptance') {
                throw new HttpError(409, 'reassignment_isolation_pending');
            }
            $target = Tenant::query()->master()->find($tenant);
            if ($target === null || !$target->getEnabled()) {
                throw new HttpError(409, 'reassignment_target_unavailable');
            }
            $device = self::relationshipDevice($connection, $record->getDeviceId());
            if ($device['transfer_id'] !== $id || $device['ownership_id'] !== $record->getOwnershipId()) {
                throw new HttpError(409, 'reassignment_relationship_changed');
            }
            $ownershipId = bin2hex(random_bytes(16));
            DeviceOwnership::create(['id' => $ownershipId, 'tenant_id' => $tenant, 'device_id' => $device['id'], 'started_at' => time(), 'ended_at' => null,
                'expires_at' => null, 'expiry_context' => null, 'access_started' => false]);
            // 改归属是受控的跨租户基础设施操作；Model 禁止直接改变已加载实体的租户身份。
            $connection->table('center_device')->where('tenant_id', '=', $record->getSourceTenantId())->where('id', '=', $device['id'])->where('ownership_id', '=', $record->getOwnershipId())
                ->where('transfer_id', '=', $id)->update(['tenant_id' => $tenant, 'product_id' => null, 'ownership_id' => $ownershipId, 'lifecycle' => 'inactive',
                    'transfer_id' => null, 'transfer_frozen' => 0, 'version' => (int)$device['version'] + 1, 'updated_at' => time()]);
            $connection->table('center_device_connection')->where('tenant_id', '=', $record->getSourceTenantId())->where('device_id', '=', $device['id'])
                ->where('ownership_id', '=', $record->getOwnershipId())->update(['tenant_id' => $tenant, 'ownership_id' => $ownershipId,
                    'status' => 'unknown', 'owner_id' => null, 'node_id' => null, 'run_id' => null, 'observed_at' => null]);
            $deviceConnection = DeviceConnection::query()->master()->find($device['id']);
            if ($deviceConnection === null) {
                throw new HttpError(409, 'reassignment_connection_missing');
            }
            $deviceConnection->setOwnershipId($ownershipId);
            $deviceConnection->setStatus('unknown');
            $deviceConnection->setOwnerId(null);
            $deviceConnection->setNodeId(null);
            $deviceConnection->setRunId(null);
            $deviceConnection->setObservedAt(null);
            $deviceConnection->save();
            $record->setNewOwnershipId($ownershipId);
            $record->setStatus('completed');
            $record->setAcceptId($acceptId);
            $record->setAcceptVersion($version);
            $record->setAcceptContext(json_encode($context, JSON_THROW_ON_ERROR));
            $record->save();
            $credential = DeviceService::transferCredential($connection, array_replace($device, ['tenant_id' => $tenant, 'ownership_id' => $ownershipId]));
            self::relationshipAudit($connection, $record, $context, 'reassignment.accepted');
            return ['operation' => self::relationshipProjection($connection, $record), 'credential' => $credential, 'replayed' => false];
        }, 'system', $tenant);
    }

    /**
     * 调用者已持有授权写锁；领域变更、所有应用撤权与审计一起提交。
     */
    private static function beginRelationship(Connection $connection, array $row, string $id, ?string $target, string $kind, array $context): array
    {
        if ($row['transfer_id'] !== null || $row['lifecycle'] === 'retired') {
            throw new HttpError(409, 'reassignment_in_progress');
        }
        if ($target !== null) {
            $tenant = Tenant::query()->master()->find($target);
            if ($target === $row['tenant_id'] || $tenant === null || !$tenant->getEnabled()) {
                throw new HttpError(422, 'reassignment_target_unavailable');
            }
        }
        $pending = $connection->table('center_authorization_invalidation')->where('device_id', '=', $row['id'])->whereNull('completed_at')->first();
        if ($pending !== null) {
            throw new HttpError(409, 'device_authorization_pending');
        }
        return ExecutionScope::current()->run(static function (ExecutionScope $scope) use ($row, $id, $target, $kind, $context, $connection): array {
            $ownership = DeviceOwnership::query()->master()->findOrFail($row['ownership_id']);
            $grants = ApplicationGrant::query()->master()->where('ownership_id', '=', $row['ownership_id'])->get();
            $hints = [];
            $requiresStore = $ownership->getAccessStarted();
            $hasRules = false;
            foreach ($grants as $grant) {
                $app = ClientApplication::query()->master()->findOrFail($grant->getApplicationId());
                if ($app->getAccessStarted()) {
                    $requiresStore = true;
                    $hints[$app->getId()] = 'app:' . $app->getId();
                }
                $hasRules = $hasRules || $grant->getReceive() || $grant->getSend() || $grant->getSubscribe();
            }
            $principal = $connection->table('platform_mqtt_access_principal')->where('id', '=', $row['id'])->first();
            if ($principal !== null) {
                $hints[$row['id']] = $row['id'];
                $requiresStore = true;
                $hasRules = $hasRules || (int)$principal['enabled'] === 1;
            }
            $accessVersion = $hasRules ? AccessService::currentVersion($connection) : null;
            $change = static function (Connection $transaction) use ($grants, $row, $accessVersion, $principal): void {
                $topics = DeviceService::topics($row);
                foreach ($grants as $grant) {
                    if (!$grant->getReceive() && !$grant->getSend() && !$grant->getSubscribe()) {
                        continue;
                    }
                    $grant->setReceive(false);
                    $grant->setSend(false);
                    $grant->setSubscribe(false);
                    $grant->save();
                    foreach ($topics as $topic) {
                        $transaction->table('platform_mqtt_access_grant')->where('principal_id', '=', $grant->getApplicationId())->where('topic', '=', $topic)->delete();
                    }
                    $app = ClientApplication::query()->master()->findOrFail($grant->getApplicationId());
                    $app->setAccessVersion($accessVersion + 1);
                    $app->save();
                }
                if ($principal !== null) {
                    $transaction->table('platform_mqtt_access_principal')->where('id', '=', $row['id'])->update(['enabled' => 0, 'updated_at' => time()]);
                }
            };
            if ($hasRules) {
                AccessService::publishChange($connection, $row['tenant_id'], $context['actor_id'], $context['actor_realm'], $accessVersion, $change, '', $hints);
            }
            $isolationId = null;
            if ($requiresStore) {
                $isolationId = DeviceService::invalidateCredential($connection, $row, $context, true);
            } else {
                DeviceCredential::query()->master()->where('tenant_id', '=', $row['tenant_id'])->where('device_id', '=', $row['id'])->where('ownership_id', '=', $row['ownership_id'])
                    ->where('status', '=', 'active')->update(['status' => 'revoked', 'revoked_at' => time()]);
            }
            if ($ownership->getEndedAt() === null) {
                $ownership->setEndedAt(time());
                $ownership->save();
            }
            $device = Device::query()->master()->findOrFail($row['id']);
            $device->setTransferId($id);
            $device->setLifecycle('disabled');
            $device->save();
            $record = DeviceReassignment::create(['id' => $id, 'device_id' => $row['id'], 'device_name' => $row['name'], 'source_tenant_id' => $row['tenant_id'],
                'target_tenant_id' => $target, 'ownership_id' => $row['ownership_id'], 'new_ownership_id' => null, 'kind' => $kind, 'status' => 'isolating',
                'request_version' => (int)$row['version'], 'source_context' => json_encode($context, JSON_THROW_ON_ERROR), 'requires_store' => $requiresStore,
                'isolation_id' => $isolationId, 'access_version' => $accessVersion === null ? null : $accessVersion + 1, 'retained_json' => '{}',
                'accept_id' => null, 'accept_version' => null, 'accept_context' => null]);
            self::relationshipAudit($connection, $record, $context, 'reassignment.' . $kind);
            return self::relationshipProjection($connection, $record);
        }, ['tenant_id' => $row['tenant_id']]);
    }

    private static function relationshipDevice(Connection $connection, string $id): array
    {
        $device = self::lockedDevice($connection, $id);
        if ($device === null) {
            throw new HttpError(404, 'device_not_found');
        }
        return $device;
    }

    private static function visibleRelationship(string $id, string $realm, string $tenant): DeviceReassignment
    {
        $record = DeviceReassignment::query()->master()->find($id);
        if ($record === null || ($realm === 'system' && !in_array($tenant, [$record->getSourceTenantId(), $record->getTargetTenantId()], true))) {
            throw new HttpError(404, 'reassignment_not_found');
        }
        return $record;
    }

    /**
     * session_terminate 回执已含全部旧所有者的 fence 和持久清理，不以管理连接离线推断完成。
     */
    private static function relationshipIsolated(Connection $connection, DeviceReassignment $record): bool
    {
        if ($record->getIsolationId() !== null) {
            $intent = $connection->table('center_authorization_invalidation')->where('id', '=', $record->getIsolationId())->first();
            if ($intent === null || $intent['completed_at'] === null) {
                return false;
            }
        }
        if ($record->getAccessVersion() !== null) {
            $revision = $connection->table('platform_mqtt_access_revision')->where('version', '=', $record->getAccessVersion())->first();
            if ($revision === null || $revision['status'] !== 'effective') {
                return false;
            }
        }
        return true;
    }

    private static function relationshipProjection(Connection $connection, DeviceReassignment $record): array
    {
        $row = $record->project(self::RELATION_FIELDS);
        $row['pending_reasons'] = match ($record->getStatus()) {
            'isolating' => [self::relationshipIsolated($connection, $record) ? 'retained_verification_required' : 'old_authorization_isolation_pending'],
            'awaiting_acceptance' => ['target_system_confirmation_required'], default => [],
        };
        $row['retained'] = json_decode($record->getRetainedJson(), true, 16, JSON_THROW_ON_ERROR);
        return $row;
    }

    /**
     * 一份真实来源分别留下平台与双方客户的可见记录，不复制任何密钥。
     */
    private static function relationshipAudit(Connection $connection, DeviceReassignment $record, array $context, string $action): void
    {
        $details = ['device_id' => $record->getDeviceId(), 'ownership_id' => $record->getOwnershipId(), 'version' => $record->getVersion(), 'context' => 'device-reassignment'];
        AuditLog::append($connection, $record->getSourceTenantId(), $context, $action, $record->getId(), 'success', $details, 'platform');
        foreach (array_unique(array_filter([$record->getSourceTenantId(), $record->getTargetTenantId()])) as $tenant) {
            AuditLog::append($connection, $tenant, $context, $action, $record->getId(), 'success', $details, 'system');
        }
    }

    private static function lockedDevice(Connection $connection, string $id): ?array
    {
        $query = DeviceDirectory::query()->master()->onConnection($connection)->where('id', '=', $id)->where('recovery_verified', '=', true);
        $device = ($connection->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
        return $device?->toArray();
    }

    private static function version(array $device, int $version): void
    {
        if ($version < 1 || $version >= 2147483646 || (int)$device['version'] !== $version) {
            throw new HttpError(409, 'stale_version');
        }
    }
}
