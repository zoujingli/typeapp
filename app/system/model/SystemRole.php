<?php

declare(strict_types=1);

namespace app\system\model;

use Type\Orm\Model;

/**
 * 客户角色由 ORM tenant 声明和复合关联共同隔离。
 *
 * @property string $id 记录的稳定标识。
 * @property-read string $tenant_id 所属 Tenant 标识，由可信执行上下文约束。
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
final class SystemRole extends Model
{
    /** 通过当前角色关联的账号；访问前须显式加载。
     * @return list<SystemUser>
     */
    public function users(): array
    {
        return $this->belongsToMany(SystemUser::class, 'system_user_role', 'role_id', 'user_id', pivotTenant: 'tenant_id');
    }

    /** 通过角色授权关联的菜单；访问前须显式加载。
     * @return list<SystemMenu> 共享菜单通过当前 Tenant 的角色关联授权
     */
    public function menus(): array
    {
        return $this->belongsToMany(SystemMenu::class, 'system_role_permission', 'role_id', 'permission', targetKey: 'permission', pivotTenant: 'tenant_id');
    }

    protected string $table = 'system_role';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = 'tenant_id';

    protected ?string $versionKey = 'version';

    protected ?string $createTime = 'created_at';

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '记录的稳定标识。'],
        'tenant_id' => ['type' => 'string', 'comment' => '所属 Tenant 标识，由可信执行上下文约束。'],
        'name' => ['type' => 'string', 'comment' => '业务显示名称。'],
        'enabled' => ['type' => 'boolean', 'comment' => '当前是否启用。'],
        'protected' => ['type' => 'boolean', 'comment' => '受保护超级管理员角色标志。'],
        'recovery_verified' => ['type' => 'boolean', 'comment' => '当前授权事实是否已完成恢复核对。'],
        'created_at' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '创建时间，UTC Unix 秒。'],
        'version' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '资料并发版本。'],
    ];
}
