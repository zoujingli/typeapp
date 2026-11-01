<?php

declare(strict_types=1);
require __DIR__ . '/support.php';
$root = dirname(__DIR__);
$binary = $argv[1] ?? '--php';
$driver = $argv[2] ?? 'mysql';
$coroutine = ($argv[3] ?? '') === '--coroutine';
expect($argc <= 4 && (!isset($argv[3]) || ($binary === '--php' && $coroutine)), '协程 TLS 对照只接受 PHP 入口与 --coroutine');
expect(in_array($driver, ['mysql', 'pgsql', 'redis'], true), 'TLS 验证目标无效');
$coroutineLog = $coroutine ? getenv('TYPE_TLS_SWOOLE_LOG') : '';
expect(!$coroutine || (is_string($coroutineLog) && $coroutineLog !== ''), '协程 TLS 对照需要独立的 Swoole 原始日志');
$command = $binary === '--php' ? [PHP_BINARY, '-r', 'require ' . var_export($root . '/vendor/autoload.php', true)
    . ';require ' . var_export($root . '/examples/tls-command.php', true) . ';'
    . ($coroutine ? 'swoole_async_set(["log_file"=>' . var_export($coroutineLog, true) . ']);Type\\Runtime\\CoroutineRuntime::enableIo();Type\\Runtime\\CoroutineRuntime::run(static function () use ($argc,$argv): void {main($argc,$argv);});'
        : 'main($argc,$argv);')] : nativeCommand($binary);
[$status, $stdout, $stderr] = execute([...$command, $driver], $root);
expect($status === 0 && $stderr === '' && $stdout === $driver . " TLS 信任链、主机名和错误 CA 拒绝通过。\n", 'TLS 公开连接行为失败：' . $stdout . $stderr);
echo $stdout;
