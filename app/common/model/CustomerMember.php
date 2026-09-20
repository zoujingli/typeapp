<?php

declare(strict_types=1);

namespace app\common\model;

use Type\Orm\Attribute\BelongsTo;
use Type\Orm\Attribute\BelongsToMany;
use Type\Orm\Attribute\Column;
use Type\Orm\Attribute\Table;
use Type\Orm\Model;

/** 客户账号在单一租户中的成员关系；状态、版本和角色归属均在此实体。 */
#[Table('customer_members', generatedPrimary: false, version: 'version')]
final class CustomerMember extends Model
{
    public string $id;
    public string $tenant_id;
    public string $user_id;
    public string $name;
    public bool $enabled;
    public bool $recovery_verified;

    #[Column(fillable: false, required: false)]
    public int $version;

    public int $created_at;

    #[BelongsTo(Tenant::class, foreignKey: 'tenant_id')]
    public ?Tenant $tenant;

    #[BelongsTo(CustomerUser::class, foreignKey: 'user_id')]
    public ?CustomerUser $user;

    #[BelongsToMany(CustomerRole::class, 'customer_member_roles', 'member_id', 'role_id', pivotTenant: 'tenant_id')]
    public array $roles;
}
