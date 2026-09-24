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

/**
 * 在协程内运行文件调度示例，待协程收尾后向宿主传递非零状态。
 *
 * @param list<string> $argv 程序路径与该示例的显式参数。
 */
function main(int $argc, array $argv): void
{
    $status = (int) \Type\Runtime\CoroutineRuntime::run(
        static fn (): int => schedulerScenario($argc, $argv)
    );
    $GLOBALS['type_app_exit_status'] = $status;
    // 原生包装只在全局值可读时补退出码；非零状态在协程结束后直接退出。
    if ($status !== 0) {
        exit($status);
    }
}

/**
 * 按外置场景选择受控时钟和任务，在文件存储上运行有限控制台命令。
 *
 * @param list<string> $argv 程序路径与 once/history/work 参数。
 */
function schedulerScenario(int $argc, array $argv): int
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
    return $status;
}
