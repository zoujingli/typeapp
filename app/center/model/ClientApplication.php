<?php

declare(strict_types=1);

namespace app\center\model;

use Type\Orm\Model;

/**
 * 客户外部系统的独立身份；人员会话不参与密钥有效性，当前凭据代次由应用单独约束。
 *
 * @property string $id 记录的稳定标识。
 * @property-read string $tenant_id 所属 Tenant 标识，由可信执行上下文约束。
 * @property string $name 业务显示名称。
 * @property bool $enabled 当前是否启用。
 * @property int $key_version 接入应用密钥代次。
 * @property int $access_version 通信授权发布代次。
 * @property string $credential_id 当前接入凭据的稳定标识。
 * @property string $request_hash 创建请求内容摘要，用于拒绝幂等标识冲突。
 * @property bool $access_started 已发出有效认证结论，不表示连接在线；防止遗留持久会话被误判为从未使用。
 * @property bool $recovery_verified 当前授权事实是否已完成恢复核对。
 * @property-read int $version 资料并发版本。
 * @property-read int $created_at 创建时间，UTC Unix 秒。
 * @property-read int $updated_at 最后更新时间，UTC Unix 秒。
 * @method static \Type\Orm\ModelQuery<static> query(string $alias = '')
 * @method static static|null find(int|string $id)
 * @method static static create(array $values)
 * @method static static firstOrCreate(array $identity, array $values = [])
 */
final class ClientApplication extends Model
{
    protected string $table = 'center_application';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = 'tenant_id';

    protected ?string $versionKey = 'version';

    protected ?string $createTime = 'created_at';

    protected ?string $updateTime = 'updated_at';

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '记录的稳定标识。'],
        'tenant_id' => ['type' => 'string', 'comment' => '所属 Tenant 标识，由可信执行上下文约束。'],
        'name' => ['type' => 'string', 'comment' => '业务显示名称。'],
        'enabled' => ['type' => 'boolean', 'comment' => '当前是否启用。'],
        'key_version' => ['type' => 'integer', 'comment' => '接入应用密钥代次。'],
        'access_version' => ['type' => 'integer', 'comment' => '通信授权发布代次。'],
        'credential_id' => ['type' => 'string', 'visible' => false, 'comment' => '当前接入凭据的稳定标识。'],
        'request_hash' => ['type' => 'string', 'visible' => false, 'comment' => '创建请求内容摘要，用于拒绝幂等标识冲突。'],
        'access_started' => ['type' => 'boolean', 'visible' => false, 'comment' => '已发出有效认证结论，不表示连接在线；防止遗留持久会话被误判为从未使用。'],
        'recovery_verified' => ['type' => 'boolean', 'comment' => '当前授权事实是否已完成恢复核对。'],
        'version' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '资料并发版本。'],
        'created_at' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '创建时间，UTC Unix 秒。'],
        'updated_at' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '最后更新时间，UTC Unix 秒。'],
    ];
}
