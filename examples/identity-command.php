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

/** 构造带角色、凭据代次与会话基线的驱动，供身份切换和退役验收。 */
function identityDriver(string $name, int $generation = 1, string $role = 'writer', ?string $schema = null, ?string $password = null, ?string $databaseRole = null): Driver
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
        $schema,
        $databaseRole
    );
}

/**
 * 将当前示例的行为断言转为明确失败，避免只输出成功文字而忽略实际状态。
 *
 * @throws \RuntimeException 条件不成立。
 */
function identityExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * 验证数据库身份、读写用途、凭据代次轮换和独立进程租约边界。
 *
 * @param list<string> $argv 程序路径与该示例的显式参数。
 */
function main(int $argc, array $argv): void
{
    $driver = (string) ($argv[1] ?? 'sqlite');
    if (($argv[2] ?? '') === 'session-baseline') {
        identityExpect($driver === 'pgsql', '角色与 schema 会话基线仅适用于 PostgreSQL');
        identitySessionBaseline();
        return;
    }
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
            identityExpect($state['id'] === $sessionId && $state['zone'] === 'UTC' && $state['schema'] !== 'pg_catalog', 'PostgreSQL 没有复用已完整重置的会话');
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

/** 同一物理会话归还后恢复配置角色、schema 与读写用途，PHP 和 AOT 使用相同断言。 */
function identitySessionBaseline(): void
{
    $schema = (string) getenv('TYPE_IDENTITY_SCHEMA');
    $databaseRole = (string) getenv('TYPE_IDENTITY_ROLE');
    foreach (['writer', 'reader'] as $role) {
        $database = new Database(identityDriver('pgsql', 1, $role, $schema, null, $databaseRole), 1, 1);
        $scope = new ExecutionScope();
        try {
            $connection = $database->connect($scope);
            $row = $connection->query("SELECT pg_backend_pid() AS id, current_user AS role, current_schema() AS schema, current_setting('default_transaction_read_only') AS read_only")[0];
            identityExpect($row['role'] === $databaseRole && $row['schema'] === $schema
                && $row['read_only'] === ($role === 'reader' ? 'on' : 'off'), 'PostgreSQL 会话基线没有初始化');
            // 查询也可调用有副作用的函数；只读用途不能代替归还时完整重置。
            $connection->query("SELECT set_config('role', session_user, false)");
            $connection->query("SELECT set_config('search_path', 'pg_catalog', false), set_config('default_transaction_read_only', ?, false)", [$role === 'reader' ? 'off' : 'on']);
            $dirty = $connection->query("SELECT current_user AS role, current_schema() AS schema, current_setting('default_transaction_read_only') AS read_only")[0];
            identityExpect($dirty['role'] !== $row['role'] && $dirty['schema'] === 'pg_catalog'
                && $dirty['read_only'] !== $row['read_only'], 'PostgreSQL 污染场景未实际改变会话状态');
            $connection->close();
            $next = $database->connect($scope);
            $restored = $next->query("SELECT pg_backend_pid() AS id, current_user AS role, current_schema() AS schema, current_setting('default_transaction_read_only') AS read_only")[0];
            identityExpect($restored === $row, 'PostgreSQL 未复用物理会话或未恢复角色、schema 和读写用途');
            $next->close();
            identityExpect($database->statistics()['idle'] === 1, '恢复基线后没有归还可借物理会话');
        } finally {
            $scope->close();
            $database->close();
        }
    }
    echo "PostgreSQL 物理会话复用与角色、schema、读写用途恢复通过。\n";
}
