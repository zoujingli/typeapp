<?php

declare(strict_types=1);

namespace Type\Scheduler;

use Closure;
use InvalidArgumentException;

/** 工厂在每次任务作用域内调用；注册定义不接受 shell、文件名或可调用字符串。 */
final class Definition
{
    private string $id;
    private Schedule $schedule;
    private Closure $factory;
    private string $misfire;
    private int $catchUpLimit;
    private int $lookbackSeconds;
    private int $graceSeconds;
    private string $revision;

    /** @param Closure(TaskContext): Task $factory 始终接收本次调度上下文。 */
    public function __construct(
        string $id,
        Schedule $schedule,
        Closure $factory,
        string $misfire = 'skip',
        int $catchUpLimit = 1,
        int $lookbackSeconds = 3600,
        int $graceSeconds = 59,
        string $revision = ''
    ) {
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}$/D', $id) || !in_array($misfire, ['skip', 'catch-up'], true)
            || $catchUpLimit < 1 || $catchUpLimit > 1000 || $lookbackSeconds < 1 || $lookbackSeconds > 31622400
            || $graceSeconds < 0 || $graceSeconds > $lookbackSeconds || strlen($revision) > 200) {
            throw new InvalidArgumentException('TYPE_SCHEDULER_CONFIG：任务身份、错过策略或补跑边界无效');
        }
        $this->id = $id;
        $this->schedule = $schedule;
        $this->factory = $factory;
        $this->misfire = $misfire;
        $this->catchUpLimit = $catchUpLimit;
        $this->lookbackSeconds = $lookbackSeconds;
        $this->graceSeconds = $graceSeconds;
        $this->revision = $revision;
    }

    public function id(): string
    {
        return $this->id;
    }
    public function schedule(): Schedule
    {
        return $this->schedule;
    }
    public function revision(): string
    {
        return $this->revision;
    }

    public function create(TaskContext $context): Task
    {
        $task = ($this->factory)($context);
        if (!$task instanceof Task) {
            throw new InvalidArgumentException('TYPE_SCHEDULER_FACTORY：任务工厂必须返回 Task');
        }

        return $task;
    }

    public function due(?int $cursor, int $now): array
    {
        $window = $this->misfire === 'skip' ? (int) $this->graceSeconds : (int) $this->lookbackSeconds;
        $after = max($cursor ?? PHP_INT_MIN, $now - $window - ($this->misfire === 'skip' ? 1 : 0));

        return $this->schedule->occurrences($after, $now, $this->misfire === 'skip' ? 1 : (int) $this->catchUpLimit);
    }
}
