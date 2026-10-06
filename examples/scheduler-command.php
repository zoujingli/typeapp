<?php

declare(strict_types=1);

use Type\Core\Configuration;
use Type\Generated\CommandApplication;
use Type\Scheduler\FileStateStore;
use Type\Scheduler\Scheduler;
use Type\Scheduler\SchedulerConsole;
use Type\Scheduler\SystemClock;
use TypeApp\SchedulerExample\ControlledClock;

/**
 * 在协程内运行文件调度示例，待协程收尾后向宿主传递非零状态。
 *
 * @param list<string> $argv 程序路径与该示例的显式参数。
 */
function schedulerMain(int $argc, array $argv): void
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
    $filename = (string) (getenv('TYPE_SCHEDULER_STATE') ?: '');
    if ($filename === '') {
        // 系统临时目录在 macOS 可含系统链接；只解析这个默认目录，显式状态路径仍严格校验。
        $temporary = realpath(sys_get_temp_dir());
        if ($temporary === false) {
            throw new RuntimeException('TYPE_SCHEDULER_STORE：系统临时目录不存在');
        }
        $filename = $temporary . '/type-app-scheduler.json';
    }
    $store = new FileStateStore($filename);
    $application = new CommandApplication(new Configuration(['scenario' => $scenario]));
    $definitions = [];
    $selected = $scenario === 'interval' ? 'summary.interval' : 'summary.minute';
    foreach ($application->schedules() as $definition) {
        if ($definition->id() === $selected) {
            $definitions[] = $definition;
        }
    }
    $execution = getenv('TYPE_SCHEDULER_EXECUTION_MS');
    if ($execution !== false && (!ctype_digit($execution) || (int) $execution < 1 || (int) $execution > 3600000)) {
        throw new InvalidArgumentException('TYPE_SCHEDULER_EXECUTION_MS必须是有效毫秒预算');
    }
    $milliseconds = $execution === false ? 30000 : (int) $execution;
    $status = (new SchedulerConsole(new Scheduler($clock, $store, $definitions, executionMilliseconds: $milliseconds)))->run(array_slice($argv, 1));
    return $status;
}
