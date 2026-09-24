<?php

declare(strict_types=1);

namespace Type\Orm;

/** 一个模型批次及其全部嵌套关联共用行数预算，超限明确失败。 */
final class ReadBudget
{
    /** 为一批模型与关联声明 1 至 100000 行的读取预算，不参与写入限制。 */
    public function __construct(private int $remaining = 10000)
    {
        if ($remaining < 1 || $remaining > 100000) {
            throw new ModelException('invalid_read_budget', '模型读取预算必须在 1 至 100000 行之间');
        }
    }
    /** 返回尚未水合的行数额度；父模型、子模型和中间表共用。 */
    public function remaining(): int
    {
        return $this->remaining;
    }
    /** 先检查再扣减读取额度，超限明确抛错，不静默截断数据。 */
    public function consume(int $rows): void
    {
        if ($rows < 0 || $rows > $this->remaining) {
            throw new ModelException('read_budget_exceeded', '模型批次及其关系超过声明的行数预算');
        }
        $this->remaining -= $rows;
    }
}
