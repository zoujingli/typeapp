<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';

use Type\Build\BuildPlatform;

$root = dirname(__DIR__);
expect(
    $argc >= 4 && $argc <= 20
    && array_diff(array_slice($argv, 4), ['--app', '--broker', '--no-source', '--audit', '--broker-audit', '--broker-resources', '--products', '--devices', '--business', '--history', '--aggregate', '--alarms', '--exports', '--export-transfers-only', '--io-export-baseline', '--lifecycle', '--operations']) === [],
    '用法：php tests/iot-identity-databases.php <原生产物或--php> <MySQL工具根> <PostgreSQL工具根> [--app|--broker] [--no-source] [--audit] [--broker-audit] [--broker-resources] [--products] [--devices] [--business] [--history] [--aggregate] [--alarms] [--exports [--export-transfers-only|--io-export-baseline]] [--lifecycle] [--operations]'
);
expect(!in_array('--export-transfers-only', $argv, true) || in_array('--exports', $argv, true), '转移导出专项需要--exports');
$ioExportBaseline = in_array('--io-export-baseline', $argv, true);
expect(!$ioExportBaseline || (in_array('--exports', $argv, true) && !in_array('--export-transfers-only', $argv, true)), 'I/O导出基线需要--exports，不能与转移专项混用');
$target = $argv[1] === '--php' ? '--php' : realpath($argv[1]);
expect(is_string($target), '身份验证产物不存在');
$tools = ['mysql' => NativeDatabase::tools('mysql', $argv[2]), 'pgsql' => NativeDatabase::tools('pgsql', $argv[3])];
$base = $root . '/build/iot-identity-databases-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法准备本轮三库验证根');
$environment = getenv();
if ($target !== '--php') {
    $environment = array_replace($environment, (new BuildPlatform())->environment(getenv('PHP_HOME') ?: '', getenv('PHPX_HOME') ?: ''));
    $built = json_decode(file_get_contents($target . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
    // 归档产物恢复到新目录时可显式提供等价运行配置，应用仍校验真实扩展身份。
    $environment['TYPE_NATIVE_PHP_INI'] = getenv('TYPE_NATIVE_PHP_INI') ?: $built['runtime-profile']['ini'];
}
$report = ['status' => 'running', 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'drivers' => []];
// 组合运行包含多个独立有界场景，按额外场景增加总预算；各HTTP和子命令自身截止保持不变。
$scenarios = count(array_intersect(array_slice($argv, 4), ['--history', '--aggregate', '--alarms', '--exports']));
$timeout = 120 + max(0, $scenarios - 1) * 60;
if ($ioExportBaseline) {
    $timeout = 600;
}
try {
    foreach ($ioExportBaseline ? ['pgsql'] : ['mysql', 'pgsql', 'sqlite'] as $driver) {
        $database = new NativeDatabase($base . '/' . $driver, $driver, $tools[$driver] ?? []);
        try {
            $run = array_replace($environment, $database->environment());
            $secrets = $driver === 'sqlite' ? [] : [$run['TYPE_' . strtoupper($driver) . '_PASSWORD']];
            $output = nativeDatabaseCommand([PHP_BINARY, $root . '/tests/iot-identity.php', $target, $driver, ...array_slice($argv, 4)], $run, $secrets, $base . '/' . $driver . '.log', $timeout);
            expect(preg_match('#通过：(.*?/verification\.json)\s*$#u', $output, $matches) === 1, '身份入口没有返回通过证据');
            $report['drivers'][$driver] = ['evidence' => substr($matches[1], strlen($root) + 1), 'sha256' => hash_file('sha256', $matches[1])];
            echo $output;
        } finally {
            $database->close();
            $report['drivers'][$driver]['database'] = $database->evidence();
        }
    }
    $report['status'] = 'passed';
} finally {
    if ($report['status'] !== 'passed') {
        $report['status'] = 'failed';
    }
    file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
echo '身份数据库与实例正常退出通过：' . $base . "/verification.json\n";
