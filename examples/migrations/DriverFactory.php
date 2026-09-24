<?php

declare(strict_types=1);

namespace TypeApp\Migrations;

use Type\Orm\Driver;
use Type\Orm\Mysql\MysqlDriver;
use Type\Orm\Pgsql\PgsqlDriver;
use Type\Orm\Sqlite\SqliteDriver;

/** 从示例专用环境变量选择三库驱动，不在组件内自动读取业务配置。 */
final class DriverFactory
{
    /** 按显式测试配置构造驱动；SQLite 未指定文件时使用示例默认路径。 */
    public static function create(): Driver
    {
        $driver = getenv('TYPE_MIGRATION_DRIVER') ?: 'sqlite';
        if ($driver === 'mysql') {
            return new MysqlDriver(
                getenv('TYPE_MYSQL_HOST') ?: '127.0.0.1',
                (int) (getenv('TYPE_MYSQL_PORT') ?: '3306'),
                getenv('TYPE_MYSQL_DATABASE') ?: 'type_app_test',
                getenv('TYPE_MYSQL_USER') ?: 'root',
                getenv('TYPE_MYSQL_PASSWORD') ?: ''
            );
        }
        if ($driver === 'pgsql') {
            return new PgsqlDriver(
                getenv('TYPE_PGSQL_HOST') ?: '127.0.0.1',
                (int) (getenv('TYPE_PGSQL_PORT') ?: '5432'),
                getenv('TYPE_PGSQL_DATABASE') ?: 'type_app_test',
                getenv('TYPE_PGSQL_USER') ?: 'type_app',
                getenv('TYPE_PGSQL_PASSWORD') ?: ''
            );
        }
        if ($driver !== 'sqlite') {
            throw new \InvalidArgumentException('迁移驱动无效');
        }

        return new SqliteDriver(getenv('TYPE_SQLITE_FILE') ?: '/tmp/type-app-migrations.sqlite', 100, true);
    }
}
