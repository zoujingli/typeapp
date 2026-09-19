<?php

declare(strict_types=1);

namespace Type\Scheduler;

interface Task
{
    /** 返回可编码为 JSON 的结果；异常与资源清理失败均记录为失败。 */
    public function run(TaskContext $context): array;
}
