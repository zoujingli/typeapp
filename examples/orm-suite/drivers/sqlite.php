<?php

declare(strict_types=1);

namespace TypeApp\OrmSuite;

use Type\Orm\Driver;
use Type\Orm\Sqlite\SqliteDriver;

final class DriverFactory
{
    /** 按独立消费者配置建立驱动；代次用于真实租约轮换验收。 */
    public static function create(string $role = 'writer', int $generation = 1): Driver
    {
        return new SqliteDriver((string) (getenv('TYPE_SQLITE_FILE') ?: '/tmp/type-orm-suite.sqlite'), 5000, true, $generation, $role);
    }
}
