<?php

declare(strict_types=1);

namespace Type\Scheduler;

use DateTimeImmutable;
use Type\Runtime\ExecutionScope;

final class TaskContext
{
    private string $taskId;
    private string $occurrenceId;
    private int $scheduledAt;
    private ExecutionScope $scope;
    private ?ExecutionLease $lease;

    public function __construct(string $taskId, int $scheduledAt, ExecutionScope $scope, ?ExecutionLease $lease = null)
    {
        $this->taskId = $taskId;
        $this->scheduledAt = $scheduledAt;
        $this->scope = $scope;
        $this->lease = $lease === null ? null : new ScopedLease($lease, $scope);
        $this->occurrenceId = hash('sha256', 'type-scheduler:1:' . $taskId . ':' . $scheduledAt);
    }

    public function taskId(): string
    {
        return $this->taskId;
    }
    public function occurrenceId(): string
    {
        return $this->occurrenceId;
    }
    public function scheduledAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $this->scheduledAt);
    }
    public function scope(): ExecutionScope
    {
        return $this->scope;
    }
    public function lease(): ?ExecutionLease
    {
        return $this->lease;
    }

    public function assertActive(): void
    {
        $this->scope->assertActive();
        $this->lease?->assertOwned();
    }

    public function renew(): void
    {
        $this->scope->assertActive();
        if ($this->lease === null) {
            throw new LeaseException('config', '本地执行没有需要续期的分布式租约');
        }
        $this->lease->renew();
    }
}
