<?php

declare(strict_types=1);

namespace app\broker\service;

use Type\Core\Http\HttpError;
use Type\Mqtt\PendingCommit;
use Type\Orm\Connection;

/** 两个管理宿主共用的有界查询；认证由宿主负责，逐页绑定当前身份和范围。 */
final class ResourceQueries
{
    private static bool $quarantined = false;

    /** 资源释放未知后只允许重启已完成清理的管理实例，不由另一次成功读取解除。 */
    public static function quarantine(): void
    {
        self::$quarantined = true;
    }

    /** 同一管理实例的指标和资源查询共用单向隔离事实。 */
    public static function quarantined(): bool
    {
        return self::$quarantined;
    }

    /**
     * @param array{all_metadata:bool,resource_scope?:string,topic_namespace?:string} $authorization 已经重新授权的明确范围。
     * @param list<string> $worker 当前宿主配置的持久存储命令；空表示仅提供实时观察。
     * @param string $context 当前宿主的准确人员、会话、租户、权限及模拟来源；只绑定游标，不代替逐次授权。
     * @return array<string,mixed> 列表或详情白名单；无消息原件、属性或秘密。
     */
    public static function query(Connection $connection, array $worker, string $resource, string $query, string $id, array $authorization, string $context): array
    {
        $allowed = match ($resource) {
            'nodes' => ['node_id', 'state'],
            'connections', 'sessions' => ['client_id', 'session_id', 'node_id', 'state'],
            'subscriptions' => ['client_id', 'session_id', 'node_id', 'state', 'qos'],
            'retained' => ['topic', 'state', 'qos'],
            'backlog' => ['topic', 'session_id', 'state', 'qos', 'kind'],
            default => throw new HttpError(400, 'broker_resource_invalid'),
        };
        if (strlen($query) > 262144 || substr_count($query, '&') > count($allowed) + 1) {
            throw new HttpError(400, 'broker_resource_query_invalid');
        }
        // 字段集合很小，逐项解码可拒绝重复字段，也不会触发 PHP 的输入截断或名称改写。
        $input = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $parts = explode('=', $pair, 2);
            $name = urldecode($parts[0]);
            if (array_key_exists($name, $input)) {
                throw new HttpError(400, 'broker_resource_query_invalid');
            }
            $input[$name] = urldecode($parts[1] ?? '');
        }
        $filters = [];
        foreach ($input as $field => $value) {
            if (!in_array($field, [...$allowed, 'limit', 'cursor'], true) || !is_string($value)
                || strlen($value) > 65535 || preg_match('//u', $value) !== 1 || str_contains($value, "\0")) {
                throw new HttpError(400, 'broker_resource_query_invalid');
            }
            if ($value !== '' && in_array($field, $allowed, true)) {
                if ($field === 'qos' && !in_array($value, ['0', '1', '2'], true)) {
                    throw new HttpError(400, 'broker_resource_query_invalid');
                }
                $filters[$field] = $field === 'qos' ? (int) $value : $value;
            }
        }
        $limit = $input['limit'] ?? '20';
        if (preg_match('/^[1-9][0-9]{0,2}$/D', $limit) !== 1 || (int) $limit > 100) {
            throw new HttpError(400, 'broker_resource_query_invalid');
        }
        $idPattern = match ($resource) {
            'subscriptions' => '/^(?:v:)?[a-f0-9]{64}$/D',
            'backlog' => '/^(?:m:[a-f0-9]{32}|d:[a-f0-9]{64})$/D',
            default => '/^[a-f0-9]{32}$/D',
        };
        if ($id !== '' && preg_match($idPattern, $id) !== 1) {
            throw new HttpError(400, 'broker_resource_id_invalid');
        }
        ksort($filters);
        $binding = hash('sha256', json_encode([$context, $authorization, $resource, $filters, (int) $limit], JSON_THROW_ON_ERROR));
        $phase = in_array($resource, ['nodes', 'connections'], true) || $worker === [] ? 'live' : 'durable';
        $cursor = null;
        if (($input['cursor'] ?? '') !== '') {
            if ($id !== '') {
                throw new HttpError(400, 'broker_resource_cursor_invalid');
            }
            $decoded = self::cursor($input['cursor']);
            if (($decoded['context'] ?? null) !== $binding || !in_array($decoded['phase'] ?? null, ['live', 'durable'], true)
                || !array_key_exists('cursor', $decoded) || ($decoded['cursor'] !== null && !is_string($decoded['cursor']))
                || count($decoded) !== 3) {
                throw new HttpError(400, 'broker_resource_cursor_invalid');
            }
            $phase = $decoded['phase'];
            $cursor = $decoded['cursor'];
            if (($phase === 'live' && !in_array($resource, ['nodes', 'connections', 'subscriptions'], true))
                || ($phase === 'durable' && (in_array($resource, ['nodes', 'connections'], true) || $worker === []))) {
                throw new HttpError(400, 'broker_resource_cursor_invalid');
            }
            if ($cursor !== null) {
                $inner = self::cursor($cursor);
                if (count($inner) !== 2 || !is_string($inner['context'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $inner['context']) !== 1
                    || !is_string($inner['after'] ?? null) || str_starts_with($inner['after'], 'v:') || preg_match($idPattern, $inner['after']) !== 1) {
                    throw new HttpError(400, 'broker_resource_cursor_invalid');
                }
                $expected = $phase === 'durable'
                    ? [$resource, $authorization['all_metadata'], $authorization['resource_scope'] ?? null, $authorization['topic_namespace'] ?? null, $filters]
                    : [$resource, $authorization, $filters, $resource === 'subscriptions' && $worker !== []];
                if ($inner['context'] !== hash('sha256', json_encode($expected, JSON_THROW_ON_ERROR))) {
                    throw new HttpError(400, 'broker_resource_cursor_invalid');
                }
            }
        }
        if ($id !== '' && $resource === 'subscriptions') {
            $phase = str_starts_with($id, 'v:') ? 'live' : 'durable';
        }
        if ($id !== '' && str_starts_with($id, 'v:')) {
            if ($resource !== 'subscriptions') {
                throw new HttpError(400, 'broker_resource_id_invalid');
            }
            $id = substr($id, 2);
        }
        $request = ['action' => $id === '' ? 'resource_list' : 'resource_detail', 'resource' => $resource,
            'authorization' => $authorization, 'filters' => $filters, 'limit' => (int) $limit, 'cursor' => $cursor];
        if ($id !== '') {
            $request['id'] = $id;
        }
        if (!in_array($resource, ['nodes', 'connections', 'subscriptions'], true) && $worker === []) {
            throw new HttpError(503, 'broker_resource_store_unavailable');
        }
        try {
            $hasLive = false;
            if ($resource === 'subscriptions' && $phase === 'durable' && $id === '') {
                $live = ResourceObservations::resources($connection, [
                    'action' => 'resource_list', 'resource' => $resource, 'authorization' => $authorization,
                    'filters' => $filters, 'limit' => 1,
                ], true);
                $hasLive = $live['items'] !== [];
            }
            $result = $phase === 'live'
                ? ResourceObservations::resources($connection, $request, $resource === 'subscriptions' && $worker !== [])
                : self::stored($connection, $worker, $request);
        } catch (\InvalidArgumentException|\JsonException) {
            throw new HttpError(400, 'broker_resource_query_invalid');
        }
        if ($id !== '') {
            if (!$result['found']) {
                throw new HttpError(404, 'broker_resource_not_found');
            }
            $result['item']['source'] = $result['source'];
            if ($resource === 'subscriptions' && $phase === 'live') {
                $result['item']['id'] = 'v:' . $result['item']['id'];
            }
            return $result;
        }
        foreach ($result['items'] as $index => $item) {
            $result['items'][$index]['source'] = $result['source'];
            if ($resource === 'subscriptions' && $phase === 'live') {
                $result['items'][$index]['id'] = 'v:' . $item['id'];
            }
        }
        // 持久订阅在前、纯实时订阅在后；只读取一页，不在内存拉全量排序。
        $nextPhase = $phase;
        $nextCursor = $result['next_cursor'];
        if ($resource === 'subscriptions' && $phase === 'durable' && !$result['has_more'] && $hasLive) {
            $nextPhase = 'live';
            $nextCursor = null;
            $result['has_more'] = true;
        }
        $result['next_cursor'] = $result['has_more'] ? base64_encode(json_encode([
            'context' => $binding, 'phase' => $nextPhase, 'cursor' => $nextCursor,
        ], JSON_THROW_ON_ERROR)) : null;
        if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > 1048576) {
            throw new HttpError(503, 'broker_resource_response_budget');
        }
        return $result;
    }

    private static function cursor(string $encoded): array
    {
        if (strlen($encoded) > 2048) {
            throw new HttpError(400, 'broker_resource_cursor_invalid');
        }
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || base64_encode($decoded) !== $encoded) {
            throw new HttpError(400, 'broker_resource_cursor_invalid');
        }
        try {
            $data = json_decode($decoded, true, 6, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpError(400, 'broker_resource_cursor_invalid');
        }
        if (!is_array($data)) {
            throw new HttpError(400, 'broker_resource_cursor_invalid');
        }
        return $data;
    }

    private static function stored(Connection $connection, array $worker, array $request): array
    {
        $connection->close();
        if ($worker === [] || self::$quarantined) {
            throw new HttpError(503, 'broker_resource_store_unavailable');
        }
        $pending = new PendingCommit($worker, $request + ['operation_id' => bin2hex(random_bytes(16))]);
        do {
            $result = $pending->poll();
            if ($result === null) {
                usleep(10000);
            }
        } while ($result === null);
        if (!$result->released) {
            self::$quarantined = true;
        }
        if ($result->state !== 'committed' || self::$quarantined) {
            throw new HttpError(503, 'broker_resource_store_unavailable');
        }
        return $result->value;
    }
}
