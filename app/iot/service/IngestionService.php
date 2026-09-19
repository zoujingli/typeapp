<?php

declare(strict_types=1);

namespace app\iot\service;

use app\common\service\AuditLog;
use Closure;
use InvalidArgumentException;
use JsonException;
use LogicException;
use stdClass;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Orm\Connection;
use Type\Validate\ValidationException;

/** 业务接收事实的所有者；协议确认、同步持久证明与实际回执发送由调用者负责。 */
final class IngestionService
{
    /**
     * 设备缓存与接收端共享的精确内容身份；对象键顺序和等价十进制数值不改变摘要。
     * @throws InvalidArgumentException 顶层不是对象、重复对象键或业务载荷超过16KiB。
     * @throws JsonException JSON语法、深度或UTF-8无效。
     */
    public static function contentHash(string $payload): string
    {
        if (strlen($payload) > 16384 || !json_decode($payload, false, 32, JSON_THROW_ON_ERROR) instanceof stdClass) {
            throw new InvalidArgumentException('invalid_payload');
        }
        return hash('sha256', self::canonical($payload));
    }

    /**
     * 在调用者已开启的事务中锁定设备并保存去重、原始事实与当前字段。
     * 输入Topic必须来自已经认证授权的MQTT 5连接；返回值不是已经同步提交的证明。
     * 每次可回执结果写入新的receipt_proof_nonce，调用者须取得覆盖本次写入的同步持久证明后发送。
     * @return array{message_id: string, topic: string, receipt: array<string, mixed>|null, code: string}
     * @throws LogicException 未在事务中调用。
     */
    public static function accept(Connection $transaction, string $topic, string $payload, int $qos, int $receivedAt): array
    {
        if ($transaction->transactionDepth() < 1) {
            throw new LogicException('ingestion_transaction_required');
        }
        if ($receivedAt < 1 || strlen($payload) > 16384) {
            return self::unidentified('invalid_payload');
        }
        try {
            $decoded = json_decode($payload, false, 32, JSON_THROW_ON_ERROR);
            if (!$decoded instanceof stdClass) {
                return self::unidentified('invalid_payload');
            }
            $data = get_object_vars($decoded);
            $canonical = self::canonical($payload);
        } catch (JsonException | InvalidArgumentException $invalid) {
            return self::unidentified('invalid_payload');
        }
        if (in_array($data['type'] ?? '', ['time_request', 'command_receipt', 'command_query_result'], true)) {
            return CommandService::accept($transaction, $topic, $data, $qos, $receivedAt);
        }
        if (($data['type'] ?? '') === 'model_switch_receipt') {
            return DeviceService::confirmModelSwitch($transaction, $topic, $data, $qos, $receivedAt);
        }
        if (($data['type'] ?? '') === 'transfer_status') {
            return TransferService::accept($transaction, $topic, $data, $qos, $receivedAt);
        }
        if (($data['type'] ?? '') === 'transfer_activated') {
            return TransferService::complete($transaction, $topic, $data, $qos, $receivedAt);
        }
        if (!isset($data['device_id'], $data['ownership_id'], $data['sequence'])
            || !is_string($data['device_id']) || !preg_match('/^[a-f0-9]{32}$/D', $data['device_id'])
            || !is_string($data['ownership_id']) || !preg_match('/^[a-f0-9]{32}$/D', $data['ownership_id'])
            || !is_string($data['sequence']) || !preg_match('/^[1-9][0-9]{0,37}$/D', $data['sequence'])) {
            return self::unidentified('invalid_identity');
        }
        $query = $transaction->table('iot_devices')->where('id', '=', $data['device_id'])->where('recovery_verified', '=', 1);
        $device = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
        if ($device === null || $device['ownership_id'] !== $data['ownership_id'] || DeviceService::topics($device)['publish'] !== $topic) {
            return self::unidentified('topic_forbidden');
        }
        if (($data['type'] ?? null) === 'device_status') {
            return self::unidentified(self::observeStatus($transaction, $device, $data, $qos, $receivedAt));
        }
        $messageId = hash('sha256', $data['device_id'] . ':' . $data['ownership_id'] . ':' . $data['sequence']);
        $contentHash = hash('sha256', $canonical);
        $ledger = $transaction->table('iot_ingestion')->where('message_id', '=', $messageId);
        $stored = ($transaction->driverName() === 'sqlite' ? $ledger : $ledger->lockForUpdate())->first();
        if ($stored !== null) {
            $transaction->table('iot_ingestion')->where('message_id', '=', $messageId)->update([
                'receipt_proof_nonce' => bin2hex(random_bytes(16)), 'receipt_requested_at' => $receivedAt,
            ]);
            $code = $stored['content_hash'] === $contentHash ? (string) $stored['code'] : 'content_conflict';
            $status = $stored['content_hash'] === $contentHash ? (string) $stored['status'] : 'rejected';
            if (!in_array($device['lifecycle'], ['inactive', 'enabled'], true)) {
                $code = 'device_unavailable';
                $status = 'rejected';
            } elseif ($qos !== 1) {
                $code = 'invalid_envelope';
                $status = 'rejected';
            } elseif ($status === 'accepted') {
                $code = self::validate($transaction, $device, $data, $qos, $receivedAt, false);
                $status = $code === 'accepted' ? 'accepted' : 'rejected';
            }
            if ($code === 'content_conflict' || $code === 'device_unavailable') {
                self::audit($transaction, $device, $messageId, $code);
            }
            return self::receipt($device, $data, $messageId, $contentHash, $status, $code, (int) $stored['received_at']);
        }
        $code = self::validate($transaction, $device, $data, $qos, $receivedAt);
        $status = $code === 'accepted' ? 'accepted' : 'rejected';
        $transaction->table('iot_ingestion')->insert([
            'message_id' => $messageId, 'tenant_id' => $device['tenant_id'], 'device_id' => $device['id'],
            'ownership_id' => $data['ownership_id'], 'sequence' => $data['sequence'], 'content_hash' => $contentHash,
            'status' => $status, 'code' => $code, 'received_at' => $receivedAt,
            'receipt_proof_nonce' => bin2hex(random_bytes(16)), 'receipt_requested_at' => $receivedAt,
        ]);
        if ($status === 'accepted') {
            $advanced = self::advance($transaction, $device, $data, $receivedAt);
            $transaction->table('iot_ingestion_facts')->insert([
                'message_id' => $messageId, 'tenant_id' => $device['tenant_id'], 'device_id' => $device['id'],
                'ownership_id' => $data['ownership_id'], 'product_id' => $device['product_id'], 'model_version' => $data['model_version'],
                'sequence' => $data['sequence'], 'type' => $data['type'], 'identifier' => $data['identifier'] ?? '',
                'sampled_at' => $data['sampled_at'], 'received_at' => $receivedAt,
                'values_json' => json_encode($data['values'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                'current_advanced' => $advanced ? 1 : 0,
            ]);
            $transaction->table('iot_ingestion_completed')->insert(['message_id' => $messageId, 'consumer' => 'current', 'completed_at' => $receivedAt]);
        } else {
            self::audit($transaction, $device, $messageId, $code);
        }
        return self::receipt($device, $data, $messageId, $contentHash, $status, $code, $receivedAt);
    }

    /**
     * 重新授权后以同一快照返回当前字段、独立实时事实与服务端新鲜度，以及本阶段最新首次接收事实。
     * last_receipt表示平台保存的接收结果，不证明设备已收到网络回执；重试不改变首次接收顺序。
     * buffer是设备最近报告的缓存观察，不证明观察生成后设备仍保持同一状态；null表示本阶段/模型尚无观察。
     * @return array{device_id: string, ownership_id: string, model_version: int, sequence: string|null, sampled_at: int|null, received_at: int|null, freshness: string, realtime_sequence: string|null, realtime_sampled_at: int|null, realtime_received_at: int|null, fields: list<array<string, mixed>>, buffer: array{sequence: string, sampled_at: int, received_at: int, freshness: string, values: array<string, int|bool|string>}|null, last_receipt: array<string, mixed>|null}
     * @throws HttpError 无当前租户读取权限或设备不属于本租户。
     */
    public static function current(Connection $connection, Identity $identity, string $tenantId, string $deviceId): array
    {
        HistoryService::authorize($connection, $identity, $tenantId, $deviceId);
        $rows = $connection->query('SELECT d.id, d.product_id, d.ownership_id, d.model_version, c.sequence, c.sampled_at, c.received_at, c.fields_json,'
            . ' c.realtime_sequence, c.realtime_sampled_at, c.realtime_received_at,'
            . " CASE WHEN c.sequence IS NULL THEN 'empty' WHEN c.realtime_received_at IS NULL OR c.realtime_received_at <= ? THEN 'stale' ELSE 'fresh' END AS freshness,"
            . ' r.sequence AS receipt_sequence, r.status AS receipt_status, r.code AS receipt_code, r.received_at AS receipt_received_at,'
            . ' s.sequence AS status_sequence, s.sampled_at AS status_sampled_at, s.received_at AS status_received_at, s.values_json AS status_values'
            . ' FROM iot_devices d LEFT JOIN iot_current_data c ON c.device_id = d.id AND c.ownership_id = d.ownership_id AND c.model_version = d.model_version'
            . ' AND (LENGTH(c.sequence) > LENGTH(d.model_start_sequence) OR (LENGTH(c.sequence) = LENGTH(d.model_start_sequence) AND c.sequence > d.model_start_sequence))'
            . ' LEFT JOIN iot_device_status s ON s.device_id = d.id AND s.ownership_id = d.ownership_id AND s.model_version = d.model_version'
            . ' AND (LENGTH(s.sequence) > LENGTH(d.model_start_sequence) OR (LENGTH(s.sequence) = LENGTH(d.model_start_sequence) AND s.sequence > d.model_start_sequence))'
            . ' LEFT JOIN iot_ingestion r ON r.message_id = (SELECT i.message_id FROM iot_ingestion i'
            . ' WHERE i.tenant_id = d.tenant_id AND i.device_id = d.id AND i.ownership_id = d.ownership_id ORDER BY i.received_at DESC, i.message_id DESC LIMIT 1)'
            . ' WHERE d.tenant_id = ? AND d.id = ?', [time() - 60, $tenantId, $deviceId]);
        if ($rows === []) {
            throw new HttpError(404, 'device_not_found');
        }
        $row = $rows[0];
        $fields = $row['fields_json'] === null ? [] : array_values(json_decode((string) $row['fields_json'], true, 32, JSON_THROW_ON_ERROR));
        return ['device_id' => $deviceId, 'ownership_id' => $row['ownership_id'], 'model_version' => (int) $row['model_version'],
            'model' => ProductService::publishedModel($connection, $tenantId, $row['product_id'], (int) $row['model_version']),
            'sequence' => $row['sequence'], 'sampled_at' => $row['sampled_at'] === null ? null : (int) $row['sampled_at'],
            'received_at' => $row['received_at'] === null ? null : (int) $row['received_at'], 'fields' => $fields,
            'freshness' => $row['freshness'], 'realtime_sequence' => $row['realtime_sequence'],
            'realtime_sampled_at' => $row['realtime_sampled_at'] === null ? null : (int) $row['realtime_sampled_at'],
            'realtime_received_at' => $row['realtime_received_at'] === null ? null : (int) $row['realtime_received_at'],
            'buffer' => $row['status_sequence'] === null ? null : ['sequence' => $row['status_sequence'],
                'sampled_at' => (int) $row['status_sampled_at'], 'received_at' => (int) $row['status_received_at'],
                'freshness' => time() - (int) $row['status_received_at'] < 60
                    && (int) $row['status_sampled_at'] >= (int) $row['status_received_at'] - 30
                    && (int) $row['status_sampled_at'] <= (int) $row['status_received_at'] + 5 ? 'fresh' : 'stale',
                'values' => json_decode((string) $row['status_values'], true, 8, JSON_THROW_ON_ERROR)],
            'last_receipt' => $row['receipt_sequence'] === null ? null : ['sequence' => $row['receipt_sequence'],
                'status' => $row['receipt_status'], 'code' => $row['receipt_code'], 'received_at' => (int) $row['receipt_received_at']]];
    }

    /**
     * 运行概览只批量读取新鲜度和时间；调用者已授权租户，SQL再绑定设备当前归属与模型阶段。
     * @internal 只供运行元数据投影；不读取fields_json或消息载荷。
     * @param list<string> $deviceIds 当前有界设备页，最多100项。
     * @return array<string, array<string, mixed>> 按设备ID索引，缺项表示查询期间设备已经变更归属。
     */
    public static function freshness(Connection $connection, string $tenantId, array $deviceIds): array
    {
        if (count($deviceIds) > 100) {
            throw new \InvalidArgumentException('operations_device_budget');
        }
        if ($deviceIds === []) {
            return [];
        }
        $rows = $connection->query('SELECT d.id, d.ownership_id, d.model_version, c.realtime_received_at, c.realtime_sampled_at,'
            . " CASE WHEN c.sequence IS NULL THEN 'empty' WHEN c.realtime_received_at IS NULL OR c.realtime_received_at <= ? THEN 'stale' ELSE 'fresh' END AS freshness"
            . ' FROM iot_devices d LEFT JOIN iot_current_data c ON c.device_id = d.id AND c.ownership_id = d.ownership_id AND c.model_version = d.model_version'
            . ' AND (LENGTH(c.sequence) > LENGTH(d.model_start_sequence) OR (LENGTH(c.sequence) = LENGTH(d.model_start_sequence) AND c.sequence > d.model_start_sequence))'
            . ' WHERE d.tenant_id = ? AND d.id IN (' . implode(', ', array_fill(0, count($deviceIds), '?')) . ')', [time() - 60, $tenantId, ...$deviceIds]);
        $result = [];
        foreach ($rows as $row) {
            $result[$row['id']] = ['ownership_id' => $row['ownership_id'], 'model_version' => (int) $row['model_version'], 'freshness' => $row['freshness'],
                'received_at' => $row['realtime_received_at'] === null ? null : (int) $row['realtime_received_at'],
                'sampled_at' => $row['realtime_sampled_at'] === null ? null : (int) $row['realtime_sampled_at']];
        }
        return $result;
    }

    /**
     * 工作进程按明确消费者名发现未完成的原始事实；返回有界批次，不领取或删除其他用途的数据。
     * @return list<array<string, mixed>> 含message_id、固定归属/模型、双时间与values的原始事实。
     * @throws InvalidArgumentException 消费者标识或批量预算无效。
     */
    public static function pending(Connection $connection, string $consumer, int $limit = 100): array
    {
        self::consumer($consumer);
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('ingestion_batch_invalid');
        }
        $rows = $connection->query('SELECT f.* FROM iot_ingestion_facts f WHERE NOT EXISTS'
            . ' (SELECT 1 FROM iot_ingestion_completed c WHERE c.message_id = f.message_id AND c.consumer = ?)'
            . ' ORDER BY f.received_at, f.message_id LIMIT ' . $limit, [$consumer]);
        $result = [];
        foreach ($rows as $row) {
            $result[] = self::fact($row);
        }
        return $result;
    }

    /**
     * 在同一事务完成一个消费者的数据库效果及完成事实；并发重复只运行一次，失败全部回滚。
     * 回调只在本连接中写入效果，不能发送网络消息或自行提交；跨系统效果应另存Outbox。
     * @param Closure(Connection, array<string, mixed>): void $effect 接收不可变原始事实。
     * @return bool 本次实际运行效果；已经完成返回false。
     * @throws HttpError 原始事实不存在。
     * @throws InvalidArgumentException 消费者名无效或占用内建current消费者。
     */
    public static function consume(Connection $connection, string $messageId, string $consumer, Closure $effect): bool
    {
        self::consumer($consumer);
        if ($consumer === 'current') {
            throw new InvalidArgumentException('ingestion_consumer_reserved');
        }
        return $connection->transaction(static function (Connection $transaction) use ($messageId, $consumer, $effect): bool {
            $query = $transaction->table('iot_ingestion_facts')->where('message_id', '=', $messageId);
            $row = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
            if ($row === null) {
                throw new HttpError(404, 'ingestion_not_found');
            }
            $completed = $transaction->table('iot_ingestion_completed')->where('message_id', '=', $messageId)->where('consumer', '=', $consumer);
            if (($transaction->driverName() === 'sqlite' ? $completed : $completed->lockForUpdate())->first() !== null) {
                return false;
            }
            $effect($transaction, self::fact($row));
            $transaction->table('iot_ingestion_completed')->insert(['message_id' => $messageId, 'consumer' => $consumer, 'completed_at' => time()]);
            return true;
        }, $connection->transactionDepth() === 0 && $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /** 一台设备只保存一份状态观察；重复与乱序不刷新观察时间，也不创建可靠采样事实。 */
    private static function observeStatus(Connection $transaction, array $device, array $data, int $qos, int $receivedAt): string
    {
        $envelope = ['app_version', 'type', 'device_id', 'ownership_id', 'model_version', 'sequence', 'sampled_at', 'values'];
        if ($qos !== 1 || ($data['app_version'] ?? null) !== 1 || $device['lifecycle'] !== 'enabled'
            || !is_int($data['model_version'] ?? null) || $data['model_version'] !== (int) $device['model_version']
            || DeviceService::sequenceCompare($data['sequence'], $device['model_start_sequence']) <= 0
            || !is_int($data['sampled_at'] ?? null) || $data['sampled_at'] < 0 || !($data['values'] ?? null) instanceof stdClass
            || count($data) !== count($envelope) || array_diff(array_keys($data), $envelope) !== []) {
            return 'invalid_device_status';
        }
        $values = get_object_vars($data['values']);
        $counters = ['maximum_records', 'maximum_bytes', 'pending_count', 'pending_bytes', 'not_admitted', 'exception_count',
            'accepted_total', 'rejected_total', 'exceptions_dropped'];
        $fields = [...$counters, 'full', 'capacity_reason', 'counters_saturated'];
        if (count($values) !== count($fields) || array_diff(array_keys($values), $fields) !== []) {
            return 'invalid_device_status';
        }
        foreach ($counters as $field) {
            if (!is_int($values[$field]) || $values[$field] < 0) {
                return 'invalid_device_status';
            }
        }
        if ($values['maximum_records'] < 1 || $values['maximum_records'] > 86400 || $values['maximum_bytes'] < 128 || $values['maximum_bytes'] > 1073741824
            || $values['pending_count'] > $values['maximum_records'] || $values['pending_bytes'] > $values['maximum_bytes'] || $values['exception_count'] > 1000
            || !is_bool($values['full']) || !is_bool($values['counters_saturated']) || !in_array($values['capacity_reason'], ['', 'records', 'bytes'], true)
            || $values['full'] !== ($values['capacity_reason'] !== '' || $values['pending_count'] >= $values['maximum_records'] || $values['pending_bytes'] >= $values['maximum_bytes'])) {
            return 'invalid_device_status';
        }
        $query = $transaction->table('iot_device_status')->where('device_id', '=', $device['id']);
        $stored = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
        if ($stored !== null && $stored['ownership_id'] === $device['ownership_id'] && (int) $stored['model_version'] === (int) $device['model_version']
            && (strlen($data['sequence']) < strlen($stored['sequence'])
                || (strlen($data['sequence']) === strlen($stored['sequence']) && strcmp($data['sequence'], $stored['sequence']) <= 0))) {
            return 'device_status_ignored';
        }
        $replacement = ['ownership_id' => $device['ownership_id'], 'model_version' => (int) $device['model_version'],
            'sequence' => $data['sequence'], 'sampled_at' => $data['sampled_at'], 'received_at' => $receivedAt,
            'values_json' => json_encode($values, JSON_THROW_ON_ERROR)];
        if ($stored === null) {
            $transaction->table('iot_device_status')->insert(['device_id' => $device['id']] + $replacement);
        } else {
            $query->update($replacement);
        }
        return 'device_status_observed';
    }

    private static function validate(Connection $transaction, array $device, array $data, int $qos, int $receivedAt, bool $firstReception = true): string
    {
        if (!in_array($device['lifecycle'], ['inactive', 'enabled'], true)) {
            return 'device_unavailable';
        }
        if ($qos !== 1 || ($data['app_version'] ?? null) !== 1 || !in_array($data['type'] ?? null, ['telemetry', 'event'], true)
            || !isset($data['model_version'], $data['sampled_at'], $data['values']) || !is_int($data['model_version'])
            || !is_int($data['sampled_at']) || $data['sampled_at'] < 0 || !$data['values'] instanceof stdClass) {
            return 'invalid_envelope';
        }
        $allowed = ['app_version', 'type', 'device_id', 'ownership_id', 'model_version', 'sequence', 'sampled_at', 'values'];
        if ($data['type'] === 'event') {
            $allowed[] = 'identifier';
            if (!isset($data['identifier']) || !is_string($data['identifier'])) {
                return 'invalid_envelope';
            }
        }
        if (array_diff(array_keys($data), $allowed) !== []) {
            return 'invalid_envelope';
        }
        if (!DeviceService::acceptsModel($transaction, $device, $data['model_version'], $data['sequence'])) {
            return 'model_mismatch';
        }
        if ((int) $device['transfer_frozen'] === 1 && $device['transfer_id'] !== null) {
            $transfer = $transaction->table('iot_transfers')->where('id', '=', $device['transfer_id'])->first();
            if ($transfer !== null && in_array($transfer['status'], ['isolating', 'activating'], true)) {
                return 'transfer_frozen';
            }
            if ($transfer !== null && $transfer['device_status'] !== null) {
                $frozen = json_decode($transfer['device_status'], true, 32, JSON_THROW_ON_ERROR);
                if (DeviceService::sequenceCompare($data['sequence'], $frozen['boundary_sequence']) > 0) {
                    return 'transfer_frozen';
                }
            }
        }
        if ($firstReception && $data['sampled_at'] > $receivedAt + 5) {
            return 'clock_ahead';
        }
        if ($firstReception && $data['sampled_at'] < $receivedAt - 172800) {
            return 'sample_expired';
        }
        try {
            $model = ProductService::publishedModel($transaction, $device['tenant_id'], $device['product_id'], $data['model_version']);
            ModelDefinition::validateValues($model['definition'], $data['type'] === 'telemetry' ? 'properties' : 'event', $data['identifier'] ?? '', $data['values']);
        } catch (HttpError | ValidationException $invalid) {
            return 'invalid_model_values';
        }
        return 'accepted';
    }

    /** 设备行锁串行化本设备的接收；序号按十进制位数和字典序比较，绝不转成机器整数。 */
    private static function advance(Connection $transaction, array $device, array $data, int $receivedAt): bool
    {
        if ($data['type'] !== 'telemetry' || $data['model_version'] !== (int) $device['model_version']
            || DeviceService::sequenceCompare($data['sequence'], $device['model_start_sequence']) <= 0) {
            return false;
        }
        $query = $transaction->table('iot_current_data')->where('device_id', '=', $device['id']);
        $current = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
        $same = $current !== null && $current['ownership_id'] === $device['ownership_id'] && (int) $current['model_version'] === (int) $device['model_version']
            && DeviceService::sequenceCompare($current['sequence'], $device['model_start_sequence']) > 0;
        if ($same && (strlen($data['sequence']) < strlen($current['sequence'])
            || (strlen($data['sequence']) === strlen($current['sequence']) && strcmp($data['sequence'], $current['sequence']) <= 0))) {
            return false;
        }
        $fields = $same ? json_decode((string) $current['fields_json'], true, 32, JSON_THROW_ON_ERROR) : [];
        foreach (get_object_vars($data['values']) as $identifier => $value) {
            $fields[$identifier] = ['identifier' => $identifier, 'value' => $value, 'sequence' => $data['sequence'], 'sampled_at' => $data['sampled_at'], 'received_at' => $receivedAt];
        }
        ksort($fields, SORT_STRING);
        $realtime = $data['sampled_at'] >= $receivedAt - 30 && $data['sampled_at'] <= $receivedAt + 5;
        $replacement = ['ownership_id' => $device['ownership_id'], 'model_version' => (int) $device['model_version'],
            'sequence' => $data['sequence'], 'sampled_at' => $data['sampled_at'], 'received_at' => $receivedAt,
            'realtime_sequence' => $realtime ? $data['sequence'] : ($same ? $current['realtime_sequence'] : null),
            'realtime_sampled_at' => $realtime ? $data['sampled_at'] : ($same ? $current['realtime_sampled_at'] : null),
            'realtime_received_at' => $realtime ? $receivedAt : ($same ? $current['realtime_received_at'] : null),
            'fields_json' => json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)];
        if ($current === null) {
            $transaction->table('iot_current_data')->insert(['device_id' => $device['id']] + $replacement);
        } else {
            $query->update($replacement);
        }
        return true;
    }

    private static function receipt(array $device, array $data, string $messageId, string $contentHash, string $status, string $code, int $receivedAt): array
    {
        return ['message_id' => $messageId, 'topic' => DeviceService::topics($device)['subscribe'], 'code' => $code,
            'receipt' => ['app_version' => 1, 'type' => 'ingestion_receipt', 'device_id' => $device['id'], 'ownership_id' => $device['ownership_id'],
                'sequence' => $data['sequence'], 'content_hash' => $contentHash, 'status' => $status, 'code' => $code, 'received_at' => $receivedAt]];
    }

    private static function unidentified(string $code): array
    {
        return ['message_id' => '', 'topic' => '', 'receipt' => null, 'code' => $code];
    }

    private static function audit(Connection $transaction, array $device, string $messageId, string $code): void
    {
        AuditLog::append($transaction, $device['tenant_id'], $device['id'], 'ingestion.rejected', $messageId, 'denied', ['context' => 'ingestion', 'reason' => $code], 'customer');
    }

    private static function consumer(string $consumer): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $consumer)) {
            throw new InvalidArgumentException('ingestion_consumer_invalid');
        }
    }

    private static function fact(array $row): array
    {
        $row['values'] = json_decode((string) $row['values_json'], true, 32, JSON_THROW_ON_ERROR);
        unset($row['values_json']);
        $row['model_version'] = (int) $row['model_version'];
        $row['sampled_at'] = (int) $row['sampled_at'];
        $row['received_at'] = (int) $row['received_at'];
        $row['current_advanced'] = (int) $row['current_advanced'] === 1;
        return $row;
    }

    /** 已经由JSON解码器校验语法；另外保留数字精确十进制身份，避免浮点舍入令异内容共用散列。 */
    private static function canonical(string $payload): string
    {
        // 字符串连续段与整组使用占有量词，16KiB合法长字符串不耗尽PCRE JIT栈。
        if (preg_match_all('/"(?:\\\\.|[^"\\\\]++)*+"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?|true|false|null|[{}\[\]:,]/s', $payload, $matches) === false) {
            throw new InvalidArgumentException('invalid_payload');
        }
        $offset = 0;
        return self::canonicalValue($matches[0], $offset);
    }

    private static function canonicalValue(array $tokens, int &$offset): string
    {
        $token = $tokens[$offset++];
        if ($token === '{') {
            $members = [];
            while ($tokens[$offset] !== '}') {
                $key = json_encode(json_decode($tokens[$offset++], false, 32, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $offset++;
                if (array_key_exists($key, $members)) {
                    throw new InvalidArgumentException('duplicate_json_key');
                }
                $members[$key] = self::canonicalValue($tokens, $offset);
                if ($tokens[$offset] === ',') {
                    $offset++;
                }
            }
            $offset++;
            ksort($members, SORT_STRING);
            $parts = [];
            foreach ($members as $name => $value) {
                $parts[] = $name . ':' . $value;
            }
            return '{' . implode(',', $parts) . '}';
        }
        if ($token === '[') {
            $items = [];
            while ($tokens[$offset] !== ']') {
                $items[] = self::canonicalValue($tokens, $offset);
                if ($tokens[$offset] === ',') {
                    $offset++;
                }
            }
            $offset++;
            return '[' . implode(',', $items) . ']';
        }
        if ($token[0] === '"') {
            return json_encode(json_decode($token, false, 32, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        if (in_array($token, ['true', 'false', 'null'], true)) {
            return $token;
        }
        preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]+))?$/D', $token, $number);
        $exponentText = $number[4] ?? '0';
        if (strlen(ltrim($exponentText, '+-0')) > 6) {
            throw new InvalidArgumentException('json_exponent_out_of_range');
        }
        $fraction = $number[3] ?? '';
        $digits = ltrim($number[2] . $fraction, '0');
        if ($digits === '') {
            return '0';
        }
        $trimmed = rtrim($digits, '0');
        $exponent = (int) $exponentText - strlen($fraction) + strlen($digits) - strlen($trimmed);
        return $number[1] . $trimmed . 'e' . $exponent;
    }
}
