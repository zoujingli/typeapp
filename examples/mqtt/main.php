<?php

declare(strict_types=1);

use Type\Mqtt\AccessIdentity;
use Type\Mqtt\CertificateAccessPolicy;
use Type\Mqtt\IdentityAccessPolicy;
use Type\Mqtt\Broker;
use Type\Mqtt\BrokerOptions;
use Type\Mqtt\ConnectPacket;
use Type\Mqtt\PendingCommit;
use Type\Mqtt\PostgresStore;
use Type\Orm\Pgsql\PgsqlDriver;
use Type\Runtime\Arguments;

/** 独立消费者显式提供认证；示例凭据从环境取得，不接受空密码或匿名连接。 */
final class ExampleMqttAccess implements IdentityAccessPolicy, CertificateAccessPolicy
{
    private string $password;
    private string $servicePassword;
    private string $username;
    /** @var list<string> */
    private array $clientFingerprints;
    private string $clientOverlap;
    private AccessIdentity $identity;
    private AccessIdentity $serviceIdentity;
    private AccessIdentity $certificateIdentity;

    /** 启动时读取数据配置；缺少凭据立即拒绝启动。 */
    public function __construct()
    {
        $this->password = (string) getenv('MQTT_PASSWORD');
        $this->servicePassword = (string) getenv('MQTT_SERVICE_PASSWORD');
        $this->username = (string) (getenv('MQTT_USERNAME') ?: 'example');
        $this->clientFingerprints = [];
        foreach (explode(',', (string) getenv('MQTT_CLIENT_FINGERPRINT')) as $item) {
            $item = strtolower(str_replace(':', '', trim($item)));
            if ($item === '') {
                continue;
            }
            if (preg_match('/^[0-9a-f]{64}$/D', $item) !== 1) {
                throw new RuntimeException('MQTT_CLIENT_FINGERPRINT 需要 64 位小写十六进制 SHA-256');
            }
            $this->clientFingerprints[] = $item;
        }
        $this->clientOverlap = (string) getenv('MQTT_CLIENT_OVERLAP');
        $this->identity = new AccessIdentity('example', (string) (getenv('MQTT_CREDENTIAL_ID') ?: 'example-account'), mqttBudget('MQTT_CREDENTIAL_VERSION', 1));
        $this->serviceIdentity = new AccessIdentity('example-service', 'service-account', 1);
        $this->certificateIdentity = new AccessIdentity(
            'example',
            (string) (getenv('MQTT_CLIENT_CREDENTIAL_ID') ?: 'example-client-cert'),
            mqttBudget('MQTT_CLIENT_CREDENTIAL_VERSION', 1),
            'mtls'
        );
        if ($this->password === '') {
            throw new RuntimeException('需要设置非空 MQTT_PASSWORD');
        }
        if ($this->username === 'service' || strlen($this->username) > 256 || preg_match('//u', $this->username) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $this->username) === 1) {
            throw new RuntimeException('MQTT_USERNAME 需要独立且有效的示例用户名');
        }
    }

    /** 只接受示例用户名和恒定时间比较成功的密码，不依据客户端声明推定 TLS。 */
    public function authenticate(ConnectPacket $connect, string $peer, bool $secure): bool
    {
        if ($connect->username === 'service') {
            return $this->servicePassword !== '' && $connect->password !== null && hash_equals($this->servicePassword, $connect->password);
        }
        return $connect->username === $this->username && $connect->password !== null && hash_equals($this->password, $connect->password);
    }

    /** 仅接受 Broker 已用配置 CA 校验过的指纹；不把证书主题或 Client ID 当作主体。 */
    public function authenticateCertificate(ConnectPacket $connect, string $peer, string $fingerprint): ?AccessIdentity
    {
        foreach ($this->clientFingerprints as $registered) {
            if (hash_equals($registered, strtolower($fingerprint))) {
                return $this->certificateIdentity;
            }
        }
        if ($this->clientOverlap === '') {
            return null;
        }
        $windows = BrokerOptions::overlapWindows($this->clientOverlap, time());
        if ($windows === null) {
            return null;
        }
        $now = time();
        foreach ($windows as $registered => $until) {
            if ($until > $now && hash_equals($registered, strtolower($fingerprint))) {
                return $this->certificateIdentity;
            }
        }
        return null;
    }

    /** 通过同一凭据校验后返回固定主体；修改用户名或轮换凭据不会改变其主体。 */
    public function authenticateIdentity(ConnectPacket $connect, string $peer, bool $secure): ?AccessIdentity
    {
        if (!$this->authenticate($connect, $peer, $secure)) {
            return null;
        }
        return $connect->username === 'service' ? $this->serviceIdentity : $this->identity;
    }

    /** 示例只开放 example/；read-only/ 路径拒绝发布。 */
    public function authorize(ConnectPacket $connect, string $topic, string $action, int $qos): bool
    {
        return str_starts_with($topic, 'example/') && ($action !== 'publish' || !str_starts_with($topic, 'example/read-only/'));
    }

    /** 授权以当前有效凭据快照为准，不从传入的 Client ID 或用户名重新建立身份。 */
    public function authorizeIdentity(AccessIdentity $identity, ConnectPacket $connect, string $topic, string $action, int $qos): bool
    {
        return ($identity->matches($this->identity) || ($this->servicePassword !== '' && $identity->matches($this->serviceIdentity))
            || $identity->matches($this->certificateIdentity))
            && $this->authorize($connect, $topic, $action, $qos);
    }
}

/** 示例启动参数是数据配置；非法、空白和超限值由此处及资源所有者拒绝。 */
function mqttBudget(string $name, int $default): int
{
    $value = (string) getenv($name);
    if ($value !== '' && preg_match('/^[0-9]{1,10}$/D', $value) !== 1) {
        throw new RuntimeException('MQTT 容量配置需要十进制整数');
    }
    return $value === '' ? $default : (int) $value;
}

/** 使用 --plaintext 显式启动回环调试；省略时证书和私钥均须从启动环境提供。 */
function main(int $argc, array $argv): void
{
    $arguments = new Arguments(
        $argv,
        ['host', 'port', 'ws-port', 'wss-port', 'mtls-port', 'allowed-origins', 'store-worker', 'terminate-session', 'actor', 'node-id', 'fence-node', 'node-run-id', 'proof-ref'],
        ['plaintext', 'install-store', 'store-statistics', 'clustered', 'node-statistics']
    );
    $workerConfiguration = (string) getenv('MQTT_WORKER_COMMAND');
    $workerCommand = $workerConfiguration === '' ? [] : json_decode($workerConfiguration, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($workerCommand)) {
        throw new RuntimeException('MQTT_WORKER_COMMAND 必须是显式命令参数数组');
    }
    if ($arguments->has('store-worker')) {
        $retainedMessages = (string) getenv('MQTT_RETAINED_MAX_MESSAGES');
        $retainedBytes = (string) getenv('MQTT_RETAINED_MAX_BYTES');
        $sharedMessages = (string) getenv('MQTT_SHARED_MAX_MESSAGES');
        $sharedBytes = (string) getenv('MQTT_SHARED_MAX_BYTES');
        $store = new PostgresStore(
            new PgsqlDriver(
                (string) getenv('TYPE_PGSQL_HOST'),
                (int) getenv('TYPE_PGSQL_PORT'),
                (string) getenv('TYPE_PGSQL_DATABASE'),
                (string) getenv('TYPE_PGSQL_USER'),
                (string) getenv('TYPE_PGSQL_PASSWORD')
            ),
            (string) (getenv('MQTT_STANDBY') ?: 'iot_sync'),
            mqttBudget('MQTT_PENDING_MAX_MESSAGES', 2000000),
            mqttBudget('MQTT_PENDING_MAX_BYTES', 4294967296),
            $retainedMessages === '' ? 2000000 : (int) $retainedMessages,
            $retainedBytes === '' ? 4294967296 : (int) $retainedBytes,
            $sharedMessages === '' ? 1000000 : (int) $sharedMessages,
            $sharedBytes === '' ? 2147483648 : (int) $sharedBytes,
            mqttBudget('MQTT_MAX_SESSIONS', 20000),
            mqttBudget('MQTT_DEVICE_MAX_MESSAGES', 10000),
            mqttBudget('MQTT_DEVICE_MAX_BYTES', 16777216),
            mqttBudget('MQTT_APPLICATION_MAX_MESSAGES', 1000000),
            mqttBudget('MQTT_APPLICATION_MAX_BYTES', 2147483648)
        );
        PendingCommit::work($store, $arguments->text('store-worker', ''));
        return;
    }
    if ($arguments->has('install-store') || $arguments->has('terminate-session') || $arguments->has('store-statistics') || $arguments->has('node-statistics') || $arguments->has('fence-node')) {
        $operation = $arguments->has('install-store') ? ['action' => 'install'] : ($arguments->has('store-statistics') ? ['action' => 'session_statistics']
            : ['action' => 'session_terminate', 'client_id' => $arguments->text('terminate-session', ''), 'actor' => $arguments->text('actor', '')]);
        if ($arguments->has('node-statistics')) {
            $operation = ['action' => 'node_statistics'];
        } elseif ($arguments->has('fence-node')) {
            $operation = ['action' => 'node_fence', 'node_id' => $arguments->text('fence-node', ''), 'node_run_id' => $arguments->text('node-run-id', ''),
                'actor' => $arguments->text('actor', ''), 'proof_ref' => $arguments->text('proof-ref', '')];
        }
        $operation['operation_id'] = bin2hex(random_bytes(16));
        $pending = new PendingCommit($workerCommand, $operation);
        do {
            $result = $pending->poll();
            if ($result === null) {
                usleep(10000);
            }
        } while ($result === null);
        echo json_encode($result->data(), JSON_THROW_ON_ERROR), "\n";
        return;
    }
    $origins = [];
    if ($arguments->has('allowed-origins')) {
        foreach (explode(',', $arguments->text('allowed-origins', '-')) as $origin) {
            $origin = strtolower(trim($origin));
            if ($origin !== '') {
                $origins[] = $origin;
            }
        }
    }
    $mtlsPort = $arguments->integer('mtls-port', 0, 0, 65535);
    $wssPort = $arguments->integer('wss-port', 0, 0, 65535);
    $broker = new Broker(new ExampleMqttAccess(), new BrokerOptions(
        certificate: (string) getenv('MQTT_CERTIFICATE'),
        privateKey: (string) getenv('MQTT_PRIVATE_KEY'),
        privateKeyPassphrase: (string) getenv('MQTT_PRIVATE_KEY_PASSPHRASE'),
        allowPlaintext: $arguments->has('plaintext'),
        maximumConnections: mqttBudget('MQTT_MAX_CONNECTIONS', getenv('MQTT_IO_DRIVER') === 'swoole' ? 10100 : 256),
        maximumDeviceConnections: mqttBudget('MQTT_MAX_DEVICE_CONNECTIONS', 10000),
        maximumServiceConnections: mqttBudget('MQTT_MAX_SERVICE_CONNECTIONS', 100),
        ioDriver: (string) (getenv('MQTT_IO_DRIVER') ?: 'stream'),
        clustered: $arguments->has('clustered'),
        wsPort: $arguments->integer('ws-port', 0, 0, 65535),
        wssPort: $wssPort,
        allowedOrigins: $origins,
        mtlsPort: $mtlsPort,
        clientCa: ($mtlsPort > 0 || $wssPort > 0) ? (string) getenv('MQTT_CLIENT_CA') : '',
        clientCrl: ($mtlsPort > 0 || $wssPort > 0) ? (string) getenv('MQTT_CLIENT_CRL') : '',
        clientCrlUrl: ($mtlsPort > 0 || $wssPort > 0) ? (string) getenv('MQTT_CLIENT_CRL_URL') : '',
        clientCrlInterval: ($mtlsPort > 0 || $wssPort > 0) ? mqttBudget('MQTT_CLIENT_CRL_INTERVAL', 300) : 300,
        clientRevoke: ($mtlsPort > 0 || $wssPort > 0) ? (string) getenv('MQTT_CLIENT_REVOKE') : '',
        clientOverlap: ($mtlsPort > 0 || $wssPort > 0) ? (string) getenv('MQTT_CLIENT_OVERLAP') : '',
        sniHost: (string) getenv('MQTT_SNI_HOST'),
        sniCertificate: (string) getenv('MQTT_SNI_CERTIFICATE'),
        sniPrivateKey: (string) getenv('MQTT_SNI_PRIVATE_KEY')
    ), $workerCommand, nodeId: $arguments->text('node-id', 'default'), classify: function (ConnectPacket $connect): string {
        return $connect->username === 'service' ? 'application' : 'device';
    });
    $broker->serve($arguments->text('host', '127.0.0.1'), $arguments->integer('port', 8883, 1, 65535));
    echo json_encode($broker->statistics(), JSON_THROW_ON_ERROR), "\n";
}
