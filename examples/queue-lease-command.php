<?php

declare(strict_types=1);

use Type\Queue\Message;
use Type\Queue\Queue;
use Type\Queue\QueueException;
use Type\Redis\Purpose;
use Type\Redis\RedisConfiguration;
use Type\Redis\RedisManager;
use Type\Runtime\ExecutionScope;

function leaseExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function main(int $argc, array $argv): void
{
    $mode = (string) ($argv[1] ?? 'normal');
    $application = (string) (getenv('TYPE_QUEUE_APPLICATION') ?: 'lease-test-' . bin2hex(random_bytes(8)));
    $manager = new RedisManager(['default' => new RedisConfiguration(
        (string) (getenv('TYPE_REDIS_HOST') ?: '127.0.0.1'),
        (int) (getenv('TYPE_REDIS_PORT') ?: 6379)
    )]);
    $scope = new ExecutionScope();
    try {
        // 正向操作要容纳 AOF fsync 和运行器调度抖动；崩溃恢复仍用短租约加快真实过期。
        $leaseMilliseconds = $mode === 'crash' || $mode === 'recover' ? 100 : 2000;
        $queue = new Queue($manager->connection($scope, 'default', Purpose::SCRIPT), $application, 'leases', $leaseMilliseconds);
        if ($mode === 'crash') {
            $queue->publish(new Message('crashed-message', 'probe', 1, ['value' => '不能丢失']));
            $reservation = $queue->reserve('crashed-worker');
            leaseExpect($reservation !== null, '崩溃前没有领取任务');
            posix_kill((int) getmypid(), 9);
            throw new RuntimeException('进程没有被终止');
        }
        if ($mode === 'recover') {
            $deadline = microtime(true) + 5;
            $recovered = null;
            do {
                $recovered = $queue->reclaim('recovery-worker');
                if ($recovered !== null) {
                    break;
                } usleep(10000);
            } while (microtime(true) < $deadline);
            leaseExpect($recovered !== null && $recovered->message()->id() === 'crashed-message'
                && $recovered->message()->payload()['value'] === '不能丢失', '崩溃后没有恢复原任务');
            $recovered->acknowledge();
            leaseExpect($queue->statistics()['messages'] === 0, '恢复任务没有确认');
            echo "真实 worker 崩溃后恢复原消息通过。\n";
            return;
        }
        $queue->publish(new Message('lease-message', 'probe', 1, []));
        $old = $queue->reserve('first-worker');
        leaseExpect($old !== null, '首次领取失败');
        // 验收可显式模拟进程被调度器暂停；真实 Redis TIME 仍是租约唯一时间来源。
        usleep($mode === 'delayed-renew' ? 150000 : 30000);
        $old->renew();
        usleep(50000);
        leaseExpect($queue->reclaim('early-worker') === null, '未过期租约被提前重领');
        // 等待 Redis 实际判定过期，不把固定 sleep 的结束时间当作服务端租约状态。
        $reclaimDeadline = microtime(true) + 5;
        $new = null;
        do {
            $new = $queue->reclaim('second-worker');
            if ($new !== null) {
                break;
            } usleep(10000);
        } while (microtime(true) < $reclaimDeadline);
        leaseExpect($new !== null && $new->streamId() === $old->streamId() && $new->token() !== $old->token(), '重领没有替换租约 token');
        foreach (['ack', 'renew', 'effect'] as $operation) {
            $rejected = false;
            try {
                if ($operation === 'ack') {
                    $old->acknowledge();
                } elseif ($operation === 'renew') {
                    $old->renew();
                } else {
                    $old->effect("return redis.call('SET',KEYS[1],'invalid')", [$application . ':effect']);
                }
            } catch (QueueException $error) {
                $rejected = $error->errorCode() === 'lease_lost';
            }
            leaseExpect($rejected, '旧 token 仍可执行：' . $operation);
        }
        $new->effect("return redis.call('SET',KEYS[1],'valid')", [$application . ':effect']);
        $new->renew();
        leaseExpect($new->acknowledge() && !$new->acknowledge(), '新执行者确认或重复确认状态错误');
        $command = $manager->connection($scope);
        leaseExpect($command->command('GET', [$application . ':effect']) === 'valid', '旧执行者污染新任务副作用');
        $command->command('DEL', [$application . ':effect']);
        echo "租约续期、过期重领、旧 token 拒绝与原子副作用通过。\n";
    } finally {
        $scope->close();
        $manager->close();
    }
}
