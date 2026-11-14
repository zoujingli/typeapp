<?php

declare(strict_types=1);

namespace app\center\model;

use Type\Orm\Model;

/**
 * 客户中心看到的 Broker 节点存活观察
 * @property string $node_id MQTT 节点标识
 * @property string $run_id 节点本次运行代次
 * @property int $observed_at 最近一次观察时间，UTC Unix 秒
 * @property int $expires_at 观察失效时间，UTC Unix 秒
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
final class BrokerObservation extends Model
{
    protected string $table = 'center_broker_observation';
    protected string $pk = 'node_id';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = null;

    protected array $schema = [
        'node_id' => ['type' => 'string', 'comment' => 'MQTT 节点标识'],
        'run_id' => ['type' => 'string', 'comment' => '节点本次运行代次'],
        'observed_at' => ['type' => 'integer', 'comment' => '最近一次观察时间，UTC Unix 秒'],
        'expires_at' => ['type' => 'integer', 'comment' => '观察失效时间，UTC Unix 秒'],
    ];
}
