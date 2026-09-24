<?php

declare(strict_types=1);

namespace Type\Core;

/** 同步按注册顺序通知；异常中止当前分发并交给命令执行器处理。 */
final class Events
{
    private array $listeners = [];

    /** 追加同步监听者，顺序即触发顺序；重复登记会重复调用。 */
    public function listen(string $event, Listener $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    /** 通知指定事件的全部监听者；无监听者时无操作，异常直接传播。 */
    public function dispatch(string $event, Configuration $configuration): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener->handle($event, $configuration);
        }
    }
}
