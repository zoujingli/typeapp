<?php

declare(strict_types=1);

namespace Type\Runtime;

use RuntimeException;
use Throwable;
use Closure;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Timer;

/** 单次执行的资源所有权；线程请求、Fiber 和协程均参与检查。 */
final class ExecutionScope
{
    private const CURRENT_KEY = 'type.runtime.execution_scope';

    private array $resources = [];

    private string $state = 'active';

    private ExecutionOwner $owner;
    private Deadline $deadline;
    private Cancellation $cancellation;
    private array $context = [];
    private array $bindings = [];
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
    private ?int $deadlineTimer = null;
    private int $deadlineSubscription = 0;
    private bool $ownsCancellation;

    /**
     * @param array<string, string> $context 只保存有界关联标识；资源句柄和可变对象必须由当前作用域登记。
     */
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
        $this->ownsCancellation = $cancellation === null;
        $this->cancellation = $cancellation ?? new Cancellation();
        $this->childLimit = $childLimit;
        $this->cleanupSeconds = $cleanupSeconds;
        $this->taskBudget = $taskBudget ?? new TaskBudget($childLimit);
        if ($this->ownsCancellation) {
            $this->deadlineSubscription = $this->deadline->watch(function (): void {
                $this->armDeadlineTimer();
            });
            $this->armDeadlineTimer();
        }
    }

    /**
     * 在每次发起工作前校验执行者、生命周期、取消和业务截止。
     * @throws TaskException 已取消或截止预算耗尽。
     * @throws RuntimeException 已关闭、正在关闭或跨执行者使用。
     */
    public function assertActive(): void
    {
        $this->assertOwner();
        if ($this->state !== 'active') {
            throw new RuntimeException('执行作用域已经关闭或正在关闭');
        }
        if ($this->deadline->expired()) {
            throw new TaskException('deadline_exceeded', '执行作用域截止预算已用尽');
        }
        if ($this->cancellation->cancelled()) {
            throw new TaskException('cancelled', '执行作用域已经取消');
        }
    }

    /**
     * 取得当前协程显式绑定的作用域；不向父协程查找或继承资源。
     *
     * @throws TaskException 不在协程内、未绑定作用域、已取消或截止。
     * @throws RuntimeException 作用域已关闭或不属于当前执行者。
     */
    public static function current(): self
    {
        CoroutineRuntime::assertAvailable();
        if (Coroutine::getCid() < 0) {
            throw new TaskException('coroutine_required', '当前作用域需要 Swoole 协程上下文');
        }
        $scope = Coroutine::getContext()[self::CURRENT_KEY] ?? null;
        if (!$scope instanceof self) {
            throw new TaskException('scope_missing', '当前协程没有绑定执行作用域');
        }
        $scope->assertActive();
        return $scope;
    }

    /**
     * 在当前协程临时绑定作用域；正常返回和异常均恢复外层，创建者仍负责关闭。
     *
     * 新作用域不会继承外层绑定；同作用域重入暂时覆盖指定值，返回后恢复。
     * 绑定值只能由应用验证后显式提供，构造时的关联 context 不会自动成为绑定值。
     * @param Closure(ExecutionScope): mixed $operation 当前工作。
     * @param array<string, string> $bindings 应用确认的字符串值，不接受资源或可变对象。
     * @throws TaskException 不在协程内、作用域已取消或截止。
     * @throws RuntimeException 作用域已关闭或不属于当前执行者。
     */
    public function run(Closure $operation, array $bindings = []): mixed
    {
        $this->assertActive();
        CoroutineRuntime::assertAvailable();
        if (Coroutine::getCid() < 0) {
            throw new TaskException('coroutine_required', '绑定作用域需要先进入 Swoole 协程');
        }
        $snapshot = [];
        foreach ($bindings as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new \InvalidArgumentException('作用域绑定只接受显式字符串标识');
            }
            $snapshot[$key] = $value;
        }
        $context = Coroutine::getContext();
        $previous = $context[self::CURRENT_KEY] ?? null;
        $previousBindings = $this->bindings;
        $this->bindings = $snapshot + $previousBindings;
        $context[self::CURRENT_KEY] = $this;
        try {
            return $operation($this);
        } finally {
            $this->bindings = $previousBindings;
            if ($previous === null) {
                unset($context[self::CURRENT_KEY]);
            } else {
                $context[self::CURRENT_KEY] = $previous;
            }
        }
    }

    /** 读取应用显式绑定的值；缺失返回 null，不从消息关联 context 推断身份。 */
    public function binding(string $name): ?string
    {
        $this->assertActive();
        return $this->bindings[$name] ?? null;
    }

    /** 校验进程、线程、请求代次、协程和 Fiber 身份；清理时不要求仍处于 active。 */
    public function assertOwner(): void
    {
        $this->owner->assertCurrent();
    }

    /**
     * 先登记所有权再启动资源，同一实例只启动一次；启动失败仍交由 close() 收尾。
     * @throws \Throwable 作用域不可用或资源启动失败；创建者仍须在 finally 关闭作用域。
     */
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

    /**
     * 取消并收尾当前作用域；监听异常不跳过后续清理，重复关闭不重复释放。
     * @throws RuntimeException 取消监听、子任务或资源清理失败；已能收尾的资源仍会释放。
     */
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
            $errors = [];
            try {
                $this->cancellation->cancel();
            } catch (Throwable $cancelError) {
                // 监听异常仍需报告，但不能跳过子任务与已登记资源的收尾。
                $errors[] = $cancelError->getMessage();
            }
            $this->stopDeadlineTimer();
            foreach ($this->children as $child) {
                if ($child instanceof ManagedTask) {
                    $child->cancel();
                }
            }
            $cleanup = new Deadline(min($this->cleanupSeconds, $this->shutdownDeadline?->remaining() ?? $this->cleanupSeconds));
            foreach ($this->children as $child) {
                if ($child instanceof ManagedTask && !$child->join($cleanup)) {
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

    /** 返回 active、closing 或 closed；closing 可能仍持有未完成资源。 */
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
    /** 返回任务树共享的截止对象；缩短它会影响现有子任务。 */
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
    /** 返回创建时的上下文快照；修改返回数组不会影响当前或父作用域。 */
    public function context(): array
    {
        return $this->context;
    }
    /** 返回当前作用域尚未完整收尾的直接子任务数量。 */
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
                $this->cancellation,
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
                },
                $this->bindings
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

    private function armDeadlineTimer(): void
    {
        if ($this->deadlineTimer !== null) {
            Timer::clear($this->deadlineTimer);
            $this->deadlineTimer = null;
        }
        $remaining = $this->deadline->remaining();
        if ($remaining === null) {
            return;
        }
        if ($remaining <= 0) {
            if ($this->state === 'active') {
                $this->cancellation->cancel();
            }
            return;
        }
        if (!extension_loaded('swoole') || \Swoole\Coroutine::getCid() < 0) {
            return;
        }
        $this->deadlineTimer = Timer::after(max(1, (int) ceil($remaining * 1000.0)), function (): void {
            $this->deadlineTimer = null;
            if ($this->state === 'active' && $this->deadline->expired()) {
                $this->cancellation->cancel();
            }
        });
    }

    private function stopDeadlineTimer(): void
    {
        if ($this->deadlineTimer !== null) {
            Timer::clear($this->deadlineTimer);
            $this->deadlineTimer = null;
        }
        if ($this->deadlineSubscription !== 0) {
            $this->deadline->unwatch($this->deadlineSubscription);
            $this->deadlineSubscription = 0;
        }
    }
}
