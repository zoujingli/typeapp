<?php

declare(strict_types=1);

namespace Type\Runtime;

use Closure;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;

/** 受父作用域管理的协程结果；任务及后代收尾完成前持续占用执行额度。 */
final class ManagedTask
{
    private ExecutionOwner $owner;
    private Cancellation $cancellation;
    private Cancellation $parentCancellation;
    private int $parentSubscription;
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
     * @param array<string, string> $bindings 创建子任务时的应用绑定值快照。
     */
    public function __construct(Closure $operation, Deadline $deadline, array $context, int $childLimit, float $cleanupSeconds, TaskBudget $budget, Cancellation $parentCancellation, Closure $finished, array $bindings = [])
    {
        if (!extension_loaded('swoole') || Coroutine::getCid() < 0) {
            throw new TaskException('coroutine_required', '受管子任务需要 Swoole 协程上下文');
        }
        $this->owner = new ExecutionOwner();
        $this->deadline = $deadline;
        $this->cancellation = new Cancellation();
        $this->parentCancellation = $parentCancellation;
        $this->parentSubscription = $parentCancellation->subscribe(function (): void {
            $this->cancellation->cancel();
        });
        $this->completion = new Channel(1);
        $cid = Coroutine::create(function () use ($operation, $context, $childLimit, $cleanupSeconds, $budget, $finished, $bindings): void {
            $scope = new ExecutionScope($this->deadline, $context, $childLimit, $cleanupSeconds, $this->cancellation, $budget);
            try {
                $this->result = $scope->run($operation, $bindings);
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
                $this->detachParentCancellation();
                $this->finished = true;
                $finished($this);
                $this->completion->push(true);
            }
        });
        if ($cid === false) {
            $this->detachParentCancellation();
            throw new TaskException('spawn_failed', '无法创建受管子任务');
        }
    }

    /**
     * 等待结果或重抛任务异常；超时取消任务，但不提前归还仍在途的资源。
     * @param float|null $seconds 本次最多等待的秒数，null 沿用共享截止。
     * @throws TaskException 等待超时，错误码为 task_timeout。
     * @throws \InvalidArgumentException 等待秒数为负数或非有限值。
     * @throws Throwable 任务执行或清理失败。
     */
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

    /** 由创建者广播合作式取消；调用返回不表示协程或底层操作已退出。 */
    public function cancel(): void
    {
        $this->owner->assertCurrent();
        $this->cancellation->cancel();
    }
    /** 只有任务、后代及其作用域完成收尾后才返回 true。 */
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

    private function detachParentCancellation(): void
    {
        if ($this->parentSubscription !== 0) {
            $this->parentCancellation->unsubscribe($this->parentSubscription);
            $this->parentSubscription = 0;
        }
    }
}
