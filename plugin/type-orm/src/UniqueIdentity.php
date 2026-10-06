<?php

declare(strict_types=1);

namespace Type\Orm;

/** @internal 从模型属性映射到真实、完整且不可空的唯一索引。 */
final class UniqueIdentity
{
    /**
     * 返回完整匹配的真实索引，不接受声明代替数据库约束。
     * @param list<string> $fields 模型属性名，包含租户模型的可信租户字段。
     * @return array{name: string, columns: list<string>, nullable: bool, partial: bool, expression: bool}
     * @throws ModelException 字段重复、缺少租户列或没有完整非空唯一索引。
     */
    public static function resolve(ModelDefinition $definition, Connection $connection, array $fields): array
    {
        $columns = array_values($definition->columns($fields));
        sort($columns);
        if ($columns === [] || count(array_unique($columns)) !== count($columns)) {
            throw new ModelException('invalid_unique_identity', '唯一身份必须包含不重复字段');
        }
        $tenant = $definition->tenantField();
        if ($tenant !== null && !in_array($definition->field($tenant)->column(), $columns, true)) {
            throw new ModelException('unsafe_unique_identity', '租户模型的唯一身份必须包含可信租户列');
        }
        foreach ($connection->uniqueIndexes($definition->table()) as $index) {
            $actual = $index['columns'];
            sort($actual);
            if ($actual === $columns && !$index['nullable'] && !$index['partial'] && !$index['expression']) {
                return $index;
            }
        }
        throw new ModelException('unsafe_unique_identity', '数据库不存在完整、非空且普通列构成的唯一身份');
    }
}
