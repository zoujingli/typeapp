<?php

declare(strict_types=1);

namespace Type\Runtime;

use Closure;
use InvalidArgumentException;
use Swoole\Coroutine\Channel;

/** 基于单调时钟共享剩余秒数；缩短预算不会延长已有任务的截止。 */
final class Deadline
{
    private ?float $end;
    private array $listeners = [];
    private array $changeListeners = [];
    private int $sequence = 0;
    /**
     * 从当前时刻建立截止，null 表示无限制，0 表示立即到期。
     * @throws InvalidArgumentException 秒数为负数或非有限值。
     */
    public function __construct(?float $seconds = null)
    {
        if ($seconds !== null && (!is_finite($seconds) || $seconds < 0)) {
            throw new InvalidArgumentException('截止预算无效');
        }
        $this->end = $seconds === null ? null : self::now() + $seconds;
    }
    /** 返回非负剩余秒数；null 表示未设置业务截止。 */
    public function remaining(): ?float
    {
        return $this->end === null ? null : max(0.0, $this->end - self::now());
    }
    /** 检查当前单调时钟是否到期；未设置截止时始终为 false。 */
    public function expired(): bool
    {
        return $this->end !== null && $this->end <= self::now();
    }
    /** 停止时只缩短既有预算；子任务共享同一个截止对象。 */
    public function shorten(float $seconds): void
    {
        if (!is_finite($seconds) || $seconds < 0) {
            throw new InvalidArgumentException('截止预算无效');
        }
        $end = self::now() + $seconds;
        if ($this->end !== null && $this->end <= $end) {
            return;
        }
        $this->end = $end;
        $listeners = $this->listeners;
        foreach ($listeners as $listener) {
            if (!$listener->isFull()) {
                $listener->push(true);
            }
        }
        foreach ($this->changeListeners as $listener) {
            $listener();
        }
    }

    /** @internal 角色排空缩短截止时，唤醒池等待以重算原生等待时间。 */
    public function subscribe(Channel $signal): int
    {
        $id = ++$this->sequence;
        $this->listeners[$id] = $signal;
        return $id;
    }

    /** @internal 借用完成、到期或取消后撤销登记。 */
    public function unsubscribe(int $id): void
    {
        unset($this->listeners[$id]);
    }

    /** @internal 作用域用于在截止缩短时重置自己的唤醒计时器。 */
    public function watch(Closure $listener): int
    {
        $id = ++$this->sequence;
        if ($this->expired()) {
            $listener();
        } else {
            $this->changeListeners[$id] = $listener;
        }
        return $id;
    }

    /** @internal 作用域关闭后撤销截止监听，避免父子作用域相互持有。 */
    public function unwatch(int $id): void
    {
        unset($this->changeListeners[$id]);
    }
    private static function now(): float
    {
        return hrtime(true) / 1000000000.0;
    }
}
