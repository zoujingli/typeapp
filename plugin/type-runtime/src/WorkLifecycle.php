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

    public function __construct()
    {
        $this->process = (int) getmypid();
    }

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

    public function ready(): bool
    {
        $this->assertProcess();
        return $this->ready;
    }
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
