<?php

declare(strict_types=1);

namespace Type\Orm;

use Closure;

/** 模型条件组统一执行字段映射与编码，不能注入未声明列。 */
final class ModelConditions
{
    /** @internal 绑定模型映射、同一连接的条件与可选关联别名。 */
    public function __construct(private ModelDefinition $definition, private Conditions $conditions, private string $qualifier = '')
    {
    }

    /** 字段值先按模型类型规范化；null 仅用于显式空值比较。 */
    public function where(string $field, string $operator, mixed $value, string $boolean = 'AND'): ModelConditions
    {
        $mapping = $this->definition->field($field);
        $copy = clone $this;
        $copy->conditions = $this->conditions->where(
            $this->column($field),
            $operator,
            $value === null ? null : $mapping->encode($mapping->normalize($value)),
            $boolean
        );
        return $copy;
    }

    /** OR 与已有条件按调用顺序括号化组合。 */
    public function orWhere(string $field, string $operator, mixed $value): ModelConditions
    {
        return $this->where($field, $operator, $value, 'OR');
    }

    /** 显式匹配 null 或非 null。 */
    public function whereNull(string $field, bool $not = false, string $boolean = 'AND'): ModelConditions
    {
        return $this->where($field, $not ? '!=' : '=', null, $boolean);
    }

    /** 集合逐值编码，空集合由共同条件实现保留恒真/恒假语义。 */
    public function whereIn(string $field, array $values, bool $not = false, string $boolean = 'AND'): ModelConditions
    {
        $mapping = $this->definition->field($field);
        $parameters = [];
        foreach ($values as $value) {
            $parameters[] = $mapping->encode($mapping->normalize($value));
        }
        $copy = clone $this;
        $copy->conditions = $this->conditions->whereIn($this->column($field), $parameters, $not, $boolean);
        return $copy;
    }

    /** 排除集合内的值。 */
    public function whereNotIn(string $field, array $values): ModelConditions
    {
        return $this->whereIn($field, $values, true);
    }

    /** JSON 路径只适用于 JSON 字段，值的标量类型由数据库方言保持。 */
    public function whereJson(string $field, array $path, mixed $value, string $boolean = 'AND'): ModelConditions
    {
        if ($this->definition->field($field)->typeName() !== 'json') {
            throw new ModelException('invalid_json_field', 'JSON 条件只能用于 JSON 模型字段');
        }
        $copy = clone $this;
        $copy->conditions = $this->conditions->whereJson($this->column($field), $path, $value, $boolean);
        return $copy;
    }

    /** @param Closure(ModelConditions): ModelConditions $group 返回非空模型条件组。 */
    public function whereGroup(Closure $group, string $boolean = 'AND'): ModelConditions
    {
        $definition = $this->definition;
        $qualifier = $this->qualifier;
        $copy = clone $this;
        $copy->conditions = $this->conditions->whereGroup(static function (Conditions $conditions) use ($definition, $qualifier, $group): Conditions {
            $result = $group(new ModelConditions($definition, $conditions, $qualifier));
            if (!$result instanceof ModelConditions || $result->definition !== $definition || $result->qualifier !== $qualifier) {
                throw new ModelException('invalid_conditions', '条件组必须保持当前模型映射');
            }
            return $result->conditions;
        }, $boolean);
        return $copy;
    }

    /** @internal 交还已经映射的共同条件。 */
    public function conditions(): Conditions
    {
        return $this->conditions;
    }

    private function column(string $field): string
    {
        return ($this->qualifier === '' ? '' : $this->qualifier . '.') . $this->definition->field($field)->column();
    }
}
