<?php

declare(strict_types=1);

namespace app\iot\model;

use Type\Orm\Attribute\Column;
use Type\Orm\Attribute\Table;
use Type\Orm\Model;

/** 接入应用对一个设备使用关系的显式通信资格；不随设备重新分配复制。 */
#[Table('iot_application_grants', generatedPrimary: false, version: 'version', createdAt: 'created_at', updatedAt: 'updated_at')]
final class ApplicationGrant extends Model
{
    public string $id;
    public string $tenant_id;
    public string $application_id;
    public string $device_id;
    public string $ownership_id;
    public bool $receive;
    public bool $send;
    public bool $subscribe;

    #[Column(fillable: false, required: false)]
    public int $version;

    public int $created_at;
    public int $updated_at;
}
