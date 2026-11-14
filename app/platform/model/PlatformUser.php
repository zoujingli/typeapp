<?php

declare(strict_types=1);

namespace app\platform\model;

use Type\Orm\Attribute\BelongsToMany;
use Type\Orm\Attribute\HasMany;
use Type\Orm\Model;

/**
 * 平台管理账号；凭据和登录状态属于账号实体本身。
 *
 * @property string $id 记录的稳定标识。
 * @property string $login 规范化的登录账号。
 * @property string $name 业务显示名称。
 * @property string $password_hash 登录口令散列，不进入公开投影。
 * @property bool $enabled 当前是否启用。
 * @property int $version 资料版本由身份服务在授权锁内维护，登录计数不属于资料变更。
 * @property int $failures 当前口令验证连续失败次数。
 * @property int $locked_until 口令验证锁定截止时间，UTC Unix 秒；0 表示未锁定。
 * @property bool $recovery_verified 当前授权事实是否已完成恢复核对。
 * @property-read int $created_at 创建时间，UTC Unix 秒。
 * @method static \Type\Orm\ModelQuery<static> query(string $alias = '')
 * @method static static|null find(int|string $id)
 * @method static static create(array $values)
 * @method static static firstOrCreate(array $identity, array $values = [])
 */
final class PlatformUser extends Model
{
    /** 账号关联的会话；访问前须显式加载。
     * @var list<PlatformSession>
     */
    #[HasMany(PlatformSession::class, foreignKey: 'user_id')]
    public array $sessions;

    /** 账号关联的角色；访问前须显式加载。
     * @var list<PlatformRole>
     */
    #[BelongsToMany(PlatformRole::class, 'platform_user_role', 'user_id', 'role_id')]
    public array $roles;

    protected string $table = 'platform_user';

    protected bool $autoIncrement = false;

    protected ?string $versionKey = 'version';

    protected ?string $createTime = 'created_at';

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '记录的稳定标识。'],
        'login' => ['type' => 'string', 'comment' => '规范化的登录账号。'],
        'name' => ['type' => 'string', 'comment' => '业务显示名称。'],
        'password_hash' => ['type' => 'string', 'visible' => false, 'comment' => '登录口令散列，不进入公开投影。'],
        'enabled' => ['type' => 'boolean', 'comment' => '当前是否启用。'],
        'version' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '资料版本由身份服务在授权锁内维护，登录计数不属于资料变更。'],
        'failures' => ['type' => 'integer', 'comment' => '当前口令验证连续失败次数。'],
        'locked_until' => ['type' => 'integer', 'comment' => '口令验证锁定截止时间，UTC Unix 秒；0 表示未锁定。'],
        'recovery_verified' => ['type' => 'boolean', 'comment' => '当前授权事实是否已完成恢复核对。'],
        'created_at' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '创建时间，UTC Unix 秒。'],
    ];
}
