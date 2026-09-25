<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Testing\Process;

$root = dirname(__DIR__);
$target = $argv[1] ?? '--php';
if ($target !== '--php') {
    $artifact = realpath($target);
    expect($artifact !== false && is_file($artifact . '.build.json'), '需要带构建身份的真实应用产物');
    $built = json_decode(file_get_contents($artifact . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
    putenv('TYPE_NATIVE_PHP_INI=' . $built['runtime-profile']['ini']);
}
$command = $target === '--php' ? [PHP_BINARY, $root . '/bin/typeapp'] : nativeCommand($target);
$base = $root . '/build/application-diagnostics-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建独立诊断测试目录');
$secret = 'diagnostic-secret-' . bin2hex(random_bytes(20));
$environment = getenv();
foreach (array_keys($environment) as $key) {
    if (str_starts_with($key, 'APP_') || str_starts_with($key, 'DB_') || str_starts_with($key, 'REDIS_')) {
        unset($environment[$key]);
    }
}
$environment += ['APP_BASE_PATH' => $base, 'APP_ADMIN_PASSWORD' => $secret, 'APP_CUSTOMER_PASSWORD' => $secret . '-customer',
    'APP_DEBUG' => 'false', 'APP_ENV' => 'production', 'APP_CACHE_ENABLED' => 'false',
    'DB_DRIVER' => 'sqlite', 'DB_SQLITE_FILE' => $base . '/invalid.sqlite'];
try {
    // 损坏的真实 SQLite 文件触发 PDO 原始错误，再由 ORM 包装；不添加测试故障入口。
    file_put_contents($environment['DB_SQLITE_FILE'], str_repeat($secret, 100));
    foreach (['0', '1'] as $trace) {
        $environment['TYPE_APP_TRACE'] = $trace;
        $process = new Process([...$command, 'app:install', 'trace-admin', '诊断管理员', 'trace-customer', '诊断客户', '诊断租户'], $root, $environment);
        try {
            $failure = $process->wait(10);
            $output = $failure->stdout . $failure->stderr;
            expect(!$failure->successful() && !$failure->timedOut && str_contains($output, 'internal_error'), '数据库打开失败没有拒绝执行');
            expect(!str_contains($output, $secret) && !str_contains($output, $base)
                && !str_contains($output, 'not a database'), '受控诊断泄漏原始驱动消息、路径或秘密');
            expect(str_contains($output, '"driver_code":26') === ($trace === '1')
                && str_contains($output, '"sqlstate":"HY000"') === ($trace === '1'), '驱动错误码未按显式诊断开关输出：' . $output);
        } finally {
            $process->stop();
        }
    }
    echo "应用数据库失败拒绝执行、显式错误码诊断与秘密隔离通过。\n";
} finally {
    if (is_file($environment['DB_SQLITE_FILE'])) {
        unlink($environment['DB_SQLITE_FILE']);
    }
    rmdir($base);
}
