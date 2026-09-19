<?php

declare(strict_types=1);

namespace app\common\service;

use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Orm\Connection;
use app\iot\service\TenantService;
use Closure;

/** 应用固定权限目录与作用域角色；每次查询当前事实，不缓存可变授权。 */
final class RoleService
{
    /** @return array<string, string> 只声明已经接入对应后端行为的节点。 */
    public static function catalog(string $realm): array
    {
        if (!in_array($realm, ['admin', 'customer'], true)) {
            throw new \InvalidArgumentException('identity_realm_invalid');
        }
        $catalog = ['identity.read' => '查看个人工作区', $realm . '.audit.read' => '查看操作审计', $realm . '.operations.read' => '查看运行状态',
            $realm . '.broker.read' => '查看Broker资源元数据', $realm . '.broker.write' => '发布Broker接入授权并管理连接'];
        if ($realm === 'admin') {
            $catalog += [
                'admin.users.read' => '查看平台人员', 'admin.users.create' => '创建平台人员',
                'admin.users.update' => '修改平台人员资料', 'admin.users.status' => '启停平台人员',
                'admin.users.password' => '重置平台人员密码', 'admin.users.sessions' => '撤销平台人员会话',
                'admin.roles.read' => '查看平台角色', 'admin.roles.create' => '创建平台角色',
                'admin.roles.copy' => '复制平台角色', 'admin.roles.update' => '修改平台角色资料',
                'admin.roles.status' => '启停平台角色', 'admin.roles.delete' => '删除平台角色',
                'admin.roles.permissions' => '编辑平台角色权限', 'admin.roles.assign' => '分配平台人员角色',
                'admin.tenants.read' => '查看平台租户', 'admin.tenants.create' => '创建租户工作区',
                'admin.tenants.update' => '修改租户资料', 'admin.tenants.status' => '启停租户',
                'admin.tenants.administrators.manage' => '维护客户最高管理员',
                'admin.customers.read' => '查看客户账号', 'admin.customers.create' => '创建客户账号',
                'admin.customers.update' => '修改客户资料', 'admin.customers.status' => '启停客户账号',
                'admin.customers.password' => '重置客户密码', 'admin.customers.sessions' => '撤销客户会话',
                'admin.customers.impersonate' => '模拟客户登录',
                'admin.site.read' => '查看站点设置', 'admin.site.manage' => '维护站点设置',
                'admin.config.read' => '查看系统配置', 'admin.config.manage' => '维护系统配置',
            ];
        } else {
            $catalog += [
                'customer.members.read' => '查看租户成员', 'customer.members.create' => '添加租户成员',
                'customer.members.update' => '修改租户成员资料', 'customer.members.status' => '启停租户成员',
                'customer.members.delete' => '移除租户成员', 'customer.roles.read' => '查看租户角色',
                'customer.roles.assign' => '分配租户成员角色', 'customer.roles.create' => '创建租户角色',
                'customer.roles.copy' => '复制租户角色', 'customer.roles.update' => '修改租户角色资料',
                'customer.roles.status' => '启停租户角色', 'customer.roles.delete' => '删除租户角色',
                'customer.roles.permissions' => '编辑租户角色权限',
                'customer.products.read' => '查看产品与物模型', 'customer.products.manage' => '维护产品与物模型',
            ];
        }
        foreach (['read' => '查看设备资产', 'update' => '修改设备资料', 'enable' => '启用设备', 'disable' => '禁用设备',
            'retire' => '退役设备', 'rotate' => '轮换设备凭据', 'revoke' => '撤销设备凭据'] as $action => $label) {
            $catalog[$realm . '.devices.' . $action] = $label;
        }
        if ($realm === 'customer') {
            $catalog['customer.devices.create'] = '登记设备并生成凭据';
            $catalog['customer.devices.model-switch'] = '请求与重试设备模型切换';
            $catalog['customer.telemetry.read'] = '查看当前遥测与归属历史';
            $catalog['customer.alarms.read'] = '查看告警与规则版本';
            $catalog['customer.alarms.manage'] = '发布与停用告警规则';
            $catalog['customer.alarms.acknowledge'] = '确认告警';
            $catalog['customer.notifications.read'] = '查看站内通知';
            foreach (['read' => '查看本人导出任务', 'create' => '创建与恢复历史导出', 'cancel' => '取消本人导出', 'download' => '下载本人私有导出'] as $action => $label) {
                $catalog['customer.exports.' . $action] = $label;
            }
            foreach (['read' => '查看指令结果', 'create' => '下发设备指令', 'query' => '主动查询设备执行结果', 'cancel' => '取消尚未下发的指令'] as $action => $label) {
                $catalog['customer.commands.' . $action] = $label;
            }
            foreach (['read' => '查看设备转移', 'request' => '发起设备转移', 'accept' => '接受设备转移', 'reject' => '拒绝设备转移',
                'cancel' => '取消未决设备转移', 'retry' => '重发原冻结请求', 'switch' => '推进设备归属切换'] as $action => $label) {
                $catalog['customer.transfers.' . $action] = $label;
            }
        }
        return $catalog;
    }

    /**
     * 安装或已授权的租户创建事务调用；没有 HTTP 或角色标记旁路。
     * 租户角色通过复合外键限定范围，初始最高管理员只靠显式节点授权。
     */
    public static function initializeScope(Connection $connection, string $realm, string $scopeId, string $subjectId): void
    {
        $catalog = self::catalog($realm);
        if ($connection->transactionDepth() < 1 || ($realm === 'admin' && $scopeId !== 'platform')) {
            throw new \LogicException('role_initialization_requires_transaction');
        }
        foreach ($realm === 'admin' ? ['最高管理员'] : ['最高管理员', '操作员', '只读成员'] as $index => $name) {
            $id = bin2hex(random_bytes(16));
            $connection->table($realm . '_roles')->insert(['id' => $id, 'scope_id' => $scopeId, 'name' => $name, 'protected' => $index === 0 ? 1 : 0, 'created_at' => time()]);
            foreach ($catalog as $permission => $label) {
                if ($realm === 'customer' && $index !== 0 && $permission !== 'identity.read') {
                    continue;
                }
                $connection->table($realm . '_role_permissions')->insert(['role_id' => $id, 'permission' => $permission]);
            }
            if ($index === 0) {
                if ($realm === 'admin') {
                    $connection->table('admin_user_roles')->insert(['user_id' => $subjectId, 'role_id' => $id]);
                } else {
                    $connection->table('customer_member_roles')->insert(['tenant_id' => $scopeId, 'member_id' => $subjectId, 'role_id' => $id]);
                }
            }
        }
    }

    /**
     * 管理端只读平台绑定，客户权限只来自指定租户的有效成员及角色。
     * @return list<string> 目录内显式权限的并集；无角色返回空列表。
     */
    public static function permissions(Connection $connection, Identity $identity, string $realm, string $tenantId = ''): array
    {
        $catalog = self::catalog($realm);
        if (!in_array('realm:' . $realm, $identity->roles(), true)) {
            throw new HttpError(403, 'identity_realm_forbidden');
        }
        (new IdentityService($realm))->user($connection, $identity->subject());
        if ($realm === 'admin') {
            if ($tenantId !== '') {
                throw new HttpError(403, 'identity_scope_forbidden');
            }
            $rows = $connection->query("SELECT DISTINCT p.permission FROM admin_user_roles b JOIN admin_roles r ON r.id = b.role_id JOIN admin_role_permissions p ON p.role_id = r.id WHERE b.user_id = ? AND r.scope_id = 'platform' AND r.enabled = 1 AND r.recovery_verified = 1 ORDER BY p.permission", [$identity->subject()]);
        } else {
            $rows = $connection->query('SELECT DISTINCT p.permission FROM customer_members m JOIN iot_tenants t ON t.id = m.tenant_id JOIN customer_member_roles b ON b.member_id = m.id AND b.tenant_id = m.tenant_id JOIN customer_roles r ON r.id = b.role_id AND r.scope_id = m.tenant_id JOIN customer_role_permissions p ON p.role_id = r.id WHERE m.user_id = ? AND m.tenant_id = ? AND m.enabled = 1 AND m.recovery_verified = 1 AND t.enabled = 1 AND r.enabled = 1 AND r.recovery_verified = 1 ORDER BY p.permission', [$identity->subject(), $tenantId]);
        }
        $permissions = [];
        foreach ($rows as $row) {
            $permission = (string) $row['permission'];
            if (isset($catalog[$permission])) {
                $permissions[] = $permission;
            }
        }
        return $permissions;
    }

    /**
     * 双端只读观察绑定准确会话、模拟来源与当前权限；耗时读取后再次调用并比较，不能用游标充当授权。
     * @return array{identity: array<string, mixed>, permissions: list<string>} 同一授权范围的稳定元数据，不包含令牌。
     */
    public static function readContext(Connection $connection, Identity $identity, string $realm, string $tenantId, string $permission): array
    {
        if (!in_array($realm, ['admin', 'customer'], true) || ($realm === 'admin' ? $tenantId !== '' : preg_match('/^[a-f0-9]{32}$/D', $tenantId) !== 1)) {
            throw new HttpError(403, 'identity_scope_forbidden');
        }
        $current = (new IdentityService($realm))->refresh($connection, $identity);
        if ($current === null) {
            throw new HttpError(401, 'unauthenticated');
        }
        $permissions = self::permissions($connection, $current, $realm, $tenantId);
        self::requirePermission($permissions, $realm . '.' . $permission);
        return ['identity' => IdentityService::context($current, $tenantId), 'permissions' => $permissions];
    }

    /**
     * 返回按业务功能分组的固定菜单；父级只是导航目录，不代表额外权限。
     * @return list<array{name: string, path: string, icon: string, order: int, redirectPath?: string, children?: list<array{name: string, path: string, icon: string, order: int}>}>
     */
    public static function menus(string $realm, array $permissions): array
    {
        self::catalog($realm);
        $menus = $realm === 'customer' || in_array('identity.read', $permissions, true)
            ? [['name' => '个人工作区', 'path' => $realm === 'admin' ? '/admin/profile' : '/profile', 'icon' => 'lucide:user', 'order' => 1]] : [];
        if ($realm === 'admin') {
            $identity = [];
            if (in_array('admin.users.read', $permissions, true)) {
                $identity[] = ['name' => '平台人员', 'path' => '/admin/users', 'icon' => 'lucide:users', 'order' => 1];
            }
            if (in_array('admin.roles.read', $permissions, true)) {
                $identity[] = ['name' => '平台角色', 'path' => '/admin/roles', 'icon' => 'lucide:shield', 'order' => 2];
            }
            if (in_array('admin.tenants.read', $permissions, true)) {
                $identity[] = ['name' => '平台租户', 'path' => '/admin/tenants', 'icon' => 'lucide:building', 'order' => 3];
            }
            if (in_array('admin.customers.read', $permissions, true)) {
                $identity[] = ['name' => '客户账号', 'path' => '/admin/customers', 'icon' => 'lucide:contact', 'order' => 4];
            }
            if ($identity !== []) {
                $menus[] = ['name' => '身份与租户', 'path' => '/admin/identity', 'redirectPath' => $identity[0]['path'], 'icon' => 'lucide:users-round', 'order' => 2, 'children' => $identity];
            }
            $system = [];
            if (in_array('admin.site.read', $permissions, true)) {
                $system[] = ['name' => '站点设置', 'path' => '/admin/site', 'icon' => 'lucide:palette', 'order' => 1];
            }
            if (in_array('admin.config.read', $permissions, true)) {
                $system[] = ['name' => '系统配置', 'path' => '/admin/configuration', 'icon' => 'lucide:settings-2', 'order' => 2];
            }
            if ($system !== []) {
                $menus[] = ['name' => '系统管理', 'path' => '/admin/system', 'redirectPath' => $system[0]['path'], 'icon' => 'lucide:settings-2', 'order' => 3, 'children' => $system];
            }
        } else {
            $organization = [['name' => '我的租户', 'path' => '/tenants', 'icon' => 'lucide:building', 'order' => 1]];
            if (in_array('customer.members.read', $permissions, true)) {
                $organization[] = ['name' => '租户成员', 'path' => '/members', 'icon' => 'lucide:users', 'order' => 2];
            }
            if (in_array('customer.roles.read', $permissions, true)) {
                $organization[] = ['name' => '租户角色', 'path' => '/roles', 'icon' => 'lucide:shield', 'order' => 3];
            }
            $menus[] = ['name' => '组织与成员', 'path' => '/organization', 'redirectPath' => $organization[0]['path'], 'icon' => 'lucide:users-round', 'order' => 2, 'children' => $organization];
            $iot = [];
            if (in_array('customer.products.read', $permissions, true)) {
                $iot[] = ['name' => '产品管理', 'path' => '/products', 'icon' => 'lucide:box', 'order' => 1];
            }
            if (in_array('customer.devices.read', $permissions, true)) {
                $iot[] = ['name' => '设备管理', 'path' => '/devices', 'icon' => 'lucide:cpu', 'order' => 2];
            }
            if (in_array('customer.transfers.read', $permissions, true)) {
                $iot[] = ['name' => '设备转移', 'path' => '/transfers', 'icon' => 'lucide:arrow-left-right', 'order' => 3];
            }
            if ($iot !== []) {
                $menus[] = ['name' => '物联管理', 'path' => '/iot', 'redirectPath' => $iot[0]['path'], 'icon' => 'lucide:cpu', 'order' => 4, 'children' => $iot];
            }
            $data = [];
            if (array_intersect(['customer.telemetry.read', 'customer.exports.read', 'customer.exports.create'], $permissions) !== []) {
                $data[] = ['name' => '历史数据', 'path' => '/history', 'icon' => 'lucide:chart-line', 'order' => 1];
            }
            if (in_array('customer.alarms.read', $permissions, true)) {
                $data[] = ['name' => '告警规则', 'path' => '/alarm-rules', 'icon' => 'lucide:sliders', 'order' => 2];
                $data[] = ['name' => '告警中心', 'path' => '/alarms', 'icon' => 'lucide:triangle-alert', 'order' => 3];
            }
            if (in_array('customer.notifications.read', $permissions, true)) {
                $data[] = ['name' => '站内通知', 'path' => '/notifications', 'icon' => 'lucide:bell', 'order' => 4];
            }
            if ($data !== []) {
                $menus[] = ['name' => '数据与告警', 'path' => '/data', 'redirectPath' => $data[0]['path'], 'icon' => 'lucide:chart-line', 'order' => 5, 'children' => $data];
            }
        }
        $operations = [];
        if ($realm === 'admin' && in_array('admin.devices.read', $permissions, true)) {
            $operations[] = ['name' => '设备资产', 'path' => '/admin/devices', 'icon' => 'lucide:cpu', 'order' => 1];
        }
        foreach (['audit' => '操作审计', 'operations' => '运行概览'] as $node => $label) {
            if (in_array($realm . '.' . $node . '.read', $permissions, true)) {
                $operations[] = ['name' => $label, 'path' => ($realm === 'admin' ? '/admin/' : '/') . $node, 'icon' => $node === 'audit' ? 'lucide:scroll-text' : 'lucide:activity', 'order' => count($operations) + 1];
            }
        }
        if (in_array($realm . '.broker.read', $permissions, true) || in_array($realm . '.broker.write', $permissions, true)) {
            if (in_array($realm . '.broker.read', $permissions, true)) {
                $operations[] = ['name' => 'Broker资源', 'path' => $realm === 'admin' ? '/admin/broker' : '/broker-resources', 'icon' => 'lucide:network', 'order' => count($operations) + 1];
                if (in_array($realm . '.audit.read', $permissions, true)) {
                    $operations[] = ['name' => 'Broker审计', 'path' => $realm === 'admin' ? '/admin/broker-audit' : '/broker-audit', 'icon' => 'lucide:scroll-text', 'order' => count($operations) + 1];
                }
            }
            $operations[] = ['name' => 'Broker授权', 'path' => $realm === 'admin' ? '/admin/broker-access' : '/broker-access', 'icon' => 'lucide:key-round', 'order' => count($operations) + 1];
            $operations[] = ['name' => 'Broker配额', 'path' => $realm === 'admin' ? '/admin/broker-quotas' : '/broker-quotas', 'icon' => 'lucide:gauge', 'order' => count($operations) + 1];
            $operations[] = ['name' => 'Broker运行配置', 'path' => $realm === 'admin' ? '/admin/broker-runtime' : '/broker-runtime', 'icon' => 'lucide:sliders-horizontal', 'order' => count($operations) + 1];
            $operations[] = ['name' => 'Broker调试订阅', 'path' => $realm === 'admin' ? '/admin/broker-debug' : '/broker-debug', 'icon' => 'lucide:radio-tower', 'order' => count($operations) + 1];
        }
        if ($operations !== []) {
            $menus[] = ['name' => '运维中心', 'path' => $realm === 'admin' ? '/admin/operations-center' : '/operations-center', 'redirectPath' => $operations[0]['path'], 'icon' => 'lucide:activity', 'order' => $realm === 'admin' ? 4 : 6, 'children' => $operations];
        }
        return $menus;
    }

    /**
     * 平台目录只返回明确投影，分页关系一次批量读取；没有口令或会话散列。
     * @param array{page:int,per_page:int,search:string,enabled:int} $filters enabled=-1 表示全部。
     * @return array<string, mixed> 当前权限与目录、列表或详情。
     */
    public static function directory(Connection $connection, Identity $identity, string $kind, string $id, array $filters, string $realm = 'admin', string $scopeId = 'platform'): array
    {
        if (!in_array($kind, $realm === 'admin' ? ['users', 'roles', 'customers'] : ['roles'], true)) {
            throw new \InvalidArgumentException('admin_resource_invalid');
        }
        $permissions = self::permissions($connection, $identity, $realm, $realm === 'admin' ? '' : $scopeId);
        self::requirePermission($permissions, $realm . '.' . $kind . '.read');
        $query = $connection->table($kind === 'customers' ? 'customer_users' : $realm . '_' . $kind)->select($kind !== 'roles'
            ? ['id', 'login', 'name', 'enabled', 'version', 'recovery_verified', 'created_at']
            : ['id', 'scope_id', 'name', 'enabled', 'protected', 'version', 'recovery_verified', 'created_at']);
        if ($kind === 'roles') {
            $query = $query->where('scope_id', '=', $scopeId);
        }
        if ($id !== '') {
            $query = $query->where('id', '=', $id);
        } else {
            if ($filters['search'] !== '') {
                $query = $query->where($kind === 'roles' ? 'name' : 'login', 'LIKE', '%' . $filters['search'] . '%');
            }
            if ($filters['enabled'] !== -1) {
                $query = $query->where('enabled', '=', $filters['enabled']);
            }
        }
        $page = $query->orderBy('created_at', 'DESC')->orderBy('id')->paginate($id === '' ? $filters['page'] : 1, $id === '' ? $filters['per_page'] : 1);
        $items = $page->items();
        if ($id !== '' && $items === []) {
            throw new HttpError(404, $kind === 'roles' ? 'role_not_found' : 'user_not_found');
        }
        if ($items !== [] && $kind !== 'customers') {
            $ids = array_column($items, 'id');
            $relations = $kind === 'users'
                ? $connection->query('SELECT b.user_id, r.id, r.name, r.enabled, r.protected, r.version FROM admin_user_roles b JOIN admin_roles r ON r.id = b.role_id WHERE b.user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY r.name, r.id', $ids)
                : $connection->table($realm . '_role_permissions')->whereIn('role_id', $ids)->orderBy('permission')->get();
            foreach ($items as $index => $row) {
                $values = [];
                foreach ($relations as $relation) {
                    if ((string) $relation[$kind === 'users' ? 'user_id' : 'role_id'] === (string) $row['id']) {
                        if ($kind === 'users') {
                            unset($relation['user_id']);
                            $values[] = $relation;
                        } else {
                            $values[] = (string) $relation['permission'];
                        }
                    }
                }
                $items[$index][$kind === 'users' ? 'roles' : 'permissions'] = $values;
            }
        }
        return ['items' => $items, 'total' => $page->total(), 'page' => $page->number(), 'per_page' => $page->perPage(),
            'permissions' => $permissions, 'catalog' => self::catalog($realm), 'menus' => self::menus($realm, $permissions)];
    }

    /**
     * 双端授权写入在安装行锁内串行，只锁授权变更而不锁一般读取。
     * 锁后重新认证来源会话和当前权限；账号、角色及批量绑定与审计在同一事务提交。
     * @param array<string, mixed> $data 由固定动作对应的输入声明校验。
     * @return array<string, mixed> 不含秘密的变更回执。
     */
    public static function change(Connection $connection, Identity $identity, string $token, string $action, string $id, array $data, string $realm = 'admin', string $scopeId = 'platform'): array
    {
        self::catalog($realm);
        if (!str_starts_with($action, $realm . '.') || ($realm === 'admin' && $scopeId !== 'platform')) {
            throw new \InvalidArgumentException('authorization_scope_invalid');
        }
        try {
            $requiredPermission = str_starts_with($action, 'admin.tenants.administrators.') ? 'admin.tenants.administrators.manage' : $action;
            return self::authorized($connection, $identity, $token, $requiredPermission, static function (Connection $transaction, Identity $current, array $permissions) use ($action, $id, $data, $realm, $scopeId): array {
                $identities = new IdentityService($realm);
                $result = [];
                if ($action === 'customer.roles.assign') {
                    $result = self::assign($transaction, $current, $permissions, $data, $realm, $scopeId);
                } elseif (str_starts_with($action, 'customer.members.')) {
                    if ($action !== 'customer.members.create') {
                        self::protectSubject($transaction, $id, $permissions, $realm, $scopeId);
                    }
                    $result = (new TenantService())->changeMember($transaction, $current, $scopeId, $action, $id, $data);
                    if ($action === 'customer.members.create') {
                        self::requirePermission($permissions, 'customer.roles.assign');
                        self::assign($transaction, $current, $permissions, ['members' => [['id' => $result['id'], 'version' => 1]], 'roles' => $data['roles']], $realm, $scopeId);
                        $result['version'] = 2;
                    }
                } elseif ($action === 'admin.users.create') {
                    $result = $identities->provision($transaction, $data['login'], $data['name'], $data['password'], false, '', $current);
                } elseif (in_array($action, ['admin.users.update', 'admin.users.status', 'admin.users.password', 'admin.users.sessions'], true)) {
                    self::protectSubject($transaction, $id, $permissions, $realm, $scopeId);
                    $result = $identities->change($transaction, $id, substr($action, 12), $data, $current);
                } elseif ($action === 'admin.roles.assign') {
                    $result = self::assign($transaction, $current, $permissions, $data, $realm, $scopeId);
                } elseif (str_starts_with($action, 'admin.tenants.')) {
                    $result = (new TenantService())->change($transaction, $current, $action, $id, $data);
                } elseif ($action === 'admin.customers.impersonate') {
                    $result = $identities->impersonate($transaction, $current, $id, $data['version']);
                } elseif (str_starts_with($action, 'admin.customers.')) {
                    $customers = new IdentityService('customer');
                    $result = $action === 'admin.customers.create'
                        ? $customers->provision($transaction, $data['login'], $data['name'], $data['password'], false, '', $current, 'admin')
                        : $customers->change($transaction, $id, substr($action, 16), $data, $current, 'admin');
                    if ($action === 'admin.customers.status' && !$data['enabled']) {
                        $scopes = $transaction->query('SELECT m.tenant_id FROM customer_members m JOIN iot_tenants t ON t.id = m.tenant_id WHERE m.user_id = ? AND t.enabled = 1', [$id]);
                        foreach ($scopes as $scope) {
                            self::requireHighest($transaction, 'customer', (string) $scope['tenant_id']);
                        }
                    }
                } else {
                    $result = self::changeRole($transaction, $current, $permissions, $action, $id, $data, $realm, $scopeId);
                }
                self::requireHighest($transaction, $realm, $scopeId);
                return $result;
            }, $realm, $scopeId);
        } catch (HttpError $error) {
            AuditLog::append($connection, $realm === 'admin' ? null : $scopeId, $identity, 'authorization.rejected', $id === '' ? $scopeId : $id, 'denied', ['reason' => $error->errorCode(), 'context' => $action], $realm);
            throw $error;
        }
    }

    /**
     * 授权和业务写入共用既有安装行锁；锁后重验会话、模拟来源和当前权限。
     * @param ?string $token 外部入口复核令牌；null只用于持有可信认证身份的应用服务。
     * @param Closure(Connection, Identity, list<string>): array<string, mixed> $operation 同连接保存业务及审计，异常整体回滚。
     * @throws HttpError 安装、会话或当前授权无效。
     */
    public static function authorized(Connection $connection, Identity $identity, ?string $token, string $permission, Closure $operation, string $realm = 'customer', string $scopeId = ''): array
    {
        self::catalog($realm);
        return $connection->transaction(static function (Connection $transaction) use ($identity, $token, $permission, $operation, $realm, $scopeId): array {
            self::lockAuthorization($transaction);
            $identities = new IdentityService($realm);
            $current = $token === null ? $identities->refresh($transaction, $identity) : $identities->authenticate($transaction, $token);
            if ($current === null || $current->subject() !== $identity->subject()) {
                throw new HttpError(401, 'unauthenticated');
            }
            $permissions = self::permissions($transaction, $current, $realm, $realm === 'admin' ? '' : $scopeId);
            self::requirePermission($permissions, $permission);
            return $operation($transaction, $current, $permissions);
        }, $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default');
    }

    /** 异步领取与人员写入共用授权锁；必须先于业务读取和设备锁，以免MySQL保留撤权前快照。 */
    public static function lockAuthorization(Connection $transaction): void
    {
        if ($transaction->transactionDepth() < 1) {
            throw new \LogicException('authorization_requires_transaction');
        }
        $lock = $transaction->table('app_installation')->where('id', '=', 1);
        $installation = ($transaction->driverName() === 'sqlite' ? $lock : $lock->lockForUpdate())->first();
        if ($installation === null || (int) $installation['schema_version'] !== 1) {
            throw new HttpError(503, 'installation_incomplete');
        }
    }

    /**
     * 调用方持有授权写锁；当前账号、成员与受保护角色都有效且必要节点完整才算管理入口。
     * 客户租户停用不删除管理员，重新启用前仍必须满足此约束。
     */
    public static function requireHighest(Connection $connection, string $realm, string $scopeId): void
    {
        $catalog = self::catalog($realm);
        if ($connection->transactionDepth() < 1) {
            throw new \LogicException('highest_administrator_requires_transaction');
        }
        $rows = $realm === 'admin'
            ? $connection->query("SELECT r.id FROM admin_users u JOIN admin_user_roles b ON b.user_id = u.id JOIN admin_roles r ON r.id = b.role_id WHERE u.enabled = 1 AND u.recovery_verified = 1 AND r.scope_id = 'platform' AND r.protected = 1 AND r.enabled = 1 AND r.recovery_verified = 1 LIMIT 1")
            : $connection->query('SELECT r.id FROM customer_members m JOIN customer_users u ON u.id = m.user_id JOIN customer_member_roles b ON b.member_id = m.id AND b.tenant_id = m.tenant_id JOIN customer_roles r ON r.id = b.role_id AND r.scope_id = m.tenant_id WHERE m.tenant_id = ? AND m.enabled = 1 AND m.recovery_verified = 1 AND u.enabled = 1 AND u.recovery_verified = 1 AND r.enabled = 1 AND r.protected = 1 AND r.recovery_verified = 1 LIMIT 1', [$scopeId]);
        $grants = $rows === [] ? [] : array_column($connection->table($realm . '_role_permissions')->where('role_id', '=', $rows[0]['id'])->get(), 'permission');
        if ($rows === [] || array_diff(array_keys($catalog), $grants) !== []) {
            throw new HttpError(409, $realm === 'admin' ? 'last_platform_admin' : 'last_tenant_admin');
        }
    }

    /** 目标的潜在授权包含停用角色，不能通过启用账号、重置密码或更换绑定接管高权限人员。 */
    private static function protectSubject(Connection $connection, string $id, array $permissions, string $realm, string $scopeId): void
    {
        $query = $connection->table($realm === 'admin' ? 'admin_users' : 'customer_members')->where('id', '=', $id);
        $target = ($realm === 'customer' ? $query->where('tenant_id', '=', $scopeId) : $query)->first();
        if ($target === null) {
            throw new HttpError(404, $realm === 'admin' ? 'user_not_found' : 'member_not_found');
        }
        $grants = $realm === 'admin'
            ? $connection->query('SELECT DISTINCT p.permission FROM admin_user_roles b JOIN admin_role_permissions p ON p.role_id = b.role_id WHERE b.user_id = ?', [$id])
            : $connection->query('SELECT DISTINCT p.permission FROM customer_member_roles b JOIN customer_role_permissions p ON p.role_id = b.role_id WHERE b.member_id = ? AND b.tenant_id = ?', [$id, $scopeId]);
        self::canGrant($permissions, array_column($grants, 'permission'), $realm);
    }

    /** 全部目标和角色都带预期版本；一批至多100人/每人64角色，任何一项失败整体回滚。 */
    private static function assign(Connection $connection, Identity $identity, array $permissions, array $data, string $realm, string $scopeId): array
    {
        $roles = $data['roles'];
        $users = $data[$realm === 'admin' ? 'users' : 'members'];
        $table = $realm === 'admin' ? 'admin_users' : 'customer_members';
        $bindings = $realm === 'admin' ? 'admin_user_roles' : 'customer_member_roles';
        $column = $realm === 'admin' ? 'user_id' : 'member_id';
        if (count(array_unique(array_column($roles, 'id'))) !== count($roles) || count(array_unique(array_column($users, 'id'))) !== count($users)) {
            throw new HttpError(422, 'role_binding_invalid');
        }
        foreach ($roles as $binding) {
            $role = self::role($connection, $binding['id'], $binding['version'], $realm, $scopeId);
            self::canGrant($permissions, $role['permissions'], $realm);
        }
        foreach ($users as $binding) {
            self::protectSubject($connection, $binding['id'], $permissions, $realm, $scopeId);
            $user = $connection->table($table)->where('id', '=', $binding['id'])->first();
            if ((int) $user['version'] !== $binding['version']) {
                throw new HttpError(409, 'stale_version');
            }
            $bindingQuery = $connection->table($bindings)->where($column, '=', $binding['id']);
            if ($realm === 'customer') {
                $bindingQuery = $bindingQuery->where('tenant_id', '=', $scopeId);
            }
            $bindingQuery->delete();
            foreach ($roles as $assigned) {
                $connection->table($bindings)->insert([$column => $binding['id'], 'role_id' => $assigned['id']] + ($realm === 'customer' ? ['tenant_id' => $scopeId] : []));
            }
            $connection->table($table)->where('id', '=', $binding['id'])->update(['version' => $binding['version'] + 1]);
            AuditLog::append($connection, $realm === 'admin' ? null : $scopeId, $identity, 'authorization.assigned', $binding['id'], 'success', ['version' => $binding['version'] + 1, 'content_hash' => hash('sha256', json_encode($roles, JSON_THROW_ON_ERROR)), 'context' => $realm], $realm);
        }
        return ['updated' => count($users)];
    }

    /** 读取包括停用角色的明确权限，修改和复制都使用同一版本判断。 */
    private static function role(Connection $connection, string $id, int $version, string $realm = 'admin', string $scopeId = 'platform'): array
    {
        $role = $connection->table($realm . '_roles')->where('id', '=', $id)->where('scope_id', '=', $scopeId)->first();
        if ($role === null) {
            throw new HttpError(404, 'role_not_found');
        }
        if ((int) $role['version'] !== $version) {
            throw new HttpError(409, 'stale_version');
        }
        if ((int) $role['recovery_verified'] !== 1) {
            throw new HttpError(409, 'recovery_reconciliation_required');
        }
        $role['permissions'] = array_column($connection->table($realm . '_role_permissions')->where('role_id', '=', $id)->orderBy('permission')->get(), 'permission');
        return $role;
    }

    /** 受保护角色可改显示名称或复制，不能停用、删除或收紧固定必要权限。 */
    private static function changeRole(Connection $connection, Identity $identity, array $permissions, string $action, string $id, array $data, string $realm, string $scopeId): array
    {
        $operation = substr($action, strlen($realm . '.roles.'));
        $role = $operation === 'create' ? [] : self::role($connection, $id, $data['version'], $realm, $scopeId);
        if ($role !== []) {
            self::canGrant($permissions, $role['permissions'], $realm);
        }
        if (in_array($operation, ['create', 'copy', 'update'], true)) {
            if (trim($data['name']) === '' || strlen($data['name']) > 100) {
                throw new HttpError(422, 'role_name_invalid');
            }
            $duplicate = $connection->table($realm . '_roles')->where('scope_id', '=', $scopeId)->where('name', '=', trim($data['name']));
            if ($operation === 'update') {
                $duplicate = $duplicate->where('id', '!=', $id);
            }
            if ($duplicate->first() !== null) {
                throw new HttpError(409, 'role_exists');
            }
        }
        $grants = [];
        if (in_array($operation, ['create', 'copy', 'permissions'], true)) {
            self::requirePermission($permissions, $realm . '.roles.permissions');
            $grants = $operation === 'copy' ? $role['permissions'] : $data['permissions'];
            self::canGrant($permissions, $grants, $realm);
        }
        if (in_array($operation, ['create', 'copy'], true)) {
            $id = bin2hex(random_bytes(16));
            $role = ['id' => $id, 'scope_id' => $scopeId, 'name' => trim($data['name']), 'enabled' => 0, 'protected' => 0, 'version' => 1, 'created_at' => time()];
            $connection->table($realm . '_roles')->insert($role);
        } else {
            if ((int) $role['protected'] === 1 && ($operation === 'delete' || ($operation === 'status' && !$data['enabled'])
                || ($operation === 'permissions' && array_diff(array_keys(self::catalog($realm)), $grants) !== []))) {
                throw new HttpError(409, 'protected_role');
            }
            if ($operation === 'delete') {
                // 所有绑定随角色删除；用户版本同步推进，旧表单不能悄悄覆盖已变角色。
                $subjects = $realm === 'admin' ? 'admin_users' : 'customer_members';
                $bindings = $realm === 'admin' ? 'admin_user_roles' : 'customer_member_roles';
                $column = $realm === 'admin' ? 'user_id' : 'member_id';
                $connection->execute('UPDATE ' . $subjects . ' SET version = version + 1 WHERE id IN (SELECT ' . $column . ' FROM ' . $bindings . ' WHERE role_id = ?)', [$id]);
                $connection->table($bindings)->where('role_id', '=', $id)->delete();
                $connection->table($realm . '_role_permissions')->where('role_id', '=', $id)->delete();
                $connection->table($realm . '_roles')->where('id', '=', $id)->delete();
            } else {
                $changes = ['version' => (int) $role['version'] + 1];
                if ($operation === 'update') {
                    $changes['name'] = trim($data['name']);
                } elseif ($operation === 'status') {
                    $changes['enabled'] = $data['enabled'] ? 1 : 0;
                } elseif ($operation !== 'permissions') {
                    throw new \InvalidArgumentException('role_action_invalid');
                }
                $connection->table($realm . '_roles')->where('id', '=', $id)->update($changes);
                $role = array_replace($role, $changes);
            }
        }
        if (in_array($operation, ['create', 'copy', 'permissions'], true)) {
            $connection->table($realm . '_role_permissions')->where('role_id', '=', $id)->delete();
            foreach ($grants as $permission) {
                $connection->table($realm . '_role_permissions')->insert(['role_id' => $id, 'permission' => $permission]);
            }
            $role['permissions'] = $grants;
        }
        AuditLog::append($connection, $realm === 'admin' ? null : $scopeId, $identity, $action, $id, 'success', ['version' => (int) $role['version'], 'content_hash' => hash('sha256', json_encode($role, JSON_THROW_ON_ERROR)), 'context' => $realm], $realm);
        return $operation === 'delete' ? ['deleted' => true, 'id' => $id] : $role;
    }

    /** 节点明确匹配，不支持通配或平台布尔旁路。 */
    private static function requirePermission(array $permissions, string $action): void
    {
        if (!in_array($action, $permissions, true)) {
            throw new HttpError(403, 'permission_denied');
        }
    }

    /** 潜在权限也必须属于当前权限；未知节点与重复节点不能进入角色。 */
    private static function canGrant(array $permissions, array $grants, string $realm = 'admin'): void
    {
        if (count($grants) !== count(array_unique($grants)) || array_diff($grants, array_keys(self::catalog($realm))) !== []) {
            throw new HttpError(422, 'role_permissions_invalid');
        }
        if (array_diff($grants, $permissions) !== []) {
            throw new HttpError(403, 'permission_escalation');
        }
    }
}
