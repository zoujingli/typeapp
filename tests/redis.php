<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
if (isset($argv[1]) && $argv[1] !== '--php') {
    $command = nativeCommand($argv[1]);
} else {
    $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true)
        . '; require ' . var_export($root . '/examples/redis-command.php', true) . '; main($argc, $argv);';
    $command = [PHP_BINARY, '-r', $launcher];
}
[$status, $stdout, $stderr] = execute($command);
expect($status === 0 && $stdout === "Redis 命名用途、租约、pipeline、事务、超时与恢复通过。\n" && $stderr === '', 'Redis 验收失败：' . $stdout . $stderr);
echo $stdout;
