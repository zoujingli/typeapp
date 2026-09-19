<?php

declare(strict_types=1);

namespace Type\Runtime;

final class CompatibilityException extends \RuntimeException
{
    private string $reason;
    public function __construct(string $reason, string $message)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }
    public function reason(): string
    {
        return $this->reason;
    }
}
