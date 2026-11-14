<?php

declare(strict_types=1);

namespace app\platform\mqtt\model;

use Type\Orm\Model;

/**
 * 按 Client ID 占用的调试名额，同一身份重连不额外占用
 * @property string $client_id 占用名额的调试 Client ID
 * @property string $credential_id 对应调试凭据
 * @property string $actor_id 占用名额的操作者
 * @property string $node_id 承载调试连接的节点
 * @property int $occupied_at 占用开始时间，UTC Unix 秒
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
final class DebugOccupancy extends Model
{
    protected string $table = 'platform_mqtt_debug_occupancy';
    protected string $pk = 'client_id';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = null;

    protected array $schema = [
        'client_id' => ['type' => 'string', 'comment' => '占用名额的调试 Client ID'],
        'credential_id' => ['type' => 'string', 'comment' => '对应调试凭据'],
        'actor_id' => ['type' => 'string', 'comment' => '占用名额的操作者'],
        'node_id' => ['type' => 'string', 'comment' => '承载调试连接的节点'],
        'occupied_at' => ['type' => 'integer', 'comment' => '占用开始时间，UTC Unix 秒'],
    ];
}
