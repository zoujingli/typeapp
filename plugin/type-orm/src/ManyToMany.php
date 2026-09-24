<?php

declare(strict_types=1);

namespace Type\Orm;

use Closure;
use InvalidArgumentException;

/** 通过中间表加载及修改多对多关系，保留目标范围、租户归属和共享读取预算。 */
final class ManyToMany extends Relation
{
    private Closure $target;
    private string $table;
    private string $sourcePivot;
    private string $targetPivot;
    private string $source;
    private string $related;
    private array $fields;
    private int $batchSize;

    /** @param Closure(Connection): ModelQuery $target 接收关联查询所用连接。 */
    public function __construct(
        Closure $target,
        string $table,
        string $sourcePivot,
        string $targetPivot,
        string $source,
        string $related,
        array $fields,
        int $batchSize,
        private ?string $pivotTenant = null
    ) {
        if ($batchSize < 1 || $batchSize > 1000 || strtolower($sourcePivot) === strtolower($targetPivot)
            || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/D', $table)
            || !array_is_list($fields)) {
            throw new InvalidArgumentException('中间表或批量声明无效');
        }
        foreach (array_merge([$sourcePivot, $targetPivot, $source, $related], $fields) as $field) {
            if (!is_string($field) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $field)) {
                throw new InvalidArgumentException('关系字段无效');
            }
        }
        $normalizedFields = array_map('strtolower', $fields);
        if ($pivotTenant !== null && (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $pivotTenant)
            || in_array(strtolower($pivotTenant), array_merge($normalizedFields, [strtolower($sourcePivot), strtolower($targetPivot)]), true))) {
            throw new InvalidArgumentException('中间表租户列不能覆盖关系键或业务字段');
        }
        if (count(array_unique($normalizedFields)) !== count($fields)
            || array_intersect($normalizedFields, [strtolower($sourcePivot), strtolower($targetPivot)]) !== []) {
            throw new InvalidArgumentException('中间表业务字段不能覆盖关系键');
        }
        $this->target = $target;
        $this->table = $table;
        $this->sourcePivot = $sourcePivot;
        $this->targetPivot = $targetPivot;
        $this->source = $source;
        $this->related = $related;
        $this->fields = $fields;
        $this->batchSize = $batchSize;
    }

    /** 返回父模型关联属性名，匹配时读取存储值而非展示获取器。 */
    public function sourceKey(): string
    {
        return $this->source;
    }

    /**
     * 批量加载关系并写回父模型，沿用父查询已经选定的连接。
     *
     * @param list<Model> $models
     */
    public function load(Connection $connection, array $models, string $name): void
    {
        $this->loadRows($connection, $models, $name);
    }

    /**
     * 在共享行数预算内批量加载；超限拒绝，不把截断关系交给业务。
     *
     * @param list<Model> $models
     */
    public function loadBounded(Connection $connection, array $models, string $name, ReadBudget $budget): void
    {
        $this->loadRows($connection, $models, $name, $budget);
    }

    private function loadRows(Connection $connection, array $models, string $name, ?ReadBudget $budget = null): void
    {
        $keys = [];
        foreach ($models as $model) {
            $value = $model->rawValue($this->source);
            if ($value !== null) {
                $keys[$this->identity($value)] = $value;
            }
        }
        $pivots = [];
        $targetIds = [];
        $pairs = [];
        foreach (array_chunk(array_values($keys), $this->batchSize) as $chunk) {
            $query = $connection->table($this->table)->select(array_merge([$this->sourcePivot, $this->targetPivot], $this->fields))
                ->whereIn($this->sourcePivot, $chunk)->orderBy($this->sourcePivot)->orderBy($this->targetPivot);
            $query = $this->restrictPivot($query);
            $rows = ($budget === null ? $query : $query->limit($budget->remaining() + 1))->get();
            $budget?->consume(count($rows));
            foreach ($rows as $row) {
                $source = $this->identity($row[$this->sourcePivot]);
                $target = $this->identity($row[$this->targetPivot]);
                if (!isset($keys[$source])) {
                    throw new ModelException('relation_key_comparison', '中间表关系键与模型比较规则不一致');
                }
                $pair = json_encode([$source, $target], JSON_THROW_ON_ERROR);
                if (isset($pairs[$pair])) {
                    throw new ModelException('duplicate_pivot', '中间表缺少唯一关系约束');
                }
                $pairs[$pair] = true;
                $pivots[$source][] = $row;
                $targetIds[$target] = $row[$this->targetPivot];
            }
        }
        $targets = $this->targets($connection, array_values($targetIds), false, $budget);
        foreach ($models as $model) {
            $key = $model->rawValue($this->source);
            $linked = [];
            foreach ($key === null ? [] : ($pivots[$this->identity($key)] ?? []) as $pivot) {
                $relatedModel = $targets[$this->identity($pivot[$this->targetPivot])] ?? null;
                if ($relatedModel === null) {
                    continue;
                }
                $copy = clone $relatedModel;
                $values = [];
                foreach ($this->fields as $field) {
                    $values[$field] = $pivot[$field];
                }
                $copy->setPivot($values);
                $connection->trackModel($copy);
                $linked[] = $copy;
            }
            $model->setRelation($name, $linked);
        }
    }

    /** 返回是否新增关系；重复挂载可更新显式提供的中间表字段。 */
    public function attach(Model $parent, int|string $id, array $values = []): bool
    {
        $this->validateValues($values);
        $connection = Db::connection($parent->definition()->database(), true);
        return $this->mutate($connection, $parent, function (mixed $source) use ($connection, $id, $values): bool {
            $this->targets($connection, [$id], true);
            $query = $this->pivotQuery($connection, $source)->where($this->targetPivot, '=', $id);
            $existing = $query->get();
            if (count($existing) > 1) {
                throw new ModelException('duplicate_pivot', '中间表缺少唯一关系约束');
            }
            if ($existing !== []) {
                if ($this->identity($existing[0][$this->sourcePivot]) !== $this->identity($source)
                    || $this->identity($existing[0][$this->targetPivot]) !== $this->identity($id)) {
                    throw new ModelException('relation_key_comparison', '中间表键需要统一规范化');
                }
                if ($values !== []) {
                    $query->update($values);
                }
                return false;
            }
            $connection->table($this->table)->insert($this->pivotValues($source, $id, $values));
            return true;
        });
    }

    /** 在当前主库事务中解除允许访问的中间表关系，目标不可见或不存在时返回 false。 */
    public function detach(Model $parent, int|string $id): bool
    {
        $connection = Db::connection($parent->definition()->database(), true);
        return $this->mutate($connection, $parent, function (mixed $source) use ($connection, $id): bool {
            if ($this->targets($connection, [$id], false) === []) {
                return false;
            }
            $query = $this->pivotQuery($connection, $source)->where($this->targetPivot, '=', $id);
            $rows = $query->get();
            if (count($rows) > 1) {
                throw new ModelException('duplicate_pivot', '中间表缺少唯一关系约束');
            }
            if ($rows === []) {
                return false;
            }
            if ($this->identity($rows[0][$this->sourcePivot]) !== $this->identity($source)
                || $this->identity($rows[0][$this->targetPivot]) !== $this->identity($id)) {
                throw new ModelException('relation_key_comparison', '中间表键需要统一规范化');
            }
            return $query->delete() > 0;
        });
    }

    /** items 为 [{id: 整数或字符串, pivot: 字段映射}]，避免 PHP 数组键转换 ID。 */
    public function sync(Model $parent, array $items): array
    {
        if (!array_is_list($items)) {
            throw new ModelException('invalid_pivot_input', '同步关系需要记录列表');
        }
        $desired = [];
        foreach ($items as $item) {
            if (!is_array($item) || !array_key_exists('id', $item) || !is_array($item['pivot'] ?? [])
                || array_diff(array_keys($item), ['id', 'pivot']) !== []) {
                throw new ModelException('invalid_pivot_input', '同步记录需要 id 和可选 pivot');
            }
            $identity = $this->identity($item['id']);
            if (isset($desired[$identity])) {
                throw new ModelException('duplicate_pivot_input', '同步列表重复指定同一目标');
            }
            $values = $item['pivot'] ?? [];
            $this->validateValues($values);
            $desired[$identity] = ['id' => $item['id'], 'pivot' => $values];
        }
        $connection = Db::connection($parent->definition()->database(), true);
        return $this->mutate($connection, $parent, function (mixed $source) use ($connection, $desired): array {
            $ids = [];
            foreach ($desired as $item) {
                $ids[] = $item['id'];
            }
            $this->targets($connection, $ids, true);
            $query = $this->pivotQuery($connection, $source);
            $existing = [];
            foreach ($query->get() as $row) {
                if ($this->identity($row[$this->sourcePivot]) !== $this->identity($source)) {
                    throw new ModelException('relation_key_comparison', '中间表键需要统一规范化');
                }
                $existingIdentity = $this->identity($row[$this->targetPivot]);
                if (isset($existing[$existingIdentity])) {
                    throw new ModelException('duplicate_pivot', '中间表缺少唯一关系约束');
                }
                $existing[$existingIdentity] = $row;
            }
            $result = ['attached' => 0, 'detached' => 0, 'updated' => 0];
            $visible = $this->targets($connection, array_column($existing, $this->targetPivot), false);
            foreach ($existing as $key => $row) {
                if (!isset($desired[$key]) && isset($visible[$key])) {
                    $result['detached'] += $query->where($this->targetPivot, '=', $row[$this->targetPivot])->delete();
                }
            }
            foreach ($desired as $key => $item) {
                if (!isset($existing[$key])) {
                    $connection->table($this->table)->insert($this->pivotValues($source, $item['id'], $item['pivot']));
                    $result['attached']++;
                } elseif ($item['pivot'] !== []) {
                    $query->where($this->targetPivot, '=', $item['id'])->update($item['pivot']);
                    $result['updated']++;
                }
            }
            return $result;
        });
    }

    private function mutate(Connection $connection, Model $parent, Closure $operation): mixed
    {
        if (!$parent->isPersisted()) {
            throw new ModelException('not_persisted', '关系写入要求已持久化父模型');
        }
        $source = $parent->rawValue($this->source);
        $this->identity($source);
        $mode = $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default';
        return $connection->transaction(function (Connection $transaction) use ($parent, $source, $operation): mixed {
            $transaction->trackModel($parent);
            $definition = $parent->definition();
            $query = $transaction->table($definition->table())
                ->select(['type_relation_key' => $definition->field($this->source)->column()])
                ->where($definition->field($definition->key())->column(), '=', $parent->rawValue($definition->key()));
            if ($definition->tenantField() !== null) {
                $field = $definition->field($definition->tenantField());
                $query = $query->where($field->column(), '=', $field->encode($definition->tenantIdentity(\Type\Runtime\ExecutionScope::current())));
            }
            if ($definition->softDeleteField() !== null) {
                $query = $query->where($definition->field($definition->softDeleteField())->column(), '=', null);
            }
            if ($transaction->driverName() !== 'sqlite') {
                $query = $query->lockForUpdate();
            }
            $rows = $query->get();
            if (count($rows) !== 1) {
                throw new ModelException('not_found', '父记录不存在或主键不唯一');
            }
            if ($this->identity($rows[0]['type_relation_key']) !== $this->identity($source)) {
                throw new ModelException('relation_parent_changed', '父记录的关联键已经变化，请重新查询');
            }
            $result = $operation($source);
            $parent->forgetRelations();
            return $result;
        }, $mode);
    }

    private function targets(Connection $connection, array $ids, bool $required, ?ReadBudget $budget = null): array
    {
        $requested = [];
        foreach ($ids as $id) {
            $requested[$this->identity($id)] = $id;
        }
        $models = [];
        foreach (array_chunk(array_values($requested), $this->batchSize) as $chunk) {
            $query = ($this->target)($connection);
            if (!$query instanceof ModelQuery) {
                throw new ModelException('invalid_relation_factory', '关系工厂必须返回 ModelQuery');
            }
            $query = $query->including([$this->related])->whereIn($this->related, $chunk)->orderedForRelation();
            $rows = $budget === null ? $query->get() : $query->getBounded($budget->remaining(), $budget);
            foreach ($rows as $model) {
                $key = $this->identity($model->rawValue($this->related));
                if (!isset($requested[$key])) {
                    throw new ModelException('relation_key_comparison', '目标键与请求的规范化不一致');
                }
                if (isset($models[$key])) {
                    throw new ModelException('non_unique_relation', '目标关系键必须唯一');
                }
                $models[$key] = $model;
            }
        }
        if ($required && count($models) !== count($requested)) {
            throw new ModelException('related_not_found', '部分目标不存在或不符合关系筛选');
        }
        return $models;
    }

    private function pivotQuery(Connection $connection, mixed $source): Query
    {
        return $this->restrictPivot($connection->table($this->table)->where($this->sourcePivot, '=', $source));
    }

    private function restrictPivot(Query $query): Query
    {
        return $this->pivotTenant === null ? $query : $query->where($this->pivotTenant, '=', $this->tenantIdentity());
    }

    private function pivotValues(mixed $source, int|string $target, array $values): array
    {
        $row = [$this->sourcePivot => $source, $this->targetPivot => $target] + $values;
        if ($this->pivotTenant !== null) {
            $row[$this->pivotTenant] = $this->tenantIdentity();
        }
        return $row;
    }

    private function tenantIdentity(): string
    {
        $tenant = \Type\Runtime\ExecutionScope::current()->binding('tenant_id');
        if ($tenant === null || $tenant === '') {
            throw new ModelException('tenant_scope_required', '中间表关系需要可信租户上下文');
        }
        return $tenant;
    }

    private function validateValues(array $values): void
    {
        foreach ($values as $name => $value) {
            if (!in_array($name, $this->fields, true) || ($value !== null && !is_scalar($value)) || (is_float($value) && !is_finite($value))) {
                throw new ModelException('invalid_pivot_field', '中间表字段未声明或值不支持');
            }
        }
    }

    private function identity(mixed $value): string
    {
        if (!is_int($value) && !is_string($value)) {
            throw new ModelException('invalid_relation_key', '关系键必须是整数或字符串');
        }
        return 'key:' . (string) $value;
    }
}
