<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\database\Schema;
use app\common\middleware\TenantContext;
use app\platform\model\PlatformInstallation;
use app\platform\model\PlatformRole;
use app\platform\model\PlatformUser;
use app\system\model\SystemRole;
use app\system\model\SystemUser;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Orm\Db;
use Type\Orm\Model;
use Type\Orm\ModelQuery;
use Type\Runtime\ExecutionScope;

/** 双身份域共用授权算法，客户业务范围只来自已认证的 客户系统 会话。 */
final class RoleService
{
    /** 已启用菜单节点是角色可授权目录，客户端不能提供新权限标识。 */
    public static function catalog(string $realm): array
    {
        return MenuService::catalog($realm);
    }

    /** 空库安装及 客户系统 开通事务建立受保护角色，普通预设角色不自动取得业务权限。 */
    public static function initializeScope(string $realm, string $scopeId, string $subjectId): void
    {
        $connection = Db::connection('default', true);
        if ($connection->transactionDepth() < 1) {
            throw new \LogicException('role_initialization_requires_transaction');
        }
        $operation = static function (ExecutionScope $scope) use ($realm, $subjectId): void {
            $values = ['id' => bin2hex(random_bytes(16)), 'name' => '超级管理员', 'enabled' => true,
                'protected' => true, 'recovery_verified' => true];
            if ($realm === 'platform') {
                $platformRole = new PlatformRole($values + ['scope_id' => 'platform']);
                $platformUser = PlatformUser::query()->master()->findOrFail($subjectId);
                $platformRole->save();
                $platformUser->relation('roles')->attach($platformRole->id);
                return;
            }
            $systemRole = new SystemRole($values);
            $systemUser = SystemUser::query()->master()->findOrFail($subjectId);
            $systemRole->save();
            $systemUser->relation('roles')->attach($systemRole->id);
        };
        if ($realm === 'system') {
            ExecutionScope::current()->run($operation, TenantContext::bindings($scopeId));
        } else {
            $operation(ExecutionScope::current());
        }
    }

    /** 多个有效角色取并集；受保护超管取得本域当前已启用目录，不合并平台权限。 */
    public static function permissions(Identity $identity, string $realm, string $tenantId = ''): array
    {
        $attributes = $identity->attributes();
        if (!in_array($realm, ['platform', 'system'], true) || ($attributes['realm'] ?? '') !== $realm
            || ($realm === 'system' && ($tenantId === '' || ($attributes['tenant_id'] ?? '') !== $tenantId))) {
            throw new HttpError(403, 'identity_scope_forbidden');
        }
        if ($realm === 'system' && ExecutionScope::current()->binding('tenant_id') !== $tenantId) {
            throw new HttpError(403, 'identity_scope_forbidden');
        }
        $subject = $identity->subject();
        $roles = self::roleQuery($realm)->where('enabled', '=', true)->where('recovery_verified', '=', true)
            ->whereHas('users', static fn (ModelQuery $users): ModelQuery => $users->where('id', '=', $subject))
            ->select(['id', 'protected'])->get();
        $catalog = self::catalog($realm);
        foreach ($roles as $role) {
            if ($role->protected) {
                return array_keys($catalog);
            }
        }
        if ($roles === []) {
            return [];
        }
        self::roleQuery($realm)->with('menus')->load($roles);
        $grants = [];
        foreach ($roles as $role) {
            $grants = [...$grants, ...self::grantedPermissions($role, $realm)];
        }
        return array_values(array_intersect(array_keys($catalog), $grants));
    }

    /** 读取也重新检查会话、来源与当前目录，返回可供耗时操作后复核的上下文。 */
    public static function readContext(Identity $identity, string $realm, string $tenantId, string $permission): array
    {
        $current = (new IdentityService($realm))->refresh($identity);
        if ($current === null) {
            throw new HttpError(401, 'unauthenticated');
        }
        $permissions = self::permissions($current, $realm, $tenantId);
        $required = preg_match('/^(platform|system|center)\./', $permission) === 1 ? $permission : $realm . '.' . $permission;
        self::requirePermission($permissions, $required);
        return ['identity' => IdentityService::context($current, $tenantId), 'permissions' => $permissions];
    }

    /** 菜单树按当前权限过滤，目录本身不产生权限。 */
    public static function menus(string $realm, array $permissions): array
    {
        return MenuService::navigation($realm, $permissions);
    }

    /** 写锁后复核身份及权限；业务事实与审计沿用同一事务。 */
    public static function authorized(Identity $identity, ?string $token, string $permission, \Closure $operation, string $realm = 'system', string $scopeId = ''): array
    {
        $connection = Db::connection('default', true);
        return Db::transaction(static function () use ($identity, $token, $permission, $operation, $realm, $scopeId): array {
            self::lockAuthorization();
            $current = $token === null ? (new IdentityService($realm))->refresh($identity) : (new IdentityService($realm))->authenticate($token);
            if ($current === null || $current->subject() !== $identity->subject()) {
                throw new HttpError(401, 'unauthenticated');
            }
            $tenantId = $realm === 'system' ? (string)($current->attributes()['tenant_id'] ?? '') : '';
            if ($realm === 'system' && $scopeId !== '' && $scopeId !== $tenantId) {
                throw new HttpError(403, 'tenant_context_mismatch');
            }
            $permissions = self::permissions($current, $realm, $tenantId);
            self::requirePermission($permissions, $permission);
            if ($realm === 'system') {
                return ExecutionScope::current()->run(static fn (ExecutionScope $scope): array => $operation($current, $permissions), TenantContext::bindings($tenantId));
            }
            return $operation($current, $permissions);
        }, 'default', $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default');
    }

    /** 授权、登录和身份变更共用安装锁，旧安装不能进入新数据结构。 */
    public static function lockAuthorization(): void
    {
        $connection = Db::connection('default', true);
        if ($connection->transactionDepth() < 1) {
            throw new \LogicException('authorization_requires_transaction');
        }
        $query = PlatformInstallation::query()->master()->where('id', '=', 1);
        $row = ($connection->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
        if ($row === null || (int)$row->schema_version !== Schema::VERSION) {
            throw new HttpError(503, 'installation_incompatible');
        }
    }

    /** 受保护角色与有效账号必须在同一域仍有绑定，禁止撤走最后一位超管。 */
    public static function requireHighest(string $realm, string $scopeId): void
    {
        if ($realm === 'system' && ExecutionScope::current()->binding('tenant_id') !== $scopeId) {
            throw new HttpError(403, 'tenant_context_mismatch');
        }
        $query = self::roleQuery($realm)->where('protected', '=', true)->where('enabled', '=', true)->where('recovery_verified', '=', true)
            ->whereHas('users', static fn (ModelQuery $users): ModelQuery => $users->where('enabled', '=', true)->where('recovery_verified', '=', true));
        if (!$query->exists()) {
            throw new HttpError(409, 'last_super_administrator');
        }
    }

    /** 人员及角色目录共用模型投影和有界分页，System 模型自动约束 客户系统。 */
    public static function directory(Identity $identity, string $kind, string $id, array $filters, string $realm = 'platform', string $scopeId = 'platform'): array
    {
        if (!in_array($kind, ['users', 'roles'], true)) {
            throw new \InvalidArgumentException('identity_resource_invalid');
        }
        $tenantId = $realm === 'system' ? (string)($identity->attributes()['tenant_id'] ?? '') : '';
        $access = self::readContext($identity, $realm, $tenantId, $kind . '.read');
        $query = $kind === 'roles' ? self::roleQuery($realm) : ($realm === 'platform' ? PlatformUser::query()->master() : SystemUser::query()->master());
        if ($id !== '') {
            $query = $query->where('id', '=', $id);
        }
        $query = $query->with($kind === 'users' ? 'roles' : 'menus');
        $page = \_query($query, [
            'search' => $filters['search'] ?? '',
            'enabled' => ($filters['enabled'] ?? -1) === -1 ? '' : (bool)$filters['enabled'],
            'page' => $filters['page'] ?? 1,
            'page_size' => $filters['per_page'] ?? 20,
        ])->like('search')->equal('enabled')
            ->order(['created_at' => 'created_at', 'id' => 'id'], ['created_at' => 'DESC', 'id' => 'ASC'])
            ->paginatePage(20, 100, 100000);
        $items = [];
        foreach ($page->items() as $model) {
            $item = $model->project($kind === 'roles' ? ['id', 'name', 'enabled', 'protected', 'version', 'created_at'] : ['id', 'login', 'name', 'enabled', 'version', 'created_at']);
            if ($kind === 'roles') {
                $item['permissions'] = $item['protected'] ? array_keys(self::catalog($realm)) : self::grantedPermissions($model, $realm);
                $item['menu_ids'] = MenuService::idsForPermissions($realm, $item['permissions']);
            } else {
                $item['roles'] = [];
                foreach ($model->related('roles') as $role) {
                    $item['roles'][] = $role->project(['id', 'name', 'enabled', 'protected', 'version']);
                }
            }
            $items[] = $item;
        }
        if ($id !== '' && $items === []) {
            throw new HttpError(404, $kind === 'roles' ? 'role_not_found' : 'user_not_found');
        }
        return ['items' => $items, 'total' => $page->total(), 'page' => $page->number(), 'per_page' => $page->perPage(),
            'permissions' => $access['permissions'], 'menus' => self::menus($realm, $access['permissions'])];
    }

    /** 双端人员、角色及关联写入共享授权事实，任何失败都回滚整个批次。 */
    public static function change(Identity $identity, string $token, string $action, string $id, array $data, string $realm = 'platform', string $scopeId = 'platform'): array
    {
        if (!in_array($realm, ['platform', 'system'], true) || !str_starts_with($action, $realm . '.')) {
            throw new \InvalidArgumentException('authorization_scope_invalid');
        }
        return self::authorized($identity, $token, $action, static function (Identity $current, array $permissions) use ($action, $id, $data, $realm): array {
            $tenant = $realm === 'system' ? (string)$current->attributes()['tenant_id'] : '';
            $super = self::isAdministrator($realm, $current->subject(), $tenant);
            $operation = substr($action, strlen($realm) + 1);
            $identities = new IdentityService($realm);
            if ($operation === 'roles.assign') {
                $result = self::assign($current, $permissions, $super, $data, $realm, $tenant);
            } elseif ($operation === 'users.create') {
                $result = $identities->provision($data['login'], $data['name'], $data['password'], false, '', $current);
            } elseif (str_starts_with($operation, 'users.')) {
                self::protectSubject($id, $permissions, $super, $realm, $tenant);
                $userAction = substr($operation, 6);
                if ($userAction === 'delete') {
                    $user = self::userQuery($realm)->findOrFail($id);
                    self::version($user, $data['version']);
                    $identities->change($id, 'sessions', $data, $current);
                    $user->relation('roles')->sync([]);
                    $user->delete();
                    AuditLog::append(Db::connection('default', true), $tenant === '' ? null : $tenant, $current, $action, $id, 'success', [], $realm);
                    $result = ['id' => $id, 'deleted' => true];
                } else {
                    $result = $identities->change($id, $userAction, $data, $current);
                }
            } else {
                $result = self::changeRole($current, $permissions, $super, substr($operation, 6), $id, $data, $realm, $tenant);
            }
            self::requireHighest($realm, $tenant);
            return $result;
        }, $realm, $scopeId);
    }

    /** 是否持有本域有效超管角色；跨客户不能通过用户标识推导资格。 */
    public static function isAdministrator(string $realm, string $userId, string $tenant = ''): bool
    {
        if ($realm === 'system' && ExecutionScope::current()->binding('tenant_id') !== $tenant) {
            throw new HttpError(403, 'tenant_context_mismatch');
        }
        return self::roleQuery($realm)->where('protected', '=', true)->where('enabled', '=', true)->where('recovery_verified', '=', true)
            ->whereHas('users', static fn (ModelQuery $users): ModelQuery => $users->where('id', '=', $userId))->exists();
    }

    /** 包含停用角色的潜在授权；不能借重置密码、启用或重新分配接管高权限账号。 */
    private static function protectSubject(string $id, array $permissions, bool $super, string $realm, string $tenant): void
    {
        $user = self::userQuery($realm)->where('id', '=', $id)->with('roles')->first();
        if ($user === null) {
            throw new HttpError(404, 'user_not_found');
        }
        foreach ($user->related('roles') as $role) {
            self::manageable($role, $permissions, $super, $realm);
        }
    }

    /** 受保护角色的自动扩权资格只允许受保护超管授予。 */
    private static function manageable(Model $role, array $permissions, bool $super, string $realm): void
    {
        $grants = self::grantedPermissions($role, $realm);
        if (!$super && ($role->protected || array_diff($grants, $permissions) !== [])) {
            throw new HttpError(403, 'permission_escalation');
        }
    }

    /** 每项关联带预期版本，单批至多一百人、每人六十四角色。 */
    private static function assign(Identity $identity, array $permissions, bool $super, array $data, string $realm, string $tenant): array
    {
        $roles = $data['roles'];
        $users = $data['users'];
        if (count(array_unique(array_column($roles, 'id'))) !== count($roles) || count(array_unique(array_column($users, 'id'))) !== count($users)) {
            throw new HttpError(422, 'role_binding_invalid');
        }
        foreach ($roles as $binding) {
            $role = self::roleQuery($realm)->find($binding['id']);
            if ($role === null) {
                throw new HttpError(404, 'role_not_found');
            }
            self::version($role, $binding['version']);
            self::manageable($role, $permissions, $super, $realm);
        }
        foreach ($users as $binding) {
            self::protectSubject($binding['id'], $permissions, $super, $realm, $tenant);
            $user = self::userQuery($realm)->findOrFail($binding['id']);
            self::version($user, $binding['version']);
            $user->relation('roles')->sync(array_map(static fn (array $role): array => ['id' => $role['id']], $roles));
            $user->touch();
            AuditLog::append(Db::connection('default', true), $tenant === '' ? null : $tenant, $identity, 'authorization.assigned', $binding['id'], 'success', ['version' => $binding['version'] + 1, 'content_hash' => hash('sha256', json_encode($roles, JSON_THROW_ON_ERROR))], $realm);
        }
        return ['updated' => count($users)];
    }

    /** 角色只能关联平台已启用且操作者持有的节点；复制超管形成普通显式授权角色。 */
    private static function changeRole(Identity $identity, array $permissions, bool $super, string $operation, string $id, array $data, string $realm, string $tenant): array
    {
        $connection = Db::connection('default', true);
        $role = $operation === 'create' ? null : self::roleQuery($realm)->find($id);
        if ($operation !== 'create') {
            if ($role === null) {
                throw new HttpError(404, 'role_not_found');
            }
            self::version($role, $data['version']);
            self::manageable($role, $permissions, $super, $realm);
        }
        if (in_array($operation, ['create', 'copy', 'update'], true)) {
            $duplicate = self::roleQuery($realm)->where('name', '=', $data['name']);
            if ($operation === 'update') {
                $duplicate = $duplicate->where('id', '!=', $id);
            }
            if ($duplicate->first() !== null) {
                throw new HttpError(409, 'role_exists');
            }
        }
        $auditVersion = $role === null ? 1 : (int)$role->version;
        $grants = [];
        if (in_array($operation, ['create', 'copy', 'permissions'], true)) {
            self::requirePermission($permissions, $realm . '.roles.permissions');
            $grants = $operation === 'copy'
                ? ($role->protected ? array_keys(self::catalog($realm)) : array_values(array_intersect(array_keys(self::catalog($realm)), self::grantedPermissions($role, $realm))))
                : MenuService::permissionsForIds($realm, $data['menu_ids']);
            if (array_diff($grants, $permissions) !== []) {
                throw new HttpError(403, 'permission_escalation');
            }
        }
        if (in_array($operation, ['create', 'copy'], true)) {
            $id = bin2hex(random_bytes(16));
            $values = ['id' => $id, 'name' => $data['name'], 'enabled' => false, 'protected' => false, 'recovery_verified' => true];
            $role = $realm === 'platform' ? new PlatformRole($values + ['scope_id' => 'platform']) : new SystemRole($values);
            $role->save();
        } else {
            if ($role->protected && ($operation === 'delete' || $operation === 'permissions' || ($operation === 'status' && !$data['enabled']))) {
                throw new HttpError(409, 'protected_role');
            }
            if ($operation === 'delete') {
                do {
                    $users = self::userQuery($realm)->whereHas('roles', static fn (ModelQuery $query): ModelQuery => $query->where('id', '=', $id))->orderBy('id')->limit(100)->get();
                    foreach ($users as $user) {
                        $user->relation('roles')->detach($id);
                        $user->touch();
                    }
                } while ($users !== []);
                $role->relation('menus')->sync([]);
                $role->delete();
            } else {
                if ($operation === 'update') {
                    $role->name = $data['name'];
                } elseif ($operation === 'status') {
                    $role->enabled = $data['enabled'];
                } elseif ($operation !== 'permissions') {
                    throw new \InvalidArgumentException('role_action_invalid');
                }
                $role->dirty() === [] ? $role->touch() : $role->save();
            }
        }
        if (in_array($operation, ['create', 'copy', 'permissions'], true)) {
            $role->relation('menus')->sync(array_map(static fn (string $grant): array => ['id' => $grant], $grants));
        }
        AuditLog::append($connection, $tenant === '' ? null : $tenant, $identity, $realm . '.roles.' . $operation, $id, 'success', ['version' => $operation === 'delete' ? $auditVersion : (int)$role->version], $realm);
        return $operation === 'delete' ? ['id' => $id, 'deleted' => true] : $role->project(['id', 'name', 'enabled', 'protected', 'version']);
    }

    private static function version(Model $model, int $version): void
    {
        if ((int)$model->version !== $version) {
            throw new HttpError(409, 'stale_version');
        }
    }

    /** 显式加载授权关系；包含停用菜单事实，供防越权校验与目录展示复用。 */
    private static function grantedPermissions(Model $role, string $realm): array
    {
        self::roleQuery($realm)->with('menus')->loadMissing([$role]);
        $permissions = [];
        foreach ($role->related('menus') as $menu) {
            $permissions[] = $menu->permission;
        }
        return $permissions;
    }

    private static function userQuery(string $realm): ModelQuery
    {
        return $realm === 'platform' ? PlatformUser::query()->master() : SystemUser::query()->master();
    }

    /** 原生模型入口供人员管理事务复用，不按请求输入选择数据表。 */
    private static function roleQuery(string $realm): ModelQuery
    {
        return $realm === 'platform' ? PlatformRole::query()->master() : SystemRole::query()->master();
    }

    private static function requirePermission(array $permissions, string $permission): void
    {
        if (!in_array($permission, $permissions, true)) {
            throw new HttpError(403, 'permission_denied');
        }
    }
}
