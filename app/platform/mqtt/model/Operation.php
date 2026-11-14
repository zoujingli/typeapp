<?php

declare(strict_types=1);

namespace app\platform\mqtt\model;

use Type\Orm\Model;

/**
 * 平台 MQTT 管理操作的提交上下文与回执
 * @property string $operation_id 管理操作的稳定标识
 * @property string $context_json 不含秘密的操作上下文
 * @property string $context_hash 操作上下文摘要
 * @property string $stage 操作阶段
 * @property string $result 操作结果
 * @property int $version 操作并发版本
 * @property string $receipts_json 节点回执清单
 * @property int $recovery_verified 当前授权事实是否已完成恢复核对
 * @property int $created_at 创建时间，UTC Unix 秒
 * @property int $updated_at 最后更新时间，UTC Unix 秒
 * @method static \Type\Orm\ModelQuery<static> query(string $alias = '')
 * @method static \Type\Orm\ModelQuery<static> where(string|array $field, mixed $operator = null, mixed $value = null, string $boolean = 'AND')
 * @method static \Type\Orm\ModelQuery<static> order(string $field, string $direction = 'ASC')
 * @method static \Type\Orm\ModelQuery<static> limit(int $count, int $offset = 0)
 * @method static \Type\Orm\ModelQuery<static> with(string $name, ?\Closure $relation = null)
 * @method static static|null find(int|string $id)
 * @method static bool destroy(int|string $id)
 * @method static static create(array $values)
 * @method static static firstOrCreate(array $identity, array $values = [])
 * @method static \Type\Orm\Helper\QueryHelper search(array $input = [], string $alias = '')
 */
final class Operation extends Model
{
    protected string $table = 'platform_mqtt_operation';
    protected string $pk = 'operation_id';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = null;

    protected array $schema = [
        'operation_id' => ['type' => 'string', 'comment' => '管理操作的稳定标识'],
        'context_json' => ['type' => 'string', 'comment' => '不含秘密的操作上下文'],
        'context_hash' => ['type' => 'string', 'comment' => '操作上下文摘要'],
        'stage' => ['type' => 'string', 'comment' => '操作阶段'],
        'result' => ['type' => 'string', 'comment' => '操作结果'],
        'version' => ['type' => 'integer', 'comment' => '操作并发版本'],
        'receipts_json' => ['type' => 'string', 'comment' => '节点回执清单'],
        'recovery_verified' => ['type' => 'integer', 'required' => false, 'comment' => '当前授权事实是否已完成恢复核对'],
        'created_at' => ['type' => 'integer', 'comment' => '创建时间，UTC Unix 秒'],
        'updated_at' => ['type' => 'integer', 'comment' => '最后更新时间，UTC Unix 秒'],
    ];
}
