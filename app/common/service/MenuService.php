<?php

declare(strict_types=1);

namespace app\common\service;

use app\platform\model\PlatformMenu;
use app\platform\model\PlatformRole;
use app\system\model\SystemMenu;
use app\system\model\SystemRole;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Orm\Db;
use Type\Orm\Model;
use Type\Orm\ModelQuery;

/** 菜单元数据可管理，页面和操作能力只取已实现的固定登记。 */
final class MenuService
{
    /** 已实现页面与操作的唯一登记，同时用于空库初始化和运行时绑定校验。 */
    public static function definitions(): array
    {
        $pages = [
            'platform' => [
                ['users', '平台用户', 'platform-users', ['read', 'create', 'update', 'status', 'password', 'sessions'], true],
                ['roles', '平台角色', 'platform-roles', ['read', 'create', 'copy', 'update', 'status', 'delete', 'permissions', 'assign'], true],
                ['tenants', '客户管理', 'platform-tenants', ['read', 'create', 'update', 'status', 'administrators.manage', 'impersonate'], false],
                ['menus', '菜单管理', 'platform-menus', ['read', 'manage'], true],
                ['site', '平台设置', 'platform-settings', ['read', 'manage'], false],
                ['defaults', '系统登录与初始配置', 'platform-system-defaults', ['read', 'manage'], false],
                ['config', '运行配置', 'platform-configuration', ['read', 'manage'], false],
                ['broker', 'MQTT 管理', 'platform-broker', ['read', 'write'], false],
                ['operations', '运行概览', 'platform-operations', ['read'], false],
                ['audit', '操作审计', 'platform-audit', ['read'], false],
                ['enrollments', '注册资格', 'platform-enrollments', ['read', 'issue', 'disable', 'reset'], false],
                ['devices', '设备资产', 'platform-devices', ['read', 'update', 'enable', 'disable', 'retire', 'rotate', 'revoke', 'expiry', 'end'], false],
                ['applications', '接入应用', 'platform-applications', ['read', 'create', 'update', 'enable', 'disable', 'keys', 'authorize'], false],
                ['reassignments', '设备转配', 'platform-reassignments', ['read', 'create', 'advance'], false],
            ],
            'system' => [
                ['users', '用户管理', 'system-users', ['read', 'create', 'update', 'status', 'password', 'sessions', 'delete'], true],
                ['roles', '角色管理', 'system-roles', ['read', 'create', 'copy', 'update', 'status', 'delete', 'permissions', 'assign'], true],
                ['site', '系统设置', 'system-settings', ['read', 'manage'], true],
                ['audit', '操作审计', 'system-audit', ['read'], false],
                ['center.products', '产品分类', 'center-products', ['read', 'manage'], false],
                ['center.devices', '设备管理', 'center-devices', ['read', 'update', 'enable', 'disable', 'retire', 'rotate', 'revoke'], false],
                ['center.enrollments', '注册资格', 'center-enrollments', ['read'], false],
                ['center.applications', '接入应用', 'center-applications', ['read', 'create', 'update', 'enable', 'disable', 'keys', 'authorize'], false],
                ['center.connections', '连接观察', 'center-connections', ['read'], false],
                ['center.reassignments', '设备转配', 'center-reassignments', ['read', 'accept'], false],
            ],
        ];
        $groups = [
            'platform' => [
                ['operations', '客户与物联', 'lucide:building-2', ['tenants', 'enrollments', 'devices', 'applications', 'reassignments']],
                ['access', '平台权限', 'lucide:shield-check', ['users', 'roles', 'menus']],
                ['monitoring', '运维监控', 'lucide:activity', ['operations', 'broker', 'audit']],
                ['settings', '平台设置', 'lucide:settings', ['site', 'defaults', 'config']],
            ],
            'system' => [
                ['access', '用户与权限', 'lucide:users-round', ['users', 'roles']],
                ['center', '物联中心', 'lucide:cpu', ['center.products', 'center.enrollments', 'center.devices', 'center.applications', 'center.connections', 'center.reassignments']],
                ['settings', '系统管理', 'lucide:settings', ['site', 'audit']],
            ],
        ];
        $icons = ['users' => 'lucide:users', 'roles' => 'lucide:shield-check', 'tenants' => 'lucide:building-2',
            'menus' => 'lucide:menu', 'site' => 'lucide:palette', 'defaults' => 'lucide:panels-top-left',
            'config' => 'lucide:sliders-horizontal', 'broker' => 'lucide:network', 'operations' => 'lucide:activity',
            'audit' => 'lucide:scroll-text', 'products' => 'lucide:boxes', 'devices' => 'lucide:cpu',
            'enrollments' => 'lucide:badge-check', 'applications' => 'lucide:app-window',
            'connections' => 'lucide:plug-zap', 'reassignments' => 'lucide:arrow-left-right'];
        $actionNames = ['create' => '新增', 'update' => '编辑', 'status' => '启停', 'password' => '重置口令',
            'sessions' => '撤销会话', 'copy' => '复制', 'delete' => '删除', 'permissions' => '菜单授权', 'assign' => '分配角色',
            'administrators.manage' => '维护超管', 'impersonate' => '模拟登录', 'manage' => '维护', 'issue' => '签发',
            'disable' => '停用', 'reset' => '重置注册码', 'enable' => '启用', 'retire' => '退役', 'rotate' => '轮换凭据',
            'revoke' => '吊销凭据', 'expiry' => '设置期限', 'end' => '结束使用', 'keys' => '管理密钥',
            'authorize' => '通信授权', 'advance' => '推进隔离', 'accept' => '确认接收', 'write' => '执行运维操作'];
        $nodes = [];
        $parents = [];
        $orders = [];
        foreach ($groups as $realm => $directories) {
            foreach ($directories as $index => $directory) {
                [$key, $name, $icon, $resources] = $directory;
                $id = md5($realm . ':directory:' . $key);
                $nodes[] = ['id' => $id, 'realm' => $realm, 'parent_id' => null, 'kind' => 'directory',
                    'name' => $name, 'path' => '/' . $realm . '/groups/' . $key, 'component' => '', 'permission' => null,
                    'icon' => $icon, 'order_number' => ($index + 1) * 10, 'enabled' => true, 'hidden' => false, 'guarded' => false];
                foreach ($resources as $position => $resource) {
                    $parents[$realm . ':' . $resource] = $id;
                    $orders[$realm . ':' . $resource] = ($position + 1) * 10;
                }
            }
        }
        foreach ($pages as $realm => $entries) {
            foreach ($entries as $order => $entry) {
                [$key, $label, $component, $actions, $guarded] = $entry;
                $module = str_starts_with($key, 'center.') ? 'center' : $realm;
                $resource = str_starts_with($key, 'center.') ? substr($key, 7) : $key;
                $pageId = md5($realm . ':' . $key);
                $base = ['realm' => $realm, 'enabled' => true, 'hidden' => false, 'guarded' => $guarded];
                $nodes[] = $base + ['id' => $pageId, 'parent_id' => $parents[$realm . ':' . $key], 'kind' => 'page', 'name' => $label,
                    'path' => $key === 'defaults' ? '/platform/system-defaults' : '/' . $module . '/' . $resource, 'component' => $component, 'permission' => $module . '.' . $resource . '.read',
                    'icon' => $icons[$resource], 'order_number' => $orders[$realm . ':' . $key]];
                foreach (array_values(array_diff($actions, ['read'])) as $index => $action) {
                    $permission = $module . '.' . $resource . '.' . $action;
                    $nodes[] = $base + ['id' => md5($realm . ':' . $permission), 'parent_id' => $pageId, 'kind' => 'action',
                        'name' => $actionNames[$action], 'path' => '', 'component' => '', 'permission' => $permission, 'icon' => '', 'order_number' => ($index + 1) * 10];
                }
            }
        }
        return $nodes;
    }

    /** 安装事务写入代码已登记目录，不额外开放客户目录修改入口。 */
    public static function initialize(): void
    {
        foreach (self::definitions() as $definition) {
            if ($definition['realm'] === 'platform') {
                PlatformMenu::create($definition);
            } else {
                SystemMenu::create($definition);
            }
        }
    }

    /** 目录停用与祖先停用共同撤销能力；显隐只影响导航。 */
    public static function catalog(string $realm): array
    {
        $catalog = [];
        foreach (self::active($realm) as $row) {
            if ($row['permission'] !== null) {
                $catalog[$row['permission']] = $row['name'];
            }
        }
        return $catalog;
    }

    /** 返回 Vben 使用的嵌套菜单，操作节点不显示为页面。 */
    public static function navigation(string $realm, array $permissions): array
    {
        return self::children(self::active($realm), null, $permissions);
    }

    /** 角色表单以节点标识表达关联，权限字符串由平台目录解析。 */
    public static function permissionsForIds(string $realm, array $ids): array
    {
        if (count($ids) > 512 || count(array_unique($ids)) !== count($ids)) {
            throw new HttpError(422, 'menu_selection_invalid');
        }
        $found = [];
        $permissions = [];
        foreach (self::active($realm) as $row) {
            if (in_array($row['id'], $ids, true)) {
                $found[] = $row['id'];
                if ($row['permission'] !== null) {
                    $permissions[] = $row['permission'];
                }
            }
        }
        if (array_diff($ids, $found) !== []) {
            throw new HttpError(422, 'menu_selection_invalid');
        }
        return array_values(array_unique($permissions));
    }

    public static function idsForPermissions(string $realm, array $permissions): array
    {
        $ids = [];
        foreach (self::active($realm) as $row) {
            if (in_array($row['permission'], $permissions, true)) {
                $ids[] = $row['id'];
            }
        }
        return $ids;
    }

    /** 平台管理目录或系统角色选项；后者只能读取当前可授予范围。 */
    public static function listing(Identity $identity, string $realm, bool $management = false): array
    {
        $actorRealm = (string)($identity->attributes()['realm'] ?? '');
        $tenant = $actorRealm === 'system' ? (string)($identity->attributes()['tenant_id'] ?? '') : '';
        $access = RoleService::readContext($identity, $actorRealm, $tenant, $management ? 'platform.menus.read' : $actorRealm . '.roles.read');
        if (!in_array($realm, ['platform', 'system'], true) || (!$management && $realm !== $actorRealm)) {
            throw new HttpError(403, 'permission_denied');
        }
        $rows = [];
        foreach (self::query($realm)->orderBy('order_number')->orderBy('id')->get() as $menu) {
            $rows[$menu->id] = $menu->toArray();
        }
        $included = [];
        foreach ($rows as $row) {
            if ($management || $row['permission'] === null || in_array($row['permission'], $access['permissions'], true)) {
                $included[$row['id']] = true;
                $parent = $row['parent_id'];
                while ($parent !== null && isset($rows[$parent]) && !isset($included[$parent])) {
                    $included[$parent] = true;
                    $parent = $rows[$parent]['parent_id'];
                }
            }
        }
        $items = [];
        foreach ($rows as $row) {
            if (isset($included[$row['id']])) {
                $items[] = $row;
            }
        }
        return ['items' => $items];
    }

    /** 元数据变更在平台授权事务内完成，不能伪造未实现的组件或能力。 */
    public static function save(Identity $identity, string $realm, string $id, array $data, bool $delete = false): array
    {
        return RoleService::authorized($identity, null, 'platform.menus.manage', static function (Identity $actor, array $permissions) use ($realm, $id, $data, $delete): array {
            if (!in_array($realm, ['platform', 'system'], true)) {
                throw new HttpError(422, 'menu_realm_invalid');
            }
            $menu = $id === '' ? null : self::query($realm)->find($id);
            if ($id !== '' && ($menu === null || $menu->realm !== $realm)) {
                throw new HttpError(404, 'menu_not_found');
            }
            if (($menu === null ? 0 : $menu->version) !== $data['version']) {
                throw new HttpError(409, 'stale_version');
            }
            if ($menu !== null && $menu->guarded && ($delete || !($data['enabled'] ?? true))) {
                throw new HttpError(409, 'menu_protected');
            }
            if ($delete) {
                if (self::query($realm)->where('parent_id', '=', $id)->first() !== null
                    || self::roleQuery($realm)->whereHas('menus', static fn (ModelQuery $menus): ModelQuery => $menus->where('permission', '=', $menu->permission))->exists()) {
                    throw new HttpError(409, 'menu_in_use');
                }
                $menu->delete();
            } else {
                $parent = $data['parent_id'] ?? null;
                $seen = [$id];
                while ($parent !== null) {
                    if (in_array($parent, $seen, true)) {
                        throw new HttpError(422, 'menu_cycle');
                    }
                    $seen[] = $parent;
                    $ancestor = self::query($realm)->find($parent);
                    if ($ancestor === null || $ancestor->realm !== $realm || $ancestor->kind === 'action') {
                        throw new HttpError(422, 'menu_parent_invalid');
                    }
                    $parent = $ancestor->parent_id;
                }
                if ($menu === null) {
                    if (self::query($realm)->count() >= 4096) {
                        throw new HttpError(409, 'menu_limit_reached');
                    }
                    $binding = null;
                    foreach (self::definitions() as $definition) {
                        if ($definition['realm'] === $realm && $definition['permission'] === ($data['permission'] ?? null)) {
                            $binding = $definition;
                            break;
                        }
                    }
                    if (($data['kind'] ?? '') !== 'directory' && ($binding === null || $binding['kind'] !== $data['kind'])) {
                        throw new HttpError(422, 'menu_binding_unregistered');
                    }
                    if ($binding !== null && self::query($realm)->where('permission', '=', $binding['permission'])->first() !== null) {
                        throw new HttpError(409, 'menu_binding_exists');
                    }
                    $values = $binding ?? ['path' => '/' . $realm . '/groups/' . bin2hex(random_bytes(8)), 'component' => '', 'permission' => null, 'guarded' => false];
                    $menu = self::make($realm, array_replace($values, ['id' => bin2hex(random_bytes(16)), 'realm' => $realm,
                        'kind' => $data['kind'], 'parent_id' => $data['parent_id'] ?? null, 'name' => $data['name'],
                        'icon' => $data['icon'], 'order_number' => $data['order_number'], 'enabled' => $data['enabled'], 'hidden' => $data['hidden']]));
                } else {
                    foreach (['parent_id', 'name', 'icon', 'order_number', 'enabled', 'hidden'] as $field) {
                        $menu->set($field, $data[$field]);
                    }
                }
                $menu->save();
                $id = $menu->id;
                $catalog = self::catalog($realm);
                foreach (self::query($realm)->where('guarded', '=', true)->get() as $guarded) {
                    if ($guarded->permission !== null && !isset($catalog[$guarded->permission])) {
                        throw new HttpError(409, 'menu_protected');
                    }
                }
            }
            AuditLog::append(Db::connection('default', true), null, $actor, $delete ? 'menu.deleted' : 'menu.saved', $id, 'success', ['context' => $realm], 'platform');
            return ['id' => $id, 'deleted' => $delete, 'menu' => $delete ? null : $menu->toArray()];
        }, 'platform', 'platform');
    }

    /** 目录按模块存储；共享授权算法不能跨表读取另一身份域。 */
    private static function query(string $realm): ModelQuery
    {
        return match ($realm) {
            'platform' => PlatformMenu::query()->master(),
            'system' => SystemMenu::query()->master(),
            default => throw new HttpError(422, 'menu_realm_invalid'),
        };
    }

    /** 新建时保留具体模型，供 ORM 与 TypePHP 按各自表声明装配。 */
    private static function make(string $realm, array $values): Model
    {
        return $realm === 'platform' ? new PlatformMenu($values) : new SystemMenu($values);
    }

    /** 通过角色模型检查目录引用，避免菜单服务直接读取角色权限中间表。 */
    private static function roleQuery(string $realm): ModelQuery
    {
        return $realm === 'platform' ? PlatformRole::query()->master() : SystemRole::query()->master();
    }

    private static function active(string $realm): array
    {
        if (!in_array($realm, ['platform', 'system'], true)) {
            throw new HttpError(403, 'identity_realm_forbidden');
        }
        $rows = [];
        foreach (self::query($realm)->orderBy('order_number')->orderBy('id')->limit(4096)->get() as $menu) {
            $rows[$menu->id] = $menu->toArray();
        }
        $active = [];
        foreach ($rows as $row) {
            $current = $row;
            $seen = [];
            $enabled = true;
            while ($current !== null) {
                if (!$current['enabled'] || in_array($current['id'], $seen, true)) {
                    $enabled = false;
                    break;
                }
                $seen[] = $current['id'];
                if ($current['parent_id'] === null) {
                    break;
                }
                $current = $rows[$current['parent_id']] ?? null;
                if ($current === null) {
                    $enabled = false;
                }
            }
            if ($enabled) {
                $active[] = $row;
            }
        }
        return $active;
    }

    private static function children(array $rows, ?string $parent, array $permissions): array
    {
        $items = [];
        foreach ($rows as $row) {
            if ($row['parent_id'] !== $parent || $row['kind'] === 'action' || $row['hidden']) {
                continue;
            }
            $children = self::children($rows, $row['id'], $permissions);
            if ($row['permission'] !== null && !in_array($row['permission'], $permissions, true)) {
                continue;
            }
            if ($row['kind'] === 'directory' && $children === []) {
                continue;
            }
            $items[] = ['id' => $row['id'], 'name' => $row['name'], 'path' => $row['path'], 'component' => $row['component'],
                'icon' => $row['icon'], 'order' => $row['order_number'], 'children' => $children] + ($row['kind'] === 'directory' ? ['redirectPath' => $children[0]['path']] : []);
        }
        return $items;
    }
}
