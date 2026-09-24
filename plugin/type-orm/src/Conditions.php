<?php

declare(strict_types=1);

namespace Type\Orm;

use Closure;

/** 不可变条件组；回调返回新条件，不修改既有查询。 */
final class Conditions
{
    private SqlDialect $dialect;
    private array $clauses = [];
    private array $parameters = [];
    // 1 恒真、0 恒假、-1 取决于数据，用于识别空 NOT IN 等显式无约束写入。
    private int $truth = 1;

    /** 绑定方言；需要子查询时同时绑定所属连接，条件构造本身不执行 SQL。 */
    public function __construct(SqlDialect $dialect, private ?Connection $connection = null)
    {
        $this->dialect = $dialect;
    }

    /** 返回新增绑定比较的新条件组；null 仅允许等于或不等于比较。 */
    public function where(string $column, string $operator, mixed $value, string $boolean = 'AND'): Conditions
    {
        $operator = $this->dialect->operator($operator);
        $column = $this->dialect->identifier($column);
        if ($value === null) {
            if (!in_array($operator, ['=', '<>', '!='], true)) {
                throw new DatabaseException('NULL 只允许相等或不等比较');
            }

            return $this->append($column . ($operator === '=' ? ' IS NULL' : ' IS NOT NULL'), [], $boolean);
        }

        return $this->append($column . ' ' . $operator . ' ' . $this->dialect->placeholder($value), [$this->dialect->value($value)], $boolean);
    }

    /** 将比较以 OR 加入新条件组，保留按调用顺序括号化的语义。 */
    public function orWhere(string $column, string $operator, mixed $value): Conditions
    {
        return $this->where($column, $operator, $value, 'OR');
    }

    /** 明确生成 IS NULL 或 IS NOT NULL，不把 null 作为普通占位值比较。 */
    public function whereNull(string $column, bool $not = false, string $boolean = 'AND'): Conditions
    {
        return $this->where($column, $not ? '!=' : '=', null, $boolean);
    }

    /**
     * 组合值集合或同连接子查询；空 IN 恒假、空 NOT IN 恒真。
     *
     * @param list<scalar>|Query $values 集合不接受 null，需用独立 NULL 条件。
     */
    public function whereIn(string $column, array|Query $values, bool $not = false, string $boolean = 'AND'): Conditions
    {
        $column = $this->dialect->identifier($column);
        if ($values instanceof Query) {
            $statement = $this->subquery($values);
            return $this->append($column . ($not ? ' NOT IN (' : ' IN (') . $statement[0] . ')', $statement[1], $boolean);
        }
        if ($values === []) {
            return $this->append($not ? '1 = 1' : '1 = 0', [], $boolean, $not ? 1 : 0);
        }
        $parameters = [];
        $placeholders = [];
        foreach ($values as $value) {
            if ($value === null) {
                throw new DatabaseException('IN 集合不接受 NULL，请用独立 NULL 条件明确组合');
            }
            $parameters[] = $this->dialect->value($value);
            $placeholders[] = $this->dialect->placeholder($value);
        }

        return $this->append($column . ($not ? ' NOT IN (' : ' IN (') . implode(', ', $placeholders) . ')', $parameters, $boolean);
    }

    /**
     * 组合排除集合；空集合不限制行，不能单独视为安全写入条件。
     *
     * @param list<scalar>|Query $values
     */
    public function whereNotIn(string $column, array|Query $values): Conditions
    {
        return $this->whereIn($column, $values, true);
    }

    /** @param Closure(Conditions): Conditions $group 接收独立条件组并返回组合后的条件。 */
    public function whereGroup(Closure $group, string $boolean = 'AND'): Conditions
    {
        $result = $group(new Conditions($this->dialect, $this->connection));
        if (!$result instanceof Conditions || $result->sql() === '' || $result->dialect !== $this->dialect || $result->connection !== $this->connection) {
            throw new DatabaseException('条件分组必须返回非空的不可变 Conditions');
        }

        return $this->append('(' . $result->sql() . ')', $result->parameters(), $boolean, $result->truth);
    }

    /** 生成用于 HAVING 的受支持聚合比较，不接受隐式 null。 */
    public function whereAggregate(string $function, string $column, string $operator, mixed $value): Conditions
    {
        if ($value === null) {
            throw new DatabaseException('聚合比较不接受隐式 NULL');
        }

        return $this->append($this->dialect->aggregate($function, $column) . ' ' . $this->dialect->operator($operator) . ' ' . $this->dialect->placeholder($value), [$this->dialect->value($value)], 'AND');
    }

    /**
     * 按方言组合 JSON 标量相等，区分缺失路径与 JSON null。
     *
     * @param list<string|int> $path 字段名或非负下标组成的路径。
     */
    public function whereJson(string $column, array $path, mixed $value, string $boolean = 'AND'): Conditions
    {
        $comparison = $this->dialect->jsonEquals($column, $path, $value);

        return $this->append($comparison[0], $comparison[1], $boolean);
    }

    /** 比较两个列标识符；右侧不会作为字面值绑定。 */
    public function whereColumn(string $left, string $operator, string $right, string $boolean = 'AND'): Conditions
    {
        return $this->append($this->dialect->identifier($left) . ' ' . $this->dialect->operator($operator)
            . ' ' . $this->dialect->identifier($right), [], $boolean);
    }

    /** 只组合相同连接的子查询，不执行子查询。 */
    public function whereExists(Query $query, bool $not = false, string $boolean = 'AND'): Conditions
    {
        $statement = $this->subquery($query);
        return $this->append(($not ? 'NOT EXISTS (' : 'EXISTS (') . $statement[0] . ')', $statement[1], $boolean);
    }

    private function subquery(Query $query): array
    {
        if ($this->connection === null) {
            throw new DatabaseException('子查询条件需要由连接创建的条件组');
        }
        return $query->compileFor($this->connection);
    }

    /** 识别恒真条件，用于拒绝未声明 allowAll 的全范围写入。 */
    public function unconstrained(): bool
    {
        return $this->truth === 1;
    }

    /** 取得组合后的条件 SQL，绑定值通过 parameters 单独取得。 */
    public function sql(): string
    {
        return implode(' ', $this->clauses);
    }

    /**
     * 按占位符出现顺序返回参数，不执行数据库操作。
     *
     * @return list<scalar|null>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }

    private function append(string $sql, array $parameters, string $boolean, int $truth = -1): Conditions
    {
        $boolean = strtoupper($boolean);
        if (!in_array($boolean, ['AND', 'OR'], true)) {
            throw new DatabaseException('条件连接只支持 AND 或 OR');
        }
        $next = clone $this;
        // 每次组合显式括号化，执行顺序与调用顺序一致。
        $next->clauses = [$this->clauses === [] ? $sql : '(' . $this->sql() . ') ' . $boolean . ' (' . $sql . ')'];
        $next->parameters = array_merge($this->parameters, $parameters);
        if ($this->clauses === []) {
            $next->truth = $truth;
        } elseif ($boolean === 'AND') {
            $next->truth = $this->truth === 0 || $truth === 0 ? 0 : ($this->truth === 1 && $truth === 1 ? 1 : -1);
        } else {
            $next->truth = $this->truth === 1 || $truth === 1 ? 1 : ($this->truth === 0 && $truth === 0 ? 0 : -1);
        }

        return $next;
    }
}
