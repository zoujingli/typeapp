<?php

declare(strict_types=1);

use Type\Queue\JobContext;
use Type\Queue\Message;
use Type\Queue\Queue;
use Type\Queue\Registry;
use Type\Queue\RetryPolicy;
use Type\Queue\Worker;
use Type\Redis\Purpose;
use Type\Redis\RedisConfiguration;
use Type\Redis\RedisManager;
use Type\Runtime\ExecutionScope;
use TypeApp\QueueExample\Resource;
use TypeApp\QueueExample\RetryJob;

/**
 * 将当前示例的行为断言转为明确失败，避免只输出成功文字而忽略实际状态。
 *
 * @throws \RuntimeException 条件不成立。
 */
function retryExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * 在协程内验证延迟提升、有限重试、隔离与转移故障后的恢复。
 *
 * @param list<string> $argv 程序路径与该示例的显式参数。
 */
function main(int $argc, array $argv): void
{
    \Type\Runtime\CoroutineRuntime::run(static function () use ($argc, $argv): void {
        queueRetryScenario($argc, $argv);
    });
}

/**
 * 验证延迟、超时、重试与隔离转移，使用随机应用命名空间避免混入其他消息。
 *
 * @param list<string> $argv 程序路径及演练参数。
 */
function queueRetryScenario(int $argc, array $argv): void
{
    $manager = new RedisManager(['default' => new RedisConfiguration(
        (string) (getenv('TYPE_REDIS_HOST') ?: '127.0.0.1'),
        (int) (getenv('TYPE_REDIS_PORT') ?: 6379)
    )], [Purpose::SCRIPT => 5]);
    $scope = new ExecutionScope();
    $application = 'type_retry_' . bin2hex(random_bytes(8));
    try {
        $redis = $manager->connection($scope, 'default', Purpose::SCRIPT);
        $queue = new Queue($redis, $application, 'jobs', 1000, 100, 1);
        $registry = new Registry();
        $registry->register('retry', 1, static fn (JobContext $context): RetryJob => new RetryJob());
        // 100ms执行预算必须保留小数秒，不能在原生整数除法下变为零。
        $policy = new RetryPolicy(3, 20, 40, 100);
        $worker = new Worker($queue, $registry, 'worker', $policy);
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $delay = $policy->delay($attempt);
            retryExpect($delay >= 10 && $delay <= 40, '退避抖动超出配置');
        }
        $queue->publishDelayed(new Message('delayed', 'retry', 1, []), 40);
        retryExpect(!$worker->runOnce() && $queue->statistics()['delayed'] === 1, '延迟任务提前运行');
        usleep(60000);
        retryExpect($worker->runOnce() && RetryJob::$handled === [['delayed', 1]], '到期任务没有可靠提升');
        $queue->publish(new Message('retry-success', 'retry', 1, ['failures' => 2]));
        $deadline = microtime(true) + 3;
        while ((count(RetryJob::$handled) < 4 || $queue->statistics()['messages'] > 0 || $queue->statistics()['delayed'] > 0) && microtime(true) < $deadline) {
            $worker->runOnce();
            usleep(10000);
        }
        retryExpect(array_slice(RetryJob::$handled, 1) === [['retry-success', 1], ['retry-success', 2], ['retry-success', 3]], '重试次数或稳定 ID 错误');
        $queue->publish(new Message('exhausted', 'retry', 1, ['failures' => 10]));
        $deadline = microtime(true) + 3;
        do {
            $worker->runOnce();
            if ($queue->statistics()['quarantined'] === 1) {
                break;
            } usleep(10000);
        } while (microtime(true) < $deadline);
        $quarantine = $queue->quarantined();
        retryExpect(count($quarantine) === 1 && $quarantine[0]['attempt'] === 3 && str_starts_with($quarantine[0]['reason'], 'attempts_exhausted:'), '超过上限没有隔离');
        $queue->publish(new Message('future', 'retry', 2, []));
        $worker->runOnce();
        $future = null;
        foreach ($queue->quarantined() as $item) {
            if (Message::decode($item['message'])->id() === 'future') {
                $future = $item;
            }
        }
        retryExpect($future !== null && $future['reason'] === 'unsupported_version', '未知版本没有隔离');
        $futureRegistry = new Registry();
        $futureRegistry->register('retry', 2, static fn (JobContext $context): RetryJob => new RetryJob());
        $queue->replay($future['receipt']);
        (new Worker($queue, $futureRegistry, 'new-version'))->runOnce();
        retryExpect(RetryJob::$handled[count(RetryJob::$handled) - 1] === ['future', 1], '隔离重放改变了稳定消息 ID');
        $admin = $manager->connection($scope, 'default', Purpose::SCRIPT);
        $admin->script("return redis.call('XADD',KEYS[1],'*','message','{invalid','attempt','1')", [$queue->identity() . ':stream']);
        retryExpect($worker->runOnce() && $queue->statistics()['quarantined'] === 2, '非法载荷没有保留隔离');
        $queue->publish(new Message('slow', 'retry', 1, ['slow' => true, 'effect_key' => $application . ':timeout-effect']));
        (new Worker($queue, $registry, 'short-budget', new RetryPolicy(1, 10, 20, 10)))->runOnce();
        retryExpect($queue->statistics()['quarantined'] === 3, '最大执行时间没有限制受管效果');
        $plain = $manager->connection($scope);
        retryExpect($plain->command('GET', [$application . ':timeout-effect']) === false, '超时后仍执行受管副作用');
        retryExpect(Resource::$opened === Resource::$closed, '失败或重试路径没有清理资源');
        // Redis以服务端时间判断到期；宿主墙钟回拨时，固定本地休眠不代表服务端保留期已结束。
        $collectionDeadline = hrtime(true) + 3000000000;
        $collected = 0;
        do {
            $collected += $queue->collect(100);
            if ($collected === 3) {
                break;
            }
            usleep(10000);
        } while (hrtime(true) < $collectionDeadline);
        retryExpect($collected === 3 && $queue->statistics()['quarantined'] === 0, '隔离保留期到期没有回收');
        // 破坏目标索引类型模拟服务端脚本失败，源消息必须仍保留 pending。
        $queue->publish(new Message('transfer-failure', 'retry', 1, []));
        $reservation = $queue->reserve('failure-worker');
        $admin->script("return redis.call('SET',KEYS[1],'invalid-type')", [$queue->identity() . ':delayed']);
        $failed = false;
        try {
            $reservation->retry('simulated_failure', 10);
        } catch (\Type\Redis\RedisException) {
            $failed = true;
        }
        retryExpect($failed, '没有触发服务端失败');
        $admin->script("return redis.call('DEL',KEYS[1])", [$queue->identity() . ':delayed']);
        $recovery = new Queue($manager->connection($scope, 'default', Purpose::SCRIPT), $application, 'jobs', 1000, 100, 1);
        retryExpect($recovery->statistics()['messages'] === 1 && $recovery->statistics()['leased'] === 1, '失败转移删除了未确认消息');
        $reclaimed = $recovery->reclaim('recovered-transfer');
        $recoveryDeadline = hrtime(true) + 3000000000;
        while ($reclaimed === null && hrtime(true) < $recoveryDeadline) {
            usleep(10000);
            $reclaimed = $recovery->reclaim('recovered-transfer');
        }
        retryExpect($reclaimed !== null && $reclaimed->message()->id() === 'transfer-failure', '故障后无法恢复原任务');
        $reclaimed->acknowledge();
        echo "延迟提升、有限重试、超时、隔离重放、保留期与转移故障恢复通过。\n";
    } finally {
        $scope->close();
        $manager->close();
    }
}
