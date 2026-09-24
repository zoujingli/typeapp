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
    /**
     * 登记故意清理失败的资源后返回，验证业务返回不能冒充调度完成。
     *
     * @return array{message: string}
     */
    public function run(TaskContext $context): array
    {
        $context->scope()->open($this);
        return ['message' => '业务返回，资源尚未收尾'];
    }

    /** 不分配外部资源，使故障集中发生在清理阶段。 */
    public function start(): void
    {
    }

    /**
     * 故意抛出清理错误，验证调度器保留未完成的资源状态。
     *
     * @throws \RuntimeException 每次调用均失败。
     */
    public function stop(): void
    {
        throw new \RuntimeException('controlled scheduler cleanup failure');
    }
}

/** 可手动推进的示例时钟，用于固定计划、回拨与补跑窗口。 */
final class ControlledClock implements ClockInterface
{
    private DateTimeImmutable $date;

    /** 解析显式时间文本，不读取当前系统时间替代测试输入。 */
    public function __construct(string $date)
    {
        $this->date = new DateTimeImmutable($date);
    }

    /** 返回当前受控时间，不推进时钟。 */
    public function now(): DateTimeImmutable
    {
        return $this->date;
    }
    /** 按给定秒数推进受控时间，供连续 tick 使用。 */
    public function advance(int $seconds): void
    {
        $this->date = $this->date->modify('+' . $seconds . ' seconds');
    }
}

/** 返回稳定计划身份和时间的示例任务，检查当前作用域绑定。 */
final class SummaryTask implements Task
{
    /**
     * 检查任务 Scope 归属并返回当前 occurrence 与计划时刻。
     *
     * @return array<string, mixed> 可持久记录的计划摘要。
     */
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
    /** 故意以 SIGKILL 终止演练进程，留下 running 记录供新执行者识别中断。 */
    public function run(TaskContext $context): array
    {
        posix_kill((int) getmypid(), 9);
        throw new \RuntimeException('测试进程没有按预期中断');
    }
}
