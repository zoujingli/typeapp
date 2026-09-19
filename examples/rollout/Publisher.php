<?php

declare(strict_types=1);

namespace TypeApp\Rollout;

use Type\Orm\Outbox\Record;
use Type\Queue\Message;
use Type\Queue\Queue;

/** 将演练的 Outbox 记录交给同一可靠队列；延迟窗口由发布演练显式选择。 */
final class Publisher implements \Type\Orm\Outbox\Publisher
{
    private Queue $queue;
    private int $delayMilliseconds;

    /**
     * @param int $delayMilliseconds 延迟消息的毫秒数；默认保持五秒，慢切换演练最多两分钟。
     * @throws \InvalidArgumentException 延迟不在有界演练窗口内。
     */
    public function __construct(Queue $queue, int $delayMilliseconds = 5000)
    {
        if ($delayMilliseconds < 1 || $delayMilliseconds > 120000) {
            throw new \InvalidArgumentException('发布演练延迟必须在1至120000毫秒之间');
        }
        $this->queue = $queue;
        $this->delayMilliseconds = $delayMilliseconds;
    }

    /** 只有 delayed- 操作延迟投递；保持记录的稳定 ID、协议版本及幂等消费语义。 */
    public function publish(Record $record): string
    {
        $message = new Message($record->id(), $record->topic(), $record->version(), $record->payload(), $record->context());
        return str_starts_with($record->id(), 'delayed-') ? $this->queue->publishDelayed($message, $this->delayMilliseconds) : $this->queue->publish($message);
    }
}
