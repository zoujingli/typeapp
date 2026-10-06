<?php

declare(strict_types=1);

namespace Type\Build;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** 只由显式 Schema 准备入口调用的确定性三库 SQL 生成器，不连接数据库。 */
final class SchemaSql
{
    /** 冻结协议升级需要显式准备新迁移，不能在构建时改写旧 SQL。 */
    public const PROTOCOL = 1;

    /** @param list<array<string, mixed>> $operations */
    public function generate(array $operations, string $driver): array
    {
        if (!in_array($driver, ['mysql', 'pgsql', 'sqlite'], true) || !array_is_list($operations) || $operations === [] || count($operations) > 256) {
            throw new RuntimeException('TYPE_SCHEMA_INVALID：需要受支持驱动及有限非空操作列表');
        }
        $sql = [];
        foreach ($operations as $operation) {
            if (!is_array($operation) || !is_string($operation['action'] ?? null) || !is_string($operation['table'] ?? null)) {
                throw new RuntimeException('TYPE_SCHEMA_INVALID：每个操作需要 action 与 table');
            }
            $table = $this->quote($operation['table'], $driver);
            switch ($operation['action']) {
                case 'create':
                    array_push($sql, ...$this->create($operation, $driver));
                    break;
                case 'add-column':
                    $this->keys($operation, ['action', 'table', 'name', 'column']);
                    $column = $operation['column'] ?? [];
                    if (!is_array($column) || ($column['auto'] ?? false) || (!($column['nullable'] ?? false) && !array_key_exists('default', $column))) {
                        throw new RuntimeException('TYPE_SCHEMA_UNSUPPORTED：增列必须可空或具有非空常量默认值，不能增加自增列');
                    }
                    $sql[] = 'ALTER TABLE ' . $table . ' ADD COLUMN ' . $this->column($operation['name'] ?? '', $column, $driver, false);
                    break;
                case 'rename-table':
                    $this->keys($operation, ['action', 'table', 'to']);
                    $sql[] = 'ALTER TABLE ' . $table . ' RENAME TO ' . $this->quote($operation['to'] ?? '', $driver);
                    break;
                case 'rename-column':
                    $this->keys($operation, ['action', 'table', 'from', 'to']);
                    $sql[] = 'ALTER TABLE ' . $table . ' RENAME COLUMN ' . $this->quote($operation['from'] ?? '', $driver) . ' TO ' . $this->quote($operation['to'] ?? '', $driver);
                    break;
                case 'drop-column':
                    $this->keys($operation, ['action', 'table', 'name']);
                    $sql[] = 'ALTER TABLE ' . $table . ' DROP COLUMN ' . $this->quote($operation['name'] ?? '', $driver);
                    break;
                case 'add-index':
                    $this->keys($operation, ['action', 'table', 'name', 'columns', 'unique']);
                    $sql[] = $this->index($operation['table'], $operation, $driver);
                    break;
                case 'drop-index':
                    $this->keys($operation, ['action', 'table', 'name']);
                    $sql[] = 'DROP INDEX ' . $this->quote($operation['name'] ?? '', $driver) . ($driver === 'mysql' ? ' ON ' . $table : '');
                    break;
                default:
                    throw new RuntimeException('TYPE_SCHEMA_UNSUPPORTED：不支持该结构变更，不自动重建表或修改主外键、类型、null 与默认值');
            }
        }
        return $sql;
    }

    private function create(array $operation, string $driver): array
    {
        $this->keys($operation, ['action', 'table', 'columns', 'primary', 'indexes']);
        $columns = $operation['columns'] ?? [];
        $primary = $operation['primary'] ?? [];
        if (!is_array($columns) || $columns === [] || array_is_list($columns) || count($columns) > 256 || !is_array($primary)) {
            throw new RuntimeException('TYPE_SCHEMA_INVALID：建表需要有限命名列与主键列表');
        }
        $primarySql = $this->columns($primary, $driver);
        $definitions = [];
        $auto = '';
        foreach ($columns as $name => $column) {
            if (!is_string($name) || !is_array($column)) {
                throw new RuntimeException('TYPE_SCHEMA_INVALID：字段声明必须为命名对象');
            }
            $isPrimary = in_array($name, $primary, true);
            if ($isPrimary && (($column['nullable'] ?? false) || in_array($column['type'] ?? '', ['text', 'binary'], true))) {
                throw new RuntimeException('TYPE_SCHEMA_INVALID：主键不可为空或使用 text/binary');
            }
            if ($column['auto'] ?? false) {
                if ($auto !== '' || $primary !== [$name] || ($column['type'] ?? '') !== 'integer' || array_key_exists('default', $column)) {
                    throw new RuntimeException('TYPE_SCHEMA_INVALID：自增只能用于没有默认值的单列整数主键');
                }
                $auto = $name;
            }
            $definitions[] = $this->column($name, $column, $driver, $isPrimary);
        }
        foreach ($primary as $name) {
            if (!array_key_exists($name, $columns)) {
                throw new RuntimeException('TYPE_SCHEMA_INVALID：主键引用未声明列');
            }
        }
        if ($auto === '' || $driver !== 'sqlite') {
            $definitions[] = 'PRIMARY KEY (' . implode(', ', $primarySql) . ')';
        }
        $sql = ['CREATE TABLE ' . $this->quote($operation['table'], $driver) . ' (' . implode(', ', $definitions) . ')' . ($driver === 'mysql' ? ' ENGINE=InnoDB' : '')];
        $indexes = $operation['indexes'] ?? [];
        if (!is_array($indexes) || !array_is_list($indexes) || count($indexes) > 256) {
            throw new RuntimeException('TYPE_SCHEMA_INVALID：索引必须为有限列表');
        }
        $names = [];
        foreach ($indexes as $index) {
            if (!is_array($index) || !is_string($index['name'] ?? null) || isset($names[$index['name']])) {
                throw new RuntimeException('TYPE_SCHEMA_INVALID：索引声明无效或重名');
            }
            $this->keys($index, ['name', 'columns', 'unique']);
            $this->columns($index['columns'] ?? [], $driver);
            foreach ($index['columns'] as $name) {
                if (!isset($columns[$name]) || in_array($columns[$name]['type'] ?? '', ['text', 'binary'], true)) {
                    throw new RuntimeException('TYPE_SCHEMA_INVALID：普通索引需引用已声明的可索引列');
                }
            }
            $sql[] = $this->index($operation['table'], $index, $driver);
            $names[$index['name']] = true;
        }
        return $sql;
    }

    private function column(mixed $name, array $column, string $driver, bool $primary): string
    {
        $this->keys($column, ['type', 'nullable', 'default', 'length', 'bits', 'precision', 'scale', 'auto']);
        $type = $column['type'] ?? '';
        foreach (['nullable', 'auto'] as $flag) {
            if (array_key_exists($flag, $column) && !is_bool($column[$flag])) {
                throw new RuntimeException('TYPE_SCHEMA_INVALID：字段开关必须为布尔值');
            }
        }
        $length = array_key_exists('length', $column) ? $column['length'] : 255;
        $bits = array_key_exists('bits', $column) ? $column['bits'] : 64;
        $precision = array_key_exists('precision', $column) ? $column['precision'] : 30;
        $scale = array_key_exists('scale', $column) ? $column['scale'] : 0;
        if (!is_string($type) || !is_int($length) || $length < 1 || $length > 16383 || !in_array($bits, [32, 64], true)
            || !is_int($precision) || !is_int($scale) || $precision < 1 || $precision > 65 || $scale < 0 || $scale > min(30, $precision)
            || (array_key_exists('length', $column) && $type !== 'string') || (array_key_exists('bits', $column) && $type !== 'integer')
            || ((array_key_exists('precision', $column) || array_key_exists('scale', $column)) && $type !== 'decimal')) {
            throw new RuntimeException('TYPE_SCHEMA_INVALID：字段类型、长度或精度组合无效');
        }
        $sqlType = match ($type) {
            'string' => 'VARCHAR(' . $length . ')',
            'text' => 'TEXT',
            'integer' => $driver === 'sqlite' ? 'INTEGER' : ($bits === 64 ? 'BIGINT' : 'INTEGER'),
            'boolean' => $driver === 'sqlite' ? 'INTEGER' : 'BOOLEAN',
            'decimal' => $driver === 'sqlite' ? 'TEXT' : 'DECIMAL(' . $precision . ', ' . $scale . ')',
            'datetime' => match ($driver) {
                'mysql' => 'DATETIME(6)', 'pgsql' => 'TIMESTAMP(6) WITHOUT TIME ZONE', default => 'TEXT'
            },
            'binary' => match ($driver) {
                'mysql' => 'LONGBLOB', 'pgsql' => 'BYTEA', default => 'BLOB'
            },
            default => throw new RuntimeException('TYPE_SCHEMA_INVALID：未知字段类型'),
        };
        $auto = $column['auto'] ?? false;
        if ($auto && (!$primary || $type !== 'integer')) {
            throw new RuntimeException('TYPE_SCHEMA_INVALID：自增仅用于整数主键');
        }
        $sql = $this->quote($name, $driver) . ' ' . $sqlType;
        if ($auto) {
            $sql .= match ($driver) {
                'mysql' => ' AUTO_INCREMENT', 'pgsql' => ' GENERATED BY DEFAULT AS IDENTITY', default => ' PRIMARY KEY AUTOINCREMENT'
            };
        }
        $sql .= ($column['nullable'] ?? false) ? ' NULL' : ' NOT NULL';
        if (array_key_exists('default', $column)) {
            $sql .= ' DEFAULT ' . $this->defaultValue($column['default'], $type, $column, $driver);
        }
        return $sql;
    }

    private function defaultValue(mixed $value, string $type, array $column, string $driver): string
    {
        if ($value === null) {
            if (!($column['nullable'] ?? false)) {
                throw new RuntimeException('TYPE_SCHEMA_INVALID：非空列不能使用 null 默认值');
            }
            return 'NULL';
        }
        if ($type === 'boolean' && is_bool($value)) {
            return $driver === 'pgsql' ? ($value ? 'TRUE' : 'FALSE') : ($value ? '1' : '0');
        }
        if ($type === 'integer' && is_int($value) && (($column['bits'] ?? 64) === 64 || ($value >= -2147483648 && $value <= 2147483647))) {
            return (string) $value;
        }
        if ($type === 'decimal' && is_string($value) && preg_match('/^-?(0|[1-9][0-9]*)(?:\.([0-9]+))?$/D', $value, $match)) {
            $scale = array_key_exists('scale', $column) ? $column['scale'] : 0;
            if (strlen(ltrim($match[1], '0')) <= ($column['precision'] ?? 30) - $scale && strlen($match[2] ?? '') <= $scale) {
                return $driver === 'sqlite' ? "'" . $value . "'" : $value;
            }
        }
        if ($type === 'datetime' && is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $value)) {
            $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new DateTimeZone('UTC'));
            if ($time !== false && $time->format('Y-m-d\TH:i:s.u\Z') === $value) {
                return "'" . $time->format('Y-m-d H:i:s.u') . "'";
            }
        }
        if ($type === 'string' && is_string($value) && strlen($value) <= ($column['length'] ?? 255) && !str_contains($value, "\0") && !str_contains($value, '\\')) {
            return "'" . str_replace("'", "''", $value) . "'";
        }
        throw new RuntimeException('TYPE_SCHEMA_INVALID：默认值必须符合字段精度和类型；text/binary 只支持 null，不接受 SQL 表达式');
    }

    private function index(string $table, array $index, string $driver): string
    {
        if (array_key_exists('unique', $index) && !is_bool($index['unique'])) {
            throw new RuntimeException('TYPE_SCHEMA_INVALID：unique 必须为布尔值');
        }
        return 'CREATE ' . (($index['unique'] ?? false) ? 'UNIQUE ' : '') . 'INDEX ' . $this->quote($index['name'] ?? '', $driver)
            . ' ON ' . $this->quote($table, $driver) . ' (' . implode(', ', $this->columns($index['columns'] ?? [], $driver)) . ')';
    }

    private function columns(mixed $columns, string $driver): array
    {
        if (!is_array($columns) || $columns === [] || !array_is_list($columns) || count($columns) > 256 || count(array_filter($columns, 'is_string')) !== count($columns) || count(array_unique($columns)) !== count($columns)) {
            throw new RuntimeException('TYPE_SCHEMA_INVALID：主键或索引需要不重复的非空列列表');
        }
        return array_map(fn (string $column): string => $this->quote($column, $driver), $columns);
    }

    private function quote(mixed $identifier, string $driver): string
    {
        if (!is_string($identifier) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $identifier) !== 1) {
            throw new RuntimeException('TYPE_SCHEMA_INVALID：标识符须为最多63字节的小写字母数字及下划线');
        }
        $quote = $driver === 'mysql' ? '`' : '"';
        return $quote . $identifier . $quote;
    }

    private function keys(array $values, array $allowed): void
    {
        if (array_diff(array_keys($values), $allowed) !== []) {
            throw new RuntimeException('TYPE_SCHEMA_INVALID：声明包含未知选项');
        }
    }
}
