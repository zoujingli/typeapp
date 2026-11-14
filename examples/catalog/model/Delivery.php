<?php

declare (strict_types=1);
namespace app\catalog\model;

use Type\Orm\Model;
/**
 * 消费凭据与业务效果在同一 catalog 事务确认，消息 ID 是唯一效果身份。
 */
final class Delivery extends Model
{
    protected string $table = 'catalog_deliveries';
    protected bool $autoIncrement = false;
    protected string $database = 'catalog';
    protected array $schema = ['id' => 'string', 'product_id' => 'int'];
}