<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
(new Type\Build\DevelopmentBuilder())->loadConfiguration(__DIR__ . '/type-app.json');

/** 固定计划时刻用于跨进程游标恢复验证。 */
final class ScaffoldClock implements Psr\Clock\ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@1800000000');
    }
}

Type\Runtime\CoroutineRuntime::run(static function () use ($argv): void {
    $application = new Type\Generated\CommandApplication(new Type\Core\Configuration([]));
    if (($argv[1] ?? '') === 'schedule') {
        $scheduler = new Type\Scheduler\Scheduler(new ScaffoldClock(), new Type\Scheduler\FileStateStore(__DIR__ . '/schedule.json'), $application->schedules());
        echo json_encode($scheduler->tick(), JSON_THROW_ON_ERROR) . "\n";
        $scheduler->stop();
        if ($scheduler->ready() || $scheduler->tick() !== []) { throw new RuntimeException('scheduler_stop_failed'); }
        return;
    }
    $manager = new Type\Redis\RedisManager(['default' => new Type\Redis\RedisConfiguration(
        (string) (getenv('TYPE_REDIS_HOST') ?: '127.0.0.1'), (int) (getenv('TYPE_REDIS_PORT') ?: 6379)
    )], [Type\Redis\Purpose::SCRIPT => 4]);
    $scope = new Type\Runtime\ExecutionScope();
    $prefix = 'type_scaffold_' . bin2hex(random_bytes(8));
    try {
        $redis = $manager->connection($scope, 'default', Type\Redis\Purpose::SCRIPT);
        $queue = new Type\Queue\Queue($redis, $prefix, 'jobs', 1000, 10);
        $worker = new Type\Queue\Worker($queue, $application->jobs(), 'scaffold');
        $queue->publish(new Type\Queue\Message('one', 'marker', 1, ['marker' => 'queue-scaffold-ok']));
        if (!$worker->runOnce() || $queue->statistics()['messages'] !== 0) { throw new RuntimeException('queue_not_acknowledged'); }
        $queue->publish(new Type\Queue\Message('two', 'marker', 1, ['marker' => 'queue-after-stop']));
        $worker->stop();
        if ($worker->runOnce() || $queue->statistics()['messages'] !== 1) { throw new RuntimeException('worker_stop_failed'); }
        $restarted = new Type\Queue\Worker($queue, $application->jobs(), 'scaffold-next');
        if (!$restarted->runOnce() || $queue->statistics()['messages'] !== 0) { throw new RuntimeException('worker_restart_failed'); }
        $restarted->stop();
    } finally {
        $scope->close();
        $manager->close();
        // 清理由测试控制器执行；生产任务只接触显式业务值。
        $cleanup = new Redis();
        $cleanup->connect((string) (getenv('TYPE_REDIS_HOST') ?: '127.0.0.1'), (int) (getenv('TYPE_REDIS_PORT') ?: 6379));
        foreach ($cleanup->keys('type:queue:{' . hash('sha256', $prefix . "\0" . 'jobs') . '}*') as $key) { $cleanup->del($key); }
        $cleanup->close();
    }
});
