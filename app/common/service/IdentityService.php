<?php

declare(strict_types=1);

namespace app\common\service;

use app\broker\model\BrokerUser;
use app\broker\model\BrokerSession;
use app\common\model\AdminUser;
use app\common\model\AdminSession;
use app\common\model\CustomerUser;
use app\common\model\CustomerSession;
use app\broker\service\DebugService;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Orm\Connection;
use Type\Orm\Db;
use Type\Orm\Model;
use Type\Orm\ModelQuery;

/** 人员账号与可撤销登录会话；角色按每次请求读取，不在令牌中缓存租户授权。 */
final class IdentityService
{
    /** 启动时选择独立账号域；网络输入不能选择表或获得另一个域的角色。 */
    public function __construct(private string $realm = 'customer')
    {
        if (!in_array($realm, ['admin', 'customer', 'broker'], true)) {
            throw new \InvalidArgumentException('identity_realm_invalid');
        }
    }

    /**
     * 受控初始化或已授权事务创建；已存在账号不可借创建修改密码或角色。
     * @param string $requestId 宿主生成的32位关联ID；CLI省略时在本次同步事件内生成。
     * @param string $actorRealm 固定调用者的审计域；省略时使用账号域，不能来自网络输入。
     * @return array<string, mixed> Broker 保留自己的管理员字段，新双端不使用平台布尔标记。
     * @throws HttpError 账号字段无效或登录名已存在。
     */
    public function provision(string $login, string $name, string $password, bool $platform, string $requestId = '', string|Identity $actorId = 'provisioning', string $actorRealm = ''): array
    {
        self::validateAccount($login, $name, $password);
        if ($this->realm !== 'broker' && $platform) {
            throw new HttpError(422, 'identity_role_invalid');
        }
        return Db::transaction(function () use ($login, $name, $password, $platform, $requestId, $actorId, $actorRealm): array {
            $transaction = Db::connection('default', true);
            if ($this->userQuery()->where('login', '=', $login)->first() !== null) {
                throw new HttpError(409, 'account_exists');
            }
            $id = bin2hex(random_bytes(16));
            $values = [
                'id' => $id, 'login' => $login, 'name' => trim($name), 'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'enabled' => true, 'failures' => 0, 'locked_until' => 0, 'recovery_verified' => true, 'created_at' => time(),
            ];
            if ($this->realm === 'broker') {
                $values['platform_admin'] = $platform;
            } else {
                $values['version'] = 1;
            }
            $user = $this->newUser($values);
            $user->save();
            $this->audit($transaction, $actorId, 'identity.created', $id, 'success', 'operator-command', $requestId, $actorRealm);

            return $this->present($user);
        });
    }

    /** 初始化在建表前同样校验输入；只接受 bcrypt 不截断的显式口令。 */
    public static function validateAccount(string $login, string $name, string $password): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_.@-]{2,99}$/D', $login) || trim($name) === '' || strlen($name) > 100
            || strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new HttpError(422, 'invalid_account');
        }
    }

    /**
     * 已授权的账号维护事务调用；角色服务负责动作权限、目标保护与最后管理员约束。
     * 锁定账号与登录使用同一行，避免撤销会话后被正在登录的旧口令重新建立会话。
     * @param array<string, mixed> $data 已校验的动作字段和预期 version；密码不进入回执或审计。
     * @param string $actorRealm 固定调用者的真实身份域，客户管理动作可来自平台。
     * @return array<string, mixed> 更新后的公开账号字段。
     */
    public function change(string $id, string $action, array $data, string|Identity $actorId, string $actorRealm = ''): array
    {
        $connection = Db::connection('default', true);
        if ($this->realm === 'broker' || $connection->transactionDepth() < 1) {
            throw new \LogicException('identity_change_requires_authorized_transaction');
        }
        $query = $this->userQuery()->where('id', '=', $id);
        $user = ($connection->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
        if ($user === null) {
            throw new HttpError(404, 'user_not_found');
        }
        $version = (int) $user->get('version');
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
            $user->set('login', $data['login']);
            $user->set('name', trim($data['name']));
        } elseif ($action === 'status') {
            $user->set('enabled', (bool) $data['enabled']);
        } elseif ($action === 'password') {
            self::validateAccount((string) $user->get('login'), (string) $user->get('name'), $data['password']);
            $user->set('password_hash', password_hash($data['password'], PASSWORD_BCRYPT));
            $user->set('failures', 0);
            $user->set('locked_until', 0);
        } elseif ($action !== 'sessions') {
            throw new \InvalidArgumentException('identity_action_invalid');
        }
        $user->set('version', $version + 1);
        $user->save();
        if ($action === 'password' || $action === 'sessions' || ($action === 'status' && !$data['enabled'])) {
            $this->deleteSessions($id);
        }
        $updatedVersion = (int) $user->get('version');
        AuditLog::append($connection, null, $actorId, 'identity.' . $action, $id, 'success', ['version' => $updatedVersion, 'context' => $this->realm], $actorRealm === '' ? $this->realm : $actorRealm);
        return $this->present($user) + ['enabled' => $user->get('enabled') ? 1 : 0, 'version' => $updatedVersion];
    }

    /**
     * 真实客户以原密码维护本人资料；无租户角色也可调用，目标始终取当前认证主体。
     * 与平台变更使用相同锁顺序；原密码错误计入已有五次/五分钟限额，失败审计不含输入。
     * @param array<string, mixed> $data 固定字段、原密码及预期版本。
     * @return array<string, mixed> 公开账号；改密后全部旧会话失效。
     */
    public function changeSelf(Identity $identity, string $token, string $action, array $data): array
    {
        $connection = Db::connection('default', true);
        if ($this->realm !== 'customer' || !in_array($action, ['update', 'password'], true)) {
            throw new \LogicException('identity_self_action_invalid');
        }
        $result = Db::transaction(function () use ($identity, $token, $action, $data): array {
            $transaction = Db::connection('default', true);
            $lock = $transaction->table('app_installation')->where('id', '=', 1);
            $installation = ($transaction->driverName() === 'sqlite' ? $lock : $lock->lockForUpdate())->first();
            if ($installation === null || (int) $installation['schema_version'] !== 1) {
                throw new HttpError(503, 'installation_incomplete');
            }
            $query = CustomerUser::query()->master()->where('id', '=', $identity->subject());
            $user = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
            $current = $this->authenticate($token);
            if ($user === null || $current === null || $current->subject() !== $identity->subject()) {
                throw new HttpError(401, 'unauthenticated');
            }
            if (($current->attributes()['impersonation_id'] ?? '') !== '') {
                throw new HttpError(403, 'personal_credentials_required');
            }
            if ((int) $user->get('version') !== $data['version']) {
                throw new HttpError(409, 'stale_version');
            }
            $now = time();
            if ((int) $user->get('locked_until') > $now || str_contains($data['current_password'], "\0") || !password_verify($data['current_password'], (string) $user->get('password_hash'))) {
                if ((int) $user->get('locked_until') <= $now) {
                    $failures = (int) $user->get('locked_until') > 0 ? 1 : (int) $user->get('failures') + 1;
                    $user->set('failures', $failures);
                    $user->set('locked_until', $failures >= 5 ? $now + 300 : 0);
                    $user->save();
                }
                AuditLog::append($transaction, null, $identity->subject(), 'identity.' . $action, $identity->subject(), 'denied', ['reason' => 'current_password_invalid', 'context' => 'customer'], 'customer');
                return [];
            }
            $user->set('failures', 0);
            $user->set('locked_until', 0);
            if ($action === 'update') {
                if (!preg_match('/^[a-z0-9][a-z0-9_.@-]{2,99}$/D', $data['login']) || trim($data['name']) === '' || strlen($data['name']) > 100) {
                    throw new HttpError(422, 'invalid_account');
                }
                $duplicate = CustomerUser::query()->master()->where('login', '=', $data['login'])->where('id', '!=', $identity->subject())->first();
                if ($duplicate !== null) {
                    throw new HttpError(409, 'account_exists');
                }
                $user->set('login', $data['login']);
                $user->set('name', trim($data['name']));
            } else {
                self::validateAccount((string) $user->get('login'), (string) $user->get('name'), $data['password']);
                $user->set('password_hash', password_hash($data['password'], PASSWORD_BCRYPT));
                $this->deleteSessions($identity->subject());
            }
            $user->set('version', (int) $user->get('version') + 1);
            $user->save();
            $updated = $this->present($user) + ['enabled' => $user->get('enabled') ? 1 : 0, 'version' => (int) $user->get('version')];
            AuditLog::append($transaction, null, $identity->subject(), 'identity.' . $action, $identity->subject(), 'success', ['version' => $updated['version'], 'context' => 'customer'], 'customer');
            return $updated;
        }, 'default', $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
        if ($result === []) {
            throw new HttpError(403, 'current_password_invalid');
        }
        return $result;
    }

    /**
     * 登录失败也提交有限计数；连续五次失败锁定五分钟，每人最多十个会话。
     * @param string $requestId HTTP宿主传入现有请求关联ID，不接受客户端指定值。
     * @return array{accessToken: string, expiresAt: int, user: array<string, mixed>}
     * @throws HttpError 无效凭据或账号暂时锁定；不返回账号是否存在。
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
            $hash = $user === null ? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.' : (string) $user->get('password_hash');
            $valid = password_verify($password, $hash);
            $now = time();
            if ($user === null || !$user->get('enabled') || !$user->get('recovery_verified') || (int) $user->get('locked_until') > $now) {
                $this->audit($transaction, 'anonymous', 'identity.login', 'anonymous', 'denied', 'anonymous', $requestId);
                return null;
            }
            if (!$valid) {
                $failures = (int) $user->get('locked_until') > 0 ? 1 : (int) $user->get('failures') + 1;
                $user->set('failures', $failures);
                $user->set('locked_until', $failures >= 5 ? $now + 300 : 0);
                $user->save();
                $this->audit($transaction, (string) $user->get('id'), 'identity.login', (string) $user->get('id'), 'denied', 'anonymous', $requestId);
                return null;
            }
            $user->set('failures', 0);
            $user->set('locked_until', 0);
            $user->save();
            $sessionQuery = $this->sessionQuery()->where('user_id', '=', $user->get('id'));
            if ($this->realm === 'customer') {
                $sessionQuery = $sessionQuery->whereNull('source_session_id');
            }
            $sessions = $sessionQuery->orderBy('created_at')->orderBy('token_hash')->limit(10)->get();
            foreach ($sessions as $index => $session) {
                if ((int) $session->get('expires_at') <= $now || (count($sessions) >= 10 && $index === 0)) {
                    $session->delete();
                }
            }
            $token = bin2hex(random_bytes(32));
            $sessionData = [
                'token_hash' => hash('sha256', $token), 'user_id' => $user->get('id'), 'expires_at' => $now + 28800, 'created_at' => $now,
            ];
            if ($this->realm !== 'broker') {
                $sessionData['id'] = bin2hex(random_bytes(16));
            }
            if ($this->realm === 'customer') {
                $sessionData['actor_id'] = null;
                $sessionData['source_session_id'] = null;
            }
            $session = $this->newSession($sessionData);
            $session->save();
            $this->audit($transaction, (string) $user->get('id'), 'identity.login', (string) $user->get('id'), 'success', $this->realm === 'broker' && $user->get('platform_admin') ? 'broker_admin' : 'broker_user', $requestId);

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
     * @return Identity|null 会话已撤销、来源失效或身份域不匹配时返回null。
     */
    public function refresh(Identity $identity): ?Identity
    {
        $attributes = $identity->attributes();
        if ($this->realm === 'broker' || ($attributes['realm'] ?? '') !== $this->realm || !preg_match('/^[a-f0-9]{32}$/D', $attributes['session_id'] ?? '')) {
            return null;
        }
        $current = $this->sessionIdentity('id', $attributes['session_id']);
        return $current !== null && $current->subject() === $identity->subject() ? $current : null;
    }

    /**
     * 从应用已持久保存的来源重验准确会话；公开上下文字段不得作为外部认证入口。
     * @param array<string, mixed> $context 原受理来源，不允许回退同账号其他会话或另一模拟来源。
     */
    public function resume(array $context): ?Identity
    {
        if ($this->realm !== 'customer' || ($context['realm'] ?? '') !== 'customer' || !is_string($context['customer_id'] ?? null)
            || !is_string($context['tenant_id'] ?? null) || !is_string($context['key'] ?? null)) {
            return null;
        }
        $current = $this->refresh(new Identity($context['customer_id'], ['realm:customer'], $context));
        return $current !== null && self::context($current, $context['tenant_id'])['key'] === $context['key'] ? $current : null;
    }

    /** 固定的散列或会话标识查询共享同一认证事实及来源检查。 */
    private function sessionIdentity(string $column, string $value): ?Identity
    {
        $connection = Db::connection('default', true);
        $query = $this->sessionQuery()->where($column, '=', $value)->with('user');
        if ($this->realm === 'customer') {
            $query = $query->with('source_session.user');
        }
        $session = $query->first();
        if ($session === null) {
            return null;
        }
        $user = $session->related('user');
        if ($user === null || !$user->get('enabled') || !$user->get('recovery_verified') || (int) $session->get('expires_at') <= time()) {
            return null;
        }
        if ($this->realm === 'broker') {
            return new Identity((string) $user->get('id'), $user->get('platform_admin') ? ['broker_admin'] : [], [
                'realm' => 'broker', 'actor_id' => (string) $user->get('id'), 'actor_realm' => 'broker', 'customer_id' => '',
                'session_id' => substr((string) $session->get('token_hash'), 0, 32), 'source_session_id' => '', 'impersonation_id' => '',
                'expires_at' => (int) $session->get('expires_at'), 'actor_name' => (string) $user->get('name'),
            ]);
        }
        $attributes = ['realm' => $this->realm, 'actor_id' => (string) $user->get('id'), 'actor_realm' => $this->realm,
            'customer_id' => $this->realm === 'customer' ? (string) $user->get('id') : '', 'session_id' => (string) $session->get('id'),
            'source_session_id' => '', 'impersonation_id' => '', 'expires_at' => (int) $session->get('expires_at'), 'actor_name' => (string) $user->get('name')];
        if ($this->realm === 'customer' && $session->get('source_session_id') !== null) {
            $origin = $session->related('source_session');
            $originUser = $origin === null ? null : $origin->related('user');
            if ($origin === null || $originUser === null || !$originUser->get('enabled') || !$originUser->get('recovery_verified')
                || (int) $origin->get('expires_at') <= time()
                || !in_array('admin.customers.impersonate', RoleService::permissions(new Identity((string) $originUser->get('id'), ['realm:admin']), 'admin'), true)) {
                return null;
            }
            $attributes = array_replace($attributes, ['actor_id' => (string) $originUser->get('id'), 'actor_realm' => 'admin',
                'source_session_id' => (string) $session->get('source_session_id'), 'impersonation_id' => (string) $session->get('id'),
                'expires_at' => min((int) $session->get('expires_at'), (int) $origin->get('expires_at')), 'actor_name' => (string) $originUser->get('name')]);
        }
        return new Identity((string) $user->get('id'), ['realm:' . $this->realm], $attributes);
    }

    /**
     * 已授权平台事务内创建专用客户会话；每个来源最多十个，淘汰不触及客户真实会话。
     * @return array<string, mixed> 令牌仅在本次响应返回，期限受来源会话约束。
     */
    public function impersonate(Identity $identity, string $customerId, int $version): array
    {
        $connection = Db::connection('default', true);
        $origin = $identity->attributes();
        if ($this->realm !== 'admin' || $connection->transactionDepth() < 1 || ($origin['session_id'] ?? '') === '') {
            throw new \LogicException('impersonation_requires_authorized_transaction');
        }
        if (!in_array('admin.customers.impersonate', RoleService::permissions($identity, 'admin'), true)) {
            throw new HttpError(403, 'permission_denied');
        }
        $query = CustomerUser::query()->master()->where('id', '=', $customerId);
        $customer = ($connection->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
        if ($customer === null || !$customer->get('enabled') || !$customer->get('recovery_verified')) {
            throw new HttpError(404, 'user_not_found');
        }
        if ((int) $customer->get('version') !== $version) {
            throw new HttpError(409, 'stale_version');
        }
        $now = time();
        $sessions = CustomerSession::query()->master()->where('source_session_id', '=', $origin['session_id'])->orderBy('created_at')->orderBy('id')->limit(10)->get();
        foreach ($sessions as $index => $session) {
            if ((int) $session->get('expires_at') <= $now || (count($sessions) >= 10 && $index === 0)) {
                $session->delete();
            }
        }
        $token = bin2hex(random_bytes(32));
        $id = bin2hex(random_bytes(16));
        $expires = min($now + 28800, (int) $origin['expires_at']);
        $session = new CustomerSession(['id' => $id, 'token_hash' => hash('sha256', $token), 'user_id' => $customerId,
            'actor_id' => $identity->subject(), 'source_session_id' => $origin['session_id'], 'created_at' => $now, 'expires_at' => $expires]);
        $session->save();
        AuditLog::append(
            $connection,
            null,
            $identity,
            'identity.impersonated',
            $customerId,
            'success',
            ['impersonation_id' => $id, 'source_session_id' => $origin['session_id'], 'grantee_id' => $customerId, 'expires_at' => $expires],
            'admin'
        );
        return ['accessToken' => $token, 'expiresAt' => $expires, 'user' => (new self('customer'))->present($customer)];
    }

    /**
     * 公开请求、审计及持久异步事实使用同一身份边界；key只区分上下文，不是授权凭据。
     * @return array<string, mixed> 当前认证元数据，tenantId必须来自已验证的工作区。
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

    /** @return array<string, mixed> 当前有效账号的公开字段。 */
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
     * @param string $requestId HTTP宿主传入现有请求关联ID，不接受客户端指定值。
     */
    public function logout(string|Identity $actor, string $token, string $requestId = ''): void
    {
        $connection = Db::connection('default', true);
        $userId = $actor instanceof Identity ? $actor->subject() : $actor;
        Db::transaction(function () use ($actor, $userId, $token, $requestId): void {
            $transaction = Db::connection('default', true);
            if ($this->realm !== 'broker') {
                $lock = $transaction->table('app_installation')->where('id', '=', 1);
                ($transaction->driverName() === 'sqlite' ? $lock : $lock->lockForUpdate())->first();
            }
            $identity = $this->authenticate($token);
            if ($identity !== null) {
                $sessionId = (string) ($identity->attributes()['session_id'] ?? '');
                if ($sessionId !== '') {
                    DebugService::revokeSession($transaction, $sessionId, $userId);
                }
            }
            $session = $this->sessionQuery()->where('token_hash', '=', hash('sha256', $token))->where('user_id', '=', $userId)->first();
            if ($session !== null) {
                $session->delete();
            }
            $user = $this->realm === 'broker' ? BrokerUser::query()->master()->where('id', '=', $userId)->first() : null;
            $this->audit($transaction, $identity ?? $actor, 'identity.logout', $userId, 'success', $this->realm === 'broker' && $user !== null && $user->get('platform_admin') ? 'broker_admin' : 'broker_user', $requestId);
        }, 'default', $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /** 当前账号域只映射到一个稳定领域 Model，不再通过运行时表名切换业务实体。 */
    private function userQuery(): ModelQuery
    {
        return match ($this->realm) {
            'admin' => AdminUser::query()->master(),
            'customer' => CustomerUser::query()->master(),
            'broker' => BrokerUser::query()->master(),
        };
    }

    /** 新账号统一经过具体 Model 的字段赋值和版本契约。 */
    private function newUser(array $values): Model
    {
        return match ($this->realm) {
            'admin' => new AdminUser($values),
            'customer' => new CustomerUser($values),
            'broker' => new BrokerUser($values),
        };
    }

    /** 会话域同样固定映射到领域 Model；Broker 会话使用 token_hash 作为内部主键。 */
    private function sessionQuery(): ModelQuery
    {
        return match ($this->realm) {
            'admin' => AdminSession::query()->master(),
            'customer' => CustomerSession::query()->master(),
            'broker' => BrokerSession::query()->master(),
        };
    }

    private function newSession(array $values): Model
    {
        return match ($this->realm) {
            'admin' => new AdminSession($values),
            'customer' => new CustomerSession($values),
            'broker' => new BrokerSession($values),
        };
    }

    /** 会话撤销逐模型执行，保留删除事件、事务登记和跨驱动一致性。 */
    private function deleteSessions(string $userId): void
    {
        foreach ($this->sessionQuery()->where('user_id', '=', $userId)->get() as $session) {
            $session->delete();
        }
    }

    /** 独立人员同步动作只记录真实结果；保留IoT既有事件、字段默认值和事务归属。 */
    private function audit(Connection $connection, string|Identity $actorId, string $action, string $subjectId, string $result, string $role, string $requestId, string $actorRealm = ''): void
    {
        if ($this->realm !== 'broker') {
            AuditLog::append($connection, null, $actorId, $action, $subjectId, $result, ['context' => $this->realm, 'reason' => $action === 'identity.created' ? ($actorId === 'provisioning' ? 'operator-command' : 'authorized-account') : 'session'], $actorRealm === '' ? $this->realm : $actorRealm);
            return;
        }
        $requestId = $requestId === '' ? bin2hex(random_bytes(16)) : $requestId;
        $stage = $result === 'success' ? 'completed' : 'failed';
        AuditLog::recordBroker($connection, 'broker', [
            'operation_id' => bin2hex(random_bytes(16)), 'mode' => 'sync', 'origin_request_id' => $requestId,
            'actor_id' => $actorId instanceof Identity ? $actorId->subject() : $actorId, 'tenant_id' => null, 'action' => $action, 'subject_id' => $subjectId,
            'authorization' => ['source' => $role === 'operator-command' ? 'operator-command' : 'broker-identity', 'role' => $role,
                'permissions' => $result === 'success' ? [$action] : [], 'required_action' => $action, 'decision' => $result === 'success' ? 'allowed' : 'denied',
                'support_id' => null, 'support_version' => null, 'support_expires_at' => null],
            'target' => ['kind' => 'identity', 'node_id' => '', 'node_run_id' => '', 'observation_run' => '', 'generation' => 0],
            'impact' => ['confirmed' => true, 'effect' => $action, 'target_count' => 1, 'proof_hash' => ''],
        ], $stage, ['request_id' => $requestId, 'stage' => $stage, 'result' => $result, 'facts' => ['reason' => $result === 'success' ? 'identity_committed' : 'invalid_credentials']]);
    }

    /** @param Model|array<string, mixed> $row 数据库身份模型/行；公开投影永远排除口令与会话字段。 */
    private function present(Model|array $row): array
    {
        $value = static fn (string $field): mixed => $row instanceof Model ? $row->get($field) : ($row[$field] ?? null);
        $result = ['id' => (string) $value('id'), 'login' => (string) $value('login'), 'name' => (string) $value('name'), 'realm' => $this->realm];
        if ($this->realm === 'broker') {
            $result['platform_admin'] = (bool) $value('platform_admin');
        } else {
            $result['version'] = (int) ($value('version') ?? 1);
        }
        return $result;
    }
}
