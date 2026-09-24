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

    /** 返回跨版本保持稳定的业务任务身份；不同业务任务应使用不同 ID。 */
    public function id(): string
    {
        return $this->id;
    }
    /** 返回本定义的纯时间计划，不执行任务工厂。 */
    public function schedule(): Schedule
    {
        return $this->schedule;
    }
    /** 返回历史展示用修订标识；修订不改变 occurrence 身份。 */
    public function revision(): string
    {
        return $this->revision;
    }

    /**
     * 在调用方已建立的任务作用域内调用工厂，不加载外部 PHP 文件。
     *
     * @throws InvalidArgumentException 工厂未返回 Task；工厂自身异常直接传播。
     */
    public function create(TaskContext $context): Task
    {
        $task = ($this->factory)($context);
        if (!$task instanceof Task) {
            throw new InvalidArgumentException('TYPE_SCHEDULER_FACTORY：任务工厂必须返回 Task');
        }

        return $task;
    }

    /**
     * 结合持久游标与错过策略计算本轮应执行时刻；首次调用也受回看窗口限制。
     *
     * @param int|null $cursor 已推进至的 UTC Unix 秒；null 表示无历史游标。
     * @param int $now 本轮时钟的 UTC Unix 秒。
     * @return list<int> 按时间升序的有限计划时刻。
     */
    public function due(?int $cursor, int $now): array
    {
        $window = $this->misfire === 'skip' ? (int) $this->graceSeconds : (int) $this->lookbackSeconds;
        $after = max($cursor ?? PHP_INT_MIN, $now - $window - ($this->misfire === 'skip' ? 1 : 0));

        return $this->schedule->occurrences($after, $now, $this->misfire === 'skip' ? 1 : (int) $this->catchUpLimit);
    }
}
