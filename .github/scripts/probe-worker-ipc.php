<?php

declare(strict_types=1);

// 无数据库及秘密的真实进程探针；只验证锁定 SDK 的本地 IPC，不代替应用原生验收。
$root = dirname(__DIR__, 2);
require $root . '/plugin/type-runtime/src/TaskException.php';
require $root . '/plugin/type-runtime/src/CoroutineRuntime.php';

\Type\Runtime\CoroutineRuntime::enableIo();
if (($argv[1] ?? '') === '--worker') {
    \Type\Runtime\CoroutineRuntime::run(static function (): void {
        \Swoole\Coroutine::sleep(0.75);
        $socket = new \Swoole\Coroutine\Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);
        try {
            if (!$socket->connect('127.0.0.1', (int) getenv('TYPE_IPC_PORT'), 1.0)) {
                throw new RuntimeException('worker connect failed');
            }
            $hello = (string) getenv('TYPE_IPC_TOKEN') . "\n";
            if ($socket->sendAll($hello, 1.0) !== strlen($hello)) {
                throw new RuntimeException('worker identity send failed');
            }
            $input = $socket->recvLine(397307, 2.0);
            if (!is_string($input) || strlen($input) !== 397305 || !str_ends_with($input, "\n")) {
                throw new RuntimeException('worker request incomplete');
            }
            $output = hash('sha256', $input) . "\n" . str_repeat('b', 524288) . "\n";
            if ($socket->sendAll($output, 2.0) !== strlen($output)) {
                throw new RuntimeException('worker response incomplete');
            }
        } finally {
            $socket->close();
        }
    });
    exit(0);
}

$report = \Type\Runtime\CoroutineRuntime::run(static function (): array {
    $payload = str_repeat('a', 397304) . "\n";
    $token = bin2hex(random_bytes(32));
    $listener = new \Swoole\Coroutine\Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);
    if (!$listener->bind('127.0.0.1', 0) || !$listener->listen(1)) {
        $listener->close();
        throw new RuntimeException('worker listener failed');
    }
    $address = $listener->getsockname();
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $log = dirname(__DIR__, 2) . '/build/worker-ipc-probe/worker.log';
    $process = proc_open(
        [PHP_BINARY, __FILE__, '--worker'],
        [0 => ['file', $null, 'rb'], 1 => ['file', $null, 'wb'], 2 => ['file', $log, 'wb']],
        $pipes,
        null,
        array_replace(getenv(), ['TYPE_IPC_PORT' => (string) $address['port'], 'TYPE_IPC_TOKEN' => $token])
    );
    if (!is_resource($process)) {
        $listener->close();
        throw new RuntimeException('socket IPC worker spawn failed');
    }
    $state = (object) ['progress' => 0, 'finished' => false];
    $started = hrtime(true);
    $peer = null;
    $output = '';
    $error = null;
    $written = 0;
    $authenticated = false;
    $status = null;
    try {
        \Swoole\Coroutine::create(static function () use ($state): void {
            while (!$state->finished) {
                \Swoole\Coroutine::sleep(0.01);
                ++$state->progress;
            }
        });
        $accepted = $listener->accept(2.0);
        if ($accepted === false) {
            throw new RuntimeException('socket IPC accept failed');
        }
        $peer = $accepted;
        $identity = $peer->recvLine(65, 1.0);
        $authenticated = is_string($identity) && hash_equals($token . "\n", $identity);
        if (!$authenticated) {
            throw new RuntimeException('socket IPC worker identity invalid');
        }
        $written = $peer->sendAll($payload, 2.0);
        if ($written !== strlen($payload)) {
            throw new RuntimeException('socket IPC request incomplete');
        }
        $response = $peer->recvAll(64 + 1 + 524288 + 1, 2.0);
        $output = is_string($response) ? $response : '';
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(1000);
        } while ((hrtime(true) - $started) / 1e9 < 5.0);
    } catch (Throwable $failure) {
        $error = $failure->getMessage();
    } finally {
        $state->finished = true;
        if ($status === null || $status['running']) {
            $status = proc_get_status($process);
        }
        if ($status['running']) {
            proc_terminate($process, 9);
        }
        $peer?->close();
        $listener->close();
        proc_close($process);
    }
    $expected = hash('sha256', $payload) . "\n" . str_repeat('b', 524288) . "\n";
    return ['passed' => $error === null && $state->progress >= 10 && $authenticated && $output === $expected
        && !$status['running'] && $status['exitcode'] === 0,
        'php' => PHP_VERSION, 'swoole' => phpversion('swoole'), 'mechanism' => 'Swoole Coroutine Socket',
        'error' => $error, 'coroutine_progress' => $state->progress, 'authenticated' => $authenticated,
        'request_bytes' => strlen($payload), 'written_bytes' => $written, 'response_bytes' => strlen($output),
        'response_sha256' => hash('sha256', $output), 'expected_sha256' => hash('sha256', $expected),
        'seconds' => (hrtime(true) - $started) / 1e9, 'worker_running' => $status['running'], 'worker_exit' => $status['exitcode']];
});
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
exit($report['passed'] ? 0 : 1);
