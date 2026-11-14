<?php

declare(strict_types=1);

namespace app\platform\mqtt\model;

use Type\Orm\Model;

/**
 * 管理库兼容代次与危险回退的维护说明
 * @property string $id 兼容记录标识
 * @property int $runtime_epoch 当前程序接受的运行代次
 * @property int $min_runtime_epoch 允许回退到的最低运行代次
 * @property string $management_head 管理库结构头摘要
 * @property int $store_ready 管理库是否已完成安装并可提供服务
 * @property int $rollback_allowed 是否允许危险回退
 * @property string $rollback_reason 允许或拒绝回退的维护说明
 * @property int $updated_at 最后更新时间，UTC Unix 秒
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
final class Compatibility extends Model
{
    protected string $table = 'platform_mqtt_compatibility';
    protected string $pk = 'id';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = null;

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '兼容记录标识'],
        'runtime_epoch' => ['type' => 'integer', 'comment' => '当前程序接受的运行代次'],
        'min_runtime_epoch' => ['type' => 'integer', 'comment' => '允许回退到的最低运行代次'],
        'management_head' => ['type' => 'string', 'comment' => '管理库结构头摘要'],
        'store_ready' => ['type' => 'integer', 'comment' => '管理库是否已完成安装并可提供服务'],
        'rollback_allowed' => ['type' => 'integer', 'comment' => '是否允许危险回退'],
        'rollback_reason' => ['type' => 'string', 'comment' => '允许或拒绝回退的维护说明'],
        'updated_at' => ['type' => 'integer', 'comment' => '最后更新时间，UTC Unix 秒'],
    ];
}
