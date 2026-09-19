<?php

declare(strict_types=1);

namespace app\broker\service;

use Type\Mqtt\AccessIdentity;
use Type\Mqtt\AuthorizationInvalidations;
use Type\Mqtt\CertificateAccessPolicy;
use Type\Mqtt\ConnectPacket;
use Type\Mqtt\ConnectionDisconnects;
use Type\Mqtt\IdentityAccessPolicy;
use Type\Mqtt\ProtocolError;
use Type\Mqtt\QuotaUpdates;
use Type\Mqtt\ResourceConnectionObserver;
use Type\Orm\Connection;
use Type\Orm\DatabaseManager;
use Type\Runtime\Deadline;
use Type\Runtime\ExecutionScope;

/** 独立宿主的版本化接入策略及有界节点观察；人员登录令牌不用于 MQTT。 */
final class NodeAccess implements IdentityAccessPolicy, CertificateAccessPolicy, ResourceConnectionObserver, AuthorizationInvalidations, ConnectionDisconnects, QuotaUpdates
{
    /** 本进程运行身份；提交成功才占用观察槽。 */
    private string $runId;
    /** 已声明的观察槽；-1 表示尚未提交成功。 */
    private int $slot = -1;
    /** @var \Closure(): array<string, int|float|bool>|null 从当前 Broker 读取公开统计。 */
    private ?\Closure $statistics = null;
    private int $authenticationRefusals = 0;
    /** 最近一次心跳上报的接入版本。 */
    private int $appliedAccessVersion = 0;
    /** 握手 CA 文件路径；空表示本节点未开 mTLS。 */
    private string $clientCa;
    /** 服务端证书路径；空表示明文。 */
    private string $certificate;
    /** 受控 HTTPS CRL 拉取；失败不覆盖已接纳列表。 */
    private CrlHttpsFetch $crlFetch;

    /**
     * @param array{host:string,port:int,transport:string,ws_port?:int,wss_port?:int,mtls_port?:int,io_driver?:string,plaintext?:int,allowed_origins?:string} $listener 实际交给 Broker 的监听配置，不含密钥。
     * @param array<string, int> $limits 本次进程实际启动额度；缺省沿用规格默认值。
     */
    public function __construct(private DatabaseManager $database, private string $nodeId, private string $username, private string $password, private string $topicPrefix, private array $listener, private bool $durable = false, array $limits = [], string $clientCa = '', string $certificate = '')
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $nodeId) !== 1 || $username === '' || strlen($username) > 100 || strlen($password) < 12
            || strlen($password) > 72 || $topicPrefix === '' || !str_ends_with($topicPrefix, '/') || strlen($topicPrefix) > 200
            || str_contains($topicPrefix, '#') || str_contains($topicPrefix, '+') || str_contains($topicPrefix, "\0")
            || str_contains($clientCa, "\0") || str_contains($certificate, "\0")) {
            throw new \InvalidArgumentException('broker_access_configuration_invalid');
        }
        $this->clientCa = $clientCa;
        $this->certificate = $certificate;
        $this->runId = bin2hex(random_bytes(16));
        $this->crlFetch = new CrlHttpsFetch();
        $scope = new ExecutionScope(new Deadline(2.0));
        try {
            $connection = $this->database->connect($scope);
            CompatService::assertRuntime($connection);
            RecoveryService::ready($connection);
            AccessService::ensureBootstrap($connection, $username, $password, $topicPrefix);
            QuotaService::ensureBootstrap($connection, $limits === [] ? QuotaService::defaults() : $limits);
            RuntimeService::ensureBootstrap($connection, RuntimeService::fromListener($this->listener));
        } finally {
            $scope->close();
        }
    }

    /** @param \Closure(): array<string, int|float|bool> $statistics 从当前 Broker 公共入口读取实际资源。 */
    public function observe(\Closure $statistics): void
    {
        $this->statistics = $statistics;
    }

    /** 每次 CONNECT 读取当前活动凭据；环境账号只用于首次写入，之后以数据库事实为准。 */
    public function authenticate(ConnectPacket $connect, string $peer, bool $secure): bool
    {
        return $this->authenticateIdentity($connect, $peer, $secure) !== null;
    }

    /**
     * 数据库当前活动凭据对应的稳定身份；调试名额不足时抛出配额原因码。
     *
     * @throws ProtocolError 调试名额已满时返回 MQTT 5 0x97。
     */
    public function authenticateIdentity(ConnectPacket $connect, string $peer, bool $secure): ?AccessIdentity
    {
        $scope = new ExecutionScope(new Deadline(2.0));
        try {
            $database = $this->database->connect($scope);
            $identity = AccessService::authenticate($database, $connect);
            if ($identity !== null && $identity->authenticationMethod === 'debug' && !$secure) {
                $identity = null;
            }
            if ($identity !== null && $identity->authenticationMethod === 'debug' && !DebugService::admit($database, $identity, $connect->clientId, $this->nodeId)) {
                throw new ProtocolError(0x97);
            }
            if ($identity === null) {
                $this->authenticationRefusals++;
            }
            return $identity;
        } finally {
            $scope->close();
        }
    }

    /** 指纹须已在当前生效快照中绑定到启用主体；未知、已吊销、CRL 列入、过期或重叠已结束的证书不形成身份。 */
    public function authenticateCertificate(ConnectPacket $connect, string $peer, string $fingerprint): ?AccessIdentity
    {
        $scope = new ExecutionScope(new Deadline(2.0));
        try {
            $identity = AccessService::authenticateCertificate($this->database->connect($scope), $fingerprint);
            if ($identity === null) {
                $this->authenticationRefusals++;
            }
            return $identity;
        } finally {
            $scope->close();
        }
    }

    /** 固定命名空间的启动授权由版本化 Topic 规则接替；每次按当前事实重新授权。 */
    public function authorize(ConnectPacket $connect, string $topic, string $action, int $qos): bool
    {
        $identity = $this->authenticateIdentity($connect, '', true);
        return $identity !== null && $this->authorizeIdentity($identity, $connect, $topic, $action, $qos);
    }

    /** 已认证身份每次按当前授权重判；同登录名的新代次不能沿用旧连接。 */
    public function authorizeIdentity(AccessIdentity $identity, ConnectPacket $connect, string $topic, string $action, int $qos): bool
    {
        $scope = new ExecutionScope(new Deadline(2.0));
        try {
            return AccessService::authorize($this->database->connect($scope), $identity, $topic, $action, $qos);
        } finally {
            $scope->close();
        }
    }

    /** 一次领取一项尚未完成的撤权意图；重叠结束、证书到期或 CRL 缺失/过期先持久处理再返回，供 Broker 在约 5 秒内断开旧连接。 */
    public function nextInvalidation(): ?array
    {
        $this->refreshCrls();
        $this->syncHandshakeCa();
        $scope = new ExecutionScope(new Deadline(2.0));
        try {
            return AccessService::nextInvalidation($this->database->connect($scope));
        } finally {
            $scope->close();
        }
    }

    /** Broker 已取得终止证明后记下本节点完成时间。 */
    public function invalidationCompleted(string $id): void
    {
        $scope = new ExecutionScope(new Deadline(2.0));
        try {
            AccessService::invalidationCompleted($this->database->connect($scope), $id, $this->nodeId);
        } finally {
            $scope->close();
        }
    }

    /** 管理库当前最新配额快照；无版本时返回 null。 */
    public function nextQuota(): ?array
    {
        $scope = new ExecutionScope(new Deadline(2.0));
        try {
            return QuotaService::currentSnapshot($this->database->connect($scope));
        } finally {
            $scope->close();
        }
    }

    /** 一次领取本节点尚未完成的精确断开；其它节点的意图不能被领取。 */
    public function nextDisconnect(): ?array
    {
        $scope = new ExecutionScope(new Deadline(2.0));
        try {
            return ConnectionOperations::next($this->database->connect($scope), $this->nodeId);
        } finally {
            $scope->close();
        }
    }

    /** 观察行消失后记下完成；仍有该 owner 的实时投影时返回假。 */
    public function disconnectCompleted(string $id, string $outcome): bool
    {
        $scope = new ExecutionScope(new Deadline(2.0));
        try {
            return ConnectionOperations::complete($this->database->connect($scope), $id, $outcome);
        } finally {
            $scope->close();
        }
    }

    /** 一次领取本节点尚未完成的精确会话终止；其它节点的意图不能被领取。 */
    public function nextTermination(): ?array
    {
        $scope = new ExecutionScope(new Deadline(2.0));
        try {
            return ConnectionOperations::next($this->database->connect($scope), $this->nodeId, 'session_terminate');
        } finally {
            $scope->close();
        }
    }

    /** 观察行消失且持久会话删除证明保存后记下完成。 */
    public function terminationCompleted(string $id, string $outcome): bool
    {
        $scope = new ExecutionScope(new Deadline(2.0));
        try {
            return ConnectionOperations::complete($this->database->connect($scope), $id, $outcome);
        } finally {
            $scope->close();
        }
    }

    /** 一次领取尚未完成的保留原件清除；任意具备存储的节点均可执行。 */
    public function nextClearance(): ?array
    {
        $scope = new ExecutionScope(new Deadline(2.0));
        try {
            return ConnectionOperations::next($this->database->connect($scope), $this->nodeId, 'retain_clear');
        } finally {
            $scope->close();
        }
    }

    /** 原件已按代次删除或确认缺失后记下完成。 */
    public function clearanceCompleted(string $id, string $outcome): bool
    {
        $scope = new ExecutionScope(new Deadline(2.0));
        try {
            return ConnectionOperations::complete($this->database->connect($scope), $id, $outcome);
        } finally {
            $scope->close();
        }
    }

    /** 兼容旧观察接口；完整连接事实由扩展接口登记。 */
    public function connected(string $clientId, string $username, string $ownerId, int $observedAt): void
    {
    }

    /** 完整 CONNACK 输出后登记无秘密的真实连接；数据库资源归本次观察作用域。 */
    public function connectedResource(array $resource, int $observedAt): void
    {
        $scope = new ExecutionScope(new Deadline(2.0));
        try {
            $connection = $this->database->connect($scope);
            ResourceObservations::connected($connection, $this->nodeId, $this->runId, $resource, $observedAt);
            try {
                DebugService::confirm($connection, $resource, $this->nodeId);
            } catch (\Throwable) {
            }
        } finally {
            $scope->close();
        }
    }

    /** @param array{options:int,identifier:int}|null $subscription 已生效订阅；null 表示精确取消。 */
    public function subscriptionResource(string $ownerId, string $filter, ?array $subscription, int $observedAt): void
    {
        $scope = new ExecutionScope(new Deadline(2.0));
        try {
            ResourceObservations::subscription($this->database->connect($scope), $this->nodeId, $this->runId, $ownerId, $filter, $subscription, $observedAt);
        } finally {
            $scope->close();
        }
    }

    /** 只回收当前运行的精确 owner，不影响稍后接入的新连接。 */
    public function disconnected(string $clientId, string $username, string $ownerId, int $observedAt, int $reason): void
    {
        $scope = new ExecutionScope(new Deadline(2.0));
        try {
            $connection = $this->database->connect($scope);
            ResourceObservations::disconnected($connection, $this->nodeId, $this->runId, $ownerId);
            try {
                DebugService::release($connection, $clientId, $observedAt);
            } catch (\Throwable) {
            }
        } finally {
            $scope->close();
        }
    }

    /** 最多32个运行槽位；仅已停止或明确登记隔离的槽位可重用，迟到旧运行不能覆盖新运行。 */
    public function heartbeat(int $observedAt): void
    {
        $this->syncHandshakeCa();
        $this->sample($observedAt, false);
        $this->refreshCrls();
    }

    /** 只在 Broker 已回收连接后记录正常停止；崩溃通过采样过期呈现不可达。 */
    public function stopped(int $observedAt): void
    {
        $this->crlFetch->stop();
        if ($this->slot >= 0) {
            $this->sample($observedAt, true);
        }
    }

    /** 在有界作用域内写入节点槽位与接入版本；提交成功才接受槽位身份。 */
    private function sample(int $observedAt, bool $stopped): void
    {
        if ($this->statistics === null) {
            throw new \RuntimeException('broker_statistics_unavailable');
        }
        $raw = ($this->statistics)();
        $metrics = [];
        foreach (['connections', 'accepted', 'rejected', 'closed', 'observationFailures', 'subscriptions', 'bufferedBytes', 'incomingExchanges', 'outgoingExchanges',
            'pendingCommits', 'durableCommits', 'rejectedCommits', 'unknownCommits', 'quarantinedCommits',
            'connectionQuotaRefusals', 'packetQuotaRefusals', 'subscriptionQuotaRefusals', 'commitQuotaRefusals', 'flushTimeouts', 'handshakeTimeouts',
            'deviceConnections', 'serviceConnections', 'maximumConnections', 'maximumDeviceConnections', 'maximumServiceConnections', 'quotaRevision', 'quotaFailures', 'nodeGeneration', 'processMemoryBytes', 'processPeakMemoryBytes'] as $key) {
            $value = $raw[$key] ?? null;
            $metrics[$key] = is_int($value) && $value >= 0 ? $value : null;
        }
        $metrics['authenticationRefusals'] = $this->authenticationRefusals;
        $metrics['durableConfigured'] = $this->durable ? 1 : 0;
        $metrics['stopping'] = ($raw['stopping'] ?? true) ? 1 : 0;
        foreach (['handshakeCaReloads', 'handshakeCaFailures'] as $key) {
            $value = $raw[$key] ?? null;
            $metrics[$key] = is_int($value) && $value >= 0 ? $value : 0;
        }
        $scope = new ExecutionScope(new Deadline(2.0));
        try {
            $connection = $this->database->connect($scope);
            $this->appliedAccessVersion = AccessService::currentVersion($connection);
            $metrics['accessRevision'] = $this->appliedAccessVersion;
            AccessService::observeNode($connection, $this->nodeId, $this->appliedAccessVersion, $stopped);
            $quotaRevision = is_int($metrics['quotaRevision'] ?? null) ? $metrics['quotaRevision'] : 0;
            QuotaService::observeNode($connection, $this->nodeId, $quotaRevision, $stopped);
            $loaded = $this->listener;
            $caHash = $this->clientCa !== '' && is_file($this->clientCa) ? hash_file('sha256', $this->clientCa) : false;
            $certHash = $this->certificate !== '' && is_file($this->certificate) ? hash_file('sha256', $this->certificate) : false;
            $loaded['handshake_ca_sha256'] = is_string($caHash) ? $caHash : '';
            $loaded['certificate_sha256'] = is_string($certHash) ? $certHash : '';
            RuntimeService::observeNode($connection, $this->nodeId, $loaded, $stopped);
            $claimed = $connection->transaction(function (Connection $transaction) use ($observedAt, $stopped, $metrics, $loaded): int {
                $selected = $this->slot;
                if ($selected < 0) {
                    $rows = $transaction->table('broker_nodes')->orderBy('slot');
                    if ($transaction->driverName() !== 'sqlite') {
                        $rows = $rows->lockForUpdate();
                    }
                    $occupied = [];
                    $reusable = [];
                    foreach ($rows->limit(32)->get() as $row) {
                        if ((int) $row['stopped'] > 0) {
                            $reusable[(int) $row['slot']] = ['run_id' => (string) $row['run_id'], 'stopped' => (int) $row['stopped']];
                        } else {
                            $occupied[(int) $row['slot']] = true;
                        }
                    }
                    for ($slot = 0; $slot < 32; $slot++) {
                        if (isset($occupied[$slot])) {
                            continue;
                        }
                        if (isset($reusable[$slot])) {
                            $transaction->table('broker_nodes')->where('slot', '=', $slot)->where('run_id', '=', $reusable[$slot]['run_id'])->where('stopped', '=', $reusable[$slot]['stopped'])->delete();
                        }
                        $transaction->table('broker_nodes')->insert(['slot' => $slot, 'node_id' => $this->nodeId, 'run_id' => $this->runId,
                            'observed_at' => $observedAt, 'stopped' => 0, 'listener_json' => json_encode($loaded, JSON_THROW_ON_ERROR), 'metrics_json' => '{}']);
                        $selected = $slot;
                        break;
                    }
                    if ($selected < 0) {
                        throw new \RuntimeException('broker_observation_capacity');
                    }
                }
                $changed = $transaction->table('broker_nodes')->where('slot', '=', $selected)->where('run_id', '=', $this->runId)->update([
                    'observed_at' => $observedAt, 'stopped' => $stopped ? 1 : 0,
                    'listener_json' => json_encode($loaded, JSON_THROW_ON_ERROR),
                    'metrics_json' => json_encode($metrics, JSON_THROW_ON_ERROR),
                ]);
                if ($changed !== 1) {
                    throw new \RuntimeException('broker_observation_owner_lost');
                }
                ResourceObservations::heartbeat($transaction, $this->nodeId, $this->runId, $observedAt, $this->slot < 0, $stopped);
                return $selected;
            }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
            // 提交成功才接受槽位；回滚或未知提交不能留下可被后续写入误用的内存身份。
            $this->slot = $claimed;
        } finally {
            $scope->close();
        }
        $this->refreshOccupancy();
    }

    /** 占用刷新失败不能让观察心跳停掉整个节点。 */
    private function refreshOccupancy(): void
    {
        $scope = new ExecutionScope(new Deadline(2.0));
        try {
            DebugService::touch($this->database->connect($scope), $this->nodeId);
        } catch (\Throwable) {
        } finally {
            $scope->close();
        }
    }

    /** 把当前受信 CA 公钥写入本节点握手文件；Broker 按 mtime 重载 SSL_CTX。 */
    private function syncHandshakeCa(): void
    {
        if ($this->clientCa === '') {
            return;
        }
        $scope = new ExecutionScope(new Deadline(2.0));
        try {
            AccessService::syncHandshakeCa($this->database->connect($scope), $this->clientCa);
        } finally {
            $scope->close();
        }
    }

    /** 到期则 fork Swoole Process 拉取 HTTPS CRL；接纳后才排队撤权。 */
    private function refreshCrls(): void
    {
        $scope = new ExecutionScope(new Deadline(2.0));
        try {
            $connection = $this->database->connect($scope);
            foreach (AccessService::dueCrlFetches($connection) as $job) {
                AccessService::scheduleCrlFetch($connection, $job['ca_id'], $job['interval']);
                $this->crlFetch->spawn($job['ca_id'], $job['url'], $job['ca_pem']);
            }
            foreach ($this->crlFetch->collect() as $result) {
                if (isset($result['pem']) && is_string($result['pem'])) {
                    AccessService::acceptFetchedCrl($connection, $result['ca_id'], $result['pem']);
                } else {
                    AccessService::recordCrlFetchFailure($connection, $result['ca_id'], (string) ($result['error'] ?? 'network'));
                }
            }
        } finally {
            $scope->close();
        }
    }
}
