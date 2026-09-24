<?php

declare(strict_types=1);

namespace Type\Orm;

/** 显式模型事件观察者；只能取消前置事件，后置事件不撤销已发生的写入。 */
interface ModelObserver
{
    /** 前置事件返回 false 取消写入，后置事件的返回值不撤销已执行步骤。 */
    public function onEvent(string $event, Model $model): bool;
}
