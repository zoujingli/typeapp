<?php

declare(strict_types=1);

namespace app\platform\mqtt\model;

use Type\Orm\Model;

/**
 * MQTT 主体对 Topic 的发布与订阅能力
 * @property string $id 记录的稳定标识
 * @property string $principal_id 被授权的 MQTT 主体
 * @property string $topic 允许访问的 Topic
 * @property int $publish 是否允许向该 Topic 发布
 * @property int $subscribe 是否允许订阅该 Topic
 * @property int $max_qos 允许的最高 QoS
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
final class AccessGrant extends Model
{
    protected string $table = 'platform_mqtt_access_grant';
    protected string $pk = 'id';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = null;

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '记录的稳定标识'],
        'principal_id' => ['type' => 'string', 'comment' => '被授权的 MQTT 主体'],
        'topic' => ['type' => 'string', 'comment' => '允许访问的 Topic'],
        'publish' => ['type' => 'integer', 'comment' => '是否允许向该 Topic 发布'],
        'subscribe' => ['type' => 'integer', 'comment' => '是否允许订阅该 Topic'],
        'max_qos' => ['type' => 'integer', 'comment' => '允许的最高 QoS'],
    ];
}
