<?php

declare(strict_types=1);

namespace app\platform\mqtt\model;

use Type\Orm\Model;

/**
 * MQTT 客户端证书的受信 CA
 * @property string $id 记录的稳定标识
 * @property string|null $tenant_id 所属 Tenant；平台共用 CA 为空
 * @property string $fingerprint 证书指纹
 * @property string $subject 证书主体可分辨名
 * @property string $pem CA 证书 PEM，不包含私钥
 * @property int $created_at 创建时间，UTC Unix 秒
 * @property int $updated_at 最后更新时间，UTC Unix 秒
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
final class AccessCa extends Model
{
    protected string $table = 'platform_mqtt_access_ca';
    protected string $pk = 'id';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = null;

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '记录的稳定标识'],
        'tenant_id' => ['type' => 'string', 'nullable' => true, 'required' => false, 'comment' => '所属 Tenant；平台共用 CA 为空'],
        'fingerprint' => ['type' => 'string', 'comment' => '证书指纹'],
        'subject' => ['type' => 'string', 'comment' => '证书主体可分辨名'],
        'pem' => ['type' => 'string', 'comment' => 'CA 证书 PEM，不包含私钥'],
        'created_at' => ['type' => 'integer', 'comment' => '创建时间，UTC Unix 秒'],
        'updated_at' => ['type' => 'integer', 'comment' => '最后更新时间，UTC Unix 秒'],
        'recovery_verified' => ['type' => 'integer', 'required' => false, 'comment' => '当前授权事实是否已完成恢复核对'],
    ];
}
