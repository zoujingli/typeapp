<?php

declare(strict_types=1);

use Type\Orm\Database;
use Type\Orm\DatabaseException;
use Type\Orm\DatabaseManager;
use Type\Orm\Driver;
use Type\Orm\Mysql\MysqlDriver;
use Type\Orm\Pgsql\PgsqlDriver;
use Type\Orm\Sqlite\SqliteDriver;
use Type\Runtime\ExecutionScope;

function identityDriver(string $name, int $generation = 1, string $role = 'writer', ?string $schema = null, ?string $password = null): Driver
{
    if ($name === 'sqlite') {
        return new SqliteDriver((string) getenv('TYPE_SQLITE_FILE'), 1000, true, $generation, $role);
    }
    if ($name === 'mysql') {
        return new MysqlDriver(
            (string) (getenv('TYPE_MYSQL_HOST') ?: '127.0.0.1'),
            (int) (getenv('TYPE_MYSQL_PORT') ?: 3306),
            (string) (getenv('TYPE_MYSQL_DATABASE') ?: 'type_app_test'),
            (string) (getenv('TYPE_MYSQL_USER') ?: 'root'),
            $password ?? (string) (getenv('TYPE_MYSQL_PASSWORD') ?: ''),
            $generation,
            $role
        );
    }
    return new PgsqlDriver(
        (string) (getenv('TYPE_PGSQL_HOST') ?: '127.0.0.1'),
        (int) (getenv('TYPE_PGSQL_PORT') ?: 5432),
        (string) (getenv('TYPE_PGSQL_DATABASE') ?: 'type_app_test'),
        (string) (getenv('TYPE_PGSQL_USER') ?: 'type_app'),
        $password ?? (string) (getenv('TYPE_PGSQL_PASSWORD') ?: ''),
        $generation,
        $role,
        $schema
    );
}

function identityExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function main(int $argc, array $argv): void
{
    $driver = (string) ($argv[1] ?? 'sqlite');
    $manager = new DatabaseManager(['default' => identityDriver($driver), 'reader' => identityDriver($driver, 1, 'reader')], 1, 1);
    $scope = new ExecutionScope();
    try {
        $connection = $manager->connect($scope);
        if (($argv[2] ?? '') === 'credentials') {
            $ready = (string) getenv('TYPE_IDENTITY_READY');
            $continue = (string) getenv('TYPE_IDENTITY_CONTINUE');
            identityExpect((int) $connection->query('SELECT 1 AS value')[0]['value'] === 1, '旧凭据无法建立初始会话');
            file_put_contents($ready, 'ready');
            $deadline = microtime(true) + 10;
            while (!is_file($continue)) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('密码轮换等待超时');
                } usleep(1000);
            }
            $manager->rotate('default', identityDriver($driver, 2, 'writer', null, (string) getenv('TYPE_IDENTITY_NEXT_PASSWORD')));
            $new = $manager->connect($scope);
            identityExpect($new->identity()['credential-generation'] === 2 && (int) $new->query('SELECT 2 AS value')[0]['value'] === 2
                && (int) $connection->query('SELECT 1 AS value')[0]['value'] === 1, '真实密码轮换没有保留旧租约并启用新凭据');
            $bad = new Database(identityDriver($driver), 1, 0);
            $rejected = false;
            try {
                $bad->connect($scope);
            } catch (DatabaseException) {
                $rejected = true;
            }
            $bad->close();
            identityExpect($rejected, '数据库仍接受已失效旧密码');
            echo "真实数据库密码轮换与旧租约排空通过。\n";
            return;
        }
        $identity = $connection->identity();
        identityExpect($identity['driver'] === $driver && $identity['credential-generation'] === 1 && $identity['role'] === 'writer'
            && !isset($identity['password']), '连接身份未包含配置或泄露了密码');
        $sessionId = $driver === 'mysql' ? $connection->query('SELECT CONNECTION_ID() AS id')[0]['id']
            : ($driver === 'pgsql' ? $connection->query('SELECT pg_backend_pid() AS id')[0]['id'] : null);
        // 即使调用者误用受管入口改变会话，下一租约也必须干净。
        if ($driver === 'mysql') {
            $connection->execute("SET time_zone = '+08:00'");
            $connection->execute('SET @type_identity_value = 77');
        } elseif ($driver === 'pgsql') {
            $connection->execute("SET TIME ZONE 'Asia/Shanghai'");
            $connection->execute('SET search_path TO pg_catalog');
        } else {
            $connection->execute('PRAGMA foreign_keys = OFF');
            $connection->execute('PRAGMA busy_timeout = 0');
        }
        $connection->execute('CREATE TEMPORARY TABLE type_identity_temp (id INTEGER)');
        $connection->close();
        $next = $manager->connect($scope);
        if ($driver === 'mysql') {
            $state = $next->query('SELECT CONNECTION_ID() AS id, @@time_zone AS zone, @type_identity_value AS value')[0];
            identityExpect($state['id'] !== $sessionId && $state['zone'] === '+00:00' && $state['value'] === null, 'MySQL 会话污染下一租约');
        } elseif ($driver === 'pgsql') {
            $state = $next->query("SELECT pg_backend_pid() AS id, current_setting('TimeZone') AS zone, current_schema() AS schema")[0];
            identityExpect($state['id'] !== $sessionId && $state['zone'] === 'UTC' && $state['schema'] !== 'pg_catalog', 'PostgreSQL 会话污染下一租约');
        } else {
            identityExpect((int) $next->query('PRAGMA foreign_keys')[0]['foreign_keys'] === 1 && (int) $next->query('PRAGMA busy_timeout')[0]['timeout'] === 1000, 'SQLite PRAGMA 污染下一租约');
        }
        $missing = false;
        try {
            $next->query('SELECT * FROM type_identity_temp');
        } catch (DatabaseException) {
            $missing = true;
        }
        identityExpect($missing, '临时表跨请求泄漏');
        $next->close();
        $old = $manager->connect($scope);
        $manager->rotate('default', identityDriver($driver, 2));
        $new = $manager->connect($scope);
        identityExpect($old->identity()['credential-generation'] === 1 && $new->identity()['credential-generation'] === 2
            && (int) $old->query('SELECT 1 AS value')[0]['value'] === 1, '轮换改变旧租约身份或阻止其安全收尾');
        $old->close();
        identityExpect(($manager->statistics()['retired-generations']['default'] ?? 0) === 0, '旧代池没有退役排空');
        $new->close();
        $reader = $manager->connect($scope, 'reader');
        identityExpect($reader->identity()['role'] === 'reader', '只读连接身份错误');
        $rejected = false;
        try {
            $reader->execute('CREATE TABLE type_identity_disallowed (id INTEGER)');
        } catch (DatabaseException) {
            $rejected = true;
        }
        identityExpect($rejected, '只读命名连接错误允许写入');
        $reader->close();
        $unused = new Database(identityDriver($driver), 1, 0);
        $pipes = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('无法创建实际子进程');
        }
        if ($pid === 0) {
            fclose($pipes[0]);
            $childScope = new ExecutionScope();
            try {
                $child = $unused->connect($childScope);
                $rejected = false;
                try {
                    $manager->connect($childScope);
                } catch (RuntimeException $error) {
                    $rejected = str_contains($error->getMessage(), '进程');
                }
                fwrite($pipes[1], (int) $child->query('SELECT 9 AS value')[0]['value'] === 9 && $rejected ? 'ok' : 'failed');
            } finally {
                $childScope->close();
                $unused->close();
                fclose($pipes[1]);
            }
            exit(0);
        }
        fclose($pipes[1]);
        stream_set_timeout($pipes[0], 5);
        $result = stream_get_contents($pipes[0]);
        fclose($pipes[0]);
        pcntl_waitpid($pid, $status);
        identityExpect($result === 'ok' && pcntl_wexitstatus($status) === 0, 'fork 后连接初始化或旧进程连接拒绝失败');
        $unused->close();
        echo "三库连接身份、会话基线、只读用途、代次轮换与实际 fork 通过。\n";
    } finally {
        $scope->close();
        $manager->close();
    }
}
