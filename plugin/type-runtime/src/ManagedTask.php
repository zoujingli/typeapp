<?php

declare(strict_types=1);

namespace Type\Runtime;

use Closure;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;

final class ManagedTask
{
    private ExecutionOwner $owner;
    private Cancellation $cancellation;
    private Deadline $deadline;
    private Channel $completion;
    private bool $finished = false;
    private bool $observed = false;
    private mixed $result = null;
    private ?Throwable $error = null;

    /**
     * @param Closure(ExecutionScope): mixed $operation 作用域内的任务体。
     * @param array<string, string> $context 父作用域的上下文快照，不携带父资源。
     * @param Closure(ManagedTask): void $finished 完成并清理后的通知。
     */
    public function __construct(Closure $operation, Deadline $deadline, array $context, int $childLimit, float $cleanupSeconds, TaskBudget $budget, Closure $finished)
    {
        if (!extension_loaded('swoole') || Coroutine::getCid() < 0) {
            throw new TaskException('coroutine_required', '受管子任务需要 Swoole 协程上下文');
        }
        $this->owner = new ExecutionOwner();
        $this->deadline = $deadline;
        $this->cancellation = new Cancellation();
        $this->completion = new Channel(1);
        $cid = Coroutine::create(function () use ($operation, $context, $childLimit, $cleanupSeconds, $budget, $finished): void {
            $scope = new ExecutionScope($this->deadline, $context, $childLimit, $cleanupSeconds, $this->cancellation, $budget);
            try {
                $scope->assertActive();
                $this->result = $operation($scope);
            } catch (Throwable $error) {
                $this->error = $error;
            } finally {
                try {
                    $scope->close();
                } catch (Throwable $error) {
                    if ($this->error === null) {
                        $this->error = $error;
                    }
                }
                // 清理超时不表示后代已经退出，整个子树结束前继续占用共享预算。
                $scope->awaitClosed();
                $this->finished = true;
                $finished($this);
                $this->completion->push(true);
            }
        });
        if ($cid === false) {
            throw new TaskException('spawn_failed', '无法创建受管子任务');
        }
    }

    public function await(?float $seconds = null): mixed
    {
        $this->owner->assertCurrent();
        $remaining = $this->deadline->remaining();
        if ($seconds !== null) {
            if (!is_finite($seconds) || $seconds < 0) {
                throw new \InvalidArgumentException('子任务等待时间无效');
            }
            $remaining = $remaining === null ? $seconds : min($remaining, $seconds);
        }
        if (!$this->wait($remaining)) {
            $this->cancellation->cancel();
            throw new TaskException('task_timeout', '停止等待子任务，底层操作由作用域继续持有');
        }
        $this->observed = true;
        if ($this->error !== null) {
            throw $this->error;
        }
        return $this->result;
    }

    public function cancel(): void
    {
        $this->owner->assertCurrent();
        $this->cancellation->cancel();
    }
    public function finished(): bool
    {
        return $this->finished;
    }

    /** @internal 清理预算独立于业务截止，等待确认退出，不取消或释放原生句柄。 */
    public function join(Deadline $cleanup): bool
    {
        $this->owner->assertCurrent();
        $this->cancellation->cancel();
        return $this->wait($cleanup->remaining());
    }

    /** @internal 未由业务 await 观察的失败交给父作用域。 */
    public function unobservedError(): ?Throwable
    {
        if ($this->observed || ($this->cancellation->cancelled() && $this->error instanceof TaskException
            && in_array($this->error->errorCode(), ['cancelled', 'deadline_exceeded'], true))) {
            return null;
        }
        return $this->error;
    }

    private function wait(?float $seconds): bool
    {
        if ($this->finished) {
            return true;
        }
        if ($seconds !== null && $seconds <= 0) {
            return false;
        }
        $this->completion->pop($seconds ?? -1);
        return $this->finished;
    }
}
