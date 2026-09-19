<?php

declare(strict_types=1);

namespace Type\Runtime;

use InvalidArgumentException;
use Swoole\Coroutine\Channel;

final class Deadline
{
    private ?float $end;
    private array $listeners = [];
    private int $sequence = 0;
    public function __construct(?float $seconds = null)
    {
        if ($seconds !== null && (!is_finite($seconds) || $seconds < 0)) {
            throw new InvalidArgumentException('截止预算无效');
        }
        $this->end = $seconds === null ? null : self::now() + $seconds;
    }
    public function remaining(): ?float
    {
        return $this->end === null ? null : max(0.0, $this->end - self::now());
    }
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
    private static function now(): float
    {
        return hrtime(true) / 1000000000.0;
    }
}
