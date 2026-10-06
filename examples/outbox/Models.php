<?php

declare(strict_types=1);

namespace TypeApp\OutboxExample;

use Type\Orm\Attribute\Table;
use Type\Orm\Model;

/** 与消息意图共同提交的命名来源业务实体。 */
#[Table('type_outbox_business', generatedPrimary: false, database: 'outbox')]
final class Business extends Model
{
    public string $id;
    public string $value;
}

/** 消费凭据与实际业务效果使用同一来源事务。 */
#[Table('type_outbox_effects', generatedPrimary: false, database: 'outbox')]
final class Effect extends Model
{
    public string $id;
    public string $value;
}
