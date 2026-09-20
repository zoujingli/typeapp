<?php

declare(strict_types=1);

namespace Type\Orm;

final class ModelException extends DatabaseException
{
    private string $errorCode;

    public function __construct(string $errorCode, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
