<?php

declare(strict_types=1);

namespace app\common\service;

use app\broker\service\DebugService;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Orm\Connection;

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
    public function provision(Connection $connection, string $login, string $name, string $password, bool $platform, string $requestId = '', string|Identity $actorId = 'provisioning', string $actorRealm = ''): array
    {
        self::validateAccount($login, $name, $password);
        if ($this->realm !== 'broker' && $platform) {
            throw new HttpError(422, 'identity_role_invalid');
        }
        return $connection->transaction(function (Connection $transaction) use ($login, $name, $password, $platform, $requestId, $actorId, $actorRealm): array {
            if ($transaction->table($this->realm . '_users')->where('login', '=', $login)->first() !== null) {
                throw new HttpError(409, 'account_exists');
            }
            $id = bin2hex(random_bytes(16));
            $values = [
                'id' => $id, 'login' => $login, 'name' => trim($name), 'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'enabled' => 1, 'failures' => 0, 'locked_until' => 0, 'created_at' => time(),
            ];
            if ($this->realm === 'broker') {
                $values['platform_admin'] = $platform ? 1 : 0;
            }
            $transaction->table($this->realm . '_users')->insert($values);
            $this->audit($transaction, $actorId, 'identity.created', $id, 'success', 'operator-command', $requestId, $actorRealm);

            return $this->present($values);
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
    public function change(Connection $connection, string $id, string $action, array $data, string|Identity $actorId, string $actorRealm = ''): array
    {
        if ($this->realm === 'broker' || $connection->transactionDepth() < 1) {
            throw new \LogicException('identity_change_requires_authorized_transaction');
        }
        $query = $connection->table($this->realm . '_users')->where('id', '=', $id);
        $user = ($connection->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
        if ($user === null) {
            throw new HttpError(404, 'user_not_found');
        }
        if ((int) $user['version'] !== $data['version']) {
            throw new HttpError(409, 'stale_version');
        }
        $values = ['version' => (int) $user['version'] + 1];
        if ($action === 'update') {
            if (!preg_match('/^[a-z0-9][a-z0-9_.@-]{2,99}$/D', $data['login']) || trim($data['name']) === '' || strlen($data['name']) > 100) {
                throw new HttpError(422, 'invalid_account');
            }
            $duplicate = $connection->table($this->realm . '_users')->where('login', '=', $data['login'])->where('id', '!=', $id)->first();
            if ($duplicate !== null) {
                throw new HttpError(409, 'account_exists');
            }
            $values += ['login' => $data['login'], 'name' => trim($data['name'])];
        } elseif ($action === 'status') {
            $values['enabled'] = $data['enabled'] ? 1 : 0;
        } elseif ($action === 'password') {
            self::validateAccount((string) $user['login'], (string) $user['name'], $data['password']);
            $values += ['password_hash' => password_hash($data['password'], PASSWORD_BCRYPT), 'failures' => 0, 'locked_until' => 0];
        } elseif ($action !== 'sessions') {
            throw new \InvalidArgumentException('identity_action_invalid');
        }
        $connection->table($this->realm . '_users')->where('id', '=', $id)->update($values);
        if ($action === 'password' || $action === 'sessions' || ($action === 'status' && !$data['enabled'])) {
            $connection->table($this->realm . '_sessions')->where('user_id', '=', $id)->delete();
        }
        AuditLog::append($connection, null, $actorId, 'identity.' . $action, $id, 'success', ['version' => $values['version'], 'context' => $this->realm], $actorRealm === '' ? $this->realm : $actorRealm);
        $updated = array_replace($user, $values);
        return $this->present($updated) + ['enabled' => (int) $updated['enabled'], 'version' => $values['version']];
    }

    /**
     * 真实客户以原密码维护本人资料；无租户角色也可调用，目标始终取当前认证主体。
     * 与平台变更使用相同锁顺序；原密码错误计入已有五次/五分钟限额，失败审计不含输入。
     * @param array<string, mixed> $data 固定字段、原密码及预期版本。
     * @return array<string, mixed> 公开账号；改密后全部旧会话失效。
     */
    public function changeSelf(Connection $connection, Identity $identity, string $token, string $action, array $data): array
    {
        if ($this->realm !== 'customer' || !in_array($action, ['update', 'password'], true)) {
            throw new \LogicException('identity_self_action_invalid');
        }
        $result = $connection->transaction(function (Connection $transaction) use ($identity, $token, $action, $data): array {
            $lock = $transaction->table('app_installation')->where('id', '=', 1);
            $installation = ($transaction->driverName() === 'sqlite' ? $lock : $lock->lockForUpdate())->first();
            if ($installation === null || (int) $installation['schema_version'] !== 1) {
                throw new HttpError(503, 'installation_incomplete');
            }
            $query = $transaction->table('customer_users')->where('id', '=', $identity->subject());
            $user = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
            $current = $this->authenticate($transaction, $token);
            if ($user === null || $current === null || $current->subject() !== $identity->subject()) {
                throw new HttpError(401, 'unauthenticated');
            }
            if (($current->attributes()['impersonation_id'] ?? '') !== '') {
                throw new HttpError(403, 'personal_credentials_required');
            }
            if ((int) $user['version'] !== $data['version']) {
                throw new HttpError(409, 'stale_version');
            }
            $now = time();
            if ((int) $user['locked_until'] > $now || str_contains($data['current_password'], "\0") || !password_verify($data['current_password'], (string) $user['password_hash'])) {
                if ((int) $user['locked_until'] <= $now) {
                    $failures = (int) $user['locked_until'] > 0 ? 1 : (int) $user['failures'] + 1;
                    $transaction->table('customer_users')->where('id', '=', $identity->subject())->update(['failures' => $failures, 'locked_until' => $failures >= 5 ? $now + 300 : 0]);
                }
                AuditLog::append($transaction, null, $identity->subject(), 'identity.' . $action, $identity->subject(), 'denied', ['reason' => 'current_password_invalid', 'context' => 'customer'], 'customer');
                return [];
            }
            $transaction->table('customer_users')->where('id', '=', $identity->subject())->update(['failures' => 0, 'locked_until' => 0]);
            return $this->change($transaction, $identity->subject(), $action, $data, $identity->subject());
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
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
    public function login(Connection $connection, string $login, string $password, string $requestId = ''): array
    {
        if (strlen($login) > 100 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new HttpError(401, 'invalid_credentials');
        }
        $result = $connection->transaction(function (Connection $transaction) use ($login, $password, $requestId): ?array {
            $rows = $transaction->table($this->realm . '_users')->where('login', '=', $login);
            if ($transaction->driverName() !== 'sqlite') {
                $rows = $rows->lockForUpdate();
            }
            $user = $rows->first();
            // 固定的无效口令散列保持未知账号也经过口令校验，不是可登录的默认账号。
            $hash = $user === null ? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.' : (string) $user['password_hash'];
            $valid = password_verify($password, $hash);
            $now = time();
            if ($user === null || (int) $user['enabled'] !== 1 || (int) $user['recovery_verified'] !== 1 || (int) $user['locked_until'] > $now) {
                $this->audit($transaction, 'anonymous', 'identity.login', 'anonymous', 'denied', 'anonymous', $requestId);
                return null;
            }
            if (!$valid) {
                $failures = (int) $user['locked_until'] > 0 ? 1 : (int) $user['failures'] + 1;
                $transaction->table($this->realm . '_users')->where('id', '=', $user['id'])->update([
                    'failures' => $failures, 'locked_until' => $failures >= 5 ? $now + 300 : 0,
                ]);
                $this->audit($transaction, (string) $user['id'], 'identity.login', (string) $user['id'], 'denied', 'anonymous', $requestId);
                return null;
            }
            $transaction->table($this->realm . '_users')->where('id', '=', $user['id'])->update(['failures' => 0, 'locked_until' => 0]);
            $sessionQuery = $transaction->table($this->realm . '_sessions')->where('user_id', '=', $user['id']);
            if ($this->realm === 'customer') {
                $sessionQuery = $sessionQuery->whereNull('source_session_id');
            }
            $sessions = $sessionQuery->orderBy('created_at')->orderBy('token_hash')->limit(10)->get();
            foreach ($sessions as $index => $session) {
                if ((int) $session['expires_at'] <= $now || (count($sessions) >= 10 && $index === 0)) {
                    $transaction->table($this->realm . '_sessions')->where('token_hash', '=', $session['token_hash'])->delete();
                }
            }
            $token = bin2hex(random_bytes(32));
            $sessionData = [
                'token_hash' => hash('sha256', $token), 'user_id' => $user['id'], 'expires_at' => $now + 28800, 'created_at' => $now,
            ];
            if ($this->realm !== 'broker') {
                $sessionData['id'] = bin2hex(random_bytes(16));
            }
            $transaction->table($this->realm . '_sessions')->insert($sessionData);
            $this->audit($transaction, (string) $user['id'], 'identity.login', (string) $user['id'], 'success', (int) ($user['platform_admin'] ?? 0) === 1 ? 'broker_admin' : 'broker_user', $requestId);

            return ['accessToken' => $token, 'expiresAt' => $now + 28800, 'user' => $this->present($user)];
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
        if ($result === null) {
            throw new HttpError(401, 'invalid_credentials');
        }
        return $result;
    }

    /**
     * 只查询散列；模拟会话逐次核对准确来源会话及管理资格，不合并平台权限。
     * 返回的上下文标识是独立随机ID，不暴露可认证的令牌或其散列。
     */
    public function authenticate(Connection $connection, string $token): ?Identity
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }
        return $this->sessionIdentity($connection, 'token_hash', hash('sha256', $token));
    }

    /**
     * 业务事务复核可信认证身份的准确会话及模拟来源；公开会话ID不作为外部认证凭据。
     * @return Identity|null 会话已撤销、来源失效或身份域不匹配时返回null。
     */
    public function refresh(Connection $connection, Identity $identity): ?Identity
    {
        $attributes = $identity->attributes();
        if ($this->realm === 'broker' || ($attributes['realm'] ?? '') !== $this->realm || !preg_match('/^[a-f0-9]{32}$/D', $attributes['session_id'] ?? '')) {
            return null;
        }
        $current = $this->sessionIdentity($connection, 'id', $attributes['session_id']);
        return $current !== null && $current->subject() === $identity->subject() ? $current : null;
    }

    /**
     * 从应用已持久保存的来源重验准确会话；公开上下文字段不得作为外部认证入口。
     * @param array<string, mixed> $context 原受理来源，不允许回退同账号其他会话或另一模拟来源。
     */
    public function resume(Connection $connection, array $context): ?Identity
    {
        if ($this->realm !== 'customer' || ($context['realm'] ?? '') !== 'customer' || !is_string($context['customer_id'] ?? null)
            || !is_string($context['tenant_id'] ?? null) || !is_string($context['key'] ?? null)) {
            return null;
        }
        $current = $this->refresh($connection, new Identity($context['customer_id'], ['realm:customer'], $context));
        return $current !== null && self::context($current, $context['tenant_id'])['key'] === $context['key'] ? $current : null;
    }

    /** 固定的散列或会话标识查询共享同一认证事实及来源检查。 */
    private function sessionIdentity(Connection $connection, string $column, string $value): ?Identity
    {
        $columns = $this->realm === 'broker' ? 'u.id, u.platform_admin, s.token_hash, s.expires_at' : 'u.id, s.id AS session_id, s.expires_at';
        if ($this->realm === 'customer') {
            $columns .= ', s.actor_id, s.source_session_id';
        }
        $rows = $connection->query('SELECT ' . $columns . ' FROM ' . $this->realm . '_sessions s INNER JOIN ' . $this->realm . '_users u ON u.id = s.user_id WHERE s.' . $column . ' = ? AND s.expires_at > ? AND u.enabled = 1 AND u.recovery_verified = 1', [$value, time()]);
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];
        if ($this->realm === 'broker') {
            return new Identity((string) $row['id'], (int) $row['platform_admin'] === 1 ? ['broker_admin'] : [], [
                'realm' => 'broker', 'actor_id' => (string) $row['id'], 'actor_realm' => 'broker', 'customer_id' => '',
                'session_id' => substr((string) $row['token_hash'], 0, 32), 'source_session_id' => '', 'impersonation_id' => '',
                'expires_at' => (int) $row['expires_at'], 'actor_name' => '',
            ]);
        }
        $attributes = ['realm' => $this->realm, 'actor_id' => (string) $row['id'], 'actor_realm' => $this->realm,
            'customer_id' => $this->realm === 'customer' ? (string) $row['id'] : '', 'session_id' => (string) $row['session_id'],
            'source_session_id' => '', 'impersonation_id' => '', 'expires_at' => (int) $row['expires_at'], 'actor_name' => ''];
        if ($this->realm === 'customer' && $row['source_session_id'] !== null) {
            $origins = $connection->query('SELECT u.id, u.name, s.expires_at FROM admin_sessions s JOIN admin_users u ON u.id = s.user_id WHERE s.id = ? AND s.user_id = ? AND s.expires_at > ? AND u.enabled = 1 AND u.recovery_verified = 1', [$row['source_session_id'], $row['actor_id'], time()]);
            if ($origins === [] || !in_array('admin.customers.impersonate', RoleService::permissions($connection, new Identity((string) $origins[0]['id'], ['realm:admin']), 'admin'), true)) {
                return null;
            }
            $attributes = array_replace($attributes, ['actor_id' => (string) $origins[0]['id'], 'actor_realm' => 'admin',
                'source_session_id' => (string) $row['source_session_id'], 'impersonation_id' => (string) $row['session_id'],
                'expires_at' => min((int) $row['expires_at'], (int) $origins[0]['expires_at']), 'actor_name' => (string) $origins[0]['name']]);
        }
        return new Identity((string) $row['id'], ['realm:' . $this->realm], $attributes);
    }

    /**
     * 已授权平台事务内创建专用客户会话；每个来源最多十个，淘汰不触及客户真实会话。
     * @return array<string, mixed> 令牌仅在本次响应返回，期限受来源会话约束。
     */
    public function impersonate(Connection $connection, Identity $identity, string $customerId, int $version): array
    {
        $origin = $identity->attributes();
        if ($this->realm !== 'admin' || $connection->transactionDepth() < 1 || ($origin['session_id'] ?? '') === '') {
            throw new \LogicException('impersonation_requires_authorized_transaction');
        }
        if (!in_array('admin.customers.impersonate', RoleService::permissions($connection, $identity, 'admin'), true)) {
            throw new HttpError(403, 'permission_denied');
        }
        $query = $connection->table('customer_users')->where('id', '=', $customerId);
        $customer = ($connection->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
        if ($customer === null || (int) $customer['enabled'] !== 1 || (int) $customer['recovery_verified'] !== 1) {
            throw new HttpError(404, 'user_not_found');
        }
        if ((int) $customer['version'] !== $version) {
            throw new HttpError(409, 'stale_version');
        }
        $now = time();
        $sessions = $connection->table('customer_sessions')->where('source_session_id', '=', $origin['session_id'])->orderBy('created_at')->orderBy('id')->limit(10)->get();
        foreach ($sessions as $index => $session) {
            if ((int) $session['expires_at'] <= $now || (count($sessions) >= 10 && $index === 0)) {
                $connection->table('customer_sessions')->where('id', '=', $session['id'])->delete();
            }
        }
        $token = bin2hex(random_bytes(32));
        $id = bin2hex(random_bytes(16));
        $expires = min($now + 28800, (int) $origin['expires_at']);
        $connection->table('customer_sessions')->insert(['id' => $id, 'token_hash' => hash('sha256', $token), 'user_id' => $customerId,
            'actor_id' => $identity->subject(), 'source_session_id' => $origin['session_id'], 'created_at' => $now, 'expires_at' => $expires]);
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
    public function user(Connection $connection, string $id): array
    {
        $user = $connection->table($this->realm . '_users')->where('id', '=', $id)->where('enabled', '=', 1)->where('recovery_verified', '=', 1)->first();
        if ($user === null) {
            throw new HttpError(401, 'unauthenticated');
        }
        return $this->present($user);
    }

    /**
     * 只撤销当前认证令牌，不记录令牌、散列或口令。
     * @param string $requestId HTTP宿主传入现有请求关联ID，不接受客户端指定值。
     */
    public function logout(Connection $connection, string|Identity $actor, string $token, string $requestId = ''): void
    {
        $userId = $actor instanceof Identity ? $actor->subject() : $actor;
        $connection->transaction(function (Connection $transaction) use ($actor, $userId, $token, $requestId): void {
            if ($this->realm !== 'broker') {
                $lock = $transaction->table('app_installation')->where('id', '=', 1);
                ($transaction->driverName() === 'sqlite' ? $lock : $lock->lockForUpdate())->first();
            }
            $identity = $this->authenticate($transaction, $token);
            if ($identity !== null) {
                $sessionId = (string) ($identity->attributes()['session_id'] ?? '');
                if ($sessionId !== '') {
                    DebugService::revokeSession($transaction, $sessionId, $userId);
                }
            }
            $transaction->table($this->realm . '_sessions')->where('token_hash', '=', hash('sha256', $token))->where('user_id', '=', $userId)->delete();
            $user = $this->realm === 'broker' ? $transaction->table('broker_users')->where('id', '=', $userId)->first() : null;
            $this->audit($transaction, $identity ?? $actor, 'identity.logout', $userId, 'success', (int) ($user['platform_admin'] ?? 0) === 1 ? 'broker_admin' : 'broker_user', $requestId);
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
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

    /** @param array<string, mixed> $row 数据库身份行；公开投影永远排除口令与会话字段。 */
    private function present(array $row): array
    {
        $result = ['id' => (string) $row['id'], 'login' => (string) $row['login'], 'name' => (string) $row['name'], 'realm' => $this->realm];
        if ($this->realm === 'broker') {
            $result['platform_admin'] = (int) $row['platform_admin'] === 1;
        } else {
            $result['version'] = (int) ($row['version'] ?? 1);
        }
        return $result;
    }
}
