<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$command = ($argv[1] ?? '') === '--php'
    ? [PHP_BINARY, '-r', 'require ' . var_export($root . '/vendor/autoload.php', true) . ';require '
        . var_export($root . '/examples/query-command.php', true) . ';main($argc,$argv);']
    : nativeCommand($argv[1] ?? 'build/query/type-app');
$drivers = array_slice($argv, 2);
if ($drivers === []) {
    $drivers = ['mysql', 'pgsql', 'sqlite'];
}
foreach ($drivers as $driver) {
    expect(in_array($driver, ['mysql', 'pgsql', 'sqlite'], true), '未知查询驱动');
    [$status, $stdout, $stderr] = execute([...$command, $driver]);
    expect($status === 0 && $stdout === $driver . " 不可变查询与批量报表验证通过。\n" && $stderr === '', '查询验证失败：' . $stdout . $stderr);
    echo $stdout;
}
