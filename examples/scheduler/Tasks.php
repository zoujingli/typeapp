<?php

declare(strict_types=1);

namespace TypeApp\SchedulerExample;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Type\Scheduler\Task;
use Type\Scheduler\TaskContext;
use Type\Runtime\ManagedResource;

/** 故障场景随消费者编译，验证清理失败停止有限补跑。 */
final class CleanupFailureTask implements Task, ManagedResource
{
    public function run(TaskContext $context): array
    {
        $context->scope()->open($this);
        return ['message' => '业务返回，资源尚未收尾'];
    }

    public function start(): void
    {
    }

    public function stop(): void
    {
        throw new \RuntimeException('controlled scheduler cleanup failure');
    }
}

final class ControlledClock implements ClockInterface
{
    private DateTimeImmutable $date;

    public function __construct(string $date)
    {
        $this->date = new DateTimeImmutable($date);
    }

    public function now(): DateTimeImmutable
    {
        return $this->date;
    }
    public function advance(int $seconds): void
    {
        $this->date = $this->date->modify('+' . $seconds . ' seconds');
    }
}

final class SummaryTask implements Task
{
    public function run(TaskContext $context): array
    {
        $context->scope()->assertActive();
        if (\Type\Runtime\ExecutionScope::current() !== $context->scope()) {
            throw new \RuntimeException('调度任务未绑定自己的作用域');
        }

        return ['message' => '计划任务已完成', 'scheduled_at' => $context->scheduledAt()->format('Y-m-d\TH:i:s\Z')];
    }
}

/** 测试入口随应用编译，用真实中断验证 running 的恢复规则。 */
final class InterruptedTask implements Task
{
    public function run(TaskContext $context): array
    {
        posix_kill((int) getmypid(), 9);
        throw new \RuntimeException('测试进程没有按预期中断');
    }
}
