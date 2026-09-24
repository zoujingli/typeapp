<?php

declare(strict_types=1);

use Type\Orm\Migration\MigrationConsole;
use Type\Orm\Migration\Migrator;
use TypeApp\Migrations\DriverFactory;
use TypeApp\Migrations\Plan;

/**
 * 将显式参数交给迁移控制台，并把非零状态传递给宿主进程。
 *
 * @param list<string> $argv 程序路径与该示例的显式参数。
 */
function main(int $argc, array $argv): void
{
    $driver = DriverFactory::create();
    $prefix = getenv('TYPE_MIGRATION_PREFIX') ?: 'type_example';
    $console = new MigrationConsole(new Migrator($driver, $prefix . '_migrations'), Plan::migrations($driver->name(), $prefix));
    $status = $console->run(array_slice($argv, 1));
    if ($status !== 0) {
        exit($status);
    }
}
