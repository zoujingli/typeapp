<?php

declare(strict_types=1);

namespace app\center\model;

use Type\Orm\Model;

/**
 * 设备当前接入凭据；明文秘密只在签发响应中存在，模型永不公开散列字段。
 *
 * @property string $id 凭据稳定标识。
 * @property-read string $tenant_id 所属 Tenant 标识，由可信执行上下文约束。
 * @property string $device_id 设备的稳定标识。
 * @property string $ownership_id 凭据对应的设备使用关系。
 * @property string $secret_hash 凭据验证散列，不进入公开投影。
 * @property string $status 凭据状态。
 * @property bool $recovery_verified 凭据事实是否已完成恢复核对。
 * @property int $created_at 创建时间，UTC Unix 秒。
 * @property null|int $revoked_at 吊销时间，UTC Unix 秒。
 * @method static \Type\Orm\ModelQuery<static> query(string $alias = '')
 * @method static \Type\Orm\ModelQuery<static> where(string|array $field, mixed $operator = null, mixed $value = null, string $boolean = 'AND')
 * @method static \Type\Orm\ModelQuery<static> order(string $field, string $direction = 'ASC')
 * @method static \Type\Orm\ModelQuery<static> limit(int $count, int $offset = 0)
 * @method static \Type\Orm\ModelQuery<static> with(string $name, ?\Closure $relation = null)
 * @method static static|null find(int|string $id)
 * @method static static create(array $values)
 */
final class DeviceCredential extends Model
{
    protected string $table = 'center_device_credential';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = 'tenant_id';

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '凭据稳定标识。'],
        'tenant_id' => ['type' => 'string', 'comment' => '所属 Tenant 标识，由可信执行上下文约束。'],
        'device_id' => ['type' => 'string', 'comment' => '设备的稳定标识。'],
        'ownership_id' => ['type' => 'string', 'comment' => '凭据对应的设备使用关系。'],
        'secret_hash' => ['type' => 'string', 'visible' => false, 'comment' => '凭据验证散列，不进入公开投影。'],
        'status' => ['type' => 'string', 'comment' => '凭据状态。'],
        'recovery_verified' => ['type' => 'boolean', 'comment' => '凭据事实是否已完成恢复核对。'],
        'created_at' => ['type' => 'integer', 'comment' => '创建时间，UTC Unix 秒。'],
        'revoked_at' => ['type' => 'integer', 'nullable' => true, 'comment' => '吊销时间，UTC Unix 秒。'],
    ];
}
