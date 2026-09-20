<?php

declare(strict_types=1);

use Type\Queue\JobContext;
use Type\Queue\Message;
use Type\Queue\Queue;
use Type\Queue\QueueException;
use Type\Queue\Worker;
use Type\Redis\Purpose;
use Type\Redis\RedisConfiguration;
use Type\Redis\RedisManager;
use Type\Runtime\ExecutionScope;
use TypeApp\QueueExample\Increment;
use TypeApp\QueueExample\Resource;
use TypeApp\QueueExample\Jobs;

function queueExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function main(int $argc, array $argv): void
{
    \Type\Runtime\CoroutineRuntime::run(static function () use ($argc, $argv): void {
        queueScenario($argc, $argv);
    });
}

function queueScenario(int $argc, array $argv): void
{
    if (($argv[1] ?? '') === '--help') {
        echo "队列验证：编译注册任务、投递、消费与幂等确认。\n";
        return;
    }
    $manager = new RedisManager(['default' => new RedisConfiguration(
        (string) (getenv('TYPE_REDIS_HOST') ?: '127.0.0.1'),
        (int) (getenv('TYPE_REDIS_PORT') ?: 6379)
    )], [Purpose::SCRIPT => 4]);
    $scope = new ExecutionScope();
    $prefix = 'type_job_test_' . bin2hex(random_bytes(8));
    try {
        $redis = $manager->connection($scope, 'default', Purpose::SCRIPT);
        $queue = new Queue($redis, $prefix, 'jobs', 1000, 10);
        $registry = Jobs::create([Increment::class => static fn (JobContext $context): Increment => new Increment($prefix)]);
        $worker = new Worker($queue, $registry, 'worker-' . getmypid());
        queueExpect(!$worker->runOnce(), '空队列错误领取');
        $message = new Message('stable-1', 'increment', 1, ['amount' => 2], ['request_id' => 'request-1', 'message_id' => '不能覆盖']);
        $queue->publish($message);
        $queue->publish($message);
        queueExpect($worker->runOnce() && $worker->runOnce() && !$worker->runOnce(), '正常任务没有确认或重复执行状态错误');
        $command = $manager->connection($scope);
        queueExpect($command->command('GET', [$prefix . ':total']) === '2', '重复投递产生重复业务副作用');
        queueExpect(Resource::$opened === 2 && Resource::$closed === 2, '消息作用域没有逐次清理');
        queueExpect($queue->statistics()['messages'] === 0 && $queue->statistics()['leased'] === 0, '确认后没有完成消息状态收尾');
        $queue->publish(new Message('stable-2', 'increment', 1, ['amount' => 3]));
        $worker->stop();
        queueExpect(!$worker->runOnce() && $queue->statistics()['messages'] === 1, '停止后仍领取任务');
        $next = new Worker($queue, $registry, 'worker-next');
        queueExpect($next->runOnce(), '正常停止影响后续 worker');
        queueExpect($command->command('GET', [$prefix . ':total']) === '5', '后续任务结果错误');
        $queue->publish(new Message('cleanup', 'increment', 1, ['amount' => 7]));
        $failing = new Worker($queue, $registry, 'worker-cleanup');
        Resource::$failStop = true;
        $rejected = false;
        try {
            $failing->runOnce();
        } catch (QueueException $error) {
            $rejected = $error->errorCode() === 'cleanup_incomplete';
        } finally {
            Resource::$failStop = false;
        }
        $stats = $failing->statistics();
        $pending = $queue->statistics();
        queueExpect($rejected && !$failing->ready() && $stats['in_flight'] === 1 && $stats['state'] === 'draining'
            && $stats['completed'] === 0 && $stats['retried'] === 0 && $stats['quarantined'] === 0
            && $stats['cleanup_failures'] === 1 && $stats['storage_failures'] === 0, '清理失败提前归还执行额度或转移投递');
        queueExpect($pending['messages'] === 1 && $pending['leased'] === 1 && $pending['delayed'] === 0
            && !$failing->runOnce(), '原投递未保留在租约中或故障角色继续预取');
        queueExpect(Increment::$lastScope?->state() === 'closing', '队列丢失未清理作用域');
        Increment::$lastScope?->close();
        queueExpect($failing->statistics()['in_flight'] === 0 && !$failing->ready(), '真实清理后额度未归还或故障角色恢复接活');
        usleep(1100000);
        $recovery = new Worker($queue, $registry, 'worker-recovery');
        queueExpect($recovery->runOnce() && $queue->statistics()['leased'] === 0 && $queue->statistics()['messages'] === 0
            && $command->command('GET', [$prefix . ':total']) === '12', '原租约重领失败或重复产生业务副作用');
        $command->command('DEL', [$prefix . ':total', $prefix . ':done:stable-1', $prefix . ':done:stable-2', $prefix . ':done:cleanup']);
        echo "Streams 任务注册、独立作用域、幂等消费、原子确认与停止通过。\n";
    } finally {
        $scope->close();
        $manager->close();
    }
}
