<?php

declare(strict_types=1);

namespace app\platform\service;

use app\common\middleware\TenantContext;
use app\common\service\AuditLog;
use app\common\service\IdentityService;
use app\common\service\RoleService;
use app\platform\model\PlatformSetting;
use app\platform\model\Tenant;
use app\system\model\SystemRole;
use app\system\model\SystemSetting;
use app\system\model\SystemUser;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Orm\Db;
use Type\Orm\ModelQuery;
use Type\Runtime\ExecutionScope;

/**
 * 客户开通与平台对系统超管的显式管理，不建立跨客户人员成员关系。
 */
final class TenantService
{
    /**
     * 登录前公开客户选择，只返回启用客户的标识与名称。
     */
    public static function publicListing(array $filters): array
    {
        $query = Tenant::query()->master()->where('enabled', '=', true);
        if (($filters['search'] ?? '') !== '') {
            $query = $query->where('name', 'LIKE', '%' . $filters['search'] . '%');
        }
        $page = \_query($query, ['page' => $filters['page'] ?? 1, 'page_size' => $filters['per_page'] ?? 20])
            ->order(['name' => 'name', 'id' => 'id'], ['name' => 'ASC', 'id' => 'ASC'])
            ->paginatePage(20, 100, 100000);
        $items = [];
        foreach ($page->items() as $tenant) {
            $items[] = $tenant->project(['id', 'name']);
        }
        return ['items' => $items, 'total' => $page->total(), 'page' => $page->number(), 'per_page' => $page->perPage()];
    }

    /**
     * 登录前公开客户上下文，只返回公开展示白名单中的客户摘要。
     */
    public static function publicSummary(string $id): array
    {
        $tenant = Tenant::query()->master()->where('enabled', '=', true)->find($id);
        if ($tenant === null) {
            throw new HttpError(404, 'tenant_not_found');
        }
        return $tenant->project(['id', 'name']);
    }

    /**
     * 平台目录与详情；超管清单在指定客户上下文内查询。
     */
    public static function listing(Identity $identity, array $filters, string $id = ''): array
    {
        RoleService::readContext($identity, 'platform', '', 'tenants.read');
        $query = Tenant::query()->master();
        if ($id !== '') {
            $query = $query->where('id', '=', $id);
        }
        $page = \_query($query, [
            'search' => $filters['search'] ?? '',
            'enabled' => ($filters['enabled'] ?? -1) === -1 ? '' : (bool)$filters['enabled'],
            'page' => $filters['page'] ?? 1,
            'page_size' => $filters['per_page'] ?? 20,
        ])->like(['search' => 'name'])->equal('enabled')
            ->order(['created_at' => 'created_at', 'id' => 'id'], ['created_at' => 'DESC', 'id' => 'ASC'])
            ->paginatePage(20, 100, 100000);
        $items = [];
        foreach ($page->items() as $tenant) {
            $row = $tenant->toArray();
            if ($id !== '') {
                $row['administrators'] = self::administrators($tenant->id);
            }
            $items[] = $row;
        }
        if ($id !== '' && $items === []) {
            throw new HttpError(404, 'tenant_not_found');
        }
        return ['items' => $items, 'total' => $page->total(), 'page' => $page->number(), 'per_page' => $page->perPage()];
    }

    /**
     * 同一请求重试不重复开通或交付口令，遗失秘密通过客户超管重置恢复。
     */
    public static function create(Identity $identity, array $data): array
    {
        return RoleService::authorized($identity, null, 'platform.tenants.create', static function (Identity $actor, array $permissions) use ($data): array {
            self::subdomain($data['subdomain']);
            $hash = hash('sha256', json_encode([$actor->subject(), $data['name'], $data['subdomain'], $data['login'], $data['owner_name']], JSON_THROW_ON_ERROR));
            $previous = Tenant::query()->master()->where('request_id', '=', $data['request_id'])->first();
            if ($previous !== null) {
                if (!hash_equals($previous->request_hash, $hash)) {
                    throw new HttpError(409, 'tenant_request_conflict');
                }
                return ['tenant' => $previous->toArray(), 'credential' => null, 'replayed' => true];
            }
            self::uniqueDomain($data['subdomain'], '');
            $tenant = Tenant::create(['id' => bin2hex(random_bytes(16)), 'name' => $data['name'], 'subdomain' => $data['subdomain'],
                'enabled' => true, 'request_id' => $data['request_id'], 'request_hash' => $hash]);
            $result = ExecutionScope::current()->run(static function (ExecutionScope $scope) use ($tenant, $data, $actor): array {
                $password = bin2hex(random_bytes(16));
                $user = (new IdentityService('system'))->provision($data['login'], $data['owner_name'], $password, false, '', $actor, 'platform');
                RoleService::initializeScope('system', $tenant->id, $user['id']);
                $defaults = PlatformSetting::query()->master()->findOrFail(2)->toArray();
                unset($defaults['id'], $defaults['version'], $defaults['created_at'], $defaults['updated_at']);
                $defaults['name'] = $tenant->name;
                SystemSetting::create(['id' => 1, ...$defaults]);
                return ['user' => $user, 'password' => $password];
            }, TenantContext::bindings($tenant->id));
            AuditLog::append(Db::connection('default', true), $tenant->id, $actor, 'tenant.created', $tenant->id, 'success', [], 'platform');
            return ['tenant' => $tenant->toArray(), 'credential' => $result, 'replayed' => false];
        }, 'platform', 'platform');
    }

    /**
     * 资料及状态版本冲突整体回滚，停用立即使入口及协议动作失去客户资格。
     */
    public static function change(Identity $identity, string $id, string $action, array $data): array
    {
        return RoleService::authorized($identity, null, 'platform.tenants.' . $action, static function (Identity $actor, array $permissions) use ($id, $action, $data): array {
            $tenant = Tenant::query()->master()->find($id);
            if ($tenant === null) {
                throw new HttpError(404, 'tenant_not_found');
            }
            if ($tenant->version !== $data['version']) {
                throw new HttpError(409, 'stale_version');
            }
            if ($action === 'status') {
                $tenant->enabled = $data['enabled'];
            } else {
                self::subdomain($data['subdomain']);
                self::uniqueDomain($data['subdomain'], $id);
                $tenant->name = $data['name'];
                $tenant->subdomain = $data['subdomain'];
            }
            $tenant->dirty() === [] ? $tenant->touch() : $tenant->save();
            AuditLog::append(Db::connection('default', true), $id, $actor, 'tenant.' . $action, $id, 'success', ['version' => $tenant->version], 'platform');
            return $tenant->toArray();
        }, 'platform', 'platform');
    }

    /**
     * 平台只管理指定客户的超管；原账号保留，移除或替换时保护最后一位有效超管。
     */
    public static function administrator(Identity $identity, string $tenantId, string $action, string $id, array $data): array
    {
        return RoleService::authorized($identity, null, 'platform.tenants.administrators.manage', static function (Identity $actor, array $permissions) use ($tenantId, $action, $id, $data): array {
            $tenant = Tenant::query()->master()->find($tenantId);
            if ($tenant === null) {
                throw new HttpError(404, 'tenant_not_found');
            }
            if ($tenant->version !== $data['version']) {
                throw new HttpError(409, 'stale_version');
            }
            $result = ExecutionScope::current()->run(static function (ExecutionScope $scope) use ($actor, $tenantId, $action, $id, $data): array {
                $role = SystemRole::query()->master()->where('protected', '=', true)->where('enabled', '=', true)->first();
                if ($role === null) {
                    throw new HttpError(409, 'system_administrator_unavailable');
                }
                $password = null;
                if ($action === 'add' || $action === 'replace') {
                    $password = bin2hex(random_bytes(16));
                    $user = (new IdentityService('system'))->provision($data['login'], $data['name'], $password, false, '', $actor, 'platform');
                    $model = SystemUser::query()->master()->findOrFail($user['id']);
                    $model->relation('roles')->attach($role->id);
                }
                if ($id !== '') {
                    $target = SystemUser::query()->master()->find($id);
                    if ($target === null || !SystemUser::query()->master()->where('id', '=', $id)
                        ->whereHas('roles', static fn (ModelQuery $roles): ModelQuery => $roles->where('protected', '=', true))->exists()) {
                        throw new HttpError(404, 'administrator_not_found');
                    }
                    if ($target->version !== $data['user_version']) {
                        throw new HttpError(409, 'stale_version');
                    }
                    if ($action === 'password') {
                        $password = bin2hex(random_bytes(16));
                        $user = (new IdentityService('system'))->change($id, 'password', ['version' => $data['user_version'], 'password' => $password], $actor, 'platform');
                    } elseif ($action === 'status') {
                        $user = (new IdentityService('system'))->change($id, 'status', ['version' => $data['user_version'], 'enabled' => $data['enabled']], $actor, 'platform');
                    } elseif ($action === 'remove' || $action === 'replace') {
                        $target->relation('roles')->detach($role->id);
                        $target->touch();
                    }
                }
                RoleService::requireHighest('system', $tenantId);
                return ['user' => $user ?? null, 'password' => $password];
            }, TenantContext::bindings($tenantId));
            $tenant->touch();
            AuditLog::append(Db::connection('default', true), $tenantId, $actor, 'tenant.administrator_' . $action, $id === '' ? $tenantId : $id, 'success', ['version' => $tenant->version], 'platform');
            return ['tenant' => $tenant->toArray(), 'credential' => $result];
        }, 'platform', 'platform');
    }

    /**
     * 真实超管资格供模拟登录签发及消费时重复核对。
     */
    public static function isAdministrator(string $tenantId, string $userId): bool
    {
        return ExecutionScope::current()->run(static function (ExecutionScope $scope) use ($userId): bool {
            return SystemUser::query()->master()->where('id', '=', $userId)->where('enabled', '=', true)->where('recovery_verified', '=', true)
                ->whereHas('roles', static fn (ModelQuery $roles): ModelQuery => $roles->where('protected', '=', true)
                    ->where('enabled', '=', true)->where('recovery_verified', '=', true))->exists();
        }, TenantContext::bindings($tenantId));
    }

    /**
     * 超管目录在数据库内筛选并分页，停用账号仍可由平台恢复或重置。
     */
    public static function administratorDirectory(Identity $identity, string $tenantId, int $page, int $perPage): array
    {
        RoleService::readContext($identity, 'platform', '', 'tenants.read');
        if (Tenant::query()->master()->find($tenantId) === null) {
            throw new HttpError(404, 'tenant_not_found');
        }
        return ExecutionScope::current()->run(static function (ExecutionScope $scope) use ($page, $perPage): array {
            $result = SystemUser::query()->master()
                ->whereHas('roles', static fn (ModelQuery $roles): ModelQuery => $roles->where('protected', '=', true))
                ->order('id')->paginate($page, $perPage);
            $items = [];
            foreach ($result->items() as $user) {
                $items[] = $user->project(['id', 'login', 'name', 'enabled', 'version']);
            }
            return ['items' => $items, 'total' => $result->total(), 'page' => $result->number(), 'per_page' => $result->perPage()];
        }, TenantContext::bindings($tenantId));
    }

    private static function administrators(string $tenantId): array
    {
        return ExecutionScope::current()->run(static function (ExecutionScope $scope): array {
            $items = [];
            $query = SystemUser::query()->master()->where('enabled', '=', true)->where('recovery_verified', '=', true)
                ->whereHas('roles', static fn (ModelQuery $roles): ModelQuery => $roles->where('protected', '=', true)->where('enabled', '=', true)->where('recovery_verified', '=', true));
            foreach ($query->order('id')->limit(100)->get() as $user) {
                $items[] = $user->project(['id', 'login', 'name', 'enabled', 'version']);
            }
            return $items;
        }, TenantContext::bindings($tenantId));
    }

    private static function subdomain(?string $subdomain): void
    {
        if ($subdomain !== null && (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $subdomain) !== 1
            || in_array($subdomain, ['www', 'platform', 'system', 'center', 'public'], true))) {
            throw new HttpError(422, 'tenant_subdomain_invalid');
        }
    }

    private static function uniqueDomain(?string $subdomain, string $id): void
    {
        if ($subdomain !== null && Tenant::query()->master()->where('subdomain', '=', $subdomain)->where('id', '!=', $id)->first() !== null) {
            throw new HttpError(409, 'tenant_subdomain_exists');
        }
    }
}
