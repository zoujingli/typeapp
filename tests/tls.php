<?php

declare(strict_types=1);
require __DIR__ . '/support.php';
$root = dirname(__DIR__);
$binary = $argv[1] ?? '--php';
$driver = $argv[2] ?? 'mysql';
expect(in_array($driver, ['mysql', 'pgsql', 'redis'], true), 'TLS 验证目标无效');
$command = $binary === '--php' ? [PHP_BINARY, '-r', 'require ' . var_export($root . '/vendor/autoload.php', true)
    . ';require ' . var_export($root . '/examples/tls-command.php', true) . ';main($argc,$argv);'] : nativeCommand($binary);
[$status, $stdout, $stderr] = execute([...$command, $driver], $root);
expect($status === 0 && $stderr === '' && $stdout === $driver . " TLS 信任链、主机名和错误 CA 拒绝通过。\n", 'TLS 公开连接行为失败：' . $stdout . $stderr);
echo $stdout;
