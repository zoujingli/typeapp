<?php

declare(strict_types=1);

namespace app\common\model;

use Type\Orm\Attribute\BelongsToMany;
use Type\Orm\Attribute\Column;
use Type\Orm\Attribute\Table;
use Type\Orm\Model;

/** 平台角色实体；授权规则及固定权限码由应用服务维护。 */
#[Table('admin_roles', generatedPrimary: false, version: 'version')]
final class AdminRole extends Model
{
    public string $id;
    public string $scope_id;
    public string $name;
    public bool $enabled;
    public bool $protected;
    public bool $recovery_verified;
    public int $created_at;

    #[Column(fillable: false, required: false)]
    public int $version;

    #[BelongsToMany(AdminUser::class, 'admin_user_roles', 'role_id', 'user_id')]
    public array $users;
}
