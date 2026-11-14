<?php

declare(strict_types=1);

namespace app\platform\mqtt\model;

use Type\Orm\Model;

/**
 * 针对一条精确连接的管理操作及完成结果
 * @property string $id 记录的稳定标识
 * @property string $kind 连接操作种类
 * @property string $owner_id 目标连接资源所有者
 * @property string $session_id 目标会话标识
 * @property int $session_generation 目标会话代次，避免作用到新连接
 * @property string $node_id MQTT 节点标识
 * @property string $node_run_id 目标节点运行代次
 * @property string $observation_run 操作依据的观察运行代次
 * @property string $actor_id 发起本次变更的真实操作者标识
 * @property string $actor_realm 操作者身份域，platform 或 system
 * @property string|null $tenant_id 操作所属 Tenant；平台操作可为空
 * @property int $requested_at 发起时间，UTC Unix 秒
 * @property int|null $completed_at 完成时间，UTC Unix 秒；未完成为空
 * @property string $outcome 完成结果
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
final class ConnectionOperation extends Model
{
    protected string $table = 'platform_mqtt_connection_operation';
    protected string $pk = 'id';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = null;

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '记录的稳定标识'],
        'kind' => ['type' => 'string', 'comment' => '连接操作种类'],
        'owner_id' => ['type' => 'string', 'comment' => '目标连接资源所有者'],
        'session_id' => ['type' => 'string', 'comment' => '目标会话标识'],
        'session_generation' => ['type' => 'integer', 'comment' => '目标会话代次，避免作用到新连接'],
        'node_id' => ['type' => 'string', 'comment' => 'MQTT 节点标识'],
        'node_run_id' => ['type' => 'string', 'comment' => '目标节点运行代次'],
        'observation_run' => ['type' => 'string', 'comment' => '操作依据的观察运行代次'],
        'actor_id' => ['type' => 'string', 'comment' => '发起本次变更的真实操作者标识'],
        'actor_realm' => ['type' => 'string', 'comment' => '操作者身份域，platform 或 system'],
        'tenant_id' => ['type' => 'string', 'nullable' => true, 'required' => false, 'comment' => '操作所属 Tenant；平台操作可为空'],
        'requested_at' => ['type' => 'integer', 'comment' => '发起时间，UTC Unix 秒'],
        'completed_at' => ['type' => 'integer', 'nullable' => true, 'required' => false, 'comment' => '完成时间，UTC Unix 秒；未完成为空'],
        'outcome' => ['type' => 'string', 'comment' => '完成结果'],
    ];
}
