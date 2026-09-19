<?php

declare(strict_types=1);

namespace Type\Orm\Outbox;

interface Publisher
{
    /** 发布到目标并返回接受凭据，不宣称目标已经永久保存或消费。 */
    public function publish(Record $record): string;
}
