<?php

declare(strict_types=1);

namespace Type\Orm;

use Closure;

/** 编译期关系声明的运行表示；目标查询工厂显式生成，不反射模型。 */
final class RelationDefinition
{
    /** @param Closure(Connection, string): ModelQuery $target 参数为连接和内部关联别名。 */
    public function __construct(
        private string $kind,
        private Closure $target,
        private string $source,
        private string $related,
        private string $table = '',
        private string $sourcePivot = '',
        private string $targetPivot = '',
        private array $pivotFields = [],
        private ?string $pivotTenant = null
    ) {
        if (!in_array($kind, ['HasOne', 'HasMany', 'BelongsTo', 'BelongsToMany'], true)) {
            throw new ModelException('invalid_relation', '未知关系类型');
        }
    }

    /** 父模型匹配属性。 */
    public function sourceKey(): string
    {
        return $this->source;
    }

    /** 复用批量关系算法与中间表操作，不创建逐模型加载器。 */
    public function loader(?Closure $constraint = null, string $nested = ''): Relation
    {
        $target = $this->target;
        $factory = static function (Connection $connection) use ($target, $constraint, $nested): ModelQuery {
            $query = $target($connection, '');
            return $nested === '' ? ($constraint === null ? $query : $query->scope($constraint)) : $query->with($nested, $constraint);
        };
        if ($this->kind === 'BelongsToMany') {
            return new ManyToMany($factory, $this->table, $this->sourcePivot, $this->targetPivot, $this->source, $this->related, $this->pivotFields, 250, $this->pivotTenant);
        }
        return new KeyRelation($factory, $this->source, $this->related, $this->kind === 'HasMany', 250);
    }

    /** @internal 约束先应用于目标模型，关联键始终在约束之后以 AND 合入。 */
    public function correlated(Connection $connection, string $parentColumn, string $alias, ?Closure $constraint, int $depth): ModelQuery
    {
        $query = ($this->target)($connection, $alias)->atRelationDepth($depth);
        if ($constraint !== null) {
            $query = $query->scope($constraint);
        }
        if ($this->kind === 'BelongsToMany') {
            return $query->correlatePivot($this->table, $this->sourcePivot, $this->targetPivot, $this->related, $parentColumn, $alias . '_pivot', $this->pivotTenant);
        }
        return $query->correlate($this->related, $parentColumn);
    }

    /** @internal 为已加载子列表继续补加载深层关系，不重查父记录。 */
    public function target(Connection $connection): ModelQuery
    {
        return ($this->target)($connection, '');
    }
}
