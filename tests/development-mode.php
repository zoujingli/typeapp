<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Testing\HttpClient;
use Type\Testing\Process;

/** 仅测试驱动用于以 PHP 对照生产入口标记；正式交付必须另传实际 AOT 产物。 */
function productionModeCommand(string $root, string $target): array
{
    if ($target !== '--php') {
        return nativeCommand($target);
    }
    return [PHP_BINARY, '-r', 'require ' . var_export($root . '/bin/typeapp-prepare', true)
        . '; $g = prepareTypeAppApplication(' . var_export($root, true) . '); foreach ($g["files"] as $f) { require $g["directory"] . "/" . $f; } require '
        . var_export($root . '/app/main.php', true) . '; main($argc, $argv);'];
}

$root = dirname(__DIR__);
$target = $argv[1] ?? '--php';
$production = productionModeCommand($root, $target);
$development = [PHP_BINARY, $root . '/bin/typeapp'];
$base = $root . '/build/mode-test-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建独立模式测试目录');
$secret = 'mode-secret-' . bin2hex(random_bytes(20));
$environment = getenv();
foreach (array_keys($environment) as $key) {
    if (str_starts_with($key, 'APP_') || str_starts_with($key, 'DB_') || str_starts_with($key, 'REDIS_')) {
        unset($environment[$key]);
    }
}
$environment += ['APP_BASE_PATH' => $base, 'APP_ADMIN_PASSWORD' => $secret, 'APP_CUSTOMER_PASSWORD' => $secret . '-customer', 'APP_DEBUG' => 'true',
    'APP_ENV' => 'production', 'APP_CACHE_ENABLED' => 'false',
    'DB_DRIVER' => 'sqlite', 'DB_SQLITE_FILE' => $base . '/database.sqlite'];
$pdo = new PDO('sqlite:' . $base . '/database.sqlite');
$pdo = null;
try {
    foreach ([['command' => $development, 'development' => true], ['command' => $production, 'development' => false]] as $mode) {
        $check = new Process([...$mode['command'], 'check'], $root, $environment);
        $checked = $check->wait(10);
        expect($checked->successful() && str_contains($checked->stdout, $mode['development'] ? 'development' : 'production')
            && str_contains($checked->stdout, $mode['development'] ? '调试：on' : '调试：off'), '启动模式和调试权限混淆');
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        expect(is_resource($listener), '无法选择测试端口');
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $environment['APP_PORT'] = substr(strrchr($address, ':'), 1);
        $environment['APP_ALLOWED_HOSTS'] = $address;
        $process = new Process([...$mode['command'], 'serve'], $root, $environment);
        $client = new HttpClient('http://' . $address);
        try {
            $ready = false;
            $deadline = microtime(true) + 10;
            do {
                expect($process->running(), '模式测试服务提前退出：' . $process->stderr());
                try {
                    $ready = $client->request('GET', '/readyz')->status === 200;
                } catch (RuntimeException) {
                }
                if (!$ready) {
                    usleep(10000);
                }
            } while (!$ready && microtime(true) < $deadline);
            expect($ready, '模式测试服务未就绪');
            // 数据库文件存在而结构缺失是实际部署失败；不在业务源码中添加测试故障路由。
            $response = $client->request('GET', '/admin/profile', [
                'Authorization' => 'Bearer ' . $secret, 'X-Debug' => 'true', 'X-Request-Id' => $secret,
            ]);
            $body = $response->json();
            expect($response->status === 500 && array_keys($body) === ['error', 'request_id']
                && $body['error'] === 'internal_error' && preg_match('/^[a-f0-9]{32}$/D', $body['request_id']) === 1
                && ($response->headers['x-request-id'] ?? []) === [$body['request_id']], '失败响应暴露了调试内容或相信客户端请求ID');
            $stopped = $process->stop(5);
            expect($stopped->successful(), '测试服务未正常收尾');
            $logs = $stopped->stdout . $stopped->stderr;
            expect(!str_contains($response->body . $logs, $secret) && !str_contains($logs, 'no such table')
                && str_contains($logs, $body['request_id']), '错误日志暴露查询/令牌或无法关联请求');
            if ($mode['development']) {
                expect(str_contains($logs, 'exception_type') && str_contains($logs, 'app/admin/')
                    && !str_contains($logs, '"args"'), '开发诊断未定位业务源码或包含调用参数');
            } else {
                expect(!str_contains($logs, 'exception_type') && !str_contains($logs, '"frames"'), '生产环境被配置或HTTP头打开了调试');
            }
        } finally {
            $process->stop();
        }
    }
    echo "开发与生产入口权限、失败响应、业务定位、日志脱敏与请求关联通过。\n";
} finally {
    foreach (['database.sqlite', 'database.sqlite-wal', 'database.sqlite-shm'] as $file) {
        if (is_file($base . '/' . $file)) {
            unlink($base . '/' . $file);
        }
    }
}
