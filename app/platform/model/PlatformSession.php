<?php

declare(strict_types=1);

namespace app\platform\model;

use Type\Orm\Attribute\BelongsTo;
use Type\Orm\Model;

/**
 * 平台登录会话；令牌散列只用于内部匹配，不允许业务输出。
 *
 * @property string $token_hash 会话令牌的 SHA-256 摘要，不保存原始令牌。
 * @property string $id 记录的稳定标识。
 * @property string $user_id 对应人员账号的稳定标识。
 * @property int $expires_at 有效截止时间，UTC Unix 秒。
 * @property int $created_at 创建时间，UTC Unix 秒。
 * @method static \Type\Orm\ModelQuery<static> query(string $alias = '')
 * @method static static|null find(int|string $id)
 * @method static static create(array $values)
 * @method static static firstOrCreate(array $identity, array $values = [])
 */
final class PlatformSession extends Model
{
    /** 显式加载的所属账号。
     * @var list<PlatformUser>
     */
    #[BelongsTo(PlatformUser::class, foreignKey: 'user_id')]
    public ?PlatformUser $user;

    protected string $table = 'platform_session';

    protected string $pk = 'token_hash';

    protected bool $autoIncrement = false;

    protected array $schema = [
        'token_hash' => ['type' => 'string', 'visible' => false, 'comment' => '会话令牌的 SHA-256 摘要，不保存原始令牌。'],
        'id' => ['type' => 'string', 'visible' => false, 'comment' => '记录的稳定标识。'],
        'user_id' => ['type' => 'string', 'comment' => '对应人员账号的稳定标识。'],
        'expires_at' => ['type' => 'integer', 'comment' => '有效截止时间，UTC Unix 秒。'],
        'created_at' => ['type' => 'integer', 'comment' => '创建时间，UTC Unix 秒。'],
    ];
}
