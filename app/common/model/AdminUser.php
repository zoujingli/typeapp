<?php

declare(strict_types=1);

namespace app\common\model;

use Type\Orm\Attribute\Column;
use Type\Orm\Attribute\BelongsToMany;
use Type\Orm\Attribute\HasMany;
use Type\Orm\Attribute\Table;
use Type\Orm\Model;

/** 平台管理账号；凭据和登录状态属于账号实体本身。 */
#[Table('admin_users', generatedPrimary: false)]
final class AdminUser extends Model
{
    public string $id;
    public string $login;
    public string $name;

    #[Column(visible: false)]
    public string $password_hash;

    public bool $enabled;

    /** 资料版本由身份服务在授权锁内维护，登录计数不属于资料变更。 */
    public int $version;

    public int $failures;
    public int $locked_until;
    public bool $recovery_verified;
    public int $created_at;

    /** @var list<AdminSession> */
    #[HasMany(AdminSession::class, foreignKey: 'user_id')]
    public array $sessions;

    #[BelongsToMany(AdminRole::class, 'admin_user_roles', 'user_id', 'role_id')]
    public array $roles;
}
