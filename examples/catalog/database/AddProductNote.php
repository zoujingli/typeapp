<?php

declare(strict_types=1);

namespace app\catalog\database;

use Type\Orm\Attribute\Schema;

/** 已有表通过新的冻结迁移增列，保留所有历史迁移身份。 */
#[Schema(version: '004_catalog_note', description: '商品增加可空备注', snapshot: 'snapshots/product-note.json', operations: [
    ['action' => 'add-column', 'table' => 'catalog_products', 'name' => 'note', 'column' => ['type' => 'string', 'length' => 200, 'nullable' => true]],
])]
final class AddProductNote
{
}
