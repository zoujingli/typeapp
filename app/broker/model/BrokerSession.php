<?php

declare(strict_types=1);

namespace app\broker\model;

use Type\Orm\Attribute\BelongsTo;
use Type\Orm\Attribute\Column;
use Type\Orm\Attribute\Table;
use Type\Orm\Model;

/** Broker 管理会话；broker_sessions 没有公开会话 ID。 */
#[Table('broker_sessions', primary: 'token_hash', generatedPrimary: false)]
final class BrokerSession extends Model
{
    #[Column(visible: false)]
    public string $token_hash;

    public string $user_id;
    public int $expires_at;
    public int $created_at;

    #[BelongsTo(BrokerUser::class, foreignKey: 'user_id')]
    public ?BrokerUser $user;
}
