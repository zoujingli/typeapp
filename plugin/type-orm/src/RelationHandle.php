<?php

declare(strict_types=1);

namespace Type\Orm;

use Type\Runtime\ExecutionScope;

/**
 * 绑定一个父模型实例的关系写入句柄。
 *
 * 句柄只保留父模型和已声明关系，不缓存查询结果；每次写入仍由关系实现
 * 重新校验模型状态、执行作用域、租户和数据库事务。
 */
final class RelationHandle
{
    private ?string $tenantBinding;
    private ManyToMany $relation;

    /** @internal 通过 Model::relation() 创建，名称只能引用父模型声明的多对多关系。 */
    public function __construct(private Model $parent, string $name)
    {
        $relation = $parent->definition()->relation($name)->loader();
        if (!$relation instanceof ManyToMany) {
            throw new ModelException('relation_write_unsupported', '当前关系类型不支持 attach、detach 或 sync');
        }
        $this->relation = $relation;
        $this->tenantBinding = ExecutionScope::current()->binding('tenant_id');
    }

    /**
     * 在当前父模型上挂载一个目标；重复挂载仍返回 false。
     *
     * @param int|string $id 目标模型主键
     * @param array<string, scalar|null> $values 允许写入的中间表字段
     * @throws ModelException 父模型、作用域、租户、目标或中间表字段不满足写入约束。
     */
    public function attach(int|string $id, array $values = []): bool
    {
        return $this->writable()->attach($this->parent, $id, $values);
    }

    /**
     * 解除当前父模型与目标的关系；目标不可见或关系不存在返回 false。
     * @throws ModelException 父模型失效、作用域不匹配或租户上下文改变。
     */
    public function detach(int|string $id): bool
    {
        return $this->writable()->detach($this->parent, $id);
    }

    /**
     * 将当前父模型的关系同步到目标列表，并返回新增、移除和更新数量。
     *
     * @param list<array{id: int|string, pivot?: array<string, scalar|null>}> $items
     * @return array{attached: int, detached: int, updated: int}
     * @throws ModelException 模型、作用域或租户失效，或目标列表及中间表字段非法。
     */
    public function sync(array $items): array
    {
        return $this->writable()->sync($this->parent, $items);
    }

    private function writable(): ManyToMany
    {
        if (!$this->parent->isPersisted()) {
            throw new ModelException('not_persisted', '关系写入要求已持久化父模型');
        }
        if (ExecutionScope::current()->binding('tenant_id') !== $this->tenantBinding) {
            throw new ModelException('tenant_context_changed', '关系句柄的租户上下文已经改变，请重新取得句柄');
        }
        return $this->relation;
    }
}
