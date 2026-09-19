<?php

declare(strict_types=1);

namespace Type\Runtime;

use InvalidArgumentException;

final class QueryStringException extends InvalidArgumentException
{
    private string $field;
    private string $reason;
    private int $status;

    public function __construct(string $field, string $reason, int $status = 400)
    {
        parent::__construct('查询输入不明确：' . $reason);
        $this->field = $field;
        $this->reason = $reason;
        $this->status = $status;
    }
    public function field(): string
    {
        return $this->field;
    }
    public function reason(): string
    {
        return $this->reason;
    }
    public function status(): int
    {
        return $this->status;
    }
}
