<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
if (isset($argv[1]) && !in_array($argv[1], ['--php', '--scope-only'], true)) {
    $command = nativeCommand($argv[1]);
} else {
    $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true)
        . '; require ' . var_export($root . '/examples/tasks-command.php', true) . '; main($argc, $argv);';
    $command = [PHP_BINARY, '-d', 'swoole.enable_library=On', '-r', $launcher, '--'];
}
$scopeOnly = in_array('--scope-only', $argv, true);
if ($scopeOnly) {
    $command[] = '--scope-only';
}
[$status, $stdout, $stderr] = execute($command);
$expected = $scopeOnly ? "当前作用域、嵌套恢复、可信值快照与协程隔离通过。\n" : "受管子任务、预算、延迟 SQL、隔离容量与延期清理通过。\n";
expect($status === 0 && $stdout === $expected && $stderr === '', '受管任务验收失败：' . $stdout . $stderr);
echo $stdout;
