<?php

declare(strict_types=1);

namespace Type\Testing;

use Type\Runtime\Deadline;

/**
 * 数组参数直接启动进程，同时捕获双输出并限制保留字节与等待时间。
 *
 * Unix 使用非阻塞管道；Windows 使用独立读句柄的临时文件，避免无法 select
 * 或不能非阻塞读取的匿名管道。测试输出可能短暂落盘，不应用于记录生产密钥。
 */
final class Process
{
    private mixed $process = null;
    private array $pipes = [];
    private array $outputFiles = [];
    private string $stdout = '';
    private string $stderr = '';
    private bool $exceeded = false;
    private ?array $exit = null;
    private ?ProcessResult $result = null;
    private int $maximumBytes;

    /**
     * @param list<string> $command 原样传递的可执行文件及参数，不经过 shell。
     * @param array<string,string>|null $environment 显式子进程环境；null 继承当前环境。
     * @param string|null $stdinFile 以只读二进制方式连接标准输入的普通文件；相对调用方工作目录解析。null保持空输入。
     *                               调用者保持输入文件内容稳定直到进程结束；此接口不锁定文件，也不把内容加载到父进程内存。
     * @throws \InvalidArgumentException 命令、输出预算或输入文件无效；目录、设备和NUL路径不接受。
     * @throws \RuntimeException 无法准备输出或启动进程，已打开的临时文件会关闭。
     */
    public function __construct(array $command, ?string $directory = null, ?array $environment = null, int $maximumBytes = 2097152, ?string $stdinFile = null)
    {
        if ($command === [] || !array_is_list($command) || $command[0] === '' || $maximumBytes < 1 || $maximumBytes > 67108864) {
            throw new \InvalidArgumentException('测试进程命令或输出预算无效');
        }
        foreach ($command as $argument) {
            if (!is_string($argument) || str_contains($argument, "\0")) {
                throw new \InvalidArgumentException('进程参数必须为不含 NUL 的字符串');
            }
        }
        $this->maximumBytes = $maximumBytes;
        $windows = PHP_OS_FAMILY === 'Windows';
        $inputPath = $windows ? 'NUL' : '/dev/null';
        if ($stdinFile !== null) {
            if (str_contains($stdinFile, "\0")) {
                throw new \InvalidArgumentException('标准输入文件路径不能含NUL');
            }
            $resolvedInput = realpath($stdinFile);
            if ($resolvedInput === false || !is_file($resolvedInput) || !is_readable($resolvedInput)) {
                throw new \InvalidArgumentException('标准输入需要可读的普通文件');
            }
            $inputPath = (string) $resolvedInput;
        }
        $descriptors = [0 => ['file', $inputPath, 'rb'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $readers = [];
        try {
            if ($windows) {
                foreach ([1, 2] as $index) {
                    $file = tmpfile();
                    if ($file === false) {
                        throw new \RuntimeException('无法创建进程输出临时文件');
                    }
                    $this->outputFiles[$index] = $file;
                    $reader = fopen(stream_get_meta_data($file)['uri'], 'rb');
                    if ($reader === false) {
                        throw new \RuntimeException('无法创建独立的进程输出读取句柄');
                    }
                    $readers[$index] = $reader;
                    $descriptors[$index] = $file;
                }
            }
            $this->process = proc_open(
                $command,
                $descriptors,
                $this->pipes,
                $directory,
                $environment,
                ['bypass_shell' => true, 'create_process_group' => $windows]
            );
            if (!is_resource($this->process)) {
                throw new \RuntimeException('无法启动测试进程');
            }
            if ($windows) {
                $this->pipes = $readers;
            } else {
                foreach ($this->pipes as $pipe) {
                    stream_set_blocking($pipe, false);
                }
            }
        } catch (\Throwable $error) {
            foreach ($readers as $reader) {
                fclose($reader);
            }
            foreach ($this->outputFiles as $file) {
                fclose($file);
            }
            $this->outputFiles = [];
            throw $error;
        }
    }

    /** 同时排空可读输出；false 表示已观察到退出，不只是发送过停止请求。 */
    public function running(): bool
    {
        if ($this->result !== null) {
            return false;
        }
        $this->read();
        if ($this->exit === null) {
            $state = proc_get_status($this->process);
            if (!$state['running']) {
                $this->exit = $state;
            }
        }
        return $this->exit === null;
    }
    /** 返回目前已捕获的标准输出；字节总数受 maximumBytes 约束。 */
    public function stdout(): string
    {
        $this->read();
        return $this->stdout;
    }
    /** 返回目前已捕获的标准错误，不附加命令参数或环境值。 */
    public function stderr(): string
    {
        $this->read();
        return $this->stderr;
    }

    /** 等待正常退出；截止或输出超限会终止并确认退出，重复调用返回同一结果。 */
    public function wait(float $seconds = 10.0): ProcessResult
    {
        if (!is_finite($seconds) || $seconds < 0 || $seconds > 3600) {
            throw new \InvalidArgumentException('测试进程等待预算无效');
        }
        if ($this->result !== null) {
            return $this->result;
        }
        $deadline = new Deadline($seconds);
        while ($this->running()) {
            if ($this->exceeded || $deadline->expired()) {
                return $this->terminate(0.2, !$this->exceeded);
            }
            $this->pause();
        }
        return $this->finish(false);
    }

    /**
     * 先发 Unix SIGTERM 或 Windows CTRL_BREAK，预算后强制终止并确认退出。
     *
     * Windows 控制事件不可用时只能终止进程，其非零状态不会伪装成正常排空。
     */
    public function stop(float $graceSeconds = 1.0): ProcessResult
    {
        if (!is_finite($graceSeconds) || $graceSeconds < 0 || $graceSeconds > 60) {
            throw new \InvalidArgumentException('测试进程停止预算无效');
        }
        return $this->terminate($graceSeconds, false);
    }

    private function terminate(float $seconds, bool $timeout): ProcessResult
    {
        if ($this->result !== null) {
            return $this->result;
        }
        if ($this->running()) {
            if (PHP_OS_FAMILY !== 'Windows' || !function_exists('sapi_windows_generate_ctrl_event')
                || !sapi_windows_generate_ctrl_event(PHP_WINDOWS_EVENT_CTRL_BREAK, (int) proc_get_status($this->process)['pid'])) {
                proc_terminate($this->process, 15);
            }
        }
        $deadline = new Deadline($seconds);
        while ($this->running() && !$deadline->expired()) {
            $this->pause();
        }
        if ($this->running()) {
            proc_terminate($this->process, 9);
        }
        $killed = new Deadline(2.0);
        while ($this->running() && !$killed->expired()) {
            $this->pause();
        }
        if ($this->running()) {
            throw new \RuntimeException('测试进程未确认退出，仍需外部监督者处理');
        }
        return $this->finish($timeout);
    }

    /** 返回仍在运行的子进程PID，供操作系统资源观测；进程结束后返回null。 */
    public function pid(): ?int
    {
        return $this->running() ? (int) proc_get_status($this->process)['pid'] : null;
    }

    private function finish(bool $timeout): ProcessResult
    {
        $this->read(true);
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        } $this->pipes = [];
        foreach ($this->outputFiles as $file) {
            fclose($file);
        }
        $this->outputFiles = [];
        $closed = proc_close($this->process);
        $this->process = null;
        $signal = ($this->exit['signaled'] ?? false) ? (int) $this->exit['termsig'] : null;
        $code = $this->exit['exitcode'] ?? $closed;
        if ($code < 0 && $signal !== null) {
            $code = 128 + $signal;
        }
        $this->result = new ProcessResult($code, $this->stdout, $this->stderr, $timeout, $this->exceeded, $signal);
        return $this->result;
    }

    private function read(bool $finishing = false): void
    {
        foreach ($this->pipes as $index => $pipe) {
            $iterations = $finishing ? (int) ceil($this->maximumBytes / 8192) + 1 : 16;
            for ($read = 0; $read < $iterations; $read++) {
                $chunk = fread($pipe, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $remaining = max(0, $this->maximumBytes - strlen($this->stdout) - strlen($this->stderr));
                if (strlen($chunk) > $remaining) {
                    $this->exceeded = true;
                }
                if ($index === 1) {
                    $this->stdout .= substr($chunk, 0, $remaining);
                } else {
                    $this->stderr .= substr($chunk, 0, $remaining);
                }
            }
        }
    }

    private function pause(): void
    {
        $read = array_values($this->pipes);
        $write = [];
        $except = [];
        if ($read !== [] && PHP_OS_FAMILY !== 'Windows') {
            @stream_select($read, $write, $except, 0, 10000);
        } else {
            usleep(10000);
        }
    }
}
