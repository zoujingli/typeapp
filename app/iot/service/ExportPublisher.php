<?php

declare(strict_types=1);

namespace app\iot\service;

use RuntimeException;
use Type\Orm\Outbox\Publisher;
use Type\Orm\Outbox\Record;
use Type\Queue\Message;
use Type\Queue\Queue;

/** 在现有Outbox发布协议与有界队列消息协议之间转换，保留稳定身份和真实接收凭据。 */
final class ExportPublisher implements Publisher
{
    /** 队列连接由后台角色作用域拥有，发布器不扩大资源池。 */
    public function __construct(private Queue $queue)
    {
    }

    /** 拒绝非导出协议，只有Redis返回有效凭据后Relay才能标记已发布。 */
    public function publish(Record $record): string
    {
        if ($record->topic() !== 'iot.export' || $record->version() !== 1) {
            throw new RuntimeException('export_message_invalid');
        }
        return $this->queue->publish(new Message($record->id(), $record->topic(), $record->version(), $record->payload(), $record->context()));
    }
}
