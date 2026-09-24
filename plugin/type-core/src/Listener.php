<?php

declare(strict_types=1);

namespace Type\Core;

/** 同步事件监听者，依登记顺序参与应用生命周期。 */
interface Listener
{
    /** 处理一次生命周期通知；异常会中止后续监听并交由命令执行器处理。 */
    public function handle(string $event, Configuration $configuration): void;
}
