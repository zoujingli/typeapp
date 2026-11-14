<?php

declare(strict_types=1);

namespace app\platform\mqtt\model;

use Type\Orm\Model;

/**
 * 设备或接入应用的 MQTT 主体
 * @property string $id 主体稳定标识，与设备或接入应用标识相同
 * @property string|null $tenant_id 所属 Tenant；平台级主体为空
 * @property string $name 业务显示名称
 * @property int $enabled 当前是否允许接入
 * @property int $created_at 创建时间，UTC Unix 秒
 * @property int $updated_at 最后更新时间，UTC Unix 秒
 * @property int $recovery_verified 当前授权事实是否已完成恢复核对
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
final class AccessPrincipal extends Model
{
    protected string $table = 'platform_mqtt_access_principal';
    protected string $pk = 'id';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = null;

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '主体稳定标识，与设备或接入应用标识相同'],
        'tenant_id' => ['type' => 'string', 'nullable' => true, 'required' => false, 'comment' => '所属 Tenant；平台级主体为空'],
        'name' => ['type' => 'string', 'comment' => '业务显示名称'],
        'enabled' => ['type' => 'integer', 'comment' => '当前是否允许接入'],
        'created_at' => ['type' => 'integer', 'comment' => '创建时间，UTC Unix 秒'],
        'updated_at' => ['type' => 'integer', 'comment' => '最后更新时间，UTC Unix 秒'],
        'recovery_verified' => ['type' => 'integer', 'required' => false, 'comment' => '当前授权事实是否已完成恢复核对'],
    ];
}
