<?php

declare(strict_types=1);

namespace app\center\model;

use Type\Orm\Model;

/**
 * 接入应用对一个设备使用关系的显式通信资格；不随设备重新分配复制。
 *
 * @property string $id 记录的稳定标识。
 * @property-read string $tenant_id 所属 Tenant 标识，由可信执行上下文约束。
 * @property string $application_id 取得通信授权的接入应用标识。
 * @property string $device_id 设备的稳定标识。
 * @property string $ownership_id 授权或操作绑定的设备使用关系。
 * @property bool $receive 是否允许接收设备上行消息。
 * @property bool $send 是否允许向设备发送下行消息。
 * @property bool $subscribe 是否允许订阅设备上行 Topic，须同时具有接收资格。
 * @property-read int $version 资料并发版本。
 * @property-read int $created_at 创建时间，UTC Unix 秒。
 * @property-read int $updated_at 最后更新时间，UTC Unix 秒。
 * @method static \Type\Orm\ModelQuery<static> query(string $alias = '')
 * @method static static|null find(int|string $id)
 * @method static static create(array $values)
 * @method static static firstOrCreate(array $identity, array $values = [])
 */
final class ApplicationGrant extends Model
{
    protected string $table = 'center_application_grant';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = 'tenant_id';

    protected ?string $versionKey = 'version';

    protected ?string $createTime = 'created_at';

    protected ?string $updateTime = 'updated_at';

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '记录的稳定标识。'],
        'tenant_id' => ['type' => 'string', 'comment' => '所属 Tenant 标识，由可信执行上下文约束。'],
        'application_id' => ['type' => 'string', 'comment' => '取得通信授权的接入应用标识。'],
        'device_id' => ['type' => 'string', 'comment' => '设备的稳定标识。'],
        'ownership_id' => ['type' => 'string', 'comment' => '授权或操作绑定的设备使用关系。'],
        'receive' => ['type' => 'boolean', 'comment' => '是否允许接收设备上行消息。'],
        'send' => ['type' => 'boolean', 'comment' => '是否允许向设备发送下行消息。'],
        'subscribe' => ['type' => 'boolean', 'comment' => '是否允许订阅设备上行 Topic，须同时具有接收资格。'],
        'version' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '资料并发版本。'],
        'created_at' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '创建时间，UTC Unix 秒。'],
        'updated_at' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '最后更新时间，UTC Unix 秒。'],
    ];
}
