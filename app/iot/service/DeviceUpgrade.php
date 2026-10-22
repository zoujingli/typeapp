<?php

declare(strict_types=1);

namespace app\iot\service;

use app\broker\service\CompatService;
use Type\Orm\Connection;

/**
 * 停写升级中的设备转换所有者；只在既有迁移锁内写入，账本与实体同事务提交。
 * 历史数据、凭据及未决任务保持原件；本类不执行旧任务，不解释设备载荷。
 */
final class DeviceUpgrade
{
    /** @var array<string,list<string>> 退役处理链的稳定原件及其确定排序键。 */
    private const PRESERVED = [
        'iot_models' => ['tenant_id', 'product_id', 'model_version'],
        'iot_model_switches' => ['id'], 'iot_transfers' => ['id'],
        'iot_ingestion' => ['message_id'], 'iot_ingestion_facts' => ['message_id'],
        'iot_ingestion_completed' => ['message_id', 'consumer'], 'iot_current_data' => ['device_id'],
        'iot_device_status' => ['device_id'], 'iot_minute_aggregates' => ['id'],
        'iot_commands' => ['id'], 'iot_command_attempts' => ['id'], 'iot_command_uncertainties' => ['command_id'],
        'iot_alarm_rules' => ['id'], 'iot_alarm_rule_versions' => ['rule_id', 'version'],
        'iot_alarm_states' => ['rule_id', 'rule_version'], 'iot_alarms' => ['id'],
        'iot_notifications' => ['id'], 'iot_notice_outbox' => ['id'],
        'iot_exports' => ['id'], 'iot_export_rows' => ['export_id', 'source_id'], 'iot_export_outbox' => ['id'],
    ];

    /**
     * 只读库存与最多一页设备决策；after 是设备 ID 游标，不把截断的一页当作全部设备。
     * @return array<string,mixed> 不包含口令、凭据散列、业务载荷或物理数据库路径。
     */
    public static function inspect(Connection $connection, string $after = ''): array
    {
        $installation = $connection->table('app_installation')->where('id', '=', 1)->first();
        if ($installation === null) {
            throw new \InvalidArgumentException('upgrade_installation_missing');
        }
        $run = self::hasLedger($connection) ? $connection->table('iot_architecture_upgrade')->where('id', '=', 1)->first() : null;
        $entities = [];
        foreach (['iot_tenants', 'admin_users', 'customer_users', 'admin_roles', 'customer_roles', 'customer_members', 'iot_devices', 'iot_device_ownerships', 'iot_device_credentials'] as $table) {
            $entities[$table] = (int) $connection->table($table)->aggregate('COUNT');
        }
        $pending = [];
        foreach ([
            'commands' => "SELECT COUNT(*) AS total FROM iot_commands WHERE cancelled_at IS NULL AND dispatch_stopped_at IS NULL AND (result_status IS NULL OR result_status NOT IN ('succeeded', 'failed', 'rejected'))",
            'uncertain_commands' => 'SELECT COUNT(*) AS total FROM iot_command_uncertainties',
            'ingestion' => "SELECT COUNT(*) AS total FROM iot_ingestion WHERE status = 'accepted'",
            'notifications' => 'SELECT COUNT(*) AS total FROM iot_notice_outbox WHERE consumed_at IS NULL',
            'exports' => "SELECT COUNT(*) AS total FROM iot_exports WHERE status IN ('queued', 'running')",
            'model_switches' => "SELECT COUNT(*) AS total FROM iot_model_switches WHERE status NOT IN ('confirmed', 'rejected')",
            'transfers' => "SELECT COUNT(*) AS total FROM iot_transfers WHERE status NOT IN ('completed', 'rejected', 'cancelled')",
        ] as $kind => $sql) {
            $pending[$kind] = (int) $connection->query($sql)[0]['total'];
        }
        $rows = $connection->table('iot_devices')->whereNull('model_version', true)->where('id', '>', $after)->orderBy('id')->limit(101)->get();
        $more = count($rows) > 100;
        $devices = [];
        foreach (array_slice($rows, 0, 100) as $row) {
            $reasons = self::reasons($connection, $row);
            $devices[] = ['device_id' => $row['id'], 'tenant_id' => $row['tenant_id'], 'ownership_id' => $row['ownership_id'],
                'lifecycle' => $row['lifecycle'], 'decision' => $reasons === [] ? 'convert' : 'quarantine', 'reasons' => $reasons];
        }
        return ['installation_id' => $installation['installation_id'], 'state' => $run['state'] ?? 'not_started',
            'legacy_devices' => (int) $connection->table('iot_devices')->whereNull('model_version', true)->aggregate('COUNT'),
            'entities' => $entities, 'pending' => $pending, 'devices' => $devices, 'has_more_devices' => $more,
            'next_device_cursor' => $more ? $devices[count($devices) - 1]['device_id'] : null,
            'active_mqtt_nodes' => self::activeNodes($connection),
            'stop_required' => ['http', 'mqtt', 'ingestion', 'commands', 'notifications', 'exports', 'scheduler'],
            'backup_required' => ['management_database', 'mqtt_store', 'application_data_and_private_exports'],
            'source' => $run === null ? null : json_decode($run['source_json'], true, 32, JSON_THROW_ON_ERROR),
            'preserved' => $run === null ? [] : json_decode($run['preserved_json'], true, 32, JSON_THROW_ON_ERROR),
            'backup_sha256' => $run['backup_sha256'] ?? null,
            'converted' => $run === null ? 0 : (int) $connection->table('iot_device_upgrade_records')->where('decision', '=', 'converted')->aggregate('COUNT'),
            'quarantined' => $run === null ? 0 : (int) $connection->table('iot_device_upgrade_records')->where('decision', '=', 'quarantined')->aggregate('COUNT')];
    }

    /** 新安装没有旧设备；沿用已完成状态，不制造旧转换来源或注册资格。 */
    public static function initialize(Connection $connection, string $installation): void
    {
        $connection->table('iot_architecture_upgrade')->insert(['id' => 1, 'installation_id' => $installation, 'state' => 'complete',
            'backup_sha256' => '', 'source_json' => '{}', 'preserved_json' => '{}', 'last_device_id' => '', 'started_at' => time(), 'completed_at' => time()]);
    }

    /** 存在未完成转换时关闭 HTTP 和后台角色；纯预检、迁移恢复与备份不经过该门。 */
    public static function ready(Connection $connection): void
    {
        if (!self::hasLedger($connection)) {
            throw new \InvalidArgumentException('upgrade_data_required：先执行 app:upgrade --check 并停写升级');
        }
        $row = $connection->table('iot_architecture_upgrade')->where('id', '=', 1)->first();
        if ($row === null || $row['state'] !== 'complete') {
            throw new \InvalidArgumentException('upgrade_data_incomplete：保持停写，使用原备份身份继续 app:upgrade');
        }
    }

    /** 离线是操作人对全部写角色的确认；可观察到活动节点时仍拒绝，过期不等于基础设施隔离证明。 */
    public static function offline(Connection $connection): void
    {
        if (self::activeNodes($connection) !== []) {
            throw new \InvalidArgumentException('upgrade_writers_active：先停止 MQTT 节点及全部写角色');
        }
    }

    /**
     * 同一迁移锁内每次至多转换 100 个设备；游标、原件和设备变更原子提交，中断后重复原命令继续。
     * 完成前不开放运行门；MySQL 已提交的结构由迁移器记录，不在这里假称 DDL 回滚。
     */
    public static function advance(Connection $connection, string $backup, array $states): void
    {
        self::offline($connection);
        $run = $connection->table('iot_architecture_upgrade')->where('id', '=', 1)->first();
        if ($run === null) {
            $inventory = self::inspect($connection);
            $source = ['installation_id' => $inventory['installation_id'], 'entities' => $inventory['entities'], 'pending' => $inventory['pending'],
                'migrations' => $states, 'compatibility' => CompatService::current($connection)];
            $preserved = [];
            foreach (self::PRESERVED as $table => $keys) {
                $preserved[$table] = self::fingerprint($connection, $table, $keys);
            }
            $connection->transaction(static function (Connection $transaction) use ($source, $preserved, $backup): void {
                $transaction->table('iot_architecture_upgrade')->insert(['id' => 1, 'installation_id' => $source['installation_id'], 'state' => 'converting',
                    'backup_sha256' => $backup, 'source_json' => json_encode($source, JSON_THROW_ON_ERROR), 'preserved_json' => json_encode($preserved, JSON_THROW_ON_ERROR),
                    'last_device_id' => '', 'started_at' => time(), 'completed_at' => null]);
                // 数据转换前推进已有兼容门，旧二进制不能启动部分升级后的库。
                CompatService::recordUpgrade($transaction);
            });
            $run = $connection->table('iot_architecture_upgrade')->where('id', '=', 1)->first();
        }
        if ($run['backup_sha256'] !== '' && !hash_equals($run['backup_sha256'], $backup)) {
            throw new \InvalidArgumentException('upgrade_backup_identity_changed：继续转换必须使用首次核对的备份');
        }
        if ($run['state'] === 'complete') {
            // 完成后出现旧写入由维护入口核对；通信热路径只读单行账本，不扫描设备集合。
            if ($connection->table('iot_devices')->whereNull('model_version', true)->first() !== null) {
                throw new \InvalidArgumentException('upgrade_legacy_device_after_completion：检测到旧写入，保持维护并核对安装来源');
            }
            return;
        }
        $connection->transaction(static function (Connection $transaction) use ($run): void {
            $rows = $transaction->table('iot_devices')->whereNull('model_version', true)->where('id', '>', $run['last_device_id'])->orderBy('id')->limit(100)->get();
            $cursor = $run['last_device_id'];
            foreach ($rows as $row) {
                $reasons = self::reasons($transaction, $row);
                $credentials = self::fingerprint($transaction, 'iot_device_credentials', ['id'], $row['id']);
                $transaction->table('iot_device_upgrade_records')->insert(['device_id' => $row['id'], 'installation_id' => $run['installation_id'],
                    'source_json' => json_encode($row, JSON_THROW_ON_ERROR), 'credential_sha256' => $credentials['sha256'],
                    'ownership_id' => $row['ownership_id'], 'decision' => $reasons === [] ? 'converted' : 'quarantined',
                    'reasons_json' => json_encode($reasons, JSON_THROW_ON_ERROR), 'converted_at' => time()]);
                $changes = ['product_id' => null, 'model_version' => null, 'model_switch_id' => null, 'transfer_id' => null,
                    'transfer_frozen' => 0, 'version' => (int) $row['version'] + 1, 'updated_at' => time()];
                if ($reasons !== []) {
                    $changes['recovery_verified'] = 0;
                    $changes['lifecycle'] = $row['lifecycle'] === 'retired' ? 'retired' : 'disabled';
                }
                $transaction->table('iot_devices')->where('id', '=', $row['id'])->update($changes);
                $cursor = $row['id'];
            }
            $remaining = (int) $transaction->table('iot_devices')->whereNull('model_version', true)->aggregate('COUNT');
            if ($remaining > 0 && $rows === []) {
                throw new \InvalidArgumentException('upgrade_legacy_device_order_changed：转换游标之前出现旧写入，保持维护并核对备份');
            }
            if ($remaining === 0) {
                foreach (json_decode($run['preserved_json'], true, 32, JSON_THROW_ON_ERROR) as $table => $expected) {
                    if (self::fingerprint($transaction, $table, self::PRESERVED[$table]) !== $expected) {
                        throw new \InvalidArgumentException('upgrade_preserved_data_changed：旧处理链仍有写入，保持维护并核对备份');
                    }
                }
            }
            $transaction->table('iot_architecture_upgrade')->where('id', '=', 1)->update(['state' => $remaining === 0 ? 'complete' : 'converting',
                'last_device_id' => $cursor, 'completed_at' => $remaining === 0 ? time() : null]);
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    }

    /** 设备详情只投影转换决定和原分类，不公开原件 JSON 或凭据指纹。 */
    public static function device(Connection $connection, string $id): ?array
    {
        $row = $connection->table('iot_device_upgrade_records')->where('device_id', '=', $id)->first();
        if ($row === null) {
            return null;
        }
        $source = json_decode($row['source_json'], true, 8, JSON_THROW_ON_ERROR);
        return ['source' => 'legacy_installation', 'decision' => $row['decision'], 'reasons' => json_decode($row['reasons_json'], true, 8, JSON_THROW_ON_ERROR),
            'product_id' => $source['product_id'], 'model_version' => (int) $source['model_version'], 'lifecycle' => $source['lifecycle'], 'converted_at' => (int) $row['converted_at']];
    }

    /** 旧字段矛盾或未决业务效果只隔离，不猜测设备是否完成了操作。 */
    private static function reasons(Connection $connection, array $device): array
    {
        $reasons = [];
        $ownership = $connection->table('iot_device_ownerships')->where('id', '=', $device['ownership_id'])->where('device_id', '=', $device['id'])->where('tenant_id', '=', $device['tenant_id'])->first();
        if ($ownership === null || $ownership['ended_at'] !== null) {
            $reasons[] = 'ownership_requires_review';
        }
        if ((int) $device['recovery_verified'] !== 1) {
            $reasons[] = 'recovery_not_verified';
        }
        if ($device['model_switch_id'] !== null || $device['transfer_id'] !== null || (int) $device['transfer_frozen'] !== 0) {
            $reasons[] = 'legacy_handoff_unresolved';
        }
        $active = $connection->table('iot_device_credentials')->where('device_id', '=', $device['id'])->where('status', '=', 'active')->limit(2)->get();
        if (count($active) > 1 || ($active !== [] && ($active[0]['ownership_id'] !== $device['ownership_id'] || (int) $active[0]['recovery_verified'] !== 1))) {
            $reasons[] = 'credential_requires_review';
        }
        if ($connection->table('iot_authorization_invalidations')->where('device_id', '=', $device['id'])->whereNull('completed_at')->first() !== null) {
            $reasons[] = 'authorization_isolation_pending';
        }
        if ($connection->query("SELECT id FROM iot_commands WHERE device_id = ? AND cancelled_at IS NULL AND dispatch_stopped_at IS NULL AND (result_status IS NULL OR result_status NOT IN ('succeeded', 'failed', 'rejected')) LIMIT 1", [$device['id']]) !== []
            || $connection->table('iot_command_uncertainties')->where('device_id', '=', $device['id'])->first() !== null) {
            $reasons[] = 'legacy_command_outcome_unknown';
        }
        return $reasons;
    }

    /** 只读取运行观察，不把观察超时当作进程已隔离；--offline 仍要求维护者停写。 */
    private static function activeNodes(Connection $connection): array
    {
        return $connection->table('broker_resource_runs')->where('expires_at', '>', time())->select(['node_id', 'run_id', 'expires_at'])->orderBy('node_id')->get();
    }

    /** 游标分块摘要，内存与每批记录数有界；表名和排序键仅来自固定历史目录。 */
    private static function fingerprint(Connection $connection, string $table, array $keys, string $device = ''): array
    {
        $hash = hash_init('sha256');
        $count = 0;
        $cursor = [];
        do {
            $where = $cursor === [] ? '' : (count($keys) === 1 ? $keys[0] . ' > ?' : '(' . implode(', ', $keys) . ') > (' . implode(', ', array_fill(0, count($keys), '?')) . ')');
            $bindings = $cursor;
            if ($device !== '') {
                $where .= ($where === '' ? '' : ' AND ') . 'device_id = ?';
                $bindings[] = $device;
            }
            $rows = $connection->query('SELECT * FROM ' . $table . ($where === '' ? '' : ' WHERE ' . $where) . ' ORDER BY ' . implode(', ', $keys) . ' LIMIT 100', $bindings);
            foreach ($rows as $row) {
                hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR) . "\n");
                $count++;
                $cursor = [];
                foreach ($keys as $key) {
                    $cursor[] = $row[$key];
                }
            }
        } while (count($rows) === 100);
        return ['rows' => $count, 'sha256' => hash_final($hash)];
    }

    private static function hasLedger(Connection $connection): bool
    {
        return $connection->table('type_migrations')->where('version', '=', '107_iot_raw_device_upgrade')->where('state', '=', 'applied')->first() !== null;
    }
}
