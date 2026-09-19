<?php

declare(strict_types=1);

namespace app\broker\service;

use Type\Core\Http\HttpError;
use Type\Mqtt\PendingCommit;
use Type\Orm\Connection;
use Type\Validate\Field;

/**
 * 连接、会话与消息额度的版本化发布；保存成功不等于集群生效。
 * 降低到现有用量以下时保留已确认积压，只拒绝新的超额占用并等待自然释放。
 */
final class QuotaService
{
    /** @var array<string, int> 规格上限；发布不得突破，也不按最大槽位预分配。 */
    private const CEILINGS = [
        'maximumConnections' => 10100,
        'maximumDeviceConnections' => 10000,
        'maximumServiceConnections' => 100,
        'maximumSessions' => 20000,
        'maximumSubscriptions' => 100,
        'maximumPendingMessages' => 2000000,
        'maximumPendingBytes' => 4294967296,
        'maximumDeviceMessages' => 10000,
        'maximumDeviceBytes' => 16777216,
        'maximumApplicationMessages' => 1000000,
        'maximumApplicationBytes' => 2147483648,
        'maximumSharedMessages' => 1000000,
        'maximumSharedBytes' => 2147483648,
    ];

    /**
     * 节点首次启动把实际启动额度写入版本 1 并标为已生效。
     *
     * @param array<string, int> $limits 本次进程实际交给 Broker/存储的额度。
     */
    public static function ensureBootstrap(Connection $connection, array $limits): void
    {
        if ($connection->table('broker_quota_revisions')->first() !== null) {
            return;
        }
        $snapshot = self::encode(self::normalize($limits, self::defaults()));
        $connection->transaction(static function (Connection $transaction) use ($snapshot): void {
            if ($transaction->table('broker_quota_revisions')->first() !== null) {
                return;
            }
            $now = time();
            $transaction->table('broker_quota_revisions')->insert([
                'id' => bin2hex(random_bytes(16)), 'version' => 1, 'actor_id' => 'bootstrap', 'actor_realm' => 'broker',
                'lowering' => 0, 'status' => 'effective', 'snapshot_json' => $snapshot, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /** @return array<string, int> 规格默认额度。 */
    public static function defaults(): array
    {
        return [
            'maximumConnections' => 256,
            'maximumDeviceConnections' => 10000,
            'maximumServiceConnections' => 100,
            'maximumSessions' => 20000,
            'maximumSubscriptions' => 100,
            'maximumPendingMessages' => 2000000,
            'maximumPendingBytes' => 4294967296,
            'maximumDeviceMessages' => 10000,
            'maximumDeviceBytes' => 16777216,
            'maximumApplicationMessages' => 1000000,
            'maximumApplicationBytes' => 2147483648,
            'maximumSharedMessages' => 1000000,
            'maximumSharedBytes' => 2147483648,
        ];
    }

    public static function currentVersion(Connection $connection): int
    {
        $row = $connection->table('broker_quota_revisions')->orderBy('version', 'DESC')->limit(1)->first();
        return $row === null ? 0 : (int) $row['version'];
    }

    /**
     * @return array{version:int,limits:array<string,int>}|null
     */
    public static function currentSnapshot(Connection $connection): ?array
    {
        $row = $connection->table('broker_quota_revisions')->orderBy('version', 'DESC')->limit(1)->first();
        if ($row === null) {
            return null;
        }
        return ['version' => (int) $row['version'], 'limits' => self::decode((string) $row['snapshot_json'])];
    }

    /** MQTT 节点上报已加载版本；失联节点不能把发布标成完成。 */
    public static function observeNode(Connection $connection, string $nodeId, int $appliedVersion, bool $isolated): void
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $nodeId) !== 1 || $appliedVersion < 0) {
            throw new \InvalidArgumentException('broker_quota_node_invalid');
        }
        $revision = $connection->table('broker_quota_revisions')->orderBy('version', 'DESC')->limit(1)->first();
        if ($revision === null) {
            return;
        }
        $connection->transaction(static function (Connection $transaction) use ($revision, $nodeId, $appliedVersion, $isolated): void {
            $state = (int) $revision['version'] <= $appliedVersion ? 'applied' : ($isolated ? 'isolated' : 'pending');
            $existing = $transaction->table('broker_quota_node_states')->where('revision_id', '=', $revision['id'])->where('node_id', '=', $nodeId)->first();
            $now = time();
            if ($existing === null) {
                $transaction->table('broker_quota_node_states')->insert([
                    'revision_id' => $revision['id'], 'node_id' => $nodeId, 'applied_version' => $appliedVersion, 'state' => $state, 'updated_at' => $now,
                ]);
            } else {
                $transaction->table('broker_quota_node_states')->where('revision_id', '=', $revision['id'])->where('node_id', '=', $nodeId)->update([
                    'applied_version' => $appliedVersion, 'state' => $state, 'updated_at' => $now,
                ]);
            }
            self::refreshStatus($transaction, (string) $revision['id']);
        }, $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default');
    }

    /**
     * 当前额度、用量、超额与节点版本；不含载荷。
     *
     * @param list<string> $worker
     * @return array<string, mixed>
     */
    public static function current(Connection $connection, array $worker): array
    {
        if (self::currentSnapshot($connection) === null) {
            self::ensureBootstrap($connection, self::defaults());
        }
        $snapshot = self::currentSnapshot($connection);
        if ($snapshot === null) {
            throw new HttpError(404, 'broker_quota_not_found');
        }
        $latest = $connection->table('broker_quota_revisions')->orderBy('version', 'DESC')->limit(1)->first();
        return self::projection($connection, $worker, $snapshot['limits'], $latest === null ? null : (string) $latest['id']);
    }

    /**
     * 预览拟发布额度对现有用量的影响；不写入版本。
     *
     * @param list<string> $worker
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function preview(Connection $connection, array $worker, array $input): array
    {
        if (self::currentSnapshot($connection) === null) {
            self::ensureBootstrap($connection, self::defaults());
        }
        $current = self::currentSnapshot($connection);
        if ($current === null) {
            throw new HttpError(404, 'broker_quota_not_found');
        }
        $expected = (int) ($input['expected_version'] ?? -1);
        if ($expected !== $current['version']) {
            throw new HttpError(409, 'broker_quota_version_conflict');
        }
        $proposed = self::normalize($input, $current['limits']);
        $latest = $connection->table('broker_quota_revisions')->orderBy('version', 'DESC')->limit(1)->first();
        $projection = self::projection($connection, $worker, $proposed, $latest === null ? null : (string) $latest['id']);
        $projection['current_limits'] = $current['limits'];
        $projection['lowering'] = self::isLowering($current['limits'], $proposed);
        return $projection;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function publish(Connection $connection, string $actorId, string $actorRealm, array $input): array
    {
        if (($input['confirmed'] ?? false) !== true) {
            throw new HttpError(422, 'broker_quota_unconfirmed');
        }
        if (self::currentSnapshot($connection) === null) {
            self::ensureBootstrap($connection, self::defaults());
        }
        $expected = (int) ($input['expected_version'] ?? -1);
        $current = self::currentSnapshot($connection);
        if ($current === null || $expected !== $current['version']) {
            throw new HttpError($current === null ? 404 : 409, $current === null ? 'broker_quota_not_found' : 'broker_quota_version_conflict');
        }
        $blocking = $connection->table('broker_quota_revisions')->orderBy('version', 'DESC')->limit(1)->first();
        if ($blocking !== null && in_array((string) $blocking['status'], ['pending', 'partial'], true)) {
            throw new HttpError(409, 'broker_quota_publish_paused');
        }
        $proposed = self::normalize($input, $current['limits']);
        if ($proposed === $current['limits']) {
            throw new HttpError(409, 'broker_quota_unchanged');
        }
        return $connection->transaction(static function (Connection $transaction) use ($actorId, $actorRealm, $proposed, $current): array {
            $now = time();
            $revisionId = bin2hex(random_bytes(16));
            $transaction->table('broker_quota_revisions')->insert([
                'id' => $revisionId, 'version' => $current['version'] + 1, 'actor_id' => $actorId, 'actor_realm' => $actorRealm,
                'lowering' => self::isLowering($current['limits'], $proposed) ? 1 : 0, 'status' => 'pending',
                'snapshot_json' => self::encode($proposed), 'created_at' => $now, 'updated_at' => $now,
            ]);
            self::refreshStatus($transaction, $revisionId);
            return self::revision($transaction, $revisionId);
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /** @return array<string, mixed> */
    public static function retry(Connection $connection, string $revisionId): array
    {
        return $connection->transaction(static function (Connection $transaction) use ($revisionId): array {
            $query = $transaction->table('broker_quota_revisions')->where('id', '=', $revisionId);
            $row = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
            if ($row === null) {
                throw new HttpError(404, 'broker_quota_not_found');
            }
            if (!in_array((string) $row['status'], ['pending', 'partial', 'failed'], true)) {
                throw new HttpError(409, 'broker_quota_retry_unavailable');
            }
            $latest = $transaction->table('broker_quota_revisions')->orderBy('version', 'DESC')->limit(1)->first();
            if ($latest === null || (string) $latest['id'] !== $revisionId) {
                throw new HttpError(409, 'broker_quota_not_current');
            }
            $query->update(['updated_at' => time()]);
            self::refreshStatus($transaction, $revisionId);
            return self::revision($transaction, $revisionId);
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /** 普通配额部分失败允许显式回退到上一快照；不复活已经吊销的身份。 */
    public static function rollback(Connection $connection, string $revisionId, string $actorId, string $actorRealm): array
    {
        return $connection->transaction(static function (Connection $transaction) use ($revisionId, $actorId, $actorRealm): array {
            $current = $transaction->table('broker_quota_revisions')->where('id', '=', $revisionId)->first();
            if ($current === null) {
                throw new HttpError(404, 'broker_quota_not_found');
            }
            if (!in_array((string) $current['status'], ['pending', 'partial', 'failed'], true)) {
                throw new HttpError(409, 'broker_quota_rollback_unavailable');
            }
            $previous = $transaction->table('broker_quota_revisions')->where('version', '<', (int) $current['version'])->orderBy('version', 'DESC')->first();
            if ($previous === null) {
                throw new HttpError(409, 'broker_quota_no_previous');
            }
            $now = time();
            $newId = bin2hex(random_bytes(16));
            $transaction->table('broker_quota_revisions')->where('id', '=', $revisionId)->update(['status' => 'rolled_back', 'updated_at' => $now]);
            $transaction->table('broker_quota_revisions')->insert([
                'id' => $newId, 'version' => self::currentVersion($transaction) + 1, 'actor_id' => $actorId, 'actor_realm' => $actorRealm,
                'lowering' => 0, 'status' => 'pending', 'snapshot_json' => $previous['snapshot_json'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            self::refreshStatus($transaction, $newId);
            return self::revision($transaction, $newId);
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /**
     * @return array<string, mixed>
     */
    public static function revisions(Connection $connection, string $id, int $page, int $perPage): array
    {
        if ($id !== '') {
            $row = $connection->table('broker_quota_revisions')->where('id', '=', $id)->first();
            if ($row === null) {
                throw new HttpError(404, 'broker_quota_not_found');
            }
            return ['item' => self::revision($connection, $id)];
        }
        $result = $connection->table('broker_quota_revisions')->orderBy('version', 'DESC')->paginate($page, $perPage);
        $items = [];
        foreach ($result->items() as $row) {
            $items[] = self::revision($connection, (string) $row['id']);
        }
        return ['items' => $items, 'total' => $result->total(), 'page' => $page, 'per_page' => $perPage,
            'current_version' => self::currentVersion($connection), 'publish_paused' => self::paused($connection)];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public static function changeInput(array $source): array
    {
        $allowed = array_merge(array_keys(self::CEILINGS), ['expected_version', 'confirmed']);
        if (array_diff(array_keys($source), $allowed) !== []) {
            throw new HttpError(422, 'broker_quota_invalid');
        }
        $fields = ['expected_version' => Field::integer()->cast()->required()->range(0, 2147483646)];
        foreach (array_keys(self::CEILINGS) as $key) {
            $fields[$key] = Field::integer()->cast()->range(0, self::CEILINGS[$key]);
        }
        $input = \_vali($fields, $source);
        if (array_key_exists('confirmed', $source) && $source['confirmed'] !== true && $source['confirmed'] !== false) {
            throw new HttpError(422, 'broker_quota_invalid');
        }
        if (array_key_exists('confirmed', $source)) {
            $input['confirmed'] = $source['confirmed'] === true;
        }
        return $input;
    }

    public static function paused(Connection $connection): bool
    {
        $latest = $connection->table('broker_quota_revisions')->orderBy('version', 'DESC')->limit(1)->first();
        return $latest !== null && in_array((string) $latest['status'], ['pending', 'partial'], true);
    }

    /**
     * @param list<string> $worker
     * @param array<string, int> $limits
     * @return array<string, mixed>
     */
    private static function projection(Connection $connection, array $worker, array $limits, ?string $revisionId): array
    {
        $usage = self::usage($connection, $worker);
        $excess = [];
        $waiting = false;
        foreach (self::usageKeys() as $limitKey => $usageKey) {
            $used = $usage[$usageKey] ?? null;
            $over = is_int($used) ? max(0, $used - $limits[$limitKey]) : null;
            $excess[$usageKey] = $over;
            $waiting = $waiting || ($over !== null && $over > 0);
        }
        return [
            'limits' => $limits, 'ceilings' => self::CEILINGS, 'usage' => $usage, 'excess' => $excess,
            'waiting_release' => $waiting, 'store_available' => ($usage['pendingMessages'] ?? null) !== null,
            'current_version' => self::currentVersion($connection), 'publish_paused' => self::paused($connection),
            'revision' => $revisionId === null ? null : self::revision($connection, $revisionId),
        ];
    }

    /**
     * @param list<string> $worker
     * @return array<string, int|null>
     */
    private static function usage(Connection $connection, array $worker): array
    {
        $connections = 0;
        $devices = 0;
        $services = 0;
        $liveSubscriptions = 0;
        try {
            $nodes = $connection->table('broker_nodes')->where('stopped', '=', 0)->limit(32)->get();
        } catch (\Type\Orm\DatabaseException) {
            // 双端身份库没有独立宿主节点采样表；实时连接用量记为零。
            $nodes = [];
        }
        foreach ($nodes as $row) {
            if (time() - (int) $row['observed_at'] >= 15) {
                continue;
            }
            $metrics = json_decode((string) $row['metrics_json'], true, 8);
            if (!is_array($metrics)) {
                continue;
            }
            $connections += is_int($metrics['connections'] ?? null) ? $metrics['connections'] : 0;
            $devices += is_int($metrics['deviceConnections'] ?? null) ? $metrics['deviceConnections'] : 0;
            $services += is_int($metrics['serviceConnections'] ?? null) ? $metrics['serviceConnections'] : 0;
            $liveSubscriptions += is_int($metrics['subscriptions'] ?? null) ? $metrics['subscriptions'] : 0;
        }
        $store = self::storeUsage($worker);
        return [
            'connections' => $connections, 'deviceConnections' => $devices, 'serviceConnections' => $services,
            'liveSubscriptions' => $liveSubscriptions,
            'sessions' => $store['sessions'] ?? null, 'pendingMessages' => $store['pendingMessages'] ?? null,
            'pendingBytes' => $store['pendingBytes'] ?? null, 'devicePendingMessages' => $store['devicePendingMessages'] ?? null,
            'devicePendingBytes' => $store['devicePendingBytes'] ?? null, 'applicationPendingMessages' => $store['applicationPendingMessages'] ?? null,
            'applicationPendingBytes' => $store['applicationPendingBytes'] ?? null, 'sharedPendingMessages' => $store['sharedPendingMessages'] ?? null,
            'sharedPendingBytes' => $store['sharedPendingBytes'] ?? null,
        ];
    }

    /**
     * @param list<string> $worker
     * @return array<string, int>
     */
    private static function storeUsage(array $worker): array
    {
        if ($worker === [] || ResourceQueries::quarantined()) {
            return [];
        }
        $pending = new PendingCommit($worker, ['action' => 'session_statistics', 'operation_id' => bin2hex(random_bytes(16))]);
        do {
            $result = $pending->poll();
            if ($result === null) {
                usleep(10000);
            }
        } while ($result === null);
        if (!$result->released) {
            ResourceQueries::quarantine();
            return [];
        }
        if ($result->state !== 'committed') {
            return [];
        }
        $values = [];
        foreach (['sessions', 'pendingMessages', 'pendingBytes', 'devicePendingMessages', 'devicePendingBytes',
            'applicationPendingMessages', 'applicationPendingBytes', 'sharedPendingMessages', 'sharedPendingBytes'] as $key) {
            $value = $result->value[$key] ?? null;
            if (is_int($value) && $value >= 0) {
                $values[$key] = $value;
            }
        }
        return $values;
    }

    /** @return array<string, string> */
    private static function usageKeys(): array
    {
        return [
            'maximumConnections' => 'connections', 'maximumDeviceConnections' => 'deviceConnections',
            'maximumServiceConnections' => 'serviceConnections', 'maximumSessions' => 'sessions',
            'maximumSubscriptions' => 'liveSubscriptions', 'maximumPendingMessages' => 'pendingMessages',
            'maximumPendingBytes' => 'pendingBytes', 'maximumDeviceMessages' => 'devicePendingMessages',
            'maximumDeviceBytes' => 'devicePendingBytes', 'maximumApplicationMessages' => 'applicationPendingMessages',
            'maximumApplicationBytes' => 'applicationPendingBytes', 'maximumSharedMessages' => 'sharedPendingMessages',
            'maximumSharedBytes' => 'sharedPendingBytes',
        ];
    }

    /** @return array<string, mixed> */
    private static function revision(Connection $connection, string $id): array
    {
        $row = $connection->table('broker_quota_revisions')->where('id', '=', $id)->first();
        if ($row === null) {
            throw new HttpError(404, 'broker_quota_not_found');
        }
        $nodes = [];
        foreach ($connection->table('broker_quota_node_states')->where('revision_id', '=', $id)->orderBy('node_id')->get() as $node) {
            $nodes[] = ['node_id' => $node['node_id'], 'applied_version' => (int) $node['applied_version'], 'state' => $node['state'],
                'updated_at' => (int) $node['updated_at']];
        }
        $applied = 0;
        $pendingNodes = 0;
        $isolated = 0;
        foreach ($nodes as $node) {
            if ($node['state'] === 'applied') {
                $applied++;
            } elseif ($node['state'] === 'isolated') {
                $isolated++;
            } else {
                $pendingNodes++;
            }
        }
        $status = (string) $row['status'];
        $stage = $status === 'effective' ? 'completed' : ($status === 'failed' || $status === 'rolled_back' ? $status === 'failed' ? 'failed' : 'completed' : ($applied > 0 ? 'executing' : 'accepted'));
        return ['id' => $row['id'], 'operation_id' => $row['id'], 'version' => (int) $row['version'], 'actor_id' => $row['actor_id'],
            'actor_realm' => $row['actor_realm'], 'lowering' => (int) $row['lowering'] === 1, 'status' => $status, 'stage' => $stage,
            'limits' => self::decode((string) $row['snapshot_json']), 'created_at' => (int) $row['created_at'],
            'updated_at' => (int) $row['updated_at'], 'nodes' => $nodes, 'pending_nodes' => $pendingNodes, 'isolated_nodes' => $isolated];
    }

    private static function refreshStatus(Connection $transaction, string $revisionId): void
    {
        $revision = $transaction->table('broker_quota_revisions')->where('id', '=', $revisionId)->first();
        if ($revision === null || (string) $revision['status'] === 'rolled_back' || (int) ($revision['recovery_verified'] ?? 1) !== 1) {
            return;
        }
        $nodes = $transaction->table('broker_quota_node_states')->where('revision_id', '=', $revisionId)->get();
        $applied = 0;
        $pendingNodes = 0;
        $isolated = 0;
        foreach ($nodes as $node) {
            if ((string) $node['state'] === 'applied') {
                $applied++;
            } elseif ((string) $node['state'] === 'isolated') {
                $isolated++;
            } else {
                $pendingNodes++;
            }
        }
        $status = 'pending';
        $everObserved = $transaction->table('broker_quota_node_states')->limit(1)->first();
        if ($everObserved === null) {
            $status = 'effective';
        } elseif ($applied > 0 && $pendingNodes === 0 && $isolated === 0) {
            $status = 'effective';
        } elseif ($applied > 0 && ($pendingNodes > 0 || $isolated > 0)) {
            $status = 'partial';
        } elseif ($isolated > 0 && $applied === 0 && $pendingNodes === 0) {
            $status = 'failed';
        }
        $transaction->table('broker_quota_revisions')->where('id', '=', $revisionId)->update(['status' => $status, 'updated_at' => time()]);
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, int> $baseline
     * @return array<string, int>
     */
    private static function normalize(array $source, array $baseline): array
    {
        $limits = $baseline;
        foreach (self::CEILINGS as $key => $ceiling) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            if (!is_int($source[$key])) {
                throw new HttpError(422, 'broker_quota_invalid');
            }
            $minimum = $key === 'maximumServiceConnections' ? 0 : 1;
            if ($source[$key] < $minimum || $source[$key] > $ceiling) {
                throw new HttpError(422, 'broker_quota_invalid');
            }
            $limits[$key] = $source[$key];
        }
        if ($limits['maximumDeviceConnections'] + $limits['maximumServiceConnections'] < 1) {
            throw new HttpError(422, 'broker_quota_invalid');
        }
        return $limits;
    }

    /**
     * @param array<string, int> $before
     * @param array<string, int> $after
     */
    private static function isLowering(array $before, array $after): bool
    {
        foreach (self::CEILINGS as $key => $ceiling) {
            if ($after[$key] < $before[$key]) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, int> $limits */
    private static function encode(array $limits): string
    {
        return json_encode($limits, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, int> */
    private static function decode(string $json): array
    {
        $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new HttpError(500, 'broker_quota_snapshot_invalid');
        }
        return self::normalize($decoded, self::defaults());
    }
}
