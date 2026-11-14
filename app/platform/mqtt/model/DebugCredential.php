<?php

declare(strict_types=1);

namespace app\platform\mqtt\model;

use Type\Orm\Model;

/**
 * 登录会话绑定的 MQTT 调试短期凭据，不进入接入快照
 * @property string $id 记录的稳定标识
 * @property string $principal_id 调试使用的临时主体，不代表正式设备或接入应用
 * @property string|null $tenant_id 调试所属 Tenant；平台调试可为空
 * @property string $actor_id 发起本次变更的真实操作者标识
 * @property string $actor_realm 操作者身份域，platform 或 system
 * @property string $session_id 绑定的登录会话
 * @property string|null $support_id 平台模拟登录的交接标识；普通调试为空
 * @property string $login 调试登录名
 * @property string $secret_hash 调试口令摘要，不保存明文
 * @property int $credential_version 调试凭据代次
 * @property string $client_id 调试连接使用的 Client ID
 * @property string $subscribe_topic 允许订阅的调试 Topic
 * @property string $publish_topic 允许发布的调试 Topic
 * @property string $status 调试凭据状态：active 或 revoked
 * @property int $expires_at 调试凭据失效时间，UTC Unix 秒
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
final class DebugCredential extends Model
{
    protected string $table = 'platform_mqtt_debug_credential';
    protected string $pk = 'id';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = null;

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '记录的稳定标识'],
        'principal_id' => ['type' => 'string', 'comment' => '调试使用的临时主体，不代表正式设备或接入应用'],
        'tenant_id' => ['type' => 'string', 'nullable' => true, 'required' => false, 'comment' => '调试所属 Tenant；平台调试可为空'],
        'actor_id' => ['type' => 'string', 'comment' => '发起本次变更的真实操作者标识'],
        'actor_realm' => ['type' => 'string', 'comment' => '操作者身份域，platform 或 system'],
        'session_id' => ['type' => 'string', 'comment' => '绑定的登录会话'],
        'support_id' => ['type' => 'string', 'nullable' => true, 'required' => false, 'comment' => '平台模拟登录的交接标识；普通调试为空'],
        'login' => ['type' => 'string', 'comment' => '调试登录名'],
        'secret_hash' => ['type' => 'string', 'comment' => '调试口令摘要，不保存明文'],
        'credential_version' => ['type' => 'integer', 'comment' => '调试凭据代次'],
        'client_id' => ['type' => 'string', 'comment' => '调试连接使用的 Client ID'],
        'subscribe_topic' => ['type' => 'string', 'comment' => '允许订阅的调试 Topic'],
        'publish_topic' => ['type' => 'string', 'comment' => '允许发布的调试 Topic'],
        'status' => ['type' => 'string', 'comment' => '调试凭据状态：active 或 revoked'],
        'expires_at' => ['type' => 'integer', 'comment' => '调试凭据失效时间，UTC Unix 秒'],
        'created_at' => ['type' => 'integer', 'comment' => '创建时间，UTC Unix 秒'],
        'revoked_at' => ['type' => 'integer', 'nullable' => true, 'required' => false, 'comment' => '吊销时间，UTC Unix 秒；未吊销为空'],
        'recovery_verified' => ['type' => 'integer', 'required' => false, 'comment' => '当前授权事实是否已完成恢复核对'],
    ];
}
