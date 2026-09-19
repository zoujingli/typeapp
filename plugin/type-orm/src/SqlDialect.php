<?php

declare(strict_types=1);

namespace Type\Orm;

/** @internal 只生成三个已验证驱动的 SQL 语法。 */
final class SqlDialect
{
    private string $driver;
    private string $version;

    public function __construct(string $driver, string $version)
    {
        if (!in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new DatabaseException('查询不支持该数据库驱动');
        }
        $matches = [];
        if (str_contains(strtolower($version), 'mariadb') || preg_match('/^(\d+(?:\.\d+){0,2})/', $version, $matches) !== 1) {
            throw new DatabaseException('不能确认受支持的数据库服务版本');
        }
        $this->driver = $driver;
        $this->version = $matches[1];
    }

    public function capabilities(): array
    {
        return [
            'driver' => $this->driver,
            'server-version' => $this->version,
            'json-scalar-equality' => ($this->driver === 'mysql' && version_compare($this->version, '8.0.19', '>='))
                || ($this->driver === 'pgsql' && version_compare($this->version, '9.5', '>='))
                || ($this->driver === 'sqlite' && version_compare($this->version, '3.38.0', '>=')),
            'upsert-conflict-target' => ($this->driver === 'pgsql' && version_compare($this->version, '9.5', '>='))
                || ($this->driver === 'sqlite' && version_compare($this->version, '3.24.0', '>=')),
            'upsert-any-unique' => $this->driver === 'mysql' && version_compare($this->version, '8.0.19', '>='),
            'insert-returning' => $this->driver === 'pgsql' || ($this->driver === 'sqlite' && version_compare($this->version, '3.35.0', '>=')),
            'row-lock' => in_array($this->driver, ['mysql', 'pgsql'], true),
            'skip-locked' => ($this->driver === 'mysql' && version_compare($this->version, '8.0.1', '>='))
                || ($this->driver === 'pgsql' && version_compare($this->version, '9.5', '>=')),
            'update-count' => $this->driver === 'mysql' ? 'changed-rows' : 'matched-rows',
            'upsert-count' => $this->driver === 'mysql' ? 'insert-1-update-2-unchanged-0' : 'insert-or-update-1',
        ];
    }

    public function requireCapability(string $capability): void
    {
        $capabilities = $this->capabilities();
        if (!isset($capabilities[$capability]) || $capabilities[$capability] !== true) {
            throw new DatabaseException('当前数据库不支持该查询能力：' . $capability);
        }
    }

    public function identifier(string $identifier, bool $wildcard = false, bool $qualified = true): string
    {
        $parts = explode('.', $identifier);
        if (!$qualified && count($parts) !== 1) {
            throw new DatabaseException('该位置只接受单段标识符');
        }
        $quoted = [];
        // SQLite 的双引号兼容模式会把不存在的列当作文本；反引号避免该静默回退。
        $quote = $this->driver === 'pgsql' ? '"' : '`';
        foreach ($parts as $index => $part) {
            if ($wildcard && $part === '*' && $index === count($parts) - 1) {
                $quoted[] = '*';
                continue;
            }
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $part) !== 1) {
                throw new DatabaseException('SQL 标识符无效，必须来自应用已知范围');
            }
            $quoted[] = $quote . $part . $quote;
        }

        return implode('.', $quoted);
    }

    public function operator(string $operator): string
    {
        $operator = strtoupper(trim($operator));
        if (!in_array($operator, ['=', '<>', '!=', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE'], true)) {
            throw new DatabaseException('查询比较操作符无效');
        }

        return $operator;
    }

    public function placeholder(mixed $value): string
    {
        // PDO 没有浮点参数类型；表达式比较不能依赖列亲和性或错误推断成整数。
        if (is_float($value) && $this->driver === 'sqlite') {
            return 'CAST(? AS REAL)';
        }
        if (is_float($value) && $this->driver === 'pgsql') {
            return 'CAST(? AS NUMERIC)';
        }

        return '?';
    }

    public function aggregate(string $function, string $column): string
    {
        $function = strtoupper($function);
        if (!in_array($function, ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'], true)) {
            throw new DatabaseException('不支持该聚合函数');
        }

        return $function . '(' . $this->identifier($column, $function === 'COUNT') . ')';
    }

    /** 严格区分文本、数字、布尔、JSON null 和缺失；不承诺复合值包含关系。 */
    public function jsonEquals(string $column, array $path, mixed $value): array
    {
        $this->requireCapability('json-scalar-equality');
        $column = $this->identifier($column);
        $this->value($value);
        if ($path === [] || !array_is_list($path) || count($path) > 16) {
            throw new DatabaseException('JSON 路径必须包含 1 至 16 个字段名或非负数组下标');
        }
        $jsonPath = '$';
        $segments = [];
        foreach ($path as $segment) {
            if (is_int($segment) && $segment >= 0) {
                $jsonPath .= '[' . $segment . ']';
            } elseif (is_string($segment) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $segment) === 1) {
                $jsonPath .= '.' . $segment;
            } else {
                throw new DatabaseException('JSON 路径片段无效');
            }
            $segments[] = (string) $segment;
        }
        $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        if ($this->driver === 'mysql') {
            return ['JSON_EXTRACT(' . $column . ', ?) = CAST(? AS JSON)', [$jsonPath, $encoded]];
        }
        if ($this->driver === 'pgsql') {
            $segments[] = $encoded;

            return ['jsonb_extract_path(CAST(' . $column . ' AS JSONB), ' . implode(', ', array_fill(0, count($path), '?')) . ') = CAST(? AS JSONB)', $segments];
        }
        if ($value === null || is_bool($value)) {
            $type = $value === null ? 'null' : ($value ? 'true' : 'false');

            return ['json_type(' . $column . ', ?) = ?', [$jsonPath, $type]];
        }
        if (is_int($value) || is_float($value)) {
            return ['(json_type(' . $column . ', ?) IN (\'integer\', \'real\') AND json_extract(' . $column . ', ?) = CAST(? AS NUMERIC))', [$jsonPath, $jsonPath, $value]];
        }

        return ['(json_type(' . $column . ', ?) = \'text\' AND json_extract(' . $column . ', ?) = CAST(? AS TEXT))', [$jsonPath, $jsonPath, $value]];
    }

    public function value(mixed $value): mixed
    {
        if ($value !== null && !is_scalar($value)) {
            throw new DatabaseException('查询参数只接受标量或 NULL，JSON 请显式编码');
        }
        if (is_float($value) && !is_finite($value)) {
            throw new DatabaseException('查询参数不接受非有限数值');
        }

        return is_bool($value) ? (int) $value : $value;
    }
}
