<?php

declare(strict_types=1);

namespace Type\Queue;

final class RetryPolicy
{
    private int $attempts;
    private int $base;
    private int $maximum;
    private int $execution;
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
    public function maximumAttempts(): int
    {
        return $this->attempts;
    }
    public function executionMilliseconds(): int
    {
        return $this->execution;
    }
    public function delay(int $attempt): int
    {
        $cap = (int) min($this->maximum, $this->base * (2 ** min(30, max(0, $attempt - 1))));
        return random_int(max(1, intdiv($cap, 2)), $cap);
    }
}
