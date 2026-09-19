<?php

declare(strict_types=1);

namespace Type\Runtime;

use Swoole\Coroutine\Channel;

/** 一个线程请求内多个身份及轮换代次共用已分配额度，隔离租约仍占用。 */
final class ResourceBudget
{
    private int $capacity;
    private int $used = 0;
    private int $rejected = 0;
    private ExecutionOwner $owner;
    private array $listeners = [];
    private int $sequence = 0;
    public function __construct(int $capacity)
    {
        if ($capacity < 1 || $capacity > 1000000) {
            throw new \InvalidArgumentException('共享资源预算无效');
        }
        $this->capacity = $capacity;
        $this->owner = new ExecutionOwner(false);
    }
    public function acquire(): void
    {
        if (!$this->tryAcquire()) {
            $this->rejected++;
            throw new CapacityException('共享资源预算已满');
        }
    }
    /** 池等待中的容量探测不计为业务拒绝；成功即保留额度。 */
    public function tryAcquire(): bool
    {
        $this->owner->assertCurrent();
        if ($this->used >= $this->capacity) {
            return false;
        }
        $this->used++;
        return true;
    }

    /** 只有原生资源关闭完成后调用；同时唤醒共享此额度的其他池。 */
    public function release(): void
    {
        $this->owner->assertCurrent();
        if ($this->used <= 0) {
            throw new \LogicException('共享资源预算重复归还');
        }
        $this->used--;
        $listeners = $this->listeners;
        foreach ($listeners as $listener) {
            if (!$listener->isFull()) {
                $listener->push(true);
            }
        }
    }

    /** @internal 每个等待者只登记一次，由池在 finally 撤销。 */
    public function subscribe(Channel $signal): int
    {
        $this->owner->assertCurrent();
        $id = ++$this->sequence;
        $this->listeners[$id] = $signal;
        return $id;
    }

    /** @internal 等待结束后移除唤醒登记。 */
    public function unsubscribe(int $id): void
    {
        $this->owner->assertCurrent();
        unset($this->listeners[$id]);
    }

    public function statistics(): array
    {
        $this->owner->assertCurrent();
        return ['capacity' => $this->capacity, 'allocated' => $this->used, 'rejected' => $this->rejected, 'waiters' => count($this->listeners)];
    }
}
