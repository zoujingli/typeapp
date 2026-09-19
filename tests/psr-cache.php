<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
if (isset($argv[1]) && $argv[1] !== '--php') {
    $command = nativeCommand($argv[1]);
} else {
    $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true)
        . '; require ' . var_export($root . '/vendor/swoole/typephp/src/polyfills.php', true)
        . '; require ' . var_export($root . '/examples/cache/SerializableNote.php', true)
        . '; require ' . var_export($root . '/examples/psr-cache-command.php', true) . '; main($argc, $argv);';
    $command = [PHP_BINARY, '-r', $launcher];
}
[$status, $stdout, $stderr] = execute($command);
expect($status === 0 && $stdout === "PSR-16 类型、对象、TTL、非法键、签名与永久数据回收通过。\n" && $stderr === '', 'PSR 缓存验收失败：' . $stdout . $stderr);
echo $stdout;
