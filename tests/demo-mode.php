<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Testing\HttpClient;
use Type\Testing\Process;

/** 对同一公开应用入口检查环境、认证例外和业务表不变；支持 PHP 与最终 SQLite 程序。 */
$root = dirname(__DIR__);
$target = $argv[1] ?? '--php';
$command = $target === '--php' ? [PHP_BINARY, $root . '/bin/typeapp'] : nativeCommand($target);
if (($argv[2] ?? '') === '--pgsql-store') {
    demoStoreChecks($command, $target === '--php' ? null : realpath($target));
    exit(0);
}
$base = $root . '/build/demo-mode-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建演示模式测试目录');
$environment = getenv();
foreach (array_keys($environment) as $key) {
    if (str_starts_with($key, 'APP_') || str_starts_with($key, 'DB_') || str_starts_with($key, 'BROKER_') || str_starts_with($key, 'IOT_')) {
        unset($environment[$key]);
    }
}
$password = 'admin';
$environment += ['APP_BASE_PATH' => $base, 'APP_ENV' => 'prod', 'APP_DEBUG' => 'true', 'APP_CACHE_ENABLED' => 'false',
    'APP_ADMIN_PASSWORD' => $password, 'APP_CUSTOMER_PASSWORD' => $password, 'APP_HTTP_THREADS' => '1',
    'DB_DRIVER' => 'sqlite', 'DB_SQLITE_FILE' => 'demo.sqlite'];
$server = null;
$database = null;
try {
    $install = new Process([...$command, 'app:install', 'admin', '演示管理员', 'admin', '演示客户', '演示租户'], $root, $environment);
    try {
        $result = $install->wait(45);
        expect($result->successful(), '演示测试初始化失败：' . $result->stderr);
    } finally {
        $install->stop();
    }
    $defaults = $environment;
    unset($defaults['APP_ENV']);
    $check = new Process([...$command, 'check'], $root, $defaults);
    try {
        $result = $check->wait(15);
        expect($result->successful() && str_contains($result->stdout, '运行模式：prod') && str_contains($result->stdout, '调试：off'), '缺省环境不是 prod 或意外开启了调试');
    } finally {
        $check->stop();
    }
    foreach (['dev', 'prod', 'demo', 'development', 'production', 'unknown'] as $mode) {
        $check = new Process([...$command, 'check'], $root, array_replace($environment, ['APP_ENV' => $mode]));
        try {
            $result = $check->wait(15);
            if (in_array($mode, ['dev', 'prod', 'demo'], true)) {
                expect($result->successful() && str_contains($result->stdout, '运行模式：' . $mode), '环境未按显式配置生效');
                expect(str_contains($result->stdout, '调试：' . ($mode === 'dev' && $target === '--php' ? 'on' : 'off')), '调试权限越界');
            } else {
                expect(!$result->successful() && str_contains($result->stderr, 'APP_ENV 只接受'), '旧值或未知环境未被拒绝');
            }
        } finally {
            $check->stop();
        }
    }
    $environment['APP_ENV'] = 'demo';
    $database = new PDO('sqlite:' . $base . '/demo.sqlite');
    $tables = $database->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $snapshot = static function () use ($database, $tables): array {
        $records = [];
        foreach ($tables as $table) {
            if (in_array($table, ['admin_sessions', 'customer_sessions', 'admin_audit', 'customer_audit'], true)) {
                continue;
            }
            $rows = $database->query('SELECT * FROM "' . $table . '"')->fetchAll(PDO::FETCH_ASSOC);
            $encoded = array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows);
            sort($encoded, SORT_STRING);
            $records[$table] = hash('sha256', implode("\n", $encoded));
        }
        return $records;
    };
    $before = $snapshot();
    // 写命令在准备业务依赖和校验操作参数前拒绝；包含恢复、配置与普通进程旁路。
    foreach ([['iot:mqtt'], ['broker:run'], ['iot:mqtt-access'], ['iot:mqtt-install'], ['app:install'], ['app:upgrade'],
        ['migrate', 'up'], ['app:schedule', 'once'], ['web:install'], ['web:install', '--force'],
        ['config:restore'], ['config:check', '--remember'], ['app:audit-clean', 'admin'],
        ['iot:recovery', 'begin'], ['iot:backup', 'clean'], ['iot:wal', 'restore'], ['broker:user']] as $arguments) {
        $process = new Process([...$command, ...$arguments], $root, $environment);
        try {
            $result = $process->wait(15);
            expect(!$result->successful() && str_contains($result->stderr, 'demo_readonly'), '演示命令未在副作用前拒绝：' . implode(' ', $arguments));
            expect($snapshot() === $before, '被拒绝的演示命令改变业务表');
        } finally {
            $process->stop();
        }
    }
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect(is_resource($listener), '无法选择测试端口');
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $environment['APP_PORT'] = substr(strrchr($address, ':'), 1);
    $environment['APP_ALLOWED_HOSTS'] = $address;
    $server = new Process([...$command, 'serve'], dirname($base), $environment);
    $client = new HttpClient('http://' . $address);
    $deadline = microtime(true) + 15;
    do {
        expect($server->running(), '演示服务提前退出：' . $server->stderr());
        try {
            $ready = $client->request('GET', '/public/site');
            if ($ready->status === 200) {
                break;
            }
        } catch (RuntimeException) {
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    expect(isset($ready) && $ready->status === 200 && $ready->json()['data']['environment'] === 'demo', '公开环境未正确返回');
    foreach (['admin', 'customer'] as $realm) {
        $login = $client->request('POST', '/' . $realm . '/auth/login', ['Content-Type' => 'application/json'], json_encode(['login' => 'admin', 'password' => $password], JSON_THROW_ON_ERROR));
        expect($login->status === 200, '演示登录失败：' . $realm);
        $headers = ['Authorization' => 'Bearer ' . $login->json()['data']['accessToken'], 'Content-Type' => 'application/json'];
        expect($client->request('GET', '/' . $realm . '/auth/me', $headers)->status === 200, '演示身份查询失败');
        $tenant = (string) $database->query('SELECT id FROM iot_tenants LIMIT 1')->fetchColumn();
        $product = str_repeat('a', 32);
        $paths = $realm === 'admin'
            ? [['PUT', '/admin/site'], ['PUT', '/admin/configuration'], ['POST', '/admin/users'], ['PATCH', '/admin/users/' . $product], ['DELETE', '/admin/users/' . $product . '/sessions'],
                ['POST', '/admin/device-authorizations'], ['POST', '/admin/device-authorizations/' . $product . '/reset'], ['POST', '/admin/device-authorizations/' . $product . '/disable'], ['POST', '/public/devices/register']]
            : [['POST', '/customer/tenants/' . $tenant . '/products'], ['PATCH', '/customer/tenants/' . $tenant . '/products/' . $product], ['DELETE', '/customer/tenants/' . $tenant . '/products/' . $product], ['POST', '/customer/account/password']];
        foreach ($paths as [$method, $path]) {
            $response = $client->request($method, $path, $headers, '{}');
            expect($response->status === 403 && $response->json()['error'] === 'demo_readonly', '演示写接口未拒绝：' . $method . ' ' . $path);
        }
        $applications = $realm === 'admin' ? '/admin/applications' : '/customer/tenants/' . $tenant . '/applications';
        foreach (['', '/' . $product . '/update', '/' . $product . '/enable', '/' . $product . '/disable', '/' . $product . '/rotate', '/' . $product . '/revoke', '/' . $product . '/issue', '/' . $product . '/devices/' . $product] as $suffix) {
            $response = $client->request('POST', $applications . $suffix, $headers, '{}');
            expect($response->status === 403 && $response->json()['error'] === 'demo_readonly', '演示模式不能创建应用或修改密钥');
        }
        $relations = $realm === 'admin' ? ['/admin/devices/' . $product . '/usage/expiry', '/admin/devices/' . $product . '/usage/end',
            '/admin/devices/' . $product . '/usage/reassign', '/admin/reassignments/' . $product] : ['/customer/tenants/' . $tenant . '/reassignments/' . $product];
        foreach ($relations as $path) {
            $response = $client->request('POST', $path, $headers, '{}');
            expect($response->status === 403 && $response->json()['error'] === 'demo_readonly', '演示模式不能结束、转配或接收设备');
        }
        expect($client->request('GET', '/' . $realm . '/auth/me', $headers)->status === 200, '拒绝写入改变了登录状态');
        expect($client->request('POST', '/' . $realm . '/auth/logout', $headers, '{}')->status === 200, '退出登录被错误拒绝');
        expect($client->request('GET', '/' . $realm . '/auth/me', $headers)->status === 401, '退出后旧令牌仍可使用');
    }
    expect($snapshot() === $before, '演示浏览或拒绝操作修改了业务表');
    expect($server->stop(5)->successful(), '演示服务没有正常停止');
    echo "三种环境、调试限制、旧值拒绝、两端普通账号登录退出、HTTP 只读及业务表不变通过。\n";
} finally {
    $server?->stop(5);
    $database = null;
    unset($snapshot);
    removeTestDirectory($base);
}

/** 真实同步 PostgreSQL 检查内部管道只读、过期原件保持和精确清理，不借助 HTTP 拦截。 */
function demoStoreChecks(array $command, ?string $artifact): void
{
    require_once __DIR__ . '/native-database.php';
    require_once __DIR__ . '/postgres-sync.php';
    $root = dirname(__DIR__);
    $base = $root . '/build/demo-store-' . bin2hex(random_bytes(6));
    expect(mkdir($base, 0700), '无法创建 Demo 存储验收目录');
    $tools = NativeDatabase::tools('pgsql', (string) getenv('TYPE_PGSQL_TOOLS'));
    $database = new NativeDatabase($base . '/primary', 'pgsql', $tools);
    $sync = null;
    $inspection = null;
    $victim = null;
    $requestFile = $base . '/request.json';
    $report = ['status' => 'running', 'native' => $artifact !== null, 'binary_sha256' => $artifact === null ? null : hash_file('sha256', $artifact), 'checks' => []];
    try {
        $sync = new PostgresSync($database, $base . '/standby', $tools);
        $parameters = $database->environment();
        $environment = getenv();
        foreach (array_keys($environment) as $key) {
            if (str_starts_with($key, 'APP_') || str_starts_with($key, 'DB_') || str_starts_with($key, 'IOT_') || str_starts_with($key, 'BROKER_')) {
                unset($environment[$key]);
            }
        }
        $environment += ['APP_BASE_PATH' => $base, 'APP_ENV' => 'demo', 'DB_DRIVER' => 'pgsql'];
        foreach (['HOST' => 'HOST', 'PORT' => 'PORT', 'DATABASE' => 'DATABASE', 'USERNAME' => 'USER', 'PASSWORD' => 'PASSWORD'] as $destination => $source) {
            $environment['DB_' . $destination] = $parameters['TYPE_PGSQL_' . $source];
        }
        $call = static function (array $request, string $mode = 'demo') use ($command, $environment, $root, $requestFile): array {
            $request += ['operation_id' => bin2hex(random_bytes(16))];
            file_put_contents($requestFile, json_encode($request, JSON_THROW_ON_ERROR) . "\n");
            chmod($requestFile, 0600);
            $token = bin2hex(random_bytes(32));
            $process = new Process(
                [...$command, 'iot:mqtt-store', '--store-worker-pipe'],
                $root,
                array_replace($environment, ['APP_ENV' => $mode, 'MQTT_WORKER_TOKEN' => $token]),
                stdinFile: $requestFile
            );
            try {
                $result = $process->wait(15);
                expect($result->successful(), 'Demo 存储 worker 未正常退出');
                $lines = explode("\n", trim($result->stdout));
                expect(count($lines) === 2 && hash_equals($token, $lines[0]), '存储管道响应身份无效');
                $data = json_decode($lines[1], true, 32, JSON_THROW_ON_ERROR);
                expect($data['operation_id'] === $request['operation_id'] && $data['released'] === true, '存储工作没有归还资源');
                return $data;
            } finally {
                $process->stop();
                unlink($requestFile);
            }
        };
        expect($call(['action' => 'install'], 'prod')['state'] === 'committed', '生产维护不能安装真实协议表');
        $session = ['session_id' => bin2hex(random_bytes(16)), 'owner_id' => bin2hex(random_bytes(16)),
            'client_id' => 'demo-store-device', 'node_id' => 'demo-store', 'principal' => 'demo-store',
            'protocol' => 5, 'expiry' => 86400, 'clean_start' => false];
        expect($call(['action' => 'session_open'] + $session, 'prod')['state'] === 'committed', '生产维护不能建立协议会话');
        expect($call(['action' => 'session_close', 'cause' => 'normal'] + $session, 'prod')['state'] === 'committed', '生产维护不能关闭协议会话');
        $inspection = $sync->connection();
        $inspection->prepare("UPDATE type_mqtt_sessions SET expires_at = clock_timestamp() - interval '1 day' WHERE id = ?")->execute([$session['session_id']]);
        $tables = $inspection->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'type_mqtt_%' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
        $snapshot = static function () use ($inspection, $tables): array {
            $hashes = [];
            foreach ($tables as $table) {
                $rows = $inspection->query('SELECT row_to_json(t)::text FROM "' . $table . '" t')->fetchAll(PDO::FETCH_COLUMN);
                sort($rows, SORT_STRING);
                $hashes[$table] = hash('sha256', implode("\n", $rows));
            }
            return $hashes;
        };
        $before = $snapshot();
        foreach (['install', 'session_open', 'session_save', 'session_close', 'session_terminate', 'session_recover',
            'node_open', 'node_fence', 'quota_apply', 'retain_clear', 'accept', 'complete', 'shared_drop', 'will_accept'] as $action) {
            $result = $call(['action' => $action] + $session);
            expect($result['state'] === 'rejected' && $result['reason'] === 0x87
                && ($result['value']['error'] ?? '') === 'demo_readonly', '内部写动作未按 Demo 稳定错误拒绝：' . $action);
        }
        $authorization = ['all_metadata' => true];
        foreach ([
            ['action' => 'resource_list', 'resource' => 'sessions', 'authorization' => $authorization],
            ['action' => 'resource_detail', 'resource' => 'sessions', 'id' => $session['session_id'], 'authorization' => $authorization],
            ['action' => 'node_statistics'], ['action' => 'session_statistics'],
        ] as $request) {
            expect($call($request)['state'] === 'committed', 'Demo 合法存储读取失败：' . $request['action']);
        }
        expect($snapshot() === $before, 'Demo 查询或拒绝写入改变了协议原件，包括过期会话');
        $report['checks'][] = 'readonly-worker-preserves-expired-session-and-all-protocol-tables';
        // 以真实独立后端证明 cleanup 仅终止同一操作身份，保留检查连接及全部数据。
        $operation = bin2hex(random_bytes(16));
        $victim = $sync->connection();
        $victim->prepare("SELECT set_config('application_name', ?, false)")->execute(['type_mqtt_' . $operation]);
        $victimPid = (int) $victim->query('SELECT pg_backend_pid()')->fetchColumn();
        $cleanup = $call(['action' => 'cleanup', 'operation_id' => $operation]);
        expect($cleanup['state'] === 'unknown' && $cleanup['released'], 'Demo 未保留精确清理证明');
        expect((int) $inspection->query('SELECT COUNT(*) FROM pg_stat_activity WHERE pid = ' . $victimPid)->fetchColumn() === 0, '精确清理后本次后端仍存在');
        expect($snapshot() === $before, '精确清理修改了协议数据');
        $report['checks'][] = 'cleanup-terminates-only-owned-operation';
        $report['status'] = 'passed';
    } finally {
        $victim = null;
        $inspection = null;
        unset($snapshot, $call);
        if (is_file($requestFile)) {
            unlink($requestFile);
        }
        try {
            $sync?->close();
        } finally {
            $database->close();
            $report['database'] = $database->evidence();
            if ($report['status'] !== 'passed') {
                $report['status'] = 'failed';
            }
            file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        }
    }
    echo 'Demo 内部存储只读与精确清理通过：' . $base . "/verification.json\n";
}
