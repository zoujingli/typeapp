<?php

declare(strict_types=1);

use Type\Orm\Database;
use Type\Runtime\ExecutionScope;
use TypeApp\OrmSuite\DriverFactory;
use TypeApp\OrmSuite\Suite;

function main(int $argc, array $argv): void
{
    $driver = DriverFactory::create();
    $classes = ['mysql' => 'Type\\Orm\\Mysql\\MysqlDriver', 'pgsql' => 'Type\\Orm\\Pgsql\\PgsqlDriver', 'sqlite' => 'Type\\Orm\\Sqlite\\SqliteDriver'];
    foreach ($classes as $name => $class) {
        if ($name !== $driver->name() && class_exists($class)) {
            throw new RuntimeException('独立业务应用混入了未选驱动源码');
        }
    }
    if (($argv[1] ?? 'run') === 'run') {
        $result = Suite::run();
        $result['pdo_extensions'] = PDO::getAvailableDrivers();
        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        return;
    }
    $scope = new ExecutionScope();
    $database = new Database($driver, 1, 0);
    try {
        $connection = $database->connect($scope);
        $id = (int) ($argv[2] ?? 0);
        if ($id < 1) {
            throw new InvalidArgumentException('并发验证需要有效文章主键');
        }
        if ($argv[1] === 'race') {
            echo Suite::race($connection, $id) . PHP_EOL;
        } elseif ($argv[1] === 'verify') {
            echo json_encode(Suite::verifyRace($connection, $id), JSON_THROW_ON_ERROR) . PHP_EOL;
        } elseif ($argv[1] === 'increment') {
            echo Suite::incrementRace($connection, $id) . PHP_EOL;
        } elseif ($argv[1] === 'verify-increment') {
            echo json_encode(Suite::verifyIncrementRace($connection, $id), JSON_THROW_ON_ERROR) . PHP_EOL;
        } else {
            throw new InvalidArgumentException('未知三库业务命令');
        }
    } finally {
        $scope->close();
        $database->close();
    }
}
