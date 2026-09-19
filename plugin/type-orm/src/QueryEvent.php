<?php

declare(strict_types=1);

namespace Type\Orm;

/** 查询完成事实；SQL 与绑定是否可见由监听作用域显式决定。 */
final class QueryEvent
{
    /** @internal 接收已经脱敏和限制大小的查询完成数据。 */
    public function __construct(private array $data)
    {
    }

    /** 不可变的事件数据副本，不携带异常对象或数据库资源。 */
    public function toArray(): array
    {
        return $this->data;
    }
}
