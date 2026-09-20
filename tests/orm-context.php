<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Type\Orm\Database;
use Type\Orm\DatabaseManager;
use Type\Orm\Db;
use Type\Orm\Sqlite\SqliteDriver;
use Type\Runtime\Deadline;
use Type\Runtime\ExecutionScope;
use Type\Runtime\TaskException;

expect(extension_loaded('swoole') && version_compare((string) phpversion('swoole'), '6.2', '>=')
    && version_compare((string) phpversion('swoole'), '7', '<'), 'ORM 协程专项需要 Swoole >=6.2 <7');
expect(extension_loaded('pdo_sqlite'), 'ORM 协程专项需要 PDO SQLite');

$directory = dirname(__DIR__) . '/build/orm-context-' . bin2hex(random_bytes(5));
expect(mkdir($directory, 0700, true), '无法创建 ORM 协程专项目录');
$databaseFile = $directory . '/context.sqlite';
$database = new Database(new SqliteDriver($databaseFile), 1, 0, null, 4, 0.5);

try {
    \Swoole\Coroutine\run(function () use ($database): void {
        $contextScope = new ExecutionScope(null, ['request_id' => 'root', 'tenant_id' => 'tenant-a']);
        try {
            $contextTask = $contextScope->spawn(static function (ExecutionScope $child): array {
                $context = $child->context();
                $context['request_id'] = 'child';
                return $context;
            });
            $context = $contextTask->await();
            expect($context === ['request_id' => 'child', 'tenant_id' => 'tenant-a'], '子作用域没有取得独立上下文快照');
            expect($contextScope->context()['request_id'] === 'root', '子作用域修改污染父上下文');
        } finally {
            $contextScope->close();
        }

        // 可信值复制给子任务；事务和连接只属于各自的执行者。
        $manager = new DatabaseManager(['default' => new SqliteDriver(':memory:')], 2, 0);
        Db::configure($manager);
        $parent = new ExecutionScope();
        try {
            $parent->run(static function (ExecutionScope $current) use ($manager): void {
                $outer = Db::connection('default', true);
                Db::transaction(static function () use ($current, $outer, $manager): void {
                    $child = $current->spawn(static function (ExecutionScope $scope) use ($outer): array {
                        expect(ExecutionScope::current() === $scope, '任务没有绑定自身当前作用域');
                        $connection = Db::connection('default', true);
                        expect($connection !== $outer && $connection->transactionDepth() === 0, '子任务继承了父连接或事务');
                        return Db::transaction(static function () use ($connection, $scope): array {
                            expect($connection->transactionDepth() === 1, '子任务事务未独立建立');
                            return ['tenant' => $scope->binding('tenant_id'), 'value' => (int) $connection->query('SELECT 7 AS value')[0]['value']];
                        });
                    });
                    expect($child->await() === ['tenant' => 'tenant-a', 'value' => 7], '子任务可信上下文或独立事务返回值不符');
                    expect(
                        $outer->transactionDepth() === 1 && $manager->statistics()['active']['default']['leased'] === 1,
                        '子任务关闭影响父事务或未归还自身连接'
                    );
                });
                expect($outer->transactionDepth() === 0, '父事务没有独立完成');
            }, ['tenant_id' => 'tenant-a']);
        } finally {
            $parent->close();
            $manager->close();
        }

        $cancelScope = new ExecutionScope();
        $cancelSignal = new Channel(1);
        try {
            $cancelTask = $cancelScope->spawn(static function (ExecutionScope $child) use ($cancelSignal): string {
                $subscription = $child->cancellation()->subscribe(static function () use ($cancelSignal): void {
                    if (!$cancelSignal->isFull()) {
                        $cancelSignal->push(true);
                    }
                });
                try {
                    expect($cancelSignal->pop(1) === true, '父取消没有唤醒子协程');
                    return $child->cancellation()->cancelled() ? 'cancelled' : 'active';
                } finally {
                    $child->cancellation()->unsubscribe($subscription);
                }
            });
            Coroutine::sleep(0.005);
            $cancelScope->cancellation()->cancel();
            expect($cancelTask->await(1) === 'cancelled', '父取消没有传播到子作用域');
        } finally {
            $cancelScope->close();
        }

        $closeScope = new ExecutionScope();
        $closeSignal = new Channel(1);
        $closeTask = $closeScope->spawn(static function (ExecutionScope $child) use ($closeSignal): string {
            $subscription = $child->cancellation()->subscribe(static function () use ($closeSignal): void {
                if (!$closeSignal->isFull()) {
                    $closeSignal->push(true);
                }
            });
            try {
                expect($closeSignal->pop(1) === true, '父关闭没有唤醒子协程');
                return $child->cancellation()->cancelled() ? 'closed' : 'active';
            } finally {
                $child->cancellation()->unsubscribe($subscription);
            }
        });
        Coroutine::sleep(0.005);
        $closeScope->close();
        expect($closeTask->finished(), '父关闭后子任务没有真实完成');

        $deadlineScope = new ExecutionScope(new Deadline(0.03));
        $deadlineSignal = new Channel(1);
        try {
            $deadlineTask = $deadlineScope->spawn(static function (ExecutionScope $child) use ($deadlineSignal): string {
                $subscription = $child->cancellation()->subscribe(static function () use ($deadlineSignal): void {
                    if (!$deadlineSignal->isFull()) {
                        $deadlineSignal->push(true);
                    }
                });
                try {
                    expect($deadlineSignal->pop(1) === true, 'Deadline 到期没有唤醒子协程');
                    return $child->cancellation()->cancelled() ? 'deadline' : 'active';
                } finally {
                    $child->cancellation()->unsubscribe($subscription);
                }
            });
            try {
                $deadlineTask->await(1);
            } catch (TaskException $error) {
                expect($error->errorCode() === 'task_timeout', 'Deadline 等待错误码不符');
            }
            expect($deadlineTask->join(new Deadline(1)) && $deadlineTask->finished(), 'Deadline 没有传播到子作用域');
        } finally {
            $deadlineScope->close();
        }

        $ownerScope = new ExecutionScope();
        try {
            $connection = $database->connect($ownerScope);
            $ownerTask = $ownerScope->spawn(static function (ExecutionScope $child) use ($connection): string {
                try {
                    $connection->query('SELECT 1');
                    return 'allowed';
                } catch (Throwable $error) {
                    return $error->getMessage();
                }
            });
            $ownerResult = $ownerTask->await();
            expect($ownerResult !== 'allowed' && str_contains((string) $ownerResult, '执行者'), '数据库连接被跨协程使用');
            $connection->close();
        } finally {
            $ownerScope->close();
        }

        $timeoutScope = new ExecutionScope(null, [], 4, 0.2);
        try {
            $timeoutTask = $timeoutScope->spawn(static function (ExecutionScope $child) use ($database): int {
                $connection = $database->connect($child);
                Coroutine::sleep(0.08);
                return (int) $connection->query('SELECT 1 AS value')[0]['value'];
            });
            Coroutine::sleep(0.005);
            try {
                $timeoutTask->await(0.005);
                throw new RuntimeException('等待超时没有报告 task_timeout');
            } catch (TaskException $error) {
                expect($error->errorCode() === 'task_timeout', '等待超时错误码不符');
            }
            expect(
                !$timeoutTask->finished() && $database->statistics()['leased'] === 1,
                '等待超时提前释放了仍在途的数据库连接'
            );
            expect($timeoutTask->join(new Deadline(1)), '真实连接收尾没有在预算内完成');
            expect(
                $database->statistics()['leased'] === 0 && $database->statistics()['created'] === 0,
                '子作用域完成后数据库连接没有释放'
            );
        } finally {
            $timeoutScope->close();
        }
    });
    echo "ORM 协程上下文、取消传播、Deadline、连接所有权与迟到收尾通过。\n";
} finally {
    $database->close();
    removeTestDirectory($directory);
}
