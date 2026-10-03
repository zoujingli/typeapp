<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/swoole/typephp/src/polyfills.php';

use Type\Build\BuildPlatform;
use Type\Core\TcpSocket;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\ResourceBudget;
use Type\Runtime\TaskException;

$root = BuildPlatform::resolve(dirname(__DIR__));
$directory = BuildPlatform::path($argv[1] ?? '');
expect($argc === 2 && BuildPlatform::contains($root . '/build', $directory) && !str_contains($directory, '..'), '需要 build 下的专用绑定验证目录');
expect(!file_exists($directory) && mkdir($directory, 0700, true), '绑定验证目录必须尚不存在');
CoroutineRuntime::enableIo();
$results = [];
Swoole\Coroutine::create(static function () use (&$results): void {
    foreach (['127.0.0.1', '::1'] as $address) {
        $budget = new ResourceBudget(2);
        $listener = TcpSocket::listener($budget, $address);
        $conflict = null;
        $result = ['passed' => false, 'duplicate' => 'not-started'];
        try {
            $listener->start();
            $conflict = TcpSocket::listener($budget, $address, $listener->addresses()['local']['port']);
            try {
                $conflict->start();
                $result['duplicate'] = 'accepted';
            } catch (TaskException $error) {
                $result['duplicate'] = $error->errorCode();
            }
            $result['passed'] = $result['duplicate'] === 'tcp_listen_failed';
        } catch (Throwable $error) {
            $result['failure'] = get_class($error) . ': ' . $error->getMessage();
        } finally {
            // 在任意启动阶段失败都显式停止已创建对象，避免清理异常覆盖首次失败。
            $conflict?->stop();
            $listener->stop();
            $conflict?->awaitClosed(1);
            $listener->awaitClosed(1);
            $result['allocated'] = $budget->statistics()['allocated'];
            $result['passed'] = $result['passed'] && $result['allocated'] === 0;
            $results[$address] = $result;
        }
    }
});
Swoole\Event::wait();
// 对照短截止与原生拒绝完成时间；采样不修改生产连接预算，也不把超时当成拒绝通过。
$refusal = [];
Swoole\Coroutine::create(static function () use (&$refusal): void {
    $budget = new ResourceBudget(1);
    $listener = TcpSocket::listener($budget, '127.0.0.1');
    $listener->start();
    $port = $listener->addresses()['local']['port'];
    $listener->stop();
    $listener->awaitClosed(1);
    foreach ([0.2, 5.0] as $timeout) {
        $socket = new Swoole\Coroutine\Socket(AF_INET, SOCK_STREAM, 0);
        $began = hrtime(true);
        try {
            $connected = $socket->connect('127.0.0.1', $port, $timeout);
            $refusal[] = ['timeout' => $timeout, 'connected' => $connected, 'errno' => $socket->errCode,
                'seconds' => (hrtime(true) - $began) / 1e9];
        } finally {
            $socket->close();
        }
    }
});
Swoole\Event::wait();
$coroutines = Swoole\Coroutine::stats()['coroutine_num'];
$passed = count($results) === 2 && !in_array(false, array_column($results, 'passed'), true) && $coroutines === 0;
file_put_contents($directory . '/verification.json', json_encode(['passed' => $passed, 'platform' => PHP_OS_FAMILY,
    'swoole' => phpversion('swoole'), 'coroutines' => $coroutines, 'results' => $results, 'refusal' => $refusal], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
expect($passed, '重复 TCP 监听必须拒绝且归还额度；见 ' . $directory . '/verification.json');
echo "TCP IPv4/IPv6 重复监听拒绝与清理验证通过。\n";
