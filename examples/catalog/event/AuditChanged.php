<?php

declare(strict_types=1);

namespace app\catalog\event;

use Type\Orm\Db;

/** 固定监听器只记录当前事件观察，不在事务中发送外部通知。 */
final class AuditChanged
{
    /** 同步监听在调用栈中完成，异常会直接传回发布者。 */
    public function changed(ProductChanged $event): void
    {
        $event->transactions[] = Db::inTransaction('catalog');
    }
}
