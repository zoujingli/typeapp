<?php

declare(strict_types=1);

namespace app\iot\model;

use Type\Orm\Attribute\Column;
use Type\Orm\Attribute\Table;
use Type\Orm\Model;

/** 平台发起的使用关系终止或转配；双方可见性由服务按来源及目标客户显式限制。 */
#[Table('iot_device_reassignments', generatedPrimary: false, version: 'version', createdAt: 'created_at', updatedAt: 'updated_at')]
final class DeviceReassignment extends Model
{
    public string $id;
    public string $device_id;
    public string $device_name;
    public string $source_tenant_id;
    public ?string $target_tenant_id;
    public string $ownership_id;
    public ?string $new_ownership_id;
    public string $kind;
    public string $status;
    public int $request_version;

    #[Column(visible: false)]
    public string $source_context;

    public bool $requires_store;
    public ?string $isolation_id;
    public ?int $access_version;

    #[Column(visible: false)]
    public string $retained_json;

    public ?string $accept_id;
    public ?int $accept_version;

    #[Column(visible: false)]
    public ?string $accept_context;

    #[Column(fillable: false, required: false)]
    public int $version;

    public int $created_at;
    public int $updated_at;
}
