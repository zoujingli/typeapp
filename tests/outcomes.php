<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
if (isset($argv[1]) && $argv[1] !== '--php') {
    $command = nativeCommand($argv[1]);
} else {
    $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true)
        . '; require ' . var_export($root . '/examples/model/Drivers.php', true)
        . '; require ' . var_export($root . '/examples/outcome-command.php', true) . '; main($argc, $argv);';
    $command = [PHP_BINARY, '-r', $launcher];
}
[$status, $stdout, $stderr] = execute([...$command, $argv[2] ?? 'sqlite']);
expect($status === 0 && $stdout === "事务结果、提交后回调、失败汇总与边界 SQL 拒绝通过。\n" && $stderr === '', '事务结果验收失败：' . $stdout . $stderr);
echo $stdout;
