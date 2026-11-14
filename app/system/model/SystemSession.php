<?php

declare(strict_types=1);

namespace app\system\model;

use app\platform\model\PlatformSession;
use Type\Orm\Model;

/**
 * 客户登录会话，模拟登录来源通过同一实体保留。
 *
 * @property string $token_hash 会话令牌的 SHA-256 摘要，不保存原始令牌。
 * @property string $id 记录的稳定标识。
 * @property string $user_id 对应人员账号的稳定标识。
 * @property-read string $tenant_id 所属 Tenant 标识，由可信执行上下文约束。
 * @property int $expires_at 有效截止时间，UTC Unix 秒。
 * @property int $created_at 创建时间，UTC Unix 秒。
 * @property null|string $actor_id 真实平台操作者的账号标识。
 * @property null|string $source_session_id 来源平台会话标识，用于撤销后续模拟访问。
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
final class SystemSession extends Model
{
    /**
     * 显式加载的所属账号。
     */
    public function user(): array
    {
        return $this->belongsTo(SystemUser::class, 'user_id');
    }

    /**
     * 显式加载的来源平台会话。
     */
    public function source_session(): array
    {
        return $this->belongsTo(PlatformSession::class, 'source_session_id', 'id');
    }

    protected string $table = 'system_session';

    protected string $pk = 'token_hash';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = 'tenant_id';

    protected array $schema = [
        'token_hash' => ['type' => 'string', 'visible' => false, 'comment' => '会话令牌的 SHA-256 摘要，不保存原始令牌。'],
        'id' => ['type' => 'string', 'visible' => false, 'comment' => '记录的稳定标识。'],
        'user_id' => ['type' => 'string', 'comment' => '对应人员账号的稳定标识。'],
        'tenant_id' => ['type' => 'string', 'comment' => '所属 Tenant 标识，由可信执行上下文约束。'],
        'expires_at' => ['type' => 'integer', 'comment' => '有效截止时间，UTC Unix 秒。'],
        'created_at' => ['type' => 'integer', 'comment' => '创建时间，UTC Unix 秒。'],
        'actor_id' => ['type' => 'string', 'nullable' => true, 'comment' => '真实平台操作者的账号标识。'],
        'source_session_id' => ['type' => 'string', 'nullable' => true, 'comment' => '来源平台会话标识，用于撤销后续模拟访问。'],
    ];
}
