<?php

declare(strict_types=1);

namespace Type\Orm\Migration;

use Type\Orm\Connection;
use Type\Orm\Database;
use Type\Orm\DatabaseException;
use Type\Orm\Driver;
use Type\Orm\Schema\Schema;
use Type\Orm\SqlStatement;
use Type\Runtime\DeploymentBudget;
use Type\Runtime\ExecutionScope;

/**
 * 独立数据库角色：锁、迁移与记录始终使用同一条专属连接。
 */
final class Migrator
{
    private Driver $driver;

    private MigrationDriver $catalog;

    private string $table;

    /**
     * @var array<string, SchemaMigration>
     */
    private array $schemas = [];

    /**
     * 声明迁移专属驱动及记录表名；每次操作建立独立短生命周期连接。
     */
    public function __construct(Driver $driver, string $table = 'type_migrations', private ?DeploymentBudget $budget = null)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,47}$/D', $table) !== 1) {
            throw new MigrationException('TYPE_MIGRATION_INVALID：迁移记录表名无效');
        }
        $this->driver = $driver;
        $this->catalog = $driver->migrations();
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
     * @param list<Migration> $migrations 完整迁移计划
     * @param ?\Closure(Connection, array):void $before 持锁且写入前核对应用前置条件；不能在回调中另借迁移连接
     * @param ?\Closure(Connection, array):void $after 全部迁移成功后、同一锁内验证和记录应用版本。失败须显式重试，不自动撤销已提交 DDL。
     */
    public function run(array $migrations, bool $fresh = false, ?\Closure $before = null, ?\Closure $after = null): array
    {
        $plan = $this->plan($migrations);

        return $this->connection(function (Connection $connection) use ($plan, $fresh, $before, $after): array {
            return $this->locked($connection, function () use ($connection, $plan, $fresh, $before, $after): array {
                if ($fresh && !$this->catalog->isEmpty($connection)) {
                    throw new MigrationException('TYPE_MIGRATION_NOT_EMPTY：全新初始化只允许空数据库，不修改已有模式或数据');
                }
                $states = $this->snapshot($connection, $plan);
                if ($before !== null) {
                    $before($connection, $states);
                }
                foreach ($states as $state) {
                    if (in_array($state['state'], ['running', 'failed', 'rolling_back'], true)) {
                        throw new MigrationException('TYPE_MIGRATION_RECOVERY_REQUIRED：迁移 ' . $state['version'] . ' 需要显式恢复');
                    }
                }
                $this->initialize($connection);
                foreach ($plan as $index => $migration) {
                    if ($states[$index]['state'] === 'applied') {
                        continue;
                    }
                    $this->apply($connection, $migration, (int)$states[$index]['attempts']);
                }

                $finished = $this->snapshot($connection, $plan);
                if ($after !== null) {
                    $after($connection, $finished);
                }
                return $finished;
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

    /**
     * 只读读取当前数据库结构并生成迁移草稿；不取得迁移写锁，也不写入迁移记录。
     * @return array{driver:string,migration:Migration,script:string,warnings:list<string>,complete:bool,integrity:array<string,mixed>}
     */
    public function generate(string $version, string $description): array
    {
        return $this->connection(function (Connection $connection) use ($version, $description): array {
            return (new MigrationGenerator())->generate($connection, $version, $description);
        });
    }

    /**
     * 按已应用版本倒序回滚有限步数；没有 down 声明的版本明确拒绝。
     * @return list<array<string, mixed>> 回滚后的完整迁移状态
     */
    public function rollback(array $migrations, int $steps = 1): array
    {
        if ($steps < 1 || $steps > 100) {
            throw new MigrationException('TYPE_MIGRATION_INVALID：回滚步数必须在 1 到 100 之间');
        }
        $plan = $this->plan($migrations);
        return $this->connection(function (Connection $connection) use ($plan, $steps): array {
            return $this->locked($connection, function () use ($connection, $plan, $steps): array {
                if (!$this->exists($connection, $this->table)) {
                    throw new MigrationException('TYPE_MIGRATION_NOT_INITIALIZED：迁移记录不存在');
                }
                $states = $this->snapshot($connection, $plan);
                $targets = [];
                for ($index = count($states) - 1; $index >= 0 && count($targets) < $steps; --$index) {
                    if ($states[$index]['state'] === 'applied') {
                        $targets[] = [$plan[$index], $states[$index]];
                    }
                }
                foreach ($targets as [$migration, $state]) {
                    if (!$migration->reversible()) {
                        throw new MigrationException('TYPE_MIGRATION_IRREVERSIBLE：迁移没有 down 回滚声明：' . $migration->version());
                    }
                    $this->rollbackOne($connection, $migration, (int)$state['attempts']);
                }
                return $this->snapshot($connection, $plan);
            });
        });
    }

    /**
     * 恢复前由维护者确认实际数据；retry 不会替非事务 DDL 撤销已生效操作。
     */
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
                    if (!in_array($state['state'], ['running', 'failed', 'rolling_back'], true)) {
                        throw new MigrationException('TYPE_MIGRATION_INVALID：只能恢复 running/failed/rolling_back 迁移');
                    }
                    $next = $state['state'] === 'rolling_back'
                        ? ($resolution === 'retry' ? 'applied' : 'rolled_back')
                        : ($resolution === 'retry' ? 'pending' : 'applied');
                    $connection->transaction(function (Connection $transaction) use ($version, $next, $reason, $state): void {
                        $transaction->execute(
                            'UPDATE ' . $this->table . ' SET state = ?, finished_at = ?, error = ? WHERE version = ?',
                            [$next, gmdate('c'), '', $version]
                        );
                        $this->record($transaction, $version, 'recovered_' . $next, (int)$state['attempts'], $reason);
                    });

                    return $this->snapshot($connection, $plan);
                }
                throw new MigrationException('TYPE_MIGRATION_INVALID：恢复版本不在迁移计划中');
            });
        });
    }

    /**
     * 只展开 Schema 迁移会执行的 SQL，不连接数据库、不写迁移记录。
     *
     * @param list<Migration|SchemaMigration> $migrations
     * @return list<array{version:string,description:string,up:list<string>,down:list<string>}>
     */
    public function pretend(array $migrations): array
    {
        $result = [];
        foreach ($migrations as $migration) {
            if ($migration instanceof SchemaMigration) {
                $migration = $migration->compile($this->driver->name());
            }
            if (!$migration instanceof Migration) {
                throw new MigrationException('TYPE_MIGRATION_INVALID：计划只接受 Migration 或 SchemaMigration');
            }
            $result[] = ['version' => $migration->version(), 'description' => $migration->description(),
                'up' => $migration->statements(), 'down' => $migration->downStatements()];
        }
        return $result;
    }

    private function plan(array $migrations): array
    {
        $result = [];
        $this->schemas = [];
        foreach ($migrations as $migration) {
            if ($migration instanceof SchemaMigration) {
                $script = $migration;
                $migration = $script->compile($this->driver->name());
                $this->schemas[$migration->version()] = $script;
            }
            if (!$migration instanceof Migration) {
                throw new MigrationException('TYPE_MIGRATION_INVALID：计划只接受 Migration 或 SchemaMigration');
            }
            $version = $migration->version();
            if (isset($result[$version])) {
                throw new MigrationException('TYPE_MIGRATION_DUPLICATE：迁移版本重复：' . $version);
            }
            if (!$this->catalog->transactionalDdl() && $migration->transactional()) {
                throw new MigrationException('TYPE_MIGRATION_UNSUPPORTED：MySQL 迁移必须显式声明非事务执行，不能承诺 DDL 回滚');
            }
            foreach ($migration->statements() as $statement) {
                $this->validateSql($statement, $migration->transactional());
            }
            foreach ($migration->downStatements() as $statement) {
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
            SqlStatement::operation($sql);
        } catch (DatabaseException $error) {
            throw new MigrationException('TYPE_MIGRATION_UNSUPPORTED：' . $error->getMessage(), 0, $error);
        }
        // SQLite 重建被引用的表需要推迟校验；只开放事务内的精确开关，绝不关闭 foreign_keys。
        // Connection 在恢复即时校验前检查完整引用，避免 SQLite 清除延迟计数后漏掉损坏数据。
        if ($transactional && $this->catalog->foreignKeyDeferral($sql)) {
            return;
        }
        if (!preg_match('/^\s*(CREATE\s+(TABLE|INDEX|UNIQUE\s+INDEX)\b|ALTER\s+TABLE\b|DROP\s+(TABLE|INDEX)\b|INSERT\s+INTO\b|UPDATE\s+|DELETE\s+FROM\b|VACUUM\b)/i', $sql)) {
            throw new MigrationException('TYPE_MIGRATION_UNSUPPORTED：迁移只接受普通表、索引 DDL 与数据写入 SQL');
        }
        if ($transactional && preg_match('/^\s*VACUUM\b|^\s*(CREATE|DROP)\s+(UNIQUE\s+)?INDEX\s+CONCURRENTLY\b/i', $sql)) {
            throw new MigrationException('TYPE_MIGRATION_UNSUPPORTED：该 DDL 不能在事务中执行');
        }
    }

    private function connection(\Closure $operation): mixed
    {
        $scope = new ExecutionScope();
        $database = new Database($this->driver, 1, 0, $this->budget);
        try {
            return $operation($database->connect($scope));
        } finally {
            $scope->close();
            $database->close();
        }
    }

    private function locked(Connection $connection, \Closure $operation): mixed
    {
        return $this->catalog->lock($connection, $this->table, $operation);
    }

    private function executeMigration(Connection $connection, Migration $migration, bool $up): void
    {
        $script = $this->schemas[$migration->version()] ?? null;
        if ($script !== null) {
            $schema = new Schema($this->driver->name(), $connection, false);
            $up ? $script->up($schema) : $script->down($schema);
            return;
        }
        foreach ($up ? $migration->statements() : $migration->downStatements() as $statement) {
            $connection->raw($statement);
        }
    }

    private function exists(Connection $connection, string $table): bool
    {
        return $this->catalog->tableExists($connection, $table);
    }

    private function initialize(Connection $connection): void
    {
        foreach ($this->catalog->migrationTables($this->table) as $sql) {
            $connection->execute($sql);
        }
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
                'state' => $record === null ? 'pending' : (string)$record['state'], 'transactional' => $migration->transactional(),
                'attempts' => $record === null ? 0 : (int)$record['attempts'], 'error' => $record === null ? '' : (string)$record['error']];
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
                $this->executeMigration($transaction, $migration, true);
                $complete($transaction);
            };
            if ($migration->transactional()) {
                $connection->transaction($operation, 'schema');
            } else {
                $this->executeMigration($connection, $migration, true);
                $connection->transaction($complete);
            }
        } catch (\Throwable $error) {
            try {
                $connection->transaction(function (Connection $transaction) use ($version, $attempt, $error): void {
                    $transaction->execute(
                        'UPDATE ' . $this->table . ' SET state = ?, finished_at = ?, error = ? WHERE version = ?',
                        ['failed', gmdate('c'), substr($error->getMessage(), 0, 2000), $version]
                    );
                    $this->record($transaction, $version, 'failed', $attempt, substr($error->getMessage(), 0, 2000));
                });
            } catch (\Throwable $recordError) {
                throw new MigrationException('TYPE_MIGRATION_INTERRUPTED：迁移失败且记录无法确认；需要查看状态并显式恢复：' . $version, 0, $error);
            }
            throw new MigrationException('TYPE_MIGRATION_FAILED：迁移失败，需要检查实际数据并显式恢复：' . $version, 0, $error);
        }
    }

    private function rollbackOne(Connection $connection, Migration $migration, int $attempts): void
    {
        $attempt = $attempts + 1;
        $version = $migration->version();
        $connection->transaction(function (Connection $transaction) use ($version, $attempt): void {
            $transaction->execute('UPDATE ' . $this->table . ' SET state = ?, attempts = ?, started_at = ?, finished_at = ?, error = ? WHERE version = ?',
                ['rolling_back', $attempt, gmdate('c'), '', '', $version]);
            $this->record($transaction, $version, 'rolling_back', $attempt, '开始回滚');
        });
        try {
            $complete = function (Connection $transaction) use ($version, $attempt): void {
                $transaction->execute('UPDATE ' . $this->table . ' SET state = ?, finished_at = ?, error = ? WHERE version = ?', ['rolled_back', gmdate('c'), '', $version]);
                $this->record($transaction, $version, 'rolled_back', $attempt, '回滚完成');
            };
            $operation = function (Connection $transaction) use ($migration, $complete): void {
                $this->executeMigration($transaction, $migration, false);
                $complete($transaction);
            };
            if ($migration->transactional()) {
                $connection->transaction($operation, 'schema');
            } else {
                $this->executeMigration($connection, $migration, false);
                $connection->transaction($complete);
            }
        } catch (\Throwable $error) {
            try {
                $connection->transaction(function (Connection $transaction) use ($version, $attempt, $error): void {
                    $message = substr($error->getMessage(), 0, 2000);
                    $transaction->execute('UPDATE ' . $this->table . ' SET state = ?, finished_at = ?, error = ? WHERE version = ?', ['failed', gmdate('c'), $message, $version]);
                    $this->record($transaction, $version, 'failed', $attempt, $message);
                });
            } catch (\Throwable $recordError) {
                throw new MigrationException('TYPE_MIGRATION_INTERRUPTED：回滚失败且记录无法确认：' . $version, 0, $error);
            }
            throw new MigrationException('TYPE_MIGRATION_ROLLBACK_FAILED：回滚失败：' . $version, 0, $error);
        }
    }

    private function record(Connection $connection, string $version, string $state, int $attempt, string $note): void
    {
        $next = (int)$connection->query('SELECT COALESCE(MAX(number), 0) + 1 AS value FROM ' . $this->table . '_events')[0]['value'];
        $connection->execute(
            'INSERT INTO ' . $this->table . '_events (number, version, state, attempt, recorded_at, note) VALUES (?, ?, ?, ?, ?, ?)',
            [$next, $version, $state, $attempt, gmdate('c'), $note]
        );
    }
}
