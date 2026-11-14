<?php

declare(strict_types=1);

namespace app\platform\model;

use Type\Orm\Model;

/**
 * 平台角色实体；授权规则及固定权限码由应用服务维护。
 *
 * @property string $id 记录的稳定标识。
 * @property string $scope_id 平台作用域标识，固定为 platform。
 * @property string $name 业务显示名称。
 * @property bool $enabled 当前是否启用。
 * @property bool $protected 受保护超级管理员角色标志。
 * @property bool $recovery_verified 当前授权事实是否已完成恢复核对。
 * @property-read int $created_at 创建时间，UTC Unix 秒。
 * @property-read int $version 资料并发版本。
 * @method static \Type\Orm\ModelQuery<static> query(string $alias = '')
 * @method static \Type\Orm\ModelQuery<static> where(string|array $field, mixed $operator = null, mixed $value = null, string $boolean = 'AND')
 * @method static \Type\Orm\ModelQuery<static> order(string $field, string $direction = 'ASC')
 * @method static \Type\Orm\ModelQuery<static> limit(int $count, int $offset = 0)
 * @method static \Type\Orm\ModelQuery<static> with(string $name, ?\Closure $relation = null)
 * @method static static|null find(int|string $id)
 * @method static static create(array $values)
 * @method static static firstOrCreate(array $identity, array $values = [])
 * @method static bool destroy(int|string $id)
 * @method static \Type\Orm\Helper\QueryHelper search(array $input = [], string $alias = '')
 */
final class PlatformRole extends Model
{
    /** 通过当前角色关联的账号；访问前须显式加载。
     * @return list<PlatformUser>
     */
    public function users(): array
    {
        return $this->belongsToMany(PlatformUser::class, 'platform_user_role', 'role_id', 'user_id');
    }

    /** 通过角色授权关联的菜单；访问前须显式加载。
     * @return list<PlatformMenu> 显式授权引用已登记菜单的稳定权限标识
     */
    public function menus(): array
    {
        return $this->belongsToMany(PlatformMenu::class, 'platform_role_permission', 'role_id', 'permission', targetKey: 'permission');
    }

    protected string $table = 'platform_role';

    protected bool $autoIncrement = false;

    protected ?string $versionKey = 'version';

    protected ?string $createTime = 'created_at';

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '记录的稳定标识。'],
        'scope_id' => ['type' => 'string', 'comment' => '平台作用域标识，固定为 platform。'],
        'name' => ['type' => 'string', 'comment' => '业务显示名称。'],
        'enabled' => ['type' => 'boolean', 'comment' => '当前是否启用。'],
        'protected' => ['type' => 'boolean', 'comment' => '受保护超级管理员角色标志。'],
        'recovery_verified' => ['type' => 'boolean', 'comment' => '当前授权事实是否已完成恢复核对。'],
        'created_at' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '创建时间，UTC Unix 秒。'],
        'version' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '资料并发版本。'],
    ];
}
