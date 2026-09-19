<?php

declare(strict_types=1);

namespace Type\Scheduler;

interface Schedule
{
    /** 返回 (after, through] 内最多 limit 个最新计划时刻，按 UTC 秒升序排列。 */
    public function occurrences(int $after, int $through, int $limit): array;

    public function description(): string;
}
