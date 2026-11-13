<?php

declare(strict_types=1);

namespace app\center\model;

use Type\Orm\Model;

/**
 * 当前客户使用的原始通信设备，身份绑定明确的使用关系。
 *
 * @property string $id 设备的全局稳定标识。
 * @property-read string $tenant_id 所属 Tenant 标识，由可信执行上下文约束。
 * @property null|string $product_id 设备所属分类标识；未分类时为空。
 * @property string $ownership_id 授权或操作绑定的设备使用关系。
 * @property string $name 业务显示名称。
 * @property string $lifecycle 设备管理生命周期，与连接观察状态分别维护。
 * @property-read int $version 资料并发版本。
 * @property-read int $created_at 创建时间，UTC Unix 秒。
 * @property-read int $updated_at 最后更新时间，UTC Unix 秒。
 * @property null|string $transfer_id 当前转配流程标识；无进行中流程时为空。
 * @property bool $transfer_frozen 当前使用关系是否因转配流程冻结。
 * @property bool $recovery_verified 当前授权事实是否已完成恢复核对。
 * @method static \Type\Orm\ModelQuery<static> query(string $alias = '')
 * @method static static|null find(int|string $id)
 * @method static static create(array $values)
 * @method static static firstOrCreate(array $identity, array $values = [])
 */
final class Device extends Model
{
    protected string $table = 'center_device';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = 'tenant_id';

    protected ?string $versionKey = 'version';

    protected ?string $createTime = 'created_at';

    protected ?string $updateTime = 'updated_at';

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '设备的全局稳定标识。'],
        'tenant_id' => ['type' => 'string', 'comment' => '所属 Tenant 标识，由可信执行上下文约束。'],
        'product_id' => ['type' => 'string', 'nullable' => true, 'comment' => '设备所属分类标识；未分类时为空。'],
        'ownership_id' => ['type' => 'string', 'comment' => '授权或操作绑定的设备使用关系。'],
        'name' => ['type' => 'string', 'comment' => '业务显示名称。'],
        'lifecycle' => ['type' => 'string', 'comment' => '设备管理生命周期，与连接观察状态分别维护。'],
        'version' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '资料并发版本。'],
        'created_at' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '创建时间，UTC Unix 秒。'],
        'updated_at' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '最后更新时间，UTC Unix 秒。'],
        'transfer_id' => ['type' => 'string', 'nullable' => true, 'comment' => '当前转配流程标识；无进行中流程时为空。'],
        'transfer_frozen' => ['type' => 'boolean', 'comment' => '当前使用关系是否因转配流程冻结。'],
        'recovery_verified' => ['type' => 'boolean', 'comment' => '当前授权事实是否已完成恢复核对。'],
    ];
}
