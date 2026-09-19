<?php

declare(strict_types=1);

use Type\Orm\Connection;
use Type\Orm\Database;
use Type\Orm\ModelException;
use Type\Runtime\ExecutionScope;
use TypeApp\ModelExample\Counter;
use TypeApp\ModelExample\Drivers;

function optimisticExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function main(int $argc, array $argv): void
{
    $driver = (string) ($argv[1] ?? 'sqlite');
    $database = new Database(Drivers::create($driver), 1, 0);
    $scope = new ExecutionScope();
    try {
        $connection = $database->connect($scope);
        if (($argv[2] ?? '') === 'race') {
            $counter = Counter::query($connection)->find(3);
            $barrier = (string) getenv('TYPE_OPTIMISTIC_BARRIER');
            file_put_contents($barrier, "ready\n", FILE_APPEND | LOCK_EX);
            $deadline = microtime(true) + 5;
            while (substr_count((string) file_get_contents($barrier), "\n") < 2) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('并发版本验证超时');
                }
                usleep(1000);
            }
            $counter->setValue($counter->getValue() + 1);
            try {
                $counter->save($connection);
                echo "updated\n";
            } catch (ModelException $error) {
                if ($error->errorCode() !== 'optimistic_conflict') {
                    throw $error;
                }
                echo "conflict\n";
            }
            return;
        }
        $connection->raw('CREATE TABLE type_model_counters (id INTEGER PRIMARY KEY, value INTEGER NOT NULL, version BIGINT NOT NULL)');
        $connection->table('type_model_counters')->insertMany([
            ['id' => 1, 'value' => 0, 'version' => 1], ['id' => 2, 'value' => 10, 'version' => 1], ['id' => 3, 'value' => 0, 'version' => 1],
        ]);
        $first = Counter::query($connection)->select(['value'])->find(1);
        $stale = Counter::query($connection)->find(1);
        optimisticExpect($first->getVersion() === 1, '部分查询没有保留版本');
        $first->setValue(1);
        optimisticExpect($first->save($connection) === 'updated' && $first->getVersion() === 2, '更新没有原子推进版本');
        $stale->setValue(2);
        $conflict = false;
        try {
            $stale->save($connection);
        } catch (ModelException $error) {
            $conflict = $error->errorCode() === 'optimistic_conflict';
        }
        optimisticExpect($conflict && Counter::query($connection)->find(1)->getValue() === 1, '过期版本覆盖了最新数据');
        $invalid = false;
        try {
            $stale->getVersion();
        } catch (ModelException $error) {
            $invalid = $error->errorCode() === 'model_invalid';
        }
        optimisticExpect($invalid, '冲突对象仍伪装为有效版本');
        optimisticExpect($first->save($connection) === 'unchanged' && $first->getVersion() === 2, '无变更推进了版本');
        $missing = Counter::query($connection)->find(2);
        $connection->table('type_model_counters')->where('id', '=', 2)->delete();
        $missing->setValue(11);
        $notFound = false;
        try {
            $missing->save($connection);
        } catch (ModelException $error) {
            $notFound = $error->errorCode() === 'not_found';
        }
        optimisticExpect($notFound, '未找到被误判成版本冲突');
        $rolledBack = null;
        try {
            $connection->transaction(static function (Connection $transaction) use (&$rolledBack): void {
                $rolledBack = Counter::query($transaction)->find(1);
                $rolledBack->setValue(99);
                $rolledBack->save($transaction);
                throw new RuntimeException('回滚版本');
            });
        } catch (RuntimeException $error) {
            optimisticExpect($error->getMessage() === '回滚版本', '回滚错误不符');
        }
        $current = Counter::query($connection)->find(1);
        optimisticExpect($current->getVersion() === 2 && $current->getValue() === 1, '回滚没有恢复数据库版本');
        $invalid = false;
        try {
            $rolledBack->getVersion();
        } catch (ModelException $error) {
            $invalid = $error->errorCode() === 'model_invalid';
        }
        optimisticExpect($invalid, '回滚后的版本对象仍可访问');
        $staleDelete = Counter::query($connection)->find(1);
        $current->setValue(3);
        $current->save($connection);
        $conflict = false;
        try {
            $staleDelete->forceDelete($connection);
        } catch (ModelException $error) {
            $conflict = $error->errorCode() === 'optimistic_conflict';
        }
        optimisticExpect($conflict && Counter::query($connection)->find(1)->getValue() === 3, '过期版本删除了最新对象');
        Counter::query($connection)->find(1)->forceDelete($connection);
        $protected = false;
        $guarded = Counter::query($connection)->find(3);
        try {
            $guarded->set('version', 999);
        } catch (ModelException $error) {
            $protected = $error->errorCode() === 'field_not_fillable';
        }
        optimisticExpect($protected, '版本可以被直接覆盖');
        echo "原子版本、冲突、未找到、无变更与回滚状态通过。\n";
    } finally {
        $scope->close();
        $database->close();
    }
}
