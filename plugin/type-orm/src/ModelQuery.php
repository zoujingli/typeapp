<?php

declare(strict_types=1);

namespace Type\Orm;

use Closure;
use Type\Runtime\ExecutionScope;

/** 查询条件保持不可变，水合只经过显式生成工厂。 */
final class ModelQuery
{
    private ModelDefinition $definition;
    private ?Query $query = null;
    private Closure $hydrate;
    private ?Connection $connection = null;
    private ExecutionScope $execution;
    private array $operations = [];
    private bool $primary = false;
    private ?string $tenantBinding;
    private array $selected;
    private array $relations = [];
    private bool $limited = false;
    private string $trashed = 'without';
    private ?ModelBehavior $behavior = null;
    private string $alias;
    private array $computations = [];
    private int $relationDepth = 0;
    private array $relationRequests = [];
    private array $arithmeticChecks = [];

    /** @param Closure(array): Model $hydrate 接收数据库行并返回对应模型。 */
    public function __construct(ModelDefinition $definition, Closure $hydrate, string $alias = '')
    {
        $this->execution = ExecutionScope::current();
        $this->tenantBinding = $this->execution->binding('tenant_id');
        $this->definition = $definition;
        $this->alias = $alias;
        $this->hydrate = $hydrate;
        $this->selected = $definition->names();
    }

    /** 仅本查询及其关系读取主库；不借连接，也不改变其他查询。 */
    public function master(): ModelQuery
    {
        if ($this->connection !== null && $this->connection->identity()['role'] !== 'writer') {
            throw new ModelException('relation_route_conflict', '关系已绑定只读连接，请在父查询选择 master');
        }
        $copy = clone $this;
        $copy->primary = true;
        return $copy;
    }

    /** @internal 关系必须沿用父查询已经选定的执行连接。 */
    public function onConnection(Connection $connection): ModelQuery
    {
        $this->assertExecution();
        if ($connection !== Db::connection($this->definition->database(), $connection->identity()['role'] === 'writer')) {
            throw new ModelException('relation_connection_mismatch', '关系必须使用当前逻辑数据源和作用域的执行连接');
        }
        if ($this->query !== null) {
            if ($this->connection !== $connection) {
                throw new ModelException('relation_connection_mismatch', '已构造的关系 SQL 不能更换连接');
            }
            return clone $this;
        }
        $copy = clone $this;
        $copy->connection = $connection;
        return $copy->materialize();
    }

    private function assertExecution(): void
    {
        if (ExecutionScope::current() !== $this->execution) {
            throw new ModelException('model_scope_mismatch', '模型查询不能跨执行作用域使用');
        }
        if ($this->execution->binding('tenant_id') !== $this->tenantBinding) {
            throw new ModelException('tenant_context_changed', '已有模型查询的租户上下文已经改变，请重新构造查询');
        }
        $this->definition->tenantIdentity($this->execution);
    }

    /** @param Closure(ModelQuery): ModelQuery $operation 延迟构造 SQL，选路之前不访问端点。 */
    private function defer(Closure $operation): ModelQuery
    {
        $copy = clone $this;
        $copy->operations[] = $operation;
        return $copy;
    }

    private function materialize(bool $write = false): ModelQuery
    {
        $this->assertExecution();
        $copy = clone $this;
        $copy->connection ??= Db::connection($this->definition->database(), $write || $this->primary);
        $copy->query = $copy->connection->table($this->definition->table(), $this->alias)
            ->select($this->projection($this->selected));
        $copy->operations = [];
        foreach ($this->operations as $operation) {
            $copy = $operation($copy);
        }
        return $copy;
    }

    public function where(string $field, string $operator, mixed $value, string $boolean = 'AND'): ModelQuery
    {
        if ($this->query === null) {
            $this->definition->field($field);
            return $this->defer(static fn (ModelQuery $query): ModelQuery => $query->where($field, $operator, $value, $boolean));
        }
        $copy = clone $this;
        $mapping = $this->definition->field($field);
        $copy->query = $this->query->where($this->column($field), $operator, $value === null ? null : $mapping->encode($mapping->normalize($value)), $boolean);
        return $copy;
    }

    public function whereIn(string $field, array $values, bool $not = false, string $boolean = 'AND'): ModelQuery
    {
        if ($this->query === null) {
            $this->definition->field($field);
            return $this->defer(static fn (ModelQuery $query): ModelQuery => $query->whereIn($field, $values, $not, $boolean));
        }
        $copy = clone $this;
        $mapping = $this->definition->field($field);
        $parameters = [];
        foreach ($values as $value) {
            $parameters[] = $mapping->encode($mapping->normalize($value));
        }
        $copy->query = $this->query->whereIn($this->column($field), $parameters, $not, $boolean);
        return $copy;
    }

    /** OR 同样执行模型字段验证与编码。 */
    public function orWhere(string $field, string $operator, mixed $value): ModelQuery
    {
        return $this->where($field, $operator, $value, 'OR');
    }

    /** 匹配未设置的数据库值；不将模型的未加载字段当作 null。 */
    public function whereNull(string $field, bool $not = false): ModelQuery
    {
        return $this->where($field, $not ? '!=' : '=', null);
    }

    /** 排除规范化后的字段值集合。 */
    public function whereNotIn(string $field, array $values): ModelQuery
    {
        return $this->whereIn($field, $values, true);
    }

    /** @param Closure(ModelConditions): ModelConditions $group 返回模型条件组，不接受原始列。 */
    public function whereGroup(Closure $group, string $boolean = 'AND'): ModelQuery
    {
        if ($this->query === null) {
            return $this->defer(static fn (ModelQuery $query): ModelQuery => $query->whereGroup($group, $boolean));
        }
        $definition = $this->definition;
        $alias = $this->alias;
        $copy = clone $this;
        $copy->query = $this->query->whereGroup(static function (Conditions $conditions) use ($definition, $alias, $group): Conditions {
            return (new ModelConditions($definition, $conditions, $alias))->whereGroup($group)->conditions();
        }, $boolean);
        return $copy;
    }

    /** JSON 标量查询保留类型与缺失路径语义。 */
    public function whereJson(string $field, array $path, mixed $value): ModelQuery
    {
        if ($this->definition->field($field)->typeName() !== 'json') {
            throw new ModelException('invalid_json_field', 'JSON 条件只能用于 JSON 模型字段');
        }
        if ($this->query === null) {
            return $this->defer(static fn (ModelQuery $query): ModelQuery => $query->whereJson($field, $path, $value));
        }
        $copy = clone $this;
        $copy->query = $this->query->whereJson($this->column($field), $path, $value);
        return $copy;
    }

    /** 执行时必须处于活动事务；能力不足的驱动明确拒绝。 */
    public function lockForUpdate(bool $skipLocked = false): ModelQuery
    {
        if ($this->query === null) {
            return $this->master()->defer(static fn (ModelQuery $query): ModelQuery => $query->lockForUpdate($skipLocked));
        }
        $copy = $this->master();
        $copy->query = $this->query->lockForUpdate($skipLocked);
        return $copy;
    }

    /** 只生成 SQL，不做存储目录查询或模型水合。 */
    public function toSql(): string
    {
        if ($this->query === null) {
            return $this->materialize()->toSql();
        }
        return $this->readingQuery(false)->toSql();
    }

    /** 显式预览原始绑定值，不执行目标查询。 */
    public function bindings(): array
    {
        if ($this->query === null) {
            return $this->materialize()->bindings();
        }
        return $this->readingQuery(false)->bindings();
    }

    public function select(array $fields): ModelQuery
    {
        $copy = clone $this;
        $fields[] = $this->definition->key();
        if ($this->definition->softDeleteField() !== null) {
            $fields[] = $this->definition->softDeleteField();
        }
        if ($this->definition->versionField() !== null) {
            $fields[] = $this->definition->versionField();
        }
        $copy->selected = array_values(array_unique($fields));
        if ($this->query === null) {
            $this->projection($copy->selected);
            return $copy;
        }
        $copy->query = $this->query->select($this->projection($copy->selected));
        return $copy;
    }

    public function orderBy(string $field, string $direction = 'ASC'): ModelQuery
    {
        if (!in_array(strtoupper($direction), ['ASC', 'DESC'], true)) {
            throw new DatabaseException('排序方向只支持 ASC 或 DESC');
        }
        if ($this->query === null) {
            $this->definition->field($field);
            return $this->defer(static fn (ModelQuery $query): ModelQuery => $query->orderBy($field, $direction));
        }
        $copy = clone $this;
        $copy->query = $this->query->orderBy($this->column($field), $direction);
        return $copy;
    }

    /**
     * 通过模型字段映射追加尚不存在的排序，保留最早方向、已有范围与水合工厂。
     *
     * 先验证字段映射和方向，不能因已有排序而放过非法声明；当前模型查询保持不可变。
     *
     * @throws ModelException 字段未在模型中声明。
     * @throws DatabaseException 方向或映射列标识符无效。
     */
    public function orderByIfAbsent(string $field, string $direction = 'ASC'): ModelQuery
    {
        if (!in_array(strtoupper($direction), ['ASC', 'DESC'], true)) {
            throw new DatabaseException('排序方向只支持 ASC 或 DESC');
        }
        if ($this->query === null) {
            $this->definition->field($field);
            return $this->defer(static fn (ModelQuery $query): ModelQuery => $query->orderByIfAbsent($field, $direction));
        }
        $copy = clone $this;
        $copy->query = $this->query->orderByIfAbsent($this->column($field), $direction);

        return $copy;
    }

    public function limit(int $count, int $offset = 0): ModelQuery
    {
        if ($this->query === null) {
            $copy = $this->defer(static fn (ModelQuery $query): ModelQuery => $query->limit($count, $offset));
            $copy->limited = true;
            return $copy;
        }
        $copy = clone $this;
        $copy->query = $this->query->limit($count, $offset);
        $copy->limited = true;
        return $copy;
    }

    public function get(): array
    {
        if ($this->query === null) {
            return $this->materialize()->get();
        }
        return $this->hydrateRows($this->readingQuery()->get());
    }

    /** @internal 关联与批量消费者使用显式共享预算；不无界物化一对多结果。 */
    public function getBounded(int $maxRows, ?ReadBudget $budget = null): array
    {
        if ($this->query === null) {
            return $this->materialize()->getBounded($maxRows, $budget);
        }
        if ($maxRows < 0 || $maxRows > 100000 || $this->limited) {
            throw new ModelException('invalid_read_budget', '有界模型查询不接受已有 LIMIT 或无效行数上限');
        }
        $budget ??= new ReadBudget(max(1, $maxRows));
        $limit = min($maxRows, $budget->remaining());
        $rows = $this->readingQuery()->limit($limit + 1)->get();
        if (count($rows) > $limit) {
            throw new ModelException('read_budget_exceeded', '关联结果超过模型批次预算');
        }
        return $this->hydrateRows($rows, $budget);
    }

    public function paginate(int $page = 1, int $perPage = 20, int $maxRows = 10000): Page
    {
        if ($this->query === null) {
            return $this->materialize()->paginate($page, $perPage, $maxRows);
        }
        $budget = new ReadBudget($maxRows);
        $result = $this->readingQuery()->paginate($page, $perPage, $this->definition->field($this->definition->key())->column());
        return new Page($this->hydrateRows($result->items(), $budget), $result->total(), $result->number(), $result->perPage());
    }

    /** 无总数分页，模型与预加载结果共享读取预算。 */
    public function simplePaginate(int $page = 1, int $perPage = 20, int $maxRows = 10000): SimplePage
    {
        if ($this->query === null) {
            return $this->materialize()->simplePaginate($page, $perPage, $maxRows);
        }
        $budget = new ReadBudget($maxRows);
        $result = $this->readingQuery()->simplePaginate($page, $perPage, $this->definition->field($this->definition->key())->column());
        return new SimplePage($this->hydrateRows($result->items(), $budget), $result->number(), $result->perPage(), $result->hasMore());
    }

    public function cursorPaginate(int $perPage = 100, ?string $after = null, int $maxRows = 10000): CursorPage
    {
        if ($this->query === null) {
            return $this->materialize()->cursorPaginate($perPage, $after, $maxRows);
        }
        $budget = new ReadBudget($maxRows);
        $result = $this->readingQuery()->cursorPaginate($perPage, $after, $this->definition->field($this->definition->key())->column());
        return new CursorPage($this->hydrateRows($result->items(), $budget), $result->next());
    }

    /**
     * 每批完成关系加载再交给调用者。
     * @param Closure(array): mixed $consumer 接收本批模型；可返回 void，仅 false 提前退出。
     */
    public function chunk(int $size, Closure $consumer, int $maxRows = 10000): int
    {
        if ($this->query === null) {
            return $this->materialize()->chunk($size, $consumer, $maxRows);
        }
        if ($this->connection->transactionDepth() !== 0) {
            throw new ModelException('streaming_transaction_unsupported', '模型分批导出需要事务外连接，避免事务状态无限保留已加载模型');
        }
        $cursor = null;
        $count = 0;
        do {
            $page = $this->cursorPaginate($size, $cursor, $maxRows);
            $models = $page->items();
            if ($models === []) {
                break;
            }
            $count += count($models);
            $cursor = $page->next();
            if ($consumer($models) === false) {
                break;
            }
            unset($models, $page);
        } while ($cursor !== null);
        return $count;
    }

    private function readingQuery(bool $validateStorage = true): Query
    {
        $fields = $this->selected;
        if ($this->definition->tenantField() !== null) {
            $fields[] = $this->definition->tenantField();
        }
        foreach ($this->relations as $relation) {
            $fields[] = $relation->sourceKey();
        }
        if ($validateStorage) {
            $this->definition->assertStorage($this->connection, $fields);
            foreach ($this->arithmeticChecks as $check) {
                $check[0]->assertArithmeticStorage($this->connection, $check[1]);
            }
        }
        $query = $this->visibleQuery()->select($this->projection(array_values(array_unique($fields))));
        foreach ($this->computations as $alias => $computation) {
            $query = $query->selectSub($computation['query'], '__type_computed_' . $alias);
        }
        return $query;
    }

    private function hydrateRows(array $rows, ?ReadBudget $budget = null): array
    {
        $budget?->consume(count($rows));
        $retained = $this->selected;
        foreach ($this->relations as $relation) {
            $retained[] = $relation->sourceKey();
        }
        $models = [];
        foreach ($rows as $row) {
            $computed = [];
            foreach ($this->computations as $alias => $computation) {
                $value = $row['__type_computed_' . $alias];
                $computed[$alias] = $computation['count'] ? (int) ($value ?? 0) : $value;
                unset($row['__type_computed_' . $alias]);
            }
            $model = ($this->hydrate)($row);
            if (!$model instanceof Model) {
                throw new ModelException('invalid_hydrator', '水合工厂没有返回 Model');
            }
            $this->connection->trackModel($model);
            $model->retainProjection($retained);
            foreach ($computed as $alias => $value) {
                $model->setComputed($alias, $value);
            }
            if ($this->behavior !== null) {
                $model->useBehavior($this->behavior);
            }
            $model->retrieved();
            $models[] = $model;
        }
        foreach ($this->relations as $name => $relation) {
            if ($budget === null) {
                $relation->load($this->connection, $models, $name);
            } else {
                $relation->loadBounded($this->connection, $models, $name, $budget);
            }
        }
        return $models;
    }

    /** @param Relation|Closure(ModelQuery): ModelQuery|null $relation 显式关系或已声明关系的目标约束。 */
    public function with(string $name, Relation|Closure|null $relation = null): ModelQuery
    {
        $copy = clone $this;
        if (!$relation instanceof Relation) {
            $parts = $this->relationPath($name);
            $name = $parts[0];
            $copy->relationRequests[$name][$parts[1]] = $relation;
            $requests = $copy->relationRequests[$name];
            $relation = $this->definition->relation($name)->loader(static function (ModelQuery $query) use ($requests): ModelQuery {
                foreach ($requests as $path => $constraint) {
                    if ($path !== '') {
                        $query = $query->with($path, $constraint);
                    } elseif ($constraint !== null) {
                        $query = $query->scope($constraint);
                    }
                }
                return $query;
            });
        } else {
            unset($copy->relationRequests[$name]);
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) || in_array($name, $this->definition->names(), true)) {
            throw new ModelException('invalid_relation_name', '关系名称无效或与字段冲突');
        }
        $this->definition->field($relation->sourceKey());
        $copy->relations[$name] = $relation;
        return $copy;
    }

    /** 数据库端关系存在过滤，支持嵌套路径；约束作用于路径末端。 */
    public function whereHas(string $path, ?Closure $constraint = null, bool $not = false): ModelQuery
    {
        if ($this->query === null) {
            return $this->defer(static fn (ModelQuery $query): ModelQuery => $query->whereHas($path, $constraint, $not));
        }
        $parts = $this->relationPath($path);
        $relation = $this->definition->relation($parts[0]);
        $alias = '__type_relation_' . $this->relationDepth;
        $target = $relation->correlated(
            $this->connection,
            $this->column($relation->sourceKey(), true),
            $alias,
            $parts[1] === '' ? $constraint : null,
            $this->relationDepth + 1
        );
        if ($parts[1] !== '') {
            $target = $target->whereHas($parts[1], $constraint);
        }
        $copy = clone $this;
        $copy->query = $this->query->whereExists($target->visibleQuery(), $not);
        return $copy;
    }

    /** 排除完整关系路径存在的父记录。 */
    public function whereDoesntHave(string $path, ?Closure $constraint = null): ModelQuery
    {
        return $this->whereHas($path, $constraint, true);
    }

    /** 统计目标行数，不加载子模型；默认计算值名为路径加 _count。 */
    public function withCount(string $path, ?Closure $constraint = null, ?string $alias = null): ModelQuery
    {
        return $this->withAggregate($path, 'COUNT', '*', $constraint, $alias ?? str_replace('.', '_', $path) . '_count');
    }

    /** 对路径末端数值属性求和；空集合保留数据库 SUM 的 null。 */
    public function withSum(string $path, string $field, ?Closure $constraint = null, ?string $alias = null): ModelQuery
    {
        return $this->withAggregate($path, 'SUM', $field, $constraint, $alias ?? str_replace('.', '_', $path) . '_' . $field . '_sum');
    }

    private function withAggregate(string $path, string $function, string $field, ?Closure $constraint, string $alias): ModelQuery
    {
        if ($this->query === null) {
            return $this->defer(static fn (ModelQuery $query): ModelQuery => $query->withAggregate($path, $function, $field, $constraint, $alias));
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,39}$/D', $alias) || in_array($alias, $this->definition->names(), true)
            || isset($this->computations[$alias])) {
            throw new ModelException('invalid_computed_alias', '计算值别名无效、重复或与模型字段冲突');
        }
        $copy = clone $this;
        $aggregate = $this->aggregateRelation($path, $function, $field, $constraint);
        $copy->computations[$alias] = ['query' => $aggregate[0], 'count' => $function === 'COUNT'];
        $copy->arithmeticChecks = array_merge($copy->arithmeticChecks, $aggregate[1]);
        return $copy;
    }

    private function aggregateRelation(string $path, string $function, string $field, ?Closure $constraint): array
    {
        $parts = $this->relationPath($path);
        $relation = $this->definition->relation($parts[0]);
        $alias = '__type_relation_' . $this->relationDepth;
        $target = $relation->correlated(
            $this->connection,
            $this->column($relation->sourceKey(), true),
            $alias,
            $parts[1] === '' ? $constraint : null,
            $this->relationDepth + 1
        );
        if ($target->limited) {
            throw new ModelException('relation_limit_unsupported', '关系统计约束不能包含 LIMIT');
        }
        if ($parts[1] !== '') {
            $nested = $target->aggregateRelation($parts[1], $function, $field, $constraint);
            $rows = $target->visibleQuery()->select([$target->column($target->definition->key())])->selectSub($nested[0], '__type_nested');
            return [Query::fromSub($rows, '__type_nested_rows')->aggregateQuery('SUM', '__type_nested'), $nested[1]];
        }
        if ($function === 'SUM' && !in_array($target->definition->field($field)->typeName(), ['integer', 'bigint', 'decimal'], true)) {
            throw new ModelException('invalid_sum_field', '关系求和只能使用数值属性');
        }
        if ($function === 'SUM' && $this->connection->driverName() === 'sqlite'
            && in_array($target->definition->field($field)->typeName(), ['bigint', 'decimal'], true)) {
            throw new ModelException('exact_sum_unsupported', 'SQLite 不支持精确数值文本列的数据库端求和');
        }
        return [$target->visibleQuery()->aggregateQuery($function, $field === '*' ? '*' : $target->column($field)),
            $function === 'SUM' ? [[$target->definition, $field]] : []];
    }

    /** 已有模型列表显式批量补加载；父模型不重查，所有关系共享预算。 */
    public function load(array $models, bool $missingOnly = false, int $maxRows = 10000): array
    {
        if ($this->query === null) {
            return $this->materialize()->load($models, $missingOnly, $maxRows);
        }
        $budget = new ReadBudget($maxRows);
        return $this->loadWithBudget($models, $missingOnly, $budget);
    }

    /** 只补尚未加载的关系，深层路径继续复用已加载子列表。 */
    public function loadMissing(array $models, int $maxRows = 10000): array
    {
        return $this->load($models, true, $maxRows);
    }

    private function loadWithBudget(array $models, bool $missingOnly, ReadBudget $budget): array
    {
        $budget->consume(count($models));
        foreach ($models as $model) {
            if (!$model instanceof Model || $model->definition()->table() !== $this->definition->table()
                || $model->definition()->names() !== $this->definition->names()) {
                throw new ModelException('invalid_model_list', '批量补加载只接受当前模型的有效列表');
            }
        }
        foreach ($this->relations as $name => $relation) {
            $pending = [];
            $existing = [];
            foreach ($models as $model) {
                if (!$missingOnly || !$model->relationLoaded($name)) {
                    $pending[] = $model;
                } else {
                    $value = $model->related($name);
                    foreach ($value instanceof Model ? [$value] : ($value ?? []) as $child) {
                        if (!in_array($child, $existing, true)) {
                            $existing[] = $child;
                        }
                    }
                }
            }
            if ($pending !== []) {
                $relation->loadBounded($this->connection, $pending, $name, $budget);
            }
            if ($existing !== [] && isset($this->relationRequests[$name])) {
                $nested = $this->definition->relation($name)->target($this->connection);
                $hasNested = false;
                foreach ($this->relationRequests[$name] as $path => $constraint) {
                    if ($path !== '') {
                        $nested = $nested->with($path, $constraint);
                        $hasNested = true;
                    }
                }
                if ($hasNested) {
                    $nested->loadWithBudget($existing, true, $budget);
                }
            }
        }
        return $models;
    }

    /** @internal 在执行用户约束前传递相关子查询深度，避免嵌套条件别名遮蔽。 */
    public function atRelationDepth(int $depth): ModelQuery
    {
        $copy = clone $this;
        $copy->relationDepth = $depth;
        return $copy;
    }

    /** @internal 保留目标模型范围后附加相关列比较。 */
    public function correlate(string $field, string $parentColumn): ModelQuery
    {
        if ($this->query === null) {
            return $this->defer(static fn (ModelQuery $query): ModelQuery => $query->correlate($field, $parentColumn));
        }
        $copy = clone $this;
        $copy->query = $this->query->whereColumn($this->column($field), '=', $parentColumn);
        return $copy;
    }

    /** @internal 中间表只参加关系 SQL，不投影进模型状态。 */
    public function correlatePivot(string $table, string $sourcePivot, string $targetPivot, string $targetKey, string $parentColumn, string $alias, ?string $pivotTenant = null): ModelQuery
    {
        if ($this->query === null) {
            return $this->defer(static fn (ModelQuery $query): ModelQuery => $query->correlatePivot($table, $sourcePivot, $targetPivot, $targetKey, $parentColumn, $alias, $pivotTenant));
        }
        $copy = clone $this;
        $copy->query = $this->query->join($table, $this->column($targetKey), '=', $alias . '.' . $targetPivot, $alias)
            ->whereColumn($alias . '.' . $sourcePivot, '=', $parentColumn);
        if ($pivotTenant !== null) {
            if ($this->tenantBinding === null || $this->tenantBinding === '') {
                throw new ModelException('tenant_scope_required', '中间表关系需要可信租户上下文');
            }
            $copy->query = $copy->query->where($alias . '.' . $pivotTenant, '=', $this->tenantBinding);
        }
        return $copy;
    }

    private function relationPath(string $path): array
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/D', $path) || substr_count($path, '.') + $this->relationDepth > 8) {
            throw new ModelException('invalid_relation_path', '关系路径无效或超过八层');
        }
        $parts = explode('.', $path, 2);
        return [$parts[0], $parts[1] ?? ''];
    }

    private function column(string $field, bool $qualified = false): string
    {
        $qualifier = $this->alias !== '' ? $this->alias : ($qualified ? $this->definition->table() : '');
        return ($qualifier === '' ? '' : $qualifier . '.') . $this->definition->field($field)->column();
    }

    private function projection(array $fields): array
    {
        $columns = [];
        foreach ($fields as $field) {
            $columns[$field] = $this->column($field);
        }
        return $columns;
    }

    /** @internal 预加载保留调用者的选择并补充匹配键。 */
    public function including(array $fields): ModelQuery
    {
        return $this->select(array_merge($this->selected, $fields));
    }

    /** @internal 关联结果用主键作为最后的排序条件。 */
    public function orderedForRelation(): ModelQuery
    {
        if ($this->limited) {
            throw new ModelException('relation_limit_unsupported', '批量预加载不把全局 LIMIT 伪装成每个父记录的限制');
        }
        return $this->orderBy($this->definition->key());
    }

    public function first(): ?Model
    {
        return $this->limit(1)->get()[0] ?? null;
    }

    public function find(int|string $id): ?Model
    {
        return $this->where($this->definition->key(), '=', $id)->first();
    }

    /** 不水合模型、不加载关系地检查匹配行。 */
    public function exists(): bool
    {
        if ($this->query === null) {
            return $this->materialize()->exists();
        }
        return $this->visibleQuery()->exists();
    }

    /** 缺失使用稳定错误码 not_found，不返回未持久化对象。 */
    public function firstOrFail(): Model
    {
        return $this->first() ?? throw new ModelException('not_found', '模型不存在');
    }

    /** 按映射主键查询，缺失抛出 ModelException。 */
    public function findOrFail(int|string $id): Model
    {
        return $this->where($this->definition->key(), '=', $id)->firstOrFail();
    }

    /** 只读取所需字段并按模型类型转换，空结果返回 null。 */
    public function value(string $field): mixed
    {
        if ($this->query === null) {
            return $this->materialize()->value($field);
        }
        $mapping = $this->definition->field($field);
        $this->definition->assertStorage($this->connection, [$field]);
        $row = $this->visibleQuery()->select(['__type_value' => $this->column($field)])->first();
        return $row === null ? null : $mapping->normalize($row['__type_value'], true);
    }

    /** 返回类型化字段列表或唯一键映射，不水合无关模型。 */
    public function pluck(string $field, ?string $key = null): array
    {
        if ($this->query === null) {
            return $this->materialize()->pluck($field, $key);
        }
        $mapping = $this->definition->field($field);
        $keyColumn = $key === null ? null : $this->column($key);
        $this->definition->assertStorage($this->connection, $key === null ? [$field] : [$field, $key]);
        $values = $this->visibleQuery()->pluck($this->column($field), $keyColumn);
        $result = [];
        foreach ($values as $index => $value) {
            $result[$index] = $mapping->normalize($value, true);
        }
        return $result;
    }

    /** 原子修改普通数值字段；已有模型不会自动刷新，版本字段自动递增以保持乐观锁。 */
    public function increment(string $field, int $amount = 1): int
    {
        return $this->arithmetic($field, $amount, false);
    }

    /** 原子递减遵守相同写入保护与真实影响行数语义。 */
    public function decrement(string $field, int $amount = 1): int
    {
        return $this->arithmetic($field, $amount, true);
    }

    private function arithmetic(string $field, int $amount, bool $decrement): int
    {
        if ($this->query === null) {
            return $this->materialize(true)->arithmetic($field, $amount, $decrement);
        }
        $mapping = $this->definition->field($field);
        if (!$mapping->fillable() || $field === $this->definition->key() || $field === $this->definition->versionField() || $field === $this->definition->tenantField()
            || !in_array($mapping->typeName(), ['integer', 'bigint', 'decimal'], true)) {
            throw new ModelException('invalid_increment_field', '原子增减只能修改可赋值的普通数值字段');
        }
        $this->query->assertWriteIntent();
        $this->definition->assertStorage($this->connection, [$field]);
        $this->definition->assertArithmeticStorage($this->connection, $field);
        $query = $this->visibleQuery()->select(['*']);
        $version = $this->definition->versionField();
        return $query->adjust($mapping->column(), $amount, $decrement, $version === null ? null : $this->definition->field($version)->column());
    }

    public function count(): int
    {
        if ($this->query === null) {
            return $this->materialize()->count();
        }
        return (int) $this->visibleQuery()->aggregate('COUNT');
    }

    public function withTrashed(): ModelQuery
    {
        return $this->trashedMode('all');
    }
    public function onlyTrashed(): ModelQuery
    {
        return $this->trashedMode('only');
    }
    public function withoutTrashed(): ModelQuery
    {
        return $this->trashedMode('without');
    }

    private function trashedMode(string $mode): ModelQuery
    {
        if ($this->definition->softDeleteField() === null) {
            throw new ModelException('soft_delete_unsupported', '模型没有声明软删除');
        }
        $copy = clone $this;
        $copy->trashed = $mode;
        return $copy;
    }

    public function withBehavior(ModelBehavior $behavior): ModelQuery
    {
        $copy = clone $this;
        $copy->behavior = $behavior;
        return $copy;
    }

    /** @param Closure(ModelQuery): ModelQuery $scope 接收当前查询并返回新查询。 */
    public function scope(Closure $scope): ModelQuery
    {
        $query = $scope($this);
        if (!$query instanceof ModelQuery || $query->connection !== $this->connection || $query->definition !== $this->definition
            || $query->hydrate !== $this->hydrate || $query->alias !== $this->alias
            || $query->execution !== $this->execution || $query->tenantBinding !== $this->tenantBinding) {
            throw new ModelException('invalid_scope', '查询范围必须返回 ModelQuery');
        }
        return $query;
    }

    /** @param array<string, Closure(ModelQuery, mixed): ModelQuery> $searchers 依次接收当前查询和搜索值。 */
    public function search(array $values, array $searchers): ModelQuery
    {
        $query = $this;
        foreach ($values as $name => $value) {
            if (!isset($searchers[$name]) || !$searchers[$name] instanceof Closure) {
                throw new ModelException('unknown_searcher', '没有声明该搜索器：' . $name);
            }
            $query = ($searchers[$name])($query, $value);
            if (!$query instanceof ModelQuery || $query->connection !== $this->connection || $query->definition !== $this->definition
                || $query->hydrate !== $this->hydrate || $query->alias !== $this->alias
                || $query->execution !== $this->execution || $query->tenantBinding !== $this->tenantBinding) {
                throw new ModelException('invalid_searcher', '搜索器必须在原模型、范围与作用域内继续查询');
            }
        }
        return $query;
    }

    private function visibleQuery(): Query
    {
        $this->assertExecution();
        $query = $this->query;
        $tenant = $this->definition->tenantField();
        if ($tenant !== null) {
            // Conditions 已将之前所有业务条件作为独立括号组，最后追加的 AND 不会被 OR 放宽。
            $query = $query->where($this->column($tenant), '=', $this->definition->field($tenant)->encode($this->definition->tenantIdentity($this->execution)));
        }
        $field = $this->definition->softDeleteField();
        if ($field === null || $this->trashed === 'all') {
            return $query;
        }
        return $query->where($this->column($field), $this->trashed === 'only' ? '!=' : '=', null);
    }
}
