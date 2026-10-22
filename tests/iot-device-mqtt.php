<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';
require __DIR__ . '/postgres-sync.php';

$root = dirname(__DIR__);
$target = $argv[1] ?? '--php';
$tools = NativeDatabase::tools('pgsql', (string) getenv('TYPE_PGSQL_TOOLS'));
$clientRoot = realpath((string) getenv('TYPE_MQTT_CLIENT_ROOT'));
expect((is_string($clientRoot) && is_file($clientRoot . '/node_modules/mqtt/package.json')), 'TYPE_MQTT_CLIENT_ROOT需要已安装MQTT.js 5.15.0的测试依赖根');
$base = $root . '/build/iot-device-mqtt-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建接入测试根');
$database = PHP_OS_FAMILY === 'Windows' ? null : new NativeDatabase($base . '/primary', 'pgsql', $tools);
$sync = null;
try {
    // Windows 主库由外层已隔离的 PowerShell 装置持有，PostgresSync 另核对其准确数据目录。
    $parameters = $database === null ? getenv() : $database->environment();
    $sync = new PostgresSync($database ?? $parameters, $base . '/standby', $tools);
    $environment = array_replace(getenv(), $parameters);
    $environment['PATH'] = (string) getenv('PATH');
    $environment['TYPE_MQTT_CLIENT_ROOT'] = $clientRoot ?: '';
    $environment['TYPE_PGSQL_STANDBY_PORT'] = (string) $sync->standby()->query('SHOW port')->fetchColumn();
    $output = nativeDatabaseCommand(
        [PHP_BINARY, $root . '/tests/iot-identity.php', $target, 'pgsql', '--devices', '--device-mqtt', ...array_slice($argv, 2)],
        $environment,
        [$environment['TYPE_PGSQL_PASSWORD']],
        $base . '/application.log',
        // 组合包含同步持久确认、撤权及重启等待；各协议动作仍使用自己的有限截止。
        in_array('--broker-resources', $argv, true) && getenv('TYPE_BROKER_RESOURCE_DIST') ? 600
            : (in_array('--reassignments-mqtt', $argv, true) ? 480 : (in_array('--lifecycle', $argv, true) || in_array('--lifecycle-mqtt', $argv, true) || in_array('--communication-mqtt', $argv, true) || in_array('--broker-resources', $argv, true) ? 360 : 240))
    );
    echo $output;
} finally {
    try {
        $sync?->close();
    } finally {
        $database?->close();
    }
}
