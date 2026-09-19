<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
if (isset($argv[1]) && $argv[1] !== '--php') {
    $command = nativeCommand($argv[1]);
} else {
    $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true)
        . '; require ' . var_export($root . '/examples/tasks-command.php', true) . '; main($argc, $argv);';
    $command = [PHP_BINARY, '-d', 'swoole.enable_library=Off', '-r', $launcher];
}
[$status, $stdout, $stderr] = execute($command);
expect($status === 0 && $stdout === "受管子任务、预算、延迟 SQL、隔离容量与延期清理通过。\n" && $stderr === '', '受管任务验收失败：' . $stdout . $stderr);
echo $stdout;
