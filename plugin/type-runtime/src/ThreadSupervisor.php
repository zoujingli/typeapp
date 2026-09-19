<?php

declare(strict_types=1);

namespace Type\Runtime;

use InvalidArgumentException;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;
use Swoole\Event;
use Swoole\Thread;
use Swoole\Thread\Map;
use Swoole\Timer;
use Throwable;

/**
 * 独占角色主线程，监督有限的编译业务线程组；线程与事件循环仍由 Swoole 拥有。
 *
 * 入口从 Thread::getArguments()[2] 取得原生 Map：主控只写 stop，业务只写 ready、pulse、failed。
 * ready/failed 为 bool，pulse 为递增的单调时钟纳秒 int；固定四个标量键，不存业务消息或资源。
 * 进度只证明线程仍能前进，不证明每项异步 I/O 已经完成；业务仍须拥有操作截止与资源结算。
 */
final class ThreadSupervisor
{
    private ExecutionOwner $owner;
    private ProcessSignals $signals;
    private array $threads = [];
    private array $exits = [];
    private ?Deadline $drain = null;
    private ?Throwable $failure = null;
    private ?int $timer = null;
    private bool $ioSupervised = false;
    private string $state = 'new';

    /** 各期限单位为秒；停止期限涵盖业务排空、PHP 请求关闭及最终 C++ TLS 析构。 */
    public function __construct(
        private int $maximumThreads,
        private float $startupSeconds = 10.0,
        private float $progressSeconds = 5.0,
        private float $stopSeconds = 10.0,
    ) {
        if ($maximumThreads < 1 || $maximumThreads > 256) {
            throw new InvalidArgumentException('业务线程组容量必须在 1–256 之间');
        }
        foreach ([$startupSeconds, $progressSeconds, $stopSeconds] as $seconds) {
            if (!is_finite($seconds) || $seconds < 0.1 || $seconds > 60.0) {
                throw new InvalidArgumentException('业务线程监督期限必须在 0.1–60 秒之间');
            }
        }
        $this->owner = new ExecutionOwner(false);
        $this->signals = new ProcessSignals();
    }

    /**
     * 启动一次有限线程组，持有全部句柄直至真实 join；停止后不自动重启。
     *
     * 调用方独占主线程的事件循环，不能在协程、业务线程或已有其他线程的进程中调用。
     * Socket 仍由调用方拥有；各业务入口负责关闭自己的副本，不传递业务 PHP 对象。
     * 收到停止后不再创建后续槽位；启动失败也先收尾已启动线程，再抛出原错误。
     * 无法在停止期限内回收时以原生 _Exit(75) 结束角色进程，由既有部署管理器决定重启。
     * @param list<string> $payloads 按槽位复制的启动数据，单项上限沿用 startThread。
     * @return array<int, int> 实际启动槽位的退出码，仅在正常停止且全部回收后返回。
     * @throws TaskException 能力不足、入口意外退出或线程进度超时；所有可回收句柄已 join。
     * @throws Throwable 启动或信号恢复失败；已启动句柄同样先回收。
     */
    public function run(string $entry, array $payloads, ?Socket $listener = null): array
    {
        $this->owner->assertCurrent();
        CoroutineRuntime::assertAvailable();
        if ($this->state !== 'new' || !class_exists(Thread::class, false) || !Thread::getInfo()['is_main_thread']
            || Thread::activeCount() !== 1 || Coroutine::getCid() >= 0) {
            throw new TaskException('thread_supervisor_owner', '线程监督需要独占角色主线程并且只能运行一次');
        }
        if (!defined('Swoole\\Thread::TYPEAPP_JOIN_ABI') || constant('Swoole\\Thread::TYPEAPP_JOIN_ABI') !== 1
            || !defined('Swoole\\Thread::TYPEAPP_CONTROL_ARGUMENT_ABI') || constant('Swoole\\Thread::TYPEAPP_CONTROL_ARGUMENT_ABI') !== 1
            || !function_exists('type_runtime_native_control_fail_stop') || php_sapi_name() !== 'embed') {
            throw new TaskException('thread_supervisor_unavailable', '线程监督需要编译运行时、原生控制状态与有截止的完成等待');
        }
        if (!array_is_list($payloads) || count($payloads) < 1 || count($payloads) > $this->maximumThreads) {
            throw new InvalidArgumentException('启动数据必须是容量范围内的非空列表');
        }
        foreach ($payloads as $payload) {
            if (!is_string($payload)) {
                throw new InvalidArgumentException('每个业务线程的启动数据必须是字符串');
            }
        }
        CoroutineRuntime::enableIo();
        $this->ioSupervised = defined('SWOOLE_FILE_IO_ABI');
        if ($this->ioSupervised && (constant('SWOOLE_FILE_IO_ABI') !== 2 || !method_exists(Coroutine::class, 'typeappSuperviseIo'))) {
            throw new TaskException('thread_io_supervisor_unavailable', '文件候选需要由角色主控监督真实原生操作截止');
        }
        $this->state = 'running';
        try {
            $this->signals->attach(function (): void {
                $this->stop();
            });
            $timer = Timer::tick(20, function (int $timerId): void {
                try {
                    $this->poll();
                } catch (Throwable $error) {
                    // 控制回调自身损坏时不能异常展开到 Thread 析构，那里仍可能无界 join。
                    \type_runtime_native_control_fail_stop();
                }
            });
            if ($timer === false) {
                throw new TaskException('thread_supervisor_timer', '无法启动业务线程监督定时器');
            }
            $this->timer = $timer;
            if ($this->ioSupervised && !Coroutine::typeappSuperviseIo()) {
                throw new TaskException('thread_io_supervisor_owner', '原生 I/O 监督必须在首次提交和业务线程启动前独占主控事件循环');
            }
            try {
                foreach ($payloads as $slot => $payload) {
                    if ($this->drain !== null) {
                        break;
                    }
                    $control = new Map(['stop' => false, 'ready' => false, 'pulse' => 0, 'failed' => false]);
                    $started = hrtime(true);
                    $thread = CoroutineRuntime::startThread($entry, $payload, $listener, $control);
                    $this->threads[$slot] = ['thread' => $thread, 'control' => $control, 'started' => $started,
                        'pulse' => 0, 'progress' => $started, 'ready' => false];
                    if ($this->drain !== null) {
                        $control['stop'] = true;
                    }
                }
            } catch (Throwable $error) {
                $this->failure = $error;
                $this->stop();
            }
            $this->poll();
            Event::wait();
        } finally {
            if ($this->threads !== []) {
                \type_runtime_native_control_fail_stop();
            }
            $this->clearTimer();
            $this->state = 'stopped';
            $this->signals->close();
        }
        if ($this->failure !== null) {
            throw $this->failure;
        }
        ksort($this->exits);
        return $this->exits;
    }

    /** 幂等停止整组；只缩短生命周期，不 detach、不释放未完成句柄或伪造线程额度。 */
    public function stop(): void
    {
        $this->owner->assertCurrent();
        if ($this->state === 'new' || $this->state === 'stopped') {
            return;
        }
        $this->state = 'stopping';
        $this->drain ??= new Deadline($this->stopSeconds);
        foreach ($this->threads as $record) {
            // 先取出原生对象，保证 TypePHP 对外层 Map 写入走 offsetSet，而非嵌套 item 的临时读取值。
            $control = $record['control'];
            $control['stop'] = true;
        }
    }

    /** @return array{state: string, owned: int, ready: int, joined: int} 有限聚合值，不暴露句柄或控制 Map。 */
    public function statistics(): array
    {
        $this->owner->assertCurrent();
        $ready = 0;
        foreach ($this->threads as $record) {
            if ($this->state === 'running' && $record['control']['ready'] === true) {
                $ready++;
            }
        }
        return ['state' => $this->state, 'owned' => count($this->threads), 'ready' => $ready, 'joined' => count($this->exits)];
    }

    private function poll(): void
    {
        $this->owner->assertCurrent();
        $this->signals->dispatch();
        if ($this->ioSupervised && !Coroutine::typeappSuperviseIo()) {
            // 真实提交超过硬截止，即使该线程的其他协程和心跳仍能前进也不能放行。
            \type_runtime_native_control_fail_stop();
        }
        $now = hrtime(true);
        foreach ($this->threads as $slot => $record) {
            $thread = $record['thread'];
            if ($thread->joinWithin(0)) {
                $exit = $thread->getExitStatus();
                $this->exits[$slot] = $exit;
                unset($this->threads[$slot]);
                if ($exit !== 0 || $this->drain === null) {
                    $this->fail('thread_exit_unexpected', '业务线程在完整停止前退出或返回失败');
                }
                continue;
            }
            $control = $record['control'];
            if ($control['failed'] === true) {
                $this->fail('thread_cleanup_failed', '业务线程报告无法完整收尾');
            }
            if ($this->drain !== null) {
                continue;
            }
            $pulse = $control['pulse'];
            if (!is_int($pulse) || $pulse < $record['pulse']) {
                $this->fail('thread_control_invalid', '业务线程进度状态不符合约定');
                continue;
            }
            if ($pulse > $record['pulse']) {
                $this->threads[$slot]['pulse'] = $pulse;
                $this->threads[$slot]['progress'] = $now;
            }
            if ($control['ready'] === true) {
                $this->threads[$slot]['ready'] = true;
            }
            if (!$this->threads[$slot]['ready'] && $now - $record['started'] >= $this->startupSeconds * 1000000000.0) {
                $this->fail('thread_startup_timeout', '业务线程未在启动期限内就绪');
            } elseif ($this->threads[$slot]['ready'] && $now - $this->threads[$slot]['progress'] >= $this->progressSeconds * 1000000000.0) {
                $this->fail('thread_progress_timeout', '业务线程未在期限内推进事件循环');
            }
        }
        if ($this->threads === []) {
            $this->clearTimer();
        } elseif ($this->drain !== null && $this->drain->expired()) {
            \type_runtime_native_control_fail_stop();
        }
    }

    private function fail(string $code, string $message): void
    {
        $this->failure ??= new TaskException($code, $message);
        $this->stop();
    }

    private function clearTimer(): void
    {
        if ($this->timer !== null) {
            Timer::clear($this->timer);
            $this->timer = null;
        }
    }
}
