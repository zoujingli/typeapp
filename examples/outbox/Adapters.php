<?php

declare(strict_types=1);

namespace TypeApp\OutboxExample;

use Type\Orm\Connection;
use Type\Orm\Database;
use Type\Orm\Driver;
use Type\Orm\Outbox\Publisher;
use Type\Orm\Outbox\Record;
use Type\Orm\Outbox\Store;
use Type\Queue\Job;
use Type\Queue\JobContext;
use Type\Queue\Message;
use Type\Queue\Queue;

/** 将 Outbox 意图转换为队列消息；保留稳定 ID，支持发布后崩溃的受控演练。 */
final class QueuePublisher implements Publisher
{
    private Queue $queue;
    private bool $crash;
    /** 借用队列并选择是否在投递成功后终止当前测试进程。 */
    public function __construct(Queue $queue, bool $crash = false)
    {
        $this->queue = $queue;
        $this->crash = $crash;
    }
    /** 发布同身份消息并返回 Stream 回执；crash 模式故意在标记 Outbox 前杀死进程。 */
    public function publish(Record $record): string
    {
        $receipt = $this->queue->publish(new Message($record->id(), $record->topic(), $record->version(), $record->payload(), $record->context()));
        if ($this->crash) {
            posix_kill((int) getmypid(), 9);
        }
        return $receipt;
    }
}

/** 在业务数据库中核对消费凭据，验证重复 Outbox 投递的幂等效果。 */
final class Delivered implements Job
{
    private Driver $driver;
    private Store $store;
    /** 注入业务驱动与 Outbox 存储；连接在每次任务作用域内获取。 */
    public function __construct(Driver $driver, Store $store)
    {
        $this->driver = $driver;
        $this->store = $store;
    }
    /**
     * 在同一数据库事务中记录消费与业务效果，数据库实例在 finally 中关闭。
     *
     * @param array<array-key, mixed> $payload 本次任务的 JSON 业务数据。
     */
    public function handle(JobContext $context, array $payload): void
    {
        $context->assertActive();
        $database = new Database($this->driver, 1, 0);
        try {
            $connection = $database->connect($context->scope());
            $connection->transaction(function (Connection $transaction) use ($context, $payload): void {
                if ($this->store->consumed($transaction, $context->message()->id(), 'receipt:' . $context->message()->id())) {
                    $transaction->table('type_outbox_effects')->insert(['id' => $context->message()->id(), 'value' => $payload['value']]);
                }
            });
            $connection->close();
        } finally {
            $database->close();
        }
    }
}
