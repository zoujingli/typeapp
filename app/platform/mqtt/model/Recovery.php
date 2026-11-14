<?php

declare(strict_types=1);

namespace app\platform\mqtt\model;

use Type\Orm\Model;

/**
 * Broker 接入事实的恢复核对进度
 * @property string $id 恢复操作的稳定标识
 * @property int|null $slot 当前活动槽位；空值表示历史记录
 * @property string $actor 发起恢复的维护者标识
 * @property string $proof 已完成隔离依据的受控引用
 * @property string $state 恢复阶段
 * @property int $kind_index 当前主体种类索引
 * @property string $cursor 当前主体种类内的有序游标
 * @property int $started_at 恢复开始时间，UTC Unix 秒
 * @property int|null $reviewed_at 最近一次核对时间，UTC Unix 秒；尚未核对为空
 * @property int|null $completed_at 恢复完成时间，UTC Unix 秒；未完成为空
 * @property string $review_sha256 当前核对清单摘要，不保存清单原文
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
final class Recovery extends Model
{
    protected string $table = 'platform_mqtt_recovery';
    protected string $pk = 'id';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = null;

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '恢复操作的稳定标识'],
        'slot' => ['type' => 'integer', 'nullable' => true, 'required' => false, 'comment' => '当前活动槽位；空值表示历史记录'],
        'actor' => ['type' => 'string', 'comment' => '发起恢复的维护者标识'],
        'proof' => ['type' => 'string', 'comment' => '已完成隔离依据的受控引用'],
        'state' => ['type' => 'string', 'comment' => '恢复阶段'],
        'kind_index' => ['type' => 'integer', 'comment' => '当前主体种类索引'],
        'cursor' => ['type' => 'string', 'comment' => '当前主体种类内的有序游标'],
        'started_at' => ['type' => 'integer', 'comment' => '恢复开始时间，UTC Unix 秒'],
        'reviewed_at' => ['type' => 'integer', 'nullable' => true, 'required' => false, 'comment' => '最近一次核对时间，UTC Unix 秒；尚未核对为空'],
        'completed_at' => ['type' => 'integer', 'nullable' => true, 'required' => false, 'comment' => '恢复完成时间，UTC Unix 秒；未完成为空'],
        'review_sha256' => ['type' => 'string', 'comment' => '当前核对清单摘要，不保存清单原文'],
    ];
}
