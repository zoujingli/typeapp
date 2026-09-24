<?php

declare(strict_types=1);

namespace Type\Orm;

use InvalidArgumentException;

/** 模型的静态数据库映射；负责字段、生命周期、租户和实际存储一致性。 */
final class ModelDefinition
{
    private string $table;
    private string $key;
    private array $fields;
    private bool $generatedKey;
    private ?string $softDelete;
    private ?string $version;

    /**
     * 固定字段与关系映射，并检查租户、版本和软删除声明之间的约束。
     *
     * @param array<string, ModelField> $fields 模型属性名到字段策略。
     * @param array<string, RelationDefinition> $relations 模型属性名到关系策略。
     * @param class-string<Model>|string $modelClass 生成模型的稳定类身份，底层手工映射可为空。
     */
    public function __construct(string $table, string $key, array $fields, bool $generatedKey = true, ?string $softDelete = null, ?string $version = null, private array $relations = [], private string $database = 'default', private ?string $tenant = null, private string $modelClass = '')
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/D', $table) || !isset($fields[$key])
            || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $database) !== 1) {
            throw new InvalidArgumentException('模型表或主键映射无效');
        }
        $columns = [];
        $checked = [];
        foreach ($fields as $name => $field) {
            if (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) || !$field instanceof ModelField
                || in_array(strtolower($field->column()), $columns, true)) {
                throw new InvalidArgumentException('模型字段映射无效或数据库列重复');
            }
            $columns[] = strtolower($field->column());
            $checked[$name] = $field;
        }
        $this->table = $table;
        $this->key = $key;
        $this->fields = $checked;
        foreach ($checked as $name => $field) {
            if ($this->tenant === null && $field->column() === 'tenant_id') {
                $this->tenant = $name;
            }
        }
        if ($this->tenant !== null && (!isset($checked[$this->tenant]) || $checked[$this->tenant]->allowsNull()
            || !in_array($checked[$this->tenant]->typeName(), ['integer', 'string', 'bigint'], true))) {
            throw new InvalidArgumentException('租户字段必须是已映射的非空字符串或整数身份');
        }
        $this->generatedKey = $generatedKey;
        if ($softDelete !== null && (!isset($fields[$softDelete]) || $fields[$softDelete]->typeName() !== 'datetime'
            || !$fields[$softDelete]->allowsNull() || $fields[$softDelete]->fillable())) {
            throw new InvalidArgumentException('软删除字段必须是不可批量赋值的可空时间字段');
        }
        $this->softDelete = $softDelete;
        if ($version !== null && (!isset($fields[$version]) || $fields[$version]->typeName() !== 'integer'
            || $fields[$version]->allowsNull() || $fields[$version]->fillable() || $version === $key)) {
            throw new InvalidArgumentException('版本字段必须是不可赋值的非空整数');
        }
        $this->version = $version;
        foreach ($relations as $name => $relation) {
            if (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) || isset($fields[$name])
                || !$relation instanceof RelationDefinition || !isset($fields[$relation->sourceKey()])) {
                throw new InvalidArgumentException('模型关系映射无效或与字段冲突');
            }
        }
    }

    /** 返回映射的真实表名，不接受运行时任意切换表。 */
    public function table(): string
    {
        return $this->table;
    }
    /** 返回逻辑数据源名称，由 Db 在当前作用域选路。 */
    public function database(): string
    {
        return $this->database;
    }

    /** 返回租户模型属性名；null 表示该模型未声明租户范围。 */
    public function tenantField(): ?string
    {
        return $this->tenant;
    }

    /** 当前应用已经验证的租户值；关联 context 与查询输入不能代替可信绑定。 */
    public function tenantIdentity(\Type\Runtime\ExecutionScope $scope): int|string|null
    {
        if ($this->tenant === null) {
            return null;
        }
        $value = $scope->binding('tenant_id');
        if ($value === null || $value === '') {
            throw new ModelException('tenant_scope_required', '租户模型需要当前作用域的可信租户身份');
        }
        return $this->field($this->tenant)->normalize($value, true);
    }
    /** 返回模型主键属性名，与真实数据库列名可能不同。 */
    public function key(): string
    {
        return $this->key;
    }
    /** 表示插入时是否允许由数据库生成主键。 */
    public function generatedKey(): bool
    {
        return $this->generatedKey;
    }
    /** 返回受管软删除时间属性；null 表示默认执行物理删除。 */
    public function softDeleteField(): ?string
    {
        return $this->softDelete;
    }
    /** 返回受管乐观锁属性；null 表示没有模型版本比较。 */
    public function versionField(): ?string
    {
        return $this->version;
    }
    /**
     * 按声明顺序列出模型字段，不含关系与计算值。
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->fields);
    }

    /** @internal 生成映射可重复构造；逻辑数据源、模型类及完整字段契约共同确定身份。 */
    public function sameMapping(ModelDefinition $other): bool
    {
        if ($this->database !== $other->database || $this->modelClass !== $other->modelClass
            || $this->table !== $other->table || $this->key !== $other->key || $this->generatedKey !== $other->generatedKey
            || $this->tenant !== $other->tenant || $this->softDelete !== $other->softDelete || $this->version !== $other->version
            || $this->names() !== $other->names()) {
            return false;
        }
        foreach ($this->fields as $name => $field) {
            if (!$field->sameMapping($other->fields[$name])) {
                return false;
            }
        }
        return true;
    }

    /** 使用同一静态关系声明加载、过滤与统计。 */
    public function relation(string $name): RelationDefinition
    {
        if (!isset($this->relations[$name])) {
            throw new ModelException('unknown_relation', '模型关系没有声明：' . $name);
        }
        return $this->relations[$name];
    }

    /** 取得声明字段策略，未知属性以 unknown_field 拒绝。 */
    public function field(string $name): ModelField
    {
        if (!isset($this->fields[$name])) {
            throw new ModelException('unknown_field', '模型字段没有声明：' . $name);
        }
        return $this->fields[$name];
    }

    /**
     * 把模型属性投影转换为真实列映射，保留属性名作为结果别名。
     *
     * @param list<string> $fields
     * @return array<string, string>
     */
    public function columns(array $fields): array
    {
        $columns = [];
        foreach ($fields as $name) {
            $columns[$name] = $this->field($name)->column();
        }
        return $columns;
    }

    /** 校验实际列的精度与时间分辨率，避免数据库隐式转换丢失信息。 */
    public function assertStorage(Connection $connection, array $fields): void
    {
        $exact = [];
        foreach ($fields as $name) {
            $declaredField = $this->field($name);
            if (in_array($declaredField->typeName(), ['decimal', 'bigint', 'datetime'], true)) {
                $exact[strtolower($declaredField->column())] = $declaredField;
            }
        }
        if ($exact === []) {
            return;
        }
        $driver = $connection->driverName();
        $columns = $connection->columns($this->table);
        $types = [];
        foreach ($columns as $column) {
            $types[strtolower($column['name'])] = $column;
        }
        foreach ($exact as $column => $field) {
            $storage = $types[$column] ?? [];
            $type = strtolower($storage['type'] ?? '');
            $safe = false;
            $match = [];
            if ($driver === 'sqlite') {
                $safe = !str_contains($type, 'int') && (str_contains($type, 'char') || str_contains($type, 'clob') || str_contains($type, 'text'));
            } elseif ($field->typeName() === 'datetime') {
                if (in_array($type, ['text', 'tinytext', 'mediumtext', 'longtext', 'character varying', 'varchar'], true)) {
                    $safe = true;
                } elseif (preg_match('/^(?:var)?char(?:acter(?: varying)?)?\(([0-9]+)\)/', $type, $match)) {
                    $safe = (int) $match[1] >= 26;
                } elseif ($driver === 'mysql') {
                    $safe = preg_match('/^(?:datetime|timestamp)\(6\)$/D', $type) === 1;
                } elseif (preg_match('/^timestamp(?:\(([0-9]+)\))? (?:with|without) time zone$/D', $type, $match)) {
                    $safe = !isset($match[1]) || $match[1] === '' || (int) $match[1] >= 6;
                }
            } elseif (in_array($type, ['text', 'tinytext', 'mediumtext', 'longtext', 'numeric', 'character varying', 'varchar'], true)) {
                $safe = true;
            } elseif (preg_match('/^(?:var)?char(?:acter(?: varying)?)?\(([0-9]+)\)/', $type, $match)) {
                $safe = (int) $match[1] >= max(1, $field->precision() - $field->scale()) + $field->scale() + ($field->scale() > 0 ? 1 : 0) + 1;
            } elseif (preg_match('/^(?:numeric|decimal)\(([0-9]+),([0-9]+)\)/', $type, $match)) {
                $safe = (int) $match[2] >= $field->scale() && (int) $match[1] - (int) $match[2] >= $field->precision() - $field->scale();
            } else {
                $safeDigits = ['tinyint' => 2, 'smallint' => 4, 'mediumint' => 6, 'int' => 9, 'integer' => 9, 'bigint' => 18];
                preg_match('/^[a-z]+/', $type, $match);
                $base = $match[0] ?? '';
                $safe = isset($safeDigits[$base]) && $field->scale() === 0 && $field->precision() <= $safeDigits[$base];
            }
            if (!$safe) {
                throw new ModelException('unsafe_exact_storage', '数据库列不足以精确表示模型字段，SQLite 请使用 TEXT：' . $column);
            }
        }
    }

    /** 精确数值的数据库运算要求真实数值列，文本仅用于无损读写。 */
    public function assertArithmeticStorage(Connection $connection, string $name): void
    {
        $field = $this->field($name);
        if (!in_array($field->typeName(), ['bigint', 'decimal'], true)) {
            return;
        }
        $this->assertStorage($connection, [$name]);
        foreach ($connection->columns($this->table) as $column) {
            if (strtolower($column['name']) !== strtolower($field->column())) {
                continue;
            }
            if ($connection->driverName() !== 'sqlite'
                && preg_match('/^(?:numeric|decimal|tinyint|smallint|mediumint|int|integer|bigint)(?:\b|\()/i', $column['type'])) {
                return;
            }
        }
        throw new ModelException('exact_arithmetic_unsupported', '精确数值文本列不支持数据库端算术，请使用真实数值列');
    }
}
