<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\database\Schema;
use app\platform\model\PlatformInstallation;
use app\platform\model\PlatformSession;
use app\platform\model\PlatformUser;
use app\platform\model\Tenant;
use app\platform\mqtt\service\DebugService;
use app\platform\service\TenantService;
use app\system\model\SystemSession;
use app\system\model\SystemUser;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Orm\Connection;
use Type\Orm\Db;
use Type\Orm\Model;
use Type\Orm\ModelQuery;

/**
 * 人员账号与可撤销登录会话；角色按每次请求读取，不在令牌中缓存租户授权。
 */
final class IdentityService
{
    /**
     * 启动时选择独立账号域；网络输入不能选择表或获得另一个域的角色。
     */
    public function __construct(private string $realm = 'system')
    {
        if (!in_array($realm, ['platform', 'system'], true)) {
            throw new \InvalidArgumentException('identity_realm_invalid');
        }
    }

    /**
     * 受控初始化或已授权事务创建；已存在账号不可借创建修改密码或角色。
     * @param string $requestId 宿主生成的32位关联ID；CLI省略时在本次同步事件内生成
     * @param string $actorRealm 固定调用者的审计域；省略时使用账号域，不能来自网络输入
     * @return array<string, mixed> 两身份域仅返回公开账号字段
     * @throws HttpError 账号字段无效或登录名已存在
     */
    public function provision(string $login, string $name, string $password, bool $platform, string $requestId = '', Identity|string $actorId = 'provisioning', string $actorRealm = ''): array
    {
        self::validateAccount($login, $name, $password);
        if ($platform) {
            throw new HttpError(422, 'identity_role_invalid');
        }
        return Db::transaction(function () use ($login, $name, $password, $requestId, $actorId, $actorRealm): array {
            $transaction = Db::connection('default', true);
            if ($this->userQuery()->where('login', '=', $login)->first() !== null) {
                throw new HttpError(409, 'account_exists');
            }
            $id = bin2hex(random_bytes(16));
            $values = [
                'id' => $id, 'login' => $login, 'name' => trim($name), 'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'enabled' => true, 'failures' => 0, 'locked_until' => 0, 'recovery_verified' => true,
            ];
            $user = $this->newUser($values);
            $user->save();
            $this->audit($transaction, $actorId, 'identity.created', $id, 'success', 'operator-command', $requestId, $actorRealm);

            return $this->present($user);
        });
    }

    /**
     * 初始化在建表前同样校验输入；只接受 bcrypt 不截断的显式口令。
     */
    public static function validateAccount(string $login, string $name, string $password): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_.@-]{2,99}$/D', $login) || trim($name) === '' || strlen($name) > 100
            || strlen($password) < 5 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new HttpError(422, 'invalid_account');
        }
    }

    /**
     * 已授权的账号维护事务调用；角色服务负责动作权限、目标保护与最后管理员约束。
     * 锁定账号与登录使用同一行，避免撤销会话后被正在登录的旧口令重新建立会话。
     * @param array<string, mixed> $data 已校验的动作字段和预期 version；密码不进入回执或审计
     * @param string $actorRealm 固定调用者的真实身份域，客户管理动作可来自平台
     * @return array<string, mixed> 更新后的公开账号字段
     */
    public function change(string $id, string $action, array $data, Identity|string $actorId, string $actorRealm = ''): array
    {
        $connection = Db::connection('default', true);
        if ($connection->transactionDepth() < 1) {
            throw new \LogicException('identity_change_requires_authorized_transaction');
        }
        $query = $this->userQuery()->where('id', '=', $id);
        $user = ($connection->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
        if ($user === null) {
            throw new HttpError(404, 'user_not_found');
        }
        $version = (int)$user->version;
        if ($version !== $data['version']) {
            throw new HttpError(409, 'stale_version');
        }
        if ($action === 'update') {
            if (!preg_match('/^[a-z0-9][a-z0-9_.@-]{2,99}$/D', $data['login']) || trim($data['name']) === '' || strlen($data['name']) > 100) {
                throw new HttpError(422, 'invalid_account');
            }
            $duplicate = $this->userQuery()->where('login', '=', $data['login'])->where('id', '!=', $id)->first();
            if ($duplicate !== null) {
                throw new HttpError(409, 'account_exists');
            }
            $user->login = $data['login'];
            $user->name = trim($data['name']);
        } elseif ($action === 'status') {
            $user->enabled = (bool)$data['enabled'];
        } elseif ($action === 'password') {
            self::validateAccount((string)$user->login, (string)$user->name, $data['password']);
            $user->password_hash = password_hash($data['password'], PASSWORD_BCRYPT);
            $user->failures = 0;
            $user->locked_until = 0;
        } elseif ($action !== 'sessions') {
            throw new \InvalidArgumentException('identity_action_invalid');
        }
        if ($action === 'sessions') {
            $user->touch();
        } else {
            $user->save();
        }
        if ($action === 'password' || $action === 'sessions' || ($action === 'status' && !$data['enabled'])) {
            $this->deleteSessions($id);
        }
        $updatedVersion = (int)$user->version;
        AuditLog::append($connection, null, $actorId, 'identity.' . $action, $id, 'success', ['version' => $updatedVersion, 'context' => $this->realm], $actorRealm === '' ? $this->realm : $actorRealm);
        return $this->present($user) + ['enabled' => $user->enabled ? 1 : 0, 'version' => $updatedVersion];
    }

    /**
     * 真实客户以原密码维护本人资料；无租户角色也可调用，目标始终取当前认证主体。
     * 与平台变更使用相同锁顺序；原密码错误计入已有五次/五分钟限额，失败审计不含输入。
     * @param array<string, mixed> $data 固定字段、原密码及预期版本
     * @return array<string, mixed> 公开账号；改密后全部旧会话失效
     */
    public function changeSelf(Identity $identity, string $token, string $action, array $data): array
    {
        $connection = Db::connection('default', true);
        if (!in_array($action, ['update', 'password'], true)) {
            throw new \LogicException('identity_self_action_invalid');
        }
        $result = Db::transaction(function () use ($identity, $token, $action, $data): array {
            $transaction = Db::connection('default', true);
            $lock = PlatformInstallation::query()->master()->where('id', '=', 1);
            $installation = ($transaction->driverName() === 'sqlite' ? $lock : $lock->lockForUpdate())->first();
            if ($installation === null || (int)$installation->schema_version !== Schema::VERSION) {
                throw new HttpError(503, 'installation_incomplete');
            }
            $query = $this->userQuery()->where('id', '=', $identity->subject());
            $user = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
            $current = $this->authenticate($token);
            if ($user === null || $current === null || $current->subject() !== $identity->subject()) {
                throw new HttpError(401, 'unauthenticated');
            }
            if (($current->attributes()['impersonation_id'] ?? '') !== '') {
                throw new HttpError(403, 'personal_credentials_required');
            }
            if ((int)$user->version !== $data['version']) {
                throw new HttpError(409, 'stale_version');
            }
            $now = time();
            if ((int)$user->locked_until > $now || str_contains($data['current_password'], "\0") || !password_verify($data['current_password'], (string)$user->password_hash)) {
                if ((int)$user->locked_until <= $now) {
                    $failures = (int)$user->locked_until > 0 ? 1 : (int)$user->failures + 1;
                    $user->failures = $failures;
                    $user->locked_until = $failures >= 5 ? $now + 300 : 0;
                    $user->save();
                }
                AuditLog::append($transaction, null, $identity->subject(), 'identity.' . $action, $identity->subject(), 'denied', ['reason' => 'current_password_invalid', 'context' => $this->realm], $this->realm);
                return [];
            }
            $user->failures = 0;
            $user->locked_until = 0;
            if ($action === 'update') {
                if (!preg_match('/^[a-z0-9][a-z0-9_.@-]{2,99}$/D', $data['login']) || trim($data['name']) === '' || strlen($data['name']) > 100) {
                    throw new HttpError(422, 'invalid_account');
                }
                $duplicate = $this->userQuery()->where('login', '=', $data['login'])->where('id', '!=', $identity->subject())->first();
                if ($duplicate !== null) {
                    throw new HttpError(409, 'account_exists');
                }
                $user->login = $data['login'];
                $user->name = trim($data['name']);
            } else {
                self::validateAccount((string)$user->login, (string)$user->name, $data['password']);
                $user->password_hash = password_hash($data['password'], PASSWORD_BCRYPT);
                $this->deleteSessions($identity->subject());
            }
            $user->save();
            $updated = $this->present($user) + ['enabled' => $user->enabled ? 1 : 0, 'version' => (int)$user->version];
            AuditLog::append($transaction, null, $identity->subject(), 'identity.' . $action, $identity->subject(), 'success', ['version' => $updated['version'], 'context' => $this->realm], $this->realm);
            return $updated;
        }, 'default', $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
        if ($result === []) {
            throw new HttpError(403, 'current_password_invalid');
        }
        return $result;
    }

    /**
     * 登录失败也提交有限计数；连续五次失败锁定五分钟，每人最多十个会话。
     * @param string $requestId HTTP宿主传入现有请求关联ID，不接受客户端指定值
     * @return array{accessToken: string, expiresAt: int, user: array<string, mixed>}
     * @throws HttpError 无效凭据或账号暂时锁定；不返回账号是否存在
     */
    public function login(string $login, string $password, string $requestId = ''): array
    {
        $connection = Db::connection('default', true);
        if (strlen($login) > 100 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new HttpError(401, 'invalid_credentials');
        }
        $result = Db::transaction(function () use ($login, $password, $requestId): ?array {
            $transaction = Db::connection('default', true);
            $rows = $this->userQuery()->where('login', '=', $login);
            if ($transaction->driverName() !== 'sqlite') {
                $rows = $rows->lockForUpdate();
            }
            $user = $rows->first();
            // 固定的无效口令散列保持未知账号也经过口令校验，不是可登录的默认账号。
            $hash = $user === null ? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.' : (string)$user->password_hash;
            $valid = password_verify($password, $hash);
            $now = time();
            if ($user === null || !$user->enabled || !$user->recovery_verified || (int)$user->locked_until > $now) {
                $this->audit($transaction, 'anonymous', 'identity.login', 'anonymous', 'denied', 'anonymous', $requestId);
                return null;
            }
            if (!$valid) {
                $failures = (int)$user->locked_until > 0 ? 1 : (int)$user->failures + 1;
                $user->failures = $failures;
                $user->locked_until = $failures >= 5 ? $now + 300 : 0;
                $user->save();
                $this->audit($transaction, (string)$user->id, 'identity.login', (string)$user->id, 'denied', 'anonymous', $requestId);
                return null;
            }
            $user->failures = 0;
            $user->locked_until = 0;
            $user->save();
            $sessionQuery = $this->sessionQuery()->where('user_id', '=', $user->id);
            if ($this->realm === 'system') {
                $sessionQuery = $sessionQuery->whereNull('source_session_id');
            }
            $sessions = $sessionQuery->order('created_at')->order('token_hash')->limit(10)->get();
            foreach ($sessions as $index => $session) {
                if ((int)$session->expires_at <= $now || (count($sessions) >= 10 && $index === 0)) {
                    $session->delete();
                }
            }
            $token = bin2hex(random_bytes(32));
            $sessionData = [
                'token_hash' => hash('sha256', $token), 'user_id' => $user->id, 'expires_at' => $now + 28800, 'created_at' => $now,
            ];
            $sessionData['id'] = bin2hex(random_bytes(16));
            if ($this->realm === 'system') {
                $sessionData['actor_id'] = null;
                $sessionData['source_session_id'] = null;
            }
            $session = $this->newSession($sessionData);
            $session->save();
            $this->audit($transaction, (string)$user->id, 'identity.login', (string)$user->id, 'success', 'authenticated-user', $requestId);

            return ['accessToken' => $token, 'expiresAt' => $now + 28800, 'user' => $this->present($user)];
        }, 'default', $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
        if ($result === null) {
            throw new HttpError(401, 'invalid_credentials');
        }
        return $result;
    }

    /**
     * 只查询散列；模拟会话逐次核对准确来源会话及管理资格，不合并平台权限。
     * 返回的上下文标识是独立随机ID，不暴露可认证的令牌或其散列。
     */
    public function authenticate(string $token): ?Identity
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }
        return $this->sessionIdentity('token_hash', hash('sha256', $token));
    }

    /**
     * 业务事务复核可信认证身份的准确会话及模拟来源；公开会话ID不作为外部认证凭据。
     * @return null|Identity 会话已撤销、来源失效或身份域不匹配时返回null
     */
    public function refresh(Identity $identity): ?Identity
    {
        $attributes = $identity->attributes();
        if (($attributes['realm'] ?? '') !== $this->realm || !preg_match('/^[a-f0-9]{32}$/D', $attributes['session_id'] ?? '')) {
            return null;
        }
        $current = $this->sessionIdentity('id', $attributes['session_id']);
        return $current !== null && $current->subject() === $identity->subject() ? $current : null;
    }

    /**
     * 从应用已持久保存的来源重验准确会话；公开上下文字段不得作为外部认证入口。
     * @param array<string, mixed> $context 原受理来源，不允许回退同账号其他会话或另一模拟来源
     */
    public function resume(array $context): ?Identity
    {
        if ($this->realm !== 'system' || ($context['realm'] ?? '') !== 'system' || !is_string($context['user_id'] ?? null)
            || !is_string($context['tenant_id'] ?? null) || !is_string($context['key'] ?? null)) {
            return null;
        }
        $current = $this->refresh(new Identity($context['user_id'], ['realm:system'], $context));
        return $current !== null && self::context($current, $context['tenant_id'])['key'] === $context['key'] ? $current : null;
    }

    /**
     * 交接码消费事务内创建模拟会话；来源及目标资格由消费方和每次认证重复核对。
     */
    public function impersonate(Identity $identity, string $tenantId, string $userId): array
    {
        $connection = Db::connection('default', true);
        $origin = $identity->attributes();
        if ($this->realm !== 'platform' || $connection->transactionDepth() < 1 || ($origin['session_id'] ?? '') === '') {
            throw new \LogicException('impersonation_requires_authorized_transaction');
        }
        if (!TenantService::isAdministrator($tenantId, $userId)) {
            throw new HttpError(403, 'impersonation_target_invalid');
        }
        $now = time();
        $sessions = SystemSession::query()->master()->where('source_session_id', '=', $origin['session_id'])->order('created_at')->order('id')->limit(10)->get();
        foreach ($sessions as $index => $session) {
            if ((int)$session->expires_at <= $now || (count($sessions) >= 10 && $index === 0)) {
                $session->delete();
            }
        }
        $token = bin2hex(random_bytes(32));
        $id = bin2hex(random_bytes(16));
        $expires = min($now + 28800, (int)$origin['expires_at']);
        $session = new SystemSession(['id' => $id, 'token_hash' => hash('sha256', $token), 'user_id' => $userId,
            'actor_id' => $identity->subject(), 'source_session_id' => $origin['session_id'], 'created_at' => $now, 'expires_at' => $expires]);
        $session->save();
        $details = ['impersonation_id' => $id, 'source_session_id' => $origin['session_id'], 'grantee_id' => $userId, 'expires_at' => $expires];
        AuditLog::append($connection, $tenantId, $identity, 'identity.impersonated', $userId, 'success', $details, 'platform');
        $systemIdentity = new Identity($userId, ['realm:system'], ['realm' => 'system', 'tenant_id' => $tenantId,
            'actor_id' => $identity->subject(), 'actor_realm' => 'platform', 'user_id' => $userId, 'session_id' => $id,
            'source_session_id' => $origin['session_id'], 'impersonation_id' => $id, 'expires_at' => $expires]);
        AuditLog::append($connection, $tenantId, $systemIdentity, 'identity.impersonated', $userId, 'success', $details, 'system');
        return ['accessToken' => $token, 'expiresAt' => $expires, 'user' => (new self('system'))->user($userId)];
    }

    /**
     * 公开请求、审计及持久异步事实使用同一身份边界；key只区分上下文，不是授权凭据。
     * @return array<string, mixed> 当前认证元数据，tenantId必须来自已验证的工作区
     */
    public static function context(Identity $identity, string $tenantId = ''): array
    {
        $context = $identity->attributes();
        if ($context === []) {
            return [];
        }
        return $context + ['tenant_id' => $tenantId, 'key' => hash('sha256', json_encode([
            $context['realm'], $context['actor_id'], $identity->subject(), $context['session_id'], $context['source_session_id'], $tenantId,
        ], JSON_THROW_ON_ERROR))];
    }

    /**
     * @return array<string, mixed> 当前有效账号的公开字段。
     */
    public function user(string $id): array
    {
        $user = $this->userQuery()->where('id', '=', $id)->where('enabled', '=', true)->where('recovery_verified', '=', true)->first();
        if ($user === null) {
            throw new HttpError(401, 'unauthenticated');
        }
        return $this->present($user);
    }

    /**
     * 只撤销当前认证令牌，不记录令牌、散列或口令。
     * @param string $requestId HTTP宿主传入现有请求关联ID，不接受客户端指定值
     */
    public function logout(Identity|string $actor, string $token, string $requestId = ''): void
    {
        $connection = Db::connection('default', true);
        $userId = $actor instanceof Identity ? $actor->subject() : $actor;
        Db::transaction(function () use ($actor, $userId, $token, $requestId): void {
            $transaction = Db::connection('default', true);
            RoleService::lockAuthorization();
            $identity = $this->authenticate($token);
            if ($identity !== null) {
                $sessionId = (string)($identity->attributes()['session_id'] ?? '');
                if ($sessionId !== '') {
                    DebugService::revokeSession($transaction, $sessionId, $userId);
                }
            }
            $session = $this->sessionQuery()->where('token_hash', '=', hash('sha256', $token))->where('user_id', '=', $userId)->first();
            if ($session !== null) {
                $session->delete();
            }
            $this->audit($transaction, $identity ?? $actor, 'identity.logout', $userId, 'success', 'authenticated-user', $requestId);
            if ($identity !== null && ($identity->attributes()['impersonation_id'] ?? '') !== '') {
                AuditLog::append($transaction, (string)$identity->attributes()['tenant_id'], $identity, 'identity.logout', $userId, 'success', ['context' => 'system-impersonation'], 'platform');
            }
        }, 'default', $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /**
     * 固定的散列或会话标识查询共享同一认证事实及来源检查。
     */
    private function sessionIdentity(string $column, string $value): ?Identity
    {
        $connection = Db::connection('default', true);
        $query = $this->sessionQuery()->where($column, '=', $value)->with('user');
        if ($this->realm === 'system') {
            $query = $query->with('source_session.user');
        }
        $session = $query->first();
        if ($session === null) {
            return null;
        }
        if ($this->realm === 'system' && Tenant::query()->master()->where('id', '=', $session->tenant_id)->where('enabled', '=', true)->first() === null) {
            return null;
        }
        $user = $session->related('user');
        if ($user === null || !$user->enabled || !$user->recovery_verified || (int)$session->expires_at <= time()) {
            return null;
        }
        $attributes = ['realm' => $this->realm, 'tenant_id' => $this->realm === 'system' ? (string)$session->tenant_id : '', 'actor_id' => (string)$user->id, 'actor_realm' => $this->realm,
            'user_id' => $this->realm === 'system' ? (string)$user->id : '', 'session_id' => (string)$session->id,
            'source_session_id' => '', 'impersonation_id' => '', 'expires_at' => (int)$session->expires_at, 'actor_name' => (string)$user->name];
        if ($this->realm === 'system' && $session->source_session_id !== null) {
            $origin = $session->related('source_session');
            $originUser = $origin === null ? null : $origin->related('user');
            if ($origin === null || $originUser === null || !$originUser->enabled || !$originUser->recovery_verified
                || (int)$origin->expires_at <= time()
                || !TenantService::isAdministrator((string)$session->tenant_id, (string)$user->id)
                || !in_array('platform.tenants.impersonate', RoleService::permissions(new Identity((string)$originUser->id, ['realm:platform'], ['realm' => 'platform']), 'platform'), true)) {
                return null;
            }
            $attributes = array_replace($attributes, ['actor_id' => (string)$originUser->id, 'actor_realm' => 'platform',
                'source_session_id' => (string)$session->source_session_id, 'impersonation_id' => (string)$session->id,
                'expires_at' => min((int)$session->expires_at, (int)$origin->expires_at), 'actor_name' => (string)$originUser->name]);
        }
        return new Identity((string)$user->id, ['realm:' . $this->realm], $attributes);
    }

    /**
     * 当前账号域只映射到一个稳定领域 Model，不再通过运行时表名切换业务实体。
     */
    private function userQuery(): ModelQuery
    {
        return match ($this->realm) {
            'platform' => PlatformUser::query()->master(),
            'system' => SystemUser::query()->master(),
        };
    }

    /**
     * 新账号统一经过具体 Model 的字段赋值和版本契约。
     */
    private function newUser(array $values): Model
    {
        return match ($this->realm) {
            'platform' => new PlatformUser($values),
            'system' => new SystemUser($values),
        };
    }

    /**
     * 会话固定映射到所属身份域，两个域都以 token_hash 为内部主键。
     */
    private function sessionQuery(): ModelQuery
    {
        return match ($this->realm) {
            'platform' => PlatformSession::query()->master(),
            'system' => SystemSession::query()->master(),
        };
    }

    private function newSession(array $values): Model
    {
        return match ($this->realm) {
            'platform' => new PlatformSession($values),
            'system' => new SystemSession($values),
        };
    }

    /**
     * 会话撤销逐模型执行，保留删除事件、事务登记和跨驱动一致性。
     */
    private function deleteSessions(string $userId): void
    {
        foreach ($this->sessionQuery()->where('user_id', '=', $userId)->get() as $session) {
            $session->delete();
        }
    }

    /**
     * 凭据与会话散列始终排除在审计之外。
     */
    private function audit(Connection $connection, Identity|string $actorId, string $action, string $subjectId, string $result, string $role, string $requestId, string $actorRealm = ''): void
    {
        AuditLog::append(
            $connection,
            null,
            $actorId,
            $action,
            $subjectId,
            $result,
            ['context' => $this->realm, 'reason' => $action === 'identity.created' ? 'authorized-account' : 'session'],
            $actorRealm === '' ? $this->realm : $actorRealm
        );
    }

    /**
     * 身份模型的公开投影；口令、锁定计数及会话字段不进入响应。
     */
    private function present(Model $user): array
    {
        return ['id' => (string)$user->id, 'login' => (string)$user->login, 'name' => (string)$user->name,
            'realm' => $this->realm, 'version' => (int)$user->version];
    }
}
