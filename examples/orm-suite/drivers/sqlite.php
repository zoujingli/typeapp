<?php

declare(strict_types=1);

namespace TypeApp\OrmSuite;

use Type\Orm\Driver;
use Type\Orm\Sqlite\SqliteDriver;

final class DriverFactory
{
    public static function create(string $role = 'writer'): Driver
    {
        return new SqliteDriver((string) (getenv('TYPE_SQLITE_FILE') ?: '/tmp/type-orm-suite.sqlite'), 5000, true, 1, $role);
    }
}
