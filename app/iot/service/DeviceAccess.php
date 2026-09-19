<?php

declare(strict_types=1);

namespace app\iot\service;

use app\broker\service\ConnectionOperations;
use app\broker\service\QuotaService;
use app\broker\service\ResourceObservations;
use app\common\service\AuditLog;
use RuntimeException;
use Type\Mqtt\AccessIdentity;
use Type\Mqtt\AuthorizationInvalidations;
use Type\Mqtt\CertificateAccessPolicy;
use Type\Mqtt\ConnectPacket;
use Type\Mqtt\ConnectionDisconnects;
use Type\Mqtt\PendingCommit;
use Type\Mqtt\ProtocolError;
use Type\Mqtt\QuotaUpdates;
use Type\Mqtt\ResourceAccessPolicy;
use Type\Mqtt\ResourceConnectionObserver;
use Type\Orm\DatabaseManager;
use Type\Orm\Connection;
use Type\Runtime\Deadline;
use Type\Runtime\ExecutionScope;

/**
 * 设备接入的进程边界；同步策略每次只持有一个有硬截止的工作进程，不让PDO占住网络进程。
 * 凭据仅以验证值进入私有管道；超时后精确清理后端并永久停止本实例，未知结果不重试。
 */
final class DeviceAccess implements ResourceAccessPolicy, CertificateAccessPolicy, ResourceConnectionObserver, AuthorizationInvalidations, ConnectionDisconnects, QuotaUpdates
{
    /** 单条 MQTT 过滤器最多 65535 字节；按 JSON 每字节至多六字节转义，再留 4KiB 信封与换行。 */
    private const MAXIMUM_REQUEST_BYTES = 65535 * 6 + 4096;

    private string $runId;
    private bool $initial = true;
    private bool $failed = false;
    private ?\Closure $statistics = null;
    private int $startedAt;
    private ?AccessIdentity $authenticatedIdentity = null;
    private string $authenticatedClient = '';
    private string $authenticatedScope = '';

    /**
     * @param list<string> $command 当前应用的显式可执行入口；不经shell、不猜测PHP_BINARY。
     * @param array<string, int> $limits 本次进程实际启动额度；缺省沿用规格默认值。
     */
    public function __construct(private array $command, private string $nodeId, private array $limits = [])
    {
        if (PHP_OS_FAMILY === 'Windows') {
            throw new RuntimeException('设备接入工作进程当前需要Unix非阻塞管道');
        }
        $this->runId = bin2hex(random_bytes(16));
        $this->startedAt = hrtime(true);
    }

    /** 由应用绑定Broker公开统计；退出时解除闭包，避免保留网络对象。 */
    public function monitor(?\Closure $statistics): void
    {
        $this->statistics = $statistics;
    }

    /** CONNECT必须来自真实TLS；校验成功不写在线或改变设备生命周期。 */
    public function authenticate(ConnectPacket $connect, string $peer, bool $secure): bool
    {
        return $this->authenticateIdentity($connect, $peer, $secure) !== null;
    }

    /** 稳定主体及凭据代次只接受真实数据库认证结果，不在网络进程从用户名猜测。 */
    public function authenticateIdentity(ConnectPacket $connect, string $peer, bool $secure): ?AccessIdentity
    {
        $this->authenticatedIdentity = null;
        $this->authenticatedClient = '';
        $this->authenticatedScope = '';
        $result = $this->request(['action' => 'authenticate', 'client_id' => self::clientId($connect->clientId), 'username' => self::username($connect->username ?? ''),
            'verifier' => hash('sha256', $connect->password ?? ''), 'protocol' => $connect->version, 'secure' => $secure,
            'keep_alive' => $connect->keepAlive, 'expiry' => (int) ($connect->properties[0x11] ?? 0),
            'clean_start' => $connect->cleanStart, 'will' => $connect->will]);
        if (!$result['allowed']) {
            if ((string) ($result['reason'] ?? '') === 'quota') {
                throw new ProtocolError(0x97);
            }
            return null;
        }
        if (!is_array($result['access_identity'] ?? null) || !is_string($result['resource_scope'] ?? null)
            || preg_match('/^(?:iot:[a-f0-9]{32}|service:ingestion)$/D', $result['resource_scope']) !== 1) {
            throw new RuntimeException('device_access_identity_invalid');
        }
        $identity = AccessIdentity::fromData($result['access_identity']);
        $this->authenticatedIdentity = $identity;
        $this->authenticatedClient = $connect->clientId;
        $this->authenticatedScope = $result['resource_scope'];
        return $identity;
    }

    /** 专用 mTLS 入口按已登记指纹认证；证书身份的主体必须是当前设备。 */
    public function authenticateCertificate(ConnectPacket $connect, string $peer, string $fingerprint): ?AccessIdentity
    {
        $this->authenticatedIdentity = null;
        $this->authenticatedClient = '';
        $this->authenticatedScope = '';
        $result = $this->request(['action' => 'authenticate_certificate', 'client_id' => self::clientId($connect->clientId),
            'username' => self::username($connect->username ?? ''), 'fingerprint' => $fingerprint,
            'protocol' => $connect->version, 'secure' => true, 'keep_alive' => $connect->keepAlive,
            'expiry' => (int) ($connect->properties[0x11] ?? 0)]);
        if (!$result['allowed']) {
            return null;
        }
        if (!is_array($result['access_identity'] ?? null) || !is_string($result['resource_scope'] ?? null)
            || preg_match('/^(?:iot:[a-f0-9]{32}|service:ingestion)$/D', $result['resource_scope']) !== 1) {
            throw new RuntimeException('device_access_identity_invalid');
        }
        $identity = AccessIdentity::fromData($result['access_identity']);
        $this->authenticatedIdentity = $identity;
        $this->authenticatedClient = $connect->clientId;
        $this->authenticatedScope = $result['resource_scope'];
        return $identity;
    }

    /** 只消费紧邻认证的单份数据库结果；不二次认证，也不从客户端声明推导租户。 */
    public function resourceScope(AccessIdentity $identity, ConnectPacket $connect): ?string
    {
        if ($this->authenticatedIdentity === null || !$this->authenticatedIdentity->matches($identity)
            || $this->authenticatedClient !== $connect->clientId || $this->authenticatedScope === '') {
            throw new RuntimeException('device_resource_scope_unavailable');
        }
        $scope = $this->authenticatedScope;
        $this->authenticatedIdentity = null;
        $this->authenticatedClient = '';
        $this->authenticatedScope = '';
        return $scope;
    }

    /** 已认证身份每次发布、订阅及恢复投递都重验凭据状态、生命周期和归属，不保存或再次传递秘密。 */
    public function authorize(ConnectPacket $connect, string $topic, string $action, int $qos): bool
    {
        return $this->request(['action' => 'authorize', 'client_id' => self::clientId($connect->clientId), 'username' => self::username($connect->username ?? ''),
            'topic' => strlen($topic) <= 256 ? $topic : '', 'operation' => $action, 'qos' => $qos])['allowed'];
    }

    /** 显式核对认证快照与当前凭据记录；同用户名的新代次不能获得旧连接的授权。 */
    public function authorizeIdentity(AccessIdentity $identity, ConnectPacket $connect, string $topic, string $action, int $qos): bool
    {
        return $this->request(['action' => 'authorize', 'client_id' => self::clientId($connect->clientId), 'username' => self::username($connect->username ?? ''),
            'access_identity' => $identity->data(), 'topic' => strlen($topic) <= 256 ? $topic : '', 'operation' => $action, 'qos' => $qos])['allowed'];
    }

    /** 只有Broker完整输出成功CONNACK且仍持有连接后调用。 */
    public function connected(string $clientId, string $username, string $ownerId, int $observedAt): void
    {
        if (!$this->request(['action' => 'connected', 'client_id' => $clientId, 'username' => $username, 'owner_id' => $ownerId, 'observed_at' => $observedAt])['allowed']) {
            $this->failed = true;
            throw new RuntimeException('device_observation_rejected');
        }
    }

    /** 完整 CONNACK 后由同一受控工作进程提交设备激活与无秘密资源观察。 */
    public function connectedResource(array $resource, int $observedAt): void
    {
        if (!$this->request(['action' => 'connected', 'client_id' => $resource['client_id'], 'username' => $resource['username'],
            'owner_id' => $resource['owner_id'], 'access_identity' => $resource['access_identity'],
            'resource' => $resource, 'observed_at' => $observedAt])['allowed']) {
            $this->failed = true;
            throw new RuntimeException('device_observation_rejected');
        }
    }

    /** Broker 仅上报已授权生效的单条订阅变化；精确运行和连接所有者由投影重验。 */
    public function subscriptionResource(string $ownerId, string $filter, ?array $subscription, int $observedAt): void
    {
        if (!$this->request(['action' => 'subscription', 'owner_id' => $ownerId, 'filter' => $filter,
            'subscription' => $subscription, 'observed_at' => $observedAt])['allowed']) {
            $this->failed = true;
            throw new RuntimeException('device_observation_rejected');
        }
    }

    /** 精确连接所有者匹配的关闭事实；超时或依赖故障不能伪造离线。 */
    public function disconnected(string $clientId, string $username, string $ownerId, int $observedAt, int $reason): void
    {
        $this->request(['action' => 'disconnected', 'client_id' => $clientId, 'username' => $username, 'owner_id' => $ownerId,
            'observed_at' => $observedAt, 'reason' => $reason]);
    }

    /** 每次只更新本节点一行，15秒后无新观察则在线状态为未知。 */
    public function heartbeat(int $observedAt): void
    {
        $this->request(['action' => 'heartbeat', 'observed_at' => $observedAt, 'initial' => $this->initial] + $this->observation());
        $this->initial = false;
    }

    /** 正常退出使本运行代次立即失去活性；故障实例保留未知而不补写假离线。 */
    public function stopped(int $observedAt): void
    {
        if (!$this->initial) {
            $this->request(['action' => 'stopped', 'observed_at' => $observedAt, 'initial' => false] + $this->observation());
        }
    }

    /** 把公开统计附到心跳；未绑定统计入口则不上报。 */
    private function observation(): array
    {
        return $this->statistics === null ? [] : ['metrics' => ($this->statistics)() + ['uptimeMs' => (hrtime(true) - $this->startedAt) / 1e6]];
    }

    /** 一次读取一项持久撤权意图，不扫描设备或把普通连接观察当作授权事实。 */
    public function nextInvalidation(): ?array
    {
        return $this->request(['action' => 'invalidation_next'])['invalidation'];
    }

    /** Broker已取得旧会话终止证明后，幂等保存本节点完成时间。 */
    public function invalidationCompleted(string $id): void
    {
        $this->request(['action' => 'invalidation_completed', 'invalidation_id' => $id]);
    }

    /** 一次读取管理库当前最新配额快照；响应仍受 1KiB 预算约束。 */
    public function nextQuota(): ?array
    {
        $result = $this->request(['action' => 'quota_next', 'limits' => $this->limits]);
        $quota = $result['quota'] ?? null;
        if (!is_array($quota) || !is_int($quota['version'] ?? null) || $quota['version'] < 1 || !is_array($quota['limits'] ?? null)) {
            return null;
        }
        $limits = [];
        foreach ($quota['limits'] as $key => $value) {
            if (!is_string($key) || !is_int($value)) {
                return null;
            }
            $limits[$key] = $value;
        }
        return ['version' => $quota['version'], 'limits' => $limits];
    }

    /** 一次读取本节点尚未完成的精确断开，不扫描设备表。 */
    public function nextDisconnect(): ?array
    {
        $result = $this->request(['action' => 'disconnect_next']);
        $disconnect = $result['disconnect'] ?? null;
        if (!is_array($disconnect)) {
            return null;
        }
        $disconnect['session_generation'] = (int) ($disconnect['session_generation'] ?? -1);
        return $disconnect;
    }

    /** Broker 已关闭匹配网络且观察行消失后保存完成事实。 */
    public function disconnectCompleted(string $id, string $outcome): bool
    {
        $result = $this->request(['action' => 'disconnect_completed', 'disconnect_id' => $id, 'outcome' => $outcome]);
        return ($result['completed'] ?? false) === true;
    }

    /** 一次读取本节点尚未完成的精确会话终止。 */
    public function nextTermination(): ?array
    {
        $result = $this->request(['action' => 'terminate_next']);
        $termination = $result['termination'] ?? null;
        if (!is_array($termination)) {
            return null;
        }
        $termination['session_generation'] = (int) ($termination['session_generation'] ?? -1);
        return $termination;
    }

    /** Broker 已关闭匹配网络并取得会话删除证明后保存完成事实。 */
    public function terminationCompleted(string $id, string $outcome): bool
    {
        $result = $this->request(['action' => 'terminate_completed', 'terminate_id' => $id, 'outcome' => $outcome]);
        return ($result['completed'] ?? false) === true;
    }

    /** 一次读取尚未完成的保留原件清除。 */
    public function nextClearance(): ?array
    {
        $result = $this->request(['action' => 'clear_next']);
        $clearance = $result['clearance'] ?? null;
        if (!is_array($clearance)) {
            return null;
        }
        $clearance['session_generation'] = (int) ($clearance['session_generation'] ?? -1);
        return $clearance;
    }

    /** Broker 已按代次删除当前原件或确认缺失后保存完成事实。 */
    public function clearanceCompleted(string $id, string $outcome): bool
    {
        $result = $this->request(['action' => 'clear_completed', 'clear_id' => $id, 'outcome' => $outcome]);
        return ($result['completed'] ?? false) === true;
    }

    /**
     * 当前应用的受控子进程入口；输入遵守父子共用固定预算、响应至多1KiB，数据库租约退出即归还。
     * 父进程负责三秒硬截止和不确定后端清理；客户端不能通过网络选择此角色。
     */
    public static function work(DatabaseManager $database, string $serviceCredential = '', string $serviceVerifier = ''): void
    {
        $line = fgets(STDIN, self::MAXIMUM_REQUEST_BYTES + 1);
        $request = is_string($line) && str_ends_with($line, "\n") ? json_decode($line, true, 8, JSON_THROW_ON_ERROR) : null;
        if (!is_array($request) || !in_array($request['action'] ?? '', ['authenticate', 'authenticate_certificate', 'authorize', 'connected', 'disconnected', 'subscription', 'heartbeat', 'stopped', 'invalidation_next', 'invalidation_completed', 'disconnect_next', 'disconnect_completed', 'terminate_next', 'terminate_completed', 'clear_next', 'clear_completed', 'quota_next'], true)
            || preg_match('/^[a-f0-9]{32}$/D', (string) ($request['operation_id'] ?? '')) !== 1) {
            throw new RuntimeException('device_access_request_invalid');
        }
        $scope = new ExecutionScope(new Deadline(2.0));
        try {
            $connection = $database->connect($scope);
            $connection->rawQuery("SELECT set_config('application_name', ?, false), set_config('statement_timeout', '1000', false), "
                . "set_config('lock_timeout', '500', false), set_config('idle_in_transaction_session_timeout', '1000', false), "
                . "set_config('synchronous_commit', 'remote_apply', false)", ['type_mqtt_' . $request['operation_id']]);
            if ($request['action'] === 'subscription') {
                ResourceObservations::subscription(
                    $connection,
                    $request['node_id'],
                    $request['run_id'],
                    $request['owner_id'],
                    $request['filter'],
                    $request['subscription'],
                    (int) $request['observed_at']
                );
                $result = ['allowed' => true];
            } elseif ($request['action'] === 'disconnect_next') {
                $result = ['allowed' => true, 'disconnect' => ConnectionOperations::next($connection, (string) $request['node_id'])];
            } elseif ($request['action'] === 'disconnect_completed') {
                $result = ['allowed' => true, 'completed' => ConnectionOperations::complete($connection, (string) $request['disconnect_id'], (string) $request['outcome'])];
            } elseif ($request['action'] === 'terminate_next') {
                $result = ['allowed' => true, 'termination' => ConnectionOperations::next($connection, (string) $request['node_id'], 'session_terminate')];
            } elseif ($request['action'] === 'terminate_completed') {
                $result = ['allowed' => true, 'completed' => ConnectionOperations::complete($connection, (string) $request['terminate_id'], (string) $request['outcome'])];
            } elseif ($request['action'] === 'quota_next') {
                $limits = QuotaService::defaults();
                if (is_array($request['limits'] ?? null)) {
                    foreach (array_keys($limits) as $key) {
                        if (is_int($request['limits'][$key] ?? null)) {
                            $limits[$key] = $request['limits'][$key];
                        }
                    }
                }
                QuotaService::ensureBootstrap($connection, $limits);
                $result = ['allowed' => true, 'quota' => QuotaService::currentSnapshot($connection)];
            } elseif ($request['action'] === 'clear_next') {
                $result = ['allowed' => true, 'clearance' => ConnectionOperations::next($connection, (string) $request['node_id'], 'retain_clear')];
            } elseif ($request['action'] === 'clear_completed') {
                $result = ['allowed' => true, 'completed' => ConnectionOperations::complete($connection, (string) $request['clear_id'], (string) $request['outcome'])];
            } else {
                $result = str_starts_with((string) ($request['username'] ?? ''), 'service:')
                    ? self::serviceAccess($connection, $request, $serviceCredential, $serviceVerifier)
                    : DeviceService::access($connection, $request);
            }
            if (in_array($request['action'], ['heartbeat', 'stopped'], true) && is_array($request['metrics'] ?? null)) {
                OperationsService::observe($connection, 'broker', $request['node_id'], $request['run_id'], $request['metrics'], $request['initial']);
            }
        } finally {
            $scope->close();
            $database->close();
        }
        echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
    }

    /**
     * 每次认证/授权只派生一个有硬截止的工作进程；超时精确清理后端并永久停止本实例。
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function request(array $request): array
    {
        if ($this->failed) {
            throw new RuntimeException('device_access_unavailable');
        }
        $operationId = bin2hex(random_bytes(16));
        $request += ['operation_id' => $operationId, 'node_id' => $this->nodeId, 'run_id' => $this->runId];
        $input = json_encode($request, JSON_THROW_ON_ERROR) . "\n";
        if (strlen($input) > self::MAXIMUM_REQUEST_BYTES) {
            return ['allowed' => false];
        }
        $pipes = [];
        $environment = getenv();
        $environment['PGAPPNAME'] = 'type_mqtt_' . $operationId;
        $process = proc_open(
            [...$this->command, 'iot:mqtt-access'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $environment,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            $this->failed = true;
            throw new RuntimeException('device_access_unavailable');
        }
        $output = '';
        $complete = false;
        $deadline = new Deadline(3.0);
        try {
            foreach ($pipes as $pipe) {
                if (!stream_set_blocking($pipe, false)) {
                    throw new RuntimeException('device_access_pipe_unavailable');
                }
            }
            do {
                if ($input !== '') {
                    $written = @fwrite($pipes[0], $input, min(strlen($input), 16384));
                    if ($written === false) {
                        break;
                    }
                    $input = substr($input, $written);
                }
                $chunk = @fread($pipes[1], 1025);
                if ($chunk === false) {
                    break;
                }
                $output .= $chunk;
                if (strlen($output) > 1024) {
                    break;
                }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    // 子进程退出后仍排空内核管道，不能丢失尾部响应。
                    $output .= (string) stream_get_contents($pipes[1], 1025);
                    $complete = $status['exitcode'] === 0 && strlen($output) <= 1024 && str_ends_with($output, "\n");
                    break;
                }
                usleep(1000);
            } while (!$deadline->expired());
        } catch (\Throwable) {
            $complete = false;
        } finally {
            if (!$complete) {
                proc_terminate($process, 9);
                $this->failed = true;
            }
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }
        if (!$complete) {
            // 复用持久worker的精确application_name清理，不把本地进程退出当作远端已回收。
            $cleanup = new PendingCommit([...$this->command, 'iot:mqtt-store'], ['operation_id' => $operationId, 'action' => 'cleanup']);
            do {
                $result = $cleanup->poll();
                if ($result === null) {
                    usleep(10000);
                }
            } while ($result === null);
            throw new RuntimeException($result->released ? 'device_access_unavailable' : 'device_access_backend_quarantined');
        }
        $data = json_decode($output, true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_bool($data['allowed'] ?? null)) {
            $this->failed = true;
            throw new RuntimeException('device_access_response_invalid');
        }
        return $data;
    }

    /** 只接受设备 id 或上报消费 Client ID；其他值当成未识别。 */
    private static function clientId(string $value): string
    {
        return preg_match('/^(?:[a-f0-9]{32}|iot-ingestion-[a-z0-9][a-z0-9_-]{0,39})$/D', $value) === 1 ? $value : '';
    }

    /** 只接受设备凭据用户名、上报服务用户名或调试短期凭据。 */
    private static function username(string $value): string
    {
        return preg_match('/^(?:[a-f0-9]{32}:[a-f0-9]{32}|service:ingestion:[a-f0-9]{32}|debug:[a-f0-9]{32})$/D', $value) === 1 ? $value : '';
    }

    /** 服务凭据独立于设备；只授予上报消费与当前阶段下行，不改变任何设备的激活或连接观察。 */
    private static function serviceAccess(Connection $connection, array $request, string $credential, string $verifier): array
    {
        $allowed = preg_match('/^[a-f0-9]{32}$/D', $credential) === 1 && preg_match('/^[a-f0-9]{64}$/D', $verifier) === 1
            && $request['username'] === 'service:ingestion:' . $credential
            && preg_match('/^iot-ingestion-[a-z0-9][a-z0-9_-]{0,39}$/D', $request['client_id']) === 1;
        $action = $request['action'];
        $identity = $allowed ? new AccessIdentity('service:ingestion', $credential, 1) : null;
        if ($allowed && isset($request['access_identity'])) {
            $allowed = is_array($request['access_identity']) && $identity->matches(AccessIdentity::fromData($request['access_identity']));
        }
        if ($allowed && $action === 'authenticate') {
            $allowed = hash_equals($verifier, $request['verifier']) && $request['secure'] && $request['protocol'] === 5
                && $request['keep_alive'] === 30 && $request['expiry'] === 86400;
        } elseif ($allowed && $action === 'authorize') {
            $topic = $request['topic'];
            $operation = $request['operation'];
            // Broker向策略传入已解析的实际过滤器，普通和共享均不能扩大服务职责。
            if ($operation === 'subscribe' && $topic === 'iot/+/devices/+/epochs/+/up') {
                return ['allowed' => true];
            }
            $parts = [];
            $allowed = in_array($operation, ['publish', 'subscribe'], true)
                && preg_match('#^iot/([a-f0-9]{32})/devices/([a-f0-9]{32})/epochs/([a-f0-9]{32})/(up|down)$#D', $topic, $parts) === 1;
            if ($allowed) {
                $device = $connection->table('iot_devices')->where('id', '=', $parts[2])->where('tenant_id', '=', $parts[1])
                    ->where('ownership_id', '=', $parts[3])->where('lifecycle', '=', 'enabled')->where('recovery_verified', '=', 1)->first();
                $allowed = $device !== null && $parts[4] === ($operation === 'publish' ? 'down' : 'up')
                    && $connection->table('iot_tenants')->where('id', '=', $parts[1])->where('enabled', '=', 1)->first() !== null;
            }
        }
        if (!$allowed) {
            AuditLog::append($connection, null, 'mqtt-service', 'service.access_denied', 'ingestion', 'denied', ['reason' => 'service_forbidden'], 'customer');
        }
        if ($allowed && $action === 'connected' && isset($request['resource'])) {
            if (($request['resource']['resource_scope'] ?? null) !== 'service:ingestion') {
                throw new RuntimeException('device_resource_scope_changed');
            }
            ResourceObservations::connected($connection, $request['node_id'], $request['run_id'], $request['resource'], (int) $request['observed_at']);
        } elseif ($action === 'disconnected') {
            ResourceObservations::disconnected($connection, $request['node_id'], $request['run_id'], $request['owner_id']);
        }
        return ['allowed' => $allowed] + ($allowed && $action === 'authenticate'
            ? ['access_identity' => $identity->data(), 'resource_scope' => 'service:ingestion'] : []);
    }
}
