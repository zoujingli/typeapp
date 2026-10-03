<?php

declare(strict_types=1);

/**
 * 快速定位原生模块的 DNS 配置与线程归属；完整 AOT 仍由 tcp-consumer 验收。
 *
 * @return array{address:string|false,error:int,coroutines:int}
 */
function probeSwooleDns(string $server): array
{
    Swoole\Coroutine::set(['dns_server' => $server, 'dns_cache_capacity' => 0, 'hook_flags' => 0]);
    $result = ['address' => '', 'error' => 0];
    Swoole\Coroutine::create(static function () use (&$result): void {
        $result['address'] = Swoole\Coroutine\System::gethostbyname('tcp.typeapp.test', AF_INET, 1.0);
        $result['error'] = swoole_last_error();
    });
    Swoole\Event::wait();
    $result['coroutines'] = Swoole\Coroutine::stats()['coroutine_num'];
    return $result;
}

$arguments = Swoole\Thread::getArguments();
if (is_array($arguments)) {
    [$server, $path] = $arguments;
    $result = probeSwooleDns($server);
    file_put_contents($path, json_encode($result, JSON_THROW_ON_ERROR));
    exit($result['address'] === '127.0.0.1' && $result['coroutines'] === 0 ? 0 : 1);
}

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\BuildPlatform;
use Type\Testing\Process;

$root = BuildPlatform::resolve(dirname(__DIR__));
$directory = BuildPlatform::path($argv[1] ?? '');
expect($argc === 2 && BuildPlatform::contains($root . '/build', $directory) && !str_contains($directory, '..'), '需要 build 下的专用 DNS 验证目录');
expect(!file_exists($directory) && mkdir($directory, 0700, true), 'DNS 验证目录必须尚不存在');
$peer = new Process([(string) (getenv('NODE_BINARY') ?: 'node'), __DIR__ . '/fixtures/tcp-peer.mjs', $directory . '/peers.json', '--dns-only'], $directory);
$results = [];
try {
    $deadline = microtime(true) + 5;
    while (!is_file($directory . '/peers.json') && $peer->running() && microtime(true) < $deadline) {
        usleep(10000);
    }
    expect(is_file($directory . '/peers.json'), 'DNS 对端未就绪：' . $peer->stderr());
    $peers = json_decode((string) file_get_contents($directory . '/peers.json'), true, 512, JSON_THROW_ON_ERROR);
    $server = '127.0.0.1:' . $peers['dns']['port'];
    foreach (['first', 'restart'] as $role) {
        $path = $directory . '/' . $role . '.json';
        $thread = new Swoole\Thread(__FILE__, $server, $path);
        $thread->join();
        $results[$role] = ['exit' => $thread->getExitStatus(), 'result' => json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)];
    }
    $results['main'] = probeSwooleDns($server);
    $queries = json_decode((string) file_get_contents($directory . '/dns.json'), true, 512, JSON_THROW_ON_ERROR);
    $passed = $results['first']['exit'] === 0 && $results['restart']['exit'] === 0
        && $results['main']['address'] === '127.0.0.1' && $results['main']['coroutines'] === 0
        && ($queries['tcp.typeapp.test'] ?? 0) >= 3;
    file_put_contents($directory . '/verification.json', json_encode(['passed' => $passed, 'swoole' => phpversion('swoole'),
        'queries' => $queries, 'results' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    expect($passed, '指定 DNS 在主线程与重建工作线程必须生效；见 ' . $directory . '/verification.json');
    echo "原生 DNS 主线程、工作线程与重建验证通过。\n";
} finally {
    $peer->stop();
}
