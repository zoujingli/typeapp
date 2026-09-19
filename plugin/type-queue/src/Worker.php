<?php

declare(strict_types=1);

namespace Type\Queue;

use Type\Runtime\ExecutionOwner;
use Type\Runtime\ExecutionScope;
use Type\Runtime\Deadline;
use Type\Runtime\WorkLifecycle;
use Throwable;

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
    public function __construct(Queue $queue, Registry $registry, string $consumer, ?RetryPolicy $retries = null)
    {
        $this->queue = $queue;
        $this->registry = $registry;
        $this->consumer = $consumer;
        $this->owner = new ExecutionOwner();
        $this->retries = $retries ?? new RetryPolicy();
        $this->lifecycle = new WorkLifecycle();
    }
    public function runOnce(): bool
    {
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
                $context = new JobContext($scope, $reservation);
                $context->assertActive();
                $job = $this->registry->create($context);
                $job->handle($context, $reservation->message()->payload());
                $context->assertActive();
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
    public function stop(float $drainSeconds = 5.0): void
    {
        $this->lifecycle->stop($drainSeconds);
    }
    public function ready(): bool
    {
        return $this->lifecycle->ready();
    }
    public function statistics(): array
    {
        return $this->lifecycle->statistics() + $this->counts + ['retry_limit' => $this->retries->maximumAttempts()];
    }

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
