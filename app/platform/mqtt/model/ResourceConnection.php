<?php

declare(strict_types=1);

namespace app\platform\mqtt\model;

use Type\Orm\Model;

/**
 * Broker 上一条真实连接的资源观察
 * @property string $owner_id 连接资源所有者标识
 * @property string $observation_run 产生本观察的节点运行代次
 * @property string $client_id MQTT Client ID
 * @property string $client_hash Client ID 摘要，用于索引
 * @property string $session_id 会话标识
 * @property int $session_generation 会话代次，用于拒绝过期连接
 * @property string $node_id MQTT 节点标识
 * @property string $node_run_id 承载该连接的节点运行代次
 * @property int $node_generation 节点代次
 * @property string|null $resource_scope 连接所属资源范围；未绑定业务范围时为空
 * @property string|null $scope_hash 资源范围摘要；未绑定业务范围时为空
 * @property string|null $access_identity 认证时确认的接入身份；未认证时为空
 * @property int $protocol MQTT 协议级别
 * @property string $transport 传输方式
 * @property int $durable 是否为持久会话
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
final class ResourceConnection extends Model
{
    protected string $table = 'platform_mqtt_resource_connection';
    protected string $pk = 'owner_id';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = null;

    protected array $schema = [
        'owner_id' => ['type' => 'string', 'comment' => '连接资源所有者标识'],
        'observation_run' => ['type' => 'string', 'comment' => '产生本观察的节点运行代次'],
        'client_id' => ['type' => 'string', 'comment' => 'MQTT Client ID'],
        'client_hash' => ['type' => 'string', 'comment' => 'Client ID 摘要，用于索引'],
        'session_id' => ['type' => 'string', 'comment' => '会话标识'],
        'session_generation' => ['type' => 'integer', 'comment' => '会话代次，用于拒绝过期连接'],
        'node_id' => ['type' => 'string', 'comment' => 'MQTT 节点标识'],
        'node_run_id' => ['type' => 'string', 'comment' => '承载该连接的节点运行代次'],
        'node_generation' => ['type' => 'integer', 'comment' => '节点代次'],
        'resource_scope' => ['type' => 'string', 'nullable' => true, 'required' => false, 'comment' => '连接所属资源范围；未绑定业务范围时为空'],
        'scope_hash' => ['type' => 'string', 'nullable' => true, 'required' => false, 'comment' => '资源范围摘要；未绑定业务范围时为空'],
        'access_identity' => ['type' => 'string', 'nullable' => true, 'required' => false, 'comment' => '认证时确认的接入身份；未认证时为空'],
        'protocol' => ['type' => 'integer', 'comment' => 'MQTT 协议级别'],
        'transport' => ['type' => 'string', 'comment' => '传输方式'],
        'durable' => ['type' => 'integer', 'comment' => '是否为持久会话'],
        'observed_at' => ['type' => 'integer', 'comment' => '最近一次观察时间，UTC Unix 秒'],
    ];
}
