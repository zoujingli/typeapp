<?php

declare(strict_types=1);

namespace app\platform\model;

use Type\Orm\Model;

/**
 * 平台签发、目标客户消费的一次性交接记录；原始交接码不持久化。
 *
 * @property string $code_hash 一次性交接码摘要，不保存原始交接码。
 * @property string $target_tenant_id 交接目标客户；平台记录由来源平台会话管理。
 * @property string $user_id 对应人员账号的稳定标识。
 * @property int $user_version 签发时目标账号的资料版本。
 * @property int $tenant_version 签发时目标客户的资料版本。
 * @property string $actor_id 真实平台操作者的账号标识。
 * @property string $source_session_id 来源平台会话标识，用于撤销后续模拟访问。
 * @property string $target_origin 交接绑定的目标入口地址。
 * @property int $expires_at 有效截止时间，UTC Unix 秒。
 * @property null|int $consumed_at 交接码消费时间，UTC Unix 秒；空值表示尚未消费。
 * @property-read int $created_at 创建时间，UTC Unix 秒。
 * @method static \Type\Orm\ModelQuery<static> query(string $alias = '')
 * @method static static|null find(int|string $id)
 * @method static static create(array $values)
 * @method static static firstOrCreate(array $identity, array $values = [])
 */
final class PlatformHandoff extends Model
{
    protected string $table = 'platform_handoff';

    protected string $pk = 'code_hash';

    protected bool $autoIncrement = false;

    protected ?string $createTime = 'created_at';

    protected array $schema = [
        'code_hash' => ['type' => 'string', 'visible' => false, 'comment' => '一次性交接码摘要，不保存原始交接码。'],
        'target_tenant_id' => ['type' => 'string', 'comment' => '交接目标客户；平台记录由来源平台会话管理。'],
        'user_id' => ['type' => 'string', 'comment' => '对应人员账号的稳定标识。'],
        'user_version' => ['type' => 'integer', 'comment' => '签发时目标账号的资料版本。'],
        'tenant_version' => ['type' => 'integer', 'comment' => '签发时目标客户的资料版本。'],
        'actor_id' => ['type' => 'string', 'comment' => '真实平台操作者的账号标识。'],
        'source_session_id' => ['type' => 'string', 'comment' => '来源平台会话标识，用于撤销后续模拟访问。'],
        'target_origin' => ['type' => 'string', 'comment' => '交接绑定的目标入口地址。'],
        'expires_at' => ['type' => 'integer', 'comment' => '有效截止时间，UTC Unix 秒。'],
        'consumed_at' => ['type' => 'integer', 'nullable' => true, 'comment' => '交接码消费时间，UTC Unix 秒；空值表示尚未消费。'],
        'created_at' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '创建时间，UTC Unix 秒。'],
    ];
}
