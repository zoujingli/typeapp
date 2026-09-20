<?php

declare(strict_types=1);

namespace app\iot\service;

use app\common\model\CustomerMember;
use app\common\model\CustomerUser;
use app\common\model\CustomerRole;
use app\common\model\Tenant;
use app\common\service\AuditLog;
use app\common\service\IdentityService;
use app\common\service\RoleService;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Orm\Connection;
use Type\Orm\Model;
use Type\Orm\ModelQuery;
use Type\Orm\Query;

/** 双端租户与成员管理；权限来自当前范围的多角色，写入在真实锁内重验。 */
final class TenantService
{
    /**
     * 双端分别查询平台目录或本人有效成员关系，不把平台权限转换为租户权限。
     * @param array{page:int,per_page:int,search:string,enabled:int} $filters 已校验的固定筛选。
     * @return array<string, mixed> 稳定分页与明确租户投影；平台详情另含当前有效最高管理员。
     */
    public function tenants(Identity $identity, array $filters, bool $platform, string $id = ''): array
    {
        $connection = \Type\Orm\Db::connection('default', true);
        if (!$platform) {
            return $this->availableTenants($identity, $filters, $id);
        }
        $rows = Tenant::query()->master();
        $permissions = [];
        if ($platform) {
            $permissions = RoleService::permissions($identity, 'admin');
            if (!in_array('admin.tenants.read', $permissions, true)) {
                throw new HttpError(403, 'permission_denied');
            }
            if ($filters['enabled'] !== -1) {
                $rows = $rows->where('enabled', '=', (bool) $filters['enabled']);
            }
        }
        if ($id !== '') {
            $rows = $rows->where('id', '=', $id);
        } elseif ($filters['search'] !== '') {
            $rows = $rows->where('name', 'LIKE', '%' . $filters['search'] . '%');
        }
        $result = $this->modelPage($rows->orderBy('created_at', 'DESC')->orderBy('id'), $id === '' ? $filters['page'] : 1, $id === '' ? $filters['per_page'] : 1);
        if ($id !== '' && $result['items'] === []) {
            throw new HttpError(404, 'tenant_not_found');
        }
        if ($platform) {
            $result += ['permissions' => $permissions, 'catalog' => RoleService::catalog('admin'), 'menus' => RoleService::menus('admin', $permissions)];
            if ($id !== '') {
                $result['items'][0]['administrators'] = $connection->query('SELECT DISTINCT u.id, m.id AS member_id, m.version AS member_version, u.login, u.name FROM customer_members m JOIN customer_users u ON u.id = m.user_id JOIN customer_member_roles b ON b.member_id = m.id AND b.tenant_id = m.tenant_id JOIN customer_roles r ON r.id = b.role_id AND r.scope_id = m.tenant_id WHERE m.tenant_id = ? AND m.enabled = 1 AND m.recovery_verified = 1 AND u.enabled = 1 AND u.recovery_verified = 1 AND r.enabled = 1 AND r.protected = 1 AND r.recovery_verified = 1 ORDER BY u.id LIMIT 100', [$id]);
            }
        }
        return $result;
    }

    /** 登录后选租户之前的受控跨模型投影；范围始终取已认证账号，不建立租户绑定。 */
    private function availableTenants(Identity $identity, array $filters, string $id): array
    {
        if (!in_array('realm:customer', $identity->roles(), true)) {
            throw new HttpError(403, 'identity_realm_forbidden');
        }
        (new IdentityService('customer'))->user($identity->subject());
        $connection = \Type\Orm\Db::connection('default', true);
        $rows = $connection->table('iot_tenants', 't')->join('customer_members', 't.id', '=', 'm.tenant_id', 'm')
            ->select(['id' => 't.id', 'name' => 't.name', 'enabled' => 't.enabled', 'version' => 't.version', 'created_at' => 't.created_at'])
            ->where('m.user_id', '=', $identity->subject())->where('m.enabled', '=', 1)->where('m.recovery_verified', '=', 1)->where('t.enabled', '=', 1);
        if ($id !== '') {
            $rows = $rows->where('t.id', '=', $id);
        } elseif ($filters['search'] !== '') {
            $rows = $rows->where('t.name', 'LIKE', '%' . $filters['search'] . '%');
        }
        $result = $this->joinedPage($rows->orderBy('t.created_at', 'DESC')->orderBy('t.id'), $id === '' ? $filters['page'] : 1, $id === '' ? $filters['per_page'] : 1);
        if ($id !== '' && $result['items'] === []) {
            throw new HttpError(404, 'tenant_not_found');
        }
        return $result;
    }

    /**
     * RoleService 在安装行锁内重验平台会话和独立动作权限后调用。
     * 新客户、成员、角色和审计属于同一事务；关联已有客户不能修改全局资料、状态或凭据。
     * @param array<string, mixed> $data 固定动作字段；创建的稳定id仅用于拒绝重复，不赋予身份。
     * @return array<string, mixed> 无秘密的租户资料；失败由外层事务整体回滚。
     */
    public function change(Identity $identity, string $action, string $id, array $data): array
    {
        $connection = \Type\Orm\Db::connection('default', true);
        if ($connection->transactionDepth() < 1 || !in_array('realm:admin', $identity->roles(), true)) {
            throw new \LogicException('tenant_change_requires_authorized_transaction');
        }
        if (in_array($action, ['admin.tenants.create', 'admin.tenants.update'], true) && (trim($data['name']) === '' || strlen($data['name']) > 100)) {
            throw new HttpError(422, 'tenant_name_invalid');
        }
        if ($action === 'admin.tenants.create') {
            $id = $data['id'];
            if (Tenant::query()->master()->find($id) !== null) {
                throw new HttpError(409, 'tenant_exists');
            }
            if ($data['new_customer']) {
                if (!isset($data['owner_name'], $data['owner_password'])) {
                    throw new HttpError(422, 'tenant_owner_input_invalid');
                }
                $owner = (new IdentityService('customer'))->provision($data['owner_login'], $data['owner_name'], $data['owner_password'], false, '', $identity->subject(), 'admin');
            } else {
                if (array_key_exists('owner_name', $data) || array_key_exists('owner_password', $data)) {
                    throw new HttpError(422, 'tenant_owner_input_invalid');
                }
                $query = CustomerUser::query()->master()->where('login', '=', $data['owner_login'])->where('enabled', '=', true)->where('recovery_verified', '=', true);
                $account = ($connection->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
                if ($account === null) {
                    throw new HttpError(422, 'owner_not_available');
                }
                $owner = $account->project(['id', 'name']);
            }
            $tenant = new Tenant(['id' => $id, 'name' => trim($data['name']), 'enabled' => true, 'created_at' => time()]);
            $tenant->save();
            $memberId = bin2hex(random_bytes(16));
            $member = new CustomerMember([
                'id' => $memberId, 'tenant_id' => $id, 'user_id' => $owner['id'], 'name' => $owner['name'],
                'enabled' => true, 'recovery_verified' => true, 'created_at' => time(),
            ]);
            $member->save();
            RoleService::initializeScope('customer', $id, $memberId);
            AuditLog::append($connection, $id, $identity->subject(), 'member.added', $memberId, 'success', ['grantee_id' => $owner['id'], 'context' => 'admin', 'version' => 1], 'admin');
        } elseif (str_starts_with($action, 'admin.tenants.administrators.')) {
            return $this->changeAdministrator($connection, $identity, $action, $id, $data);
        } else {
            $query = Tenant::query()->master()->where('id', '=', $id);
            $query = $connection->driverName() === 'sqlite' ? $query : $query->lockForUpdate();
            $tenant = $query->first();
            if ($tenant === null) {
                throw new HttpError(404, 'tenant_not_found');
            }
            if ($tenant->getVersion() !== $data['version']) {
                throw new HttpError(409, 'stale_version');
            }
            if ($action === 'admin.tenants.update') {
                $tenant->setName(trim($data['name']));
            } elseif ($action === 'admin.tenants.status') {
                $tenant->setEnabled((bool) $data['enabled']);
            } else {
                throw new \InvalidArgumentException('tenant_action_invalid');
            }
            $tenant->save();
        }
        if ($tenant->getEnabled()) {
            RoleService::requireHighest('customer', $id);
        }
        $values = $this->tenantValues($tenant);
        AuditLog::append($connection, $id, $identity->subject(), $action, $id, 'success', ['version' => $values['version'], 'context' => 'admin'], 'admin');
        return $values;
    }

    /**
     * 平台直接维护指定租户的受保护最高管理员；只改变成员关系和角色绑定，不修改客户全局资料。
     * 新增与替换共用一次事务，移除保留成员及其余角色；调用者已在 RoleService 授权锁内。
     * @param array<string, mixed> $data 已校验的租户版本、账号和替换/成员版本。
     * @return array<string, mixed> 变更后的租户和最高管理员投影。
     */
    private function changeAdministrator(Connection $connection, Identity $identity, string $action, string $tenantId, array $data): array
    {
        if ($connection->transactionDepth() < 1 || !in_array('realm:admin', $identity->roles(), true)) {
            throw new \LogicException('tenant_administrator_change_requires_authorized_transaction');
        }
        $tenantQuery = Tenant::query()->master()->where('id', '=', $tenantId);
        $tenant = ($connection->driverName() === 'sqlite' ? $tenantQuery : $tenantQuery->lockForUpdate())->first();
        if ($tenant === null) {
            throw new HttpError(404, 'tenant_not_found');
        }
        if ($tenant->getVersion() !== $data['version']) {
            throw new HttpError(409, 'stale_version');
        }
        $roleQuery = CustomerRole::query()->master()->where('protected', '=', true);
        $highest = ($connection->driverName() === 'sqlite' ? $roleQuery : $roleQuery->lockForUpdate())->first();
        if ($highest === null || !$highest->getEnabled() || !$highest->getRecoveryVerified()) {
            throw new HttpError(409, 'tenant_admin_role_unavailable');
        }
        $now = time();
        if ($action === 'admin.tenants.administrators.remove') {
            $memberQuery = CustomerMember::query()->master()->where('id', '=', $data['member_id']);
            $member = ($connection->driverName() === 'sqlite' ? $memberQuery : $memberQuery->lockForUpdate())->first();
            if ($member === null) {
                throw new HttpError(404, 'member_not_found');
            }
            if ($member->getVersion() !== $data['member_version']) {
                throw new HttpError(409, 'stale_version');
            }
            if (!$member->definition()->relation('roles')->loader()->detach($member, $highest->getId())) {
                throw new HttpError(409, 'administrator_not_found');
            }
            $member->touch();
            RoleService::requireHighest('customer', $tenantId);
            $tenant->touch();
            AuditLog::append($connection, $tenantId, $identity, $action, $member->getId(), 'success', ['version' => $member->getVersion(), 'grantee_id' => $member->getUserId(), 'context' => 'admin'], 'admin');
            return ['tenant' => $this->tenantValues($tenant), 'administrator' => null, 'removed_member_id' => $member->getId()];
        }

        $isNew = (bool) $data['new_customer'];
        if ($isNew) {
            if (!isset($data['owner_name'], $data['owner_password'])) {
                throw new HttpError(422, 'tenant_owner_input_invalid');
            }
            $user = (new IdentityService('customer'))->provision($data['login'], $data['owner_name'], $data['owner_password'], false, '', $identity, 'admin');
        } else {
            if (array_key_exists('owner_name', $data) || array_key_exists('owner_password', $data)) {
                throw new HttpError(422, 'tenant_owner_input_invalid');
            }
            $userQuery = CustomerUser::query()->master()->where('login', '=', $data['login'])->where('enabled', '=', true)->where('recovery_verified', '=', true);
            $account = ($connection->driverName() === 'sqlite' ? $userQuery : $userQuery->lockForUpdate())->first();
            if ($account === null) {
                throw new HttpError(422, 'administrator_account_unavailable');
            }
            $user = $account->project(['id', 'login', 'name']);
        }
        $memberQuery = CustomerMember::query()->master()->with('roles')->where('user_id', '=', $user['id']);
        $member = ($connection->driverName() === 'sqlite' ? $memberQuery : $memberQuery->lockForUpdate())->first();
        if ($member !== null) {
            if (!$member->getEnabled() || !$member->getRecoveryVerified()) {
                throw new HttpError(422, 'administrator_member_unavailable');
            }
            foreach ($member->related('roles') as $role) {
                if ($role->getId() === $highest->getId()) {
                    throw new HttpError(409, 'administrator_exists');
                }
            }
        } else {
            $member = new CustomerMember(['id' => bin2hex(random_bytes(16)), 'user_id' => $user['id'], 'name' => $user['name'], 'enabled' => true, 'recovery_verified' => true, 'created_at' => $now]);
            $member->save();
        }
        $replacement = null;
        if ($action === 'admin.tenants.administrators.replace') {
            if (!isset($data['replace_member_id'], $data['replace_member_version']) || $data['replace_member_id'] === $member->getId()) {
                throw new HttpError(422, 'administrator_replacement_invalid');
            }
            $replacementQuery = CustomerMember::query()->master()->where('id', '=', $data['replace_member_id']);
            $replacement = ($connection->driverName() === 'sqlite' ? $replacementQuery : $replacementQuery->lockForUpdate())->first();
            if ($replacement === null) {
                throw new HttpError(404, 'member_not_found');
            }
            if ($replacement->getVersion() !== $data['replace_member_version']) {
                throw new HttpError(409, 'stale_version');
            }
            if (!$replacement->definition()->relation('roles')->loader()->detach($replacement, $highest->getId())) {
                throw new HttpError(409, 'administrator_not_found');
            }
        }
        $member->definition()->relation('roles')->loader()->attach($member, $highest->getId());
        $member->touch();
        $memberVersion = $member->getVersion();
        if ($replacement !== null) {
            $replacement->touch();
        }
        RoleService::requireHighest('customer', $tenantId);
        $tenant->touch();
        AuditLog::append($connection, $tenantId, $identity, $action, $member->getId(), 'success', ['version' => $memberVersion, 'grantee_id' => $member->getUserId(), 'context' => 'admin'], 'admin');
        return ['tenant' => $this->tenantValues($tenant), 'administrator' => ['id' => $user['id'], 'member_id' => $member->getId(), 'member_version' => $memberVersion, 'login' => $user['login'], 'name' => $user['name']], 'replaced_member_id' => $replacement?->getId()];
    }

    /** @return array<string, mixed> 当前租户成员投影与分页角色，不读取其他租户或全局凭据。 */
    public function members(Identity $identity, string $tenantId, string $id, array $filters): array
    {
        $permissions = RoleService::permissions($identity, 'customer', $tenantId);
        if (!in_array('customer.members.read', $permissions, true)) {
            throw new HttpError(403, 'permission_denied');
        }
        return \Type\Runtime\ExecutionScope::current()->run(function (\Type\Runtime\ExecutionScope $current) use ($id, $filters, $permissions): array {
            $rows = CustomerMember::query()->master()->with('user')->with('roles', static fn (ModelQuery $roles): ModelQuery => $roles
                ->select(['id', 'name', 'enabled', 'protected', 'version', 'recovery_verified'])->orderBy('name')->orderBy('id'));
            if ($id !== '') {
                $rows = $rows->where('id', '=', $id);
            } else {
                if ($filters['search'] !== '') {
                    $rows = $rows->whereHas('user', static fn (\Type\Orm\ModelQuery $conditions): \Type\Orm\ModelQuery => $conditions
                        ->where('login', 'LIKE', '%' . $filters['search'] . '%'));
                }
                if ($filters['enabled'] !== -1) {
                    $rows = $rows->where('enabled', '=', (bool) $filters['enabled']);
                }
            }
            $result = $this->memberPage($rows->orderBy('created_at', 'DESC')->orderBy('id'), $id === '' ? $filters['page'] : 1, $id === '' ? $filters['per_page'] : 1);
            if ($id !== '' && $result['items'] === []) {
                throw new HttpError(404, 'member_not_found');
            }
            return $result + ['permissions' => $permissions, 'catalog' => RoleService::catalog('customer'), 'menus' => RoleService::menus('customer', $permissions)];
        }, ['tenant_id' => $tenantId]);
    }

    /**
     * RoleService 持授权锁并校验当前权限及目标后调用，外层统一保护最后最高管理员。
     * 创建账号、成员与角色原子提交；既有客户只按准确登录名关联，后续只修改成员字段。
     * @param array<string, mixed> 已校验的动作字段、版本和显式角色绑定。
     * @return array<string, mixed> 成员回执，永不返回全局口令或其他租户信息。
     */
    public function changeMember(Identity $identity, string $tenantId, string $action, string $id, array $data): array
    {
        $connection = \Type\Orm\Db::connection('default', true);
        if ($connection->transactionDepth() < 1 || !in_array('realm:customer', $identity->roles(), true)) {
            throw new \LogicException('member_change_requires_authorized_transaction');
        }
        if (isset($data['name']) && (trim($data['name']) === '' || strlen($data['name']) > 100)) {
            throw new HttpError(422, 'member_name_invalid');
        }
        if ($action === 'customer.members.create') {
            $user = [];
            if ($data['new_customer']) {
                if (!isset($data['account_name'], $data['password'])) {
                    throw new HttpError(422, 'member_input_invalid');
                }
                $user = (new IdentityService('customer'))->provision($data['login'], $data['account_name'], $data['password'], false, '', $identity);
            } else {
                if (array_key_exists('account_name', $data) || array_key_exists('password', $data)) {
                    throw new HttpError(422, 'member_input_invalid');
                }
                $query = CustomerUser::query()->master()->where('login', '=', $data['login'])->where('enabled', '=', true)->where('recovery_verified', '=', true);
                $query = $connection->driverName() === 'sqlite' ? $query : $query->lockForUpdate();
                $userModel = $query->first();
                $user = $userModel === null ? null : [
                    'id' => $userModel->getId(), 'name' => $userModel->getName(), 'login' => $userModel->getLogin(),
                ];
            }
            if ($user === null) {
                throw new HttpError(422, 'account_not_available');
            }
            if (CustomerMember::query()->master()->where('user_id', '=', $user['id'])->first() !== null) {
                throw new HttpError(409, 'member_exists');
            }
            $id = bin2hex(random_bytes(16));
            $member = new CustomerMember([
                'id' => $id, 'tenant_id' => $tenantId, 'user_id' => $user['id'], 'name' => trim($data['name']),
                'enabled' => true, 'recovery_verified' => true, 'created_at' => time(),
            ]);
            $member->save();
        } else {
            $query = CustomerMember::query()->master()->where('id', '=', $id);
            $member = $query->first();
            if ($member === null) {
                throw new HttpError(404, 'member_not_found');
            }
            if ($member->getVersion() !== $data['version']) {
                throw new HttpError(409, 'stale_version');
            }
            if ($action === 'customer.members.delete') {
                $user = ['id' => $member->getUserId()];
                $member->definition()->relation('roles')->loader()->sync($member, []);
                $member->delete();
            } else {
                if ($action === 'customer.members.update') {
                    $member->setName(trim($data['name']));
                } elseif ($action === 'customer.members.status') {
                    $member->setEnabled((bool) $data['enabled']);
                } else {
                    throw new \InvalidArgumentException('member_action_invalid');
                }
                $member->save();
            }
        }
        if ($action === 'customer.members.delete') {
            AuditLog::append($connection, $tenantId, $identity, $action, $id, 'success', ['version' => $data['version'], 'grantee_id' => $user['id'] ?? '', 'context' => 'customer'], 'customer');
            return ['id' => $id, 'deleted' => true];
        }
        $values = $this->memberValues($member);
        AuditLog::append($connection, $tenantId, $identity, $action, $id, 'success', ['version' => $values['version'], 'grantee_id' => $values['user_id'], 'context' => 'customer'], 'customer');
        return $values;
    }

    /** 模型查询分页只返回显式字段投影，不把持久化对象直接交给 HTTP。 */
    private function modelPage(ModelQuery $rows, int $page, int $perPage): array
    {
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) {
            throw new HttpError(422, 'invalid_pagination');
        }
        $result = $rows->paginate($page, $perPage);
        $items = [];
        foreach ($result->items() as $tenant) {
            $items[] = $this->tenantValues($tenant);
        }
        return ['items' => $items, 'total' => $result->total(), 'page' => $result->number(), 'per_page' => $result->perPage()];
    }

    /** 成员查询使用关联模型投影登录名，租户条件由已验证的当前执行上下文固定。 */
    private function memberPage(ModelQuery $rows, int $page, int $perPage): array
    {
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) {
            throw new HttpError(422, 'invalid_pagination');
        }
        $result = $rows->paginate($page, $perPage);
        $items = [];
        foreach ($result->items() as $member) {
            $values = $this->memberValues($member);
            $user = $member->related('user');
            $values['login'] = $user === null ? '' : $user->getLogin();
            $values['roles'] = [];
            foreach ($member->related('roles') as $role) {
                $values['roles'][] = [
                    'id' => $role->getId(), 'name' => $role->getName(), 'enabled' => $role->getEnabled() ? 1 : 0,
                    'protected' => $role->getProtected() ? 1 : 0, 'version' => $role->getVersion(),
                    'recovery_verified' => $role->getRecoveryVerified() ? 1 : 0,
                ];
            }
            $items[] = $values;
        }
        return ['items' => $items, 'total' => $result->total(), 'page' => $result->number(), 'per_page' => $result->perPage()];
    }

    /** 两个列表的关联均由真实唯一键保证一对一，并已按主表主键收尾排序。 */
    private function joinedPage(Query $rows, int $page, int $perPage): array
    {
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) {
            throw new HttpError(422, 'invalid_pagination');
        }
        return ['items' => $rows->limit($perPage, ($page - 1) * $perPage)->get(), 'total' => (int) $rows->aggregate('COUNT'), 'page' => $page, 'per_page' => $perPage];
    }

    /** 租户 Model 的稳定 HTTP 投影；数据库布尔值保持既有整数契约。 */
    private function tenantValues(Tenant $tenant): array
    {
        return [
            'id' => $tenant->getId(), 'name' => $tenant->getName(), 'enabled' => $tenant->getEnabled() ? 1 : 0,
            'version' => $tenant->getVersion(), 'created_at' => $tenant->getCreatedAt(),
        ];
    }

    /** 成员 Model 的稳定业务投影，不包含全局客户凭据。 */
    private function memberValues(CustomerMember $member): array
    {
        return [
            'id' => $member->getId(), 'tenant_id' => $member->getTenantId(), 'user_id' => $member->getUserId(),
            'name' => $member->getName(), 'enabled' => $member->getEnabled() ? 1 : 0,
            'version' => $member->getVersion(), 'created_at' => $member->getCreatedAt(),
            'recovery_verified' => $member->getRecoveryVerified() ? 1 : 0,
        ];
    }

    private function platform(Identity $identity): void
    {
        if (!in_array('platform_admin', $identity->roles(), true)) {
            throw new HttpError(403, 'platform_forbidden');
        }
    }
}
