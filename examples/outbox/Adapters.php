<?php

declare(strict_types=1);

namespace TypeApp\OutboxExample;

use Type\Orm\Db;
use Type\Orm\DatabaseManager;
use Type\Orm\Outbox\Publisher;
use Type\Orm\Outbox\Record;
use Type\Orm\Outbox\Store;
use Type\Queue\Job;
use Type\Queue\JobContext;
use Type\Queue\Message;
use Type\Queue\Queue;
use Type\Redis\RedisConnection;
use Type\Runtime\ExecutionScope;

/** 将 Outbox 意图转换为队列消息；保留稳定 ID，支持发布后崩溃的受控演练。 */
final class QueuePublisher implements Publisher
{
    private Queue $queue;
    private bool $crash;
    private DatabaseManager $database;
    private ?RedisConnection $control;
    private string $signal;
    /** 借用队列并选择是否在投递成功后终止当前测试进程。 */
    public function __construct(Queue $queue, DatabaseManager $database, bool $crash = false, ?RedisConnection $control = null, string $signal = '')
    {
        $this->queue = $queue;
        $this->database = $database;
        $this->crash = $crash;
        $this->control = $control;
        $this->signal = $signal;
    }
    /** 发布同身份消息并返回 Stream 回执；crash 模式故意在标记 Outbox 前杀死进程。 */
    public function publish(Record $record): string
    {
        foreach ($this->database->statistics()['active'] as $statistics) {
            if ($statistics['leased'] !== 0) {
                throw new \RuntimeException('Outbox 外部投递期间仍占用数据库租约');
            }
        }
        $receipt = $this->queue->publish(new Message($record->id(), $record->topic(), $record->version(), $record->payload(), $record->context()));
        if ($this->crash) {
            posix_kill((int) getmypid(), 9);
        }
        if ($this->control !== null) {
            $this->control->command('SET', [$this->signal . ':accepted', $receipt, 'EX', 30]);
            $until = microtime(true) + 5.0;
            while ($this->control->command('GET', [$this->signal . ':consumed']) !== 'yes') {
                ExecutionScope::current()->assertActive();
                if (microtime(true) >= $until) {
                    throw new \RuntimeException('并发消费演练没有在预算内完成');
                }
                usleep(1000);
            }
        }
        return $receipt;
    }
}

/** 在业务数据库中核对消费凭据，验证重复 Outbox 投递的幂等效果。 */
final class Delivered implements Job
{
    private Store $store;
    /** 注入 Outbox 存储；命名源连接在每次任务作用域内获取。 */
    public function __construct(Store $store)
    {
        $this->store = $store;
    }
    /**
     * 在同一命名数据库事务中记录消费与 Model 效果，连接归本次任务作用域。
     *
     * @param array<array-key, mixed> $payload 本次任务的 JSON 业务数据。
     */
    public function handle(JobContext $context, array $payload): void
    {
        $context->assertActive();
        Db::transaction(function () use ($context, $payload): void {
            if ($this->store->consumed($context->message()->id(), 'receipt:' . $context->message()->id(), 'outbox')) {
                (new Effect(['id' => $context->message()->id(), 'value' => $payload['value']]))->save();
            }
        }, 'outbox');
    }
}

/** 使用真实发布作用域验证预算、停止与清理失败；不把故障当作外部接受。 */
final class FaultPublisher implements Publisher, \Type\Runtime\ManagedResource
{
    private string $mode;
    private ?\Type\Orm\Outbox\Relay $relay = null;
    private ?ExecutionScope $scope = null;
    private bool $recovered = false;

    /** 选择一个明确、有限的故障阶段。 */
    public function __construct(string $mode)
    {
        $this->mode = $mode;
    }

    /** 仅为演练在真实 Publisher 执行中触发角色停止。 */
    public function bind(\Type\Orm\Outbox\Relay $relay): void
    {
        $this->relay = $relay;
    }

    /** 在真实发布阶段触发异常、超时或收尾失败。 */
    public function publish(Record $record): string
    {
        $this->scope = ExecutionScope::current();
        if ($this->mode === 'exception') {
            throw new \RuntimeException('受控Outbox发布失败');
        }
        if ($this->mode === 'cleanup') {
            $this->scope->open($this);
            return 'target-accepted-before-cleanup-failure';
        }
        if ($this->mode === 'stop') {
            $this->relay?->stop(0.01);
        }
        usleep($this->mode === 'timeout' ? 150000 : 30000);
        $this->scope->assertActive();
        throw new \RuntimeException('执行预算没有生效');
    }

    /** 无外部资源，仅在 stop 注入明确失败。 */
    public function start(): void
    {
    }

    /** 资源真实恢复前持续报告失败，不能提前归还在途额度。 */
    public function stop(): void
    {
        if (!$this->recovered) {
            throw new \RuntimeException('受控Outbox收尾失败');
        }
    }

    /** 模拟底层资源恢复，再由原作用域完成关闭。 */
    public function recover(): void
    {
        $this->recovered = true;
        $this->scope?->close();
    }
}
