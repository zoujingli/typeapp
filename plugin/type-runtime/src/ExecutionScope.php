<?php

declare(strict_types=1);

namespace Type\Runtime;

use RuntimeException;
use Throwable;
use Closure;
use Swoole\Coroutine\Channel;

/** 单次执行的资源所有权；线程请求、Fiber 和协程均参与检查。 */
final class ExecutionScope
{
    private array $resources = [];

    private string $state = 'active';

    private ExecutionOwner $owner;
    private Deadline $deadline;
    private Cancellation $cancellation;
    private array $context = [];
    private array $children = [];
    private array $childErrors = [];
    private int $childSequence = 0;
    private int $childLimit;
    private float $cleanupSeconds;
    private bool $resourcesClosed = false;
    private bool $closeInProgress = false;
    private ?Deadline $shutdownDeadline = null;
    private TaskBudget $taskBudget;
    private ?Channel $completion = null;

    public function __construct(?Deadline $deadline = null, array $context = [], int $childLimit = 16, float $cleanupSeconds = 5.0, ?Cancellation $cancellation = null, ?TaskBudget $taskBudget = null)
    {
        $this->owner = new ExecutionOwner();
        if ($childLimit < 1 || $childLimit > 1024 || !is_finite($cleanupSeconds) || $cleanupSeconds < 0 || $cleanupSeconds > 60) {
            throw new \InvalidArgumentException('作用域子任务或清理预算无效');
        }
        foreach ($context as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new \InvalidArgumentException('上下文只接受显式字符串标识');
            }
            $this->context[$key] = $value;
        }
        $this->deadline = $deadline ?? new Deadline();
        $this->cancellation = $cancellation ?? new Cancellation();
        $this->childLimit = $childLimit;
        $this->cleanupSeconds = $cleanupSeconds;
        $this->taskBudget = $taskBudget ?? new TaskBudget($childLimit);
    }

    public function assertActive(): void
    {
        $this->assertOwner();
        if ($this->state !== 'active') {
            throw new RuntimeException('执行作用域已经关闭或正在关闭');
        }
        if ($this->cancellation->cancelled()) {
            throw new TaskException('cancelled', '执行作用域已经取消');
        }
        if ($this->deadline->expired()) {
            throw new TaskException('deadline_exceeded', '执行作用域截止预算已用尽');
        }
    }

    public function assertOwner(): void
    {
        $this->owner->assertCurrent();
    }

    public function open(ManagedResource $resource): void
    {
        $this->assertActive();
        if (in_array($resource, $this->resources, true)) {
            return;
        }
        // 先登记再启动，使部分启动失败也能进入逆序清理。
        $this->resources[] = $resource;
        $resource->start();
    }

    public function close(): void
    {
        if ($this->state === 'closed') {
            return;
        }
        $this->assertOwner();
        if ($this->closeInProgress) {
            return;
        }
        $this->closeInProgress = true;
        try {
            $this->state = 'closing';
            $this->cancellation->cancel();
            $errors = [];
            foreach ($this->children as $child) {
                $child->cancel();
            }
            $cleanup = new Deadline(min($this->cleanupSeconds, $this->shutdownDeadline?->remaining() ?? $this->cleanupSeconds));
            foreach ($this->children as $child) {
                if (!$child->join($cleanup)) {
                    $errors[] = '子任务清理超时，资源继续隔离持有';
                }
            }
            if (!$this->resourcesClosed) {
                for ($index = count($this->resources) - 1; $index >= 0; $index--) {
                    try {
                        $this->resources[$index]->stop();
                        unset($this->resources[$index]);
                    } catch (Throwable $error) {
                        $errors[] = $error->getMessage();
                    }
                }
                // 失败项继续由作用域持有，所有者再次 close 时只收尾剩余资源。
                $this->resources = array_values($this->resources);
                $this->resourcesClosed = $this->resources === [];
            }
            foreach ($this->childErrors as $task) {
                if ($task->unobservedError() !== null) {
                    $errors[] = $task->unobservedError()->getMessage();
                }
            }
            $this->childErrors = [];
            if ($this->resourcesClosed && $this->children === []) {
                $this->state = 'closed';
                $this->completion?->close();
            }
            if ($errors !== []) {
                throw new RuntimeException('资源清理失败：' . implode('；', $errors));
            }
        } finally {
            $this->closeInProgress = false;
        }
    }

    public function state(): string
    {
        return $this->state;
    }

    /**
     * @internal 仅在 close 后由所属任务等待真实收尾；取消等待不能提前归还子树额度。
     * @throws RuntimeException 作用域尚未开始关闭，或调用者不是所属执行者。
     */
    public function awaitClosed(): void
    {
        $this->assertOwner();
        if ($this->state === 'active') {
            throw new RuntimeException('等待收尾前必须关闭执行作用域');
        }
        if ($this->state === 'closed') {
            return;
        }
        $this->completion ??= new Channel(1);
        while ($this->state !== 'closed') {
            // 原生取消只唤醒本次 pop；再次挂起，直到后代完成回调关闭通道。
            $this->completion->pop();
        }
    }
    public function deadline(): Deadline
    {
        return $this->deadline;
    }
    /** 供受管等待登记取消通知；取消只表达意图，不能代替 close 或真实完成。 */
    public function cancellation(): Cancellation
    {
        return $this->cancellation;
    }
    /** 角色停止时共享剩余清理预算；只影响等待，不提前释放仍在执行的资源。 */
    public function limitCleanup(Deadline $deadline): void
    {
        if ($this->shutdownDeadline === null || ($deadline->remaining() ?? INF) < ($this->shutdownDeadline->remaining() ?? INF)) {
            $this->shutdownDeadline = $deadline;
        }
    }
    public function context(): array
    {
        return $this->context;
    }
    public function activeTasks(): int
    {
        return $this->taskBudget->active();
    }

    /** @param Closure(ExecutionScope): mixed $operation 子任务始终接收自己的作用域。 */
    public function spawn(Closure $operation): ManagedTask
    {
        $this->assertActive();
        $this->childErrors = array_values(array_filter($this->childErrors, static fn (ManagedTask $task): bool => $task->unobservedError() !== null));
        if (count($this->childErrors) >= $this->childLimit) {
            throw new TaskException('unobserved_task_errors', '未处理的子任务失败已达到上限');
        }
        if (count($this->children) >= $this->childLimit) {
            throw new TaskException('child_limit', '作用域子任务容量已满');
        }
        $this->taskBudget->acquire();
        $id = ++$this->childSequence;
        // 协程可能在构造返回前完成，先登记占位再替换。
        $this->children[$id] = null;
        try {
            $task = new ManagedTask(
                $operation,
                $this->deadline,
                $this->context,
                $this->childLimit,
                $this->cleanupSeconds,
                $this->taskBudget,
                function (ManagedTask $task) use ($id): void {
                    if ($task->unobservedError() !== null) {
                        $this->childErrors[] = $task;
                    }
                    unset($this->children[$id]);
                    $this->taskBudget->release();
                    if ($this->state === 'closing' && $this->resourcesClosed && $this->children === []) {
                        $this->state = 'closed';
                        $this->completion?->close();
                    }
                }
            );
        } catch (Throwable $error) {
            unset($this->children[$id]);
            $this->taskBudget->release();
            throw $error;
        }
        if (isset($this->children[$id]) || array_key_exists($id, $this->children)) {
            $this->children[$id] = $task;
        }
        return $task;
    }
}
