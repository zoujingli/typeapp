<?php

declare(strict_types=1);

namespace app\common\bootstrap;

use app\common\database\DatabaseFactory;
use app\common\service\AuditRetentionTask;
use Type\Core\Config\Repository;
use Type\Orm\DatabaseManager;
use Type\Orm\Db;
use Type\Redis\Purpose;
use Type\Redis\RedisManager;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\ExecutionScope;
use Type\Scheduler\Definition;
use Type\Scheduler\IntervalSchedule;
use Type\Scheduler\RedisStateStore;
use Type\Scheduler\Scheduler;
use Type\Scheduler\SchedulerConsole;
use Type\Scheduler\SystemClock;
use Type\Scheduler\Task;
use Type\Scheduler\TaskContext;

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
        DatabaseFactory::requireExisting($settings, $basePath);
        return (int) CoroutineRuntime::run(static function () use ($settings, $basePath, $arguments): int {
            $database = new DatabaseManager(['default' => DatabaseFactory::create($settings, $basePath)]);
            Db::configure($database);
            $redis = new RedisManager(['scheduler' => Settings::redis($settings, $basePath, 'scheduler')]);
            $scope = new ExecutionScope();
            try {
                return (int) $scope->run(static function (ExecutionScope $current) use ($settings, $redis, $arguments): int {
                    $store = new RedisStateStore($redis->connection($current, 'scheduler', Purpose::SCRIPT), $settings->text('app.scheduler.namespace'), 'maintenance', 60000);
                    $definitions = [
                        new Definition('audit.admin', new IntervalSchedule(60), static fn (TaskContext $context): Task => new AuditRetentionTask('admin')),
                        new Definition('audit.customer', new IntervalSchedule(60), static fn (TaskContext $context): Task => new AuditRetentionTask('customer')),
                    ];
                    $scheduler = new Scheduler(new SystemClock(), $store, $definitions, 1000, 2, 20000);
                    try {
                        return (new SchedulerConsole($scheduler))->run($arguments);
                    } finally {
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
    }
}
