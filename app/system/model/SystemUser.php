<?php

declare(strict_types=1);

namespace app\system\model;

use Type\Orm\Model;

/**
 * 直接归属一个 Tenant 的系统账号，登录名只在该客户内唯一。
 *
 * @property string $id 记录的稳定标识。
 * @property-read string $tenant_id 所属 Tenant 标识，由可信执行上下文约束。
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
final class SystemUser extends Model
{
    /** 账号关联的角色；访问前须显式加载。
     * @return list<SystemRole> 当前客户系统内角色，关联表同时约束归属
     */
    public function roles(): array
    {
        return $this->belongsToMany(SystemRole::class, 'system_user_role', 'user_id', 'role_id', pivotTenant: 'tenant_id');
    }

    /** 账号关联的会话；访问前须显式加载。
     * @return list<SystemSession>
     */
    public function sessions(): array
    {
        return $this->hasMany(SystemSession::class, 'user_id');
    }

    protected string $table = 'system_user';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = 'tenant_id';

    protected ?string $versionKey = 'version';

    protected ?string $createTime = 'created_at';

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '记录的稳定标识。'],
        'tenant_id' => ['type' => 'string', 'comment' => '所属 Tenant 标识，由可信执行上下文约束。'],
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
