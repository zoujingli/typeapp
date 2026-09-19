<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/examples/rollout/Publisher.php';

use Type\Orm\Outbox\Record;
use Type\Queue\Queue;
use Type\Redis\Purpose;
use Type\Redis\RedisConfiguration;
use Type\Redis\RedisManager;
use Type\Runtime\ExecutionScope;
use TypeApp\Rollout\Publisher;

$configuration = new RedisConfiguration(getenv('TYPE_REDIS_HOST') ?: '127.0.0.1', (int) (getenv('TYPE_REDIS_PORT') ?: 6379));
$scope = new ExecutionScope();
$manager = new RedisManager(['queue' => $configuration]);
$queue = new Queue($manager->connection($scope, 'queue', Purpose::SCRIPT), 'rollout-delay-' . bin2hex(random_bytes(6)));
try {
    // 在真实发布接口设置十秒窗口；六秒迁移/进程切换超过旧固定五秒值。
    $publisher = new Publisher($queue, 10000);
    $record = new Record(['id' => 'delayed-old', 'topic' => 'user.changed', 'version' => 1, 'payload' => '{}', 'context' => '{}']);
    $started = microtime(true);
    $publisher->publish($record);
    expect($queue->statistics()['delayed'] === 1, '旧消息未进入延迟队列');
    usleep(6000000);
    $promoted = $queue->promote();
    $stats = $queue->statistics();
    echo json_encode(['elapsed-ms' => (int) ((microtime(true) - $started) * 1000), 'promoted' => $promoted, 'delayed' => $stats['delayed']], JSON_THROW_ON_ERROR) . "\n";
    expect($promoted === 0 && $stats['delayed'] === 1, '旧延迟消息没有保留到六秒消费者切换窗口');
    $deadline = $started + 13;
    do {
        $due = $queue->promote();
        if ($due === 1) {
            break;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    expect($due === 1 && $queue->statistics()['delayed'] === 0, '配置延迟到期后没有进入消费队列');
    expect($queue->reserve('new-release')?->message()->id() === 'delayed-old', '新版消费者没有取得原旧消息');
    foreach ([0, -1, 120001] as $invalid) {
        $rejected = false;
        try {
            new Publisher($queue, $invalid);
        } catch (InvalidArgumentException) {
            $rejected = true;
        }
        expect($rejected, '非法发布演练延迟没有拒绝');
    }
    echo "旧消息跨慢切换窗口保留并按配置到期通过。\n";
} finally {
    $connection = $configuration->connect();
    try {
        $iterator = null;
        do {
            $keys = $connection->scan($iterator, $queue->identity() . ':*', 100);
            if (is_array($keys) && $keys !== []) {
                $connection->del($keys);
            }
        } while ($iterator !== 0);
    } finally {
        $connection->close();
        $scope->close();
        $manager->close();
    }
}
