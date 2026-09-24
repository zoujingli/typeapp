<?php

declare(strict_types=1);

namespace Type\Runtime;

/** 由执行作用域拥有的资源；启动前登记，结束时按登记逆序收尾。 */
interface ManagedResource
{
    /** 初始化资源；部分启动失败也必须允许 stop() 执行清理。 */
    public function start(): void;

    /**
     * 即使 start 部分失败，也必须允许安全收尾；抛错后保留必要状态，允许所有者再次收尾。
     * 正常返回表示本资源已收尾，或在途资源已由既有池/原生完成回调继续持有。
     */
    public function stop(): void;
}
