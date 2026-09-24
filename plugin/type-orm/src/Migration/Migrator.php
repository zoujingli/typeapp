<?php

declare(strict_types=1);

namespace Type\Orm\Migration;

use Closure;
use Throwable;
use Type\Orm\Connection;
use Type\Orm\Database;
use Type\Orm\Driver;
use Type\Runtime\ExecutionScope;

/** 独立数据库角色：锁、迁移与记录始终使用同一条专属连接。 */
final class Migrator
{
    private Driver $driver;
    private string $table;

    /** 声明迁移专属驱动及记录表名；每次操作建立独立短生命周期连接。 */
    public function __construct(Driver $driver, string $table = 'type_migrations')
    {
        if (!in_array($driver->name(), ['mysql', 'pgsql', 'sqlite'], true) || !preg_match('/^[a-z][a-z0-9_]{0,47}$/D', $table)) {
            throw new MigrationException('TYPE_MIGRATION_INVALID：迁移驱动或记录表名无效');
        }
        $this->driver = $driver;
        $this->table = $table;
    }

    /**
     * 核对完整计划与数据库记录，不执行待应用的迁移 SQL。
     *
     * @param list<Migration> $migrations
     * @return list<array<string, mixed>>
     */
    public function status(array $migrations): array
    {
        $plan = $this->plan($migrations);

        return $this->connection(function (Connection $connection) use ($plan): array {
            return $this->snapshot($connection, $plan);
        });
    }

    /**
     * 在同一迁移锁内执行计划；fresh 只允许空数据库/当前 PostgreSQL schema。
     * 已有对象在建立迁移记录前拒绝，不覆盖、升级或清空已有数据。
     * @param list<Migration> $migrations 完整迁移计划。
     */
    public function run(array $migrations, bool $fresh = false): array
    {
        $plan = $this->plan($migrations);

        return $this->connection(function (Connection $connection) use ($plan, $fresh): array {
            return $this->locked($connection, function () use ($connection, $plan, $fresh): array {
                if ($fresh) {
                    $objects = match ($this->driver->name()) {
                        'mysql' => $connection->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'),
                        'pgsql' => $connection->query('SELECT relname FROM pg_class WHERE relnamespace = current_schema()::regnamespace AND relkind IN (\'r\', \'p\', \'v\', \'m\', \'S\', \'f\')'),
                        default => $connection->query("SELECT name FROM sqlite_master WHERE name NOT LIKE 'sqlite_%'"),
                    };
                    if ($objects !== []) {
                        throw new MigrationException('TYPE_MIGRATION_NOT_EMPTY：全新初始化只允许空数据库，不修改已有模式或数据');
                    }
                }
                $this->initialize($connection);
                $states = $this->snapshot($connection, $plan);
                foreach ($states as $state) {
                    if ($state['state'] === 'running' || $state['state'] === 'failed') {
                        throw new MigrationException('TYPE_MIGRATION_RECOVERY_REQUIRED：迁移 ' . $state['version'] . ' 需要显式恢复');
                    }
                }
                foreach ($plan as $index => $migration) {
                    if ($states[$index]['state'] === 'applied') {
                        continue;
                    }
                    $this->apply($connection, $migration, (int) $states[$index]['attempts']);
                }

                return $this->snapshot($connection, $plan);
            });
        });
    }

    /**
     * 按事件编号读取迁移与恢复历史，事件表尚未创建时返回空列表。
     *
     * @return list<array<string, mixed>>
     */
    public function history(): array
    {
        return $this->connection(function (Connection $connection): array {
            return $this->exists($connection, $this->table . '_events')
                ? $connection->query('SELECT number, version, state, attempt, recorded_at, note FROM ' . $this->table . '_events ORDER BY number') : [];
        });
    }

    /** 恢复前由维护者确认实际数据；retry 不会替非事务 DDL 撤销已生效操作。 */
    public function recover(array $migrations, string $version, string $resolution, string $reason): array
    {
        $plan = $this->plan($migrations);
        if (!in_array($resolution, ['retry', 'applied'], true) || trim($reason) === '' || strlen($reason) > 1000) {
            throw new MigrationException('TYPE_MIGRATION_INVALID：恢复需要 retry/applied 与明确说明');
        }

        return $this->connection(function (Connection $connection) use ($plan, $version, $resolution, $reason): array {
            return $this->locked($connection, function () use ($connection, $plan, $version, $resolution, $reason): array {
                $states = $this->snapshot($connection, $plan);
                foreach ($states as $state) {
                    if ($state['version'] !== $version) {
                        continue;
                    }
                    if ($state['state'] !== 'running' && $state['state'] !== 'failed') {
                        throw new MigrationException('TYPE_MIGRATION_INVALID：只能恢复 running/failed 迁移');
                    }
                    $next = $resolution === 'retry' ? 'pending' : 'applied';
                    $connection->transaction(function (Connection $transaction) use ($version, $next, $reason, $state): void {
                        $transaction->execute(
                            'UPDATE ' . $this->table . ' SET state = ?, finished_at = ?, error = ? WHERE version = ?',
                            [$next, gmdate('c'), '', $version]
                        );
                        $this->record($transaction, $version, 'recovered_' . $next, (int) $state['attempts'], $reason);
                    });

                    return $this->snapshot($connection, $plan);
                }
                throw new MigrationException('TYPE_MIGRATION_INVALID：恢复版本不在迁移计划中');
            });
        });
    }

    private function plan(array $migrations): array
    {
        $result = [];
        foreach ($migrations as $migration) {
            if (!$migration instanceof Migration) {
                throw new MigrationException('TYPE_MIGRATION_INVALID：计划只接受 Migration');
            }
            $version = $migration->version();
            if (isset($result[$version])) {
                throw new MigrationException('TYPE_MIGRATION_DUPLICATE：迁移版本重复：' . $version);
            }
            if ($this->driver->name() === 'mysql' && $migration->transactional()) {
                throw new MigrationException('TYPE_MIGRATION_UNSUPPORTED：MySQL 迁移必须显式声明非事务执行，不能承诺 DDL 回滚');
            }
            foreach ($migration->statements() as $statement) {
                $this->validateSql($statement, $migration->transactional());
            }
            $result[$version] = $migration;
        }
        ksort($result, SORT_STRING);

        return array_values($result);
    }

    private function validateSql(string $sql, bool $transactional): void
    {
        // 每个元素只能包含一个普通 SQL 语句，避免隐式事务控制改变记录的原子性。
        try {
            \Type\Orm\SqlStatement::operation($sql);
        } catch (\Type\Orm\DatabaseException $error) {
            throw new MigrationException('TYPE_MIGRATION_UNSUPPORTED：' . $error->getMessage(), 0, $error);
        }
        if (!preg_match('/^\s*(CREATE\s+(TABLE|INDEX|UNIQUE\s+INDEX)\b|ALTER\s+TABLE\b|DROP\s+(TABLE|INDEX)\b|INSERT\s+INTO\b|UPDATE\s+|DELETE\s+FROM\b|VACUUM\b)/i', $sql)) {
            throw new MigrationException('TYPE_MIGRATION_UNSUPPORTED：迁移只接受普通表、索引 DDL 与数据写入 SQL');
        }
        if ($transactional && preg_match('/^\s*VACUUM\b|^\s*(CREATE|DROP)\s+(UNIQUE\s+)?INDEX\s+CONCURRENTLY\b/i', $sql)) {
            throw new MigrationException('TYPE_MIGRATION_UNSUPPORTED：该 DDL 不能在事务中执行');
        }
    }

    private function connection(Closure $operation): mixed
    {
        $scope = new ExecutionScope();
        $database = new Database($this->driver, 1, 0);
        try {
            return $operation($database->connect($scope));
        } finally {
            $scope->close();
            $database->close();
        }
    }

    /** @param Closure(): mixed $operation 同一专属数据库连接上的迁移操作。 */
    private function locked(Connection $connection, Closure $operation): mixed
    {
        $driver = $this->driver->name();
        $name = '';
        $key = 0;
        if ($driver === 'mysql') {
            $database = (string) $connection->query('SELECT DATABASE() AS name')[0]['name'];
            $name = 'type-migration:' . substr(hash('sha256', $database . ':' . $this->table), 0, 48);
            if ((int) $connection->query('SELECT GET_LOCK(?, 0) AS acquired', [$name])[0]['acquired'] !== 1) {
                throw new MigrationException('TYPE_MIGRATION_LOCKED：已有迁移持有数据库互斥锁');
            }
        } elseif ($driver === 'pgsql') {
            $schema = (string) $connection->query('SELECT current_schema() AS name')[0]['name'];
            $key = (int) hexdec(substr(hash('sha256', $schema . ':' . $this->table), 0, 7));
            if ((int) $connection->query('SELECT CASE WHEN pg_try_advisory_lock(1348032837, ?) THEN 1 ELSE 0 END AS acquired', [$key])[0]['acquired'] !== 1) {
                throw new MigrationException('TYPE_MIGRATION_LOCKED：已有迁移持有数据库互斥锁');
            }
        } else {
            $file = (string) $connection->query('PRAGMA database_list')[0]['file'];

            return $file === '' ? $operation() : $this->sqliteLocked($file, $operation);
        }
        $failed = false;
        try {
            return $operation();
        } catch (Throwable $error) {
            $failed = true;
            throw $error;
        } finally {
            try {
                if ($driver === 'mysql') {
                    $connection->query('SELECT RELEASE_LOCK(?)', [$name]);
                } elseif ($driver === 'pgsql') {
                    $connection->query('SELECT pg_advisory_unlock(1348032837, ?)', [$key]);
                }
            } catch (Throwable $cleanupError) {
                if (!$failed) {
                    throw new MigrationException('TYPE_MIGRATION_UNLOCK_FAILED：迁移已结束但释放会话锁失败', 0, $cleanupError);
                }
            }
        }
    }

    /**
     * 持有稳定的独立锁文件；Linux 同时持有旧数据库 flock，兼容已发布的旧执行者。
     *
     * @param Closure(): mixed $operation 已取得文件互斥后执行的迁移操作。
     * @throws MigrationException 锁文件不安全、不能打开、已有执行者或释放锁失败。
     */
    private function sqliteLocked(string $file, Closure $operation): mixed
    {
        $databaseFile = realpath($file);
        if ($databaseFile === false || !is_file($databaseFile)) {
            throw new MigrationException('TYPE_MIGRATION_LOCK_INVALID：SQLite 数据库必须是存在的本地普通文件');
        }
        clearstatcache(true, $databaseFile);
        $databaseStat = @stat($databaseFile);
        if (PHP_OS_FAMILY !== 'Windows' && ($databaseStat === false || !isset($databaseStat['nlink']) || $databaseStat['nlink'] !== 1)) {
            throw new MigrationException('TYPE_MIGRATION_LOCK_INVALID：SQLite 数据库不能使用硬链接别名或未知链接身份');
        }
        $handles = [];
        $failed = false;
        try {
            $handles[] = $this->sqliteFileLock($databaseFile . '.type-migration.lock', true);
            // Darwin 的 flock 与 SQLite fcntl 锁交互；Windows 的文件锁也不能施加在业务数据上。
            if (PHP_OS_FAMILY === 'Linux') {
                $handles[] = $this->sqliteFileLock($databaseFile, false);
            }

            return $operation();
        } catch (Throwable $error) {
            $failed = true;
            throw $error;
        } finally {
            $released = true;
            foreach (array_reverse($handles) as $handle) {
                if (is_resource($handle)) {
                    $unlocked = @flock($handle, LOCK_UN);
                    $closed = @fclose($handle);
                    $released = $released && $unlocked && $closed;
                }
            }
            if (!$released && !$failed) {
                throw new MigrationException('TYPE_MIGRATION_UNLOCK_FAILED：迁移已结束但释放 SQLite 文件锁失败');
            }
        }
    }

    /**
     * 锁文件不写内容、不截断、不删除；父目录必须由受信任的迁移执行账户管理。
     *
     * @return resource 已取得非阻塞排他锁的文件句柄。
     * @throws MigrationException 路径不安全、打开失败或已有执行者持锁。
     */
    private function sqliteFileLock(string $path, bool $create): mixed
    {
        $handle = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            clearstatcache(true, $path);
            $before = @lstat($path);
            if (is_link($path) || ($before !== false && (($before['mode'] & 0170000) !== 0100000))) {
                throw new MigrationException('TYPE_MIGRATION_LOCK_INVALID：SQLite 迁移锁路径不能是符号链接或非普通文件');
            }
            if (PHP_OS_FAMILY !== 'Windows' && $before !== false && (!isset($before['nlink']) || $before['nlink'] !== 1)) {
                throw new MigrationException('TYPE_MIGRATION_LOCK_INVALID：SQLite 迁移锁不能是硬链接或未知链接身份');
            }
            if ($before === false && !$create) {
                throw new MigrationException('TYPE_MIGRATION_LOCK_INVALID：SQLite 兼容锁文件已经消失');
            }
            // x+b 独占创建；r+b 只打开已经存在的文件，两种模式均不截断内容。
            $handle = @fopen($path, $before === false ? 'x+b' : 'r+b');
            if (is_resource($handle)) {
                break;
            }
            if ($before !== false) {
                break;
            }
        }
        if (!is_resource($handle)) {
            throw new MigrationException('TYPE_MIGRATION_LOCK_FAILED：无法创建或打开 SQLite 迁移锁文件');
        }
        try {
            $this->sqliteLockIdentity($path, $handle);
            if (!@flock($handle, LOCK_EX | LOCK_NB)) {
                throw new MigrationException('TYPE_MIGRATION_LOCKED：已有迁移持有 SQLite 文件互斥锁');
            }
            $this->sqliteLockIdentity($path, $handle);

            return $handle;
        } catch (Throwable $error) {
            @flock($handle, LOCK_UN);
            fclose($handle);
            throw $error;
        }
    }

    /** @param resource $handle 未写入内容的锁文件句柄。 */
    private function sqliteLockIdentity(string $path, mixed $handle): void
    {
        clearstatcache(true, $path);
        $entry = @lstat($path);
        $opened = fstat($handle);
        $resolved = realpath($path);
        if (PHP_OS_FAMILY !== 'Windows' && ($entry === false || $opened === false || ($entry['nlink'] ?? 0) !== 1 || ($opened['nlink'] ?? 0) !== 1)) {
            throw new MigrationException('TYPE_MIGRATION_LOCK_INVALID：SQLite 锁文件不能是硬链接或未知链接身份');
        }
        $samePath = $resolved !== false && (PHP_OS_FAMILY === 'Windows' ? strcasecmp($resolved, $path) === 0 : $resolved === $path);
        if ($entry === false || $opened === false || is_link($path) || !$samePath
            || ($entry['mode'] & 0170000) !== 0100000 || ($opened['mode'] & 0170000) !== 0100000
            || (PHP_OS_FAMILY !== 'Windows' && ($entry['dev'] !== $opened['dev'] || $entry['ino'] !== $opened['ino']))) {
            throw new MigrationException('TYPE_MIGRATION_LOCK_INVALID：SQLite 锁路径被替换或超出真实文件身份');
        }
    }

    private function exists(Connection $connection, string $table): bool
    {
        if ($this->driver->name() === 'mysql') {
            return count($connection->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table])) > 0;
        }
        if ($this->driver->name() === 'pgsql') {
            return count($connection->query('SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?', [$table])) > 0;
        }

        return count($connection->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])) > 0;
    }

    private function initialize(Connection $connection): void
    {
        $suffix = $this->driver->name() === 'mysql' ? ' ENGINE=InnoDB' : '';
        $versionType = $this->driver->name() === 'mysql' ? 'VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin' : 'VARCHAR(64)';
        $connection->execute('CREATE TABLE IF NOT EXISTS ' . $this->table . ' (version ' . $versionType . ' PRIMARY KEY, checksum VARCHAR(64) NOT NULL, '
            . 'description VARCHAR(500) NOT NULL, state VARCHAR(20) NOT NULL, transactional INTEGER NOT NULL, attempts INTEGER NOT NULL, '
            . 'started_at VARCHAR(40) NOT NULL, finished_at VARCHAR(40) NOT NULL, error TEXT NOT NULL)' . $suffix);
        $connection->execute('CREATE TABLE IF NOT EXISTS ' . $this->table . '_events (number INTEGER PRIMARY KEY, version VARCHAR(64) NOT NULL, '
            . 'state VARCHAR(30) NOT NULL, attempt INTEGER NOT NULL, recorded_at VARCHAR(40) NOT NULL, note TEXT NOT NULL)' . $suffix);
    }

    private function snapshot(Connection $connection, array $plan): array
    {
        $records = [];
        if ($this->exists($connection, $this->table)) {
            foreach ($connection->query('SELECT * FROM ' . $this->table . ' ORDER BY version') as $record) {
                $records[$record['version']] = $record;
            }
        }
        $result = [];
        foreach ($plan as $migration) {
            $version = $migration->version();
            $record = $records[$version] ?? null;
            if ($record !== null && $record['checksum'] !== $migration->checksum()) {
                throw new MigrationException('TYPE_MIGRATION_CHANGED：已记录迁移的内容发生变化：' . $version);
            }
            $result[] = ['version' => $version, 'description' => $migration->description(), 'checksum' => $migration->checksum(),
                'state' => $record === null ? 'pending' : (string) $record['state'], 'transactional' => $migration->transactional(),
                'attempts' => $record === null ? 0 : (int) $record['attempts'], 'error' => $record === null ? '' : (string) $record['error']];
            unset($records[$version]);
        }
        if ($records !== []) {
            throw new MigrationException('TYPE_MIGRATION_MISSING：计划遗漏了已有迁移版本');
        }

        return $result;
    }

    private function apply(Connection $connection, Migration $migration, int $attempts): void
    {
        $attempt = $attempts + 1;
        $version = $migration->version();
        $connection->transaction(function (Connection $transaction) use ($migration, $version, $attempt, $attempts): void {
            if ($attempts === 0) {
                $transaction->execute(
                    'INSERT INTO ' . $this->table . ' (version, checksum, description, state, transactional, attempts, started_at, finished_at, error) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$version, $migration->checksum(), $migration->description(), 'running', $migration->transactional() ? 1 : 0, $attempt, gmdate('c'), '', '']
                );
            } else {
                $transaction->execute(
                    'UPDATE ' . $this->table . ' SET state = ?, attempts = ?, started_at = ?, finished_at = ?, error = ? WHERE version = ?',
                    ['running', $attempt, gmdate('c'), '', '', $version]
                );
            }
            $this->record($transaction, $version, 'running', $attempt, '开始执行');
        });
        try {
            $complete = function (Connection $transaction) use ($version, $attempt): void {
                $transaction->execute('UPDATE ' . $this->table . ' SET state = ?, finished_at = ? WHERE version = ?', ['applied', gmdate('c'), $version]);
                $this->record($transaction, $version, 'applied', $attempt, '执行完成');
            };
            $operation = function (Connection $transaction) use ($migration, $complete): void {
                foreach ($migration->statements() as $statement) {
                    $transaction->raw($statement);
                }
                $complete($transaction);
            };
            if ($migration->transactional()) {
                $connection->transaction($operation, 'schema');
            } else {
                foreach ($migration->statements() as $statement) {
                    $connection->raw($statement);
                }
                $connection->transaction($complete);
            }
        } catch (Throwable $error) {
            try {
                $connection->transaction(function (Connection $transaction) use ($version, $attempt, $error): void {
                    $transaction->execute(
                        'UPDATE ' . $this->table . ' SET state = ?, finished_at = ?, error = ? WHERE version = ?',
                        ['failed', gmdate('c'), substr($error->getMessage(), 0, 2000), $version]
                    );
                    $this->record($transaction, $version, 'failed', $attempt, substr($error->getMessage(), 0, 2000));
                });
            } catch (Throwable $recordError) {
                throw new MigrationException('TYPE_MIGRATION_INTERRUPTED：迁移失败且记录无法确认；需要查看状态并显式恢复：' . $version, 0, $error);
            }
            throw new MigrationException('TYPE_MIGRATION_FAILED：迁移失败，需要检查实际数据并显式恢复：' . $version, 0, $error);
        }
    }

    private function record(Connection $connection, string $version, string $state, int $attempt, string $note): void
    {
        $next = (int) $connection->query('SELECT COALESCE(MAX(number), 0) + 1 AS value FROM ' . $this->table . '_events')[0]['value'];
        $connection->execute(
            'INSERT INTO ' . $this->table . '_events (number, version, state, attempt, recorded_at, note) VALUES (?, ?, ?, ?, ?, ?)',
            [$next, $version, $state, $attempt, gmdate('c'), $note]
        );
    }
}
