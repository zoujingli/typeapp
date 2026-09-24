<?php

declare(strict_types=1);

namespace Type\Scheduler;

/** 额外向任务暴露分布式租约的状态存储，供检查点和受管效果验证。 */
interface LeasedStateStore extends StateStore
{
    /** 返回 acquire() 所取得的有效租约；尚未取得时必须失败。 */
    public function lease(): ExecutionLease;
}
