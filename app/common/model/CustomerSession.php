<?php

declare(strict_types=1);

namespace app\common\model;

use Type\Orm\Attribute\BelongsTo;
use Type\Orm\Attribute\Column;
use Type\Orm\Attribute\Table;
use Type\Orm\Model;

/** 客户登录会话，模拟登录来源通过同一实体保留。 */
#[Table('customer_sessions', primary: 'token_hash', generatedPrimary: false)]
final class CustomerSession extends Model
{
    #[Column(visible: false)]
    public string $token_hash;

    #[Column(visible: false)]
    public string $id;

    public string $user_id;
    public int $expires_at;
    public int $created_at;
    public ?string $actor_id;
    public ?string $source_session_id;

    #[BelongsTo(CustomerUser::class, foreignKey: 'user_id')]
    public ?CustomerUser $user;

    #[BelongsTo(AdminSession::class, foreignKey: 'source_session_id', ownerKey: 'id')]
    public ?AdminSession $source_session;
}
