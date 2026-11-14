<?php

declare(strict_types=1);

namespace app\center\service;

use app\center\model\ApplicationGrant;
use app\center\model\ClientApplication;
use app\center\model\Device;
use app\center\model\DeviceOwnership;
use app\common\service\AuditLog;
use app\common\service\IdentityService;
use app\common\service\RoleService;
use app\platform\model\Tenant;
use app\platform\mqtt\service\AccessService;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Orm\Connection;
use Type\Orm\Db;
use Type\Runtime\ExecutionScope;

/** 逐应用、逐使用关系维护通信权限，并与 Broker 授权发布、撤权和人员审计共同提交。 */
final class ApplicationGrantService
{
    private const FIELDS = ['id', 'tenant_id', 'application_id', 'device_id', 'ownership_id', 'receive', 'send', 'subscribe', 'version', 'created_at', 'updated_at'];

    /** 有界查询只读取授权和真实当前关系，不因打开页面推进任何撤权流程。 */
    public function listing(Identity $identity, string $realm, string $tenantId, string $application, int $page, int $perPage): array
    {
        $view = (new ClientApplicationService())->detail($identity, $realm, $tenantId, $application);
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) {
            throw new HttpError(422, 'invalid_pagination');
        }
        return ExecutionScope::current()->run(static function (ExecutionScope $scope) use ($application, $page, $perPage, $view): array {
            $rows = \_query(ApplicationGrant::query()->master()->where('application_id', '=', $application), [
                'page' => $page, 'page_size' => $perPage,
            ])->order(['created_at' => 'created_at', 'id' => 'id'], ['created_at' => 'DESC', 'id' => 'ASC'])
                ->paginatePage($perPage, 100, 100000);
            $items = [];
            foreach ($rows->items() as $model) {
                $items[] = self::project($model);
            }
            return ['items' => $items, 'total' => $rows->total(), 'page' => $page, 'per_page' => $perPage] + $view;
        }, ['tenant_id' => $view['application']['tenant_id']]);
    }

    /**
     * version=0 仅创建不存在的关系授权；撤销保存全 false，历史版本不能重新授予权限。
     * @param array{ownership_id:string,version:int,receive:bool,send:bool,subscribe:bool} $data 明确权限和表单版本
     * @return array{grant:array<string,mixed>,application:array<string,mixed>,changed:bool}
     * @throws HttpError 缺少权限、客户/应用/设备无效、关系已更改、旧表单或发布尚待隔离
     */
    public function save(Identity $identity, string $realm, string $tenantId, string $application, string $deviceId, array $data): array
    {
        if ($data['subscribe'] && !$data['receive']) {
            throw new HttpError(422, 'application_subscription_requires_receive');
        }
        try {
            return RoleService::authorized($identity, null, ($realm === 'system' ? 'center' : 'platform') . '.applications.authorize', static function (Identity $current, array $permissions) use ($realm, $tenantId, $application, $deviceId, $data): array {
                $tenant = Tenant::query()->master()->find($tenantId);
                if ($tenant === null) {
                    throw new HttpError(404, 'tenant_not_found');
                }
                $enabled = $data['receive'] || $data['send'] || $data['subscribe'];
                if ($enabled && !$tenant->getEnabled()) {
                    throw new HttpError(409, 'application_system_disabled');
                }
                return ExecutionScope::current()->run(static function (ExecutionScope $scope) use ($current, $realm, $tenantId, $application, $deviceId, $data, $enabled): array {
                    $app = ClientApplication::query()->master()->find($application);
                    if ($app === null) {
                        throw new HttpError(404, 'application_not_found');
                    }
                    if (!$app->getRecoveryVerified()) {
                        throw new HttpError(409, 'application_recovery_required');
                    }
                    if ($enabled && !$app->getEnabled()) {
                        throw new HttpError(409, 'application_disabled');
                    }
                    $grant = ApplicationGrant::query()->master()->where('application_id', '=', $application)->where('ownership_id', '=', $data['ownership_id'])->first();
                    if (($grant === null ? 0 : $grant->getVersion()) !== $data['version'] || $data['version'] >= 2147483646 || $app->getVersion() >= 2147483646) {
                        throw new HttpError(409, 'stale_version');
                    }
                    if ($grant !== null && $grant->getDeviceId() !== $deviceId) {
                        throw new HttpError(409, 'application_device_relationship_changed');
                    }
                    $device = Device::query()->master()->find($deviceId);
                    $ownership = DeviceOwnership::query()->master()->where('id', '=', $data['ownership_id'])
                        ->where('device_id', '=', $deviceId)->first();
                    if ($enabled && ($device === null || $ownership === null || $ownership->getEndedAt() !== null
                        || ($ownership->getExpiresAt() !== null && $ownership->getExpiresAt() <= time()) || $device->getOwnershipId() !== $data['ownership_id'])) {
                        throw new HttpError(409, 'application_device_relationship_changed');
                    }
                    if ($enabled && (!$device->getRecoveryVerified()
                        || !in_array($device->getLifecycle(), ['inactive', 'enabled'], true) || $device->getTransferId() !== null)) {
                        throw new HttpError(409, 'application_device_unavailable');
                    }
                    if ($grant === null && !$enabled) {
                        throw new HttpError(422, 'application_grant_empty');
                    }
                    $connection = Db::connection('default', true);
                    $changed = $grant === null || $grant->getReceive() !== $data['receive'] || $grant->getSend() !== $data['send'] || $grant->getSubscribe() !== $data['subscribe'];
                    if (!$changed) {
                        return ['grant' => self::project($grant), 'application' => ClientApplicationService::publicProjection($app), 'changed' => false];
                    }
                    $version = AccessService::currentVersion($connection);
                    $origin = IdentityService::context($current, $tenantId);
                    AccessService::publishChange(
                        $connection,
                        $tenantId,
                        $origin['actor_id'],
                        $origin['actor_realm'],
                        $version,
                        static function (Connection $transaction) use ($grant, $app, $current, $realm, $tenantId, $application, $deviceId, $data, $version): void {
                            if ($grant === null) {
                                ApplicationGrant::create(['id' => bin2hex(random_bytes(16)), 'application_id' => $application, 'device_id' => $deviceId,
                                    'ownership_id' => $data['ownership_id'], 'receive' => $data['receive'], 'send' => $data['send'], 'subscribe' => $data['subscribe']]);
                            } else {
                                $grant->setReceive($data['receive']);
                                $grant->setSend($data['send']);
                                $grant->setSubscribe($data['subscribe']);
                                $grant->save();
                            }
                            $topics = DeviceService::topics(['id' => $deviceId, 'tenant_id' => $tenantId, 'ownership_id' => $data['ownership_id']]);
                            foreach ([$topics['publish'], $topics['subscribe']] as $topic) {
                                $transaction->table('platform_mqtt_access_grant')->where('principal_id', '=', $application)->where('topic', '=', $topic)->delete();
                            }
                            if ($data['receive']) {
                                // 零订阅标记仍保存接收范围，使其变更纳入发布身份；它本身不建立 MQTT 订阅。
                                $transaction->table('platform_mqtt_access_grant')->insert(['id' => bin2hex(random_bytes(16)), 'principal_id' => $application,
                                    'topic' => $topics['publish'], 'publish' => 0, 'subscribe' => $data['subscribe'] ? 1 : 0, 'max_qos' => 2]);
                            }
                            if ($data['send']) {
                                $transaction->table('platform_mqtt_access_grant')->insert(['id' => bin2hex(random_bytes(16)), 'principal_id' => $application,
                                    'topic' => $topics['subscribe'], 'publish' => 1, 'subscribe' => 0, 'max_qos' => 2]);
                            }
                            $app->setAccessVersion($version + 1);
                            $app->save();
                            AuditLog::append(
                                $transaction,
                                $tenantId,
                                $current,
                                'application.device_authorization',
                                $application,
                                'success',
                                ['device_id' => $deviceId, 'ownership_id' => $data['ownership_id'], 'version' => $data['version'] + 1,
                                    'receive_allowed' => $data['receive'] ? 1 : 0, 'send_allowed' => $data['send'] ? 1 : 0, 'subscribe_allowed' => $data['subscribe'] ? 1 : 0],
                                $realm
                            );
                        },
                        $app->getAccessStarted() ? 'app:' . $application : ''
                    );
                    $saved = ApplicationGrant::query()->master()->where('application_id', '=', $application)->where('ownership_id', '=', $data['ownership_id'])->first();
                    if ($saved === null) {
                        throw new \LogicException('application_grant_save_missing');
                    }
                    return ['grant' => self::project($saved), 'application' => ClientApplicationService::publicProjection($app), 'changed' => true];
                }, ['tenant_id' => $tenantId]);
            }, $realm, $realm === 'platform' ? 'platform' : $tenantId);
        } catch (HttpError $failure) {
            AuditLog::append(
                Db::connection('default', true),
                $tenantId,
                $identity,
                'application.device_authorization',
                $application,
                in_array($failure->status(), [401, 403, 404], true) ? 'denied' : 'failed',
                ['device_id' => $deviceId, 'ownership_id' => $data['ownership_id'], 'reason' => $failure->errorCode()],
                $realm
            );
            throw $failure;
        }
    }

    /**
     * 每次发布、订阅建立、恢复及交付均核对当前关系；密码认证不授予设备范围。
     * @internal 只消费 DeviceAccess 从已认证连接形成的身份及 MQTT 动作
     */
    public static function allows(Connection $connection, array $application, string $topic, string $operation, int $qos): bool
    {
        if (!in_array($qos, [0, 1, 2], true) || !in_array($operation, ['publish', 'subscribe'], true)
            || preg_match('#^iot/([a-f0-9]{32})/devices/([a-f0-9]{32})/epochs/([a-f0-9]{32})/raw/(up|down)$#D', $topic, $matches) !== 1
            || $matches[1] !== $application['tenant_id']) {
            return false;
        }
        $grant = $connection->table('center_application_grant')->where('application_id', '=', $application['id'])->where('tenant_id', '=', $application['tenant_id'])
            ->where('device_id', '=', $matches[2])->where('ownership_id', '=', $matches[3])->first();
        if ($grant === null || ($operation === 'publish'
            ? $matches[4] !== 'down' || (int)$grant['send'] !== 1
            : $matches[4] !== 'up' || (int)$grant['receive'] !== 1 || (int)$grant['subscribe'] !== 1)) {
            return false;
        }
        $device = $connection->table('center_device')->where('id', '=', $matches[2])->where('tenant_id', '=', $application['tenant_id'])->where('ownership_id', '=', $matches[3])->first();
        if ($device === null || (int)$device['recovery_verified'] !== 1 || $device['transfer_id'] !== null
            || !in_array($device['lifecycle'], ['inactive', 'enabled'], true)) {
            return false;
        }
        return TransferService::ownershipCurrent($connection, $device);
    }

    private static function project(ApplicationGrant $model): array
    {
        $row = $model->project(self::FIELDS);
        $device = Device::query()->master()->where('id', '=', $row['device_id'])->where('ownership_id', '=', $row['ownership_id'])->first();
        $ownership = DeviceOwnership::query()->master()->where('id', '=', $row['ownership_id'])
            ->where('device_id', '=', $row['device_id'])->whereNull('ended_at')->first();
        $row['current'] = $device !== null && $ownership !== null;
        $row['device_name'] = $device?->getName() ?? '';
        $row['topics'] = $row['current'] ? DeviceService::topics($device->project(['id', 'tenant_id', 'ownership_id'])) : null;
        return $row;
    }
}
