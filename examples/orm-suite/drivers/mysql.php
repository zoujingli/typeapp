<?php

declare(strict_types=1);

namespace TypeApp\OrmSuite;

use Type\Orm\Driver;
use Type\Orm\Mysql\MysqlDriver;

final class DriverFactory
{
    public static function create(string $role = 'writer'): Driver
    {
        return new MysqlDriver(
            (string) (getenv('TYPE_MYSQL_HOST') ?: '127.0.0.1'),
            (int) (getenv('TYPE_MYSQL_PORT') ?: 3306),
            (string) (getenv('TYPE_MYSQL_DATABASE') ?: 'type_app_test'),
            (string) (getenv('TYPE_MYSQL_USER') ?: 'root'),
            (string) (getenv('TYPE_MYSQL_PASSWORD') ?: ''),
            1,
            $role
        );
    }
}
