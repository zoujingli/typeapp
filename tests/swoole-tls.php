<?php

declare(strict_types=1);

/**
 * 直接观察 Swoole TLS 的超时、写半关闭与读 EOF；完整 AOT 仍由 TCP 消费者验收。
 *
 * @param array{tls:array{port:int},tlsReset:array{port:int},certificate:string} $peers 独立 Node TLS 对端。
 * @return array{passed:bool,cases:array,coroutines:int}
 */
function probeSwooleTls(array $peers): array
{
    Swoole\Coroutine::set(['hook_flags' => 0]);
    $cases = [];
    Swoole\Coroutine::create(static function () use ($peers, &$cases): void {
        foreach (['direct', 'after-timeout', 'abrupt-close'] as $mode) {
            $events = [];
            $passed = false;
            $socket = new Swoole\Coroutine\Socket(AF_INET, SOCK_STREAM, 0);
            // 仅保存类型、长度和摘要；操作后立即采样 errno，避免后续调用覆盖故障证据。
            $record = static function (string $operation, mixed $value) use ($socket, &$events): mixed {
                $events[] = ['operation' => $operation, 'value' => is_string($value) ? null : $value,
                    'type' => get_debug_type($value), 'bytes' => is_string($value) ? strlen($value) : null,
                    'sha256' => is_string($value) ? hash('sha256', $value) : null,
                    'error' => $socket->errCode, 'message' => $socket->errMsg];
                return $value;
            };
            try {
                if (!$record('protocol', $socket->setProtocol(['open_ssl' => true, 'ssl_verify_peer' => true,
                    'ssl_cafile' => $peers['certificate'], 'ssl_host_name' => 'localhost']))
                    || !$record('connect', $socket->connect('localhost', $peers[$mode === 'abrupt-close' ? 'tlsReset' : 'tls']['port'], 1.0))) {
                    throw new RuntimeException('TLS 连接失败');
                }
                $payload = "\x00\xff\x80\r\n";
                if ($record('send', $socket->sendAll($payload, 1.0)) !== strlen($payload)) {
                    throw new RuntimeException('TLS 发送失败');
                }
                if ($mode === 'abrupt-close') {
                    $passed = $record('abrupt-close', $socket->recv(1024, 1.0)) === false && $socket->errCode !== 0;
                    continue;
                }
                if ($record('echo', $socket->recvAll(strlen($payload), 1.0)) !== $payload) {
                    throw new RuntimeException('TLS 回声失败');
                }
                if ($mode === 'after-timeout' && ($record('timeout', $socket->recv(1024, 0.02)) !== false || $socket->errCode === 0)) {
                    throw new RuntimeException('TLS 等待没有按约定超时');
                }
                if (!$record('shutdown-write', $socket->shutdown(SHUT_WR))) {
                    throw new RuntimeException('TLS 写半关闭失败');
                }
                $first = $record('eof-first', $socket->recv(1024, 1.0));
                $second = $record('eof-repeat', $socket->recv(1024, 1.0));
                $passed = $first === '' && $second === '';
            } catch (Throwable $error) {
                $events[] = ['failure' => get_class($error) . ': ' . $error->getMessage()];
            } finally {
                $record('close', $socket->close());
                $cases[$mode] = ['passed' => $passed, 'events' => $events];
            }
        }
    });
    Swoole\Event::wait();
    $coroutines = Swoole\Coroutine::stats()['coroutine_num'];
    return ['passed' => $cases['direct']['passed'] && $cases['after-timeout']['passed'] && $cases['abrupt-close']['passed'] && $coroutines === 0,
        'cases' => $cases, 'coroutines' => $coroutines];
}

/**
 * 对不发送 TLS 数据的真实 TCP 对端验证握手截止；父进程另设硬截止防止诊断自身挂起。
 *
 * @param array{idle:array{port:int}} $peers 独立 Node 对端。
 * @return array{passed:bool,events:array,coroutines:int}
 */
function probeSwooleTlsDeadline(array $peers, bool $cancel, string $path): array
{
    Swoole\Coroutine::set(['hook_flags' => 0]);
    $events = [];
    $passed = false;
    $record = static function (array $event) use ($path, &$events): void {
        $events[] = $event;
        file_put_contents($path . '.checkpoint.json', json_encode($events, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    };
    Swoole\Coroutine::create(static function () use ($peers, $cancel, $record, &$passed): void {
        $socket = new Swoole\Coroutine\Socket(AF_INET, SOCK_STREAM, 0);
        // connect 的单次参数只设置 TCP 连接期限；TLS 的 BIO 读写使用自己的原生期限。
        $protocol = $socket->setProtocol(['open_ssl' => true, 'ssl_verify_peer' => true, 'ssl_host_name' => 'localhost']);
        $read = $socket->setOption(SOL_SOCKET, SO_RCVTIMEO, ['sec' => 0, 'usec' => 150000]);
        $write = $socket->setOption(SOL_SOCKET, SO_SNDTIMEO, ['sec' => 0, 'usec' => 150000]);
        $record(['operation' => 'configuration', 'protocol' => $protocol, 'read_timeout' => $read, 'write_timeout' => $write]);
        $timer = false;
        if ($cancel) {
            $cid = Swoole\Coroutine::getCid();
            $timer = Swoole\Timer::after(20, static function () use ($cid, $record): void {
                $record(['operation' => 'cancel']);
                $record(['operation' => 'cancel-return', 'value' => Swoole\Coroutine::cancel($cid)]);
            });
        }
        try {
            $record(['operation' => 'connect', 'cancel' => $cancel]);
            $began = hrtime(true);
            $value = $socket->connect('127.0.0.1', $peers['idle']['port'], 0.15);
            $seconds = (hrtime(true) - $began) / 1e9;
            $record(['operation' => 'connect-return', 'value' => $value, 'error' => $socket->errCode,
                'message' => $socket->errMsg, 'seconds' => $seconds]);
            $passed = $protocol && $read && $write && $value === false
                && $socket->errCode === ($cancel ? SOCKET_ECANCELED : SOCKET_ETIMEDOUT) && $seconds < 0.5;
        } finally {
            if ($timer !== false && Swoole\Timer::exists($timer)) {
                Swoole\Timer::clear($timer);
            }
            $record(['operation' => 'close', 'value' => $socket->close()]);
        }
    });
    Swoole\Event::wait();
    $coroutines = Swoole\Coroutine::stats()['coroutine_num'];
    return ['passed' => $passed && $coroutines === 0, 'events' => $events, 'coroutines' => $coroutines];
}

/**
 * 在相同对端复核框架启动截止和停止；与原生探针分开记录，不将 PHP 结果当作 AOT 验收。
 *
 * @param array{idle:array{port:int}} $peers 独立 Node 对端。
 * @return array{passed:bool,events:array,coroutines:int}
 */
function probeFrameworkTlsDeadline(array $peers, bool $cancel, string $path): array
{
    $events = [];
    $passed = false;
    $record = static function (array $event) use ($path, &$events): void {
        $events[] = $event;
        file_put_contents($path . '.checkpoint.json', json_encode($events, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    };
    Swoole\Coroutine::create(static function () use ($peers, $cancel, $record, &$passed): void {
        $budget = new Type\Runtime\ResourceBudget(1);
        $socket = Type\Core\TcpSocket::client($budget, '127.0.0.1', $peers['idle']['port'], 1024, ['open_ssl' => true]);
        $timer = false;
        if ($cancel) {
            $timer = Swoole\Timer::after(20, static function () use ($socket, $record): void {
                $record(['operation' => 'stop']);
                $socket->stop();
                $record(['operation' => 'stop-return']);
            });
        }
        $began = hrtime(true);
        try {
            $record(['operation' => 'start', 'cancel' => $cancel]);
            $socket->start(0.15);
            $record(['operation' => 'unexpected-start-success']);
        } catch (Type\Runtime\TaskException $error) {
            $seconds = (hrtime(true) - $began) / 1e9;
            $record(['operation' => 'rejected', 'reason' => $error->errorCode(), 'seconds' => $seconds]);
            $passed = $error->errorCode() === ($cancel ? 'tcp_stopped' : 'tcp_timeout') && $seconds < 0.5;
        } finally {
            if ($timer !== false && Swoole\Timer::exists($timer)) {
                Swoole\Timer::clear($timer);
            }
            $record(['operation' => 'cleanup']);
            $socket->stop();
            $socket->awaitClosed(1);
            $record(['operation' => 'closed', 'statistics' => $socket->statistics()]);
            $passed = $passed && $budget->statistics()['allocated'] === 0;
        }
    });
    Swoole\Event::wait();
    $coroutines = Swoole\Coroutine::stats()['coroutine_num'];
    return ['passed' => $passed && $coroutines === 0, 'events' => $events, 'coroutines' => $coroutines];
}

$arguments = Swoole\Thread::getArguments();
if (is_array($arguments)) {
    if (in_array($arguments[0], ['deadline', 'framework'], true)) {
        require dirname(__DIR__) . '/vendor/autoload.php';
        require dirname(__DIR__) . '/vendor/swoole/typephp/src/polyfills.php';
        $peers = json_decode($arguments[1], true, 512, JSON_THROW_ON_ERROR);
        $result = $arguments[0] === 'framework' ? probeFrameworkTlsDeadline($peers, $arguments[2], $arguments[3])
            : probeSwooleTlsDeadline($peers, $arguments[2], $arguments[3]);
        file_put_contents($arguments[3], json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        exit($result['passed'] ? 0 : 1);
    }
    [$peers, $path] = $arguments;
    $result = probeSwooleTls(json_decode($peers, true, 512, JSON_THROW_ON_ERROR));
    file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    exit($result['passed'] ? 0 : 1);
}

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\BuildPlatform;
use Type\Testing\Process;

if (($argv[1] ?? '') === '--deadline') {
    expect($argc === 6, 'TLS 截止子进程参数无效');
    $peers = (string) file_get_contents($argv[4]);
    $framework = str_starts_with($argv[2], 'framework-');
    $cancel = str_ends_with($argv[2], 'cancel');
    if ($framework) {
        require dirname(__DIR__) . '/vendor/swoole/typephp/src/polyfills.php';
        Type\Runtime\CoroutineRuntime::enableIo();
    }
    if ($argv[3] === 'thread') {
        $thread = new Swoole\Thread(__FILE__, $framework ? 'framework' : 'deadline', $peers, $cancel, $argv[5]);
        $thread->join();
        exit($thread->getExitStatus());
    }
    $decodedPeers = json_decode($peers, true, 512, JSON_THROW_ON_ERROR);
    $result = $framework ? probeFrameworkTlsDeadline($decodedPeers, $cancel, $argv[5])
        : probeSwooleTlsDeadline($decodedPeers, $cancel, $argv[5]);
    file_put_contents($argv[5], json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    exit($result['passed'] ? 0 : 1);
}

$root = BuildPlatform::resolve(dirname(__DIR__));
$directory = BuildPlatform::path($argv[1] ?? '');
expect($argc === 2 && BuildPlatform::contains($root . '/build', $directory) && !str_contains($directory, '..'), '需要 build 下的专用 TLS 验证目录');
expect(!file_exists($directory) && mkdir($directory, 0700, true), 'TLS 验证目录必须尚不存在');
$peer = null;
try {
    $configuration = $directory . '/certificate.cnf';
    file_put_contents($configuration, "[req]\ndistinguished_name=dn\nx509_extensions=server\n[dn]\n[server]\nsubjectAltName=DNS:localhost\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\n");
    $options = ['config' => $configuration, 'private_key_bits' => 2048, 'digest_alg' => 'sha256', 'x509_extensions' => 'server'];
    $key = openssl_pkey_new($options);
    $request = openssl_csr_new(['commonName' => 'localhost'], $key, $options);
    $certificate = openssl_csr_sign($request, null, $key, 1, $options);
    expect($certificate !== false && openssl_x509_export($certificate, $pem) && openssl_pkey_export($key, $private), '无法生成 TLS 探针证书');
    file_put_contents($directory . '/certificate.pem', $pem);
    file_put_contents($directory . '/key.pem', $private);
    chmod($directory . '/key.pem', 0600);
    $peer = new Process([(string) (getenv('NODE_BINARY') ?: 'node'), __DIR__ . '/fixtures/tcp-peer.mjs', $directory . '/peers.json'], $directory);
    $deadline = microtime(true) + 5;
    while (!is_file($directory . '/peers.json') && $peer->running() && microtime(true) < $deadline) {
        usleep(10000);
    }
    expect(is_file($directory . '/peers.json'), 'TLS 对端未就绪：' . $peer->stderr());
    $peers = json_decode((string) file_get_contents($directory . '/peers.json'), true, 512, JSON_THROW_ON_ERROR);
    $results = [];
    foreach (['first', 'restart'] as $role) {
        $path = $directory . '/' . $role . '.json';
        $thread = new Swoole\Thread(__FILE__, json_encode($peers, JSON_THROW_ON_ERROR), $path);
        $thread->join();
        $results[$role] = ['exit' => $thread->getExitStatus(), 'result' => json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)];
    }
    $results['main'] = probeSwooleTls($peers);
    $passed = $results['first']['exit'] === 0 && $results['restart']['exit'] === 0 && $results['main']['passed'];
    $deadlines = [];
    foreach (['main', 'thread'] as $role) {
        foreach (['timeout', 'cancel', 'framework-timeout', 'framework-cancel'] as $mode) {
            $path = $directory . '/' . $role . '-' . $mode . '.json';
            $process = new Process([PHP_BINARY, __FILE__, '--deadline', $mode, $role, $directory . '/peers.json', $path], $directory);
            try {
                $execution = $process->wait(3);
                $deadlines[$role . '-' . $mode] = ['exit' => $execution->exitCode, 'timed_out' => $execution->timedOut,
                    'stdout' => $execution->stdout, 'stderr' => $execution->stderr,
                    'result' => is_file($path) ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : null,
                    'checkpoint' => is_file($path . '.checkpoint.json') ? json_decode((string) file_get_contents($path . '.checkpoint.json'), true, 512, JSON_THROW_ON_ERROR) : null];
                $passed = $passed && $execution->successful() && ($deadlines[$role . '-' . $mode]['result']['passed'] ?? false);
            } finally {
                $process->stop();
            }
        }
    }
    file_put_contents($directory . '/verification.json', json_encode(['passed' => $passed, 'swoole' => phpversion('swoole'),
        'results' => $results, 'deadlines' => $deadlines], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    expect($passed, 'TLS EOF、错误与握手截止必须保持明确语义；见 ' . $directory . '/verification.json');
    echo "原生 TLS 半关闭、错误、握手截止与线程重建验证通过。\n";
} finally {
    $peer?->stop();
    if (is_file($directory . '/key.pem')) {
        expect(unlink($directory . '/key.pem'), '无法回收 TLS 探针私钥');
    }
}
