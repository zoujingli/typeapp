<?php

declare (strict_types=1);
namespace TypeApp\OutboxExample;

use Type\Orm\Model;
/**
 * 与消息意图共同提交的命名来源业务实体。
 */
final class Business extends Model
{
    protected string $table = 'type_outbox_business';
    protected bool $autoIncrement = false;
    protected string $database = 'outbox';
    protected array $schema = ['id' => 'string', 'value' => 'string'];
}
/**
 * 消费凭据与实际业务效果使用同一来源事务。
 */
final class Effect extends Model
{
    protected string $table = 'type_outbox_effects';
    protected bool $autoIncrement = false;
    protected string $database = 'outbox';
    protected array $schema = ['id' => 'string', 'value' => 'string'];
}