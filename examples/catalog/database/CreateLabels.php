<?php

declare(strict_types=1);

namespace app\catalog\database;

use Type\Orm\Attribute\Schema;

/** 标签与关系对有真实唯一约束，关系写入不依赖先查后写。 */
#[Schema(version: '003_catalog_labels', description: '创建标签与租户商品关联', snapshot: 'snapshots/labels.json', operations: [
    ['action' => 'create', 'table' => 'catalog_labels', 'columns' => [
        'id' => ['type' => 'integer', 'auto' => true], 'tenant_id' => ['type' => 'string', 'length' => 40],
        'code' => ['type' => 'string', 'length' => 50],
    ], 'primary' => ['id'], 'indexes' => [['name' => 'catalog_label_identity', 'columns' => ['tenant_id', 'code'], 'unique' => true]]],
    ['action' => 'create', 'table' => 'catalog_product_labels', 'columns' => [
        'tenant_id' => ['type' => 'string', 'length' => 40], 'product_id' => ['type' => 'integer'], 'label_id' => ['type' => 'integer'],
    ], 'primary' => ['tenant_id', 'product_id', 'label_id']],
])]
final class CreateLabels
{
}
