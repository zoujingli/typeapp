<?php

declare(strict_types=1);

namespace Type\Scheduler;

/** 任务检查点的权限；代次需要由实际写入目标验证才具有 fencing 效果。 */
interface ExecutionLease
{
    public function assertOwned(): void;
    public function renew(): void;
    public function generation(): string;
}
