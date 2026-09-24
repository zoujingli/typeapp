<?php

declare(strict_types=1);

namespace Type\Runtime;

use Closure;
use Throwable;

/** 同一线程内广播取消意图；监听者只唤醒等待，不释放仍在使用的资源。 */
final class Cancellation
{
    private bool $cancelled = false;
    private array $listeners = [];
    private int $sequence = 0;

    /** 所有已登记监听者均会得到通知，重复取消没有副作用。 */
    public function cancel(): void
    {
        if ($this->cancelled) {
            return;
        }
        $this->cancelled = true;
        $listeners = $this->listeners;
        $this->listeners = [];
        $failure = null;
        foreach ($listeners as $listener) {
            try {
                $listener();
            } catch (Throwable $error) {
                $failure ??= $error;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /** @param Closure(): void $listener 取消时唤醒；已经取消则立即调用。 */
    public function subscribe(Closure $listener): int
    {
        $id = ++$this->sequence;
        if ($this->cancelled) {
            $listener();
        } else {
            $this->listeners[$id] = $listener;
        }
        return $id;
    }

    /** 等待结束后撤销通知，避免闭包与作用域长期相互持有。 */
    public function unsubscribe(int $id): void
    {
        unset($this->listeners[$id]);
    }

    /** 读取取消意图；true 不代表在途操作或资源清理已经完成。 */
    public function cancelled(): bool
    {
        return $this->cancelled;
    }
}
