<?php

declare(strict_types=1);

namespace Type\Orm;

/** 不包含总数的分页结果；下一页标志来自同一次数据查询多取的一行。 */
final class SimplePage
{
    /** @internal 由查询入口传入本页结果、已验证页码和多取一行的判断。 */
    public function __construct(private array $items, private int $page, private int $perPage, private bool $more)
    {
    }

    /** 本页行或模型列表。 */
    public function items(): array
    {
        return $this->items;
    }

    /** 从一开始的页码。 */
    public function number(): int
    {
        return $this->page;
    }

    /** 请求的每页条数。 */
    public function perPage(): int
    {
        return $this->perPage;
    }

    /** 数据查询时是否存在下一行。 */
    public function hasMore(): bool
    {
        return $this->more;
    }
}
