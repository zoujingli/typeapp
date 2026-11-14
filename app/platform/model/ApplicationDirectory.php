<?php

declare(strict_types=1);

namespace app\platform\model;

use Type\Orm\Model;

/**
 * Platform 跨 Tenant 只读的接入应用目录。
 *
 * 目录查询由平台权限和请求筛选共同限定，故意不声明 tenantKey；应用创建、
 * 修改和通信授权仍必须使用带可信 Tenant 的 ClientApplication 模型与业务服务。
 *
 * @property string $id 接入应用的稳定标识。
 * @property string $tenant_id 所属 Tenant 标识，仅用于平台目录投影。
 * @property string $name 业务显示名称。
 * @property bool $enabled 当前是否启用。
 * @property int $key_version 接入应用密钥代次。
 * @property int $access_version 通信授权发布代次。
 * @property string $credential_id 当前接入凭据的稳定标识，不进入公开投影。
 * @property string $request_hash 创建请求内容摘要，不进入公开投影。
 * @property bool $access_started 是否已发出有效认证结论。
 * @property bool $recovery_verified 当前授权事实是否已完成恢复核对。
 * @property-read int $version 资料并发版本。
 * @property-read int $created_at 创建时间，UTC Unix 秒。
 * @property-read int $updated_at 最后更新时间，UTC Unix 秒。
 * @method static \Type\Orm\ModelQuery<static> query(string $alias = '')
 * @method static static|null find(int|string $id)
 */
final class ApplicationDirectory extends Model
{
    protected string $table = 'center_application';

    protected bool $autoIncrement = false;

    /** 平台目录按全局接入应用读取，不能自动从 tenant_id 字段推导租户范围。 */
    protected ?string $tenantKey = null;

    protected array $schema = [
        'id' => ['type' => 'string', 'fillable' => false, 'comment' => '接入应用的稳定标识。'],
        'tenant_id' => ['type' => 'string', 'fillable' => false, 'comment' => '所属 Tenant 标识，仅用于平台目录投影。'],
        'name' => ['type' => 'string', 'fillable' => false, 'comment' => '业务显示名称。'],
        'enabled' => ['type' => 'boolean', 'fillable' => false, 'comment' => '当前是否启用。'],
        'key_version' => ['type' => 'integer', 'fillable' => false, 'comment' => '接入应用密钥代次。'],
        'access_version' => ['type' => 'integer', 'fillable' => false, 'comment' => '通信授权发布代次。'],
        'credential_id' => ['type' => 'string', 'visible' => false, 'fillable' => false, 'comment' => '当前接入凭据的稳定标识。'],
        'request_hash' => ['type' => 'string', 'visible' => false, 'fillable' => false, 'comment' => '创建请求内容摘要。'],
        'access_started' => ['type' => 'boolean', 'fillable' => false, 'comment' => '是否已发出有效认证结论。'],
        'recovery_verified' => ['type' => 'boolean', 'fillable' => false, 'comment' => '当前授权事实是否已完成恢复核对。'],
        'version' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '资料并发版本。'],
        'created_at' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '创建时间，UTC Unix 秒。'],
        'updated_at' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '最后更新时间，UTC Unix 秒。'],
    ];
}
