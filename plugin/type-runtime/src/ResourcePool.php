<?php

declare(strict_types=1);

namespace Type\Runtime;

use Closure;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;

/** 线程内共享有界池；协程等待由 Swoole 唤醒，同步与零等待立即拒绝。 */
final class ResourcePool
{
    private Closure $factory;
    private int $capacity;
    private int $idleLimit;
    private ExecutionOwner $owner;
    private int $created = 0;
    private int $creating = 0;
    private int $sequence = 0;
    private array $idle = [];
    private array $leased = [];
    private array $leaseOwners = [];
    private array $inUse = [];
    private array $pending = [];
    private array $closing = [];
    private array $quarantined = [];
    private array $waiters = [];
    private int $waitSequence = 0;
    private int $waiterLimit;
    private float $waitLimit;
    private bool $closed = false;
    private bool $retired = false;
    private ?ResourceBudget $budget;
    private int $rejected = 0;
    private int $cleanupFailures = 0;
    private int $borrows = 0;
    private float $waitSeconds = 0.0;

    /**
     * @param Closure(): ReusableResource $factory 零参数工厂，失败时负责完成部分建连的关闭。
     * @param int $waiterLimit 协程排队上限，0 禁止等待。
     * @param float $waitSeconds 单次借用最长等待秒数，0 立即拒绝。
     */
    public function __construct(Closure $factory, int $capacity, int $idleLimit, ?ResourceBudget $budget = null, int $waiterLimit = 64, float $waitSeconds = 1.0)
    {
        if ($capacity < 1 || $idleLimit < 0 || $idleLimit > $capacity || $waiterLimit < 0 || $waiterLimit > 65536
            || !is_finite($waitSeconds) || $waitSeconds < 0 || $waitSeconds > 60) {
            throw new RuntimeException('连接池容量、空闲上限或等待预算无效');
        }
        $this->factory = $factory;
        $this->capacity = $capacity;
        $this->idleLimit = $idleLimit;
        $this->owner = new ExecutionOwner(false);
        $this->budget = $budget;
        $this->waiterLimit = $waiterLimit;
        $this->waitLimit = $waitSeconds;
    }

    /**
     * 借用只属于当前作用域；等待截止取作用域、池配置和本次上限中的较小值。
     * @param ?float $waitSeconds null 沿用池配置，0 即时借用，正数只能缩短等待。
     * @throws CapacityException 同步满载、零等待或等待队列已满。
     * @throws TaskException 等待到期或作用域取消；资源尚未借出。
     */
    public function borrow(ExecutionScope $scope, ?float $waitSeconds = null): ResourceLease
    {
        $this->owner->assertCurrent();
        $scope->assertActive();
        $this->assertOpen();
        if ($waitSeconds !== null && (!is_finite($waitSeconds) || $waitSeconds < 0)) {
            throw new \InvalidArgumentException('本次借用等待时间无效');
        }
        $resource = $this->waiters === [] ? $this->tryResource() : null;
        if ($resource === null) {
            $seconds = min($this->waitLimit, $waitSeconds ?? $this->waitLimit, $scope->deadline()->remaining() ?? $this->waitLimit);
            if ($seconds <= 0 || !extension_loaded('swoole') || Coroutine::getCid() < 0 || count($this->waiters) >= $this->waiterLimit) {
                $this->rejected++;
                throw new CapacityException('连接额度或等待队列已满，本次借用立即拒绝');
            }
            $resource = $this->waitForResource($scope, $seconds);
        }
        $id = ++$this->sequence;
        $this->leased[$id] = $resource;
        $this->leaseOwners[$id] = new ExecutionOwner();
        $lease = new ResourceLease($this, $scope, $id);
        try {
            // 工厂与排队均可能让出；期间的取消和退役不能接受新的租约。
            $this->assertOpen();
            $scope->open($lease);
        } catch (Throwable $error) {
            $this->release($id);
            throw $error;
        }
        $this->borrows++;
        return $lease;
    }

    /** @internal 只允许租约原执行者访问，调用者不直接取得底层原生句柄。 */
    public function resource(int $id): ReusableResource
    {
        $this->owner->assertCurrent();
        if ($this->closed || !isset($this->leased[$id]) || isset($this->pending[$id])) {
            throw new RuntimeException('资源租约已失效');
        }
        $this->leaseOwners[$id]->assertCurrent();
        return $this->leased[$id];
    }

    /**
     * @internal 记录真实在途操作，释放意图只在最后一个操作退出后生效。
     * @param Closure(ReusableResource): mixed $operation 使用本租约资源，不向外转交句柄。
     */
    public function hold(int $id, Closure $operation): mixed
    {
        $resource = $this->resource($id);
        $this->inUse[$id] = ($this->inUse[$id] ?? 0) + 1;
        try {
            return $operation($resource);
        } finally {
            $this->inUse[$id]--;
            if ($this->inUse[$id] === 0) {
                unset($this->inUse[$id]);
                if (isset($this->pending[$id])) {
                    $this->release($id);
                }
            }
        }
    }

    /** @internal 重复归还没有副作用；活动操作或未完成关闭始终占用额度。 */
    public function release(int $id): void
    {
        $this->owner->assertCurrent();
        if (!isset($this->leased[$id])) {
            return;
        }
        $this->leaseOwners[$id]->assertCurrent();
        if (isset($this->inUse[$id])) {
            $this->pending[$id] = true;
            return;
        }
        $resource = $this->leased[$id];
        $this->closing[$id] = $resource;
        unset($this->leased[$id], $this->leaseOwners[$id], $this->pending[$id]);
        $reusable = false;
        try {
            $reusable = $resource->reset();
        } catch (Throwable $error) {
            $this->cleanupFailures++;
        }
        if ($reusable && !$this->closed && !$this->retired && count($this->idle) < $this->idleLimit) {
            $this->idle[] = $resource;
            unset($this->closing[$id]);
            $this->wake();
        } else {
            $this->discard($id, $resource);
        }
    }

    /** 拒绝新借用并清理空闲资源；在途租约仍在最后一个操作退出时收尾。 */
    public function close(): void
    {
        $this->owner->assertCurrent();
        $this->closed = true;
        $this->wakeAll();
        $this->closeIdle();
    }

    /** 停止新借用，原执行者可完成旧租约；归还后不再进入空闲池。 */
    public function retire(): void
    {
        $this->owner->assertCurrent();
        $this->retired = true;
        $this->wakeAll();
        $this->closeIdle();
    }

    /** 在途含 hold、建连和收尾；隔离含已请求释放的活动租约及关闭失败。 */
    public function statistics(): array
    {
        $this->owner->assertCurrent();
        return ['capacity' => $this->capacity, 'created' => $this->created, 'leased' => count($this->leased),
            'idle' => count($this->idle), 'idle_limit' => $this->idleLimit, 'waiters' => count($this->waiters), 'waiter_limit' => $this->waiterLimit,
            'borrowed' => $this->borrows, 'rejected' => $this->rejected, 'cleanup_failures' => $this->cleanupFailures, 'wait_seconds' => $this->waitSeconds,
            'in_flight' => array_sum($this->inUse) + $this->creating + count($this->closing),
            'quarantined' => count($this->pending) + count($this->quarantined), 'closing' => count($this->closing)];
    }

    private function tryResource(): ?ReusableResource
    {
        $resource = array_pop($this->idle);
        if ($resource !== null) {
            return $resource;
        }
        if ($this->created >= $this->capacity || ($this->budget !== null && !$this->budget->tryAcquire())) {
            return null;
        }
        $this->created++;
        $this->creating++;
        try {
            $resource = ($this->factory)();
            if (!$resource instanceof ReusableResource) {
                throw new RuntimeException('资源工厂没有返回可复用资源');
            }
            return $resource;
        } catch (Throwable $error) {
            $this->created--;
            $this->budget?->release();
            $this->wake();
            throw $error;
        } finally {
            $this->creating--;
        }
    }

    private function waitForResource(ExecutionScope $scope, float $seconds): ReusableResource
    {
        $started = hrtime(true);
        $deadline = new Deadline($seconds);
        $signal = new Channel(1);
        $id = ++$this->waitSequence;
        $this->waiters[$id] = $signal;
        $cancellation = $scope->cancellation();
        $subscription = $cancellation->subscribe(static function () use ($signal): void {
            if (!$signal->isFull()) {
                $signal->push(true);
            }
        });
        $budgetSubscription = $this->budget?->subscribe($signal);
        $deadlineSubscription = $scope->deadline()->subscribe($signal);
        try {
            while (true) {
                $scope->assertActive();
                $this->assertOpen();
                if ($deadline->expired()) {
                    throw new TaskException('pool_timeout', '连接池借用等待已到期');
                }
                if (array_key_first($this->waiters) === $id) {
                    $resource = $this->tryResource();
                    if ($resource !== null) {
                        return $resource;
                    }
                }
                $remaining = min($deadline->remaining(), $scope->deadline()->remaining() ?? $seconds);
                if ($remaining > 0) {
                    $signal->pop($remaining);
                    if ($signal->errCode === SWOOLE_CHANNEL_CANCELED) {
                        $cancellation->cancel();
                        throw new TaskException('cancelled', '连接池等待协程已经取消');
                    }
                }
            }
        } catch (Throwable $error) {
            $this->rejected++;
            throw $error;
        } finally {
            unset($this->waiters[$id]);
            $cancellation->unsubscribe($subscription);
            $scope->deadline()->unsubscribe($deadlineSubscription);
            if ($budgetSubscription !== null) {
                $this->budget->unsubscribe($budgetSubscription);
            }
            $signal->close();
            $elapsed = (float) ((hrtime(true) - $started) / 1000000000.0);
            $this->waitSeconds += $elapsed;
            $this->wake();
        }
    }

    /** 最多唤醒队首；容量不足时继续挂起，没有周期轮询。 */
    private function wake(): void
    {
        $id = array_key_first($this->waiters);
        if ($id !== null && !$this->waiters[$id]->isFull()) {
            $this->waiters[$id]->push(true);
        }
    }

    private function wakeAll(): void
    {
        $waiters = $this->waiters;
        foreach ($waiters as $signal) {
            if (!$signal->isFull()) {
                $signal->push(true);
            }
        }
    }

    private function assertOpen(): void
    {
        if ($this->closed || $this->retired) {
            throw new RuntimeException('连接池已经关闭或退役');
        }
    }

    /** 每项先登记收尾，关闭可能让出；失败项保留，下一次 close/retire 可重试。 */
    private function closeIdle(): void
    {
        $idle = $this->idle;
        $this->idle = [];
        $quarantined = $this->quarantined;
        $this->quarantined = [];
        foreach ($idle as $resource) {
            $id = ++$this->sequence;
            $this->closing[$id] = $resource;
            $this->discard($id, $resource);
        }
        foreach ($quarantined as $id => $resource) {
            $this->closing[$id] = $resource;
            $this->discard($id, $resource);
        }
    }

    private function discard(int $id, ReusableResource $resource): void
    {
        try {
            $resource->close();
        } catch (Throwable $error) {
            $this->cleanupFailures++;
            $this->quarantined[$id] = $resource;
            unset($this->closing[$id]);
            return;
        }
        unset($this->closing[$id]);
        $this->created--;
        $this->budget?->release();
        $this->wake();
    }
}
