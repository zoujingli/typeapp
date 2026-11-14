<?php

declare(strict_types=1);

namespace app\platform\mqtt\model;

use Type\Orm\Model;

/**
 * 真实连接上的订阅观察
 * @property string $id 连接与订阅过滤器共同决定的稳定标识
 * @property string $owner_id 所属连接资源所有者
 * @property string $topic 客户端声明的订阅过滤器
 * @property string $topic_hash 订阅过滤器摘要，用于索引
 * @property string $actual_filter 去掉共享订阅组后的实际过滤器
 * @property int $options 订阅选项位
 * @property int $identifier 订阅标识符
 * @property int $observed_at 最近一次观察时间，UTC Unix 秒
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
final class ResourceSubscription extends Model
{
    protected string $table = 'platform_mqtt_resource_subscription';
    protected string $pk = 'id';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = null;

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '连接与订阅过滤器共同决定的稳定标识'],
        'owner_id' => ['type' => 'string', 'comment' => '所属连接资源所有者'],
        'topic' => ['type' => 'string', 'comment' => '客户端声明的订阅过滤器'],
        'topic_hash' => ['type' => 'string', 'comment' => '订阅过滤器摘要，用于索引'],
        'actual_filter' => ['type' => 'string', 'comment' => '去掉共享订阅组后的实际过滤器'],
        'options' => ['type' => 'integer', 'comment' => '订阅选项位'],
        'identifier' => ['type' => 'integer', 'comment' => '订阅标识符'],
        'observed_at' => ['type' => 'integer', 'comment' => '最近一次观察时间，UTC Unix 秒'],
    ];
}
