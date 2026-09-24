<?php

declare(strict_types=1);

namespace Type\Scheduler;

/** 区分租约竞争、失权、目标不匹配与未知效果，保留原始失败链。 */
final class LeaseException extends \RuntimeException
{
    private string $reason;

    /** 将稳定原因组成 TYPE_SCHEDULER 错误前缀，并保留底层异常。 */
    public function __construct(string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct('TYPE_SCHEDULER_' . strtoupper($reason) . '：' . $message, 0, $previous);
        $this->reason = $reason;
    }

    /** 返回可供分支处理的稳定原因；busy 与存储故障应分别处理。 */
    public function reason(): string
    {
        return $this->reason;
    }
}
