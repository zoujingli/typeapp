<?php

declare(strict_types=1);

namespace app\platform\mqtt\model;

use Type\Orm\Model;

/**
 * MQTT 主体的口令凭据及代次
 * @property string $id 凭据稳定标识
 * @property string $principal_id 所属 MQTT 主体
 * @property string $login 规范化登录名，设备与接入应用分域
 * @property string $secret_hash 口令摘要，不保存明文
 * @property int $credential_version 凭据代次，换钥后递增
 * @property string $status 凭据状态：active 或 revoked
 * @property int $created_at 创建时间，UTC Unix 秒
 * @property int|null $revoked_at 吊销时间，UTC Unix 秒；未吊销为空
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
final class AccessCredential extends Model
{
    protected string $table = 'platform_mqtt_access_credential';
    protected string $pk = 'id';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = null;

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '凭据稳定标识'],
        'principal_id' => ['type' => 'string', 'comment' => '所属 MQTT 主体'],
        'login' => ['type' => 'string', 'comment' => '规范化登录名，设备与接入应用分域'],
        'secret_hash' => ['type' => 'string', 'comment' => '口令摘要，不保存明文'],
        'credential_version' => ['type' => 'integer', 'comment' => '凭据代次，换钥后递增'],
        'status' => ['type' => 'string', 'comment' => '凭据状态：active 或 revoked'],
        'created_at' => ['type' => 'integer', 'comment' => '创建时间，UTC Unix 秒'],
        'revoked_at' => ['type' => 'integer', 'nullable' => true, 'required' => false, 'comment' => '吊销时间，UTC Unix 秒；未吊销为空'],
        'recovery_verified' => ['type' => 'integer', 'required' => false, 'comment' => '当前授权事实是否已完成恢复核对'],
    ];
}
