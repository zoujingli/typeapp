<?php

declare(strict_types=1);

namespace Type\Orm\Schema;

use Type\Orm\Migration\MigrationException;

/**
 * 把 Blueprint 编译成 MySQL、PostgreSQL 或 SQLite 语句。
 */
final class Grammar
{
    /**
     * @return list<string>
     */
    public static function create(Blueprint $blueprint, string $driver): array
    {
        if ($blueprint->columns === [] || $blueprint->primary === []) {
            throw new MigrationException('TYPE_SCHEMA_INVALID：建表需要列和主键');
        }
        $definitions = [];
        $auto = '';
        foreach ($blueprint->columns as $column) {
            if ($column->auto) {
                $auto = $column->name;
            }
        }
        if ($auto !== '' && !in_array($auto, $blueprint->primary, true)) {
            throw new MigrationException('TYPE_SCHEMA_INVALID：自增列必须是主键');
        }
        $inlinePrimary = count($blueprint->primary) === 1 && $auto === '';
        foreach ($blueprint->columns as $column) {
            $definitions[] = self::definition($column, $driver, $inlinePrimary && $column->name === $blueprint->primary[0]);
        }
        if (!$inlinePrimary && ($auto === '' || $driver !== 'sqlite')) {
            $definitions[] = 'PRIMARY KEY (' . implode(', ', array_map(static fn (string $name): string => self::quote($name, $driver), $blueprint->primary)) . ')';
        }
        foreach ($blueprint->foreignKeys as $foreign) {
            $clause = 'FOREIGN KEY (' . implode(', ', array_map(static fn (string $name): string => self::quote($name, $driver), $foreign['columns'])) . ') REFERENCES '
                . self::quote($foreign['on'], $driver) . ' (' . implode(', ', array_map(static fn (string $name): string => self::quote($name, $driver), $foreign['references'])) . ')';
            if ($foreign['onDelete'] !== null) {
                $clause .= ' ON DELETE ' . self::referentialAction($foreign['onDelete']);
            }
            $definitions[] = $clause;
        }
        foreach ($blueprint->checks as $check) {
            $definitions[] = 'CHECK (' . self::expression($check) . ')';
        }
        $sql = 'CREATE TABLE ' . self::quote($blueprint->table, $driver) . ' (' . implode(', ', $definitions) . ')';
        if ($driver === 'mysql') {
            $sql .= ' ENGINE=InnoDB';
            if ($blueprint->charset !== null) {
                $sql .= ' DEFAULT CHARACTER SET ' . self::charsetName($blueprint->charset);
                if ($blueprint->collation !== null) {
                    $sql .= ' COLLATE ' . self::charsetName($blueprint->collation);
                }
            }
            if ($blueprint->comment !== null) {
                $sql .= ' COMMENT=' . self::string($blueprint->comment);
            }
        }
        $statements = [$sql];
        foreach ($blueprint->indexes as $index) {
            $statements[] = self::index($blueprint->table, $index, $driver);
        }
        return $statements;
    }

    /**
     * @return list<string>
     */
    public static function alter(Blueprint $blueprint, string $driver): array
    {
        $statements = [];
        $table = self::quote($blueprint->table, $driver);
        foreach ($blueprint->columns as $column) {
            if (!$column->nullable && !$column->hasDefault) {
                throw new MigrationException('TYPE_SCHEMA_INVALID：新增列必须可空或带默认值：' . $column->name);
            }
            $statements[] = 'ALTER TABLE ' . $table . ' ADD COLUMN ' . self::definition($column, $driver, false);
        }
        foreach ($blueprint->dropColumns as $name) {
            $statements[] = 'ALTER TABLE ' . $table . ' DROP COLUMN ' . self::quote($name, $driver);
        }
        foreach ($blueprint->indexes as $index) {
            $statements[] = self::index($blueprint->table, $index, $driver);
        }
        return $statements;
    }

    /**
     * 表不存在时语句仍然成功。
     */
    public static function dropIfExists(string $table, string $driver): string
    {
        return 'DROP TABLE IF EXISTS ' . self::quote($table, $driver);
    }

    private static function definition(ColumnDefinition $column, string $driver, bool $primary): string
    {
        $type = match ($column->type) {
            'string' => 'VARCHAR(' . ($column->length ?? 255) . ')',
            'text' => 'TEXT',
            'integer' => ($column->length ?? 32) === 64 ? 'BIGINT' : 'INTEGER',
            'boolean' => $driver === 'sqlite' ? 'INTEGER' : 'BOOLEAN',
            'decimal' => $driver === 'sqlite' ? 'TEXT' : 'DECIMAL(' . ($column->precision ?? 10) . ', ' . ($column->scale ?? 2) . ')',
            'datetime' => match ($driver) {
                'mysql' => 'DATETIME(6)', 'pgsql' => 'TIMESTAMP(6) WITHOUT TIME ZONE', default => 'TEXT'
            },
            default => throw new MigrationException('TYPE_SCHEMA_INVALID：未知字段类型：' . $column->type),
        };
        $sql = self::quote($column->name, $driver) . ' ' . $type;
        if ($column->charset !== null && $driver === 'mysql') {
            $sql .= ' CHARACTER SET ' . self::charsetName($column->charset);
            if ($column->collation !== null) {
                $sql .= ' COLLATE ' . self::charsetName($column->collation);
            }
        }
        $sql .= $column->nullable ? ' NULL' : ' NOT NULL';
        if ($column->hasDefault) {
            $sql .= ' DEFAULT ' . self::defaultValue($column, $driver);
        }
        if ($column->auto) {
            $sql .= match ($driver) {
                'mysql' => ' AUTO_INCREMENT', 'pgsql' => ' GENERATED BY DEFAULT AS IDENTITY', default => ' PRIMARY KEY AUTOINCREMENT'
            };
        } elseif ($primary) {
            $sql .= ' PRIMARY KEY';
        }
        if ($column->unique) {
            $sql .= ' UNIQUE';
        }
        if ($column->check !== null) {
            $sql .= ' CHECK (' . self::expression($column->check) . ')';
        }
        if ($column->comment !== null && $driver === 'mysql') {
            $sql .= ' COMMENT ' . self::string($column->comment);
        }
        if ($column->after !== null && $driver === 'mysql') {
            $sql .= ' AFTER ' . self::quote($column->after, $driver);
        }
        return $sql;
    }

    /**
     * @param array{name:string,columns:list<string>,unique:bool} $index
     */
    private static function index(string $table, array $index, string $driver): string
    {
        $columns = array_map(static fn (string $name): string => self::quote($name, $driver), $index['columns']);
        return 'CREATE ' . ($index['unique'] ? 'UNIQUE ' : '') . 'INDEX ' . self::quote($index['name'], $driver)
            . ' ON ' . self::quote($table, $driver) . ' (' . implode(', ', $columns) . ')';
    }

    private static function defaultValue(ColumnDefinition $column, string $driver): string
    {
        $value = $column->default;
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $driver === 'pgsql' ? ($value ? 'TRUE' : 'FALSE') : ($value ? '1' : '0');
        }
        if (is_int($value)) {
            return (string)$value;
        }
        return self::string((string)$value);
    }

    /**
     * 引用表名或列名。只接受小写标识符，避免插入语句拼接未检查的名字。
     */
    public static function identifier(string $identifier, string $driver): string
    {
        return self::quote($identifier, $driver);
    }

    /**
     * 把迁移插入值写成 SQL 字面量。非有限数字和数组会拒绝，不转成 PHP 的 Array 文本。
     */
    public static function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value)) {
            return (string)$value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new MigrationException('TYPE_SCHEMA_INVALID：插入值必须是有限数字');
            }
            return (string)$value;
        }
        if (!is_string($value)) {
            throw new MigrationException('TYPE_SCHEMA_INVALID：插入值只接受标量或 null');
        }
        return self::string($value);
    }

    private static function quote(string $identifier, string $driver): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $identifier) !== 1) {
            throw new MigrationException('TYPE_SCHEMA_INVALID：标识符须为小写字母、数字和下划线：' . $identifier);
        }
        $quote = $driver === 'mysql' ? '`' : '"';
        return $quote . $identifier . $quote;
    }

    private static function string(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    private static function expression(string $expression): string
    {
        if (preg_match('/^[A-Za-z0-9_\\s=<>!().,\'+-]+$/D', $expression) !== 1 || str_contains($expression, '--')) {
            throw new MigrationException('TYPE_SCHEMA_INVALID：检查表达式包含不允许的字符');
        }
        return $expression;
    }

    private static function referentialAction(string $action): string
    {
        $normalized = strtoupper($action);
        if (!in_array($normalized, ['CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION'], true)) {
            throw new MigrationException('TYPE_SCHEMA_INVALID：外键动作只接受 CASCADE、SET NULL、RESTRICT 或 NO ACTION');
        }
        return $normalized;
    }

    private static function charsetName(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $name) !== 1) {
            throw new MigrationException('TYPE_SCHEMA_INVALID：字符集或排序规则无效：' . $name);
        }
        return $name;
    }
}
