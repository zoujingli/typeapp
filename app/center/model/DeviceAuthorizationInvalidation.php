<?php

declare(strict_types=1);

namespace app\center\model;

use Type\Orm\Model;

/**
 * 设备凭据撤权的持久意图及 Broker 回执；协议工作进程只消费已登记的隔离事实。
 *
 * @property string $device_id 设备的稳定标识，也是本记录主键。
 * @property string $id 撤权意图的稳定标识。
 * @property string $principal Broker 侧待隔离主体标识。
 * @property string $actor_id 发起撤权的真实操作者标识。
 * @property-read string $tenant_id 所属 Tenant 标识，由可信执行上下文约束。
 * @property string $actor_realm 发起撤权的身份域。
 * @property string $context_json 撤权时保存的身份与来源快照，不进入公开投影。
 * @property int $requested_at 撤权意图登记时间，UTC Unix 秒。
 * @property null|int $completed_at Broker 完成隔离的时间，UTC Unix 秒；尚未完成时为空。
 * @property null|string $node_id 完成隔离的 Broker 节点标识。
 * @method static \Type\Orm\ModelQuery<static> query(string $alias = '')
 * @method static static|null find(int|string $id)
 * @method static static create(array $values)
 */
final class DeviceAuthorizationInvalidation extends Model
{
    protected string $table = 'center_authorization_invalidation';

    protected bool $autoIncrement = false;

    protected string $pk = 'device_id';

    protected ?string $tenantKey = 'tenant_id';

    protected array $schema = [
        'device_id' => ['type' => 'string', 'comment' => '设备的稳定标识，也是本记录主键。'],
        'id' => ['type' => 'string', 'comment' => '撤权意图的稳定标识。'],
        'principal' => ['type' => 'string', 'comment' => 'Broker 侧待隔离主体标识。'],
        'actor_id' => ['type' => 'string', 'comment' => '发起撤权的真实操作者标识。'],
        'tenant_id' => ['type' => 'string', 'comment' => '所属 Tenant 标识，由可信执行上下文约束。'],
        'actor_realm' => ['type' => 'string', 'comment' => '发起撤权的身份域。'],
        'context_json' => ['type' => 'string', 'visible' => false, 'comment' => '撤权时保存的身份与来源快照，不进入公开投影。'],
        'requested_at' => ['type' => 'integer', 'comment' => '撤权意图登记时间，UTC Unix 秒。'],
        'completed_at' => ['type' => 'integer', 'nullable' => true, 'comment' => 'Broker 完成隔离的时间，UTC Unix 秒；尚未完成时为空。'],
        'node_id' => ['type' => 'string', 'nullable' => true, 'comment' => '完成隔离的 Broker 节点标识。'],
    ];
}
