<?php

declare(strict_types=1);

use Type\Log\Channel;
use Type\Log\LogManager;
use Type\Log\Output;
use Type\Queue\JobContext;
use Type\Queue\Message;
use Type\Queue\Queue;
use Type\Queue\Registry;
use Type\Queue\RetryPolicy;
use Type\Queue\Worker;
use Type\Redis\Purpose;
use Type\Redis\RedisConfiguration;
use Type\Redis\RedisException;
use Type\Redis\RedisManager;
use Type\Redis\StoragePolicy;
use Type\Runtime\ExecutionScope;
use Type\Scheduler\Definition;
use Type\Scheduler\FileStateStore;
use Type\Scheduler\IntervalSchedule;
use Type\Scheduler\RedisStateStore;
use Type\Scheduler\Scheduler;
use Type\Scheduler\Task;
use Type\Scheduler\TaskContext;
use TypeApp\Reliability\DurableJob;
use TypeApp\Reliability\DurableSchedule;
use TypeApp\Reliability\StoppingSchedule;
use TypeApp\SchedulerExample\ControlledClock;

/**
 * 将当前示例的行为断言转为明确失败，避免只输出成功文字而忽略实际状态。
 *
 * @throws \RuntimeException 条件不成立。
 */
function reliabilityExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** 使用显式端口连接受控实例，未配置时保持既有默认值。 */
function reliabilityPort(string $name): int
{
    $value = getenv($name);
    if ($value === false) {
        return 6379;
    }
    if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 65535) {
        throw new InvalidArgumentException($name . '必须是有效端口');
    }
    return (int) $value;
}

/** 同一编译入口的本地调度场景，不为停止验收建立无关的外部连接。 */
function reliabilitySchedulerStop(): void
{
    $filename = sys_get_temp_dir() . '/type-stop-scheduler-native-' . bin2hex(random_bytes(6)) . '.json';
    $task = new StoppingSchedule();
    $scheduler = new Scheduler(new ControlledClock('@600'), new FileStateStore($filename), [
        new Definition('stop', new IntervalSchedule(10), static fn (TaskContext $context): Task => $task, 'catch-up', 10, 100, 0),
    ]);
    $task->attach($scheduler);
    try {
        $results = $scheduler->tick();
        $next = $scheduler->tick();
        echo json_encode(['stopping' => $task->stopping(), 'deadline_error' => $task->deadlineError(),
            'results' => $results, 'scheduler' => $scheduler->statistics(), 'next' => $next, 'history' => $scheduler->history()], JSON_THROW_ON_ERROR) . PHP_EOL;
    } finally {
        foreach ([$filename, $filename . '.lock'] as $stateFile) {
            if (is_file($stateFile)) {
                unlink($stateFile);
            }
        }
    }
}

/**
 * 在协程入口内运行可靠 Redis、停止与恢复演练，故障模式由显式参数选择。
 *
 * @param list<string> $argv 程序路径与该示例的显式参数。
 */
function main(int $argc, array $argv): void
{
    \Type\Runtime\CoroutineRuntime::run(static function () use ($argc, $argv): void {
        taskReliabilityScenario($argc, $argv);
    });
}

/**
 * 在专属可靠 Redis 执行播种、恢复、写满或停止阶段，按真实副作用核对。
 *
 * @param list<string> $argv 程序路径与显式故障演练阶段。
 */
function taskReliabilityScenario(int $argc, array $argv): void
{
    $mode = (string) ($argv[1] ?? 'seed');
    if ($mode === 'scheduler-stop') {
        reliabilitySchedulerStop();
        return;
    }
    $reliablePort = reliabilityPort('TYPE_RELIABLE_PORT');
    $reliable = new RedisConfiguration(getenv('TYPE_RELIABLE_HOST') ?: '127.0.0.1', $reliablePort);
    $cache = new RedisConfiguration(getenv('TYPE_CACHE_HOST') ?: '127.0.0.2', reliabilityPort('TYPE_CACHE_PORT'));
    $policy = StoragePolicy::verify($reliable, $cache);
    reliabilityExpect($policy['isolated'] && $policy['reliable']['appendfsync'] === 'always', '可靠存储策略检查失败');
    $manager = new RedisManager(['queue' => $reliable, 'coordination' => $reliable], [Purpose::SCRIPT => 1, Purpose::BLOCKING => 1]);
    $scope = new ExecutionScope();
    $application = getenv('TYPE_RELIABILITY_APP') ?: 'durable-tasks';
    try {
        $redis = $manager->connection($scope, 'queue', Purpose::SCRIPT);
        $coordination = $manager->connection($scope, 'coordination', Purpose::SCRIPT);
        $ordinary = $manager->connection($scope, 'queue');
        $queue = new Queue($redis, $application, 'jobs', 300, 100);
        $registry = new Registry();
        $registry->register('durable', 1, static fn (JobContext $context): DurableJob => new DurableJob($application, $mode));
        $worker = new Worker($queue, $registry, 'worker-' . getmypid(), new RetryPolicy(5, 10, 10, 3000));
        $clock = new ControlledClock('2026-09-09T12:00:00Z');
        $scheduler = new Scheduler($clock, new RedisStateStore($coordination, $application), [
            new Definition(
                'durable.schedule',
                new IntervalSchedule(60),
                static fn (TaskContext $context): Task => new DurableSchedule($coordination, $application . ':schedule'),
                'catch-up',
                1000,
                600,
                0
            ),
        ], 100, 2);

        if ($mode === 'seed') {
            $same = false;
            try {
                StoragePolicy::verify($reliable, new RedisConfiguration(getenv('TYPE_RELIABLE_HOST') ?: '127.0.0.1', $reliablePort, 1));
            } catch (RedisException $error) {
                $same = $error->errorCode() === 'shared_failure_domain';
            }
            reliabilityExpect($same, '同实例的逻辑数据库被误认成故障域隔离');
            $queue->publish(new Message('persistent-one', 'durable', 1, []));
            $queue->publish(new Message('persistent-two', 'durable', 1, []));
            $queue->publishDelayed(new Message('persistent-delayed', 'durable', 1, []), 100);
            reliabilityExpect($queue->reserve('before-restart') !== null, '无法建立重启前在途任务');
            $first = $scheduler->tick();
            reliabilityExpect(count($first) === 2 && $scheduler->statistics()['limit_reached'] === 1, 'scheduler 全轮补跑没有有限上限');
            $scheduler->stop();
            reliabilityExpect(!$scheduler->ready() && $scheduler->tick() === [], '停止调度后仍触发新计划');
            echo "已写入 AOF：待执行、在途、延迟消息与调度高水位。\n";
            return;
        }
        if ($mode === 'recover') {
            reliabilityExpect(
                $queue->statistics()['messages'] === 2 && $queue->statistics()['leased'] === 1 && $queue->statistics()['delayed'] === 1,
                'Redis 重启丢失了消息、租约或延迟状态'
            );
            reliabilityExpect($queue->statistics()['oldest_stream_age_ms'] > 0 && $queue->statistics()['backlog'] === 3, '重启后积压或消息年龄缺失');
            // 原生重启可能早于300ms租约到期；轮询公开worker入口，不能提前窃取仍有效的租约。
            $recovered = 0;
            $until = microtime(true) + 5.0;
            do {
                $recovered += $worker->run(10);
                if ($queue->statistics()['backlog'] === 0) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $until);
            reliabilityExpect($recovered === 3 && $ordinary->command('GET', [$application . ':count']) === '3', '重启后不能恢复并完成原消息');
            reliabilityExpect($worker->statistics()['message_age_ms'] > 0 && $queue->statistics()['backlog'] === 0, '执行年龄或积压指标错误');
            $previous = $scheduler->history();
            reliabilityExpect(count($previous) === 2, '调度执行历史没有持久化');
            $nextOccurrences = $scheduler->tick();
            reliabilityExpect(count($nextOccurrences) === 2 && $nextOccurrences[0]['scheduled_at'] > $previous[1]['scheduled_at'], '调度重启重复旧 occurrence');
            echo "AOF 重启恢复通过：原消息、消息年龄、积压与调度游标均保留。\n";
            return;
        }
        if ($mode === 'pressure') {
            $queue->publish(new Message('pressure-one', 'durable', 1, []));
            $queue->publish(new Message('pressure-two', 'durable', 1, []));
            $cacheClient = $cache->connect();
            for ($index = 0; $index < 256; $index++) {
                $cacheClient->set('evict:' . $index, str_repeat('C', 65536));
            }
            reliabilityExpect(
                (int) $cacheClient->info('stats')['evicted_keys'] > 0 && $queue->statistics()['messages'] === 2,
                '缓存淘汰压力影响可靠消息或未产生真实淘汰'
            );
            $cacheClient->close();
            $admin = $reliable->connect();
            $filled = false;
            $keys = [];
            for ($index = 0; $index < 1000; $index++) {
                $key = 'pressure:' . $index;
                try {
                    $admin->set($key, str_repeat('R', 65536));
                    $keys[] = $key;
                } catch (\RedisException) {
                    $filled = true;
                    break;
                }
            }
            reliabilityExpect($filled, '没有将专属 noeviction Redis 写满');
            // 大块失败不代表小消息一定放不下；将本专属服务的上限降到当前占用以下，明确触发所有新写入拒绝。
            $admin->config('SET', 'maxmemory', '1');
            $failed = false;
            try {
                $queue->publish(new Message('must-not-succeed', 'durable', 1, []));
            } catch (Throwable) {
                $failed = true;
            }
            reliabilityExpect(
                $failed && $queue->counters()['publish_rejected'] === 1 && $queue->counters()['storage_failures'] === 1,
                'OOM 投递虚报成功或缺失拒绝计数'
            );
            try {
                $worker->runOnce();
            } catch (Throwable) {
            }
            reliabilityExpect(!$worker->ready() && $worker->statistics()['storage_failures'] === 1 && !$worker->runOnce(), '存储失败后仍继续领取');
            try {
                $scheduler->tick();
            } catch (Throwable) {
            }
            reliabilityExpect(
                !$scheduler->ready() && $scheduler->statistics()['storage_failures'] === 1 && $scheduler->tick() === [],
                '存储写满后 scheduler 仍继续触发'
            );
            foreach ($keys as $filledKey) {
                $admin->del($filledKey);
            }
            $admin->config('SET', 'maxmemory', '16mb');
            $admin->close();
            $redis->close();
            $queue = new Queue($manager->connection($scope, 'queue', Purpose::SCRIPT), $application, 'jobs', 300, 100);
            reliabilityExpect($queue->statistics()['messages'] === 2, 'noeviction 写满丢失已接受任务');
            $recoveredWorker = new Worker($queue, $registry, 'after-pressure');
            reliabilityExpect($recoveredWorker->run(2) === 2 && $queue->statistics()['messages'] === 0, '释放容量后新 worker 无法恢复');
            $coordination->close();
            $coordination = $manager->connection($scope, 'coordination', Purpose::SCRIPT);

            $blocked = $manager->connection($scope, 'queue', Purpose::BLOCKING);
            $limited = false;
            try {
                $manager->connection($scope, 'queue', Purpose::BLOCKING);
            } catch (Throwable) {
                $limited = true;
            }
            reliabilityExpect($limited && $coordination->script('return 1') === 1, '阻塞池容量挤占协调连接');
            $blocked->close();
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            reliabilityExpect($pair !== false, '无法建立日志故障管道');
            stream_set_blocking($pair[0], false);
            for ($index = 0; $index < 8192; $index++) {
                if (fwrite($pair[0], str_repeat('F', 4096)) === 0) {
                    break;
                }
            }
            $output = Output::stream($pair[0], 3, 2048, 1024);
            $logs = new LogManager('reliability-test', ['app' => new Channel($output)], 0.02);
            $logger = $logs->logger($scope);
            for ($index = 0; $index < 100; $index++) {
                $logger->warning('存储压力', ['attempt' => $index]);
            }
            reliabilityExpect($output->stats()['pending_records'] === 3 && $output->stats()['dropped_full'] === 97
                && $coordination->script('return 2') === 2, '日志故障突破容量或阻塞协调');
            $started = microtime(true);
            $logs->stop();
            reliabilityExpect(microtime(true) - $started < 0.5 && $output->stats()['dropped_stop'] === 3, '日志没有在停止预算内收尾');
            fclose($pair[0]);
            fclose($pair[1]);
            echo "存储写满与隔离通过：投递拒绝、停止领取、容量恢复、日志和协调连接有界。\n";
            return;
        }
        if ($mode === 'prepare-stop') {
            $queue->publish(new Message('stop-one', 'durable', 1, []));
            $queue->publish(new Message('stop-two', 'durable', 1, []));
            return;
        }
        if ($mode === 'recover-stop') {
            usleep(400000);
            $worker->run(10);
            reliabilityExpect($queue->statistics()['backlog'] === 0 && $ordinary->command('GET', [$application . ':count']) === '2', '停止后恢复丢失或重复业务效果');
            echo "停止后的未确认与重试任务恢复通过。\n";
            return;
        }
        if (in_array($mode, ['grace', 'slow', 'paused-pull', 'uncooperative'], true)) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function (int $signal, ?array $information) use ($worker): void {
                $worker->stop(0.3);
                echo json_encode(['stopping' => $worker->statistics()], JSON_THROW_ON_ERROR) . PHP_EOL;
            });
            if ($mode === 'paused-pull') {
                $ordinary->command('SET', [$application . ':client', (string) $redis->identity()]);
                $ordinary->command('SET', [$application . ':started', 'yes']);
                $admin = $reliable->connect();
                $admin->rawCommand('CLIENT', 'PAUSE', 500, 'WRITE');
                $admin->close();
            }
            $worker->run(100);
            echo json_encode(['worker' => $worker->statistics(), 'queue' => $queue->statistics()], JSON_THROW_ON_ERROR) . PHP_EOL;
            return;
        }
        throw new InvalidArgumentException('未知可靠存储验证模式');
    } finally {
        $scope->close();
        $manager->close();
    }
}
