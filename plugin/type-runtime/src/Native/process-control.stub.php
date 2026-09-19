<?php

declare(strict_types=1);

/** @internal 仅供角色主控处理不可恢复收尾，以 75 结束整个进程，不运行 PHP/C++ 析构。 */
function type_runtime_native_control_fail_stop(): void
{
}

/** @internal 由同包C++实现；Windows控制台不可用或已注册时返回false。 */
function type_runtime_native_control_start(): bool
{
}

/** @internal 只读取原生标记，不从系统回调线程进入PHP。 */
function type_runtime_native_control_pending(): bool
{
}

/** @internal 只移除本模块的原生处理器，失败返回false。 */
function type_runtime_native_control_stop(): bool
{
}
