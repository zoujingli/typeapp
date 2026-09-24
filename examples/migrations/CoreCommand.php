<?php

declare(strict_types=1);

namespace TypeApp\Migrations;

use Type\Core\Command;
use Type\Core\Configuration;
use Type\Orm\Migration\MigrationConsole;
use Type\Orm\Migration\Migrator;

/** core 只装配目标命令；同一个 MigrationConsole 也用于独立 ORM 入口。 */
final class CoreCommand implements Command
{
    /**
     * 将框架命令的参数交给迁移控制台，返回其退出状态。
     *
     * @param list<string> $arguments 不含命令名称的控制台参数。
     */
    public function run(Configuration $configuration, array $arguments): int
    {
        $driver = DriverFactory::create();
        $prefix = getenv('TYPE_MIGRATION_PREFIX') ?: 'type_example';

        return (new MigrationConsole(new Migrator($driver, $prefix . '_migrations'), Plan::migrations($driver->name(), $prefix)))->run($arguments);
    }
}
