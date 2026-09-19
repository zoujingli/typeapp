<?php

declare(strict_types=1);

namespace app\iot\service;

use app\common\service\IdentityService;
use app\common\service\RoleService;
use InvalidArgumentException;
use RuntimeException;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Orm\Connection;
use Type\Orm\Database;
use Type\Orm\Outbox\Publisher;
use Type\Orm\Outbox\Record;
use Type\Orm\Outbox\Store;
use Type\Queue\Job;
use Type\Queue\JobContext;
use Type\Queue\Message;
use Type\Queue\Queue;

/** 告警站内通知的持久意图、租户读模型与到期生命周期；专用队列只有本消费者。 */
final class NoticeService implements Publisher, Job
{
    private const RETENTION = 15552000;

    /** 数据库及队列连接由调用角色拥有，构造不连接外部服务。 */
    public function __construct(private Database $database, private Queue $queue)
    {
    }

    /**
     * 调用者在告警触发/结束事务内持有设备或告警锁；状态和意图同时提交。
     * 独立标记支持旧告警有界补齐，不能因Outbox已回收而再次生成通知。
     */
    public static function enqueue(Connection $connection, string $alarmId, string $kind): void
    {
        if ($connection->transactionDepth() === 0 || !in_array($kind, ['triggered', 'ended'], true)) {
            throw new InvalidArgumentException('notice_transaction_required');
        }
        $row = $connection->query('SELECT a.*, v.definition FROM iot_alarms a JOIN iot_alarm_rule_versions v ON v.rule_id = a.rule_id AND v.version = a.rule_version WHERE a.id = ?', [$alarmId])[0];
        $marker = $kind === 'triggered' ? 'trigger_notified' : 'end_notified';
        if ((int) $row[$marker] === 1 || ($kind === 'ended' && $row['ended_at'] === null)) {
            return;
        }
        $definition = json_decode((string) $row['definition'], true, 16, JSON_THROW_ON_ERROR);
        $payload = ['alarm_id' => $alarmId, 'tenant_id' => $row['tenant_id'], 'device_id' => $row['device_id'],
            'rule_id' => $row['rule_id'], 'rule_version' => (int) $row['rule_version'], 'name' => $definition['name'],
            'kind' => $kind, 'created_at' => time(), 'occurred_at' => (int) ($kind === 'triggered' ? $row['created_at'] : $row['ended_at']),
            'end_reason' => $kind === 'triggered' ? null : $row['end_reason']];
        (new Store('iot_notice_outbox'))->enqueue($connection, $alarmId . '.' . $kind, 'iot.notice', 1, $payload, ['tenant_id' => $row['tenant_id']]);
        $connection->table('iot_alarms')->where('id', '=', $alarmId)->update([$marker => 1]);
    }

    /** 唯一通知协议投递到本角色专用队列；网络调用不持有数据库事务。 */
    public function publish(Record $record): string
    {
        if ($record->topic() !== 'iot.notice' || $record->version() !== 1) {
            throw new RuntimeException('notice_message_invalid');
        }
        return $this->queue->publish(new Message($record->id(), $record->topic(), $record->version(), $record->payload(), $record->context()));
    }

    /**
     * 读模型与消费凭据同事务，队列至少一次和旧租约重放不会产生第二条通知。
     * 已回收意图和到期载荷只确认队列，不重建已过180天保留期的数据。
     * @param array<string, mixed> $payload 只消费与持久Outbox完全一致的编译注册协议。
     */
    public function handle(JobContext $context, array $payload): void
    {
        $connection = $this->database->connect($context->scope());
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
            (new Store('iot_notice_outbox'))->consumed($transaction, $id, 'notice:' . $id);
            $context->assertActive();
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /**
     * 每轮最多补齐100个旧告警、恢复100条已发布但60秒无消费凭据的意图。
     * 队列丢失或重试耗尽不丢数据库事实；幂等处理允许恢复与仍在执行的任务并行。
     * @return array{backfilled: int, replayed: int}
     */
    public static function recover(Connection $connection, int $batch = 100): array
    {
        self::batch($batch);
        $rows = $connection->query('SELECT id FROM iot_alarms WHERE trigger_notified = 0 ORDER BY id LIMIT ' . $batch);
        if (count($rows) < $batch) {
            $rows = array_merge($rows, $connection->query("SELECT id FROM iot_alarms WHERE trigger_notified = 1 AND end_notified = 0 AND status = 'ended' ORDER BY id LIMIT " . ($batch - count($rows))));
        }
        $backfilled = 0;
        foreach ($rows as $row) {
            $backfilled += $connection->transaction(static function (Connection $transaction) use ($row): int {
                $query = $transaction->table('iot_alarms')->where('id', '=', $row['id']);
                $alarm = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
                if ($alarm === null) {
                    return 0;
                }
                self::enqueue($transaction, $row['id'], 'triggered');
                if ($alarm['ended_at'] !== null) {
                    self::enqueue($transaction, $row['id'], 'ended');
                }
                return 1;
            }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
        }
        $store = new Store('iot_notice_outbox', 30000, self::RETENTION);
        $pending = $connection->table('iot_notice_outbox')->where('state', '=', 'published')->whereNull('consumed_at')
            ->where('published_at', '<=', (time() - 60) * 1000)->orderBy('published_at')->orderBy('id')->limit($batch)->get();
        $replayed = 0;
        foreach ($pending as $record) {
            $payload = json_decode((string) $record['payload'], true, 16, JSON_THROW_ON_ERROR);
            if ((int) $payload['created_at'] <= time() - self::RETENTION) {
                $connection->transaction(static function (Connection $transaction) use ($store, $record): void {
                    $store->consumed($transaction, $record['id'], 'notice:expired:' . $record['id']);
                });
            } elseif ($store->replay($connection, $record['id'], 'notice_delivery_recovery')) {
                $replayed++;
            }
        }
        return ['backfilled' => $backfilled, 'replayed' => $replayed];
    }

    /**
     * 当前租户授权与每次读取绑定；通知保存发生时版本，当前确认和活动状态来自原租户告警。
     * @param array<string, mixed> $filters kind及分页。
     * @return array<string, mixed> 20条默认、100条上限及当前成员权限。
     * @throws HttpError 权限不足或筛选非法。
     */
    public static function notifications(Connection $connection, Identity $identity, string $tenantId, array $filters): array
    {
        $current = (new IdentityService('customer'))->refresh($connection, $identity);
        if ($current === null) {
            throw new HttpError(401, 'unauthenticated');
        }
        $permissions = RoleService::permissions($connection, $current, 'customer', $tenantId);
        if (!in_array('customer.notifications.read', $permissions, true)) {
            throw new HttpError(403, 'permission_denied');
        }
        $context = ['permissions' => $permissions, 'menus' => RoleService::menus('customer', $permissions), 'identity' => IdentityService::context($current, $tenantId)];
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 20);
        $kind = $filters['kind'] ?? '';
        if (array_diff(array_keys($filters), ['page', 'per_page', 'kind']) !== [] || $page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100
            || !in_array($kind, ['', 'triggered', 'ended'], true)) {
            throw new HttpError(422, 'notice_filter_invalid');
        }
        $where = 'n.tenant_id = ? AND n.created_at > ?';
        $parameters = [$tenantId, time() - self::RETENTION];
        if ($kind !== '') {
            $where .= ' AND n.kind = ?';
            $parameters[] = $kind;
        }
        $rows = $connection->query('SELECT n.*, a.status AS alarm_status, a.end_reason AS alarm_end_reason, a.acknowledged_by, a.acknowledged_at'
            . ' FROM iot_notifications n LEFT JOIN iot_alarms a ON a.id = n.alarm_id AND a.tenant_id = n.tenant_id WHERE ' . $where
            . ' ORDER BY n.created_at DESC, n.id LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $parameters);
        $items = [];
        foreach ($rows as $row) {
            $row['payload'] = json_decode((string) $row['payload'], true, 16, JSON_THROW_ON_ERROR);
            $row['created_at'] = (int) $row['created_at'];
            $row['acknowledged_at'] = $row['acknowledged_at'] === null ? null : (int) $row['acknowledged_at'];
            $items[] = $row;
        }
        return ['items' => $items, 'total' => (int) $connection->query('SELECT COUNT(*) AS total FROM iot_notifications n WHERE ' . $where, $parameters)[0]['total'],
            'page' => $page, 'per_page' => $perPage, 'context' => $context];
    }

    /**
     * 每类最多100条，逐项事务提交可从中断继续。活动告警永不按创建时间删除。
     * 通知不外键依赖告警，二者期限独立；未投递的持久意图继续用于恢复。
     * @return array{alarms_deleted: int, notices_deleted: int, outbox_deleted: int}
     */
    public static function clean(Connection $connection, int $batch = 100): array
    {
        self::batch($batch);
        $before = time() - self::RETENTION;
        $alarms = $connection->table('iot_alarms')->where('status', '=', 'ended')->where('ended_at', '<=', $before)->orderBy('ended_at')->orderBy('id')->limit($batch)->get();
        $deleted = 0;
        foreach ($alarms as $alarm) {
            $deleted += $connection->transaction(static function (Connection $transaction) use ($alarm, $before): int {
                return $transaction->table('iot_alarms')->where('id', '=', $alarm['id'])->where('status', '=', 'ended')->where('ended_at', '<=', $before)->delete();
            }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
        }
        $notices = $connection->table('iot_notifications')->where('created_at', '<=', $before)->orderBy('created_at')->orderBy('id')->limit($batch)->get();
        $pruned = 0;
        foreach ($notices as $notice) {
            $pruned += $connection->table('iot_notifications')->where('id', '=', $notice['id'])->where('created_at', '<=', $before)->delete();
        }
        return ['alarms_deleted' => $deleted, 'notices_deleted' => $pruned, 'outbox_deleted' => (new Store('iot_notice_outbox'))->collect($connection, $batch)];
    }

    private static function batch(int $batch): void
    {
        if ($batch < 1 || $batch > 100) {
            throw new InvalidArgumentException('notice_batch_invalid');
        }
    }
}
