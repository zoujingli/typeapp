<?php

declare(strict_types=1);

namespace Type\Runtime;

use Closure;
use InvalidArgumentException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Timer;
use Throwable;

/** 单调截止及其通知；观察者共用一个 Swoole Timer，缩短预算不会延长已有任务。 */
final class Deadline
{
    private ?float $end;
    private array $listeners = [];
    private array $changeListeners = [];
    private int $sequence = 0;
    private ?int $timer = null;
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
        $this->armTimer();
        $this->notifyWatchers();
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

    /**
     * @internal 在已有协程中观察缩短及真实到期；有限截止的观察者共用一个原生定时器。
     * 已到期则立即通知；回调可能同步唤醒其他协程，须校验自身是否仍拥有等待。
     * @param Closure(): void $listener 不传系统数据的截止通知。
     */
    public function watch(Closure $listener): int
    {
        $id = ++$this->sequence;
        if ($this->expired()) {
            $listener();
        } else {
            $this->changeListeners[$id] = $listener;
            try {
                if ($this->timer === null) {
                    $this->armTimer();
                }
            } catch (Throwable $error) {
                unset($this->changeListeners[$id]);
                throw $error;
            }
        }
        return $id;
    }

    /** @internal 等待或作用域结束后撤销自己的截止监听，避免长期相互持有。 */
    public function unwatch(int $id): void
    {
        unset($this->changeListeners[$id]);
        if ($this->changeListeners === [] && $this->timer !== null) {
            Timer::clear($this->timer);
            $this->timer = null;
        }
    }

    /** 按原生范围分段唤醒并复核单调截止，覆盖过早唤醒与超长预算。 */
    private function armTimer(): void
    {
        if ($this->timer !== null) {
            Timer::clear($this->timer);
            $this->timer = null;
        }
        $remaining = $this->remaining();
        if ($remaining !== null && $remaining <= 0) {
            $this->notifyWatchers();
            return;
        }
        if ($remaining === null || $this->changeListeners === []
            || !extension_loaded('swoole') || Coroutine::getCid() < 0) {
            return;
        }
        // 整数上限转为浮点数可能向上舍入；半范围也为原生绝对触发时间保留空间。
        $delay = max(1, (int) min(ceil($remaining * 1000.0), \SWOOLE_TIMER_MAX_MS / 2.0));
        $this->timer = Timer::after($delay, function (): void {
            $this->timer = null;
            $this->armTimer();
        });
    }

    /** 到期后释放观察引用；一个取消监听失败不能跳过同一截止的其他所有者。 */
    private function notifyWatchers(): void
    {
        $listeners = $this->changeListeners;
        if ($this->expired()) {
            $this->changeListeners = [];
        }
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

    private static function now(): float
    {
        return hrtime(true) / 1000000000.0;
    }
}
