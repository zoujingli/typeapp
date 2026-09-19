<?php

declare(strict_types=1);

namespace app\broker\service;

use Type\Core\Http\HttpError;
use Type\Orm\Connection;
use Type\Validate\Field;

/**
 * 需运维重启才生效的监听、I/O 与证书路径版本；保存成功不等于节点已加载。
 * 握手 CA 文件由节点心跳在线重写，不进入本表。收紧变更不允许回退。
 */
final class RuntimeService
{
    /** @var list<string> 快照字段；不含私钥与绝对秘密。 */
    private const KEYS = ['listen', 'port', 'ws_port', 'wss_port', 'mtls_port', 'io_driver', 'plaintext', 'allowed_origins'];

    /**
     * 节点首次启动把实际监听身份写入版本 1 并标为已生效。
     *
     * @param array<string, mixed> $config 本次进程实际交给 Broker 的监听身份。
     */
    public static function ensureBootstrap(Connection $connection, array $config): void
    {
        if ($connection->table('broker_runtime_revisions')->first() !== null) {
            return;
        }
        $snapshot = self::encode(self::normalize($config, self::defaults()));
        $connection->transaction(static function (Connection $transaction) use ($snapshot): void {
            if ($transaction->table('broker_runtime_revisions')->first() !== null) {
                return;
            }
            $now = time();
            $transaction->table('broker_runtime_revisions')->insert([
                'id' => bin2hex(random_bytes(16)), 'version' => 1, 'actor_id' => 'bootstrap', 'actor_realm' => 'broker',
                'tightening' => 0, 'status' => 'effective', 'snapshot_json' => $snapshot, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /** @return array<string, mixed> 缺省监听身份。 */
    public static function defaults(): array
    {
        return [
            'listen' => '127.0.0.1', 'port' => 8883, 'ws_port' => 0, 'wss_port' => 0, 'mtls_port' => 0,
            'io_driver' => 'swoole', 'plaintext' => false, 'allowed_origins' => '',
        ];
    }

    /**
     * 节点上报的 listener_json 转成可比较快照；兼容仅含 host/port/transport 的旧行。
     *
     * @param array<string, mixed> $listener
     * @return array<string, mixed>
     */
    public static function fromListener(array $listener): array
    {
        $transport = (string) ($listener['transport'] ?? '');
        return self::normalize([
            'listen' => (string) ($listener['host'] ?? $listener['listen'] ?? ''),
            'port' => (int) ($listener['port'] ?? 0),
            'ws_port' => (int) ($listener['ws_port'] ?? 0),
            'wss_port' => (int) ($listener['wss_port'] ?? 0),
            'mtls_port' => (int) ($listener['mtls_port'] ?? 0),
            'io_driver' => (string) ($listener['io_driver'] ?? 'swoole'),
            'plaintext' => array_key_exists('plaintext', $listener) ? (bool) $listener['plaintext'] : $transport === 'tcp',
            'allowed_origins' => (string) ($listener['allowed_origins'] ?? ''),
        ], self::defaults());
    }

    /**
     * 双端 MQTT 进程环境中的监听身份；缺少明确地址时返回 null。
     *
     * @return array<string, mixed>|null
     */
    public static function fromMqttEnvironment(): ?array
    {
        $listen = getenv('IOT_MQTT_LISTEN');
        $port = getenv('IOT_MQTT_PORT');
        if (!is_string($listen) || $listen === '' || !is_string($port) || $port === '') {
            return null;
        }
        $ws = getenv('IOT_MQTT_WS_PORT');
        $wss = getenv('IOT_MQTT_WSS_PORT');
        $mtls = getenv('IOT_MQTT_MTLS_PORT');
        $driver = getenv('IOT_MQTT_IO_DRIVER');
        $origins = getenv('IOT_MQTT_ALLOWED_ORIGINS');
        return self::normalize([
            'listen' => $listen,
            'port' => (int) $port,
            'ws_port' => is_string($ws) && $ws !== '' ? (int) $ws : 0,
            'wss_port' => is_string($wss) && $wss !== '' ? (int) $wss : 0,
            'mtls_port' => is_string($mtls) && $mtls !== '' ? (int) $mtls : 0,
            'io_driver' => is_string($driver) && $driver !== '' ? $driver : 'swoole',
            'plaintext' => false,
            'allowed_origins' => is_string($origins) ? $origins : '',
        ], self::defaults());
    }

    public static function currentVersion(Connection $connection): int
    {
        $row = $connection->table('broker_runtime_revisions')->orderBy('version', 'DESC')->limit(1)->first();
        return $row === null ? 0 : (int) $row['version'];
    }

    /**
     * @return array{version:int,config:array<string, mixed>}|null
     */
    public static function currentSnapshot(Connection $connection): ?array
    {
        $row = $connection->table('broker_runtime_revisions')->orderBy('version', 'DESC')->limit(1)->first();
        if ($row === null) {
            return null;
        }
        return ['version' => (int) $row['version'], 'config' => self::decode((string) $row['snapshot_json'])];
    }

    /**
     * 节点上报已加载监听身份；失联或隔离节点不能把发布标成完成。
     *
     * @param array<string, mixed> $loaded
     */
    public static function observeNode(Connection $connection, string $nodeId, array $loaded, bool $isolated): void
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $nodeId) !== 1) {
            throw new \InvalidArgumentException('broker_runtime_node_invalid');
        }
        $revision = $connection->table('broker_runtime_revisions')->orderBy('version', 'DESC')->limit(1)->first();
        if ($revision === null) {
            return;
        }
        $snapshot = self::decode((string) $revision['snapshot_json']);
        $matched = self::matches($snapshot, self::fromListener($loaded));
        $appliedVersion = $matched ? (int) $revision['version'] : 0;
        $state = $matched ? 'applied' : ($isolated ? 'isolated' : 'pending');
        $connection->transaction(static function (Connection $transaction) use ($revision, $nodeId, $appliedVersion, $state): void {
            $existing = $transaction->table('broker_runtime_node_states')->where('revision_id', '=', $revision['id'])->where('node_id', '=', $nodeId)->first();
            $now = time();
            if ($existing === null) {
                $transaction->table('broker_runtime_node_states')->insert([
                    'revision_id' => $revision['id'], 'node_id' => $nodeId, 'applied_version' => $appliedVersion, 'state' => $state, 'updated_at' => $now,
                ]);
            } else {
                $transaction->table('broker_runtime_node_states')->where('revision_id', '=', $revision['id'])->where('node_id', '=', $nodeId)->update([
                    'applied_version' => $appliedVersion, 'state' => $state, 'updated_at' => $now,
                ]);
            }
            self::refreshStatus($transaction, (string) $revision['id']);
        }, $connection->driverName() === 'sqlite' && $connection->transactionDepth() === 0 ? 'immediate' : 'default');
    }

    /**
     * 当前已保存运行配置、节点加载身份与握手 CA 摘要；不含私钥。
     *
     * @return array<string, mixed>
     */
    public static function current(Connection $connection): array
    {
        if (self::currentSnapshot($connection) === null) {
            self::ensureBootstrap($connection, self::defaults());
        }
        $snapshot = self::currentSnapshot($connection);
        if ($snapshot === null) {
            throw new HttpError(404, 'broker_runtime_not_found');
        }
        $latest = $connection->table('broker_runtime_revisions')->orderBy('version', 'DESC')->limit(1)->first();
        return self::projection($connection, $snapshot['config'], $latest === null ? null : (string) $latest['id']);
    }

    /**
     * 预览拟保存配置与当前节点加载身份的差异；不写入版本。
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function preview(Connection $connection, array $input): array
    {
        if (self::currentSnapshot($connection) === null) {
            self::ensureBootstrap($connection, self::defaults());
        }
        $current = self::currentSnapshot($connection);
        if ($current === null) {
            throw new HttpError(404, 'broker_runtime_not_found');
        }
        $expected = (int) ($input['expected_version'] ?? -1);
        if ($expected !== $current['version']) {
            throw new HttpError(409, 'broker_runtime_version_conflict');
        }
        $proposed = self::normalize($input, $current['config']);
        $latest = $connection->table('broker_runtime_revisions')->orderBy('version', 'DESC')->limit(1)->first();
        $projection = self::projection($connection, $proposed, $latest === null ? null : (string) $latest['id']);
        $projection['current_config'] = $current['config'];
        $projection['tightening'] = self::isTightening($current['config'], $proposed);
        return $projection;
    }

    /**
     * 明确确认后校验并保存新运行配置；不在应用内重启或滚动分发节点。
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function publish(Connection $connection, string $actorId, string $actorRealm, array $input): array
    {
        if (($input['confirmed'] ?? false) !== true) {
            throw new HttpError(422, 'broker_runtime_unconfirmed');
        }
        if (self::currentSnapshot($connection) === null) {
            self::ensureBootstrap($connection, self::defaults());
        }
        $expected = (int) ($input['expected_version'] ?? -1);
        $current = self::currentSnapshot($connection);
        if ($current === null || $expected !== $current['version']) {
            throw new HttpError($current === null ? 404 : 409, $current === null ? 'broker_runtime_not_found' : 'broker_runtime_version_conflict');
        }
        $blocking = $connection->table('broker_runtime_revisions')->orderBy('version', 'DESC')->limit(1)->first();
        if ($blocking !== null && in_array((string) $blocking['status'], ['pending', 'partial'], true)) {
            throw new HttpError(409, 'broker_runtime_publish_paused');
        }
        $proposed = self::normalize($input, $current['config']);
        if ($proposed === $current['config']) {
            throw new HttpError(409, 'broker_runtime_unchanged');
        }
        return $connection->transaction(static function (Connection $transaction) use ($actorId, $actorRealm, $proposed, $current): array {
            $now = time();
            $revisionId = bin2hex(random_bytes(16));
            $transaction->table('broker_runtime_revisions')->insert([
                'id' => $revisionId, 'version' => $current['version'] + 1, 'actor_id' => $actorId, 'actor_realm' => $actorRealm,
                'tightening' => self::isTightening($current['config'], $proposed) ? 1 : 0, 'status' => 'pending',
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
            $query = $transaction->table('broker_runtime_revisions')->where('id', '=', $revisionId);
            $row = ($transaction->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
            if ($row === null) {
                throw new HttpError(404, 'broker_runtime_not_found');
            }
            if (!in_array((string) $row['status'], ['pending', 'partial', 'failed'], true)) {
                throw new HttpError(409, 'broker_runtime_retry_unavailable');
            }
            $latest = $transaction->table('broker_runtime_revisions')->orderBy('version', 'DESC')->limit(1)->first();
            if ($latest === null || (string) $latest['id'] !== $revisionId) {
                throw new HttpError(409, 'broker_runtime_not_current');
            }
            $query->update(['updated_at' => time()]);
            self::refreshStatus($transaction, $revisionId);
            return self::revision($transaction, $revisionId);
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /** 普通监听变更允许显式回退；收紧安全边界后不能回退。 */
    public static function rollback(Connection $connection, string $revisionId, string $actorId, string $actorRealm): array
    {
        return $connection->transaction(static function (Connection $transaction) use ($revisionId, $actorId, $actorRealm): array {
            $current = $transaction->table('broker_runtime_revisions')->where('id', '=', $revisionId)->first();
            if ($current === null) {
                throw new HttpError(404, 'broker_runtime_not_found');
            }
            if (!in_array((string) $current['status'], ['pending', 'partial', 'failed'], true)) {
                throw new HttpError(409, 'broker_runtime_rollback_unavailable');
            }
            if ((int) $current['tightening'] === 1) {
                throw new HttpError(409, 'broker_runtime_tightening');
            }
            $previous = $transaction->table('broker_runtime_revisions')->where('version', '<', (int) $current['version'])->orderBy('version', 'DESC')->first();
            if ($previous === null) {
                throw new HttpError(409, 'broker_runtime_no_previous');
            }
            $now = time();
            $newId = bin2hex(random_bytes(16));
            $transaction->table('broker_runtime_revisions')->where('id', '=', $revisionId)->update(['status' => 'rolled_back', 'updated_at' => $now]);
            $transaction->table('broker_runtime_revisions')->insert([
                'id' => $newId, 'version' => self::currentVersion($transaction) + 1, 'actor_id' => $actorId, 'actor_realm' => $actorRealm,
                'tightening' => 0, 'status' => 'pending', 'snapshot_json' => $previous['snapshot_json'],
                'created_at' => $now, 'updated_at' => $now,
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
            $row = $connection->table('broker_runtime_revisions')->where('id', '=', $id)->first();
            if ($row === null) {
                throw new HttpError(404, 'broker_runtime_not_found');
            }
            return ['item' => self::revision($connection, $id)];
        }
        $result = $connection->table('broker_runtime_revisions')->orderBy('version', 'DESC')->paginate($page, $perPage);
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
        $allowed = array_merge(self::KEYS, ['expected_version', 'confirmed']);
        if (array_diff(array_keys($source), $allowed) !== []) {
            throw new HttpError(422, 'broker_runtime_invalid');
        }
        $fields = [
            'expected_version' => Field::integer()->cast()->required()->range(0, 2147483646),
            'listen' => Field::text()->length(1, 45),
            'port' => Field::integer()->cast()->range(1, 65535),
            'ws_port' => Field::integer()->cast()->range(0, 65535),
            'wss_port' => Field::integer()->cast()->range(0, 65535),
            'mtls_port' => Field::integer()->cast()->range(0, 65535),
            'io_driver' => Field::text()->length(1, 16),
            'plaintext' => Field::boolean(),
            'allowed_origins' => Field::text()->length(0, 2000),
        ];
        $input = \_vali($fields, $source);
        if (array_key_exists('confirmed', $source) && $source['confirmed'] !== true && $source['confirmed'] !== false) {
            throw new HttpError(422, 'broker_runtime_invalid');
        }
        if (array_key_exists('confirmed', $source)) {
            $input['confirmed'] = $source['confirmed'] === true;
        }
        return $input;
    }

    public static function paused(Connection $connection): bool
    {
        $latest = $connection->table('broker_runtime_revisions')->orderBy('version', 'DESC')->limit(1)->first();
        return $latest !== null && in_array((string) $latest['status'], ['pending', 'partial'], true);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function projection(Connection $connection, array $config, ?string $revisionId): array
    {
        return [
            'config' => $config, 'restart_required' => true,
            'loaded' => self::loadedNodes($connection), 'handshake' => self::handshake($connection),
            'current_version' => self::currentVersion($connection), 'publish_paused' => self::paused($connection),
            'revision' => $revisionId === null ? null : self::revision($connection, $revisionId),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function loadedNodes(Connection $connection): array
    {
        $nodes = [];
        try {
            $rows = $connection->table('broker_nodes')->where('stopped', '=', 0)->orderBy('node_id')->limit(32)->get();
        } catch (\Type\Orm\DatabaseException) {
            return [];
        }
        foreach ($rows as $row) {
            $listener = json_decode((string) $row['listener_json'], true, 8);
            if (!is_array($listener)) {
                continue;
            }
            $nodes[] = [
                'node_id' => (string) $row['node_id'],
                'config' => self::fromListener($listener),
                'certificate_sha256' => (string) ($listener['certificate_sha256'] ?? ''),
                'handshake_ca_sha256' => (string) ($listener['handshake_ca_sha256'] ?? ''),
                'observed_at' => (int) $row['observed_at'],
            ];
        }
        return $nodes;
    }

    /**
     * @return array{sha256:string,reloads:int,failures:int}
     */
    private static function handshake(Connection $connection): array
    {
        $sha256 = '';
        $reloads = 0;
        $failures = 0;
        try {
            $rows = $connection->table('broker_nodes')->where('stopped', '=', 0)->orderBy('node_id')->limit(32)->get();
        } catch (\Type\Orm\DatabaseException) {
            return ['sha256' => '', 'reloads' => 0, 'failures' => 0];
        }
        foreach ($rows as $row) {
            $listener = json_decode((string) $row['listener_json'], true, 8);
            $metrics = json_decode((string) $row['metrics_json'], true, 8);
            if (is_array($listener) && $sha256 === '') {
                $sha256 = (string) ($listener['handshake_ca_sha256'] ?? '');
            }
            if (is_array($metrics)) {
                $reloads += is_int($metrics['handshakeCaReloads'] ?? null) ? $metrics['handshakeCaReloads'] : 0;
                $failures += is_int($metrics['handshakeCaFailures'] ?? null) ? $metrics['handshakeCaFailures'] : 0;
            }
        }
        return ['sha256' => $sha256, 'reloads' => $reloads, 'failures' => $failures];
    }

    /** @return array<string, mixed> */
    private static function revision(Connection $connection, string $id): array
    {
        $row = $connection->table('broker_runtime_revisions')->where('id', '=', $id)->first();
        if ($row === null) {
            throw new HttpError(404, 'broker_runtime_not_found');
        }
        $nodes = [];
        $applied = 0;
        $pendingNodes = 0;
        $isolated = 0;
        foreach ($connection->table('broker_runtime_node_states')->where('revision_id', '=', $id)->orderBy('node_id')->get() as $node) {
            $state = (string) $node['state'];
            $nodes[] = ['node_id' => (string) $node['node_id'], 'applied_version' => (int) $node['applied_version'],
                'state' => $state, 'updated_at' => (int) $node['updated_at']];
            if ($state === 'applied') {
                $applied++;
            } elseif ($state === 'isolated') {
                $isolated++;
            } else {
                $pendingNodes++;
            }
        }
        $status = (string) $row['status'];
        $stage = $status === 'effective' ? 'completed' : ($status === 'failed' || $status === 'rolled_back' ? $status === 'failed' ? 'failed' : 'completed' : ($applied > 0 ? 'executing' : 'accepted'));
        return ['id' => $row['id'], 'operation_id' => $row['id'], 'version' => (int) $row['version'], 'actor_id' => $row['actor_id'],
            'actor_realm' => $row['actor_realm'], 'tightening' => (int) $row['tightening'] === 1, 'status' => $status, 'stage' => $stage,
            'config' => self::decode((string) $row['snapshot_json']), 'created_at' => (int) $row['created_at'],
            'updated_at' => (int) $row['updated_at'], 'nodes' => $nodes, 'pending_nodes' => $pendingNodes, 'isolated_nodes' => $isolated];
    }

    private static function refreshStatus(Connection $transaction, string $revisionId): void
    {
        $revision = $transaction->table('broker_runtime_revisions')->where('id', '=', $revisionId)->first();
        if ($revision === null || (string) $revision['status'] === 'rolled_back' || (int) ($revision['recovery_verified'] ?? 1) !== 1) {
            return;
        }
        $nodes = $transaction->table('broker_runtime_node_states')->where('revision_id', '=', $revisionId)->get();
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
        $everObserved = $transaction->table('broker_runtime_node_states')->limit(1)->first();
        if ($everObserved === null) {
            $status = 'effective';
        } elseif ($applied > 0 && $pendingNodes === 0 && $isolated === 0) {
            $status = 'effective';
        } elseif ($applied > 0 && ($pendingNodes > 0 || $isolated > 0)) {
            $status = 'partial';
        } elseif ($isolated > 0 && $applied === 0 && $pendingNodes === 0) {
            $status = 'failed';
        }
        $transaction->table('broker_runtime_revisions')->where('id', '=', $revisionId)->update(['status' => $status, 'updated_at' => time()]);
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $baseline
     * @return array<string, mixed>
     */
    private static function normalize(array $source, array $baseline): array
    {
        $config = $baseline;
        if (array_key_exists('listen', $source)) {
            $listen = (string) $source['listen'];
            if (filter_var($listen, FILTER_VALIDATE_IP) === false) {
                throw new HttpError(422, 'broker_runtime_invalid');
            }
            $config['listen'] = $listen;
        }
        foreach (['port', 'ws_port', 'wss_port', 'mtls_port'] as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            if (!is_int($source[$key])) {
                throw new HttpError(422, 'broker_runtime_invalid');
            }
            $minimum = $key === 'port' ? 1 : 0;
            if ($source[$key] < $minimum || $source[$key] > 65535) {
                throw new HttpError(422, 'broker_runtime_invalid');
            }
            $config[$key] = $source[$key];
        }
        if (array_key_exists('io_driver', $source)) {
            $driver = (string) $source['io_driver'];
            if (!in_array($driver, ['stream', 'swoole'], true)) {
                throw new HttpError(422, 'broker_runtime_invalid');
            }
            $config['io_driver'] = $driver;
        }
        if (array_key_exists('plaintext', $source)) {
            if ($source['plaintext'] !== true && $source['plaintext'] !== false) {
                throw new HttpError(422, 'broker_runtime_invalid');
            }
            $config['plaintext'] = $source['plaintext'] === true;
        }
        if (array_key_exists('allowed_origins', $source)) {
            $origins = self::origins((string) $source['allowed_origins']);
            $config['allowed_origins'] = implode(',', $origins);
        }
        self::assertSafe($config);
        return $config;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function assertSafe(array $config): void
    {
        $port = (int) $config['port'];
        $ws = (int) $config['ws_port'];
        $wss = (int) $config['wss_port'];
        $mtls = (int) $config['mtls_port'];
        $driver = (string) $config['io_driver'];
        $plaintext = (bool) $config['plaintext'];
        if ($ws > 0 && $wss > 0) {
            throw new HttpError(422, 'broker_runtime_invalid');
        }
        if ($ws > 0 && !$plaintext) {
            throw new HttpError(422, 'broker_runtime_invalid');
        }
        if (($wss > 0 || $mtls > 0) && $plaintext) {
            throw new HttpError(422, 'broker_runtime_invalid');
        }
        if (($ws > 0 || $wss > 0 || $mtls > 0) && $driver !== 'swoole') {
            throw new HttpError(422, 'broker_runtime_invalid');
        }
        $used = [$port];
        foreach ([$ws, $wss, $mtls] as $extra) {
            if ($extra === 0) {
                continue;
            }
            if (in_array($extra, $used, true)) {
                throw new HttpError(422, 'broker_runtime_invalid');
            }
            $used[] = $extra;
        }
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private static function isTightening(array $before, array $after): bool
    {
        if ((bool) $before['plaintext'] && !(bool) $after['plaintext']) {
            return true;
        }
        if ((int) $before['mtls_port'] === 0 && (int) $after['mtls_port'] > 0) {
            return true;
        }
        if ((int) $before['wss_port'] === 0 && (int) $after['wss_port'] > 0) {
            return true;
        }
        $old = self::origins((string) $before['allowed_origins']);
        $new = self::origins((string) $after['allowed_origins']);
        if ($old === [] && $new !== []) {
            return true;
        }
        foreach ($old as $origin) {
            if (!in_array($origin, $new, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<string>
     */
    private static function origins(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }
        $items = [];
        foreach (explode(',', $value) as $origin) {
            $item = strtolower(trim($origin));
            if ($item === '') {
                continue;
            }
            if (strlen($item) > 253 || str_contains($item, "\0")) {
                throw new HttpError(422, 'broker_runtime_invalid');
            }
            if (!in_array($item, $items, true)) {
                $items[] = $item;
            }
        }
        sort($items);
        return $items;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $loaded
     */
    private static function matches(array $snapshot, array $loaded): bool
    {
        foreach (self::KEYS as $key) {
            if (($snapshot[$key] ?? null) !== ($loaded[$key] ?? null)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $config */
    private static function encode(array $config): string
    {
        return json_encode($config, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private static function decode(string $json): array
    {
        $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new HttpError(500, 'broker_runtime_snapshot_invalid');
        }
        return self::normalize($decoded, self::defaults());
    }
}
