<?php

declare(strict_types=1);

namespace Type\Orm;

use Closure;

abstract class Relation
{
    /** @param Closure(Connection): ModelQuery $target 接收关联查询所用连接。 */
    public static function belongsToMany(
        Closure $target,
        string $table,
        string $sourcePivotKey,
        string $targetPivotKey,
        string $sourceKey = 'id',
        string $targetKey = 'id',
        array $pivotFields = [],
        int $batchSize = 250,
        ?string $pivotTenant = null
    ): ManyToMany {
        return new ManyToMany($target, $table, $sourcePivotKey, $targetPivotKey, $sourceKey, $targetKey, $pivotFields, $batchSize, $pivotTenant);
    }

    /** @param Closure(Connection): ModelQuery $target 接收关联查询所用连接。 */
    public static function belongsTo(Closure $target, string $foreignKey, string $ownerKey = 'id', int $batchSize = 250): Relation
    {
        return new KeyRelation($target, $foreignKey, $ownerKey, false, $batchSize);
    }

    /** @param Closure(Connection): ModelQuery $target 接收关联查询所用连接。 */
    public static function hasOne(Closure $target, string $foreignKey, string $localKey = 'id', int $batchSize = 250): Relation
    {
        return new KeyRelation($target, $localKey, $foreignKey, false, $batchSize);
    }

    /** @param Closure(Connection): ModelQuery $target 接收关联查询所用连接。 */
    public static function hasMany(Closure $target, string $foreignKey, string $localKey = 'id', int $batchSize = 250): Relation
    {
        return new KeyRelation($target, $localKey, $foreignKey, true, $batchSize);
    }

    abstract public function sourceKey(): string;
    abstract public function load(Connection $connection, array $models, string $name): void;

    public function loadBounded(Connection $connection, array $models, string $name, ReadBudget $budget): void
    {
        throw new ModelException('bounded_relation_unsupported', '自定义关系需要实现共享预算下的有界预加载');
    }
}
