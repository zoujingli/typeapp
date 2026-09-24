<?php

declare(strict_types=1);

namespace Type\Queue;

/** 有限重试及指数退避策略；退避含随机抖动，不改变业务消息身份。 */
final class RetryPolicy
{
    private int $attempts;
    private int $base;
    private int $maximum;
    private int $execution;
    /**
     * 设置总尝试次数和执行/退避的毫秒预算；总次数包含首次投递。
     *
     * @throws QueueException 次数、延迟或执行预算越界。
     */
    public function __construct(int $maxAttempts = 3, int $baseDelayMilliseconds = 1000, int $maxDelayMilliseconds = 60000, int $maxExecutionMilliseconds = 30000)
    {
        if ($maxAttempts < 1 || $maxAttempts > 100 || $baseDelayMilliseconds < 1 || $maxDelayMilliseconds < $baseDelayMilliseconds
            || $maxDelayMilliseconds > 86400000 || $maxExecutionMilliseconds < 1 || $maxExecutionMilliseconds > 3600000) {
            throw new QueueException('invalid_retry_policy', '任务重试与执行上限无效');
        }
        $this->attempts = $maxAttempts;
        $this->base = $baseDelayMilliseconds;
        $this->maximum = $maxDelayMilliseconds;
        $this->execution = $maxExecutionMilliseconds;
    }
    /** 返回包括首次执行在内的总尝试次数上限。 */
    public function maximumAttempts(): int
    {
        return $this->attempts;
    }
    /** 返回每次任务作用域的执行预算，单位为毫秒。 */
    public function executionMilliseconds(): int
    {
        return $this->execution;
    }
    /**
     * 按当前尝试次数计算下次延迟，在指数上限的一半至上限内随机取值。
     *
     * @return int 至少 1 毫秒，且不超过最大退避时间。
     */
    public function delay(int $attempt): int
    {
        $cap = (int) min($this->maximum, $this->base * (2 ** min(30, max(0, $attempt - 1))));
        return random_int(max(1, intdiv($cap, 2)), $cap);
    }
}
