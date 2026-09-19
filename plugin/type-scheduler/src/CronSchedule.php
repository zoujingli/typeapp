<?php

declare(strict_types=1);

namespace Type\Scheduler;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** 在时区偏移恒定的区间内解析当地墙上时间，显式处理 DST 重叠与缺口。 */
final class CronSchedule implements Schedule
{
    private CronExpression $expression;
    private DateTimeZone $timezone;
    private string $overlap;

    public function __construct(string $expression, string $timezone = 'UTC', string $overlap = 'first')
    {
        if (!in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)
            || !in_array($overlap, ['first', 'both'], true)) {
            throw new InvalidArgumentException('TYPE_SCHEDULER_CONFIG：Cron 需要明确的 IANA 时区与 first/both 夏令时策略');
        }
        $this->expression = new CronExpression($expression);
        $this->timezone = new DateTimeZone($timezone);
        $this->overlap = $overlap;
    }

    public function occurrences(int $after, int $through, int $limit): array
    {
        if ($limit < 1 || $limit > 1000 || $through - $after > 31622400) {
            throw new InvalidArgumentException('TYPE_SCHEDULER_RANGE：Cron 查询最多跨越 366 天并返回 1000 个时刻');
        }
        if ($through <= $after) {
            return [];
        }
        $transitions = $this->timezone->getTransitions($after + 1, $through + 1);
        if ($transitions === false) {
            throw new InvalidArgumentException('TYPE_SCHEDULER_CONFIG：时区没有可用的转换规则');
        }
        $matches = [];
        foreach ($transitions as $index => $transition) {
            $offset = (int) $transition['offset'];
            $start = max($after + 1, (int) $transition['ts']);
            $end = isset($transitions[$index + 1]) ? (int) $transitions[$index + 1]['ts'] - 1 : $through;
            $wall = $end + $offset;
            for ($count = 0; $count < $limit; $count++) {
                $date = $this->expression->getPreviousRunDate(new DateTimeImmutable('@' . $wall), 0, true, 'UTC');
                $timestamp = $date->getTimestamp() - $offset;
                if ($timestamp < $start) {
                    break;
                }
                if ($this->overlap === 'both' || !$this->secondOverlap($timestamp)) {
                    $matches[$timestamp] = $timestamp;
                }
                $wall = $date->getTimestamp() - 60;
            }
        }
        sort($matches, SORT_NUMERIC);

        return array_slice($matches, -$limit);
    }

    public function description(): string
    {
        return 'cron:' . $this->expression->getExpression() . ':' . $this->timezone->getName() . ':gap-skip:overlap-' . $this->overlap;
    }

    private function secondOverlap(int $timestamp): bool
    {
        $transitions = $this->timezone->getTransitions($timestamp - 172800, $timestamp + 1);
        $previous = null;
        foreach ($transitions as $transition) {
            $offset = (int) $transition['offset'];
            if ($previous !== null && $previous > $offset && $timestamp < (int) $transition['ts'] + $previous - $offset) {
                return true;
            }
            $previous = $offset;
        }

        return false;
    }
}
