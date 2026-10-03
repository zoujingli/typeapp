<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\BuildPlatform;
use Type\Testing\Process;

// 独立子进程直接调用原生 API；外层截止能捕获取消后仍没有完成通知的挂起。
if (($argv[1] ?? '') === '--probe') {
    $input = json_decode((string) file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    $mode = $argv[3];
    $output = $argv[4];
    Swoole\Coroutine::set(['dns_server' => '127.0.0.1:' . $input['dns']['port'],
        'dns_cache_capacity' => 0, 'hook_flags' => 0, 'log_file' => $output . '.log']);
    $results = [];
    $failure = null;
    Swoole\Coroutine::create(static function () use ($input, $mode, $output, &$results, &$failure): void {
        try {
            for ($round = 0; $round < 16; $round++) {
                file_put_contents($output . '.checkpoint.json', json_encode(['round' => $round, 'operation' => 'dns'], JSON_THROW_ON_ERROR));
                if ($mode === 'dns') {
                    expect(Swoole\Coroutine\System::gethostbyname('tcp.typeapp.test', AF_INET, 0.5) === '127.0.0.1', '原生 DNS 结果不符');
                }
                $socket = new Swoole\Coroutine\Socket(AF_INET, SOCK_DGRAM, 0);
                $fd = $socket->fd;
                try {
                    // 独立 DNS 对端也是有界 UDP 请求/响应对端；请求有唯一编号并校验返回地址。
                    $query = pack('nnnnnn', $round + 1, 0x0100, 1, 0, 0, 0) . "\x03tcp\x07typeapp\x04test\0" . pack('nn', 1, 1);
                    file_put_contents($output . '.checkpoint.json', json_encode(['round' => $round, 'fd' => $fd, 'operation' => 'send'], JSON_THROW_ON_ERROR));
                    expect($socket->sendto('127.0.0.1', $input['dns']['port'], $query) === strlen($query), 'DNS 后原生 UDP 发送未完成');
                    file_put_contents($output . '.checkpoint.json', json_encode(['round' => $round, 'fd' => $fd, 'operation' => 'receive'], JSON_THROW_ON_ERROR));
                    $peer = [];
                    $response = $socket->recvfrom($peer, 0.5);
                    expect(is_string($response) && strlen($response) >= 16
                        && substr($response, 0, 2) === substr($query, 0, 2)
                        && substr($response, -4) === "\x7f\0\0\x01", 'DNS 后原生 UDP 响应不符');
                    expect($peer['address'] === '127.0.0.1' && $peer['port'] === $input['dns']['port'], '原生 UDP 响应来源不符');
                    $results[] = ['round' => $round, 'fd' => $fd, 'bytes' => strlen($response)];
                } finally {
                    $socket->close();
                }
            }
        } catch (Throwable $error) {
            $failure = get_class($error) . ': ' . $error->getMessage();
        }
    });
    Swoole\Event::wait();
    echo json_encode(['mode' => $mode, 'results' => $results, 'failure' => $failure,
        'coroutines' => Swoole\Coroutine::stats()['coroutine_num']], JSON_THROW_ON_ERROR) . "\n";
    exit($failure === null && count($results) === 16 ? 0 : 1);
}

$root = BuildPlatform::resolve(dirname(__DIR__));
$directory = BuildPlatform::path($argv[1] ?? '');
expect($argc === 2 && BuildPlatform::contains($root . '/build', $directory) && !str_contains($directory, '..'), '需要 build 下的独立 DNS 句柄复用验证目录');
expect(!file_exists($directory) && mkdir($directory, 0700, true), 'DNS 句柄复用验证目录必须尚不存在');
$peer = new Process([(string) (getenv('NODE_BINARY') ?: 'node'), __DIR__ . '/fixtures/tcp-peer.mjs', $directory . '/peers.json', '--dns-only'], $directory);
$application = null;
$results = [];
try {
    $deadline = microtime(true) + 5;
    while (!is_file($directory . '/peers.json') && $peer->running() && microtime(true) < $deadline) {
        usleep(10000);
    }
    expect(is_file($directory . '/peers.json'), '独立 DNS 对端未就绪：' . $peer->stderr());
    foreach (['control', 'dns'] as $mode) {
        $application = new Process([PHP_BINARY, __FILE__, '--probe', $directory . '/peers.json', $mode, $directory . '/' . $mode], $directory);
        $execution = $application->wait(12);
        $results[$mode] = ['exit' => $execution->exitCode, 'timed_out' => $execution->timedOut,
            'stdout' => $execution->stdout, 'stderr' => $execution->stderr];
        file_put_contents($directory . '/execution.json', json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        expect($execution->successful() && $execution->stderr === '', '原生 DNS/句柄复用验收失败：' . $mode . '，见 ' . $directory);
        $result = json_decode(trim($execution->stdout), true, 512, JSON_THROW_ON_ERROR);
        expect($result['failure'] === null && count($result['results']) === 16 && $result['coroutines'] === 0, '原生 DNS/句柄复用未完成或协程残留');
    }
    file_put_contents($directory . '/verification.json', json_encode(['swoole' => phpversion('swoole'),
        'platform' => PHP_OS_FAMILY, 'results' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "原生 DNS 后 Socket 句柄复用与无 DNS 对照均通过。\n";
} finally {
    $application?->stop();
    $peer->stop();
}
