<?php

declare(strict_types=1);

namespace Type\Queue;

use Type\Runtime\ExecutionOwner;
use Type\Runtime\ExecutionScope;

final class Reservation
{
    private Queue $queue;
    private string $streamId;
    private string $consumer;
    private string $token;
    private Message $message;
    private ExecutionOwner $owner;
    private bool $finished = false;
    private int $attempt;
    private int $enqueuedAt;
    private int $ageMilliseconds;
    private ?ExecutionScope $scope = null;
    public function __construct(Queue $queue, string $streamId, string $consumer, string $token, Message $message, int $attempt = 1, int $enqueuedAt = 0, int $ageMilliseconds = 0)
    {
        $this->queue = $queue;
        $this->streamId = $streamId;
        $this->consumer = $consumer;
        $this->token = $token;
        $this->message = $message;
        $this->owner = new ExecutionOwner();
        $this->attempt = $attempt;
        $this->enqueuedAt = $enqueuedAt > 0 ? $enqueuedAt : (int) explode('-', $streamId)[0];
        $this->ageMilliseconds = $ageMilliseconds;
    }
    public function message(): Message
    {
        return $this->message;
    }
    public function streamId(): string
    {
        return $this->streamId;
    }
    public function consumer(): string
    {
        return $this->consumer;
    }
    public function token(): string
    {
        return $this->token;
    }
    public function attempt(): int
    {
        return $this->attempt;
    }
    public function enqueuedAt(): int
    {
        return $this->enqueuedAt;
    }
    public function ageMilliseconds(): int
    {
        return $this->ageMilliseconds;
    }
    public function bindScope(ExecutionScope $scope): void
    {
        $this->owner->assertCurrent();
        if ($this->scope !== null && $this->scope !== $scope) {
            throw new QueueException('scope_already_bound', '任务租约不能改变执行预算');
        }
        $this->scope = $scope;
    }
    public function assertOwned(): void
    {
        $this->owner->assertCurrent();
        if ($this->finished || !$this->queue->owns($this)) {
            throw new QueueException('lease_lost', '任务租约已过期或不再属于当前执行者');
        }
    }
    public function acknowledge(): bool
    {
        $this->owner->assertCurrent();
        if ($this->finished) {
            return false;
        }
        $result = $this->queue->acknowledge($this);
        if (!$result) {
            throw new QueueException('lease_lost', '确认任务时租约已失效');
        }
        $this->finished = true;
        return true;
    }

    public function renew(): void
    {
        $this->owner->assertCurrent();
        $this->scope?->assertActive();
        if ($this->finished || !$this->queue->renew($this)) {
            throw new QueueException('lease_lost', '旧执行者不能续租');
        }
    }

    public function effect(string $script, array $keys = [], array $arguments = []): mixed
    {
        $this->owner->assertCurrent();
        $this->scope?->assertActive();
        if ($this->finished) {
            throw new QueueException('lease_lost', '任务已经结束');
        }
        return $this->queue->effect($this, $script, $keys, $arguments);
    }

    public function retry(string $reason, int $delayMilliseconds): void
    {
        $this->owner->assertCurrent();
        if ($this->finished || !$this->queue->fail($this, $reason, $delayMilliseconds)) {
            throw new QueueException('lease_lost', '失败转移时租约已失效');
        }
        $this->finished = true;
    }

    public function quarantine(string $reason): void
    {
        $this->owner->assertCurrent();
        if ($this->finished || !$this->queue->fail($this, $reason, null)) {
            throw new QueueException('lease_lost', '隔离任务时租约已失效');
        }
        $this->finished = true;
    }
}
