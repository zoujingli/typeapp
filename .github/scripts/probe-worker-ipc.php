<?php

declare(strict_types=1);

// 使用生产 IPC 的真实进程探针；验证大消息、协程推进与双向身份拒绝，不代替应用原生验收。
$root = dirname(__DIR__, 2);
require $root . '/plugin/type-runtime/src/TaskException.php';
require $root . '/plugin/type-runtime/src/CoroutineRuntime.php';
require $root . '/plugin/type-mqtt/src/WorkerSocket.php';

\Type\Runtime\CoroutineRuntime::enableIo();
if (($argv[1] ?? '') === '--worker') {
    try {
        \Type\Runtime\CoroutineRuntime::run(static function (): void {
            \Swoole\Coroutine::sleep(0.75);
            $socket = \Type\Mqtt\WorkerSocket::connect();
            try {
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
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(7);
    }
    exit(0);
}

/** 业务字节只能在双方证明身份后交换，进程退出与缓冲排空分别观察。 */
function probeWorkerSocket(string $case): array
{
    $payload = str_repeat('a', 397304) . "\n";
    $token = bin2hex(random_bytes(32));
    $channel = new \Type\Mqtt\WorkerSocket($token);
    $environment = array_replace(getenv(), $channel->environment());
    $listener = null;
    if ($case === 'invalid-worker') {
        $environment['MQTT_WORKER_TOKEN'] = bin2hex(random_bytes(32));
    } elseif ($case === 'forged-parent') {
        $listener = new \Swoole\Coroutine\Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);
        if (!$listener->bind('127.0.0.1', 0) || !$listener->listen(1)) {
            $listener->close();
            $channel->close();
            throw new RuntimeException('forged listener failed');
        }
        $environment['MQTT_WORKER_PORT'] = (string) $listener->getsockname()['port'];
    }
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $log = dirname(__DIR__, 2) . '/build/worker-ipc-probe/' . $case . '.log';
    $process = proc_open(
        [PHP_BINARY, __FILE__, '--worker'],
        [0 => ['file', $null, 'rb'], 1 => ['file', $null, 'wb'], 2 => ['file', $log, 'wb']],
        $pipes,
        null,
        $environment
    );
    if (!is_resource($process)) {
        $listener?->close();
        $channel->close();
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
    $remaining = $payload;
    $expected = hash('sha256', $payload) . "\n" . str_repeat('b', 524288) . "\n";
    try {
        \Swoole\Coroutine::create(static function () use ($state): void {
            while (!$state->finished) {
                \Swoole\Coroutine::sleep(0.01);
                ++$state->progress;
            }
        });
        if ($listener !== null) {
            $accepted = $listener->accept(2.0);
            if ($accepted === false) {
                throw new RuntimeException('forged parent accept failed');
            }
            $peer = $accepted;
            // 把 worker 的正确证明原样反射回去，也不能冒充父进程。
            $identity = $peer->recvLine(65, 1.0);
            if (!is_string($identity) || $peer->sendAll($identity, 1.0) !== strlen($identity)) {
                throw new RuntimeException('forged parent response failed');
            }
        }
        do {
            if ($remaining !== '') {
                $bytes = $channel->write(substr($remaining, 0, 16384), 0.001);
                if ($bytes === false) {
                    throw new RuntimeException('socket IPC request failed');
                }
                $written += $bytes;
                $remaining = substr($remaining, $bytes);
            }
            $response = $channel->read(262144, 0.001);
            if (is_string($response)) {
                $output .= $response;
            }
            if (strlen($output) > strlen($expected)) {
                throw new RuntimeException('socket IPC response exceeds limit');
            }
            if ($status === null || $status['running']) {
                $status = proc_get_status($process);
            }
            if (!$status['running'] && ($case !== 'normal' || strlen($output) === strlen($expected))) {
                break;
            }
            usleep(1000);
        } while ((hrtime(true) - $started) / 1e9 < 5.0);
        $authenticated = $channel->authenticated();
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
        $listener?->close();
        $channel->close();
        proc_close($process);
    }
    $passed = $error === null && $state->progress >= 10 && !$status['running'] && ($case === 'normal'
        ? ($authenticated && $remaining === '' && $output === $expected && $status['exitcode'] === 0)
        : (!$authenticated && $written === 0 && $output === '' && $status['exitcode'] === 7));
    return ['passed' => $passed, 'case' => $case,
        'php' => PHP_VERSION, 'swoole' => phpversion('swoole'), 'mechanism' => 'Swoole Coroutine Socket',
        'error' => $error, 'coroutine_progress' => $state->progress, 'authenticated' => $authenticated,
        'request_bytes' => strlen($payload), 'written_bytes' => $written, 'response_bytes' => strlen($output),
        'response_sha256' => hash('sha256', $output), 'expected_sha256' => hash('sha256', $expected),
        'seconds' => (hrtime(true) - $started) / 1e9, 'worker_running' => $status['running'], 'worker_exit' => $status['exitcode']];
}

$cases = \Type\Runtime\CoroutineRuntime::run(static function (): array {
    $results = [];
    foreach (['normal', 'invalid-worker', 'forged-parent'] as $case) {
        $results[$case] = probeWorkerSocket($case);
    }
    return $results;
});
$report = ['passed' => count(array_filter($cases, static fn (array $case): bool => !$case['passed'])) === 0, 'cases' => $cases];
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
exit($report['passed'] ? 0 : 1);
