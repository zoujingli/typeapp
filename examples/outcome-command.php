<?php

declare(strict_types=1);

use Type\Orm\AfterCommitException;
use Type\Orm\Connection;
use Type\Orm\Database;
use Type\Orm\DatabaseManager;
use Type\Orm\Db;
use Type\Orm\Mysql\MysqlDriver;
use Type\Orm\DatabaseException;
use Type\Orm\ModelException;
use Type\Orm\TransactionException;
use Type\Orm\TransactionOutcome;
use Type\Runtime\ExecutionScope;
use Type\Runtime\CoroutineRuntime;
use TypeApp\ModelExample\Drivers;
use TypeApp\ModelExample\User;

function outcomeExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function main(int $argc, array $argv): void
{
    CoroutineRuntime::run(static function () use ($argc, $argv): void {
        runOutcome($argc, $argv);
    });
}

function runOutcome(int $argc, array $argv): void
{
    $mode = (string) ($argv[1] ?? 'sqlite');
    $fault = str_starts_with($mode, 'fault-');
    $database = new Database(Drivers::create($fault ? 'mysql' : $mode), 1, 0);
    $scope = new ExecutionScope();
    $rwManager = null;
    try {
        $scope->run(static function (ExecutionScope $current) use ($fault, $mode, $database, $scope, &$rwManager): void {
            if ($fault) {
                $rwManager = new DatabaseManager(['default' => ['master' => Drivers::create('mysql'), 'reader' => new MysqlDriver(
                    (string) (getenv('TYPE_MYSQL_HOST') ?: '127.0.0.1'),
                    (int) (getenv('TYPE_MYSQL_PORT') ?: 3306),
                    (string) (getenv('TYPE_MYSQL_DATABASE') ?: 'type_app_test'),
                    (string) (getenv('TYPE_MYSQL_USER') ?: 'root'),
                    (string) (getenv('TYPE_MYSQL_PASSWORD') ?: ''),
                    1,
                    'reader'
                )]]);
                Db::configure($rwManager);
                $calls = 0;
                $callbacks = 0;
                $model = null;
                $outcome = '';
                try {
                    Db::transaction(static function () use (&$calls, &$callbacks, &$model): void {
                        $calls++;
                        $model = new User(['name' => '故障写入', 'age' => 20, 'active' => true, 'secret' => '内部']);
                        $model->save();
                        Db::afterCommit(static function () use (&$callbacks): void {
                            $callbacks++;
                        });
                    });
                } catch (TransactionException $error) {
                    $outcome = $error->outcome();
                }
                $invalid = false;
                if ($model !== null) {
                    try {
                        $model->getId();
                    } catch (ModelException $error) {
                        $invalid = $error->errorCode() === 'model_invalid';
                    }
                }
                outcomeExpect($outcome === ($mode === 'fault-begin' ? TransactionOutcome::NOT_STARTED : TransactionOutcome::UNKNOWN), '故障被错误报告为确定提交或回滚');
                if ($outcome === TransactionOutcome::UNKNOWN) {
                    $blocked = false;
                    try {
                        Db::connection('default', true);
                    } catch (TransactionException $error) {
                        $blocked = $error->outcome() === TransactionOutcome::UNKNOWN;
                    }
                    outcomeExpect($blocked, '提交未知后仍允许新的模型数据库操作');
                }
                echo json_encode(['outcome' => $outcome, 'calls' => $calls, 'callbacks' => $callbacks, 'model_invalid' => $invalid], JSON_THROW_ON_ERROR) . PHP_EOL;
                return;
            }
            $connection = $database->connect($scope);
            $connection->raw('CREATE TEMPORARY TABLE type_outcome_probe (id INTEGER PRIMARY KEY, value VARCHAR(255))');
            outcomeExpect($connection->transactionOutcome() === TransactionOutcome::NOT_STARTED, '未开始状态错误');
            $calls = 0;
            $log = [];
            $committedFailure = false;
            try {
                $connection->transaction(static function (Connection $transaction) use (&$calls, &$log): void {
                    $calls++;
                    outcomeExpect($transaction->transactionOutcome() === TransactionOutcome::ACTIVE, '活动状态错误');
                    $transaction->table('type_outcome_probe')->insert(['id' => 1, 'value' => '已提交']);
                    $transaction->afterCommit(static function () use (&$log): void {
                        $log[] = 'A';
                        throw new RuntimeException('回调失败');
                    });
                    $transaction->transaction(static function (Connection $inner) use (&$log): void {
                        $inner->afterCommit(static function () use (&$log): void {
                            $log[] = 'B';
                        });
                    });
                    try {
                        $transaction->transaction(static function (Connection $inner) use (&$log): void {
                            $inner->afterCommit(static function () use (&$log): void {
                                $log[] = '丢弃';
                            });
                            throw new RuntimeException('回滚内层');
                        });
                    } catch (RuntimeException $error) {
                        outcomeExpect($error->getMessage() === '回滚内层', '内层异常错误');
                    }
                    $transaction->afterCommit(static function () use (&$log): void {
                        $log[] = 'C';
                    });
                    outcomeExpect($log === [], '回调在提交前执行');
                });
            } catch (AfterCommitException $error) {
                $committedFailure = $error->outcome() === TransactionOutcome::COMMITTED && count($error->errors()) === 1;
            }
            outcomeExpect($committedFailure && $calls === 1 && $log === ['A', 'B', 'C']
                && $connection->transactionOutcome() === TransactionOutcome::COMMITTED
                && $connection->table('type_outcome_probe')->aggregate('COUNT') === 1, '提交事实、回调顺序或单次执行错误');
            try {
                $connection->transaction(static function (Connection $transaction): void {
                    $transaction->table('type_outcome_probe')->insert(['id' => 2, 'value' => '应回滚']);
                    $transaction->execute('CREATE TABLE type_forbidden_transaction_ddl (id INTEGER)');
                });
            } catch (DatabaseException $error) {
                outcomeExpect(str_contains($error->getMessage(), 'DDL'), 'DDL 拒绝错误');
            }
            outcomeExpect($connection->transactionOutcome() === TransactionOutcome::ROLLED_BACK
                && $connection->table('type_outcome_probe')->where('id', '=', 2)->first() === null, '拒绝 DDL 后没有确认回滚');
            foreach (['COMMIT', 'ROLLBACK', 'SELECT 1; COMMIT', '/* 注释 */ COMMIT'] as $sql) {
                $rejected = false;
                try {
                    $connection->transaction(static fn (Connection $transaction): int => $transaction->raw($sql));
                } catch (DatabaseException) {
                    $rejected = true;
                }
                outcomeExpect($rejected, '事务控制 SQL 没有拒绝');
            }
            if ($mode !== 'mysql') {
                $connection->transaction(static fn (Connection $transaction): int => $transaction->raw('CREATE TEMPORARY TABLE type_schema_transaction (id INTEGER)'), 'schema');
            }
            $started = false;
            try {
                $connection->transaction(static function (Connection $transaction) use (&$started): void {
                    $started = true;
                }, 'invalid-mode');
            } catch (TransactionException $error) {
                outcomeExpect($error->outcome() === TransactionOutcome::NOT_STARTED, '无效模式状态错误');
            }
            outcomeExpect(!$started && $connection->transactionOutcome() === TransactionOutcome::NOT_STARTED, '失败开始执行了事务体');
            echo "事务结果、提交后回调、失败汇总与边界 SQL 拒绝通过。\n";
        });
    } finally {
        $scope->close();
        $database->close();
        $rwManager?->close();
    }
}
