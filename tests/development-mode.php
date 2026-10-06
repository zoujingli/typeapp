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
        . '; typeAppDevelopmentBuilder(' . var_export($root, true) . ')->loadConfiguration('
        . var_export($root . '/docs/build-config/type-app.json', true) . '); main($argc, $argv);'];
}

$root = dirname(__DIR__);
$target = $argv[1] ?? '--php';
$production = productionModeCommand($root, $target);
$development = [PHP_BINARY, $root . '/bin/typeapp'];
$base = $root . '/build/mode-test-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建独立模式测试目录');
$secret = bin2hex(random_bytes(32));
$environment = getenv();
foreach (array_keys($environment) as $key) {
    if (str_starts_with($key, 'APP_') || str_starts_with($key, 'DB_') || str_starts_with($key, 'REDIS_')) {
        unset($environment[$key]);
    }
}
$environment += ['APP_BASE_PATH' => $base, 'APP_ADMIN_PASSWORD' => $secret, 'APP_CUSTOMER_PASSWORD' => 'customer-' . substr($secret, 0, 48), 'APP_DEBUG' => 'true',
    'APP_ENV' => 'production', 'APP_CACHE_ENABLED' => 'false',
    'DB_DRIVER' => 'sqlite', 'DB_SQLITE_FILE' => $base . '/database.sqlite'];
try {
    $install = new Process([...$development, 'app:install', 'mode-admin', '模式管理员', 'mode-customer', '模式客户', '模式租户'], $root, $environment);
    try {
        $installed = $install->wait(30);
        expect($installed->successful(), '模式测试标准初始化失败：' . $installed->stderr);
    } finally {
        $install->stop();
    }
    foreach ([['command' => $development, 'development' => true], ['command' => $production, 'development' => false]] as $mode) {
        $check = new Process([...$mode['command'], 'check'], $root, $environment);
        $checked = $check->wait(10);
        expect($checked->successful() && str_contains($checked->stdout, $mode['development'] ? 'development' : 'production')
            && str_contains($checked->stdout, $mode['development'] ? '调试：on' : '调试：off'), '启动模式和调试权限混淆');
        if ($target === '--php' && !$mode['development']) {
            // PHP 对照只核对生成 main 的权限；生产 HTTP 线程必须由实际 AOT 产物验证。
            continue;
        }
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        expect(is_resource($listener), '无法选择测试端口');
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $environment['APP_PORT'] = substr(strrchr($address, ':'), 1);
        $environment['APP_ALLOWED_HOSTS'] = $address;
        $process = new Process([...$mode['command'], 'serve'], $root, $environment);
        $client = new HttpClient('http://' . $address);
        $renamed = false;
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
            // 先通过真实启动预检，再制造实际请求依赖缺失；不增加生产故障路由。
            $inspection = new PDO('sqlite:' . $base . '/database.sqlite');
            $inspection->exec('ALTER TABLE admin_sessions RENAME TO mode_unavailable_sessions');
            $renamed = true;
            $inspection = null;
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
            $failures = [];
            foreach (explode("\n", $logs) as $line) {
                $record = json_decode($line, true);
                if (is_array($record) && ($record['context']['error'] ?? null) === 'internal_error'
                    && ($record['context']['request_id'] ?? null) === $body['request_id']) {
                    $failures[] = $record;
                }
            }
            expect(count($failures) === 1, '内部错误缺少唯一可关联的结构化原因');
            $details = $failures[0]['context'];
            expect($failures[0]['level'] === 'error' && is_string($details['exception_type'] ?? null)
                && is_string($details['file'] ?? null) && is_int($details['line'] ?? null)
                && $details['line'] >= 0 && !str_contains($logs, '"args"'), '内部错误缺少受控定位或包含调用参数');
            if ($mode['development']) {
                expect(str_contains($logs, 'exception_type') && str_contains($logs, 'app/common/')
                    && !str_contains($logs, '"args"'), '开发诊断未定位业务源码或包含调用参数');
            } else {
                // 生产始终保留受控原因；启动权限只决定能否增加调用帧，不能退回无原因日志。
                expect(array_keys($details) === ['error', 'request_id', 'exception_type', 'file', 'line', 'build_id']
                    && !str_contains($logs, '"frames"'), '生产环境被配置或HTTP头打开了额外调试详情');
                $identity = (new \Type\Build\ArtifactManifest())->read(realpath($target));
                expect(
                    $details['build_id'] === $identity['build-id'] && $failures[0]['build_id'] === $identity['build-id'],
                    '生产原因日志没有绑定实际验收程序'
                );
            }
        } finally {
            $process->stop();
            if ($renamed) {
                $inspection = new PDO('sqlite:' . $base . '/database.sqlite');
                $inspection->exec('ALTER TABLE mode_unavailable_sessions RENAME TO admin_sessions');
                $inspection = null;
            }
        }
    }
    echo $target === '--php'
        ? "开发 HTTP 失败响应、业务定位、日志脱敏与请求关联及生成 main 生产权限通过；生产 HTTP 另由原生产物验证。\n"
        : "开发与原生生产入口权限、失败响应、业务定位、日志脱敏与请求关联通过。\n";
} finally {
    removeTestDirectory($base);
}
