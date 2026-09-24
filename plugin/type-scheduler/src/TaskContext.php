<?php

declare(strict_types=1);

namespace Type\Scheduler;

use DateTimeImmutable;
use Type\Runtime\ExecutionScope;

/** 绑定一次计划的稳定身份、UTC 时刻、作用域与可选分布式租约。 */
final class TaskContext
{
    private string $taskId;
    private string $occurrenceId;
    private int $scheduledAt;
    private ExecutionScope $scope;
    private ?ExecutionLease $lease;

    /**
     * 根据任务 ID 与 UTC 计划秒派生稳定 occurrence ID；租约权限限于本作用域。
     *
     * @param int $scheduledAt 计划 UTC Unix 秒，不是实际开始时间。
     */
    public function __construct(string $taskId, int $scheduledAt, ExecutionScope $scope, ?ExecutionLease $lease = null)
    {
        $this->taskId = $taskId;
        $this->scheduledAt = $scheduledAt;
        $this->scope = $scope;
        $this->lease = $lease === null ? null : new ScopedLease($lease, $scope);
        $this->occurrenceId = hash('sha256', 'type-scheduler:1:' . $taskId . ':' . $scheduledAt);
    }

    /** 返回定义中跨部署保持稳定的业务任务 ID。 */
    public function taskId(): string
    {
        return $this->taskId;
    }
    /** 返回任务 ID 与计划秒生成的稳定摘要，修订版本不改变此身份。 */
    public function occurrenceId(): string
    {
        return $this->occurrenceId;
    }
    /** 返回 UTC 计划时刻；与任务实际开始时间分开记录。 */
    public function scheduledAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $this->scheduledAt);
    }
    /** 返回本次执行的独立作用域，由 Scheduler 在记录完成前收尾。 */
    public function scope(): ExecutionScope
    {
        return $this->scope;
    }
    /** 返回受本次作用域约束的租约；本地文件调度返回 null。 */
    public function lease(): ?ExecutionLease
    {
        return $this->lease;
    }

    /** 检查任务截止、取消及分布式持有权，不替代实际写入目标的 fencing。 */
    public function assertActive(): void
    {
        $this->scope->assertActive();
        $this->lease?->assertOwned();
    }

    /**
     * 延长有效的分布式租约，不延长任务作用域截止。
     *
     * @throws LeaseException 本地任务无可续租租约，或分布式租约已丢失。
     */
    public function renew(): void
    {
        $this->scope->assertActive();
        if ($this->lease === null) {
            throw new LeaseException('config', '本地执行没有需要续期的分布式租约');
        }
        $this->lease->renew();
    }
}
