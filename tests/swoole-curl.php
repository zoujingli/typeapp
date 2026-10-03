<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

/**
 * 在当前请求线程内创建和销毁 curl 句柄，验证双协程标识、超时后的复用及事件循环收尾。
 *
 * @return array{passed:bool,completed:int,failures:list<string>,coroutines:int}
 */
function probeSwooleCurl(int $port, string $owner): array
{
    // 各请求线程声明协程配置；进程级 PHP handler 已在主线程创建工作线程前安装。
    Swoole\Coroutine::set(['hook_flags' => SWOOLE_HOOK_NATIVE_CURL]);
    $completed = 0;
    $failures = [];
    foreach ([0, 1] as $worker) {
        Swoole\Coroutine::create(static function () use ($port, $owner, $worker, &$completed, &$failures): void {
            try {
                expect((Swoole\Runtime::getHookFlags() & SWOOLE_HOOK_NATIVE_CURL) !== 0, '原生 curl hook 未继承');
                for ($round = 0; $round < 8; $round++) {
                    $token = $owner . ':' . $worker . ':' . $round;
                    $handle = curl_init('http://127.0.0.1:' . $port . '/ok');
                    expect($handle instanceof CurlHandle && curl_setopt_array($handle, [
                        CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_TIMEOUT_MS => 2000,
                        CURLOPT_FORBID_REUSE => true, CURLOPT_HTTPHEADER => ['X-Probe-Owner: ' . $token],
                    ]), '无法创建 curl 请求');
                    try {
                        $response = curl_exec($handle);
                        expect($response === $token && curl_errno($handle) === 0
                            && curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 200, 'curl 线程/协程响应串扰或请求失败：'
                            . json_encode(['owner' => $token, 'response' => $response, 'errno' => curl_errno($handle),
                                'error' => curl_error($handle), 'status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE)], JSON_THROW_ON_ERROR));
                        $completed++;
                    } finally {
                        // PHP 8 的句柄由对象析构关闭；不使用已弃用且不负责释放的 curl_close。
                        unset($handle);
                    }
                }
                $token = $owner . ':' . $worker . ':recovered';
                $handle = curl_init('http://127.0.0.1:' . $port . '/slow');
                expect($handle instanceof CurlHandle && curl_setopt_array($handle, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_TIMEOUT_MS => 150,
                    CURLOPT_FORBID_REUSE => true, CURLOPT_HTTPHEADER => ['X-Probe-Owner: ' . $token],
                ]), '无法创建 curl 截止请求');
                $ticks = 0;
                $timer = Swoole\Timer::tick(10, static function (int $timerId) use (&$ticks): void {
                    $ticks++;
                });
                try {
                    expect(curl_exec($handle) === false && curl_errno($handle) === CURLE_OPERATION_TIMEDOUT, 'curl 慢响应未按原生截止返回');
                    expect($ticks > 0, 'curl 等待期间没有让出当前线程的事件循环');
                    expect(curl_setopt_array($handle, [CURLOPT_URL => 'http://127.0.0.1:' . $port . '/ok', CURLOPT_TIMEOUT_MS => 2000]), '无法复用超时句柄');
                    expect(curl_exec($handle) === $token && curl_errno($handle) === 0, 'curl 超时后句柄未恢复');
                    $completed++;
                } finally {
                    Swoole\Timer::clear($timer);
                    unset($handle);
                }
            } catch (Throwable $error) {
                $failures[] = get_class($error) . ': ' . $error->getMessage();
            }
        });
    }
    Swoole\Event::wait();
    $coroutines = Swoole\Coroutine::stats()['coroutine_num'];
    return ['passed' => $completed === 18 && $failures === [] && $coroutines === 0,
        'completed' => $completed, 'failures' => $failures, 'coroutines' => $coroutines];
}

$arguments = Swoole\Thread::getArguments();
if (is_array($arguments)) {
    [$port, $owner, $path] = $arguments;
    $result = probeSwooleCurl($port, $owner);
    file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    exit($result['passed'] ? 0 : 1);
}

// 原生线程 join 的异常亦由外层进程截止捕获；失败不遗留没有监督者的线程。
if (in_array($argv[1] ?? '', ['--probe', '--main-probe'], true)) {
    $directory = $argv[2];
    $peer = json_decode((string) file_get_contents($directory . '/peer.json'), true, 512, JSON_THROW_ON_ERROR);
    expect(Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_NATIVE_CURL), '无法在启动线程前安装 curl hook');
    if ($argv[1] === '--main-probe') {
        $main = probeSwooleCurl($peer['port'], 'standalone-main');
        file_put_contents($directory . '/standalone-main.json', json_encode($main, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        exit($main['passed'] ? 0 : 1);
    }
    $results = [];
    foreach (['first', 'restart'] as $round) {
        $threads = [];
        foreach (['left', 'right'] as $worker) {
            $owner = $round . '-' . $worker;
            $threads[$owner] = new Swoole\Thread(__FILE__, $peer['port'], $owner, $directory . '/' . $owner . '.json');
        }
        foreach ($threads as $owner => $thread) {
            $thread->join();
            $result = json_decode((string) file_get_contents($directory . '/' . $owner . '.json'), true, 512, JSON_THROW_ON_ERROR);
            $results[$owner] = ['exit' => $thread->getExitStatus(), 'result' => $result];
        }
        // 无论哪一个工作线程失败，都先回收本轮全部线程，避免关闭过程掩盖原始错误。
        foreach ($threads as $owner => $thread) {
            expect($results[$owner]['exit'] === 0 && $results[$owner]['result']['passed'], 'curl 线程请求或重建失败：' . $owner);
        }
    }
    $results['main'] = probeSwooleCurl($peer['port'], 'main');
    expect($results['main']['passed'], '主线程 curl 验收失败');
    file_put_contents($directory . '/results.json', json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    exit(0);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\BuildPlatform;
use Type\Testing\Process;

$root = BuildPlatform::resolve(dirname(__DIR__));
$directory = BuildPlatform::path($argv[1] ?? '');
expect($argc === 2 && BuildPlatform::contains($root . '/build', $directory) && !str_contains($directory, '..'), '需要 build 下的独立 curl 回归目录');
expect(!file_exists($directory) && mkdir($directory, 0700, true), 'curl 回归目录必须尚不存在');
$peer = new Process([(string) (getenv('NODE_BINARY') ?: 'node'), __DIR__ . '/fixtures/curl-peer.mjs', $directory . '/peer.json'], $directory);
$application = null;
try {
    $deadline = microtime(true) + 5;
    while (!is_file($directory . '/peer.json') && $peer->running() && microtime(true) < $deadline) {
        usleep(10000);
    }
    expect(is_file($directory . '/peer.json'), 'curl 独立对端未就绪：' . $peer->stderr());
    // 独立主线程对照采用另一进程；Event::wait 的退出会恢复进程级 hook，不能污染线程启动条件。
    foreach (['--main-probe' => 'standalone-execution.json', '--probe' => 'execution.json'] as $mode => $evidence) {
        $application = new Process([PHP_BINARY, __FILE__, $mode, $directory], $directory);
        $execution = $application->wait(20);
        file_put_contents($directory . '/' . $evidence, json_encode(['exit' => $execution->exitCode,
            'timed_out' => $execution->timedOut, 'stdout' => $execution->stdout, 'stderr' => $execution->stderr], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        expect($execution->successful(), '原生 curl 回归失败，见 ' . $directory . '/' . $evidence);
    }
    file_put_contents($directory . '/verification.json', json_encode(['passed' => true, 'platform' => PHP_OS_FAMILY,
        'swoole' => phpversion('swoole'), 'curl' => curl_version()['version'],
        'results' => json_decode((string) file_get_contents($directory . '/results.json'), true, 512, JSON_THROW_ON_ERROR)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "原生 curl 双线程、线程重建、双协程响应隔离及超时恢复通过。\n";
} finally {
    $application?->stop();
    $peer->stop();
}
