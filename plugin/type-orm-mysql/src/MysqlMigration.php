<?php

declare(strict_types=1);

namespace Type\Orm\Mysql;

use Type\Orm\Connection;
use Type\Orm\Migration\AbstractMigrationDriver;
use Type\Orm\Migration\MigrationException;

/**
 * MySQL 的结构导出、非事务 DDL 与会话锁。
 */
final class MysqlMigration extends AbstractMigrationDriver
{
    /**
     * DDL 会隐式提交，不能和迁移记录放在同一个事务里。
     */
    public function transactionalDdl(): bool
    {
        return false;
    }

    /**
     * 当前库没有任何基表时视为空库。
     */
    public function isEmpty(Connection $connection): bool
    {
        return $connection->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()') === [];
    }

    /**
     * 按 information_schema 判断当前库中的表。
     */
    public function tableExists(Connection $connection, string $table): bool
    {
        return count($connection->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table])) > 0;
    }

    /**
     * 按 information_schema 判断当前库中的列。
     */
    public function columnExists(Connection $connection, string $table, string $column): bool
    {
        return count($connection->query(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        )) > 0;
    }

    /**
     * 建立迁移记录和事件表。版本列使用 ascii 比较，避免大小写或重音折叠。
     */
    public function migrationTables(string $table): array
    {
        $version = 'VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin';
        return [
            'CREATE TABLE IF NOT EXISTS ' . $table . ' (version ' . $version . ' PRIMARY KEY, checksum VARCHAR(64) NOT NULL, description VARCHAR(500) NOT NULL, state VARCHAR(20) NOT NULL, transactional INTEGER NOT NULL, attempts INTEGER NOT NULL, started_at VARCHAR(40) NOT NULL, finished_at VARCHAR(40) NOT NULL, error TEXT NOT NULL) ENGINE=InnoDB',
            'CREATE TABLE IF NOT EXISTS ' . $table . '_events (number INTEGER PRIMARY KEY, version VARCHAR(64) NOT NULL, state VARCHAR(30) NOT NULL, attempt INTEGER NOT NULL, recorded_at VARCHAR(40) NOT NULL, note TEXT NOT NULL) ENGINE=InnoDB',
        ];
    }

    /**
     * 导出 SHOW CREATE TABLE，不含迁移记录表。
     */
    public function structure(Connection $connection): array
    {
        $tables = $connection->query(
            "SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' "
            . "AND TABLE_NAME NOT IN ('type_migrations', 'type_migrations_events') ORDER BY TABLE_NAME"
        );
        $statements = [];
        foreach ($tables as $table) {
            $name = (string)$table['name'];
            $row = $connection->query('SHOW CREATE TABLE `' . str_replace('`', '``', $name) . '`');
            if (count($row) !== 1) {
                throw new \RuntimeException('TYPE_MIGRATION_GENERATE_TABLE：无法读取 MySQL 表结构：' . $name);
            }
            $statements[] = rtrim((string)array_values($row[0])[1], " \t\r\n;");
        }
        return $statements;
    }

    /**
     * 读取列注释、索引和外键，供生成 Blueprint。检查约束不伪装成已完整表达。
     */
    public function describe(Connection $connection): array
    {
        $tables = $connection->query(
            "SELECT TABLE_NAME AS name, TABLE_COMMENT AS comment FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME NOT IN ('type_migrations', 'type_migrations_events') ORDER BY TABLE_NAME"
        );
        $described = [];
        foreach ($tables as $table) {
            $name = (string)$table['name'];
            $columns = $connection->query(
                'SELECT COLUMN_NAME AS name, DATA_TYPE AS type, COLUMN_TYPE AS declaration, CHARACTER_MAXIMUM_LENGTH AS length, '
                . 'IS_NULLABLE AS nullable, COLUMN_DEFAULT AS default_value, COLUMN_KEY AS key_type, EXTRA AS extra, COLUMN_COMMENT AS comment, '
                . 'NUMERIC_PRECISION AS precision_value, NUMERIC_SCALE AS scale_value FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
                [$name]
            );
            $primary = [];
            $mapped = [];
            foreach ($columns as $column) {
                if ((string)$column['key_type'] === 'PRI') {
                    $primary[] = (string)$column['name'];
                }
                $declaration = strtolower((string)$column['declaration']);
                $type = strtolower((string)$column['type']);
                $blueprint = match (true) {
                    $type === 'tinyint' && str_contains($declaration, 'tinyint(1)') => 'boolean',
                    in_array($type, ['varchar', 'char'], true) => 'string',
                    in_array($type, ['text', 'mediumtext', 'longtext', 'json'], true) => 'text',
                    in_array($type, ['decimal', 'numeric'], true) => 'decimal',
                    in_array($type, ['datetime', 'timestamp'], true) => 'datetime',
                    default => 'integer',
                };
                $mapped[] = [
                    'name' => (string)$column['name'], 'type' => $blueprint,
                    'length' => $column['length'] !== null ? (int)$column['length'] : null,
                    'bits' => $type === 'bigint' ? 64 : 32,
                    'precision' => $column['precision_value'] !== null ? (int)$column['precision_value'] : null,
                    'scale' => $column['scale_value'] !== null ? (int)$column['scale_value'] : null,
                    'nullable' => strtoupper((string)$column['nullable']) === 'YES',
                    'default' => $column['default_value'],
                    'comment' => (string)$column['comment'],
                    'auto' => str_contains(strtolower((string)$column['extra']), 'auto_increment'),
                ];
            }
            $indexes = [];
            $current = null;
            foreach ($connection->query(
                'SELECT INDEX_NAME AS name, NON_UNIQUE AS non_unique, COLUMN_NAME AS column_name FROM information_schema.STATISTICS '
                . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME <> 'PRIMARY' ORDER BY INDEX_NAME, SEQ_IN_INDEX",
                [$name]
            ) as $index) {
                if ($current !== $index['name']) {
                    $indexes[] = ['name' => (string)$index['name'], 'columns' => [], 'unique' => (int)$index['non_unique'] === 0];
                    $current = $index['name'];
                }
                $indexes[array_key_last($indexes)]['columns'][] = (string)$index['column_name'];
            }
            $foreign = [];
            $constraint = null;
            foreach ($connection->query(
                'SELECT k.CONSTRAINT_NAME AS name, k.COLUMN_NAME AS column_name, k.REFERENCED_TABLE_NAME AS referenced_table, '
                . 'k.REFERENCED_COLUMN_NAME AS referenced_column, r.DELETE_RULE AS delete_rule FROM information_schema.KEY_COLUMN_USAGE k '
                . 'LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME '
                . 'WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
                [$name]
            ) as $key) {
                if ($constraint !== $key['name']) {
                    $delete = strtoupper((string)($key['delete_rule'] ?? 'NO ACTION'));
                    $foreign[] = ['name' => (string)$key['name'], 'columns' => [], 'on' => (string)$key['referenced_table'], 'references' => [], 'onDelete' => $delete === 'NO ACTION' ? null : $delete];
                    $constraint = $key['name'];
                }
                $foreign[array_key_last($foreign)]['columns'][] = (string)$key['column_name'];
                $foreign[array_key_last($foreign)]['references'][] = (string)$key['referenced_column'];
            }
            $checks = array_map(static fn (array $row): string => (string)$row['check_clause'], $connection->query(
                'SELECT c.CHECK_CLAUSE AS check_clause FROM information_schema.TABLE_CONSTRAINTS t '
                . 'JOIN information_schema.CHECK_CONSTRAINTS c ON c.CONSTRAINT_SCHEMA = t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME = t.CONSTRAINT_NAME '
                . "WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = ? AND t.CONSTRAINT_TYPE = 'CHECK' ORDER BY t.CONSTRAINT_NAME",
                [$name]
            ));
            $described[] = ['name' => $name, 'comment' => (string)$table['comment'], 'columns' => $mapped, 'primary' => $primary, 'indexes' => $indexes, 'foreign' => $foreign, 'checks' => $checks];
        }
        return $described;
    }

    /**
     * MySQL 不使用 SQLite 的外键延迟开关。
     */
    public function foreignKeyDeferral(string $sql): bool
    {
        return false;
    }

    /**
     * 用 GET_LOCK 互斥；操作结束或失败后都释放锁。
     */
    public function lock(Connection $connection, string $table, \Closure $operation): mixed
    {
        $database = (string)$connection->query('SELECT DATABASE() AS name')[0]['name'];
        $name = 'type-migration:' . substr(hash('sha256', $database . ':' . $table), 0, 48);
        if ((int)$connection->query('SELECT GET_LOCK(?, 0) AS acquired', [$name])[0]['acquired'] !== 1) {
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
                $connection->query('SELECT RELEASE_LOCK(?)', [$name]);
            } catch (\Throwable $cleanupError) {
                if (!$failed) {
                    throw new MigrationException('TYPE_MIGRATION_UNLOCK_FAILED：迁移已结束但释放会话锁失败', 0, $cleanupError);
                }
            }
        }
    }
}
