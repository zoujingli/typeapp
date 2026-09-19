<?php

declare(strict_types=1);

namespace Type\Core;

/** 同步按注册顺序通知；异常中止当前分发并交给命令执行器处理。 */
final class Events
{
    private array $listeners = [];

    public function listen(string $event, Listener $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function dispatch(string $event, Configuration $configuration): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener->handle($event, $configuration);
        }
    }
}
