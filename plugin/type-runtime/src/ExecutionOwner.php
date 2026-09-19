<?php

declare(strict_types=1);

namespace Type\Runtime;

use Fiber;
use RuntimeException;

/** 资源归属线程请求；租约还区分该线程内的 Fiber 与 Swoole 协程。 */
final class ExecutionOwner
{
    private static ?string $generation = null;
    private int $processId;
    private int $threadId;
    private string $requestId;
    private int $coroutineId;
    private ?Fiber $fiber;
    private bool $executor;

    /** 池和预算传 false，允许同一线程请求内的不同执行者使用。 */
    public function __construct(bool $executor = true)
    {
        $this->processId = (int) getmypid();
        $this->threadId = self::currentThreadId();
        $this->requestId = self::requestId();
        $this->executor = $executor;
        $this->coroutineId = $executor ? self::currentCoroutineId() : -1;
        $this->fiber = $executor ? Fiber::getCurrent() : null;
    }

    /** 线程重建后即使系统重用了线程编号，也不能接受旧归属。 */
    public function assertCurrent(): void
    {
        if ($this->processId !== (int) getmypid() || $this->threadId !== self::currentThreadId() || $this->requestId !== self::requestId()
            || ($this->executor && ($this->coroutineId !== self::currentCoroutineId() || $this->fiber !== Fiber::getCurrent()))) {
            throw new RuntimeException('资源只能由所属进程、线程请求与执行者使用');
        }
    }

    private static function currentThreadId(): int
    {
        return class_exists(\Swoole\Thread::class, false) ? \Swoole\Thread::getNativeId() : 0;
    }

    private static function requestId(): string
    {
        self::$generation ??= bin2hex(random_bytes(16));
        return self::$generation;
    }

    private static function currentCoroutineId(): int
    {
        return extension_loaded('swoole') ? \Swoole\Coroutine::getCid() : -1;
    }
}
