<?php

declare(strict_types=1);

namespace Type\Scheduler;

/** 可编译的纯时间计划；仅计算 UTC 触发时刻，不持有任务或运行资源。 */
interface Schedule
{
    /** 返回 (after, through] 内最多 limit 个最新计划时刻，按 UTC 秒升序排列。 */
    public function occurrences(int $after, int $through, int $limit): array;

    /** 返回可写入历史的计划描述，含影响时刻计算的时间策略。 */
    public function description(): string;
}
