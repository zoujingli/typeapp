<?php

declare(strict_types=1);

namespace app\iot\service;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Orm\Connection;

/** 分钟统计的持久所有者；一个归属、产品、模型和UTC分钟保存一行，原始事实独立到期。 */
final class AggregateService
{
    /**
     * 有界消费已经接受的事实；统计效果和aggregate完成事实同事务，不等待或确认alarm。
     * 每条事实独立提交，进程中断后重试；事件、无数值字段或已过90天的窗口也记录真实处理完成。
     * @return array{examined: int, completed: int, already_completed: int, has_more: bool}
     * @throws InvalidArgumentException 批次不在1至100范围。
     * @throws RuntimeException 数值累计超出有限浮点或精确计数范围，此事实保持待处理。
     */
    public static function run(Connection $connection, int $batch = 100): array
    {
        $facts = IngestionService::pending($connection, 'aggregate', $batch);
        $completed = 0;
        foreach ($facts as $fact) {
            if (IngestionService::consume($connection, $fact['message_id'], 'aggregate', static function (Connection $transaction, array $accepted): void {
                self::apply($transaction, $accepted);
            })) {
                $completed++;
            }
        }
        return ['examined' => count($facts), 'completed' => $completed, 'already_completed' => count($facts) - $completed,
            'has_more' => IngestionService::pending($connection, 'aggregate', 1) !== []];
    }

    /**
     * 复用历史授权、筛选及键集翻页；每页最多100个UTC分钟行，字段带冻结模型和六项统计。
     * 游标固定时间范围与排序位置，不保留跨请求数据库快照；迟到修正可改变既有行的值。
     * @param array<string, mixed> $filters 历史筛选；只允许sampled_asc或sampled_desc。
     * @return array<string, mixed> items、分页、下一页游标和按分钟覆盖的查询范围。
     * @throws HttpError 无权限、无历史设备或筛选、游标、分页无效。
     */
    public static function search(Connection $connection, Identity $identity, string $tenantId, string $deviceId, array $filters): array
    {
        HistoryService::authorize($connection, $identity, $tenantId, $deviceId);
        HistoryService::filters($filters);
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
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100 || !in_array($sort, ['sampled_asc', 'sampled_desc'], true)) {
            throw new HttpError(422, 'history_filter_invalid');
        }
        $fingerprint = hash('sha256', json_encode(['minutes', $tenantId, $deviceId, $window['from'], $window['to'], $filters['product_id'] ?? '',
            $filters['model_version'] ?? 0, $filters['ownership_id'] ?? '', $filters['field'] ?? '', $sort, $perPage], JSON_THROW_ON_ERROR));
        if ($cursor !== null) {
            if (($cursor['query'] ?? '') !== $fingerprint || ($cursor['page'] ?? 0) !== $page || !is_int($cursor['issued'] ?? null)
                || !is_int($cursor['time'] ?? null) || !is_string($cursor['id'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $cursor['id']) || $cursor['issued'] > time()) {
                throw new HttpError(422, 'history_cursor_invalid');
            }
            if ($cursor['issued'] < time() - 900) {
                throw new HttpError(409, 'history_snapshot_expired');
            }
        } elseif ($page !== 1) {
            throw new HttpError(422, 'history_page_out_of_range');
        }
        [$where, $parameters] = self::conditions($connection, $tenantId, $deviceId, $filters, $window);
        $total = (int) $connection->query('SELECT COUNT(*) AS total FROM iot_minute_aggregates a WHERE ' . $where, $parameters)[0]['total'];
        $direction = $sort === 'sampled_desc' ? 'DESC' : 'ASC';
        if ($cursor !== null) {
            $operator = $direction === 'DESC' ? '<' : '>';
            $where .= ' AND (a.window_start ' . $operator . ' ? OR (a.window_start = ? AND a.id ' . $operator . ' ?))';
            array_push($parameters, $cursor['time'], $cursor['time'], $cursor['id']);
        }
        $rows = $connection->query('SELECT a.*, m.definition AS model_definition FROM iot_minute_aggregates a'
            . ' JOIN iot_models m ON m.tenant_id = a.tenant_id AND m.product_id = a.product_id AND m.model_version = a.model_version'
            . ' WHERE ' . $where . ' ORDER BY a.window_start ' . $direction . ', a.id ' . $direction . ' LIMIT ' . ($perPage + 1), $parameters);
        if ($page > 1 && $rows === []) {
            throw new HttpError(422, 'history_page_out_of_range');
        }
        $items = [];
        foreach (array_slice($rows, 0, $perPage) as $row) {
            $row['fields'] = json_decode((string) $row['fields_json'], true, 32, JSON_THROW_ON_ERROR);
            foreach ($row['fields'] as $field => $statistics) {
                $row['fields'][$field]['avg'] = (float) $statistics['sum'] / (float) $statistics['count'];
            }
            $row['model'] = json_decode((string) $row['model_definition'], true, 32, JSON_THROW_ON_ERROR);
            unset($row['fields_json'], $row['model_definition']);
            foreach (['model_version', 'window_start', 'window_end', 'updated_at'] as $key) {
                $row[$key] = (int) $row[$key];
            }
            $items[] = $row;
        }
        $next = null;
        if (count($rows) > $perPage) {
            $last = $items[count($items) - 1];
            $next = rtrim(strtr(base64_encode(json_encode(['query' => $fingerprint, 'page' => $page + 1, 'issued' => $cursor['issued'] ?? time(),
                'from' => $window['from'], 'to' => $window['to'], 'time' => $last['window_start'], 'id' => $last['id']], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        }
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'next_cursor' => $next, 'sort' => $sort, 'window' => $window];
    }

    /**
     * 单图固定产品、模型、归属和数值属性；数据库合并分钟统计，avg按总sum/count，last按双键选取。
     * 最多2000个整分钟倍数的桶；缺测的value和statistics为null，不把无样本的count画为零。
     * @param array<string, mixed> $filters 历史筛选及stat=count|min|max|sum|avg|last，默认avg。
     * @return array<string, mixed> 统计点、有效样本数、原分钟数、实际粒度、模型属性与时间范围。
     * @throws HttpError 无权限、缺少范围或非数值属性。
     */
    public static function curve(Connection $connection, Identity $identity, string $tenantId, string $deviceId, array $filters): array
    {
        HistoryService::authorize($connection, $identity, $tenantId, $deviceId);
        $stat = $filters['stat'] ?? 'avg';
        unset($filters['stat']);
        HistoryService::filters($filters);
        if (!in_array($stat, ['count', 'min', 'max', 'sum', 'avg', 'last'], true)
            || ($filters['cursor'] ?? '') !== '' || (int) ($filters['page'] ?? 1) !== 1 || (int) ($filters['per_page'] ?? 20) !== 20) {
            throw new HttpError(422, 'history_filter_invalid');
        }
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
        [$where, $parameters] = self::conditions($connection, $tenantId, $deviceId, $filters, $window);
        $minutes = (int) (($window['end'] - $window['start']) / 60);
        $granularity = 60 * (int) ceil($minutes / 2000.0);
        $bucket = 'FLOOR((a.window_start - ' . $window['start'] . ') / ' . $granularity . '.0)';
        $columns = [];
        foreach (['count', 'min', 'max', 'sum', 'last', 'last_sampled_at', 'last_sequence'] as $key) {
            $expression = self::valueSql($connection, $filters['field'], $key);
            if ($key !== 'last_sequence') {
                $type = in_array($key, ['count', 'last_sampled_at'], true) ? ($connection->driverName() === 'mysql' ? 'SIGNED' : 'BIGINT')
                    : ($connection->driverName() === 'pgsql' ? 'DOUBLE PRECISION' : ($connection->driverName() === 'mysql' ? 'DOUBLE' : 'REAL'));
                $expression = 'CAST(' . $expression . ' AS ' . $type . ')';
            }
            $columns[] = $expression . ' AS stat_' . $key;
        }
        $rows = $connection->query('WITH scoped AS (SELECT ' . $bucket . ' AS bucket, ' . implode(', ', $columns)
            . ' FROM iot_minute_aggregates a WHERE ' . $where . '), ranked AS (SELECT scoped.*,'
            . ' ROW_NUMBER() OVER (PARTITION BY bucket ORDER BY stat_last_sampled_at DESC, LENGTH(stat_last_sequence) DESC, stat_last_sequence DESC) AS last_rank FROM scoped)'
            . ' SELECT bucket, COUNT(*) AS minute_count, SUM(stat_count) AS sample_count, MIN(stat_min) AS value_min, MAX(stat_max) AS value_max, SUM(stat_sum) AS value_sum,'
            . ' MAX(CASE WHEN last_rank = 1 THEN stat_last END) AS value_last, MAX(CASE WHEN last_rank = 1 THEN stat_last_sampled_at END) AS last_sampled_at,'
            . ' MAX(CASE WHEN last_rank = 1 THEN stat_last_sequence END) AS last_sequence FROM ranked GROUP BY bucket ORDER BY bucket LIMIT 2000', $parameters);
        $buckets = [];
        $sampleCount = 0;
        $minuteCount = 0;
        foreach ($rows as $row) {
            $count = (int) $row['sample_count'];
            $sum = (float) $row['value_sum'];
            if (!is_finite($sum)) {
                throw new HttpError(422, 'aggregate_numeric_overflow');
            }
            $statistics = ['count' => $count, 'min' => (float) $row['value_min'], 'max' => (float) $row['value_max'], 'sum' => $sum,
                'avg' => $sum / (float) $count, 'last' => (float) $row['value_last'], 'last_sampled_at' => (int) $row['last_sampled_at'], 'last_sequence' => $row['last_sequence']];
            $buckets[(int) $row['bucket']] = $statistics;
            $sampleCount += $count;
            $minuteCount += (int) $row['minute_count'];
        }
        $points = [];
        $limit = (int) ceil(($window['end'] - $window['start']) / (float) $granularity);
        for ($index = 0; $index < $limit; $index++) {
            if (!isset($buckets[$index])) {
                $points[] = ['sampled_at' => $window['start'] + $index * $granularity, 'received_at' => null,
                    'value' => null, 'count' => 0, 'sequence' => null, 'statistics' => null];
                continue;
            }
            $pointStatistics = $buckets[$index];
            $points[] = ['sampled_at' => $window['start'] + $index * $granularity, 'received_at' => null,
                'value' => $pointStatistics[$stat], 'count' => $pointStatistics['count'],
                'sequence' => $pointStatistics['last_sequence'], 'statistics' => $pointStatistics];
        }
        return ['points' => $points, 'raw_count' => $sampleCount, 'minute_count' => $minuteCount, 'granularity_seconds' => $granularity,
            'function' => 'minute_aggregate', 'statistic' => $stat, 'property' => $property, 'product_id' => $filters['product_id'],
            'model_version' => (int) $filters['model_version'], 'ownership_id' => $filters['ownership_id'], 'window' => $window];
    }

    /**
     * 只删除窗口结束已满90天的统计，一次事务最多1000行；中断回滚，重复运行不影响原始或当前事实。
     * @return array{examined: int, deleted: int, has_more: bool, cutoff: int}
     * @throws InvalidArgumentException 批次不在1至1000范围。
     */
    public static function prune(Connection $connection, int $batch = 1000): array
    {
        if ($batch < 1 || $batch > 1000) {
            throw new InvalidArgumentException('aggregate_cleanup_invalid');
        }
        $cutoff = time() - 90 * 86400;
        return $connection->transaction(static function (Connection $transaction) use ($batch, $cutoff): array {
            $rows = $transaction->query('SELECT id FROM iot_minute_aggregates WHERE window_end <= ? ORDER BY window_end, id LIMIT ' . ($batch + 1), [$cutoff]);
            $deleted = 0;
            foreach (array_slice($rows, 0, $batch) as $row) {
                $deleted += $transaction->table('iot_minute_aggregates')->where('id', '=', $row['id'])->where('window_end', '<=', $cutoff)->delete();
            }
            return ['examined' => min(count($rows), $batch), 'deleted' => $deleted, 'has_more' => count($rows) > $batch, 'cutoff' => $cutoff];
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /** 消费者已锁定事实；通过唯一分钟行的无内容覆盖upsert建立行锁，避免不同事实并发丢失累计。 */
    private static function apply(Connection $connection, array $fact): void
    {
        $start = intdiv($fact['sampled_at'], 60) * 60;
        if ($fact['type'] !== 'telemetry' || $start + 60 <= time() - 90 * 86400) {
            return;
        }
        $model = ProductService::publishedModel($connection, $fact['tenant_id'], $fact['product_id'], $fact['model_version']);
        $numeric = [];
        foreach ($model['definition']['properties'] as $property) {
            if (in_array($property['type'], ['integer', 'number'], true) && array_key_exists($property['identifier'], $fact['values'])) {
                $numeric[$property['identifier']] = $fact['values'][$property['identifier']];
            }
        }
        if ($numeric === []) {
            return;
        }
        $id = hash('sha256', implode(':', [$fact['tenant_id'], $fact['device_id'], $fact['product_id'], $fact['model_version'], $fact['ownership_id'], $start]));
        $table = $connection->table('iot_minute_aggregates');
        $initial = ['id' => $id, 'tenant_id' => $fact['tenant_id'], 'device_id' => $fact['device_id'], 'product_id' => $fact['product_id'],
            'model_version' => $fact['model_version'], 'ownership_id' => $fact['ownership_id'], 'window_start' => $start, 'window_end' => $start + 60,
            'fields_json' => '{}', 'updated_at' => time()];
        if ($connection->driverName() === 'mysql') {
            $table->upsertAnyUnique([$initial], ['id']);
        } else {
            $table->upsert([$initial], ['id'], ['id']);
        }
        $query = $table->where('id', '=', $id);
        $row = ($connection->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
        $fields = json_decode((string) $row['fields_json'], true, 32, JSON_THROW_ON_ERROR);
        foreach ($numeric as $field => $value) {
            $previous = $fields[$field] ?? null;
            $sum = (float) ($previous['sum'] ?? 0) + (float) $value;
            $count = (int) ($previous['count'] ?? 0) + 1;
            if (!is_finite($sum) || $count > 9007199254740991) {
                throw new RuntimeException('aggregate_numeric_overflow');
            }
            $newLast = $previous === null || $fact['sampled_at'] > $previous['last_sampled_at']
                || ($fact['sampled_at'] === $previous['last_sampled_at'] && (strlen($fact['sequence']) > strlen($previous['last_sequence'])
                    || (strlen($fact['sequence']) === strlen($previous['last_sequence']) && strcmp($fact['sequence'], $previous['last_sequence']) > 0)));
            $fields[$field] = ['count' => $count, 'min' => $previous === null ? $value : min($previous['min'], $value),
                'max' => $previous === null ? $value : max($previous['max'], $value), 'sum' => $sum,
                'last' => $newLast ? $value : $previous['last'], 'last_sampled_at' => $newLast ? $fact['sampled_at'] : $previous['last_sampled_at'],
                'last_sequence' => $newLast ? $fact['sequence'] : $previous['last_sequence']];
        }
        $query->update(['fields_json' => json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), 'updated_at' => time()]);
    }

    /** @return array<string, int> 页面和导出共用UTC整分钟范围及九十天保留边界。 */
    public static function window(array $filters): array
    {
        $window = HistoryService::window($filters, 90, 90);
        $window['start'] = intdiv($window['from'], 60) * 60;
        $window['end'] = intdiv($window['to'], 60) * 60 + 60;
        return $window;
    }

    /**
     * 页面与导出快照共用筛选；调用者先完成历史授权、filters与window。
     * @internal
     * @return array{string, list<mixed>} 固定分钟别名a的SQL条件与绑定值。
     */
    public static function conditions(Connection $connection, string $tenantId, string $deviceId, array $filters, array $window): array
    {
        $where = 'a.tenant_id = ? AND a.device_id = ? AND a.window_end > ? AND a.window_start >= ? AND a.window_start < ?';
        $parameters = [$tenantId, $deviceId, $window['retention_cutoff'], $window['start'], $window['end']];
        foreach (['product_id', 'ownership_id', 'model_version'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $where .= ' AND a.' . $key . ' = ?';
                $parameters[] = $filters[$key];
            }
        }
        if (($filters['field'] ?? '') !== '') {
            $where .= ' AND ' . self::valueSql($connection, $filters['field'], 'count') . ' IS NOT NULL';
        }
        return [$where, $parameters];
    }

    /** 物模型字段先按标识符规则验证；三库JSON差异只在本表达式内，不接受调用者SQL。 */
    private static function valueSql(Connection $connection, string $field, string $stat): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/D', $field)) {
            throw new HttpError(422, 'history_filter_invalid');
        }
        return match ($connection->driverName()) {
            'pgsql' => "(a.fields_json::jsonb -> '" . $field . "' ->> '" . $stat . "')",
            'mysql' => "JSON_UNQUOTE(JSON_EXTRACT(a.fields_json, '$." . $field . '.' . $stat . "'))",
            default => "json_extract(a.fields_json, '$." . $field . '.' . $stat . "')",
        };
    }
}
