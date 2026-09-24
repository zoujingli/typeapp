<?php

declare(strict_types=1);

namespace Type\Orm;

/** 单页游标结果，只保存已读取的数据和继续位置。 */
final class CursorPage
{
    /**
     * 保存已读取的一页结果与后续游标，不持有数据库连接。
     *
     * @param list<Model|array<string, mixed>> $items
     */
    public function __construct(private array $items, private ?string $next)
    {
    }
    /**
     * 取得本页模型或表查询行，不追加查询。
     *
     * @return list<Model|array<string, mixed>>
     */
    public function items(): array
    {
        return $this->items;
    }
    /** 返回继续相同查询的游标，null 表示没有后续页；游标不能替代授权。 */
    public function next(): ?string
    {
        return $this->next;
    }
    /** 按已生成的后续游标判断是否还有下一页。 */
    public function hasMore(): bool
    {
        return $this->next !== null;
    }
}
