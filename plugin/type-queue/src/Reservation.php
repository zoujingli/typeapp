<?php

declare(strict_types=1);

namespace Type\Queue;

use Type\Runtime\ExecutionOwner;
use Type\Runtime\ExecutionScope;

/** 当前执行者独占的单次投递租约；确认、重试或隔离后不可再使用。 */
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
    /**
     * 保存 Redis 原子领取结果并绑定当前执行者；业务从 Queue 获取实例。
     *
     * @param int $enqueuedAt 原始入队时间，Unix 毫秒；0 时由 Stream ID 推导。
     * @param int $ageMilliseconds 领取时观测的消息年龄，不随本地等待递增。
     * @internal
     */
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
    /** 返回重试间保持身份的业务消息。 */
    public function message(): Message
    {
        return $this->message;
    }
    /** 返回本次 Stream 投递标识；重试后可能改变，不能作为业务幂等键。 */
    public function streamId(): string
    {
        return $this->streamId;
    }
    /** 返回当前领取者标识，和随机 token 一起参加服务端校验。 */
    public function consumer(): string
    {
        return $this->consumer;
    }
    /**
     * 返回本次领取的随机凭据；只用于内部租约脚本，不写入业务日志。
     *
     * @internal
     */
    public function token(): string
    {
        return $this->token;
    }
    /** 返回首次投递、重试及回收累计的尝试次数。 */
    public function attempt(): int
    {
        return $this->attempt;
    }
    /** 返回原始入队时间，单位为 Unix 毫秒。 */
    public function enqueuedAt(): int
    {
        return $this->enqueuedAt;
    }
    /** 返回本次领取时测量的消息年龄，单位为毫秒。 */
    public function ageMilliseconds(): int
    {
        return $this->ageMilliseconds;
    }
    /**
     * 将同执行者租约绑定到唯一任务作用域，限制后续续租与副作用预算。
     *
     * @internal
     * @throws QueueException 已经绑定另一个作用域。
     */
    public function bindScope(ExecutionScope $scope): void
    {
        $this->owner->assertCurrent();
        if ($this->scope !== null && $this->scope !== $scope) {
            throw new QueueException('scope_already_bound', '任务租约不能改变执行预算');
        }
        $this->scope = $scope;
    }
    /**
     * 向 Redis 检查 token、领取者与到期时间，已结束租约不再有效。
     *
     * @throws QueueException 当前租约已结束、到期或被接管。
     */
    public function assertOwned(): void
    {
        $this->owner->assertCurrent();
        if ($this->finished || !$this->queue->owns($this)) {
            throw new QueueException('lease_lost', '任务租约已过期或不再属于当前执行者');
        }
    }
    /**
     * 原子确认并删除当前投递，首次成功后使本地租约失效。
     *
     * @return bool 已经结束时返回 false；本次确认成功返回 true。
     * @throws QueueException 服务端拒绝已失效租约。
     */
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

    /**
     * 在当前作用域仍有效时延长 Redis 租约，不延长任务执行截止。
     *
     * @throws QueueException 租约已结束或服务端拒绝旧持有者。
     */
    public function renew(): void
    {
        $this->owner->assertCurrent();
        $this->scope?->assertActive();
        if ($this->finished || !$this->queue->renew($this)) {
            throw new QueueException('lease_lost', '旧执行者不能续租');
        }
    }

    /**
     * 在同一 Redis 脚本内校验租约并执行业务副作用，不保护外部数据库写入。
     *
     * @param list<string> $keys 可信脚本使用的 Redis 键。
     * @param list<string|int|float> $arguments 可信脚本的标量参数。
     * @throws QueueException 租约已结束、失效或脚本参数无效。
     */
    public function effect(string $script, array $keys = [], array $arguments = []): mixed
    {
        $this->owner->assertCurrent();
        $this->scope?->assertActive();
        if ($this->finished) {
            throw new QueueException('lease_lost', '任务已经结束');
        }
        return $this->queue->effect($this, $script, $keys, $arguments);
    }

    /**
     * 原子转入延迟区并结束本次投递；下次执行增加尝试次数，消息 ID 保持不变。
     *
     * @param int $delayMilliseconds 重试延迟，0 至 86400000 毫秒。
     * @throws QueueException 租约失效或转移参数无效。
     */
    public function retry(string $reason, int $delayMilliseconds): void
    {
        $this->owner->assertCurrent();
        if ($this->finished || !$this->queue->fail($this, $reason, $delayMilliseconds)) {
            throw new QueueException('lease_lost', '失败转移时租约已失效');
        }
        $this->finished = true;
    }

    /**
     * 原子转入隔离区并结束本次投递，等待核对后显式重放或到期回收。
     *
     * @throws QueueException 租约失效或原因格式无效。
     */
    public function quarantine(string $reason): void
    {
        $this->owner->assertCurrent();
        if ($this->finished || !$this->queue->fail($this, $reason, null)) {
            throw new QueueException('lease_lost', '隔离任务时租约已失效');
        }
        $this->finished = true;
    }
}
