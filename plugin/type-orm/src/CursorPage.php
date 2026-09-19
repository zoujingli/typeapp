<?php

declare(strict_types=1);

namespace Type\Orm;

final class CursorPage
{
    public function __construct(private array $items, private ?string $next)
    {
    }
    public function items(): array
    {
        return $this->items;
    }
    public function next(): ?string
    {
        return $this->next;
    }
    public function hasMore(): bool
    {
        return $this->next !== null;
    }
}
