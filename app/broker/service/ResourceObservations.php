<?php

declare(strict_types=1);

namespace app\broker\service;

use Type\Mqtt\AccessIdentity;
use Type\Orm\Connection;
use Type\Orm\Query;

/** 无秘密的节点、连接及订阅事实；运行过期呈现未知，精确所有者关闭才删除实时投影。 */
final class ResourceObservations
{
    /**
     * 最多 32 个运行槽位；过期投影可整体替换，旧运行不能写入或延长新运行。
     * 调用者负责有界数据库作用域；IoT 与设备观察使用同一事务。
     */
    public static function heartbeat(Connection $connection, string $nodeId, string $runId, int $observedAt, bool $initial, bool $stopped): void
    {
        self::runIdentity($nodeId, $runId);
        $connection->transaction(static function (Connection $transaction) use ($nodeId, $runId, $observedAt, $initial, $stopped): void {
            if ($initial) {
                $query = $transaction->table('broker_resource_runs')->orderBy('slot');
                $rows = self::locked($transaction, $query)->limit(32)->get();
                $occupied = [];
                $selected = -1;
                foreach ($rows as $row) {
                    $slot = (int) $row['slot'];
                    $occupied[$slot] = $row;
                    if ($row['node_id'] === $nodeId) {
                        if ((int) $row['expires_at'] > time() && $row['run_id'] !== $runId) {
                            throw new \RuntimeException('broker_resource_node_active');
                        }
                        $selected = $slot;
                        break;
                    }
                }
                if ($selected < 0) {
                    for ($candidate = 0; $candidate < 32; $candidate++) {
                        if (!isset($occupied[$candidate]) || (int) $occupied[$candidate]['expires_at'] <= time()) {
                            $selected = $candidate;
                            break;
                        }
                    }
                }
                if ($selected < 0) {
                    throw new \RuntimeException('broker_resource_capacity');
                }
                if (isset($occupied[$selected])) {
                    $transaction->table('broker_resource_runs')->where('slot', '=', $selected)->where('run_id', '=', $occupied[$selected]['run_id'])->delete();
                }
                $transaction->table('broker_resource_runs')->insert(['slot' => $selected, 'node_id' => $nodeId, 'run_id' => $runId,
                    'observed_at' => $observedAt, 'expires_at' => $stopped ? $observedAt : $observedAt + 15]);
                return;
            }
            $run = self::locked($transaction, $transaction->table('broker_resource_runs')->where('node_id', '=', $nodeId)->where('run_id', '=', $runId))->first();
            if ($run === null) {
                throw new \RuntimeException('broker_resource_owner_lost');
            }
            $transaction->table('broker_resource_runs')->where('slot', '=', (int) $run['slot'])->where('run_id', '=', $runId)->update([
                'observed_at' => $observedAt, 'expires_at' => $stopped ? $observedAt : $observedAt + 15,
            ]);
            if ($stopped) {
                $transaction->table('broker_resource_connections')->where('observation_run', '=', $runId)->delete();
                $transaction->table('broker_resource_runs')->where('run_id', '=', $runId)->update(['connections' => 0]);
            }
        }, $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default');
    }

    /**
     * 成功 CONNACK 后保存白名单字段；每个运行至多 10100 个真实连接，不保存用户名或秘密。
     * transport 含 mtls，表示专用客户端证书入口，不是普通 TLS 上的 CONNECT 凭据。
     * @param array<string, mixed> $resource 来自 ResourceConnectionObserver 的明确资源事实。
     */
    public static function connected(Connection $connection, string $nodeId, string $runId, array $resource, int $observedAt): void
    {
        self::runIdentity($nodeId, $runId);
        self::owner($resource['owner_id']);
        if (!is_string($resource['client_id']) || $resource['client_id'] === '' || strlen($resource['client_id']) > 65535
            || preg_match('//u', $resource['client_id']) !== 1 || str_contains($resource['client_id'], "\0")
            || !in_array($resource['protocol'], [4, 5], true) || !in_array($resource['transport'], ['tcp', 'tls', 'mtls', 'ws', 'wss'], true)
            || !is_bool($resource['durable']) || !is_int($resource['session_generation']) || $resource['session_generation'] < 0
            || !is_int($resource['node_generation']) || $resource['node_generation'] < 0
            || !is_string($resource['session_id']) || ($resource['session_id'] !== '' && preg_match('/^[a-f0-9]{32}$/D', $resource['session_id']) !== 1)
            || !is_string($resource['node_run_id']) || ($resource['node_run_id'] !== '' && preg_match('/^[a-f0-9]{32}$/D', $resource['node_run_id']) !== 1)) {
            throw new \InvalidArgumentException('broker_resource_connection_invalid');
        }
        $scope = $resource['resource_scope'];
        if ($scope !== null && (!is_string($scope) || $scope === '' || strlen($scope) > 128
            || preg_match('//u', $scope) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $scope) === 1)) {
            throw new \InvalidArgumentException('broker_resource_scope_invalid');
        }
        $identity = $resource['access_identity'] === null ? null : AccessIdentity::fromData($resource['access_identity'])->data();
        $connection->transaction(static function (Connection $transaction) use ($nodeId, $runId, $resource, $observedAt, $scope, $identity): void {
            $run = self::activeRun($transaction, $nodeId, $runId);
            if ((int) $run['connections'] >= 10100) {
                throw new \RuntimeException('broker_resource_connection_capacity');
            }
            $transaction->table('broker_resource_connections')->insert([
                'owner_id' => $resource['owner_id'], 'observation_run' => $runId, 'client_id' => $resource['client_id'],
                'client_hash' => hash('sha256', $resource['client_id']), 'session_id' => $resource['session_id'],
                'session_generation' => $resource['session_generation'], 'node_id' => $nodeId, 'node_run_id' => $resource['node_run_id'],
                'node_generation' => $resource['node_generation'], 'resource_scope' => $scope, 'scope_hash' => $scope === null ? null : hash('sha256', $scope),
                'access_identity' => $identity === null ? null : json_encode($identity, JSON_THROW_ON_ERROR),
                'protocol' => $resource['protocol'], 'transport' => $resource['transport'], 'durable' => $resource['durable'] ? 1 : 0,
                'observed_at' => $observedAt,
            ]);
            $transaction->table('broker_resource_runs')->where('run_id', '=', $runId)->update(['connections' => (int) $run['connections'] + 1]);
        }, $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default');
    }

    /** 关闭只删除精确运行的连接；迟到旧 owner 不影响新连接，订阅随外键同步回收。 */
    public static function disconnected(Connection $connection, string $nodeId, string $runId, string $ownerId): void
    {
        self::runIdentity($nodeId, $runId);
        self::owner($ownerId);
        $connection->transaction(static function (Connection $transaction) use ($nodeId, $runId, $ownerId): void {
            $run = self::locked($transaction, $transaction->table('broker_resource_runs')->where('node_id', '=', $nodeId)->where('run_id', '=', $runId))->first();
            if ($run === null) {
                return;
            }
            $changed = $transaction->table('broker_resource_connections')->where('node_id', '=', $nodeId)->where('observation_run', '=', $runId)->where('owner_id', '=', $ownerId)->delete();
            if ($changed > 0) {
                $transaction->table('broker_resource_runs')->where('run_id', '=', $runId)->update(['connections' => (int) $run['connections'] - $changed]);
            }
        }, $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default');
    }

    /** @param array{options:int, identifier:int}|null $subscription 已生效选项；null 精确移除，单连接最多 100 条。 */
    public static function subscription(Connection $connection, string $nodeId, string $runId, string $ownerId, string $filter, ?array $subscription, int $observedAt): void
    {
        self::runIdentity($nodeId, $runId);
        self::owner($ownerId);
        if ($filter === '' || strlen($filter) > 65535 || preg_match('//u', $filter) !== 1 || str_contains($filter, "\0")) {
            throw new \InvalidArgumentException('broker_resource_subscription_invalid');
        }
        if ($subscription !== null && (!is_int($subscription['options'] ?? null) || $subscription['options'] < 0 || $subscription['options'] > 63
            || ($subscription['options'] & 3) > 2 || (($subscription['options'] >> 4) & 3) > 2
            || !is_int($subscription['identifier'] ?? null) || $subscription['identifier'] < 0 || $subscription['identifier'] > 268435455)) {
            throw new \InvalidArgumentException('broker_resource_subscription_invalid');
        }
        $actualFilter = $filter;
        if (str_starts_with($filter, '$share/')) {
            $parts = explode('/', $filter, 3);
            if (count($parts) !== 3 || $parts[1] === '' || strpbrk($parts[1], '+#') !== false || $parts[2] === '') {
                throw new \InvalidArgumentException('broker_resource_subscription_invalid');
            }
            $actualFilter = $parts[2];
        }
        $connection->transaction(static function (Connection $transaction) use ($nodeId, $runId, $ownerId, $filter, $actualFilter, $subscription, $observedAt): void {
            self::activeRun($transaction, $nodeId, $runId);
            $owner = self::locked($transaction, $transaction->table('broker_resource_connections')->where('owner_id', '=', $ownerId)->where('observation_run', '=', $runId))->first();
            if ($owner === null) {
                throw new \RuntimeException('broker_resource_owner_lost');
            }
            $id = hash('sha256', $ownerId . "\0" . $filter);
            $query = $transaction->table('broker_resource_subscriptions')->where('id', '=', $id)->where('owner_id', '=', $ownerId);
            if ($subscription === null) {
                $query->delete();
                return;
            }
            $values = ['topic' => $filter, 'topic_hash' => hash('sha256', $filter), 'actual_filter' => $actualFilter, 'options' => $subscription['options'],
                'identifier' => $subscription['identifier'], 'observed_at' => $observedAt];
            if ($query->first() !== null) {
                $query->update($values);
                return;
            }
            if ((int) $transaction->table('broker_resource_subscriptions')->where('owner_id', '=', $ownerId)->aggregate('COUNT', 'id') >= 100) {
                throw new \RuntimeException('broker_resource_subscription_capacity');
            }
            $transaction->table('broker_resource_subscriptions')->insert(['id' => $id, 'owner_id' => $ownerId] + $values);
        }, $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default');
    }

    /**
     * 与持久存储同形的只读列表或详情；调用者先逐次授权，本入口再按显式归属和 Topic 边界收窄。
     * @param array{action:string,resource:string,authorization:array,filters?:array,limit?:int,cursor?:?string,id?:string} $request 只接受节点、连接和实时订阅。
     * @param bool $volatileOnly 与持久订阅列表组合时只取非持久连接，避免重复展示持久订阅。
     * @return array<string, mixed> 单页至多 100 项、响应至多 1MiB，不返回载荷或凭据。
     */
    public static function resources(Connection $connection, array $request, bool $volatileOnly = false): array
    {
        $resource = $request['resource'] ?? '';
        if ($resource === 'nodes') {
            return self::nodes($connection, $request);
        }
        $detail = ($request['action'] ?? '') === 'resource_detail';
        $authorization = $request['authorization'] ?? [];
        $filters = $request['filters'] ?? [];
        $limit = $request['limit'] ?? 20;
        if (!in_array($resource, ['connections', 'subscriptions'], true)
            || !in_array($request['action'] ?? '', ['resource_list', 'resource_detail'], true)
            || !is_bool($authorization['all_metadata'] ?? null) || !is_array($filters) || count($filters) > 6
            || !is_int($limit) || $limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('broker_resource_query_invalid');
        }
        ksort($filters);
        $context = hash('sha256', json_encode([$resource, $authorization, $filters, $volatileOnly], JSON_THROW_ON_ERROR));
        $after = '';
        if (($request['cursor'] ?? null) !== null) {
            if (!is_string($request['cursor']) || strlen($request['cursor']) > 2048) {
                throw new \InvalidArgumentException('broker_resource_cursor_invalid');
            }
            $decoded = base64_decode($request['cursor'], true);
            $cursor = $decoded === false ? null : json_decode($decoded, true, 4, JSON_THROW_ON_ERROR);
            if (!is_array($cursor) || ($cursor['context'] ?? null) !== $context
                || !is_string($cursor['after'] ?? null) || preg_match('/^[a-f0-9]{32}(?:[a-f0-9]{32})?$/D', $cursor['after']) !== 1) {
                throw new \InvalidArgumentException('broker_resource_cursor_invalid');
            }
            $after = $cursor['after'];
        }
        $from = 'broker_resource_connections c JOIN broker_resource_runs r ON r.run_id = c.observation_run';
        $predicates = [];
        $parameters = [];
        $clientBytes = $connection->driverName() === 'sqlite' ? 'length(CAST(c.client_id AS BLOB))' : 'octet_length(c.client_id)';
        $fields = ['id' => 'c.owner_id', 'owner_id' => 'c.owner_id', 'session_id' => 'c.session_id',
            'client_id' => $detail ? 'c.client_id' : 'substr(c.client_id, 1, 128)', 'client_id_bytes' => $clientBytes,
            'resource_scope' => 'c.resource_scope', 'access_identity' => 'c.access_identity', 'protocol' => 'c.protocol', 'transport' => 'c.transport',
            'session_generation' => 'c.session_generation', 'node_id' => 'c.node_id', 'node_run_id' => 'c.node_run_id',
            'node_generation' => 'c.node_generation', 'durable' => 'c.durable', 'observed_at' => 'c.observed_at',
            'broker_observed_at' => 'r.observed_at', 'broker_expires_at' => 'r.expires_at'];
        $idField = 'c.owner_id';
        if ($resource === 'subscriptions') {
            $from .= ' JOIN broker_resource_subscriptions s ON s.owner_id = c.owner_id';
            $idField = 's.id';
            $fields['id'] = 's.id';
            $fields['filter'] = $detail ? 's.topic' : 'substr(s.topic, 1, 128)';
            $fields['filter_bytes'] = $connection->driverName() === 'sqlite' ? 'length(CAST(s.topic AS BLOB))' : 'octet_length(s.topic)';
            $fields['options'] = 's.options';
            $fields['subscription_identifier'] = 's.identifier';
            $fields['observed_at'] = 's.observed_at';
        }
        if (!$authorization['all_metadata']) {
            $scope = $authorization['resource_scope'] ?? null;
            $namespace = $authorization['topic_namespace'] ?? null;
            if (!is_string($scope) || $scope === '' || strlen($scope) > 128 || preg_match('//u', $scope) !== 1
                || preg_match('/[\x00-\x1f\x7f]/', $scope) === 1 || !is_string($namespace) || $namespace === ''
                || strlen($namespace) > 128 || preg_match('//u', $namespace) !== 1 || preg_match('/[\x00-\x1f\x7f+#]/', $namespace) === 1) {
                throw new \InvalidArgumentException('broker_resource_authorization_invalid');
            }
            $predicates[] = 'c.scope_hash = ?';
            $parameters[] = hash('sha256', $scope);
            if ($resource === 'subscriptions') {
                // 字面前缀与 / 分界使用真实字节语义，不让 MySQL 排序规则折叠大小写或尾部空格。
                $prefix = $namespace . '/';
                $actual = $connection->driverName() === 'mysql' ? 'CAST(s.actual_filter AS BINARY)' : 's.actual_filter';
                $predicates[] = '(' . $actual . ' = ? OR substr(' . $actual . ', 1, ?) = ?)';
                $parameters = [...$parameters, $namespace, $connection->driverName() === 'mysql' ? strlen($prefix) : (int) mb_strlen($prefix, 'UTF-8'), $prefix];
            }
        }
        if ($volatileOnly) {
            $predicates[] = 'c.durable = 0';
        }
        foreach ($filters as $field => $value) {
            if (!in_array($field, ['client_id', 'node_id', 'session_id', 'state', 'topic', 'qos'], true)
                || ($field === 'qos' ? !in_array($value, [0, 1, 2], true) : (!is_string($value) || $value === '' || strlen($value) > 65535))) {
                throw new \InvalidArgumentException('broker_resource_filter_invalid');
            }
            if ($field === 'client_id') {
                $predicates[] = 'c.client_hash = ?';
                $parameters[] = hash('sha256', $value);
            } elseif (in_array($field, ['node_id', 'session_id'], true)) {
                $predicates[] = 'c.' . $field . ' = ?';
                $parameters[] = $value;
            } elseif ($field === 'state') {
                if (!in_array($value, [$resource === 'connections' ? 'connected' : 'subscribed', 'unknown'], true)) {
                    throw new \InvalidArgumentException('broker_resource_filter_invalid');
                }
                $predicates[] = 'r.expires_at ' . ($value === 'unknown' ? '<=' : '>') . ' ?';
                $parameters[] = time();
            } elseif ($resource !== 'subscriptions') {
                throw new \InvalidArgumentException('broker_resource_filter_invalid');
            } elseif ($field === 'topic') {
                $predicates[] = 's.topic_hash = ?';
                $parameters[] = hash('sha256', $value);
            } else {
                $predicates[] = '(s.options & 3) = ?';
                $parameters[] = $value;
            }
        }
        if ($detail) {
            if (!is_string($request['id'] ?? null) || preg_match('/^[a-f0-9]{32}(?:[a-f0-9]{32})?$/D', $request['id']) !== 1) {
                throw new \InvalidArgumentException('broker_resource_id_invalid');
            }
            $predicates[] = $idField . ' = ?';
            $parameters[] = $request['id'];
        } elseif ($after !== '') {
            $predicates[] = $idField . ' > ?';
            $parameters[] = $after;
        }
        $columns = [];
        foreach ($fields as $alias => $expression) {
            $columns[] = $expression . ' AS ' . $alias;
        }
        $rows = $connection->query('SELECT ' . implode(', ', $columns) . ' FROM ' . $from
            . ($predicates === [] ? '' : ' WHERE ' . implode(' AND ', $predicates))
            . ' ORDER BY ' . $idField . ' LIMIT ' . ($detail ? 1 : $limit + 1), $parameters);
        $items = [];
        $bytes = 1024;
        $more = !$detail && count($rows) > $limit;
        foreach ($rows as $row) {
            if (!$detail && count($items) >= $limit) {
                break;
            }
            foreach (['protocol', 'session_generation', 'node_generation', 'observed_at', 'broker_observed_at', 'broker_expires_at', 'options', 'subscription_identifier', 'client_id_bytes', 'filter_bytes'] as $field) {
                if (isset($row[$field])) {
                    $row[$field] = (int) $row[$field];
                }
            }
            $row['durable'] = (int) $row['durable'] === 1;
            $row['connection_confirmed'] = $row['broker_expires_at'] > time();
            $row['state'] = $row['connection_confirmed'] ? ($resource === 'subscriptions' ? 'subscribed' : 'connected') : 'unknown';
            $row['access_identity'] = $row['access_identity'] === null ? null : AccessIdentity::fromData(json_decode($row['access_identity'], true, 4, JSON_THROW_ON_ERROR))->data();
            if (isset($row['options'])) {
                $row['qos'] = $row['options'] & 3;
                $row['shared'] = str_starts_with($row['filter'], '$share/');
            }
            $size = strlen(json_encode($row, JSON_THROW_ON_ERROR)) + 1;
            if ($bytes + $size > 1048576) {
                $more = true;
                break;
            }
            $items[] = $row;
            $bytes += $size;
        }
        if ($detail) {
            return ['found' => $items !== [], 'item' => $items[0] ?? null, 'source' => 'live_observation', 'observed_at' => time()];
        }
        $next = $more && $items !== [] ? base64_encode(json_encode(['context' => $context, 'after' => $items[count($items) - 1]['id']], JSON_THROW_ON_ERROR)) : null;
        return ['items' => $items, 'next_cursor' => $next, 'has_more' => $more, 'total' => null,
            'source' => 'live_observation', 'observed_at' => time()];
    }

    /** 节点信息只展示当前范围实际观察到的连接计数；不把全局客户端数量交给租户。 */
    private static function nodes(Connection $connection, array $request): array
    {
        $authorization = $request['authorization'];
        $filters = $request['filters'] ?? [];
        ksort($filters);
        $context = hash('sha256', json_encode(['nodes', $authorization, $filters, false], JSON_THROW_ON_ERROR));
        $parameters = [];
        $scopePredicate = '';
        if (!$authorization['all_metadata']) {
            $scope = $authorization['resource_scope'] ?? null;
            if (!is_string($scope) || $scope === '' || strlen($scope) > 128) {
                throw new \InvalidArgumentException('broker_resource_authorization_invalid');
            }
            $scopePredicate = ' AND c.scope_hash = ?';
            $parameters[] = hash('sha256', $scope);
        }
        $sql = 'SELECT r.run_id AS id, r.run_id, r.node_id, r.observed_at, r.expires_at, '
            . '(SELECT COUNT(*) FROM broker_resource_connections c WHERE c.observation_run = r.run_id' . $scopePredicate . ') AS connections '
            . 'FROM broker_resource_runs r';
        $predicates = [];
        if (!$authorization['all_metadata']) {
            $predicates[] = 'r.connections > 0 AND EXISTS (SELECT 1 FROM broker_resource_connections c WHERE c.observation_run = r.run_id' . $scopePredicate . ')';
            $parameters[] = hash('sha256', $authorization['resource_scope']);
        }
        foreach ($filters as $field => $value) {
            if ($field === 'node_id' && is_string($value) && preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $value) === 1) {
                $predicates[] = 'r.node_id = ?';
                $parameters[] = $value;
            } elseif ($field === 'state' && in_array($value, ['reporting', 'unreachable', 'stopped'], true)) {
                $predicates[] = match ($value) {
                    'reporting' => 'r.expires_at > ?',
                    'stopped' => 'r.expires_at = r.observed_at',
                    default => 'r.expires_at <> r.observed_at AND r.expires_at <= ?',
                };
                if ($value !== 'stopped') {
                    $parameters[] = time();
                }
            } else {
                throw new \InvalidArgumentException('broker_resource_filter_invalid');
            }
        }
        $detail = $request['action'] === 'resource_detail';
        if ($detail) {
            $predicates[] = 'r.run_id = ?';
            $parameters[] = $request['id'];
        } elseif (($request['cursor'] ?? null) !== null) {
            $decoded = base64_decode($request['cursor'], true);
            $cursor = $decoded === false ? null : json_decode($decoded, true, 4, JSON_THROW_ON_ERROR);
            if (!is_array($cursor) || ($cursor['context'] ?? null) !== $context || !is_string($cursor['after'] ?? null)
                || preg_match('/^[a-f0-9]{32}$/D', $cursor['after']) !== 1) {
                throw new \InvalidArgumentException('broker_resource_cursor_invalid');
            }
            $predicates[] = 'r.run_id > ?';
            $parameters[] = $cursor['after'];
        }
        $limit = $request['limit'] ?? 20;
        if (!is_int($limit) || $limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('broker_resource_query_invalid');
        }
        $rows = $connection->query($sql . ($predicates === [] ? '' : ' WHERE ' . implode(' AND ', $predicates))
            . ' ORDER BY r.run_id LIMIT ' . ($detail ? 1 : $limit + 1), $parameters);
        $more = !$detail && count($rows) > $limit;
        $items = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            foreach (['observed_at', 'expires_at', 'connections'] as $field) {
                $row[$field] = (int) $row[$field];
            }
            $row['state'] = $row['expires_at'] === $row['observed_at'] ? 'stopped' : ($row['expires_at'] > time() ? 'reporting' : 'unreachable');
            if ($row['state'] !== 'reporting') {
                $row['connections'] = null;
            }
            $items[] = $row;
        }
        if ($detail) {
            return ['found' => $items !== [], 'item' => $items[0] ?? null, 'source' => 'live_observation', 'observed_at' => time()];
        }
        $next = $more && $items !== [] ? base64_encode(json_encode(['context' => $context, 'after' => $items[count($items) - 1]['id']], JSON_THROW_ON_ERROR)) : null;
        return ['items' => $items, 'next_cursor' => $next, 'has_more' => $more, 'total' => null, 'source' => 'live_observation', 'observed_at' => time()];
    }

    /** 节点 id 与 32 位 hex 运行身份；格式不对直接拒绝，避免写错槽位。 */
    private static function runIdentity(string $nodeId, string $runId): void
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $nodeId) !== 1 || preg_match('/^[a-f0-9]{32}$/D', $runId) !== 1) {
            throw new \InvalidArgumentException('broker_resource_run_invalid');
        }
    }

    /** 连接所有者必须是 32 位 hex。 */
    private static function owner(string $ownerId): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $ownerId) !== 1) {
            throw new \InvalidArgumentException('broker_resource_owner_invalid');
        }
    }

    /** 运行锁串行连接额度与槽位替换；投影失活后不接受迟到订阅。 */
    private static function activeRun(Connection $connection, string $nodeId, string $runId): array
    {
        $run = self::locked($connection, $connection->table('broker_resource_runs')->where('node_id', '=', $nodeId)->where('run_id', '=', $runId))->first();
        if ($run === null || (int) $run['expires_at'] <= time()) {
            throw new \RuntimeException('broker_resource_owner_lost');
        }
        return $run;
    }

    /** SQLite 无行锁；其他驱动在额度与槽位更新前加 FOR UPDATE。 */
    private static function locked(Connection $connection, Query $query): Query
    {
        return $connection->driverName() === 'sqlite' ? $query : $query->lockForUpdate();
    }
}
