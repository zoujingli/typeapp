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

    /**
     * 登记要投递的固定协议和载荷，不接管队列 Redis 连接。
     *
     * @param array<array-key, mixed> $payload 受限 JSON 业务数据。
     */
    public function __construct(Queue $queue, string $type, int $version, array $payload)
    {
        $this->queue = $queue;
        $this->type = $type;
        $this->version = $version;
        $this->payload = $payload;
    }

    /**
     * 用 occurrence ID 生成稳定消息身份，在同一 Redis 租约脚本保护下投递。
     *
     * @return array{message_id: string, receipt: string}
     * @throws LeaseException 当前计划不能提供 Redis 原子保护。
     */
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
