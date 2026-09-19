<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
$root = dirname(__DIR__);
$launcher = 'require ' . var_export($root . '/tests/log-bootstrap.php', true)
    . '; require ' . var_export($root . '/vendor/swoole/typephp/src/polyfills.php', true) . '; require '
    . var_export($root . '/examples/log-behavior-command.php', true) . '; main($argc, $argv);';
$command = isset($argv[1]) && $argv[1] !== '--php' ? nativeCommand($argv[1]) : [PHP_BINARY, '-r', $launcher];
[$status, $stdout, $stderr] = execute($command);
expect($status === 0 && $stdout === "日志文件、多通道、作用域隔离、任意上下文与脱敏通过。\n" && $stderr === '', '日志公开行为失败：' . $stdout . $stderr);
echo $stdout;
