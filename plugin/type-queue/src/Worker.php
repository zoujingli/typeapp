<?php

declare(strict_types=1);

namespace Type\Queue;

use Type\Runtime\ExecutionOwner;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\ExecutionScope;
use Type\Runtime\Deadline;
use Type\Runtime\WorkLifecycle;
use Throwable;

/** 在所属协程内串行消费消息；每次独立作用域收尾后才确认或安排重投。 */
final class Worker
{
    private Queue $queue;
    private Registry $registry;
    private string $consumer;
    private bool $busy = false;
    private WorkLifecycle $lifecycle;
    private array $counts = ['received' => 0, 'completed' => 0, 'failed' => 0, 'retried' => 0, 'quarantined' => 0,
        'storage_failures' => 0, 'cleanup_failures' => 0, 'message_age_ms' => 0, 'busy_rejected' => 0];
    private ExecutionOwner $owner;
    private RetryPolicy $retries;
    /** 绑定队列、显式注册表与消费者身份；在实际运行的同一协程内构造。 */
    public function __construct(Queue $queue, Registry $registry, string $consumer, ?RetryPolicy $retries = null)
    {
        $this->queue = $queue;
        $this->registry = $registry;
        $this->consumer = $consumer;
        $this->owner = new ExecutionOwner();
        $this->retries = $retries ?? new RetryPolicy();
        $this->lifecycle = new WorkLifecycle();
    }
    /** Worker 及其队列连接在调用方的 Swoole 协程内装配；每条投递独立绑定作用域。 */
    public function runOnce(): bool
    {
        CoroutineRuntime::assertAvailable();
        if (\Swoole\Coroutine::getCid() < 0) {
            throw new \Type\Runtime\TaskException('coroutine_required', '队列入口需要先在 Swoole 协程内装配资源');
        }
        $this->owner->assertCurrent();
        if ($this->busy) {
            $this->counts['busy_rejected']++;
            throw new QueueException('worker_busy', 'worker 已占用执行额度');
        }
        if (!$this->lifecycle->begin()) {
            return false;
        }
        $this->busy = true;
        try {
            if (!$this->lifecycle->ready()) {
                return false;
            }
            $this->queue->promote();
            if (!$this->lifecycle->ready()) {
                return false;
            }
            try {
                $reservation = $this->queue->reclaim($this->consumer);
                if ($reservation === null && $this->lifecycle->ready()) {
                    $reservation = $this->queue->reserve($this->consumer);
                }
            } catch (QueueException $error) {
                if ($error->errorCode() === 'quarantined') {
                    return true;
                } throw $error;
            }
            if ($reservation === null) {
                return false;
            }
            $this->counts['received']++;
            $this->counts['message_age_ms'] = $reservation->ageMilliseconds();
            if (!$this->registry->supports($reservation->message()) || $reservation->attempt() > $this->retries->maximumAttempts()) {
                $reservation->quarantine($this->registry->supports($reservation->message()) ? 'attempts_exhausted' : 'unsupported_version');
                $this->counts['quarantined']++;
                return true;
            }
            $scope = new ExecutionScope(new Deadline($this->retries->executionMilliseconds() / 1000.0), ['message_id' => $reservation->message()->id()] + $reservation->message()->context());
            $this->lifecycle->attach($scope);
            $error = null;
            try {
                $scope->run(function (ExecutionScope $current) use ($reservation): void {
                    $context = new JobContext($current, $reservation);
                    $context->assertActive();
                    $job = $this->registry->create($context);
                    $job->handle($context, $reservation->message()->payload());
                    $context->assertActive();
                });
            } catch (Throwable $failure) {
                $error = $failure;
            } finally {
                try {
                    $scope->close();
                } catch (Throwable $failure) {
                    $error ??= $failure;
                }
            }
            if ($scope->state() !== 'closed') {
                // 保留原投递及租约；不能在旧执行仍持有资源时确认或安排重投。
                $this->counts['failed']++;
                throw new QueueException('cleanup_incomplete', '任务作用域尚未收尾，原投递继续由租约协议持有');
            }
            if ($error === null) {
                $reservation->acknowledge();
                $this->counts['completed']++;
            } elseif ($reservation->attempt() >= $this->retries->maximumAttempts()) {
                $reservation->quarantine('attempts_exhausted:' . get_class($error));
                $this->counts['quarantined']++;
            } else {
                $reservation->retry('job_failed:' . get_class($error), $this->retries->delay($reservation->attempt()));
                $this->counts['retried']++;
            }
            if ($error !== null) {
                $this->counts['failed']++;
            }
            return true;
        } catch (Throwable $error) {
            if ($error instanceof QueueException && $error->errorCode() === 'cleanup_incomplete') {
                $this->counts['cleanup_failures']++;
            } else {
                $this->counts['storage_failures']++;
            }
            $this->lifecycle->stop(0.0);
            throw $error;
        } finally {
            $this->busy = false;
            $this->lifecycle->finish();
        }
    }
    /** 撤销就绪并按秒数预算等待在途收尾；不关闭应用创建的 RedisManager。 */
    public function stop(float $drainSeconds = 5.0): void
    {
        $this->lifecycle->stop($drainSeconds);
    }
    /** 返回是否仍接受下一条投递；存储或清理失败后会撤销就绪。 */
    public function ready(): bool
    {
        return $this->lifecycle->ready();
    }
    /**
     * 读取生命周期与任务累计计数；不触发队列查询。
     *
     * @return array<string, int|bool|string> 包含消息年龄毫秒、清理失败数及重试上限。
     */
    public function statistics(): array
    {
        return $this->lifecycle->statistics() + $this->counts + ['retry_limit' => $this->retries->maximumAttempts()];
    }

    /**
     * 串行执行有限次领取，遇空队列或停止即返回；不是永久监听循环。
     *
     * @param int $maximum 单轮最多处理 1 至 10000 次。
     * @return int 已处理的投递数，包含失败转移和隔离。
     * @throws QueueException 上限非法或队列/清理协议失败。
     */
    public function run(int $maximum = 100): int
    {
        if ($maximum < 1 || $maximum > 10000) {
            throw new QueueException('invalid_limit', 'worker 单轮处理上限必须为 1 至 10000');
        }
        $count = 0;
        while ($count < $maximum && $this->runOnce()) {
            $count++;
        }
        return $count;
    }
}
