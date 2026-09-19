<?php

declare(strict_types=1);

namespace app\common\database;

use app\common\bootstrap\Settings;
use InvalidArgumentException;
use Type\Core\Config\Repository;
use Type\Orm\DatabaseException;
use Type\Orm\Driver;
use Type\Orm\Mysql\MysqlDriver;
use Type\Orm\Pgsql\PgsqlDriver;
use Type\Orm\Sqlite\SqliteDriver;

/**
 * 从物联中心标准项目配置选择已由 Composer 安装的驱动，并控制 SQLite 初始化副作用。
 *
 * 驱动构造不建立网络连接；实际连接由迁移角色或请求作用域中的数据库管理器持有。
 */
final class DatabaseFactory
{
    /** @throws InvalidArgumentException 驱动名称不在当前应用允许的三库列表中。 */
    public static function name(Repository $configuration): string
    {
        $driver = $configuration->text('database.driver');
        if (!in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new InvalidArgumentException('DB_DRIVER 只接受 mysql、pgsql 或 sqlite');
        }

        return $driver;
    }

    /**
     * 验证数据库配置的结构，不创建连接或 SQLite 文件；配置检查允许迁移前的数据目录尚未出现。
     *
     * 运行期仍通过 create() 保持 SQLite 驱动对已有本地目录的约束。
     */
    public static function validate(Repository $configuration, string $basePath): void
    {
        if (self::name($configuration) === 'sqlite') {
            self::sqliteFile($configuration, $basePath);
            return;
        }

        // MySQL/PostgreSQL 驱动构造只校验连接参数和 TLS 文件，不会建立网络连接。
        self::create($configuration, $basePath);
    }

    /**
     * 创建驱动配置对象；不会迁移、创建数据库账号或连接外部数据库。
     *
     * @throws DatabaseException 驱动参数或本地 CA 文件无效。
     * @throws InvalidArgumentException 应用配置、端口或 SQLite 路径无效。
     */
    public static function create(Repository $configuration, string $basePath): Driver
    {
        $driver = self::name($configuration);
        if ($driver === 'sqlite') {
            return new SqliteDriver(self::sqliteFile($configuration, $basePath), 1000, true);
        }
        $port = Settings::integer($configuration, 'database.port', 0, 65535);
        if ($port === 0) {
            $port = $driver === 'mysql' ? 3306 : 5432;
        }
        $ca = $configuration->text('database.tls_ca');
        if ($ca !== '' && !Settings::absolutePath($ca)) {
            $ca = $basePath . '/' . $ca;
        }
        if ($driver === 'mysql') {
            return new MysqlDriver(
                $configuration->text('database.host'),
                $port,
                $configuration->text('database.database'),
                $configuration->text('database.username'),
                $configuration->text('database.password'),
                1,
                'writer',
                $ca === '' ? null : $ca
            );
        }

        return new PgsqlDriver(
            $configuration->text('database.host'),
            $port,
            $configuration->text('database.database'),
            $configuration->text('database.username'),
            $configuration->text('database.password'),
            1,
            'writer',
            null,
            null,
            $ca === '' ? null : $ca
        );
    }

    /**
     * 仅精确的 migrate run 命令调用；按需创建私有 SQLite 数据目录，不删除已有数据。
     *
     * @throws InvalidArgumentException 本地数据库路径无效或目录无法创建。
     */
    public static function prepareMigration(Repository $configuration, string $basePath): void
    {
        if (self::name($configuration) !== 'sqlite') {
            return;
        }
        $directory = dirname(self::sqliteFile($configuration, $basePath));
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new InvalidArgumentException('无法创建 SQLite 示例数据目录');
        }
    }

    /**
     * 非初始化命令拒绝 SQLite 隐式建库；存在文件不等于迁移版本已全部就绪。
     *
     * @throws InvalidArgumentException SQLite 文件不存在，需先显式运行 migrate run。
     */
    public static function requireExisting(Repository $configuration, string $basePath): void
    {
        if (self::name($configuration) === 'sqlite' && !is_file(self::sqliteFile($configuration, $basePath))) {
            throw new InvalidArgumentException('SQLite 示例库尚未初始化，请先运行 migrate run');
        }
    }

    /** 将相对配置解释为应用根下的数据路径；此跨命令持久化示例不接受内存库。 */
    private static function sqliteFile(Repository $configuration, string $basePath): string
    {
        $filename = $configuration->text('database.sqlite_file');
        if ($filename === '' || $filename === ':memory:' || str_contains($filename, "\0") || str_ends_with($filename, '/')) {
            throw new InvalidArgumentException('DB_SQLITE_FILE 必须为本地数据库文件路径；示例不使用内存库');
        }
        $resolved = Settings::absolutePath($filename) ? $filename : $basePath . '/' . $filename;
        if (is_dir($resolved)) {
            throw new InvalidArgumentException('DB_SQLITE_FILE 不能指向目录');
        }

        return $resolved;
    }
}
