<?php

declare(strict_types=1);

namespace Type\Scheduler;

/** 每次计划新建的已编译任务；业务效果需要按 occurrence ID 实施幂等。 */
interface Task
{
    /** 返回可编码为 JSON 的结果；异常与资源清理失败均记录为失败。 */
    public function run(TaskContext $context): array;
}
