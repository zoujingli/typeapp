<?php

declare(strict_types=1);

namespace Type\Queue;

final class QueueException extends \RuntimeException
{
    private string $errorCode;
    public function __construct(string $errorCode, string $message)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }
    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
