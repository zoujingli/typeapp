<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';
require __DIR__ . '/native-rollout-redis.php';

use Type\Build\BuildPlatform;
use Type\Testing\HttpClient;
use Type\Testing\Process;
use Type\Testing\ProcessResult;

/** 对当前命令做有界调用并保留脱敏退出信息；失败也必须回收子进程。 */
function runtimeCommand(array $command, array $arguments, array $environment, string $directory, string $log): ProcessResult
{
    $process = new Process([...$command, ...$arguments], $directory, $environment, 4194304);
    try {
        $result = $process->wait(45);
    } finally {
        $process->stop(5);
    }
    $secrets = array_values(array_filter($environment, static fn (string $key): bool => str_contains($key, 'PASSWORD'), ARRAY_FILTER_USE_KEY));
    file_put_contents($log, str_replace($secrets, '<REDACTED>', $result->stdout . $result->stderr));
    chmod($log, 0600);
    expect(!$result->timedOut && !$result->outputExceeded && $result->signal === null, '命令未有界退出：' . basename($log));
    return $result;
}

/** 核对所有业务表字节；迁移控制表另由迁移状态断言核对。 */
function runtimeDataIdentity(PDO $database, string $driver, array $columns = []): array
{
    $query = match ($driver) {
        'mysql' => 'SHOW TABLES',
        'pgsql' => "SELECT tablename FROM pg_tables WHERE schemaname = 'public'",
        default => "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'",
    };
    $identity = [];
    foreach ($database->query($query)->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if (str_starts_with($table, 'type_migrations')) {
            continue;
        }
        expect(preg_match('/^[a-z_]+$/D', $table) === 1, '测试数据库含未知表名');
        $rows = $database->query('SELECT ' . ($columns[$table] ?? '*') . ' FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
        $encoded = array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows);
        sort($encoded, SORT_STRING);
        $identity[$table] = ['rows' => count($rows), 'sha256' => hash('sha256', implode("\n", $encoded))];
    }
    ksort($identity);
    return $identity;
}

/** 追加注册资格和应用身份时，仅最高管理员准确增权；普通角色和已有业务数据逐字保留。 */
function runtimeIdentityUpgrade(PDO $database, string $driver, array $before, array $roles, array $permissions): array
{
    $after = runtimeDataIdentity($database, $driver);
    $enrollments = !isset($before['iot_device_enrollments']);
    $applications = !isset($before['iot_applications']);
    $grants = !isset($before['iot_application_grants']);
    $relationships = !isset($before['iot_device_reassignments']);
    $newTables = [...($enrollments ? ['iot_device_enrollments', 'iot_device_enrollment_bindings'] : []), ...($applications ? ['iot_applications'] : []), ...($grants ? ['iot_application_grants'] : []), ...($relationships ? ['iot_device_reassignments'] : [])];
    $excluded = ['admin_roles', 'customer_roles', 'admin_role_permissions', 'customer_role_permissions', 'iot_devices', 'broker_compatibility', 'iot_architecture_upgrade', 'iot_device_upgrade_records', ...$newTables];
    $business = static fn (array $tables): array => array_diff_key($tables, array_fill_keys($excluded, true));
    $comparable = $relationships ? runtimeDataIdentity($database, $driver, ['iot_device_ownerships' => 'id, device_id, tenant_id, started_at, ended_at']) : $after;
    expect($business($comparable) === $business($before), '身份增量修改了已有业务数据');
    if ($relationships) {
        expect(
            (int) $database->query('SELECT COUNT(*) FROM iot_device_ownerships WHERE expires_at IS NOT NULL OR expiry_context IS NOT NULL OR access_started <> 1 OR version <> 1')->fetchColumn() === 0,
            '旧使用关系必须保持无新增期限、通信历史未知及初始版本'
        );
    }
    foreach ($newTables as $table) {
        expect($after[$table]['rows'] === 0, '升级不能自动签发资格、注册设备或创建接入应用');
    }
    foreach (['admin' => ['read', 'issue', 'disable', 'reset'], 'customer' => ['read']] as $realm => $actions) {
        $current = $database->query('SELECT * FROM ' . $realm . '_roles ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $expected = $roles[$realm];
        expect(array_filter($expected, static fn (array $role): bool => (int) $role['protected'] === 0) !== [], '升级夹具必须包含普通角色，不能空验不增权');
        $expectedPermissions = $permissions[$realm];
        foreach ($expected as $index => $role) {
            if ((int) $role['protected'] !== 1) {
                continue;
            }
            $version = (int) $role['version'] + (int) $enrollments + (int) $applications + (int) $grants + (int) $relationships;
            $expected[$index]['version'] = is_string($role['version']) ? (string) $version : $version;
            foreach ($enrollments ? $actions : [] as $action) {
                $expectedPermissions[] = $role['id'] . ':' . $realm . '.enrollments.' . $action;
            }
            foreach ($applications ? ['read', 'create', 'update', 'enable', 'disable', 'keys'] : [] as $action) {
                $expectedPermissions[] = $role['id'] . ':' . $realm . '.applications.' . $action;
            }
            if ($grants) {
                $expectedPermissions[] = $role['id'] . ':' . $realm . '.applications.authorize';
            }
            foreach ($relationships ? ($realm === 'admin' ? ['devices.expiry', 'devices.end', 'reassignments.read', 'reassignments.create', 'reassignments.advance'] : ['reassignments.read', 'reassignments.accept']) : [] as $action) {
                $expectedPermissions[] = $role['id'] . ':' . $realm . '.' . $action;
            }
        }
        expect($current === $expected, '迁移必须只推进受保护角色的版本');
        $actualPermissions = [];
        foreach ($database->query('SELECT role_id, permission FROM ' . $realm . '_role_permissions')->fetchAll(PDO::FETCH_ASSOC) as $binding) {
            $actualPermissions[] = $binding['role_id'] . ':' . $binding['permission'];
        }
        sort($actualPermissions, SORT_STRING);
        $expectedPermissions = array_values(array_unique($expectedPermissions));
        sort($expectedPermissions, SORT_STRING);
        expect($actualPermissions === $expectedPermissions, '迁移没有准确补齐最高管理员或向普通角色增权');
    }
    return $after;
}

/** 只允许设备转换列与兼容门变化；其他业务原件由全表身份比较覆盖。 */
function runtimeDeviceUpgrade(PDO $database, array $devices, array $compatibility): void
{
    $current = $database->query('SELECT * FROM iot_devices ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $records = $database->query('SELECT * FROM iot_device_upgrade_records ORDER BY device_id')->fetchAll(PDO::FETCH_ASSOC);
    $ledger = [];
    foreach ($records as $record) {
        $ledger[$record['device_id']] = $record;
    }
    expect(count($current) === count($devices), '升级不能删除或重建设备');
    foreach ($devices as $index => $device) {
        $actual = $current[$index];
        if ($device['model_version'] === null) {
            expect($actual === $device, '既有原始设备被改写');
            continue;
        }
        $record = $ledger[$device['id']] ?? null;
        expect($record !== null && json_decode($record['source_json'], true, 32, JSON_THROW_ON_ERROR) === $device
            && $record['ownership_id'] === $device['ownership_id'], '转换来源或稳定使用关系丢失');
        $reasons = json_decode($record['reasons_json'], true, 32, JSON_THROW_ON_ERROR);
        $quarantined = $reasons !== [];
        expect($record['decision'] === ($quarantined ? 'quarantined' : 'converted'), '转换决定不匹配原因');
        foreach (['product_id', 'model_version', 'model_switch_id', 'transfer_id'] as $field) {
            expect($actual[$field] === null, '设备仍依赖旧处理链：' . $field);
            $actual[$field] = $device[$field];
        }
        expect((int) $actual['transfer_frozen'] === 0 && (int) $actual['version'] === (int) $device['version'] + 1
            && (int) $actual['updated_at'] >= (int) $device['updated_at'], '设备转换版本或时序错误');
        foreach (['transfer_frozen', 'version', 'updated_at'] as $field) {
            $actual[$field] = $device[$field];
        }
        if ($quarantined) {
            expect((int) $actual['recovery_verified'] === 0 && $actual['lifecycle'] === ($device['lifecycle'] === 'retired' ? 'retired' : 'disabled'), '不明确设备未保持隔离');
            $actual['recovery_verified'] = $device['recovery_verified'];
            $actual['lifecycle'] = $device['lifecycle'];
        }
        expect($actual === $device, '设备转换改写了未授权字段');
    }
    $actual = $database->query('SELECT * FROM broker_compatibility')->fetchAll(PDO::FETCH_ASSOC);
    expect(count($actual) === count($compatibility), '兼容身份数量改变');
    foreach ($actual as $index => $row) {
        expect((int) $row['runtime_epoch'] === \app\broker\service\CompatService::EPOCH
            && (int) $row['min_runtime_epoch'] === \app\broker\service\CompatService::EPOCH, '未关闭旧程序运行门');
        foreach (['runtime_epoch', 'min_runtime_epoch', 'updated_at'] as $field) {
            $row[$field] = $compatibility[$index][$field];
        }
        expect($row === $compatibility[$index], '升级改写了其他兼容或持久存储状态');
    }
}

/** 等待可观测的真实 HTTP 状态；不以固定休眠代替服务就绪。 */
function runtimeProbe(HttpClient $client, Process $server, string $path, int $expected): array
{
    $deadline = microtime(true) + 10;
    $last = null;
    do {
        expect($server->running(), 'HTTP 提前退出');
        $server->stdout();
        try {
            $response = $client->request('GET', $path);
            $last = ['status' => $response->status, 'body' => $response->body];
            if ($response->status === $expected) {
                return $last;
            }
        } catch (RuntimeException) {
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('探针未达到预期：' . $path . ' ' . json_encode($last));
}

/** 用两个管理器及不同用途触发同一真实 Redis 部署额度，验证释放后可继续借用。 */
function runtimeRedisBudget(array $environment): void
{
    \Type\Runtime\CoroutineRuntime::run(static function () use ($environment): void {
        $budget = new \Type\Runtime\DeploymentBudget(2, 1, 0, 1, 0);
        $configuration = new \Type\Redis\RedisConfiguration($environment['TYPE_REDIS_HOST'], (int) $environment['TYPE_REDIS_PORT']);
        $first = new \Type\Redis\RedisManager(['default' => $configuration], [], $budget);
        $second = new \Type\Redis\RedisManager(['default' => $configuration], [], $budget);
        $one = new \Type\Runtime\ExecutionScope();
        $two = new \Type\Runtime\ExecutionScope();
        try {
            $first->connection($one, 'default', \Type\Redis\Purpose::SCRIPT, 0)->script('return 1', []);
            $second->connection($two, 'default', \Type\Redis\Purpose::BLOCKING, 0);
            $rejected = false;
            try {
                $second->connection($two, 'default', \Type\Redis\Purpose::COMMAND, 0);
            } catch (\Type\Runtime\CapacityException) {
                $rejected = true;
            }
            expect($rejected, '不同管理器/用途复制了 Redis 总额度');
            $one->close();
            expect($second->connection($two, 'default', \Type\Redis\Purpose::COMMAND, 0)->command('PING') !== false, '归还后无法重新借用');
        } finally {
            $one->close();
            $two->close();
            $first->close();
            $second->close();
        }
        expect($budget->poolBudget()->statistics()['allocated'] === 0, 'Redis 关闭后仍占用总额度');
    });
}

/** 从真实 HTTP 建立非空业务数据与未完成导出；升级前正常停写，秘密只留在内存。 */
function runtimeBusinessFixture(array $command, array $environment, string $directory, string $password, string $tenant, string $adminPassword, bool $legacy): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect(is_resource($socket), '无法分配夹具服务端口');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $server = new Process([...$command, 'serve'], dirname($directory), array_replace(
        $environment,
        ['APP_PORT' => substr(strrchr($address, ':'), 1), 'APP_ALLOWED_HOSTS' => $address]
    ), 4194304);
    $client = new HttpClient('http://' . $address, 5);
    try {
        runtimeProbe($client, $server, '/readyz', 200);
        $headers = ['Content-Type' => 'application/json'];
        $login = $client->request('POST', '/customer/auth/login', $headers, json_encode(['login' => 'customer', 'password' => $password], JSON_THROW_ON_ERROR));
        expect($login->status === 200, '升级前客户登录失败');
        $token = $login->json()['data']['accessToken'];
        $headers += ['Authorization' => 'Bearer ' . $token, 'X-Tenant-Id' => $tenant];
        $path = '/customer/tenants/' . $tenant;
        $create = static function (string $url, array $body, int $status) use ($client, $headers): array {
            $response = $client->request('POST', $url, $headers, json_encode($body, JSON_THROW_ON_ERROR));
            expect($response->status === $status, '升级前业务夹具失败：' . $url . ' status=' . $response->status);
            return $response->json()['data'];
        };
        $create('/customer/roles', ['name' => '升级保留普通客户角色', 'permissions' => ['identity.read', 'customer.devices.read']], 200);
        $adminLogin = $client->request('POST', '/admin/auth/login', ['Content-Type' => 'application/json'], json_encode(['login' => 'operator', 'password' => $adminPassword], JSON_THROW_ON_ERROR));
        expect($adminLogin->status === 200, '升级前平台登录失败');
        $adminRole = $client->request(
            'POST',
            '/admin/roles',
            ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $adminLogin->json()['data']['accessToken']],
            json_encode(['name' => '升级保留普通平台角色', 'permissions' => ['identity.read', 'admin.devices.read']], JSON_THROW_ON_ERROR)
        );
        expect($adminRole->status === 200, '升级前普通平台角色夹具失败');
        if (!$legacy) {
            $grant = $client->request(
                'POST',
                '/admin/device-authorizations',
                ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $adminLogin->json()['data']['accessToken']],
                json_encode(['id' => bin2hex(random_bytes(16)), 'tenant_id' => $tenant, 'name' => '当前安装设备', 'capacity' => 1, 'register_expires_at' => time() + 3600], JSON_THROW_ON_ERROR)
            );
            expect($grant->status === 201, '当前安装注册资格失败');
            $registered = $create('/public/devices/register', ['id' => bin2hex(random_bytes(16)), 'device_id' => bin2hex(random_bytes(16)), 'tenant_id' => $tenant, 'name' => '升级保留设备', 'code' => $grant->json()['data']['code']], 201);
            return ['token' => $token, 'admin_token' => $adminLogin->json()['data']['accessToken'], 'path' => $path, 'tenant' => $tenant,
                'product' => null, 'device' => $registered['device']['id'], 'export' => null, 'legacy' => false];
        }
        $product = $create($path . '/products', ['name' => '升级保留产品'], 201);
        $models = $path . '/products/' . $product['id'] . '/models';
        $create($models, ['definition' => ['properties' => [
            ['identifier' => 'temperature', 'name' => '温度', 'type' => 'number', 'required' => false, 'unit' => '°C'],
        ], 'events' => [], 'commands' => []]], 201);
        $create($models . '/1/publish', ['version' => 1], 200);
        $device = $create($path . '/devices', ['name' => '升级保留设备', 'product_id' => $product['id'], 'model_version' => 1], 201)['device'];
        $job = $create($path . '/devices/' . $device['id'] . '/exports', ['id' => bin2hex(random_bytes(16)),
            'kind' => 'records', 'from' => time() - 3600, 'to' => time(), 'timezone' => 'Asia/Shanghai', 'sort' => 'sampled_asc'], 202);
        expect($job['status'] === 'queued', '升级前没有留下未完成任务');
        $special = [];
        for ($index = 0; $index < 102; $index++) {
            $extra = $create($path . '/devices', ['name' => '升级批次设备 ' . $index, 'product_id' => $product['id'], 'model_version' => 1], 201)['device'];
            if ($index < 3) {
                $special[] = $extra['id'];
            }
        }
        $create($path . '/devices/' . $special[0] . '/disable', ['version' => 1, 'confirm_device_id' => $special[0]], 202);
        $create($path . '/devices/' . $special[1] . '/revoke', ['version' => 1, 'confirm_device_id' => $special[1]], 202);
        return ['token' => $token, 'admin_token' => $adminLogin->json()['data']['accessToken'], 'path' => $path, 'tenant' => $tenant, 'product' => $product['id'],
            'device' => $device['id'], 'export' => $job['id'], 'legacy' => true, 'enabled_device' => $extra['id'], 'unavailable' => $special];
    } finally {
        $stopped = $server->stop(5);
        file_put_contents($directory . '/before-upgrade-http.log', $stopped->stdout . $stopped->stderr);
        expect($stopped->successful(), '升级前服务未正常停写');
    }
}

$root = dirname(__DIR__);
expect(in_array($argc, [5, 6], true), '用法：php tests/application-runtime.php <原生产物> <MySQL工具根> <PostgreSQL工具根> <redis-server> [已发布旧版目录或带构建身份的基线程序]');
$target = realpath($argv[1]);
expect(is_string($target) && is_file($target . '.build.json'), '需要原生产物及身份');
$built = json_decode(file_get_contents($target . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
putenv('TYPE_NATIVE_PHP_INI=' . $built['runtime-profile']['ini']);
$command = nativeCommand($target);
$base = $root . '/build/application runtime-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建本轮根目录');
$tools = ['mysql' => NativeDatabase::tools('mysql', $argv[2]), 'pgsql' => NativeDatabase::tools('pgsql', $argv[3])];
$environment = array_replace(getenv(), (new BuildPlatform())->environment(getenv('PHP_HOME') ?: '', getenv('PHPX_HOME') ?: ''));
foreach (array_keys($environment) as $key) {
    if (preg_match('/^(APP_|DB_|IOT_|BROKER_|REDIS_)/', $key)) {
        unset($environment[$key]);
    }
}
$report = ['status' => 'running', 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'),
    'binary_sha256' => hash_file('sha256', $target), 'drivers' => []];
$legacyCommands = [];
if ($argc === 6 && is_file($argv[5])) {
    $old = realpath($argv[5]);
    expect(is_file($old . '.build.json'), '基线程序缺少构建身份');
    $legacy = json_decode(file_get_contents($old . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
    expect(hash_file('sha256', $old) === $legacy['sha256'], '基线程序摘要不一致');
    foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
        $legacyCommands[$driver] = nativeCommand($old);
    }
    $report['legacy'] = ['kind' => 'development-candidate', 'sha256' => $legacy['sha256'], 'build_id' => $legacy['build-id'],
        'manifest_sha256' => hash_file('sha256', $old . '.build.json')];
} elseif ($argc === 6) {
    $legacyRoot = realpath($argv[5]);
    expect(is_string($legacyRoot), '旧版目录不存在');
    $legacy = json_decode(file_get_contents($legacyRoot . '/release-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $platform = (PHP_OS_FAMILY === 'Darwin' ? 'macos' : 'linux') . '-' . (in_array(php_uname('m'), ['arm64', 'aarch64'], true) ? 'arm64' : 'x64');
    foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
        $program = $legacy['programs'][$platform . '-' . $driver] ?? null;
        expect(is_array($program) && basename($program['file']) === $program['file'], '旧版清单缺少当前平台的数据库程序');
        $old = $legacyRoot . '/' . $program['file'];
        expect(is_file($old) && hash_file('sha256', $old) === $program['sha256'], '旧版程序与发布清单不一致');
        (new BuildPlatform())->assertArtifact($old);
        $legacyCommands[$driver] = [$old];
        $report['legacy']['programs'][$driver] = ['file' => $program['file'], 'sha256' => $program['sha256']];
    }
    $report['legacy']['version'] = $legacy['version'];
    $report['legacy']['source'] = $legacy['source'];
    $report['legacy']['manifest_sha256'] = hash_file('sha256', $legacyRoot . '/release-manifest.json');
}
$redis = new NativeRolloutRedis($base . '/redis', $argv[4]);
try {
    runtimeRedisBudget($redis->environment());
    $report['redis_budget_php'] = 'shared-managers-purposes-release';
    $redisBaseline = runtimeCommand([PHP_BINARY, $root . '/tests/redis.php'], ['--php'], array_replace($environment, $redis->environment()), $base, $base . '/redis-baseline.log');
    expect($redisBaseline->successful(), 'Redis 既有用途、事务和失效恢复回归失败');
    foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
        $instance = new NativeDatabase($base . '/' . $driver, $driver, $tools[$driver] ?? []);
        $directory = $base . '/' . $driver . '/application with spaces';
        expect(mkdir($directory, 0700), '无法创建应用根');
        $env = array_replace($environment, $instance->environment(), ['APP_BASE_PATH' => $directory, 'APP_DEBUG' => 'false',
            'DB_DRIVER' => $driver, 'DB_SQLITE_FILE' => 'application.sqlite', 'APP_HTTP_THREADS' => '1',
            'APP_SCHEDULER_NAMESPACE' => 'architecture-' . bin2hex(random_bytes(6))]);
        if ($driver !== 'sqlite') {
            foreach (['HOST' => 'HOST', 'PORT' => 'PORT', 'DATABASE' => 'DATABASE', 'USERNAME' => 'USER', 'PASSWORD' => 'PASSWORD'] as $to => $from) {
                $env['DB_' . $to] = $env['TYPE_' . strtoupper($driver) . '_' . $from];
            }
        }
        $redisEnv = $redis->environment();
        $env['APP_SCHEDULER_REDIS_HOST'] = $redisEnv['TYPE_REDIS_HOST'];
        $env['APP_SCHEDULER_REDIS_PORT'] = $redisEnv['TYPE_REDIS_PORT'];
        $commandId = 0;
        $run = static function (array $arguments, array $overrides = []) use ($command, $env, $directory, &$commandId): ProcessResult {
            return runtimeCommand($command, $arguments, array_replace($env, $overrides), dirname($directory), $directory . '/command-' . (++$commandId) . '.log');
        };
        $server = null;
        $scheduler = null;
        $database = null;
        $observer = null;
        $checks = [];
        try {
            file_put_contents($directory . '/.env', "# fixture\n");
            foreach ([['DB_SERVER_BUDGET' => '2'], ['APP_ALLOWED_HOSTS' => 'https://invalid.example/path'],
                ['APP_TRUSTED_PROXIES' => 'invalid-cidr'], ['APP_UPLOAD_TEMP' => $directory . '/missing'],
                ['APP_CACHE_ENABLED' => 'true']] as $invalid) {
                $result = $run(['config:check'], $invalid);
                expect($result->exitCode === 2 && json_decode($result->stdout, true, 64, JSON_THROW_ON_ERROR)['status'] === 'config_invalid', '原生预检未拒绝非法配置');
                expect(file_get_contents($directory . '/.env') === "# fixture\n", '离线检查改写配置');
            }
            expect(!file_exists($directory . '/application.sqlite'), '预检隐式创建 SQLite');
            $checks[] = 'native-offline-preflight';
            $password = bin2hex(random_bytes(20));
            $installed = runtimeCommand(
                $legacyCommands[$driver] ?? $command,
                ['app:install', 'operator', '平台管理员', 'customer', '客户管理员', '测试租户'],
                array_replace($env, ['APP_ADMIN_PASSWORD' => $password, 'APP_CUSTOMER_PASSWORD' => $password . '-customer']),
                dirname($directory),
                $directory . '/install.log'
            );
            expect($installed->successful(), '空库安装失败');
            $checks[] = $legacyCommands === [] ? 'current-installation' : 'fixed-baseline-installation';
            $receipt = json_decode($installed->stdout, true, 64, JSON_THROW_ON_ERROR);
            $fixture = runtimeBusinessFixture($legacyCommands[$driver] ?? $command, $env, $directory, $password . '-customer', $receipt['data']['tenant_id'], $password, $legacyCommands !== []);
            $dsn = $driver === 'sqlite' ? 'sqlite:' . $directory . '/application.sqlite'
                : $driver . ':host=' . $env['DB_HOST'] . ';port=' . $env['DB_PORT'] . ';dbname=' . $env['DB_DATABASE'];
            $database = new PDO($dsn, $env['DB_USERNAME'] ?? '', $env['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if ($fixture['legacy']) {
                // 受控恢复故障数据，不伪造迁移记录；旧公开接口已建立完整设备与凭据。
                $unverified = $database->prepare('UPDATE iot_devices SET recovery_verified = 0 WHERE id = ?');
                $unverified->execute([$fixture['unavailable'][2]]);
                $unverified->closeCursor();
                $enabled = $database->prepare("UPDATE iot_devices SET lifecycle = 'enabled' WHERE id = ?");
                $enabled->execute([$fixture['enabled_device']]);
                $enabled->closeCursor();
                // 旧部署中已持久化但尚未执行的工作样本；不宣称收到过真实设备消息。
                $source = $database->prepare('SELECT * FROM iot_devices WHERE id = ?');
                $source->execute([$fixture['unavailable'][2]]);
                $pendingDevice = $source->fetch(PDO::FETCH_ASSOC);
                $source->closeCursor();
                $insert = static function (string $table, array $fields) use ($database): void {
                    $statement = $database->prepare('INSERT INTO ' . $table . ' (' . implode(', ', array_keys($fields)) . ') VALUES (' . implode(', ', array_fill(0, count($fields), '?')) . ')');
                    $statement->execute(array_values($fields));
                    $statement->closeCursor();
                };
                $id = bin2hex(random_bytes(16));
                $insert('iot_commands', ['id' => $id, 'tenant_id' => $pendingDevice['tenant_id'], 'device_id' => $pendingDevice['id'], 'ownership_id' => $pendingDevice['ownership_id'],
                    'model_version' => 1, 'identifier' => 'legacy_pending', 'payload' => '{}', 'content_hash' => hash('sha256', '{}'), 'actor_id' => $receipt['data']['customer']['id'],
                    'accepted_at' => time(), 'deadline_at' => time() + 3600, 'source_context' => '{}', 'request_version' => 1]);
                $insert('iot_command_uncertainties', ['command_id' => $id, 'device_id' => $pendingDevice['id'], 'ownership_id' => $pendingDevice['ownership_id']]);
                $insert('iot_ingestion', ['message_id' => bin2hex(random_bytes(32)), 'tenant_id' => $pendingDevice['tenant_id'], 'device_id' => $pendingDevice['id'], 'ownership_id' => $pendingDevice['ownership_id'],
                    'sequence' => '1', 'content_hash' => hash('sha256', 'pending'), 'status' => 'accepted', 'code' => 'accepted', 'received_at' => time(), 'receipt_proof_nonce' => bin2hex(random_bytes(16)), 'receipt_requested_at' => time()]);
                $notice = bin2hex(random_bytes(16));
                $insert('iot_notifications', ['id' => $notice, 'tenant_id' => $pendingDevice['tenant_id'], 'alarm_id' => bin2hex(random_bytes(16)), 'kind' => 'trigger', 'payload' => '{}', 'created_at' => time()]);
                $insert('iot_notice_outbox', ['id' => $notice, 'topic' => 'iot.notice', 'version' => 1, 'payload' => '{}', 'context' => '{}', 'fingerprint' => hash('sha256', 'pending-notice'),
                    'state' => 'pending', 'token' => '', 'lease_until' => 0, 'attempts' => 0, 'created_at' => time()]);
            }
            $sourceDevices = $database->query('SELECT * FROM iot_devices ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            $sourceCompatibility = $database->query('SELECT * FROM broker_compatibility')->fetchAll(PDO::FETCH_ASSOC);
            $before = runtimeDataIdentity($database, $driver);
            $registrationPending = !isset($before['iot_registration_attempts']);
            $enrollmentPending = !isset($before['iot_device_enrollments']);
            $applicationPending = !isset($before['iot_applications']);
            $relationshipPending = !isset($before['iot_device_reassignments']);
            $dataPending = !isset($before['iot_architecture_upgrade']);
            $enrollmentRoles = [];
            $enrollmentPermissions = [];
            if ($enrollmentPending || $applicationPending || $relationshipPending || $dataPending) {
                foreach (['admin', 'customer'] as $realm) {
                    $enrollmentRoles[$realm] = $database->query('SELECT * FROM ' . $realm . '_roles ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
                    $enrollmentPermissions[$realm] = [];
                    foreach ($database->query('SELECT role_id, permission FROM ' . $realm . '_role_permissions')->fetchAll(PDO::FETCH_ASSOC) as $binding) {
                        $enrollmentPermissions[$realm][] = $binding['role_id'] . ':' . $binding['permission'];
                    }
                }
            }
            $backup = $directory . '/backup';
            if ($driver === 'sqlite') {
                $database->exec('VACUUM INTO ' . $database->quote($backup));
            } else {
                $dumpCommand = $driver === 'mysql'
                    ? [$tools[$driver]['mysqldump'], '--no-defaults', '--host=' . $env['DB_HOST'], '--port=' . $env['DB_PORT'], '--user=' . $env['DB_USERNAME'], '--single-transaction', '--set-gtid-purged=OFF', $env['DB_DATABASE']]
                    : [$tools[$driver]['pg_dump'], '--host=' . $env['DB_HOST'], '--port=' . $env['DB_PORT'], '--username=' . $env['DB_USERNAME'], $env['DB_DATABASE']];
                $dump = new Process($dumpCommand, $directory, array_replace($env, ['MYSQL_PWD' => $env['DB_PASSWORD'], 'PGPASSWORD' => $env['DB_PASSWORD']]), 16777216);
                try {
                    $dumped = $dump->wait(30);
                    expect($dumped->successful(), '本轮数据库备份失败');
                    file_put_contents($backup, $dumped->stdout);
                } finally {
                    $dump->stop(5);
                }
            }
            chmod($backup, 0600);
            $upgrade = ['app:upgrade', '--offline', '--backup', 'backup', '--sha256', hash_file('sha256', $backup)];
            $preflight = $run(['app:upgrade', '--check']);
            expect($preflight->successful(), '升级只读预检失败');
            $preflightData = json_decode($preflight->stdout, true, 64, JSON_THROW_ON_ERROR);
            expect(($preflightData['data_upgrade']['installation_id'] ?? '') === $receipt['data']['installation_id']
                && ($preflightData['data_upgrade']['legacy_devices'] ?? -1) === ($fixture['legacy'] ? 103 : 0)
                && ($preflightData['data_upgrade']['pending']['exports'] ?? -1) === ($fixture['legacy'] ? 1 : 0), '升级预检缺少真实安装、旧设备或未决导出清单');
            expect(runtimeDataIdentity($database, $driver) === $before, '只读升级预检修改了业务数据');
            if ($fixture['legacy']) {
                foreach (['commands', 'uncertain_commands', 'ingestion', 'notifications', 'exports'] as $kind) {
                    expect($preflightData['data_upgrade']['pending'][$kind] === 1, '预检未准确记录未决工作：' . $kind);
                }
                $nextPage = $run(['app:upgrade', '--check', '--after', $preflightData['data_upgrade']['next_device_cursor']]);
                expect($nextPage->successful(), '只读设备游标不可使用');
                $nextInventory = json_decode($nextPage->stdout, true, 64, JSON_THROW_ON_ERROR)['data_upgrade'];
                expect(count($nextInventory['devices']) === 3 && !$nextInventory['has_more_devices']
                    && array_intersect(array_column($nextInventory['devices'], 'device_id'), array_column($preflightData['data_upgrade']['devices'], 'device_id')) === [], '设备预检分页遗漏或重复');
                expect(runtimeDataIdentity($database, $driver) === $before, '继续预检改写了业务数据');
            }
            $bad = $run(['app:upgrade', '--offline', '--backup', 'backup', '--sha256', str_repeat('0', 64)]);
            expect(!$bad->successful() && str_contains($bad->stderr, 'upgrade_backup_digest_mismatch'), '错误备份摘要未拒绝');
            if ($dataPending) {
                $activeNode = $database->prepare('INSERT INTO broker_resource_runs (slot, node_id, run_id, observed_at, expires_at, connections) VALUES (?, ?, ?, ?, ?, ?)');
                $activeNode->execute([31, 'upgrade-running-fixture', bin2hex(random_bytes(16)), time(), time() + 60, 0]);
                $activeNode->closeCursor();
                try {
                    $activeBefore = runtimeDataIdentity($database, $driver);
                    $activeRefusal = $run($upgrade);
                    expect(!$activeRefusal->successful() && str_contains($activeRefusal->stderr, 'upgrade_writers_active'), '活动 MQTT 观察未阻止离线升级');
                    expect(runtimeDataIdentity($database, $driver) === $activeBefore, '活动写角色拒绝仍改写业务状态');
                } finally {
                    $database->exec("DELETE FROM broker_resource_runs WHERE node_id = 'upgrade-running-fixture'");
                }
                // 让真实 107 的第二条 DDL 失败：MySQL 保留首条，事务 DDL 必须回滚。
                $database->exec('CREATE TABLE iot_device_upgrade_records (fixture_id INTEGER PRIMARY KEY)');
                $ddlFailure = $run($upgrade);
                expect(!$ddlFailure->successful(), '冲突 DDL 未失败');
                $state = $database->query("SELECT state FROM type_migrations WHERE version = '107_iot_raw_device_upgrade'")->fetchColumn();
                expect($state === 'failed', '迁移未记录实际 DDL 失败');
                $history = runtimeDataIdentity($database, $driver);
                expect(isset($history['iot_architecture_upgrade']) === ($driver === 'mysql'), 'DDL 部分提交与真实数据库语义不一致');
                $database->exec('DROP TABLE iot_device_upgrade_records');
                if ($driver === 'mysql') {
                    $database->exec('DROP TABLE iot_architecture_upgrade');
                }
                expect($run(['migrate', 'recover', '107_iot_raw_device_upgrade', 'retry', 'isolated-fixture-partial-ddl-verified-and-removed'])->successful(), '真实失败后不能恢复迁移');
                $checks[] = 'real-ddl-failure-and-explicit-recovery';
            }
            $first = $run($upgrade);
            expect($first->successful(), '升级首批失败：' . $first->stderr);
            $firstData = json_decode($first->stdout, true, 64, JSON_THROW_ON_ERROR);
            if ($fixture['legacy']) {
                expect($preflightData['data_upgrade']['has_more_devices'] && count($preflightData['data_upgrade']['devices']) === 100, '预检未显式分页');
                expect($firstData['status'] === 'upgrade_in_progress' && !$firstData['restart_required']
                    && (int) $database->query('SELECT COUNT(*) FROM iot_device_upgrade_records')->fetchColumn() === 100, '首批未形成可续跑进度');
                $blocked = $run(['serve']);
                expect(!$blocked->successful() && str_contains($blocked->stderr, 'upgrade_data_incomplete'), '转换未完成时开放业务入口');
                $oldRun = runtimeCommand($legacyCommands[$driver], ['serve'], $env, dirname($directory), $directory . '/stale-runtime.log');
                expect(!$oldRun->successful() && str_contains($oldRun->stdout . $oldRun->stderr, 'broker_compat_runtime_stale'), '旧程序未被兼容门拒绝');
                file_put_contents($directory . '/other-backup', 'different backup identity');
                $different = $run(['app:upgrade', '--offline', '--backup', 'other-backup', '--sha256', hash_file('sha256', $directory . '/other-backup')]);
                expect(!$different->successful() && str_contains($different->stderr, 'upgrade_backup_identity_changed'), '续跑接受了不同备份');
                // 真实写入故障验证批次回滚，已提交的首批不能被谎称全部撤销。
                if ($driver === 'mysql') {
                    $database->exec("CREATE TRIGGER upgrade_batch_failure BEFORE INSERT ON iot_device_upgrade_records FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'controlled upgrade batch failure'");
                } elseif ($driver === 'pgsql') {
                    $database->exec("CREATE FUNCTION upgrade_batch_failure() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''controlled upgrade batch failure''; END'");
                    $database->exec('CREATE TRIGGER upgrade_batch_failure BEFORE INSERT ON iot_device_upgrade_records FOR EACH ROW EXECUTE FUNCTION upgrade_batch_failure()');
                } else {
                    $database->exec("CREATE TRIGGER upgrade_batch_failure BEFORE INSERT ON iot_device_upgrade_records BEGIN SELECT RAISE(ABORT, 'controlled upgrade batch failure'); END");
                }
                $batchBefore = runtimeDataIdentity($database, $driver);
                try {
                    expect(!$run($upgrade)->successful(), '批次故障被报告为成功');
                    expect(runtimeDataIdentity($database, $driver) === $batchBefore, '失败批次留下设备或游标部分变更');
                } finally {
                    $database->exec('DROP TRIGGER upgrade_batch_failure' . ($driver === 'pgsql' ? ' ON iot_device_upgrade_records' : ''));
                    if ($driver === 'pgsql') {
                        $database->exec('DROP FUNCTION upgrade_batch_failure()');
                    }
                }
                $resumed = $run($upgrade);
                expect($resumed->successful() && json_decode($resumed->stdout, true, 64, JSON_THROW_ON_ERROR)['status'] === 'upgrade_complete', '中断后续跑失败');
                runtimeDeviceUpgrade($database, $sourceDevices, $sourceCompatibility);
                $checks[] = 'legacy-batch-failure-resume-and-old-runtime-rejection';
            }
            $completeBefore = runtimeDataIdentity($database, $driver);
            expect($run($upgrade)->successful() && runtimeDataIdentity($database, $driver) === $completeBefore, '完成后重复升级不幂等');
            if ($fixture['legacy']) {
                $different = $run(['app:upgrade', '--offline', '--backup', 'other-backup', '--sha256', hash_file('sha256', $directory . '/other-backup')]);
                expect(!$different->successful() && str_contains($different->stderr, 'upgrade_backup_identity_changed')
                    && runtimeDataIdentity($database, $driver) === $completeBefore, '完成后不能用另一个备份冒充原升级来源');
            }
            if ($registrationPending) {
                $attempts = runtimeDataIdentity($database, $driver)['iot_registration_attempts'];
                expect($attempts === ['rows' => 0, 'sha256' => hash('sha256', '')], '升级不能产生注册尝试或丢失限流结构');
                $before['iot_registration_attempts'] = $attempts;
                ksort($before);
                $checks[] = 'registration-increment-preserves-existing-device-references';
            }
            if ($enrollmentPending || $applicationPending || $relationshipPending || $dataPending) {
                $before = runtimeIdentityUpgrade($database, $driver, $before, $enrollmentRoles, $enrollmentPermissions);
            }
            expect(runtimeDataIdentity($database, $driver) === $before, '重复升级修改业务数据');
            expect($run(['web:install', '--force'])->successful(), '升级后内嵌页面安装失败');
            $checks[] = 'backup-gate-and-idempotent-upgrade';

            // 专用故障夹具：构造已知 101 增量缺失，不冒充历史发布版本的模式。
            foreach (['admin', 'customer'] as $realm) {
                $database->exec('ALTER TABLE ' . $realm . '_broker_operations DROP COLUMN recovery_verified');
            }
            $database->exec("DELETE FROM type_migrations WHERE version = '101_app_broker_operation_recovery'");
            expect($run($upgrade)->successful(), '已知增量升级失败');
            expect(runtimeDataIdentity($database, $driver) === $before, '增量升级丢失业务数据');
            $database->exec("UPDATE type_migrations SET state = 'running' WHERE version = '101_app_broker_operation_recovery'");
            $recovery = $run(['app:upgrade', '--check']);
            expect(!$recovery->successful() && str_contains($recovery->stderr, 'upgrade_recovery_required'), '未拒绝未核对的中断迁移');
            expect($run(['migrate', 'history'])->successful(), '迁移历史不可读');
            expect($run(['migrate', 'recover', '101_app_broker_operation_recovery', 'applied', 'fixture-schema-verified'])->successful(), '已核对的迁移不能通过公开入口恢复');
            expect($run(['app:upgrade', '--check'])->successful(), '恢复后不能重新检查升级');
            $checks[] = 'known-increment-and-interrupted-migration-rejection';

            // 有后续迁移后不能单独删除 102 表伪造旧库；增量验收使用上面的固定基线程序建立真实旧结构。
            if ($enrollmentPending) {
                $checks[] = 'enrollment-increment-with-protected-role-only-grants';
            }

            $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
            expect(is_resource($socket), '无法分配测试端口');
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            $httpEnv = array_replace($env, ['APP_PORT' => substr(strrchr($address, ':'), 1), 'APP_ALLOWED_HOSTS' => $address]);
            $server = new Process([...$command, 'serve'], dirname($directory), $httpEnv, 4194304);
            $client = new HttpClient('http://' . $address, 3);
            runtimeProbe($client, $server, '/readyz', 200);
            runtimeProbe($client, $server, '/livez', 200);
            $headers = ['Authorization' => 'Bearer ' . $fixture['token'], 'X-Tenant-Id' => $fixture['tenant']];
            $device = $client->request('GET', $fixture['path'] . '/devices/' . $fixture['device'], $headers);
            expect($device->status === 200 && $device->json()['data']['name'] === '升级保留设备', '旧会话或设备升级后不可用');
            if ($fixture['legacy']) {
                $pending = $database->prepare('SELECT status FROM iot_exports WHERE id = ?');
                $pending->execute([$fixture['export']]);
                expect($pending->fetchColumn() === 'queued', '未完成任务升级后丢失或被擅自执行');
                $pending->closeCursor();
                $projection = $device->json()['data'];
                expect($projection['model_version'] === null && $projection['upgrade']['source'] === 'legacy_installation'
                    && $projection['upgrade']['decision'] === 'converted' && str_contains($projection['topics']['publish'], '/raw/'), '转换后的设备未提供原始接入及来源投影');
                $application = $client->request(
                    'POST',
                    $fixture['path'] . '/applications',
                    $headers + ['Content-Type' => 'application/json'],
                    json_encode(['id' => bin2hex(random_bytes(16)), 'name' => '旧设备新业务系统'], JSON_THROW_ON_ERROR)
                );
                expect($application->status === 201, '旧会话不能创建新应用');
                $applicationId = $application->json()['data']['application']['id'];
                $permission = ['ownership_id' => $projection['ownership_id'], 'version' => 0, 'receive' => true, 'send' => true, 'subscribe' => true];
                $granted = $client->request('POST', $fixture['path'] . '/applications/' . $applicationId . '/devices/' . $fixture['device'], $headers + ['Content-Type' => 'application/json'], json_encode($permission, JSON_THROW_ON_ERROR));
                expect($granted->status === 200, '旧设备不能进入新逐设备授权路径：' . $granted->body);
                foreach ($fixture['unavailable'] as $id) {
                    $isolated = $client->request('GET', $fixture['path'] . '/devices/' . $id, $headers);
                    expect($isolated->status === 200 && $isolated->json()['data']['recovery_verified'] === 0, '未核对旧设备未保持隔离');
                    $refused = $client->request(
                        'POST',
                        $fixture['path'] . '/applications/' . $applicationId . '/devices/' . $id,
                        $headers + ['Content-Type' => 'application/json'],
                        json_encode(array_replace($permission, ['ownership_id' => $isolated->json()['data']['ownership_id']]), JSON_THROW_ON_ERROR)
                    );
                    expect($refused->status === 409, '旧不可用设备自动恢复通信授权');
                }
                $control = $client->request('GET', '/admin/devices/' . $fixture['device'], ['Authorization' => 'Bearer ' . $fixture['admin_token']]);
                expect($control->status === 200 && $control->json()['data']['upgrade']['decision'] === 'converted', '控制端旧会话丢失或无法查询转换来源');
                $checks[] = 'legacy-realms-new-grants-and-quarantine';
            }
            $checks[] = 'business-data-and-pending-export-preserved';
            $untrusted = new HttpClient('http://localhost:' . $httpEnv['APP_PORT'], 3);
            expect($untrusted->request('GET', '/readyz')->status === 400, '探针绕过 Host 白名单');
            $checks[] = 'probes-and-host-policy';
            $database->exec('ALTER TABLE app_site_settings RENAME TO runtime_hidden_site');
            try {
                $failed = $client->request('GET', '/public/site');
                expect($failed->status === 500 && $failed->json()['error'] === 'internal_error', '真实查询故障未产生生产 500');
                $requestId = $failed->json()['request_id'];
                expect(preg_match('/^[a-f0-9]{32}$/D', $requestId) === 1, '500 缺少可关联请求身份');
                $deadline = microtime(true) + 3;
                do {
                    $logged = str_contains($server->stdout(), $requestId);
                    if (!$logged) {
                        usleep(10000);
                    }
                } while (!$logged && microtime(true) < $deadline);
                expect($logged, '生产 500 缺少关联日志');
                $records = [];
                foreach (explode("\n", $server->stdout()) as $line) {
                    $record = json_decode($line, true);
                    if (is_array($record) && str_contains($line, $requestId) && str_contains($line, 'exception_type')) {
                        $records[] = $record;
                    }
                }
                expect(count($records) === 1 && !str_contains(json_encode($records), 'runtime_hidden_site'), '原因记录不唯一或泄漏 SQL');
                expect(str_contains(json_encode($records), $built['build-id'] ?? $built['identity']['id']), '日志缺少真实构建身份');
            } finally {
                $database->exec('ALTER TABLE runtime_hidden_site RENAME TO app_site_settings');
            }
            expect($client->request('GET', '/public/site')->status === 200, '真实 API 故障恢复失败');
            $checks[] = 'production-error-correlation-and-recovery';
            $database->exec('ALTER TABLE broker_compatibility RENAME TO runtime_hidden_compatibility');
            try {
                runtimeProbe($client, $server, '/readyz', 503);
                runtimeProbe($client, $server, '/livez', 200);
            } finally {
                $database->exec('ALTER TABLE runtime_hidden_compatibility RENAME TO broker_compatibility');
            }
            runtimeProbe($client, $server, '/readyz', 200);
            $checks[] = 'readiness-dependency-failure-and-recovery';
            $database->exec("UPDATE type_migrations SET state = 'running' WHERE version = '101_app_broker_operation_recovery'");
            try {
                runtimeProbe($client, $server, '/readyz', 503);
                runtimeProbe($client, $server, '/livez', 200);
            } finally {
                $database->exec("UPDATE type_migrations SET state = 'applied' WHERE version = '101_app_broker_operation_recovery'");
            }
            runtimeProbe($client, $server, '/readyz', 200);
            $checks[] = 'readiness-migration-state';
            expect($server->stop(5)->successful(), 'HTTP 未正常排空');

            $scheduler = new Process([...$command, 'app:schedule', 'work', '100', '60000'], dirname($directory), $env);
            $deadline = microtime(true) + 10;
            do {
                expect($scheduler->running(), '调度器在首轮前退出');
                $tick = str_contains($scheduler->stdout(), "\n");
                if (!$tick) {
                    usleep(10000);
                }
            } while (!$tick && microtime(true) < $deadline);
            expect($tick, '调度器未完成真实首轮任务');
            $started = hrtime(true);
            $stopped = $scheduler->stop(5);
            $milliseconds = (hrtime(true) - $started) / 1e6;
            expect($stopped->successful() && $milliseconds < 3000, '60 秒空闲调度未及时正常退出');
            $history = $run(['app:schedule', 'history']);
            expect($history->successful(), '无法读取调度历史');
            $historyRows = json_decode($history->stdout, true, 64, JSON_THROW_ON_ERROR);
            expect(count($historyRows) === 2 && array_unique(array_column($historyRows, 'state')) === ['succeeded'], '正常完成的维护任务未准确持久记录');
            $checks[] = 'scheduler-idle-signal-and-durable-history';

            // 锁住真实审计表，先从 Redis 观察 running，再在在途 I/O 时停止或撤销租约。
            $observer = new Redis();
            expect($observer->connect($redisEnv['TYPE_REDIS_HOST'], (int) $redisEnv['TYPE_REDIS_PORT'], 1), '无法观察本轮 Redis');
            foreach (['stop', 'lease-loss'] as $fault) {
                $namespace = $env['APP_SCHEDULER_NAMESPACE'] . '-' . $fault;
                $stateRoot = 'type:scheduler:{' . hash('sha256', $namespace . "\0maintenance") . '}';
                if ($driver === 'mysql') {
                    $database->exec('LOCK TABLES admin_audit WRITE');
                } elseif ($driver === 'pgsql') {
                    $database->beginTransaction();
                    $database->exec('LOCK TABLE admin_audit IN ACCESS EXCLUSIVE MODE');
                } else {
                    $database->exec('BEGIN IMMEDIATE');
                }
                try {
                    $scheduler = new Process([...$command, 'app:schedule', 'work', '100', '60000'], dirname($directory), array_replace($env, ['APP_SCHEDULER_NAMESPACE' => $namespace]));
                    $deadline = microtime(true) + 8;
                    do {
                        expect($scheduler->running(), '任务等待数据库期间提前退出');
                        $state = json_decode($observer->get($stateRoot . ':state') ?: '{}', true, 64, JSON_THROW_ON_ERROR);
                        $running = in_array('running', array_column($state['records'] ?? [], 'state'), true);
                        if (!$running) {
                            usleep(1000);
                        }
                    } while (!$running && microtime(true) < $deadline);
                    expect($running, '未观察到真实在途任务');
                    $started = hrtime(true);
                    if ($fault === 'stop') {
                        expect(posix_kill($scheduler->pid(), SIGTERM), '无法发送本轮进程的停止信号');
                    } else {
                        expect($observer->del($stateRoot . ':lock') === 1, '未撤销本轮专用租约');
                    }
                } finally {
                    if ($driver === 'mysql') {
                        $database->exec('UNLOCK TABLES');
                    } elseif ($driver === 'pgsql') {
                        $database->rollBack();
                    } else {
                        $database->exec('ROLLBACK');
                    }
                }
                $finished = $scheduler->wait(8);
                expect(!$finished->timedOut && $finished->signal === null, '在途任务未受控退出');
                file_put_contents($directory . '/scheduler-' . $fault . '.log', $finished->stdout . $finished->stderr);
                if ($fault === 'stop') {
                    expect($finished->successful(), '可完成的在途任务未正常排空');
                    expect((hrtime(true) - $started) / 1e6 < 3000, '在途停机超时');
                    $state = json_decode($observer->get($stateRoot . ':state'), true, 64, JSON_THROW_ON_ERROR);
                    expect(count($state['records']) === 1 && $state['records'][0]['state'] === 'succeeded', '停止后仍执行后续计划或错误记录已完成任务');
                } else {
                    expect(!$finished->successful(), '租约丢失被报为成功');
                    $continued = $run(['app:schedule', 'once'], ['APP_SCHEDULER_NAMESPACE' => $namespace]);
                    expect($continued->exitCode === 70, '接管后没有报告需核对的中断结果');
                    $rows = json_decode($run(['app:schedule', 'history'], ['APP_SCHEDULER_NAMESPACE' => $namespace])->stdout, true, 64, JSON_THROW_ON_ERROR);
                    expect(in_array('interrupted', array_column($rows, 'state'), true), '失锁留下的未知结果未保留为 interrupted');
                }
            }
            $observer->close();
            $observer = null;
            $checks[] = 'scheduler-inflight-stop-and-lease-loss';
            $report['drivers'][$driver] = ['checks' => $checks, 'business_tables' => $before, 'scheduler_stop_ms' => $milliseconds];
        } finally {
            $report['drivers'][$driver]['checks'] = $checks;
            // closeCursor 不释放 PDOStatement 持有的连接；夹具闭包也不能跨到数据库停机之后。
            unset($insert, $unverified, $enabled, $source, $activeNode, $pending);
            foreach (['http' => $server, 'scheduler' => $scheduler] as $role => $process) {
                if ($process !== null) {
                    $result = $process->stop(5);
                    file_put_contents($directory . '/' . $role . '.log', $result->stdout . $result->stderr);
                    expect(!$process->running(), '本轮角色未退出');
                }
            }
            $database = null;
            if ($observer !== null) {
                $observer->close();
            }
            $observer = null;
            $instance->close();
            $report['drivers'][$driver]['database'] = $instance->evidence();
        }
    }
    $report['status'] = 'passed';
} finally {
    $redis->close();
    $report['redis'] = $redis->evidence();
    if ($report['status'] !== 'passed') {
        $report['status'] = 'failed';
    }
    file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
echo '应用启动、诊断、调度与升级三库通过：' . $base . "/verification.json\n";
