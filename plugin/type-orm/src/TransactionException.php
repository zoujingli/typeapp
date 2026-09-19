<?php

declare(strict_types=1);

namespace Type\Orm;

use Throwable;

class TransactionException extends DatabaseException
{
    private string $outcome;

    public function __construct(string $outcome, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->outcome = $outcome;
    }

    public function outcome(): string
    {
        return $this->outcome;
    }
}
