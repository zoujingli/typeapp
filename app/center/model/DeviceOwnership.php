<?php

declare(strict_types=1);

namespace app\center\model;

use Type\Orm\Model;

/**
 * 客户使用设备的确定阶段；注册期限独立，结束关系不会自动恢复。
 *
 * @property string $id 设备一次使用关系的稳定标识。
 * @property string $device_id 设备的稳定标识。
 * @property-read string $tenant_id 所属 Tenant 标识，由可信执行上下文约束。
 * @property int $started_at 本次设备使用关系的开始时间，UTC Unix 秒。
 * @property null|int $ended_at 使用关系结束时间，UTC Unix 秒；尚未结束时为空。
 * @property null|int $expires_at 有效截止时间，UTC Unix 秒。
 * @property null|string $expiry_context 使用期限操作的审计身份快照。
 * @property bool $access_started 是否已有实际通信接入历史。
 * @property-read int $version 资料并发版本。
 * @method static \Type\Orm\ModelQuery<static> query(string $alias = '')
 * @method static static|null find(int|string $id)
 * @method static static create(array $values)
 * @method static static firstOrCreate(array $identity, array $values = [])
 */
final class DeviceOwnership extends Model
{
    protected string $table = 'center_device_ownership';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = 'tenant_id';

    protected ?string $versionKey = 'version';

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '设备一次使用关系的稳定标识。'],
        'device_id' => ['type' => 'string', 'comment' => '设备的稳定标识。'],
        'tenant_id' => ['type' => 'string', 'comment' => '所属 Tenant 标识，由可信执行上下文约束。'],
        'started_at' => ['type' => 'integer', 'comment' => '本次设备使用关系的开始时间，UTC Unix 秒。'],
        'ended_at' => ['type' => 'integer', 'nullable' => true, 'comment' => '使用关系结束时间，UTC Unix 秒；尚未结束时为空。'],
        'expires_at' => ['type' => 'integer', 'nullable' => true, 'comment' => '有效截止时间，UTC Unix 秒。'],
        'expiry_context' => ['type' => 'string', 'nullable' => true, 'visible' => false, 'comment' => '使用期限操作的审计身份快照。'],
        'access_started' => ['type' => 'boolean', 'comment' => '是否已有实际通信接入历史。'],
        'version' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '资料并发版本。'],
    ];
}
