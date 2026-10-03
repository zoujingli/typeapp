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

$arguments = Swoole\Thread::getArguments();
if (is_array($arguments)) {
    [$peers, $path] = $arguments;
    $result = probeSwooleTls(json_decode($peers, true, 512, JSON_THROW_ON_ERROR));
    file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    exit($result['passed'] ? 0 : 1);
}

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\BuildPlatform;
use Type\Testing\Process;

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
    file_put_contents($directory . '/verification.json', json_encode(['passed' => $passed, 'swoole' => phpversion('swoole'),
        'results' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    expect($passed, 'TLS 写半关闭后必须读到稳定 EOF；见 ' . $directory . '/verification.json');
    echo "原生 TLS 半关闭、超时恢复与线程重建验证通过。\n";
} finally {
    $peer?->stop();
    if (is_file($directory . '/key.pem')) {
        expect(unlink($directory . '/key.pem'), '无法回收 TLS 探针私钥');
    }
}
