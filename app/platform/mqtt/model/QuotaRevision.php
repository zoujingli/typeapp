<?php

declare(strict_types=1);

namespace app\platform\mqtt\model;

use Type\Orm\Model;

/**
 * 可在线调整的连接、会话与消息额度版本
 * @property string $id 记录的稳定标识
 * @property int $version 发布代次，单调递增
 * @property string $actor_id 发起本次变更的真实操作者标识
 * @property string $actor_realm 操作者身份域，platform 或 system
 * @property int $lowering 是否降低既有额度
 * @property string $status 发布状态
 * @property string $snapshot_json 额度配置快照
 * @property int $created_at 创建时间，UTC Unix 秒
 * @property int $updated_at 最后更新时间，UTC Unix 秒
 * @property int $recovery_verified 当前授权事实是否已完成恢复核对
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
final class QuotaRevision extends Model
{
    protected string $table = 'platform_mqtt_quota_revision';
    protected string $pk = 'id';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = null;

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '记录的稳定标识'],
        'version' => ['type' => 'integer', 'comment' => '发布代次，单调递增'],
        'actor_id' => ['type' => 'string', 'comment' => '发起本次变更的真实操作者标识'],
        'actor_realm' => ['type' => 'string', 'comment' => '操作者身份域，platform 或 system'],
        'lowering' => ['type' => 'integer', 'comment' => '是否降低既有额度'],
        'status' => ['type' => 'string', 'comment' => '发布状态'],
        'snapshot_json' => ['type' => 'string', 'comment' => '额度配置快照'],
        'created_at' => ['type' => 'integer', 'comment' => '创建时间，UTC Unix 秒'],
        'updated_at' => ['type' => 'integer', 'comment' => '最后更新时间，UTC Unix 秒'],
        'recovery_verified' => ['type' => 'integer', 'required' => false, 'comment' => '当前授权事实是否已完成恢复核对'],
    ];
}
