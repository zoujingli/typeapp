<?php

declare(strict_types=1);

namespace app\platform\mqtt\model;

use Type\Orm\Model;

/**
 * 节点一次运行的连接容量与过期边界
 * @property int $slot 运行占用的节点槽位，取值 0 到 31
 * @property string $node_id MQTT 节点标识
 * @property string $run_id 节点本次运行代次
 * @property int $observed_at 最近一次观察时间，UTC Unix 秒
 * @property int $expires_at 本次运行观察的失效时间，UTC Unix 秒
 * @property int $connections 本次运行已观察的连接数量
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
final class ResourceRun extends Model
{
    protected string $table = 'platform_mqtt_resource_run';
    protected string $pk = 'slot';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = null;

    protected array $schema = [
        'slot' => ['type' => 'integer', 'comment' => '运行占用的节点槽位，取值 0 到 31'],
        'node_id' => ['type' => 'string', 'comment' => 'MQTT 节点标识'],
        'run_id' => ['type' => 'string', 'comment' => '节点本次运行代次'],
        'observed_at' => ['type' => 'integer', 'comment' => '最近一次观察时间，UTC Unix 秒'],
        'expires_at' => ['type' => 'integer', 'comment' => '本次运行观察的失效时间，UTC Unix 秒'],
        'connections' => ['type' => 'integer', 'required' => false, 'comment' => '本次运行已观察的连接数量'],
    ];
}
