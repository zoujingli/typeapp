<?php

declare(strict_types=1);

namespace app\catalog\model;

use Type\Orm\Attribute\Table;
use Type\Orm\Model;

/** 消费凭据与业务效果在同一 catalog 事务确认，消息 ID 是唯一效果身份。 */
#[Table('catalog_deliveries', generatedPrimary: false, database: 'catalog')]
final class Delivery extends Model
{
    public string $id;
    public int $product_id;
}
