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
        foreach (['', '/' . $product . '/update', '/' . $product . '/enable', '/' . $product . '/disable', '/' . $product . '/rotate', '/' . $product . '/revoke', '/' . $product . '/issue'] as $suffix) {
            $response = $client->request('POST', $applications . $suffix, $headers, '{}');
            expect($response->status === 403 && $response->json()['error'] === 'demo_readonly', '演示模式不能创建应用或修改密钥');
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
