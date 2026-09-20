<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
$consumer = $argv[1];
require $consumer . '/vendor/autoload.php';
$command = ($argv[2] ?? '') === '--native' ? nativeCommand($consumer . '/build/type-app') : [PHP_BINARY, $consumer . '/run.php'];

use Type\Redis\RedisConfiguration;
use Type\Queue\Queue;
use Type\Redis\Purpose;
use Type\Redis\RedisManager;
use Type\Runtime\ExecutionScope;
use Type\Scheduler\Definition;
use Type\Scheduler\FileStateStore;
use Type\Scheduler\IntervalSchedule;
use Type\Scheduler\Scheduler;
use Type\Scheduler\Task;
use Type\Scheduler\TaskContext;
use Psr\Clock\ClockInterface;

$schedulerStop = json_decode(successful([...$command, 'scheduler-stop']), true, 512, JSON_THROW_ON_ERROR);
expect($schedulerStop['stopping']['ready'] === false && $schedulerStop['stopping']['state'] === 'draining'
    && $schedulerStop['stopping']['in_flight'] === 1, '调度停止没有先撤销就绪并保留当前在途执行');
expect($schedulerStop['deadline_error'] === 'deadline_exceeded' && count($schedulerStop['results']) === 1
    && $schedulerStop['results'][0]['state'] === 'failed', '调度停止截止没有使原生在途任务明确失败');
expect(
    $schedulerStop['scheduler']['ready'] === false && $schedulerStop['scheduler']['state'] === 'stopped'
    && $schedulerStop['scheduler']['in_flight'] === 0 && $schedulerStop['scheduler']['triggered'] === 1
    && $schedulerStop['scheduler']['failed'] === 1 && $schedulerStop['scheduler']['drain_expired'] === true,
    '调度限时排空没有保留准确失败次数或已停止状态'
);
expect(
    $schedulerStop['next'] === [] && $schedulerStop['scheduler']['rejected'] === 1
    && count($schedulerStop['history']) === 1 && $schedulerStop['history'][0]['state'] === 'failed',
    '调度停止后继续补跑或没有持久化当前失败'
);
echo "独立命令调度停止验证通过：在途撤销就绪、截止失败、单次补跑、失败记录与后续拒绝。\n";
if (in_array('--scheduler-stop-only', $argv, true)) {
    exit(0);
}

\Type\Runtime\CoroutineRuntime::run(static function () use ($command): void {
    $port = getenv('TYPE_RELIABLE_PORT');
    expect($port === false || (ctype_digit($port) && (int) $port >= 1 && (int) $port <= 65535), '可靠存储端口无效');
    $configuration = new RedisConfiguration(getenv('TYPE_RELIABLE_HOST'), $port === false ? 6379 : (int) $port);
    $admin = $configuration->connect();
    $manager = new RedisManager(['default' => $configuration]);
    $scope = new ExecutionScope();
    try {
        foreach (['grace', 'slow', 'paused-pull', 'uncooperative'] as $mode) {
            $application = 'type-stop-' . $mode . '-' . bin2hex(random_bytes(5));
            putenv('TYPE_RELIABILITY_APP=' . $application);
            successful([...$command, 'prepare-stop']);
            $out = tmpfile();
            $err = tmpfile();
            $process = proc_open([...$command, $mode], [0 => ['file', '/dev/null', 'r'], 1 => $out, 2 => $err], $pipes);
            expect(is_resource($process), '无法启动真实 worker');
            try {
                $deadline = microtime(true) + 5;
                do {
                    if ($admin->get($application . ':started') === 'yes') {
                        break;
                    } usleep(10000);
                } while (microtime(true) < $deadline);
                expect($admin->get($application . ':started') === 'yes', 'worker 没有进入真实在途任务');
                if ($mode === 'paused-pull') {
                    $client = (string) $admin->get($application . ':client');
                    $blocked = false;
                    $deadline = microtime(true) + 2;
                    do {
                        $state = (string) $admin->rawCommand('CLIENT', 'LIST', 'ID', $client);
                        if (str_contains($state, 'cmd=eval ') && preg_match('/ flags=[^ ]*b/', $state)) {
                            $blocked = true;
                            break;
                        }
                        usleep(1000);
                    } while (microtime(true) < $deadline);
                    expect($blocked, '停止前未观察到实际暂停的队列读取：' . $state);
                }
                $started = microtime(true);
                proc_terminate($process, 15);
                $deadline = $started + ($mode === 'uncooperative' ? 0.6 : 2.0);
                do {
                    $state = proc_get_status($process);
                    if (!$state['running']) {
                        break;
                    }
                    usleep(10000);
                } while (microtime(true) < $deadline);
                if ($mode === 'uncooperative') {
                    expect($state['running'], '不合作任务没有覆盖监督进程排空超时路径');
                    proc_terminate($process, 9);
                    $deadline = microtime(true) + 2;
                    do {
                        $state = proc_get_status($process);
                        if (!$state['running']) {
                            break;
                        } usleep(10000);
                    } while (microtime(true) < $deadline);
                    expect(!$state['running'] && $state['signaled'] && $state['termsig'] === 9, '监督者没有在有界排空后真实终止旧 worker');
                } else {
                    expect(!$state['running'] && $state['exitcode'] === 0 && microtime(true) - $started < 1.5, '合作 worker 没有按有限排空预算退出');
                }
                rewind($out);
                rewind($err);
                $stdout = stream_get_contents($out);
                $stderr = stream_get_contents($err);
                expect($stderr === '', '停止进程出现未记录错误：' . $stderr);
                $rows = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), explode("\n", trim($stdout)));
                expect(
                    !$rows[0]['stopping']['ready'] && $rows[0]['stopping']['state'] === 'draining' && $rows[0]['stopping']['in_flight'] === 1,
                    '停止信号没有先撤销就绪并保留在途状态：' . $mode . ' ' . $stdout
                );
                if ($mode !== 'uncooperative') {
                    expect($rows[1]['worker']['received'] === ($mode === 'paused-pull' ? 0 : 1) && $rows[1]['worker']['state'] === 'stopped'
                        && $rows[1]['worker']['prefetch_limit'] === 1, '就绪取消后仍领取新消息');
                    if ($mode !== 'paused-pull') {
                        expect(
                            $rows[1]['worker']['rejected'] === 1 && ($mode === 'grace' ? $rows[1]['worker']['completed'] === 1 : $rows[1]['worker']['failed'] === 1),
                            '排空完成与超时失败没有区分'
                        );
                    }
                }
            } finally {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 9);
                }
                proc_close($process);
                fclose($out);
                fclose($err);
            }
            echo successful([...$command, 'recover-stop']);
        }
        $file = sys_get_temp_dir() . '/type-stop-scheduler-' . bin2hex(random_bytes(6)) . '.json';
        $clock = new class () implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('@600');
            }
        };
        $scheduler = null;
        $factory = static function (TaskContext $context) use (&$scheduler): Task {
            return new class ($scheduler) implements Task {
                public function __construct(private Scheduler $scheduler)
                {
                }
                public function run(TaskContext $context): array
                {
                    $this->scheduler->stop(0.01);
                    usleep(30000);
                    $context->assertActive();
                    return [];
                }
            };
        };
        try {
            $scheduler = new Scheduler($clock, new FileStateStore($file), [new Definition('stop', new IntervalSchedule(10), $factory, 'catch-up', 10, 100, 0)]);
            $results = $scheduler->tick();
            expect(
                count($results) === 1 && $results[0]['state'] === 'failed' && !$scheduler->ready() && $scheduler->tick() === [],
                'scheduler 停止后仍触发后续补跑或未限制在途执行'
            );
            expect($scheduler->statistics()['triggered'] === 1 && $scheduler->statistics()['failed'] === 1, '调度停止指标缺失');
        } finally {
            foreach ([$file, $file . '.lock'] as $generated) {
                if (is_file($generated)) {
                    unlink($generated);
                }
            }
        }
        echo "真实任务停止验证通过：先取消就绪、有限排空、监督终止、可恢复消息与停止补跑。\n";
    } finally {
        $scope->close();
        $manager->close();
        $admin->close();
        putenv('TYPE_RELIABILITY_APP');
    }
});
