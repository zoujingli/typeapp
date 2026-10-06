<?php

declare(strict_types=1);

namespace app\iot\model;

use Type\Orm\Attribute\Column;
use Type\Orm\Attribute\Table;
use Type\Orm\Model;

/** 租户产品资料；物模型的永久编号与产品资料版本分别管理。 */
#[Table('iot_products', generatedPrimary: false, version: 'version', createdAt: 'created_at', updatedAt: 'updated_at')]
final class Product extends Model
{
    public string $id;
    public string $tenant_id;
    public string $name;
    public string $description;

    #[Column(fillable: false, required: false)]
    public int $version;

    /** 编号只由授权事务分配，不能通过普通模型赋值或公开投影修改。 */
    #[Column(fillable: false, visible: false, required: false)]
    public int $next_model_version;

    public int $created_at;
    public int $updated_at;
}
