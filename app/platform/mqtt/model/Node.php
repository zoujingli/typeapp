<?php

declare(strict_types=1);

namespace app\platform\mqtt\model;

use Type\Orm\Model;

/**
 * 有界 Broker 节点的最近一次运行观察
 * @property int $slot 节点槽位，取值 0 到 31
 * @property string $node_id MQTT 节点标识
 * @property string $run_id 节点本次运行代次
 * @property int $observed_at 最近一次观察时间，UTC Unix 秒
 * @property int $stopped 节点是否已停止上报
 * @property string $listener_json 当前监听地址与端口的观察快照
 * @property string $metrics_json 连接、提交与拒绝等运行计数快照
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
final class Node extends Model
{
    protected string $table = 'platform_mqtt_node';
    protected string $pk = 'slot';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = null;

    protected array $schema = [
        'slot' => ['type' => 'integer', 'comment' => '节点槽位，取值 0 到 31'],
        'node_id' => ['type' => 'string', 'comment' => 'MQTT 节点标识'],
        'run_id' => ['type' => 'string', 'comment' => '节点本次运行代次'],
        'observed_at' => ['type' => 'integer', 'comment' => '最近一次观察时间，UTC Unix 秒'],
        'stopped' => ['type' => 'integer', 'comment' => '节点是否已停止上报'],
        'listener_json' => ['type' => 'string', 'comment' => '当前监听地址与端口的观察快照'],
        'metrics_json' => ['type' => 'string', 'comment' => '连接、提交与拒绝等运行计数快照'],
    ];
}
