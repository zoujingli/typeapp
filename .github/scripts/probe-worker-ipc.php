<?php

declare(strict_types=1);

// 无数据库及秘密的真实进程探针；仅验证锁定 Windows SDK 的本地 IPC，不代替应用原生验收。
$root = dirname(__DIR__, 2);
require $root . '/plugin/type-runtime/src/TaskException.php';
require $root . '/plugin/type-runtime/src/CoroutineRuntime.php';

\Type\Runtime\CoroutineRuntime::enableIo();
$report = \Type\Runtime\CoroutineRuntime::run(static function (): array {
    $payload = str_repeat('a', 397304) . "\n";
    $worker = 'usleep(750000); echo "ready\n"; $s=fgets(STDIN, 397307); '
        . 'if (!is_string($s) || !str_ends_with($s, "\n")) { exit(2); } '
        . 'echo hash("sha256", $s), "\n", str_repeat("b", 524288), "\n";';
    $process = proc_open(
        [PHP_BINARY, '-n', '-r', $worker],
        [0 => ['socket'], 1 => ['socket'], 2 => ['redirect', 1]],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('socket IPC worker spawn failed');
    }
    $state = (object) ['progress' => 0, 'finished' => false];
    $started = hrtime(true);
    try {
        $nonblocking = [];
        foreach ($pipes as $pipe) {
            $nonblocking[] = stream_set_blocking($pipe, false);
        }
        \Swoole\Coroutine::create(static function () use ($state): void {
            while (!$state->finished) {
                \Swoole\Coroutine::sleep(0.01);
                ++$state->progress;
            }
        });
        $firstStarted = hrtime(true);
        $output = fread($pipes[1], 16);
        $firstSeconds = (hrtime(true) - $firstStarted) / 1e9;
        // 与实际调用者一致：非阻塞读取的空值不视作业务结果，完整性由最终字节与退出状态证明。
        $falseReads = $output === false ? 1 : 0;
        $output = is_string($output) ? $output : '';
        $input = '';
        $authenticated = false;
        $maximumReadSeconds = $firstSeconds;
        do {
            $readStarted = hrtime(true);
            $chunk = fread($pipes[1], 262144);
            $maximumReadSeconds = max($maximumReadSeconds, (hrtime(true) - $readStarted) / 1e9);
            if ($chunk === false) {
                ++$falseReads;
            }
            $output .= is_string($chunk) ? $chunk : '';
            if (!$authenticated && str_starts_with($output, "ready\n")) {
                $authenticated = true;
                $output = substr($output, 6);
                $input = $payload;
            }
            if ($input !== '') {
                $written = fwrite($pipes[0], substr($input, 0, 16384));
                if ($written === false) {
                    throw new RuntimeException('socket IPC write failed');
                }
                $input = substr($input, $written);
            }
            $status = proc_get_status($process);
            if (!$status['running'] && strlen($output) === 64 + 1 + 524288 + 1) {
                break;
            }
            usleep(1000);
        } while ((hrtime(true) - $started) / 1e9 < 5.0);
        $expected = hash('sha256', $payload) . "\n" . str_repeat('b', 524288) . "\n";
        $passed = $nonblocking === [true, true] && $firstSeconds < 0.1 && $maximumReadSeconds < 0.1
            && $state->progress >= 10 && $authenticated && $input === '' && $output === $expected && !$status['running'];
        return ['passed' => $passed, 'php' => PHP_VERSION, 'swoole' => phpversion('swoole'),
            'hook_flags' => \Swoole\Runtime::getHookFlags(), 'nonblocking_accepted' => $nonblocking,
            'first_read_seconds' => $firstSeconds, 'maximum_read_seconds' => $maximumReadSeconds,
            'false_reads' => $falseReads, 'read_metadata' => stream_get_meta_data($pipes[1]),
            'coroutine_progress' => $state->progress, 'request_bytes' => strlen($payload), 'response_bytes' => strlen($output),
            'response_sha256' => hash('sha256', $output), 'expected_sha256' => hash('sha256', $expected),
            'seconds' => (hrtime(true) - $started) / 1e9, 'worker_running' => $status['running']];
    } finally {
        $state->finished = true;
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
    }
});
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
exit($report['passed'] ? 0 : 1);
