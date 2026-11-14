<?php

declare(strict_types=1);

namespace app\platform\mqtt\model;

use Type\Orm\Model;

/**
 * 接入授权的发布版本
 * @property string $id 记录的稳定标识
 * @property int $version 发布代次，单调递增
 * @property string $actor_id 发起本次变更的真实操作者标识
 * @property string $actor_realm 操作者身份域，platform 或 system
 * @property string|null $tenant_id 本次发布影响的 Tenant；平台级发布为空
 * @property int $tightening 是否收紧既有授权，收紧发布不能直接回滚
 * @property string $status 发布状态
 * @property string $snapshot_json 本次发布的完整配置快照
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
final class AccessRevision extends Model
{
    protected string $table = 'platform_mqtt_access_revision';
    protected string $pk = 'id';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = null;

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '记录的稳定标识'],
        'version' => ['type' => 'integer', 'comment' => '发布代次，单调递增'],
        'actor_id' => ['type' => 'string', 'comment' => '发起本次变更的真实操作者标识'],
        'actor_realm' => ['type' => 'string', 'comment' => '操作者身份域，platform 或 system'],
        'tenant_id' => ['type' => 'string', 'nullable' => true, 'required' => false, 'comment' => '本次发布影响的 Tenant；平台级发布为空'],
        'tightening' => ['type' => 'integer', 'comment' => '是否收紧既有授权，收紧发布不能直接回滚'],
        'status' => ['type' => 'string', 'comment' => '发布状态'],
        'snapshot_json' => ['type' => 'string', 'comment' => '本次发布的完整配置快照'],
        'created_at' => ['type' => 'integer', 'comment' => '创建时间，UTC Unix 秒'],
        'updated_at' => ['type' => 'integer', 'comment' => '最后更新时间，UTC Unix 秒'],
        'recovery_verified' => ['type' => 'integer', 'required' => false, 'comment' => '当前授权事实是否已完成恢复核对'],
    ];
}
