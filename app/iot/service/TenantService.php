<?php

declare(strict_types=1);

namespace app\iot\service;

use app\common\service\AuditLog;
use app\common\service\IdentityService;
use app\common\service\RoleService;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Orm\Connection;
use Type\Orm\Query;

/** 双端租户与成员管理；权限来自当前范围的多角色，写入在真实锁内重验。 */
final class TenantService
{
    /**
     * 双端分别查询平台目录或本人有效成员关系，不把平台权限转换为租户权限。
     * @param array{page:int,per_page:int,search:string,enabled:int} $filters 已校验的固定筛选。
     * @return array<string, mixed> 稳定分页与明确租户投影；平台详情另含当前有效最高管理员。
     */
    public function tenants(Connection $connection, Identity $identity, array $filters, bool $platform, string $id = ''): array
    {
        $rows = $connection->table('iot_tenants', 't')->select(['t.id', 't.name', 't.enabled', 't.version', 't.created_at']);
        $permissions = [];
        if ($platform) {
            $permissions = RoleService::permissions($connection, $identity, 'admin');
            if (!in_array('admin.tenants.read', $permissions, true)) {
                throw new HttpError(403, 'permission_denied');
            }
            if ($filters['enabled'] !== -1) {
                $rows = $rows->where('t.enabled', '=', $filters['enabled']);
            }
        } else {
            if (!in_array('realm:customer', $identity->roles(), true)) {
                throw new HttpError(403, 'identity_realm_forbidden');
            }
            (new IdentityService('customer'))->user($connection, $identity->subject());
            $rows = $rows->join('customer_members', 'm.tenant_id', '=', 't.id', 'm')->where('m.user_id', '=', $identity->subject())
                ->where('m.enabled', '=', 1)->where('m.recovery_verified', '=', 1)->where('t.enabled', '=', 1);
        }
        if ($id !== '') {
            $rows = $rows->where('t.id', '=', $id);
        } elseif ($filters['search'] !== '') {
            $rows = $rows->where('t.name', 'LIKE', '%' . $filters['search'] . '%');
        }
        $result = $this->joinedPage($rows->orderBy('t.created_at', 'DESC')->orderBy('t.id'), $id === '' ? $filters['page'] : 1, $id === '' ? $filters['per_page'] : 1);
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

    /**
     * RoleService 在安装行锁内重验平台会话和独立动作权限后调用。
     * 新客户、成员、角色和审计属于同一事务；关联已有客户不能修改全局资料、状态或凭据。
     * @param array<string, mixed> $data 固定动作字段；创建的稳定id仅用于拒绝重复，不赋予身份。
     * @return array<string, mixed> 无秘密的租户资料；失败由外层事务整体回滚。
     */
    public function change(Connection $connection, Identity $identity, string $action, string $id, array $data): array
    {
        if ($connection->transactionDepth() < 1 || !in_array('realm:admin', $identity->roles(), true)) {
            throw new \LogicException('tenant_change_requires_authorized_transaction');
        }
        if (in_array($action, ['admin.tenants.create', 'admin.tenants.update'], true) && (trim($data['name']) === '' || strlen($data['name']) > 100)) {
            throw new HttpError(422, 'tenant_name_invalid');
        }
        if ($action === 'admin.tenants.create') {
            $id = $data['id'];
            if ($connection->table('iot_tenants')->where('id', '=', $id)->first() !== null) {
                throw new HttpError(409, 'tenant_exists');
            }
            if ($data['new_customer']) {
                if (!isset($data['owner_name'], $data['owner_password'])) {
                    throw new HttpError(422, 'tenant_owner_input_invalid');
                }
                $owner = (new IdentityService('customer'))->provision($connection, $data['owner_login'], $data['owner_name'], $data['owner_password'], false, '', $identity->subject(), 'admin');
            } else {
                if (array_key_exists('owner_name', $data) || array_key_exists('owner_password', $data)) {
                    throw new HttpError(422, 'tenant_owner_input_invalid');
                }
                $query = $connection->table('customer_users')->where('login', '=', $data['owner_login'])->where('enabled', '=', 1)->where('recovery_verified', '=', 1);
                $owner = ($connection->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
                if ($owner === null) {
                    throw new HttpError(422, 'owner_not_available');
                }
            }
            $tenant = ['id' => $id, 'name' => trim($data['name']), 'enabled' => 1, 'version' => 1, 'created_at' => time()];
            $connection->table('iot_tenants')->insert($tenant);
            $memberId = bin2hex(random_bytes(16));
            $connection->table('customer_members')->insert(['id' => $memberId, 'tenant_id' => $id, 'user_id' => $owner['id'], 'name' => $owner['name'], 'created_at' => time()]);
            RoleService::initializeScope($connection, 'customer', $id, $memberId);
            AuditLog::append($connection, $id, $identity->subject(), 'member.added', $memberId, 'success', ['grantee_id' => $owner['id'], 'context' => 'admin', 'version' => 1], 'admin');
        } elseif (str_starts_with($action, 'admin.tenants.administrators.')) {
            return $this->changeAdministrator($connection, $identity, $action, $id, $data);
        } else {
            $query = $connection->table('iot_tenants')->where('id', '=', $id);
            $tenant = ($connection->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
            if ($tenant === null) {
                throw new HttpError(404, 'tenant_not_found');
            }
            if ((int) $tenant['version'] !== $data['version']) {
                throw new HttpError(409, 'stale_version');
            }
            $changes = ['version' => $data['version'] + 1];
            if ($action === 'admin.tenants.update') {
                $changes['name'] = trim($data['name']);
            } elseif ($action === 'admin.tenants.status') {
                $changes['enabled'] = $data['enabled'] ? 1 : 0;
            } else {
                throw new \InvalidArgumentException('tenant_action_invalid');
            }
            $query->update($changes);
            $tenant = array_replace($tenant, $changes);
        }
        if ((int) $tenant['enabled'] === 1) {
            RoleService::requireHighest($connection, 'customer', $id);
        }
        AuditLog::append($connection, $id, $identity->subject(), $action, $id, 'success', ['version' => (int) $tenant['version'], 'context' => 'admin'], 'admin');
        return $tenant;
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
        $tenantQuery = $connection->table('iot_tenants')->where('id', '=', $tenantId);
        $tenant = ($connection->driverName() === 'sqlite' ? $tenantQuery : $tenantQuery->lockForUpdate())->first();
        if ($tenant === null) {
            throw new HttpError(404, 'tenant_not_found');
        }
        if ((int) $tenant['version'] !== $data['version']) {
            throw new HttpError(409, 'stale_version');
        }
        $roleQuery = $connection->table('customer_roles')->where('scope_id', '=', $tenantId)->where('protected', '=', 1);
        $highest = ($connection->driverName() === 'sqlite' ? $roleQuery : $roleQuery->lockForUpdate())->first();
        if ($highest === null || (int) $highest['enabled'] !== 1 || (int) $highest['recovery_verified'] !== 1) {
            throw new HttpError(409, 'tenant_admin_role_unavailable');
        }
        $now = time();
        if ($action === 'admin.tenants.administrators.remove') {
            $memberQuery = $connection->table('customer_members')->where('tenant_id', '=', $tenantId)->where('id', '=', $data['member_id']);
            $member = ($connection->driverName() === 'sqlite' ? $memberQuery : $memberQuery->lockForUpdate())->first();
            if ($member === null) {
                throw new HttpError(404, 'member_not_found');
            }
            if ((int) $member['version'] !== $data['member_version']) {
                throw new HttpError(409, 'stale_version');
            }
            $binding = $connection->table('customer_member_roles')->where('tenant_id', '=', $tenantId)->where('member_id', '=', $member['id'])->where('role_id', '=', $highest['id'])->first();
            if ($binding === null) {
                throw new HttpError(409, 'administrator_not_found');
            }
            $connection->table('customer_member_roles')->where('tenant_id', '=', $tenantId)->where('member_id', '=', $member['id'])->where('role_id', '=', $highest['id'])->delete();
            $connection->table('customer_members')->where('tenant_id', '=', $tenantId)->where('id', '=', $member['id'])->update(['version' => (int) $member['version'] + 1]);
            $resultMember = array_replace($member, ['version' => (int) $member['version'] + 1]);
            RoleService::requireHighest($connection, 'customer', $tenantId);
            $tenant = $this->advanceAdministratorTenant($connection, $tenantQuery, $tenant, $data['version']);
            AuditLog::append($connection, $tenantId, $identity, $action, (string) $member['id'], 'success', ['version' => (int) $resultMember['version'], 'grantee_id' => (string) $member['user_id'], 'context' => 'admin'], 'admin');
            return ['tenant' => $tenant, 'administrator' => null, 'removed_member_id' => $member['id']];
        }

        $isNew = (bool) $data['new_customer'];
        if ($isNew) {
            if (!isset($data['owner_name'], $data['owner_password'])) {
                throw new HttpError(422, 'tenant_owner_input_invalid');
            }
            $user = (new IdentityService('customer'))->provision($connection, $data['login'], $data['owner_name'], $data['owner_password'], false, '', $identity, 'admin');
        } else {
            if (array_key_exists('owner_name', $data) || array_key_exists('owner_password', $data)) {
                throw new HttpError(422, 'tenant_owner_input_invalid');
            }
            $userQuery = $connection->table('customer_users')->where('login', '=', $data['login'])->where('enabled', '=', 1)->where('recovery_verified', '=', 1);
            $user = ($connection->driverName() === 'sqlite' ? $userQuery : $userQuery->lockForUpdate())->first();
            if ($user === null) {
                throw new HttpError(422, 'administrator_account_unavailable');
            }
        }
        $memberQuery = $connection->table('customer_members')->where('tenant_id', '=', $tenantId)->where('user_id', '=', $user['id']);
        $member = ($connection->driverName() === 'sqlite' ? $memberQuery : $memberQuery->lockForUpdate())->first();
        if ($member !== null) {
            if ((int) $member['enabled'] !== 1 || (int) $member['recovery_verified'] !== 1) {
                throw new HttpError(422, 'administrator_member_unavailable');
            }
            if ($connection->table('customer_member_roles')->where('tenant_id', '=', $tenantId)->where('member_id', '=', $member['id'])->where('role_id', '=', $highest['id'])->first() !== null) {
                throw new HttpError(409, 'administrator_exists');
            }
        } else {
            $member = ['id' => bin2hex(random_bytes(16)), 'tenant_id' => $tenantId, 'user_id' => $user['id'], 'name' => $user['name'], 'enabled' => 1, 'recovery_verified' => 1, 'version' => 1, 'created_at' => $now];
            $connection->table('customer_members')->insert($member);
        }
        $replacement = null;
        if ($action === 'admin.tenants.administrators.replace') {
            if (!isset($data['replace_member_id'], $data['replace_member_version']) || $data['replace_member_id'] === $member['id']) {
                throw new HttpError(422, 'administrator_replacement_invalid');
            }
            $replacementQuery = $connection->table('customer_members')->where('tenant_id', '=', $tenantId)->where('id', '=', $data['replace_member_id']);
            $replacement = ($connection->driverName() === 'sqlite' ? $replacementQuery : $replacementQuery->lockForUpdate())->first();
            if ($replacement === null) {
                throw new HttpError(404, 'member_not_found');
            }
            if ((int) $replacement['version'] !== $data['replace_member_version']) {
                throw new HttpError(409, 'stale_version');
            }
            if ($connection->table('customer_member_roles')->where('tenant_id', '=', $tenantId)->where('member_id', '=', $replacement['id'])->where('role_id', '=', $highest['id'])->first() === null) {
                throw new HttpError(409, 'administrator_not_found');
            }
        }
        $memberVersion = (int) $member['version'] + 1;
        $connection->table('customer_member_roles')->insert(['tenant_id' => $tenantId, 'member_id' => $member['id'], 'role_id' => $highest['id']]);
        $connection->table('customer_members')->where('tenant_id', '=', $tenantId)->where('id', '=', $member['id'])->update(['version' => $memberVersion]);
        $member['version'] = $memberVersion;
        if ($replacement !== null) {
            $connection->table('customer_member_roles')->where('tenant_id', '=', $tenantId)->where('member_id', '=', $replacement['id'])->where('role_id', '=', $highest['id'])->delete();
            $connection->table('customer_members')->where('tenant_id', '=', $tenantId)->where('id', '=', $replacement['id'])->update(['version' => (int) $replacement['version'] + 1]);
        }
        RoleService::requireHighest($connection, 'customer', $tenantId);
        $tenant = $this->advanceAdministratorTenant($connection, $tenantQuery, $tenant, $data['version']);
        AuditLog::append($connection, $tenantId, $identity, $action, (string) $member['id'], 'success', ['version' => $memberVersion, 'grantee_id' => (string) $member['user_id'], 'context' => 'admin'], 'admin');
        return ['tenant' => $tenant, 'administrator' => ['id' => $user['id'], 'member_id' => $member['id'], 'member_version' => $memberVersion, 'login' => $user['login'], 'name' => $user['name']], 'replaced_member_id' => $replacement['id'] ?? null];
    }

    /** 推进租户版本并返回不含内部列的稳定投影。 */
    private function advanceAdministratorTenant(Connection $connection, Query $query, array $tenant, int $version): array
    {
        $changes = ['version' => $version + 1];
        $query->update($changes);
        return array_replace($tenant, $changes);
    }

    /** @return array<string, mixed> 当前租户成员投影与分页角色，不读取其他租户或全局凭据。 */
    public function members(Connection $connection, Identity $identity, string $tenantId, string $id, array $filters): array
    {
        $permissions = RoleService::permissions($connection, $identity, 'customer', $tenantId);
        if (!in_array('customer.members.read', $permissions, true)) {
            throw new HttpError(403, 'permission_denied');
        }
        $rows = $connection->table('customer_members', 'm')->join('customer_users', 'u.id', '=', 'm.user_id', 'u')->where('m.tenant_id', '=', $tenantId)
            ->select(['m.id', 'm.tenant_id', 'm.user_id', 'm.name', 'm.enabled', 'm.version', 'm.created_at', 'm.recovery_verified', 'u.login']);
        if ($id !== '') {
            $rows = $rows->where('m.id', '=', $id);
        } else {
            if ($filters['search'] !== '') {
                $rows = $rows->where('u.login', 'LIKE', '%' . $filters['search'] . '%');
            }
            if ($filters['enabled'] !== -1) {
                $rows = $rows->where('m.enabled', '=', $filters['enabled']);
            }
        }
        $result = $this->joinedPage($rows->orderBy('m.created_at', 'DESC')->orderBy('m.id'), $id === '' ? $filters['page'] : 1, $id === '' ? $filters['per_page'] : 1);
        if ($id !== '' && $result['items'] === []) {
            throw new HttpError(404, 'member_not_found');
        }
        if ($result['items'] !== []) {
            $ids = array_column($result['items'], 'id');
            $roles = $connection->query('SELECT b.member_id, r.id, r.name, r.enabled, r.protected, r.version, r.recovery_verified FROM customer_member_roles b JOIN customer_roles r ON r.id = b.role_id AND r.scope_id = b.tenant_id WHERE b.tenant_id = ? AND b.member_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY r.name, r.id', [$tenantId, ...$ids]);
            foreach ($result['items'] as $index => $member) {
                $assigned = [];
                foreach ($roles as $role) {
                    if ($role['member_id'] === $member['id']) {
                        unset($role['member_id']);
                        $assigned[] = $role;
                    }
                }
                $result['items'][$index]['roles'] = $assigned;
            }
        }
        return $result + ['permissions' => $permissions, 'catalog' => RoleService::catalog('customer'), 'menus' => RoleService::menus('customer', $permissions)];
    }

    /**
     * RoleService 持授权锁并校验当前权限及目标后调用，外层统一保护最后最高管理员。
     * 创建账号、成员与角色原子提交；既有客户只按准确登录名关联，后续只修改成员字段。
     * @param array<string, mixed> 已校验的动作字段、版本和显式角色绑定。
     * @return array<string, mixed> 成员回执，永不返回全局口令或其他租户信息。
     */
    public function changeMember(Connection $connection, Identity $identity, string $tenantId, string $action, string $id, array $data): array
    {
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
                $user = (new IdentityService('customer'))->provision($connection, $data['login'], $data['account_name'], $data['password'], false, '', $identity);
            } else {
                if (array_key_exists('account_name', $data) || array_key_exists('password', $data)) {
                    throw new HttpError(422, 'member_input_invalid');
                }
                $query = $connection->table('customer_users')->where('login', '=', $data['login'])->where('enabled', '=', 1)->where('recovery_verified', '=', 1);
                $user = ($connection->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
            }
            if ($user === null) {
                throw new HttpError(422, 'account_not_available');
            }
            if ($connection->table('customer_members')->where('tenant_id', '=', $tenantId)->where('user_id', '=', $user['id'])->first() !== null) {
                throw new HttpError(409, 'member_exists');
            }
            $id = bin2hex(random_bytes(16));
            $member = ['id' => $id, 'tenant_id' => $tenantId, 'user_id' => $user['id'], 'name' => trim($data['name']), 'enabled' => 1, 'recovery_verified' => 1, 'version' => 1, 'created_at' => time()];
            $connection->table('customer_members')->insert($member);
        } else {
            $query = $connection->table('customer_members')->where('tenant_id', '=', $tenantId)->where('id', '=', $id);
            $member = $query->first();
            if ($member === null) {
                throw new HttpError(404, 'member_not_found');
            }
            if ((int) $member['version'] !== $data['version']) {
                throw new HttpError(409, 'stale_version');
            }
            if ($action === 'customer.members.delete') {
                $connection->table('customer_member_roles')->where('tenant_id', '=', $tenantId)->where('member_id', '=', $id)->delete();
                $query->delete();
            } else {
                $changes = ['version' => $data['version'] + 1];
                if ($action === 'customer.members.update') {
                    $changes['name'] = trim($data['name']);
                } elseif ($action === 'customer.members.status') {
                    $changes['enabled'] = $data['enabled'] ? 1 : 0;
                } else {
                    throw new \InvalidArgumentException('member_action_invalid');
                }
                $query->update($changes);
                $member = array_replace($member, $changes);
            }
        }
        AuditLog::append($connection, $tenantId, $identity, $action, $id, 'success', ['version' => (int) $member['version'], 'grantee_id' => $member['user_id'], 'context' => 'customer'], 'customer');
        return $action === 'customer.members.delete' ? ['id' => $id, 'deleted' => true] : $member;
    }

    /** 两个列表的关联均由真实唯一键保证一对一，并已按主表主键收尾排序。 */
    private function joinedPage(Query $rows, int $page, int $perPage): array
    {
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) {
            throw new HttpError(422, 'invalid_pagination');
        }
        return ['items' => $rows->limit($perPage, ($page - 1) * $perPage)->get(), 'total' => (int) $rows->aggregate('COUNT'), 'page' => $page, 'per_page' => $perPage];
    }

    /** 在真实租户行上建立写序列，避免两个管理员互相删除而留下零管理员。 */
    private function lock(Connection $connection, string $tenantId): void
    {
        if ($connection->execute('UPDATE iot_tenants SET version = version + 1 WHERE id = ?', [$tenantId]) !== 1) {
            throw new HttpError(403, 'tenant_forbidden');
        }
    }

    private function platform(Identity $identity): void
    {
        if (!in_array('platform_admin', $identity->roles(), true)) {
            throw new HttpError(403, 'platform_forbidden');
        }
    }
}
