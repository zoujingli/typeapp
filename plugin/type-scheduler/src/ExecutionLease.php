<?php

declare(strict_types=1);

namespace Type\Scheduler;

/** 任务检查点的权限；代次需要由实际写入目标验证才具有 fencing 效果。 */
interface ExecutionLease
{
    /** 核对当前执行者仍持有权威租约；失效必须阻止后续受管写入。 */
    public function assertOwned(): void;
    /** 延长当前有效租约；不能恢复旧持有者或延长任务截止。 */
    public function renew(): void;
    /** 返回精确的代次文本；避免大代次经浮点数转换后失去精度。 */
    public function generation(): string;
}
