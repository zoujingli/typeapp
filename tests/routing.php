<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$launcher = 'require ' . var_export($root . '/vendor/autoload.php', true)
    . '; require ' . var_export($root . '/vendor/swoole/typephp/src/polyfills.php', true)
    . '; require ' . var_export($root . '/examples/routing/Exercise.php', true)
    . '; require ' . var_export($root . '/examples/routing-command.php', true) . '; main($argc, $argv);';
$command = isset($argv[1]) && $argv[1] !== '--php' ? nativeCommand($argv[1]) : [PHP_BINARY, '-r', $launcher];
[$status, $stdout, $stderr] = execute($command);
expect($status === 0 && $stdout === "参数路由、命名 URL、中间件及匹配规则验证通过。\n" && $stderr === '', '路由行为验证失败：' . $stdout . $stderr);
echo $stdout;
