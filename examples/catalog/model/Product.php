<?php

declare (strict_types=1);
namespace app\catalog\model;

use Type\Orm\Model;
/**
 * 目录商品的可信租户、自动时间及显式公开字段。
 */
final class Product extends Model
{
    protected string $table = 'catalog_products';
    protected string $database = 'catalog';
    protected ?string $tenantKey = 'tenant_id';
    protected ?string $versionKey = 'version';
    protected ?string $createTime = 'created_at';
    protected ?string $updateTime = 'updated_at';
    protected array $schema = ['id' => 'int', 'tenant_id' => 'string', 'code' => 'string', 'name' => 'string', 'note' => '?string', 'version' => 'int', 'created_at' => 'int', 'updated_at' => 'int'];
    public function labels(): array
    {
        return $this->belongsToMany(Label::class, 'catalog_product_labels', 'product_id', 'label_id', pivotTenant: 'tenant_id');
    }
    /**
     * @return array<string,mixed> 仅输出业务允许的字段。
     */
    public function present(): array
    {
        return $this->project(['id', 'code', 'name', 'note', 'version', 'created_at', 'updated_at']);
    }
}