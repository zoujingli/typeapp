<?php

declare(strict_types=1);

namespace Type\Runtime;

/** 资源必须在 reset/close 返回前完成对应原生操作，不将关闭意图当作完成。 */
interface ReusableResource
{
    /** 返回是否已经恢复为可复用状态，失败时由池丢弃。 */
    public function reset(): bool;

    /** 幂等关闭底层句柄；未确认关闭时抛出异常，池继续保留隔离额度。 */
    public function close(): void;
}
