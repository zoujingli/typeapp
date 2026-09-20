<?php

declare(strict_types=1);

use Type\Orm\Connection;
use Type\Orm\DatabaseManager;
use Type\Orm\Db;
use Type\Orm\Driver;
use Type\Orm\Mysql\MysqlDriver;
use Type\Orm\Pgsql\PgsqlDriver;
use Type\Orm\Sqlite\SqliteDriver;
use Type\Runtime\ExecutionScope;
use TypeApp\ModelExample\ReadWriteProbe;

function readWriteDriver(string $driver, string $database, string $role, bool $broken = false): Driver
{
    if ($driver === 'sqlite') {
        return new SqliteDriver($database, 1000, true, 1, $role);
    }
    if ($driver === 'mysql') {
        $prefix = $role === 'reader' ? 'TYPE_READER_MYSQL_' : 'TYPE_MYSQL_';
        return new MysqlDriver(
            (string) (getenv($prefix . 'HOST') ?: '127.0.0.1'),
            $broken ? 1 : (int) (getenv($prefix . 'PORT') ?: 3306),
            $database,
            (string) (getenv($prefix . 'USER') ?: 'root'),
            (string) (getenv($prefix . 'PASSWORD') ?: ''),
            1,
            $role
        );
    }
    $prefix = $role === 'reader' ? 'TYPE_READER_PGSQL_' : 'TYPE_PGSQL_';
    return new PgsqlDriver(
        (string) (getenv($prefix . 'HOST') ?: '127.0.0.1'),
        $broken ? 1 : (int) (getenv($prefix . 'PORT') ?: 5432),
        $database,
        (string) (getenv($prefix . 'USER') ?: 'type_app'),
        (string) (getenv($prefix . 'PASSWORD') ?: ''),
        1,
        $role
    );
}

function readWriteExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function main(int $argc, array $argv): void
{
    \Type\Runtime\CoroutineRuntime::run(static function () use ($argv): void {
        $driver = (string) ($argv[1] ?? 'sqlite');
        $database = (string) getenv('TYPE_PRIMARY_DATABASE');
        $writer = readWriteDriver($driver, $database, 'writer');
        $manager = new DatabaseManager(['default' => ['master' => $writer,
            'reader' => $driver === 'sqlite' ? null : readWriteDriver($driver, $database, 'reader')]], 4, 1);
        Db::configure($manager);
        $scope = new ExecutionScope();
        try {
            $scope->run(static function (ExecutionScope $current) use ($manager, $driver): void {
                $base = ReadWriteProbe::query();
                readWriteExpect($manager->statistics()['active'] === [], '构造查询提前借用数据库');
                $expected = $driver === 'sqlite' ? '主库已有' : '副本旧值';
                readWriteExpect($base->findOrFail(1)->getValue() === $expected, '普通读取没有使用配置端点');
                readWriteExpect($base->master()->with('children')->withCount('children')->findOrFail(1)->related('children')[0]->getValue() === '主库子', 'master 或关系没有沿用主库');
                $beforeTransaction = ReadWriteProbe::query();
                Db::transaction(static function () use ($beforeTransaction, $driver): void {
                    readWriteExpect($beforeTransaction->findOrFail(1)->getValue() === '主库已有', '事务前构造查询没有在执行时选主');
                    if ($driver !== 'sqlite') {
                        readWriteExpect($beforeTransaction->lockForUpdate()->findOrFail(1)->getValue() === '主库已有', '行锁没有使用主库');
                    }
                    (new ReadWriteProbe(['id' => 2, 'value' => '本次写入', 'parent_id' => 0]))->save();
                });
                readWriteExpect($base->findOrFail(1)->getValue() === $expected && $base->master()->findOrFail(2)->getValue() === '本次写入', '事务后普通读取错误粘主或提交没有持久化');
                if ($driver !== 'sqlite') {
                    readWriteExpect($base->find(2) === null && $base->with('children')->findOrFail(1)->related('children')[0]->getValue() === '副本子', '副本延迟或普通关系选路错误');
                    $fromReader = $base->findOrFail(1);
                    $fromReader->setValue('从库模型写主');
                    $fromReader->save();
                    readWriteExpect($base->findOrFail(1)->getValue() === '副本旧值' && $base->master()->findOrFail(1)->getValue() === '从库模型写主', '从库模型保存没有转主或影响后续读取');
                    readWriteExpect($base->where('id', '=', 1)->increment('parent_id') === 1 && $base->master()->findOrFail(1)->getParentId() === 1, '原子写入没有选择主库');
                }
            });
        } finally {
            $scope->close();
            $manager->close();
        }
        if ($driver !== 'sqlite') {
            $failedManager = new DatabaseManager(['default' => ['master' => $writer, 'reader' => readWriteDriver($driver, $database, 'reader', true)]]);
            Db::configure($failedManager);
            $failedScope = new ExecutionScope();
            try {
                $failedScope->run(static function (ExecutionScope $current) use ($failedManager): void {
                    $rejected = false;
                    try {
                        ReadWriteProbe::query()->first();
                    } catch (\Type\Orm\ModelException $error) {
                        $rejected = $error->errorCode() === 'reader_unavailable';
                    }
                    readWriteExpect($rejected && !isset($failedManager->statistics()['active']['default']), '副本失败没有明确报错或转投了主库');
                    readWriteExpect(ReadWriteProbe::query()->master()->findOrFail(2)->getValue() === '本次写入', '显式主库被副本失败影响');
                });
            } finally {
                $failedScope->close();
                $failedManager->close();
            }
        }
        echo "模型执行期选路、显式主读、事务固定与写后不粘主通过。\n";
    });
}
