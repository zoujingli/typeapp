<?php

declare(strict_types=1);

namespace Type\Scheduler;

use InvalidArgumentException;

/** 相对于固定 UTC 锚点的间隔，不从进程启动或上次结束时间漂移。 */
final class IntervalSchedule implements Schedule
{
    private int $seconds;
    private int $anchor;

    /**
     * 设置固定间隔秒数与 Unix UTC 秒锚点，重启不重新计时。
     *
     * @throws InvalidArgumentException 间隔不在 1 秒至 366 天，或锚点早于 Unix 纪元。
     */
    public function __construct(int $seconds, int $anchor = 0)
    {
        if ($seconds < 1 || $seconds > 31622400 || $anchor < 0) {
            throw new InvalidArgumentException('TYPE_SCHEDULER_CONFIG：间隔必须为 1 秒至 366 天，锚点不能早于 Unix 纪元');
        }
        $this->seconds = $seconds;
        $this->anchor = $anchor;
    }

    /**
     * 选取 (after, through] 内最新的有限计划时刻，再按时间升序返回。
     *
     * @return list<int> UTC Unix 秒；空区间或仍早于锚点时返回空列表。
     * @throws InvalidArgumentException limit 不在 1 至 1000。
     */
    public function occurrences(int $after, int $through, int $limit): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('TYPE_SCHEDULER_RANGE：单次最多返回 1000 个时刻');
        }
        if ($through <= $after || $through < $this->anchor) {
            return [];
        }
        $last = $this->anchor + intdiv($through - $this->anchor, $this->seconds) * $this->seconds;
        $result = [];
        while ($last > $after && $last >= $this->anchor && count($result) < $limit) {
            $result[] = $last;
            $last -= $this->seconds;
        }

        return array_reverse($result);
    }

    /** 返回包含秒数和固定锚点的计划标识，用于执行历史。 */
    public function description(): string
    {
        return 'interval:' . $this->seconds . ':anchor:' . $this->anchor;
    }
}
