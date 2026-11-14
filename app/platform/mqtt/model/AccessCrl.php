<?php

declare(strict_types=1);

namespace app\platform\mqtt\model;

use Type\Orm\Model;

/**
 * 受信 CA 已接受的证书吊销清单
 * @property string $ca_id 对应受信 CA
 * @property string $pem 已接受的 CRL PEM
 * @property int $this_update CRL 本次更新时间，UTC Unix 秒
 * @property int $next_update CRL 下次更新时间，UTC Unix 秒
 * @property string $url CRL 分发地址
 * @property int $fetch_interval 拉取间隔，秒
 * @property string $fetch_status 最近一次拉取状态
 * @property string $fetch_error 最近一次拉取失败原因；成功时为空
 * @property int $fetched_at 最近一次拉取时间，UTC Unix 秒
 * @property int $accepted_at 最近一次接受 CRL 的时间，UTC Unix 秒
 * @property int $fetch_due_at 下次应拉取时间，UTC Unix 秒
 * @property int $configured 是否已配置 CRL 分发
 * @property int $updated_at 最后更新时间，UTC Unix 秒
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
final class AccessCrl extends Model
{
    protected string $table = 'platform_mqtt_access_crl';
    protected string $pk = 'ca_id';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = null;

    protected array $schema = [
        'ca_id' => ['type' => 'string', 'comment' => '对应受信 CA'],
        'pem' => ['type' => 'string', 'comment' => '已接受的 CRL PEM'],
        'this_update' => ['type' => 'integer', 'comment' => 'CRL 本次更新时间，UTC Unix 秒'],
        'next_update' => ['type' => 'integer', 'comment' => 'CRL 下次更新时间，UTC Unix 秒'],
        'url' => ['type' => 'string', 'comment' => 'CRL 分发地址'],
        'fetch_interval' => ['type' => 'integer', 'comment' => '拉取间隔，秒'],
        'fetch_status' => ['type' => 'string', 'comment' => '最近一次拉取状态'],
        'fetch_error' => ['type' => 'string', 'comment' => '最近一次拉取失败原因；成功时为空'],
        'fetched_at' => ['type' => 'integer', 'comment' => '最近一次拉取时间，UTC Unix 秒'],
        'accepted_at' => ['type' => 'integer', 'comment' => '最近一次接受 CRL 的时间，UTC Unix 秒'],
        'fetch_due_at' => ['type' => 'integer', 'comment' => '下次应拉取时间，UTC Unix 秒'],
        'configured' => ['type' => 'integer', 'comment' => '是否已配置 CRL 分发'],
        'updated_at' => ['type' => 'integer', 'comment' => '最后更新时间，UTC Unix 秒'],
    ];
}
