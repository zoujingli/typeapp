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

final class QueuePublisher implements Publisher
{
    private Queue $queue;
    private bool $crash;
    public function __construct(Queue $queue, bool $crash = false)
    {
        $this->queue = $queue;
        $this->crash = $crash;
    }
    public function publish(Record $record): string
    {
        $receipt = $this->queue->publish(new Message($record->id(), $record->topic(), $record->version(), $record->payload(), $record->context()));
        if ($this->crash) {
            posix_kill((int) getmypid(), 9);
        }
        return $receipt;
    }
}

final class Delivered implements Job
{
    private Driver $driver;
    private Store $store;
    public function __construct(Driver $driver, Store $store)
    {
        $this->driver = $driver;
        $this->store = $store;
    }
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
