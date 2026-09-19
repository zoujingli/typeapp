<?php

declare(strict_types=1);

namespace app\iot\service;

use app\common\service\IdentityService;
use app\common\service\RoleService;
use InvalidArgumentException;
use JsonException;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Orm\Connection;

/** 原始历史的查询与到期回收所有者；当前投影及下游业务状态有独立生命周期。 */
final class HistoryService
{
    /**
     * 当前租户权限重新授权；历史归属来自原始事实，不随设备转移改变。
     * @param array<string, mixed> $filters 经HTTP声明校验的设备内筛选；游标固定查询边界并采用键集翻页。
     * @return array<string, mixed> 原始记录、当时模型、下一页游标及实际查询时间范围。
     * @throws HttpError 权限不足、设备不可见、非法筛选、过期游标或越界分页。
     */
    public static function search(Connection $connection, Identity $identity, string $tenantId, string $deviceId, array $filters): array
    {
        self::authorize($connection, $identity, $tenantId, $deviceId);
        self::filters($filters);
        $cursor = null;
        if (($filters['cursor'] ?? '') !== '') {
            try {
                $cursor = json_decode(base64_decode(strtr($filters['cursor'], '-_', '+/'), true) ?: '', true, 4, JSON_THROW_ON_ERROR);
            } catch (JsonException $invalid) {
                throw new HttpError(422, 'history_cursor_invalid');
            }
            if (!is_array($cursor) || !is_int($cursor['from'] ?? null) || !is_int($cursor['to'] ?? null)) {
                throw new HttpError(422, 'history_cursor_invalid');
            }
        }
        $window = self::window($filters + ($cursor === null ? [] : ['from' => $cursor['from'], 'to' => $cursor['to']]));
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 20);
        $sort = (string) ($filters['sort'] ?? 'sampled_desc');
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100 || !in_array($sort, ['sampled_desc', 'sampled_asc', 'received_desc', 'received_asc'], true)) {
            throw new HttpError(422, 'history_filter_invalid');
        }
        $fingerprint = hash('sha256', json_encode([$tenantId, $deviceId, $window['from'], $window['to'], $filters['product_id'] ?? '',
            $filters['model_version'] ?? 0, $filters['ownership_id'] ?? '', $filters['field'] ?? '', $sort, $perPage], JSON_THROW_ON_ERROR));
        if ($cursor !== null) {
            if (!is_array($cursor) || ($cursor['query'] ?? '') !== $fingerprint || ($cursor['page'] ?? 0) !== $page
                || !is_int($cursor['snapshot'] ?? null) || !is_int($cursor['time'] ?? null)
                || !is_string($cursor['id'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $cursor['id']) || $cursor['snapshot'] > time()) {
                throw new HttpError(422, 'history_cursor_invalid');
            }
            if ($cursor['snapshot'] < time() - 900) {
                throw new HttpError(409, 'history_snapshot_expired');
            }
            $window['snapshot'] = $cursor['snapshot'];
        } elseif ($page !== 1) {
            throw new HttpError(422, 'history_page_out_of_range');
        }
        [$where, $parameters] = self::conditions($connection, $tenantId, $deviceId, $filters, $window);
        $total = (int) $connection->query('SELECT COUNT(*) AS total FROM iot_ingestion_facts f WHERE ' . $where, $parameters)[0]['total'];
        $column = str_starts_with($sort, 'sampled') ? 'f.sampled_at' : 'f.received_at';
        $direction = str_ends_with($sort, 'desc') ? 'DESC' : 'ASC';
        if ($cursor !== null) {
            $operator = $direction === 'DESC' ? '<' : '>';
            $where .= ' AND (' . $column . ' ' . $operator . ' ? OR (' . $column . ' = ? AND f.message_id ' . $operator . ' ?))';
            array_push($parameters, $cursor['time'], $cursor['time'], $cursor['id']);
        }
        $rows = $connection->query('SELECT f.*, m.definition AS model_definition FROM iot_ingestion_facts f'
            . ' JOIN iot_models m ON m.tenant_id = f.tenant_id AND m.product_id = f.product_id AND m.model_version = f.model_version'
            . ' WHERE ' . $where . ' ORDER BY ' . $column . ' ' . $direction . ', f.message_id ' . $direction . ' LIMIT ' . ($perPage + 1), $parameters);
        if ($page > 1 && $rows === []) {
            throw new HttpError(422, 'history_page_out_of_range');
        }
        $hasMore = count($rows) > $perPage;
        $items = [];
        foreach (array_slice($rows, 0, $perPage) as $row) {
            $row['values'] = json_decode((string) $row['values_json'], true, 32, JSON_THROW_ON_ERROR);
            $row['model'] = json_decode((string) $row['model_definition'], true, 32, JSON_THROW_ON_ERROR);
            unset($row['values_json'], $row['model_definition']);
            foreach (['model_version', 'sampled_at', 'received_at', 'current_advanced'] as $key) {
                $row[$key] = (int) $row[$key];
            }
            $items[] = $row;
        }
        $next = null;
        if ($hasMore) {
            $last = $items[count($items) - 1];
            $next = rtrim(strtr(base64_encode(json_encode(['query' => $fingerprint, 'page' => $page + 1, 'snapshot' => $window['snapshot'],
                'from' => $window['from'], 'to' => $window['to'], 'time' => $last[str_starts_with($sort, 'sampled') ? 'sampled_at' : 'received_at'], 'id' => $last['message_id']], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        }
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'next_cursor' => $next, 'sort' => $sort, 'window' => $window];
    }

    /**
     * 单图限定当时产品、模型、归属阶段和数值属性，避免把单位或归属变化画成同一曲线。
     * 超过2000点时数据库按时间桶计算显示均值；不修改原始事实或冒充分钟聚合。
     * @param array<string, mixed> $filters 必须含product_id、model_version、ownership_id、field。
     * @return array<string, mixed> 至多2000个显示点，缺测桶值为null，实际粒度以秒返回。
     * @throws HttpError 非法模型/属性/时间范围，或无当前租户查询权限。
     */
    public static function curve(Connection $connection, Identity $identity, string $tenantId, string $deviceId, array $filters): array
    {
        self::authorize($connection, $identity, $tenantId, $deviceId);
        self::filters($filters);
        if (empty($filters['product_id']) || empty($filters['ownership_id']) || empty($filters['model_version']) || empty($filters['field'])) {
            throw new HttpError(422, 'history_curve_scope_required');
        }
        $model = ProductService::publishedModel($connection, $tenantId, $filters['product_id'], (int) $filters['model_version']);
        $property = null;
        foreach ($model['definition']['properties'] as $candidate) {
            if ($candidate['identifier'] === $filters['field']) {
                $property = $candidate;
            }
        }
        if ($property === null || !in_array($property['type'], ['number', 'integer'], true)) {
            throw new HttpError(422, 'history_numeric_field_required');
        }
        $window = self::window($filters);
        $scoped = $filters;
        unset($scoped['field']);
        [$where, $parameters] = self::conditions($connection, $tenantId, $deviceId, $scoped, $window);
        $count = (int) $connection->query('SELECT COUNT(*) AS total FROM iot_ingestion_facts f WHERE ' . $where, $parameters)[0]['total'];
        $rawValue = self::valueSql($connection, $filters['field']);
        $value = match ($connection->driverName()) {
            'pgsql' => 'CAST(' . $rawValue . ' AS DOUBLE PRECISION)',
            'mysql' => 'CAST(' . $rawValue . ' AS DOUBLE)',
            default => 'CAST(' . $rawValue . ' AS REAL)',
        };
        $points = [];
        $granularity = 0;
        if ($count <= 2000) {
            $rows = $connection->query('SELECT f.message_id, f.sampled_at, f.received_at, f.sequence, ' . $value . ' AS value'
                . ' FROM iot_ingestion_facts f WHERE ' . $where . ' ORDER BY f.sampled_at, LENGTH(f.sequence), f.sequence, f.message_id LIMIT 2000', $parameters);
            foreach ($rows as $row) {
                $points[] = ['sampled_at' => (int) $row['sampled_at'], 'received_at' => (int) $row['received_at'],
                    'value' => $row['value'] === null ? null : (float) $row['value'], 'count' => $row['value'] === null ? 0 : 1, 'sequence' => $row['sequence']];
            }
        } else {
            $granularity = (int) ceil(($window['to'] - $window['from'] + 1) / 2000.0);
            $bucket = 'FLOOR((f.sampled_at - ' . $window['from'] . ') / ' . $granularity . '.0)';
            $rows = $connection->query('SELECT ' . $bucket . ' AS bucket, AVG(' . $value . ') AS value, COUNT(' . $value . ') AS samples'
                . ' FROM iot_ingestion_facts f WHERE ' . $where . ' GROUP BY ' . $bucket . ' ORDER BY bucket LIMIT 2000', $parameters);
            $buckets = [];
            foreach ($rows as $row) {
                $buckets[(int) $row['bucket']] = ['value' => $row['value'] === null ? null : (float) $row['value'], 'count' => (int) $row['samples']];
            }
            $limit = (int) ceil(($window['to'] - $window['from'] + 1) / (float) $granularity);
            for ($index = 0; $index < $limit; $index++) {
                $points[] = ['sampled_at' => $window['from'] + $index * $granularity, 'received_at' => null,
                    'value' => $buckets[$index]['value'] ?? null, 'count' => $buckets[$index]['count'] ?? 0, 'sequence' => null];
            }
        }
        return ['points' => $points, 'raw_count' => $count, 'granularity_seconds' => $granularity,
            'function' => $granularity === 0 ? 'raw' : 'display_average', 'property' => $property,
            'product_id' => $filters['product_id'], 'model_version' => (int) $filters['model_version'], 'ownership_id' => $filters['ownership_id'], 'window' => $window];
    }

    /**
     * 到期七天才可删除；固定current、aggregate、alarm任一未完成都保留并报告阻塞。
     * 一个事务至多检查1000条账本，逐条锁账本/原始事实并按外键顺序删除；当前值不参与清理。
     * @return array{examined: int, deleted: int, blocked: int, has_more: bool, cutoff: int, next_cursor: string|null}
     * @throws InvalidArgumentException 批次或续扫游标无效。
     */
    public static function prune(Connection $connection, int $batch, string $cursor = ''): array
    {
        if ($batch < 1 || $batch > 1000 || ($cursor !== '' && !preg_match('/^[0-9]{1,12}:[a-f0-9]{64}$/D', $cursor))) {
            throw new InvalidArgumentException('history_cleanup_invalid');
        }
        $required = ['current', 'aggregate', 'alarm'];
        $cutoff = time() - 7 * 86400;
        return $connection->transaction(static function (Connection $transaction) use ($batch, $required, $cutoff, $cursor): array {
            $where = 'received_at <= ?';
            $parameters = [$cutoff];
            if ($cursor !== '') {
                $parts = explode(':', $cursor);
                $where .= ' AND (received_at > ? OR (received_at = ? AND message_id > ?))';
                array_push($parameters, (int) $parts[0], (int) $parts[0], $parts[1]);
            }
            $rows = $transaction->query('SELECT message_id, received_at FROM iot_ingestion WHERE ' . $where . ' ORDER BY received_at, message_id LIMIT ' . ($batch + 1), $parameters);
            $deleted = 0;
            $blocked = 0;
            $next = null;
            foreach (array_slice($rows, 0, $batch) as $candidate) {
                $query = $transaction->table('iot_ingestion')->where('message_id', '=', $candidate['message_id'])->where('received_at', '<=', $cutoff);
                $ledger = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
                if ($ledger !== null) {
                    $factQuery = $transaction->table('iot_ingestion_facts')->where('message_id', '=', $candidate['message_id']);
                    $fact = ($transaction->driverName() === 'sqlite' ? $factQuery : $factQuery->lockForUpdate())->first();
                    $ready = $ledger['status'] === 'rejected' || ($ledger['status'] === 'accepted' && $fact !== null);
                    if ($fact !== null) {
                        foreach ($required as $consumer) {
                            if ($transaction->table('iot_ingestion_completed')->where('message_id', '=', $candidate['message_id'])->where('consumer', '=', $consumer)->first() === null) {
                                $ready = false;
                            }
                        }
                    }
                    if (!$ready) {
                        $blocked++;
                    } else {
                        $transaction->table('iot_ingestion_completed')->where('message_id', '=', $candidate['message_id'])->delete();
                        $factQuery->delete();
                        $deleted += $query->delete();
                    }
                }
                $next = (string) $candidate['received_at'] . ':' . $candidate['message_id'];
            }
            $hasMore = count($rows) > $batch;
            return ['examined' => min(count($rows), $batch), 'deleted' => $deleted, 'blocked' => $blocked, 'has_more' => $hasMore,
                'cutoff' => $cutoff, 'next_cursor' => $hasMore ? $next : null];
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /**
     * 原始与分钟查询共用当前成员授权；历史设备可由任一保留数据证明，不继承当前的新归属。
     * @throws HttpError 无当前租户读取权限，或设备从未在该租户留下可查询归属。
     */
    public static function authorize(Connection $connection, Identity $identity, string $tenantId, string $deviceId): void
    {
        $current = (new IdentityService('customer'))->refresh($connection, $identity);
        if ($current === null) {
            throw new HttpError(401, 'unauthenticated');
        }
        if (!in_array('customer.telemetry.read', RoleService::permissions($connection, $current, 'customer', $tenantId), true)) {
            throw new HttpError(403, 'permission_denied');
        }
        self::requireDeviceScope($connection, $tenantId, $deviceId);
    }

    /** 历史查询与导出各自授权后共用归属证明；不以设备当前所属取代历史阶段。 */
    public static function requireDeviceScope(Connection $connection, string $tenantId, string $deviceId): void
    {
        if ($connection->table('iot_devices')->where('tenant_id', '=', $tenantId)->where('id', '=', $deviceId)->first() === null
            && $connection->table('iot_device_ownerships')->where('tenant_id', '=', $tenantId)->where('device_id', '=', $deviceId)->limit(1)->first() === null
            && $connection->table('iot_ingestion_facts')->where('tenant_id', '=', $tenantId)->where('device_id', '=', $deviceId)->limit(1)->first() === null
            && $connection->table('iot_minute_aggregates')->where('tenant_id', '=', $tenantId)->where('device_id', '=', $deviceId)->limit(1)->first() === null) {
            throw new HttpError(404, 'device_not_found');
        }
    }

    /**
     * 历史视图共用UTC范围校验；保留天数与最大跨度由调用视图固定，用户不能覆盖。
     * @param array<string, mixed> $filters 可选Unix秒from/to。
     * @return array{from: int, to: int, snapshot: int, retention_cutoff: int}
     * @throws HttpError 时间非法、跨度超限或结束时间超前超过5秒。
     */
    public static function window(array $filters, int $retentionDays = 7, int $maxSpanDays = 9): array
    {
        $snapshot = time();
        $from = (int) ($filters['from'] ?? ($snapshot - 86400));
        $to = (int) ($filters['to'] ?? $snapshot);
        if ($from < 1 || $to < $from || $to - $from > $maxSpanDays * 86400 || $to > $snapshot + 5) {
            throw new HttpError(422, 'invalid_time_range');
        }
        return ['from' => $from, 'to' => $to, 'snapshot' => $snapshot, 'retention_cutoff' => $snapshot - $retentionDays * 86400];
    }

    /**
     * 原始与分钟服务共同拒绝未知筛选，调用者不能绕过HTTP白名单或注入动态标识符。
     * @param array<string, mixed> $filters 历史公共筛选字段。
     * @throws HttpError 未知字段或非法标识符、模型及游标。
     */
    public static function filters(array $filters): void
    {
        if (array_diff(array_keys($filters), ['from', 'to', 'product_id', 'ownership_id', 'model_version', 'field', 'page', 'per_page', 'sort', 'cursor']) !== []) {
            throw new HttpError(422, 'history_filter_invalid');
        }
        foreach (['product_id', 'ownership_id'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '' && (!is_string($filters[$key]) || !preg_match('/^[a-f0-9]{32}$/D', $filters[$key]))) {
                throw new HttpError(422, 'history_filter_invalid');
            }
        }
        if ((isset($filters['field']) && (!is_string($filters['field']) || !preg_match('/^([a-zA-Z_][a-zA-Z0-9_]{0,63})?$/D', $filters['field'])))
            || (isset($filters['model_version']) && (!is_int($filters['model_version']) || $filters['model_version'] < 1))
            || (isset($filters['cursor']) && (!is_string($filters['cursor']) || strlen($filters['cursor']) > 1024))) {
            throw new HttpError(422, 'history_filter_invalid');
        }
    }

    /**
     * 历史页面与导出快照共用同一筛选表达式；调用者先完成authorize、filters及window。
     * @internal
     * @return array{string, list<mixed>} 固定事实别名f的SQL条件与绑定值。
     */
    public static function conditions(Connection $connection, string $tenantId, string $deviceId, array $filters, array $window): array
    {
        $where = "f.tenant_id = ? AND f.device_id = ? AND f.type = 'telemetry' AND f.received_at > ? AND f.received_at < ? AND f.sampled_at >= ? AND f.sampled_at <= ?";
        $parameters = [$tenantId, $deviceId, $window['retention_cutoff'], $window['snapshot'], $window['from'], $window['to']];
        foreach (['product_id', 'ownership_id', 'model_version'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $where .= ' AND f.' . $key . ' = ?';
                $parameters[] = $filters[$key];
            }
        }
        if (($filters['field'] ?? '') !== '') {
            $where .= ' AND ' . self::valueSql($connection, $filters['field']) . ' IS NOT NULL';
        }
        return [$where, $parameters];
    }

    /** 字段先按物模型标识符校验，再生成三库各自JSON取值表达式；不接受SQL/JSONPath片段。 */
    private static function valueSql(Connection $connection, string $field): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/D', $field)) {
            throw new HttpError(422, 'history_filter_invalid');
        }
        return match ($connection->driverName()) {
            'pgsql' => "(f.values_json::jsonb ->> '" . $field . "')",
            'mysql' => "JSON_UNQUOTE(JSON_EXTRACT(f.values_json, '$." . $field . "'))",
            default => "json_extract(f.values_json, '$." . $field . "')",
        };
    }
}
