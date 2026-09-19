<?php

declare(strict_types=1);

use Type\Orm\Migration\MigrationConsole;
use Type\Orm\Migration\Migrator;
use TypeApp\Migrations\DriverFactory;
use TypeApp\Migrations\Plan;

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
