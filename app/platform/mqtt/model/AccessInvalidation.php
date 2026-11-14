<?php

declare(strict_types=1);

namespace app\platform\mqtt\model;

use Type\Orm\Model;

/**
 * 待 Broker 隔离的接入身份
 * @property string $id 隔离意图的稳定标识
 * @property string $client_id 需要断开的 MQTT Client ID
 * @property string $principal Broker 侧待隔离主体
 * @property string $actor 发起隔离的操作者
 * @property string $access_identity 隔离所依据的接入身份
 * @property int $requested_at 发起时间，UTC Unix 秒
 * @property int|null $completed_at 节点完成隔离的时间，UTC Unix 秒；未完成为空
 * @property string|null $node_id 执行隔离的节点；尚未分派为空
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
final class AccessInvalidation extends Model
{
    protected string $table = 'platform_mqtt_access_invalidation';
    protected string $pk = 'id';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = null;

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '隔离意图的稳定标识'],
        'client_id' => ['type' => 'string', 'comment' => '需要断开的 MQTT Client ID'],
        'principal' => ['type' => 'string', 'comment' => 'Broker 侧待隔离主体'],
        'actor' => ['type' => 'string', 'comment' => '发起隔离的操作者'],
        'access_identity' => ['type' => 'string', 'comment' => '隔离所依据的接入身份'],
        'requested_at' => ['type' => 'integer', 'comment' => '发起时间，UTC Unix 秒'],
        'completed_at' => ['type' => 'integer', 'nullable' => true, 'required' => false, 'comment' => '节点完成隔离的时间，UTC Unix 秒；未完成为空'],
        'node_id' => ['type' => 'string', 'nullable' => true, 'required' => false, 'comment' => '执行隔离的节点；尚未分派为空'],
    ];
}
