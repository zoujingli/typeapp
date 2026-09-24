<?php

declare(strict_types=1);

namespace Type\Orm;

use Closure;

/** 模型关系的显式加载协议；关系访问不触发隐式数据库查询。 */
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

    /** 返回父模型用于匹配的属性名，不使用展示获取器改变关联身份。 */
    abstract public function sourceKey(): string;

    /**
     * 沿用父查询连接批量加载并登记结果，不允许访问关系时隐式查询。
     *
     * @param list<Model> $models
     */
    abstract public function load(Connection $connection, array $models, string $name): void;

    /**
     * 自定义关系必须显式实现共享预算下的加载；默认拒绝无限制回退。
     *
     * @param list<Model> $models
     * @throws ModelException 未实现有界关系时报告 bounded_relation_unsupported。
     */
    public function loadBounded(Connection $connection, array $models, string $name, ReadBudget $budget): void
    {
        throw new ModelException('bounded_relation_unsupported', '自定义关系需要实现共享预算下的有界预加载');
    }
}
