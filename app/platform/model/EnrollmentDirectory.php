<?php

declare(strict_types=1);

namespace app\platform\model;

use Type\Orm\Model;

/**
 * Platform 跨 Tenant 只读注册资格目录。
 *
 * 该模型只用于平台已经通过 RBAC 的目录、详情和幂等冲突检查；它故意不声明
 * tenantKey，因此不会在没有客户执行范围时误把平台目录截成某一个 Tenant。
 * 任何写入仍必须进入带可信 Tenant 的 DeviceEnrollment 模型和注册资格服务。
 *
 * @property string $id 注册资格的稳定标识。
 * @property string $tenant_id 所属 Tenant 标识；平台目录只读展示，不能由请求赋值。
 * @property string $name 业务显示名称。
 * @property int $capacity 本批注册资格允许注册的设备总数。
 * @property int $registered_count 本批注册资格已使用的设备数量。
 * @property int $register_expires_at 允许新设备注册的截止时间，UTC Unix 秒。
 * @property bool $enabled 当前是否启用。
 * @property bool $recovery_verified 当前授权事实是否已完成恢复核对。
 * @property string $secret_hash 注册码散列，不参与平台公开投影。
 * @property string $request_hash 创建请求内容摘要，用于幂等标识冲突检查。
 * @property-read int $version 资料并发版本。
 * @property-read int $created_at 创建时间，UTC Unix 秒。
 * @property-read int $updated_at 最后更新时间，UTC Unix 秒。
 * @method static \Type\Orm\ModelQuery<static> query(string $alias = '')
 * @method static static|null find(int|string $id)
 */
final class EnrollmentDirectory extends Model
{
    protected string $table = 'center_device_enrollment';

    protected bool $autoIncrement = false;

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '注册资格的稳定标识。'],
        'tenant_id' => ['type' => 'string', 'fillable' => false, 'comment' => '所属 Tenant 标识；平台目录只读展示。'],
        'name' => ['type' => 'string', 'fillable' => false, 'comment' => '业务显示名称。'],
        'capacity' => ['type' => 'integer', 'fillable' => false, 'comment' => '本批注册资格允许注册的设备总数。'],
        'registered_count' => ['type' => 'integer', 'fillable' => false, 'comment' => '本批注册资格已使用的设备数量。'],
        'register_expires_at' => ['type' => 'integer', 'fillable' => false, 'comment' => '允许新设备注册的截止时间，UTC Unix 秒。'],
        'enabled' => ['type' => 'boolean', 'fillable' => false, 'comment' => '当前是否启用。'],
        'recovery_verified' => ['type' => 'boolean', 'fillable' => false, 'comment' => '当前授权事实是否已完成恢复核对。'],
        'secret_hash' => ['type' => 'string', 'visible' => false, 'fillable' => false, 'comment' => '注册码散列，不参与平台公开投影。'],
        'request_hash' => ['type' => 'string', 'visible' => false, 'fillable' => false, 'comment' => '创建请求内容摘要，用于幂等标识冲突检查。'],
        'version' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '资料并发版本。'],
        'created_at' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '创建时间，UTC Unix 秒。'],
        'updated_at' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '最后更新时间，UTC Unix 秒。'],
    ];
}
