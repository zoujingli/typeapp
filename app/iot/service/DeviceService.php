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
use Closure;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Orm\Connection;
use Type\Orm\Query;

/** 预注册设备、精确模型绑定与凭据的持久所有者；归属只能通过显式转移流程变更。 */
final class DeviceService
{
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
     * 授权事务中绑定精确已发布模型并生成独立凭据；不推进租户资料版本。
     * 256位随机秘密只随本次成功结果返回，数据库仅保存SHA-256验证值。
     * @return array{device: array<string, mixed>, credential: array<string, mixed>}
     * @throws HttpError 无登记权限、跨租户模型、模型未发布或不存在。
     */
    public function register(Connection $connection, Identity $identity, string $tenantId, string $productId, int $modelVersion, string $name): array
    {
        return $this->run($connection, $identity, $tenantId, 'customer', 'create', 'device.registered', $productId, true, static function (Connection $transaction, Identity $current, array $context) use ($tenantId, $productId, $modelVersion, $name): array {
            ProductService::publishedModel($transaction, $tenantId, $productId, $modelVersion);
            $device = ['id' => bin2hex(random_bytes(16)), 'tenant_id' => $tenantId, 'product_id' => $productId,
                'model_version' => $modelVersion, 'ownership_id' => bin2hex(random_bytes(16)), 'name' => $name,
                'lifecycle' => 'inactive', 'version' => 1, 'created_at' => time(), 'updated_at' => time(),
                'model_switch_id' => null, 'model_start_sequence' => '0', 'transfer_id' => null, 'transfer_frozen' => 0, 'recovery_verified' => 1];
            $credentialId = bin2hex(random_bytes(16));
            $secret = bin2hex(random_bytes(32));
            $transaction->table('iot_devices')->insert($device);
            $transaction->table('iot_device_ownerships')->insert(['id' => $device['ownership_id'], 'device_id' => $device['id'],
                'tenant_id' => $tenantId, 'started_at' => $device['created_at']]);
            $transaction->table('iot_device_connections')->insert(['device_id' => $device['id'], 'ownership_id' => $device['ownership_id'], 'status' => 'unknown']);
            $transaction->table('iot_device_credentials')->insert(['id' => $credentialId, 'device_id' => $device['id'],
                'ownership_id' => $device['ownership_id'], 'secret_hash' => hash('sha256', $secret), 'status' => 'active',
                'created_at' => time(), 'revoked_at' => null]);
            AuditLog::append($transaction, $tenantId, $current, 'device.registered', $device['id'], 'success', ['context' => 'device', 'version' => 1], 'customer');
            AuditLog::append($transaction, $tenantId, $current, 'credential.created', $device['id'], 'success', ['context' => 'device', 'version' => 1], 'customer');
            return ['device' => self::project($transaction, $device['id'], 'customer'),
                'credential' => self::credential($device, $credentialId, $secret)];
        });
    }

    /**
     * 客户可读绑定模型和公开Topic，平台仅取得资产与接入状态；不混入遥测、指令或导出。
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
        return $realm === 'admin' ? $result : $result + [
            'model' => ProductService::publishedModel($connection, $device['tenant_id'], $device['product_id'], (int) $device['model_version']),
            'topics' => self::topics($device), 'model_switch' => $device['model_switch_id'] === null ? null
                : self::switchProjection($connection->table('iot_model_switches')->where('id', '=', $device['model_switch_id'])->first()),
        ];
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

    /** 当前有权客户提出精确设备版本；原ID确认受理，retry只重新发送原目标并保留真实来源。 */
    public function switchModel(Connection $connection, Identity $identity, string $tenantId, string $deviceId, string $switchId, int $targetVersion, int $version, bool $retry = false): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $switchId) || $targetVersion < 1 || $targetVersion > 2147483646) {
            throw new HttpError(422, 'model_switch_invalid');
        }
        return $this->run($connection, $identity, $tenantId, 'customer', 'model-switch', 'device.model_switch_requested', $deviceId, true, static function (Connection $transaction, Identity $current, array $context) use ($tenantId, $deviceId, $switchId, $targetVersion, $version, $retry): array {
            $lookup = $transaction->table('iot_devices')->where('tenant_id', '=', $tenantId)->where('id', '=', $deviceId);
            $device = ($transaction->driverName() === 'sqlite' ? $lookup : $lookup->lockForUpdate())->first();
            if ($device === null) {
                throw new HttpError(404, 'device_not_found');
            }
            $query = $transaction->table('iot_model_switches')->where('id', '=', $switchId);
            $existing = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
            if ($existing !== null) {
                if ($existing['tenant_id'] !== $tenantId || $existing['device_id'] !== $deviceId || (int) $existing['target_version'] !== $targetVersion
                    || (!$retry && ((int) $existing['request_version'] !== $version || json_decode($existing['source_context'], true, 8, JSON_THROW_ON_ERROR)['key'] !== $context['identity']['key']))) {
                    throw new HttpError(409, 'model_switch_identity_conflict');
                }
                if ($retry && (int) ($existing['retry_version'] ?? 0) === $version) {
                    if (json_decode($existing['dispatch_context'], true, 8, JSON_THROW_ON_ERROR)['key'] !== $context['identity']['key']) {
                        throw new HttpError(409, 'model_switch_identity_conflict');
                    }
                    return self::switchProjection($existing);
                }
                if ($retry && $existing['status'] === 'pending') {
                    if ($version < 1 || $version >= 2147483646 || (int) $device['version'] !== $version) {
                        throw new HttpError(409, 'stale_version');
                    }
                    if (!self::modelSwitchAllowed($transaction, $device) || $device['model_switch_id'] !== $switchId || $device['ownership_id'] !== $existing['ownership_id']) {
                        throw new HttpError(409, 'model_switch_device_unavailable');
                    }
                    if ($existing['last_attempt_at'] !== null && time() < (int) $existing['last_attempt_at'] + 10) {
                        throw new HttpError(409, 'model_switch_retry_too_soon');
                    }
                    $transaction->table('iot_model_switches')->where('id', '=', $switchId)->update(['next_attempt_at' => time(), 'retry_version' => $version,
                        'dispatch_context' => json_encode($context['identity'], JSON_THROW_ON_ERROR)]);
                    $lookup->update(['version' => $version + 1, 'updated_at' => time()]);
                    AuditLog::append($transaction, $tenantId, $current, 'device.model_switch_retried', $deviceId, 'unknown', ['switch_id' => $switchId, 'version' => $version + 1], 'customer');
                }
                return self::switchProjection($transaction->table('iot_model_switches')->where('id', '=', $switchId)->first());
            }
            if ($retry) {
                throw new HttpError(404, 'model_switch_not_found');
            }
            if ($version < 1 || $version >= 2147483646 || (int) $device['version'] !== $version) {
                throw new HttpError(409, 'stale_version');
            }
            if (!self::modelSwitchAllowed($transaction, $device)) {
                throw new HttpError(409, 'model_switch_device_unavailable');
            }
            if ($device['model_switch_id'] !== null) {
                throw new HttpError(409, 'model_switch_in_progress');
            }
            if ((int) $device['model_version'] === $targetVersion) {
                throw new HttpError(409, 'model_already_bound');
            }
            $target = ProductService::publishedModel($transaction, $tenantId, $device['product_id'], $targetVersion);
            $source = ProductService::publishedModel($transaction, $tenantId, $device['product_id'], (int) $device['model_version']);
            $now = time();
            $record = ['id' => $switchId, 'tenant_id' => $tenantId, 'device_id' => $deviceId, 'ownership_id' => $device['ownership_id'],
                'product_id' => $device['product_id'], 'source_version' => (int) $device['model_version'], 'target_version' => $targetVersion,
                'source_start' => $device['model_start_sequence'], 'structure_hash' => ModelDefinition::structuralHash($target['definition']),
                'same_structure' => ModelDefinition::structurallyEqual($source['definition'], $target['definition']) ? 1 : 0,
                'actor_id' => $current->subject(), 'request_version' => $version, 'source_context' => json_encode($context['identity'], JSON_THROW_ON_ERROR),
                'dispatch_context' => json_encode($context['identity'], JSON_THROW_ON_ERROR), 'created_at' => $now, 'next_attempt_at' => $now, 'status' => 'pending'];
            $transaction->table('iot_model_switches')->insert($record);
            $lookup->update(['model_switch_id' => $switchId, 'version' => (int) $device['version'] + 1, 'updated_at' => $now]);
            AuditLog::append(
                $transaction,
                $tenantId,
                $current,
                'device.model_switch_requested',
                $deviceId,
                'unknown',
                ['switch_id' => $switchId, 'source_version' => (int) $device['model_version'], 'target_version' => $targetVersion],
                'customer'
            );
            return self::switchProjection($transaction->table('iot_model_switches')->where('id', '=', $switchId)->first());
        });
    }

    /** 只读、有界的切换记录；超时只投影为尚未确认，不撤回可能已经在设备持久生效的意图。 */
    public function modelSwitches(Connection $connection, Identity $identity, string $tenantId, string $deviceId, int $page = 1, int $perPage = 20): array
    {
        $this->device($connection, $identity, $tenantId, $deviceId);
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) {
            throw new HttpError(422, 'model_switch_page_invalid');
        }
        $pagination = $connection->table('iot_model_switches')->where('tenant_id', '=', $tenantId)->where('device_id', '=', $deviceId)
            ->orderBy('created_at', 'DESC')->orderBy('id')->paginate($page, $perPage);
        $items = [];
        foreach ($pagination->items() as $record) {
            $items[] = self::switchProjection($record);
        }
        return ['items' => $items, 'total' => $pagination->total(), 'page' => $pagination->number(), 'per_page' => $pagination->perPage()];
    }

    /** 同步事务内领取至多一个原意图；自动最多三次，60秒以后保留未知等待管理员重试。 */
    public static function claimModelSwitch(Connection $transaction): ?array
    {
        if ($transaction->transactionDepth() < 1) {
            throw new \LogicException('model_switch_transaction_required');
        }
        RoleService::lockAuthorization();
        $query = $transaction->table('iot_model_switches')->where('next_attempt_at', '<=', time())->orderBy('next_attempt_at')->orderBy('id')->limit(1);
        $record = $query->first();
        if ($record === null) {
            return null;
        }
        $deviceLock = $transaction->table('iot_devices')->where('id', '=', $record['device_id']);
        $lockedDevice = ($transaction->driverName() === 'sqlite' ? $deviceLock : $deviceLock->lockForUpdate())->first();
        $recordLock = $transaction->table('iot_model_switches')->where('id', '=', $record['id']);
        $record = ($transaction->driverName() === 'sqlite' ? $recordLock : $recordLock->lockForUpdate())->first();
        if ($record === null || $record['next_attempt_at'] === null || (int) $record['next_attempt_at'] > time()) {
            return null;
        }
        $now = time();
        $source = json_decode($record['dispatch_context'], true, 8, JSON_THROW_ON_ERROR);
        $current = (new IdentityService('customer'))->resume($source);
        $allowed = false;
        if ($current !== null) {
            try {
                $allowed = in_array('customer.devices.model-switch', RoleService::permissions($current, 'customer', $record['tenant_id']), true);
            } catch (HttpError $revoked) {
                $allowed = false;
            }
        }
        if (!$allowed) {
            // 未领取过才可解除模型冻结；已发送的意图保留未知并继续接收设备确认。
            $transaction->table('iot_model_switches')->where('id', '=', $record['id'])->update(['next_attempt_at' => null,
                'status' => $record['last_attempt_at'] === null ? 'rejected' : $record['status'], 'result_code' => 'source_permission_revoked']);
            if ($record['last_attempt_at'] === null) {
                $transaction->execute('UPDATE iot_devices SET model_switch_id = NULL, version = version + 1 WHERE id = ? AND model_switch_id = ?', [$record['device_id'], $record['id']]);
            }
            AuditLog::append(
                $transaction,
                $record['tenant_id'],
                $source,
                'device.model_switch_stopped',
                $record['device_id'],
                $record['last_attempt_at'] === null ? 'denied' : 'unknown',
                ['switch_id' => $record['id'], 'reason' => 'source_permission_revoked'],
                'customer'
            );
            return null;
        }
        $device = self::observation($transaction, $record['device_id'], $lockedDevice);
        $available = $device !== null && self::modelSwitchAllowed($transaction, $device) && $device['connection']['status'] === 'online'
            && $device['model_switch_id'] === $record['id'] && $device['ownership_id'] === $record['ownership_id']
            && (int) $device['model_version'] === (int) $record['source_version'] && $record['status'] === 'pending';
        $count = (int) $record['attempt_count'];
        // 离线不消耗发送次数，但自动等待有60秒上限；人工重试的节点可以晚于首次请求。
        $automaticExpired = $now >= (int) $record['created_at'] + 60 && (int) $record['next_attempt_at'] < (int) $record['created_at'] + 60;
        if (!$available || $automaticExpired || $count >= 100000) {
            $transaction->table('iot_model_switches')->where('id', '=', $record['id'])->update(['next_attempt_at' => $available || $now + 10 >= (int) $record['created_at'] + 60 ? null : $now + 10]);
            return null;
        }
        $next = $now + ($count === 0 ? 10 : 20);
        $transaction->table('iot_model_switches')->where('id', '=', $record['id'])->update(['attempt_count' => $count + 1,
            'last_attempt_at' => $now, 'next_attempt_at' => $count < 2 && $next < (int) $record['created_at'] + 60 ? $next : null]);
        AuditLog::append($transaction, $record['tenant_id'], $current, 'device.model_switch_dispatched', $record['device_id'], 'unknown', ['switch_id' => $record['id'], 'version' => $count + 1], 'customer');
        return ['topic' => DeviceService::topics($device)['subscribe'], 'deadline_at' => min($now + 30, (int) $current->attributes()['expires_at']),
            'payload' => json_encode(['app_version' => 1, 'type' => 'model_switch',
            'device_id' => $record['device_id'], 'ownership_id' => $record['ownership_id'], 'switch_id' => $record['id'],
            'source_version' => (int) $record['source_version'], 'target_version' => (int) $record['target_version'],
            'source_start' => $record['source_start'], 'structure_hash' => $record['structure_hash']], JSON_THROW_ON_ERROR)];
    }

    /** 仅设备在本地同步切换后发出的精确确认改变绑定；业务ACK须由调用者取得本次同步提交证明后发送。 */
    public static function confirmModelSwitch(Connection $transaction, string $topic, array $data, int $qos, int $receivedAt): array
    {
        $none = ['message_id' => '', 'topic' => '', 'receipt' => null, 'code' => 'invalid_model_switch_receipt'];
        $fields = ['app_version', 'type', 'device_id', 'ownership_id', 'switch_id', 'source_version', 'target_version', 'structure_hash', 'boundary_sequence', 'status', 'code'];
        if ($transaction->transactionDepth() < 1) {
            throw new \LogicException('model_switch_transaction_required');
        }
        if ($qos !== 1 || count($data) !== count($fields) || array_diff(array_keys($data), $fields) !== [] || ($data['app_version'] ?? null) !== 1
            || ($data['type'] ?? '') !== 'model_switch_receipt' || !is_string($data['device_id'] ?? null) || !is_string($data['switch_id'] ?? null)
            || !is_string($data['boundary_sequence'] ?? null) || !preg_match('/^(0|[1-9][0-9]{0,37})$/D', $data['boundary_sequence'])
            || !is_int($data['source_version'] ?? null) || !is_int($data['target_version'] ?? null)
            || !in_array($data['status'] ?? '', ['confirmed', 'rejected'], true)
            || !in_array($data['code'] ?? '', $data['status'] === 'confirmed' ? ['model_switched'] : ['model_unsupported', 'model_state_conflict'], true)) {
            return $none;
        }
        $lookup = $transaction->table('iot_devices')->where('id', '=', $data['device_id'])->where('recovery_verified', '=', 1);
        $device = ($transaction->driverName() === 'sqlite' ? $lookup : $lookup->lockForUpdate())->first();
        $recordQuery = $transaction->table('iot_model_switches')->where('id', '=', $data['switch_id']);
        $record = ($transaction->driverName() === 'sqlite' ? $recordQuery : $recordQuery->lockForUpdate())->first();
        if ($device === null || $record === null || !self::modelSwitchAllowed($transaction, $device) || DeviceService::topics($device)['publish'] !== $topic
            || $device['ownership_id'] !== ($data['ownership_id'] ?? '') || $record['ownership_id'] !== $device['ownership_id']
            || $record['device_id'] !== $device['id'] || (int) $record['source_version'] !== $data['source_version']
            || (int) $record['target_version'] !== $data['target_version'] || $record['structure_hash'] !== ($data['structure_hash'] ?? '')
            || $record['last_attempt_at'] === null) {
            return $none;
        }
        $hash = IngestionService::contentHash(json_encode((object) $data, JSON_THROW_ON_ERROR));
        if ($record['status'] !== 'pending') {
            if ($record['result_hash'] !== $hash) {
                return $none;
            }
        } else {
            if ($device['model_switch_id'] !== $record['id'] || (int) $device['model_version'] !== (int) $record['source_version']
                || self::sequenceCompare($data['boundary_sequence'], $record['source_start']) < 0) {
                return $none;
            }
            $current = $transaction->table('iot_current_data')->where('device_id', '=', $device['id'])->first();
            if ($data['status'] === 'confirmed' && $current !== null && $current['ownership_id'] === $device['ownership_id']
                && self::sequenceCompare($data['boundary_sequence'], $current['sequence']) < 0) {
                return $none;
            }
            $change = ['model_switch_id' => null, 'version' => (int) $device['version'] + 1, 'updated_at' => $receivedAt];
            if ($data['status'] === 'confirmed') {
                $change += ['model_version' => (int) $record['target_version'], 'model_start_sequence' => $data['boundary_sequence']];
            }
            $lookup->update($change);
            $transaction->table('iot_model_switches')->where('id', '=', $record['id'])->update(['status' => $data['status'], 'result_code' => $data['code'],
                'boundary_sequence' => $data['boundary_sequence'], 'confirmed_at' => $receivedAt, 'result_hash' => $hash, 'next_attempt_at' => null]);
            AuditLog::append(
                $transaction,
                $record['tenant_id'],
                json_decode($record['dispatch_context'], true, 8, JSON_THROW_ON_ERROR),
                'device.model_switch_confirmed',
                $device['id'],
                $data['status'] === 'confirmed' ? 'success' : 'failed',
                ['switch_id' => $record['id'], 'source_version' => $data['source_version'], 'target_version' => $data['target_version'], 'reason' => $data['code'], 'boundary_sequence' => $data['boundary_sequence']],
                'customer'
            );
        }
        $transaction->table('iot_model_switches')->where('id', '=', $record['id'])->update(['receipt_nonce' => bin2hex(random_bytes(16))]);
        return ['message_id' => '', 'topic' => DeviceService::topics($device)['subscribe'], 'code' => 'accepted', 'receipt' => ['app_version' => 1,
            'type' => 'model_switch_ack', 'device_id' => $device['id'], 'ownership_id' => $device['ownership_id'], 'switch_id' => $record['id'], 'result_hash' => $hash]];
    }

    /** 只接受当前序号区间或已确认结束的原绑定区间；旧缓存按原版本进入历史，不能伪造从未绑定的版本。 */
    public static function acceptsModel(Connection $connection, array $device, int $modelVersion, string $sequence): bool
    {
        if ($modelVersion === (int) $device['model_version'] && self::sequenceCompare($sequence, $device['model_start_sequence']) > 0) {
            return true;
        }
        $rows = $connection->query(
            "SELECT id FROM iot_model_switches WHERE device_id = ? AND ownership_id = ? AND source_version = ? AND status = 'confirmed'"
            . ' AND (LENGTH(source_start) < ? OR (LENGTH(source_start) = ? AND source_start < ?))'
            . ' AND (LENGTH(boundary_sequence) > ? OR (LENGTH(boundary_sequence) = ? AND boundary_sequence >= ?)) LIMIT 1',
            [$device['id'], $device['ownership_id'], $modelVersion, strlen($sequence), strlen($sequence), $sequence, strlen($sequence), strlen($sequence), $sequence]
        );
        return $rows !== [];
    }

    /** 十进制持久序号不转为机器整数；供缓存、平台绑定与迟到数据边界使用。 */
    public static function sequenceCompare(string $left, string $right): int
    {
        return strlen($left) === strlen($right) ? strcmp($left, $right) : (strlen($left) < strlen($right) ? -1 : 1);
    }

    private static function switchProjection(array $record): array
    {
        unset($record['receipt_nonce'], $record['result_hash'], $record['source_context'], $record['dispatch_context']);
        foreach (['source_version', 'target_version', 'request_version', 'retry_version', 'created_at', 'next_attempt_at', 'attempt_count', 'last_attempt_at', 'confirmed_at'] as $field) {
            $record[$field] = $record[$field] === null ? null : (int) $record[$field];
        }
        $record['state'] = $record['status'] === 'pending' && time() >= (int) $record['created_at'] + 60 ? 'unconfirmed' : $record['status'];
        $record['same_structure'] = (int) $record['same_structure'] === 1;
        return $record;
    }

    /** 沿用生命周期与撤权事实；设备锁由请求/确认调用者拥有，待撤权或无有效凭据时不能继续模型切换。 */
    private static function modelSwitchAllowed(Connection $connection, array $device): bool
    {
        return (int) $device['recovery_verified'] === 1 && $device['lifecycle'] === 'enabled' && $device['transfer_id'] === null
            && $connection->table('iot_authorization_invalidations')->where('device_id', '=', $device['id'])->whereNull('completed_at')->first() === null
            && $connection->table('iot_device_credentials')->where('device_id', '=', $device['id'])->where('status', '=', 'active')->where('recovery_verified', '=', 1)->first() !== null;
    }

    /**
     * 管理动作在授权和设备锁内重验独立节点、版本及危险确认；平台身份不伪造成租户成员。
     * 禁用撤销旧凭据，重新启用须另行生成新凭据。退役是终态，历史及原归属不删除。
     * @param string $name 资料入口已通过公共Field校验的1–100字符名称；其他动作忽略。
     * @return array{device:array<string,mixed>,credential:array<string,mixed>|null} authorization.pending须等Broker完成，不能当作网络已隔离。
     * @throws HttpError 无权限、确认不符、版本冲突、退役终态或前次撤权尚未完成。
     */
    public function change(Connection $connection, Identity $identity, string $tenantId, string $deviceId, string $action, int $version, string $confirmation, string $realm = 'customer', string $name = ''): array
    {
        if (!in_array($action, ['update', 'rotate', 'revoke', 'disable', 'enable', 'retire'], true)) {
            throw new HttpError(422, 'device_action_invalid');
        }
        return $this->run($connection, $identity, $tenantId, $realm, $action, 'device.' . $action, $deviceId, true, static function (Connection $transaction, Identity $current, array $context) use ($tenantId, $deviceId, $action, $version, $confirmation, $realm, $name): array {
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
            if ($device['transfer_id'] !== null) {
                $transfer = $transaction->table('iot_transfers')->where('id', '=', $device['transfer_id'])->first();
                if ($transfer !== null && ($transfer['status'] === 'isolating'
                    || ($transfer['status'] === 'activating' && !in_array($action, ['rotate', 'revoke'], true)))) {
                    throw new HttpError(409, 'transfer_switch_in_progress');
                }
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
                $changes['name'] = $name;
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
    public static function invalidateCredential(Connection $transaction, array $device, Identity $actor): string
    {
        if ($transaction->transactionDepth() < 1) {
            throw new \LogicException('device_transaction_required');
        }
        $query = $transaction->table('iot_authorization_invalidations')->where('device_id', '=', $device['id']);
        $previous = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
        $credentialQuery = $transaction->table('iot_device_credentials')->where('device_id', '=', $device['id'])->where('status', '=', 'active')->limit(2);
        $credentials = ($transaction->driverName() === 'sqlite' ? $credentialQuery : $credentialQuery->lockForUpdate())->get();
        if (($previous !== null && $previous['completed_at'] === null) || count($credentials) !== 1 || $credentials[0]['ownership_id'] !== $device['ownership_id']) {
            throw new HttpError(409, 'device_authorization_pending');
        }
        $old = $credentials[0];
        $context = IdentityService::context($actor, $device['tenant_id']);
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
        $action = $request['action'];
        if ($action === 'invalidation_next') {
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
     * @param array{id: string, tenant_id: string, ownership_id: string} $device 已从数据库取得的归属事实。
     * @return array{publish: string, subscribe: string} 两个精确Topic；具体消息类型由业务载荷表达。
     */
    public static function topics(array $device): array
    {
        $prefix = 'iot/' . $device['tenant_id'] . '/devices/' . $device['id'] . '/epochs/' . $device['ownership_id'];
        return ['publish' => $prefix . '/up', 'subscribe' => $prefix . '/down'];
    }
}
