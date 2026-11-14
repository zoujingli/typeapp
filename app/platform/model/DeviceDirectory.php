<?php

declare(strict_types=1);

namespace app\platform\model;

use Type\Orm\Model;

/**
 * Platform 与受控协议入口使用的跨 Tenant 设备只读目录。
 *
 * 该模型故意不声明 tenantKey，只允许目录读取；设备创建、资料修改、归属
 * 和生命周期写入必须使用带可信 Tenant 范围的 Center Device Model。
 *
 * @property string $id 设备的全局稳定标识。
 * @property string $tenant_id 所属 Tenant 标识，仅用于跨客户目录投影。
 * @property null|string $product_id 设备所属分类标识。
 * @property string $ownership_id 当前设备使用关系标识。
 * @property string $name 业务显示名称。
 * @property string $lifecycle 设备管理生命周期。
 * @property int $version 资料并发版本。
 * @property int $created_at 创建时间，UTC Unix 秒。
 * @property int $updated_at 最后更新时间，UTC Unix 秒。
 * @property null|string $transfer_id 当前转配流程标识。
 * @property bool $transfer_frozen 当前使用关系是否因转配流程冻结。
 * @property bool $recovery_verified 当前授权事实是否已完成恢复核对。
 * @method static \Type\Orm\ModelQuery<static> query(string $alias = '')
 * @method static static|null find(int|string $id)
 */
final class DeviceDirectory extends Model
{
    protected string $table = 'center_device';

    protected bool $autoIncrement = false;

    /** 平台目录按全局资产读取，不能自动从 tenant_id 字段推导租户范围。 */
    protected ?string $tenantKey = null;

    protected array $schema = [
        'id' => ['type' => 'string', 'fillable' => false, 'comment' => '设备的全局稳定标识。'],
        'tenant_id' => ['type' => 'string', 'fillable' => false, 'comment' => '所属 Tenant 标识，仅用于跨客户目录投影。'],
        'product_id' => ['type' => 'string', 'nullable' => true, 'fillable' => false, 'comment' => '设备所属分类标识。'],
        'ownership_id' => ['type' => 'string', 'fillable' => false, 'comment' => '当前设备使用关系标识。'],
        'name' => ['type' => 'string', 'fillable' => false, 'comment' => '业务显示名称。'],
        'lifecycle' => ['type' => 'string', 'fillable' => false, 'comment' => '设备管理生命周期。'],
        'version' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '资料并发版本。'],
        'created_at' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '创建时间，UTC Unix 秒。'],
        'updated_at' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '最后更新时间，UTC Unix 秒。'],
        'transfer_id' => ['type' => 'string', 'nullable' => true, 'fillable' => false, 'comment' => '当前转配流程标识。'],
        'transfer_frozen' => ['type' => 'boolean', 'fillable' => false, 'comment' => '当前使用关系是否因转配流程冻结。'],
        'recovery_verified' => ['type' => 'boolean', 'fillable' => false, 'comment' => '当前授权事实是否已完成恢复核对。'],
    ];
}
