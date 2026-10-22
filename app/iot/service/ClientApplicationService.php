<?php

declare(strict_types=1);

namespace app\iot\service;

use app\broker\service\AccessService;
use app\broker\service\ResourceObservations;
use app\common\model\Tenant;
use app\common\service\AuditLog;
use app\common\service\IdentityService;
use app\common\service\RoleService;
use app\iot\model\ClientApplication;
use Closure;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Mqtt\AccessIdentity;
use Type\Orm\Connection;
use Type\Orm\Db;
use Type\Runtime\ExecutionScope;

/** 接入应用与密钥的业务所有者；人员授权、应用状态和设备通信权限分别执行。 */
final class ClientApplicationService
{
    private const FIELDS = ['id', 'tenant_id', 'name', 'enabled', 'key_version', 'access_version', 'recovery_verified', 'version', 'created_at', 'updated_at'];

    /** @return array<string, mixed> 当前范围的有界目录；秘密与请求摘要不进入普通响应。 */
    public function listing(Identity $identity, string $realm, string $tenantId, int $page, int $perPage): array
    {
        $context = self::context($identity, $realm, $tenantId);
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) {
            throw new HttpError(422, 'invalid_pagination');
        }
        if ($realm === 'customer') {
            return ExecutionScope::current()->run(static function (ExecutionScope $scope) use ($page, $perPage, $context): array {
                $result = ClientApplication::query()->master()->orderBy('created_at', 'DESC')->orderBy('id')->paginate($page, $perPage);
                $items = [];
                foreach ($result->items() as $model) {
                    $items[] = self::publicProjection($model);
                }
                return ['items' => $items, 'total' => $result->total(), 'page' => $page, 'per_page' => $perPage, 'context' => $context];
            }, ['tenant_id' => $tenantId]);
        }
        // 平台跨客户目录使用显式投影，业务写入仍在可信租户范围内使用 Model。
        $rows = Db::connection('default', true)->table('iot_applications')->select([...self::FIELDS, 'credential_id']);
        if ($tenantId !== '') {
            $rows = $rows->where('tenant_id', '=', $tenantId);
        }
        $total = (int) $rows->aggregate('COUNT');
        $items = [];
        foreach ($rows->orderBy('created_at', 'DESC')->orderBy('id')->limit($perPage, ($page - 1) * $perPage)->get() as $row) {
            $items[] = self::project($row);
        }
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'context' => $context];
    }

    /** 读取真实密钥状态和发布回执；GET 不更改凭据、应用版本或在线观察。 */
    public function detail(Identity $identity, string $realm, string $tenantId, string $id): array
    {
        $context = self::context($identity, $realm, $tenantId);
        $query = Db::connection('default', true)->table('iot_applications')->select([...self::FIELDS, 'credential_id'])->where('id', '=', $id);
        $row = ($realm === 'customer' ? $query->where('tenant_id', '=', $tenantId) : $query)->first();
        if ($row === null) {
            throw new HttpError(404, 'application_not_found');
        }
        return ['application' => self::project($row), 'context' => $context];
    }

    /**
     * 同一请求 ID 和客户/名称只创建一次；响应丢失后确认原身份，不重新交付旧秘密。
     * @param array{id:string,name:string} $data 已校验字段，客户由明确管理范围提供。
     * @return array{application:array<string,mixed>,credential:?array<string,mixed>,replayed:bool}
     */
    public function create(Identity $identity, string $realm, string $tenantId, array $data): array
    {
        return $this->write($identity, $realm, $tenantId, $data['id'], 'create', static function (Identity $current) use ($data, $tenantId, $realm): array {
            $hash = hash('sha256', json_encode([$tenantId, $data['name']], JSON_THROW_ON_ERROR));
            $existing = ClientApplication::query()->master()->find($data['id']);
            if ($existing !== null) {
                if (!hash_equals($existing->getRequestHash(), $hash)) {
                    throw new HttpError(409, 'application_request_conflict');
                }
                return ['application' => self::publicProjection($existing), 'credential' => null, 'replayed' => true];
            }
            $connection = Db::connection('default', true);
            // 协议身份共享主体索引；即使用户名分域，也不能复用另一设备的主体 ID。
            if ($connection->table('broker_access_principals')->where('id', '=', $data['id'])->first() !== null
                || $connection->table('iot_devices')->where('id', '=', $data['id'])->first() !== null) {
                throw new HttpError(409, 'application_request_conflict');
            }
            $version = AccessService::currentVersion($connection);
            $secret = bin2hex(random_bytes(32));
            $credentialId = bin2hex(random_bytes(16));
            $origin = IdentityService::context($current, $tenantId);
            AccessService::publishChange(
                $connection,
                $tenantId,
                $origin['actor_id'],
                $origin['actor_realm'],
                $version,
                static function (Connection $transaction) use ($current, $realm, $tenantId, $data, $hash, $secret, $credentialId, $version): void {
                    $now = time();
                    $transaction->table('broker_access_principals')->insert(['id' => $data['id'], 'tenant_id' => $tenantId,
                        'name' => $data['name'], 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now]);
                    $transaction->table('broker_access_credentials')->insert(['id' => $credentialId, 'principal_id' => $data['id'],
                        'login' => 'app:' . $data['id'], 'secret_hash' => hash('sha256', $secret), 'credential_version' => 1,
                        'status' => 'active', 'created_at' => $now, 'revoked_at' => null]);
                    ClientApplication::create(['id' => $data['id'], 'name' => $data['name'], 'enabled' => true, 'credential_id' => $credentialId,
                        'key_version' => 1, 'access_version' => $version + 1, 'request_hash' => $hash, 'access_started' => false, 'recovery_verified' => true]);
                    AuditLog::append($transaction, $tenantId, $current, 'application.create', $data['id'], 'success', ['version' => 1], $realm);
                }
            );
            $model = ClientApplication::query()->master()->find($data['id']);
            if ($model === null) {
                throw new \LogicException('application_create_missing');
            }
            return ['application' => self::publicProjection($model), 'credential' => self::credential($model, $secret), 'replayed' => false];
        });
    }

    /**
     * 资料、启停与密钥操作使用独立权限和表单版本；新密钥只在此次受控响应中交付。
     * @param array{version:int,name?:string} $data 不允许修改客户、当前密钥代次或授权版本。
     * @return array{application:array<string,mixed>,credential:?array<string,mixed>}
     * @throws HttpError 旧表单、客户/应用停用、密钥动作不适用或前次发布仍等待隔离。
     */
    public function change(Identity $identity, string $realm, string $tenantId, string $id, string $action, array $data): array
    {
        if (!in_array($action, ['update', 'enable', 'disable', 'rotate', 'revoke', 'issue'], true)) {
            throw new HttpError(422, 'application_action_invalid');
        }
        return $this->write($identity, $realm, $tenantId, $id, $action, static function (Identity $current) use ($realm, $tenantId, $id, $action, $data): array {
            $model = ClientApplication::query()->master()->find($id);
            if ($model === null) {
                throw new HttpError(404, 'application_not_found');
            }
            if ($model->getVersion() !== $data['version'] || $data['version'] >= 2147483646 || $model->getKeyVersion() >= 2147483646) {
                throw new HttpError(409, 'stale_version');
            }
            if (!$model->getRecoveryVerified()) {
                throw new HttpError(409, 'application_recovery_required');
            }
            $connection = Db::connection('default', true);
            $stored = $connection->table('broker_access_credentials')->where('id', '=', $model->getCredentialId())->where('principal_id', '=', $id)->first();
            if ($stored === null || (int) $stored['credential_version'] !== $model->getKeyVersion()) {
                throw new HttpError(409, 'application_recovery_required');
            }
            if (in_array($action, ['rotate', 'issue'], true) && !$model->getEnabled()) {
                throw new HttpError(409, 'application_disabled');
            }
            if ((in_array($action, ['rotate', 'revoke'], true) && $stored['status'] !== 'active') || ($action === 'issue' && $stored['status'] !== 'revoked')) {
                throw new HttpError(409, 'application_key_unavailable');
            }
            $secret = in_array($action, ['rotate', 'issue'], true) ? bin2hex(random_bytes(32)) : null;
            $version = AccessService::currentVersion($connection);
            $origin = IdentityService::context($current, $tenantId);
            AccessService::publishChange(
                $connection,
                $tenantId,
                $origin['actor_id'],
                $origin['actor_realm'],
                $version,
                static function (Connection $transaction) use ($current, $realm, $tenantId, $id, $model, $action, $data, $secret, $version): void {
                    if ($action === 'update') {
                        $model->setName($data['name']);
                    } elseif (in_array($action, ['enable', 'disable'], true)) {
                        $model->setEnabled($action === 'enable');
                    } else {
                        $model->setKeyVersion($model->getKeyVersion() + 1);
                        $changes = ['credential_version' => $model->getKeyVersion(), 'status' => $action === 'revoke' ? 'revoked' : 'active',
                            'revoked_at' => $action === 'revoke' ? time() : null];
                        if ($secret !== null) {
                            $changes['secret_hash'] = hash('sha256', $secret);
                        }
                        $transaction->table('broker_access_credentials')->where('id', '=', $model->getCredentialId())->where('principal_id', '=', $id)->update($changes);
                    }
                    $transaction->table('broker_access_principals')->where('id', '=', $id)->where('tenant_id', '=', $tenantId)
                        ->update(['name' => $model->getName(), 'enabled' => $model->getEnabled() ? 1 : 0, 'updated_at' => time()]);
                    $model->setAccessVersion($version + 1);
                    $model->save();
                    AuditLog::append($transaction, $tenantId, $current, 'application.' . $action, $id, 'success', ['version' => $model->getVersion()], $realm);
                },
                $model->getAccessStarted() ? 'app:' . $id : ''
            );
            return ['application' => self::publicProjection($model), 'credential' => $secret === null ? null : self::credential($model, $secret)];
        });
    }

    /**
     * MQTT 受控工作进程复用同一管理库；只接受摘要与已认证快照，不接收明文密钥。
     * 首次认证与人员撤权共用写锁，先记录可能产生持久会话的事实，再向 Broker 返回身份。
     * @internal 网络、TLS、会话及 QoS 均由既有 DeviceAccess/Broker 拥有。
     * @param array<string,mixed> $request 受控进程请求。
     * @return array<string,mixed> 认证结果或当前动作授权。
     */
    public static function access(Connection $connection, array $request): array
    {
        if ($request['action'] === 'disconnected') {
            ResourceObservations::disconnected($connection, $request['node_id'], $request['run_id'], $request['owner_id']);
            return ['allowed' => true];
        }
        if ($request['action'] === 'authenticate') {
            return $connection->transaction(static function (Connection $transaction) use ($request): array {
                RoleService::lockAuthorization();
                return self::accessCurrent($transaction, $request);
            }, $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default');
        }
        return self::accessCurrent($connection, $request);
    }

    /** 应用身份、客户状态和当前设备授权逐动作核对，无匹配授权默认拒绝 Topic。 */
    private static function accessCurrent(Connection $connection, array $request): array
    {
        $username = (string) ($request['username'] ?? '');
        $id = preg_match('/^app:[a-f0-9]{32}$/D', $username) === 1 ? substr($username, 4) : '';
        $row = $id === '' ? null : $connection->table('iot_applications')->where('id', '=', $id)->first();
        $credential = $row === null ? null : $connection->table('broker_access_credentials')->where('id', '=', $row['credential_id'])->where('principal_id', '=', $id)->first();
        $principal = $row === null ? null : $connection->table('broker_access_principals')->where('id', '=', $id)->where('tenant_id', '=', $row['tenant_id'])->first();
        if ($request['action'] === 'connected') {
            // CONNACK 已发送后才产生观察；期间可发生换证，旧连接事实不能令整个 Broker 故障。
            // 主体、凭据标识与历史代次必须仍对应本应用，授权有效性由每次动作及撤权流程检查。
            $observed = isset($request['access_identity']) && is_array($request['access_identity']) ? AccessIdentity::fromData($request['access_identity']) : null;
            if ($row === null || $credential === null || $principal === null || $observed === null || (int) $row['access_started'] !== 1
                || $request['client_id'] !== $username || $observed->principalId !== $id || $observed->credentialId !== $row['credential_id']
                || $observed->authenticationMethod !== 'connect' || $observed->credentialVersion > (int) $row['key_version']
                || !isset($request['resource']) || ($request['resource']['resource_scope'] ?? null) !== 'iot:' . $row['tenant_id']) {
                throw new \RuntimeException('application_observation_identity_invalid');
            }
            ResourceObservations::connected($connection, $request['node_id'], $request['run_id'], $request['resource'], (int) $request['observed_at']);
            return ['allowed' => true];
        }
        $allowed = $row !== null && $credential !== null && $principal !== null && $request['client_id'] === $username
            && (int) $row['enabled'] === 1 && (int) $row['recovery_verified'] === 1 && (int) $principal['enabled'] === 1
            && (int) $principal['recovery_verified'] === 1 && (int) $credential['recovery_verified'] === 1
            && $credential['status'] === 'active' && $credential['login'] === $username && (int) $credential['credential_version'] === (int) $row['key_version']
            && $connection->table('iot_tenants')->where('id', '=', $row['tenant_id'])->where('enabled', '=', 1)->first() !== null;
        $identity = $allowed ? new AccessIdentity($id, $credential['id'], (int) $row['key_version']) : null;
        if ($allowed && $request['action'] === 'authenticate') {
            $allowed = hash_equals($credential['secret_hash'], (string) $request['verifier']) && $request['secure'] && $request['protocol'] === 5
                && $request['keep_alive'] === 30 && $request['expiry'] === 86400;
            if ($allowed && (int) $row['access_started'] !== 1) {
                // 认证事实不是业务编辑，不推进表单版本；它在发出身份结论前持久保存。
                $connection->table('iot_applications')->where('id', '=', $id)->update(['access_started' => 1]);
            }
        } elseif ($allowed) {
            $allowed = isset($request['access_identity']) && is_array($request['access_identity'])
                && $identity->matches(AccessIdentity::fromData($request['access_identity']));
            $allowed = $allowed && $request['action'] === 'authorize'
                && ApplicationGrantService::allows($connection, $row, $request['topic'], $request['operation'], (int) $request['qos']);
        }
        if (!$allowed) {
            AuditLog::append(
                $connection,
                $row === null ? null : $row['tenant_id'],
                $row === null ? 'mqtt' : $id,
                $request['action'] === 'authorize' ? 'application.topic_denied' : 'application.connection_denied',
                $row === null ? 'unidentified' : $id,
                'denied',
                ['context' => 'application', 'reason' => 'application_forbidden'],
                'customer'
            );
            return ['allowed' => false];
        }
        return ['allowed' => true] + ($request['action'] === 'authenticate'
            ? ['access_identity' => $identity->data(), 'resource_scope' => 'iot:' . $row['tenant_id']] : []);
    }

    /** @param Closure(Identity):array<string,mixed> $operation 已验证人员来源和租户的事务业务。 */
    private function write(Identity $identity, string $realm, string $tenantId, string $id, string $action, Closure $operation): array
    {
        $permission = in_array($action, ['rotate', 'revoke', 'issue'], true) ? 'keys' : $action;
        try {
            return RoleService::authorized(
                $identity,
                null,
                $realm . '.applications.' . $permission,
                static function (Identity $current, array $permissions) use ($tenantId, $action, $operation): array {
                    $tenant = Tenant::query()->master()->find($tenantId);
                    if ($tenant === null) {
                        throw new HttpError(404, 'tenant_not_found');
                    }
                    if (!$tenant->getEnabled() && !in_array($action, ['disable', 'revoke'], true)) {
                        throw new HttpError(409, 'application_customer_disabled');
                    }
                    return ExecutionScope::current()->run(static fn (ExecutionScope $scope): array => $operation($current), ['tenant_id' => $tenantId]);
                },
                $realm,
                $realm === 'admin' ? 'platform' : $tenantId
            );
        } catch (HttpError $failure) {
            AuditLog::append(
                Db::connection('default', true),
                $tenantId,
                $identity,
                'application.' . $action,
                $id,
                in_array($failure->status(), [401, 403, 404], true) ? 'denied' : 'failed',
                ['reason' => $failure->errorCode()],
                $realm
            );
            throw $failure;
        }
    }

    /** @return array<string,mixed> 当前人员权限只影响管理入口，不成为应用通信身份。 */
    private static function context(Identity $identity, string $realm, string $tenantId): array
    {
        $context = RoleService::readContext($identity, $realm, $realm === 'admin' ? '' : $tenantId, 'applications.read');
        $context['menus'] = RoleService::menus($realm, $context['permissions']);
        return $context;
    }

    /** @internal 已在可信租户范围读取的应用，只投影公开状态及真实发布回执。 */
    public static function publicProjection(ClientApplication $model): array
    {
        return self::project($model->project(self::FIELDS) + ['credential_id' => $model->getCredentialId()]);
    }

    /** 凭据仅投影状态与代次，发布状态取真实节点回执，不按 enabled 猜测网络隔离结果。 */
    private static function project(array $row): array
    {
        $connection = Db::connection('default', true);
        $credential = $connection->table('broker_access_credentials')->where('id', '=', $row['credential_id'])->where('principal_id', '=', $row['id'])->first();
        $publication = $connection->table('broker_access_revisions')->select(['id'])->where('version', '=', (int) $row['access_version'])->where('tenant_id', '=', $row['tenant_id'])->first();
        if ($publication === null) {
            throw new \RuntimeException('application_publication_missing');
        }
        $row['key'] = ['version' => (int) $row['key_version'], 'active' => $credential !== null && $credential['status'] === 'active'
            && (int) $credential['credential_version'] === (int) $row['key_version'] && (int) $credential['recovery_verified'] === 1];
        $row['publication'] = AccessService::revisions($connection, $row['tenant_id'], $publication['id'], 1, 1)['item'];
        unset($row['credential_id'], $row['key_version'], $row['access_version']);
        foreach (['version', 'created_at', 'updated_at'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        $row['enabled'] = (bool) $row['enabled'];
        $row['recovery_verified'] = (bool) $row['recovery_verified'];
        return $row;
    }

    /** 一份应用固定一个受管 MQTT Client ID，多个外部系统应分别建应用而非共享会话所有权。 */
    private static function credential(ClientApplication $model, string $secret): array
    {
        return ['client_id' => 'app:' . $model->getId(), 'username' => 'app:' . $model->getId(), 'password' => $secret,
            'version' => $model->getKeyVersion(), 'protocol' => 5, 'tls' => true, 'keep_alive' => 30, 'session_expiry' => 86400];
    }
}
