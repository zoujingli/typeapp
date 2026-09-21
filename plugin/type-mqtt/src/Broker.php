<?php

declare(strict_types=1);

namespace Type\Mqtt;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Type\Runtime\Deadline;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\ExecutionScope;
use Type\Runtime\ProcessSignals;

/**
 * 独立进程的 MQTT 连接、授权与过滤器 QoS 0/1/2 路由入口。
 * 非阻塞 socket 由本实例独占，认证回调必须自行限定外部 I/O 等待，不持有长事务。
 */
final class Broker
{
    /** @var array<int, Connection> */
    private array $connections = [];
    private bool $started = false;
    private bool $stopping = false;
    private bool $nativeExiting = false;
    private int $accepted = 0;
    private int $rejected = 0;
    private int $closed = 0;
    private int $delivered = 0;
    private int $dropped = 0;
    private ?ProcessSignals $signals = null;
    /** @var array<string,array<string,mixed>> 当前持久操作；全局最多 32 个，消息原件最多 32 MiB。 */
    private array $commits = [];
    /** @var array<string,Connection> 断线后待登记的会话；与连接上限共享容量。 */
    private array $closingSessions = [];
    private int $durableCommits = 0;
    private int $unknownCommits = 0;
    private int $quarantinedCommits = 0;
    private int $rejectedCommits = 0;
    private float $observationAt = 0.0;
    private int $observationFailures = 0;
    private bool $willPending = false;
    private float $willPollAt = 0.0;
    private int $publishedWills = 0;
    private int $deniedWills = 0;
    private int $failedWills = 0;
    private int $connectionQuotaRefusals = 0;
    private int $packetQuotaRefusals = 0;
    private int $subscriptionQuotaRefusals = 0;
    private int $commitQuotaRefusals = 0;
    private int $flushTimeouts = 0;
    private int $handshakeTimeouts = 0;
    private int $eventTimer = 0;
    /** 官方 Channel 串行放行协议状态机；事件额度在作用域真实关闭后归还。 */
    private ?Channel $eventGate = null;
    private int $pendingEvents = 0;
    private int $pendingEventBytes = 0;
    private int $callbackFailures = 0;
    private int $eventQuotaRefusals = 0;
    private bool $tickPending = false;
    /** @var array<int, int> 原生事件接纳代次；排队期间关闭或复用的 fd 不再执行旧事件。 */
    private array $nativeEpochs = [];
    /** @var array<int, int> 同一原生连接在途事件数；全部收尾才恢复原生读取。 */
    private array $pendingFdEvents = [];
    /** 当前 Swoole Server，统一持有 TCP、TLS 与 WebSocket 监听。 */
    private mixed $nativeServer = null;
    /**
     * 需要重载握手 CA 的 Swoole 监听端口。
     * Server::set 在启动后会拒绝；Port::set 会重建 SSL_CTX，只影响新连接。
     *
     * @var list<mixed>
     */
    private array $handshakeCaPorts = [];
    private int $handshakeCaMtime = 0;
    private int $handshakeCaSize = 0;
    private int $handshakeCaReloads = 0;
    private int $handshakeCaFailures = 0;
    /** @var array<int, array{fingerprint:string,serial:string,notAfter:int}> WSS 握手前在 onConnect 暂存的客户端证书事实。 */
    private array $pendingCertificates = [];
    private int $nativeInstances = 0;
    /** @var array<int, int> 已请求关闭的 fd 及其接纳序号；迟到 close 不能拆掉后来占用者。 */
    private array $closingNativeInstances = [];
    /** 已导入的签名 CRL；未配置时保持 null。 */
    private ?CertificateRevocationList $revocationList = null;
    private int $crlMtime = 0;
    private int $crlSize = 0;
    private bool $crlUnavailable = false;
    private int $certificateEnforceAt = 0;
    private bool $crlFetching = false;
    private ?\Swoole\Coroutine\Http\Client $crlClient = null;
    private int $crlFetchAt = 0;
    /** @var array<string, true> 平台吊销序列号，本进程内只增不减。 */
    private array $platformRevoked = [];
    private int $revokeMtime = 0;
    private int $revokeSize = 0;
    /** @var array<string, int> 换证重叠指纹到截止时间。 */
    private array $overlapUntil = [];
    /** @var array<string, true> 本进程见过的重叠指纹；移出文件后结束重叠。 */
    private array $overlapKnown = [];
    private int $overlapMtime = 0;
    private int $overlapSize = 0;
    /** @var array{id:string,client_id:string,principal:string,actor:string,access_identity?:array<string,mixed>}|null 一次只隔离一项，持久意图由来源保留。 */
    private ?array $invalidation = null;
    private bool $invalidationPending = false;
    private float $invalidationAt = 0.0;
    private int $invalidated = 0;
    private int $invalidationFailures = 0;
    /** @var array{id:string,owner_id:string,session_id:string,session_generation:int,actor:string}|null 一次只执行一项管理断开，意图由来源保留。 */
    private ?array $disconnectOp = null;
    private bool $disconnectMatched = false;
    private float $disconnectAt = 0.0;
    private int $disconnected = 0;
    private int $disconnectFailures = 0;
    /** @var array{id:string,owner_id:string,session_id:string,session_generation:int,actor:string}|null 一次只执行一项精确会话终止。 */
    private ?array $terminateOp = null;
    private bool $terminateMatched = false;
    private bool $terminateStorePending = false;
    private bool $terminateErased = false;
    private string $terminateOutcome = '';
    private float $terminateAt = 0.0;
    private int $terminated = 0;
    private int $terminationFailures = 0;
    /** @var array{id:string,owner_id:string,session_id:string,session_generation:int,actor:string}|null 一次只清除一项保留原件。 */
    private ?array $clearOp = null;
    private bool $clearStorePending = false;
    private bool $clearErased = false;
    private string $clearOutcome = '';
    private float $clearAt = 0.0;
    private int $retainedCleared = 0;
    private int $retainClearFailures = 0;
    /** 当前生效的连接与订阅额度；启动值是本进程上限，在线只能降到该上限以内。 */
    private int $maximumConnections = 256;
    private int $maximumDeviceConnections = 10000;
    private int $maximumServiceConnections = 100;
    private int $maximumSubscriptions = 100;
    private int $startupMaximumConnections = 256;
    private int $startupMaximumDeviceConnections = 10000;
    private int $startupMaximumServiceConnections = 100;
    /** @var array<string, int>|null 已交给存储 worker 的持久额度；空表示沿用 worker 启动默认。 */
    private ?array $storeQuotas = null;
    private int $appliedQuotaVersion = 0;
    /** @var array{version:int,limits:array<string,int>}|null */
    private ?array $quotaOp = null;
    private bool $quotaStorePending = false;
    private float $quotaAt = 0.0;
    private int $quotaFailures = 0;
    private string $nodeRunId = '';
    private int $nodeGeneration = 0;
    private bool $nodePending = false;
    private float $nodePollAt = 0.0;
    private string $nodeDeliveryCursor = '';
    private int $nodeFailures = 0;
    /** @var list<array{owner_id:string}> 每节点最多100个关闭意图；未知旧运行只能由硬隔离完成登记释放。 */
    private array $nodeFences = [];
    /** Swoole Server 未接线 ssl_passphrase 时的解密私钥路径；空则沿用配置文件。 */
    private string $nativeKeyFile = '';
    /** 主证书与 SNI 叶证书中较早的 notAfter；0 表示未启用 TLS。 */
    private int $serverCertificateNotAfter = 0;

    /**
     * 必须显式注入认证方；没有默认匿名访问或应用反向依赖。
     * @param list<string> $workerCommand 已编译应用的明确 worker 命令；空数组仅开放 QoS 0。
     * @param string $nodeId 稳定节点身份；集群模式由持久运行身份隔离，不允许两个active运行共用。
     * @param ConnectionObserver|null $observer 仅观察真实网络生命周期；不接收密码、载荷或内部 Connection。
     * @param \Closure(ConnectPacket): string|null $classify 认证成功后返回 device/application；缺省为 device。分类必须来自受信身份，不能信任客户端自报属性。
     * @param AuthorizationInvalidations|null $invalidations 明确撤权来源；失败停止实例，不以普通观察回调承担授权证明。
     * @param ConnectionDisconnects|null $disconnects 精确管理断开、会话终止与清保留；断开按 owner 与代次匹配并保留会话，终止按 session_id 与代次删除该会话，清保留按 resource_id 与原件代次删除当前原件。
     * @param QuotaUpdates|null $quotas 版本化连接/会话/消息额度；降低不得删除已确认积压或终止可靠会话。
     */
    public function __construct(private AccessPolicy $access, private BrokerOptions $options, private array $workerCommand = [], private string $nodeId = 'default', private ?ConnectionObserver $observer = null, private ?\Closure $classify = null, private ?AuthorizationInvalidations $invalidations = null, private ?ConnectionDisconnects $disconnects = null, private ?QuotaUpdates $quotas = null)
    {
        if (!array_is_list($workerCommand) || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $nodeId) !== 1) {
            throw new \InvalidArgumentException('MQTT 持久 worker 命令必须是有序参数数组');
        }
        foreach ($workerCommand as $argument) {
            if (!is_string($argument) || $argument === '' || str_contains($argument, "\0")) {
                throw new \InvalidArgumentException('MQTT 持久 worker 命令参数无效');
            }
        }
        if ($options->clustered && $workerCommand === []) {
            throw new \InvalidArgumentException('MQTT集群模式需要明确同步持久worker');
        }
        if ($options->handleSignals) {
            $this->signals = new ProcessSignals();
        }
        $this->startupMaximumConnections = (int) $options->maximumConnections;
        $this->startupMaximumDeviceConnections = (int) $options->maximumDeviceConnections;
        $this->startupMaximumServiceConnections = (int) $options->maximumServiceConnections;
        $this->maximumConnections = (int) $options->maximumConnections;
        $this->maximumDeviceConnections = (int) $options->maximumDeviceConnections;
        $this->maximumServiceConnections = (int) $options->maximumServiceConnections;
    }

    /**
     * 绑定明确 IP 并运行至停止信号；一个实例只启动一次。
     * @throws \RuntimeException 监听、TLS 配置或信号能力不可用。
     */
    public function serve(string $host, int $port): void
    {
        if (filter_var($host, FILTER_VALIDATE_IP) === false || $port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('MQTT 监听需要明确 IP 与有效端口');
        }
        if ($this->options->wsPort === $port || $this->options->wssPort === $port || $this->options->mtlsPort === $port) {
            throw new \InvalidArgumentException('MQTT WebSocket 或 mTLS 端口不能与 TCP 端口相同');
        }
        if ($this->started || $this->stopping) {
            throw new \RuntimeException('MQTT 服务实例不能重复启动');
        }
        CoroutineRuntime::enableIo();
        // 原生事件只接纳工作；显式协程与全局 Channel 保持状态机串行，不嵌套 Scheduler。
        if (!swoole_async_set(['enable_coroutine' => false, 'enable_kqueue' => PHP_OS_FAMILY === 'Darwin'])) {
            throw new \RuntimeException('MQTT 原生事件驱动配置失败');
        }
        if (!$this->options->allowPlaintext) {
            // 第一份须为与私钥匹配的叶证书；其后的中间 CA 由 Swoole/OpenSSL 作为链下发。
            $certificate = @openssl_x509_read((string) file_get_contents($this->options->certificate));
            $key = @openssl_pkey_get_private((string) file_get_contents($this->options->privateKey), $this->options->privateKeyPassphrase);
            $parsed = $certificate === false ? false : openssl_x509_parse($certificate, false);
            if ($certificate === false || $key === false || !openssl_x509_check_private_key($certificate, $key)) {
                throw new \RuntimeException('MQTT TLS 证书或私钥无效、口令错误或二者不匹配');
            }
            if (!is_array($parsed) || !self::serverCertificateUsable($parsed)) {
                throw new \RuntimeException('MQTT TLS 证书已过期、尚未生效或不是服务端用途');
            }
            $this->rememberServerExpiry($parsed);
            if ($this->options->sniHost !== '') {
                $sniCertificate = @openssl_x509_read((string) file_get_contents($this->options->sniCertificate));
                $sniKey = @openssl_pkey_get_private((string) file_get_contents($this->options->sniPrivateKey));
                $sniParsed = $sniCertificate === false ? false : openssl_x509_parse($sniCertificate, false);
                if ($sniCertificate === false || $sniKey === false || !openssl_x509_check_private_key($sniCertificate, $sniKey)) {
                    throw new \RuntimeException('MQTT TLS 证书或私钥无效、口令错误或二者不匹配');
                }
                if (!is_array($sniParsed) || !self::serverCertificateUsable($sniParsed)) {
                    throw new \RuntimeException('MQTT TLS 证书已过期、尚未生效或不是服务端用途');
                }
                $this->rememberServerExpiry($sniParsed);
            }
            if ($this->options->privateKeyPassphrase !== '') {
                $this->nativeKeyFile = $this->materializeNativePrivateKey();
            }
        }
        if ($this->options->clientCa !== '') {
            clearstatcache(true, $this->options->clientCa);
            $this->handshakeCaMtime = (int) filemtime($this->options->clientCa);
            $this->handshakeCaSize = (int) filesize($this->options->clientCa);
        }
        if ($this->options->clientCrl !== '') {
            $this->revocationList = CertificateRevocationList::fromFiles($this->options->clientCrl, $this->options->clientCa);
            if (!$this->revocationList->covers(time())) {
                throw new \RuntimeException('MQTT 客户端 CRL 不在有效期内');
            }
            clearstatcache(true, $this->options->clientCrl);
            $this->crlMtime = (int) filemtime($this->options->clientCrl);
            $this->crlSize = (int) filesize($this->options->clientCrl);
        }
        if ($this->options->clientRevoke !== '') {
            $loaded = self::readPlatformRevokeFile($this->options->clientRevoke);
            if ($loaded === null) {
                throw new \RuntimeException('MQTT 平台吊销名单无效');
            }
            foreach ($loaded as $serial) {
                $this->platformRevoked[$serial] = true;
            }
            clearstatcache(true, $this->options->clientRevoke);
            $this->revokeMtime = (int) filemtime($this->options->clientRevoke);
            $this->revokeSize = (int) filesize($this->options->clientRevoke);
        }
        if ($this->options->clientOverlap !== '') {
            $windows = BrokerOptions::overlapWindows($this->options->clientOverlap, time());
            if ($windows === null) {
                throw new \RuntimeException('MQTT 换证重叠名单无效');
            }
            $this->applyOverlapWindows($windows);
            clearstatcache(true, $this->options->clientOverlap);
            $this->overlapMtime = (int) filemtime($this->options->clientOverlap);
            $this->overlapSize = (int) filesize($this->options->clientOverlap);
        }
        $this->started = true;
        try {
            if ($this->options->clustered) {
                $this->nodeRunId = bin2hex(random_bytes(16));
                $registered = $this->nodeRequest('node_open');
                if ($registered->state !== 'committed' || !$registered->released) {
                    throw new \RuntimeException('MQTT节点注册未取得同步持久证明，须核验旧运行隔离状态');
                }
                $this->nodeGeneration = $registered->value['generation'];
            } elseif ($this->workerCommand !== []) {
                do {
                    $recovery = new PendingCommit($this->workerCommand, ['operation_id' => bin2hex(random_bytes(16)), 'action' => 'session_recover', 'node_id' => $this->nodeId]);
                    do {
                        $result = $recovery->poll();
                        if ($result === null) {
                            usleep(10000);
                        }
                    } while ($result === null);
                    if ($result->state !== 'committed' || !$result->released) {
                        throw new \RuntimeException('MQTT 单节点会话恢复未取得同步持久证明');
                    }
                } while ($result->value['recovered'] === 100);
            }
            $this->signals?->attach(function (): void {
                $this->stop();
            });
            $this->serveNative($host, $port);
            if ($this->pendingEvents !== 0 || $this->crlFetching) {
                throw new \RuntimeException('mqtt_callback_shutdown_incomplete');
            }
        } finally {
            $this->stopping = true;
            $this->shutdownNative();
            CoroutineRuntime::run(function (): void {
                $scope = new ExecutionScope();
                try {
                    $scope->run(function (ExecutionScope $current): void {
                        foreach ($this->connections as $connection) {
                            $this->disconnect($connection, 0x8b);
                            $connection->flush();
                            $connection->close();
                            $this->observeClosed($connection);
                            $this->finishSession($connection);
                            $this->closed++;
                        }
                        $this->connections = [];
                        // 已启动工作沿用原有界截止；正常停机不把仍可确认的事务强制转为未知。
                        while ($this->commits !== [] || $this->closingSessions !== []) {
                            $this->pollCommits();
                            if ($this->commits !== []) {
                                usleep(10000);
                            }
                        }
                        if ($this->options->clustered && $this->nodeGeneration > 0 && $this->quarantinedCommits === 0) {
                            $retired = $this->nodeRequest('node_close');
                            if ($retired->state !== 'committed' || !$retired->released) {
                                $this->nodeFailures++;
                            }
                        }
                        $this->nodeFences = [];
                        $this->signals?->close();
                        try {
                            $this->observer?->stopped(time());
                        } catch (\Throwable) {
                            $this->observationFailures++;
                        }
                    });
                } finally {
                    try {
                        $scope->close();
                    } finally {
                        if ($scope->state() !== 'closed') {
                            $scope->awaitClosed();
                        }
                        $this->eventGate?->close();
                        $this->eventGate = null;
                        $this->nativeEpochs = [];
                        $this->pendingFdEvents = [];
                        $this->closingNativeInstances = [];
                        $this->pendingCertificates = [];
                    }
                }
            });
        }
    }

    /** 停止接收新连接，事件循环释放每个连接与监听资源。 */
    public function stop(): void
    {
        $this->stopping = true;
        if ($this->nativeServer !== null && $this->pendingEvents === 0 && !$this->nativeExiting) {
            try {
                $this->nativeServer->shutdown();
            } catch (\Throwable) {
            }
        }
    }

    /** 启动和退出阶段的单个有硬截止工作；事件循环的轮询继续使用全局32工作预算。 */
    private function nodeRequest(string $action): CommitResult
    {
        $pending = new PendingCommit($this->workerCommand, ['operation_id' => bin2hex(random_bytes(16)), 'action' => $action,
            'node_id' => $this->nodeId, 'node_run_id' => $this->nodeRunId]);
        do {
            $result = $pending->poll();
            if ($result === null) {
                usleep(10000);
            }
        } while ($result === null);
        return $result;
    }

    /** 一次最多100个隔离意图；只确认本进程已无socket、会话关闭及持久工作的精确所有者。 */
    private function pollNode(): void
    {
        if (!$this->options->clustered || $this->stopping || $this->nodePending
            || (float) hrtime(true) / 1000000000.0 < $this->nodePollAt || count($this->commits) + $this->quarantinedCommits >= 32) {
            return;
        }
        if ($this->quarantinedCommits !== 0) {
            $this->failNode();
            return;
        }
        $closed = [];
        foreach ($this->nodeFences as $fence) {
            $ownerId = $fence['owner_id'];
            $busy = isset($this->closingSessions[$ownerId]);
            foreach ($this->connections as $connection) {
                if ($connection->ownerId === $ownerId) {
                    $busy = true;
                }
            }
            foreach ($this->commits as $commit) {
                if ((isset($commit['connection']) && $commit['connection']->ownerId === $ownerId)
                    || (isset($commit['publisher']) && $commit['publisher']->ownerId === $ownerId)) {
                    $busy = true;
                }
                foreach ($commit['targets'] ?? [] as $target) {
                    if ($target['connection']->ownerId === $ownerId) {
                        $busy = true;
                    }
                }
            }
            if (!$busy) {
                $closed[] = $ownerId;
            }
        }
        $operationId = bin2hex(random_bytes(16));
        $this->nodePollAt = (float) hrtime(true) / 1000000000.0 + 0.25;
        try {
            $this->commits[$operationId] = ['kind' => 'node_poll', 'pending' => new PendingCommit(
                $this->workerCommand,
                ['operation_id' => $operationId, 'action' => 'node_poll', 'node_id' => $this->nodeId, 'node_run_id' => $this->nodeRunId,
                    'closed_owners' => $closed, 'delivery_cursor' => $this->nodeDeliveryCursor]
            )];
            $this->nodePending = true;
        } catch (\Throwable) {
            $this->failNode();
        }
    }

    /** 失去持久节点证明先丢弃所有网络输出；关闭事实确认之前其他节点仍保持等待。 */
    private function failNode(): void
    {
        $this->nodeFailures++;
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->stop();
    }

    /** MQTT 监听始终由 Swoole Server 持有；没有可选驱动或 PHP 流回退。 */
    private function nativeEnabled(): bool
    {
        return true;
    }

    /** 配置了明文 WS 或 WSS 时主端口改为 WebSocket Server。 */
    private function websocketEnabled(): bool
    {
        return $this->options->wsPort > 0 || $this->options->wssPort > 0;
    }

    /** 以一个 Swoole Server 持有所有监听；TLS 与 WebSocket 分帧交给原生。 */
    private function serveNative(string $host, int $port): void
    {
        $settings = [
            'worker_num' => 1,
            'enable_coroutine' => false,
            'log_level' => SWOOLE_LOG_ERROR,
            'log_file' => '/dev/null',
            'max_connection' => min(10100, $this->options->maximumConnections + 16),
            'max_request' => 0,
            'reload_async' => true,
            'max_wait_time' => (int) ceil($this->options->callbackSeconds + 6),
            'package_max_length' => min(2097152, $this->options->maximumPacketBytes * 2),
            'buffer_output_size' => 2097152,
            'open_tcp_nodelay' => true,
        ];
        if ($this->websocketEnabled()) {
            $wsPort = $this->options->wsPort > 0 ? $this->options->wsPort : $this->options->wssPort;
            $secureWs = $this->options->wssPort > 0;
            $server = new \Swoole\WebSocket\Server($host, $wsPort, SWOOLE_BASE, self::nativeTcp($host) | ($secureWs ? SWOOLE_SSL : 0));
            $settings['open_websocket_close_frame'] = true;
            $settings['open_websocket_ping_frame'] = false;
            $settings['open_websocket_pong_frame'] = false;
            $settings['websocket_compression'] = false;
            $settings['websocket_subprotocol'] = 'mqtt';
            if ($secureWs) {
                $settings = array_merge($settings, $this->swooleTls($this->options->clientCa !== ''));
            }
            if (!$server->set($settings)) {
                throw new \RuntimeException('MQTT WebSocket 原生配置未被接受');
            }
            if ($secureWs && $this->options->clientCa !== '') {
                $this->rememberHandshakePort($this->primaryPort($server));
            }
            $tcp = $server->addListener($host, $port, self::nativeTcp($host) | ($this->options->allowPlaintext ? 0 : SWOOLE_SSL));
            if ($tcp === false) {
                throw new \RuntimeException('MQTT TCP 监听失败，请检查地址、端口与权限');
            }
            $tcpSettings = ['open_http_protocol' => false, 'open_websocket_protocol' => false, 'open_mqtt_protocol' => false, 'open_tcp_nodelay' => true];
            if (!$this->options->allowPlaintext) {
                $tcpSettings = array_merge($tcpSettings, $this->swooleTls(false));
            }
            if (method_exists($tcp, 'set') && $tcp->set($tcpSettings) === false) {
                throw new \RuntimeException('MQTT TCP 监听配置未被接受');
            }
            if ($this->options->mtlsPort > 0) {
                $mtls = $server->addListener($host, $this->options->mtlsPort, self::nativeTcp($host) | SWOOLE_SSL);
                if ($mtls === false) {
                    throw new \RuntimeException('MQTT mTLS 监听失败，请检查地址、端口与权限');
                }
                $this->rememberHandshakePort($mtls);
                $mtlsSettings = array_merge(
                    ['open_http_protocol' => false, 'open_websocket_protocol' => false, 'open_mqtt_protocol' => false, 'open_tcp_nodelay' => true],
                    $this->swooleTls(true)
                );
                if (method_exists($mtls, 'set') && $mtls->set($mtlsSettings) === false) {
                    throw new \RuntimeException('MQTT mTLS 监听配置未被接受');
                }
            }
            $this->launchNative($server, $wsPort, $port);
            return;
        }
        $secure = !$this->options->allowPlaintext;
        $tcpServer = new \Swoole\Server($host, $port, SWOOLE_BASE, self::nativeTcp($host) | ($secure ? SWOOLE_SSL : 0));
        if ($secure) {
            $settings = array_merge($settings, $this->swooleTls(false));
        }
        $settings = array_merge($settings, [
            'open_http_protocol' => false,
            'open_websocket_protocol' => false,
            'open_mqtt_protocol' => false,
            'open_tcp_nodelay' => true,
        ]);
        if (!$tcpServer->set($settings)) {
            throw new \RuntimeException('MQTT 原生 TCP 配置未被接受');
        }
        if ($this->options->mtlsPort > 0) {
            $mtls = $tcpServer->addListener($host, $this->options->mtlsPort, self::nativeTcp($host) | SWOOLE_SSL);
            if ($mtls === false) {
                throw new \RuntimeException('MQTT mTLS 监听失败，请检查地址、端口与权限');
            }
            $this->rememberHandshakePort($mtls);
            $mtlsSettings = array_merge(
                ['open_http_protocol' => false, 'open_websocket_protocol' => false, 'open_mqtt_protocol' => false, 'open_tcp_nodelay' => true],
                $this->swooleTls(true)
            );
            if (method_exists($mtls, 'set') && $mtls->set($mtlsSettings) === false) {
                throw new \RuntimeException('MQTT mTLS 监听配置未被接受');
            }
        }
        $this->launchNative($tcpServer, 0, $port);
    }

    /** IPv6 必须用 TCP6；IPv4 的 TCP 套接字不能绑定 ::1。 */
    private static function nativeTcp(string $host): int
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? SWOOLE_SOCK_TCP6 : SWOOLE_SOCK_TCP;
    }

    /** WebSocket 与纯 mTLS 分支各自持有稳定的 Server 类型后再进入同一启动路径。 */
    private function launchNative(\Swoole\Server $server, int $wsPort, int $port): void
    {
        $this->bindNative($server, $wsPort, $port);
        $this->nativeServer = $server;
        $server->start();
    }

    /**
     * @param bool $mutual 为真时校验客户端证书；mTLS TCP 主端口或配置了 CA 的 WSS 主端口启用。
     * @return array<string, mixed>
     */
    private function swooleTls(bool $mutual): array
    {
        $settings = [
            'ssl_cert_file' => $this->options->certificate,
            'ssl_key_file' => $this->nativeKeyFile !== '' ? $this->nativeKeyFile : $this->options->privateKey,
            'ssl_verify_peer' => $mutual,
            'ssl_allow_self_signed' => !$mutual,
            'ssl_compress' => false,
            'ssl_prefer_server_ciphers' => true,
            'ssl_protocols' => SWOOLE_SSL_TLSv1_2 | SWOOLE_SSL_TLSv1_3,
            'ssl_ciphers' => self::tlsCiphers(),
            'ssl_ecdh_curve' => 'X25519:P-256',
        ];
        if ($this->options->sniHost !== '') {
            $settings['ssl_sni_certs'] = [
                $this->options->sniHost => [
                    'ssl_cert_file' => $this->options->sniCertificate,
                    'ssl_key_file' => $this->options->sniPrivateKey,
                ],
            ];
        }
        if ($mutual) {
            $settings['ssl_client_cert_file'] = $this->options->clientCa;
            $settings['ssl_verify_depth'] = 3;
        }
        return $settings;
    }

    /** TLS 1.2 只提供 ECDHE AEAD；TLS 1.3 套件由 OpenSSL 单独配置。 */
    private static function tlsCiphers(): string
    {
        return 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305';
    }

    private function bindNative(\Swoole\Server $server, int $wsPort, int $tcpPort): void
    {
        $server->on('workerStart', function (\Swoole\Server $native, int $workerId): void {
            $timer = \Swoole\Timer::tick(50, function (int $timerId) use ($native): void {
                if ($this->stopping) {
                    $this->stopClientCrlFetch();
                    $this->stop();
                    return;
                }
                if ($this->tickPending) {
                    return;
                }
                $this->tickPending = true;
                $this->nativeEvent('tick', function (ExecutionScope $scope) use ($native): void {
                    $this->signals?->dispatch();
                    $this->tick();
                    foreach ($this->connections as $connection) {
                        if ($connection->output !== '' && $connection->alive()) {
                            $this->flush($connection);
                        }
                    }
                });
            });
            if ($timer === false) {
                throw new \RuntimeException('MQTT 原生定时唤醒注册失败');
            }
            $this->eventTimer = $timer;
        });
        $server->on('connect', function (\Swoole\Server $native, int $fd, int $reactorId) use ($wsPort, $tcpPort): void {
            // 先撤销旧传输，避免正在让出的回调把输出写入复用后的连接；持久收尾仍串行执行。
            $previous = $this->connections[$fd] ?? null;
            if ($previous !== null) {
                $previous->socket = null;
                $previous->native = null;
            }
            $this->nativeEpochs[$fd] = ++$this->nativeInstances;
            $this->pendingFdEvents[$fd] = 0;
            $this->nativeEvent('connect', function (ExecutionScope $scope) use ($native, $fd, $reactorId, $wsPort, $tcpPort): void {
                $info = $native->getClientInfo($fd, $reactorId);
                if (!is_array($info)) {
                    $info = $native->getClientInfo($fd);
                }
                $listen = is_array($info) ? (int) ($info['server_port'] ?? 0) : 0;
                if ($wsPort > 0 && $this->options->clientCa !== '' && $listen === $wsPort) {
                    $this->pendingCertificates[$fd] = $this->certificateFromInfo(is_array($info) ? $info : []);
                    return;
                }
                if ($this->options->mtlsPort > 0 && $listen === $this->options->mtlsPort) {
                    $facts = $this->certificateFromInfo(is_array($info) ? $info : []);
                    $this->acceptNative($native, $fd, 'tcp', true, is_array($info) ? $info : [], $facts['fingerprint'], true, $facts['serial'], $facts['notAfter']);
                    return;
                }
                // WebSocket 连接在 onOpen 建表；此处只接收 TCP/TLS 监听上的 fd。
                if ($listen !== $tcpPort || ($wsPort > 0 && $listen === $wsPort)) {
                    return;
                }
                $this->acceptNative($native, $fd, 'tcp', !$this->options->allowPlaintext, is_array($info) ? $info : []);
            }, $fd);
        });
        $server->on('receive', function (\Swoole\Server $native, int $fd, int $reactorId, string $data): void {
            $this->nativeEvent('receive', function (ExecutionScope $scope) use ($fd, $data): void {
                $connection = $this->connections[$fd] ?? null;
                if ($connection === null || $connection->transport !== 'tcp' || $connection->closing) {
                    return;
                }
                $this->ingest($connection, $data);
                if ($connection->output !== '') {
                    $this->flush($connection);
                }
            }, $fd, strlen($data));
        });
        if ($this->websocketEnabled() && $server instanceof \Swoole\WebSocket\Server) {
            $server->on('open', function (\Swoole\WebSocket\Server $native, \Swoole\Http\Request $request): void {
                $fd = $request->fd;
                if (!isset($this->nativeEpochs[$fd])) {
                    $this->nativeEpochs[$fd] = ++$this->nativeInstances;
                }
                $this->nativeEvent('open', function (ExecutionScope $scope) use ($native, $request, $fd): void {
                    $headers = [];
                    foreach ($request->header as $name => $value) {
                        $headers[(string) $name] = (string) $value;
                    }
                    if (!self::offersMqtt($headers)) {
                        $this->rejected++;
                        $native->disconnect($fd, 1002, 'subprotocol_not_offered');
                        return;
                    }
                    $origin = strtolower($headers['origin'] ?? $headers['sec-websocket-origin'] ?? '');
                    if ($this->options->allowedOrigins !== [] && $origin !== '' && !in_array($origin, $this->options->allowedOrigins, true)) {
                        $this->rejected++;
                        $native->disconnect($fd, 1008, 'origin_not_allowed');
                        return;
                    }
                    $info = $native->getClientInfo($fd);
                    $mutual = $this->options->wssPort > 0 && $this->options->clientCa !== '';
                    $facts = $this->pendingCertificates[$fd] ?? ['fingerprint' => '', 'serial' => '', 'notAfter' => 0];
                    unset($this->pendingCertificates[$fd]);
                    if ($mutual && $facts['fingerprint'] === '') {
                        $facts = $this->certificateFromInfo(is_array($info) ? $info : []);
                    }
                    if ($mutual && $facts['fingerprint'] === '') {
                        $this->rejected++;
                        $native->disconnect($fd, 1008, 'client_certificate_required');
                        return;
                    }
                    $this->acceptNative($native, $fd, 'websocket', $this->options->wssPort > 0, is_array($info) ? $info : [], $facts['fingerprint'], $mutual, $facts['serial'], $facts['notAfter']);
                }, $fd);
            });
            $server->on('message', function (\Swoole\WebSocket\Server $native, \Swoole\WebSocket\Frame $frame): void {
                $this->nativeEvent('message', function (ExecutionScope $scope) use ($native, $frame): void {
                    $connection = $this->connections[$frame->fd] ?? null;
                    if ($connection === null || $connection->transport !== 'websocket' || !$connection->alive()) {
                        return;
                    }
                    if ($frame->opcode === WEBSOCKET_OPCODE_PING || $frame->opcode === WEBSOCKET_OPCODE_PONG
                        || $frame->opcode === WEBSOCKET_OPCODE_CLOSE) {
                        return;
                    }
                    if ($frame->opcode === WEBSOCKET_OPCODE_TEXT) {
                        $native->disconnect($frame->fd, 1003, 'mqtt_binary_required');
                        $connection->close();
                        return;
                    }
                    if ($connection->closing) {
                        return;
                    }
                    $this->ingest($connection, $frame->data);
                    if ($connection->output !== '') {
                        $this->flush($connection);
                    }
                }, $frame->fd, strlen($frame->data));
            });
            $server->on('request', function (\Swoole\Http\Request $request, \Swoole\Http\Response $response): void {
                $response->status(404);
                $response->end('not_found');
            });
        }
        $server->on('close', function (\Swoole\Server $native, int $fd, int $reactorId): void {
            $closingInstance = $this->closingNativeInstances[$fd] ?? 0;
            unset($this->closingNativeInstances[$fd]);
            if ($closingInstance !== 0 && ($this->nativeEpochs[$fd] ?? 0) !== $closingInstance) {
                return;
            }
            // 原生关闭立即撤销传输资格；观察与持久收尾仍由串行事件处理。
            unset($this->pendingCertificates[$fd], $this->nativeEpochs[$fd], $this->pendingFdEvents[$fd]);
            $connection = $this->connections[$fd] ?? null;
            if ($closingInstance !== 0 && ($connection === null || $connection->nativeInstance !== $closingInstance)) {
                return;
            }
            if ($connection === null || $connection->socket !== $fd) {
                return;
            }
            $connection->socket = null;
            $connection->native = null;
            $connection->closing = true;
            if ($connection->endedAt === 0.0) {
                $connection->endedAt = microtime(true);
            }
        });
        $server->on('workerExit', function (\Swoole\Server $native, int $workerId): void {
            $this->stopping = true;
            $this->nativeExiting = true;
            if ($this->eventTimer !== 0) {
                \Swoole\Timer::clear($this->eventTimer);
                $this->eventTimer = 0;
            }
            $this->stopClientCrlFetch();
        });
    }

    /**
     * 直接使用官方协程和 Channel，有界等待后才创建本次作用域。
     * 原生回调不自动开协程，故接纳计数先于 create；过载关闭当前连接，维护事件失败则停止角色。
     * @param \Closure(ExecutionScope): void $operation
     */
    private function nativeEvent(string $event, \Closure $operation, int $fd = 0, int $bytes = 0): void
    {
        if ($this->stopping || $this->pendingEvents >= $this->startupMaximumConnections + 32
            || $this->pendingEventBytes + $bytes > 33554432) {
            $this->eventQuotaRefusals++;
            if ($fd > 0) {
                $this->nativeServer?->close($fd, true);
            } else {
                $this->tickPending = false;
                $this->stop();
            }
            return;
        }
        $epoch = $fd > 0 ? ($this->nativeEpochs[$fd] ?? 0) : 0;
        $deadline = new Deadline($this->options->callbackSeconds);
        if ($fd > 0) {
            $this->pendingFdEvents[$fd] = ($this->pendingFdEvents[$fd] ?? 0) + 1;
            $this->nativeServer?->pause($fd);
        }
        $this->pendingEvents++;
        $this->pendingEventBytes += $bytes;
        $created = Coroutine::create(function () use ($event, $operation, $fd, $bytes, $epoch, $deadline): void {
            $acquired = false;
            try {
                if ($this->eventGate === null) {
                    $this->eventGate = new Channel(1);
                    $this->eventGate->push(true);
                }
                if ($deadline->expired()) {
                    throw new \RuntimeException('mqtt_callback_wait_timeout');
                }
                $acquired = $this->eventGate->pop((float) $deadline->remaining()) === true;
                if (!$acquired) {
                    throw new \RuntimeException('mqtt_callback_wait_timeout');
                }
                if ($this->stopping || ($fd > 0 && ($epoch === 0 || ($this->nativeEpochs[$fd] ?? 0) !== $epoch))) {
                    return;
                }
                $scope = new ExecutionScope($deadline, ['protocol' => 'mqtt', 'event' => $event, 'connection' => (string) $fd]);
                try {
                    $scope->run($operation);
                } finally {
                    try {
                        $scope->close();
                    } finally {
                        if ($scope->state() !== 'closed') {
                            $this->stop();
                            $scope->awaitClosed();
                        }
                    }
                }
            } catch (\Throwable) {
                $this->callbackFailures++;
                if ($fd > 0 && ($this->nativeEpochs[$fd] ?? 0) === $epoch) {
                    $this->nativeServer?->close($fd, true);
                } elseif ($fd === 0) {
                    $this->stop();
                }
            } finally {
                $this->completeNativeEvent($event, $fd, $bytes, $epoch, $acquired);
            }
        });
        if ($created === false) {
            $this->callbackFailures++;
            $this->completeNativeEvent($event, $fd, $bytes, $epoch, false);
            $this->stop();
        }
    }

    /** 正常、异常及创建失败共用额度归还；只有仍属同一接纳代次的连接才恢复读取。 */
    private function completeNativeEvent(string $event, int $fd, int $bytes, int $epoch, bool $acquired): void
    {
        $this->pendingEvents--;
        $this->pendingEventBytes -= $bytes;
        if ($fd > 0 && ($this->nativeEpochs[$fd] ?? 0) === $epoch) {
            $this->pendingFdEvents[$fd]--;
            if ($this->pendingFdEvents[$fd] === 0 && !$this->stopping) {
                $this->nativeServer?->resume($fd);
            }
        }
        if ($event === 'tick') {
            $this->tickPending = false;
        }
        if ($acquired) {
            $this->eventGate?->push(true);
        }
        if ($this->stopping && $this->pendingEvents === 0) {
            $this->stop();
        }
    }

    private function releaseNativeFd(int $fd): void
    {
        if (!isset($this->connections[$fd])) {
            return;
        }
        $existing = $this->connections[$fd];
        unset($this->connections[$fd]);
        if ($existing->nativeInstance !== 0) {
            $this->closingNativeInstances[$fd] = $existing->nativeInstance;
        }
        $existing->native = null;
        $existing->socket = null;
        $existing->close();
        $this->observeClosed($existing);
        $this->finishSession($existing);
        $this->closed++;
    }

    /** @param array<string, mixed> $info */
    private function acceptNative(\Swoole\Server $server, int $fd, string $transport, bool $secure, array $info, string $fingerprint = '', bool $mutualTls = false, string $serial = '', int $notAfter = 0): void
    {
        $this->releaseNativeFd($fd);
        if ($this->stopping || count($this->connections) + count($this->closingSessions) >= $this->startupMaximumConnections) {
            $this->connectionQuotaRefusals++;
            $this->rejected++;
            if ($transport === 'websocket' && $server instanceof \Swoole\WebSocket\Server) {
                $server->disconnect($fd, 1013, 'connection_quota');
            } else {
                $server->close($fd, true);
            }
            return;
        }
        if ($secure && $this->serverCertificateExpired(time())) {
            $this->rejected++;
            if ($transport === 'websocket' && $server instanceof \Swoole\WebSocket\Server) {
                $server->disconnect($fd, 1011, 'server_certificate_expired');
            } else {
                $server->close($fd, true);
            }
            return;
        }
        $peer = (string) ($info['remote_ip'] ?? '') . ':' . (string) ($info['remote_port'] ?? 0);
        $connection = new Connection($fd, $peer, $this->options->handshakeSeconds, $transport, $server);
        $connection->secure = $secure;
        $connection->mutualTls = $mutualTls || $fingerprint !== '';
        $connection->clientCertificateFingerprint = $fingerprint;
        $connection->clientCertificateSerial = $serial;
        $connection->clientCertificateNotAfter = $notAfter;
        $connection->nativeInstance = $this->nativeEpochs[$fd];
        $connection->noteNativeClosing = function (int $closedFd, int $instance): void {
            $this->closingNativeInstances[$closedFd] = $instance;
        };
        $this->connections[$fd] = $connection;
        $this->accepted++;
    }

    /**
     * @param array<string, mixed> $info
     * @return array{fingerprint:string,serial:string,notAfter:int}
     */
    private function certificateFromInfo(array $info): array
    {
        $pem = isset($info['ssl_client_cert']) && is_string($info['ssl_client_cert']) ? $info['ssl_client_cert'] : '';
        if ($pem === '' || $this->options->clientCa === '' || !$this->clientCertificateUsable($pem)) {
            return ['fingerprint' => '', 'serial' => '', 'notAfter' => 0];
        }
        $fingerprint = self::certificateFingerprint($pem);
        if ($fingerprint === '' || $this->overlapFinished($fingerprint, time())) {
            return ['fingerprint' => '', 'serial' => '', 'notAfter' => 0];
        }
        return ['fingerprint' => $fingerprint, 'serial' => $this->certificateSerial($pem), 'notAfter' => self::certificateNotAfter($pem)];
    }

    /**
     * Swoole 仍可能用过期或 clientAuth 叶证书监听。
     * @param array<string, mixed> $parsed openssl_x509_parse 长名结果。
     */
    private static function serverCertificateUsable(array $parsed): bool
    {
        if (!isset($parsed['validFrom_time_t'], $parsed['validTo_time_t'])
            || !is_int($parsed['validFrom_time_t']) || !is_int($parsed['validTo_time_t'])) {
            return false;
        }
        $now = time();
        if ($parsed['validFrom_time_t'] > $now || $parsed['validTo_time_t'] <= $now) {
            return false;
        }
        $usage = '';
        if (isset($parsed['extensions']) && is_array($parsed['extensions'])
            && isset($parsed['extensions']['extendedKeyUsage']) && is_string($parsed['extensions']['extendedKeyUsage'])) {
            $usage = $parsed['extensions']['extendedKeyUsage'];
        }
        return str_contains($usage, 'TLS Web Server Authentication') || str_contains($usage, 'serverAuth');
    }

    /** Swoole ssl_verify_peer 不拒绝过期、serverAuth、CRL 或平台吊销；此处按 SSL 客户端用途、有效期和已接纳吊销核对。 */
    private function clientCertificateUsable(string $pem): bool
    {
        $certificate = @openssl_x509_read($pem);
        if ($certificate === false || openssl_x509_checkpurpose($certificate, X509_PURPOSE_SSL_CLIENT, [$this->options->clientCa]) !== true) {
            return false;
        }
        if ($this->clientCrlClosed(time())) {
            return false;
        }
        $parsed = openssl_x509_parse($certificate, false);
        $serial = '';
        if (is_array($parsed) && isset($parsed['serialNumberHex']) && is_string($parsed['serialNumberHex'])) {
            $serial = CertificateRevocationList::serialHex($parsed['serialNumberHex']);
        }
        return !$this->revokesClientSerial($serial);
    }

    private function certificateSerial(string $pem): string
    {
        $certificate = @openssl_x509_read($pem);
        if ($certificate === false) {
            return '';
        }
        $parsed = openssl_x509_parse($certificate, false);
        if (!is_array($parsed) || !isset($parsed['serialNumberHex']) || !is_string($parsed['serialNumberHex'])) {
            return '';
        }
        return CertificateRevocationList::serialHex($parsed['serialNumberHex']);
    }

    private static function certificateNotAfter(string $pem): int
    {
        $certificate = @openssl_x509_read($pem);
        if ($certificate === false) {
            return 0;
        }
        $parsed = openssl_x509_parse($certificate, false);
        if (!is_array($parsed) || !isset($parsed['validTo_time_t'])) {
            return 0;
        }
        return is_int($parsed['validTo_time_t']) ? $parsed['validTo_time_t'] : 0;
    }

    /** 已验证 PEM 的 SHA-256；失败返回空串，不保留证书正文。 */
    private static function certificateFingerprint(string $pem): string
    {
        if ($pem === '') {
            return '';
        }
        $certificate = @openssl_x509_read($pem);
        if ($certificate === false) {
            return '';
        }
        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256', false);
        if (!is_string($fingerprint)) {
            return '';
        }
        $fingerprint = strtolower(str_replace(':', '', $fingerprint));
        return preg_match('/^[0-9a-f]{64}$/D', $fingerprint) === 1 ? $fingerprint : '';
    }

    /** 客户端提供的 mqtt 子协议必须逐个精确匹配；原生会无条件回显配置值。 */
    private static function offersMqtt(array $headers): bool
    {
        $offered = $headers['sec-websocket-protocol'] ?? '';
        if ($offered === '') {
            return false;
        }
        foreach (explode(',', $offered) as $candidate) {
            if (trim($candidate) === 'mqtt') {
                return true;
            }
        }
        return false;
    }

    private function ingest(Connection $connection, string $bytes): void
    {
        $offset = 0;
        // TCP/WS 的一次读取可同时包含最大报文和下一报文；上限约束未解析缓冲，不约束读取总长度。
        while ($offset < strlen($bytes) && !$connection->closing) {
            $capacity = $this->options->maximumPacketBytes - strlen($connection->input);
            if ($capacity <= 0) {
                $this->disconnect($connection, 0x95);
                return;
            }
            if ($connection->input === '') {
                $connection->partial = new Deadline($this->options->partialPacketSeconds);
            }
            $chunk = substr($bytes, $offset, $capacity);
            $offset += strlen($chunk);
            $connection->input .= $chunk;
            $this->packets($connection);
        }
    }

    private function shutdownNative(): void
    {
        $this->stopClientCrlFetch();
        if ($this->eventTimer !== 0 && $this->nativeServer !== null) {
            \Swoole\Timer::clear($this->eventTimer);
            $this->eventTimer = 0;
        }
        if ($this->nativeServer !== null) {
            try {
                $this->nativeServer->shutdown();
            } catch (\Throwable) {
            }
            $this->nativeServer = null;
        }
        $this->forgetNativePrivateKey();
    }

    /** Swoole 6.2 Server::set 不把 ssl_passphrase 写入 SSLContext，加密钥只能先解密成 0600 临时文件。 */
    private function materializeNativePrivateKey(): string
    {
        $key = @openssl_pkey_get_private((string) file_get_contents($this->options->privateKey), $this->options->privateKeyPassphrase);
        $exported = '';
        if ($key === false || !openssl_pkey_export($key, $exported) || $exported === '') {
            throw new \RuntimeException('MQTT TLS 证书或私钥无效、口令错误或二者不匹配');
        }
        $path = dirname($this->options->privateKey) . '/.mqtt-native-' . bin2hex(random_bytes(8)) . '.key';
        if (file_put_contents($path, $exported) !== strlen($exported) || !chmod($path, 0600)) {
            @unlink($path);
            throw new \RuntimeException('MQTT 无法写出原生监听私钥');
        }
        return $path;
    }

    /** 退出时删除解密私钥，不把口令或明文钥留在配置路径。 */
    private function forgetNativePrivateKey(): void
    {
        if ($this->nativeKeyFile !== '' && str_contains(basename($this->nativeKeyFile), '.mqtt-native-') && is_file($this->nativeKeyFile)) {
            @unlink($this->nativeKeyFile);
        }
        $this->nativeKeyFile = '';
    }

    /** @return array<string,int|bool> 连接、缓冲和持久操作计数，不包含凭据及客户端标识。 */
    public function statistics(): array
    {
        $reservedServices = $this->classify === null ? 0 : min($this->maximumServiceConnections, max(0, $this->maximumConnections - 1));
        $subscriptions = 0;
        $buffered = 0;
        $retainedQueued = 0;
        $retainedPending = 0;
        $retainedBytes = 0;
        $devices = 0;
        $services = 0;
        $incoming = 0;
        $outgoing = 0;
        $events = count($this->connections);
        $pendingReads = 0;
        foreach ($this->connections as $connection) {
            $devices += $connection->capacityClass === 'device' && !$connection->closing ? 1 : 0;
            $services += $connection->capacityClass === 'application' && !$connection->closing ? 1 : 0;
            $incoming += count($connection->incoming) + count($connection->incomingQos2);
            $outgoing += count($connection->outgoing);
            $subscriptions += count($connection->subscriptions);
            $buffered += strlen($connection->input) + strlen($connection->output);
            $retainedQueued += count($connection->retainedTopics) + ($connection->retainedMessage === null ? 0 : 1);
            $retainedPending += $connection->retainedPending ? 1 : 0;
            if ($connection->retainedMessage !== null) {
                $retainedBytes += strlen($connection->retainedMessage->topic) + strlen($connection->retainedMessage->payload)
                    + strlen($connection->retainedMessage->properties);
            }
        }
        return ['connections' => count($this->connections), 'accepted' => $this->accepted,
            'rejected' => $this->rejected, 'closed' => $this->closed, 'stopping' => $this->stopping, 'observationFailures' => $this->observationFailures,
            'subscriptions' => $subscriptions, 'bufferedBytes' => $buffered, 'retainedQueued' => $retainedQueued, 'retainedPending' => $retainedPending,
            'retainedBytes' => $retainedBytes, 'deviceConnections' => $devices, 'serviceConnections' => $services,
            'maximumConnections' => $this->maximumConnections,
            'maximumDeviceConnections' => min($this->maximumDeviceConnections, $this->maximumConnections - $reservedServices),
            'maximumServiceConnections' => $reservedServices,
            'quotaRevision' => $this->appliedQuotaVersion,
            'incomingExchanges' => $incoming, 'outgoingExchanges' => $outgoing,
            'eventRegistrations' => $events, 'eventTimers' => $this->eventTimer === 0 ? 0 : 1,
            'readyEvents' => $this->pendingEvents, 'pendingReads' => $pendingReads,
            'pendingEventBytes' => $this->pendingEventBytes, 'callbackFailures' => $this->callbackFailures,
            'eventQuotaRefusals' => $this->eventQuotaRefusals,
            'connectionQuotaRefusals' => $this->connectionQuotaRefusals, 'packetQuotaRefusals' => $this->packetQuotaRefusals,
            'subscriptionQuotaRefusals' => $this->subscriptionQuotaRefusals, 'commitQuotaRefusals' => $this->commitQuotaRefusals,
            'flushTimeouts' => $this->flushTimeouts, 'handshakeTimeouts' => $this->handshakeTimeouts,
            'processMemoryBytes' => memory_get_usage(true), 'processPeakMemoryBytes' => memory_get_peak_usage(true),
            'invalidated' => $this->invalidated, 'invalidationFailures' => $this->invalidationFailures,
            'invalidationPending' => $this->invalidation !== null,
            'disconnected' => $this->disconnected, 'disconnectFailures' => $this->disconnectFailures,
            'disconnectPending' => $this->disconnectOp !== null,
            'terminated' => $this->terminated, 'terminationFailures' => $this->terminationFailures,
            'terminationPending' => $this->terminateOp !== null,
            'retainedCleared' => $this->retainedCleared, 'retainClearFailures' => $this->retainClearFailures,
            'retainClearPending' => $this->clearOp !== null,
            'quotaFailures' => $this->quotaFailures,
            'handshakeCaReloads' => $this->handshakeCaReloads, 'handshakeCaFailures' => $this->handshakeCaFailures,
            'nodeGeneration' => $this->nodeGeneration, 'nodePending' => $this->nodePending, 'nodeFailures' => $this->nodeFailures,
            'pendingFences' => count($this->nodeFences),
            'delivered' => $this->delivered, 'dropped' => $this->dropped,
            'pendingCommits' => count($this->commits), 'durableCommits' => $this->durableCommits,
            'rejectedCommits' => $this->rejectedCommits, 'unknownCommits' => $this->unknownCommits,
            'quarantinedCommits' => $this->quarantinedCommits, 'closingSessions' => count($this->closingSessions),
            'willPending' => $this->willPending, 'publishedWills' => $this->publishedWills, 'deniedWills' => $this->deniedWills, 'failedWills' => $this->failedWills];
    }

    /**
     * 以调用方已经认证的发布身份执行 Topic 授权，向当前合法订阅排队 QoS 0 消息。
     * 返回排队数，不代表到达、持久保存或业务完成；网络入口按实际线长检查后复用路由。
     * 使用 IdentityAccessPolicy 时调用方同时提供已经认证的 identity；缺失拒绝，不能从协议字段猜测。
     * @throws ProtocolError 未获授权、授权依赖异常或消息超过服务端完整报文上限。
     */
    public function publish(ConnectPacket $publisher, Message $message, ?AccessIdentity $identity = null): int
    {
        if ($message->qos !== 0) {
            throw new ProtocolError(0x9b);
        }
        if ($message->retain) {
            // 同步排队计数不能冒充持久保留接管；网络保留发布经过有界 worker。
            throw new ProtocolError(0x9a);
        }
        if (strlen($message->packet($publisher->version)) > $this->options->maximumPacketBytes) {
            throw new ProtocolError(0x95);
        }
        return $this->route($publisher, $message, $identity);
    }

    /** 网络入口已校验实际完整报文；展开别名后的 Topic 不再作为入站线长重新拒绝。 */
    private function route(ConnectPacket $publisher, Message $message, ?AccessIdentity $identity): int
    {
        if (!$this->authorized($publisher, $message->topic, 'publish', 0, $identity)) {
            throw new ProtocolError(0x87);
        }
        if ($message->remaining() === 0) {
            $this->dropped++;
            return 0;
        }
        $delivered = 0;
        foreach ($this->connections as $connection) {
            if (!$connection->connected || $connection->closing) {
                continue;
            }
            try {
                $selection = $this->selection($connection, $message, $publisher->clientId);
            } catch (ProtocolError) {
                $this->dropped++;
                continue;
            }
            if ($selection['qos'] < 0) {
                continue;
            }
            // MQTT 5 §3.1.2.11.5：超出接收者 Maximum Packet Size 的 PUBLISH 丢弃，不截断或伪造 ACK。
            if ($connection->publication(
                $message,
                0,
                0,
                $this->options->maximumPacketBytes,
                true,
                false,
                $message->retain && $connection->connect->version === 5 && $selection['retain'],
                false,
                $selection['identifiers']
            ) !== '') {
                $delivered++;
            } else {
                $this->dropped++;
            }
        }
        $delivered += $this->routeShared($message);
        $this->delivered += $delivered;
        return $delivered;
    }

    /** QoS 0 不保存离线副本；每个完整共享身份独立选择第一个授权且能容纳完整包的在线成员。 */
    private function routeShared(Message $message): int
    {
        $groups = [];
        $delivered = 0;
        foreach ($this->connections as $connection) {
            if (!$connection->connected || $connection->closing || $connection->sessionPending) {
                continue;
            }
            foreach ($connection->subscriptions as $key => $subscription) {
                $filter = substr($key, 2);
                if (!str_starts_with($filter, '$share/') || isset($groups[$key])) {
                    continue;
                }
                try {
                    if (!TopicFilter::matches(TopicFilter::actual($filter), $message->topic)
                        || !$this->subscriptionAuthorized($connection, $filter, $subscription['options'] & 3)
                        || !$this->authorized($connection->connect, $message->topic, 'subscribe', 0, $connection->identity)) {
                        continue;
                    }
                    if ($connection->publication(
                        $message,
                        0,
                        0,
                        $this->options->maximumPacketBytes,
                        true,
                        false,
                        $message->retain && ($subscription['options'] & 8) !== 0,
                        false,
                        $subscription['identifier'] > 0 ? [$subscription['identifier']] : []
                    ) !== '') {
                        $groups[$key] = true;
                        $delivered++;
                    }
                } catch (ProtocolError) {
                    $this->dropped++;
                }
            }
        }
        return $delivered;
    }

    /** 共享名称不扩大权限；只去除一次外层，按实际 Topic Filter 复用公开授权接口。 */
    private function subscriptionAuthorized(Connection $connection, string $filter, int $qos): bool
    {
        return (!str_starts_with($filter, '$share/') || $connection->connect->version === 5)
            && $this->authorized($connection->connect, TopicFilter::actual($filter), 'subscribe', $qos, $connection->identity);
    }

    private function authorized(ConnectPacket $connect, string $topic, string $action, int $qos, ?AccessIdentity $identity, bool $legacyAuthenticated = false): bool
    {
        try {
            if ($this->access instanceof IdentityAccessPolicy) {
                return $identity !== null ? $this->access->authorizeIdentity($identity, $connect, $topic, $action, $qos)
                    : ($legacyAuthenticated && $this->access->authorize($connect, $topic, $action, $qos));
            }
            return $this->access->authorize($connect, $topic, $action, $qos);
        } catch (\Throwable) {
            throw new ProtocolError(0x83);
        }
    }

    /** 过滤器与实际 Topic 分别授权；拒绝一个具体 Topic 不会删除仍合法的整个通配订阅。 */
    private function selection(Connection $connection, Message $message, string $publisherId): array
    {
        $subscriptions = [];
        foreach ($connection->subscriptions as $key => $subscription) {
            $filter = substr($key, 2);
            if (!str_starts_with($filter, '$share/') && TopicFilter::matches($filter, $message->topic)
                && $this->authorized($connection->connect, $filter, 'subscribe', $subscription['options'] & 3, $connection->identity)
                && $this->authorized($connection->connect, $message->topic, 'subscribe', min($message->qos, $subscription['options'] & 3), $connection->identity)) {
                $subscriptions[$key] = $subscription;
            }
        }
        return TopicFilter::select($subscriptions, $message->topic, $connection->connect->clientId === $publisherId);
    }

    /** 未完成重复交换合并；同源QoS0直投等待前项时复用目标快照，但不派生持久副本。 */
    private function publishReliable(Connection $publisher, Message $message, int $identifier, bool $duplicate, string $willId = ''): void
    {
        // 旧版已认证遗嘱没有新快照，仍按原契约重新授权；网络CONNECT不能进入此兼容分支。
        if (!$this->authorized($publisher->connect, $message->topic, 'publish', $message->qos, $publisher->identity, $willId !== '')) {
            throw new ProtocolError(0x87);
        }
        if ($message->qos > 0 && isset($publisher->incomingQos2[$identifier])) {
            $exchange = $publisher->incomingQos2[$identifier];
            if ($message->qos !== 2 || !in_array($exchange['state'], ['accepting', 'received'], true)) {
                throw new ProtocolError(0x91);
            }
            // MQTT 5 §4.3.3-10：同一未释放标识的 PUBLISH 不生成第二份交付，不以 DUP 或载荷推断新消息。
            if ($exchange['state'] === 'received') {
                $publisher->send("\x50\x02" . pack('n', $identifier));
            } elseif ($exchange['responses'] >= 32) {
                throw new ProtocolError(0x97);
            } else {
                $publisher->incomingQos2[$identifier]['responses']++;
            }
            return;
        }
        $fingerprint = hash('sha256', pack('n', strlen($message->topic)) . $message->topic
            . pack('N', strlen($message->properties)) . $message->properties . $message->payload . ($message->retain ? "\x01" : "\x00"));
        if ($message->qos > 0 && isset($publisher->incoming[$identifier])) {
            if ($message->qos !== 1 || !$duplicate || !hash_equals($publisher->incoming[$identifier], $fingerprint)) {
                throw new ProtocolError(0x91);
            }
            return;
        }
        if ($message->qos > 0 && count($publisher->incoming) + count($publisher->incomingQos2) >= 32) {
            throw new ProtocolError(0x93);
        }
        if (count($this->commits) + $this->quarantinedCommits >= 32) {
            throw new ProtocolError(0x97);
        }
        $operationId = bin2hex(random_bytes(16));
        $messageId = $willId === '' ? $operationId : $willId;
        $targets = [];
        $deliveries = [];
        $queuedSessions = [];
        $excludedSessions = [];
        try {
            foreach ($this->connections as $subscriber) {
                // 集群在线与离线目的统一由接管事务选择；不把万条连接身份装入512项交付请求。
                // 实际发送仍由接收节点复核授权、包大小及当前所有者。
                if ($this->options->clustered) {
                    break;
                }
                if (!$subscriber->connected || $subscriber->closing || $message->remaining() === 0) {
                    continue;
                }
                if ($subscriber->sessionPending) {
                    $queuedSessions[] = $subscriber->sessionId;
                    continue;
                }
                $selection = $this->selection($subscriber, $message, $publisher->connect->clientId);
                if ($selection['qos'] < 0 && TopicFilter::select($subscriber->subscriptions, $message->topic, $subscriber->connect->clientId === $publisher->connect->clientId)['qos'] < 0) {
                    // 尚无匹配订阅时交由接管事务决定；等待中的发布不能越过之后安装的订阅切点。
                    $queuedSessions[] = $subscriber->sessionId;
                    continue;
                }
                // Store 不得把在线授权或大小拒绝的接收者再次作为离线目的排队。
                $excludedSessions[] = $subscriber->sessionId;
                if ($selection['qos'] < 0) {
                    continue;
                }
                $qos = min($message->qos, $selection['qos']);
                $retain = $message->retain && $subscriber->connect->version === 5 && $selection['retain'];
                if ($subscriber->publication($message, $qos > 0 ? 1 : 0, $qos, $this->options->maximumPacketBytes, false, false, $retain, false, $selection['identifiers']) === '') {
                    $this->dropped++;
                    continue;
                }
                $deliveryId = hash('sha256', $messageId . $subscriber->sessionId);
                if ($qos > 0 && ($subscriber->sessionExpiry !== 0 || $subscriber->sessionPresent) && ($subscriber->draining || $subscriber->replaying
                    || count($subscriber->outgoing) >= min(32, (int) ($subscriber->connect->properties[0x21] ?? 65535)))) {
                    $subscriber->draining = true;
                    $queuedSessions[] = $subscriber->sessionId;
                    $deliveries[] = ['id' => $deliveryId, 'session_id' => $subscriber->sessionId, 'client_id' => $subscriber->connect->clientId,
                        'owner_id' => $subscriber->ownerId,
                        'packet_id' => 0, 'qos' => $qos, 'retain' => $retain, 'subscription_identifiers' => $selection['identifiers']];
                    continue;
                }
                $packetId = $qos > 0 ? $subscriber->reserve($deliveryId, $qos) : 0;
                $targets[] = ['connection' => $subscriber, 'id' => $deliveryId, 'packet_id' => $packetId, 'qos' => $qos,
                    'retain' => $retain, 'subscription_identifiers' => $selection['identifiers']];
                $deliveries[] = ['id' => $deliveryId, 'session_id' => $subscriber->sessionId, 'client_id' => $subscriber->connect->clientId,
                    'owner_id' => $subscriber->ownerId,
                    'packet_id' => $packetId, 'qos' => $qos, 'retain' => $retain, 'subscription_identifiers' => $selection['identifiers']];
            }
            $request = ['operation_id' => $operationId, 'action' => 'accept', 'session_id' => $publisher->sessionId, 'owner_id' => $publisher->ownerId,
                'route_sessions' => true, 'queued_sessions' => $queuedSessions, 'excluded_sessions' => $excludedSessions, 'message' => ['id' => $messageId,
                'session_id' => $publisher->sessionId, 'client_id' => $publisher->connect->clientId, 'packet_id' => $identifier,
                'topic' => $message->topic, 'payload' => base64_encode($message->payload), 'properties' => base64_encode($message->properties), 'qos' => $message->qos,
                'retain' => $message->retain, 'expires_at' => $message->expiresAt()],
                'deliveries' => $deliveries];
            if ($this->options->clustered) {
                $request['node_id'] = $this->nodeId;
                $request['node_run_id'] = $this->nodeRunId;
            }
            if ($willId !== '') {
                $request['action'] = 'will_accept';
                $request['will_id'] = $willId;
                if ($this->options->clustered) {
                    $request['node_id'] = $this->nodeId;
                    $request['node_run_id'] = $this->nodeRunId;
                }
                unset($request['owner_id']);
            }
            // 同一来源按接收顺序启动事务；数据库抢锁与子进程结束次序都不能作为 Topic 顺序。
            // 待启动项也占既有32项额度，最多等待5秒，再复用worker及精确清理的独立截止。
            $durable = $this->options->clustered || $message->qos > 0 || $message->retain || $willId !== '';
            $this->commits[$operationId] = ['pending' => null, 'request' => $durable ? $request : null, 'queued' => new Deadline(5.0),
                'kind' => $durable ? 'accept' : 'route',
                'publisher' => $publisher, 'identifier' => $identifier, 'message' => $message, 'targets' => $targets, 'will_id' => $willId];
            if ($willId !== '') {
                $this->willPending = true;
            } elseif ($message->qos === 2) {
                $publisher->incomingQos2[$identifier] = ['id' => $operationId, 'state' => 'accepting', 'responses' => 1];
            } elseif ($message->qos === 1) {
                $publisher->incoming[$identifier] = $fingerprint;
            }
        } catch (\Throwable $failure) {
            foreach ($targets as $target) {
                unset($target['connection']->outgoing[$target['packet_id']]);
            }
            throw $failure instanceof ProtocolError ? $failure : new ProtocolError(0x88);
        }
    }

    /** PUBACK 是发送方向的终结事实；当前连接不自动重传，标识在持久登记后归还。 */
    private function acknowledged(Connection $connection, string $payload): void
    {
        $reader = new PacketReader($payload);
        $identifier = $reader->integer(2);
        $reason = 0;
        if ($connection->connect->version === 5 && $reader->remaining() > 0) {
            $reason = $reader->integer(1);
            if (!in_array($reason, [0, 0x10, 0x80, 0x83, 0x87, 0x90, 0x91, 0x97, 0x99], true)) {
                throw new ProtocolError(0x82);
            }
            if ($reader->remaining() > 0) {
                $reader->properties('puback');
            }
        }
        if ($reader->remaining() !== 0 || $identifier === 0 || !isset($connection->outgoing[$identifier])) {
            throw new ProtocolError(0x82);
        }
        $delivery = $connection->outgoing[$identifier];
        if ($delivery['qos'] !== 1) {
            throw new ProtocolError(0x82);
        }
        if ($delivery['state'] === 'completing') {
            return;
        }
        if ($delivery['state'] !== 'sent' || !$this->completeDelivery($connection, $delivery['id'], $identifier, $reason)) {
            throw new ProtocolError(0x97);
        }
        $connection->outgoing[$identifier]['state'] = 'completing';
    }

    /** 解析 QoS 2 确认；两版共享非零标识，只有 MQTT 5 能携带原因及属性。 */
    private function qos2Acknowledgement(Connection $connection, int $head, string $payload): void
    {
        $reader = new PacketReader($payload);
        $identifier = $reader->integer(2);
        $reason = 0;
        if ($connection->connect->version === 5 && $reader->remaining() > 0) {
            $reason = $reader->integer(1);
            $allowed = $head === 0x50 ? [0, 0x10, 0x80, 0x83, 0x87, 0x90, 0x91, 0x97, 0x99] : [0, 0x92];
            if (!in_array($reason, $allowed, true)) {
                throw new ProtocolError(0x82);
            }
            if ($reader->remaining() > 0) {
                $reader->properties(match ($head) {
                    0x50 => 'pubrec', 0x62 => 'pubrel', default => 'pubcomp'
                });
            }
        }
        if ($identifier === 0 || $reader->remaining() !== 0) {
            throw new ProtocolError(0x82);
        }
        if ($head === 0x62) {
            $this->releasedMessage($connection, $identifier, $reason);
            return;
        }
        if (!isset($connection->outgoing[$identifier])) {
            // PUBREC 找不到本地交换时仍用标准 PUBREL 完成对方清理，不派生 PUBLISH。
            if ($head === 0x50) {
                if ($reason < 0x80) {
                    $connection->send($connection->connect->version === 5
                        ? "\x62\x03" . pack('n', $identifier) . "\x92" : "\x62\x02" . pack('n', $identifier));
                }
                return;
            }
            // 已终结的 PUBCOMP 无额外响应；不能据此重用任何其他标识。
            return;
        }
        $delivery = $connection->outgoing[$identifier];
        if ($delivery['qos'] !== 2) {
            throw new ProtocolError(0x82);
        }
        if ($head === 0x50) {
            if ($delivery['state'] === 'receiving') {
                if ($reason >= 0x80) {
                    throw new ProtocolError(0x82);
                }
                if ($delivery['responses'] >= 32) {
                    throw new ProtocolError(0x97);
                }
                $connection->outgoing[$identifier]['responses']++;
                return;
            }
            if ($delivery['state'] === 'completing') {
                return;
            }
            if ($delivery['state'] === 'released') {
                if ($reason >= 0x80) {
                    throw new ProtocolError(0x82);
                }
                $connection->send("\x62\x02" . pack('n', $identifier));
                return;
            }
            if ($delivery['state'] !== 'sent') {
                throw new ProtocolError(0x82);
            }
            if ($reason >= 0x80) {
                if (!$this->completeDelivery($connection, $delivery['id'], $identifier, $reason, 'rejected')) {
                    throw new ProtocolError(0x97);
                }
                $connection->outgoing[$identifier]['state'] = 'completing';
            } else {
                $this->qos2Commit($connection, $identifier, ['action' => 'received', 'delivery_id' => $delivery['id']], 'received');
                $connection->outgoing[$identifier]['state'] = 'receiving';
            }
        } else {
            if ($delivery['state'] === 'completing') {
                return;
            }
            if ($delivery['state'] !== 'released') {
                throw new ProtocolError(0x82);
            }
            if (!$this->completeDelivery($connection, $delivery['id'], $identifier, $reason, $reason === 0 ? 'acknowledged' : 'not_found')) {
                throw new ProtocolError(0x97);
            }
            $connection->outgoing[$identifier]['state'] = 'completing';
        }
    }

    private function releasedMessage(Connection $connection, int $identifier, int $reason): void
    {
        if (!isset($connection->incomingQos2[$identifier])) {
            if (isset($connection->incoming[$identifier])) {
                throw new ProtocolError(0x82);
            }
            $connection->send($connection->connect->version === 5
                ? "\x70\x03" . pack('n', $identifier) . "\x92" : "\x70\x02" . pack('n', $identifier));
            return;
        }
        $exchange = $connection->incomingQos2[$identifier];
        if ($exchange['state'] === 'completing') {
            $connection->acknowledge($identifier, 0x70);
            return;
        }
        if ($exchange['state'] === 'releasing') {
            if ($exchange['responses'] >= 32) {
                throw new ProtocolError(0x97);
            }
            $connection->incomingQos2[$identifier]['responses']++;
            return;
        }
        if ($exchange['state'] !== 'received') {
            throw new ProtocolError(0x82);
        }
        $this->qos2Commit($connection, $identifier, ['action' => 'release', 'message_id' => $exchange['id'],
            'session_id' => $connection->sessionId, 'reason' => $reason], 'release');
        $connection->incomingQos2[$identifier]['state'] = 'releasing';
        $connection->incomingQos2[$identifier]['responses'] = 1;
    }

    /** 复用同一有界 worker 和未知提交清理；所有成功握手推进均等待同步证明。 */
    private function qos2Commit(Connection $connection, int $identifier, array $request, string $kind): void
    {
        if (count($this->commits) + $this->quarantinedCommits >= 32) {
            throw new ProtocolError(0x97);
        }
        $operationId = bin2hex(random_bytes(16));
        $request['operation_id'] = $operationId;
        $request['session_id'] = $connection->sessionId;
        $request['owner_id'] = $connection->ownerId;
        try {
            $this->commits[$operationId] = ['pending' => new PendingCommit($this->workerCommand, $request),
                'kind' => $kind, 'connection' => $connection, 'identifier' => $identifier];
        } catch (\Throwable) {
            throw new ProtocolError(0x88);
        }
    }

    private function completeDelivery(Connection $connection, string $deliveryId, int $identifier, int $reason, string $outcome = 'acknowledged'): bool
    {
        if (count($this->commits) + $this->quarantinedCommits >= 32) {
            return false;
        }
        $operationId = bin2hex(random_bytes(16));
        try {
            $this->commits[$operationId] = ['pending' => new PendingCommit(
                $this->workerCommand,
                ['operation_id' => $operationId, 'action' => 'complete', 'delivery_id' => $deliveryId, 'reason' => $reason, 'outcome' => $outcome,
                    'session_id' => $connection->sessionId, 'owner_id' => $connection->ownerId]
            ),
                'kind' => 'complete', 'connection' => $connection, 'identifier' => $identifier];
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function finishSession(Connection $connection): void
    {
        if ($connection->sessionOpened || (!$connection->sessionManaged && $connection->hadDeliveries)) {
            $this->closingSessions[$connection->ownerId] = $connection;
            $connection->sessionOpened = false;
            $connection->hadDeliveries = false;
        }
    }

    /** 先观察已有结果再释放本地容量；未知持久写不重试，清洁会话终结另有独立事实。 */
    private function pollCommits(): void
    {
        $publishing = [];
        foreach ($this->commits as $operationId => $commit) {
            $result = null;
            if ($commit['pending'] === null) {
                if ($commit['kind'] === 'route') {
                    if ($this->stopping || $commit['queued']->expired()) {
                        unset($this->commits[$operationId]);
                        $this->cancelPublications($commit['publisher']);
                        $this->disconnect($commit['publisher'], 0x88);
                        $this->dropped++;
                    } elseif (!isset($publishing[$commit['publisher']->ownerId])) {
                        unset($this->commits[$operationId]);
                        $this->deliverMessage($commit, null);
                    }
                    continue;
                }
                if ($this->stopping || $commit['queued']->expired()) {
                    // 尚未启动的请求没有外部提交，释放其预留但不能冒称未知持久写。
                    $result = new CommitResult($operationId, 'rejected', 0x88, [], true);
                } elseif (isset($publishing[$commit['publisher']->ownerId])) {
                    continue;
                } else {
                    try {
                        $commit['pending'] = new PendingCommit($this->workerCommand, $commit['request']);
                        unset($commit['request']);
                        $this->commits[$operationId] = $commit;
                    } catch (\Throwable) {
                        $result = new CommitResult($operationId, 'rejected', 0x88, [], true);
                    }
                }
            }
            if ($result === null) {
                $result = $commit['pending']->poll();
            }
            if ($result === null) {
                if ($commit['kind'] === 'accept') {
                    $publishing[$commit['publisher']->ownerId] = true;
                }
                continue;
            }
            unset($this->commits[$operationId]);
            if (!$result->released) {
                $this->quarantinedCommits++;
            }
            if ($result->state === 'committed' && $result->released) {
                $this->durableCommits++;
            } elseif ($result->state === 'rejected') {
                $this->rejectedCommits++;
                $this->commitQuotaRefusals += $result->reason === 0x97 ? 1 : 0;
            } else {
                $this->unknownCommits++;
            }
            if ($commit['kind'] === 'node_poll') {
                $this->nodePending = false;
                if ($result->state !== 'committed' || !$result->released || $this->quarantinedCommits !== 0) {
                    if (!$this->stopping) {
                        $this->failNode();
                    }
                } else {
                    $this->nodeFences = $result->value['fences'];
                    $this->nodeDeliveryCursor = $result->value['delivery_cursor'];
                    $readyOwners = array_fill_keys(array_column($result->value['ready_owners'], 'owner_id'), true);
                    foreach ($this->connections as $readyConnection) {
                        if (isset($readyOwners[$readyConnection->ownerId])) {
                            $readyConnection->draining = true;
                        }
                    }
                    foreach ($this->nodeFences as $fence) {
                        foreach ($this->connections as $fencedConnection) {
                            if ($fencedConnection->ownerId === $fence['owner_id']) {
                                $fencedConnection->closeReason = 0x8e;
                                $fencedConnection->endReason = 'taken_over';
                                $fencedConnection->close();
                            }
                        }
                    }
                }
            } elseif ($commit['kind'] === 'invalidation') {
                $this->invalidationPending = false;
                if ($result->state === 'committed' && $result->released && $this->quarantinedCommits === 0) {
                    if ($result->value['waiting'] ?? false) {
                        $this->invalidationAt = (float) hrtime(true) / 1000000000.0 + 0.25;
                    } else {
                        try {
                            $this->invalidations->invalidationCompleted($this->invalidation['id']);
                            $this->invalidation = null;
                            $this->invalidated++;
                        } catch (\Throwable) {
                            $this->failInvalidation();
                        }
                    }
                } else {
                    // 意图仍在来源中；停止后由重启恢复重复执行精确终止，未知不能标为完成。
                    $this->failInvalidation();
                }
            } elseif ($commit['kind'] === 'termination') {
                $this->terminateStorePending = false;
                if ($this->terminateOp === null || $this->disconnects === null) {
                    $this->failTermination();
                } elseif ($result->state === 'committed' && $result->released && $this->quarantinedCommits === 0) {
                    if (($result->value['terminated'] ?? false) === true) {
                        $this->terminateErased = true;
                    }
                    if ($result->value['waiting'] ?? false) {
                        $this->terminateAt = (float) hrtime(true) / 1000000000.0 + 0.25;
                    } else {
                        $outcome = $this->terminateErased ? 'terminated' : 'missing';
                        try {
                            if ($this->disconnects->terminationCompleted($this->terminateOp['id'], $outcome)) {
                                $this->terminateOp = null;
                                $this->terminateMatched = false;
                                $this->terminateErased = false;
                                $this->terminateOutcome = '';
                                $this->terminated++;
                            } else {
                                $this->terminateOutcome = $outcome;
                                $this->terminateAt = (float) hrtime(true) / 1000000000.0 + 0.25;
                            }
                        } catch (\Throwable) {
                            $this->failTermination();
                        }
                    }
                } else {
                    $this->failTermination();
                }
            } elseif ($commit['kind'] === 'clearance') {
                $this->clearStorePending = false;
                if ($this->clearOp === null || $this->disconnects === null) {
                    $this->failClearance();
                } elseif ($result->state === 'committed' && $result->released && $this->quarantinedCommits === 0) {
                    if (($result->value['cleared'] ?? false) === true) {
                        $this->clearErased = true;
                    }
                    if ($result->value['waiting'] ?? false) {
                        $this->clearAt = (float) hrtime(true) / 1000000000.0 + 0.25;
                    } else {
                        $outcome = $this->clearErased ? 'cleared' : 'missing';
                        try {
                            if ($this->disconnects->clearanceCompleted($this->clearOp['id'], $outcome)) {
                                $this->clearOp = null;
                                $this->clearErased = false;
                                $this->clearOutcome = '';
                                $this->retainedCleared++;
                            } else {
                                $this->clearOutcome = $outcome;
                                $this->clearAt = (float) hrtime(true) / 1000000000.0 + 0.25;
                            }
                        } catch (\Throwable) {
                            $this->failClearance();
                        }
                    }
                } else {
                    $this->failClearance();
                }
            } elseif ($commit['kind'] === 'quota') {
                $this->quotaStorePending = false;
                if ($this->quotaOp === null) {
                    $this->quotaFailures++;
                } elseif ($result->state === 'committed' && $result->released && $this->quarantinedCommits === 0) {
                    $this->appliedQuotaVersion = $this->quotaOp['version'];
                    $this->quotaOp = null;
                } else {
                    $this->quotaFailures++;
                    $this->quotaOp = null;
                    $this->quotaAt = (float) hrtime(true) / 1000000000.0 + 1.0;
                }
            } elseif ($commit['kind'] === 'ending') {
                if (($result->state !== 'committed' || !$result->released) && !$this->stopping && $result->released) {
                    $closing = $commit['connection'];
                    $closing->closeRetryAt = (float) hrtime(true) / 1000000000.0 + 1.0;
                    $this->closingSessions[$closing->ownerId] = $closing;
                }
            } elseif (str_starts_with($commit['kind'], 'will_')) {
                $this->willResult($commit, $result);
            } elseif ($commit['kind'] === 'retained_read') {
                $this->retainedReadResult($commit, $result);
            } elseif ($commit['kind'] === 'retained_advance') {
                $connection = $commit['connection'];
                $connection->retainedPending = false;
                if ($result->state !== 'committed' || !$result->released) {
                    $this->disconnect($connection, $result->reason ?: 0x88);
                } else {
                    $this->retainedProgress($connection, $commit['advance']);
                }
            } elseif ($commit['kind'] === 'retained') {
                $this->retainedResult($commit, $result);
            } elseif (str_starts_with($commit['kind'], 'shared_')) {
                $this->sharedResult($commit, $result);
            } elseif (str_starts_with($commit['kind'], 'session_')) {
                $this->sessionResult($commit, $result);
            } elseif ($commit['kind'] === 'accept') {
                if ($result->state !== 'committed' || !$result->released) {
                    $this->cancelPublications($commit['publisher']);
                }
                $this->acceptedMessage($commit, $result);
            } elseif ($commit['kind'] === 'complete') {
                $connection = $commit['connection'];
                if ($result->state === 'committed' && $result->released) {
                    unset($connection->outgoing[$commit['identifier']], $connection->durableIdentifiers[$commit['identifier']]);
                } else {
                    $this->disconnect($connection, $result->reason ?: 0x88);
                }
            } elseif (in_array($commit['kind'], ['received', 'release'], true)) {
                $connection = $commit['connection'];
                $identifier = $commit['identifier'];
                if ($result->state !== 'committed' || !$result->released) {
                    $this->disconnect($connection, $result->reason ?: 0x88);
                } elseif (!$connection->closing) {
                    if ($commit['kind'] === 'received') {
                        $connection->outgoing[$identifier]['state'] = 'released';
                        $responses = $connection->outgoing[$identifier]['responses'];
                        for ($response = 0; $response < $responses && !$connection->closing; $response++) {
                            $connection->send("\x62\x02" . pack('n', $identifier));
                        }
                    } else {
                        $connection->incomingQos2[$identifier]['state'] = 'completing';
                        $responses = $connection->incomingQos2[$identifier]['responses'];
                        for ($response = 0; $response < $responses && !$connection->closing; $response++) {
                            $connection->acknowledge($identifier, 0x70);
                        }
                    }
                }
            }
        }
        foreach ($this->closingSessions as $ownerId => $closing) {
            if ($this->sessionBusy($closing) || (!$this->stopping && (float) hrtime(true) / 1000000000.0 < $closing->closeRetryAt)) {
                continue;
            }
            if (count($this->commits) + $this->quarantinedCommits >= 32) {
                if ($this->commits === []) {
                    // 保留数据库原事实；隔离容量已耗尽时不能继续派生未知清理工作。
                    $this->unknownCommits += count($this->closingSessions);
                    $this->closingSessions = [];
                }
                break;
            }
            unset($this->closingSessions[$ownerId]);
            $operationId = bin2hex(random_bytes(16));
            try {
                $this->commits[$operationId] = ['pending' => new PendingCommit(
                    $this->workerCommand,
                    $closing->sessionManaged
                        ? ['operation_id' => $operationId, 'action' => 'session_close', 'session_id' => $closing->sessionId,
                            'owner_id' => $closing->ownerId, 'client_id' => $closing->connect->clientId,
                            ...($this->options->clustered ? ['node_id' => $this->nodeId, 'node_run_id' => $this->nodeRunId] : []),
                            'expiry' => $closing->sessionExpiry, 'cause' => $closing->endReason, 'ended_at' => $closing->endedAt]
                        : ['operation_id' => $operationId, 'action' => 'abandon', 'session_id' => $closing->sessionId]
                ), 'kind' => 'ending', 'connection' => $closing];
            } catch (\Throwable) {
                $this->unknownCommits++;
                if (!$this->stopping) {
                    $closing->closeRetryAt = (float) hrtime(true) / 1000000000.0 + 1.0;
                    $this->closingSessions[$ownerId] = $closing;
                }
            }
        }
    }

    /** 首项失败后，后继尚未启动的请求不能越过不确定的同源接收边界。 */
    private function cancelPublications(Connection $publisher): void
    {
        foreach ($this->commits as $following) {
            if ($following['pending'] === null && $following['publisher'] === $publisher) {
                $following['queued']->shorten(0.0);
            }
        }
    }

    private function acceptedMessage(array $commit, CommitResult $result): void
    {
        $publisher = $commit['publisher'];
        $identifier = $commit['identifier'];
        $accepted = $result->state === 'committed' && $result->released;
        $message = $commit['message'];
        $willId = $commit['will_id'] ?? '';
        if ($willId !== '') {
            $this->willPending = false;
            $this->willPollAt = (float) hrtime(true) / 1000000000.0 + 0.25;
            if ($accepted && !($result->value['accepted'] ?? false)) {
                foreach ($commit['targets'] as $cancelledTarget) {
                    unset($cancelledTarget['connection']->outgoing[$cancelledTarget['packet_id']]);
                }
                return;
            }
            if ($accepted) {
                $this->publishedWills++;
            } else {
                $this->deferWill($willId, $result->reason ?: 0x83);
            }
        }
        if ($accepted && $willId === '') {
            if ($message->qos === 2) {
                $publisher->hadDeliveries = true;
                if (!$publisher->closing) {
                    $publisher->incomingQos2[$identifier]['state'] = 'received';
                    $responses = $publisher->incomingQos2[$identifier]['responses'];
                    for ($response = 0; $response < $responses && !$publisher->closing; $response++) {
                        $publisher->send("\x50\x02" . pack('n', $identifier));
                    }
                }
                if (!$publisher->alive()) {
                    $this->finishSession($publisher);
                }
            } elseif ($message->qos === 1 && !$publisher->closing) {
                $publisher->acknowledge($identifier);
            }
        } elseif (!$publisher->closing) {
            unset($publisher->incoming[$identifier], $publisher->incomingQos2[$identifier]);
            $this->disconnect($publisher, $result->reason ?: 0x88);
        }
        if (!$accepted && $willId === '' && $message->qos === 2 && $result->state === 'unknown') {
            $publisher->hadDeliveries = true;
            if (!$publisher->alive()) {
                $this->finishSession($publisher);
            }
        }
        if ($accepted) {
            foreach ($this->connections as $queued) {
                if (in_array($queued->sessionId, $result->value['queued_sessions'] ?? [], true)) {
                    $queued->draining = true;
                }
            }
        }
        $this->deliverMessage($commit, $result);
    }

    /** 向接收时已选定的目标排队；null仅表示无需持久性的QoS0直投，不能伪造提交证明。 */
    private function deliverMessage(array $commit, ?CommitResult $result): void
    {
        $message = $commit['message'];
        $accepted = $result === null || ($result->state === 'committed' && $result->released);
        if ($accepted && $message->qos === 0 && !$this->options->clustered) {
            $this->delivered += $this->routeShared($message);
        }
        foreach ($commit['targets'] as $target) {
            $subscriber = $target['connection'];
            if (!$accepted && $result->state === 'rejected') {
                unset($subscriber->outgoing[$target['packet_id']]);
                continue;
            }
            if ($result !== null) {
                $subscriber->hadDeliveries = true;
            }
            if (!$accepted || $subscriber->closing) {
                if (!$subscriber->closing) {
                    $this->disconnect($subscriber, 0x88);
                }
                if (!$subscriber->alive()) {
                    $this->finishSession($subscriber);
                }
                $this->dropped++;
                continue;
            }
            if ($message->remaining() === 0) {
                if ($result !== null && !$this->completeDelivery($subscriber, $target['id'], $target['packet_id'], 0, 'expired')) {
                    $this->disconnect($subscriber, 0x97);
                }
                $this->dropped++;
                continue;
            }
            if ($subscriber->publication($message, $target['packet_id'], $target['qos'], $this->options->maximumPacketBytes, true, false, $target['retain'] ?? false, false, $target['subscription_identifiers'] ?? []) === '') {
                $this->dropped++;
                $this->finishSession($subscriber);
                continue;
            }
            $this->delivered++;
            if ($target['qos'] > 0) {
                $subscriber->outgoing[$target['packet_id']]['state'] = 'sent';
            } elseif ($result !== null && !$this->completeDelivery($subscriber, $target['id'], 0, 0, 'queued')) {
                $this->disconnect($subscriber, 0x97);
            }
        }
    }

    /** 每连接至多一个保留读取；排队预算满时等窗口释放，不新建无界 worker 或载荷缓存。 */
    private function replayRetained(Connection $connection): void
    {
        if (!$connection->connected || $connection->closing || $connection->retainedPending || $connection->sessionPending || $connection->replaying
            || count($this->commits) + $this->quarantinedCommits >= 32) {
            return;
        }
        if ($connection->retainedMessage !== null) {
            $this->queueRetained($connection);
            return;
        }
        if ($connection->retainedTopics === []) {
            return;
        }
        $subscription = $connection->retainedTopics[0];
        if (($connection->subscriptions['t:' . $subscription['topic']] ?? null) !== $subscription['subscription']) {
            array_shift($connection->retainedTopics);
            return;
        }
        $qos = $subscription['subscription']['options'] & 3;
        $operationId = bin2hex(random_bytes(16));
        try {
            if (!$this->authorized($connection->connect, $subscription['topic'], 'subscribe', $qos, $connection->identity)) {
                $this->disconnect($connection, 0x87);
                return;
            }
            $request = ['action' => 'retained_read', 'operation_id' => $operationId, 'topic' => $subscription['topic'],
                'cursor' => $subscription['cursor'], 'snapshot_id' => $subscription['snapshot_id'],
                'no_local' => ($subscription['subscription']['options'] & 4) !== 0, 'client_id' => $connection->connect->clientId,
                'session_id' => $connection->sessionId, 'owner_id' => $connection->ownerId];
            $this->commits[$operationId] = ['kind' => 'retained_read', 'pending' => new PendingCommit($this->workerCommand, $request),
                'connection' => $connection, 'subscription' => $subscription];
            $connection->retainedPending = true;
        } catch (\Throwable) {
            $this->disconnect($connection, 0x88);
        }
    }

    private function retainedReadResult(array $commit, CommitResult $result): void
    {
        $connection = $commit['connection'];
        $connection->retainedPending = false;
        if ($connection->closing) {
            return;
        }
        if ($result->state !== 'committed' || !$result->released) {
            $this->disconnect($connection, $result->reason ?: 0x88);
            return;
        }
        $value = $result->value;
        $subscription = $commit['subscription'];
        if (($connection->subscriptions['t:' . $subscription['topic']] ?? null) !== $subscription['subscription']) {
            return;
        }
        $advance = ['snapshot_id' => $subscription['snapshot_id'], 'snapshot_done' => $value['done'] ?? true, 'snapshot_cursor' => $value['cursor'] ?? ''];
        if (!($value['found'] ?? false)) {
            $this->retainedProgress($connection, $advance);
            return;
        }
        $connection->retainedAdvance = $advance;
        $elapsed = $value['expires_at'] === null ? 0 : max(0, $value['expiry_interval'] - max(0, $value['expires_at'] - time()));
        $connection->retainedMessage = new Message(
            $value['message']['topic'],
            (string) base64_decode($value['message']['payload'], true),
            (string) base64_decode($value['message']['properties'], true),
            $value['message']['qos'],
            $elapsed
        );
        $connection->retainedPublisher = $value['message']['client_id'];
        $connection->retainedOptions = $subscription['subscription']['options'];
        $connection->retainedIdentifier = $subscription['subscription']['identifier'];
        $connection->retainedFilter = $subscription['topic'];
    }

    /** 按快照身份推进，避免另一订阅保存或读取结果回收时移除错误的队首。 */
    private function retainedProgress(Connection $connection, array $advance): void
    {
        foreach ($connection->retainedTopics as $index => $snapshot) {
            if ($snapshot['snapshot_id'] !== $advance['snapshot_id']) {
                continue;
            }
            if ($advance['snapshot_done']) {
                unset($connection->retainedTopics[$index]);
                $connection->retainedTopics = array_values($connection->retainedTopics);
            } else {
                $connection->retainedTopics[$index]['cursor'] = $advance['snapshot_cursor'];
            }
            return;
        }
    }

    /** 明确丢弃同样持久推进；等待发送窗口期间不提前释放快照。 */
    private function discardRetained(Connection $connection): void
    {
        $operationId = bin2hex(random_bytes(16));
        $request = ['action' => 'retained_advance', 'operation_id' => $operationId,
            'session_id' => $connection->sessionId, 'owner_id' => $connection->ownerId, ...$connection->retainedAdvance];
        try {
            $this->commits[$operationId] = ['kind' => 'retained_advance', 'pending' => new PendingCommit($this->workerCommand, $request),
                'connection' => $connection, 'advance' => $connection->retainedAdvance];
            $connection->retainedPending = true;
            $connection->retainedMessage = null;
            $connection->retainedAdvance = [];
            $this->dropped++;
        } catch (\Throwable) {
            $this->disconnect($connection, 0x88);
        }
    }

    /** 只有真实保留原件占用发送窗口；读取和排队之间不持有数据库租约。 */
    private function queueRetained(Connection $connection): void
    {
        $message = $connection->retainedMessage;
        if ($message === null) {
            return;
        }
        if (($connection->subscriptions['t:' . $connection->retainedFilter] ?? null)
            !== ['options' => $connection->retainedOptions, 'identifier' => $connection->retainedIdentifier]) {
            $connection->retainedMessage = null;
            $connection->retainedAdvance = [];
            return;
        }
        $qos = min($message->qos, $connection->retainedOptions & 3);
        $identifiers = $connection->retainedIdentifier > 0 ? [$connection->retainedIdentifier] : [];
        if ($message->remaining() === 0 || $connection->publication($message, $qos > 0 ? 1 : 0, $qos, $this->options->maximumPacketBytes, false, false, true, false, $identifiers) === '') {
            $this->discardRetained($connection);
            return;
        }
        $operationId = bin2hex(random_bytes(16));
        $deliveryId = hash('sha256', $operationId . $connection->sessionId);
        $identifier = 0;
        try {
            if (!$this->authorized($connection->connect, $message->topic, 'subscribe', $qos, $connection->identity)) {
                $this->discardRetained($connection);
                return;
            }
            if ($qos > 0) {
                try {
                    $identifier = $connection->reserve($deliveryId, $qos);
                } catch (ProtocolError) {
                    return;
                }
            }
            $delivery = ['id' => $deliveryId, 'session_id' => $connection->sessionId, 'owner_id' => $connection->ownerId, 'client_id' => $connection->connect->clientId,
                'packet_id' => $identifier, 'qos' => $qos, 'retain' => true, 'subscription_identifiers' => $identifiers];
            $request = ['action' => 'retained_accept', 'operation_id' => $operationId,
                'session_id' => $connection->sessionId, 'owner_id' => $connection->ownerId, ...$connection->retainedAdvance,
                'message' => ['id' => $operationId, 'session_id' => $connection->sessionId, 'client_id' => $connection->retainedPublisher,
                    'packet_id' => $identifier, 'topic' => $message->topic, 'payload' => base64_encode($message->payload),
                    'properties' => base64_encode($message->properties), 'qos' => $qos, 'expires_at' => $message->expiresAt()], 'deliveries' => [$delivery]];
            $this->commits[$operationId] = ['kind' => 'retained', 'pending' => new PendingCommit($this->workerCommand, $request),
                'connection' => $connection, 'message' => $message, 'delivery' => $delivery, 'advance' => $connection->retainedAdvance];
            $connection->retainedPending = true;
            $connection->retainedMessage = null;
            $connection->retainedPublisher = '';
            $connection->retainedAdvance = [];
        } catch (\Throwable) {
            unset($connection->outgoing[$identifier]);
            $this->disconnect($connection, 0x88);
        }
    }

    /** 已同步预留的保留副本只交付给原订阅连接；订阅权限在读取前和实际排队前分别验证。 */
    private function retainedResult(array $commit, CommitResult $result): void
    {
        $connection = $commit['connection'];
        $connection->retainedPending = false;
        if ($result->state !== 'committed' || !$result->released) {
            $connection->hadDeliveries = $result->state === 'unknown' || $connection->hadDeliveries;
            $this->disconnect($connection, $result->reason ?: 0x88);
            if (!$connection->alive()) {
                $this->finishSession($connection);
            }
            return;
        }
        $connection->hadDeliveries = true;
        $this->retainedProgress($connection, $commit['advance']);
        if ($connection->closing) {
            if (!$connection->alive()) {
                $this->finishSession($connection);
            }
            return;
        }
        $delivery = $commit['delivery'];
        $qos = $delivery['qos'];
        $identifier = $delivery['packet_id'];
        $message = $commit['message'];
        try {
            if (!$this->authorized($connection->connect, $message->topic, 'subscribe', $qos, $connection->identity)) {
                $this->disconnect($connection, 0x87);
                return;
            }
        } catch (ProtocolError $failure) {
            $this->disconnect($connection, $failure->reason);
            return;
        }
        if ($message->remaining() === 0) {
            if (!$this->completeDelivery($connection, $delivery['id'], $identifier, 0, 'expired')) {
                $this->disconnect($connection, 0x97);
            } elseif ($qos > 0) {
                $connection->outgoing[$identifier]['state'] = 'completing';
            }
            $this->dropped++;
            return;
        }
        if ($connection->publication($message, $identifier, $qos, $this->options->maximumPacketBytes, true, false, true, false, $delivery['subscription_identifiers'] ?? []) === '') {
            $this->dropped++;
            $this->finishSession($connection);
            return;
        }
        $this->delivered++;
        if ($qos > 0) {
            $connection->outgoing[$identifier]['state'] = 'sent';
        } elseif (!$this->completeDelivery($connection, $delivery['id'], 0, 0, 'queued')) {
            $this->disconnect($connection, 0x97);
        }
    }

    /** 全节点只保留一条遗嘱原件及一个工作；空轮询和失败均有节流，复用全局 worker 容量。 */
    private function willCommit(string $action, array $request): void
    {
        $this->willPollAt = (float) hrtime(true) / 1000000000.0 + 1.0;
        if ($this->stopping || count($this->commits) + $this->quarantinedCommits >= 32) {
            return;
        }
        $operationId = bin2hex(random_bytes(16));
        $request['operation_id'] = $operationId;
        $request['action'] = $action;
        if ($this->options->clustered) {
            $request['node_id'] = $this->nodeId;
            $request['node_run_id'] = $this->nodeRunId;
        }
        try {
            $this->commits[$operationId] = ['pending' => new PendingCommit($this->workerCommand, $request), 'kind' => $action];
            $this->willPending = true;
        } catch (\Throwable) {
            $this->failedWills++;
        }
    }

    private function deferWill(string $willId, int $reason): void
    {
        $this->failedWills++;
        $this->willCommit('will_defer', ['will_id' => $willId, 'reason' => $reason]);
    }

    private function willResult(array $commit, CommitResult $result): void
    {
        $this->willPending = false;
        $this->willPollAt = (float) hrtime(true) / 1000000000.0 + 1.0;
        if ($result->state !== 'committed' || !$result->released) {
            $this->failedWills++;
            $this->willPollAt += 1.0;
            return;
        }
        if ($commit['kind'] === 'will_discard') {
            $this->deniedWills++;
        }
        if (!isset($result->value['will']) || $this->stopping) {
            return;
        }
        $will = $result->value['will'];
        $original = $will['message'];
        // 使用认证后保存的身份重新授权；不持久保存密码，也不把遗嘱当成业务 JSON。
        $publisher = new Connection(null, '', 1.0);
        $publisher->sessionId = $will['session_id'];
        $publisher->closing = true;
        $publisher->connect->version = $will['protocol'];
        $publisher->connect->clientId = $will['client_id'];
        $publisher->connect->username = $will['principal'];
        $publisher->identity = isset($will['access_identity']) ? AccessIdentity::fromData($will['access_identity']) : null;
        try {
            $message = new Message(
                $original['topic'],
                (string) base64_decode($original['payload'], true),
                (string) base64_decode($original['properties'], true),
                $original['qos'],
                0,
                $original['retain']
            );
            $this->publishReliable($publisher, $message, $message->qos > 0 ? 1 : 0, false, $will['id']);
        } catch (ProtocolError $failure) {
            if ($failure->reason === 0x87) {
                $this->willCommit('will_discard', ['will_id' => $will['id'], 'reason' => 0x87]);
            } else {
                $this->deferWill($will['id'], $failure->reason);
            }
        }
    }

    private function tick(): void
    {
        $this->spawnClientCrlFetch();
        $this->reloadPlatformRevoke();
        $this->reloadClientOverlap();
        $this->reloadClientCrl();
        $this->reloadHandshakeCa();
        $this->enforceServerCertificate(time());
        $this->invalidateAuthorization();
        // 管理策略可以等待独立进程；在等待段之间推进已接纳的持久工作，
        // 避免 worker 身份交换、会话打开和保存每步都额外等待一整轮管理查询。
        $this->pollCommits();
        $this->applyDisconnect();
        $this->applyTermination();
        $this->pollCommits();
        $this->applyClearance();
        $this->applyQuota();
        $this->pollCommits();
        $this->pollNode();
        if ($this->workerCommand !== [] && !$this->willPending && !$this->stopping
            && (float) hrtime(true) / 1000000000.0 >= $this->willPollAt && count($this->commits) + $this->quarantinedCommits < 32) {
            $this->willCommit('will_read', ['node_id' => $this->nodeId]);
        }
        foreach ($this->connections as $identifier => $connection) {
            if (!$connection->alive()) {
                $this->observeClosed($connection);
                $this->finishSession($connection);
                unset($this->connections[$identifier]);
                $this->closed++;
                continue;
            }
            if ($connection->sessionPending && !$connection->sessionOpening && !$connection->closing) {
                $this->openSession($connection);
            }
            if ($connection->sessionRetryAt > 0.0 && !$connection->closing && !$this->sessionBusy($connection)
                && (float) hrtime(true) / 1000000000.0 >= $connection->sessionRetryAt
                && count($this->commits) + $this->quarantinedCommits < 32) {
                $connection->sessionRetryAt = 0.0;
                try {
                    $this->sessionCommit($connection, 'session_save', ['subscriptions' => $connection->subscriptions]);
                } catch (ProtocolError $saveFailure) {
                    $this->connack($connection, $saveFailure->reason);
                }
            }
            if ($connection->flush?->expired()) {
                // 同步授权可能占用上一次循环。先作一次有界非阻塞写，避免把已就绪响应误判为慢接收方。
                // 不重置截止；原生发送仍未接管就关闭，慢端不能因此无限延长缓冲寿命。
                $this->flush($connection);
                if ($connection->flush?->expired()) {
                    $this->flushTimeouts++;
                    $connection->close();
                }
            } elseif (!$connection->connected && $connection->handshake->expired()) {
                $this->handshakeTimeouts++;
                $connection->close();
                $this->rejected++;
            } elseif (!$connection->closing && $connection->keepAlive?->expired()) {
                $this->disconnect($connection, 0x8d);
            } elseif (!$connection->closing && $connection->partial?->expired()) {
                $this->disconnect($connection, 0x81);
            } elseif (!$connection->closing && $connection->input !== '') {
                $this->packets($connection);
            }
            if ($connection->alive()) {
                $this->replayRetained($connection);
            }
            $sharedFirst = $connection->sharedTurn;
            if ($sharedFirst) {
                $this->pollShared($connection);
            }
            if ($connection->connected && $connection->sessionManaged && !$connection->closing && ($connection->draining || (!$this->options->clustered && ($connection->sessionExpiry !== 0 || $connection->sessionPresent))) && !$connection->sessionPending
                && !$connection->replaying && !$this->sessionBusy($connection) && (float) hrtime(true) / 1000000000.0 >= $connection->replayAt
                && count($this->commits) + $this->quarantinedCommits < 32) {
                try {
                    $this->sessionCommit($connection, 'session_next', ['ignored' => array_keys($connection->outgoing),
                        'check_only' => count($connection->outgoing) >= min(32, (int) ($connection->connect->properties[0x21] ?? 65535))]);
                    $connection->replaying = true;
                    $connection->sharedTurn = true;
                } catch (ProtocolError $error) {
                    $this->disconnect($connection, $error->reason);
                }
            }
            if (!$sharedFirst) {
                $this->pollShared($connection);
            }
        }
        if ($this->observer !== null && !$this->stopping && (float) hrtime(true) / 1000000000.0 >= $this->observationAt) {
            try {
                $this->observer->heartbeat(time());
                $this->observationAt = (float) hrtime(true) / 1000000000.0 + 5.0;
            } catch (\Throwable) {
                $this->observationFailures++;
                $this->stop();
            }
        }
        $this->reloadHandshakeCa();
    }

    /** 只在存在新意图时扫描连接；不对万条空闲连接逐一调用外部授权进程。 */
    private function invalidateAuthorization(): void
    {
        if ($this->invalidations === null || $this->stopping || $this->invalidationPending || (float) hrtime(true) / 1000000000.0 < $this->invalidationAt) {
            return;
        }
        try {
            if ($this->invalidation === null) {
                if ((float) hrtime(true) / 1000000000.0 < $this->invalidationAt) {
                    return;
                }
                $this->invalidationAt = (float) hrtime(true) / 1000000000.0 + 0.5;
                $next = $this->invalidations->nextInvalidation();
                if ($next === null) {
                    return;
                }
                if (preg_match('/^[a-f0-9]{32}$/D', $next['id'] ?? '') !== 1
                    || !is_string($next['client_id'] ?? null) || $next['client_id'] === '' || strlen($next['client_id']) > 65535
                    || !is_string($next['principal'] ?? null) || strlen($next['principal']) > 65535
                    || !is_string($next['actor'] ?? null) || $next['actor'] === '' || strlen($next['actor']) > 256) {
                    throw new \RuntimeException('MQTT授权失效来源返回无效身份');
                }
                if (array_key_exists('access_identity', $next)) {
                    if (!is_array($next['access_identity'])) {
                        throw new \RuntimeException('MQTT授权失效来源返回无效凭据');
                    }
                    AccessIdentity::fromData($next['access_identity']);
                }
                $this->invalidation = $next;
                foreach ($this->connections as $connection) {
                    if ($this->invalidatedConnection($connection)) {
                        $connection->closeReason = 0x87;
                        $connection->sessionExpiry = 0;
                        // 直接丢弃未写网络的旧输出；不能把DISCONNECT追加在旧控制消息之后再flush。
                        $connection->close();
                    }
                }
            }
            // 曾经丢失清理证明的后端可能迟到写入。只有本地连接已关闭不足以证明隔离完成。
            if ($this->quarantinedCommits !== 0) {
                throw new \RuntimeException('MQTT授权失效缺少先前后端清理证明');
            }
            foreach ($this->connections as $connection) {
                if ($this->invalidatedConnection($connection) && ($this->sessionBusy($connection) || $connection->sessionOpened)) {
                    return;
                }
            }
            foreach ($this->closingSessions as $connection) {
                if ($this->invalidatedConnection($connection)) {
                    return;
                }
            }
            foreach ($this->commits as $commit) {
                if ((isset($commit['connection']) && $this->invalidatedConnection($commit['connection']))
                    || (isset($commit['publisher']) && $this->invalidatedConnection($commit['publisher']))) {
                    return;
                }
                foreach ($commit['targets'] ?? [] as $target) {
                    if ($this->invalidatedConnection($target['connection'])) {
                        return;
                    }
                }
            }
            if ($this->workerCommand === []) {
                $this->invalidations->invalidationCompleted($this->invalidation['id']);
                $this->invalidation = null;
                $this->invalidated++;
            } elseif (count($this->commits) + $this->quarantinedCommits < 32) {
                $operationId = bin2hex(random_bytes(16));
                $this->commits[$operationId] = ['kind' => 'invalidation', 'pending' => new PendingCommit(
                    $this->workerCommand,
                    ['operation_id' => $operationId, 'action' => 'session_terminate', 'client_id' => $this->invalidation['client_id'],
                        'principal' => $this->invalidation['principal'], 'actor' => $this->invalidation['actor'],
                        'access_identity' => $this->invalidation['access_identity'] ?? null]
                )];
                $this->invalidationPending = true;
            }
        } catch (\Throwable) {
            $this->failInvalidation();
        }
    }

    /** 隔离失败后先丢弃所有未输出数据，再停止；finally的普通关闭不能冲刷旧控制消息。 */
    private function failInvalidation(): void
    {
        $this->invalidationFailures++;
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->stop();
    }

    /**
     * 管理断开按精确 owner 与会话代次匹配；不把 Client ID 当作目标，也不清零仍有效的会话期限。
     * MQTT 5 发送 0x98 Administrative action，不伪造客户端 0x00；遗嘱按服务端关闭处理。
     */
    private function applyDisconnect(): void
    {
        if ($this->disconnects === null || $this->stopping || (float) hrtime(true) / 1000000000.0 < $this->disconnectAt) {
            return;
        }
        try {
            if ($this->disconnectOp === null) {
                $this->disconnectAt = (float) hrtime(true) / 1000000000.0 + 0.5;
                $next = $this->disconnects->nextDisconnect();
                if ($next === null) {
                    return;
                }
                if (preg_match('/^[a-f0-9]{32}$/D', $next['id'] ?? '') !== 1
                    || preg_match('/^[a-f0-9]{32}$/D', $next['owner_id'] ?? '') !== 1
                    || !is_string($next['session_id'] ?? null) || ($next['session_id'] !== '' && preg_match('/^[a-f0-9]{32}$/D', $next['session_id']) !== 1)
                    || !is_int($next['session_generation'] ?? null) || $next['session_generation'] < 0
                    || !is_string($next['actor'] ?? null) || $next['actor'] === '' || strlen($next['actor']) > 256) {
                    throw new \RuntimeException('MQTT管理断开来源返回无效目标');
                }
                $this->disconnectOp = $next;
                $this->disconnectMatched = false;
                foreach ($this->connections as $connection) {
                    if ($this->disconnectTarget($connection)) {
                        $this->disconnectMatched = true;
                        if (!$connection->closing) {
                            $this->disconnect($connection, 0x98);
                        }
                    }
                }
                foreach ($this->closingSessions as $connection) {
                    if ($this->disconnectTarget($connection)) {
                        $this->disconnectMatched = true;
                    }
                }
            }
            if ($this->quarantinedCommits !== 0) {
                throw new \RuntimeException('MQTT管理断开缺少先前后端清理证明');
            }
            foreach ($this->connections as $connection) {
                if ($this->disconnectTarget($connection)) {
                    return;
                }
            }
            foreach ($this->closingSessions as $connection) {
                if ($this->disconnectTarget($connection)) {
                    return;
                }
            }
            foreach ($this->commits as $commit) {
                if ((isset($commit['connection']) && $this->disconnectTarget($commit['connection']))
                    || (isset($commit['publisher']) && $this->disconnectTarget($commit['publisher']))) {
                    return;
                }
                foreach ($commit['targets'] ?? [] as $target) {
                    if ($this->disconnectTarget($target['connection'])) {
                        return;
                    }
                }
            }
            $outcome = $this->disconnectMatched ? 'disconnected' : 'missing';
            if (!$this->disconnects->disconnectCompleted($this->disconnectOp['id'], $outcome)) {
                return;
            }
            $this->disconnectOp = null;
            $this->disconnectMatched = false;
            $this->disconnected++;
        } catch (\Throwable) {
            $this->failDisconnect();
        }
    }

    /** 来源失败后丢弃未输出数据并停止；不能把未完成断开标成成功。 */
    private function failDisconnect(): void
    {
        $this->disconnectFailures++;
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->stop();
    }

    private function disconnectTarget(Connection $connection): bool
    {
        if ($this->disconnectOp === null || !hash_equals($this->disconnectOp['owner_id'], $connection->ownerId)
            || $this->disconnectOp['session_generation'] !== $connection->sessionGeneration) {
            return false;
        }
        return $this->disconnectOp['session_id'] === '' || hash_equals($this->disconnectOp['session_id'], $connection->sessionId);
    }

    /**
     * 管理终止按精确 session_id 与会话代次匹配；不把 Client ID 当作目标。
     * 在线连接先发 MQTT 5 0x98，再持久删除该会话并放弃其未完成交付；遗嘱按会话结束立即到期。
     */
    private function applyTermination(): void
    {
        if ($this->disconnects === null || $this->stopping || (float) hrtime(true) / 1000000000.0 < $this->terminateAt) {
            return;
        }
        try {
            if ($this->terminateOp === null) {
                $this->terminateAt = (float) hrtime(true) / 1000000000.0 + 0.5;
                $next = $this->disconnects->nextTermination();
                if ($next === null) {
                    return;
                }
                if (preg_match('/^[a-f0-9]{32}$/D', $next['id'] ?? '') !== 1
                    || preg_match('/^[a-f0-9]{32}$/D', $next['owner_id'] ?? '') !== 1
                    || preg_match('/^[a-f0-9]{32}$/D', $next['session_id'] ?? '') !== 1
                    || !is_int($next['session_generation'] ?? null) || $next['session_generation'] < 0
                    || !is_string($next['actor'] ?? null) || $next['actor'] === '' || strlen($next['actor']) > 256) {
                    throw new \RuntimeException('MQTT管理终止来源返回无效目标');
                }
                $this->terminateOp = $next;
                $this->terminateMatched = false;
                $this->terminateStorePending = false;
                $this->terminateErased = false;
                $this->terminateOutcome = '';
                foreach ($this->connections as $connection) {
                    if ($this->terminateTarget($connection)) {
                        $this->terminateMatched = true;
                        if (!$connection->closing) {
                            $this->disconnect($connection, 0x98);
                        }
                    }
                }
                foreach ($this->closingSessions as $connection) {
                    if ($this->terminateTarget($connection)) {
                        $this->terminateMatched = true;
                    }
                }
            }
            if ($this->quarantinedCommits !== 0) {
                throw new \RuntimeException('MQTT管理终止缺少先前后端清理证明');
            }
            foreach ($this->connections as $connection) {
                if ($this->terminateTarget($connection)) {
                    return;
                }
            }
            foreach ($this->closingSessions as $connection) {
                if ($this->terminateTarget($connection)) {
                    return;
                }
            }
            foreach ($this->commits as $commit) {
                if ($commit['kind'] === 'termination') {
                    return;
                }
                if ((isset($commit['connection']) && $this->terminateTarget($commit['connection']))
                    || (isset($commit['publisher']) && $this->terminateTarget($commit['publisher']))) {
                    return;
                }
                foreach ($commit['targets'] ?? [] as $target) {
                    if ($this->terminateTarget($target['connection'])) {
                        return;
                    }
                }
            }
            if ($this->terminateOutcome !== '' && $this->terminateOp !== null) {
                if (!$this->disconnects->terminationCompleted($this->terminateOp['id'], $this->terminateOutcome)) {
                    $this->terminateAt = (float) hrtime(true) / 1000000000.0 + 0.25;
                    return;
                }
                $this->terminateOp = null;
                $this->terminateMatched = false;
                $this->terminateErased = false;
                $this->terminateOutcome = '';
                $this->terminated++;
                return;
            }
            if ($this->workerCommand === []) {
                throw new \RuntimeException('MQTT管理终止需要持久存储');
            }
            if ($this->terminateStorePending) {
                return;
            }
            if (count($this->commits) + $this->quarantinedCommits >= 32) {
                return;
            }
            $operationId = bin2hex(random_bytes(16));
            $this->commits[$operationId] = ['kind' => 'termination', 'pending' => new PendingCommit(
                $this->workerCommand,
                ['operation_id' => $operationId, 'action' => 'session_terminate', 'session_id' => $this->terminateOp['session_id'],
                    'session_generation' => $this->terminateOp['session_generation'], 'actor' => $this->terminateOp['actor']]
            )];
            $this->terminateStorePending = true;
        } catch (\Throwable) {
            $this->failTermination();
        }
    }

    /** 来源或存储失败后丢弃未输出数据并停止；不能把未完成终止标成成功。 */
    private function failTermination(): void
    {
        $this->terminationFailures++;
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->stop();
    }

    private function terminateTarget(Connection $connection): bool
    {
        return $this->terminateOp !== null && $connection->sessionId !== ''
            && hash_equals($this->terminateOp['session_id'], $connection->sessionId)
            && $this->terminateOp['session_generation'] === $connection->sessionGeneration;
    }

    /**
     * 管理清除按精确 resource_id 与原件代次匹配；不向在线订阅发送 PUBLISH，也不撤回已有交付。
     */
    private function applyClearance(): void
    {
        if ($this->disconnects === null || $this->stopping || (float) hrtime(true) / 1000000000.0 < $this->clearAt) {
            return;
        }
        try {
            if ($this->clearOp === null) {
                $this->clearAt = (float) hrtime(true) / 1000000000.0 + 0.5;
                $next = $this->disconnects->nextClearance();
                if ($next === null) {
                    return;
                }
                if (preg_match('/^[a-f0-9]{32}$/D', $next['id'] ?? '') !== 1
                    || preg_match('/^[a-f0-9]{32}$/D', $next['owner_id'] ?? '') !== 1
                    || preg_match('/^[a-f0-9]{32}$/D', $next['session_id'] ?? '') !== 1
                    || !is_int($next['session_generation'] ?? null) || $next['session_generation'] < 0
                    || !is_string($next['actor'] ?? null) || $next['actor'] === '' || strlen($next['actor']) > 256) {
                    throw new \RuntimeException('MQTT管理清保留来源返回无效目标');
                }
                $this->clearOp = $next;
                $this->clearStorePending = false;
                $this->clearErased = false;
                $this->clearOutcome = '';
            }
            if ($this->quarantinedCommits !== 0) {
                throw new \RuntimeException('MQTT管理清保留缺少先前后端清理证明');
            }
            if ($this->clearOutcome !== '' && $this->clearOp !== null) {
                if (!$this->disconnects->clearanceCompleted($this->clearOp['id'], $this->clearOutcome)) {
                    $this->clearAt = (float) hrtime(true) / 1000000000.0 + 0.25;
                    return;
                }
                $this->clearOp = null;
                $this->clearErased = false;
                $this->clearOutcome = '';
                $this->retainedCleared++;
                return;
            }
            if ($this->workerCommand === []) {
                throw new \RuntimeException('MQTT管理清保留需要持久存储');
            }
            if ($this->clearStorePending) {
                return;
            }
            if (count($this->commits) + $this->quarantinedCommits >= 32) {
                return;
            }
            $operationId = bin2hex(random_bytes(16));
            $this->commits[$operationId] = ['kind' => 'clearance', 'pending' => new PendingCommit(
                $this->workerCommand,
                ['operation_id' => $operationId, 'action' => 'retain_clear', 'resource_id' => $this->clearOp['session_id'],
                    'generation' => $this->clearOp['session_generation'], 'actor' => $this->clearOp['actor'],
                    'clear_operation_id' => $this->clearOp['id']]
            )];
            $this->clearStorePending = true;
        } catch (\Throwable) {
            $this->failClearance();
        }
    }

    /** 来源或存储失败后丢弃未输出数据并停止；不能把未完成清保留标成成功。 */
    private function failClearance(): void
    {
        $this->retainClearFailures++;
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->stop();
    }

    /**
     * 管理额度按版本应用到本进程；连接立即生效且不踢现有连接，持久额度写入存储后才推进已应用版本。
     */
    private function applyQuota(): void
    {
        if ($this->quotas === null || $this->stopping || (float) hrtime(true) / 1000000000.0 < $this->quotaAt) {
            return;
        }
        try {
            if ($this->quotaOp === null) {
                $this->quotaAt = (float) hrtime(true) / 1000000000.0 + 0.5;
                $next = $this->quotas->nextQuota();
                if ($next === null || $next['version'] <= $this->appliedQuotaVersion) {
                    return;
                }
                if (!is_int($next['version']) || $next['version'] < 1 || !is_array($next['limits'] ?? null)) {
                    throw new \RuntimeException('MQTT管理配额来源返回无效快照');
                }
                $this->installLimits($next['limits']);
                $this->quotaOp = $next;
                $this->quotaStorePending = false;
            }
            if ($this->workerCommand === []) {
                $this->appliedQuotaVersion = $this->quotaOp['version'];
                $this->quotaOp = null;
                return;
            }
            if ($this->quotaStorePending) {
                return;
            }
            if ($this->quarantinedCommits !== 0 || count($this->commits) + $this->quarantinedCommits >= 32) {
                return;
            }
            $operationId = bin2hex(random_bytes(16));
            $this->commits[$operationId] = ['kind' => 'quota', 'pending' => new PendingCommit(
                $this->workerCommand,
                ['operation_id' => $operationId, 'action' => 'quota_apply', 'version' => $this->quotaOp['version'],
                    'limits' => $this->quotaOp['limits']]
            )];
            $this->quotaStorePending = true;
        } catch (\Throwable) {
            $this->quotaFailures++;
            $this->quotaOp = null;
            $this->quotaStorePending = false;
            $this->quotaAt = (float) hrtime(true) / 1000000000.0 + 1.0;
        }
    }

    /** @param array<string, mixed> $limits */
    private function installLimits(array $limits): void
    {
        $connections = is_int($limits['maximumConnections'] ?? null) ? $limits['maximumConnections'] : $this->maximumConnections;
        $devices = is_int($limits['maximumDeviceConnections'] ?? null) ? $limits['maximumDeviceConnections'] : $this->maximumDeviceConnections;
        $services = is_int($limits['maximumServiceConnections'] ?? null) ? $limits['maximumServiceConnections'] : $this->maximumServiceConnections;
        $subscriptions = is_int($limits['maximumSubscriptions'] ?? null) ? $limits['maximumSubscriptions'] : $this->maximumSubscriptions;
        if ($connections < 1 || $devices < 1 || $services < 0 || $subscriptions < 1) {
            throw new \RuntimeException('MQTT管理配额快照越界');
        }
        $this->maximumConnections = min($connections, $this->startupMaximumConnections);
        $this->maximumDeviceConnections = min($devices, $this->startupMaximumDeviceConnections);
        $this->maximumServiceConnections = min($services, $this->startupMaximumServiceConnections);
        $this->maximumSubscriptions = min($subscriptions, 100);
        $this->storeQuotas = $limits;
    }

    private function invalidatedConnection(Connection $connection): bool
    {
        if ($this->invalidation === null || $connection->connect->clientId !== $this->invalidation['client_id']) {
            return false;
        }
        if (isset($this->invalidation['access_identity'])) {
            return $connection->identity !== null
                ? $connection->identity->matches(AccessIdentity::fromData($this->invalidation['access_identity']))
                : ($connection->connect->username ?? '') === $this->invalidation['principal'];
        }
        return ($connection->connect->username ?? '') === $this->invalidation['principal'];
    }

    private function observeClosed(Connection $connection): void
    {
        if (!$connection->observed) {
            return;
        }
        $connection->observed = false;
        try {
            $this->observer?->disconnected($connection->connect->clientId, $connection->connect->username ?? '', $connection->ownerId, time(), $connection->closeReason);
        } catch (\Throwable) {
            $this->observationFailures++;
            $this->stop();
        }
    }

    /** 写入及真实CONNACK观察共用一个顺序，超时前的最后写入也不能漏掉设备激活。 */
    private function flush(Connection $connection): void
    {
        $connection->flush();
        if ($this->observer !== null && $connection->connackSent && !$connection->observed
            && !$connection->closing && $connection->alive()) {
            $connection->observed = true;
            try {
                if ($this->observer instanceof ResourceConnectionObserver) {
                    $this->observer->connectedResource([
                        'owner_id' => $connection->ownerId, 'client_id' => $connection->connect->clientId,
                        'username' => $connection->connect->username ?? '', 'session_id' => $connection->sessionId,
                        'session_generation' => $connection->sessionGeneration, 'node_id' => $this->nodeId,
                        'node_run_id' => $this->nodeRunId, 'node_generation' => $this->nodeGeneration,
                        'resource_scope' => $connection->resourceScope, 'access_identity' => $connection->identity?->data(),
                        'protocol' => $connection->connect->version, 'transport' => $connection->transportName(),
                        'durable' => $connection->sessionManaged,
                    ], time());
                    $this->observeSubscriptions($connection, []);
                } else {
                    $this->observer->connected($connection->connect->clientId, $connection->connect->username ?? '', $connection->ownerId, time());
                }
            } catch (\Throwable) {
                $this->observationFailures++;
                $this->stop();
            }
        }
    }

    /** 对已生效状态发送增量；每次回调至多携带一个标准长度的过滤器。 */
    private function observeSubscriptions(Connection $connection, array $previous): void
    {
        if (!$connection->observed || !$this->observer instanceof ResourceConnectionObserver) {
            return;
        }
        try {
            foreach ($previous as $key => $subscription) {
                if (!isset($connection->subscriptions[$key])) {
                    $this->observer->subscriptionResource($connection->ownerId, substr($key, 2), null, time());
                }
            }
            foreach ($connection->subscriptions as $key => $subscription) {
                if (($previous[$key] ?? null) !== $subscription) {
                    $this->observer->subscriptionResource($connection->ownerId, substr($key, 2), $subscription, time());
                }
            }
        } catch (\Throwable) {
            $this->observationFailures++;
            $this->stop();
        }
    }

    /** 每次至多处理 32 个完整控制报文，其他连接获得循环机会。 */
    private function packets(Connection $connection): void
    {
        if ($connection->sessionPending) {
            return;
        }
        try {
            for ($iteration = 0; $iteration < 32 && $connection->input !== '' && !$connection->closing; $iteration++) {
                $head = ord($connection->input[0]);
                $type = $head >> 4;
                $flags = $head & 15;
                // §2.1.2/2.1.3：保留类型和非法标志属于格式错误，不能与报文顺序混为一类。
                if ($type === 0 || ($type !== 3 && $flags !== (in_array($type, [6, 8, 10], true) ? 2 : 0))
                    || ($type === 3 && ((($flags >> 1) & 3) === 3 || (($flags & 6) === 0 && ($flags & 8) !== 0)))) {
                    throw new ProtocolError(0x81);
                }
                if (!$connection->connected && $head !== 0x10) {
                    throw new ProtocolError(0x82);
                }
                $length = 0;
                $multiplier = 1;
                $headerBytes = 1;
                do {
                    if (strlen($connection->input) <= $headerBytes) {
                        return;
                    }
                    $byte = ord($connection->input[$headerBytes++]);
                    $length += ($byte & 127) * $multiplier;
                    if ($headerBytes === 5 && ($byte & 128) !== 0) {
                        throw new ProtocolError();
                    }
                    $multiplier *= 128;
                } while (($byte & 128) !== 0);
                if ($headerBytes > 2 && $byte === 0) {
                    throw new ProtocolError();
                }
                if ($length + $headerBytes > $this->options->maximumPacketBytes) {
                    throw new ProtocolError(0x95);
                }
                if (strlen($connection->input) < $headerBytes + $length) {
                    return;
                }
                // 后台读取可占满worker；已经交付的确认先保留在原输入缓冲，不能因此拒绝有效ACK。
                // 不复制报文或延长既有partial截止，下轮释放工作槽后继续；其他连接照常处理。
                if (in_array($type, [4, 5, 6, 7], true) && count($this->commits) + $this->quarantinedCommits >= 32) {
                    return;
                }
                $payload = substr($connection->input, $headerBytes, $length);
                $connection->input = substr($connection->input, $headerBytes + $length);
                $connection->partial = $connection->input === '' ? null : new Deadline($this->options->partialPacketSeconds);
                $this->packet($connection, $head, $payload);
                // 完整合法报文已经收到；持久订阅/退订的异步等待不应跳过本次活动。
                if ($connection->connected && !$connection->closing) {
                    $connection->activity();
                }
                if ($connection->sessionPending) {
                    return;
                }
            }
        } catch (ProtocolError $error) {
            $this->rejected++;
            $this->packetQuotaRefusals += $error->reason === 0x97 ? 1 : 0;
            if (!$connection->connected && in_array($connection->connect->version, [4, 5], true)) {
                $this->connack($connection, $error->reason);
            } else {
                $this->disconnect($connection, $error->reason);
            }
        }
    }

    /** mTLS 入口只接受已验证指纹；同时带 CONNECT 凭据时必须映射同一主体，失败不降级。 */
    private function authenticateConnection(Connection $connection): void
    {
        $this->refreshClientCertificate($connection);
        try {
            if ($connection->mutualTls) {
                if ($connection->clientCertificateFingerprint === '' || !($this->access instanceof CertificateAccessPolicy)) {
                    throw new ProtocolError(0x87);
                }
                $certificateIdentity = $this->access->authenticateCertificate(
                    $connection->connect,
                    $connection->peer,
                    $connection->clientCertificateFingerprint
                );
                $hasCredentials = $connection->connect->username !== null || $connection->connect->password !== null;
                if ($hasCredentials) {
                    if (!($this->access instanceof IdentityAccessPolicy)) {
                        throw new ProtocolError(0x87);
                    }
                    $credentialIdentity = $this->access->authenticateIdentity($connection->connect, $connection->peer, $connection->secure);
                    if ($certificateIdentity === null || $credentialIdentity === null
                        || $certificateIdentity->principalId !== $credentialIdentity->principalId) {
                        throw new ProtocolError(0x87);
                    }
                    $connection->identity = $credentialIdentity;
                    return;
                }
                if ($certificateIdentity === null) {
                    throw new ProtocolError(0x87);
                }
                $connection->identity = $certificateIdentity;
                return;
            }
            if ($this->access instanceof IdentityAccessPolicy) {
                $connection->identity = $this->access->authenticateIdentity($connection->connect, $connection->peer, $connection->secure);
                if ($connection->identity === null) {
                    throw new ProtocolError(0x86);
                }
                return;
            }
            if (!$this->access->authenticate($connection->connect, $connection->peer, $connection->secure)) {
                throw new ProtocolError(0x86);
            }
        } catch (ProtocolError $error) {
            throw $error;
        } catch (\Throwable) {
            throw new ProtocolError(0x88);
        }
    }

    /** 握手完成晚于 onConnect 时补读一次已校验证书，仍不保存 PEM。 */
    private function refreshClientCertificate(Connection $connection): void
    {
        if (!$connection->mutualTls || $connection->clientCertificateFingerprint !== '' || $connection->native === null || !is_int($connection->socket)) {
            return;
        }
        $info = $connection->native->getClientInfo($connection->socket);
        $facts = $this->certificateFromInfo(is_array($info) ? $info : []);
        $connection->clientCertificateFingerprint = $facts['fingerprint'];
        $connection->clientCertificateSerial = $facts['serial'];
        $connection->clientCertificateNotAfter = $facts['notAfter'];
    }

    /** 平台吊销文件更新后只并入新序列号；回写缩短名单不能复活已接纳项。 */
    private function reloadPlatformRevoke(): void
    {
        if ($this->options->clientRevoke === '') {
            return;
        }
        clearstatcache(true, $this->options->clientRevoke);
        $mtime = @filemtime($this->options->clientRevoke);
        $size = @filesize($this->options->clientRevoke);
        if (!is_int($mtime) || !is_int($size) || ($mtime === $this->revokeMtime && $size === $this->revokeSize)) {
            return;
        }
        $loaded = self::readPlatformRevokeFile($this->options->clientRevoke);
        if ($loaded === null) {
            return;
        }
        $added = false;
        foreach ($loaded as $serial) {
            if (!isset($this->platformRevoked[$serial])) {
                $this->platformRevoked[$serial] = true;
                $added = true;
            }
        }
        $this->revokeMtime = $mtime;
        $this->revokeSize = $size;
        if ($added) {
            $this->certificateEnforceAt = 0;
        }
    }

    /**
     * @return list<string>|null 无效正文返回 null，不更新 mtime。
     */
    private static function readPlatformRevokeFile(string $path): ?array
    {
        $content = @file_get_contents($path);
        if (!is_string($content) || strlen($content) > 1048576) {
            return null;
        }
        $lines = preg_split('/\R/', $content);
        if (!is_array($lines)) {
            return null;
        }
        $serials = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $serial = CertificateRevocationList::serialHex($line);
            if ($serial === '') {
                return null;
            }
            $serials[] = $serial;
        }
        return $serials;
    }

    private function revokesClientSerial(string $serial): bool
    {
        if ($serial === '') {
            return $this->revocationList !== null || $this->options->clientRevoke !== '';
        }
        if ($this->platformRevokes($serial)) {
            return true;
        }
        return $this->revocationList !== null && $this->revocationList->revokes($serial);
    }

    private function platformRevokes(string $serial): bool
    {
        $serial = CertificateRevocationList::serialHex($serial);
        if ($serial === '') {
            return $this->options->clientRevoke !== '';
        }
        foreach ($this->platformRevoked as $revoked => $kept) {
            if ($kept && hash_equals($revoked, $serial)) {
                return true;
            }
        }
        return false;
    }

    /** 重叠文件更新后替换窗口；见过的指纹移出文件即结束重叠。 */
    private function reloadClientOverlap(): void
    {
        if ($this->options->clientOverlap === '') {
            return;
        }
        clearstatcache(true, $this->options->clientOverlap);
        $mtime = @filemtime($this->options->clientOverlap);
        $size = @filesize($this->options->clientOverlap);
        if (!is_int($mtime) || !is_int($size) || ($mtime === $this->overlapMtime && $size === $this->overlapSize)) {
            return;
        }
        $windows = BrokerOptions::overlapWindows($this->options->clientOverlap, time());
        if ($windows === null) {
            return;
        }
        $this->applyOverlapWindows($windows);
        $this->overlapMtime = $mtime;
        $this->overlapSize = $size;
        $this->certificateEnforceAt = 0;
    }

    /**
     * @param array<string, int> $windows
     */
    private function applyOverlapWindows(array $windows): void
    {
        foreach ($windows as $fingerprint => $until) {
            $this->overlapKnown[$fingerprint] = $until >= 0;
        }
        $this->overlapUntil = $windows;
    }

    private function overlapFinished(string $fingerprint, int $now): bool
    {
        $fingerprint = strtolower(str_replace(':', '', $fingerprint));
        if (preg_match('/^[0-9a-f]{64}$/D', $fingerprint) !== 1) {
            return false;
        }
        foreach ($this->overlapUntil as $item => $until) {
            if (hash_equals($item, $fingerprint)) {
                return $until <= $now;
            }
        }
        foreach ($this->overlapKnown as $item => $kept) {
            if ($kept && hash_equals($item, $fingerprint)) {
                return true;
            }
        }
        return false;
    }

    /** 至多一个官方协程 HTTP 下载在途；不派生会继承现有协程的 PHP 进程。 */
    private function spawnClientCrlFetch(): void
    {
        if ($this->options->clientCrlUrl === '' || $this->stopping || $this->crlFetching || time() < $this->crlFetchAt) {
            return;
        }
        $this->crlFetchAt = time() + (int) $this->options->clientCrlInterval;
        $this->crlFetching = true;
        $created = Coroutine::create(function (): void {
            try {
                $this->downloadClientCrl($this->options->clientCrlUrl, $this->options->clientCa, $this->options->clientCrl);
            } catch (\Throwable) {
                // 保留原列表，既有 CRL 有效期规则决定是否继续接受连接。
            } finally {
                $this->crlClient?->close();
                $this->crlClient = null;
                $this->crlFetching = false;
            }
        });
        if ($created === false) {
            $this->crlFetching = false;
        }
    }

    private function stopClientCrlFetch(): void
    {
        $this->crlClient?->close();
    }

    /** 下载失败或停止时保持原文件；校验与接纳仍由串行 tick 完成。 */
    private function downloadClientCrl(string $url, string $ca, string $output): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || !is_string($parts['host']) || $parts['host'] === '') {
            return;
        }
        $port = isset($parts['port']) && is_int($parts['port']) ? $parts['port'] : 443;
        $path = (string) ($parts['path'] ?? '/');
        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            $path .= '?' . $parts['query'];
        }
        $client = new \Swoole\Coroutine\Http\Client($parts['host'], $port, true);
        $this->crlClient = $client;
        $body = '';
        $client->set([
            'timeout' => 10,
            'ssl_verify_peer' => true,
            'ssl_allow_self_signed' => false,
            'ssl_cafile' => $ca,
            'ssl_host_name' => $parts['host'],
            'follow_location' => false,
            'http_compression' => false,
            'body_decompression' => false,
            'write_func' => static function (\Swoole\Coroutine\Http\Client $http, string $chunk) use (&$body): void {
                if (strlen($body) + strlen($chunk) > 1048576) {
                    throw new \RuntimeException('crl_response_too_large');
                }
                $body .= $chunk;
            },
        ]);
        $received = $client->get($path);
        $status = (int) $client->statusCode;
        $client->close();
        if (!$received || $this->stopping) {
            return;
        }
        if ($status !== 200 || $body === '' || strlen($body) > 1048576 || !str_contains($body, 'BEGIN X509 CRL')) {
            return;
        }
        $temporary = dirname($output) . '/.' . basename($output) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temporary, $body) !== strlen($body)) {
            @unlink($temporary);
            return;
        }
        if (!@rename($temporary, $output)) {
            @unlink($temporary);
        }
    }

    /** 记录需要按文件更新重建 SSL_CTX 的监听端口。 */
    private function rememberHandshakePort(mixed $port): void
    {
        if (is_object($port) && method_exists($port, 'set')) {
            $this->handshakeCaPorts[] = $port;
        }
    }

    /** 主监听对应的 Swoole Port；启动后 Server::set 会拒绝，握手 CA 必须走 Port::set。 */
    private function primaryPort(\Swoole\Server $server): mixed
    {
        $ports = $server->ports;
        if (!is_array($ports) || !isset($ports[0])) {
            return null;
        }
        return $ports[0];
    }

    /** 文件更新后重载客户端握手 CA；只影响新连接，已有会话保持到吊销或到期。 */
    private function reloadHandshakeCa(): void
    {
        if ($this->options->clientCa === '') {
            return;
        }
        clearstatcache(true, $this->options->clientCa);
        if (!is_file($this->options->clientCa) || !is_readable($this->options->clientCa)) {
            return;
        }
        $mtime = @filemtime($this->options->clientCa);
        $size = @filesize($this->options->clientCa);
        if (!is_int($mtime) || !is_int($size) || ($mtime === $this->handshakeCaMtime && $size === $this->handshakeCaSize)) {
            return;
        }
        if ($this->handshakeCaPorts === []) {
            $this->handshakeCaFailures++;
            return;
        }
        $settings = [
            'ssl_client_cert_file' => $this->options->clientCa,
            'ssl_verify_peer' => true,
            'ssl_allow_self_signed' => false,
            'ssl_verify_depth' => 3,
        ];
        foreach ($this->handshakeCaPorts as $port) {
            if (!is_object($port) || !method_exists($port, 'set') || $port->set($settings) === false) {
                $this->handshakeCaFailures++;
                return;
            }
        }
        $this->handshakeCaMtime = $mtime;
        $this->handshakeCaSize = $size;
        $this->handshakeCaReloads++;
    }

    /** 文件更新后接纳较新 CRL；缺失、过期或已列入序列号在 Swoole tick 内断开。 */
    private function reloadClientCrl(): void
    {
        $now = time();
        $accepted = false;
        if ($this->options->clientCrl !== '') {
            clearstatcache(true, $this->options->clientCrl);
            if (!is_file($this->options->clientCrl) || !is_readable($this->options->clientCrl)) {
                if (!$this->crlUnavailable) {
                    $this->crlUnavailable = true;
                    $this->certificateEnforceAt = 0;
                }
            } else {
                if ($this->crlUnavailable) {
                    $this->crlUnavailable = false;
                    $this->certificateEnforceAt = 0;
                }
                $mtime = @filemtime($this->options->clientCrl);
                $size = @filesize($this->options->clientCrl);
                if (is_int($mtime) && is_int($size) && ($mtime !== $this->crlMtime || $size !== $this->crlSize)) {
                    try {
                        $loaded = CertificateRevocationList::fromFiles($this->options->clientCrl, $this->options->clientCa);
                        if ($this->revocationList !== null && $loaded->thisUpdate() < $this->revocationList->thisUpdate()) {
                            $this->crlMtime = $mtime;
                            $this->crlSize = $size;
                        } elseif ($loaded->covers($now)) {
                            $this->revocationList = $this->revocationList === null ? $loaded : $this->revocationList->merge($loaded);
                            $this->crlMtime = $mtime;
                            $this->crlSize = $size;
                            $accepted = true;
                        } else {
                            $this->crlMtime = $mtime;
                            $this->crlSize = $size;
                        }
                    } catch (\RuntimeException) {
                    }
                }
            }
        }
        $crlClosed = $this->clientCrlClosed($now);
        if ($accepted || $crlClosed || $now >= $this->certificateEnforceAt) {
            $this->certificateEnforceAt = $now + 1;
            $this->enforceClientCertificates($crlClosed, $now);
        }
    }

    private function clientCrlClosed(int $now): bool
    {
        if ($this->options->clientCrl === '') {
            return false;
        }
        if ($this->crlUnavailable || $this->revocationList === null) {
            return true;
        }
        return !$this->revocationList->covers($now);
    }

    /** @param array<string, mixed> $parsed openssl_x509_parse 长名结果。 */
    private function rememberServerExpiry(array $parsed): void
    {
        if (!isset($parsed['validTo_time_t']) || !is_int($parsed['validTo_time_t']) || $parsed['validTo_time_t'] <= 0) {
            return;
        }
        if ($this->serverCertificateNotAfter === 0 || $parsed['validTo_time_t'] < $this->serverCertificateNotAfter) {
            $this->serverCertificateNotAfter = $parsed['validTo_time_t'];
        }
    }

    private function serverCertificateExpired(int $now): bool
    {
        return $this->serverCertificateNotAfter > 0 && $now >= $this->serverCertificateNotAfter;
    }

    /** Swoole 不因服务端证书到期断开已连会话；记下的 notAfter 到达后对 TLS 连接发出 0x8b。 */
    private function enforceServerCertificate(int $now): void
    {
        if (!$this->serverCertificateExpired($now)) {
            return;
        }
        foreach ($this->connections as $connection) {
            if ($connection->closing || !$connection->secure || !$connection->alive()) {
                continue;
            }
            $this->disconnect($connection, 0x8b);
        }
    }

    /** 已吊销、CRL 缺失/过期或叶证书到期的 mTLS 会话在 tick 内断开。 */
    private function enforceClientCertificates(bool $crlClosed, int $now): void
    {
        foreach ($this->connections as $connection) {
            if ($connection->closing || !$connection->mutualTls || !$connection->alive()) {
                continue;
            }
            if ($crlClosed
                || $this->revokesClientSerial($connection->clientCertificateSerial)
                || $this->overlapFinished($connection->clientCertificateFingerprint, $now)
                || ($connection->clientCertificateNotAfter > 0 && $now >= $connection->clientCertificateNotAfter)) {
                $this->disconnect($connection, 0x87);
            }
        }
    }

    private function packet(Connection $connection, int $head, string $payload): void
    {
        if ($head === 0x10) {
            if ($connection->connected) {
                throw new ProtocolError(0x82);
            }
            $connection->connect->decode($payload);
            $this->authenticateConnection($connection);
            if ($this->access instanceof ResourceAccessPolicy && $connection->identity !== null) {
                try {
                    $connection->resourceScope = $this->access->resourceScope($connection->identity, $connection->connect);
                } catch (\Throwable) {
                    throw new ProtocolError(0x88);
                }
                if ($connection->resourceScope !== null && ($connection->resourceScope === '' || strlen($connection->resourceScope) > 128
                    || preg_match('//u', $connection->resourceScope) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $connection->resourceScope) === 1)) {
                    throw new ProtocolError(0x87);
                }
            }
            foreach ($this->connections as $existing) {
                if ($existing !== $connection && !$existing->closing && $existing->connect->clientId === $connection->connect->clientId
                    && $existing->resourceScope !== null && $existing->resourceScope !== $connection->resourceScope) {
                    throw new ProtocolError(0x87);
                }
            }
            try {
                $capacityClass = $this->classify === null ? 'device' : ($this->classify)($connection->connect);
            } catch (\Throwable) {
                throw new ProtocolError(0x88);
            }
            if (!in_array($capacityClass, ['device', 'application'], true)) {
                throw new ProtocolError(0x87);
            }
            $counted = $this->capacityCounts($connection, $capacityClass);
            $live = $counted[0];
            $occupied = $counted[1];
            $serviceLimit = $this->classify === null ? 0 : min($this->maximumServiceConnections, max(0, $this->maximumConnections - 1));
            $capacityLimit = $capacityClass === 'application' ? $serviceLimit
                : min($this->maximumDeviceConnections, $this->maximumConnections - $serviceLimit);
            if ($capacityClass === 'application' && !$this->debugIdentity($connection)
                && ($live >= $this->maximumConnections || $occupied >= $capacityLimit)
                && $this->releaseDebugSlot()) {
                $counted = $this->capacityCounts($connection, $capacityClass);
                $live = $counted[0];
                $occupied = $counted[1];
            }
            if ($live >= $this->maximumConnections || $occupied >= $capacityLimit) {
                $this->connectionQuotaRefusals++;
                throw new ProtocolError(0x97);
            }
            $connection->capacityClass = $capacityClass;
            if ($connection->connect->will) {
                if ($this->workerCommand === []) {
                    throw new ProtocolError(0x88);
                }
                if (!$this->authorized($connection->connect, $connection->connect->willTopic, 'publish', $connection->connect->willQos, $connection->identity)) {
                    throw new ProtocolError(0x87);
                }
            }
            foreach ($this->connections as $existing) {
                if ($existing !== $connection && !$existing->closing && $existing->connect->clientId === $connection->connect->clientId) {
                    $this->disconnect($existing, 0x8e);
                }
            }
            $connection->sessionExpiry = $connection->connect->version === 4
                ? ($connection->connect->cleanStart ? 0 : -1) : (int) ($connection->connect->properties[0x11] ?? 0);
            if ($this->workerCommand !== []) {
                $connection->sessionPending = true;
                $this->openSession($connection);
            } else {
                if ($connection->sessionExpiry !== 0) {
                    throw new ProtocolError(0x88);
                }
                $this->connack($connection, 0);
                $connection->connected = true;
                $connection->activity();
            }
        } elseif (($head >> 4) === 3) {
            $qos = ($head >> 1) & 3;
            if ($qos > 0 && $this->workerCommand === []) {
                throw new ProtocolError(0x9b);
            }
            if (($head & 1) !== 0 && $this->workerCommand === []) {
                throw new ProtocolError(0x9a);
            }
            $reader = new PacketReader($payload);
            $topic = $reader->text();
            $identifier = $qos > 0 ? $reader->integer(2) : 0;
            if ($qos > 0 && $identifier === 0) {
                throw new ProtocolError(0x82);
            }
            $messageProperties = '';
            if ($connection->connect->version === 5) {
                $attributes = $reader->properties('publish');
                $topic = $connection->resolveTopic($topic, $attributes[0x23] ?? null);
                $messageProperties = $reader->forwardedProperties();
            }
            $message = new Message($topic, $reader->take($reader->remaining()), $messageProperties, $qos, 0, ($head & 1) !== 0);
            $ordered = false;
            foreach ($this->commits as $publication) {
                if (($publication['publisher'] ?? null) === $connection) {
                    $ordered = true;
                    break;
                }
            }
            if ($this->options->clustered || $qos > 0 || $message->retain || $ordered) {
                $this->publishReliable($connection, $message, $identifier, ($head & 8) !== 0);
            } else {
                $this->route($connection->connect, $message, $connection->identity);
            }
        } elseif ($head === 0x40) {
            $this->acknowledged($connection, $payload);
        } elseif (in_array($head, [0x50, 0x62, 0x70], true)) {
            $this->qos2Acknowledgement($connection, $head, $payload);
        } elseif ($head === 0x82 || $head === 0xa2) {
            $this->subscriptions($connection, $payload, $head === 0x82);
        } elseif ($head === 0xc0 && $payload === '') {
            $connection->send("\xd0\x00");
        } elseif ($head === 0xe0) {
            $connection->closeReason = 0;
            $reason = 0;
            if ($connection->connect->version === 4) {
                if ($payload !== '') {
                    throw new ProtocolError();
                }
            } elseif ($payload !== '') {
                $reader = new PacketReader($payload);
                $reason = $reader->integer(1);
                $connection->closeReason = $reason;
                if (!in_array($reason, [0, 4, 0x80, 0x81, 0x82, 0x83, 0x87, 0x8f, 0x90, 0x93, 0x94, 0x95, 0x96, 0x97, 0x98, 0x99], true)) {
                    throw new ProtocolError(0x82);
                }
                $properties = $reader->remaining() > 0 ? $reader->properties('disconnect') : [];
                if ($reader->remaining() !== 0 || (isset($properties[0x11]) && $connection->sessionExpiry === 0 && (int) $properties[0x11] !== 0)) {
                    throw new ProtocolError(0x82);
                }
                if (isset($properties[0x11])) {
                    $connection->sessionExpiry = (int) $properties[0x11];
                }
            }
            $connection->endReason = $reason === 4 ? 'will_requested' : 'normal';
            $connection->close();
        } else {
            // 未实现的控制类型不返回假成功。
            throw new ProtocolError(0x82);
        }
    }

    /** 先完整校验报文，再按 Topic 独立授权和变更；避免畸形尾部产生部分订阅副作用。 */
    private function subscriptions(Connection $connection, string $payload, bool $subscribe): void
    {
        $reader = new PacketReader($payload);
        $identifier = $reader->integer(2);
        if ($identifier === 0) {
            throw new ProtocolError(0x82);
        }
        $properties = [];
        if ($connection->connect->version === 5) {
            $properties = $reader->properties($subscribe ? 'subscribe' : 'unsubscribe');
        }
        $subscriptionIdentifier = $connection->connect->version === 5 ? (int) ($properties[0x0b] ?? 0) : 0;
        $topics = [];
        while ($reader->remaining() > 0) {
            $topic = $reader->text();
            $options = $subscribe ? $reader->integer(1) : 0;
            if (($options >> 6) !== 0) {
                throw new ProtocolError(0x81);
            }
            if ($topic === '' || ($options & 3) === 3 || (($options >> 4) & 3) === 3
                || ($connection->connect->version === 4 && ($options & 0xfc) !== 0)) {
                throw new ProtocolError(0x82);
            }
            $filter = TopicFilter::actual($topic);
            if (str_starts_with($topic, '$share/')) {
                if (($options & 4) !== 0) {
                    throw new ProtocolError(0x82);
                }
            }
            TopicFilter::validate($filter);
            if ($subscribe && $connection->connect->version === 5) {
                if (str_starts_with($topic, '$share/') && $this->workerCommand === []) {
                    throw new ProtocolError(0x9e);
                }
            }
            $topics[] = [$topic, $options];
        }
        if ($topics === []) {
            throw new ProtocolError(0x82);
        }
        $inUse = isset($connection->incoming[$identifier]) || isset($connection->incomingQos2[$identifier]);
        if ($inUse && $connection->connect->version === 4) {
            throw new ProtocolError(0x82);
        }
        $reasons = '';
        $original = $connection->subscriptions;
        $retained = [];
        foreach ($topics as $subscription) {
            $topic = $subscription[0];
            $options = $subscription[1];
            $key = 't:' . $topic;
            $reason = 0;
            if ($inUse) {
                $reason = 0x91;
            } elseif (!$subscribe) {
                $reason = isset($connection->subscriptions[$key]) ? 0 : 0x11;
                unset($connection->subscriptions[$key]);
            } elseif (str_starts_with($topic, '$share/') && $connection->connect->version === 4) {
                $reason = 0x9e;
            } else {
                try {
                    if (!$this->subscriptionAuthorized($connection, $topic, $options & 3)) {
                        $reason = 0x87;
                    } elseif (!isset($connection->subscriptions[$key]) && count($connection->subscriptions) >= $this->maximumSubscriptions) {
                        $reason = 0x97;
                    } else {
                        $handling = ($options >> 4) & 3;
                        $replay = $this->workerCommand !== [] && !str_starts_with($topic, '$share/')
                            && ($handling === 0 || ($handling === 1 && !isset($connection->subscriptions[$key])));
                        if ($replay && count($connection->retainedTopics) + count($retained) >= 100) {
                            $reason = 0x97;
                        } else {
                            $reason = min($options & 3, $this->workerCommand === [] ? 0 : 2);
                            $connection->subscriptions[$key] = ['options' => ($options & ~3) | $reason, 'identifier' => $subscriptionIdentifier];
                            if ($replay) {
                                $retained[] = ['snapshot_id' => bin2hex(random_bytes(16)), 'topic' => $topic, 'subscription' => $connection->subscriptions[$key], 'cursor' => ''];
                            }
                        }
                    }
                } catch (ProtocolError $error) {
                    $reason = $error->reason;
                }
            }
            $this->subscriptionQuotaRefusals += $reason === 0x97 ? 1 : 0;
            $reasons .= chr($connection->connect->version === 4 && $reason >= 128 ? 128 : $reason);
        }
        $body = pack('n', $identifier) . ($connection->connect->version === 5 ? "\x00" : '')
            . ($subscribe || $connection->connect->version === 5 ? $reasons : '');
        $packet = ($subscribe ? "\x90" : "\xb0") . PacketReader::encodeVariable(strlen($body)) . $body;
        if ($connection->sessionManaged) {
            $desired = $connection->subscriptions;
            $retained = array_values(array_filter($retained, static fn (array $snapshot): bool => ($desired['t:' . $snapshot['topic']] ?? null) === $snapshot['subscription']));
            $connection->subscriptions = $original;
            $connection->sessionPending = true;
            $connection->sessionOpening = true;
            $this->sessionCommit($connection, 'session_save', ['subscriptions' => $desired, 'retained' => $retained], $packet);
        } else {
            $connection->send($packet);
            $this->observeSubscriptions($connection, $original);
        }
    }

    /** 接管等待旧命令的最终结果；数据库所有者校验仍是拒绝陈旧命令的最终边界。 */
    private function sessionBusy(Connection $connection): bool
    {
        foreach ($this->commits as $commit) {
            if (($commit['connection'] ?? null) === $connection || ($commit['publisher'] ?? null) === $connection) {
                return true;
            }
            foreach ($commit['targets'] ?? [] as $target) {
                if ($target['connection'] === $connection) {
                    return true;
                }
            }
        }
        return false;
    }

    private function openSession(Connection $connection): void
    {
        foreach ($this->connections as $other) {
            if ($other !== $connection && $other->connect->clientId === $connection->connect->clientId
                && ($other->alive() || $this->sessionBusy($other))) {
                return;
            }
        }
        foreach ($this->closingSessions as $closing) {
            // Clean Start / 期限 0 的新连接不堵在旧会话收尾上；存储侧仍按 client_id 串行。
            if ($closing->connect->clientId === $connection->connect->clientId
                && !($connection->connect->cleanStart || $connection->sessionExpiry === 0)) {
                return;
            }
        }
        foreach ($this->commits as $commit) {
            if (isset($commit['connection']) && $commit['connection']->connect->clientId === $connection->connect->clientId) {
                return;
            }
        }
        try {
            $this->sessionCommit($connection, 'session_open', ['client_id' => $connection->connect->clientId,
                'protocol' => $connection->connect->version, 'clean_start' => $connection->connect->cleanStart,
                'expiry' => $connection->sessionExpiry, 'principal' => $connection->connect->username ?? '', 'node_id' => $this->nodeId,
                'capacity_class' => $connection->capacityClass,
                'access_identity' => $connection->identity?->data(),
                'will' => $connection->connect->will ? ['topic' => $connection->connect->willTopic, 'payload' => base64_encode($connection->connect->willPayload),
                    'properties' => base64_encode($connection->connect->willPublicationProperties), 'qos' => $connection->connect->willQos,
                    'retain' => $connection->connect->willRetain, 'delay' => (int) ($connection->connect->willProperties[0x18] ?? 0),
                    'username' => $connection->connect->username] : null]);
            $connection->sessionOpening = true;
        } catch (ProtocolError $error) {
            $this->connack($connection, $error->reason);
        }
    }

    private function sessionCommit(Connection $connection, string $action, array $request, string $response = ''): void
    {
        if (count($this->commits) + $this->quarantinedCommits >= 32) {
            throw new ProtocolError(0x97);
        }
        $operationId = bin2hex(random_bytes(16));
        $request['operation_id'] = $operationId;
        $request['action'] = $action;
        $request['session_id'] = $connection->sessionId;
        $request['owner_id'] = $connection->ownerId;
        if (in_array($action, ['session_open', 'session_save'], true)) {
            $request['resource_scope'] = $connection->resourceScope;
        }
        if ($this->options->clustered) {
            $request['node_id'] = $this->nodeId;
            $request['node_run_id'] = $this->nodeRunId;
        }
        try {
            $this->commits[$operationId] = ['pending' => new PendingCommit($this->workerCommand, $request),
                'kind' => $action, 'connection' => $connection, 'response' => $response, 'retained' => $request['retained'] ?? []];
        } catch (\Throwable) {
            throw new ProtocolError(0x88);
        }
    }

    /** 每连接至多一个共享读取/领取，游标每次一条；窗口满时继续处理控制报文。 */
    private function pollShared(Connection $connection): void
    {
        if (!$connection->connected || $connection->closing || !$connection->sessionManaged || $connection->connect->version !== 5
            || $connection->sessionPending || $this->sessionBusy($connection) || (float) hrtime(true) / 1000000000.0 < $connection->sharedAt
            || count($this->commits) + $this->quarantinedCommits >= 32
            || count($connection->outgoing) >= min(32, (int) ($connection->connect->properties[0x21] ?? 65535))) {
            return;
        }
        foreach ($connection->subscriptions as $key => $subscription) {
            if (str_starts_with($key, 't:$share/')) {
                try {
                    $this->sessionCommit($connection, 'shared_next', ['cursor' => $connection->sharedCursor]);
                    $connection->sharedTurn = false;
                } catch (ProtocolError $error) {
                    $this->disconnect($connection, $error->reason);
                }
                return;
            }
        }
    }

    private function sharedResult(array $commit, CommitResult $result): void
    {
        $connection = $commit['connection'];
        $connection->sharedAt = (float) hrtime(true) / 1000000000.0 + 0.25;
        if ($commit['kind'] === 'shared_claim') {
            $identifier = (int) $commit['response'];
            if ($result->state !== 'committed' || !isset($result->value['delivery'])) {
                unset($connection->outgoing[$identifier]);
            }
            if ($result->state === 'unknown') {
                $connection->hadDeliveries = true;
            }
            if (isset($result->value['delivery'])) {
                $connection->hadDeliveries = true;
                $connection->sharedCursor = '';
            }
            $this->sessionResult($commit, $result);
            return;
        }
        if ($result->state !== 'committed' || !$result->released) {
            $this->disconnect($connection, $result->reason ?: 0x88);
            return;
        }
        $connection->sharedCursor = $result->value['cursor'] ?? '';
        if ($connection->closing || !isset($result->value['delivery'])) {
            return;
        }
        $delivery = $result->value['delivery'];
        $subscription = $result->value['subscription'];
        if (($connection->subscriptions['t:' . $delivery['shared_filter']] ?? null) !== $subscription) {
            return;
        }
        try {
            if (!$this->subscriptionAuthorized($connection, $delivery['shared_filter'], $subscription['options'] & 3)
                || !$this->authorized($connection->connect, $delivery['topic'], 'subscribe', $delivery['qos'], $connection->identity)) {
                return;
            }
            $elapsed = $delivery['expires_at'] === null ? $delivery['age']
                : max(0, (int) $delivery['expiry_interval'] - max(0, $delivery['expires_at'] - time()));
            $message = new Message($delivery['topic'], (string) base64_decode($delivery['payload'], true), (string) base64_decode($delivery['properties'], true), $delivery['qos'], $elapsed);
            if ($connection->publication(
                $message,
                $delivery['qos'] > 0 ? 1 : 0,
                $delivery['qos'],
                $this->options->maximumPacketBytes,
                false,
                false,
                $delivery['retain'],
                false,
                $delivery['subscription_identifiers']
            ) === '') {
                // MQTT 5 允许超出所选成员包上限时终结该组副本，不能截断或无限占用。
                $this->sessionCommit($connection, 'shared_drop', ['delivery_id' => $delivery['id']]);
                $this->dropped++;
                return;
            }
            if ($delivery['qos'] > 0 && count($connection->outgoing) >= min(32, (int) ($connection->connect->properties[0x21] ?? 65535))) {
                return;
            }
            $identifier = $delivery['qos'] > 0 ? $connection->reserve($delivery['id'], $delivery['qos']) : 0;
            try {
                $this->sessionCommit($connection, 'shared_claim', ['delivery_id' => $delivery['id'], 'packet_id' => $identifier, 'subscription' => $subscription], (string) $identifier);
            } catch (ProtocolError $error) {
                unset($connection->outgoing[$identifier]);
                throw $error;
            }
        } catch (ProtocolError $error) {
            $this->disconnect($connection, $error->reason);
        }
    }

    private function sessionResult(array $commit, CommitResult $result): void
    {
        $connection = $commit['connection'];
        if ($commit['kind'] === 'session_next') {
            $connection->replaying = false;
            $connection->replayAt = (float) hrtime(true) / 1000000000.0 + ($result->value === [] || isset($result->value['checked']) ? 0.25 : 0.0);
            if ($result->value === [] && $result->state === 'committed') {
                $connection->draining = false;
            }
        }
        if ($result->state !== 'committed' || !$result->released) {
            if ($commit['kind'] === 'session_open' && $result->state === 'unknown') {
                // 未知打开可能已保存新所有者，但没有返回恢复后的 session_id；结束路径按客户端和所有者精确取消。
                $connection->sessionOpened = true;
                $connection->sessionManaged = true;
            }
            if (!$connection->connected) {
                $this->connack($connection, $result->reason ?: 0x88);
                if (!$connection->alive()) {
                    $this->finishSession($connection);
                }
            } else {
                $this->disconnect($connection, $result->reason ?: 0x88);
            }
            return;
        }
        if ($commit['kind'] === 'session_open') {
            $connection->sessionId = $result->value['session_id'];
            $connection->sessionGeneration = $result->value['generation'] ?? 0;
            $connection->sessionPresent = $result->value['present'];
            $connection->draining = $connection->sessionExpiry !== 0 || $connection->sessionPresent;
            $connection->sessionOpened = true;
            $connection->sessionManaged = true;
            if ($connection->closing) {
                $this->finishSession($connection);
                return;
            }
            foreach ($result->value['incoming'] as $exchange) {
                $connection->incomingQos2[(int) $exchange['packet_id']] = ['id' => $exchange['id'], 'state' => 'received', 'responses' => 1];
            }
            foreach ($result->value['identifiers'] as $reserved) {
                $connection->durableIdentifiers[(int) $reserved['packet_id']] = true;
            }
            try {
                foreach ($result->value['subscriptions'] as $key => $options) {
                    $subscription = TopicFilter::subscription($options);
                    if ($this->subscriptionAuthorized($connection, substr($key, 2), $subscription['options'] & 3)) {
                        $connection->subscriptions[$key] = $subscription;
                    }
                }
                // 恢复后的授权结果先同步保存；删除的订阅不能在下次恢复时重新出现。
                $this->sessionCommit($connection, 'session_save', ['subscriptions' => $connection->subscriptions]);
            } catch (ProtocolError $error) {
                $this->connack($connection, $error->reason);
            }
            return;
        }
        if ($connection->closing) {
            return;
        }
        if ($commit['kind'] === 'session_save') {
            if ($result->value['waiting'] ?? false) {
                $connection->sessionRetryAt = (float) hrtime(true) / 1000000000.0 + 0.25;
                return;
            }
            $connection->sessionPending = false;
            $connection->sessionOpening = false;
            $previousSubscriptions = $connection->subscriptions;
            $connection->subscriptions = $result->value['subscriptions'];
            $this->observeSubscriptions($connection, $previousSubscriptions);
            if (!$connection->connected) {
                $connection->retainedTopics = $result->value['retained'];
            } else {
                $snapshots = [];
                foreach ($connection->retainedTopics as $snapshot) {
                    if (($connection->subscriptions['t:' . $snapshot['topic']] ?? null) === $snapshot['subscription']) {
                        $snapshots[] = $snapshot;
                    }
                }
                $requested = array_column($commit['retained'], 'snapshot_id');
                foreach ($result->value['retained'] as $snapshot) {
                    if (in_array($snapshot['snapshot_id'], $requested, true)) {
                        $snapshots[] = $snapshot;
                    }
                }
                $connection->retainedTopics = $snapshots;
            }
            if (!$connection->connected) {
                $this->connack($connection, 0);
                $connection->connected = true;
                $connection->activity();
            } else {
                $connection->send($commit['response']);
            }
            return;
        }
        if (!isset($result->value['delivery'])) {
            return;
        }
        $delivery = $result->value['delivery'];
        $identifier = $delivery['packet_id'];
        $qos = $delivery['qos'];
        try {
            if (!$this->authorized($connection->connect, $delivery['topic'], 'subscribe', $qos, $connection->identity)
                || (($delivery['shared_filter'] ?? '') !== '' && !$this->subscriptionAuthorized($connection, $delivery['shared_filter'], $qos))) {
                // 撤销授权的未完成交付不能再输出；清理整个会话避免隐式恢复旧授权。
                $connection->sessionExpiry = 0;
                $this->disconnect($connection, 0x87);
                return;
            }
            if ($qos > 0) {
                $connection->outgoing[$identifier] = ['id' => $delivery['id'], 'qos' => $qos,
                    'state' => $delivery['phase'] === 'wait_pubcomp' ? 'released' : 'sent', 'responses' => 1];
                $connection->durableIdentifiers[$identifier] = true;
            }
            if ($delivery['phase'] === 'wait_pubcomp') {
                $connection->send("\x62\x02" . pack('n', $identifier));
            } else {
                // 同步读取本身也会等待；按绝对截止扣除该等待，不能从数据库读取时重新计时。
                $elapsed = $delivery['expires_at'] === null ? $delivery['age']
                    : max(0, (int) $delivery['expiry_interval'] - max(0, $delivery['expires_at'] - time()));
                $message = new Message(
                    $delivery['topic'],
                    (string) base64_decode($delivery['payload'], true),
                    (string) base64_decode($delivery['properties'], true),
                    $qos,
                    $elapsed
                );
                $packet = $connection->publication(
                    $message,
                    $identifier,
                    $qos,
                    $this->options->maximumPacketBytes,
                    true,
                    $delivery['duplicate'],
                    $delivery['retain'],
                    $delivery['duplicate'],
                    $delivery['subscription_identifiers'] ?? []
                );
                if ($packet === '') {
                    if (!$this->completeDelivery($connection, $delivery['id'], $identifier, 0, 'expired')) {
                        $this->disconnect($connection, 0x97);
                    } else {
                        $connection->outgoing[$identifier]['state'] = 'completing';
                    }
                } else {
                    $this->delivered++;
                    if ($qos === 0 && !$this->completeDelivery($connection, $delivery['id'], 0, 0, 'queued')) {
                        $this->disconnect($connection, 0x97);
                    }
                }
            }
        } catch (ProtocolError $error) {
            $this->disconnect($connection, $error->reason);
        }
    }

    private function connack(Connection $connection, int $reason): void
    {
        // Session taken over属于DISCONNECT；尚未接纳的CONNECT使用Implementation specific error。
        $reason = $reason === 0x8e ? 0x83 : $reason;
        if ($reason !== 0 && !$connection->connected) {
            // 已保存但尚未接纳的 CONNECT 在后续授权/同步失败时取消遗嘱。
            $connection->endReason = 'normal';
        }
        if ($connection->connect->version === 4) {
            $code = match ($reason) {
                0 => 0, 0x84 => 1, 0x85 => 2, 0x86 => 4, 0x87 => 5, default => 3
            };
            // MQTT 3.1.1 格式错误不发送其没有定义的原因码。
            if (in_array($reason, [0x81, 0x82, 0x90, 0x95, 0x99], true)) {
                $connection->close();
                return;
            }
            $connection->send("\x20\x02" . chr($reason === 0 && $connection->sessionPresent ? 1 : 0) . chr($code));
        } else {
            $properties = '';
            if ($reason === 0) {
                // 只声明连接切片已具有的能力；省略 Session Expiry 保留客户端默认零。
                $properties = "\x21\x00\x20\x22\x00\x20\x27" . pack('N', $this->options->maximumPacketBytes)
                    . ($this->workerCommand === [] ? "\x24\x00\x25\x00" : '') . "\x28\x01\x29\x01\x2a" . ($this->workerCommand === [] ? "\x00" : "\x01");
                if ($connection->connect->assignedIdentifier) {
                    $properties .= "\x12" . pack('n', strlen($connection->connect->clientId)) . $connection->connect->clientId;
                }
            }
            $body = chr($reason === 0 && $connection->sessionPresent ? 1 : 0) . chr($reason) . PacketReader::encodeVariable(strlen($properties)) . $properties;
            $connection->send("\x20" . PacketReader::encodeVariable(strlen($body)) . $body);
        }
        if ($reason !== 0) {
            $connection->closing = true;
        } elseif ($connection->alive()) {
            $connection->connackRemaining = strlen($connection->output);
        }
    }

    private function disconnect(Connection $connection, int $reason): void
    {
        $connection->closeReason = $reason;
        if (!$connection->closing) {
            $connection->endReason = match ($reason) {
                0x8e => 'taken_over', 0x8b => 'server_shutdown', 0x98 => 'administrative', default => 'network_lost'
            };
        }
        if ($connection->connected && $connection->connect->version === 5 && $connection->alive()) {
            // MQTT 5 表 3-23：CONNACK 的 Server unavailable 及 ACK 的 Identifier in use 不属于 DISCONNECT 原因。
            $reason = match ($reason) {
                0x88 => 0x83, 0x91 => 0x82, default => $reason
            };
            $connection->send("\xe0\x02" . chr($reason) . "\x00");
            $connection->closing = true;
        } else {
            $connection->close();
        }
    }

    /**
     * @return array{0:int,1:int} 当前活连接数与同类占用（不含本连接及同 Client ID 接管）。
     */
    private function capacityCounts(Connection $connection, string $capacityClass): array
    {
        $live = 0;
        $occupied = 0;
        foreach ($this->connections as $otherConnection) {
            if ($otherConnection === $connection || $otherConnection->closing) {
                continue;
            }
            $live++;
            if ($otherConnection->capacityClass === $capacityClass
                && $otherConnection->connect->clientId !== $connection->connect->clientId) {
                $occupied++;
            }
        }
        return [$live, $occupied];
    }

    /** 已认证的调试连接可被业务服务抢占；第三方仍按其真实身份分类。 */
    private function debugIdentity(Connection $connection): bool
    {
        return $connection->identity !== null && $connection->identity->authenticationMethod === 'debug';
    }

    /** 本节点让出一个调试服务名额；不踢设备或其他业务服务，也不跨进程抢占。 */
    private function releaseDebugSlot(): bool
    {
        foreach ($this->connections as $other) {
            if ($other->closing || $other->capacityClass !== 'application' || !$this->debugIdentity($other)) {
                continue;
            }
            $this->disconnect($other, 0x98);
            return true;
        }
        return false;
    }
}
