<?php

declare(strict_types=1);

namespace app\center\model;

use Type\Orm\Model;

/**
 * 注册资格与设备使用关系的一次绑定事实；它只记录注册结果，不承载注册码秘密。
 *
 * @property string $ownership_id 设备使用关系标识，也是本模型的稳定主键。
 * @property-read string $tenant_id 所属 Tenant 标识，由可信执行上下文约束。
 * @property string $device_id 设备的稳定标识。
 * @property string $enrollment_id 注册资格标识。
 * @property int $registered_at 注册完成时间，UTC Unix 秒。
 * @property null|string $request_id 幂等请求标识。
 * @property null|string $request_hash 注册请求摘要。
 * @method static \Type\Orm\ModelQuery<static> query(string $alias = '')
 * @method static \Type\Orm\ModelQuery<static> where(string|array $field, mixed $operator = null, mixed $value = null, string $boolean = 'AND')
 * @method static \Type\Orm\ModelQuery<static> order(string $field, string $direction = 'ASC')
 * @method static \Type\Orm\ModelQuery<static> limit(int $count, int $offset = 0)
 * @method static \Type\Orm\ModelQuery<static> with(string $name, ?\Closure $relation = null)
 * @method static static|null find(int|string $id)
 * @method static static create(array $values)
 */
final class DeviceEnrollmentBinding extends Model
{
    protected string $table = 'center_device_enrollment_binding';

    protected bool $autoIncrement = false;

    protected string $pk = 'ownership_id';

    protected ?string $tenantKey = 'tenant_id';

    protected array $schema = [
        'ownership_id' => ['type' => 'string', 'comment' => '设备使用关系标识，也是本模型的稳定主键。'],
        'tenant_id' => ['type' => 'string', 'comment' => '所属 Tenant 标识，由可信执行上下文约束。'],
        'device_id' => ['type' => 'string', 'comment' => '设备的稳定标识。'],
        'enrollment_id' => ['type' => 'string', 'comment' => '注册资格标识。'],
        'registered_at' => ['type' => 'integer', 'comment' => '注册完成时间，UTC Unix 秒。'],
        'request_id' => ['type' => 'string', 'nullable' => true, 'comment' => '幂等请求标识。'],
        'request_hash' => ['type' => 'string', 'nullable' => true, 'visible' => false, 'comment' => '注册请求摘要。'],
    ];
}
