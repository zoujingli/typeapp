<?php

declare(strict_types=1);

namespace Type\Orm;

/** 单次分页结果；COUNT 与数据读取是否同一快照取决于调用者的事务。 */
final class Page
{
    public function __construct(private array $items, private int $total, private int $page, private int $perPage)
    {
    }
    public function items(): array
    {
        return $this->items;
    }
    public function total(): int
    {
        return $this->total;
    }
    public function number(): int
    {
        return $this->page;
    }
    public function perPage(): int
    {
        return $this->perPage;
    }
    public function lastPage(): int
    {
        return max(1, intdiv($this->total, $this->perPage) + ($this->total % $this->perPage === 0 ? 0 : 1));
    }
    public function hasMore(): bool
    {
        return $this->page < $this->lastPage();
    }
}
