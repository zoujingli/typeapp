<?php

declare(strict_types=1);

namespace Type\Orm\Outbox;

/** 外部投递边界；调用者以稳定消息 ID 实现目标幂等，返回实际接受凭据。 */
interface Publisher
{
    /** 发布到目标并返回接受凭据，不宣称目标已经永久保存或消费。 */
    public function publish(Record $record): string;
}
