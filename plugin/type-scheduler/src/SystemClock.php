<?php

declare(strict_types=1);

namespace Type\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/** 以 UTC 不可变时间提供 PSR-20 业务时钟，调度时刻精度由计划定义。 */
final class SystemClock implements ClockInterface
{
    /** 读取当前 UTC 时间，不参与单调执行截止的计时。 */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
