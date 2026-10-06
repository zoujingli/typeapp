<?php

declare(strict_types=1);

namespace app\common\bootstrap;

use app\common\database\DatabaseFactory;
use Type\Core\Config\Repository;
use Type\Orm\Db;
use Type\Redis\Purpose;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\Cancellation;
use Type\Runtime\ProcessSignals;
use Type\Runtime\ExecutionScope;
use Type\Scheduler\RedisStateStore;
use Type\Scheduler\Scheduler;
use Type\Scheduler\SchedulerConsole;
use Type\Scheduler\SystemClock;

/** 物联中心固定维护任务的装配入口；复用组件调度与 Swoole 协程，不执行外部脚本。 */
final class ApplicationScheduler
{
    /**
     * 显式执行一次、读取历史或有限轮询；调用结束关闭本角色的数据库与 Redis 连接。
     * @param list<string> $arguments 与 SchedulerConsole 相同的 once/history/work 参数。
     */
    public static function run(Repository $settings, string $basePath, array $arguments): int
    {
        RuntimeCapabilities::requireFeature('scheduler');
        RuntimeCapabilities::requireFeature('redis');
        if ($arguments === [] || $arguments === ['help']) {
            echo "app:schedule once|history|work <次数> <间隔毫秒>；每分钟有界清理管理端和客户端满180天的审计。\n";
            return 0;
        }
        Settings::validateRuntimeConfiguration($settings, $basePath, 'scheduler');
        DatabaseFactory::requireExisting($settings, $basePath);
        $signals = new ProcessSignals();
        $stopping = new Cancellation();
        // Windows embed 控制事件桥在协程外登记，通知只改变停止意图。
        $signals->attach(static function () use ($stopping): void {
            $stopping->cancel();
        });
        try {
            return (int) CoroutineRuntime::run(static function () use ($settings, $basePath, $arguments, $signals, $stopping): int {
                $database = Settings::database($settings, $basePath);
                Db::configure($database);
                $redis = Settings::redisManager($settings, $basePath, 'scheduler');
                $scope = new ExecutionScope();
                try {
                    return (int) $scope->run(static function (ExecutionScope $current) use ($settings, $basePath, $redis, $arguments, $signals, $stopping): int {
                        $store = new RedisStateStore($redis->connection($current, 'scheduler', Purpose::SCRIPT), $settings->text('app.scheduler.namespace'), 'maintenance', 60000);
                        $application = new \Type\Generated\CommandApplication(ApplicationContext::configuration($basePath, '', false, $settings));
                        $definitions = $application->schedules();
                        $scheduler = new Scheduler(new SystemClock(), $store, $definitions, 1000, 2, 20000);
                        $subscription = $stopping->subscribe(static function () use ($scheduler): void {
                            $scheduler->stop();
                        });
                        // 同一原生事件循环在任务 I/O 和空闲等待期间分发 Windows 控制事件。
                        $timer = \Swoole\Timer::tick(50, static function (int $timerId) use ($signals): void {
                            $signals->dispatch();
                        });
                        try {
                            if ($timer === false) {
                                throw new \RuntimeException('scheduler_signal_timer_unavailable');
                            }
                            $signals->dispatch();
                            return (new SchedulerConsole($scheduler))->run($arguments);
                        } finally {
                            if ($timer !== false) {
                                \Swoole\Timer::clear($timer);
                            }
                            $stopping->unsubscribe($subscription);
                            $scheduler->stop();
                        }
                    });
                } finally {
                    try {
                        $scope->close();
                    } finally {
                        try {
                            $redis->close();
                        } finally {
                            $database->close();
                        }
                    }
                }
            });
        } finally {
            $signals->close();
        }
    }
}
