<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
$root = dirname(__DIR__);
$launcher = 'require ' . var_export($root . '/tests/log-bootstrap.php', true) . '; require '
    . var_export($root . '/examples/log-failure-command.php', true) . '; main($argc, $argv);';
$command = isset($argv[1]) && $argv[1] !== '--php' ? nativeCommand($argv[1]) : [PHP_BINARY, '-r', $launcher];
[$status, $stdout, $stderr] = execute($command);
expect($status === 0 && $stdout === "日志真实管道满载、停止预算、断开输出与文件失败计数通过。\n" && $stderr === '', '日志故障验证失败：' . $stdout . $stderr);
echo $stdout;
