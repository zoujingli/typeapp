<?php

declare(strict_types=1);

namespace Type\Runtime;

/** @internal 同一执行树共享容量，嵌套 spawn 不能绕过父级上限。 */
final class TaskBudget
{
    private int $capacity;
    private int $active = 0;
    public function __construct(int $capacity)
    {
        $this->capacity = $capacity;
    }
    public function acquire(): void
    {
        if ($this->active >= $this->capacity) {
            throw new TaskException('child_limit', '执行树子任务容量已满');
        }
        $this->active++;
    }
    public function release(): void
    {
        $this->active--;
    }
    public function active(): int
    {
        return $this->active;
    }
}
