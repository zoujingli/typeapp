<?php

declare(strict_types=1);

use Type\Queue\Queue;
use Type\Redis\Purpose;
use Type\Redis\RedisConfiguration;
use Type\Redis\RedisManager;
use Type\Runtime\ExecutionScope;
use Type\Scheduler\Definition;
use Type\Scheduler\IntervalSchedule;
use Type\Scheduler\RedisStateStore;
use Type\Scheduler\Scheduler;
use Type\Scheduler\SchedulerConsole;
use Type\Scheduler\Task;
use Type\Scheduler\TaskContext;
use TypeApp\Coordination\QueueDispatchTask;
use TypeApp\Coordination\ReportTask;
use TypeApp\SchedulerExample\ControlledClock;

function main(int $argc, array $argv): void
{
    $status = (int) \Type\Runtime\CoroutineRuntime::run(
        static fn (): int => schedulerCoordinationScenario($argc, $argv)
    );
    // 协程内的 exit() 会变成 Swoole\ExitException，父进程看不到调度状态码。
    $GLOBALS['type_app_exit_status'] = $status;
    if ($status !== 0) {
        exit($status);
    }
}

function schedulerCoordinationScenario(int $argc, array $argv): int
{
    if ($argc < 2 || ($argc === 2 && $argv[1] === 'help')) {
        echo "调度协调命令：once、history、work <次数> <间隔毫秒>。\n";
        return 0;
    }
    $application = getenv('TYPE_COORDINATION_APP') ?: 'type-app-coordination';
    $manager = new RedisManager(['default' => new RedisConfiguration(
        getenv('TYPE_REDIS_HOST') ?: '127.0.0.1',
        (int) (getenv('TYPE_REDIS_PORT') ?: 6379)
    )]);
    $scope = new ExecutionScope();
    $status = 70;
    try {
        $redis = $manager->connection($scope, 'default', Purpose::SCRIPT);
        $store = new RedisStateStore($redis, $application, 'reports', 300);
        $queue = new Queue($redis, $application, 'reports');
        $mode = getenv('TYPE_COORDINATION_MODE') ?: 'normal';
        $task = getenv('TYPE_COORDINATION_TASK') ?: 'reports.minute';
        $key = $application . ':business';
        $factory = static fn (TaskContext $context): Task => new ReportTask($redis, new QueueDispatchTask($queue, 'scheduled-report', 1, ['amount' => 1]), $key, $mode);
        $definition = new Definition(
            $task,
            new IntervalSchedule(60),
            $factory,
            'catch-up',
            1,
            60,
            0,
            getenv('TYPE_COORDINATION_REVISION') ?: 'v1'
        );
        $clock = new ControlledClock(getenv('TYPE_COORDINATION_NOW') ?: '2026-09-09T12:00:00Z');
        $status = (new SchedulerConsole(new Scheduler($clock, $store, [$definition])))->run(array_slice($argv, 1));
    } finally {
        $scope->close();
        $manager->close();
    }

    return $status;
}
