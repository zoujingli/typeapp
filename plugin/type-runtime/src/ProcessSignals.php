<?php

declare(strict_types=1);

namespace Type\Runtime;

use Closure;
use Throwable;

/**
 * 独占进程停止信号并恢复注册前状态；不决定排空策略，也不执行任何业务工作。
 *
 * Unix使用PCNTL；Windows CLI使用PHP控制台处理器，embed使用编译的控制事件桥。
 * Windows embed调用方须在等待循环中dispatch；只承诺CTRL_C/CTRL_BREAK，不把
 * 关闭窗口、注销或系统强制终止当作可保证完成的排空。一个进程只允许一个所有者。
 */
final class ProcessSignals
{
    private static int $owner = 0;
    private int $process;
    private bool $attached = false;
    private bool $delivered = false;
    private bool $native = false;
    private bool $windows = false;
    private bool $asynchronous = false;
    private array $previous = [];
    private ?Closure $callback = null;
    private ?Closure $windowsHandler = null;

    /** 所有权绑定创建进程；不能把已注册对象用于fork后的子进程。 */
    public function __construct()
    {
        $this->process = (int) getmypid();
    }

    /**
     * @param Closure():void $callback 至多调用一次的停止通知，不接收系统数据。
     * @throws TaskException 无信号能力、存在其他所有者或无法完成注册。
     */
    public function attach(Closure $callback): void
    {
        $this->assertProcess();
        if ($this->attached || self::$owner !== 0) {
            throw new TaskException('signal_owner_busy', '进程停止信号已有所有者');
        }
        self::$owner = spl_object_id($this);
        $this->attached = true;
        $this->delivered = false;
        $this->callback = $callback;
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                // 读取真正的运行SAPI，不能把构建CLI的常量当成embed的运行模式。
                if (php_sapi_name() === 'cli' && function_exists('sapi_windows_set_ctrl_handler')) {
                    $this->windowsHandler = function (int $event): void {
                        $this->notify();
                    };
                    if (!sapi_windows_set_ctrl_handler($this->windowsHandler, true)) {
                        throw new TaskException('signals_unavailable', '无法注册Windows控制台停止处理器');
                    }
                    $this->windows = true;
                } elseif (function_exists('type_runtime_native_control_start') && function_exists('type_runtime_native_control_pending')
                    && function_exists('type_runtime_native_control_stop') && \type_runtime_native_control_start()) {
                    $this->native = true;
                } else {
                    throw new TaskException('signals_unavailable', 'Windows embed需要原生控制事件桥和已连接的控制台；嵌入宿主可显式接管stop');
                }
                return;
            }
            if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals') || !function_exists('pcntl_signal_get_handler')) {
                throw new TaskException('signals_unavailable', 'Unix停止信号需要PCNTL；嵌入宿主可显式接管stop');
            }
            $this->asynchronous = pcntl_async_signals();
            foreach ([SIGINT, SIGTERM] as $signal) {
                $this->previous[$signal] = pcntl_signal_get_handler($signal);
                if (!pcntl_signal($signal, function (int $received, mixed $information): void {
                    $this->notify();
                })) {
                    throw new TaskException('signals_unavailable', '无法注册Unix停止信号');
                }
            }
            pcntl_async_signals(true);
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    /** 在应用线程分发Windows原生停止标记；重复调用不重复通知。 */
    public function dispatch(): void
    {
        $this->assertProcess();
        if ($this->attached && $this->native && \type_runtime_native_control_pending()) {
            $this->notify();
        }
    }

    /** 幂等释放本对象的注册；恢复失败会明确报告，不能伪装为正常停止。 */
    public function close(): void
    {
        $this->assertProcess();
        if (!$this->attached) {
            return;
        }
        $failed = false;
        foreach ($this->previous as $signal => $handler) {
            if (!pcntl_signal((int) $signal, $handler)) {
                $failed = true;
            }
        }
        if ($this->previous !== []) {
            pcntl_async_signals($this->asynchronous);
        }
        if ($this->windows) {
            if (!sapi_windows_set_ctrl_handler($this->windowsHandler, false)) {
                $failed = true;
            }
            // PHP CLI的全局callback槽须一并释放，避免保留停止所有者对象。
            if (!sapi_windows_set_ctrl_handler(null, false)) {
                $failed = true;
            }
        }
        if ($this->native && !\type_runtime_native_control_stop()) {
            $failed = true;
        }
        $this->previous = [];
        $this->windowsHandler = null;
        $this->callback = null;
        $this->windows = false;
        $this->native = false;
        $this->attached = false;
        self::$owner = 0;
        if ($failed) {
            throw new TaskException('signals_cleanup_failed', '无法完整恢复进程停止处理器，需要监督者回收进程');
        }
    }

    private function notify(): void
    {
        $this->assertProcess();
        if (!$this->attached || $this->delivered) {
            return;
        }
        $this->delivered = true;
        $callback = $this->callback;
        if ($callback !== null) {
            $callback();
        }
    }

    private function assertProcess(): void
    {
        if ($this->process !== (int) getmypid()) {
            throw new TaskException('wrong_process', '停止信号所有者不能跨进程复用');
        }
    }
}
