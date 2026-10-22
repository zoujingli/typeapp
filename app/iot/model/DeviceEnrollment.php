<?php

declare(strict_types=1);

namespace app\iot\model;

use Type\Orm\Attribute\Column;
use Type\Orm\Attribute\Table;
use Type\Orm\Model;

/** 客户的注册资格；Model 自动限定可信租户，秘密与请求验证值不参与普通投影。 */
#[Table('iot_device_enrollments', generatedPrimary: false, version: 'version', createdAt: 'created_at', updatedAt: 'updated_at')]
final class DeviceEnrollment extends Model
{
    public string $id;
    public string $tenant_id;
    public string $name;
    public int $capacity;
    public int $registered_count;
    public int $register_expires_at;
    public bool $enabled;
    public bool $recovery_verified;

    #[Column(visible: false)]
    public string $secret_hash;

    #[Column(visible: false)]
    public string $request_hash;

    #[Column(fillable: false, required: false)]
    public int $version;

    public int $created_at;
    public int $updated_at;
}
