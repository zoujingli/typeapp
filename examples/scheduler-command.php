<?php

declare(strict_types=1);

use Type\Scheduler\CronSchedule;
use Type\Scheduler\Definition;
use Type\Scheduler\FileStateStore;
use Type\Scheduler\IntervalSchedule;
use Type\Scheduler\Scheduler;
use Type\Scheduler\SchedulerConsole;
use Type\Scheduler\SystemClock;
use Type\Scheduler\Task;
use Type\Scheduler\TaskContext;
use TypeApp\SchedulerExample\ControlledClock;
use TypeApp\SchedulerExample\InterruptedTask;
use TypeApp\SchedulerExample\SummaryTask;
use TypeApp\SchedulerExample\CleanupFailureTask;

function main(int $argc, array $argv): void
{
    $scenario = getenv('TYPE_SCHEDULER_SCENARIO') ?: 'normal';
    $fixed = getenv('TYPE_SCHEDULER_NOW') ?: '';
    $clock = $fixed === '' ? new SystemClock() : new ControlledClock($fixed);
    $store = new FileStateStore(getenv('TYPE_SCHEDULER_STATE') ?: sys_get_temp_dir() . '/type-app-scheduler.json');
    $factory = static fn (TaskContext $context): Task => match ($scenario) {
        'interrupted' => new InterruptedTask(),
        'cleanup-failure' => new CleanupFailureTask(),
        default => new SummaryTask(),
    };
    $definitions = [new Definition(
        'summary.minute',
        new CronSchedule('* * * * *'),
        $factory,
        'catch-up',
        2,
        120,
        59,
        getenv('TYPE_SCHEDULER_REVISION') ?: 'development'
    )];
    if ($scenario === 'interval') {
        $definitions = [new Definition('summary.interval', new IntervalSchedule(10), $factory, 'catch-up', 3, 60, 5)];
    }
    $execution = getenv('TYPE_SCHEDULER_EXECUTION_MS');
    if ($execution !== false && (!ctype_digit($execution) || (int) $execution < 1 || (int) $execution > 3600000)) {
        throw new InvalidArgumentException('TYPE_SCHEDULER_EXECUTION_MS必须是有效毫秒预算');
    }
    $milliseconds = $execution === false ? 30000 : (int) $execution;
    $status = (new SchedulerConsole(new Scheduler($clock, $store, $definitions, executionMilliseconds: $milliseconds)))->run(array_slice($argv, 1));
    if ($status !== 0) {
        exit($status);
    }
}
