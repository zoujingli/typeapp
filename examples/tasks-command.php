<?php

declare(strict_types=1);

use Swoole\Coroutine;
use Swoole\Coroutine\Scheduler;
use Type\Orm\Database;
use Type\Orm\Mysql\MysqlDriver;
use Type\Runtime\Deadline;
use Type\Runtime\ExecutionScope;
use Type\Runtime\TaskException;

function taskExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function main(int $argc, array $argv): void
{
    \Type\Runtime\CoroutineRuntime::enableIo();
    $scheduler = new Scheduler();
    $failure = null;
    $scheduler->add(static function () use (&$failure): void {
        try {
            $parent = new ExecutionScope(new Deadline(1), ['request_id' => 'request-1'], 2, 1);
            $task = $parent->spawn(static function (ExecutionScope $child): array {
                Coroutine::sleep(0.01);
                return [$child->context(), $child->deadline()->remaining() < 1];
            });
            taskExpect($task->await() === [['request_id' => 'request-1'], true], '子任务没有复制上下文或继承剩余预算');
            $failed = $parent->spawn(static function (ExecutionScope $child): void {
                throw new RuntimeException('预期子任务失败');
            });
            try {
                $failed->await();
            } catch (RuntimeException $error) {
                taskExpect($error->getMessage() === '预期子任务失败', '子任务错误丢失');
            }
            $parent->close();
            taskExpect($parent->state() === 'closed', '正常子任务退出后作用域未关闭');

            $limited = new ExecutionScope(null, [], 1, 1);
            $active = $limited->spawn(static function (ExecutionScope $child): void {
                Coroutine::sleep(0.05);
            });
            $rejected = false;
            try {
                $limited->spawn(static fn (ExecutionScope $child): int => 1);
            } catch (TaskException $error) {
                $rejected = $error->errorCode() === 'child_limit';
            }
            taskExpect($rejected, '子任务上限没有生效');
            $limited->close();
            taskExpect($active->finished(), '父先退出没有等待活动子任务');
            $tree = new ExecutionScope(null, [], 1, 1);
            $branch = $tree->spawn(static function (ExecutionScope $scope): bool {
                try {
                    $scope->spawn(static fn (ExecutionScope $child): int => 1);
                } catch (TaskException $error) {
                    return $error->errorCode() === 'child_limit';
                }
                return false;
            });
            taskExpect($branch->await(), '嵌套子任务绕过执行树预算');
            $tree->close();
            taskExpect($tree->activeTasks() === 0, '完成子任务没有归还执行预算');

            $database = new Database(new MysqlDriver(
                (string) (getenv('TYPE_MYSQL_HOST') ?: '127.0.0.1'),
                (int) (getenv('TYPE_MYSQL_PORT') ?: 3306),
                (string) (getenv('TYPE_MYSQL_DATABASE') ?: 'type_app_test'),
                (string) (getenv('TYPE_MYSQL_USER') ?: 'root'),
                (string) (getenv('TYPE_MYSQL_PASSWORD') ?: '')
            ), 1, 1);
            $scope = new ExecutionScope(new Deadline(1), [], 2, 1);
            $started = false;
            $connectionId = null;
            $sql = $scope->spawn(static function (ExecutionScope $child) use ($database, &$started, &$connectionId): void {
                $connection = $database->connect($child);
                $connectionId = $connection->query('SELECT CONNECTION_ID() AS id')[0]['id'];
                $started = true;
                $connection->query('SELECT SLEEP(0.2) AS waited');
            });
            while (!$started) {
                Coroutine::sleep(0.001);
            }
            $begin = microtime(true);
            $timeout = false;
            try {
                $sql->await(0.02);
            } catch (TaskException $error) {
                $timeout = $error->errorCode() === 'task_timeout';
            }
            taskExpect($timeout && microtime(true) - $begin < 0.1 && !$sql->finished(), 'SQL 延迟阻塞整个执行者或超时没有停止等待');
            taskExpect($database->statistics()['leased'] === 1, '活动 SQL 的连接被提前归池');
            $peer = new ExecutionScope();
            $full = false;
            try {
                $database->connect($peer, 0);
            } catch (\Type\Runtime\CapacityException $error) {
                $full = true;
            }
            $peer->close();
            taskExpect($full, '隔离 SQL 没有计入容量');
            $scope->close();
            taskExpect($sql->finished() && $scope->state() === 'closed' && $database->statistics()['created'] === 0, '取消 SQL 未完成安全清理或被复用');
            $nextScope = new ExecutionScope();
            $next = $database->connect($nextScope);
            taskExpect($next->query('SELECT CONNECTION_ID() AS id')[0]['id'] !== $connectionId, '取消过的 SQL 连接被继续复用');
            $wrongOwner = $nextScope->spawn(static function (ExecutionScope $child) use ($next): bool {
                try {
                    $next->query('SELECT 1');
                } catch (RuntimeException $error) {
                    return str_contains($error->getMessage(), '执行者');
                }
                return false;
            });
            taskExpect($wrongOwner->await(), '捕获父事务连接跨协程使用没有被拒绝');
            $nextScope->close();
            $database->close();

            $uncooperative = new ExecutionScope(null, [], 1, 0.01);
            $child = $uncooperative->spawn(static function (ExecutionScope $scope): void {
                Coroutine::sleep(0.1);
            });
            $timedOut = false;
            try {
                $uncooperative->close();
            } catch (RuntimeException $error) {
                $timedOut = str_contains($error->getMessage(), '清理超时');
            }
            taskExpect($timedOut && $uncooperative->state() === 'closing' && !$child->finished(), '清理超时伪装成子任务已关闭');
            $rejected = false;
            try {
                $uncooperative->assertActive();
            } catch (RuntimeException) {
                $rejected = true;
            }
            taskExpect($rejected, '关闭中仍允许新业务');
            Coroutine::sleep(0.15);
            taskExpect($child->finished() && $uncooperative->state() === 'closed', '延期收尾没有完成作用域关闭');
            $uncooperative->close();
        } catch (Throwable $error) {
            $failure = $error;
        }
    });
    $scheduler->start();
    if ($failure !== null) {
        throw $failure;
    }
    echo "受管子任务、预算、延迟 SQL、隔离容量与延期清理通过。\n";
}
