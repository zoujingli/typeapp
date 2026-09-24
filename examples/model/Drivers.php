<?php

declare(strict_types=1);

namespace TypeApp\ModelExample;

use InvalidArgumentException;
use Type\Orm\Driver;
use Type\Orm\Mysql\MysqlDriver;
use Type\Orm\Pgsql\PgsqlDriver;
use Type\Orm\Sqlite\SqliteDriver;

/** 为独立模型示例选择数据库实现，生产应用应使用自己的配置装配。 */
final class Drivers
{
    /** 为受控示例选择驱动；可显式指定独立数据库及读写用途，默认保留现有测试环境。 */
    public static function create(string $name, ?string $database = null, string $role = 'writer'): Driver
    {
        if ($name === 'sqlite') {
            return new SqliteDriver($database ?? (string) (getenv('TYPE_SQLITE_FILE') ?: ':memory:'), 1000, true, 1, $role);
        }
        if ($name === 'mysql') {
            return new MysqlDriver(
                (string) (getenv('TYPE_MYSQL_HOST') ?: '127.0.0.1'),
                (int) (getenv('TYPE_MYSQL_PORT') ?: 3306),
                $database ?? (string) (getenv('TYPE_MYSQL_DATABASE') ?: 'type_app_test'),
                (string) (getenv('TYPE_MYSQL_USER') ?: 'root'),
                (string) (getenv('TYPE_MYSQL_PASSWORD') ?: ''),
                1,
                $role
            );
        }
        if ($name === 'pgsql') {
            return new PgsqlDriver(
                (string) (getenv('TYPE_PGSQL_HOST') ?: '127.0.0.1'),
                (int) (getenv('TYPE_PGSQL_PORT') ?: 5432),
                $database ?? (string) (getenv('TYPE_PGSQL_DATABASE') ?: 'type_app_test'),
                (string) (getenv('TYPE_PGSQL_USER') ?: 'type_app'),
                (string) (getenv('TYPE_PGSQL_PASSWORD') ?: ''),
                1,
                $role
            );
        }
        throw new InvalidArgumentException('未知模型数据库驱动');
    }
}
