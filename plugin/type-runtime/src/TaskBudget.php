<?php

declare(strict_types=1);

namespace Type\Runtime;

/** @internal 同一执行树共享容量，嵌套 spawn 不能绕过父级上限。 */
final class TaskBudget
{
    private int $capacity;
    private int $active = 0;
    /** 由作用域传入已确定的任务树总额度，同一任务树共享此实例。 */
    public function __construct(int $capacity)
    {
        $this->capacity = $capacity;
    }
    /** @throws TaskException 任务树额度耗尽；未增加 active，也不进入等待队列。 */
    public function acquire(): void
    {
        if ($this->active >= $this->capacity) {
            throw new TaskException('child_limit', '执行树子任务容量已满');
        }
        $this->active++;
    }
    /** 任务及其后代完全收尾后成对归还一次额度，由任务创建者负责调用。 */
    public function release(): void
    {
        $this->active--;
    }
    /** 返回仍在途的任务数，取消意图不会减少此计数。 */
    public function active(): int
    {
        return $this->active;
    }
}
