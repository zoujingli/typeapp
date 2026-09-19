<?php

declare(strict_types=1);

use Type\Testing\Process;

/** 复用独立消费者及真实存储；只在测试应用中延迟结果发送后的进程退出。 */
function mqttCommitLifecycleApplication(string $source): string
{
    $entry = "    if (\$arguments->has('store-worker')) {";
    expect(substr_count($source, $entry) === 1, '持久生命周期测试入口变化');
    $source = str_replace($entry, <<<'PHP'
    $lifecycleCase = (string) getenv('TYPE_MQTT_COMMIT_LIFECYCLE');
    if ($lifecycleCase !== '' && !$arguments->has('store-worker')) {
        mqttCommitLifecycle($workerCommand, $lifecycleCase);
        return;
    }
    if ($arguments->has('store-worker')) {
PHP, $source);
    $worker = "        PendingCommit::work(\$store, \$arguments->text('store-worker', ''));";
    expect(substr_count($source, $worker) === 1, '持久生命周期worker入口变化');
    $source = str_replace($worker, <<<'PHP'
        $lifecycle = (object) ['executed' => false, 'result' => []];
        PendingCommit::work($store, $arguments->text('store-worker', ''), function (array $request) use ($store, $lifecycle, $lifecycleCase): \Type\Mqtt\CommitResult {
            $lifecycle->executed = true;
            if ($lifecycleCase === 'cancel-before-result') {
                file_put_contents(getcwd() . '/lifecycle-ready.json', json_encode(['phase' => 'before', 'pid' => getmypid()], JSON_THROW_ON_ERROR));
                mqttLifecycleWait();
            }
            $result = $store->execute($request);
            $lifecycle->result = $result->data();
            return $result;
        });
        // cleanup 不进入业务回调，也不重复制造退出延迟。
        if ($lifecycleCase !== '' && $lifecycle->executed) {
            file_put_contents(getcwd() . '/lifecycle-ready.json', json_encode(['phase' => 'sent', 'pid' => getmypid(), 'result' => $lifecycle->result], JSON_THROW_ON_ERROR));
            mqttLifecycleWait();
        }
PHP, $source);
    return $source . <<<'PHP'

/** 测试进程保持存活，直到控制端放行；有独立硬截止以覆盖控制端异常。 */
function mqttLifecycleWait(): void
{
    $until = new \Type\Runtime\Deadline(8.0);
    while (!is_file(getcwd() . '/lifecycle-release') && !$until->expired()) {
        usleep(10000);
        clearstatcache();
    }
}

/** 编译控制端和子角色共同经过真实 IPC；结果到达与进程退出由两个独立门闩分开。 */
function mqttCommitLifecycle(array $command, string $case): void
{
    ini_set('zend.exception_ignore_args', '1');
    $operation = ['operation_id' => bin2hex(random_bytes(16)),
        'action' => $case === 'cancel-rejected' ? 'invalid-lifecycle-action' : 'session_statistics'];
    $pending = new PendingCommit($command, $operation, $case === 'timeout-confirmed' ? 2.0 : 5.0);
    $result = null;
    try {
        $ready = getcwd() . '/lifecycle-ready.json';
        $until = new \Type\Runtime\Deadline(4.0);
        while (!is_file($ready)) {
            $result = $pending->poll();
            if ($result !== null || $until->expired()) {
                throw new RuntimeException('持久生命周期门闩未到达');
            }
            usleep(10000);
            clearstatcache();
        }
        $sent = json_decode((string) file_get_contents($ready), true, 32, JSON_THROW_ON_ERROR);
        // 回应不足 64KiB，work 已完成发送并关闭 IPC；此时 worker 仍停在独立退出门闩。
        if ($pending->poll() !== null || $pending->poll() !== null) {
            throw new RuntimeException('持久结果在本地进程退出前提前释放');
        }
        if ($case === 'normal') {
            file_put_contents(getcwd() . '/lifecycle-release', 'release');
        } elseif ($case !== 'timeout-confirmed') {
            $pending->cancel();
            $pending->cancel();
        }
        do {
            $result = $pending->poll();
            if ($result === null) {
                usleep(10000);
            }
        } while ($result === null);
        $expected = $case === 'cancel-before-result' ? 'unknown' : ($case === 'cancel-rejected' ? 'rejected' : 'committed');
        if ($result->state !== $expected || !$result->released || $result->operationId !== $operation['operation_id']) {
            throw new RuntimeException($case . ': expected=' . $expected . ', actual=' . $result->state . ', released=' . ($result->released ? 'true' : 'false'));
        }
        if ($case !== 'cancel-before-result' && $result->data() !== $sent['result']) {
            throw new RuntimeException('取消或截止改写了已经收到的持久证明');
        }
        if ($pending->poll() !== $result) {
            throw new RuntimeException('终态重复轮询不稳定');
        }
        echo json_encode(['case' => $case, 'result' => $result->data(), 'worker_pid' => $sent['pid'], 'early_release' => false], JSON_THROW_ON_ERROR), "\n";
    } finally {
        file_put_contents(getcwd() . '/lifecycle-release', 'release');
        if ($result === null) {
            $pending->cancel();
            do {
                $result = $pending->poll();
                if ($result === null) {
                    usleep(10000);
                }
            } while ($result === null);
        }
    }
}
PHP;
}

/** 同一候选分别验证正常退出、已确认后的取消/截止、拒绝与未确认取消。 */
function mqttCommitLifecycleCases(string $consumer, array $command, array $environment): array
{
    $report = [];
    foreach (['normal', 'cancel-confirmed', 'timeout-confirmed', 'cancel-rejected', 'cancel-before-result'] as $case) {
        $directory = $consumer . '/lifecycle-' . $case;
        expect(mkdir($directory, 0700), '无法创建生命周期隔离目录');
        $caseCommand = $command;
        // PHP 对照入口原先相对于消费者；原生入口仍使用同一个搬迁产物。
        if ($command[0] === PHP_BINARY) {
            $caseCommand = [PHP_BINARY, '-r', 'require $argv[1] . "/vendor/autoload.php"; require $argv[1] . "/app/main.php"; main(1, [$argv[0]]);', '--', $consumer];
        }
        $caseEnvironment = array_replace($environment, ['TYPE_MQTT_COMMIT_LIFECYCLE' => $case]);
        if ($command[0] === PHP_BINARY) {
            $caseEnvironment['MQTT_WORKER_COMMAND'] = json_encode([PHP_BINARY, '-r',
                'require $argv[1] . "/vendor/autoload.php"; require $argv[1] . "/app/main.php"; main($argc - 1, array_merge([$argv[0]], array_slice($argv, 2)));', '--', $consumer], JSON_THROW_ON_ERROR);
        }
        $process = new Process($caseCommand, $directory, $caseEnvironment);
        try {
            $result = $process->wait(20);
            file_put_contents($directory . '/controller.json', json_encode(['exit' => $result->exitCode, 'timeout' => $result->timedOut,
                'stdout' => $result->stdout, 'stderr' => $result->stderr], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            expect($result->successful() && $result->stderr === '', '持久生命周期失败：' . $case . ': exit=' . $result->exitCode . ' ' . $result->stdout . $result->stderr);
            $report[$case] = json_decode(trim($result->stdout), true, 32, JSON_THROW_ON_ERROR);
            if (function_exists('posix_kill')) {
                expect(!@posix_kill($report[$case]['worker_pid'], 0), '持久终态返回后子进程仍存活');
            }
        } finally {
            $process->stop();
        }
    }
    return $report;
}
