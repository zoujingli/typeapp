<?php

declare(strict_types=1);

namespace app\center\model;

use Type\Orm\Model;

/**
 * 客户的注册资格；Model 自动限定可信租户，秘密与请求验证值不参与普通投影。
 *
 * @property string $id 记录的稳定标识。
 * @property-read string $tenant_id 所属 Tenant 标识，由可信执行上下文约束。
 * @property string $name 业务显示名称。
 * @property int $capacity 本批注册资格允许注册的设备总数。
 * @property int $registered_count 本批注册资格已使用的设备数量。
 * @property int $register_expires_at 允许新设备注册的截止时间，UTC Unix 秒。
 * @property bool $enabled 当前是否启用。
 * @property bool $recovery_verified 当前授权事实是否已完成恢复核对。
 * @property string $secret_hash 注册码散列，原始码仅在签发或重置时交付。
 * @property string $request_hash 创建请求内容摘要，用于拒绝幂等标识冲突。
 * @property-read int $version 资料并发版本。
 * @property-read int $created_at 创建时间，UTC Unix 秒。
 * @property-read int $updated_at 最后更新时间，UTC Unix 秒。
 * @method static \Type\Orm\ModelQuery<static> query(string $alias = '')
 * @method static static|null find(int|string $id)
 * @method static static create(array $values)
 * @method static static firstOrCreate(array $identity, array $values = [])
 */
final class DeviceEnrollment extends Model
{
    protected string $table = 'center_device_enrollment';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = 'tenant_id';

    protected ?string $versionKey = 'version';

    protected ?string $createTime = 'created_at';

    protected ?string $updateTime = 'updated_at';

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '记录的稳定标识。'],
        'tenant_id' => ['type' => 'string', 'comment' => '所属 Tenant 标识，由可信执行上下文约束。'],
        'name' => ['type' => 'string', 'comment' => '业务显示名称。'],
        'capacity' => ['type' => 'integer', 'comment' => '本批注册资格允许注册的设备总数。'],
        'registered_count' => ['type' => 'integer', 'comment' => '本批注册资格已使用的设备数量。'],
        'register_expires_at' => ['type' => 'integer', 'comment' => '允许新设备注册的截止时间，UTC Unix 秒。'],
        'enabled' => ['type' => 'boolean', 'comment' => '当前是否启用。'],
        'recovery_verified' => ['type' => 'boolean', 'comment' => '当前授权事实是否已完成恢复核对。'],
        'secret_hash' => ['type' => 'string', 'visible' => false, 'comment' => '注册码散列，原始码仅在签发或重置时交付。'],
        'request_hash' => ['type' => 'string', 'visible' => false, 'comment' => '创建请求内容摘要，用于拒绝幂等标识冲突。'],
        'version' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '资料并发版本。'],
        'created_at' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '创建时间，UTC Unix 秒。'],
        'updated_at' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '最后更新时间，UTC Unix 秒。'],
    ];
}
