<?php

declare(strict_types=1);

namespace Type\Runtime;

/** 进程角色先取消就绪，再给在途作用域一个只会缩短的排空预算。 */
final class WorkLifecycle
{
    private int $process;
    private bool $ready = true;
    private bool $active = false;
    private bool $cleanupPending = false;
    private ?ExecutionScope $scope = null;
    private ?Deadline $drain = null;
    private int $rejected = 0;

    /** 绑定当前进程的单工作角色；fork 后应在新进程重新创建。 */
    public function __construct()
    {
        $this->process = (int) getmypid();
    }

    /**
     * 占用唯一在途位置；停止接单后返回 false，不继续预取。
     * @throws TaskException 当前角色已有未完成工作或在另一进程使用。
     */
    public function begin(): bool
    {
        $this->assertProcess();
        if (!$this->ready) {
            $this->rejected++;
            return false;
        }
        if ($this->active) {
            $this->rejected++;
            throw new TaskException('work_busy', '当前角色已有在途执行，不能继续预取');
        }
        $this->active = true;
        return true;
    }

    /** 将本次工作绑定到其资源作用域，并继承已开始的排空截止。 */
    public function attach(ExecutionScope $scope): void
    {
        $this->assertProcess();
        if (!$this->active) {
            throw new TaskException('work_inactive', '没有在途工作可以绑定作用域');
        }
        if ($this->scope !== null && $this->scope !== $scope && $this->scope->state() !== 'closed') {
            $this->stop(0.0);
            throw new TaskException('cleanup_incomplete', '前一作用域尚未收尾，不能替换在途工作');
        }
        $this->scope = $scope;
        if ($this->drain !== null) {
            $scope->deadline()->shorten($this->drain->remaining() ?? 0.0);
            $scope->limitCleanup($this->drain);
        }
    }

    /** 作用域完整关闭后归还工作位置；尚未收尾时停止接单并保留所有权。 */
    public function finish(): void
    {
        $this->assertProcess();
        if ($this->scope !== null && $this->scope->state() !== 'closed') {
            $this->cleanupPending = true;
            $this->stop(0.0);
            return;
        }
        $this->scope = null;
        $this->active = false;
        $this->cleanupPending = false;
    }

    /**
     * 撤销就绪并限制在途工作的剩余排空秒数，重复调用只会缩短预算。
     * @throws \InvalidArgumentException 秒数不在 0 至 60 范围内或不是有限值。
     */
    public function stop(float $seconds = 5.0): void
    {
        $this->assertProcess();
        if (!is_finite($seconds) || $seconds < 0 || $seconds > 60) {
            throw new \InvalidArgumentException('排空预算必须为 0 至 60 秒');
        }
        $this->ready = false;
        if ($this->drain === null) {
            $this->drain = new Deadline($seconds);
        } else {
            $this->drain->shorten($seconds);
        }
        $scope = $this->scope;
        if ($scope !== null) {
            $scope->deadline()->shorten($this->drain->remaining() ?? 0.0);
            $scope->limitCleanup($this->drain);
        }
    }

    /** 当前进程是否仍允许接收新工作；不表示当前在途位置为空。 */
    public function ready(): bool
    {
        $this->assertProcess();
        return $this->ready;
    }
    /**
     * 刷新已完成的延迟清理，并返回角色状态与单工作额度。
     * @return array{ready: bool, state: string, in_flight: int, execution_limit: int, prefetch_limit: int, rejected: int, drain_expired: bool}
     */
    public function statistics(): array
    {
        $this->assertProcess();
        if ($this->cleanupPending && $this->scope?->state() === 'closed') {
            $this->finish();
        }
        return ['ready' => $this->ready, 'state' => $this->ready ? 'ready' : ($this->active ? 'draining' : 'stopped'),
            'in_flight' => $this->active ? 1 : 0, 'execution_limit' => 1, 'prefetch_limit' => 1, 'rejected' => $this->rejected,
            'drain_expired' => $this->drain?->expired() ?? false];
    }

    private function assertProcess(): void
    {
        if ($this->process !== (int) getmypid()) {
            throw new TaskException('wrong_process', '工作生命周期不能跨进程共享');
        }
    }
}
