<?php

declare(strict_types=1);

use Type\Orm\Database;
use Type\Orm\DatabaseException;
use Type\Orm\DatabaseManager;
use Type\Orm\Mysql\MysqlDriver;
use Type\Runtime\ExecutionScope;

/** 仅从调用者显式提供的测试环境构造只读连接，不加载应用配置或业务数据。 */
function timezoneDriver(string $zone = '+00:00', int $generation = 1): MysqlDriver
{
    return new MysqlDriver(
        (string) getenv('TYPE_MYSQL_HOST'),
        (int) getenv('TYPE_MYSQL_PORT'),
        (string) getenv('TYPE_MYSQL_DATABASE'),
        (string) getenv('TYPE_MYSQL_USER'),
        (string) getenv('TYPE_MYSQL_PASSWORD'),
        $generation,
        'reader',
        null,
        $zone
    );
}

/** 记录真实连接入口可观察的结果；任何差异立即失败。 */
function timezoneCheck(array &$checks, string $name, bool $passed): void
{
    if (!$passed) {
        throw new RuntimeException('MySQL 会话时区验证失败：' . $name);
    }
    $checks[] = $name;
}

/** 使用同一组无业务表 SELECT 验证 PHP/AOT 会话语义与配置轮换。 */
function main(int $argc, array $argv): void
{
    if (getenv('TYPE_MYSQL_HOST') !== '127.0.0.1' || (int) getenv('TYPE_MYSQL_PORT') < 1
        || getenv('TYPE_MYSQL_DATABASE') !== 'information_schema') {
        throw new RuntimeException('本专项仅接受显式回环 MySQL 测试端点和 information_schema');
    }
    $checks = [];
    foreach (['', 'UTC', 'Asia/Shanghai', 'system', '+8:00', '+00:60', '+14:01', '-14:00', "+08:00\n", "+08:00'; SELECT 1 --"] as $invalid) {
        $rejected = false;
        try {
            timezoneDriver($invalid);
        } catch (DatabaseException $error) {
            $rejected = $error->getMessage() === 'MySQL 会话时区无效';
        }
        timezoneCheck($checks, 'invalid:' . bin2hex($invalid), $rejected);
    }
    $named = new MysqlDriver('127.0.0.1', 1, 'unreachable', 'tester', '', timeZone: 'SYSTEM');
    timezoneCheck($checks, 'named_parameter', $named->identity()['session']['timezone'] === 'SYSTEM');
    if (($argv[1] ?? '') === 'initialization-failure') {
        $database = new Database(timezoneDriver('+08:00'), 1, 0);
        $scope = new ExecutionScope();
        try {
            $failed = false;
            try {
                $database->connect($scope);
            } catch (DatabaseException $error) {
                $previous = $error->getPrevious();
                $failed = $error->getMessage() === 'MySQL 连接失败' && $previous instanceof PDOException
                    && ($previous->errorInfo[1] ?? 0) === 1298;
            }
            timezoneCheck($checks, 'initialization_failure_propagated', $failed);
            timezoneCheck($checks, 'failed_connection_released', $database->statistics()['created'] === 0 && $database->statistics()['leased'] === 0);
        } finally {
            $scope->close();
            $database->close();
        }
    } else {
        $default = new MysqlDriver(
            (string) getenv('TYPE_MYSQL_HOST'),
            (int) getenv('TYPE_MYSQL_PORT'),
            'information_schema',
            (string) getenv('TYPE_MYSQL_USER'),
            (string) getenv('TYPE_MYSQL_PASSWORD'),
            1,
            'reader'
        );
        $pdo = $default->connect();
        timezoneCheck($checks, 'default_utc', $default->identity()['session']['timezone'] === '+00:00'
            && $pdo->query('SELECT @@SESSION.time_zone')->fetchColumn() === '+00:00');
        $system = (string) $pdo->query("SELECT CONVERT_TZ('2000-01-01 00:00:00', '+00:00', 'SYSTEM')")->fetchColumn();
        $global = (string) $pdo->query('SELECT @@GLOBAL.time_zone')->fetchColumn();
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        foreach (['+08:00' => '2000-01-01 08:00:00', '+05:45' => '2000-01-01 05:45:00', '-05:30' => '1999-12-31 18:30:00',
            '-13:59' => '1999-12-31 10:01:00', '+14:00' => '2000-01-01 14:00:00', '-00:00' => '2000-01-01 00:00:00', 'SYSTEM' => $system] as $zone => $expected) {
            $driver = timezoneDriver($zone);
            $connection = $driver->connect();
            $row = $connection->query("SELECT @@SESSION.time_zone AS zone, @@SESSION.sql_mode AS mode, @@SESSION.transaction_read_only AS readonly,
                @@character_set_connection AS charset, FROM_UNIXTIME(946684800) AS instant, UNIX_TIMESTAMP(FROM_UNIXTIME(946684800)) AS epoch,
                CAST('2000-01-01 00:00:00' AS DATETIME) AS wall_time")->fetch(PDO::FETCH_ASSOC);
            timezoneCheck($checks, $zone . ':session_identity', $row['zone'] === $driver->identity()['session']['timezone']);
            timezoneCheck($checks, $zone . ':instant', $row['instant'] === $expected && (int) $row['epoch'] === 946684800);
            timezoneCheck($checks, $zone . ':datetime_unchanged', $row['wall_time'] === '2000-01-01 00:00:00');
            timezoneCheck($checks, $zone . ':baseline', str_contains((string) $row['mode'], 'STRICT_ALL_TABLES')
                && $row['charset'] === 'utf8mb4' && (int) $row['readonly'] === 1
                && !$connection->getAttribute(PDO::ATTR_EMULATE_PREPARES) && !$connection->getAttribute(PDO::ATTR_STRINGIFY_FETCHES)
                && $connection->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION);
            unset($connection);
        }
        timezoneCheck($checks, 'other_session_unchanged', $pdo->query('SELECT @@SESSION.time_zone')->fetchColumn() === '+00:00');
        timezoneCheck($checks, 'global_unchanged', $pdo->query('SELECT @@GLOBAL.time_zone')->fetchColumn() === $global);
        unset($pdo);
        $manager = new DatabaseManager(['default' => timezoneDriver()], 1, 0);
        $scope = new ExecutionScope();
        try {
            $old = $manager->connect($scope);
            $rejected = false;
            try {
                $manager->rotate('default', timezoneDriver('+08:00'));
            } catch (DatabaseException $error) {
                $rejected = true;
            }
            timezoneCheck($checks, 'generation_required', $rejected);
            $manager->rotate('default', timezoneDriver('+08:00', 2));
            $next = $manager->connect($scope);
            timezoneCheck($checks, 'old_lease_keeps_timezone', $old->identity()['session']['timezone'] === '+00:00'
                && $old->query('SELECT @@SESSION.time_zone AS zone')[0]['zone'] === '+00:00');
            timezoneCheck($checks, 'new_lease_uses_timezone', $next->identity()['session']['timezone'] === '+08:00'
                && $next->query('SELECT @@SESSION.time_zone AS zone')[0]['zone'] === '+08:00');
            $old->close();
            $next->close();
            timezoneCheck($checks, 'retired_generation_drained', ($manager->statistics()['retired-generations']['default'] ?? 0) === 0);
            $again = $manager->connect($scope);
            timezoneCheck($checks, 'reconnect_reapplies_timezone', $again->query('SELECT @@SESSION.time_zone AS zone')[0]['zone'] === '+08:00');
            $again->close();
            timezoneCheck($checks, 'pool_closed_connections', $manager->statistics()['active']['default']['created'] === 0);
        } finally {
            $scope->close();
            $manager->close();
        }
        timezoneCheck($checks, 'mysql_version_observed', $version !== '');
    }
    echo json_encode(['ok' => true, 'checks' => $checks], JSON_THROW_ON_ERROR), "\n";
}
