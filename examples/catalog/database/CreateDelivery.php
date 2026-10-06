<?php

declare(strict_types=1);

namespace app\catalog\database;

use Type\Orm\Attribute\Schema;

/** 新版本增加消费效果，不修改已经冻结的商品创建和变更迁移。 */
#[Schema(version: '005_catalog_delivery', description: '创建商品通知消费效果', snapshot: 'snapshots/delivery.json', operations: [
    ['action' => 'create', 'table' => 'catalog_deliveries', 'columns' => [
        'id' => ['type' => 'string', 'length' => 128], 'product_id' => ['type' => 'integer'],
    ], 'primary' => ['id']],
])]
final class CreateDelivery
{
}
