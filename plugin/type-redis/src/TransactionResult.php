<?php

declare(strict_types=1);

namespace Type\Redis;

final class TransactionResult
{
    private bool $committed;
    private array $replies;

    public function __construct(bool $committed, array $replies)
    {
        $this->committed = $committed;
        $this->replies = $replies;
    }

    public function committed(): bool
    {
        return $this->committed;
    }
    public function replies(): array
    {
        return $this->replies;
    }
}
