<?php

declare(strict_types=1);

use Type\Orm\Connection;
use Type\Orm\DatabaseManager;
use Type\Orm\Driver;
use Type\Orm\Mysql\MysqlDriver;
use Type\Orm\Pgsql\PgsqlDriver;
use Type\Orm\ReadWriteSession;
use Type\Orm\Sqlite\SqliteDriver;
use Type\Runtime\ExecutionScope;

function readWriteDriver(string $driver, string $database, string $role): Driver
{
    if ($driver === 'sqlite') {
        return new SqliteDriver($database, 1000, true, 1, $role);
    }
    if ($driver === 'mysql') {
        return new MysqlDriver(
            (string) (getenv('TYPE_MYSQL_HOST') ?: '127.0.0.1'),
            (int) (getenv('TYPE_MYSQL_PORT') ?: 3306),
            $database,
            (string) (getenv('TYPE_MYSQL_USER') ?: 'root'),
            (string) (getenv('TYPE_MYSQL_PASSWORD') ?: ''),
            1,
            $role
        );
    }
    return new PgsqlDriver(
        (string) (getenv('TYPE_PGSQL_HOST') ?: '127.0.0.1'),
        (int) (getenv('TYPE_PGSQL_PORT') ?: 5432),
        $database,
        (string) (getenv('TYPE_PGSQL_USER') ?: 'type_app'),
        (string) (getenv('TYPE_PGSQL_PASSWORD') ?: ''),
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
    $driver = (string) ($argv[1] ?? 'sqlite');
    $manager = new DatabaseManager(['primary' => readWriteDriver($driver, (string) getenv('TYPE_PRIMARY_DATABASE'), 'writer'),
        'replica' => readWriteDriver($driver, (string) getenv('TYPE_REPLICA_DATABASE'), 'reader')], 4, 1);
    $scope = new ExecutionScope();
    $nextScope = new ExecutionScope();
    try {
        $session = new ReadWriteSession($manager, $scope);
        readWriteExpect($session->read()->identity()['role'] === 'reader' && $session->read()->table('type_rw_probe')->first() === null, '默认读取没有选择延迟副本');
        readWriteExpect($session->read(true)->identity()['role'] === 'writer' && $session->read(true)->table('type_rw_probe')->where('id', '=', 1)->first()['value'] === '主库已有', '强一致请求被路由到副本');
        $session->transaction(static function (Connection $transaction) use ($session): void {
            readWriteExpect($session->read() === $transaction, '事务内读取没有固定主连接');
            $transaction->table('type_rw_probe')->insert(['id' => 2, 'value' => '本次写入']);
        });
        readWriteExpect($session->outcome() === 'COMMITTED' && $session->read()->table('type_rw_probe')->where('id', '=', 2)->first()['value'] === '本次写入', '提交后没有保持主库粘滞');
        $next = new ReadWriteSession($manager, $nextScope);
        readWriteExpect($next->read()->identity()['role'] === 'reader' && $next->read()->table('type_rw_probe')->first() === null, '新请求继承了旧请求的粘滞状态');
        $rejected = false;
        try {
            $next->read()->table('type_rw_probe')->insert(['id' => 99, 'value' => '副本不能写']);
        } catch (\Type\Orm\DatabaseException) {
            $rejected = true;
        }
        readWriteExpect($rejected, '副本连接接受了业务写入');
        echo "副本延迟、显式主读、事务固定与每次执行粘滞隔离通过。\n";
    } finally {
        $scope->close();
        $nextScope->close();
        $manager->close();
    }
}
