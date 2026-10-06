<?php

declare(strict_types=1);

namespace app\iot\service;

use RuntimeException;
use Type\Orm\Connection;
use Type\Orm\Db;
use Type\Orm\Outbox\Store;
use Type\Queue\Job;
use Type\Queue\JobContext;

/** 通知消费只依赖本次 Job 作用域；消息的队列发布由角色持有。 */
final class NoticeJob implements Job
{
    private const RETENTION = 15552000;

    /**
     * 读模型与消费凭据同事务，队列至少一次和旧租约重放不会产生第二条通知。
     * 已回收意图和到期载荷只确认队列，不重建已过180天保留期的数据。
     * @param array<string, mixed> $payload 只消费与持久Outbox完全一致的编译注册协议。
     */
    public function handle(JobContext $context, array $payload): void
    {
        $context->assertActive();
        $connection = Db::connection('default', true);
        $connection->transaction(static function (Connection $transaction) use ($context, $payload): void {
            $id = $context->message()->id();
            $query = $transaction->table('iot_notice_outbox')->where('id', '=', $id);
            $record = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
            $context->assertActive();
            if ($record === null || $record['consumed_receipt'] !== null) {
                return;
            }
            $persisted = json_decode((string) $record['payload'], true, 16, JSON_THROW_ON_ERROR);
            if ($record['topic'] !== 'iot.notice' || (int) $record['version'] !== 1 || $persisted !== $payload) {
                throw new RuntimeException('notice_message_invalid');
            }
            if ((int) $payload['created_at'] > time() - self::RETENTION) {
                $transaction->table('iot_notifications')->insert(['id' => $id, 'tenant_id' => $payload['tenant_id'], 'alarm_id' => $payload['alarm_id'],
                    'kind' => $payload['kind'], 'payload' => $record['payload'], 'created_at' => (int) $payload['created_at']]);
            }
            (new Store('iot_notice_outbox'))->consumed($id, 'notice:' . $id);
            $context->assertActive();
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

}
