<?php

declare(strict_types=1);

namespace Type\Core;

/** 当前执行作用域内的同步、强类型事件分发入口。 */
interface EventDispatcher
{
    /** 在当前执行作用域同步分发一个已由构建期登记的事件对象。 */
    public function dispatch(object $event): void;
}
