<?php

declare(strict_types=1);

namespace Type\Orm;

use Closure;
use Type\Runtime\ExecutionScope;

/** @internal 生成模型的获取或创建入口，共用既有工厂及事务生命周期。 */
final class ModelCreation
{
    /**
     * 只恢复已确认的目标唯一竞争；事务 UNKNOWN、其他约束和不可见冲突保留失败。
     *
     * @param Closure(): ModelQuery $query
     * @param Closure(array): Model $create
     * @param array<string, mixed> $identity 完整唯一身份；租户字段由当前可信绑定补齐。
     * @param array<string, mixed> $values 仅在新增时使用的普通字段，不得覆盖唯一身份。
     * @throws ModelException 身份或输入非法、唯一获胜记录在当前范围不可见。
     * @throws ConstraintException 非目标唯一冲突或其他数据库约束失败。
     */
    public static function firstOrCreate(ModelDefinition $definition, Closure $query, Closure $create, array $identity, array $values): Model
    {
        if ($identity === []) {
            throw new ModelException('invalid_unique_identity', '获取或创建需要非空唯一身份');
        }
        foreach ($values as $field => $value) {
            if (!is_string($field)) {
                throw new ModelException('unknown_field', '创建字段名必须为字符串');
            }
            $mapping = $definition->field($field);
            if (!$mapping->fillable() && $field !== $definition->tenantField()) {
                throw new ModelException('field_not_fillable', '创建值不能设置受保护字段：' . $field);
            }
            $values[$field] = $mapping->normalize($value);
        }
        $normalized = [];
        foreach ($identity as $field => $value) {
            if (!is_string($field)) {
                throw new ModelException('invalid_unique_identity', '唯一身份必须使用模型字段名');
            }
            $mapping = $definition->field($field);
            $normalized[$field] = $mapping->normalize($value);
            if ($normalized[$field] === null || $normalized[$field] === '') {
                throw new ModelException('invalid_unique_identity', '唯一身份值不能为 null 或空字符串');
            }
            if (array_key_exists($field, $values)
                && $mapping->encode($mapping->normalize($values[$field])) !== $mapping->encode($normalized[$field])) {
                throw new ModelException('identity_conflict', '创建值与固定唯一身份冲突：' . $field);
            }
        }
        $tenant = $definition->tenantField();
        if ($tenant !== null) {
            $trusted = $definition->tenantIdentity(ExecutionScope::current());
            foreach ([$normalized, $values] as $input) {
                if (array_key_exists($tenant, $input) && $definition->field($tenant)->normalize($input[$tenant]) !== $trusted) {
                    throw new ModelException('tenant_scope_conflict', '唯一身份和创建值必须使用当前可信租户');
                }
            }
            $normalized[$tenant] = $trusted;
        }
        $values = array_replace($values, $normalized);
        $connection = Db::connection($definition->database(), true);
        UniqueIdentity::resolve($definition, $connection, array_keys($normalized));
        $definition->assertStorage($connection, array_keys($normalized));
        $connection->assertAtomicWriteStorage($definition->table());
        $lookup = $query()->master();
        foreach ($normalized as $field => $value) {
            $lookup = $lookup->where($field, '=', $value);
        }
        $existing = $lookup->first();
        if ($existing !== null) {
            return $existing;
        }
        $mode = $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default';
        $ownsTransaction = $connection->transactionDepth() === 0;
        return $connection->transaction(static function (Connection $connection) use ($create, $values, $lookup, $definition, $normalized, $ownsTransaction): Model {
            $connection->table($definition->table())->whereIn($definition->field($definition->key())->column(), [])->get();
            $index = UniqueIdentity::resolve($definition, $connection, array_keys($normalized));
            $definition->assertStorage($connection, array_keys($normalized));
            $connection->assertAtomicWriteStorage($definition->table());
            // SQLite 在读取和插入之间先取得写事务，避免从过时快照升级写锁。
            if ($connection->driverName() === 'sqlite') {
                $existing = $lookup->first();
                if ($existing !== null) {
                    return $existing;
                }
            }
            try {
                return $connection->transaction(static fn (Connection $transaction): Model => $create($values));
            } catch (ConstraintException $error) {
                if (!$error->matches($definition->table(), $index)) {
                    throw $error;
                }
                // 自有 MySQL 事务用当前读取出获胜者；已有业务事务仍尊重调用者的快照。
                $winner = ($ownsTransaction && $connection->driverName() === 'mysql' ? $lookup->lockForUpdate() : $lookup)->first();
                if ($winner === null) {
                    throw new ModelException('unique_conflict_not_visible', '唯一冲突已确认但获胜记录在当前范围或快照不可见，请在新事务中核对后重试');
                }
                return $winner;
            }
        }, $mode);
    }
}
