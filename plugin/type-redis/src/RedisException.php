<?php

declare(strict_types=1);

namespace Type\Redis;

use RuntimeException;
use Throwable;

final class RedisException extends RuntimeException
{
    private string $errorCode;
    private string $outcome;

    public function __construct(string $errorCode, string $outcome, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
        $this->outcome = $outcome;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
    public function outcome(): string
    {
        return $this->outcome;
    }
}
