<?php

declare(strict_types=1);

namespace app\iot\model;

use Type\Orm\Attribute\Column;
use Type\Orm\Attribute\Table;
use Type\Orm\Model;

/** 当前客户使用的设备实体；物模型为空表示独立原始通信身份。 */
#[Table('iot_devices', generatedPrimary: false, version: 'version', createdAt: 'created_at', updatedAt: 'updated_at')]
final class Device extends Model
{
    public string $id;
    public string $tenant_id;
    public ?string $product_id;
    public ?int $model_version;
    public string $ownership_id;
    public string $name;
    public string $lifecycle;

    #[Column(fillable: false, required: false)]
    public int $version;

    public int $created_at;
    public int $updated_at;
    public ?string $model_switch_id;
    public string $model_start_sequence;
    public ?string $transfer_id;
    public bool $transfer_frozen;
    public bool $recovery_verified;
}
