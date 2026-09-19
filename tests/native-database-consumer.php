<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
// 控制端只加载资源所有者，不因主仓Composer依赖要求其他数据库扩展。
foreach (['type-runtime/src/Deadline.php', 'type-testing/src/ProcessResult.php', 'type-testing/src/Process.php',
    'type-build/src/BuildPlatform.php', 'type-build/src/BuildLock.php'] as $helper) {
    require dirname(__DIR__) . '/plugin/' . $helper;
}
require __DIR__ . '/native-database.php';

use Type\Build\BuildPlatform;

$root = dirname(__DIR__);
$driver = $argv[1] ?? '';
$toolsRoot = $argv[2] ?? '';
expect(in_array($driver, ['mysql', 'pgsql'], true), '用法：php tests/native-database-consumer.php <mysql|pgsql> <数据库工具根>');
$tools = NativeDatabase::tools($driver, $toolsRoot);
$base = $root . '/build/native-' . $driver . '-consumer-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建独立数据库消费验收目录');
$environment = (new BuildPlatform())->environment(getenv('PHP_HOME') ?: '', getenv('PHPX_HOME') ?: '');
$environment['PATH'] = getenv('PATH') ?: '';
$environment['PHPRC'] = getenv('PHPRC') ?: '';
$environment['PHP_INI_SCAN_DIR'] = '';
$environment['COMPOSER_BINARY'] = getenv('COMPOSER_BINARY') ?: 'composer';
$environment['COMPOSER_HOME'] = $base . '/composer-home';
$environment['COMPOSER_CACHE_DIR'] = $root . '/.cache/composer';
$environment['TYPE_EXPECT_SELECTED_DRIVER_ONLY'] = getenv('TYPE_EXPECT_SELECTED_DRIVER_ONLY') ?: '';
expect(mkdir($environment['COMPOSER_HOME'], 0700), '无法创建无凭据Composer目录');
$database = new NativeDatabase($base . '/database', $driver, $tools);
$report = ['status' => 'running', 'driver' => $driver, 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'),
    'php' => PHP_VERSION, 'pdo-drivers' => PDO::getAvailableDrivers(), 'steps' => []];
try {
    $databaseEnvironment = $database->environment();
    $environment = array_replace($databaseEnvironment, $environment);
    $secrets = [$databaseEnvironment['TYPE_' . strtoupper($driver) . '_PASSWORD']];
    foreach (['database-consumer.php', 'migrations-consumer.php'] as $script) {
        $log = $base . '/' . $script . '.log';
        echo nativeDatabaseCommand([PHP_BINARY, $root . '/tests/' . $script, $driver], $environment, $secrets, $log, 1800);
        $report['steps'][] = ['script' => 'tests/' . $script, 'log' => basename($log), 'sha256' => hash_file('sha256', $log)];
    }
    $report['status'] = 'passed';
} finally {
    try {
        $database->close();
        $report['database'] = $database->evidence();
    } catch (Throwable $cleanup) {
        $report['status'] = 'failed';
        throw $cleanup;
    } finally {
        if ($report['status'] !== 'passed') {
            $report['status'] = 'failed';
        }
        file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
}
echo '独立数据库PHP/AOT与迁移验收通过：' . substr($base, strlen($root) + 1) . "/verification.json\n";
