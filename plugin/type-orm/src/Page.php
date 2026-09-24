<?php

declare(strict_types=1);

namespace Type\Orm;

/** 单次分页结果；COUNT 与数据读取是否同一快照取决于调用者的事务。 */
final class Page
{
    /**
     * 保存当前页和独立计数结果，页码从 1 开始，perPage 必须为正。
     *
     * @param list<Model|array<string, mixed>> $items
     */
    public function __construct(private array $items, private int $total, private int $page, private int $perPage)
    {
    }
    /**
     * 取得当前页的模型或查询行，不追加读取。
     *
     * @return list<Model|array<string, mixed>>
     */
    public function items(): array
    {
        return $this->items;
    }
    /** 返回 COUNT 已观察到的总数，不保证读取后数据库未变化。 */
    public function total(): int
    {
        return $this->total;
    }
    /** 返回从 1 开始的当前页码。 */
    public function number(): int
    {
        return $this->page;
    }
    /** 返回声明的每页条数，最后一页实际结果可以更少。 */
    public function perPage(): int
    {
        return $this->perPage;
    }
    /** 按总数与页大小计算末页，空结果仍使用第 1 页。 */
    public function lastPage(): int
    {
        return max(1, intdiv($this->total, $this->perPage) + ($this->total % $this->perPage === 0 ? 0 : 1));
    }
    /** 按计数结果判断当前页之后是否还有页。 */
    public function hasMore(): bool
    {
        return $this->page < $this->lastPage();
    }
}
