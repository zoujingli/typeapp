<?php

declare(strict_types=1);

namespace app\iot\service;

use app\common\service\RoleService;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Mqtt\PendingCommit;
use Type\Orm\Connection;

/** 有界运行观察的所有者；采样事实、同步持久证明、设备在线与页面读取失败分别处理。 */
final class OperationsService
{
    private static bool $persistentQuarantined = false;
    /**
     * 应用内部进程从既有公开统计取值，每五秒覆盖同一稳定节点的一行，保留前一次用于区间速率。
     * 最多64个槽位；新运行替代旧运行后，迟到旧采样不能覆盖。此记录不是业务回执证明。
     */
    public static function observe(Connection $connection, string $kind, string $node, string $run, array $metrics, bool $initial): void
    {
        if (!in_array($kind, ['broker', 'ingestion'], true) || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $node) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $run) !== 1 || $connection->driverName() !== 'pgsql') {
            throw new \InvalidArgumentException('operations_observation_invalid');
        }
        $allowed = $kind === 'broker'
            ? ['connections', 'accepted', 'rejected', 'closed', 'stopping', 'observationFailures', 'subscriptions', 'bufferedBytes',
                'deviceConnections', 'serviceConnections', 'maximumConnections', 'maximumDeviceConnections', 'maximumServiceConnections',
                'incomingExchanges', 'outgoingExchanges', 'pendingCommits', 'durableCommits', 'rejectedCommits', 'unknownCommits', 'quarantinedCommits',
                'connectionQuotaRefusals', 'packetQuotaRefusals', 'subscriptionQuotaRefusals', 'commitQuotaRefusals', 'flushTimeouts', 'handshakeTimeouts',
                'nodeGeneration', 'nodePending', 'nodeFailures', 'pendingFences', 'invalidationFailures', 'invalidationPending',
                'disconnected', 'disconnectFailures', 'disconnectPending', 'terminated', 'terminationFailures', 'terminationPending', 'retainedCleared', 'retainClearFailures', 'retainClearPending', 'quotaRevision', 'quotaFailures', 'handshakeCaReloads', 'handshakeCaFailures', 'processMemoryBytes', 'processPeakMemoryBytes']
            : ['received', 'accepted', 'rejected', 'discarded', 'acknowledged', 'observed', 'pending', 'quarantined', 'running',
                'receiptCount', 'receiptLatencyTotalMs', 'receiptLatencyMaximumMs'];
        $values = [];
        foreach ([...$allowed, 'uptimeMs'] as $key) {
            $value = $metrics[$key] ?? null;
            if ((!is_int($value) && !is_float($value) && !is_bool($value)) || (is_float($value) && !is_finite($value)) || $value < 0) {
                throw new \InvalidArgumentException('operations_metric_invalid');
            }
            $values[$key] = $value;
        }
        $encoded = json_encode($values, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 4096) {
            throw new \InvalidArgumentException('operations_metric_budget');
        }
        $now = time();
        $current = $connection->table('iot_runtime_metrics')->where('kind', '=', $kind)->where('node_id', '=', $node)->first();
        if ($current === null) {
            // 主键和CHECK同时约束实际槽位，竞争插入也不会突破64行；不用无界历史采样表。
            for ($slot = 0; $slot < 64; $slot++) {
                $inserted = $connection->execute('INSERT INTO iot_runtime_metrics (slot, kind, node_id, run_id, observed_at, metrics_json, previous_json) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, NULL) ON CONFLICT DO NOTHING', [$slot, $kind, $node, $run, $now, $encoded]);
                if ($inserted === 1) {
                    return;
                }
                if ($connection->table('iot_runtime_metrics')->where('kind', '=', $kind)->where('node_id', '=', $node)->first() !== null) {
                    break;
                }
            }
        }
        $updated = $connection->execute(
            'UPDATE iot_runtime_metrics SET previous_json = CASE WHEN run_id = ? THEN metrics_json ELSE NULL END, '
            . 'run_id = ?, observed_at = ?, metrics_json = ? WHERE kind = ? AND node_id = ?' . ($initial ? '' : ' AND run_id = ?'),
            $initial ? [$run, $run, $now, $encoded, $kind, $node] : [$run, $run, $now, $encoded, $kind, $node, $run]
        );
        if ($updated !== 1) {
            throw new \RuntimeException('operations_observation_owner_or_capacity');
        }
    }

    /** 平台运行节点独立授权；只返回数值元数据，HTTP存活与同步持久依赖就绪分别展示。 */
    public static function platform(Connection $connection, Identity $identity, string $mqttCommand = '[]'): array
    {
        $access = RoleService::readContext($connection, $identity, 'admin', '', 'operations.read');
        $now = time();
        $nodes = [];
        foreach ($connection->table('iot_runtime_metrics')->orderBy('kind')->orderBy('node_id')->limit(64)->get() as $row) {
            $metrics = json_decode($row['metrics_json'], true, 16, JSON_THROW_ON_ERROR);
            $previous = $row['previous_json'] === null ? null : json_decode($row['previous_json'], true, 16, JSON_THROW_ON_ERROR);
            $elapsed = $previous === null ? 0.0 : ((float) $metrics['uptimeMs'] - (float) $previous['uptimeMs']) / 1000.0;
            $state = ($row['kind'] === 'broker' ? $metrics['stopping'] : !$metrics['running']) ? 'stopped'
                : ($now - (int) $row['observed_at'] >= 15 ? 'unreachable' : 'reporting');
            $rate = null;
            $latency = null;
            $receipts = 0;
            if ($row['kind'] === 'ingestion' && $elapsed > 0 && $state === 'reporting') {
                $received = (int) $metrics['received'] - (int) $previous['received'];
                $receipts = (int) $metrics['receiptCount'] - (int) $previous['receiptCount'];
                if ($received >= 0) {
                    $rate = (float) $received / $elapsed;
                }
                if ($receipts > 0 && $metrics['receiptLatencyTotalMs'] >= $previous['receiptLatencyTotalMs']) {
                    $latency = ((float) $metrics['receiptLatencyTotalMs'] - (float) $previous['receiptLatencyTotalMs']) / (float) $receipts;
                }
            }
            $nodes[] = ['kind' => $row['kind'], 'node_id' => $row['node_id'], 'run_id' => $row['run_id'], 'state' => $state,
                'observed_at' => (int) $row['observed_at'], 'expires_at' => (int) $row['observed_at'] + 15,
                'interval_seconds' => $elapsed > 0 ? $elapsed : null, 'received_per_second' => $rate, 'receipt_latency_mean_ms' => $latency,
                'receipt_samples' => $latency === null ? 0 : $receipts, 'metrics' => $metrics];
        }
        $command = $mqttCommand === '' ? [] : json_decode($mqttCommand, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($command) || !array_is_list($command)) {
            throw new \InvalidArgumentException('operations_worker_command_invalid');
        }
        foreach ($command as $argument) {
            if (!is_string($argument) || $argument === '' || str_contains($argument, "\0")) {
                throw new \InvalidArgumentException('operations_worker_command_invalid');
            }
        }
        $persistent = ['state' => $connection->driverName() !== 'pgsql' ? 'unsupported' : 'unavailable', 'observed_at' => $now, 'metrics' => null, 'nodes' => null];
        if ($connection->driverName() === 'pgsql' && $command !== [] && !self::$persistentQuarantined) {
            // 与CLI统计入口共用受控worker和精确清理；HTTP进程不直接借用同步MQTT数据库租约。
            $usage = self::persistent($command, 'session_statistics');
            if ($usage->state === 'committed' && $usage->released) {
                $registry = self::persistent($command, 'node_statistics');
                if ($registry->state === 'committed' && $registry->released) {
                    $persistent = ['state' => 'available', 'observed_at' => time(), 'metrics' => $usage->value, 'nodes' => $registry->value['nodes']];
                }
            }
        }
        $persistent['quarantined'] = self::$persistentQuarantined;
        if ($access !== RoleService::readContext($connection, $identity, 'admin', '', 'operations.read')) {
            throw new HttpError(403, 'authorization_changed');
        }
        return ['scope' => 'platform', 'generated_at' => time(), 'freshness_seconds' => 15, 'nodes' => $nodes,
            'health' => ['http_alive' => true, 'durable_store_ready' => $persistent['state'] === 'unsupported' ? null : $persistent['state'] === 'available'],
            'coverage' => 'observed_instances_only', 'latency_scope' => 'application_receive_to_receipt_publish_ack', 'store' => $persistent];
    }

    private static function persistent(array $command, string $action): \Type\Mqtt\CommitResult
    {
        $pending = new PendingCommit([...$command, 'iot:mqtt-store'], ['action' => $action, 'operation_id' => bin2hex(random_bytes(16))]);
        do {
            $result = $pending->poll();
            if ($result === null) {
                usleep(10000);
            }
        } while ($result === null);
        if (!$result->released) {
            // 本HTTP进程保留该未清理资源额度；后续刷新不继续创建数据库工作进程。
            self::$persistentQuarantined = true;
        }
        return $result;
    }

    /** 租户只获得自己的首次接收记录和有界设备页；设备投影沿用现有连接和实时遥测规则。 */
    public static function tenant(Connection $connection, Identity $identity, string $tenantId, int $page, int $perPage, array $filters): array
    {
        $access = RoleService::readContext($connection, $identity, 'customer', $tenantId, 'operations.read');
        $devices = DeviceService::observations($connection, $tenantId, $page, $perPage, $filters);
        $freshness = IngestionService::freshness($connection, $tenantId, array_column($devices['items'], 'id'));
        $now = time();
        $records = $connection->query('SELECT COUNT(*) AS recorded, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS rejected '
            . 'FROM iot_ingestion WHERE tenant_id = ? AND received_at > ? AND received_at <= ?', ['rejected', $tenantId, $now - 60, $now])[0];
        $items = [];
        foreach ($devices['items'] as $device) {
            $current = $freshness[$device['id']] ?? null;
            if ($current === null || $current['ownership_id'] !== $device['ownership_id'] || $current['model_version'] !== (int) $device['model_version']) {
                // 归属/模型已在分页之后改变，不能把两次快照拼成一台设备的当前事实。
                continue;
            }
            $items[] = ['id' => $device['id'], 'name' => $device['name'], 'lifecycle' => $device['lifecycle'],
                'connection' => $device['connection'], 'authorization' => ['status' => $device['authorization']['status'],
                    'requested_at' => $device['authorization']['requested_at'], 'completed_at' => $device['authorization']['completed_at']],
                'current' => ['freshness' => $current['freshness'], 'received_at' => $current['received_at'], 'sampled_at' => $current['sampled_at']]];
        }
        $devices['items'] = $items;
        if ($access !== RoleService::readContext($connection, $identity, 'customer', $tenantId, 'operations.read')) {
            throw new HttpError(403, 'authorization_changed');
        }
        return ['scope' => 'tenant', 'generated_at' => $now, 'window_seconds' => 60,
            'recorded' => (int) $records['recorded'], 'rejected' => (int) ($records['rejected'] ?? 0),
            'recorded_per_second' => (float) $records['recorded'] / 60.0, 'devices' => $devices];
    }
}
