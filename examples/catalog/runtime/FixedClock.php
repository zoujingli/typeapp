<?php

declare(strict_types=1);

namespace app\catalog\runtime;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/** 显式演练时钟只固定计划时刻；执行截止仍使用运行时单调时钟。 */
final class FixedClock implements ClockInterface
{
    /** 由启动配置传入 UTC 秒数，零值由调用方选择真实 SystemClock。 */
    public function __construct(private int $timestamp)
    {
    }

    /** 每次进程恢复观察相同计划时刻，用于证明持久游标不重复调度。 */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $this->timestamp);
    }
}
