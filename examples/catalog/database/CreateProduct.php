<?php

declare(strict_types=1);

namespace app\catalog\database;

use Type\Orm\Attribute\Schema;

/** 第二模块的新建表；首次冻结前填写业务结构，不修改模板 001。 */
#[Schema(version: '002_catalog_products', description: '创建租户商品及唯一业务身份', snapshot: 'snapshots/products.json', operations: [
    ['action' => 'create', 'table' => 'catalog_products', 'columns' => [
        'id' => ['type' => 'integer', 'auto' => true], 'tenant_id' => ['type' => 'string', 'length' => 40],
        'code' => ['type' => 'string', 'length' => 50], 'name' => ['type' => 'string', 'length' => 100],
        'version' => ['type' => 'integer', 'default' => 1], 'created_at' => ['type' => 'integer', 'bits' => 64],
        'updated_at' => ['type' => 'integer', 'bits' => 64],
    ], 'primary' => ['id'], 'indexes' => [['name' => 'catalog_product_identity', 'columns' => ['tenant_id', 'code'], 'unique' => true]]],
])]
final class CreateProduct
{
}
