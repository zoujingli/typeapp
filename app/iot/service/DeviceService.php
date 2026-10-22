<?php

declare(strict_types=1);

namespace app\iot\service;

use app\broker\service\AccessService;
use app\broker\service\DebugService;
use app\broker\service\QuotaService;
use app\broker\service\RuntimeService;
use app\broker\service\ResourceObservations;
use app\common\service\AuditLog;
use app\common\service\IdentityService;
use app\common\service\RoleService;
use app\common\model\Tenant;
use app\iot\model\Device;
use app\iot\model\DeviceEnrollment;
use Closure;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Orm\Connection;
use Type\Orm\Query;
use Type\Orm\Db;
use Type\Runtime\ExecutionScope;

/** 设备注册、当前使用关系与接入凭据的持久所有者；接入只提供当前关系的原始通信。 */
final class DeviceService
{
    /**
     * 注册证明先经过有界尝试预算，资格、设备、当前关系、凭据和绑定在同一授权事务内提交。
     * 幂等重试只返回原关系；一次性秘密丢失后由有权人员走既有 rotate 入口恢复。
     * @param array{id:string,tenant_id:string,device_id:string,name:string,code:string} $data 已校验的注册声明。
     * @param string $clientIp 已由 RequestPolicy 解析的真实来源，不能取未经验证的转发头。
     * @return array{device:array<string,mixed>,credential:?array<string,mixed>,replayed:bool}
     * @throws HttpError 证明无效、资格不可用、请求冲突、设备已注册或尝试预算耗尽。
     */
    public function enroll(array $data, string $clientIp): array
    {
        $this->registrationAttempt($clientIp);
        $connection = Db::connection('default', true);
        try {
            return Db::transaction(static function () use ($data): array {
                RoleService::lockAuthorization();
                $tenant = Tenant::query()->master()->find($data['tenant_id']);
                if ($tenant === null || !$tenant->getEnabled()) {
                    throw new HttpError(401, 'registration_credentials_invalid');
                }
                return ExecutionScope::current()->run(static function (ExecutionScope $scope) use ($data): array {
                    [$id, $secret] = explode('.', $data['code'], 2);
                    $grant = DeviceEnrollment::query()->master()->find($id);
                    if ($grant === null || !hash_equals($grant->getSecretHash(), hash('sha256', $secret))) {
                        throw new HttpError(401, 'registration_credentials_invalid');
                    }
                    if (!$grant->getRecoveryVerified()) {
                        throw new HttpError(409, 'recovery_reconciliation_required');
                    }
                    $transaction = Db::connection('default', true);
                    $requestHash = hash('sha256', json_encode([$data['tenant_id'], $data['device_id'], $data['name'], $id], JSON_THROW_ON_ERROR));
                    $previous = $transaction->table('iot_device_enrollment_bindings')->where('request_id', '=', $data['id'])->first();
                    if ($previous !== null) {
                        if ($previous['enrollment_id'] !== $id || !hash_equals($previous['request_hash'], $requestHash)) {
                            throw new HttpError(409, 'registration_request_conflict');
                        }
                        $existing = Device::query()->master()->find($data['device_id']);
                        if ($existing === null || $existing->getOwnershipId() !== $previous['ownership_id']) {
                            throw new HttpError(409, 'registration_ownership_changed');
                        }
                        return ['device' => self::project($transaction, $existing->getId(), 'customer'), 'credential' => null, 'replayed' => true];
                    }
                    // 全球稳定设备 ID 不因客户变化重复创建；跨客户只返回冲突，不暴露原归属。
                    if ($transaction->table('iot_devices')->where('id', '=', $data['device_id'])->first() !== null
                        || $transaction->table('broker_access_principals')->where('id', '=', $data['device_id'])->first() !== null) {
                        throw new HttpError(409, 'device_already_registered');
                    }
                    if (!$grant->getEnabled() || $grant->getRegisterExpiresAt() <= time() || $grant->getRegisteredCount() >= $grant->getCapacity()) {
                        throw new HttpError(409, 'enrollment_unavailable');
                    }
                    if ($grant->getVersion() >= 2147483646) {
                        throw new HttpError(409, 'stale_version');
                    }
                    $device = Device::create(['id' => $data['device_id'], 'product_id' => null, 'model_version' => null,
                        'ownership_id' => bin2hex(random_bytes(16)), 'name' => $data['name'], 'lifecycle' => 'inactive',
                        'model_switch_id' => null, 'model_start_sequence' => '0', 'transfer_id' => null, 'transfer_frozen' => false, 'recovery_verified' => true]);
                    $now = $device->getCreatedAt();
                    $transaction->table('iot_device_ownerships')->insert(['id' => $device->getOwnershipId(), 'device_id' => $device->getId(), 'tenant_id' => $data['tenant_id'], 'started_at' => $now, 'access_started' => 0]);
                    $transaction->table('iot_device_connections')->insert(['device_id' => $device->getId(), 'ownership_id' => $device->getOwnershipId(), 'status' => 'unknown']);
                    $credentialId = bin2hex(random_bytes(16));
                    $credentialSecret = bin2hex(random_bytes(32));
                    $transaction->table('iot_device_credentials')->insert(['id' => $credentialId, 'device_id' => $device->getId(), 'ownership_id' => $device->getOwnershipId(),
                        'secret_hash' => hash('sha256', $credentialSecret), 'status' => 'active', 'created_at' => $now, 'revoked_at' => null]);
                    $transaction->table('iot_device_enrollment_bindings')->insert(['ownership_id' => $device->getOwnershipId(), 'device_id' => $device->getId(),
                        'enrollment_id' => $id, 'registered_at' => $now, 'request_id' => $data['id'], 'request_hash' => $requestHash]);
                    $grant->setRegisteredCount($grant->getRegisteredCount() + 1);
                    $grant->save();
                    AuditLog::append($transaction, $data['tenant_id'], $device->getId(), 'device.enrolled', $device->getId(), 'success', ['context' => 'device-registration', 'ownership_id' => $device->getOwnershipId(), 'version' => 1], 'customer');
                    AuditLog::append($transaction, $data['tenant_id'], $device->getId(), 'enrollment.consumed', $id, 'success', ['context' => 'device-registration', 'device_id' => $device->getId(), 'version' => $grant->getVersion()], 'customer');
                    return ['device' => self::project($transaction, $device->getId(), 'customer'),
                        'credential' => self::credential($device->project(['id', 'tenant_id', 'ownership_id', 'model_version']), $credentialId, $credentialSecret), 'replayed' => false];
                }, ['tenant_id' => $data['tenant_id']]);
            }, 'default', $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
        } catch (HttpError $failure) {
            AuditLog::append($connection, null, 'registration', 'device.registration_denied', $data['device_id'], 'denied', ['context' => 'device-registration', 'reason' => $failure->errorCode()], 'customer');
            throw $failure;
        }
    }

    /** 每来源每分钟最多 60 次有效形状的尝试，最多保留 1024 个活动来源；失败不会回滚尝试计数。 */
    private function registrationAttempt(string $clientIp): void
    {
        if (filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
            throw new HttpError(400, 'invalid_peer');
        }
        $connection = Db::connection('default', true);
        $allowed = Db::transaction(static function () use ($clientIp): bool {
            RoleService::lockAuthorization();
            $transaction = Db::connection('default', true);
            $now = time();
            $transaction->table('iot_registration_attempts')->where('window_started', '<=', $now - 60)->delete();
            $source = hash('sha256', $clientIp);
            $query = $transaction->table('iot_registration_attempts')->where('source_hash', '=', $source);
            $previous = $query->first();
            if ($previous !== null) {
                if ((int) $previous['attempts'] >= 60) {
                    return false;
                }
                $query->update(['attempts' => (int) $previous['attempts'] + 1]);
                return true;
            }
            if ((int) $transaction->table('iot_registration_attempts')->aggregate('COUNT') >= 1024) {
                return false;
            }
            $transaction->table('iot_registration_attempts')->insert(['source_hash' => $source, 'window_started' => $now, 'attempts' => 1]);
            return true;
        }, 'default', $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
        if (!$allowed) {
            throw new HttpError(429, 'registration_rate_limited');
        }
    }

    /**
     * 客户只查询所选租户，平台查询全局资产；凭据验证值不进入任何投影。
     * @param array{name?: string, product_id?: string, lifecycle?: string, tenant_id?: string} $filters 公开筛选。
     * @return array<string, mixed> 有界稳定列表和本次权限；平台权限不含客户业务数据。
     */
    public function devices(Connection $connection, Identity $identity, string $tenantId, int $page, int $perPage, array $filters, string $realm = 'customer'): array
    {
        return $this->run($connection, $identity, $tenantId, $realm, 'read', 'device.listed', $tenantId, false, static function (Connection $transaction, Identity $current, array $context) use ($tenantId, $page, $perPage, $filters, $realm): array {
            return self::observations($transaction, $realm === 'customer' ? $tenantId : '', $page, $perPage, $filters) + ['context' => $context];
        });
    }

    /**
     * 设备列表和运行观察共用归属、连接过期及授权进度投影；调用者必须先完成本入口权限核对。
     * @internal 空tenantId只供平台资产入口使用，租户运行入口必须传入当前已授权租户。
     * @param array<string, mixed> $filters 已校验的name、product_id、lifecycle及平台tenant_id。
     * @return array<string, mixed> 稳定分页，不包含凭据或业务消息载荷。
     */
    public static function observations(Connection $connection, string $tenantId, int $page, int $perPage, array $filters): array
    {
        $rows = self::observedQuery($connection);
        $scope = $tenantId !== '' ? $tenantId : ($filters['tenant_id'] ?? '');
        if ($scope !== '') {
            $rows = $rows->where('d.tenant_id', '=', $scope);
        }
        if (($filters['name'] ?? '') !== '') {
            $rows = $rows->where('d.name', 'LIKE', '%' . $filters['name'] . '%');
        }
        foreach (['product_id', 'lifecycle'] as $field) {
            if (($filters[$field] ?? '') !== '') {
                $rows = $rows->where('d.' . $field, '=', $filters[$field]);
            }
        }
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) {
            throw new HttpError(422, 'invalid_pagination');
        }
        // 关联均为主键一对一，唯一设备id收尾保持稳定；paginate只接受单表查询。
        $total = (int) $rows->aggregate('COUNT', 'd.id');
        $items = $rows->orderBy('d.created_at', 'DESC')->orderBy('d.id')->limit($perPage, ($page - 1) * $perPage)->get();
        foreach ($items as $index => $item) {
            $items[$index] = self::observed($item);
        }
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * 客户可读当前关系的公开 Topic；平台取得资产、接入状态与可核对的升级来源。
     * @return array<string, mixed> 精确目标的只读投影，不返回秘密或验证值。
     * @throws HttpError 身份无权查看或目标不在所选范围。
     */
    public function device(Connection $connection, Identity $identity, string $tenantId, string $deviceId, string $realm = 'customer'): array
    {
        return $this->run($connection, $identity, $tenantId, $realm, 'read', 'device.viewed', $deviceId, false, static function (Connection $transaction, Identity $current, array $context) use ($tenantId, $deviceId, $realm): array {
            $query = $transaction->table('iot_devices')->where('id', '=', $deviceId);
            if (($realm === 'customer' ? $query->where('tenant_id', '=', $tenantId) : $query)->first() === null) {
                throw new HttpError(404, 'device_not_found');
            }
            return self::project($transaction, $deviceId, $realm);
        });
    }

    /** 调用者已授权并核对准确目标；写结果不再次依赖查询权限，不能造成隐式权限捆绑。 */
    private static function project(Connection $connection, string $deviceId, string $realm): array
    {
        $device = self::observedQuery($connection)->where('d.id', '=', $deviceId)->first();
        if ($device === null) {
            throw new HttpError(404, 'device_not_found');
        }
        $active = $connection->table('iot_device_credentials')->where('device_id', '=', $deviceId)->where('ownership_id', '=', $device['ownership_id'])
            ->where('status', '=', 'active')->where('recovery_verified', '=', 1)->first();
        $result = self::observed($device) + ['credential_active' => $active !== null];
        $result['upgrade'] = DeviceUpgrade::device($connection, $deviceId);
        $ownership = $connection->table('iot_device_ownerships')->where('id', '=', $device['ownership_id'])->first();
        $result['usage'] = $ownership === null ? null : ['started_at' => (int) $ownership['started_at'],
            'ended_at' => $ownership['ended_at'] === null ? null : (int) $ownership['ended_at'],
            'expires_at' => $ownership['expires_at'] === null ? null : (int) $ownership['expires_at'],
            'available' => TransferService::ownershipCurrent($connection, $device)];
        return $realm === 'admin' ? $result : $result + ['topics' => self::topics($device)];
    }

    /**
     * 复用既有授权锁、来源复核与事务，不创建另一套设备角色；realm只能由登记的入口选择。
     * @param Closure(Connection, Identity, array<string, mixed>): array<string, mixed> $operation 同连接业务及审计。
     */
    private function run(Connection $connection, Identity $identity, string $tenantId, string $realm, string $permission, string $action, string $subjectId, bool $write, Closure $operation): array
    {
        if (!in_array($realm, ['admin', 'customer'], true) || ($realm === 'admin' ? $tenantId !== '' : preg_match('/^[a-f0-9]{32}$/D', $tenantId) !== 1)) {
            throw new HttpError(403, 'identity_scope_forbidden');
        }
        $node = $realm . '.devices.' . $permission;
        try {
            if (!$write) {
                $current = (new IdentityService($realm))->refresh($identity);
                if ($current === null || $current->subject() !== $identity->subject()) {
                    throw new HttpError(401, 'unauthenticated');
                }
                $permissions = RoleService::permissions($current, $realm, $tenantId);
                if (!in_array($node, $permissions, true)) {
                    throw new HttpError(403, 'permission_denied');
                }
                return $operation($connection, $current, ['permissions' => $permissions, 'menus' => RoleService::menus($realm, $permissions), 'identity' => IdentityService::context($current, $tenantId)]);
            }
            return RoleService::authorized($identity, null, $node, static function (Identity $current, array $permissions) use ($operation, $realm, $tenantId): array {
                $transaction = \Type\Orm\Db::connection('default', true);
                return $operation($transaction, $current, ['permissions' => $permissions, 'menus' => RoleService::menus($realm, $permissions), 'identity' => IdentityService::context($current, $tenantId)]);
            }, $realm, $tenantId);
        } catch (HttpError $failure) {
            $member = $realm === 'customer' ? $connection->table('customer_members')->where('tenant_id', '=', $tenantId)->where('user_id', '=', $identity->subject())->first() : null;
            AuditLog::append(
                $connection,
                $member === null ? null : $tenantId,
                $identity,
                $action,
                $subjectId,
                in_array($failure->status(), [401, 403, 404], true) ? 'denied' : 'failed',
                ['context' => 'device', 'reason' => $failure->errorCode()],
                $realm
            );
            throw $failure;
        }
    }

    /**
     * 管理动作在授权和设备锁内重验独立节点、版本及危险确认；平台身份不伪造成租户成员。
     * 禁用撤销旧凭据，重新启用须另行生成新凭据。退役是终态，历史及原归属不删除。
     * @param array{name?:string,product_id?:?string} $data 资料入口已校验的名称和分类；分类缺省保持、null 清除，其他动作忽略。
     * @return array{device:array<string,mixed>,credential:array<string,mixed>|null} authorization.pending须等Broker完成，不能当作网络已隔离。
     * @throws HttpError 无权限、确认不符、版本冲突、退役终态或前次撤权尚未完成。
     */
    public function change(Connection $connection, Identity $identity, string $tenantId, string $deviceId, string $action, int $version, string $confirmation, string $realm = 'customer', array $data = []): array
    {
        if (!in_array($action, ['update', 'rotate', 'revoke', 'disable', 'enable', 'retire'], true)) {
            throw new HttpError(422, 'device_action_invalid');
        }
        return $this->run($connection, $identity, $tenantId, $realm, $action, 'device.' . $action, $deviceId, true, static function (Connection $transaction, Identity $current, array $context) use ($tenantId, $deviceId, $action, $version, $confirmation, $realm, $data): array {
            $lookup = $transaction->table('iot_devices')->where('id', '=', $deviceId);
            if ($realm === 'customer') {
                $lookup = $lookup->where('tenant_id', '=', $tenantId);
            }
            // 锁定读取得等待后真实版本，尤其不能沿用MySQL授权查询建立的旧快照。
            $device = ($transaction->driverName() === 'sqlite' ? $lookup : $lookup->lockForUpdate())->first();
            if ($device === null) {
                throw new HttpError(404, 'device_not_found');
            }
            if ((int) $device['recovery_verified'] !== 1) {
                throw new HttpError(409, 'recovery_reconciliation_required');
            }
            if ($action !== 'update' && $confirmation !== $deviceId) {
                throw new HttpError(422, 'device_confirmation_required');
            }
            if ($version !== (int) $device['version'] || $version >= 2147483646) {
                throw new HttpError(409, 'stale_version');
            }
            if ($device['lifecycle'] === 'retired') {
                throw new HttpError(409, 'device_retired');
            }
            if (in_array($action, ['enable', 'rotate'], true) && !TransferService::ownershipCurrent($transaction, $device)) {
                throw new HttpError(409, 'device_ownership_unavailable');
            }
            if ($device['transfer_id'] !== null && ($action !== 'update' || array_key_exists('product_id', $data))) {
                throw new HttpError(409, 'reassignment_in_progress');
            }
            $pending = $transaction->table('iot_authorization_invalidations')->where('device_id', '=', $deviceId);
            $previous = ($transaction->driverName() === 'sqlite' ? $pending : $pending->lockForUpdate())->first();
            if ($previous !== null && $previous['completed_at'] === null) {
                throw new HttpError(409, 'device_authorization_pending');
            }
            if (($action === 'enable' && $device['lifecycle'] !== 'disabled') || ($action === 'disable' && $device['lifecycle'] === 'disabled')) {
                throw new HttpError(409, 'device_lifecycle_conflict');
            }
            $credentialQuery = $transaction->table('iot_device_credentials')->where('device_id', '=', $deviceId)->where('status', '=', 'active')->limit(2);
            $credentials = ($transaction->driverName() === 'sqlite' ? $credentialQuery : $credentialQuery->lockForUpdate())->get();
            if (count($credentials) > 1) {
                throw new HttpError(409, 'device_credential_conflict');
            }
            if ($action === 'revoke' && $credentials === []) {
                throw new HttpError(409, 'device_credential_revoked');
            }
            $now = time();
            if (!in_array($action, ['enable', 'update'], true) && $credentials !== []) {
                self::invalidateCredential($transaction, $device, $current);
            }
            $lifecycle = match ($action) {
                'disable' => 'disabled', 'enable' => 'enabled', 'retire' => 'retired', default => $device['lifecycle']
            };
            $changes = ['lifecycle' => $lifecycle, 'version' => $version + 1, 'updated_at' => $now];
            if ($action === 'update') {
                $changes['name'] = $data['name'];
                if (array_key_exists('product_id', $data)) {
                    if ($data['product_id'] !== null) {
                        $product = ExecutionScope::current()->run(
                            static fn (ExecutionScope $scope): ?\app\iot\model\Product => \app\iot\model\Product::query()->master()->find($data['product_id']),
                            ['tenant_id' => $device['tenant_id']]
                        );
                        if ($product === null) {
                            throw new HttpError(404, 'product_not_found');
                        }
                    }
                    $changes['product_id'] = $data['product_id'];
                }
            }
            $lookup->update($changes);
            $credential = null;
            if ($action === 'rotate') {
                $credentialId = bin2hex(random_bytes(16));
                $secret = bin2hex(random_bytes(32));
                $transaction->table('iot_device_credentials')->insert(['id' => $credentialId, 'device_id' => $deviceId, 'ownership_id' => $device['ownership_id'],
                    'secret_hash' => hash('sha256', $secret), 'status' => 'active', 'created_at' => $now, 'revoked_at' => null]);
                $credential = self::credential($device, $credentialId, $secret);
            }
            AuditLog::append($transaction, $device['tenant_id'], $current, 'device.' . $action, $deviceId, 'success', ['version' => $version + 1, 'context' => 'device'], $realm);
            return ['device' => self::project($transaction, $deviceId, $realm), 'credential' => $credential];
        });
    }

    /** 一次性公开连接契约同时用于注册和轮换，其他查询永远不调用此方法。 */
    private static function credential(array $device, string $id, string $secret): array
    {
        return ['id' => $id, 'username' => $device['id'] . ':' . $id, 'password' => $secret, 'client_id' => $device['id'],
            'protocol_version' => 5, 'tls_required' => true, 'keep_alive' => 30, 'session_expiry' => 86400, 'topics' => self::topics($device)];
    }

    /** 调用者持有设备锁且已授权；冻结真实来源，退出或撤权不撤回已经提交的隔离意图。 */
    public static function invalidateCredential(Connection $transaction, array $device, Identity|array $actor, bool $allowRevoked = false): string
    {
        if ($transaction->transactionDepth() < 1) {
            throw new \LogicException('device_transaction_required');
        }
        $query = $transaction->table('iot_authorization_invalidations')->where('device_id', '=', $device['id']);
        $previous = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
        $credentialQuery = $transaction->table('iot_device_credentials')->where('device_id', '=', $device['id'])->where('status', '=', 'active')->limit(2);
        $credentials = ($transaction->driverName() === 'sqlite' ? $credentialQuery : $credentialQuery->lockForUpdate())->get();
        if ($credentials === [] && $allowRevoked) {
            $credentialQuery = $transaction->table('iot_device_credentials')->where('device_id', '=', $device['id'])->where('ownership_id', '=', $device['ownership_id'])
                ->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')->limit(1);
            $credentials = ($transaction->driverName() === 'sqlite' ? $credentialQuery : $credentialQuery->lockForUpdate())->get();
        }
        if (($previous !== null && $previous['completed_at'] === null) || count($credentials) !== 1 || $credentials[0]['ownership_id'] !== $device['ownership_id']) {
            throw new HttpError(409, 'device_authorization_pending');
        }
        $old = $credentials[0];
        $context = $actor instanceof Identity ? IdentityService::context($actor, $device['tenant_id']) : $actor;
        if (!in_array($context['realm'] ?? '', ['admin', 'customer'], true)) {
            throw new HttpError(403, 'identity_context_invalid');
        }
        $now = time();
        $transaction->table('iot_device_credentials')->where('id', '=', $old['id'])->update(['status' => 'revoked', 'revoked_at' => $now]);
        $intent = ['device_id' => $device['id'], 'id' => bin2hex(random_bytes(16)), 'principal' => $device['id'] . ':' . $old['id'],
            'actor_id' => $context['actor_id'], 'tenant_id' => $device['tenant_id'], 'actor_realm' => $context['realm'],
            'context_json' => json_encode(['actor_realm' => $context['actor_realm'], 'customer_id' => $context['customer_id'], 'session_id' => $context['session_id'],
                'source_session_id' => $context['source_session_id'], 'impersonation_id' => $context['impersonation_id'], 'scope_key' => $context['key'],
                'context' => $context['impersonation_id'] === '' ? 'device' : 'customer-impersonation'], JSON_THROW_ON_ERROR),
            'requested_at' => $now, 'completed_at' => null, 'node_id' => null];
        if ($previous === null) {
            $transaction->table('iot_authorization_invalidations')->insert($intent);
        } else {
            $transaction->table('iot_authorization_invalidations')->where('device_id', '=', $device['id'])->update($intent);
        }
        return $intent['id'];
    }

    /** 仅在旧主体隔离证明和新归属同事务激活时生成一次秘密；查询或重复请求不得调用。 */
    public static function transferCredential(Connection $transaction, array $device): array
    {
        if ($transaction->transactionDepth() < 1) {
            throw new \LogicException('device_transaction_required');
        }
        if ($transaction->table('iot_device_credentials')->where('device_id', '=', $device['id'])->where('status', '=', 'active')->first() !== null
            || $transaction->table('iot_authorization_invalidations')->where('device_id', '=', $device['id'])->whereNull('completed_at')->first() !== null) {
            throw new HttpError(409, 'device_authorization_pending');
        }
        $id = bin2hex(random_bytes(16));
        $secret = bin2hex(random_bytes(32));
        $transaction->table('iot_device_credentials')->insert(['id' => $id, 'device_id' => $device['id'], 'ownership_id' => $device['ownership_id'],
            'secret_hash' => hash('sha256', $secret), 'status' => 'active', 'created_at' => time(), 'revoked_at' => null]);
        return self::credential($device, $id, $secret);
    }

    /**
     * 接入工作进程在短作用域中执行；输入由 DeviceAccess 从已解析的网络事实形成。
     * 不开放自注册或人员管理操作；秘密验证值只参与比较，不进入审计或返回值。
     * @param array<string,mixed> $request 有界的身份、动作、连接所有者及观察时间。
     * @return array{allowed: bool,invalidation?:array<string,string>|null} 认证、授权、失效意图或观察结果，不是业务持久接收回执。
     */
    public static function access(Connection $connection, array $request): array
    {
        if (in_array($request['action'], ['authenticate', 'authenticate_certificate'], true)) {
            // 与结束关系共用授权锁，先记录可能建立持久会话，再允许 Broker 接受身份。
            return $connection->transaction(static function (Connection $transaction) use ($request): array {
                RoleService::lockAuthorization();
                $result = self::accessState($transaction, $request);
                if ($result['allowed']) {
                    $transaction->table('iot_device_ownerships')->where('device_id', '=', $request['client_id'])->whereNull('ended_at')->update(['access_started' => 1]);
                }
                return $result;
            }, $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default');
        }
        return self::accessState($connection, $request);
    }

    /** 仅由 access 在所需的身份写锁内调用；普通授权不改变关系期限或结束状态。 */
    private static function accessState(Connection $connection, array $request): array
    {
        $action = $request['action'];
        if ($action === 'invalidation_next') {
            TransferService::expireOwnership($connection);
            $ca = getenv('IOT_MQTT_CLIENT_CA');
            if (is_string($ca) && $ca !== '') {
                AccessService::syncHandshakeCa($connection, $ca);
            }
            $broker = AccessService::nextInvalidation($connection);
            if ($broker !== null) {
                return ['allowed' => true, 'invalidation' => $broker];
            }
            $pending = $connection->query('SELECT id, device_id, principal, actor_id FROM iot_authorization_invalidations WHERE completed_at IS NULL ORDER BY requested_at, id LIMIT 1');
            if ($pending === []) {
                return ['allowed' => true, 'invalidation' => null];
            }
            $oldCredential = $connection->table('iot_device_credentials')->where('device_id', '=', $pending[0]['device_id'])
                ->where('id', '=', substr($pending[0]['principal'], 33))->first();
            if ($oldCredential === null || $pending[0]['principal'] !== $oldCredential['device_id'] . ':' . $oldCredential['id']) {
                throw new \RuntimeException('device_invalidation_identity_missing');
            }
            return ['allowed' => true, 'invalidation' => ['id' => $pending[0]['id'], 'client_id' => $pending[0]['device_id'],
                'principal' => $pending[0]['principal'], 'actor' => $pending[0]['actor_id'],
                'access_identity' => (new \Type\Mqtt\AccessIdentity('device:' . $oldCredential['device_id'], $oldCredential['id'], 1))->data()]];
        }
        if ($action === 'invalidation_completed') {
            if (preg_match('/^[a-f0-9]{32}$/D', $request['invalidation_id'] ?? '') !== 1) {
                throw new \RuntimeException('device_invalidation_invalid');
            }
            if ($connection->table('broker_access_invalidations')->where('id', '=', $request['invalidation_id'])->first() !== null) {
                AccessService::invalidationCompleted($connection, $request['invalidation_id'], (string) $request['node_id']);
                return ['allowed' => true];
            }
            $connection->transaction(static function (Connection $transaction) use ($request): void {
                $query = $transaction->table('iot_authorization_invalidations')->where('id', '=', $request['invalidation_id']);
                $intent = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
                if ($intent !== null && $intent['completed_at'] === null) {
                    $query->update(['completed_at' => time(), 'node_id' => $request['node_id']]);
                    AuditLog::append(
                        $transaction,
                        $intent['tenant_id'],
                        $intent['actor_id'],
                        'device.authorization_completed',
                        $intent['device_id'],
                        'success',
                        json_decode($intent['context_json'], true, 4, JSON_THROW_ON_ERROR),
                        $intent['actor_realm']
                    );
                }
            }, $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default');
            return ['allowed' => true];
        }
        if (in_array($action, ['heartbeat', 'stopped'], true)) {
            $connection->transaction(static function (Connection $transaction) use ($request): void {
                self::observeBroker($transaction, $request);
                ResourceObservations::heartbeat(
                    $transaction,
                    $request['node_id'],
                    $request['run_id'],
                    (int) $request['observed_at'],
                    (bool) $request['initial'],
                    $request['action'] === 'stopped'
                );
            });
            try {
                DebugService::touch($connection, (string) $request['node_id']);
            } catch (\Throwable) {
            }
            return ['allowed' => true];
        }
        /** 证书 CONNECT：指纹须已绑定同一设备、恢复核验通过，并满足设备连接契约。 */
        if ($action === 'authenticate_certificate') {
            if (preg_match('/^[a-f0-9]{64}$/D', (string) ($request['fingerprint'] ?? '')) !== 1
                || preg_match('/^[a-f0-9]{32}$/D', (string) ($request['client_id'] ?? '')) !== 1) {
                AuditLog::append(
                    $connection,
                    null,
                    'mqtt',
                    'device.connection_denied',
                    'unidentified',
                    'denied',
                    ['context' => 'device', 'reason' => 'invalid_certificate'],
                    'customer'
                );
                return ['allowed' => false];
            }
            $bound = AccessService::authenticateCertificate($connection, (string) $request['fingerprint']);
            $device = $connection->table('iot_devices')->where('id', '=', $request['client_id'])->first();
            $reason = 'invalid_certificate';
            $allowed = false;
            if ($bound !== null && $device !== null && $bound->principalId === $device['id']) {
                $allowed = (int) $device['recovery_verified'] === 1;
                $reason = $allowed ? '' : 'invalid_certificate';
                if ($allowed && !in_array($device['lifecycle'], ['inactive', 'enabled'], true)) {
                    $allowed = false;
                    $reason = 'device_unavailable';
                }
                if ($allowed && !TransferService::ownershipCurrent($connection, $device)) {
                    $allowed = false;
                    $reason = 'device_ownership_unavailable';
                }
                if ($allowed && $connection->table('iot_tenants')->where('id', '=', $device['tenant_id'])->where('enabled', '=', 1)->first() === null) {
                    $allowed = false;
                    $reason = 'tenant_unavailable';
                }
                if ($allowed) {
                    $allowed = $request['secure'] && $request['protocol'] === 5 && $request['keep_alive'] === 30 && $request['expiry'] === 86400;
                    $reason = $allowed ? '' : 'device_connection_contract';
                }
            }
            if (!$allowed || $bound === null || $device === null) {
                AuditLog::append(
                    $connection,
                    $device === null ? null : $device['tenant_id'],
                    $device === null ? 'mqtt' : $device['id'],
                    'device.connection_denied',
                    $device === null ? 'unidentified' : $device['id'],
                    'denied',
                    ['context' => 'device', 'reason' => $reason === '' ? 'invalid_certificate' : $reason],
                    'customer'
                );
                return ['allowed' => false];
            }
            $identity = new \Type\Mqtt\AccessIdentity('device:' . $device['id'], $bound->credentialId, $bound->credentialVersion, 'mtls');
            return ['allowed' => true, 'access_identity' => $identity->data(), 'resource_scope' => 'iot:' . $device['tenant_id']];
        }
        if (str_starts_with((string) ($request['username'] ?? ''), 'debug:')) {
            return DebugService::deviceAccess($connection, $request);
        }
        $clientId = $request['client_id'];
        $username = $request['username'];
        $device = preg_match('/^[a-f0-9]{32}$/D', $clientId) === 1
            ? $connection->table('iot_devices')->where('id', '=', $clientId)->first() : null;
        if ($action === 'disconnected') {
            if ($device !== null) {
                self::observeDevice($connection, $device, $request);
            } else {
                ResourceObservations::disconnected($connection, $request['node_id'], $request['run_id'], $request['owner_id']);
            }
            return ['allowed' => true];
        }
        $credential = preg_match('/^[a-f0-9]{32}:[a-f0-9]{32}$/D', $username) === 1 && substr($username, 0, 32) === $clientId
            ? $connection->table('iot_device_credentials')->where('device_id', '=', $clientId)->where('id', '=', substr($username, 33))->first() : null;
        /** 后续授权可沿用握手留下的 mTLS 身份；口令身份仍要求当前活动凭据。 */
        $certificateIdentity = isset($request['access_identity']) && is_array($request['access_identity'])
            && ($request['access_identity']['authentication_method'] ?? '') === 'mtls'
            ? \Type\Mqtt\AccessIdentity::fromData($request['access_identity']) : null;
        $reason = $device === null ? 'unknown_device' : 'invalid_credentials';
        $allowed = false;
        if ($certificateIdentity !== null && $device !== null && $certificateIdentity->principalId === 'device:' . $device['id']
            && AccessService::certificateCurrent($connection, $certificateIdentity) && (int) $device['recovery_verified'] === 1) {
            $allowed = true;
            $reason = '';
            $identity = $certificateIdentity;
        } elseif ($device !== null && $credential !== null && $credential['status'] === 'active'
            && (int) $device['recovery_verified'] === 1 && (int) $credential['recovery_verified'] === 1
            && $credential['ownership_id'] === $device['ownership_id']) {
            $allowed = true;
            $reason = '';
            $identity = new \Type\Mqtt\AccessIdentity('device:' . $device['id'], $credential['id'], 1);
        } else {
            AuditLog::append(
                $connection,
                $device === null ? null : $device['tenant_id'],
                $device === null ? 'mqtt' : $device['id'],
                $action === 'authorize' ? 'device.topic_denied' : 'device.connection_denied',
                $device === null ? 'unidentified' : $device['id'],
                'denied',
                ['context' => 'device', 'reason' => $reason],
                'customer'
            );
            return ['allowed' => false];
        }
        if (isset($request['access_identity'])) {
            $allowed = is_array($request['access_identity']) && $identity->matches(\Type\Mqtt\AccessIdentity::fromData($request['access_identity']));
        }
        if ($allowed && !in_array($device['lifecycle'], ['inactive', 'enabled'], true)) {
            $allowed = false;
            $reason = 'device_unavailable';
        }
        if ($allowed && $action !== 'connected' && !TransferService::ownershipCurrent($connection, $device)) {
            $allowed = false;
            $reason = 'device_ownership_unavailable';
        }
        if ($allowed && $connection->table('iot_tenants')->where('id', '=', $device['tenant_id'])->where('enabled', '=', 1)->first() === null) {
            $allowed = false;
            $reason = 'tenant_unavailable';
        }
        // 只有CONNECT验证秘密；证书身份按当前登记核对。后续授权使用Broker已经认证的不可变凭据身份，包括无密码的遗嘱恢复。
        if ($allowed && $action === 'authenticate') {
            $allowed = $credential !== null && hash_equals($credential['secret_hash'], $request['verifier']);
            $reason = $allowed ? $reason : 'invalid_credentials';
        }
        if ($allowed && $action === 'authenticate') {
            $allowed = $request['secure'] && $request['protocol'] === 5 && $request['keep_alive'] === 30 && $request['expiry'] === 86400;
            $reason = 'device_connection_contract';
        } elseif ($allowed && $action === 'authorize') {
            $topics = self::topics($device);
            $allowed = AccessService::authorizeDevice($connection, $device['id'], $request['topic'], $request['operation'], (int) $request['qos'], $topics);
            // 历史自定义规则不能扩大当前使用关系的两个精确主题。
            $expected = $request['operation'] === 'publish' ? $topics['publish'] : $topics['subscribe'];
            $allowed = $allowed && $request['topic'] === $expected && in_array($request['operation'], ['publish', 'subscribe'], true);
            $reason = 'topic_forbidden';
        }
        if (!$allowed) {
            AuditLog::append(
                $connection,
                $device === null ? null : $device['tenant_id'],
                $device === null ? 'mqtt' : $device['id'],
                $action === 'authorize' ? 'device.topic_denied' : 'device.connection_denied',
                $device === null ? 'unidentified' : $device['id'],
                'denied',
                ['context' => 'device', 'reason' => $reason],
                'customer'
            );
            return ['allowed' => false];
        }
        if ($action === 'connected') {
            if (isset($request['resource']) && ($request['resource']['resource_scope'] ?? null) !== 'iot:' . $device['tenant_id']) {
                throw new \RuntimeException('device_resource_scope_changed');
            }
            self::observeDevice($connection, $device, $request);
        }
        return ['allowed' => true] + ($action === 'authenticate'
            ? ['access_identity' => $identity->data(), 'resource_scope' => 'iot:' . $device['tenant_id']] : []);
    }

    /** 同一SQL快照读取设备及连接，首次激活并发提交时不能拼出未激活但在线的跨快照状态。 */
    private static function observedQuery(Connection $connection): Query
    {
        return $connection->table('iot_devices', 'd')
            ->join('iot_tenants', 't.id', '=', 'd.tenant_id', 't')
            ->join('iot_device_connections', 'c.device_id', '=', 'd.id', 'c', 'LEFT')
            ->join('iot_broker_observations', 'b.node_id', '=', 'c.node_id', 'b', 'LEFT')
            ->join('iot_authorization_invalidations', 'i.device_id', '=', 'd.id', 'i', 'LEFT')
            ->select(['d.*', 'tenant_name' => 't.name', 'tenant_enabled' => 't.enabled', 'connection_status' => 'c.status', 'connection_ownership' => 'c.ownership_id',
                'connection_time' => 'c.observed_at', 'connection_run' => 'c.run_id', 'broker_run' => 'b.run_id',
                'broker_time' => 'b.observed_at', 'broker_expiry' => 'b.expires_at', 'authorization_id' => 'i.id',
                'authorization_requested' => 'i.requested_at', 'authorization_completed' => 'i.completed_at', 'authorization_node' => 'i.node_id']);
    }

    /**
     * 内部发送者复用设备页的连接观察；已锁定的设备使用当前行，避免MySQL旧快照覆盖模型确认后的状态。
     * @param array<string, mixed>|null $lockedDevice 调用者在同一事务持有锁的完整设备行；仍须核对归属、模型和生命周期。
     */
    public static function observation(Connection $connection, string $deviceId, ?array $lockedDevice = null): ?array
    {
        $record = self::observedQuery($connection)->where('d.id', '=', $deviceId)->first();
        return $record === null ? null : self::observed(array_replace($record, $lockedDevice ?? []));
    }

    /** 去掉内部关联列；活性过期或归属阶段变化不伪造离线。 */
    private static function observed(array $row): array
    {
        $same = $row['connection_ownership'] === $row['ownership_id'];
        $status = $same ? ($row['connection_status'] ?? 'unknown') : 'unknown';
        if ($status === 'online' && ($row['connection_run'] !== $row['broker_run'] || (int) $row['broker_expiry'] <= time())) {
            $status = 'unknown';
        }
        $row['connection'] = ['status' => $status, 'observed_at' => !$same || $row['connection_time'] === null ? null : (int) $row['connection_time'],
            'broker_observed_at' => !$same || $row['broker_time'] === null ? null : (int) $row['broker_time']];
        $row['authorization'] = ['status' => $row['authorization_id'] !== null && $row['authorization_completed'] === null ? 'pending' : 'enforced',
            'id' => $row['authorization_id'], 'requested_at' => $row['authorization_requested'] === null ? null : (int) $row['authorization_requested'],
            'completed_at' => $row['authorization_completed'] === null ? null : (int) $row['authorization_completed'], 'node_id' => $row['authorization_node']];
        unset($row['authorization_id'], $row['authorization_requested'], $row['authorization_completed'], $row['authorization_node']);
        unset($row['connection_status'], $row['connection_ownership'], $row['connection_time'], $row['connection_run'], $row['broker_run'], $row['broker_time'], $row['broker_expiry']);
        unset($row['model_version'], $row['model_switch_id'], $row['model_start_sequence']);
        return $row;
    }

    /** 一条稳定节点行承载当前进程活性；过期的旧工作不能延长观察窗口。 */
    private static function observeBroker(Connection $connection, array $request): void
    {
        $observedAt = (int) $request['observed_at'];
        $expiresAt = $request['action'] === 'stopped' ? $observedAt : $observedAt + 15;
        if ($request['initial']) {
            $connection->execute(
                'INSERT INTO iot_broker_observations (node_id, run_id, observed_at, expires_at) VALUES (?, ?, ?, ?)'
                . ' ON CONFLICT (node_id) DO UPDATE SET run_id = EXCLUDED.run_id, observed_at = EXCLUDED.observed_at, expires_at = EXCLUDED.expires_at',
                [$request['node_id'], $request['run_id'], $observedAt, $expiresAt]
            );
        } else {
            if ($connection->execute(
                'UPDATE iot_broker_observations SET observed_at = ?, expires_at = ? WHERE node_id = ? AND run_id = ?',
                [$observedAt, $expiresAt, $request['node_id'], $request['run_id']]
            ) !== 1) {
                throw new \RuntimeException('device_observation_owner_lost');
            }
        }
        AccessService::observeNode($connection, $request['node_id'], AccessService::currentVersion($connection, null), $request['action'] === 'stopped');
        $metrics = is_array($request['metrics'] ?? null) ? $request['metrics'] : [];
        $quotaRevision = is_int($metrics['quotaRevision'] ?? null) ? $metrics['quotaRevision'] : 0;
        QuotaService::observeNode($connection, $request['node_id'], $quotaRevision, $request['action'] === 'stopped');
        $ca = getenv('IOT_MQTT_CLIENT_CA');
        if (is_string($ca) && $ca !== '') {
            AccessService::syncHandshakeCa($connection, $ca);
        }
        $loaded = RuntimeService::fromMqttEnvironment();
        if ($loaded !== null) {
            RuntimeService::ensureBootstrap($connection, $loaded);
            $caHash = is_string($ca) && $ca !== '' && is_file($ca) ? hash_file('sha256', $ca) : false;
            $certificate = getenv('IOT_MQTT_CERTIFICATE');
            $certHash = is_string($certificate) && $certificate !== '' && is_file($certificate) ? hash_file('sha256', $certificate) : false;
            $loaded['handshake_ca_sha256'] = is_string($caHash) ? $caHash : '';
            $loaded['certificate_sha256'] = is_string($certHash) ? $certHash : '';
            RuntimeService::observeNode($connection, $request['node_id'], $loaded, $request['action'] === 'stopped');
        }
    }

    /** 更新设备观察和审计共用事务；关闭必须匹配本连接，不能覆盖新连接。 */
    private static function observeDevice(Connection $connection, array $device, array $request): void
    {
        $connection->transaction(static function (Connection $transaction) use ($device, $request): void {
            $online = $request['action'] === 'connected';
            if ($online) {
                $tenant = $transaction->query('SELECT enabled FROM iot_tenants WHERE id = ? FOR SHARE', [$device['tenant_id']]);
                if ($tenant === [] || (int) $tenant[0]['enabled'] !== 1) {
                    throw new \RuntimeException('device_observation_identity_changed');
                }
                $current = $transaction->query('SELECT lifecycle, ownership_id FROM iot_devices WHERE id = ? AND recovery_verified = 1 FOR UPDATE', [$device['id']]);
                if ($current === [] || !in_array($current[0]['lifecycle'], ['inactive', 'enabled'], true) || $current[0]['ownership_id'] !== $device['ownership_id']) {
                    throw new \RuntimeException('device_observation_identity_changed');
                }
                $valid = $transaction->query(
                    "SELECT id FROM iot_device_credentials WHERE id = ? AND device_id = ? AND ownership_id = ? AND status = 'active' AND recovery_verified = 1 FOR UPDATE",
                    [substr($request['username'], 33), $device['id'], $device['ownership_id']]
                );
                if ($valid === []) {
                    throw new \RuntimeException('device_observation_identity_changed');
                }
            }
            $changed = $online
                ? $transaction->execute(
                    "UPDATE iot_device_connections SET status = 'online', owner_id = ?, node_id = ?, run_id = ?, observed_at = ? WHERE device_id = ? AND ownership_id = ?"
                    . ' AND EXISTS (SELECT 1 FROM iot_broker_observations WHERE node_id = ? AND run_id = ? AND expires_at > ?)',
                    [$request['owner_id'], $request['node_id'], $request['run_id'], $request['observed_at'], $device['id'], $device['ownership_id'], $request['node_id'], $request['run_id'], time()]
                )
                : $transaction->execute(
                    "UPDATE iot_device_connections SET status = 'offline', observed_at = ? WHERE device_id = ? AND owner_id = ? AND run_id = ? AND status = 'online'",
                    [$request['observed_at'], $device['id'], $request['owner_id'], $request['run_id']]
                );
            if ($online && $changed !== 1) {
                throw new \RuntimeException('device_observation_owner_lost');
            }
            if ($changed === 1) {
                if ($online) {
                    $transaction->execute(
                        "UPDATE iot_devices SET lifecycle = 'enabled', version = version + 1, updated_at = ? WHERE id = ? AND ownership_id = ? AND lifecycle = 'inactive'",
                        [$request['observed_at'], $device['id'], $device['ownership_id']]
                    );
                }
                AuditLog::append(
                    $transaction,
                    $device['tenant_id'],
                    $device['id'],
                    $online ? 'device.connected' : 'device.disconnected',
                    $device['id'],
                    'success',
                    ['context' => 'device', 'reason' => $online ? 'connack_written' : (string) $request['reason']],
                    'customer'
                );
            }
            if ($online && isset($request['resource'])) {
                ResourceObservations::connected($transaction, $request['node_id'], $request['run_id'], $request['resource'], (int) $request['observed_at']);
            } elseif (!$online) {
                ResourceObservations::disconnected($transaction, $request['node_id'], $request['run_id'], $request['owner_id']);
            }
        });
    }

    /**
     * 设备只发布up、订阅down；归属阶段进入Topic，转移后旧阶段不能命中新身份范围。
     * @param array{id: string, tenant_id: string, ownership_id: string, model_version?: ?int} $device 已从数据库取得的归属事实；始终使用独立 raw 主题。
     * @return array{publish: string, subscribe: string} 两个精确Topic；具体消息类型由业务载荷表达。
     */
    public static function topics(array $device): array
    {
        $prefix = 'iot/' . $device['tenant_id'] . '/devices/' . $device['id'] . '/epochs/' . $device['ownership_id'];
        $prefix .= '/raw';
        return ['publish' => $prefix . '/up', 'subscribe' => $prefix . '/down'];
    }
}
