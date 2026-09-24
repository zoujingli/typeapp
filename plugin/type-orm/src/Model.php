<?php

declare(strict_types=1);

namespace Type\Orm;

use JsonSerializable;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Type\Runtime\ExecutionScope;

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
    private ExecutionScope $execution;
    private int|string|null $tenantIdentity;

    protected function __construct(ModelDefinition $definition, array $values, bool $persisted = false, ?ModelBehavior $behavior = null)
    {
        $this->execution = ExecutionScope::current();
        $this->definition = $definition;
        $this->tenantIdentity = $definition->tenantIdentity($this->execution);
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
            $tenant = $definition->tenantField();
            if ($tenant !== null && (!array_key_exists($tenant, $this->values) || $this->values[$tenant] !== $this->tenantIdentity)) {
                throw new ModelException('tenant_scope_conflict', '水合模型缺少租户归属或与当前范围不符');
            }
        } else {
            if ($definition->tenantField() !== null) {
                $this->values[$definition->tenantField()] = $this->tenantIdentity;
            }
            $this->fill($values);
        }
    }

    /** 检查当前有效模型是否已持久化，不重新访问数据库。 */
    public function isPersisted(): bool
    {
        $this->assertValid();
        return $this->persisted;
    }
    /** 取得本模型的静态映射；失效或跨作用域模型不可继续读取。 */
    public function definition(): ModelDefinition
    {
        $this->assertValid();
        return $this->definition;
    }

    /** 检查声明字段是否已加载；已加载的 null 仍返回 true，未知字段抛错。 */
    public function loaded(string $field): bool
    {
        $this->assertValid();
        $this->definition->field($field);
        return array_key_exists($field, $this->values);
    }

    /** 读取已加载字段并应用显式获取器，未加载字段不自动查询数据库。 */
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

    /** 按赋值白名单规范化单字段，不能改变已持久化主键、租户或受管生命周期字段。 */
    public function set(string $field, mixed $value): void
    {
        $this->assertValid();
        if ($field === $this->definition->tenantField()) {
            $this->assertTenantValue($value);
            return;
        }
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

    /**
     * 整批校验通过才更新内存字段，非法字段不会留下部分赋值。
     *
     * @param array<string, mixed> $values 使用模型属性名，不能传任意数据库列。
     */
    public function fill(array $values): void
    {
        $this->assertValid();
        $normalized = [];
        foreach ($values as $name => $value) {
            if (!is_string($name)) {
                throw new ModelException('unknown_field', '模型字段名必须是字符串');
            }
            $field = $this->definition->field($name);
            if ($name === $this->definition->tenantField()) {
                $this->assertTenantValue($value);
                continue;
            }
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

    /**
     * 按字段编码语义比较持久化快照，只返回真实变更。
     *
     * @return array<string, mixed>
     */
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

    /**
     * 保存当前作用域模型的变更，复用字段、租户、版本和行为约束。
     *
     * @return string created、updated、unchanged 或前置行为取消时的 cancelled。
     * @throws ModelException 状态、字段、必填、存储精度或乐观锁约束不满足。
     */
    public function save(): string
    {
        $connection = $this->connection();
        return $this->writing($connection, fn (): string => $this->saveRecord($connection));
    }

    /**
     * 只推进模型的乐观锁版本，用于撤销会话、重算授权等没有业务字段变化的动作。
     * 版本仍通过当前模型主键和已加载版本条件更新，成功后模型状态与 save() 一致。
     */
    public function touch(): string
    {
        $connection = $this->connection();
        return $this->writing($connection, function () use ($connection): string {
            $this->assertValid();
            if (!$this->persisted) {
                throw new ModelException('not_persisted', '未持久化模型不能触达版本');
            }
            $version = $this->definition->versionField();
            if ($version === null) {
                return 'unchanged';
            }
            $connection->trackModel($this);
            if (!$this->event('saving', true) || !$this->event('updating', true)) {
                return 'cancelled';
            }
            $expected = $this->version();
            $next = $this->nextVersion($expected);
            $key = $this->definition->key();
            $query = $this->recordQuery($connection)
                ->where($this->definition->field($key)->column(), '=', $this->rawValue($key))
                ->where($this->definition->field($version)->column(), '=', $expected);
            $this->definition->assertStorage($connection, [$version]);
            $affected = $query->update([$this->definition->field($version)->column() => $next]);
            $this->assertVersionWrite($connection, $affected, $expected, false);
            $this->values[$version] = $next;
            $this->original[$version] = $next;
            $this->event('updated', false);
            $this->event('saved', false);
            return 'updated';
        });
    }

    private function saveRecord(Connection $connection): string
    {
        $this->assertValid();
        $connection->trackModel($this);
        $key = $this->definition->key();
        $query = $this->persisted ? $this->recordQuery($connection) : $connection->table($this->definition->table());
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

    /** 按映射执行软删除或物理删除，保留版本与租户约束；前置事件可取消。 */
    public function delete(): bool
    {
        $connection = $this->connection();
        return $this->writing($connection, fn (): bool => $this->deleteRecord($connection, false));
    }

    /** 显式物理删除并使对象失效；不会绕过租户和乐观锁边界。 */
    public function forceDelete(): bool
    {
        $connection = $this->connection();
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
        $query = $this->recordQuery($connection)->where($this->definition->field($key)->column(), '=', $this->rawValue($key));
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

    /** 恢复支持软删除的已持久化模型，成功后清除旧关系快照。 */
    public function restore(): bool
    {
        $connection = $this->connection();
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
            $query = $this->recordQuery($connection)->where($this->definition->field($key)->column(), '=', $this->rawValue($key));
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

    /** 为当前有效模型设置显式行为；观察器生命周期由应用负责。 */
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

    private function connection(): Connection
    {
        if (ExecutionScope::current() !== $this->execution) {
            throw new ModelException('model_scope_mismatch', '模型不能跨执行作用域持久化，请在当前作用域重新查询');
        }
        $this->assertValid();
        return Db::connection($this->definition->database(), true);
    }

    private function assertTenantValue(mixed $value): void
    {
        if ($this->definition->field($this->definition->tenantField())->normalize($value) !== $this->tenantIdentity) {
            throw new ModelException('tenant_scope_conflict', '普通模型赋值不能改变租户归属');
        }
    }

    private function recordQuery(Connection $connection): Query
    {
        $this->assertValid();
        $query = $connection->table($this->definition->table());
        $field = $this->definition->tenantField();
        return $field === null ? $query : $query->where($this->definition->field($field)->column(), '=', $this->definition->field($field)->encode($this->tenantIdentity));
    }

    /** @internal 水合保留原始租户身份，未显式选择的租户字段不冒充已加载业务字段。 */
    public function retainProjection(array $fields): void
    {
        $field = $this->definition->tenantField();
        if ($field !== null && !in_array($field, $fields, true)) {
            unset($this->values[$field]);
        }
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
        $key = $this->definition->key();
        $version = $this->definition->versionField();
        $query = $this->recordQuery($connection)->select(['type_version' => $this->definition->field($version)->column()])
            ->where($this->definition->field($key)->column(), '=', $this->rawValue($key));
        // MySQL RR 下必须读当前记录，不能用旧快照把已删除误判为版本冲突。
        if ($connection->driverName() !== 'sqlite') {
            $query = $query->lockForUpdate();
        }
        $rows = $query->get();
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

    /**
     * 只输出显式选择且允许可见的字段、已加载关系与计算值。
     *
     * @param list<string> $fields
     * @param array<string, list<string>> $relations 关系名到目标字段列表。
     * @param list<string> $computed 只读计算结果别名。
     * @return array<string, mixed>
     */
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

    /** 检查关系结果是否已登记，已加载的 null 或空列表均返回 true。 */
    public function relationLoaded(string $name): bool
    {
        $this->assertValid();
        return array_key_exists($name, $this->relations);
    }

    /** 清空本模型的关系快照；下次读取须重新显式加载。 */
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

    /**
     * 读取预加载时允许输出的中间表字段；未加载抛 pivot_not_loaded。
     *
     * @return array<string, mixed>
     */
    public function pivot(): array
    {
        $this->assertValid();
        if ($this->pivotValues === null) {
            throw new ModelException('pivot_not_loaded', '中间表字段未加载');
        }
        return $this->pivotValues;
    }

    /**
     * 读取已加载关系，不触发 SQL；缺失抛 relation_not_loaded。
     *
     * @return Model|list<Model>|null
     */
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

    /**
     * 输出已加载且可见的字段；关系和计算值只有 project 显式选择才输出。
     *
     * @return array<string, mixed>
     */
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

    /** @internal 使共享模型状态失效，回滚、删除等路径由 ORM 调用。 */
    public function invalidate(): void
    {
        $this->state->invalidate();
    }

    /** JSON 序列化沿用安全字段投影，不泄露持久化状态或自动展开关系。 */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * 拒绝序列化持久化状态；跨作用域数据传输应使用显式 DTO。
     *
     * @throws ModelException 模型状态不可用，或以 serialization_unsupported 拒绝序列化。
     */
    public function __serialize(): array
    {
        $this->assertValid();
        throw new ModelException('serialization_unsupported', '模型请通过显式 DTO 输出，不序列化持久化状态');
    }

    /**
     * 拒绝从载荷重建持久化模型；必须使用声明的水合工厂。
     *
     * @param array<string, mixed> $data
     * @throws ModelException 始终拒绝，以 serialization_unsupported 报告。
     */
    public function __unserialize(array $data): void
    {
        throw new ModelException('serialization_unsupported', '模型必须通过声明的水合工厂创建');
    }

    protected function assertValid(): void
    {
        $this->state->assertValid();
        if (ExecutionScope::current() !== $this->execution) {
            throw new ModelException('model_scope_mismatch', '模型属于另一执行作用域，请在当前作用域重新查询');
        }
        if ($this->definition->tenantIdentity($this->execution) !== $this->tenantIdentity) {
            throw new ModelException('tenant_context_changed', '已有模型的租户上下文已经改变');
        }
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
