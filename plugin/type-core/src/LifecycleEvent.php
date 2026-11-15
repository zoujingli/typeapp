<?php

declare(strict_types=1);

namespace Type\Core;

/** 命令生命周期事件名称；只允许构建期登记的固定事件，避免自由字符串扩散。 */
final class LifecycleEvent
{
    /** 命令资源打开后、业务命令执行前触发。 */
    public const READY = 'ready';

    /** 业务命令成功返回后触发。 */
    public const COMPLETED = 'completed';

    private function __construct() {}
}
