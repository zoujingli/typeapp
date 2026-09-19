<?php

declare(strict_types=1);

namespace TypeApp\Coordination;

use Type\Queue\Message;
use Type\Queue\Queue;
use Type\Redis\ScriptGuard;
use Type\Scheduler\LeaseException;
use Type\Scheduler\Task;
use Type\Scheduler\TaskContext;

/** 应用组合适配器：scheduler 不依赖 queue；只接受同一 Redis 目标的原子投递保护。 */
final class QueueDispatchTask implements Task
{
    private Queue $queue;
    private string $type;
    private int $version;
    private array $payload;

    public function __construct(Queue $queue, string $type, int $version, array $payload)
    {
        $this->queue = $queue;
        $this->type = $type;
        $this->version = $version;
        $this->payload = $payload;
    }

    public function run(TaskContext $context): array
    {
        $context->assertActive();
        $guard = $context->lease();
        if (!$guard instanceof ScriptGuard) {
            throw new LeaseException('target_mismatch', '安全投递需要支持 Redis 写入保护的调度租约');
        }
        $message = new Message(
            $context->occurrenceId(),
            $this->type,
            $this->version,
            $this->payload,
            ['occurrence_id' => $context->occurrenceId(), 'task_id' => $context->taskId(),
                'scheduled_at' => $context->scheduledAt()->format('Y-m-d\TH:i:s\Z')]
        );
        $receipt = $this->queue->publish($message, $guard);

        return ['message_id' => $message->id(), 'receipt' => $receipt];
    }
}
