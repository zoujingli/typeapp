<?php

declare(strict_types=1);

namespace app\common\model;

use Type\Orm\Attribute\Column;
use Type\Orm\Attribute\BelongsTo;
use Type\Orm\Attribute\Table;
use Type\Orm\Model;

/** 平台登录会话；令牌散列只用于内部匹配，不允许业务输出。 */
#[Table('admin_sessions', primary: 'token_hash', generatedPrimary: false)]
final class AdminSession extends Model
{
    #[Column(visible: false)]
    public string $token_hash;

    #[Column(visible: false)]
    public string $id;

    public string $user_id;
    public int $expires_at;
    public int $created_at;

    #[BelongsTo(AdminUser::class, foreignKey: 'user_id')]
    public ?AdminUser $user;
}
