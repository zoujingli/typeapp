<?php

declare(strict_types=1);

namespace Type\Orm\Sqlite;

use Type\Orm\Connection;
use Type\Orm\Migration\AbstractMigrationDriver;
use Type\Orm\Migration\MigrationException;

/**
 * SQLite 的结构导出、事务 DDL 与文件锁。
 */
final class SqliteMigration extends AbstractMigrationDriver
{
    /**
     * DDL 可以和迁移记录放在同一个事务里。
     */
    public function transactionalDdl(): bool
    {
        return true;
    }

    /**
     * sqlite_master 里除 sqlite_ 前缀外没有任何对象时视为空库。
     */
    public function isEmpty(Connection $connection): bool
    {
        return $connection->query("SELECT name FROM sqlite_master WHERE name NOT LIKE 'sqlite_%'") === [];
    }

    /**
     * 按 sqlite_master 判断普通表是否存在。
     */
    public function tableExists(Connection $connection, string $table): bool
    {
        return count($connection->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])) > 0;
    }

    /**
     * 表名和列名必须是标识符，再按 PRAGMA table_info 判断列。
     */
    public function columnExists(Connection $connection, string $table, string $column): bool
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $table) !== 1 || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column) !== 1) {
            throw new MigrationException('TYPE_SCHEMA_INVALID：SQLite 列检查的表名或列名无效');
        }
        foreach ($connection->query('PRAGMA table_info(' . $table . ')') as $row) {
            if ((string)$row['name'] === $column) {
                return true;
            }
        }
        return false;
    }

    /**
     * 建立迁移记录表和事件表。
     */
    public function migrationTables(string $table): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS ' . $table . ' (version VARCHAR(64) PRIMARY KEY, checksum VARCHAR(64) NOT NULL, description VARCHAR(500) NOT NULL, state VARCHAR(20) NOT NULL, transactional INTEGER NOT NULL, attempts INTEGER NOT NULL, started_at VARCHAR(40) NOT NULL, finished_at VARCHAR(40) NOT NULL, error TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS ' . $table . '_events (number INTEGER PRIMARY KEY, version VARCHAR(64) NOT NULL, state VARCHAR(30) NOT NULL, attempt INTEGER NOT NULL, recorded_at VARCHAR(40) NOT NULL, note TEXT NOT NULL)',
        ];
    }

    /**
     * 导出 sqlite_master 中的建表与索引语句，不含迁移记录表。
     */
    public function structure(Connection $connection): array
    {
        $rows = $connection->query(
            "SELECT sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' "
            . "AND name NOT IN ('type_migrations', 'type_migrations_events') ORDER BY rowid"
        );
        $statements = [];
        foreach ($rows as $row) {
            $sql = rtrim((string)$row['sql'], " \t\r\n;");
            if ($sql !== '') {
                $statements[] = $sql;
            }
        }
        return $statements;
    }

    /**
     * 读取列、主键、索引、外键和检查约束。
     */
    public function describe(Connection $connection): array
    {
        $tables = $connection->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' "
            . "AND name NOT IN ('type_migrations', 'type_migrations_events') ORDER BY name"
        );
        $described = [];
        foreach ($tables as $table) {
            $name = (string)$table['name'];
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
                continue;
            }
            $columns = [];
            $primary = [];
            $unsupported = [];
            foreach ($connection->query('PRAGMA table_info(' . $name . ')') as $column) {
                $declaration = strtolower((string)$column['type']);
                $rawDefault = $column['dflt_value'];
                $default = self::defaultLiteral($rawDefault);
                $defaultText = is_string($rawDefault) ? trim($rawDefault) : '';
                $simpleDefault = $defaultText === '' || strcasecmp($defaultText, 'NULL') === 0
                    || preg_match('/^-?(?:[0-9]+(?:\\.[0-9]+)?|[0-9]+e[+-]?[0-9]+)$/iD', $defaultText) === 1
                    || preg_match('/^(?:TRUE|FALSE)$/iD', $defaultText) === 1
                    || preg_match("/^'(?:''|[^'])*'$/D", $defaultText) === 1;
                if (is_string($rawDefault) && (!$simpleDefault || str_contains($rawDefault, '('))) {
                    $unsupported[] = 'column ' . (string)$column['name'] . ': default expression';
                }
                $blueprint = match (true) {
                    str_contains($declaration, 'char') || str_contains($declaration, 'varchar') => 'string',
                    str_contains($declaration, 'text') || $declaration === '' => 'text',
                    str_contains($declaration, 'bool') => 'boolean',
                    str_contains($declaration, 'decimal') || str_contains($declaration, 'numeric') => 'decimal',
                    str_contains($declaration, 'date') || str_contains($declaration, 'time') => 'datetime',
                    default => 'integer',
                };
                if ((int)$column['pk'] > 0) {
                    $primary[] = (string)$column['name'];
                }
                $columns[] = [
                    'name' => (string)$column['name'], 'type' => $blueprint, 'length' => 255, 'bits' => 64,
                    'precision' => 10, 'scale' => 0, 'nullable' => (int)$column['notnull'] === 0,
                    'default' => $default, 'comment' => '',
                    'auto' => (int)$column['pk'] === 1 && str_contains($declaration, 'int'),
                ];
            }
            $indexes = [];
            $indexRows = $connection->query('PRAGMA index_list(' . $name . ')');
            foreach ($indexRows as $index) {
                $indexName = (string)$index['name'];
                $pragmaIndex = "'" . str_replace("'", "''", $indexName) . "'";
                $columnsForIndex = array_map(static fn (array $row): string => (string)$row['name'], $connection->query('PRAGMA index_info(' . $pragmaIndex . ')'));
                if (str_starts_with($indexName, 'sqlite_autoindex_')) {
                    continue;
                }
                $indexes[] = ['name' => $indexName, 'columns' => $columnsForIndex, 'unique' => (int)$index['unique'] === 1];
            }
            // 表级 UNIQUE 约束只有自动索引，没有可复用的 sqlite_master 索引名；
            // 解析约束列并分配稳定名称，确保反向迁移不会静默丢失唯一性。
            $tableSql = (string)($connection->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$name])[0]['sql'] ?? '');
            foreach ($this->tableUniqueColumns($tableSql) as $uniqueColumns) {
                $indexName = 'unique_' . $name . '_' . implode('_', $uniqueColumns);
                $already = false;
                foreach ($indexes as $index) {
                    if ($index['columns'] === $uniqueColumns && $index['unique']) {
                        $already = true;
                        break;
                    }
                }
                if (!$already) {
                    $indexes[] = ['name' => $indexName, 'columns' => $uniqueColumns, 'unique' => true];
                }
            }
            $foreign = [];
            $foreignRows = $connection->query('PRAGMA foreign_key_list(' . $name . ')');
            usort($foreignRows, static fn (array $left, array $right): int => ((int)$left['id'] <=> (int)$right['id']) ?: ((int)$left['seq'] <=> (int)$right['seq']));
            foreach ($foreignRows as $row) {
                $id = (int)$row['id'];
                if (!isset($foreign[$id])) {
                    $onDelete = strtoupper((string)($row['on_delete'] ?? 'NO ACTION'));
                    if (!in_array($onDelete, ['CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION'], true)) {
                        $unsupported[] = 'foreign fk_' . $name . '_' . $id . ': ' . $onDelete;
                        $onDelete = null;
                    }
                    $foreign[$id] = ['name' => 'fk_' . $name . '_' . $id, 'columns' => [], 'on' => (string)$row['table'], 'references' => [], 'onDelete' => $onDelete === 'NO ACTION' ? null : $onDelete];
                }
                $foreign[$id]['columns'][] = (string)$row['from'];
                $foreign[$id]['references'][] = (string)$row['to'];
            }
            $checks = $this->extractChecks($tableSql);
            $unsupported = [...$checks['unsupported'], ...$unsupported];
            $described[] = ['name' => $name, 'comment' => '', 'columns' => $columns, 'primary' => $primary, 'indexes' => $indexes, 'foreign' => array_values($foreign), 'checks' => $checks['checks'], 'unsupported' => $unsupported];
        }
        return $described;
    }

    private static function defaultLiteral(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        $value = trim($value);
        if (strcasecmp($value, 'NULL') === 0) {
            return null;
        }
        if (strlen($value) >= 2 && $value[0] === "'" && $value[strlen($value) - 1] === "'") {
            return str_replace("''", "'", substr($value, 1, -1));
        }
        if (preg_match('/^-?[0-9]+$/D', $value) === 1) {
            return (int)$value;
        }
        if (preg_match('/^(?:true|false)$/iD', $value) === 1) {
            return strtolower($value) === 'true';
        }
        if (preg_match('/^-?(?:[0-9]+\\.[0-9]+|[0-9]+e[+-]?[0-9]+)$/iD', $value) === 1) {
            return (float)$value;
        }
        return $value;
    }

    /**
     * @return list<list<string>>
     */
    private function tableUniqueColumns(string $sql): array
    {
        $result = [];
        if ($sql === '') {
            return $result;
        }
        if (preg_match_all('/\bUNIQUE\s*\(([^()]*)\)/i', $sql, $matches) === false) {
            return $result;
        }
        foreach ($matches[1] as $columns) {
            $parsed = [];
            foreach (explode(',', $columns) as $column) {
                $column = trim($column, " \t\r\n\\\"'`");
                if ($column !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column) === 1) {
                    $parsed[] = $column;
                }
            }
            if ($parsed !== []) {
                $result[] = $parsed;
            }
        }
        // 列级 UNIQUE 不会出现在上面的 UNIQUE(...) 约束中；按顶层逗号拆分建表定义。
        $open = strpos($sql, '(');
        $close = strrpos($sql, ')');
        if ($open !== false && $close !== false && $close > $open) {
            foreach ($this->splitDefinitions(substr($sql, $open + 1, $close - $open - 1)) as $definition) {
                if (preg_match('/^\s*(?:["`]([^"`]+)["`]|\[([^\]]+)\]|([A-Za-z_][A-Za-z0-9_]*))\s+.*\bUNIQUE\b/is', $definition, $column) !== 1) {
                    continue;
                }
                $name = (string)($column[1] !== '' ? $column[1] : ($column[2] !== '' ? $column[2] : $column[3]));
                if ($name !== '') {
                    $result[] = [$name];
                }
            }
        }
        return $result;
    }

    /** @return list<string> */
    private function splitDefinitions(string $body): array
    {
        $parts = [];
        $start = 0;
        $depth = 0;
        $quote = '';
        $length = strlen($body);
        for ($index = 0; $index < $length; ++$index) {
            $char = $body[$index];
            if ($quote !== '') {
                if ($char === $quote) {
                    if ($index + 1 < $length && $body[$index + 1] === $quote) { ++$index; continue; }
                    $quote = '';
                }
                continue;
            }
            if (in_array($char, ["'", '"', '`'], true)) { $quote = $char; continue; }
            if ($char === '(') { ++$depth; continue; }
            if ($char === ')') { --$depth; continue; }
            if ($char === ',' && $depth === 0) {
                $parts[] = trim(substr($body, $start, $index - $start));
                $start = $index + 1;
            }
        }
        $tail = trim(substr($body, $start));
        if ($tail !== '') { $parts[] = $tail; }
        return $parts;
    }

    /**
     * SQLite 没有检查约束目录表，使用受引号保护的括号扫描 sqlite_master 原文。
     * 无法闭合的约束保留原文摘要，由生成器标记为不完整而不是静默丢弃。
     *
     * @return array{checks:list<string>,unsupported:list<string>}
     */
    private function extractChecks(string $sql): array
    {
        $checks = [];
        $unsupported = [];
        $length = strlen($sql);
        for ($offset = 0; $offset < $length; ++$offset) {
            if (strncasecmp(substr($sql, $offset, 5), 'CHECK', 5) !== 0
                || ($offset > 0 && preg_match('/[A-Za-z0-9_]/', $sql[$offset - 1]) === 1)) {
                continue;
            }
            $cursor = $offset + 5;
            while ($cursor < $length && ctype_space($sql[$cursor])) { ++$cursor; }
            if ($cursor >= $length || $sql[$cursor] !== '(') {
                $unsupported[] = substr($sql, $offset, 5);
                continue;
            }
            $start = $cursor;
            $depth = 0;
            $quote = '';
            for (; $cursor < $length; ++$cursor) {
                $char = $sql[$cursor];
                if ($quote !== '') {
                    if ($char === $quote) {
                        if ($cursor + 1 < $length && $sql[$cursor + 1] === $quote) { ++$cursor; continue; }
                        $quote = '';
                    }
                    continue;
                }
                if ($char === "'" || $char === '"' || $char === '`' || $char === '[') {
                    $quote = $char === '[' ? ']' : $char;
                    continue;
                }
                if ($char === '(') { ++$depth; }
                if ($char === ')' && --$depth === 0) {
                    $checks[] = 'CHECK ' . substr($sql, $start, $cursor - $start + 1);
                    break;
                }
            }
            if ($depth !== 0) {
                $unsupported[] = substr($sql, $offset);
            }
            $offset = $cursor;
        }
        return ['checks' => $checks, 'unsupported' => $unsupported];
    }

    /**
     * 只接受 PRAGMA defer_foreign_keys 开关。
     */
    public function foreignKeyDeferral(string $sql): bool
    {
        return preg_match('/^\s*PRAGMA\s+defer_foreign_keys\s*=\s*(ON|OFF)\s*;?\s*$/iD', $sql) === 1;
    }

    /**
     * 内存库不加文件锁；文件库在操作结束时释放互斥。
     */
    public function lock(Connection $connection, string $table, \Closure $operation): mixed
    {
        $file = (string)$connection->query('PRAGMA database_list')[0]['file'];
        return $file === '' ? $operation() : $this->locked($file, $operation);
    }

    /**
     * @param \Closure(): mixed $operation
     */
    private function locked(string $file, \Closure $operation): mixed
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
            $handles[] = $this->fileLock($databaseFile . '.type-migration.lock', true);
            if (PHP_OS_FAMILY === 'Linux') {
                $handles[] = $this->fileLock($databaseFile, false);
            }
            return $operation();
        } catch (\Throwable $error) {
            $failed = true;
            throw $error;
        } finally {
            $released = true;
            foreach (array_reverse($handles) as $handle) {
                if (is_resource($handle)) {
                    $released = @flock($handle, LOCK_UN) && @fclose($handle) && $released;
                }
            }
            if (!$released && !$failed) {
                throw new MigrationException('TYPE_MIGRATION_UNLOCK_FAILED：迁移已结束但释放 SQLite 文件锁失败');
            }
        }
    }

    /**
     * @return resource
     */
    private function fileLock(string $path, bool $create): mixed
    {
        $handle = null;
        for ($attempt = 0; $attempt < 3; ++$attempt) {
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
            $handle = @fopen($path, $before === false ? 'x+b' : 'r+b');
            if (is_resource($handle) || $before !== false) {
                break;
            }
        }
        if (!is_resource($handle)) {
            throw new MigrationException('TYPE_MIGRATION_LOCK_FAILED：无法创建或打开 SQLite 迁移锁文件');
        }
        try {
            $this->identity($path, $handle);
            if (!@flock($handle, LOCK_EX | LOCK_NB)) {
                throw new MigrationException('TYPE_MIGRATION_LOCKED：已有迁移持有 SQLite 文件互斥锁');
            }
            $this->identity($path, $handle);
            return $handle;
        } catch (\Throwable $error) {
            @flock($handle, LOCK_UN);
            fclose($handle);
            throw $error;
        }
    }

    /**
     * @param resource $handle
     */
    private function identity(string $path, mixed $handle): void
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
}
