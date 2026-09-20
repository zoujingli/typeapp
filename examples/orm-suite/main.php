<?php

declare(strict_types=1);

use Type\Orm\DatabaseManager;
use Type\Orm\Db;
use Type\Runtime\ExecutionScope;
use TypeApp\OrmSuite\DriverFactory;
use TypeApp\OrmSuite\Suite;

function main(int $argc, array $argv): void
{
    \Type\Runtime\CoroutineRuntime::enableIo();
    \Type\Runtime\CoroutineRuntime::run(static function () use ($argv): void {
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
            $result['swoole_hook_flags'] = \Swoole\Runtime::getHookFlags();
            $result['swoole_pdo_drivers'] = [];
            foreach (['pgsql' => 'SWOOLE_HOOK_PDO_PGSQL', 'sqlite' => 'SWOOLE_HOOK_PDO_SQLITE'] as $name => $hook) {
                if (defined($hook) && in_array($name, $result['pdo_extensions'], true)) {
                    $result['swoole_pdo_drivers'][] = $name;
                }
            }
            echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL;
            return;
        }
        $scope = new ExecutionScope();
        $database = new DatabaseManager(['default' => $driver], 1, 0);
        Db::configure($database);
        try {
            $scope->run(static function (ExecutionScope $current) use ($argv): void {
                $connection = Db::connection('default', true);
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
            });
        } finally {
            $scope->close();
            $database->close();
        }
    });
}
