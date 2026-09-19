<?php

declare(strict_types=1);

namespace app\iot\service;

use app\common\service\AuditLog;
use app\common\service\IdentityService;
use app\common\service\RoleService;
use Closure;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Orm\Connection;

/** 双方审批、设备冻结与正式切换的持久所有者；旧授权隔离证明先于新归属激活。 */
final class TransferService
{
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
        return self::write($connection, $identity, $tenantId, 'request', $transferId, static function (Connection $transaction, Identity $current, array $permissions) use ($tenantId, $deviceId, $targetTenant, $transferId, $version): array {
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
        return self::write($connection, $identity, $tenantId, $action, $transferId, static function (Connection $transaction, Identity $current, array $permissions) use ($tenantId, $transferId, $decision, $action, $decisionId, $hash, $invitation): array {
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
                    $product = $products->create($transaction, $current, $tenantId, trim($copyName), '');
                    if ($product['name'] === '') {
                        throw new HttpError(422, 'transfer_model_invalid');
                    }
                    $model = $products->createModel($transaction, $current, $tenantId, $product['id'], json_decode($record['source_definition'], true, 32, JSON_THROW_ON_ERROR));
                    $target = $products->changeModel($transaction, $current, $tenantId, $product['id'], (int) $model['model_version'], 1, 'publish');
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
        return self::write($connection, $identity, $tenantId, 'retry', $transferId, static function (Connection $transaction, Identity $current, array $permissions) use ($tenantId, $transferId, $invitation, $version): array {
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
        return self::write($connection, $identity, $tenantId, 'switch', $transferId, static function (Connection $transaction, Identity $current, array $permissions) use ($tenantId, $transferId, $switchId, $invitation, $version): array {
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
     * @param Closure(Connection, Identity, list<string>): array<string, mixed> $operation 锁后重验身份的原业务。
     */
    private static function write(Connection $connection, Identity $identity, string $tenantId, string $action, string $id, Closure $operation): array
    {
        try {
            return RoleService::authorized($connection, $identity, null, 'customer.transfers.' . $action, $operation, 'customer', $tenantId);
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
        $current = (new IdentityService('customer'))->refresh($connection, $identity);
        if ($current === null || $current->subject() !== $identity->subject()) {
            throw new HttpError(401, 'unauthenticated');
        }
        $permissions = RoleService::permissions($connection, $current, 'customer', $tenantId);
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
