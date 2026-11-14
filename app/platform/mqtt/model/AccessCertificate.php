<?php

declare(strict_types=1);

namespace app\platform\mqtt\model;

use Type\Orm\Model;

/**
 * 绑定到 MQTT 主体的客户端证书
 * @property string $id 记录的稳定标识
 * @property string $principal_id 证书所属 MQTT 主体
 * @property string $fingerprint 客户端证书指纹
 * @property string $subject 客户端证书主体可分辨名
 * @property string $pem 客户端证书 PEM，不包含私钥
 * @property int $not_after 证书失效时间，UTC Unix 秒
 * @property int $certificate_version 证书代次，换证后递增
 * @property string $status 证书状态：active 或 revoked
 * @property int $created_at 创建时间，UTC Unix 秒
 * @property int|null $revoked_at 吊销时间，UTC Unix 秒；未吊销为空
 * @property int $overlap_until 换证重叠截止时间，UTC Unix 秒；0 表示不允许旧证继续使用
 * @property string $serial 证书序列号，用于对照吊销清单
 * @property int $recovery_verified 当前授权事实是否已完成恢复核对
 * @method static \Type\Orm\ModelQuery<static> query(string $alias = '')
 * @method static \Type\Orm\ModelQuery<static> where(string|array $field, mixed $operator = null, mixed $value = null, string $boolean = 'AND')
 * @method static \Type\Orm\ModelQuery<static> order(string $field, string $direction = 'ASC')
 * @method static \Type\Orm\ModelQuery<static> limit(int $count, int $offset = 0)
 * @method static \Type\Orm\ModelQuery<static> with(string $name, ?\Closure $relation = null)
 * @method static static|null find(int|string $id)
 * @method static bool destroy(int|string $id)
 * @method static static create(array $values)
 * @method static static firstOrCreate(array $identity, array $values = [])
 * @method static \Type\Orm\Helper\QueryHelper search(array $input = [], string $alias = '')
 */
final class AccessCertificate extends Model
{
    protected string $table = 'platform_mqtt_access_certificate';
    protected string $pk = 'id';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = null;

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '记录的稳定标识'],
        'principal_id' => ['type' => 'string', 'comment' => '证书所属 MQTT 主体'],
        'fingerprint' => ['type' => 'string', 'comment' => '客户端证书指纹'],
        'subject' => ['type' => 'string', 'comment' => '客户端证书主体可分辨名'],
        'pem' => ['type' => 'string', 'comment' => '客户端证书 PEM，不包含私钥'],
        'not_after' => ['type' => 'integer', 'comment' => '证书失效时间，UTC Unix 秒'],
        'certificate_version' => ['type' => 'integer', 'comment' => '证书代次，换证后递增'],
        'status' => ['type' => 'string', 'comment' => '证书状态：active 或 revoked'],
        'created_at' => ['type' => 'integer', 'comment' => '创建时间，UTC Unix 秒'],
        'revoked_at' => ['type' => 'integer', 'nullable' => true, 'required' => false, 'comment' => '吊销时间，UTC Unix 秒；未吊销为空'],
        'overlap_until' => ['type' => 'integer', 'required' => false, 'comment' => '换证重叠截止时间，UTC Unix 秒；0 表示不允许旧证继续使用'],
        'serial' => ['type' => 'string', 'required' => false, 'comment' => '证书序列号，用于对照吊销清单'],
        'recovery_verified' => ['type' => 'integer', 'required' => false, 'comment' => '当前授权事实是否已完成恢复核对'],
    ];
}
