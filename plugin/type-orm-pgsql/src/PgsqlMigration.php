<?php

declare(strict_types=1);

namespace Type\Orm\Pgsql;

use Type\Orm\Connection;
use Type\Orm\Migration\AbstractMigrationDriver;
use Type\Orm\Migration\MigrationException;

/**
 * PostgreSQL 的结构导出、事务 DDL 与 advisory lock。
 */
final class PgsqlMigration extends AbstractMigrationDriver
{
    /**
     * DDL 可以和迁移记录放在同一个事务里。
     */
    public function transactionalDdl(): bool
    {
        return true;
    }

    /**
     * 当前 schema 没有任何关系对象时视为空库。
     */
    public function isEmpty(Connection $connection): bool
    {
        return $connection->query("SELECT relname FROM pg_class WHERE relnamespace = current_schema()::regnamespace AND relkind IN ('r', 'p', 'v', 'm', 'S', 'f')") === [];
    }

    /**
     * 按 information_schema 判断当前 schema 中的表。
     */
    public function tableExists(Connection $connection, string $table): bool
    {
        return count($connection->query('SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?', [$table])) > 0;
    }

    /**
     * 按 information_schema 判断当前 schema 中的列。
     */
    public function columnExists(Connection $connection, string $table, string $column): bool
    {
        return count($connection->query(
            'SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
            [$table, $column]
        )) > 0;
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
     * 按当前 schema 重建建表、约束和索引语句，不含迁移记录表。
     */
    public function structure(Connection $connection): array
    {
        $tables = $connection->query(
            "SELECT c.oid, c.relname AS name FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace "
            . "WHERE c.relkind IN ('r', 'p') AND n.nspname = current_schema() "
            . "AND c.relname NOT IN ('type_migrations', 'type_migrations_events') ORDER BY c.oid"
        );
        $statements = [];
        foreach ($tables as $table) {
            $oid = (int)$table['oid'];
            $name = (string)$table['name'];
            $columns = $connection->query(
                'SELECT a.attname AS name, format_type(a.atttypid, a.atttypmod) AS declaration, a.attnotnull AS not_null, '
                . 'pg_get_expr(d.adbin, d.adrelid) AS default_value FROM pg_attribute a '
                . 'LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum '
                . 'WHERE a.attrelid = ? AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum', [$oid]
            );
            $parts = [];
            foreach ($columns as $column) {
                $part = $this->quote((string)$column['name']) . ' ' . (string)$column['declaration'];
                if ($column['default_value'] !== null) {
                    $part .= ' DEFAULT ' . (string)$column['default_value'];
                }
                if (in_array(strtolower((string)$column['not_null']), ['t', 'true', '1'], true)) {
                    $part .= ' NOT NULL';
                }
                $parts[] = $part;
            }
            $constraints = $connection->query(
                "SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid = ? AND contype IN ('p', 'u', 'f', 'c') ORDER BY oid",
                [$oid]
            );
            foreach ($constraints as $constraint) {
                $parts[] = (string)$constraint['definition'];
            }
            $statements[] = 'CREATE TABLE ' . $this->quote($name) . ' (' . implode(', ', $parts) . ')';
            $indexes = $connection->query(
                "SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? "
                . "AND indexname NOT IN (SELECT conname FROM pg_constraint WHERE conrelid = ? AND conname IS NOT NULL) ORDER BY indexname",
                [$name, $oid]
            );
            foreach ($indexes as $index) {
                $statements[] = rtrim((string)$index['indexdef'], " \t\r\n;");
            }
        }
        return $statements;
    }

    /**
     * 读取列、主键、索引、外键、检查约束和注释。
     */
    public function describe(Connection $connection): array
    {
        $tables = $connection->query(
            "SELECT c.relname AS name, obj_description(c.oid) AS comment FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace "
            . "WHERE c.relkind IN ('r', 'p') AND n.nspname = current_schema() AND c.relname NOT IN ('type_migrations', 'type_migrations_events') ORDER BY c.relname"
        );
        $described = [];
        foreach ($tables as $table) {
            $name = (string)$table['name'];
            $columns = $connection->query(
                'SELECT column_name AS name, data_type AS type, character_maximum_length AS length, is_nullable AS nullable, '
                . 'column_default AS default_value, numeric_precision AS precision_value, numeric_scale AS scale_value '
                . 'FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position',
                [$name]
            );
            $columnComments = [];
            foreach ($connection->query(
                'SELECT a.attname AS name, col_description(a.attrelid, a.attnum) AS comment '
                . 'FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid '
                . 'JOIN pg_namespace n ON n.oid = c.relnamespace '
                . 'WHERE c.relname = ? AND n.nspname = current_schema() AND a.attnum > 0 AND NOT a.attisdropped',
                [$name]
            ) as $columnComment) {
                $columnComments[(string)$columnComment['name']] = (string)($columnComment['comment'] ?? '');
            }
            $primary = array_map(static fn (array $row): string => (string)$row['name'], $connection->query(
                "SELECT a.attname AS name FROM pg_index i "
                . "JOIN pg_class c ON c.oid = i.indrelid JOIN pg_namespace n ON n.oid = c.relnamespace "
                . "CROSS JOIN LATERAL unnest(i.indkey) WITH ORDINALITY AS key(attnum, position) "
                . "JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = key.attnum "
                . "WHERE i.indisprimary AND n.nspname = current_schema() AND c.relname = ? ORDER BY key.position",
                [$name]
            ));
            $unsupported = [];
            $mapped = [];
            foreach ($columns as $column) {
                $type = strtolower((string)$column['type']);
                $rawDefault = $column['default_value'];
                $defaultValue = self::defaultLiteral($rawDefault);
                $isSequence = is_string($rawDefault) && str_contains($rawDefault, 'nextval');
                $defaultText = is_string($rawDefault) ? trim($rawDefault) : '';
                $simpleDefault = $defaultText === '' || strcasecmp($defaultText, 'NULL') === 0
                    || preg_match('/^-?(?:[0-9]+(?:\\.[0-9]+)?|[0-9]+e[+-]?[0-9]+)$/iD', $defaultText) === 1
                    || preg_match('/^(?:true|false)$/iD', $defaultText) === 1
                    || preg_match("/^'((?:''|[^'])*)'(?:\s*::[A-Za-z0-9_ .\[\]]+)?$/D", $defaultText) === 1;
                if (is_string($rawDefault) && !$isSequence && (!$simpleDefault || str_contains($rawDefault, '('))) {
                    $unsupported[] = 'column ' . (string)$column['name'] . ': default expression';
                }
                $blueprint = match (true) {
                    in_array($type, ['character varying', 'character'], true) => 'string',
                    $type === 'text' => 'text',
                    $type === 'boolean' => 'boolean',
                    in_array($type, ['numeric', 'decimal'], true) => 'decimal',
                    str_contains($type, 'timestamp') => 'datetime',
                    default => 'integer',
                };
                $mapped[] = [
                    'name' => (string)$column['name'], 'type' => $blueprint,
                    'length' => $column['length'] !== null ? (int)$column['length'] : null,
                    'bits' => $type === 'bigint' ? 64 : 32,
                    'precision' => $column['precision_value'] !== null ? (int)$column['precision_value'] : null,
                    'scale' => $column['scale_value'] !== null ? (int)$column['scale_value'] : null,
                    'nullable' => strtolower((string)$column['nullable']) === 'yes',
                    'default' => is_string($defaultValue) && str_contains($defaultValue, '(') ? null : $defaultValue,
                    'comment' => $columnComments[(string)$column['name']] ?? '',
                    'auto' => $isSequence,
                ];
            }
            $indexes = [];
            $current = null;
            foreach ($connection->query(
                "SELECT idx.indexname AS name, i.indisunique AS is_unique, a.attname AS column_name, key.ord AS position "
                . "FROM pg_indexes idx JOIN pg_namespace ns ON ns.nspname = idx.schemaname "
                . "JOIN pg_class table_class ON table_class.relnamespace = ns.oid AND table_class.relname = idx.tablename "
                . "JOIN pg_class index_class ON index_class.relnamespace = ns.oid AND index_class.relname = idx.indexname "
                . "JOIN pg_index i ON i.indexrelid = index_class.oid "
                . "CROSS JOIN LATERAL unnest(i.indkey) WITH ORDINALITY AS key(attnum, ord) "
                . "LEFT JOIN pg_attribute a ON a.attrelid = table_class.oid AND a.attnum = key.attnum "
                . "WHERE idx.schemaname = current_schema() AND idx.tablename = ? AND NOT i.indisprimary "
                . "ORDER BY idx.indexname, key.ord",
                [$name]
            ) as $index) {
                if ($current !== $index['name']) {
                    $indexes[] = ['name' => (string)$index['name'], 'columns' => [], 'unique' => in_array(strtolower((string)$index['is_unique']), ['t', 'true', '1'], true)];
                    $current = $index['name'];
                }
                if ($index['column_name'] === null || (string)$index['column_name'] === '') {
                    $indexes[array_key_last($indexes)]['unsupported'] = 'expression index';
                    continue;
                }
                $indexes[array_key_last($indexes)]['columns'][] = (string)$index['column_name'];
            }
            foreach ($indexes as $index) {
                if (isset($index['unsupported'])) {
                    $unsupported[] = 'index ' . $index['name'] . ': ' . $index['unsupported'];
                }
            }
            $indexes = array_map(static function (array $index): array {
                unset($index['unsupported']);
                return $index;
            }, $indexes);
            $foreign = [];
            foreach ($connection->query(
                "SELECT con.conname AS name, con.confdeltype AS delete_action, source.attname AS column_name, referenced.relname AS referenced_table, target.attname AS referenced_column, source_key.ordinality AS position "
                . "FROM pg_constraint con "
                . "JOIN pg_class local ON local.oid = con.conrelid "
                . "JOIN pg_namespace local_schema ON local_schema.oid = local.relnamespace "
                . "JOIN pg_class referenced ON referenced.oid = con.confrelid "
                . "CROSS JOIN LATERAL unnest(con.conkey) WITH ORDINALITY AS source_key(attnum, ordinality) "
                . "JOIN pg_attribute source ON source.attrelid = con.conrelid AND source.attnum = source_key.attnum "
                . "JOIN LATERAL unnest(con.confkey) WITH ORDINALITY AS target_key(attnum, ordinality) ON target_key.ordinality = source_key.ordinality "
                . "JOIN pg_attribute target ON target.attrelid = con.confrelid AND target.attnum = target_key.attnum "
                . "WHERE con.contype = 'f' AND local_schema.nspname = current_schema() AND local.relname = ? "
                . "ORDER BY con.conname, source_key.ordinality",
                [$name]
            ) as $key) {
                $found = null;
                foreach ($foreign as $index => $item) {
                    if ($item['name'] === (string)$key['name']) { $found = $index; break; }
                }
                if ($found === null) {
                    $delete = match ((string)$key['delete_action']) {
                        'c' => 'CASCADE', 'n' => 'SET NULL', 'r' => 'RESTRICT', 'd' => 'SET DEFAULT', default => null,
                    };
                    if ($delete === 'SET DEFAULT') {
                        $unsupported[] = 'foreign ' . (string)$key['name'] . ': SET DEFAULT';
                    }
                    $foreign[] = ['name' => (string)$key['name'], 'columns' => [], 'on' => (string)$key['referenced_table'], 'references' => [], 'onDelete' => $delete === 'SET DEFAULT' ? null : $delete];
                    $found = array_key_last($foreign);
                }
                $foreign[$found]['columns'][] = (string)$key['column_name'];
                $foreign[$found]['references'][] = (string)$key['referenced_column'];
            }
            $checks = array_map(static fn (array $row): string => (string)$row['definition'], $connection->query(
                "SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid = (SELECT c.oid FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relname = ? AND n.nspname = current_schema()) AND contype = 'c' ORDER BY oid",
                [$name]
            ));
            $described[] = ['name' => $name, 'comment' => (string)($table['comment'] ?? ''), 'columns' => $mapped, 'primary' => $primary, 'indexes' => $indexes, 'foreign' => $foreign, 'checks' => $checks, 'unsupported' => $unsupported];
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
        if (preg_match("/^'((?:''|[^'])*)'(?:\s*::[A-Za-z0-9_ .\[\]]+)?$/D", $value, $matches) === 1) {
            return str_replace("''", "'", $matches[1]);
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
     * PostgreSQL 不使用 SQLite 的外键延迟开关。
     */
    public function foreignKeyDeferral(string $sql): bool
    {
        return false;
    }

    /**
     * 用 advisory lock 互斥；操作结束或失败后都释放锁。
     */
    public function lock(Connection $connection, string $table, \Closure $operation): mixed
    {
        $schema = (string)$connection->query('SELECT current_schema() AS name')[0]['name'];
        $key = (int)hexdec(substr(hash('sha256', $schema . ':' . $table), 0, 7));
        if ((int)$connection->query('SELECT CASE WHEN pg_try_advisory_lock(1348032837, ?) THEN 1 ELSE 0 END AS acquired', [$key])[0]['acquired'] !== 1) {
            throw new MigrationException('TYPE_MIGRATION_LOCKED：已有迁移持有数据库互斥锁');
        }
        $failed = false;
        try {
            return $operation();
        } catch (\Throwable $error) {
            $failed = true;
            throw $error;
        } finally {
            try {
                $connection->query('SELECT pg_advisory_unlock(1348032837, ?)', [$key]);
            } catch (\Throwable $cleanupError) {
                if (!$failed) {
                    throw new MigrationException('TYPE_MIGRATION_UNLOCK_FAILED：迁移已结束但释放会话锁失败', 0, $cleanupError);
                }
            }
        }
    }

    private function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
