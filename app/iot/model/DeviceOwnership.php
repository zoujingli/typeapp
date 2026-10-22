<?php

declare(strict_types=1);

namespace app\iot\model;

use Type\Orm\Attribute\Column;
use Type\Orm\Attribute\Table;
use Type\Orm\Model;

/** 客户使用设备的确定阶段；注册期限独立，结束关系不会自动恢复。 */
#[Table('iot_device_ownerships', generatedPrimary: false, version: 'version')]
final class DeviceOwnership extends Model
{
    public string $id;
    public string $device_id;
    public string $tenant_id;
    public int $started_at;
    public ?int $ended_at;
    public ?int $expires_at;

    #[Column(visible: false)]
    public ?string $expiry_context;

    public bool $access_started;

    #[Column(fillable: false, required: false)]
    public int $version;
}
