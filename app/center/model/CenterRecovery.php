<?php

declare(strict_types=1);

namespace app\center\model;

use Type\Orm\Model;

/**
 * Center 恢复操作的单例进度状态；主体核对记录仍由 RecoveryService 按复合身份维护。
 *
 * 该模型只承载恢复运行的生命周期、批次游标和核对摘要，不把恢复状态误当作
 * 普通租户业务数据；slot=1 是当前唯一活动恢复操作的数据库互斥槽位。
 *
 * @property string $id 恢复操作的稳定标识。
 * @property null|int $slot 当前活动槽位，1 表示唯一活动恢复，空值表示历史记录。
 * @property string $actor 发起恢复操作的维护者标识。
 * @property string $proof 已完成隔离依据的受控引用。
 * @property string $phase 恢复阶段：isolating、reviewing、restoring 或 ready。
 * @property int $kind_index 当前主体种类索引。
 * @property string $cursor 当前主体种类内的有序游标。
 * @property int $started_at 恢复开始时间，UTC Unix 秒。
 * @property null|int $reviewed_at 最近一次核对批次时间，UTC Unix 秒。
 * @property null|int $completed_at 恢复完成时间，UTC Unix 秒。
 * @property string $review_sha256 当前核对清单摘要，不保存清单原文。
 * @method static \Type\Orm\ModelQuery<static> query(string $alias = '')
 * @method static static|null find(int|string $id)
 * @method static static create(array $values)
 */
final class CenterRecovery extends Model
{
    protected string $table = 'center_recovery';

    protected bool $autoIncrement = false;

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '恢复操作的稳定标识。'],
        'slot' => ['type' => 'integer', 'nullable' => true, 'comment' => '当前活动槽位，1 表示唯一活动恢复，空值表示历史记录。'],
        'actor' => ['type' => 'string', 'comment' => '发起恢复操作的维护者标识。'],
        'proof' => ['type' => 'string', 'visible' => false, 'comment' => '已完成隔离依据的受控引用。'],
        'phase' => ['type' => 'string', 'column' => 'state', 'comment' => '恢复阶段：isolating、reviewing、restoring 或 ready。'],
        'kind_index' => ['type' => 'integer', 'comment' => '当前主体种类索引。'],
        'cursor' => ['type' => 'string', 'comment' => '当前主体种类内的有序游标。'],
        'started_at' => ['type' => 'integer', 'comment' => '恢复开始时间，UTC Unix 秒。'],
        'reviewed_at' => ['type' => 'integer', 'nullable' => true, 'comment' => '最近一次核对批次时间，UTC Unix 秒。'],
        'completed_at' => ['type' => 'integer', 'nullable' => true, 'comment' => '恢复完成时间，UTC Unix 秒。'],
        'review_sha256' => ['type' => 'string', 'visible' => false, 'comment' => '当前核对清单摘要，不保存清单原文。'],
    ];
}
