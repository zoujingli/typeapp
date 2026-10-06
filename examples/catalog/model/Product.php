<?php

declare(strict_types=1);

namespace app\catalog\model;

use Type\Orm\Attribute\BelongsToMany;
use Type\Orm\Attribute\Table;
use Type\Orm\Model;

/** 目录商品的可信租户、自动时间及显式公开字段。 */
#[Table('catalog_products', version: 'version', tenant: 'tenant_id', createdAt: 'created_at', updatedAt: 'updated_at', database: 'catalog')]
final class Product extends Model
{
    public int $id;
    public string $tenant_id;
    public string $code;
    public string $name;
    public ?string $note;
    public int $version;
    public int $created_at;
    public int $updated_at;
    #[BelongsToMany(Label::class, 'catalog_product_labels', 'product_id', 'label_id', pivotTenant: 'tenant_id')]
    public array $labels;

    /** @return array<string,mixed> 仅输出业务允许的字段。 */
    public function present(): array
    {
        return $this->project(['id', 'code', 'name', 'note', 'version', 'created_at', 'updated_at']);
    }
}
