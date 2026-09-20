<?php

declare(strict_types=1);

namespace TypeApp\OrmSuite;

use Type\Orm\Driver;
use Type\Orm\Pgsql\PgsqlDriver;

final class DriverFactory
{
    /** 按独立消费者配置建立驱动；代次用于真实租约轮换验收。 */
    public static function create(string $role = 'writer', int $generation = 1): Driver
    {
        return new PgsqlDriver(
            (string) (getenv('TYPE_PGSQL_HOST') ?: '127.0.0.1'),
            (int) (getenv('TYPE_PGSQL_PORT') ?: 5432),
            (string) (getenv('TYPE_PGSQL_DATABASE') ?: 'type_app_test'),
            (string) (getenv('TYPE_PGSQL_USER') ?: 'type_app'),
            (string) (getenv('TYPE_PGSQL_PASSWORD') ?: ''),
            $generation,
            $role
        );
    }
}
