<?php

declare(strict_types=1);

namespace app\common\model;

use Type\Orm\Attribute\Column;
use Type\Orm\Attribute\HasMany;
use Type\Orm\Attribute\Table;
use Type\Orm\Model;

/** 全局租户目录；租户内实体的归属由已验证执行上下文自动限定。 */
#[Table('iot_tenants', generatedPrimary: false, version: 'version')]
final class Tenant extends Model
{
    public string $id;
    public string $name;
    public bool $enabled;

    #[Column(fillable: false, required: false)]
    public int $version;

    public int $created_at;

    /** @var list<CustomerMember> */
    #[HasMany(CustomerMember::class, foreignKey: 'tenant_id')]
    public array $members;
}
