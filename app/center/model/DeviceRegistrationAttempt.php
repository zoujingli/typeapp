<?php

declare(strict_types=1);

namespace app\center\model;

use Type\Orm\Model;

/**
 * 公开设备注册入口的来源限流窗口；记录短期计数，不承载设备或租户业务状态。
 *
 * @property string $source_hash 已规范化客户端来源的 SHA-256 摘要。
 * @property int $window_started 当前限流窗口开始时间，UTC Unix 秒。
 * @property int $attempts 当前来源在窗口内的尝试次数。
 * @method static \Type\Orm\ModelQuery<static> query(string $alias = '')
 * @method static static|null find(int|string $id)
 * @method static static create(array $values)
 */
final class DeviceRegistrationAttempt extends Model
{
    protected string $table = 'center_registration_attempt';

    protected bool $autoIncrement = false;

    protected string $pk = 'source_hash';

    protected array $schema = [
        'source_hash' => ['type' => 'string', 'comment' => '已规范化客户端来源的 SHA-256 摘要。'],
        'window_started' => ['type' => 'integer', 'comment' => '当前限流窗口开始时间，UTC Unix 秒。'],
        'attempts' => ['type' => 'integer', 'comment' => '当前来源在窗口内的尝试次数。'],
    ];
}
