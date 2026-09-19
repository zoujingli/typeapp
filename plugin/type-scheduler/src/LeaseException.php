<?php

declare(strict_types=1);

namespace Type\Scheduler;

final class LeaseException extends \RuntimeException
{
    private string $reason;

    public function __construct(string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct('TYPE_SCHEDULER_' . strtoupper($reason) . '：' . $message, 0, $previous);
        $this->reason = $reason;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
