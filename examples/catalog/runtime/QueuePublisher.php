<?php

declare(strict_types=1);

namespace app\catalog\runtime;

use RuntimeException;
use Type\Orm\DatabaseManager;
use Type\Orm\Outbox\Publisher;
use Type\Orm\Outbox\Record;
use Type\Queue\Message;
use Type\Queue\Queue;

/** 固定协议转发器；队列接受与 Outbox 凭据登记是两个独立事实。 */
final class QueuePublisher implements Publisher
{
    /** failAfterAccept 仅由显式演练命令启用，模拟接受后进程在登记前失败。 */
    public function __construct(private Queue $queue, private DatabaseManager $database, private bool $failAfterAccept = false)
    {
    }

    /** 外部发送不得持有业务库租约；重复信封保留原业务 ID 以供消费幂等。 */
    public function publish(Record $record): string
    {
        foreach ($this->database->statistics()['active'] as $statistics) {
            if ($statistics['leased'] !== 0) {
                throw new RuntimeException('catalog_publish_holds_database');
            }
        }
        $receipt = $this->queue->publish(new Message($record->id(), $record->topic(), $record->version(), $record->payload(), $record->context()));
        if ($this->failAfterAccept) {
            throw new RuntimeException('catalog_accepted_without_receipt');
        }
        return $receipt;
    }
}
