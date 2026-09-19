<?php

declare(strict_types=1);

namespace Type\Mqtt;

use Closure;
use Type\Orm\Connection;
use Type\Orm\Database;
use Type\Orm\Driver;
use Type\Orm\TransactionOutcome;
use Type\Runtime\Deadline;
use Type\Runtime\ExecutionScope;

/**
 * PostgreSQL 同步持久接管核心；仅在有硬进程截止的受控 worker 中运行，不在 Broker 网络循环调用 PDO。
 * 每次操作独占短租约，保留未知提交；只接受配置白名单中当前明确选定的单个同步备库。
 */
final class PostgresStore
{
    /** @var list<string> 可参与同步接管的稳定节点名，不以通配符信任任意复制连接。 */
    private array $standbyNames;

    /**
     * 预算同时计入待完成消息原件及每份待交付副本；完成事实保留，不靠删除未完成记录腾空间。
     * 保留事实另外计入 maximumRetainedMessages/maximumRetainedBytes，期限按 Unix 秒保存。
     *
     * @param string $standbyName 单个节点名或逗号分隔的至多三个节点名；Patroni切换时仍须选择其中恰好一个合格同步备库。
     * @throws \InvalidArgumentException 驱动、同步备库白名单或待处理预算非法。
     */
    public function __construct(
        private Driver $driver,
        string $standbyName = 'iot_sync',
        private int $maximumPendingMessages = 2000000,
        private int $maximumPendingBytes = 4294967296,
        private int $maximumRetainedMessages = 2000000,
        private int $maximumRetainedBytes = 4294967296,
        private int $maximumSharedMessages = 1000000,
        private int $maximumSharedBytes = 2147483648,
        private int $maximumSessions = 20000,
        private int $maximumDeviceMessages = 10000,
        private int $maximumDeviceBytes = 16777216,
        private int $maximumApplicationMessages = 1000000,
        private int $maximumApplicationBytes = 2147483648
    ) {
        if ($driver->name() !== 'pgsql' || ($driver->identity()['role'] ?? 'writer') !== 'writer'
            || preg_match('/^[a-z][a-z0-9_]{0,47}(?:,[a-z][a-z0-9_]{0,47}){0,2}$/D', $standbyName) !== 1
            || $maximumPendingMessages < 1 || $maximumPendingMessages > 2000000
            || $maximumPendingBytes < 1 || $maximumPendingBytes > 4294967296
            || $maximumRetainedMessages < 1 || $maximumRetainedMessages > 2000000
            || $maximumRetainedBytes < 1 || $maximumRetainedBytes > 4294967296
            || $maximumSharedMessages < 1 || $maximumSharedMessages > 1000000
            || $maximumSharedBytes < 1 || $maximumSharedBytes > 2147483648
            || $maximumSessions < 1 || $maximumSessions > 20000
            || $maximumDeviceMessages < 1 || $maximumDeviceMessages > 10000
            || $maximumDeviceBytes < 1 || $maximumDeviceBytes > 16777216
            || $maximumApplicationMessages < 1 || $maximumApplicationMessages > 1000000
            || $maximumApplicationBytes < 1 || $maximumApplicationBytes > 2147483648) {
            throw new \InvalidArgumentException('MQTT 持久存储需要 PostgreSQL 写入驱动及有界同步配置');
        }
        $this->standbyNames = explode(',', $standbyName);
        if (count(array_unique($this->standbyNames)) !== count($this->standbyNames)) {
            throw new \InvalidArgumentException('MQTT 同步备库白名单不能重复');
        }
    }

    /** 显式建立或升级消息、交付、保留、会话与审计表；返回前核对同步 WAL 证明，不在运行时隐式迁移。 */
    public function install(): CommitResult
    {
        return $this->run(['operation_id' => bin2hex(random_bytes(16)), 'action' => 'install']);
    }

    /**
     * 同事务接管消息、推进交换，或保存、恢复、结束会话及订阅；仅供受控 worker 调用。
     * 调用方生成唯一 operation_id；重复 PUBLISH 的协议关联由当前会话维护，不重试未知操作。
     *
     * @param array<string,mixed> $request operation_id/action 必需，具体 action 字段见组件 README；二进制字段使用严格 base64。
     *                                     会话命令和 Broker 派生消息命令带 session_id/owner_id；所有者变化后拒绝旧命令。
     *                                     会话 expiry 为断线后的秒数，-1 仅表示 MQTT 3.1.1 无限会话。
     *                                     消息 expires_at 为 Unix 秒截止，null 为无期限；保留写入/快照接管必须显式提供。
     *                                     retained_read 接受过滤器与可选 cursor，每次扫描至多32键、返回至多一条快照及 done/cursor。
     *                                     retained_accept 不修改保留表或建立入站交换；subscription_identifiers 只属于 delivery。
     *                                     resource_list/detail 接受 resource=sessions/connections/subscriptions/retained/backlog。
     *                                     authorization 由宿主本次授权生成：all_metadata=true，或 false 配合 resource_scope/topic_namespace。
     *                                     filters 为精确字段：session/client/node/state，订阅另 qos，保留 topic/state/qos，积压 topic/session/state/qos/kind。
     *                                     列表 limit 默认20、最大100，cursor 为上页 next_cursor，最多2048字节；详情使用列表 id。
     *                                     列表返回 items/next_cursor/has_more/total(null)/observed_at/source；详情 found/item/observed_at/source。
     *                                     所有资源只返回元数据且最多1MiB。connections 是 durable_owner 意图；真实在线必须使用成功 CONNACK 观察。
     *                                     node_fence可带稳定action_operation_id与准确generation/observation_run；相同动作只返回原事实。
     *                                     node_fence_result按原动作查询并产生新的同步屏障；缺失不是未执行证明。
     *                                     对账可传origin_request_id，origin_released仅说明准确原请求后端已消失，仍须检查本次released。
     *                                     session_terminate 可按精确 session_id/session_generation/actor 删除该代次，或按遗留 client_id/actor 终止；代次不匹配不得删除新会话。
     *                                     retain_clear 按精确 resource_id/generation/actor 删除当前保留原件，不派生交付、不删除并发替换的新原件。
     *                                     quota_apply 按版本写入运行额度，不删除已确认消息、会话或保留原件。
     */
    public function execute(array $request): CommitResult
    {
        if (!in_array($request['action'] ?? '', ['install', 'accept', 'complete', 'received', 'release', 'abandon', 'retained_read', 'retained_accept', 'retained_advance',
            'retain_clear', 'quota_apply', 'node_open', 'node_poll', 'node_close', 'node_fence', 'node_fence_result', 'node_statistics',
            'session_open', 'session_save', 'session_close', 'session_next', 'session_terminate', 'session_recover', 'session_statistics',
            'will_read', 'will_accept', 'will_discard', 'will_defer',
            'shared_next', 'shared_claim', 'shared_drop', 'resource_list', 'resource_detail'], true)) {
            return new CommitResult(is_string($request['operation_id'] ?? null) ? $request['operation_id'] : '', 'rejected', 0x83);
        }
        return $this->run($request);
    }

    /**
     * 硬停止 worker 后，精确终止相同数据库、角色及 operation_id 的后端并确认消失。
     * 无法连接、确认或释放本次清理租约时返回 false；不得用 pg_cancel_backend 代替终止。
     */
    public function cleanup(string $operationId): bool
    {
        if (!self::identifier($operationId, 32)) {
            return false;
        }
        $database = new Database($this->driver, 1, 0);
        $scope = new ExecutionScope(new Deadline(3.0));
        $released = true;
        $disappeared = false;
        try {
            $connection = $database->connect($scope);
            $connection->rawQuery("SELECT set_config('statement_timeout', '1500', false)");
            $parameters = ['type_mqtt_' . $operationId];
            $predicate = ' FROM pg_stat_activity WHERE datname = current_database() AND usename = session_user'
                . " AND backend_type = 'client backend' AND application_name = ? AND pid <> pg_backend_pid()";
            $connection->query('SELECT pg_terminate_backend(pid, 500) AS terminated' . $predicate, $parameters);
            do {
                $remaining = $connection->query('SELECT pid' . $predicate, $parameters);
                if ($remaining === []) {
                    $disappeared = true;
                    break;
                }
                usleep(10000);
            } while (!$scope->deadline()->expired());
        } catch (\Throwable $error) {
            $disappeared = false;
        } finally {
            $released = $this->release($scope, $database);
        }
        return $disappeared && $released;
    }

    private function run(array $request): CommitResult
    {
        $operationId = is_string($request['operation_id'] ?? null) ? $request['operation_id'] : '';
        try {
            $this->validate($request);
        } catch (\Throwable $invalid) {
            return new CommitResult($operationId, 'rejected', 0x83);
        }
        return $this->transaction($operationId, function (Connection $transaction) use ($request): array {
            if (in_array($request['action'], ['resource_list', 'resource_detail'], true)) {
                // 管理读取不参与容量锁、所有者接管、到期清理或协议游标推进。
                return $this->resources($transaction, $request);
            }
            // 同一事务锁覆盖容量查验和全部写入，避免多个 worker 同时超卖配额。
            $transaction->query('SELECT pg_advisory_xact_lock(1954115693, 1)');
            if (!$this->synchronous($transaction)) {
                throw new ProtocolError(0x88);
            }
            if ($request['action'] !== 'install') {
                $this->hydrateQuotas($transaction);
            }
            if ($request['action'] === 'quota_apply') {
                return $this->applyQuotas($transaction, $request);
            }
            if (str_starts_with($request['action'], 'node_')) {
                return $this->node($transaction, $request);
            }
            if (str_starts_with($request['action'], 'session_')) {
                return $this->session($transaction, $request);
            }
            if (str_starts_with($request['action'], 'will_')) {
                return $this->will($transaction, $request);
            }
            if (isset($request['owner_id'])) {
                $this->owned($transaction, $request['session_id'], $request['owner_id']);
            }
            if (str_starts_with($request['action'], 'shared_')) {
                return $this->shared($transaction, $request);
            }
            if ($request['action'] === 'retain_clear') {
                return $this->clearRetained($transaction, $request);
            }
            if ($request['action'] === 'retained_read') {
                return $this->readRetained($transaction, $request);
            }
            if (in_array($request['action'], ['accept', 'retained_accept'], true)) {
                return $this->accept($transaction, $request, $request['action'] === 'accept');
            }
            match ($request['action']) {
                'install' => $this->schema($transaction),
                'retained_advance' => $this->advanceRetained($transaction, $request),
                'complete' => $this->complete($transaction, $request),
                'received' => $this->received($transaction, $request),
                'release' => $this->released($transaction, $request),
                'abandon' => $this->abandon($transaction, $request['session_id']),
            };
            return [];
        }, $request['action'] === 'install' ? 'schema' : 'default');
    }

    /**
     * 在受控worker中执行业务同步事务，和Broker接管共用租约、截止、WAL及同步备库证明。
     * 回调须在给定连接产生本次写入；已有未知记录不能以只读可见性代替新的持久屏障。
     * 不在回调中自行提交、发送网络消息或泄漏连接；只有committed且released才能对外确认。
     * @param Closure(Connection): array<string,mixed> $operation 一个有界业务事务，结果仅在证明成功后返回。
     * @param string $mode default或schema，沿用驱动真实事务模式。
     */
    public function transaction(string $operationId, Closure $operation, string $mode = 'default'): CommitResult
    {
        if (!self::identifier($operationId, 32) || !in_array($mode, ['default', 'schema'], true)) {
            return new CommitResult($operationId, 'rejected', 0x83);
        }
        $database = new Database($this->driver, 1, 0);
        $scope = new ExecutionScope(new Deadline(3.0));
        $connection = null;
        $state = 'rejected';
        $reason = 0x88;
        $barrier = '';
        $replicas = [];
        $released = true;
        $value = [];
        try {
            $connection = $database->connect($scope);
            $connection->rawQuery("SELECT set_config('application_name', ?, false), set_config('synchronous_commit', 'remote_apply', false), "
                . "set_config('statement_timeout', '1500', false), set_config('lock_timeout', '1000', false), "
                . "set_config('idle_in_transaction_session_timeout', '1500', false)", ['type_mqtt_' . $operationId]);
            if ($this->synchronous($connection)) {
                $value = $connection->transaction(function (Connection $transaction) use ($operation): array {
                    if (!$this->synchronous($transaction)) {
                        throw new ProtocolError(0x88);
                    }
                    return $operation($transaction);
                }, $mode);
                // COMMIT 可能在 SyncRep 被取消后仍返回 true；它本身不足以证明同步接管。
                $state = 'unknown';
                // remote_apply 在等待备库前已刷盘本次 COMMIT。取实际刷盘末尾，避免 insert 指针把尚无记录的下一页头计入屏障。
                $barrier = (string) $connection->query('SELECT pg_current_wal_flush_lsn()::text AS lsn')[0]['lsn'];
                do {
                    $replicas = $this->replicas($connection, $barrier);
                    if (count($replicas) === 1 && self::truth($replicas[0]['proven']) && $this->synchronous($connection)) {
                        $state = 'committed';
                        $reason = 0;
                        break;
                    }
                    usleep(10000);
                } while (!$scope->deadline()->expired());
            }
        } catch (\Throwable $failure) {
            $outcome = $connection === null ? TransactionOutcome::NOT_STARTED : $connection->transactionOutcome();
            $state = in_array($outcome, [TransactionOutcome::UNKNOWN, TransactionOutcome::COMMITTED], true) ? 'unknown' : 'rejected';
            $reason = $state === 'rejected' && $failure instanceof ProtocolError ? $failure->reason : 0x88;
        } finally {
            $released = $this->release($scope, $database);
        }
        return new CommitResult($operationId, $state, $reason, $barrier === '' ? [] : ['wal_lsn' => $barrier, 'replicas' => $replicas], $released, $state === 'committed' ? $value : []);
    }

    private function release(ExecutionScope $scope, Database $database): bool
    {
        $released = true;
        try {
            $scope->close();
        } catch (\Throwable $scopeFailure) {
            $released = false;
        }
        try {
            $database->close();
        } catch (\Throwable $databaseFailure) {
            $released = false;
        }
        $statistics = $database->statistics();
        return $released && $scope->state() === 'closed' && $statistics['leased'] === 0 && $statistics['idle'] === 0;
    }

    private function synchronous(Connection $connection): bool
    {
        return count($this->replicas($connection, '0/0')) === 1;
    }

    /**
     * Patroni严格同步且synchronous_node_count=1时使用裸节点名；同时保留既有FIRST 1写法。
     * 每次读取实际选择，切换后不沿用旧备库缓存；通配符、多同步、quorum和白名单外节点均拒绝。
     */
    private function replicas(Connection $connection, string $barrier): array
    {
        $settings = $connection->query("SELECT current_setting('fsync') AS fsync, current_setting('full_page_writes') AS full_page_writes, "
            . "current_setting('synchronous_commit') AS synchronous_commit, current_setting('synchronous_standby_names') AS names, "
            . 'pg_is_in_recovery() AS recovery')[0];
        if ($settings['fsync'] !== 'on' || $settings['full_page_writes'] !== 'on' || $settings['synchronous_commit'] !== 'remote_apply'
            || self::truth($settings['recovery'])) {
            return [];
        }
        $selection = trim((string) $settings['names']);
        $match = [];
        if (preg_match('/^(?:FIRST\s+)?1\s*\(\s*([^()]+?)\s*\)$/iD', $selection, $match) === 1) {
            $selection = trim($match[1]);
        }
        if (preg_match('/^(?:[a-z][a-z0-9_]{0,47}|"[a-z][a-z0-9_]{0,47}")$/D', $selection) !== 1) {
            return [];
        }
        $selectedName = trim($selection, '"');
        if (!in_array($selectedName, $this->standbyNames, true)) {
            return [];
        }
        return $connection->query('SELECT application_name, state, sync_state, sent_lsn::text, write_lsn::text, flush_lsn::text, replay_lsn::text, '
            . '(flush_lsn >= CAST(? AS pg_lsn) AND replay_lsn >= CAST(? AS pg_lsn)) AS proven FROM pg_stat_replication '
            . "WHERE application_name = ? AND state = 'streaming' AND sync_state = 'sync'", [$barrier, $barrier, $selectedName]);
    }

    private function schema(Connection $connection): void
    {
        $connection->execute('CREATE TABLE IF NOT EXISTS type_mqtt_messages (id CHAR(32) PRIMARY KEY, publisher_session CHAR(32) NOT NULL, '
            . 'client_id TEXT NOT NULL, packet_id INTEGER NOT NULL CHECK (packet_id BETWEEN 1 AND 65535), topic TEXT NOT NULL, '
            . 'payload BYTEA NOT NULL, properties BYTEA NOT NULL, qos INTEGER NOT NULL CHECK (qos = 1), byte_size INTEGER NOT NULL CHECK (byte_size > 0), '
            . "state VARCHAR(16) NOT NULL CHECK (state IN ('accepted', 'complete')), created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp())");
        $connection->execute('CREATE TABLE IF NOT EXISTS type_mqtt_deliveries (id CHAR(64) PRIMARY KEY, message_id CHAR(32) NOT NULL REFERENCES type_mqtt_messages (id), '
            . 'session_id CHAR(32) NOT NULL, client_id TEXT NOT NULL, packet_id INTEGER NOT NULL CHECK (packet_id BETWEEN 0 AND 65535), '
            . 'qos INTEGER NOT NULL CHECK (qos IN (0, 1)), '
            . "state VARCHAR(16) NOT NULL CHECK (state IN ('pending', 'acknowledged', 'queued', 'expired', 'closed')), ack_reason INTEGER NULL, "
            . 'created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(), UNIQUE (message_id, session_id))');
        // 显式升级现有表约束；只改 CREATE IF NOT EXISTS 无法让旧安装接受 QoS 2。
        $connection->execute('ALTER TABLE type_mqtt_messages DROP CONSTRAINT IF EXISTS type_mqtt_messages_qos_check');
        $connection->execute('ALTER TABLE type_mqtt_messages ADD CONSTRAINT type_mqtt_messages_qos_check CHECK (qos IN (0, 1, 2))');
        $connection->execute('ALTER TABLE type_mqtt_messages DROP CONSTRAINT IF EXISTS type_mqtt_messages_packet_id_check');
        $connection->execute('ALTER TABLE type_mqtt_messages ADD CONSTRAINT type_mqtt_messages_packet_id_check '
            . 'CHECK ((qos = 0 AND packet_id = 0) OR (qos > 0 AND packet_id BETWEEN 1 AND 65535))');
        $connection->execute("ALTER TABLE type_mqtt_messages ADD COLUMN IF NOT EXISTS inbound_state VARCHAR(16) NOT NULL DEFAULT 'none' "
            . "CHECK (inbound_state IN ('none', 'received', 'released', 'closed')), ADD COLUMN IF NOT EXISTS inbound_reason INTEGER NULL");
        $connection->execute('ALTER TABLE type_mqtt_deliveries DROP CONSTRAINT IF EXISTS type_mqtt_deliveries_qos_check');
        $connection->execute('ALTER TABLE type_mqtt_deliveries ADD CONSTRAINT type_mqtt_deliveries_qos_check CHECK (qos IN (0, 1, 2))');
        $connection->execute('ALTER TABLE type_mqtt_deliveries DROP CONSTRAINT IF EXISTS type_mqtt_deliveries_state_check');
        $connection->execute('ALTER TABLE type_mqtt_deliveries ADD CONSTRAINT type_mqtt_deliveries_state_check '
            . "CHECK (state IN ('pending', 'acknowledged', 'queued', 'expired', 'oversized', 'closed', 'rejected', 'not_found'))");
        $connection->execute("ALTER TABLE type_mqtt_deliveries ADD COLUMN IF NOT EXISTS phase VARCHAR(16) NOT NULL DEFAULT 'none' "
            . "CHECK (phase IN ('none', 'wait_pubrec', 'wait_pubcomp', 'complete'))");
        $connection->execute("CREATE INDEX IF NOT EXISTS type_mqtt_messages_pending ON type_mqtt_messages (publisher_session) WHERE state = 'accepted'");
        $connection->execute("CREATE INDEX IF NOT EXISTS type_mqtt_deliveries_pending ON type_mqtt_deliveries (session_id, message_id) WHERE state = 'pending'");
        $connection->execute('CREATE INDEX IF NOT EXISTS type_mqtt_deliveries_message ON type_mqtt_deliveries (message_id, state)');
        $connection->execute('CREATE TABLE IF NOT EXISTS type_mqtt_retained (topic TEXT PRIMARY KEY, payload BYTEA NOT NULL, '
            . 'properties BYTEA NOT NULL, qos INTEGER NOT NULL CHECK (qos IN (0, 1, 2)), client_id TEXT NOT NULL, '
            . 'byte_size INTEGER NOT NULL CHECK (byte_size > 0), expires_at BIGINT NULL, expiry_interval BIGINT NULL, '
            . 'updated_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp())');
        $connection->execute('CREATE INDEX IF NOT EXISTS type_mqtt_retained_expiry ON type_mqtt_retained (expires_at) WHERE expires_at IS NOT NULL');
        $connection->execute('CREATE TABLE IF NOT EXISTS type_mqtt_sessions (id CHAR(32) PRIMARY KEY, client_id TEXT NOT NULL UNIQUE, '
            . 'owner_id CHAR(32) NULL, protocol INTEGER NOT NULL, expiry BIGINT NOT NULL, '
            . 'expires_at TIMESTAMPTZ NULL, principal TEXT NOT NULL, subscriptions JSONB NOT NULL DEFAULT \'{}\'::jsonb, '
            . 'updated_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp())');
        $connection->execute('CREATE TABLE IF NOT EXISTS type_mqtt_session_audit (operation_id CHAR(32) PRIMARY KEY, session_id CHAR(32) NOT NULL, '
            . 'client_id TEXT NOT NULL, action TEXT NOT NULL, actor TEXT NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp())');
        $connection->execute('ALTER TABLE type_mqtt_messages ADD COLUMN IF NOT EXISTS expires_at TIMESTAMPTZ NULL');
        $connection->execute('ALTER TABLE type_mqtt_deliveries ADD COLUMN IF NOT EXISTS started BOOLEAN NOT NULL DEFAULT true');
        $connection->execute('ALTER TABLE type_mqtt_deliveries ADD COLUMN IF NOT EXISTS retain BOOLEAN NOT NULL DEFAULT false');
        $connection->execute("ALTER TABLE type_mqtt_deliveries ADD COLUMN IF NOT EXISTS subscription_identifiers JSONB NOT NULL DEFAULT '[]'::jsonb");
        $connection->execute('CREATE INDEX IF NOT EXISTS type_mqtt_retained_cursor ON type_mqtt_retained (topic COLLATE "C")');
        $connection->execute("ALTER TABLE type_mqtt_sessions ADD COLUMN IF NOT EXISTS node_id VARCHAR(64) NOT NULL DEFAULT 'default'");
        $connection->execute("ALTER TABLE type_mqtt_sessions ADD COLUMN IF NOT EXISTS node_run_id VARCHAR(32) NOT NULL DEFAULT '', "
            . 'ADD COLUMN IF NOT EXISTS generation BIGINT NOT NULL DEFAULT 1');
        $connection->execute('CREATE TABLE IF NOT EXISTS type_mqtt_nodes (node_id VARCHAR(64) PRIMARY KEY, run_id CHAR(32) NOT NULL, '
            . "generation BIGINT NOT NULL, state VARCHAR(16) NOT NULL CHECK (state IN ('active', 'fenced')), "
            . "actor TEXT NOT NULL DEFAULT '', proof_ref TEXT NOT NULL DEFAULT '', updated_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp())");
        $connection->execute('CREATE TABLE IF NOT EXISTS type_mqtt_node_audit (operation_id CHAR(32) PRIMARY KEY, node_id VARCHAR(64) NOT NULL, '
            . 'run_id CHAR(32) NOT NULL, generation BIGINT NOT NULL, actor TEXT NOT NULL, proof_ref TEXT NOT NULL, '
            . 'created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp())');
        $connection->execute("ALTER TABLE type_mqtt_node_audit ADD COLUMN IF NOT EXISTS action VARCHAR(24) NOT NULL DEFAULT 'legacy', "
            . "ADD COLUMN IF NOT EXISTS observation_run VARCHAR(32) NOT NULL DEFAULT ''");
        // 隔离意图不能随会话删除：Clean Start和管理员撤权也必须等待旧网络关闭的独立事实。
        $connection->execute('CREATE TABLE IF NOT EXISTS type_mqtt_fences (owner_id CHAR(32) PRIMARY KEY, client_id TEXT NOT NULL, principal TEXT NOT NULL, '
            . 'node_id VARCHAR(64) NOT NULL, node_run_id CHAR(32) NOT NULL, generation BIGINT NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp())');
        $connection->execute('CREATE INDEX IF NOT EXISTS type_mqtt_fences_node ON type_mqtt_fences(node_id, node_run_id, owner_id)');
        $connection->execute('CREATE INDEX IF NOT EXISTS type_mqtt_fences_client ON type_mqtt_fences(client_id)');
        $connection->execute("ALTER TABLE type_mqtt_sessions ADD COLUMN IF NOT EXISTS capacity_class VARCHAR(16) NOT NULL DEFAULT 'device' "
            . "CHECK (capacity_class IN ('device', 'application'))");
        // 遗嘱独立于会话行：Clean Start、期限结束及接管不能级联删除已到期的发布事实。
        $connection->execute('CREATE TABLE IF NOT EXISTS type_mqtt_wills (id CHAR(32) PRIMARY KEY, session_id CHAR(32) NOT NULL, '
            . 'node_id VARCHAR(64) NOT NULL, client_id TEXT NOT NULL, principal TEXT NOT NULL, protocol INTEGER NOT NULL, '
            . 'message JSONB NOT NULL, delay BIGINT NOT NULL, byte_size INTEGER NOT NULL, due_at TIMESTAMPTZ NULL, '
            . "retry_at TIMESTAMPTZ NULL, attempts INTEGER NOT NULL DEFAULT 0, last_reason INTEGER NULL, cause TEXT NOT NULL DEFAULT 'connected')");
        $connection->execute('CREATE INDEX IF NOT EXISTS type_mqtt_wills_due ON type_mqtt_wills (node_id, due_at) WHERE due_at IS NOT NULL');
        $connection->execute('CREATE INDEX IF NOT EXISTS type_mqtt_wills_session ON type_mqtt_wills (session_id)');
        $connection->execute("ALTER TABLE type_mqtt_wills ADD COLUMN IF NOT EXISTS node_run_id VARCHAR(32) NOT NULL DEFAULT ''");
        // 旧principal仍是消费者原来的用户名；仅真实重认证能建立稳定身份，不在迁移中猜测映射。
        $connection->execute('ALTER TABLE type_mqtt_sessions ADD COLUMN IF NOT EXISTS access_identity JSONB NULL');
        $connection->execute('ALTER TABLE type_mqtt_sessions ADD COLUMN IF NOT EXISTS resource_scope VARCHAR(128) NULL');
        $connection->execute('CREATE INDEX IF NOT EXISTS type_mqtt_sessions_resource ON type_mqtt_sessions(resource_scope, id)');
        $connection->execute('CREATE TABLE IF NOT EXISTS type_mqtt_subscription_resources (id CHAR(64) PRIMARY KEY, '
            . 'session_id CHAR(32) NOT NULL REFERENCES type_mqtt_sessions(id) ON DELETE CASCADE, '
            . 'resource_scope VARCHAR(128) NULL, filter TEXT NOT NULL, actual_filter TEXT NOT NULL, options INTEGER NOT NULL, identifier INTEGER NOT NULL)');
        $connection->execute('CREATE INDEX IF NOT EXISTS type_mqtt_subscription_scope ON type_mqtt_subscription_resources(resource_scope, id)');
        $connection->execute('CREATE INDEX IF NOT EXISTS type_mqtt_subscription_session ON type_mqtt_subscription_resources(session_id, id)');
        // 归属不从旧用户名推导；投影仅复制协议已保存的过滤器，真正绑定由重认证后的保存完成。
        $connection->execute('INSERT INTO type_mqtt_subscription_resources(id, session_id, resource_scope, filter, actual_filter, options, identifier) '
            . "SELECT encode(sha256(convert_to(s.id::text || ':' || substr(e.key, 3), 'UTF8')), 'hex'), s.id, s.resource_scope, substr(e.key, 3), "
            . "CASE WHEN starts_with(substr(e.key, 3), '\$share/') THEN substr(e.key, 10 + strpos(substr(e.key, 10), '/')) ELSE substr(e.key, 3) END, "
            . "CASE WHEN jsonb_typeof(e.value) = 'number' THEN e.value::text::integer ELSE (e.value->>'options')::integer END, "
            . "COALESCE((e.value->>'identifier')::integer, 0) FROM type_mqtt_sessions s CROSS JOIN LATERAL jsonb_each("
            . "CASE WHEN jsonb_typeof(s.subscriptions) = 'object' THEN s.subscriptions ELSE '{}'::jsonb END) e "
            . 'ON CONFLICT (id) DO UPDATE SET resource_scope = EXCLUDED.resource_scope, options = EXCLUDED.options, identifier = EXCLUDED.identifier');
        // 只索引有界前缀和固定摘要，避免标准长度 Topic 产生超长 B-tree 键。
        $connection->execute('CREATE INDEX IF NOT EXISTS type_mqtt_subscription_topic_resource ON type_mqtt_subscription_resources((left(actual_filter, 256) COLLATE "C"), id)');
        $connection->execute("ALTER TABLE type_mqtt_retained ADD COLUMN IF NOT EXISTS resource_id CHAR(32) NOT NULL DEFAULT replace(gen_random_uuid()::text, '-', '')");
        $connection->execute('CREATE UNIQUE INDEX IF NOT EXISTS type_mqtt_retained_resource ON type_mqtt_retained(resource_id)');
        $connection->execute('CREATE INDEX IF NOT EXISTS type_mqtt_retained_namespace ON type_mqtt_retained((left(topic, 256) COLLATE "C"), resource_id)');
        $connection->execute('CREATE INDEX IF NOT EXISTS type_mqtt_message_namespace ON type_mqtt_messages((left(topic, 256) COLLATE "C"), id) WHERE state = \'accepted\'');
        $connection->execute("CREATE INDEX IF NOT EXISTS type_mqtt_original_resource ON type_mqtt_messages(('m:' || id::text)) WHERE state = 'accepted'");
        $connection->execute("CREATE INDEX IF NOT EXISTS type_mqtt_delivery_resource ON type_mqtt_deliveries(('d:' || id::text)) WHERE state = 'pending'");
        $connection->execute('ALTER TABLE type_mqtt_fences ADD COLUMN IF NOT EXISTS access_identity JSONB NULL');
        $connection->execute('ALTER TABLE type_mqtt_wills ADD COLUMN IF NOT EXISTS access_identity JSONB NULL');
        $connection->execute('CREATE TABLE IF NOT EXISTS type_mqtt_will_audit (id CHAR(32) PRIMARY KEY, session_id CHAR(32) NOT NULL, '
            . 'client_id TEXT NOT NULL, outcome TEXT NOT NULL, cause TEXT NOT NULL, reason INTEGER NOT NULL, '
            . 'created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp())');
        $connection->execute('CREATE TABLE IF NOT EXISTS type_mqtt_shared_groups (id CHAR(32) PRIMARY KEY, filter TEXT NOT NULL, filter_hash CHAR(64) NOT NULL UNIQUE)');
        $connection->execute('CREATE TABLE IF NOT EXISTS type_mqtt_shared_members (group_id CHAR(32) NOT NULL REFERENCES type_mqtt_shared_groups(id), '
            . 'session_id CHAR(32) NOT NULL REFERENCES type_mqtt_sessions(id), PRIMARY KEY(group_id, session_id))');
        $connection->execute('CREATE INDEX IF NOT EXISTS type_mqtt_shared_member_session ON type_mqtt_shared_members(session_id)');
        $connection->execute("ALTER TABLE type_mqtt_deliveries ADD COLUMN IF NOT EXISTS group_id VARCHAR(32) NOT NULL DEFAULT '', "
            . "ADD COLUMN IF NOT EXISTS shared_filter TEXT NOT NULL DEFAULT ''");
        $connection->execute('ALTER TABLE type_mqtt_messages ADD COLUMN IF NOT EXISTS retain BOOLEAN NOT NULL DEFAULT false');
        $connection->execute('ALTER TABLE type_mqtt_deliveries DROP CONSTRAINT IF EXISTS type_mqtt_deliveries_message_id_session_id_key');
        $connection->execute("CREATE UNIQUE INDEX IF NOT EXISTS type_mqtt_delivery_ordinary ON type_mqtt_deliveries(message_id, session_id) WHERE group_id = ''");
        $connection->execute("CREATE UNIQUE INDEX IF NOT EXISTS type_mqtt_delivery_shared ON type_mqtt_deliveries(message_id, group_id) WHERE group_id <> ''");
        $connection->execute("CREATE INDEX IF NOT EXISTS type_mqtt_shared_pending ON type_mqtt_deliveries(group_id, id) WHERE state = 'pending'");
        $connection->execute('ALTER TABLE type_mqtt_retained ADD COLUMN IF NOT EXISTS generation BIGINT NOT NULL DEFAULT 0');
        $connection->execute('CREATE TABLE IF NOT EXISTS type_mqtt_retained_clock (singleton BOOLEAN PRIMARY KEY CHECK (singleton), generation BIGINT NOT NULL CHECK (generation >= 0))');
        $connection->execute('INSERT INTO type_mqtt_retained_clock(singleton, generation) VALUES (true, 0) ON CONFLICT DO NOTHING');
        $connection->execute('CREATE TABLE IF NOT EXISTS type_mqtt_retained_history (topic TEXT NOT NULL, generation BIGINT NOT NULL, next_generation BIGINT NOT NULL, '
            . 'payload BYTEA NOT NULL, properties BYTEA NOT NULL, qos INTEGER NOT NULL CHECK (qos IN (0, 1, 2)), client_id TEXT NOT NULL, '
            . 'byte_size INTEGER NOT NULL, expires_at BIGINT NULL, expiry_interval BIGINT NULL, PRIMARY KEY(topic, generation))');
        $connection->execute('CREATE INDEX IF NOT EXISTS type_mqtt_retained_history_cursor ON type_mqtt_retained_history(topic COLLATE "C", generation)');
        $connection->execute('CREATE TABLE IF NOT EXISTS type_mqtt_retained_snapshots (id CHAR(32) PRIMARY KEY, '
            . 'session_id CHAR(32) NOT NULL REFERENCES type_mqtt_sessions(id) ON DELETE CASCADE, topic TEXT NOT NULL, '
            . 'options INTEGER NOT NULL, identifier INTEGER NOT NULL, generation BIGINT NOT NULL, cursor TEXT NOT NULL DEFAULT \'\', '
            . 'created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp())');
        $connection->execute('CREATE INDEX IF NOT EXISTS type_mqtt_retained_snapshot_session ON type_mqtt_retained_snapshots(session_id, created_at, id)');
        $connection->execute('CREATE INDEX IF NOT EXISTS type_mqtt_retained_snapshot_generation ON type_mqtt_retained_snapshots(generation)');
        $connection->execute('CREATE TABLE IF NOT EXISTS type_mqtt_retained_audit (operation_id CHAR(32) PRIMARY KEY, resource_id CHAR(32) NOT NULL, '
            . 'topic TEXT NOT NULL, generation BIGINT NOT NULL, action TEXT NOT NULL, actor TEXT NOT NULL, '
            . 'created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp())');
        $connection->execute("ALTER TABLE type_mqtt_deliveries ADD COLUMN IF NOT EXISTS volatile_owner VARCHAR(32) NOT NULL DEFAULT ''");
        $connection->execute('ALTER TABLE type_mqtt_sessions ADD COLUMN IF NOT EXISTS connected_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()');
        $connection->execute('ALTER TABLE type_mqtt_shared_members ADD COLUMN IF NOT EXISTS joined_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()');
        $connection->execute('CREATE INDEX IF NOT EXISTS type_mqtt_node_delivery_owner ON type_mqtt_sessions(node_id, node_run_id, owner_id) WHERE owner_id IS NOT NULL');
        $connection->execute("CREATE INDEX IF NOT EXISTS type_mqtt_delivery_ready ON type_mqtt_deliveries(session_id) WHERE state = 'pending' AND NOT started");
        $connection->execute('CREATE TABLE IF NOT EXISTS type_mqtt_quotas (id SMALLINT PRIMARY KEY CHECK (id = 1), version BIGINT NOT NULL CHECK (version >= 1), '
            . 'snapshot_json TEXT NOT NULL, updated_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp())');
    }

    /** 有界只读资源查询；授权由宿主根据本次角色、租户及支持授权生成，不接受客户端自授范围。 */
    private function resources(Connection $connection, array $request): array
    {
        $resource = $request['resource'];
        $detail = $request['action'] === 'resource_detail';
        $authorization = $request['authorization'];
        $filters = $request['filters'] ?? [];
        ksort($filters);
        $context = hash('sha256', json_encode([$resource, $authorization['all_metadata'], $authorization['resource_scope'] ?? null,
            $authorization['topic_namespace'] ?? null, $filters], JSON_THROW_ON_ERROR));
        $after = '';
        if (!$detail && ($request['cursor'] ?? null) !== null) {
            $cursor = $this->resourceCursor($request['cursor'], $resource);
            if ($cursor['context'] !== $context) {
                throw new ProtocolError(0x83);
            }
            $after = $cursor['after'];
        }
        $query = $this->resourceQuery($request, $detail);
        $parameters = $query['parameters'];
        $predicates = [];
        foreach ($filters as $field => $value) {
            if (in_array($field, ['client_id', 'topic'], true)) {
                continue;
            }
            $predicates[] = 'r.' . $field . ' = ?';
            $parameters[] = $value;
        }
        if ($detail) {
            $predicates[] = 'r.id = ?';
            $parameters[] = $request['id'];
        } elseif ($after !== '') {
            $predicates[] = 'r.id > ?';
            $parameters[] = $after;
        }
        $limit = $detail ? 1 : ($request['limit'] ?? 20);
        $rows = $connection->query('SELECT * FROM (' . $query['sql'] . ') r'
            . ($predicates === [] ? '' : ' WHERE ' . implode(' AND ', $predicates)) . ' ORDER BY r.id LIMIT ' . ($detail ? 1 : $limit + 1), $parameters);
        $observedAt = time();
        $source = $resource === 'connections' ? 'durable_owner' : 'durable_store';
        if ($detail) {
            $item = $rows === [] ? null : $this->resourceItem($rows[0]);
            if ($item !== null && $resource === 'sessions') {
                $item['counts'] = $this->resourceSessionCounts($connection, $item['id'], $authorization);
            }
            if ($item !== null && $resource === 'retained') {
                $item['counts'] = $this->resourceRetainedCounts($connection, $item);
            }
            $value = ['found' => $item !== null, 'item' => $item, 'observed_at' => $observedAt, 'source' => $source];
            if (strlen(json_encode($value, JSON_THROW_ON_ERROR)) > 1048576) {
                throw new ProtocolError(0x97);
            }
            return $value;
        }
        $items = [];
        $bytes = 1024;
        $more = count($rows) > $limit;
        foreach ($rows as $row) {
            if (count($items) >= $limit) {
                break;
            }
            $item = $this->resourceItem($row);
            $itemBytes = strlen(json_encode($item, JSON_THROW_ON_ERROR)) + 1;
            if ($bytes + $itemBytes > 1048576) {
                $more = true;
                break;
            }
            $items[] = $item;
            $bytes += $itemBytes;
        }
        $next = $more && $items !== [] ? base64_encode(json_encode(['context' => $context, 'after' => $items[count($items) - 1]['id']], JSON_THROW_ON_ERROR)) : null;
        return ['items' => $items, 'next_cursor' => $next, 'has_more' => $more, 'total' => null,
            'observed_at' => $observedAt, 'source' => $source];
    }

    /** 查询投影只包含元数据；先在 SQL 缩小归属和 Topic 范围，再执行 keyset。 */
    private function resourceQuery(array $request, bool $detail): array
    {
        $resource = $request['resource'];
        $authorization = $request['authorization'];
        $all = $authorization['all_metadata'];
        $parameters = [];
        $scope = $authorization['resource_scope'] ?? '';
        $namespace = $authorization['topic_namespace'] ?? '';
        $filters = $request['filters'] ?? [];
        if (in_array($resource, ['sessions', 'connections'], true)) {
            $predicate = $all ? 'true' : 's.resource_scope = ?';
            if (!$all) {
                $parameters[] = $scope;
            }
            if ($resource === 'connections') {
                $predicate .= ' AND s.owner_id IS NOT NULL';
            }
            if (isset($filters['client_id'])) {
                $predicate .= ' AND s.client_id = ?';
                $parameters[] = $filters['client_id'];
            }
            $sql = 'SELECT ' . ($resource === 'connections' ? 's.owner_id' : 's.id') . ' AS id, s.id AS session_id, '
                . $this->resourceText('s.client_id', 'client_id', $detail) . ', s.owner_id, s.resource_scope, s.access_identity, s.protocol, '
                . 's.expiry, s.expires_at, s.node_id, s.node_run_id, s.generation AS session_generation, s.capacity_class, s.updated_at, '
                . "CASE WHEN s.expires_at <= clock_timestamp() THEN 'expired' WHEN s.owner_id IS NULL THEN 'offline' ELSE 'owner_claim' END AS state, "
                . 'false AS connection_confirmed, true AS durable FROM type_mqtt_sessions s WHERE ' . $predicate;
            return ['sql' => $sql, 'parameters' => $parameters];
        }
        if ($resource === 'subscriptions') {
            $predicate = 'true';
            if (!$all) {
                $topic = $this->resourceNamespace('r.actual_filter', $namespace);
                $predicate = 'r.resource_scope = ? AND ' . $topic['sql'];
                $parameters = [$scope, ...$topic['parameters']];
            }
            if (isset($filters['client_id'])) {
                $predicate .= ' AND s.client_id = ?';
                $parameters[] = $filters['client_id'];
            }
            $sql = 'SELECT r.id, r.session_id, r.resource_scope, ' . $this->resourceText('r.filter', 'filter', $detail)
                . ', ' . $this->resourceText('s.client_id', 'client_id', $detail) . ', s.node_id, (r.options & 3) AS qos, '
                . "r.options, r.identifier AS subscription_identifier, starts_with(r.filter, '\$share/') AS shared, true AS durable, 'subscribed' AS state "
                . 'FROM type_mqtt_subscription_resources r JOIN type_mqtt_sessions s ON s.id = r.session_id WHERE ' . $predicate;
            return ['sql' => $sql, 'parameters' => $parameters];
        }
        if ($resource === 'retained') {
            $topic = $all ? ['sql' => 'true', 'parameters' => []] : $this->resourceNamespace('m.topic', $namespace);
            if (isset($filters['topic'])) {
                $topic['sql'] .= ' AND m.topic = ?';
                $topic['parameters'][] = $filters['topic'];
            }
            $sql = 'SELECT m.resource_id AS id, ' . $this->resourceText('m.topic', 'topic', $detail) . ', '
                . ($all ? $this->resourceText('m.client_id', 'client_id', $detail) : 'NULL::text AS client_id, NULL::integer AS client_id_bytes')
                . ', m.qos, m.byte_size, m.expires_at, m.updated_at, m.generation, '
                . "CASE WHEN m.expires_at <= floor(extract(epoch FROM clock_timestamp())) THEN 'expired' ELSE 'retained' END AS state "
                . 'FROM type_mqtt_retained m WHERE ' . $topic['sql'];
            return ['sql' => $sql, 'parameters' => $topic['parameters']];
        }
        $topic = $all ? ['sql' => 'true', 'parameters' => []] : $this->resourceNamespace('m.topic', $namespace);
        if (isset($filters['topic'])) {
            $topic['sql'] .= ' AND m.topic = ?';
            $topic['parameters'][] = $filters['topic'];
        }
        $sourceScope = $all ? 'true' : 's.resource_scope = ?';
        $sourceParameters = $all ? [] : [$scope];
        $sourceFields = 'CASE WHEN ' . $sourceScope . ' THEN s.id ELSE NULL END AS session_id, '
            . 'CASE WHEN ' . $sourceScope . ' THEN ' . ($detail ? 's.client_id' : 'left(s.client_id, 128)') . ' ELSE NULL END AS client_id, '
            . 'CASE WHEN ' . $sourceScope . ' THEN octet_length(s.client_id) ELSE NULL END AS client_id_bytes';
        $sql = "SELECT 'm:' || m.id::text AS id, 'original' AS kind, m.id AS message_id, " . $sourceFields . ', '
            . $this->resourceText('m.topic', 'topic', $detail) . ', m.qos, m.byte_size, m.expires_at, m.created_at, m.state, '
            . 'm.inbound_state AS phase, false AS shared, false AS started FROM type_mqtt_messages m '
            . 'LEFT JOIN type_mqtt_sessions s ON s.id = m.publisher_session WHERE m.state = \'accepted\' AND ' . $topic['sql'];
        $parameters = [...$sourceParameters, ...$sourceParameters, ...$sourceParameters, ...$topic['parameters']];
        $sql .= " UNION ALL SELECT 'd:' || d.id::text AS id, 'delivery' AS kind, m.id AS message_id, " . $sourceFields . ', '
            . $this->resourceText('m.topic', 'topic', $detail) . ', d.qos, m.byte_size, m.expires_at, d.created_at, d.state, '
            . "d.phase, (d.group_id <> '') AS shared, d.started FROM type_mqtt_deliveries d "
            . 'JOIN type_mqtt_messages m ON m.id = d.message_id LEFT JOIN type_mqtt_sessions s ON s.id = d.session_id '
            . "WHERE d.state = 'pending' AND " . $topic['sql'];
        return ['sql' => $sql, 'parameters' => [...$parameters, ...$sourceParameters, ...$sourceParameters, ...$sourceParameters, ...$topic['parameters']]];
    }

    /** namespace 是字面路径本身和其 / 后代；索引前缀不代替完整边界校验。 */
    private function resourceNamespace(string $field, string $namespace): array
    {
        $prefix = 'left(' . $field . ', 256) COLLATE "C"';
        return ['sql' => '((' . $prefix . ' = ? OR (' . $prefix . ' >= ? AND ' . $prefix . ' < ?))'
            . ' AND (' . $field . ' = ? OR starts_with(' . $field . ', ?)))',
            'parameters' => [$namespace, $namespace . '/', $namespace . '0', $namespace, $namespace . '/']];
    }

    private function resourceText(string $field, string $alias, bool $detail): string
    {
        return ($detail ? $field : 'left(' . $field . ', 128)') . ' AS ' . $alias . ', octet_length(' . $field . ') AS ' . $alias . '_bytes';
    }

    /** 驱动的数值及布尔转换不泄漏原始 JSONB 或载荷字段。 */
    private function resourceItem(array $row): array
    {
        foreach (['protocol', 'expiry', 'session_generation', 'qos', 'options', 'subscription_identifier', 'byte_size', 'generation', 'client_id_bytes', 'topic_bytes', 'filter_bytes'] as $field) {
            if (isset($row[$field])) {
                $row[$field] = (int) $row[$field];
            }
        }
        foreach (['connection_confirmed', 'durable', 'shared', 'started'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = self::truth($row[$field]);
            }
        }
        if (isset($row['access_identity'])) {
            $row['access_identity'] = AccessIdentity::fromData(json_decode($row['access_identity'], true, 4, JSON_THROW_ON_ERROR))->data();
        }
        return $row;
    }

    /** 会话详情的计数沿用同一 Topic 范围，不把跨租户共享组总量带入页面。 */
    private function resourceSessionCounts(Connection $connection, string $sessionId, array $authorization): array
    {
        $topic = $authorization['all_metadata'] ? ['sql' => 'true', 'parameters' => []]
            : $this->resourceNamespace('r.actual_filter', $authorization['topic_namespace']);
        $subscriptions = $connection->query(
            'SELECT COUNT(*) AS total FROM type_mqtt_subscription_resources r WHERE r.session_id = ? AND ' . $topic['sql'],
            [$sessionId, ...$topic['parameters']]
        );
        $pendingTopic = $authorization['all_metadata'] ? ['sql' => 'true', 'parameters' => []]
            : $this->resourceNamespace('m.topic', $authorization['topic_namespace']);
        $backlog = $connection->query(
            'SELECT COUNT(*) AS total, COALESCE(SUM(r.byte_size), 0) AS bytes FROM ('
            . "SELECT m.byte_size FROM type_mqtt_messages m WHERE m.publisher_session = ? AND m.state = 'accepted' AND " . $pendingTopic['sql']
            . ' UNION ALL SELECT m.byte_size FROM type_mqtt_deliveries d JOIN type_mqtt_messages m ON m.id = d.message_id '
            . "WHERE d.session_id = ? AND d.state = 'pending' AND " . $pendingTopic['sql'] . ') r',
            [$sessionId, ...$pendingTopic['parameters'], $sessionId, ...$pendingTopic['parameters']]
        );
        $deliveries = $connection->query(
            'SELECT d.qos, (d.group_id <> \'\') AS shared, d.started, COUNT(*) AS total FROM type_mqtt_deliveries d '
            . 'JOIN type_mqtt_messages m ON m.id = d.message_id WHERE d.session_id = ? AND d.state = \'pending\' AND '
            . $pendingTopic['sql'] . ' GROUP BY d.qos, (d.group_id <> \'\'), d.started',
            [$sessionId, ...$pendingTopic['parameters']]
        );
        $will = $connection->query('SELECT COUNT(*) AS total FROM type_mqtt_wills WHERE session_id = ?', [$sessionId]);
        $pendingQos1 = 0;
        $pendingQos2 = 0;
        $pendingShared = 0;
        $sharedQos2Inflight = 0;
        foreach ($deliveries as $row) {
            $total = (int) $row['total'];
            if ((int) $row['qos'] === 1) {
                $pendingQos1 += $total;
            } elseif ((int) $row['qos'] === 2) {
                $pendingQos2 += $total;
            }
            if (self::truth($row['shared'])) {
                $pendingShared += $total;
                if ((int) $row['qos'] === 2 && self::truth($row['started'])) {
                    $sharedQos2Inflight += $total;
                }
            }
        }
        return ['subscriptions' => (int) $subscriptions[0]['total'], 'pending_messages' => (int) $backlog[0]['total'], 'pending_bytes' => (int) $backlog[0]['bytes'],
            'pending_qos1' => $pendingQos1, 'pending_qos2' => $pendingQos2, 'pending_shared' => $pendingShared,
            'shared_qos2_inflight' => $sharedQos2Inflight, 'will_pending' => (int) $will[0]['total']];
    }

    /** 保留详情只统计该 Topic 的在途交付与仍引用本原件的快照，不含载荷。 */
    private function resourceRetainedCounts(Connection $connection, array $item): array
    {
        $topic = (string) ($item['topic'] ?? '');
        $generation = (int) ($item['generation'] ?? 0);
        $pending = $connection->query(
            'SELECT COUNT(*) AS total FROM type_mqtt_deliveries d JOIN type_mqtt_messages m ON m.id = d.message_id '
            . "WHERE m.topic = ? AND d.state = 'pending'",
            [$topic]
        );
        $snapshots = $connection->query(
            'SELECT COUNT(*) AS total FROM type_mqtt_retained_snapshots WHERE topic = ? AND generation >= ?',
            [$topic, $generation]
        );
        return [
            'pending_deliveries' => (int) $pending[0]['total'],
            'snapshot_references' => (int) $snapshots[0]['total'],
            'byte_size' => (int) ($item['byte_size'] ?? 0),
        ];
    }

    private function validResourceScope(string $scope): bool
    {
        return $scope !== '' && strlen($scope) <= 128 && preg_match('//u', $scope) === 1 && preg_match('/[\x00-\x1f\x7f]/', $scope) !== 1;
    }

    /** 资源标识不能跨类型复用；消息原件和交付副本保留明确种类前缀。 */
    private function validResourceId(string $resource, string $id): bool
    {
        return match ($resource) {
            'sessions', 'connections', 'retained' => self::identifier($id, 32),
            'subscriptions' => self::identifier($id, 64),
            'backlog' => preg_match('/^(?:m:[a-f0-9]{32}|d:[a-f0-9]{64})$/D', $id) === 1,
            default => false,
        };
    }

    private function resourceCursor(string $encoded, string $resource): array
    {
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || base64_encode($decoded) !== $encoded) {
            throw new \InvalidArgumentException('资源游标无效');
        }
        $cursor = json_decode($decoded, true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($cursor) || count($cursor) !== 2 || !is_string($cursor['context'] ?? null) || !self::identifier($cursor['context'], 64)
            || !is_string($cursor['after'] ?? null) || !$this->validResourceId($resource, $cursor['after'])) {
            throw new \InvalidArgumentException('资源游标无效');
        }
        return $cursor;
    }

    private function validateResource(array $request): void
    {
        $resource = $request['resource'] ?? '';
        $fields = match ($resource) {
            'sessions', 'connections' => ['client_id', 'session_id', 'node_id', 'state'],
            'subscriptions' => ['client_id', 'session_id', 'node_id', 'state', 'qos'],
            'retained' => ['topic', 'state', 'qos'],
            'backlog' => ['topic', 'session_id', 'state', 'qos', 'kind'],
            default => [],
        };
        $authorization = $request['authorization'] ?? null;
        if ($fields === [] || !is_array($authorization) || !is_bool($authorization['all_metadata'] ?? null)) {
            throw new \InvalidArgumentException('资源类型或授权范围无效');
        }
        if (!$authorization['all_metadata']) {
            if (!is_string($authorization['resource_scope'] ?? null) || !$this->validResourceScope($authorization['resource_scope'])
                || !is_string($authorization['topic_namespace'] ?? null) || !$this->validResourceScope($authorization['topic_namespace'])
                || strpbrk($authorization['topic_namespace'], '+#') !== false) {
                throw new \InvalidArgumentException('资源授权必须包含准确归属及字面 Topic 命名空间');
            }
        }
        if (!is_array($request['filters'] ?? []) || count($request['filters'] ?? []) > count($fields)) {
            throw new \InvalidArgumentException('资源过滤条件无效');
        }
        foreach ($request['filters'] ?? [] as $field => $value) {
            if (!in_array($field, $fields, true) || ($field === 'qos' ? !in_array($value, [0, 1, 2], true)
                : (!is_string($value) || $value === '' || strlen($value) > 65535 || preg_match('//u', $value) !== 1 || str_contains($value, "\0")))) {
                throw new \InvalidArgumentException('资源过滤条件无效');
            }
        }
        if ($request['action'] === 'resource_detail') {
            if (!is_string($request['id'] ?? null) || !$this->validResourceId($resource, $request['id'])) {
                throw new \InvalidArgumentException('资源详情标识无效');
            }
            return;
        }
        if (!is_int($request['limit'] ?? 20) || ($request['limit'] ?? 20) < 1 || ($request['limit'] ?? 20) > 100) {
            throw new \InvalidArgumentException('资源页面额度无效');
        }
        if (($request['cursor'] ?? null) !== null) {
            if (!is_string($request['cursor']) || strlen($request['cursor']) > 2048) {
                throw new \InvalidArgumentException('资源游标额度无效');
            }
            $this->resourceCursor($request['cursor'], $resource);
        }
    }

    private function accept(Connection $connection, array $request, bool $inbound = true): array
    {
        $this->expireSessions($connection, $request['operation_id']);
        $message = $request['message'];
        $deliveries = $request['deliveries'];
        $queuedRecipients = [];
        $clustered = ($request['node_run_id'] ?? '') !== '';
        if ($clustered) {
            $this->activeNode($connection, $request);
        }
        // 发布者可仍属于本节点，但接收者已转移；旧窗口预留不能在新会话快照之后写入。
        // 仅核对Broker显式预留的在线目的；下方存储自身派生的离线packet_id=0仍由当前会话恢复。
        foreach ($deliveries as $activeDelivery) {
            if (isset($activeDelivery['owner_id'])) {
                $this->owned($connection, $activeDelivery['session_id'], $activeDelivery['owner_id']);
            } elseif ($connection->query("SELECT id FROM type_mqtt_sessions WHERE id = ? AND node_run_id <> '' AND owner_id IS NOT NULL", [$activeDelivery['session_id']]) !== []) {
                throw new ProtocolError(0x8e);
            }
        }
        $normalized = new Message($message['topic'], (string) base64_decode($message['payload'], true), (string) base64_decode($message['properties'], true), $message['qos']);
        // 新 Broker 显式携带原始截止；旧 accept 接口的相对期限只在首次接管转换一次。
        $interval = $message['expiry'] ?? ($normalized->attributes[0x02] ?? null);
        $expiresAt = array_key_exists('expires_at', $message) ? $message['expires_at'] : ($interval === null ? null : time() + $interval);
        // 单节点在线窗口可由Broker预留；集群目的统一在接管事务选定，接收节点发送前再授权。
        if (($inbound || $request['action'] === 'will_accept') && ($request['route_sessions'] ?? false) && ($expiresAt === null || $expiresAt > time())) {
            $queuedSessions = $request['queued_sessions'] ?? [];
            $activeQueue = $clustered ? " OR (owner_id IS NOT NULL AND node_run_id <> '')" : '';
            $activeQueue .= $queuedSessions === [] ? '' : ' OR id IN (' . implode(',', array_fill(0, count($queuedSessions), '?')) . ')';
            $excluded = $request['excluded_sessions'] ?? [];
            $exclusion = $excluded === [] ? '' : ' AND id NOT IN (' . implode(',', array_fill(0, count($excluded), '?')) . ')';
            $included = [];
            foreach ($deliveries as $target) {
                $included[$target['session_id']] = true;
            }
            $cursor = '';
            do {
                // 元数据分批扫描，避免把全部持久订阅一次装入内存；不读取任何消息载荷。
                $destinations = $connection->query('SELECT id, client_id, protocol, subscriptions, owner_id, (owner_id IS NOT NULL AND EXISTS '
                    . "(SELECT 1 FROM type_mqtt_nodes n WHERE n.node_id = type_mqtt_sessions.node_id AND n.run_id = node_run_id AND n.state = 'active')) AS online "
                    . 'FROM type_mqtt_sessions WHERE (expiry <> 0' . $activeQueue . ') '
                    . 'AND (expires_at IS NULL OR expires_at > clock_timestamp())' . $exclusion . ' AND id > ? ORDER BY id LIMIT 32', [...$queuedSessions, ...$excluded, $cursor]);
                foreach ($destinations as $destination) {
                    $cursor = $destination['id'];
                    $selection = TopicFilter::select(json_decode($destination['subscriptions'], true, 16, JSON_THROW_ON_ERROR), $message['topic'], $destination['client_id'] === $message['client_id']);
                    $qos = min($message['qos'], $selection['qos']);
                    if (isset($included[$destination['id']]) || $qos < 0 || ($qos === 0 && (!$clustered || !self::truth($destination['online'])))) {
                        continue;
                    }
                    if (count($deliveries) >= 512) {
                        throw new ProtocolError(0x97);
                    }
                    $deliveries[] = ['id' => hash('sha256', $message['id'] . $destination['id']), 'session_id' => $destination['id'],
                        'client_id' => $destination['client_id'], 'packet_id' => 0, 'qos' => $qos,
                        'volatile_owner' => $qos === 0 ? $destination['owner_id'] : '',
                        'retain' => ($message['retain'] ?? false) && (int) $destination['protocol'] === 5 && $selection['retain'],
                        'subscription_identifiers' => $selection['identifiers']];
                    if (in_array($destination['id'], $queuedSessions, true)) {
                        $queuedRecipients[] = $destination['id'];
                    }
                }
            } while (count($destinations) === 32);
        }
        if (($inbound || $request['action'] === 'will_accept') && ($request['route_sessions'] ?? false) && ($message['qos'] > 0 || $clustered) && ($expiresAt === null || $expiresAt > time())) {
            $cursor = '';
            do {
                $groups = $connection->query('SELECT g.id, g.filter FROM type_mqtt_shared_groups g WHERE g.id > ? AND EXISTS '
                    . '(SELECT 1 FROM type_mqtt_shared_members b JOIN type_mqtt_sessions s ON s.id = b.session_id WHERE b.group_id = g.id '
                    . 'AND s.protocol = 5 AND (s.expires_at IS NULL OR s.expires_at > clock_timestamp())'
                    . ($message['qos'] === 0 ? " AND s.owner_id IS NOT NULL AND EXISTS (SELECT 1 FROM type_mqtt_nodes n WHERE n.node_id = s.node_id AND n.run_id = s.node_run_id AND n.state = 'active')" : '')
                    . ') ORDER BY g.id LIMIT 32', [$cursor]);
                foreach ($groups as $group) {
                    $cursor = $group['id'];
                    if (TopicFilter::matches(TopicFilter::actual($group['filter']), $message['topic'])) {
                        if (count($deliveries) >= 512) {
                            throw new ProtocolError(0x97);
                        }
                        $deliveries[] = ['id' => hash('sha256', $message['id'] . $group['id']), 'session_id' => $group['id'],
                            'client_id' => '$shared', 'packet_id' => 0, 'qos' => $message['qos'], 'group_id' => $group['id'], 'shared_filter' => $group['filter']];
                    }
                }
            } while (count($groups) === 32);
        }
        $bytes = strlen($message['topic']) + strlen((string) base64_decode($message['payload'], true)) + strlen((string) base64_decode($message['properties'], true));
        $this->capacity($connection, $message['session_id'], $deliveries, $bytes);
        if ($message['retain'] ?? false) {
            $this->retain($connection, $message, $bytes);
        }
        $connection->execute(
            'INSERT INTO type_mqtt_messages (id, publisher_session, client_id, packet_id, topic, payload, properties, qos, byte_size, state, inbound_state) '
            . "VALUES (?, ?, ?, ?, ?, decode(?, 'base64'), decode(?, 'base64'), ?, ?, ?, ?)",
            [$message['id'], $message['session_id'], $message['client_id'], $message['packet_id'], $message['topic'], $message['payload'], $message['properties'],
                $message['qos'], $bytes, $deliveries === [] && (!$inbound || $message['qos'] !== 2) ? 'complete' : 'accepted', $inbound && $message['qos'] === 2 ? 'received' : 'none']
        );
        if ($expiresAt !== null) {
            $connection->execute('UPDATE type_mqtt_messages SET expires_at = to_timestamp(?) WHERE id = ?', [$expiresAt, $message['id']]);
        }
        $connection->execute('UPDATE type_mqtt_messages SET retain = ? WHERE id = ?', [($message['retain'] ?? false) ? 'true' : 'false', $message['id']]);
        foreach ($deliveries as $delivery) {
            $connection->execute(
                'INSERT INTO type_mqtt_deliveries (id, message_id, session_id, client_id, packet_id, qos, state, phase, started, retain, subscription_identifiers, group_id, shared_filter, volatile_owner) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS jsonb), ?, ?, ?)',
                [$delivery['id'], $message['id'], $delivery['session_id'], $delivery['client_id'], $delivery['packet_id'], $delivery['qos'], 'pending',
                    $delivery['qos'] === 2 ? 'wait_pubrec' : 'none', $delivery['packet_id'] > 0 ? 'true' : 'false', ($delivery['retain'] ?? false) ? 'true' : 'false',
                    json_encode($delivery['subscription_identifiers'] ?? [], JSON_THROW_ON_ERROR), $delivery['group_id'] ?? '', $delivery['shared_filter'] ?? '', $delivery['volatile_owner'] ?? '']
            );
        }
        if (isset($request['snapshot_id'])) {
            $this->advanceRetained($connection, $request);
        }
        return ['queued_sessions' => $queuedRecipients];
    }

    /**
     * 按精确原件标识与代次删除当前保留值；快照仍可读历史，不插入消息或交付。
     *
     * @return array{cleared:bool,waiting:bool}
     */
    private function clearRetained(Connection $connection, array $request): array
    {
        $audit = $connection->query(
            'SELECT resource_id FROM type_mqtt_retained_audit WHERE operation_id = ?',
            [$request['clear_operation_id']]
        );
        if ($audit !== []) {
            return ['cleared' => true, 'waiting' => false];
        }
        $rows = $connection->query(
            'SELECT resource_id, topic, generation FROM type_mqtt_retained WHERE resource_id = ? FOR UPDATE',
            [$request['resource_id']]
        );
        if ($rows === [] || (int) $rows[0]['generation'] !== $request['generation']) {
            return ['cleared' => false, 'waiting' => false];
        }
        $this->removeRetainedOriginal($connection, $rows[0]['topic']);
        $connection->execute(
            'INSERT INTO type_mqtt_retained_audit(operation_id, resource_id, topic, generation, action, actor) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT DO NOTHING',
            [$request['clear_operation_id'], $rows[0]['resource_id'], $rows[0]['topic'], $request['generation'], 'administrator_cleared', $request['actor']]
        );
        return ['cleared' => true, 'waiting' => false];
    }

    /** 同一同步事务内替换或清除；容量拒绝回滚后保留原值，不删除其他未过期消息腾空间。 */
    private function retain(Connection $connection, array $message, int $bytes): void
    {
        $this->pruneRetained($connection);
        $connection->execute('DELETE FROM type_mqtt_retained WHERE topic IN (SELECT topic FROM type_mqtt_retained '
            . 'WHERE expires_at <= floor(extract(epoch FROM clock_timestamp())) ORDER BY expires_at LIMIT 256)');
        $delete = $message['payload'] === '' || ($message['expires_at'] !== null && $message['expires_at'] <= time());
        if ($delete) {
            $this->removeRetainedOriginal($connection, $message['topic']);
            return;
        }
        $generation = (int) $connection->query('UPDATE type_mqtt_retained_clock SET generation = generation + 1 WHERE singleton RETURNING generation')[0]['generation'];
        $old = $connection->query('SELECT generation, byte_size FROM type_mqtt_retained WHERE topic = ?', [$message['topic']]);
        $preserve = $old !== [] && $connection->query(
            'SELECT id FROM type_mqtt_retained_snapshots WHERE generation >= ? AND generation < ? LIMIT 1',
            [$old[0]['generation'], $generation]
        ) !== [];
        $usage = $connection->query('SELECT COUNT(*) AS messages, COALESCE(SUM(byte_size), 0) AS bytes FROM '
            . '(SELECT byte_size FROM type_mqtt_retained WHERE topic <> ? UNION ALL SELECT byte_size FROM type_mqtt_retained_history) retained', [$message['topic']])[0];
        if ((int) $usage['messages'] + ($preserve ? 1 : 0) + 1 > $this->maximumRetainedMessages
            || (int) $usage['bytes'] + ($preserve ? (int) $old[0]['byte_size'] : 0) + $bytes > $this->maximumRetainedBytes) {
            throw new ProtocolError(0x97);
        }
        if ($preserve) {
            $connection->execute(
                'INSERT INTO type_mqtt_retained_history(topic, generation, next_generation, payload, properties, qos, client_id, byte_size, expires_at, expiry_interval) '
                . 'SELECT topic, generation, ?, payload, properties, qos, client_id, byte_size, expires_at, expiry_interval FROM type_mqtt_retained WHERE topic = ?',
                [$generation, $message['topic']]
            );
        }
        $normalized = new Message($message['topic'], (string) base64_decode($message['payload'], true), (string) base64_decode($message['properties'], true));
        $connection->execute(
            'INSERT INTO type_mqtt_retained (topic, payload, properties, qos, client_id, byte_size, expires_at, expiry_interval, generation) '
            . "VALUES (?, decode(?, 'base64'), decode(?, 'base64'), ?, ?, ?, ?, ?, ?) ON CONFLICT (topic) DO UPDATE SET "
            . 'payload = EXCLUDED.payload, properties = EXCLUDED.properties, qos = EXCLUDED.qos, client_id = EXCLUDED.client_id, '
            . 'byte_size = EXCLUDED.byte_size, expires_at = EXCLUDED.expires_at, expiry_interval = EXCLUDED.expiry_interval, generation = EXCLUDED.generation, updated_at = clock_timestamp()',
            [$message['topic'], $message['payload'], $message['properties'], $message['qos'], $message['client_id'], $bytes,
                $message['expires_at'], $normalized->attributes[0x02] ?? null, $generation]
        );
    }

    /** 归档仍被快照引用的当前原件后删除；不写入消息表，也不向在线订阅派生交付。 */
    private function removeRetainedOriginal(Connection $connection, string $topic): void
    {
        $this->pruneRetained($connection);
        $generation = (int) $connection->query('UPDATE type_mqtt_retained_clock SET generation = generation + 1 WHERE singleton RETURNING generation')[0]['generation'];
        $old = $connection->query('SELECT generation FROM type_mqtt_retained WHERE topic = ?', [$topic]);
        if ($old === []) {
            return;
        }
        $preserve = $connection->query(
            'SELECT id FROM type_mqtt_retained_snapshots WHERE generation >= ? AND generation < ? LIMIT 1',
            [$old[0]['generation'], $generation]
        ) !== [];
        if ($preserve) {
            $connection->execute(
                'INSERT INTO type_mqtt_retained_history(topic, generation, next_generation, payload, properties, qos, client_id, byte_size, expires_at, expiry_interval) '
                . 'SELECT topic, generation, ?, payload, properties, qos, client_id, byte_size, expires_at, expiry_interval FROM type_mqtt_retained WHERE topic = ?',
                [$generation, $topic]
            );
        }
        $connection->execute('DELETE FROM type_mqtt_retained WHERE topic = ?', [$topic]);
    }

    /** 只保留仍被订阅切点引用的旧版本；每次最多回收256条，不延长原事务及worker预算。 */
    private function pruneRetained(Connection $connection): void
    {
        $connection->execute('DELETE FROM type_mqtt_retained_history h USING (SELECT topic, generation FROM type_mqtt_retained_history v '
            . 'WHERE expires_at <= floor(extract(epoch FROM clock_timestamp())) OR NOT EXISTS '
            . '(SELECT 1 FROM type_mqtt_retained_snapshots s WHERE s.generation >= v.generation AND s.generation < v.next_generation) '
            . 'ORDER BY topic, generation LIMIT 256) obsolete WHERE h.topic = obsolete.topic AND h.generation = obsolete.generation');
    }

    /** 与订阅安装共用事务的切点；每会话至多100项，保存游标可在持久会话接管后继续。 */
    private function retainedSnapshots(Connection $connection, array $request): array
    {
        $rows = $connection->query('SELECT id, topic, options, identifier FROM type_mqtt_retained_snapshots WHERE session_id = ? ORDER BY created_at, id LIMIT 101', [$request['session_id']]);
        $count = count($rows);
        foreach ($rows as $snapshot) {
            if (($request['subscriptions']['t:' . $snapshot['topic']] ?? null) !== ['options' => (int) $snapshot['options'], 'identifier' => (int) $snapshot['identifier']]) {
                $connection->execute('DELETE FROM type_mqtt_retained_snapshots WHERE id = ?', [$snapshot['id']]);
                $count--;
            }
        }
        $requested = $request['retained'] ?? [];
        if ($count + count($requested) > 100) {
            throw new ProtocolError(0x97);
        }
        $generation = (int) $connection->query('SELECT generation FROM type_mqtt_retained_clock WHERE singleton')[0]['generation'];
        foreach ($requested as $snapshot) {
            $connection->execute(
                'INSERT INTO type_mqtt_retained_snapshots(id, session_id, topic, options, identifier, generation) VALUES (?, ?, ?, ?, ?, ?)',
                [$snapshot['snapshot_id'], $request['session_id'], $snapshot['topic'], $snapshot['subscription']['options'], $snapshot['subscription']['identifier'], $generation]
            );
        }
        $this->pruneRetained($connection);
        $snapshots = [];
        foreach ($connection->query('SELECT id, topic, options, identifier, cursor FROM type_mqtt_retained_snapshots WHERE session_id = ? ORDER BY created_at, id LIMIT 100', [$request['session_id']]) as $saved) {
            $snapshots[] = ['snapshot_id' => $saved['id'], 'topic' => $saved['topic'], 'cursor' => $saved['cursor'],
                'subscription' => ['options' => (int) $saved['options'], 'identifier' => (int) $saved['identifier']]];
        }
        return $snapshots;
    }

    /** 读不到消息时可直接前进；读到的原件只有持久接管或明确丢弃后才释放快照切点。 */
    private function advanceRetained(Connection $connection, array $request): void
    {
        if ($request['snapshot_done']) {
            $connection->execute('DELETE FROM type_mqtt_retained_snapshots WHERE id = ? AND session_id = ?', [$request['snapshot_id'], $request['session_id']]);
        } else {
            $connection->execute(
                'UPDATE type_mqtt_retained_snapshots SET cursor = ? WHERE id = ? AND session_id = ?',
                [$request['snapshot_cursor'], $request['snapshot_id'], $request['session_id']]
            );
        }
        $this->pruneRetained($connection);
    }

    /** 每次最多扫描32个 Topic 键、读取一条原件；无匹配也返回前进游标，不持有跨 worker 事务。 */
    private function readRetained(Connection $connection, array $request): array
    {
        $wildcard = strpbrk($request['topic'], '+#') !== false;
        $cursor = $request['cursor'] ?? '';
        $topic = $request['topic'];
        $source = 'type_mqtt_retained';
        $parameters = [];
        if (isset($request['snapshot_id'])) {
            $snapshots = $connection->query(
                'SELECT topic, options, cursor, generation FROM type_mqtt_retained_snapshots WHERE id = ? AND session_id = ?',
                [$request['snapshot_id'], $request['session_id']]
            );
            if ($snapshots === []) {
                return ['found' => false, 'cursor' => $cursor, 'done' => true];
            }
            $snapshot = $snapshots[0];
            if ($snapshot['topic'] !== $topic || $snapshot['cursor'] !== $cursor || (((int) $snapshot['options'] & 4) !== 0) !== $request['no_local']) {
                throw new ProtocolError(0x83);
            }
            $columns = 'topic, payload, properties, qos, client_id, expires_at, expiry_interval';
            $source = '(SELECT ' . $columns . ' FROM type_mqtt_retained WHERE generation <= ? UNION ALL SELECT ' . $columns
                . ' FROM type_mqtt_retained_history WHERE generation <= ? AND next_generation > ?) visible';
            $parameters = [$snapshot['generation'], $snapshot['generation'], $snapshot['generation']];
        }
        if ($wildcard) {
            $prefix = substr($topic, 0, strcspn($topic, '+#'));
            // a/# 也匹配 a，前缀索引范围不能把零个后续层排除。
            if (str_ends_with($prefix, '/') && substr($topic, strlen($prefix)) === '#') {
                $prefix = substr($prefix, 0, -1);
            }
            $keys = $connection->query('SELECT topic FROM ' . $source . ' WHERE topic COLLATE "C" > ? AND topic COLLATE "C" >= ? '
                . 'AND starts_with(topic, ?) ORDER BY topic COLLATE "C" LIMIT 32', [...$parameters, $cursor, $prefix, $prefix]);
            $topic = '';
            foreach ($keys as $entry) {
                $cursor = $entry['topic'];
                if (TopicFilter::matches($request['topic'], $cursor)) {
                    $topic = $cursor;
                    break;
                }
            }
            if ($topic === '') {
                if (isset($request['snapshot_id'])) {
                    $this->advanceRetained($connection, [...$request, 'snapshot_cursor' => $cursor, 'snapshot_done' => count($keys) < 32]);
                }
                return ['found' => false, 'cursor' => $cursor, 'done' => count($keys) < 32];
            }
        }
        $progress = ['cursor' => $cursor, 'done' => !$wildcard];
        $connection->execute('DELETE FROM type_mqtt_retained WHERE topic = ? AND expires_at <= floor(extract(epoch FROM clock_timestamp()))', [$topic]);
        $rows = $connection->query("SELECT topic, replace(encode(payload, 'base64'), chr(10), '') AS payload, "
            . "replace(encode(properties, 'base64'), chr(10), '') AS properties, qos, client_id, expiry_interval, expires_at "
            . 'FROM ' . $source . ' WHERE topic = ? AND (expires_at IS NULL OR expires_at > floor(extract(epoch FROM clock_timestamp())))', [...$parameters, $topic]);
        if ($rows === [] || ($request['no_local'] && $rows[0]['client_id'] === $request['client_id'])) {
            if (isset($request['snapshot_id'])) {
                $this->advanceRetained($connection, [...$request, 'snapshot_cursor' => $cursor, 'snapshot_done' => !$wildcard]);
            }
            return ['found' => false, ...$progress];
        }
        $retained = $rows[0];
        $message = ['client_id' => $retained['client_id'], 'topic' => $retained['topic'], 'payload' => $retained['payload'],
            'properties' => $retained['properties'], 'qos' => (int) $retained['qos']];
        return ['found' => true, 'message' => $message, ...$progress,
            'expires_at' => $retained['expires_at'] === null ? null : (int) $retained['expires_at'],
            'expiry_interval' => $retained['expiry_interval'] === null ? null : (int) $retained['expiry_interval']];
    }

    private function capacity(Connection $connection, string $publisherSession, array $deliveries, int $bytes): void
    {
        $pending = $this->pendingQuery();
        $global = $connection->query('SELECT COUNT(*) AS messages, COALESCE(SUM(byte_size), 0) AS bytes FROM (' . $pending . ') AS pending')[0];
        $reservation = 1 + count($deliveries);
        if ((int) $global['messages'] + $reservation > $this->maximumPendingMessages
            || (int) $global['bytes'] + $bytes * $reservation > $this->maximumPendingBytes) {
            throw new ProtocolError(0x97);
        }
        $sessions = [$publisherSession => 1];
        foreach ($deliveries as $delivery) {
            if (($delivery['group_id'] ?? '') !== '') {
                $groupUsage = $connection->query('SELECT COUNT(*) AS messages, COALESCE(SUM(m.byte_size), 0) AS bytes '
                    . "FROM type_mqtt_deliveries d JOIN type_mqtt_messages m ON m.id = d.message_id WHERE d.group_id = ? AND d.state = 'pending'", [$delivery['group_id']])[0];
                if ((int) $groupUsage['messages'] + 1 > $this->maximumSharedMessages || (int) $groupUsage['bytes'] + $bytes > $this->maximumSharedBytes) {
                    throw new ProtocolError(0x97);
                }
                continue;
            }
            $sessionId = $delivery['session_id'];
            $sessions[$sessionId] = ($sessions[$sessionId] ?? 0) + 1;
        }
        $rows = $connection->query('SELECT session_id, COUNT(*) AS messages, COALESCE(SUM(byte_size), 0) AS bytes FROM (' . $pending . ') AS pending '
            . "WHERE group_id = '' AND session_id IN (" . implode(',', array_fill(0, count($sessions), '?')) . ') GROUP BY session_id', array_keys($sessions));
        $existing = [];
        foreach ($rows as $row) {
            $existing[$row['session_id']] = $row;
        }
        $classes = $connection->query('SELECT id, capacity_class FROM type_mqtt_sessions WHERE id IN ('
            . implode(',', array_fill(0, count($sessions), '?')) . ')', array_keys($sessions));
        foreach ($classes as $classified) {
            $existing[$classified['id']]['capacity_class'] = $classified['capacity_class'];
        }
        foreach ($sessions as $session => $count) {
            $application = ($existing[$session]['capacity_class'] ?? 'device') === 'application';
            $messageLimit = $application ? $this->maximumApplicationMessages : $this->maximumDeviceMessages;
            $byteLimit = $application ? $this->maximumApplicationBytes : $this->maximumDeviceBytes;
            if ((int) ($existing[$session]['messages'] ?? 0) + $count > $messageLimit || (int) ($existing[$session]['bytes'] ?? 0) + $bytes * $count > $byteLimit) {
                throw new ProtocolError(0x97);
            }
        }
    }

    /** 全局逻辑条数是原件与每份副本之和；共享副本只计所属组，不重复占用领取会话的设备额度。 */
    private function pendingQuery(): string
    {
        return "SELECT publisher_session AS session_id, '' AS group_id, byte_size FROM type_mqtt_messages WHERE state = 'accepted' UNION ALL "
            . "SELECT d.session_id, d.group_id, m.byte_size FROM type_mqtt_deliveries d JOIN type_mqtt_messages m ON m.id = d.message_id WHERE d.state = 'pending'";
    }

    /** 同一短事务内返回聚合用量；不加载载荷、逐会话明细或将已终结事实当作待交付。 */
    private function usage(Connection $connection): array
    {
        $sessions = $connection->query('SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE expiry <> 0) AS persistent, '
            . "COUNT(*) FILTER (WHERE capacity_class = 'device') AS devices, COUNT(*) FILTER (WHERE capacity_class = 'application') AS applications, "
            . "COALESCE(SUM((SELECT COUNT(*) FROM jsonb_object_keys(CASE WHEN jsonb_typeof(subscriptions) = 'object' THEN subscriptions ELSE '{}'::jsonb END))), 0) AS subscriptions FROM type_mqtt_sessions")[0];
        $pending = $connection->query('SELECT COUNT(*) AS messages, COALESCE(SUM(byte_size), 0) AS bytes FROM (' . $this->pendingQuery() . ') p')[0];
        $categories = $connection->query("SELECT CASE WHEN p.group_id <> '' THEN 'shared' ELSE COALESCE(s.capacity_class, 'device') END AS category, "
            . 'COUNT(*) AS messages, COALESCE(SUM(p.byte_size), 0) AS bytes FROM (' . $this->pendingQuery() . ') p '
            . "LEFT JOIN type_mqtt_sessions s ON s.id = p.session_id AND p.group_id = '' GROUP BY category");
        $values = ['sessions' => (int) $sessions['total'], 'persistentSessions' => (int) $sessions['persistent'],
            'deviceSessions' => (int) $sessions['devices'], 'applicationSessions' => (int) $sessions['applications'],
            'subscriptions' => (int) $sessions['subscriptions'], 'pendingMessages' => (int) $pending['messages'], 'pendingBytes' => (int) $pending['bytes'],
            'devicePendingMessages' => 0, 'devicePendingBytes' => 0, 'applicationPendingMessages' => 0, 'applicationPendingBytes' => 0,
            'sharedPendingMessages' => 0, 'sharedPendingBytes' => 0,
            'maximumSessions' => $this->maximumSessions, 'maximumPendingMessages' => $this->maximumPendingMessages, 'maximumPendingBytes' => $this->maximumPendingBytes,
            'maximumDeviceMessages' => $this->maximumDeviceMessages, 'maximumDeviceBytes' => $this->maximumDeviceBytes,
            'maximumApplicationMessages' => $this->maximumApplicationMessages, 'maximumApplicationBytes' => $this->maximumApplicationBytes,
            'maximumSharedMessages' => $this->maximumSharedMessages, 'maximumSharedBytes' => $this->maximumSharedBytes];
        foreach ($categories as $category) {
            $values[$category['category'] . 'PendingMessages'] = (int) $category['messages'];
            $values[$category['category'] . 'PendingBytes'] = (int) $category['bytes'];
        }
        foreach (['retained', 'wills'] as $table) {
            $rows = $connection->query('SELECT COUNT(*) AS messages, COALESCE(SUM(byte_size), 0) AS bytes FROM type_mqtt_' . $table)[0];
            $values[$table . 'Messages'] = (int) $rows['messages'];
            $values[$table . 'Bytes'] = (int) $rows['bytes'];
        }
        $history = $connection->query('SELECT COUNT(*) AS messages, COALESCE(SUM(byte_size), 0) AS bytes FROM type_mqtt_retained_history')[0];
        $values['retainedHistoryMessages'] = (int) $history['messages'];
        $values['retainedHistoryBytes'] = (int) $history['bytes'];
        $values['retainedSnapshots'] = (int) $connection->query('SELECT COUNT(*) AS snapshots FROM type_mqtt_retained_snapshots')[0]['snapshots'];
        return $values;
    }

    /** 运行额度单行覆盖构造器默认；缺失行保持启动值，不删除已确认积压。 */
    private function hydrateQuotas(Connection $connection): void
    {
        $rows = $connection->query('SELECT snapshot_json FROM type_mqtt_quotas WHERE id = 1');
        if ($rows === []) {
            return;
        }
        try {
            $limits = json_decode((string) $rows[0]['snapshot_json'], true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ProtocolError(0x83);
        }
        if (!is_array($limits)) {
            throw new ProtocolError(0x83);
        }
        $this->assignQuotas($limits);
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function applyQuotas(Connection $connection, array $request): array
    {
        $this->assignQuotas($request['limits']);
        $store = [];
        foreach (['maximumSessions', 'maximumPendingMessages', 'maximumPendingBytes', 'maximumDeviceMessages', 'maximumDeviceBytes',
            'maximumApplicationMessages', 'maximumApplicationBytes', 'maximumSharedMessages', 'maximumSharedBytes'] as $key) {
            $store[$key] = (int) $request['limits'][$key];
        }
        $encoded = json_encode($store, JSON_THROW_ON_ERROR);
        $existing = $connection->query('SELECT version FROM type_mqtt_quotas WHERE id = 1 FOR UPDATE');
        if ($existing === []) {
            $connection->execute('INSERT INTO type_mqtt_quotas (id, version, snapshot_json) VALUES (1, ?, ?)', [$request['version'], $encoded]);
        } elseif ((int) $existing[0]['version'] <= $request['version']) {
            $connection->execute('UPDATE type_mqtt_quotas SET version = ?, snapshot_json = ?, updated_at = clock_timestamp() WHERE id = 1', [$request['version'], $encoded]);
        }
        return ['version' => $request['version'], 'applied' => true];
    }

    /** @param array<string, mixed> $limits */
    private function assignQuotas(array $limits): void
    {
        $this->maximumSessions = min(20000, max(1, (int) ($limits['maximumSessions'] ?? $this->maximumSessions)));
        $this->maximumPendingMessages = min(2000000, max(1, (int) ($limits['maximumPendingMessages'] ?? $this->maximumPendingMessages)));
        $this->maximumPendingBytes = min(4294967296, max(1, (int) ($limits['maximumPendingBytes'] ?? $this->maximumPendingBytes)));
        $this->maximumDeviceMessages = min(10000, max(1, (int) ($limits['maximumDeviceMessages'] ?? $this->maximumDeviceMessages)));
        $this->maximumDeviceBytes = min(16777216, max(1, (int) ($limits['maximumDeviceBytes'] ?? $this->maximumDeviceBytes)));
        $this->maximumApplicationMessages = min(1000000, max(1, (int) ($limits['maximumApplicationMessages'] ?? $this->maximumApplicationMessages)));
        $this->maximumApplicationBytes = min(2147483648, max(1, (int) ($limits['maximumApplicationBytes'] ?? $this->maximumApplicationBytes)));
        $this->maximumSharedMessages = min(1000000, max(1, (int) ($limits['maximumSharedMessages'] ?? $this->maximumSharedMessages)));
        $this->maximumSharedBytes = min(2147483648, max(1, (int) ($limits['maximumSharedBytes'] ?? $this->maximumSharedBytes)));
    }

    private function complete(Connection $connection, array $request): void
    {
        $outcome = $request['outcome'] ?? 'acknowledged';
        $rows = $connection->query('SELECT message_id, session_id, qos, state, phase, ack_reason FROM type_mqtt_deliveries WHERE id = ? FOR UPDATE', [$request['delivery_id']]);
        if (count($rows) !== 1 || ($outcome === 'acknowledged' && (int) $rows[0]['qos'] === 0)
            || (isset($request['owner_id']) && $rows[0]['session_id'] !== $request['session_id'])
            || ($outcome === 'queued' && (int) $rows[0]['qos'] !== 0) || ($rows[0]['state'] !== 'pending'
                && ($rows[0]['state'] !== $outcome || (int) $rows[0]['ack_reason'] !== $request['reason']))) {
            throw new ProtocolError(0x83);
        }
        if ($rows[0]['state'] === 'pending') {
            $qos = (int) $rows[0]['qos'];
            $phase = $rows[0]['phase'];
            if (($outcome === 'rejected' && ($qos !== 2 || $phase !== 'wait_pubrec' || $request['reason'] < 0x80))
                || ($outcome === 'not_found' && ($qos !== 2 || $phase !== 'wait_pubcomp' || $request['reason'] !== 0x92))
                || ($outcome === 'acknowledged' && $qos === 2 && ($phase !== 'wait_pubcomp' || $request['reason'] !== 0))
                || (in_array($outcome, ['expired', 'oversized'], true) && $phase === 'wait_pubcomp')) {
                throw new ProtocolError(0x83);
            }
            if ($qos === 1 && $request['reason'] === 0x92) {
                throw new ProtocolError(0x83);
            }
        }
        $connection->execute("UPDATE type_mqtt_deliveries SET state = ?, ack_reason = ?, phase = CASE WHEN qos = 2 THEN 'complete' ELSE phase END "
            . "WHERE id = ? AND state = 'pending'", [$outcome, $request['reason'], $request['delivery_id']]);
        $connection->execute("UPDATE type_mqtt_messages SET state = 'complete' WHERE id = ? AND inbound_state <> 'received' AND NOT EXISTS "
            . "(SELECT 1 FROM type_mqtt_deliveries WHERE message_id = type_mqtt_messages.id AND state = 'pending')", [$rows[0]['message_id']]);
    }

    /** PUBREC 成功后不可回退到重新发送 PUBLISH；重复请求只接受同一已存在阶段。 */
    private function received(Connection $connection, array $request): void
    {
        $rows = $connection->query('SELECT session_id, qos, state, phase FROM type_mqtt_deliveries WHERE id = ? FOR UPDATE', [$request['delivery_id']]);
        if (count($rows) !== 1 || (int) $rows[0]['qos'] !== 2 || $rows[0]['state'] !== 'pending'
            || (isset($request['owner_id']) && $rows[0]['session_id'] !== $request['session_id'])
            || !in_array($rows[0]['phase'], ['wait_pubrec', 'wait_pubcomp'], true)) {
            throw new ProtocolError(0x83);
        }
        $connection->execute("UPDATE type_mqtt_deliveries SET phase = 'wait_pubcomp' WHERE id = ?", [$request['delivery_id']]);
    }

    /** 接收方在 PUBLISH 接管时已登记一次交付；PUBREL 只终结入站握手，不再派生第二次交付。 */
    private function released(Connection $connection, array $request): void
    {
        $rows = $connection->query(
            'SELECT qos, inbound_state, inbound_reason FROM type_mqtt_messages WHERE id = ? AND publisher_session = ? FOR UPDATE',
            [$request['message_id'], $request['session_id']]
        );
        if (count($rows) !== 1 || (int) $rows[0]['qos'] !== 2 || !in_array($rows[0]['inbound_state'], ['received', 'released'], true)
            || ($rows[0]['inbound_state'] === 'released' && (int) $rows[0]['inbound_reason'] !== $request['reason'])) {
            throw new ProtocolError(0x83);
        }
        $connection->execute("UPDATE type_mqtt_messages SET inbound_state = 'released', inbound_reason = ? WHERE id = ?", [$request['reason'], $request['message_id']]);
        $connection->execute("UPDATE type_mqtt_messages SET state = 'complete' WHERE id = ? AND NOT EXISTS "
            . "(SELECT 1 FROM type_mqtt_deliveries WHERE message_id = type_mqtt_messages.id AND state = 'pending')", [$request['message_id']]);
    }

    private function abandon(Connection $connection, string $sessionId): void
    {
        // QoS 1 等原会话恢复；会话终止才归还仍存在的组。已经开始的 QoS 2 永不换成员。
        $connection->execute("UPDATE type_mqtt_deliveries d SET session_id = d.group_id, client_id = '\$shared', packet_id = 0, "
            . "qos = m.qos, started = false, retain = false, subscription_identifiers = '[]'::jsonb, phase = CASE WHEN m.qos = 2 THEN 'wait_pubrec' ELSE 'none' END "
            . "FROM type_mqtt_messages m WHERE m.id = d.message_id AND d.session_id = ? AND d.state = 'pending' AND d.group_id <> '' "
            . 'AND (d.qos = 1 OR NOT d.started) AND EXISTS (SELECT 1 FROM type_mqtt_shared_groups g WHERE g.id = d.group_id)', [$sessionId]);
        $connection->execute("UPDATE type_mqtt_deliveries SET state = 'closed', phase = CASE WHEN qos = 2 THEN 'complete' ELSE phase END "
            . "WHERE session_id = ? AND state = 'pending'", [$sessionId]);
        $connection->execute("UPDATE type_mqtt_messages SET inbound_state = 'closed' WHERE publisher_session = ? AND inbound_state = 'received'", [$sessionId]);
        $connection->execute("UPDATE type_mqtt_messages SET state = 'complete' WHERE state = 'accepted' AND id IN "
            . '(SELECT message_id FROM type_mqtt_deliveries WHERE session_id = ? UNION SELECT id FROM type_mqtt_messages WHERE publisher_session = ?) '
            . "AND inbound_state <> 'received' AND NOT EXISTS "
            . "(SELECT 1 FROM type_mqtt_deliveries WHERE message_id = type_mqtt_messages.id AND state = 'pending')", [$sessionId, $sessionId]);
    }

    private function validate(array $request): void
    {
        if (!is_string($request['operation_id'] ?? null) || !self::identifier($request['operation_id'], 32)) {
            throw new \InvalidArgumentException('持久操作身份无效');
        }
        if ($request['action'] === 'install') {
            return;
        }
        if ($request['action'] === 'retain_clear') {
            if (!is_string($request['resource_id'] ?? null) || !self::identifier($request['resource_id'], 32)
                || !is_int($request['generation'] ?? null) || $request['generation'] < 0
                || !is_string($request['actor'] ?? null) || $request['actor'] === '' || strlen($request['actor']) > 256
                || !is_string($request['clear_operation_id'] ?? null) || !self::identifier($request['clear_operation_id'], 32)) {
                throw new \InvalidArgumentException('管理清除保留需要精确原件身份与审计身份');
            }
            return;
        }
        if ($request['action'] === 'quota_apply') {
            if (!is_int($request['version'] ?? null) || $request['version'] < 1 || !is_array($request['limits'] ?? null)) {
                throw new \InvalidArgumentException('管理配额需要版本与有界快照');
            }
            foreach (['maximumSessions' => 20000, 'maximumPendingMessages' => 2000000, 'maximumPendingBytes' => 4294967296,
                'maximumDeviceMessages' => 10000, 'maximumDeviceBytes' => 16777216, 'maximumApplicationMessages' => 1000000,
                'maximumApplicationBytes' => 2147483648, 'maximumSharedMessages' => 1000000, 'maximumSharedBytes' => 2147483648] as $key => $ceiling) {
                if (!is_int($request['limits'][$key] ?? null) || $request['limits'][$key] < 1 || $request['limits'][$key] > $ceiling) {
                    throw new \InvalidArgumentException('管理配额快照越界');
                }
            }
            return;
        }
        if (in_array($request['action'], ['resource_list', 'resource_detail'], true)) {
            $this->validateResource($request);
            return;
        }
        if (str_starts_with($request['action'], 'node_')) {
            if ($request['action'] === 'node_statistics') {
                return;
            }
            if (!is_string($request['node_id'] ?? null) || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $request['node_id']) !== 1
                || !is_string($request['node_run_id'] ?? null) || !self::identifier($request['node_run_id'], 32)) {
                throw new \InvalidArgumentException('集群节点及运行身份无效');
            }
            if (in_array($request['action'], ['node_fence', 'node_fence_result'], true)) {
                foreach (['actor', 'proof_ref'] as $field) {
                    if (!is_string($request[$field] ?? null) || $request[$field] === '' || strlen($request[$field]) > 256
                        || preg_match('/[\\x00-\\x1f\\x7f]/', $request[$field]) === 1) {
                        throw new \InvalidArgumentException('节点硬隔离登记需要明确操作人及外部证据标识');
                    }
                }
                if (isset($request['action_operation_id']) && (!is_string($request['action_operation_id']) || !self::identifier($request['action_operation_id'], 32))) {
                    throw new \InvalidArgumentException('节点隔离动作身份无效');
                }
                if ($request['action'] === 'node_fence_result' && !isset($request['action_operation_id'])) {
                    throw new \InvalidArgumentException('节点隔离对账需要原动作身份');
                }
                if (isset($request['origin_request_id']) && (!is_string($request['origin_request_id']) || !self::identifier($request['origin_request_id'], 32))) {
                    throw new \InvalidArgumentException('节点隔离原请求身份无效');
                }
                if (isset($request['generation']) && (!is_int($request['generation']) || $request['generation'] < 1)) {
                    throw new \InvalidArgumentException('节点隔离目标代次无效');
                }
                if (isset($request['observation_run']) && (!is_string($request['observation_run'])
                    || ($request['observation_run'] !== '' && !self::identifier($request['observation_run'], 32)))) {
                    throw new \InvalidArgumentException('节点隔离采样运行无效');
                }
            }
            if ($request['action'] === 'node_poll') {
                if (!is_string($request['delivery_cursor'] ?? '') || (($request['delivery_cursor'] ?? '') !== '' && !self::identifier($request['delivery_cursor'], 32))) {
                    throw new \InvalidArgumentException('节点待交付游标无效');
                }
                if (!is_array($request['closed_owners'] ?? []) || !array_is_list($request['closed_owners'] ?? []) || count($request['closed_owners'] ?? []) > 100) {
                    throw new \InvalidArgumentException('节点关闭确认额度无效');
                }
                foreach ($request['closed_owners'] ?? [] as $closedOwner) {
                    if (!is_string($closedOwner) || !self::identifier($closedOwner, 32)) {
                        throw new \InvalidArgumentException('节点关闭所有者无效');
                    }
                }
            }
            return;
        }
        if (str_starts_with($request['action'], 'session_')) {
            $this->validateSession($request);
            return;
        }
        if ($request['action'] === 'will_read') {
            if (!is_string($request['node_id'] ?? null) || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $request['node_id']) !== 1) {
                throw new \InvalidArgumentException('遗嘱节点无效');
            }
            return;
        }
        if (str_starts_with($request['action'], 'will_')) {
            if (!is_string($request['will_id'] ?? null) || !self::identifier($request['will_id'], 32)) {
                throw new \InvalidArgumentException('遗嘱身份无效');
            }
            if ($request['action'] !== 'will_accept') {
                if (!is_int($request['reason'] ?? null) || $request['reason'] < 0 || $request['reason'] > 255) {
                    throw new \InvalidArgumentException('遗嘱失败原因无效');
                }
                return;
            }
        }
        if (isset($request['owner_id']) && (!is_string($request['owner_id']) || !self::identifier($request['owner_id'], 32)
            || !is_string($request['session_id'] ?? null) || !self::identifier($request['session_id'], 32))) {
            throw new \InvalidArgumentException('会话所有者无效');
        }
        if (isset($request['snapshot_id']) || $request['action'] === 'retained_advance') {
            if (!isset($request['owner_id']) || !is_string($request['snapshot_id'] ?? null) || !self::identifier($request['snapshot_id'], 32)
                || !in_array($request['action'], ['retained_read', 'retained_accept', 'retained_advance'], true)) {
                throw new \InvalidArgumentException('保留快照身份无效');
            }
            if ($request['action'] !== 'retained_read') {
                if (!is_bool($request['snapshot_done'] ?? null) || !is_string($request['snapshot_cursor'] ?? null)) {
                    throw new \InvalidArgumentException('保留快照进度无效');
                }
                if ($request['snapshot_cursor'] !== '') {
                    new Message($request['snapshot_cursor'], '');
                }
                if ($request['action'] === 'retained_advance') {
                    return;
                }
            }
        }
        if (str_starts_with($request['action'], 'shared_')) {
            if (!isset($request['owner_id']) || !is_string($request['cursor'] ?? '') || (($request['cursor'] ?? '') !== '' && !self::identifier($request['cursor'], 64))) {
                throw new \InvalidArgumentException('共享读取所有者或游标无效');
            }
            if ($request['action'] !== 'shared_next' && (!is_string($request['delivery_id'] ?? null) || !self::identifier($request['delivery_id'], 64))) {
                throw new \InvalidArgumentException('共享交付身份无效');
            }
            if ($request['action'] === 'shared_claim' && (!is_int($request['packet_id'] ?? null) || $request['packet_id'] < 0 || $request['packet_id'] > 65535
                || !is_array($request['subscription'] ?? null))) {
                throw new \InvalidArgumentException('共享领取参数无效');
            }
            return;
        }
        if ($request['action'] === 'retained_read') {
            if (!is_string($request['topic'] ?? null) || !is_bool($request['no_local'] ?? null)
                || !is_string($request['client_id'] ?? null) || !self::clientId($request['client_id'])) {
                throw new \InvalidArgumentException('保留重放参数无效');
            }
            TopicFilter::validate($request['topic']);
            if (str_starts_with($request['topic'], '$share/') || !is_string($request['cursor'] ?? '') || strlen($request['cursor'] ?? '') > 65535) {
                throw new \InvalidArgumentException('保留过滤器或游标无效');
            }
            if (($request['cursor'] ?? '') !== '') {
                new Message($request['cursor'], '');
            }
            return;
        }
        if ($request['action'] === 'abandon') {
            if (!is_string($request['session_id'] ?? null) || !self::identifier($request['session_id'], 32)) {
                throw new \InvalidArgumentException('清洁连接会话身份无效');
            }
            return;
        }
        if ($request['action'] === 'complete') {
            if (!is_string($request['delivery_id'] ?? null) || !self::identifier($request['delivery_id'], 64)
                || !in_array($request['outcome'] ?? 'acknowledged', ['acknowledged', 'queued', 'expired', 'oversized', 'rejected', 'not_found'], true)
                || !is_int($request['reason'] ?? null) || !in_array($request['reason'], [0, 0x10, 0x80, 0x83, 0x87, 0x90, 0x91, 0x92, 0x97, 0x99], true)) {
                throw new \InvalidArgumentException('交付确认参数无效');
            }
            return;
        }
        if ($request['action'] === 'received') {
            if (!is_string($request['delivery_id'] ?? null) || !self::identifier($request['delivery_id'], 64)) {
                throw new \InvalidArgumentException('QoS 2 交付身份无效');
            }
            return;
        }
        if ($request['action'] === 'release') {
            if (!is_string($request['message_id'] ?? null) || !self::identifier($request['message_id'], 32)
                || !is_string($request['session_id'] ?? null) || !self::identifier($request['session_id'], 32)
                || !in_array($request['reason'] ?? null, [0, 0x92], true)) {
                throw new \InvalidArgumentException('QoS 2 接收终结参数无效');
            }
            return;
        }
        $message = $request['message'] ?? [];
        $deliveries = $request['deliveries'] ?? null;
        $queuedSessions = $request['queued_sessions'] ?? [];
        if (!is_array($queuedSessions) || !array_is_list($queuedSessions) || count($queuedSessions) > 512) {
            throw new \InvalidArgumentException('当前恢复队列无效');
        }
        foreach ($queuedSessions as $queuedSession) {
            if (!is_string($queuedSession) || !self::identifier($queuedSession, 32)) {
                throw new \InvalidArgumentException('当前恢复会话身份无效');
            }
        }
        $excluded = $request['excluded_sessions'] ?? [];
        if (!is_array($excluded) || !array_is_list($excluded) || count($excluded) > 512) {
            throw new \InvalidArgumentException('在线排除会话无效');
        }
        foreach ($excluded as $excludedSession) {
            if (!is_string($excludedSession) || !self::identifier($excludedSession, 32)) {
                throw new \InvalidArgumentException('在线排除会话身份无效');
            }
        }
        if (!is_array($message) || !is_array($deliveries) || !array_is_list($deliveries) || count($deliveries) > 512) {
            throw new \InvalidArgumentException('消息或交付意图无效');
        }
        foreach (['id', 'session_id', 'client_id', 'topic', 'payload', 'properties'] as $field) {
            if (!is_string($message[$field] ?? null)) {
                throw new \InvalidArgumentException('消息字段无效');
            }
        }
        if (!self::identifier($message['id'], 32) || !self::identifier($message['session_id'], 32) || !self::clientId($message['client_id'])
            || !is_int($message['packet_id'] ?? null) || !in_array($message['qos'] ?? null, [0, 1, 2], true)
            || $message['packet_id'] < ($message['qos'] > 0 ? 1 : 0) || $message['packet_id'] > 65535 || ($message['qos'] === 0 && $message['packet_id'] !== 0)
            || !is_bool($message['retain'] ?? false)
            || ($request['action'] === 'retained_accept' && ($message['retain'] ?? false))
            || strlen($message['payload']) + strlen($message['properties']) > 1398112) {
            throw new \InvalidArgumentException('可靠消息身份或大小无效');
        }
        $payload = base64_decode($message['payload'], true);
        $properties = base64_decode($message['properties'], true);
        if ($payload === false || $properties === false || base64_encode($payload) !== $message['payload'] || base64_encode($properties) !== $message['properties']
            // 网络入口已按实际报文限制为 1 MiB；归一化别名可额外展开至多 65535 字节 Topic。
            || strlen($message['topic']) + strlen($payload) + strlen($properties) > 1114111) {
            throw new \InvalidArgumentException('可靠消息二进制字段无效');
        }
        $normalized = new Message($message['topic'], $payload, $properties, $message['qos']);
        if (((($message['retain'] ?? false) || $request['action'] === 'retained_accept') && !array_key_exists('expires_at', $message))
            || (array_key_exists('expires_at', $message) && (
                (($normalized->attributes[0x02] ?? null) === null) !== ($message['expires_at'] === null)
                || ($message['expires_at'] !== null && (!is_int($message['expires_at']) || $message['expires_at'] < 0
                    || $message['expires_at'] > time() + (int) $normalized->attributes[0x02]))
            ))) {
            throw new \InvalidArgumentException('消息截止时间无效');
        }
        if (isset($message['expiry']) && (!is_int($message['expiry']) || $message['expiry'] < 0
            || !isset($normalized->attributes[0x02]) || $message['expiry'] > $normalized->attributes[0x02])) {
            throw new \InvalidArgumentException('消息剩余期限无效');
        }
        $seen = [];
        foreach ($deliveries as $delivery) {
            if (!is_array($delivery) || !is_string($delivery['id'] ?? null) || !self::identifier($delivery['id'], 64)
                || (isset($delivery['owner_id']) && (!is_string($delivery['owner_id']) || !self::identifier($delivery['owner_id'], 32)))
                || isset($delivery['group_id']) || isset($delivery['shared_filter']) || isset($delivery['volatile_owner'])
                || !is_string($delivery['session_id'] ?? null) || !self::identifier($delivery['session_id'], 32)
                || !is_string($delivery['client_id'] ?? null) || !self::clientId($delivery['client_id'])
                || !is_int($delivery['packet_id'] ?? null) || !in_array($delivery['qos'] ?? null, [0, 1, 2], true)
                || !is_bool($delivery['retain'] ?? false)
                || $delivery['qos'] > $message['qos'] || $delivery['packet_id'] < 0 || $delivery['packet_id'] > 65535
                || ($delivery['qos'] > 0 && $delivery['packet_id'] === 0 && !in_array($delivery['session_id'], $queuedSessions, true))
                || ($delivery['qos'] === 0 && $delivery['packet_id'] !== 0) || isset($seen[$delivery['session_id']])) {
                throw new \InvalidArgumentException('可靠交付意图无效');
            }
            $identifiers = $delivery['subscription_identifiers'] ?? [];
            if (!is_array($identifiers) || !array_is_list($identifiers) || count($identifiers) > 100) {
                throw new \InvalidArgumentException('交付订阅标识额度无效');
            }
            foreach ($identifiers as $subscriptionIdentifier) {
                if (!is_int($subscriptionIdentifier) || $subscriptionIdentifier < 1 || $subscriptionIdentifier > 268435455) {
                    throw new \InvalidArgumentException('交付订阅标识无效');
                }
            }
            $seen[$delivery['session_id']] = true;
        }
    }

    /**
     * 稳定节点最多32个；每次启动使用随机运行身份。退休事实不按时间推定。
     * node_close仅在调用进程关闭全部socket并回收worker后使用；node_fence仅登记基础设施已完成的硬隔离。
     */
    private function node(Connection $connection, array $request): array
    {
        $action = $request['action'];
        if ($action === 'node_statistics') {
            return ['nodes' => $connection->query('SELECT node_id, run_id, generation, state, actor, proof_ref FROM type_mqtt_nodes ORDER BY node_id LIMIT 32'),
                'pending_fences' => (int) $connection->query('SELECT COUNT(*) AS total FROM type_mqtt_fences')[0]['total']];
        }
        // 传输请求身份每次不同；原动作身份冻结目标，避免清理一次重试时误杀同动作的另一个后端。
        $actionId = $request['action_operation_id'] ?? $request['operation_id'];
        if (in_array($action, ['node_fence', 'node_fence_result'], true)) {
            $recorded = $connection->query('SELECT * FROM type_mqtt_node_audit WHERE operation_id = ? FOR UPDATE', [$actionId]);
            if ($recorded !== []) {
                $previous = $recorded[0];
                if ($previous['action'] !== 'node_fence' || $previous['node_id'] !== $request['node_id']
                    || $previous['run_id'] !== $request['node_run_id'] || $previous['actor'] !== $request['actor']
                    || $previous['proof_ref'] !== $request['proof_ref'] || $previous['observation_run'] !== ($request['observation_run'] ?? '')
                    || (isset($request['generation']) && (int) $previous['generation'] !== $request['generation'])) {
                    throw new ProtocolError(0x8e);
                }
                // 写入同值产生本次同步屏障；不刷新原事实时间，更不能重新隔离同名节点的新运行。
                $connection->execute('UPDATE type_mqtt_node_audit SET operation_id = operation_id WHERE operation_id = ?', [$actionId]);
                $originReleased = !isset($request['origin_request_id']) || $connection->query(
                    'SELECT pid FROM pg_stat_activity WHERE datname = current_database() AND usename = current_user AND application_name = ? LIMIT 1',
                    ['type_mqtt_' . $request['origin_request_id']]
                ) === [];
                return ['fenced' => true, 'operation_id' => $actionId, 'node_id' => $previous['node_id'],
                    'node_run_id' => $previous['run_id'], 'generation' => (int) $previous['generation'],
                    'observation_run' => $previous['observation_run'], 'origin_released' => $originReleased];
            }
            if ($action === 'node_fence_result') {
                // 缺失不证明未执行，尤其不能替代未知提交的最终对账。
                return ['fenced' => false, 'operation_id' => $actionId];
            }
        }
        $rows = $connection->query('SELECT * FROM type_mqtt_nodes WHERE node_id = ? FOR UPDATE', [$request['node_id']]);
        if ($action === 'node_open') {
            if ($connection->query("SELECT id FROM type_mqtt_sessions WHERE owner_id IS NOT NULL AND node_run_id = '' LIMIT 1") !== []) {
                throw new ProtocolError(0x88);
            }
            if ($rows !== [] && ($rows[0]['state'] === 'active' || $rows[0]['run_id'] === $request['node_run_id'])) {
                throw new ProtocolError(0x8e);
            }
            if ($rows === [] && (int) $connection->query('SELECT COUNT(*) AS total FROM type_mqtt_nodes')[0]['total'] >= 32) {
                throw new ProtocolError(0x97);
            }
            $generation = $rows === [] ? 1 : (int) $rows[0]['generation'] + 1;
            $connection->execute(
                'INSERT INTO type_mqtt_nodes (node_id, run_id, generation, state) VALUES (?, ?, ?, ?) '
                . "ON CONFLICT (node_id) DO UPDATE SET run_id = EXCLUDED.run_id, generation = EXCLUDED.generation, state = 'active', actor = '', proof_ref = '', updated_at = clock_timestamp()",
                [$request['node_id'], $request['node_run_id'], $generation, 'active']
            );
            return ['generation' => $generation];
        }
        if ($rows === [] || $rows[0]['run_id'] !== $request['node_run_id']) {
            throw new ProtocolError(0x8e);
        }
        if ($action === 'node_fence' && isset($request['generation']) && (int) $rows[0]['generation'] !== $request['generation']) {
            throw new ProtocolError(0x8e);
        }
        if ($action === 'node_close' || $action === 'node_fence') {
            $actor = $action === 'node_close' ? 'broker' : $request['actor'];
            $proof = $action === 'node_close' ? 'sockets_closed_workers_reaped' : $request['proof_ref'];
            $connection->execute("UPDATE type_mqtt_nodes SET state = 'fenced', actor = ?, proof_ref = ?, updated_at = clock_timestamp() WHERE node_id = ?", [$actor, $proof, $request['node_id']]);
            $connection->execute(
                'INSERT INTO type_mqtt_node_audit (operation_id, node_id, run_id, generation, actor, proof_ref, action, observation_run) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT DO NOTHING',
                [$actionId, $request['node_id'], $request['node_run_id'], $rows[0]['generation'], $actor, $proof, $action, $request['observation_run'] ?? '']
            );
            $connection->execute('DELETE FROM type_mqtt_fences WHERE node_id = ? AND node_run_id = ?', [$request['node_id'], $request['node_run_id']]);
            if ($action === 'node_close') {
                return ['fenced' => true];
            }
            return ['fenced' => true, 'operation_id' => $actionId, 'node_id' => $request['node_id'], 'node_run_id' => $request['node_run_id'],
                'generation' => (int) $rows[0]['generation'], 'observation_run' => $request['observation_run'] ?? ''];
        }
        $this->activeNode($connection, $request);
        foreach ($request['closed_owners'] ?? [] as $closedOwner) {
            $connection->execute('DELETE FROM type_mqtt_fences WHERE node_id = ? AND node_run_id = ? AND owner_id = ?', [$request['node_id'], $request['node_run_id'], $closedOwner]);
        }
        // 每轮只处理100个已隔离运行的会话；不把节点失联或轮询超时当成隔离成功。
        $lost = $connection->query('SELECT s.* FROM type_mqtt_sessions s JOIN type_mqtt_nodes n ON n.node_id = s.node_id '
            . "WHERE s.owner_id IS NOT NULL AND s.node_run_id <> '' AND (n.state = 'fenced' OR n.run_id <> s.node_run_id) ORDER BY s.id LIMIT 100 FOR UPDATE OF s");
        foreach ($lost as $session) {
            $this->scheduleWill($connection, $session, (int) $session['expiry'], 'network_lost');
            if ((int) $session['expiry'] === 0) {
                $this->eraseSession($connection, $session, md5($request['operation_id'] . $session['id']), 'network_lost', $session['principal']);
            } else {
                $connection->execute("UPDATE type_mqtt_sessions SET owner_id = NULL, expires_at = CASE WHEN expiry IN (-1, 4294967295) THEN NULL ELSE clock_timestamp() + expiry * interval '1 second' END WHERE id = ?", [$session['id']]);
            }
        }
        $connection->execute('UPDATE type_mqtt_wills SET node_id = ?, node_run_id = ? WHERE id IN (SELECT w.id FROM type_mqtt_wills w '
            . 'LEFT JOIN type_mqtt_nodes n ON n.node_id = w.node_id '
            . "WHERE w.due_at IS NOT NULL AND (w.node_run_id = '' OR n.state = 'fenced' OR n.run_id <> w.node_run_id) ORDER BY w.id LIMIT 100)", [$request['node_id'], $request['node_run_id']]);
        // QoS0仅属于发布时的在线连接；每轮有界终结已离线的普通/共享转发，不等待将来重连腾额度。
        $volatile = $connection->query("SELECT d.id FROM type_mqtt_deliveries d WHERE d.state = 'pending' AND d.qos = 0 "
            . "AND (d.volatile_owner <> '' OR d.group_id <> '') AND NOT EXISTS "
            . '(SELECT 1 FROM type_mqtt_sessions s JOIN type_mqtt_nodes n ON n.node_id = s.node_id AND n.run_id = s.node_run_id '
            . "WHERE n.state = 'active' AND s.owner_id IS NOT NULL AND ((d.group_id = '' AND s.id = d.session_id AND s.owner_id = d.volatile_owner) "
            . "OR (d.group_id <> '' AND ((d.started AND s.id = d.session_id AND s.owner_id = d.volatile_owner) OR (NOT d.started AND s.connected_at <= d.created_at AND EXISTS "
            . '(SELECT 1 FROM type_mqtt_shared_members b WHERE b.group_id = d.group_id AND b.session_id = s.id AND b.joined_at <= d.created_at)))))) ORDER BY d.id LIMIT 100');
        foreach ($volatile as $obsolete) {
            $this->complete($connection, ['delivery_id' => $obsolete['id'], 'reason' => 0, 'outcome' => 'expired']);
        }
        // 按所有者游标轮转，窗口满的前100个成员不能持续遮挡后续成员；无载荷进入节点心跳。
        $ready = $connection->query('SELECT s.owner_id FROM type_mqtt_sessions s WHERE s.node_id = ? AND s.node_run_id = ? AND s.owner_id > ? '
            . "AND EXISTS (SELECT 1 FROM type_mqtt_deliveries d WHERE d.session_id = s.id AND d.state = 'pending' AND NOT d.started) "
            . 'ORDER BY s.owner_id LIMIT 100', [$request['node_id'], $request['node_run_id'], $request['delivery_cursor'] ?? '']);
        return ['fences' => $connection->query('SELECT owner_id FROM type_mqtt_fences WHERE node_id = ? AND node_run_id = ? ORDER BY owner_id LIMIT 100', [$request['node_id'], $request['node_run_id']]),
            'ready_owners' => $ready, 'delivery_cursor' => count($ready) === 100 ? $ready[99]['owner_id'] : ''];
    }

    /** 随机运行身份与持久active事实同时匹配；旧运行不能凭稳定节点名重新获得权限。 */
    private function activeNode(Connection $connection, array $request): void
    {
        if (!is_string($request['node_run_id'] ?? null) || !self::identifier($request['node_run_id'], 32)
            || !is_string($request['node_id'] ?? null)
            || $connection->query("SELECT node_id FROM type_mqtt_nodes WHERE node_id = ? AND run_id = ? AND state = 'active'", [$request['node_id'], $request['node_run_id']]) === []) {
            throw new ProtocolError(0x8e);
        }
    }

    /** 接管意图独立于会话寿命，A→B→C竞争仍须清完同Client ID的全部旧输出者。 */
    private function fenceOwner(Connection $connection, array $session): void
    {
        if ($session['owner_id'] === null || $session['node_run_id'] === ''
            || $connection->query("SELECT node_id FROM type_mqtt_nodes WHERE node_id = ? AND run_id = ? AND state = 'active'", [$session['node_id'], $session['node_run_id']]) === []) {
            return;
        }
        if ((int) $connection->query('SELECT COUNT(*) AS total FROM type_mqtt_fences')[0]['total'] >= $this->maximumSessions) {
            throw new ProtocolError(0x97);
        }
        $connection->execute(
            'INSERT INTO type_mqtt_fences (owner_id, client_id, principal, node_id, node_run_id, generation, access_identity) VALUES (?, ?, ?, ?, ?, ?, CAST(? AS jsonb)) ON CONFLICT DO NOTHING',
            [$session['owner_id'], $session['client_id'], $session['principal'], $session['node_id'], $session['node_run_id'], $session['generation'], $session['access_identity']]
        );
    }

    private function waiting(Connection $connection, string $clientId, ?string $principal = null, ?array $identity = null): bool
    {
        if ($identity !== null) {
            return $connection->query(
                'SELECT owner_id FROM type_mqtt_fences WHERE client_id = ? AND '
                . '(access_identity = CAST(? AS jsonb) OR (access_identity IS NULL AND principal = ?)) LIMIT 1',
                [$clientId, json_encode($identity, JSON_THROW_ON_ERROR), $principal]
            ) !== [];
        }
        return $connection->query(
            'SELECT owner_id FROM type_mqtt_fences WHERE client_id = ?' . ($principal === null ? '' : ' AND principal = ?') . ' LIMIT 1',
            $principal === null ? [$clientId] : [$clientId, $principal]
        ) !== [];
    }

    /** 会话读写和消息接管共用同一短事务锁；所有者变化后旧命令只能失败或幂等退出。 */
    private function session(Connection $connection, array $request): array
    {
        $action = $request['action'];
        if ($action === 'session_statistics') {
            return $this->usage($connection);
        }
        if ($action === 'session_recover') {
            if ($connection->query('SELECT node_id FROM type_mqtt_nodes LIMIT 1') !== []) {
                throw new ProtocolError(0x88);
            }
            // 仅用于已独占监听的单节点重启；此动作不是跨节点选主或旧主隔离证明。
            $rows = $connection->query('SELECT * FROM type_mqtt_sessions WHERE node_id = ? AND owner_id IS NOT NULL ORDER BY id LIMIT 100 FOR UPDATE', [$request['node_id']]);
            foreach ($rows as $lost) {
                $this->scheduleWill($connection, $lost, (int) $lost['expiry'], 'network_lost');
                if ((int) $lost['expiry'] === 0) {
                    $this->eraseSession($connection, $lost, md5($request['operation_id'] . $lost['id']), 'network_lost', $lost['principal']);
                } else {
                    $connection->execute("UPDATE type_mqtt_sessions SET owner_id = NULL, expires_at = CASE WHEN expiry IN (-1, 4294967295) THEN NULL ELSE clock_timestamp() + expiry * interval '1 second' END WHERE id = ?", [$lost['id']]);
                }
            }
            return ['recovered' => count($rows)];
        }
        if ($action === 'session_open') {
            $nodeRun = $request['node_run_id'] ?? '';
            if ($nodeRun !== '') {
                $this->activeNode($connection, $request);
            } elseif ($connection->query('SELECT node_id FROM type_mqtt_nodes LIMIT 1') !== []) {
                throw new ProtocolError(0x88);
            }
            $previousScopes = $connection->query('SELECT resource_scope FROM type_mqtt_sessions WHERE client_id = ? FOR UPDATE', [$request['client_id']]);
            if ($previousScopes !== [] && $previousScopes[0]['resource_scope'] !== null
                && $previousScopes[0]['resource_scope'] !== ($request['resource_scope'] ?? null)) {
                // 到期和 Clean Start 同样不能成为改变已知归属的入口。
                throw new ProtocolError(0x87);
            }
            $this->expireSessions($connection, $request['operation_id']);
            $rows = $connection->query('SELECT *, (expires_at IS NOT NULL AND expires_at <= clock_timestamp()) AS expired '
                . 'FROM type_mqtt_sessions WHERE client_id = ? FOR UPDATE', [$request['client_id']]);
            $present = $rows !== [];
            $generation = $present ? (int) $rows[0]['generation'] + 1 : 1;
            if ($present) {
                $this->fenceOwner($connection, $rows[0]);
            }
            $capacityClass = $request['capacity_class'] ?? 'device';
            $identity = isset($request['access_identity']) ? AccessIdentity::fromData($request['access_identity']) : null;
            $previousIdentity = $present && $rows[0]['access_identity'] !== null
                ? AccessIdentity::fromData(json_decode($rows[0]['access_identity'], true, 4, JSON_THROW_ON_ERROR)) : null;
            if ($previousIdentity !== null && $identity === null) {
                // 旧策略缺少稳定身份，不能通过同名凭据将已迁移会话降级或删除。
                throw new ProtocolError(0x87);
            }
            $samePrincipal = $previousIdentity !== null && $identity !== null
                ? $previousIdentity->principalId === $identity->principalId : (!$present || $rows[0]['principal'] === $request['principal']);
            if ($present && !$request['clean_start'] && $rows[0]['capacity_class'] !== $capacityClass) {
                throw new ProtocolError(0x87);
            }
            if ($present && ($request['clean_start'] || self::truth($rows[0]['expired']) || !$samePrincipal
                || ((int) $rows[0]['expiry'] === 0))) {
                $cause = $request['clean_start'] ? 'client_clean' : (self::truth($rows[0]['expired']) ? 'expired' : 'authorization_changed');
                $this->eraseSession($connection, $rows[0], $request['operation_id'], $cause, $request['principal']);
                $present = false;
            }
            $sessionId = $present ? $rows[0]['id'] : $request['session_id'];
            if ($present) {
                // 直接接管在线所有者也必须先认定旧网络结束；与本节点关闭路径使用同一事务规则。
                if ($rows[0]['owner_id'] !== null) {
                    $this->scheduleWill($connection, $rows[0], (int) $rows[0]['expiry'], 'taken_over');
                }
                $cancelled = $connection->query('SELECT * FROM type_mqtt_wills WHERE session_id = ? AND delay > 0 '
                    . 'AND due_at > clock_timestamp() FOR UPDATE', [$sessionId]);
                foreach ($cancelled as $cancelledWill) {
                    $this->finishWill($connection, $cancelledWill, 'cancelled', 0);
                }
            }
            if (!$present) {
                if ((int) $connection->query('SELECT COUNT(*) AS total FROM type_mqtt_sessions')[0]['total'] >= $this->maximumSessions) {
                    throw new ProtocolError(0x97);
                }
                $connection->execute(
                    'INSERT INTO type_mqtt_sessions (id, client_id, owner_id, protocol, expiry, principal, node_id, capacity_class, node_run_id, generation, access_identity, resource_scope) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS jsonb), ?)',
                    [$sessionId, $request['client_id'], $request['owner_id'], $request['protocol'], $request['expiry'], $request['principal'], $request['node_id'], $capacityClass, $nodeRun, $generation,
                        $identity === null ? null : json_encode($identity->data(), JSON_THROW_ON_ERROR), $request['resource_scope'] ?? null]
                );
            } else {
                $connection->execute(
                    'UPDATE type_mqtt_sessions SET owner_id = ?, protocol = ?, expiry = ?, principal = ?, access_identity = CAST(? AS jsonb), expires_at = NULL, node_id = ?, node_run_id = ?, generation = ?, connected_at = clock_timestamp(), updated_at = clock_timestamp() WHERE id = ?',
                    [$request['owner_id'], $request['protocol'], $request['expiry'], $request['principal'], $identity === null ? null : json_encode($identity->data(), JSON_THROW_ON_ERROR),
                        $request['node_id'], $nodeRun, $generation, $sessionId]
                );
            }
            $connection->execute('UPDATE type_mqtt_wills SET node_id = ?, node_run_id = ? WHERE client_id = ?', [$request['node_id'], $nodeRun, $request['client_id']]);
            if (($request['will'] ?? null) !== null) {
                $will = $request['will'];
                $bytes = strlen($will['topic']) + strlen((string) base64_decode($will['payload'], true)) + strlen((string) base64_decode($will['properties'], true))
                    + strlen($request['client_id']) + strlen($request['principal'])
                    + ($identity === null ? 0 : strlen(json_encode($identity->data(), JSON_THROW_ON_ERROR)));
                $usage = $connection->query('SELECT COUNT(*) AS total, COALESCE(SUM(byte_size), 0) AS bytes FROM type_mqtt_wills')[0];
                if ((int) $usage['total'] >= 20000 || (int) $usage['bytes'] + $bytes > $this->maximumPendingBytes) {
                    throw new ProtocolError(0x97);
                }
                $connection->execute('INSERT INTO type_mqtt_wills (id, session_id, node_id, client_id, principal, protocol, message, delay, byte_size, node_run_id, access_identity) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, CAST(? AS jsonb), ?, ?, ?, CAST(? AS jsonb))', [$request['owner_id'], $sessionId, $request['node_id'],
                        $request['client_id'], $request['principal'], $request['protocol'], json_encode($will, JSON_THROW_ON_ERROR), $will['delay'], $bytes, $nodeRun,
                        $identity === null ? null : json_encode($identity->data(), JSON_THROW_ON_ERROR)]);
            }
            $incoming = $connection->query("SELECT id, packet_id FROM type_mqtt_messages WHERE publisher_session = ? AND inbound_state = 'received' ORDER BY created_at, id LIMIT 33", [$sessionId]);
            if (count($incoming) > 32) {
                throw new ProtocolError(0x97);
            }
            $identifiers = $connection->query("SELECT packet_id FROM type_mqtt_deliveries WHERE session_id = ? AND state = 'pending' AND packet_id > 0", [$sessionId]);
            return ['session_id' => $sessionId, 'generation' => $generation, 'present' => $present, 'subscriptions' => $present ? json_decode($rows[0]['subscriptions'], true, 16, JSON_THROW_ON_ERROR) : [],
                'incoming' => $incoming, 'identifiers' => $identifiers];
        }
        if ($action === 'session_terminate') {
            $waitingClient = '';
            $waitingPrincipal = null;
            $waitingIdentity = null;
            $terminated = false;
            if (isset($request['session_id'])) {
                $rows = $connection->query('SELECT * FROM type_mqtt_sessions WHERE id = ? FOR UPDATE', [$request['session_id']]);
                if ($rows !== [] && (int) $rows[0]['generation'] === $request['session_generation']) {
                    $waitingClient = $rows[0]['client_id'];
                    $waitingPrincipal = $rows[0]['principal'];
                    $waitingIdentity = $rows[0]['access_identity'] !== null
                        ? json_decode($rows[0]['access_identity'], true, 4, JSON_THROW_ON_ERROR) : null;
                    $this->fenceOwner($connection, $rows[0]);
                    $this->eraseSession($connection, $rows[0], $request['operation_id'], 'administrator_terminated', $request['actor']);
                    $terminated = true;
                } elseif ($rows === []) {
                    $audit = $connection->query(
                        'SELECT client_id FROM type_mqtt_session_audit WHERE session_id = ? AND action = ? ORDER BY created_at DESC, operation_id DESC LIMIT 1',
                        [$request['session_id'], 'administrator_terminated']
                    );
                    if ($audit !== []) {
                        $waitingClient = $audit[0]['client_id'];
                        $terminated = true;
                    }
                }
            } else {
                $rows = $connection->query('SELECT * FROM type_mqtt_sessions WHERE client_id = ? FOR UPDATE', [$request['client_id']]);
                // 可选精确旧主体保护迟到撤权；同客户端的新凭据会话不能被旧意图终结。
                $matched = $rows !== [] && (isset($request['access_identity'])
                    ? ($rows[0]['access_identity'] !== null ? AccessIdentity::fromData(json_decode($rows[0]['access_identity'], true, 4, JSON_THROW_ON_ERROR))
                        ->matches(AccessIdentity::fromData($request['access_identity'])) : (isset($request['principal']) && $rows[0]['principal'] === $request['principal']))
                    : (!isset($request['principal']) || $rows[0]['principal'] === $request['principal']));
                $waitingClient = $request['client_id'];
                $waitingPrincipal = $request['principal'] ?? null;
                $waitingIdentity = $request['access_identity'] ?? null;
                if ($matched) {
                    $this->fenceOwner($connection, $rows[0]);
                    $this->eraseSession($connection, $rows[0], $request['operation_id'], 'administrator_terminated', $request['actor']);
                    $terminated = true;
                }
            }
            return ['terminated' => $terminated, 'waiting' => $waitingClient === '' ? false
                : $this->waiting($connection, $waitingClient, $waitingPrincipal, $waitingIdentity)];
        }
        if ($action === 'session_close') {
            // 仅在socket已关闭且相关worker已回收后登记；所有者已被替换时仍清除准确旧运行的隔离意图。
            if (isset($request['node_run_id'])) {
                $connection->execute(
                    'DELETE FROM type_mqtt_fences WHERE owner_id = ? AND node_id = ? AND node_run_id = ?',
                    [$request['owner_id'], $request['node_id'], $request['node_run_id']]
                );
            }
            $rows = $connection->query(
                'SELECT * FROM type_mqtt_sessions WHERE ' . (isset($request['client_id']) ? 'client_id' : 'id') . ' = ? AND owner_id = ? FOR UPDATE',
                [$request['client_id'] ?? $request['session_id'], $request['owner_id']]
            );
            if ($rows === []) {
                return [];
            }
            $this->scheduleWill($connection, $rows[0], $request['expiry'], $request['cause'] ?? 'network_lost', (float) ($request['ended_at'] ?? 0.0));
            if ($request['expiry'] === 0) {
                $this->eraseSession($connection, $rows[0], $request['operation_id'], 'client_closed', $rows[0]['principal']);
            } else {
                $connection->execute(
                    'UPDATE type_mqtt_sessions SET owner_id = NULL, expiry = ?, expires_at = CASE WHEN CAST(? AS bigint) IN (-1, 4294967295) THEN NULL '
                    . "ELSE to_timestamp(?) + CAST(? AS bigint) * interval '1 second' END, updated_at = clock_timestamp() WHERE id = ?",
                    [$request['expiry'], $request['expiry'], $request['ended_at'] ?? microtime(true), $request['expiry'], $rows[0]['id']]
                );
            }
            return [];
        }
        $owned = $this->owned($connection, $request['session_id'], $request['owner_id'], $action === 'session_save');
        if ($action === 'session_save' && $this->waiting($connection, $owned['client_id'])) {
            return ['waiting' => true];
        }
        if (($request['check_only'] ?? false) === true) {
            return ['checked' => true];
        }
        if ($action === 'session_save') {
            if ($owned['resource_scope'] !== null && array_key_exists('resource_scope', $request) && $owned['resource_scope'] !== $request['resource_scope']) {
                throw new ProtocolError(0x87);
            }
            $resourceScope = $owned['resource_scope'] ?? ($request['resource_scope'] ?? null);
            if ($resourceScope !== null && $owned['access_identity'] === null) {
                throw new ProtocolError(0x87);
            }
            $this->memberships($connection, $request['session_id'], $request['subscriptions']);
            $connection->execute(
                'UPDATE type_mqtt_sessions SET subscriptions = CAST(? AS jsonb), resource_scope = ?, updated_at = clock_timestamp() WHERE id = ?',
                [json_encode($request['subscriptions'], JSON_THROW_ON_ERROR), $resourceScope, $request['session_id']]
            );
            $connection->execute('DELETE FROM type_mqtt_subscription_resources WHERE session_id = ?', [$request['session_id']]);
            $projectionValues = [];
            $projectionParameters = [];
            foreach ($request['subscriptions'] as $key => $options) {
                $filter = substr($key, 2);
                $subscription = TopicFilter::subscription($options);
                $projectionValues[] = '(?, ?, ?, ?, ?, ?, ?)';
                array_push(
                    $projectionParameters,
                    hash('sha256', $request['session_id'] . ':' . $filter),
                    $request['session_id'],
                    $resourceScope,
                    $filter,
                    TopicFilter::actual($filter),
                    $subscription['options'],
                    $subscription['identifier']
                );
            }
            if ($projectionValues !== []) {
                $connection->execute('INSERT INTO type_mqtt_subscription_resources(id, session_id, resource_scope, filter, actual_filter, options, identifier) VALUES '
                    . implode(', ', $projectionValues), $projectionParameters);
            }
            return ['subscriptions' => $request['subscriptions'], 'retained' => $this->retainedSnapshots($connection, $request)];
        }
        $ignored = $request['ignored'];
        $predicate = $ignored === [] ? '' : ' AND d.packet_id NOT IN (' . implode(',', array_fill(0, count($ignored), '?')) . ')';
        $rows = $connection->query("SELECT d.*, m.topic, encode(m.payload, 'base64') AS payload, encode(m.properties, 'base64') AS properties, "
            . 'FLOOR(EXTRACT(EPOCH FROM clock_timestamp() - m.created_at))::bigint AS age, '
            . 'FLOOR(EXTRACT(EPOCH FROM m.expires_at))::bigint AS expires_at, (m.expires_at IS NOT NULL AND m.expires_at <= clock_timestamp()) AS expired '
            . "FROM type_mqtt_deliveries d JOIN type_mqtt_messages m ON m.id = d.message_id WHERE d.session_id = ? AND d.state = 'pending'"
            . $predicate . ' ORDER BY d.started DESC, d.created_at, d.id LIMIT 1 FOR UPDATE OF d', [$request['session_id'], ...$ignored]);
        if ($rows === []) {
            return [];
        }
        $delivery = $rows[0];
        if (((int) $delivery['qos'] === 0 && ($delivery['volatile_owner'] !== $request['owner_id'] || self::truth($delivery['started'])))
            || (!self::truth($delivery['started']) && self::truth($delivery['expired']))) {
            $this->complete($connection, ['delivery_id' => $delivery['id'], 'reason' => 0, 'outcome' => 'expired']);
            return ['skipped' => true];
        }
        $packetId = (int) $delivery['packet_id'];
        if ($packetId === 0 && (int) $delivery['qos'] > 0) {
            $used = $connection->query("SELECT packet_id FROM type_mqtt_deliveries WHERE session_id = ? AND state = 'pending' AND packet_id > 0", [$request['session_id']]);
            $occupied = array_fill_keys($ignored, true);
            foreach ($used as $entry) {
                $occupied[(int) $entry['packet_id']] = true;
            }
            for ($candidate = 1; $candidate <= 65535; $candidate++) {
                if (!isset($occupied[$candidate])) {
                    $packetId = $candidate;
                    break;
                }
            }
            if ($packetId === 0) {
                throw new ProtocolError(0x97);
            }
        }
        $connection->execute('UPDATE type_mqtt_deliveries SET packet_id = ?, started = true WHERE id = ?', [$packetId, $delivery['id']]);
        $expiryInterval = null;
        if ($delivery['expires_at'] !== null) {
            $original = new Message($delivery['topic'], (string) base64_decode($delivery['payload'], true), (string) base64_decode($delivery['properties'], true));
            $expiryInterval = $original->attributes[0x02] ?? null;
        }
        return ['delivery' => ['id' => $delivery['id'], 'packet_id' => $packetId, 'qos' => (int) $delivery['qos'], 'phase' => $delivery['phase'],
            'duplicate' => self::truth($delivery['started']), 'topic' => $delivery['topic'], 'payload' => str_replace("\n", '', $delivery['payload']),
            'properties' => str_replace("\n", '', $delivery['properties']), 'age' => (int) $delivery['age'], 'retain' => self::truth($delivery['retain']),
            'subscription_identifiers' => json_decode($delivery['subscription_identifiers'], true, 16, JSON_THROW_ON_ERROR),
            'expires_at' => $delivery['expires_at'] === null ? null : (int) $delivery['expires_at'], 'expiry_interval' => $expiryInterval,
            'shared_filter' => $delivery['shared_filter']]];
    }

    /** 遗嘱期限从发现网络结束开始；主动 0x04 仍遵守 Will Delay，会话期限可使其提前。 */
    private function scheduleWill(Connection $connection, array $session, int $expiry, string $cause, float $endedAt = 0.0): void
    {
        $rows = $connection->query('SELECT * FROM type_mqtt_wills WHERE id = ? FOR UPDATE', [$session['owner_id']]);
        if ($rows === []) {
            return;
        }
        $will = $rows[0];
        if ($cause === 'normal') {
            $will['cause'] = $cause;
            $this->finishWill($connection, $will, 'cancelled', 0);
            return;
        }
        $delay = (int) $will['delay'];
        if ($expiry >= 0 && $expiry !== 4294967295) {
            $delay = min($delay, $expiry);
        }
        $connection->execute(
            "UPDATE type_mqtt_wills SET due_at = COALESCE(due_at, to_timestamp(?) + CAST(? AS bigint) * interval '1 second'), cause = ? WHERE id = ?",
            [$endedAt > 0.0 ? $endedAt : microtime(true), $delay, $cause, $will['id']]
        );
    }

    /** 与消息/保留写入共用事务；重连取消与重复工作只会有一个持久终结结果。 */
    private function will(Connection $connection, array $request): array
    {
        $nodeRun = $request['node_run_id'] ?? '';
        if ($nodeRun !== '') {
            $this->activeNode($connection, $request);
        } elseif ($connection->query('SELECT node_id FROM type_mqtt_nodes LIMIT 1') !== []) {
            throw new ProtocolError(0x88);
        }
        if ($request['action'] === 'will_read') {
            $this->expireSessions($connection, $request['operation_id']);
            $rows = $connection->query('SELECT * FROM type_mqtt_wills w WHERE node_id = ? AND node_run_id = ? AND due_at <= clock_timestamp() '
                . 'AND (retry_at IS NULL OR retry_at <= clock_timestamp()) AND NOT EXISTS (SELECT 1 FROM type_mqtt_fences f WHERE f.client_id = w.client_id) '
                . 'ORDER BY due_at, id LIMIT 1', [$request['node_id'], $nodeRun]);
            if ($rows === []) {
                return [];
            }
            $will = $rows[0];
            $message = json_decode($will['message'], true, 16, JSON_THROW_ON_ERROR);
            return ['will' => ['id' => $will['id'], 'session_id' => $will['session_id'], 'client_id' => $will['client_id'],
                'principal' => array_key_exists('username', $message) ? $message['username'] : $will['principal'],
                'access_identity' => $will['access_identity'] === null ? null : json_decode($will['access_identity'], true, 4, JSON_THROW_ON_ERROR),
                'protocol' => (int) $will['protocol'], 'message' => $message]];
        }
        $rows = $connection->query('SELECT * FROM type_mqtt_wills WHERE id = ? AND due_at <= clock_timestamp() FOR UPDATE', [$request['will_id']]);
        if ($rows === []) {
            return ['accepted' => false];
        }
        $will = $rows[0];
        if ($will['node_run_id'] !== $nodeRun || ($nodeRun !== '' && $will['node_id'] !== $request['node_id']) || $this->waiting($connection, $will['client_id'])) {
            return ['accepted' => false];
        }
        if ($request['action'] === 'will_discard') {
            $this->finishWill($connection, $will, 'denied', $request['reason']);
            return ['accepted' => false];
        }
        if ($request['action'] === 'will_defer') {
            $connection->execute(
                "UPDATE type_mqtt_wills SET retry_at = clock_timestamp() + interval '1 second', attempts = LEAST(attempts + 1, 1000000), last_reason = ? WHERE id = ?",
                [$request['reason'], $will['id']]
            );
            return ['accepted' => false];
        }
        $original = json_decode($will['message'], true, 16, JSON_THROW_ON_ERROR);
        foreach (['topic', 'payload', 'properties', 'qos', 'retain'] as $field) {
            if ($request['message'][$field] !== $original[$field]) {
                throw new ProtocolError(0x83);
            }
        }
        if ($request['message']['id'] !== $will['id'] || $request['message']['session_id'] !== $will['session_id'] || $request['message']['client_id'] !== $will['client_id']) {
            throw new ProtocolError(0x83);
        }
        $accepted = $this->accept($connection, $request, false);
        $this->finishWill($connection, $will, 'published', 0);
        return ['accepted' => true, ...$accepted];
    }

    /** 终结后释放载荷预算；审计只保留身份、原因和结果，不保存凭据或重复大载荷。 */
    private function finishWill(Connection $connection, array $will, string $outcome, int $reason): void
    {
        $connection->execute(
            'INSERT INTO type_mqtt_will_audit (id, session_id, client_id, outcome, cause, reason) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT DO NOTHING',
            [$will['id'], $will['session_id'], $will['client_id'], $outcome, $will['cause'], $reason]
        );
        $connection->execute('DELETE FROM type_mqtt_wills WHERE id = ?', [$will['id']]);
    }

    private function owned(Connection $connection, string $sessionId, string $ownerId, bool $allowWaiting = false): array
    {
        $rows = $connection->query('SELECT * FROM type_mqtt_sessions WHERE id = ? AND owner_id = ? FOR UPDATE', [$sessionId, $ownerId]);
        if ($rows === []) {
            throw new ProtocolError(0x83);
        }
        if ($rows[0]['node_run_id'] !== '') {
            $this->activeNode($connection, ['node_id' => $rows[0]['node_id'], 'node_run_id' => $rows[0]['node_run_id']]);
            if (!$allowWaiting && $this->waiting($connection, $rows[0]['client_id'])) {
                throw new ProtocolError(0x8e);
            }
        }
        return $rows[0];
    }

    /** 成员属于会话世代；最后成员退出只结束未开始积压，在途交换不随组行删除。 */
    private function memberships(Connection $connection, string $sessionId, array $subscriptions): void
    {
        $old = $connection->query('SELECT group_id, joined_at FROM type_mqtt_shared_members WHERE session_id = ?', [$sessionId]);
        $joined = [];
        foreach ($old as $previous) {
            $joined[$previous['group_id']] = $previous['joined_at'];
        }
        $connection->execute('DELETE FROM type_mqtt_shared_members WHERE session_id = ?', [$sessionId]);
        foreach ($subscriptions as $key => $subscription) {
            $filter = substr($key, 2);
            if (!str_starts_with($filter, '$share/')) {
                continue;
            }
            // 完整过滤器可达65535字节；索引摘要并复核原始字节，避免数据库B-tree键上限缩小协议范围。
            $filterHash = hash('sha256', $filter);
            $group = $connection->query('SELECT id FROM type_mqtt_shared_groups WHERE filter_hash = ? AND filter = ?', [$filterHash, $filter]);
            $groupId = $group === [] ? bin2hex(random_bytes(16)) : $group[0]['id'];
            if ($group === []) {
                $connection->execute('INSERT INTO type_mqtt_shared_groups(id, filter, filter_hash) VALUES (?, ?, ?)', [$groupId, $filter, $filterHash]);
            }
            $connection->execute(
                'INSERT INTO type_mqtt_shared_members(group_id, session_id, joined_at) VALUES (?, ?, COALESCE(CAST(? AS timestamptz), clock_timestamp()))',
                [$groupId, $sessionId, $joined[$groupId] ?? null]
            );
        }
        foreach ($old as $membership) {
            $groupId = $membership['group_id'];
            if ($connection->query('SELECT session_id FROM type_mqtt_shared_members WHERE group_id = ? LIMIT 1', [$groupId]) !== []) {
                continue;
            }
            $connection->execute("UPDATE type_mqtt_deliveries SET state = 'closed', phase = CASE WHEN qos = 2 THEN 'complete' ELSE phase END "
                . "WHERE group_id = ? AND state = 'pending' AND NOT started", [$groupId]);
            $connection->execute("UPDATE type_mqtt_messages SET state = 'complete' WHERE state = 'accepted' AND inbound_state <> 'received' "
                . 'AND id IN (SELECT message_id FROM type_mqtt_deliveries WHERE group_id = ?) '
                . "AND NOT EXISTS (SELECT 1 FROM type_mqtt_deliveries WHERE message_id = type_mqtt_messages.id AND state = 'pending')", [$groupId]);
            $connection->execute('DELETE FROM type_mqtt_shared_groups WHERE id = ?', [$groupId]);
        }
    }

    /** 只读候选不占归属；授权之后的领取在同步事务中固定原会话，再允许任何 socket 写出。 */
    private function shared(Connection $connection, array $request): array
    {
        $this->expireSessions($connection, $request['operation_id']);
        $predicate = $request['action'] === 'shared_next' ? 'd.id > ?' : 'd.id = ?';
        $rows = $connection->query(
            "SELECT d.*, m.topic, m.qos AS published_qos, m.retain AS published_retain, encode(m.payload, 'base64') AS payload, "
            . "encode(m.properties, 'base64') AS properties, FLOOR(EXTRACT(EPOCH FROM clock_timestamp() - m.created_at))::bigint AS age, "
            . 'FLOOR(EXTRACT(EPOCH FROM m.expires_at))::bigint AS expires_at, (m.expires_at IS NOT NULL AND m.expires_at <= clock_timestamp()) AS expired, '
            . 's.client_id AS member_client, s.subscriptions FROM type_mqtt_deliveries d JOIN type_mqtt_messages m ON m.id = d.message_id '
            . 'JOIN type_mqtt_shared_members b ON b.group_id = d.group_id JOIN type_mqtt_sessions s ON s.id = b.session_id '
            . "WHERE s.id = ? AND s.protocol = 5 AND d.state = 'pending' AND NOT d.started AND "
            . '(m.qos > 0 OR (s.connected_at <= d.created_at AND b.joined_at <= d.created_at)) AND ' . $predicate . ' ORDER BY d.id LIMIT 1 FOR UPDATE OF d',
            [$request['session_id'], $request['action'] === 'shared_next' ? ($request['cursor'] ?? '') : $request['delivery_id']]
        );
        if ($rows === []) {
            return [];
        }
        $delivery = $rows[0];
        if (self::truth($delivery['expired']) || $request['action'] === 'shared_drop') {
            $this->complete($connection, ['delivery_id' => $delivery['id'], 'outcome' => self::truth($delivery['expired']) ? 'expired' : 'oversized', 'reason' => 0]);
            return ['skipped' => true, 'cursor' => $delivery['id']];
        }
        $subscriptions = json_decode($delivery['subscriptions'], true, 16, JSON_THROW_ON_ERROR);
        $subscription = TopicFilter::subscription($subscriptions['t:' . $delivery['shared_filter']]);
        $qos = min((int) $delivery['published_qos'], $subscription['options'] & 3);
        $packetId = 0;
        if ($request['action'] === 'shared_claim') {
            if ($subscription !== $request['subscription']) {
                return [];
            }
            $packetId = $request['packet_id'];
            if (($qos === 0) !== ($packetId === 0) || ($packetId > 0 && $connection->query("SELECT id FROM type_mqtt_deliveries WHERE session_id = ? AND packet_id = ? AND state = 'pending'", [$request['session_id'], $packetId]) !== [])) {
                throw new ProtocolError(0x91);
            }
            $connection->execute('UPDATE type_mqtt_deliveries SET session_id = ?, client_id = ?, packet_id = ?, qos = ?, started = true, retain = ?, '
                . 'subscription_identifiers = CAST(? AS jsonb), phase = ?, volatile_owner = ? WHERE id = ?', [$request['session_id'], $delivery['member_client'], $packetId, $qos,
                    self::truth($delivery['published_retain']) && ($subscription['options'] & 8) !== 0 ? 'true' : 'false',
                    json_encode($subscription['identifier'] > 0 ? [$subscription['identifier']] : [], JSON_THROW_ON_ERROR), $qos === 2 ? 'wait_pubrec' : 'none',
                    $qos === 0 ? $request['owner_id'] : '', $delivery['id']]);
        }
        $original = new Message($delivery['topic'], (string) base64_decode($delivery['payload'], true), (string) base64_decode($delivery['properties'], true));
        return ['cursor' => $delivery['id'], 'subscription' => $subscription, 'delivery' => ['id' => $delivery['id'], 'packet_id' => $packetId,
            'qos' => $qos, 'phase' => $qos === 2 ? 'wait_pubrec' : 'none', 'duplicate' => false, 'topic' => $delivery['topic'],
            'payload' => str_replace("\n", '', $delivery['payload']), 'properties' => str_replace("\n", '', $delivery['properties']),
            'age' => (int) $delivery['age'], 'expires_at' => $delivery['expires_at'] === null ? null : (int) $delivery['expires_at'],
            'expiry_interval' => $original->attributes[0x02] ?? null, 'retain' => self::truth($delivery['published_retain']) && ($subscription['options'] & 8) !== 0,
            'subscription_identifiers' => $subscription['identifier'] > 0 ? [$subscription['identifier']] : [], 'shared_filter' => $delivery['shared_filter']]];
    }

    /** 每次最多回收 100 个已过期会话，过期事实不再参与路由；不会按更新时间清理无限会话。 */
    private function expireSessions(Connection $connection, string $operationId): void
    {
        $expired = $connection->query('SELECT * FROM type_mqtt_sessions WHERE owner_id IS NULL AND expires_at <= clock_timestamp() ORDER BY expires_at LIMIT 100 FOR UPDATE');
        foreach ($expired as $session) {
            $this->eraseSession($connection, $session, md5($operationId . $session['id']), 'expired', $session['principal']);
        }
    }

    private function eraseSession(Connection $connection, array $session, string $operationId, string $action, string $actor): void
    {
        // 会话先结束时遗嘱立即到期；已经安排的更早期限保持原值。
        $connection->execute(
            'UPDATE type_mqtt_wills SET due_at = LEAST(COALESCE(due_at, clock_timestamp()), clock_timestamp()), '
            . "cause = CASE WHEN cause = 'connected' THEN ? ELSE cause END WHERE session_id = ?",
            [$action, $session['id']]
        );
        $this->memberships($connection, $session['id'], []);
        $this->abandon($connection, $session['id']);
        $connection->execute('DELETE FROM type_mqtt_sessions WHERE id = ?', [$session['id']]);
        $connection->execute(
            'INSERT INTO type_mqtt_session_audit (operation_id, session_id, client_id, action, actor) VALUES (?, ?, ?, ?, ?) ON CONFLICT DO NOTHING',
            [$operationId, $session['id'], $session['client_id'], $action, $actor]
        );
    }

    private function validateSession(array $request): void
    {
        if (array_key_exists('resource_scope', $request) && $request['resource_scope'] !== null) {
            if (!is_string($request['resource_scope']) || !$this->validResourceScope($request['resource_scope']) || ($request['action'] === 'session_open' && !isset($request['access_identity']))) {
                throw new \InvalidArgumentException('管理资源归属无效');
            }
        }
        if (array_key_exists('node_run_id', $request) && (!is_string($request['node_run_id']) || !self::identifier($request['node_run_id'], 32)
            || !is_string($request['node_id'] ?? null) || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $request['node_id']) !== 1)) {
            throw new \InvalidArgumentException('会话节点运行身份无效');
        }
        if ($request['action'] === 'session_statistics') {
            return;
        }
        if ($request['action'] === 'session_open' && !in_array($request['capacity_class'] ?? 'device', ['device', 'application'], true)) {
            throw new \InvalidArgumentException('会话容量分类无效');
        }
        if (in_array($request['action'], ['session_open', 'session_recover'], true)
            && (!is_string($request['node_id'] ?? null) || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $request['node_id']) !== 1)) {
            throw new \InvalidArgumentException('单节点恢复身份无效');
        }
        if ($request['action'] === 'session_recover') {
            return;
        }
        if ($request['action'] === 'session_open'
            && (!is_string($request['client_id'] ?? null) || !self::clientId($request['client_id']))) {
            throw new \InvalidArgumentException('客户端身份无效');
        }
        if (in_array($request['action'], ['session_open', 'session_terminate'], true) && isset($request['access_identity'])) {
            if (!is_array($request['access_identity'])) {
                throw new \InvalidArgumentException('会话接入身份无效');
            }
            AccessIdentity::fromData($request['access_identity']);
        }
        if ($request['action'] === 'session_terminate') {
            if (isset($request['session_id'])) {
                if (!is_string($request['session_id']) || !self::identifier($request['session_id'], 32)
                    || !is_int($request['session_generation'] ?? null) || $request['session_generation'] < 0) {
                    throw new \InvalidArgumentException('精确终止会话身份无效');
                }
            } elseif (!is_string($request['client_id'] ?? null) || !self::clientId($request['client_id'])) {
                throw new \InvalidArgumentException('客户端身份无效');
            }
            if (!is_string($request['actor'] ?? null) || $request['actor'] === '' || strlen($request['actor']) > 256) {
                throw new \InvalidArgumentException('管理员终止需要明确审计身份');
            }
            if (array_key_exists('principal', $request) && (!is_string($request['principal']) || strlen($request['principal']) > 65535)) {
                throw new \InvalidArgumentException('授权失效需要明确的旧主体');
            }
            return;
        }
        foreach (['session_id', 'owner_id'] as $field) {
            if (!is_string($request[$field] ?? null) || !self::identifier($request[$field], 32)) {
                throw new \InvalidArgumentException('持久会话或所有者身份无效');
            }
        }
        if (in_array($request['action'], ['session_open', 'session_close'], true)
            && (!is_int($request['expiry'] ?? null) || $request['expiry'] < -1 || $request['expiry'] > 4294967295)) {
            throw new \InvalidArgumentException('会话期限无效');
        }
        if ($request['action'] === 'session_open' && (!in_array($request['protocol'] ?? null, [4, 5], true)
            || !is_bool($request['clean_start'] ?? null) || !is_string($request['principal'] ?? null) || strlen($request['principal']) > 65535)) {
            throw new \InvalidArgumentException('会话连接参数无效');
        }
        if ($request['action'] === 'session_close' && !in_array($request['cause'] ?? 'network_lost', ['network_lost', 'normal', 'will_requested', 'taken_over', 'server_shutdown', 'administrative'], true)) {
            throw new \InvalidArgumentException('会话结束原因无效');
        }
        if ($request['action'] === 'session_close' && isset($request['client_id'])
            && (!is_string($request['client_id']) || !self::clientId($request['client_id']))) {
            throw new \InvalidArgumentException('关闭会话的客户端身份无效');
        }
        if (isset($request['ended_at']) && ((!is_float($request['ended_at']) && !is_int($request['ended_at']))
            || !is_finite((float) $request['ended_at']) || $request['ended_at'] <= 0 || $request['ended_at'] > microtime(true) + 1.0)) {
            throw new \InvalidArgumentException('网络结束时间无效');
        }
        if ($request['action'] === 'session_open' && ($request['will'] ?? null) !== null) {
            $will = $request['will'];
            if (!is_array($will) || !is_string($will['topic'] ?? null) || !is_string($will['payload'] ?? null) || !is_string($will['properties'] ?? null)
                || !is_int($will['delay'] ?? null) || $will['delay'] < 0 || $will['delay'] > 4294967295 || !is_bool($will['retain'] ?? null)
                || !in_array($will['qos'] ?? null, [0, 1, 2], true) || ($request['protocol'] === 4 && $will['delay'] !== 0)) {
                throw new \InvalidArgumentException('遗嘱配置无效');
            }
            if (isset($will['username']) && (!is_string($will['username']) || strlen($will['username']) > 65535)) {
                throw new \InvalidArgumentException('遗嘱认证身份无效');
            }
            $willPayload = base64_decode($will['payload'], true);
            $willProperties = base64_decode($will['properties'], true);
            if ($willPayload === false || $willProperties === false || base64_encode($willPayload) !== $will['payload']
                || base64_encode($willProperties) !== $will['properties'] || strlen($willPayload) > 65535
                || strlen($will['topic']) + strlen($willPayload) + strlen($willProperties) > 1048576) {
                throw new \InvalidArgumentException('遗嘱二进制字段无效');
            }
            new Message($will['topic'], $willPayload, $willProperties, $will['qos']);
        }
        if ($request['action'] === 'session_save') {
            if (!is_array($request['subscriptions'] ?? null) || count($request['subscriptions']) > 100) {
                throw new \InvalidArgumentException('会话订阅额度无效');
            }
            foreach ($request['subscriptions'] as $key => $options) {
                if (!is_int($options) && !is_array($options)) {
                    throw new \InvalidArgumentException('会话订阅无效');
                }
                $subscription = TopicFilter::subscription($options);
                if (!is_string($key) || !str_starts_with($key, 't:')
                    || !is_int($subscription['options'] ?? null) || $subscription['options'] < 0 || $subscription['options'] > 47 || ($subscription['options'] & 3) === 3
                    || !is_int($subscription['identifier'] ?? null) || $subscription['identifier'] < 0 || $subscription['identifier'] > 268435455) {
                    throw new \InvalidArgumentException('会话订阅选项或标识无效');
                }
                TopicFilter::validate(substr($key, 2));
                TopicFilter::actual(substr($key, 2));
                if (str_starts_with(substr($key, 2), '$share/') && ($subscription['options'] & 4) !== 0) {
                    throw new \InvalidArgumentException('共享订阅不允许 No Local');
                }
            }
            if (!is_array($request['retained'] ?? []) || !array_is_list($request['retained'] ?? []) || count($request['retained'] ?? []) > 100) {
                throw new \InvalidArgumentException('保留快照额度无效');
            }
            foreach ($request['retained'] ?? [] as $snapshot) {
                if (!is_array($snapshot) || !is_string($snapshot['snapshot_id'] ?? null) || !self::identifier($snapshot['snapshot_id'], 32)
                    || !is_string($snapshot['topic'] ?? null) || str_starts_with($snapshot['topic'], '$share/')
                    || !is_array($snapshot['subscription'] ?? null)
                    || ($request['subscriptions']['t:' . $snapshot['topic']] ?? null) !== $snapshot['subscription']) {
                    throw new \InvalidArgumentException('保留快照订阅无效');
                }
                TopicFilter::validate($snapshot['topic']);
            }
        }
        if ($request['action'] === 'session_next') {
            if (!is_array($request['ignored'] ?? null) || !array_is_list($request['ignored']) || count($request['ignored']) > 32) {
                throw new \InvalidArgumentException('恢复窗口无效');
            }
            foreach ($request['ignored'] as $packetId) {
                if (!is_int($packetId) || $packetId < 1 || $packetId > 65535) {
                    throw new \InvalidArgumentException('恢复报文标识无效');
                }
            }
        }
    }

    private static function identifier(string $value, int $length): bool
    {
        return strlen($value) === $length && preg_match('/^[0-9a-f]+$/D', $value) === 1;
    }

    private static function clientId(string $value): bool
    {
        return $value !== '' && strlen($value) <= 65535 && !str_contains($value, "\0") && preg_match('//u', $value) === 1;
    }

    private static function truth(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't'], true);
    }
}
