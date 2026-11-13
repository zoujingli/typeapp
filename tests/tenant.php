<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';

use Type\Testing\HttpClient;
use Type\Testing\Process;

$root = dirname(__DIR__);
$driver = $argv[1] ?? 'sqlite';
expect(in_array($driver, ['sqlite', 'mysql', 'pgsql'], true), 'Tenant 验证只接受三种正式数据库驱动');
expect($driver === 'sqlite' ? count($argv) <= 2 : count($argv) === 3, '网络数据库验证须显式提供本轮原生工具根目录');
$work = $root . '/build/tenant-' . bin2hex(random_bytes(6));
expect(mkdir($work, 0700), '无法创建 Tenant 隔离验证目录');
$environment = getenv();
foreach (array_keys($environment) as $key) {
    if (preg_match('/^(APP_|DB_|IOT_|BROKER_|TENANT_)/', $key)) {
        unset($environment[$key]);
    }
}
$environment += ['APP_BASE_PATH' => $work, 'DB_DRIVER' => $driver, 'DB_SQLITE_FILE' => 'tenant.sqlite',
    'APP_ENV' => 'dev', 'APP_DEBUG' => 'true', 'APP_HTTP_THREADS' => '1', 'TENANT_BASE_DOMAIN' => 'tenant.test',
    'APP_TRUSTED_PROXIES' => '127.0.0.1', 'APP_PLATFORM_PASSWORD' => bin2hex(random_bytes(16))];
$command = [PHP_BINARY, $root . '/bin/typeapp'];
$server = null;
$database = null;
try {
    if ($driver !== 'sqlite') {
        $database = new NativeDatabase($work . '/database', $driver, NativeDatabase::tools($driver, $argv[2]));
        $owned = $database->environment();
        $prefix = 'TYPE_' . strtoupper($driver) . '_';
        foreach (['HOST' => 'HOST', 'PORT' => 'PORT', 'DATABASE' => 'DATABASE', 'USER' => 'USERNAME', 'PASSWORD' => 'PASSWORD'] as $source => $target) {
            $environment['DB_' . $target] = $owned[$prefix . $source];
        }
    }
    $install = new Process([...$command, 'app:install', 'platform-root', '平台超管'], $root, $environment);
    try {
        $result = $install->wait(30);
        expect($result->successful(), '全新安装应仅创建平台账号：' . $result->stderr);
        $installed = json_decode($result->stdout, true, 64, JSON_THROW_ON_ERROR)['data'];
        expect(isset($installed['platform']['id']) && !isset($installed['system']), '安装不应预置客户账号');
    } finally {
        $install->stop();
    }
    $schema = $driver === 'sqlite' ? new PDO('sqlite:' . $work . '/tenant.sqlite')
        : new PDO($driver . ':host=' . $environment['DB_HOST'] . ';port=' . $environment['DB_PORT'] . ';dbname=' . $environment['DB_DATABASE'], $environment['DB_USERNAME'], $environment['DB_PASSWORD']);
    $schema->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($driver === 'sqlite') {
        $schema->exec('PRAGMA foreign_keys = ON');
    }
    $tables = $schema->query(match ($driver) {
        'mysql' => "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'",
        'pgsql' => 'SELECT tablename FROM pg_tables WHERE schemaname = current_schema()',
        default => "SELECT name FROM sqlite_schema WHERE type = 'table'",
    })->fetchAll(PDO::FETCH_COLUMN);
    foreach (['platform_installation', 'platform_tenant', 'platform_menu', 'platform_user', 'platform_role', 'platform_session',
        'system_menu', 'system_user', 'system_role', 'system_session', 'system_user_role', 'system_role_permission',
        'center_product', 'center_device', 'platform_mqtt_node', 'platform_mqtt_resource_connection'] as $table) {
        expect(in_array($table, $tables, true), '新安装缺少按模块命名的业务表：' . $table);
    }
    expect(array_filter($tables, static fn (string $table): bool => preg_match('/^(app_|broker_|platform_users$|system_users$|center_devices$)/', $table) === 1) === [], '新安装仍创建旧业务表');
    foreach (['platform', 'system'] as $realm) {
        if ($driver === 'sqlite') {
            $references = array_column($schema->query('PRAGMA foreign_key_list(' . $realm . '_role_permission)')->fetchAll(PDO::FETCH_ASSOC), 'table');
        } else {
            $foreignKeys = $schema->prepare($driver === 'mysql'
                ? 'SELECT referenced_table_name FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND table_name = ? AND referenced_table_name IS NOT NULL'
                : "SELECT referenced.relname FROM pg_constraint constraint_row JOIN pg_class owned ON owned.oid = constraint_row.conrelid JOIN pg_namespace namespace_row ON namespace_row.oid = owned.relnamespace JOIN pg_class referenced ON referenced.oid = constraint_row.confrelid WHERE constraint_row.contype = 'f' AND namespace_row.nspname = current_schema() AND owned.relname = ?");
            $foreignKeys->execute([$realm . '_role_permission']);
            $references = $foreignKeys->fetchAll(PDO::FETCH_COLUMN);
            $foreignKeys = null;
        }
        expect(in_array($realm . '_menu', $references, true), '角色权限未绑定本模块的菜单目录');
    }
    $schema->beginTransaction();
    try {
        $role = $schema->query('SELECT id FROM platform_role LIMIT 1')->fetchColumn();
        $crossCatalogAccepted = true;
        try {
            $statement = $schema->prepare('INSERT INTO platform_role_permission (role_id, permission) VALUES (?, ?)');
            $statement->execute([$role, 'system.site.read']);
        } catch (PDOException $rejected) {
            $crossCatalogAccepted = false;
        }
        expect(!$crossCatalogAccepted, '平台角色不能关联 System 菜单权限');
    } finally {
        $schema->rollBack();
    }
    $statement = null;
    $schema = null;
    $listener = stream_socket_server('tcp://127.0.0.1:0', $number, $error);
    expect(is_resource($listener), '无法分配 HTTP 验证端口');
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $environment['APP_PORT'] = substr(strrchr($address, ':'), 1);
    $environment['APP_ALLOWED_HOSTS'] = $address;
    $environment['APP_PUBLIC_ORIGIN'] = 'http://' . $address;
    $server = new Process([...$command, 'serve'], $root, $environment);
    $client = new HttpClient('http://' . $address);
    $deadline = microtime(true) + 10;
    $ready = false;
    do {
        expect($server->running(), 'HTTP 启动失败：' . $server->stderr());
        try {
            $ready = $client->request('GET', '/readyz')->status === 200;
        } catch (RuntimeException) {
        }
        if (!$ready) {
            usleep(10000);
        }
    } while (!$ready && microtime(true) < $deadline);
    expect($ready, 'HTTP 未就绪');
    $request = static function (string $method, string $path, array $body = [], string $token = '', int $status = 200, array $headers = []) use ($client, &$server): array {
        expect($server->running(), 'HTTP 提前退出：' . $server->stderr());
        $response = $client->request($method, $path, $headers + ['Content-Type' => 'application/json'] + ($token === '' ? [] : ['Authorization' => 'Bearer ' . $token]), $body === [] ? '' : json_encode($body, JSON_THROW_ON_ERROR));
        $payload = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
        expect($response->status === $status, $method . ' ' . $path . ' 状态错误：' . $response->status . '，错误码：' . ($payload['error'] ?? 'none') . ($response->status >= 500 ? '\n' . substr($server->stdout() . $server->stderr(), -5000) : ''));
        return $payload;
    };
    expect($request('GET', '/public/tenants')['items'] === [], '空库不应预置客户');
    $context = $request('GET', '/public/context')['data'];
    expect($context['realm'] === 'system' && !$context['bound'] && $context['tenant'] === null, '主域名应进入未选客户的 System 登录');
    $token = $request('POST', '/platform/auth/login', ['login' => 'platform-root', 'password' => $environment['APP_PLATFORM_PASSWORD']])['data']['accessToken'];
    $me = $request('GET', '/platform/auth/me', [], $token)['data'];
    expect($me['user']['id'] === $installed['platform']['id'] && in_array('platform.tenants.create', $me['permissions'], true), '平台初始 RBAC 无效');
    expect(array_column($me['menus'], 'name') === ['客户与物联', '平台权限', '运维监控', '平台设置'], '平台菜单未按功能分组排序');
    expect($me['menus'][0]['children'][0]['icon'] === 'lucide:building-2' && $me['menus'][1]['icon'] === 'lucide:shield-check', '菜单图标未对应实际功能');
    $nodes = $request('GET', '/platform/broker/nodes', [], $token);
    expect($nodes['items'] === [] && $nodes['total'] === 0, '全新安装的 MQTT 节点观察应为空而非失败');
    $observerPassword = bin2hex(random_bytes(16));
    $observer = $request('POST', '/platform/users', ['login' => 'mqtt-observer', 'name' => 'MQTT 观察员', 'password' => $observerPassword], $token)['data'];
    $observerRole = $request('POST', '/platform/roles', ['name' => 'MQTT 只读', 'menu_ids' => [md5('platform:broker')]], $token)['data'];
    $observerRole = $request('POST', '/platform/roles/' . $observerRole['id'] . '/status', ['version' => $observerRole['version'], 'enabled' => true], $token)['data'];
    $request('PUT', '/platform/roles/assign', ['users' => [['id' => $observer['id'], 'version' => $observer['version']]], 'roles' => [['id' => $observerRole['id'], 'version' => $observerRole['version']]]], $token);
    $observerToken = $request('POST', '/platform/auth/login', ['login' => 'mqtt-observer', 'password' => $observerPassword])['data']['accessToken'];
    $request('GET', '/platform/broker/debug', [], $observerToken);
    $request('POST', '/platform/broker/debug', [], $observerToken, 403);
    $request('POST', '/platform/broker/debug/revoke', [], $observerToken, 403);
    $creation = ['request_id' => bin2hex(random_bytes(16)), 'name' => '客户甲', 'subdomain' => 'alpha', 'login' => 'root-user', 'owner_name' => '客户超管'];
    $first = $request('POST', '/platform/tenants', $creation, $token)['data'];
    expect($first['credential']['user']['login'] === 'root-user' && strlen($first['credential']['password']) >= 12, '开通未建立独立超管');
    $retry = $request('POST', '/platform/tenants', $creation, $token)['data'];
    expect($retry['replayed'] && $retry['credential'] === null && $retry['tenant']['id'] === $first['tenant']['id'], '重试重复开通或交付秘密');
    $second = $request('POST', '/platform/tenants', array_replace($creation, ['request_id' => bin2hex(random_bytes(16)), 'name' => '客户乙', 'subdomain' => 'beta']), $token)['data'];
    $prefix = '/t/' . $first['tenant']['id'];
    $config = $request('GET', $prefix . '/public/context')['data'];
    expect($config['bound'] && $config['tenant']['id'] === $first['tenant']['id'] && $config['site']['name'] === '客户甲', '客户配置未独立');
    $userToken = $request('POST', $prefix . '/system/auth/login', ['login' => 'root-user', 'password' => $first['credential']['password']])['data']['accessToken'];
    $system = $request('GET', $prefix . '/system/auth/me', [], $userToken)['data'];
    expect($system['identity']['tenant_id'] === $first['tenant']['id'] && in_array('center.devices.read', $system['permissions'], true), '系统会话缺少客户归属或 Center 授权');
    expect(array_column($system['menus'], 'name') === ['用户与权限', '物联中心', '系统管理'], '系统菜单未按功能排序');
    expect(array_column($system['menus'][1]['children'], 'path') === ['/center/products', '/center/enrollments', '/center/devices', '/center/applications', '/center/connections', '/center/reassignments'], 'Center 菜单顺序错误');
    $subdomain = ['X-Forwarded-Host' => 'alpha.tenant.test', 'X-Forwarded-Proto' => 'http'];
    $bound = $request('GET', '/public/context', [], '', 200, $subdomain)['data'];
    expect($bound['bound'] && $bound['tenant']['id'] === $first['tenant']['id'] && $bound['site']['name'] === '客户甲', '子域名未固定客户配置');
    $request('GET', '/public/tenants', [], '', 404, $subdomain);
    $request('GET', '/platform/auth/me', [], $token, 404, $subdomain);
    $request('GET', '/t/' . $second['tenant']['id'] . '/public/context', [], '', 403, $subdomain);
    $request('GET', '/public/context', [], '', 400, ['X-Forwarded-Host' => 'unknown.tenant.test', 'X-Forwarded-Proto' => 'http']);
    $request('GET', '/t/' . $second['tenant']['id'] . '/system/auth/me', [], $userToken, 401);
    $request('POST', '/t/' . $second['tenant']['id'] . '/system/auth/login', ['login' => 'root-user', 'password' => $first['credential']['password']], '', 401);
    $request('GET', '/platform/auth/me', [], $userToken, 401);
    $request('GET', '/admin/auth/me', [], $token, 404);
    $roles = $request('GET', $prefix . '/system/roles', [], $userToken)['items'];
    expect(count($roles) === 1 && $roles[0]['protected'], '客户应有一个受保护超管角色');
    $request('POST', $prefix . '/system/users/' . $system['user']['id'] . '/status', ['version' => 1, 'enabled' => false], $userToken, 409);
    $request('POST', $prefix . '/system/roles/' . $roles[0]['id'] . '/status', ['version' => 1, 'enabled' => false], $userToken, 409);
    $ordinaryPassword = bin2hex(random_bytes(16));
    $ordinary = $request('POST', $prefix . '/system/users', ['login' => 'operator', 'name' => '操作员', 'password' => $ordinaryPassword], $userToken)['data'];
    $selected = [md5('system:users'), md5('system:roles'), md5('system:system.users.password'), md5('system:system.users.status'), md5('system:system.roles.create'), md5('system:system.roles.permissions'), md5('system:system.roles.assign')];
    $role = $request('POST', $prefix . '/system/roles', ['name' => '受限运维', 'menu_ids' => $selected], $userToken)['data'];
    expect(!$role['enabled'] && !$role['protected'], '新角色应默认停用且无超管标记');
    $role = $request('POST', $prefix . '/system/roles/' . $role['id'] . '/status', ['version' => $role['version'], 'enabled' => true], $userToken)['data'];
    $request('PUT', $prefix . '/system/roles/assign', ['users' => [['id' => $ordinary['id'], 'version' => 1]], 'roles' => [['id' => $role['id'], 'version' => $role['version']]]], $userToken);
    $ordinaryToken = $request('POST', $prefix . '/system/auth/login', ['login' => 'operator', 'password' => $ordinaryPassword])['data']['accessToken'];
    $request('GET', $prefix . '/system/users', [], $ordinaryToken);
    $request('POST', $prefix . '/system/roles', ['name' => '越权角色', 'menu_ids' => [md5('system:center.devices')]], $ordinaryToken, 403);
    $request('POST', $prefix . '/system/users/' . $system['user']['id'] . '/password', ['version' => 1, 'password' => bin2hex(random_bytes(16))], $ordinaryToken, 403);
    $request('PUT', $prefix . '/system/roles/assign', ['users' => [['id' => $ordinary['id'], 'version' => 2]], 'roles' => [['id' => $roles[0]['id'], 'version' => $roles[0]['version']]]], $ordinaryToken, 403);
    $request('PUT', $prefix . '/system/roles/assign', ['users' => [['id' => $system['user']['id'], 'version' => 1]], 'roles' => []], $userToken, 409);
    $request('PUT', '/t/' . $second['tenant']['id'] . '/system/roles/assign', ['users' => [['id' => $ordinary['id'], 'version' => 2]], 'roles' => []], $request('POST', '/t/' . $second['tenant']['id'] . '/system/auth/login', ['login' => 'root-user', 'password' => $second['credential']['password']])['data']['accessToken'], 404);
    $role = $request('PUT', $prefix . '/system/roles/' . $role['id'] . '/permissions', ['version' => $role['version'], 'menu_ids' => []], $userToken)['data'];
    $request('GET', $prefix . '/system/users', [], $ordinaryToken, 403);
    $directory = $request('GET', '/platform/menus/system', [], $token)['items'];
    $deviceMenu = array_values(array_filter($directory, static fn (array $menu): bool => $menu['permission'] === 'center.devices.read'))[0];
    $menuData = array_intersect_key($deviceMenu, array_flip(['version', 'parent_id', 'name', 'icon', 'order_number', 'enabled', 'hidden']));
    $hidden = $request('PUT', '/platform/menus/system/' . $deviceMenu['id'], array_replace($menuData, ['hidden' => true]), $token)['data']['menu'];
    expect(in_array('center.devices.read', $request('GET', $prefix . '/system/auth/me', [], $userToken)['data']['permissions'], true), '隐藏菜单不能撤销能力');
    $disabled = $request('PUT', '/platform/menus/system/' . $deviceMenu['id'], array_replace($menuData, ['version' => $hidden['version'], 'enabled' => false]), $token)['data']['menu'];
    expect(!in_array('center.devices.read', $request('GET', $prefix . '/system/auth/me', [], $userToken)['data']['permissions'], true), '停用菜单必须撤销能力');
    $request('PUT', '/platform/menus/system/' . $deviceMenu['id'], array_replace($menuData, ['version' => $disabled['version']]), $token);
    $request('PUT', $prefix . '/system/menus', [], $userToken, 405);
    $settings = $request('GET', $prefix . '/system/site', [], $userToken);
    $updated = $request('PUT', $prefix . '/system/site', ['version' => $settings['version'], 'changes' => ['name' => '甲方系统', 'theme_mode' => 'dark']], $userToken);
    expect($request('GET', $prefix . '/public/context')['data']['site']['name'] === '甲方系统', '客户配置保存未生效');
    expect($request('GET', '/t/' . $second['tenant']['id'] . '/public/context')['data']['site']['name'] === '客户乙', '客户品牌串用');
    $handoffInput = ['version' => 1, 'user_id' => $system['user']['id'], 'user_version' => 1, 'entry' => 'path'];
    $handoff = $request('POST', '/platform/tenants/' . $first['tenant']['id'] . '/impersonate', $handoffInput, $token)['data'];
    expect($handoff['target'] === 'http://' . $address . $prefix && $handoff['expiresAt'] <= time() + 60, '交接码未绑定目标或期限');
    $request('POST', '/t/' . $second['tenant']['id'] . '/system/auth/handoff', ['code' => $handoff['code']], '', 401);
    $simulated = $request('POST', $prefix . '/system/auth/handoff', ['code' => $handoff['code']])['data']['accessToken'];
    $simulatedMe = $request('GET', $prefix . '/system/auth/me', [], $simulated)['data'];
    expect($simulatedMe['identity']['actor_id'] === $installed['platform']['id'] && $simulatedMe['identity']['user_id'] === $system['user']['id'] && $simulatedMe['permissions'] === $request('GET', $prefix . '/system/auth/me', [], $userToken)['data']['permissions'], '模拟未使用真实来源或目标权限');
    $request('POST', $prefix . '/system/auth/handoff', ['code' => $handoff['code']], '', 401);
    $request('PATCH', $prefix . '/system/profile', ['version' => 1, 'login' => 'root-user', 'name' => '被模拟', 'current_password' => $first['credential']['password']], $simulated, 403);
    $request('POST', $prefix . '/system/auth/logout', [], $simulated);
    $request('GET', '/platform/auth/me', [], $token);
    $request('GET', $prefix . '/system/auth/me', [], $simulated, 401);
    $sourceToken = $request('POST', '/platform/auth/login', ['login' => 'platform-root', 'password' => $environment['APP_PLATFORM_PASSWORD']])['data']['accessToken'];
    $pending = $request('POST', '/platform/tenants/' . $first['tenant']['id'] . '/impersonate', $handoffInput, $sourceToken)['data'];
    $active = $request('POST', '/platform/tenants/' . $first['tenant']['id'] . '/impersonate', $handoffInput, $sourceToken)['data'];
    $activeToken = $request('POST', $prefix . '/system/auth/handoff', ['code' => $active['code']])['data']['accessToken'];
    $request('POST', '/platform/auth/logout', [], $sourceToken);
    $request('POST', $prefix . '/system/auth/handoff', ['code' => $pending['code']], '', 401);
    $request('GET', $prefix . '/system/auth/me', [], $activeToken, 401);
    $deletable = $request('POST', $prefix . '/system/roles', ['name' => '待删除', 'menu_ids' => []], $userToken)['data'];
    $request('DELETE', $prefix . '/system/roles/' . $deletable['id'], ['version' => $deletable['version']], $userToken);
    $request('GET', $prefix . '/system/roles/' . $deletable['id'], [], $userToken, 404);
    $product = $request('POST', $prefix . '/center/products', ['name' => '温度设备', 'description' => '原始通信分类'], $userToken, 201)['data'];
    expect($product['tenant_id'] === $first['tenant']['id'], '分类未使用入口客户');
    expect($request('GET', $prefix . '/center/products', [], $userToken)['total'] === 1, '分类分页无效');
    $request('GET', '/t/' . $second['tenant']['id'] . '/center/products/' . $product['id'], [], $request('POST', '/t/' . $second['tenant']['id'] . '/system/auth/login', ['login' => 'root-user', 'password' => $second['credential']['password']])['data']['accessToken'], 404);
    $product = $request('PATCH', $prefix . '/center/products/' . $product['id'], ['version' => $product['version'], 'name' => '采集设备'], $userToken)['data'];
    $request('DELETE', $prefix . '/center/products/' . $product['id'], ['version' => $product['version']], $userToken);
    $request('GET', $prefix . '/center/devices', [], $userToken);
    $request('GET', $prefix . '/center/enrollments', [], $userToken);
    $request('GET', $prefix . '/center/reassignments', [], $userToken);
    $request('GET', $prefix . '/system/tenants/' . $first['tenant']['id'] . '/products', [], $userToken, 404);
    $request('GET', $prefix . '/center/devices', [], $userToken, 403, ['X-Tenant-Id' => $second['tenant']['id']]);
    $enrollment = $request('POST', '/platform/enrollments', ['id' => bin2hex(random_bytes(16)), 'tenant_id' => $first['tenant']['id'],
        'name' => '设备接入验证', 'capacity' => 2, 'register_expires_at' => time() + 3600], $token, 201)['data'];
    $registration = $request('POST', '/public/devices/register', ['id' => bin2hex(random_bytes(16)), 'tenant_id' => $first['tenant']['id'],
        'device_id' => bin2hex(random_bytes(16)), 'name' => '原始通信设备', 'code' => $enrollment['code']], '', 201)['data'];
    $application = $request('POST', $prefix . '/center/applications', ['id' => bin2hex(random_bytes(16)), 'name' => '客户业务应用'], $userToken, 201)['data']['application'];
    $applicationDetail = $request('GET', $prefix . '/center/applications/' . $application['id'], [], $userToken)['data']['application'];
    expect($applicationDetail['id'] === $application['id'] && $applicationDetail['tenant_id'] === $first['tenant']['id']
        && !array_key_exists('credential_id', $applicationDetail) && !array_key_exists('request_hash', $applicationDetail), '接入应用模型详情泄漏内部字段或归属错误');
    $enrollmentDetail = $request('GET', $prefix . '/center/enrollments/' . $enrollment['authorization']['id'], [], $userToken)['data'];
    expect($enrollmentDetail['authorization']['tenant_id'] === $first['tenant']['id'] && $enrollmentDetail['bindings']['total'] === 1
        && !array_key_exists('secret_hash', $enrollmentDetail['authorization']), '注册资格模型详情或历史绑定范围错误');
    $grantPath = $prefix . '/center/applications/' . $application['id'] . '/devices/' . $registration['device']['id'];
    $grantInput = ['ownership_id' => $registration['device']['ownership_id'], 'version' => 0, 'receive' => true, 'send' => true, 'subscribe' => true];
    $request('POST', $grantPath, array_replace($grantInput, ['receive' => false]), $userToken, 422);
    $granted = $request('POST', $grantPath, $grantInput, $userToken)['data'];
    expect($granted['grant']['receive'] && $granted['grant']['send'] && $granted['grant']['subscribe'], '原始通信设备授权未保存');
    expect(str_starts_with($granted['grant']['topics']['publish'], 'iot/' . $first['tenant']['id'] . '/devices/'), '设备精确 Topic 协议被改写');
    $request('POST', $grantPath, $grantInput, $userToken, 409);
    $otherToken = $request('POST', '/t/' . $second['tenant']['id'] . '/system/auth/login', ['login' => 'root-user', 'password' => $second['credential']['password']])['data']['accessToken'];
    $request('GET', '/t/' . $second['tenant']['id'] . '/center/applications/' . $application['id'], [], $otherToken, 404);
    $request('GET', '/t/' . $second['tenant']['id'] . '/center/enrollments/' . $enrollment['authorization']['id'], [], $otherToken, 404);
    $request('GET', '/t/' . $second['tenant']['id'] . '/center/applications/' . $application['id'] . '/devices', [], $otherToken, 404);
    $revoked = $request('POST', $grantPath, array_replace($grantInput, ['version' => $granted['grant']['version'], 'receive' => false, 'send' => false, 'subscribe' => false]), $userToken)['data'];
    expect(!$revoked['grant']['receive'] && !$revoked['grant']['send'] && !$revoked['grant']['subscribe'], '通信授权撤销未保存');
    // 观测夹具在授权业务之后运行，防止模拟节点改变后续发布必须取得的真实节点回执。
    $observationEnvironment = $environment + ['IOT_MQTT_LISTEN' => '127.0.0.1', 'IOT_MQTT_PORT' => '18883'];
    $observed = json_decode(successful([PHP_BINARY, __DIR__ . '/fixtures/mqtt-node-observation.php'], $root, $observationEnvironment), true, 32, JSON_THROW_ON_ERROR);
    $nodes = $request('GET', '/platform/broker/nodes', [], $token);
    $byNode = array_column($nodes['items'], null, 'node_id');
    foreach ($observed['records'] as $record) {
        expect(isset($byNode[$record['node_id']]) && $byNode[$record['node_id']]['metrics']['connections'] === $record['connections'], 'MQTT 心跳未进入同一平台节点目录：' . $record['node_id']);
        expect($byNode[$record['node_id']]['state'] === 'stopped', '精确节点停止事实没有同步到平台目录');
        expect(!isset($record['run_id']) || $byNode[$record['node_id']]['run_id'] === $record['run_id'], '迟到旧运行覆盖了平台节点元数据');
    }
    expect(!str_contains(json_encode($nodes, JSON_THROW_ON_ERROR), 'not-a-credential'), '节点元数据透传了未登记字段');
    $pendingRecovery = $request('POST', '/platform/tenants/' . $first['tenant']['id'] . '/impersonate', $handoffInput, $token)['data'];
    $server->stop();
    $server = null;
    $snapshot = json_decode(successful([...$command, 'center:recovery', 'snapshot'], $root, $environment), true, 32, JSON_THROW_ON_ERROR);
    expect($snapshot['version'] === 1 && $snapshot['records'] !== [], '新安装的独立身份无法导出恢复核对清单');
    $recovered = json_decode(successful([PHP_BINARY, __DIR__ . '/fixtures/tenant-recovery.php'], $root, $environment), true, 32, JSON_THROW_ON_ERROR);
    expect($recovered['state'] === 'ready', '统一应用身份恢复核对没有闭环');
    foreach (['broker:install', 'broker:user', 'broker:serve', 'broker:migrate', 'broker:recovery', 'broker:audit-clean', 'app:upgrade'] as $removed) {
        $legacy = new Process([...$command, $removed], $root, $environment);
        try {
            $rejected = $legacy->wait(10);
            expect(!$rejected->successful() && str_contains($rejected->stderr, '未知应用命令：' . $removed), '已退出的独立人员后台或升级入口仍可访问：' . $removed);
        } finally {
            $legacy->stop();
        }
    }
    $server = new Process([...$command, 'serve'], $root, $environment);
    $deadline = microtime(true) + 10;
    do {
        expect($server->running(), '恢复后 HTTP 启动失败：' . $server->stderr());
        try {
            $ready = $client->request('GET', '/readyz')->status === 200;
        } catch (RuntimeException) {
            $ready = false;
        }
        if (!$ready) {
            usleep(10000);
        }
    } while (!$ready && microtime(true) < $deadline);
    expect($ready, '恢复后 HTTP 未就绪');
    $request('GET', '/platform/auth/me', [], $token, 401);
    $request('GET', $prefix . '/system/auth/me', [], $userToken, 401);
    $request('POST', $prefix . '/system/auth/handoff', ['code' => $pendingRecovery['code']], '', 401);
    $request('POST', '/platform/auth/login', ['login' => 'platform-root', 'password' => $environment['APP_PLATFORM_PASSWORD']]);
    $request('POST', $prefix . '/system/auth/login', ['login' => 'root-user', 'password' => $first['credential']['password']]);
    $request('POST', $prefix . '/system/auth/login', ['login' => 'operator', 'password' => $ordinaryPassword], '', 401);
    echo $driver . "：Tenant 空库安装、独立同名账号、入口、RBAC、独立品牌、模拟交接、MQTT 模拟观测、恢复核对及真实 HTTP 隔离通过。\n";
} finally {
    $server?->stop();
    $database?->close();
    removeTestDirectory($work);
}
