<?php

declare(strict_types=1);

namespace Type\Core\Http;

use RuntimeException;

final class HttpError extends RuntimeException
{
    private int $status;
    private string $errorCode;
    public function __construct(int $status, string $errorCode)
    {
        parent::__construct($errorCode);
        $this->status = $status;
        $this->errorCode = $errorCode;
    }
    public function status(): int
    {
        return $this->status;
    }
    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
