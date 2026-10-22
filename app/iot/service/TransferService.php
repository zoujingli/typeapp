<?php

declare(strict_types=1);

namespace app\iot\service;

use app\common\service\AuditLog;
use app\common\service\IdentityService;
use app\common\service\RoleService;
use app\broker\service\AccessService;
use app\broker\service\ConnectionOperations;
use app\broker\service\ResourceQueries;
use app\common\model\Tenant;
use app\iot\model\ApplicationGrant;
use app\iot\model\ClientApplication;
use app\iot\model\Device;
use app\iot\model\DeviceOwnership;
use app\iot\model\DeviceReassignment;
use Closure;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Orm\Connection;
use Type\Orm\Db;
use Type\Runtime\ExecutionScope;

/** 平台结束使用关系、旧授权隔离及目标客户接收的持久所有者；旧模型流程待升级完成后退出。 */
final class TransferService
{
    private const RELATION_FIELDS = ['id', 'device_id', 'device_name', 'source_tenant_id', 'target_tenant_id', 'ownership_id', 'new_ownership_id',
        'kind', 'status', 'requires_store', 'isolation_id', 'access_version', 'version', 'created_at', 'updated_at'];

    /** 原始通信的当前关系逐动作核对；旧模型路径在升级退出前保持原有合同。 */
    public static function ownershipCurrent(Connection $connection, array $device): bool
    {
        if ($device['model_version'] !== null) {
            return true;
        }
        if ($device['transfer_id'] !== null) {
            return false;
        }
        $row = $connection->table('iot_device_ownerships')->where('id', '=', $device['ownership_id'])->where('device_id', '=', $device['id'])
            ->where('tenant_id', '=', $device['tenant_id'])->first();
        return $row !== null && $row['ended_at'] === null && ($row['expires_at'] === null || (int) $row['expires_at'] > time());
    }

    /** 平台显式设置使用期限；保存原授权来源，到期收尾不重新借用已退出的人员会话。 */
    public static function expiry(Identity $identity, string $deviceId, int $version, ?int $expiresAt): array
    {
        if ($expiresAt !== null && ($expiresAt <= time() || $expiresAt > 4102444800)) {
            throw new HttpError(422, 'device_expiry_invalid');
        }
        return RoleService::authorized($identity, null, 'admin.devices.expiry', static function (Identity $current, array $permissions) use ($deviceId, $version, $expiresAt): array {
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
                    'admin'
                );
                return ['ownership' => $ownership->toArray(), 'device_version' => $device->getVersion()];
            }, ['tenant_id' => $row['tenant_id']]);
        }, 'admin', 'platform');
    }

    /**
     * 平台发起关系结束或转配；同一请求 ID 重试仅返回原结果，不能改变目标或复用旧版本。
     * @param array{id:string,version:int,target_tenant_id?:string} $data 已校验的请求；目标缺省表示只结束使用。
     */
    public static function reassign(Identity $identity, string $deviceId, array $data): array
    {
        $kind = isset($data['target_tenant_id']) ? 'reassign' : 'end';
        return RoleService::authorized(
            $identity,
            null,
            $kind === 'reassign' ? 'admin.reassignments.create' : 'admin.devices.end',
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
            'admin',
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
                $due = $transaction->query('SELECT d.* FROM iot_device_ownerships o JOIN iot_devices d ON d.ownership_id = o.id'
                    . ' WHERE o.ended_at IS NULL AND o.expires_at <= ? AND d.model_version IS NULL AND d.transfer_id IS NULL AND d.recovery_verified = 1 ORDER BY o.expires_at, o.id LIMIT 1', [time()]);
                if ($due === []) {
                    return;
                }
                $row = self::relationshipDevice($transaction, $due[0]['id']);
                $ownership = $transaction->table('iot_device_ownerships')->where('id', '=', $row['ownership_id'])->first();
                if ($ownership['expiry_context'] === null) {
                    throw new \RuntimeException('device_expiry_context_missing');
                }
                self::beginRelationship($transaction, $row, bin2hex(random_bytes(16)), null, 'expire', json_decode($ownership['expiry_context'], true, 8, JSON_THROW_ON_ERROR));
            }, $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default');
        } catch (HttpError $failure) {
            if (!in_array($failure->errorCode(), ['broker_access_publish_paused', 'device_authorization_pending'], true)) {
                throw $failure;
            }
        }
    }

    /** 两端仅查看自身可见的操作与真实阶段；查询不发起持久清理。 */
    public static function reassignments(Identity $identity, string $realm, string $tenant, int $page, int $perPage, string $id = ''): array
    {
        $context = RoleService::readContext($identity, $realm, $tenant, 'reassignments.read');
        $context['menus'] = RoleService::menus($realm, $context['permissions']);
        $connection = Db::connection('default', true);
        if ($id !== '') {
            return ['operation' => self::relationshipProjection($connection, self::visibleRelationship($id, $realm, $tenant)), 'context' => $context];
        }
        $query = DeviceReassignment::query()->master();
        if ($realm === 'customer') {
            $query = $query->whereGroup(static fn (\Type\Orm\ModelConditions $group): \Type\Orm\ModelConditions => $group->where('source_tenant_id', '=', $tenant)->orWhere('target_tenant_id', '=', $tenant));
        }
        $rows = $query->orderBy('created_at', 'DESC')->orderBy('id')->paginate($page, $perPage);
        $items = [];
        foreach ($rows->items() as $model) {
            $items[] = self::relationshipProjection($connection, $model);
        }
        return ['items' => $items, 'total' => $rows->total(), 'page' => $page, 'per_page' => $perPage, 'context' => $context];
    }

    /**
     * 显式推进持久资源核验。外部查询不占管理事务；重新授权和版本核对后才登记精确清理意图。
     * @param list<string> $worker 当前应用的持久存储命令；历史未知时空命令明确拒绝。
     */
    public static function advanceRelationship(Identity $identity, string $id, int $version, array $worker): array
    {
        RoleService::readContext($identity, 'admin', '', 'reassignments.advance');
        $connection = Db::connection('default', true);
        $record = self::visibleRelationship($id, 'admin', '');
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
            $topics = DeviceService::topics(['id' => $record->getDeviceId(), 'tenant_id' => $sourceTenant, 'ownership_id' => $record->getOwnershipId(), 'model_version' => null]);
            foreach ($topics as $direction => $topic) {
                $result = ResourceQueries::query(
                    $connection,
                    $worker,
                    'retained',
                    'topic=' . rawurlencode($topic) . '&limit=2',
                    '',
                    ['all_metadata' => false, 'resource_scope' => 'iot:' . $sourceTenant, 'topic_namespace' => $topic],
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
        return RoleService::authorized($identity, null, 'admin.reassignments.advance', static function (Identity $current, array $permissions) use ($id, $version, $proofs): array {
            $connection = Db::connection('default', true);
            $record = self::visibleRelationship($id, 'admin', '');
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
                    $operation = $connection->table('broker_connection_operations')->where('id', '=', $existing['operation_id'])->first();
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
                $context = ['tenant_id' => null, 'actor_id' => $origin['actor_id'], 'actor_realm' => 'admin', 'permissions' => $permissions,
                    'required_action' => 'admin.reassignments.advance', 'identity' => $origin];
                $operation = ConnectionOperations::requestClear(
                    $connection,
                    $context,
                    $item['id'],
                    $item,
                    ['confirmed' => true, 'generation' => (int) $item['generation'], 'operation_id' => bin2hex(random_bytes(16))],
                    bin2hex(random_bytes(16))
                );
                $stored[$direction] = ['state' => 'clearing', 'operation_id' => $operation['operation_id'], 'resource_id' => $item['id'], 'generation' => (int) $item['generation']];
                $complete = false;
            }
            $encoded = json_encode($stored, JSON_THROW_ON_ERROR);
            $changed = $encoded !== $record->getRetainedJson();
            if ($complete) {
                $record->setStatus($record->getTargetTenantId() === null ? 'ended' : 'awaiting_acceptance');
                $changed = true;
                if ($record->getTargetTenantId() === null) {
                    $connection->table('iot_devices')->where('id', '=', $record->getDeviceId())->where('transfer_id', '=', $id)->update(['transfer_id' => null]);
                }
                self::relationshipAudit($connection, $record, IdentityService::context($current, $record->getSourceTenantId()), 'reassignment.isolated');
            }
            if ($changed) {
                $record->setRetainedJson($encoded);
                $record->save();
            }
            return self::relationshipProjection($connection, $record);
        }, 'admin', 'platform');
    }

    /** 目标客户确认后才建立新关系和一次性凭据；丢失响应用既有轮换恢复，不重放秘密。 */
    public static function acceptRelationship(Identity $identity, string $tenant, string $id, int $version, string $acceptId): array
    {
        return RoleService::authorized($identity, null, 'customer.reassignments.accept', static function (Identity $current, array $permissions) use ($tenant, $id, $version, $acceptId): array {
            $connection = Db::connection('default', true);
            $record = self::visibleRelationship($id, 'customer', $tenant);
            if ($record->getTargetTenantId() !== $tenant) {
                throw new HttpError(403, 'reassignment_target_required');
            }
            $context = IdentityService::context($current, $tenant);
            if ($record->getStatus() === 'completed') {
                $origin = json_decode($record->getAcceptContext(), true, 8, JSON_THROW_ON_ERROR);
                if ($record->getAcceptId() !== $acceptId || $record->getAcceptVersion() !== $version || $origin['actor_id'] !== $context['actor_id']
                    || $origin['customer_id'] !== $context['customer_id']) {
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
            DeviceOwnership::create(['id' => $ownershipId, 'device_id' => $device['id'], 'started_at' => time(), 'ended_at' => null,
                'expires_at' => null, 'expiry_context' => null, 'access_started' => false]);
            // 改归属是受控的跨租户基础设施操作；Model 禁止直接改变已加载实体的租户身份。
            $connection->table('iot_devices')->where('id', '=', $device['id'])->where('ownership_id', '=', $record->getOwnershipId())
                ->where('transfer_id', '=', $id)->update(['tenant_id' => $tenant, 'ownership_id' => $ownershipId, 'lifecycle' => 'inactive',
                    'transfer_id' => null, 'transfer_frozen' => 0, 'version' => (int) $device['version'] + 1, 'updated_at' => time()]);
            $connection->table('iot_device_connections')->where('device_id', '=', $device['id'])->update(['ownership_id' => $ownershipId, 'status' => 'unknown',
                'owner_id' => null, 'node_id' => null, 'run_id' => null, 'observed_at' => null]);
            $record->setNewOwnershipId($ownershipId);
            $record->setStatus('completed');
            $record->setAcceptId($acceptId);
            $record->setAcceptVersion($version);
            $record->setAcceptContext(json_encode($context, JSON_THROW_ON_ERROR));
            $record->save();
            $credential = DeviceService::transferCredential($connection, array_replace($device, ['tenant_id' => $tenant, 'ownership_id' => $ownershipId]));
            self::relationshipAudit($connection, $record, $context, 'reassignment.accepted');
            return ['operation' => self::relationshipProjection($connection, $record), 'credential' => $credential, 'replayed' => false];
        }, 'customer', $tenant);
    }

    /** 调用者已持有授权写锁；领域变更、所有应用撤权与审计一起提交。 */
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
        $pending = $connection->table('iot_authorization_invalidations')->where('device_id', '=', $row['id'])->whereNull('completed_at')->first();
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
            $principal = $connection->table('broker_access_principals')->where('id', '=', $row['id'])->first();
            if ($principal !== null) {
                $hints[$row['id']] = $row['id'];
                $requiresStore = true;
                $hasRules = $hasRules || (int) $principal['enabled'] === 1;
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
                        $transaction->table('broker_access_grants')->where('principal_id', '=', $grant->getApplicationId())->where('topic', '=', $topic)->delete();
                    }
                    $app = ClientApplication::query()->master()->findOrFail($grant->getApplicationId());
                    $app->setAccessVersion($accessVersion + 1);
                    $app->save();
                }
                if ($principal !== null) {
                    $transaction->table('broker_access_principals')->where('id', '=', $row['id'])->update(['enabled' => 0, 'updated_at' => time()]);
                }
            };
            if ($hasRules) {
                AccessService::publishChange($connection, $row['tenant_id'], $context['actor_id'], $context['actor_realm'], $accessVersion, $change, '', $hints);
            }
            $isolationId = null;
            if ($requiresStore) {
                $isolationId = DeviceService::invalidateCredential($connection, $row, $context, true);
            } else {
                $connection->table('iot_device_credentials')->where('device_id', '=', $row['id'])->where('ownership_id', '=', $row['ownership_id'])
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
                'request_version' => (int) $row['version'], 'source_context' => json_encode($context, JSON_THROW_ON_ERROR), 'requires_store' => $requiresStore,
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
        if ($device['model_version'] !== null) {
            throw new HttpError(409, 'reassignment_legacy_upgrade_required');
        }
        return $device;
    }

    private static function visibleRelationship(string $id, string $realm, string $tenant): DeviceReassignment
    {
        $record = DeviceReassignment::query()->master()->find($id);
        if ($record === null || ($realm === 'customer' && !in_array($tenant, [$record->getSourceTenantId(), $record->getTargetTenantId()], true))) {
            throw new HttpError(404, 'reassignment_not_found');
        }
        return $record;
    }

    /** session_terminate 回执已含全部旧所有者的 fence 和持久清理，不以管理连接离线推断完成。 */
    private static function relationshipIsolated(Connection $connection, DeviceReassignment $record): bool
    {
        if ($record->getIsolationId() !== null) {
            $intent = $connection->table('iot_authorization_invalidations')->where('id', '=', $record->getIsolationId())->first();
            if ($intent === null || $intent['completed_at'] === null) {
                return false;
            }
        }
        if ($record->getAccessVersion() !== null) {
            $revision = $connection->table('broker_access_revisions')->where('version', '=', $record->getAccessVersion())->first();
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
            'awaiting_acceptance' => ['target_customer_confirmation_required'], default => [],
        };
        $row['retained'] = json_decode($record->getRetainedJson(), true, 16, JSON_THROW_ON_ERROR);
        return $row;
    }

    /** 一份真实来源分别留下平台与双方客户的可见记录，不复制任何密钥。 */
    private static function relationshipAudit(Connection $connection, DeviceReassignment $record, array $context, string $action): void
    {
        $details = ['device_id' => $record->getDeviceId(), 'ownership_id' => $record->getOwnershipId(), 'version' => $record->getVersion(), 'context' => 'device-reassignment'];
        AuditLog::append($connection, $record->getSourceTenantId(), $context, $action, $record->getId(), 'success', $details, 'admin');
        foreach (array_unique(array_filter([$record->getSourceTenantId(), $record->getTargetTenantId()])) as $tenant) {
            AuditLog::append($connection, $tenant, $context, $action, $record->getId(), 'success', $details, 'customer');
        }
    }

    /** 原请求固定设备、双方租户、准确版本及真实来源；授权锁先于双方租户和设备锁。 */
    public static function request(Connection $connection, Identity $identity, string $tenantId, string $deviceId, string $targetTenant, string $transferId, int $version): array
    {
        foreach ([$deviceId, $targetTenant, $transferId] as $id) {
            if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
                throw new HttpError(422, 'transfer_identity_invalid');
            }
        }
        if ($tenantId === $targetTenant) {
            throw new HttpError(422, 'transfer_target_invalid');
        }
        return self::write($connection, $identity, $tenantId, 'request', $transferId, static function (Identity $current, array $permissions) use ($tenantId, $deviceId, $targetTenant, $transferId, $version): array {
            $transaction = \Type\Orm\Db::connection('default', true);
            self::lockTenants($transaction, $tenantId, $targetTenant);
            $device = self::lockedDevice($transaction, $deviceId);
            $stored = self::lockedRecord($transaction, $transferId);
            if ($stored !== null) {
                if ($stored['device_id'] !== $deviceId || $stored['source_tenant_id'] !== $tenantId || $stored['target_tenant_id'] !== $targetTenant
                    || (int) $stored['request_version'] !== $version || !self::sameActor($stored['source_context'], $current, $tenantId)) {
                    throw new HttpError(409, 'transfer_identity_conflict');
                }
                return self::project($transaction, $stored, true);
            }
            if ($device === null || $device['tenant_id'] !== $tenantId) {
                throw new HttpError(404, 'device_not_found');
            }
            self::version($device, $version);
            self::available($transaction, $device);
            if ($device['transfer_id'] !== null) {
                throw new HttpError(409, 'transfer_in_progress');
            }
            $model = ProductService::publishedModel($transaction, $tenantId, $device['product_id'], (int) $device['model_version']);
            $record = ['id' => $transferId, 'device_id' => $deviceId, 'device_name' => $device['name'], 'source_tenant_id' => $tenantId,
                'target_tenant_id' => $targetTenant, 'ownership_id' => $device['ownership_id'], 'source_product_id' => $device['product_id'],
                'source_model_version' => (int) $device['model_version'], 'source_definition' => json_encode($model['definition'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'structure_hash' => $model['structure_hash'], 'source_actor_id' => $current->subject(), 'request_version' => $version,
                'source_context' => json_encode(IdentityService::context($current, $tenantId), JSON_THROW_ON_ERROR), 'created_at' => time(), 'status' => 'requested'];
            $transaction->table('iot_transfers')->insert($record);
            $transaction->table('iot_devices')->where('id', '=', $deviceId)->update(['transfer_id' => $transferId, 'version' => (int) $device['version'] + 1]);
            self::audit($transaction, $record, $current, 'transfer.requested', 'success');
            return self::project($transaction, $transaction->table('iot_transfers')->where('id', '=', $transferId)->first(), true);
        });
    }

    /** 目标接受或拒绝、源方取消仅处理未决邀请；原ID与版本重放不会重复复制模型或冻结。 */
    public static function decide(Connection $connection, Identity $identity, string $tenantId, string $transferId, array $decision): array
    {
        $action = $decision['action'] ?? '';
        $decisionId = $decision['decision_id'] ?? '';
        if (!in_array($action, ['accept', 'reject', 'cancel'], true) || !is_string($decisionId) || !preg_match('/^[a-f0-9]{32}$/D', $decisionId)
            || !is_int($decision['version'] ?? null) || $decision['version'] < 1 || $decision['version'] >= 2147483646) {
            throw new HttpError(422, 'transfer_decision_invalid');
        }
        if (array_diff(array_keys($decision), ['action', 'decision_id', 'version', 'target_product_id', 'target_model_version', 'copy_name']) !== []) {
            throw new HttpError(422, 'transfer_decision_invalid');
        }
        $hash = IngestionService::contentHash(json_encode((object) $decision, JSON_THROW_ON_ERROR));
        // 不在等待租户锁之前建立MySQL一致性快照；邀请身份不可改，锁后重验成员与审批事实。
        $invitation = self::visible($connection, $identity, $tenantId, $transferId, $action);
        return self::write($connection, $identity, $tenantId, $action, $transferId, static function (Identity $current, array $permissions) use ($tenantId, $transferId, $decision, $action, $decisionId, $hash, $invitation): array {
            $transaction = \Type\Orm\Db::connection('default', true);
            self::lockTenants($transaction, $invitation['source_tenant_id'], $invitation['target_tenant_id'], $action === 'accept');
            if ($invitation[$action === 'cancel' ? 'source_tenant_id' : 'target_tenant_id'] !== $tenantId) {
                throw new HttpError(403, $action === 'cancel' ? 'transfer_source_required' : 'transfer_target_admin_required');
            }
            $device = self::lockedDevice($transaction, $invitation['device_id']);
            $record = self::lockedRecord($transaction, $transferId);
            if ($record['decision_id'] !== null) {
                if ($record['decision_id'] !== $decisionId || $record['decision_hash'] !== $hash || !self::sameActor($record['decision_context'], $current, $tenantId)) {
                    throw new HttpError(409, 'transfer_decision_conflict');
                }
                return self::project($transaction, $record, true);
            }
            if ($transaction->table('iot_transfers')->where('decision_id', '=', $decisionId)->first() !== null) {
                throw new HttpError(409, 'transfer_decision_conflict');
            }
            if ($record['status'] !== 'requested' || $device === null || $device['transfer_id'] !== $record['id'] || $device['ownership_id'] !== $record['ownership_id'] || $device['tenant_id'] !== $record['source_tenant_id']) {
                throw new HttpError(409, 'transfer_device_changed');
            }
            self::version($device, $decision['version']);
            $change = ['decision_id' => $decisionId, 'decision_hash' => $hash, 'decision_actor_id' => $current->subject(),
                'decision_context' => json_encode(IdentityService::context($current, $tenantId), JSON_THROW_ON_ERROR),
                'decided_at' => time(), 'status' => $action === 'cancel' ? 'cancelled' : 'rejected'];
            if ($action === 'accept') {
                self::available($transaction, $device);
                if ((int) $device['model_version'] !== (int) $record['source_model_version'] || $device['product_id'] !== $record['source_product_id']) {
                    throw new HttpError(409, 'transfer_device_changed');
                }
                $products = new ProductService();
                $copyName = $decision['copy_name'] ?? '';
                $productId = $decision['target_product_id'] ?? '';
                $version = $decision['target_model_version'] ?? 0;
                if (!is_string($copyName) || !is_string($productId) || !is_int($version)
                    || ($copyName !== '' && ($productId !== '' || $version !== 0 || mb_strlen($copyName) > 100))) {
                    throw new HttpError(422, 'transfer_model_invalid');
                }
                if ($copyName !== '') {
                    $product = $products->create($current, $tenantId, trim($copyName), '');
                    if ($product['name'] === '') {
                        throw new HttpError(422, 'transfer_model_invalid');
                    }
                    $model = $products->createModel($current, $tenantId, $product['id'], json_decode($record['source_definition'], true, 32, JSON_THROW_ON_ERROR));
                    $target = $products->changeModel($current, $tenantId, $product['id'], (int) $model['model_version'], 1, 'publish');
                } else {
                    $target = ProductService::publishedModel($transaction, $tenantId, $productId, $version);
                }
                if (!hash_equals($record['structure_hash'], $target['structure_hash'])) {
                    throw new HttpError(409, 'transfer_model_mismatch');
                }
                $change += ['target_product_id' => $target['product_id'], 'target_model_version' => (int) $target['model_version'], 'next_attempt_at' => time()];
                $change['status'] = 'frozen';
            } elseif (array_diff(array_keys($decision), ['action', 'decision_id', 'version']) !== []) {
                throw new HttpError(422, 'transfer_decision_invalid');
            }
            $transaction->table('iot_transfers')->where('id', '=', $record['id'])->update($change);
            $transaction->table('iot_devices')->where('id', '=', $device['id'])->update(['transfer_id' => $action === 'accept' ? $record['id'] : null,
                'transfer_frozen' => $action === 'accept' ? 1 : 0, 'version' => (int) $device['version'] + 1]);
            self::audit($transaction, $record, $current, 'transfer.' . $change['status'], 'success');
            return self::project($transaction, $transaction->table('iot_transfers')->where('id', '=', $record['id'])->first(), true);
        });
    }

    /** 双方各自成员仅查看获邀转移；不开放源租户设备、指令、凭据或历史查询。 */
    public static function listing(Connection $connection, Identity $identity, string $tenantId, int $page, int $perPage, string $direction, string $status, string $deviceId): array
    {
        $context = self::authorize($connection, $identity, $tenantId, 'read');
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100 || !in_array($direction, ['source', 'target'], true)
            || !in_array($status, ['', 'requested', 'rejected', 'cancelled', 'frozen', 'isolating', 'activating', 'completed'], true) || ($deviceId !== '' && !preg_match('/^[a-f0-9]{32}$/D', $deviceId))) {
            throw new HttpError(422, 'transfer_filter_invalid');
        }
        $query = $connection->table('iot_transfers')->where($direction === 'source' ? 'source_tenant_id' : 'target_tenant_id', '=', $tenantId);
        if ($status !== '') {
            $query = $query->where('status', '=', $status);
        }
        if ($deviceId !== '') {
            $query = $query->where('device_id', '=', $deviceId);
        }
        $result = $query->orderBy('created_at', 'DESC')->orderBy('id')->paginate($page, $perPage);
        $items = [];
        foreach ($result->items() as $record) {
            $items[] = self::project($connection, $record, false);
        }
        return ['items' => $items, 'total' => $result->total(), 'page' => $page, 'per_page' => $perPage, 'context' => $context];
    }

    /** 只查询确认和待处理原因；HTTP读取不会联系设备或推进流程。 */
    public static function detail(Connection $connection, Identity $identity, string $tenantId, string $transferId): array
    {
        return self::project($connection, self::visible($connection, $identity, $tenantId, $transferId, 'read'), true);
    }

    /** 任一方管理员明确重发原冻结请求；至少间隔10秒，不解除冻结也不更改目标。 */
    public static function retry(Connection $connection, Identity $identity, string $tenantId, string $transferId, int $version): array
    {
        $invitation = self::visible($connection, $identity, $tenantId, $transferId, 'retry');
        return self::write($connection, $identity, $tenantId, 'retry', $transferId, static function (Identity $current, array $permissions) use ($tenantId, $transferId, $invitation, $version): array {
            $transaction = \Type\Orm\Db::connection('default', true);
            self::lockTenants($transaction, $invitation['source_tenant_id'], $invitation['target_tenant_id']);
            $device = self::lockedDevice($transaction, $invitation['device_id']);
            if ($device === null || $device['transfer_id'] !== $transferId || $device['ownership_id'] !== $invitation['ownership_id']) {
                throw new HttpError(409, 'transfer_device_unavailable');
            }
            self::version($device, $version);
            $record = self::lockedRecord($transaction, $transferId);
            if ($record['status'] !== 'frozen' || ($record['last_attempt_at'] !== null && time() < (int) $record['last_attempt_at'] + 10)) {
                throw new HttpError(409, 'transfer_retry_unavailable');
            }
            $transaction->table('iot_transfers')->where('id', '=', $transferId)->update(['next_attempt_at' => time()]);
            $transaction->table('iot_devices')->where('id', '=', $device['id'])->update(['version' => $version + 1]);
            self::audit($transaction, $record, $current, 'transfer.retried', 'unknown');
            return self::project($transaction, $record, true);
        });
    }

    /**
     * 目标管理员用同一请求ID推进既定目标；先持久撤旧授权，后凭Broker隔离证明原子激活。
     * 原秘密只随首次激活返回；响应丢失重试只返回实际阶段，后续明确轮换沿用设备凭据入口。
     */
    public static function advance(Connection $connection, Identity $identity, string $tenantId, string $transferId, string $switchId, int $version): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $switchId)) {
            throw new HttpError(422, 'transfer_switch_invalid');
        }
        $invitation = self::visible($connection, $identity, $tenantId, $transferId, 'switch');
        return self::write($connection, $identity, $tenantId, 'switch', $transferId, static function (Identity $current, array $permissions) use ($tenantId, $transferId, $switchId, $invitation, $version): array {
            $transaction = \Type\Orm\Db::connection('default', true);
            self::lockTenants($transaction, $invitation['source_tenant_id'], $invitation['target_tenant_id']);
            if ($tenantId !== $invitation['target_tenant_id']) {
                throw new HttpError(403, 'transfer_target_admin_required');
            }
            $device = self::lockedDevice($transaction, $invitation['device_id']);
            $record = self::lockedRecord($transaction, $transferId);
            if ($record['switch_id'] !== null && ($record['switch_id'] !== $switchId || (int) $record['switch_version'] !== $version)) {
                throw new HttpError(409, 'transfer_switch_conflict');
            }
            if (in_array($record['status'], ['activating', 'completed'], true)) {
                return self::project($transaction, $record, true) + ['credential' => null];
            }
            if (!in_array($record['status'], ['frozen', 'isolating'], true)) {
                throw new HttpError(409, 'transfer_prerequisites_pending');
            }
            if ($device === null || $device['transfer_id'] !== $transferId || $device['tenant_id'] !== $record['source_tenant_id']
                || $device['ownership_id'] !== $record['ownership_id'] || (int) $device['transfer_frozen'] !== 1 || $device['lifecycle'] !== 'enabled') {
                throw new HttpError(409, 'transfer_device_changed');
            }
            self::version($device, $record['switch_id'] === null ? $version : $version + 1);
            $target = ProductService::publishedModel($transaction, $tenantId, $record['target_product_id'] ?? '', (int) ($record['target_model_version'] ?? 0));
            if ($target['structure_hash'] !== $record['structure_hash']) {
                throw new HttpError(409, 'transfer_model_mismatch');
            }
            $now = time();
            if ($record['status'] === 'frozen') {
                if (!self::project($transaction, $record, true)['ready_for_switch']) {
                    throw new HttpError(409, 'transfer_prerequisites_pending');
                }
                if ($transaction->table('iot_transfers')->where('switch_id', '=', $switchId)->first() !== null) {
                    throw new HttpError(409, 'transfer_switch_conflict');
                }
                $isolation = DeviceService::invalidateCredential($transaction, $device, $current);
                $transaction->table('iot_transfers')->where('id', '=', $transferId)->update(['status' => 'isolating', 'switch_id' => $switchId,
                    'switch_actor_id' => $current->subject(), 'switch_version' => $version,
                    'switch_context' => json_encode(IdentityService::context($current, $tenantId), JSON_THROW_ON_ERROR),
                    'isolation_id' => $isolation, 'isolation_requested_at' => $now, 'next_attempt_at' => null]);
                $transaction->table('iot_devices')->where('id', '=', $device['id'])->update(['version' => $version + 1, 'updated_at' => $now]);
                self::audit($transaction, $record, $current, 'transfer.isolating', 'unknown');
                return self::project($transaction, $transaction->table('iot_transfers')->where('id', '=', $transferId)->first(), true) + ['credential' => null];
            }
            if ($record['status'] !== 'isolating') {
                throw new HttpError(409, 'transfer_prerequisites_pending');
            }
            $proofQuery = $transaction->table('iot_authorization_invalidations')->where('device_id', '=', $device['id'])->where('id', '=', $record['isolation_id']);
            $proof = ($transaction->driverName() === 'sqlite' ? $proofQuery : $proofQuery->lockForUpdate())->first();
            if ($proof === null || $proof['completed_at'] === null) {
                return self::project($transaction, $record, true) + ['credential' => null];
            }
            // 隔离开始时冻结确认已持久锁定；旧身份已撤销，不能再要求旧连接重新上线或恢复授权。
            $receipt = json_decode($record['device_status'], true, 32, JSON_THROW_ON_ERROR);
            if (!$receipt['supported'] || $receipt['model_pending'] || $receipt['pending_count'] !== 0 || $receipt['pending_command_receipts'] !== 0
                || $receipt['unresolved_commands'] !== 0 || self::unresolved($transaction, $record) > 0) {
                throw new HttpError(409, 'transfer_prerequisites_pending');
            }
            $ownership = bin2hex(random_bytes(16));
            $transaction->table('iot_device_ownerships')->where('id', '=', $record['ownership_id'])->whereNull('ended_at')->update(['ended_at' => $now]);
            $transaction->table('iot_device_ownerships')->insert(['id' => $ownership, 'device_id' => $device['id'], 'tenant_id' => $tenantId, 'started_at' => $now]);
            $change = ['tenant_id' => $tenantId, 'ownership_id' => $ownership, 'product_id' => $record['target_product_id'],
                'model_version' => (int) $record['target_model_version'], 'model_start_sequence' => '0', 'version' => (int) $device['version'] + 1, 'updated_at' => $now];
            $transaction->table('iot_devices')->where('id', '=', $device['id'])->update($change);
            $transaction->table('iot_device_connections')->where('device_id', '=', $device['id'])->update(['ownership_id' => $ownership,
                'status' => 'unknown', 'owner_id' => null, 'node_id' => null, 'run_id' => null, 'observed_at' => null]);
            $transaction->table('iot_current_data')->where('device_id', '=', $device['id'])->delete();
            $transaction->table('iot_device_status')->where('device_id', '=', $device['id'])->delete();
            $credential = DeviceService::transferCredential($transaction, array_replace($device, $change));
            $transaction->table('iot_transfers')->where('id', '=', $transferId)->update(['status' => 'activating', 'new_ownership_id' => $ownership,
                'isolated_at' => (int) $proof['completed_at'], 'activated_at' => $now,
                'switch_actor_id' => $current->subject(), 'switch_context' => json_encode(IdentityService::context($current, $tenantId), JSON_THROW_ON_ERROR)]);
            self::audit($transaction, $record, $current, 'transfer.activated', 'unknown');
            return self::project($transaction, $transaction->table('iot_transfers')->where('id', '=', $transferId)->first(), true) + ['credential' => $credential];
        });
    }

    /** 新凭据与新Topic上的持久设备确认；重复确认重写同步证明nonce，旧阶段载荷不能完成新归属。 */
    public static function complete(Connection $transaction, string $topic, array $data, int $qos, int $receivedAt): array
    {
        if ($transaction->transactionDepth() < 1) {
            throw new \LogicException('transfer_transaction_required');
        }
        $none = ['topic' => '', 'receipt' => null, 'code' => 'invalid_transfer_completion'];
        $fields = ['app_version', 'type', 'device_id', 'ownership_id', 'source_ownership_id', 'transfer_id', 'model_version', 'structure_hash'];
        if (count($data) !== count($fields) || array_diff(array_keys($data), $fields) !== [] || $qos !== 1 || $data['app_version'] !== 1
            || $data['type'] !== 'transfer_activated' || !is_string($data['device_id']) || !is_string($data['transfer_id']) || !is_int($data['model_version'])) {
            return $none;
        }
        $device = self::lockedDevice($transaction, $data['device_id']);
        $record = self::lockedRecord($transaction, $data['transfer_id']);
        if ($device === null || $record === null || !in_array($record['status'], ['activating', 'completed'], true)
            || $record['device_id'] !== $device['id'] || $record['target_tenant_id'] !== $device['tenant_id'] || $device['lifecycle'] !== 'enabled'
            || $record['new_ownership_id'] !== $device['ownership_id'] || $data['ownership_id'] !== $device['ownership_id']
            || $data['source_ownership_id'] !== $record['ownership_id'] || $data['model_version'] !== (int) $record['target_model_version']
            || $data['structure_hash'] !== $record['structure_hash'] || DeviceService::topics($device)['publish'] !== $topic
            || $transaction->table('iot_authorization_invalidations')->where('device_id', '=', $device['id'])->whereNull('completed_at')->first() !== null
            || $transaction->table('iot_device_credentials')->where('device_id', '=', $device['id'])->where('ownership_id', '=', $device['ownership_id'])->where('status', '=', 'active')->first() === null) {
            return $none;
        }
        if ($record['status'] === 'activating') {
            if ($device['transfer_id'] !== $record['id'] || (int) $device['transfer_frozen'] !== 1) {
                return $none;
            }
            $transaction->table('iot_transfers')->where('id', '=', $record['id'])->update(['status' => 'completed', 'completed_at' => $receivedAt]);
            $transaction->table('iot_devices')->where('id', '=', $device['id'])->update(['transfer_id' => null, 'transfer_frozen' => 0, 'version' => (int) $device['version'] + 1, 'updated_at' => $receivedAt]);
            self::audit($transaction, $record, $device['id'], 'transfer.completed', 'success', 'switch_context');
        }
        $transaction->table('iot_transfers')->where('id', '=', $record['id'])->update(['receipt_nonce' => bin2hex(random_bytes(16))]);
        return ['topic' => DeviceService::topics($device)['subscribe'], 'code' => 'accepted', 'receipt' => array_replace($data, ['type' => 'transfer_activated_ack'])];
    }

    /** 同步事务每次领取一个冻结消息，自动60秒内最多三次；离线或超时保留待处理。 */
    public static function claim(Connection $transaction): ?array
    {
        if ($transaction->transactionDepth() < 1) {
            throw new \LogicException('transfer_transaction_required');
        }
        $query = $transaction->table('iot_transfers')->where('next_attempt_at', '<=', time())->orderBy('next_attempt_at')->orderBy('id')->limit(1);
        $record = $query->first();
        if ($record === null) {
            return null;
        }
        $lockedDevice = self::lockedDevice($transaction, $record['device_id']);
        $record = self::lockedRecord($transaction, $record['id']);
        if ($record === null || $record['next_attempt_at'] === null || (int) $record['next_attempt_at'] > time()) {
            return null;
        }
        $now = time();
        $device = DeviceService::observation($transaction, $record['device_id'], $lockedDevice);
        $deadline = (int) $record['decided_at'] + 60;
        $expired = $now >= $deadline && (int) $record['next_attempt_at'] < $deadline;
        if ($record['status'] !== 'frozen' || $device === null || $device['transfer_id'] !== $record['id'] || $device['ownership_id'] !== $record['ownership_id']
            || (int) $device['recovery_verified'] !== 1 || $device['lifecycle'] !== 'enabled' || $device['connection']['status'] !== 'online' || $expired || (int) $record['attempt_count'] >= 100000) {
            $transaction->table('iot_transfers')->where('id', '=', $record['id'])->update(['next_attempt_at' => $now + 10 < $deadline ? $now + 10 : null]);
            return null;
        }
        $attempt = (int) $record['attempt_count'] + 1;
        $next = $now + ($attempt === 1 ? 10 : 20);
        $transaction->table('iot_transfers')->where('id', '=', $record['id'])->update(['last_attempt_at' => $now, 'attempt_count' => $attempt,
            'next_attempt_at' => $attempt < 3 && $next < $deadline ? $next : null]);
        return ['topic' => DeviceService::topics($device)['subscribe'], 'payload' => json_encode(['app_version' => 1, 'type' => 'transfer_freeze',
            'device_id' => $device['id'], 'ownership_id' => $record['ownership_id'], 'transfer_id' => $record['id'], 'source_model_version' => (int) $record['source_model_version'],
            'target_tenant_id' => $record['target_tenant_id'], 'target_product_id' => $record['target_product_id'],
            'target_model_version' => (int) $record['target_model_version'], 'structure_hash' => $record['structure_hash']], JSON_THROW_ON_ERROR)];
    }

    /** 设备持久冻结后的有序状态经同步提交可见；旧状态不回退排空观察，任何ACK均不代表归属已切换。 */
    public static function accept(Connection $transaction, string $topic, array $data, int $qos, int $receivedAt): array
    {
        if ($transaction->transactionDepth() < 1) {
            throw new \LogicException('transfer_transaction_required');
        }
        $none = ['topic' => '', 'receipt' => null, 'code' => 'invalid_transfer_status'];
        $fields = ['app_version', 'type', 'device_id', 'ownership_id', 'transfer_id', 'model_version', 'structure_hash', 'sequence', 'boundary_sequence',
            'supported', 'pending_count', 'pending_command_receipts', 'unresolved_commands', 'model_pending'];
        if ($qos !== 1 || count($data) !== count($fields) || array_diff(array_keys($data), $fields) !== [] || ($data['app_version'] ?? null) !== 1
            || ($data['type'] ?? '') !== 'transfer_status' || !is_string($data['device_id'] ?? null) || !is_string($data['transfer_id'] ?? null)
            || !is_int($data['model_version'] ?? null) || !is_bool($data['supported'] ?? null) || !is_bool($data['model_pending'] ?? null)
            || !is_string($data['sequence'] ?? null) || !preg_match('/^[1-9][0-9]{0,37}$/D', $data['sequence'])
            || !is_string($data['boundary_sequence'] ?? null) || !preg_match('/^(0|[1-9][0-9]{0,37})$/D', $data['boundary_sequence'])
            || DeviceService::sequenceCompare($data['sequence'], $data['boundary_sequence']) <= 0) {
            return $none;
        }
        foreach (['pending_count', 'pending_command_receipts', 'unresolved_commands'] as $field) {
            if (!is_int($data[$field]) || $data[$field] < 0 || $data[$field] > 86400) {
                return $none;
            }
        }
        $device = self::lockedDevice($transaction, $data['device_id']);
        $record = self::lockedRecord($transaction, $data['transfer_id']);
        if ($device === null || $record === null || $record['status'] !== 'frozen' || $device['transfer_id'] !== $record['id']
            || $record['device_id'] !== $device['id'] || $record['source_tenant_id'] !== $device['tenant_id'] || (int) $device['transfer_frozen'] !== 1
            || $device['ownership_id'] !== ($data['ownership_id'] ?? '') || $record['ownership_id'] !== $device['ownership_id']
            || (int) $device['model_version'] !== $data['model_version'] || $record['structure_hash'] !== ($data['structure_hash'] ?? '')
            || DeviceService::topics($device)['publish'] !== $topic || $record['last_attempt_at'] === null || $device['lifecycle'] !== 'enabled') {
            return $none;
        }
        $previous = $record['device_status'] === null ? null : json_decode($record['device_status'], true, 32, JSON_THROW_ON_ERROR);
        if ($previous !== null && $previous['boundary_sequence'] !== $data['boundary_sequence']) {
            return $none;
        }
        if ($previous === null) {
            // 首次确认不能把平台已接收的采样排除在冻结边界外；以后边界固定，普通接收逐条检查。
            $beyond = $transaction->query(
                "SELECT message_id FROM iot_ingestion WHERE tenant_id = ? AND device_id = ? AND ownership_id = ? AND status = 'accepted'"
                . ' AND (LENGTH(sequence) > ? OR (LENGTH(sequence) = ? AND sequence > ?)) LIMIT 1',
                [$device['tenant_id'], $device['id'], $device['ownership_id'], strlen($data['boundary_sequence']), strlen($data['boundary_sequence']), $data['boundary_sequence']]
            );
            if ($beyond !== [] || DeviceService::sequenceCompare($data['boundary_sequence'], $device['model_start_sequence']) < 0) {
                return $none;
            }
            self::audit($transaction, $record, $device['id'], 'transfer.device_frozen', 'success', 'decision_context');
        }
        if ($record['device_status_sequence'] === null || DeviceService::sequenceCompare($data['sequence'], $record['device_status_sequence']) > 0) {
            $transaction->table('iot_transfers')->where('id', '=', $record['id'])->update(['device_status' => json_encode((object) $data, JSON_THROW_ON_ERROR),
                'device_status_sequence' => $data['sequence'], 'device_status_at' => $receivedAt, 'next_attempt_at' => null]);
        } elseif ($record['device_status_sequence'] === $data['sequence']
            && IngestionService::contentHash($record['device_status']) !== IngestionService::contentHash(json_encode((object) $data, JSON_THROW_ON_ERROR))) {
            return $none;
        }
        $transaction->table('iot_transfers')->where('id', '=', $record['id'])->update(['receipt_nonce' => bin2hex(random_bytes(16))]);
        return ['topic' => DeviceService::topics($device)['subscribe'], 'code' => 'accepted', 'receipt' => ['app_version' => 1, 'type' => 'transfer_status_ack',
            'device_id' => $device['id'], 'ownership_id' => $device['ownership_id'], 'transfer_id' => $record['id'], 'sequence' => $data['sequence']]];
    }

    private static function visible(Connection $connection, Identity $identity, string $tenantId, string $id, string $action): array
    {
        self::authorize($connection, $identity, $tenantId, $action);
        $record = $connection->table('iot_transfers')->where('id', '=', $id)->first();
        if ($record === null || ($record['source_tenant_id'] !== $tenantId && $record['target_tenant_id'] !== $tenantId)) {
            throw new HttpError(404, 'transfer_not_found');
        }
        return $record;
    }

    private static function project(Connection $connection, array $record, bool $details): array
    {
        unset($record['decision_hash'], $record['receipt_nonce'], $record['source_context'], $record['decision_context'], $record['switch_context']);
        foreach (['request_version', 'source_model_version', 'target_model_version', 'switch_version', 'created_at', 'decided_at', 'attempt_count',
            'next_attempt_at', 'last_attempt_at', 'device_status_at', 'isolation_requested_at', 'isolated_at', 'activated_at', 'completed_at'] as $field) {
            $record[$field] = $record[$field] === null ? null : (int) $record[$field];
        }
        // 仅正在处理的本次转移暴露可操作设备版本；历史转移不跟踪后续租户的资产变化。
        $version = $details ? $connection->table('iot_devices')->where('id', '=', $record['device_id'])->where('transfer_id', '=', $record['id'])->select(['version'])->first() : null;
        $record['device_version'] = $version === null ? null : (int) $version['version'];
        $record['source_definition'] = $details ? json_decode($record['source_definition'], true, 32, JSON_THROW_ON_ERROR) : null;
        $record['device_status'] = $record['device_status'] === null ? null : json_decode($record['device_status'], true, 32, JSON_THROW_ON_ERROR);
        $record['pending_reasons'] = [];
        $record['ready_for_switch'] = false;
        $record['provisioning'] = $record['new_ownership_id'] === null ? null : ['app_version' => 1, 'type' => 'transfer_provision',
            'device_id' => $record['device_id'], 'transfer_id' => $record['id'], 'source_ownership_id' => $record['ownership_id'],
            'ownership_id' => $record['new_ownership_id'], 'tenant_id' => $record['target_tenant_id'], 'product_id' => $record['target_product_id'],
            'model_version' => (int) $record['target_model_version'], 'structure_hash' => $record['structure_hash']];
        if (!$details || in_array($record['status'], ['rejected', 'cancelled'], true)) {
            return $record;
        }
        $device = DeviceService::observation($connection, $record['device_id']);
        if ($device !== null && (int) $device['recovery_verified'] !== 1) {
            $record['pending_reasons'][] = 'recovery_not_verified';
        }
        if ($record['status'] === 'requested') {
            $record['pending_reasons'][] = 'target_approval_required';
            return $record;
        }
        if ($record['status'] === 'completed') {
            return $record;
        }
        if ($record['status'] === 'activating') {
            $record['pending_reasons'][] = 'new_device_confirmation_required';
            return $record;
        }
        if ($record['status'] === 'isolating') {
            $isolation = $connection->table('iot_authorization_invalidations')->where('id', '=', $record['isolation_id'])->first();
            $record['pending_reasons'][] = $isolation !== null && $isolation['completed_at'] !== null ? 'target_activation_required' : 'old_authorization_isolation_pending';
            return $record;
        }
        $unresolved = (int) $connection->query("SELECT COUNT(*) AS total FROM iot_commands WHERE device_id = ? AND ownership_id = ? AND cancelled_at IS NULL AND dispatch_stopped_at IS NULL AND (result_status IS NULL OR result_status NOT IN ('succeeded', 'failed', 'rejected'))", [$record['device_id'], $record['ownership_id']])[0]['total'];
        $record['expired_uncertain_commands'] = (int) $connection->table('iot_command_uncertainties')->where('device_id', '=', $record['device_id'])->where('ownership_id', '=', $record['ownership_id'])->aggregate('COUNT');
        $record['unresolved_commands'] = $unresolved + $record['expired_uncertain_commands'];
        $receipt = $record['device_status'];
        if ($device === null || $device['ownership_id'] !== $record['ownership_id'] || $device['lifecycle'] !== 'enabled') {
            $record['pending_reasons'][] = 'device_unavailable';
        } elseif ($device['connection']['status'] !== 'online') {
            $record['pending_reasons'][] = 'device_not_online';
        }
        if ($receipt === null) {
            $record['pending_reasons'][] = 'device_confirmation_required';
            $record['pending_reasons'][] = 'cache_state_unknown';
        } else {
            if (time() - (int) $record['device_status_at'] >= 60) {
                $record['pending_reasons'][] = 'device_confirmation_stale';
            }
            if (!$receipt['supported'] || $receipt['model_pending']) {
                $record['pending_reasons'][] = 'device_model_not_ready';
            }
            if ($receipt['pending_count'] !== 0) {
                $record['pending_reasons'][] = 'cache_not_drained';
            }
            if ($receipt['pending_command_receipts'] !== 0 || $receipt['unresolved_commands'] !== 0) {
                $record['pending_reasons'][] = 'device_commands_unresolved';
            }
        }
        if ($record['unresolved_commands'] > 0) {
            $record['pending_reasons'][] = 'commands_unresolved';
        }
        if ($connection->table('iot_authorization_invalidations')->where('device_id', '=', $record['device_id'])->whereNull('completed_at')->first() !== null
            || $connection->table('iot_device_credentials')->where('device_id', '=', $record['device_id'])->where('status', '=', 'active')->where('recovery_verified', '=', 1)->first() === null) {
            $record['pending_reasons'][] = 'authorization_not_ready';
        }
        $record['ready_for_switch'] = $record['pending_reasons'] === [];
        return $record;
    }

    private static function unresolved(Connection $connection, array $record): int
    {
        return (int) $connection->query("SELECT COUNT(*) AS total FROM iot_commands WHERE device_id = ? AND ownership_id = ? AND cancelled_at IS NULL AND dispatch_stopped_at IS NULL AND (result_status IS NULL OR result_status NOT IN ('succeeded', 'failed', 'rejected'))", [$record['device_id'], $record['ownership_id']])[0]['total']
            + (int) $connection->table('iot_command_uncertainties')->where('device_id', '=', $record['device_id'])->where('ownership_id', '=', $record['ownership_id'])->aggregate('COUNT');
    }

    private static function available(Connection $connection, array $device): void
    {
        if ((int) $device['recovery_verified'] !== 1 || $device['lifecycle'] !== 'enabled' || $device['model_switch_id'] !== null
            || $connection->table('iot_device_credentials')->where('device_id', '=', $device['id'])->where('status', '=', 'active')->where('recovery_verified', '=', 1)->first() === null
            || $connection->table('iot_authorization_invalidations')->where('device_id', '=', $device['id'])->whereNull('completed_at')->first() !== null) {
            throw new HttpError(409, 'transfer_device_unavailable');
        }
    }

    private static function lockedDevice(Connection $connection, string $id): ?array
    {
        $query = $connection->table('iot_devices')->where('id', '=', $id)->where('recovery_verified', '=', 1);
        return ($connection->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
    }

    private static function lockTenants(Connection $connection, string $source, string $target, bool $requireEnabled = true): void
    {
        $ids = [$source, $target];
        sort($ids, SORT_STRING);
        foreach ($ids as $id) {
            $query = $connection->table('iot_tenants')->where('id', '=', $id);
            $tenant = ($connection->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
            if ($tenant === null || ($requireEnabled && (int) $tenant['enabled'] !== 1)) {
                throw new HttpError(422, 'transfer_target_invalid');
            }
        }
    }

    private static function audit(Connection $connection, array $record, string|Identity $actor, string $action, string $result, string $source = ''): void
    {
        $details = ['device_id' => $record['device_id'], 'ownership_id' => $record['ownership_id'], 'context' => 'device-transfer'];
        if ($source !== '') {
            // 设备回执继续原已受理效果，使用冻结人员来源，不重新授权已提交动作。
            $context = json_decode($record[$source], true, 8, JSON_THROW_ON_ERROR);
            $details += ['actor_realm' => $context['actor_realm'], 'customer_id' => $context['customer_id'], 'session_id' => $context['session_id'],
                'source_session_id' => $context['source_session_id'], 'impersonation_id' => $context['impersonation_id'], 'scope_key' => $context['key']];
            $actor = $context['actor_id'];
            if ($context['impersonation_id'] !== '') {
                $details['context'] = 'customer-impersonation';
            }
        }
        foreach ([$record['source_tenant_id'], $record['target_tenant_id']] as $tenant) {
            AuditLog::append(
                $connection,
                $tenant,
                $actor,
                $action,
                $record['id'],
                $result,
                $details,
                'customer'
            );
        }
    }

    /**
     * 同一授权事务与原业务共享连接；独立写节点不隐式要求转移查询节点。
     * @param Closure(Identity, list<string>): array<string, mixed> $operation 锁后重验身份的原业务。
     */
    private static function write(Connection $connection, Identity $identity, string $tenantId, string $action, string $id, Closure $operation): array
    {
        try {
            return RoleService::authorized($identity, null, 'customer.transfers.' . $action, $operation, 'customer', $tenantId);
        } catch (HttpError $failure) {
            AuditLog::append(
                $connection,
                $tenantId,
                $identity,
                'transfer.' . $action,
                $id,
                in_array($failure->status(), [401, 403, 404], true) ? 'denied' : 'failed',
                ['reason' => $failure->errorCode(), 'context' => 'device-transfer'],
                'customer'
            );
            throw $failure;
        }
    }

    private static function authorize(Connection $connection, Identity $identity, string $tenantId, string $action): array
    {
        $current = (new IdentityService('customer'))->refresh($identity);
        if ($current === null || $current->subject() !== $identity->subject()) {
            throw new HttpError(401, 'unauthenticated');
        }
        $permissions = RoleService::permissions($current, 'customer', $tenantId);
        if (!in_array('customer.transfers.' . $action, $permissions, true)) {
            throw new HttpError(403, 'permission_denied');
        }
        return ['permissions' => $permissions, 'menus' => RoleService::menus('customer', $permissions), 'identity' => IdentityService::context($current, $tenantId)];
    }

    private static function lockedRecord(Connection $connection, string $id): ?array
    {
        $query = $connection->table('iot_transfers')->where('id', '=', $id);
        return ($connection->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
    }

    private static function version(array $device, int $version): void
    {
        if ($version < 1 || $version >= 2147483646 || (int) $device['version'] !== $version) {
            throw new HttpError(409, 'stale_version');
        }
    }

    private static function sameActor(string $stored, Identity $identity, string $tenantId): bool
    {
        $source = json_decode($stored, true, 8, JSON_THROW_ON_ERROR);
        $current = IdentityService::context($identity, $tenantId);
        // 再次登录仍可核对原请求；原会话来源保持在记录中，其他真实人员不能冒领审批。
        return $source['actor_realm'] === $current['actor_realm'] && $source['actor_id'] === $current['actor_id']
            && $source['customer_id'] === $current['customer_id'] && $source['tenant_id'] === $tenantId;
    }
}
