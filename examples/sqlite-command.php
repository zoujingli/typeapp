<?php

declare(strict_types=1);

use Type\Orm\Connection;
use Type\Orm\Database;
use Type\Orm\Sqlite\SqliteDriver;
use Type\Runtime\ExecutionScope;

function databaseExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function sqliteRecovery(string $mode, string $filename): void
{
    $scope = new ExecutionScope();
    $database = new Database(new SqliteDriver($filename, 100, true), 1, 0);
    try {
        $connection = $database->connect($scope);
        if ($mode === 'test-crash-write') {
            $connection->raw('CREATE TABLE recovery_probe (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
            $connection->transaction(static function (Connection $transaction): void {
                $transaction->execute('INSERT INTO recovery_probe VALUES (?, ?)', [1, '已提交']);
            });
            posix_kill((int) getmypid(), 9);
            throw new RuntimeException('测试进程未被终止');
        }
        databaseExpect($connection->query('SELECT value FROM recovery_probe WHERE id = ?', [1])[0]['value'] === '已提交', 'WAL 崩溃恢复丢失已提交数据');
        echo "SQLite WAL 崩溃恢复验证通过。\n";
    } finally {
        $scope->close();
        $database->close();
    }
}

function main(int $argc, array $argv): void
{
    if (($argv[1] ?? '') === 'test-drivers') {
        echo json_encode(PDO::getAvailableDrivers(), JSON_THROW_ON_ERROR), "\n";
        return;
    }
    if ($argc === 3 && in_array((string) $argv[1], ['test-crash-write', 'test-recover'], true)) {
        sqliteRecovery((string) $argv[1], (string) $argv[2]);
        return;
    }
    $memoryScope = new ExecutionScope();
    $memory = new Database(new SqliteDriver(':memory:', 100, false), 2, 0);
    $filename = tempnam(sys_get_temp_dir(), 'type_app_sqlite_');
    databaseExpect($filename !== false, '无法创建 SQLite 测试文件');
    $scope = new ExecutionScope();
    $database = new Database(new SqliteDriver((string) $filename, 100, true), 2, 0);
    try {
        $memoryA = $memory->connect($memoryScope);
        $memoryB = $memory->connect($memoryScope);
        $memoryA->raw('CREATE TABLE memory_probe (id INTEGER PRIMARY KEY)');
        databaseExpect(count($memoryB->query("SELECT name FROM sqlite_master WHERE name = 'memory_probe'")) === 0, '普通内存库被错误共享');
        $memoryScope->close();
        $memory->close();

        $one = $database->connect($scope);
        $two = $database->connect($scope);
        databaseExpect((int) $one->query('PRAGMA foreign_keys')[0]['foreign_keys'] === 1
            && (int) $two->query('PRAGMA foreign_keys')[0]['foreign_keys'] === 1, '每连接外键未启用');
        databaseExpect($one->query('PRAGMA journal_mode')[0]['journal_mode'] === 'wal', '文件库没有使用 WAL');
        $one->raw('CREATE TABLE parent_probe (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $one->raw('CREATE TABLE child_probe (id INTEGER PRIMARY KEY, parent_id INTEGER REFERENCES parent_probe(id))');
        $one->execute('INSERT INTO parent_probe (name) VALUES (?)', ["原生数据' OR 1=1 --"]);
        databaseExpect($one->lastInsertId() === '1', 'SQLite 主键返回错误');
        databaseExpect($two->query('SELECT name FROM parent_probe WHERE id = ?', [1])[0]['name'] === "原生数据' OR 1=1 --", 'SQLite 文件连接或参数绑定错误');
        $foreignKey = false;
        try {
            $two->execute('INSERT INTO child_probe (parent_id) VALUES (?)', [999]);
        } catch (\Type\Orm\DatabaseException $error) {
            $foreignKey = true;
        }
        databaseExpect($foreignKey, 'SQLite 外键约束没有生效');
        $rollback = false;
        try {
            $one->transaction(static function (Connection $transaction): void {
                $transaction->execute('UPDATE parent_probe SET name = ? WHERE id = ?', ['应回滚', 1]);
                throw new RuntimeException('主动回滚');
            });
        } catch (RuntimeException $error) {
            $rollback = $error->getMessage() === '主动回滚';
        }
        databaseExpect($rollback && $one->query('SELECT name FROM parent_probe WHERE id = ?', [1])[0]['name'] !== '应回滚', 'SQLite 回滚失败');
        $started = microtime(true);
        $busy = $one->transaction(static function (Connection $transaction) use ($two): bool {
            $transaction->execute('UPDATE parent_probe SET name = ? WHERE id = ?', ['已提交', 1]);
            try {
                $two->execute('UPDATE parent_probe SET name = ? WHERE id = ?', ['不能抢写', 1]);
            } catch (\Type\Orm\DatabaseException $error) {
                return true;
            }
            return false;
        });
        $elapsed = microtime(true) - $started;
        databaseExpect((bool) $busy && $elapsed >= 0.05 && $elapsed < 5.0, 'SQLite 写竞争或 busy 上限无效');
        databaseExpect($two->query('SELECT name FROM parent_probe WHERE id = ?', [1])[0]['name'] === '已提交', 'SQLite 提交失败');
        $one->execute('INSERT INTO parent_probe (name) VALUES (?)', ['待删除']);
        $one->execute('DELETE FROM parent_probe WHERE id = ?', [2]);
        databaseExpect(count($one->query('SELECT * FROM parent_probe')) === 1, 'SQLite 删除失败');
        $two->transaction(static function (Connection $reader) use ($one): void {
            $reader->query('SELECT * FROM parent_probe');
            $one->execute('UPDATE parent_probe SET name = ? WHERE id = ?', ['读者期间写入', 1]);
            $checkpoint = $one->query('PRAGMA wal_checkpoint(PASSIVE)')[0];
            databaseExpect((int) $checkpoint['log'] > (int) $checkpoint['checkpointed'], '长读者没有体现 WAL 检查点限制');
        });
        $one->query('PRAGMA wal_checkpoint(TRUNCATE)');
        $scope->close();
        $database->close();
        $reopenScope = new ExecutionScope();
        $reopen = new Database(new SqliteDriver((string) $filename, 100, true), 1, 0);
        try {
            databaseExpect($reopen->connect($reopenScope)->query('SELECT name FROM parent_probe WHERE id = ?', [1])[0]['name'] === '读者期间写入', 'SQLite 文件重新打开后数据错误');
        } finally {
            $reopenScope->close();
            $reopen->close();
        }
        echo "SQLite 原生读写、事务与连接池验证通过。\n";
    } catch (Throwable $error) {
        fwrite(STDERR, '数据库命令失败：' . $error->getMessage() . PHP_EOL);
        $memoryScope->close();
        $memory->close();
        $scope->close();
        $database->close();
        exit(70);
    }
    foreach ([(string) $filename, (string) $filename . '-wal', (string) $filename . '-shm'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}
