<?php

declare(strict_types=1);

namespace app\common\model;

use Type\Orm\Attribute\BelongsToMany;
use Type\Orm\Attribute\Column;
use Type\Orm\Attribute\Table;
use Type\Orm\Model;

/** 租户角色的 scope_id 明确映射为租户字段，平台角色不使用此规则。 */
#[Table('customer_roles', generatedPrimary: false, version: 'version', tenant: 'scope_id')]
final class CustomerRole extends Model
{
    public string $id;
    public string $scope_id;
    public string $name;
    public bool $enabled;
    public bool $protected;
    public bool $recovery_verified;
    public int $created_at;

    #[Column(fillable: false, required: false)]
    public int $version;

    #[BelongsToMany(CustomerMember::class, 'customer_member_roles', 'role_id', 'member_id', pivotTenant: 'tenant_id')]
    public array $members;
}
