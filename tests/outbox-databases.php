<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';

$root = dirname(__DIR__);
expect($argc === 4, '用法：php tests/outbox-databases.php <原生产物或--php> <MySQL工具根> <PostgreSQL工具根>');
$target = $argv[1];
$tools = ['mysql' => NativeDatabase::tools('mysql', $argv[2]), 'pgsql' => NativeDatabase::tools('pgsql', $argv[3])];
$base = $root . '/build/outbox-databases-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法建立Outbox验收目录');
$report = ['status' => 'running', 'target' => $target, 'source' => hash_file('sha256', $root . '/examples/outbox-command.php'),
    'relay' => hash_file('sha256', $root . '/plugin/type-orm/src/Outbox/Relay.php'), 'drivers' => []];
try {
    foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
        $database = new NativeDatabase($base . '/' . $driver, $driver, $tools[$driver] ?? []);
        try {
            $environment = array_replace(getenv(), $database->environment());
            $secrets = $driver === 'sqlite' ? [] : [$environment['TYPE_' . strtoupper($driver) . '_PASSWORD']];
            echo nativeDatabaseCommand([PHP_BINARY, $root . '/tests/outbox.php', $target, $driver], $environment, $secrets, $base . '/' . $driver . '.log');
            if ($driver === 'mysql') {
                echo nativeDatabaseCommand([PHP_BINARY, $root . '/tests/outbox-commit-failure.php', $target], $environment, $secrets, $base . '/commit-failure.log');
            }
        } finally {
            $database->close();
            $report['drivers'][$driver] = $database->evidence();
            if (is_file($base . '/' . $driver . '/server.log')) {
                copy($base . '/' . $driver . '/server.log', $base . '/' . $driver . '-server.log');
            }
            removeTestDirectory($base . '/' . $driver);
            $report['drivers'][$driver]['temporary-data-removed'] = true;
        }
    }
    $report['status'] = 'passed';
} finally {
    if ($report['status'] !== 'passed') {
        $report['status'] = 'failed';
    }
    file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
echo 'Outbox三库与 UNKNOWN 验收证据：' . $base . "/verification.json\n";
