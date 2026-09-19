<?php

declare(strict_types=1);

namespace Type\Orm;

use JsonSerializable;
use Closure;
use DateTimeImmutable;
use DateTimeZone;

/** 持久化字段封装在状态中，生成访问器同样经过未加载与失效检查。 */
abstract class Model implements JsonSerializable
{
    private ModelDefinition $definition;
    private array $values = [];
    private array $original = [];
    private bool $persisted;
    private ModelState $state;
    private array $relations = [];
    private ?array $pivotValues = null;
    private ?ModelBehavior $behavior;
    private array $computed = [];

    protected function __construct(ModelDefinition $definition, array $values, bool $persisted = false, ?ModelBehavior $behavior = null)
    {
        $this->definition = $definition;
        $this->state = new ModelState();
        $this->persisted = $persisted;
        $this->behavior = $behavior;
        if ($persisted) {
            foreach ($values as $name => $value) {
                $this->values[$name] = $definition->field($name)->normalize($value, true);
            }
            if (!array_key_exists($definition->key(), $this->values) || $this->values[$definition->key()] === null) {
                throw new ModelException('missing_primary_key', '加载模型必须包含主键');
            }
            $version = $definition->versionField();
            if ($version !== null && (!isset($this->values[$version]) || !is_int($this->values[$version]) || $this->values[$version] < 1)) {
                throw new ModelException('missing_version', '水合版本化模型必须包含有效版本');
            }
            $this->original = $this->values;
        } else {
            $this->fill($values);
        }
    }

    public function isPersisted(): bool
    {
        $this->assertValid();
        return $this->persisted;
    }
    public function definition(): ModelDefinition
    {
        $this->assertValid();
        return $this->definition;
    }

    public function loaded(string $field): bool
    {
        $this->assertValid();
        $this->definition->field($field);
        return array_key_exists($field, $this->values);
    }

    public function get(string $field): mixed
    {
        $value = $this->rawValue($field);
        return $this->behavior === null ? $value : $this->behavior->read($field, $value);
    }

    /** @internal 持久化与关联匹配使用存储值，展示获取器不能改变关系身份。 */
    public function rawValue(string $field): mixed
    {
        if (!$this->loaded($field)) {
            throw new ModelException('field_not_loaded', '模型字段未加载：' . $field);
        }
        return $this->values[$field];
    }

    public function set(string $field, mixed $value): void
    {
        $this->assertValid();
        if ($this->persisted && $field === $this->definition->key()) {
            throw new ModelException('field_not_fillable', '持久化模型的主键不能改变');
        }
        if ($field === $this->definition->softDeleteField() || $field === $this->definition->versionField()) {
            throw new ModelException('field_not_fillable', '生命周期与版本字段不能直接修改');
        }
        $mapping = $this->definition->field($field);
        if (!$mapping->fillable()) {
            throw new ModelException('field_not_fillable', '字段不允许赋值：' . $field);
        }
        $this->values[$field] = $mapping->normalize($this->behavior === null ? $value : $this->behavior->write($field, $value));
    }

    /** 拼错属性不能创建游离于模型状态的动态字段。 */
    final public function __set(string $name, mixed $value): void
    {
        $this->assertValid();
        throw new ModelException('unknown_field', '模型属性没有声明：' . $name);
    }

    /** 缺失属性不同于已声明但未加载的字段。 */
    final public function __get(string $name): mixed
    {
        $this->assertValid();
        throw new ModelException('unknown_field', '模型属性没有声明：' . $name);
    }

    public function fill(array $values): void
    {
        $this->assertValid();
        $normalized = [];
        foreach ($values as $name => $value) {
            if (!is_string($name)) {
                throw new ModelException('unknown_field', '模型字段名必须是字符串');
            }
            $field = $this->definition->field($name);
            if (!$field->fillable() || ($this->persisted && $name === $this->definition->key())) {
                throw new ModelException('field_not_fillable', '字段不允许批量赋值：' . $name);
            }
            $normalized[$name] = $field->normalize($this->behavior === null ? $value : $this->behavior->write($name, $value));
        }
        // 整批通过后更新，错误字段不会造成部分赋值。
        foreach ($normalized as $name => $value) {
            $this->values[$name] = $value;
        }
    }

    public function dirty(): array
    {
        $this->assertValid();
        $dirty = [];
        foreach ($this->values as $name => $value) {
            if (!array_key_exists($name, $this->original) || !$this->definition->field($name)->equivalent($value, $this->original[$name])) {
                $dirty[$name] = $value;
            }
        }
        return $dirty;
    }

    public function save(Connection $connection): string
    {
        return $this->writing($connection, fn (): string => $this->saveRecord($connection));
    }

    private function saveRecord(Connection $connection): string
    {
        $this->assertValid();
        $connection->trackModel($this);
        $key = $this->definition->key();
        $query = $connection->table($this->definition->table());
        if ($this->persisted && $this->dirty() === []) {
            return 'unchanged';
        }
        $version = $this->definition->versionField();
        if (!$this->persisted && $version !== null) {
            $this->values[$version] = 1;
        }
        if (!$this->event('saving', true) || !$this->event($this->persisted ? 'updating' : 'creating', true)) {
            return 'cancelled';
        }
        if (!$this->persisted) {
            $softDelete = $this->definition->softDeleteField();
            if ($softDelete !== null && !array_key_exists($softDelete, $this->values)) {
                $this->values[$softDelete] = null;
            }
            foreach ($this->definition->names() as $name) {
                if ($name === $key && $this->definition->generatedKey()) {
                    continue;
                }
                if ($this->definition->field($name)->required() && !array_key_exists($name, $this->values)) {
                    throw new ModelException('required_field', '新增模型缺少必需字段：' . $name);
                }
            }
            $row = $this->encode($this->values);
            $this->definition->assertStorage($connection, array_keys($this->values));
            if ($row === []) {
                throw new ModelException('empty_insert', '新增模型至少需要一个显式字段');
            }
            if (!array_key_exists($key, $this->values) && $this->definition->generatedKey()) {
                if (($query->capabilities()['insert-returning'] ?? false) === true) {
                    $rows = $query->insertReturning([$row], [$this->definition->field($key)->column()]);
                    $id = $rows[0][$this->definition->field($key)->column()];
                } else {
                    $query->insert($row);
                    $id = $connection->lastInsertId();
                }
                $this->values[$key] = $this->definition->field($key)->normalize($id, true);
            } else {
                $query->insert($row);
            }
            $this->persisted = true;
            $this->original = $this->values;
            $this->event('created', false);
            $this->event('saved', false);
            return 'created';
        }
        $dirty = $this->dirty();
        if ($dirty === []) {
            return 'unchanged';
        }
        $target = $query->where($this->definition->field($key)->column(), '=', $this->rawValue($key));
        $expectedVersion = $this->version();
        $values = $this->encode($dirty);
        if ($expectedVersion !== null) {
            $target = $target->where($this->definition->field($version)->column(), '=', $expectedVersion);
            $values[$this->definition->field($version)->column()] = $this->nextVersion($expectedVersion);
        }
        $this->definition->assertStorage($connection, array_keys($dirty));
        $affected = $target->update($values);
        if ($expectedVersion !== null) {
            $this->assertVersionWrite($connection, $affected, $expectedVersion, false);
            $this->values[$version] = $expectedVersion + 1;
        } elseif ($affected === 0 && $target->first() === null) {
            throw new ModelException('not_found', '更新目标已经不存在');
        }
        $this->original = $this->values;
        $this->event('updated', false);
        $this->event('saved', false);
        return $affected === 0 ? 'unchanged' : 'updated';
    }

    public function delete(Connection $connection): bool
    {
        return $this->writing($connection, fn (): bool => $this->deleteRecord($connection, false));
    }

    public function forceDelete(Connection $connection): bool
    {
        return $this->writing($connection, fn (): bool => $this->deleteRecord($connection, true));
    }

    private function deleteRecord(Connection $connection, bool $force): bool
    {
        $this->assertValid();
        $connection->trackModel($this);
        if (!$this->persisted) {
            throw new ModelException('not_persisted', '未持久化模型不能删除');
        }
        if (!$this->event($force ? 'forceDeleting' : 'deleting', true)) {
            return false;
        }
        $key = $this->definition->key();
        $query = $connection->table($this->definition->table())->where($this->definition->field($key)->column(), '=', $this->rawValue($key));
        $version = $this->definition->versionField();
        $expectedVersion = $this->version();
        if ($expectedVersion !== null) {
            $query = $query->where($this->definition->field($version)->column(), '=', $expectedVersion);
        }
        $soft = $this->definition->softDeleteField();
        if ($soft !== null && !$force) {
            $value = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $this->definition->assertStorage($connection, [$soft]);
            $values = [$this->definition->field($soft)->column() => $this->definition->field($soft)->encode($value)];
            if ($expectedVersion !== null) {
                $values[$this->definition->field($version)->column()] = $this->nextVersion($expectedVersion);
            }
            $affected = $query->whereNull($this->definition->field($soft)->column())->update($values);
            if ($expectedVersion !== null) {
                $this->assertVersionWrite($connection, $affected, $expectedVersion, true);
            }
            if ($affected > 0) {
                $this->values[$soft] = $value;
                $this->original[$soft] = $value;
                $this->forgetRelations();
            }
            if ($affected > 0 && $expectedVersion !== null) {
                $this->values[$version] = $expectedVersion + 1;
                $this->original[$version] = $expectedVersion + 1;
            }
        } else {
            $affected = $query->delete();
            if ($expectedVersion !== null) {
                $this->assertVersionWrite($connection, $affected, $expectedVersion, false);
            }
        }
        if ($affected > 0) {
            $this->event($force ? 'forceDeleted' : 'deleted', false);
        }
        if ($soft === null || $force) {
            $this->invalidate();
        }
        return $affected > 0;
    }

    public function restore(Connection $connection): bool
    {
        return $this->writing($connection, function () use ($connection): bool {
            $this->assertValid();
            $soft = $this->definition->softDeleteField();
            if ($soft === null || !$this->persisted) {
                throw new ModelException('restore_unsupported', '模型不支持恢复');
            }
            $connection->trackModel($this);
            if (!$this->event('restoring', true)) {
                return false;
            }
            $key = $this->definition->key();
            $query = $connection->table($this->definition->table())->where($this->definition->field($key)->column(), '=', $this->rawValue($key));
            $version = $this->definition->versionField();
            $expectedVersion = $this->version();
            $values = [$this->definition->field($soft)->column() => null];
            if ($expectedVersion !== null) {
                $query = $query->where($this->definition->field($version)->column(), '=', $expectedVersion);
                $values[$this->definition->field($version)->column()] = $this->nextVersion($expectedVersion);
            }
            $affected = $query->whereNull($this->definition->field($soft)->column(), true)->update($values);
            if ($expectedVersion !== null) {
                $this->assertVersionWrite($connection, $affected, $expectedVersion, true);
            }
            if ($affected > 0) {
                $this->values[$soft] = null;
                $this->original[$soft] = null;
                $this->forgetRelations();
                if ($expectedVersion !== null) {
                    $this->values[$version] = $expectedVersion + 1;
                    $this->original[$version] = $expectedVersion + 1;
                }
                $this->event('restored', false);
            }
            return $affected > 0;
        });
    }

    public function useBehavior(ModelBehavior $behavior): void
    {
        $this->assertValid();
        $this->behavior = $behavior;
    }

    /** @internal 水合完成后通知，不触发额外读取。 */
    public function retrieved(): void
    {
        $this->event('retrieved', false);
    }

    private function event(string $name, bool $cancellable): bool
    {
        return $this->behavior === null || $this->behavior->dispatch($name, $this, $cancellable);
    }

    private function writing(Connection $connection, Closure $operation): mixed
    {
        $this->state->enterWrite();
        try {
            if ($this->behavior === null && $this->definition->versionField() === null) {
                return $operation();
            }
            $mode = $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default';
            return $connection->transaction(static fn (Connection $transaction): mixed => $operation(), $mode);
        } finally {
            $this->state->leaveWrite();
        }
    }

    private function version(): ?int
    {
        $field = $this->definition->versionField();
        return $field === null ? null : $this->original[$field];
    }

    private function nextVersion(int $version): int
    {
        if ($version >= PHP_INT_MAX) {
            throw new ModelException('version_exhausted', '版本计数已达到上限');
        }
        return $version + 1;
    }

    private function assertVersionWrite(Connection $connection, int $affected, int $expected, bool $allowNoop): void
    {
        if ($affected === 1) {
            return;
        }
        if ($affected > 1) {
            throw new ModelException('non_unique_key', '模型主键必须唯一');
        }
        $dialect = new SqlDialect($connection->driverName(), $connection->serverVersion());
        $key = $this->definition->key();
        $version = $this->definition->versionField();
        $sql = 'SELECT ' . $dialect->identifier($this->definition->field($version)->column()) . ' AS type_version FROM '
            . $dialect->identifier($this->definition->table()) . ' WHERE ' . $dialect->identifier($this->definition->field($key)->column()) . ' = ?';
        // MySQL RR 下必须读当前记录，不能用旧快照把已删除误判为版本冲突。
        if ($connection->driverName() !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        $rows = $connection->query($sql, [$this->rawValue($key)]);
        if ($rows === []) {
            throw new ModelException('not_found', '写入目标已经不存在');
        }
        if ((int) $rows[0]['type_version'] !== $expected) {
            throw new ModelException('optimistic_conflict', '版本已过期，请重新查询');
        }
        if (!$allowNoop) {
            throw new ModelException('write_not_applied', '数据库未执行预期的版本写入');
        }
    }

    public function project(array $fields, array $relations = [], array $computed = []): array
    {
        $this->assertValid();
        $values = [];
        foreach ($fields as $name) {
            if (!$this->definition->field($name)->visible()) {
                throw new ModelException('hidden_field', '字段不允许输出：' . $name);
            }
            $values[$name] = $this->definition->field($name)->output($this->get($name));
        }
        foreach ($relations as $name => $selected) {
            $related = $this->related($name);
            if ($related === null) {
                $values[$name] = null;
            } elseif ($related instanceof Model) {
                $values[$name] = $related->project($selected);
            } else {
                $values[$name] = [];
                foreach ($related as $model) {
                    $values[$name][] = $model->project($selected);
                }
            }
        }
        foreach ($computed as $alias) {
            if (!is_string($alias) || array_key_exists($alias, $values)) {
                throw new ModelException('invalid_computed_alias', '计算值投影名称无效或与输出冲突');
            }
            $values[$alias] = $this->computed($alias);
        }
        return $values;
    }

    /** 独立只读计算值，缺失不同于数据库返回 null。 */
    public function computed(string $alias): mixed
    {
        $this->assertValid();
        if (!array_key_exists($alias, $this->computed)) {
            throw new ModelException('computed_not_loaded', '计算值未加载：' . $alias);
        }
        return $this->computed[$alias];
    }

    /** @internal 水合器登记一次计算结果，不进入持久化字段或变更追踪。 */
    public function setComputed(string $alias, mixed $value): void
    {
        $this->assertValid();
        if (array_key_exists($alias, $this->computed) || in_array($alias, $this->definition->names(), true)) {
            throw new ModelException('computed_read_only', '计算值只读且不能覆盖模型字段');
        }
        $this->computed[$alias] = $value;
    }

    public function relationLoaded(string $name): bool
    {
        $this->assertValid();
        return array_key_exists($name, $this->relations);
    }

    public function forgetRelations(): void
    {
        $this->assertValid();
        $this->relations = [];
    }

    /** @internal 只由中间表加载器登记声明允许输出的字段。 */
    public function setPivot(array $values): void
    {
        $this->assertValid();
        $copy = [];
        foreach ($values as $name => $value) {
            $copy[$name] = $value;
        }
        $this->pivotValues = $copy;
    }

    public function pivot(): array
    {
        $this->assertValid();
        if ($this->pivotValues === null) {
            throw new ModelException('pivot_not_loaded', '中间表字段未加载');
        }
        return $this->pivotValues;
    }

    public function related(string $name): mixed
    {
        if (!$this->relationLoaded($name)) {
            throw new ModelException('relation_not_loaded', '关系未预加载：' . $name);
        }
        return $this->relations[$name];
    }

    /** @internal 关系加载器登记结果；读取该结果不会执行 SQL。 */
    public function setRelation(string $name, mixed $value): void
    {
        $this->assertValid();
        if (in_array($name, $this->definition->names(), true) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)) {
            throw new ModelException('invalid_relation_name', '关系名称无效');
        }
        if ($value !== null && !$value instanceof Model) {
            if (!is_array($value) || !array_is_list($value)) {
                throw new ModelException('invalid_relation_value', '关系结果必须是模型、模型列表或 null');
            }
            foreach ($value as $model) {
                if (!$model instanceof Model) {
                    throw new ModelException('invalid_relation_value', '关系列表只能包含模型');
                }
            }
        }
        $this->relations[$name] = $value;
    }

    public function toArray(): array
    {
        $this->assertValid();
        $fields = [];
        foreach (array_keys($this->values) as $name) {
            if ($this->definition->field($name)->visible()) {
                $fields[] = $name;
            }
        }
        return $this->project($fields);
    }

    public function invalidate(): void
    {
        $this->state->invalidate();
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function __serialize(): array
    {
        $this->assertValid();
        throw new ModelException('serialization_unsupported', '模型请通过显式 DTO 输出，不序列化持久化状态');
    }

    public function __unserialize(array $data): void
    {
        throw new ModelException('serialization_unsupported', '模型必须通过声明的水合工厂创建');
    }

    protected function assertValid(): void
    {
        $this->state->assertValid();
    }

    private function encode(array $values): array
    {
        $row = [];
        foreach ($values as $name => $value) {
            $field = $this->definition->field($name);
            $row[$field->column()] = $field->encode($value);
        }
        return $row;
    }
}
