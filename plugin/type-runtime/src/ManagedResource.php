<?php

declare(strict_types=1);

namespace Type\Runtime;

interface ManagedResource
{
    public function start(): void;

    /**
     * 即使 start 部分失败，也必须允许安全收尾；抛错后保留必要状态，允许所有者再次收尾。
     * 正常返回表示本资源已收尾，或在途资源已由既有池/原生完成回调继续持有。
     */
    public function stop(): void;
}
