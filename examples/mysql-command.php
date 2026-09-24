<?php

declare(strict_types=1);

use Type\Orm\Connection;
use Type\Orm\Database;
use Type\Orm\Mysql\MysqlDriver;
use Type\Runtime\ExecutionScope;

/**
 * 将当前示例的行为断言转为明确失败，避免只输出成功文字而忽略实际状态。
 *
 * @throws \RuntimeException 条件不成立。
 */
function databaseExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * 在专属 MySQL 数据库验证真实读写、事务、连接复用边界及退出清理。
 *
 * @param list<string> $argv 程序路径与该示例的显式参数。
 */
function main(int $argc, array $argv): void
{
    if (($argv[1] ?? '') === 'test-drivers') {
        echo json_encode(PDO::getAvailableDrivers(), JSON_THROW_ON_ERROR) . "\n";
        return;
    }
    $scope = new ExecutionScope();
    $driver = new MysqlDriver(
        (string) (getenv('TYPE_MYSQL_HOST') ?: '127.0.0.1'),
        (int) (getenv('TYPE_MYSQL_PORT') ?: '3306'),
        (string) (getenv('TYPE_MYSQL_DATABASE') ?: 'type_app_test'),
        (string) (getenv('TYPE_MYSQL_USER') ?: 'root'),
        (string) (getenv('TYPE_MYSQL_PASSWORD') ?: '')
    );
    $database = new Database($driver, 1, 1);
    try {
        $connection = $database->connect($scope);
        $session = $connection->query('SELECT @@SESSION.time_zone AS zone, @@SESSION.sql_mode AS mode, @@character_set_connection AS charset')[0];
        databaseExpect($session['zone'] === '+00:00' && str_contains($session['mode'], 'STRICT_ALL_TABLES')
            && $session['charset'] === 'utf8mb4', 'MySQL 会话基线未应用');
        // 仅用于隔离测试数据库中的临时表，连接结束即消失。
        $connection->raw('CREATE TEMPORARY TABLE type_app_probe (id BIGINT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(255) NOT NULL)');
        $connection->execute('INSERT INTO type_app_probe (name) VALUES (?)', ["原生数据' OR 1=1 --"]);
        $id = $connection->lastInsertId();
        databaseExpect($id === '1', '主键返回不正确');
        databaseExpect($connection->query('SELECT name FROM type_app_probe WHERE id = ?', [$id])[0]['name'] === "原生数据' OR 1=1 --", '参数内容被当成 SQL 执行');
        $connection->execute('UPDATE type_app_probe SET name = ? WHERE id = ?', ['已更新', $id]);
        $rows = $connection->query('SELECT name FROM type_app_probe WHERE id = ?', [$id]);
        databaseExpect(count($rows) === 1 && $rows[0]['name'] === '已更新', '参数化读取或更新失败');
        $rolledBack = false;
        try {
            $connection->transaction(static function (Connection $transaction): void {
                $transaction->execute('INSERT INTO type_app_probe (name) VALUES (?)', ['应回滚']);
                throw new RuntimeException('主动回滚');
            });
        } catch (RuntimeException $error) {
            $rolledBack = $error->getMessage() === '主动回滚';
        }
        databaseExpect($rolledBack && count($connection->query('SELECT * FROM type_app_probe')) === 1, '事务回滚没有生效');
        $result = $connection->transaction(static function (Connection $transaction): string {
            $transaction->execute('INSERT INTO type_app_probe (name) VALUES (?)', ['已提交']);

            return '事务结果';
        });
        databaseExpect($result === '事务结果' && count($connection->query('SELECT * FROM type_app_probe')) === 2, '事务提交或返回值错误');
        $full = false;
        try {
            $database->connect($scope, 0);
        } catch (\Type\Runtime\CapacityException $error) {
            $full = true;
        }
        databaseExpect($full, '连接池超过容量继续借用');
        $connection->execute('DELETE FROM type_app_probe WHERE id = ?', [$id]);
        databaseExpect(count($connection->query('SELECT * FROM type_app_probe')) === 1, '参数化删除失败');
        $sqlError = false;
        try {
            $connection->query('SELECT nonexistent_column FROM type_app_probe');
        } catch (\Type\Orm\DatabaseException $error) {
            $sqlError = str_contains($error->getMessage(), 'SQLSTATE') && !str_contains($error->getMessage(), 'nonexistent_column');
        }
        databaseExpect($sqlError, '数据库错误未分类或泄露了原始 SQL');
        $connection->close();
        $released = false;
        try {
            $connection->query('SELECT 1');
        } catch (RuntimeException $error) {
            $released = true;
        }
        databaseExpect($released, '归还后的连接仍可使用');
        $next = $database->connect($scope);
        databaseExpect((int) $next->query('SELECT 7 AS value')[0]['value'] === 7, '作用域内无法再次借用');
        $next->close();
        databaseExpect($database->statistics()['idle'] === 0 && $database->statistics()['leased'] === 0, 'MySQL 安全关闭后不应保留物理会话');
        $scope->close();
        $closed = false;
        try {
            $database->connect($scope);
        } catch (RuntimeException $error) {
            $closed = true;
        }
        databaseExpect($closed, '关闭的作用域仍允许借用');
        echo "MySQL 原生读写、事务与连接池验证通过。\n";
    } catch (Throwable $error) {
        fwrite(STDERR, '数据库命令失败：' . $error->getMessage() . PHP_EOL);
        $scope->close();
        $database->close();
        exit(70);
    }
    $scope->close();
    $database->close();
}
