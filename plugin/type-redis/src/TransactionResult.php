<?php

declare(strict_types=1);

namespace Type\Redis;

/** WATCH/MULTI/EXEC 的确认结果，冲突与未知结果使用不同路径表达。 */
final class TransactionResult
{
    private bool $committed;
    private array $replies;

    /**
     * 保留 EXEC 的已知结果；WATCH 冲突使用 false 与空响应列表。
     *
     * @param list<mixed> $replies
     */
    public function __construct(bool $committed, array $replies)
    {
        $this->committed = $committed;
        $this->replies = $replies;
    }

    /** 表示 EXEC 已得到成功响应；false 表示 WATCH 冲突且未执行命令。 */
    public function committed(): bool
    {
        return $this->committed;
    }
    /**
     * 按入队顺序取得各命令响应；不把响应解释成数据库式回滚证明。
     *
     * @return list<mixed>
     */
    public function replies(): array
    {
        return $this->replies;
    }
}
