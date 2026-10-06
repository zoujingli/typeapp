<?php

declare(strict_types=1);

namespace app\catalog\model;

use Type\Orm\Attribute\Table;
use Type\Orm\Model;

/** 标签同样属于可信租户；关系查询不可跨越此边界。 */
#[Table('catalog_labels', tenant: 'tenant_id', database: 'catalog')]
final class Label extends Model
{
    public int $id;
    public string $tenant_id;
    public string $code;
}
