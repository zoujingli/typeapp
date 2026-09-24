<?php

declare(strict_types=1);

use Type\Orm\Database;
use Type\Orm\DatabaseException;
use Type\Orm\Driver;
use Type\Orm\Mysql\MysqlDriver;
use Type\Orm\Pgsql\PgsqlDriver;
use Type\Redis\RedisConfiguration;
use Type\Redis\RedisException;
use Type\Redis\RedisManager;
use Type\Runtime\ExecutionScope;

/** 使用专用测试端口；未设置时保留既有各驱动默认端口。 */
function tlsPort(int $default): int
{
    $value = getenv('TYPE_TLS_PORT');
    if ($value === false || $value === '') {
        return $default;
    }
    if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 65535) {
        throw new InvalidArgumentException('TYPE_TLS_PORT必须是有效端口');
    }
    return (int) $value;
}

/** 为受控 TLS 数据库创建带显式 CA 的驱动，凭据仅从运行环境读取。 */
function tlsDriver(string $kind, string $host, string $ca): Driver
{
    $password = (string) getenv('TYPE_TLS_PASSWORD');
    if ($kind === 'mysql') {
        return new MysqlDriver($host, tlsPort(3306), 'type_app_test', 'root', $password, caFile: $ca);
    }
    if ($kind === 'pgsql') {
        return new PgsqlDriver($host, tlsPort(5432), 'type_app_test', 'type_app', $password, caFile: $ca);
    }
    throw new InvalidArgumentException('未知 TLS 数据库驱动');
}

/** 建立一次真实数据库连接并检查 TLS 身份，finally 关闭作用域与实例。 */
function tlsDatabase(Driver $driver): void
{
    $database = new Database($driver, 1, 0);
    $scope = new ExecutionScope();
    try {
        $connection = $database->connect($scope);
        $identity = $connection->identity();
        if ($identity['ca-sha256'] === '' || $identity['tls'] === 'disabled') {
            throw new RuntimeException('连接身份缺少 TLS 信任信息');
        }
        if ($driver->name() === 'mysql') {
            $row = $connection->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")[0];
            if (($row['Value'] ?? '') === '') {
                throw new RuntimeException('MySQL 实际连接未启用 TLS');
            }
        } else {
            $row = $connection->query('SELECT CASE WHEN ssl THEN 1 ELSE 0 END AS secured FROM pg_stat_ssl WHERE pid = pg_backend_pid()')[0];
            if ((int) $row['secured'] !== 1) {
                throw new RuntimeException('PostgreSQL 实际连接未启用 TLS');
            }
        }
    } finally {
        $scope->close();
        $database->close();
        if ($database->statistics()['leased'] !== 0) {
            throw new RuntimeException('TLS 连接退出后仍占用租约');
        }
    }
}

/** 向显式 TLS Redis 发送 PING，结束时关闭连接作用域和管理器。 */
function tlsRedis(string $host, string $ca): void
{
    $manager = new RedisManager(['tls' => new RedisConfiguration($host, tlsPort(6379), tls: true, caFile: $ca)]);
    $scope = new ExecutionScope();
    try {
        if ($manager->connection($scope, 'tls')->command('PING') !== true) {
            throw new RuntimeException('Redis TLS 请求没有收到真实响应');
        }
    } finally {
        $scope->close();
        $manager->close();
    }
}

/**
 * 执行负向 TLS 探针，只识别数据库或 Redis 的连接失败。
 *
 * @param Closure(): void $operation 连接或查询的负向演练。
 */
function tlsRejected(Closure $operation): bool
{
    try {
        $operation();
    } catch (DatabaseException|RedisException) {
        return true;
    }
    return false;
}

/**
 * 连接受控数据库或 Redis，分别验证正确信任链、错误 CA 和主机名拒绝。
 *
 * @param list<string> $argv 程序路径与该示例的显式参数。
 */
function main(int $argc, array $argv): void
{
    $kind = $argv[1] ?? '';
    $host = (string) getenv('TYPE_TLS_HOST');
    $wrongHost = (string) getenv('TYPE_TLS_WRONG_HOST');
    $ca = (string) getenv('TYPE_TLS_CA');
    $wrongCa = (string) getenv('TYPE_TLS_WRONG_CA');
    if ($kind === 'redis') {
        tlsRedis($host, $ca);
        if (!tlsRejected(static function () use ($wrongHost, $ca): void {
            tlsRedis($wrongHost, $ca);
        })
            || !tlsRejected(static function () use ($host, $wrongCa): void {
                tlsRedis($host, $wrongCa);
            })) {
            throw new RuntimeException('Redis 接受了错误主机名或不可信 CA');
        }
    } else {
        tlsDatabase(tlsDriver($kind, $host, $ca));
        if (!tlsRejected(static function () use ($kind, $wrongHost, $ca): void {
            tlsDatabase(tlsDriver($kind, $wrongHost, $ca));
        })
            || !tlsRejected(static function () use ($kind, $host, $wrongCa): void {
                tlsDatabase(tlsDriver($kind, $host, $wrongCa));
            })) {
            throw new RuntimeException('数据库接受了错误主机名或不可信 CA');
        }
        $copy = tempnam(sys_get_temp_dir(), 'type_tls_ca_');
        if ($copy === false) {
            throw new RuntimeException('无法准备 CA 变更检查');
        }
        try {
            if (!copy($ca, $copy)) {
                throw new RuntimeException('无法复制测试 CA');
            }
            $driver = tlsDriver($kind, $host, $copy);
            if (!copy($wrongCa, $copy) || !tlsRejected(static function () use ($driver): void {
                tlsDatabase($driver);
            })) {
                throw new RuntimeException('CA 变化没有使原连接身份失效');
            }
        } finally {
            unlink($copy);
        }
    }
    echo $kind . " TLS 信任链、主机名和错误 CA 拒绝通过。\n";
}
