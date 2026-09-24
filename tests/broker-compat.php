<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/native-database.php';
require_once __DIR__ . '/postgres-sync.php';

use Type\Mqtt\Client;
use Type\Mqtt\Message;
use Type\Mqtt\ProtocolError;
use Type\Orm\Migration\Migrator;
use Type\Orm\Pgsql\PgsqlDriver;
use Type\Testing\HttpClient;
use Type\Testing\Process;

if (!function_exists('identityCommand')) {
    /**
     * 在 30 秒内执行人员管理命令并解析 JSON，退出路径均停止子进程。
     *
     * @param list<string> $command
     * @param array<string, string> $environment
     * @return array<string, mixed>
     */
    function identityCommand(array $command, array $environment): array
    {
        $process = new Process($command, dirname(__DIR__), $environment);
        try {
            $result = $process->wait(30);
            expect($result->successful(), '人员命令失败：' . $result->stderr);
            return json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            $process->stop();
        }
    }
}

/**
 * 在本轮私有目录签发兼容测试 CA 和服务端证书，限制私钥权限；目录由调用者回收。
 *
 * @return array{ca: string, server: string, key: string}
 */
function compatCertFiles(string $directory): array
{
    expect(mkdir($directory, 0700), '无法创建证书目录');
    $configuration = $directory . '/openssl.cnf';
    file_put_contents($configuration, "[req]\ndistinguished_name=dn\nx509_extensions=ext\n[dn]\n[ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n[server]\nsubjectAltName=IP:127.0.0.1\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n");
    $options = ['config' => $configuration, 'private_key_bits' => 2048, 'digest_alg' => 'sha256', 'x509_extensions' => 'ca'];
    $caKey = openssl_pkey_new($options);
    $caRequest = openssl_csr_new(['commonName' => 'broker-compat-ca'], $caKey, $options);
    $ca = openssl_csr_sign($caRequest, null, $caKey, 1, $options);
    $serverOptions = $options;
    $serverOptions['x509_extensions'] = 'server';
    $serverKey = openssl_pkey_new($serverOptions);
    $serverRequest = openssl_csr_new(['commonName' => '127.0.0.1'], $serverKey, $serverOptions);
    $server = openssl_csr_sign($serverRequest, $ca, $caKey, 1, $serverOptions);
    expect($ca !== false && $server !== false, '无法生成兼容测试证书');
    $paths = ['ca' => $directory . '/ca.pem', 'server' => $directory . '/server.pem', 'key' => $directory . '/server.key'];
    expect(openssl_x509_export($ca, $caPem) && openssl_x509_export($server, $serverPem)
        && openssl_pkey_export($serverKey, $serverKeyPem, null, $serverOptions)
        && file_put_contents($paths['ca'], $caPem) !== false && file_put_contents($paths['server'], $serverPem) !== false
        && file_put_contents($paths['key'], $serverKeyPem) !== false, '无法写出兼容测试证书');
    chmod($paths['key'], 0600);
    return $paths;
}

/** 在 15 秒轮询预算内确认兼容节点存活且通过证书与主机名验证的 TLS 握手。 */
function compatWaitTls(Process $process, int $port, string $ca): void
{
    $deadline = microtime(true) + 15;
    do {
        expect($process->running(), '兼容节点提前退出：' . $process->stderr());
        $socket = @stream_socket_client('tls://127.0.0.1:' . $port, $errno, $error, 1, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => [
            'cafile' => $ca, 'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => '127.0.0.1',
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ]]));
        if (is_resource($socket)) {
            fclose($socket);
            return;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    expect(false, '兼容节点 TLS 入口未就绪：' . $process->stderr());
}

/** 在 30 秒轮询预算内等待遗嘱占用进程报告 held:1，提前退出或超时均失败。 */
function compatWaitHeld(Process $process): void
{
    $deadline = microtime(true) + 30;
    do {
        expect($process->running(), '遗嘱占用进程提前退出：' . $process->stderr());
        if (str_contains($process->stdout(), 'held:1')) {
            return;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    expect(false, '遗嘱占用未就绪：' . $process->stdout() . $process->stderr());
}

/**
 * 按本轮环境连接 PostgreSQL，并启用异常错误模式；PDO 由调用者持有和释放。
 *
 * @param array<string, string> $environment
 */
function compatPdo(array $environment): PDO
{
    return new PDO(
        'pgsql:host=' . $environment['DB_HOST'] . ';port=' . $environment['DB_PORT'] . ';dbname=' . $environment['DB_DATABASE'],
        $environment['DB_USERNAME'],
        $environment['DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

/**
 * 在 12 秒轮询预算内等待访问修订生效并具有节点记录，超时附最后状态。
 *
 * @param callable(string, string, string, ?array, int): array<string, mixed> $request
 * @param array<string, mixed> $revision
 * @return array<string, mixed>
 */
function compatWaitRevision(callable $request, string $token, array $revision, string $message): array
{
    $deadline = microtime(true) + 12;
    do {
        $current = $request('GET', '/broker/access/revisions/' . $revision['id'], $token, null, 200)['item'];
        if (($current['status'] ?? '') === 'effective' && ($current['nodes'] ?? []) !== []) {
            return $current;
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    expect(false, $message . '：' . json_encode($current ?? []));
    return [];
}

$root = dirname(__DIR__);
$target = $argv[1] ?? '--php';
$driver = $argv[2] ?? 'pgsql';
expect($driver === 'pgsql', '升级回退验收需要 PostgreSQL 同步存储');
if ($target === '--php') {
    $swoole = getenv('TYPE_SWOOLE_MODULE');
    $swoole = is_string($swoole) && $swoole !== '' ? $swoole : rtrim((string) ini_get('extension_dir'), '/') . '/swoole.so';
    expect(is_file($swoole), 'PHP 升级回退验收需要 TYPE_SWOOLE_MODULE 或 extension_dir 中的 swoole.so');
    $command = [PHP_BINARY, '-d', 'extension=' . $swoole, $root . '/bin/typeapp'];
} else {
    $command = nativeCommand($target);
}
$base = $root . '/build/broker-compat-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700, true), '无法创建升级回退测试目录');
$noSource = in_array('--no-source', $argv, true);
$browserDist = '';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--browser-dist=')) {
        $browserDist = substr($argument, 15);
    }
}
if ($noSource) {
    expect($target !== '--php' && PHP_OS_FAMILY === 'Darwin', '无源码升级回退验收使用macOS内核策略及原生产物');
    $runtime = $base . '/runtime';
    (new Type\Build\NativePackage())->create($target, $runtime);
    $built = json_decode(file_get_contents($target . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
    $sourceRoot = realpath($built['identity']['description']['facts']['workspace']);
    expect(is_string($sourceRoot), '无源码验收必须能定位实际编译输入');
    $policy = ['sandbox-exec', '-f', $root . '/tests/fixtures/iot-no-source.sb'];
    foreach (['APP' => $sourceRoot . '/app', 'PLUGIN' => $sourceRoot . '/plugin', 'VENDOR' => $sourceRoot . '/vendor', 'CONFIG' => $sourceRoot . '/config',
        'COMPILER' => dirname($built['runtime-profile']['ini'], 2), 'COMPOSER' => $sourceRoot . '/composer.json'] as $role => $path) {
        array_push($policy, '-D', $role . '=' . $path);
    }
    $command = [...$policy, $runtime . '/run'];
}
$databaseTools = NativeDatabase::tools('pgsql', (string) getenv('TYPE_PGSQL_TOOLS'));
$fixtureDatabase = new NativeDatabase($base . '/primary', 'pgsql', $databaseTools);
$fixtureSync = new PostgresSync($fixtureDatabase, $base . '/standby', $databaseTools, 'broker_compat_sync');
$environment = getenv();
foreach (array_keys($environment) as $key) {
    if (str_starts_with($key, 'APP_') || str_starts_with($key, 'DB_') || str_starts_with($key, 'IOT_') || str_starts_with($key, 'BROKER_')) {
        unset($environment[$key]);
    }
}
foreach ($fixtureDatabase->environment() as $key => $value) {
    if (str_starts_with($key, 'TYPE_PGSQL_')) {
        $environment[$key] = $value;
        putenv($key . '=' . $value);
    }
}
$environment['APP_BASE_PATH'] = $base;
$environment['DB_DRIVER'] = 'pgsql';
$environment['DB_HOST'] = $environment['TYPE_PGSQL_HOST'];
$environment['DB_PORT'] = $environment['TYPE_PGSQL_PORT'];
$environment['DB_DATABASE'] = $environment['TYPE_PGSQL_DATABASE'];
$environment['DB_USERNAME'] = $environment['TYPE_PGSQL_USER'];
$environment['DB_PASSWORD'] = $environment['TYPE_PGSQL_PASSWORD'];
$environment['APP_CACHE_ENABLED'] = 'false';
$environment['APP_DEBUG'] = 'true';
$environment['BROKER_COMMAND'] = json_encode($command, JSON_THROW_ON_ERROR);
$environment['BROKER_STANDBY_NAMES'] = 'broker_compat_sync';
$checks = 0;
$report = ['status' => 'running', 'scope' => 'broker-compat', 'native' => $target !== '--php', 'driver' => 'pgsql',
    'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'no_source' => $noSource];
$server = null;
$node = null;
$appServer = null;
$browser = null;
$willHold = null;
$secrets = [];
$pdo = null;
$certs = compatCertFiles($base . '/certs');
$clientRoot = $base . '/mqtt-js';
expect(mkdir($clientRoot, 0700), '无法创建 MQTT.js 目录');
file_put_contents($clientRoot . '/package.json', json_encode(['name' => 'typeapp-broker-compat-client', 'private' => true, 'dependencies' => ['mqtt' => '5.15.0']], JSON_THROW_ON_ERROR));
$installJs = new Process(['npm', 'install', '--ignore-scripts', '--no-audit', '--no-fund'], $clientRoot, getenv());
try {
    expect($installJs->wait(120)->successful(), '安装 MQTT.js 5.15.0 失败：' . $installJs->stderr());
} finally {
    $installJs->stop();
}
try {
    $legacy = [];
    $head = null;
    foreach (\app\broker\database\Schema::migrations('pgsql') as $migration) {
        if ($migration->version() === '037_broker_recovery') {
            continue;
        }
        if ($migration->version() === '036_broker_compatibility') {
            $head = $migration;
            continue;
        }
        if ($migration->version() === '035_broker_debug_occupancy') {
            continue;
        }
        $legacy[] = $migration;
    }
    expect($head !== null && $legacy !== [], '缺少独立管理迁移计划');
    (new Migrator(new PgsqlDriver(
        $environment['DB_HOST'],
        (int) $environment['DB_PORT'],
        $environment['DB_DATABASE'],
        $environment['DB_USERNAME'],
        $environment['DB_PASSWORD']
    )))->run($legacy);
    $pdo = compatPdo($environment);
    expect($pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = 'broker_compatibility'")->fetch() === false, '旧库不应已有兼容表');
    $store = new Process([...$command, 'broker:store-install'], $root, $environment);
    try {
        expect($store->wait(30)->successful(), '独立持久存储安装失败：' . $store->stdout() . $store->stderr());
    } finally {
        $store->stop();
    }
    $password = bin2hex(random_bytes(16));
    $secrets[] = $password;
    identityCommand([...$command, 'broker:user', 'access-admin', '升级回退管理员'], $environment + ['BROKER_ADMIN_PASSWORD' => $password]);
    $addresses = [];
    for ($index = 0; $index < 4; $index++) {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorText);
        expect(is_resource($listener), '无法选择升级回退测试端口');
        $addresses[] = stream_socket_get_name($listener, false);
        fclose($listener);
    }
    $previewOrigin = 'http://' . $addresses[3];
    $environment['APP_PORT'] = substr(strrchr($addresses[0], ':'), 1);
    $environment['APP_ALLOWED_HOSTS'] = $addresses[0];
    $server = new Process([...$command, 'broker:serve'], $root, $environment);
    $client = new HttpClient('http://' . $addresses[0], 12.0);
    $deadline = microtime(true) + 10;
    do {
        expect($server->running(), '独立升级回退管理提前退出：' . $server->stderr());
        try {
            if ($client->request('GET', '/readyz')->status === 200) {
                break;
            }
        } catch (RuntimeException) {
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    $request = static function (string $method, string $path, string $token, ?array $data, int $status, array $extra = []) use ($client, $server, &$checks): array {
        $headers = ['Content-Type' => 'application/json'] + $extra;
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $response = $client->request($method, $path, $headers, $data === null ? '' : json_encode($data === [] ? (object) [] : $data, JSON_THROW_ON_ERROR));
        expect($response->status === $status, $method . ' ' . $path . ' 预期 ' . $status . '，实际 ' . $response->status . ' ' . $response->body
            . ($response->status >= 500 ? ' ' . $server->stdout() . $server->stderr() : ''));
        $checks++;
        return $response->json();
    };
    $auth = $request('POST', '/broker/auth/login', '', ['login' => 'access-admin', 'password' => $password], 200)['data'];
    $token = $auth['accessToken'];
    $secrets[] = $token;
    $request('GET', '/broker/compat', '', null, 401);
    $before = $request('GET', '/broker/compat', $token, null, 200);
    expect(($before['recorded'] ?? true) === false && ($before['binary_epoch'] ?? 0) === 19, '升级前兼容快照错误');
    $mqttPassword = bin2hex(random_bytes(16));
    $secrets[] = $mqttPassword;
    $tlsPort = (int) substr(strrchr($addresses[1], ':'), 1);
    $nodeEnvironment = $environment + [
        'BROKER_CLIENT_USERNAME' => 'broker-client', 'BROKER_CLIENT_PASSWORD' => $mqttPassword,
        'BROKER_TOPIC_PREFIX' => 'broker-compat/', 'BROKER_NODE_ID' => 'compat-node',
        'BROKER_LISTEN' => '127.0.0.1', 'BROKER_PLAINTEXT' => 'false',
        'BROKER_PORT' => (string) $tlsPort,
        'BROKER_CERTIFICATE' => $certs['server'], 'BROKER_PRIVATE_KEY' => $certs['key'],
        'BROKER_ALLOWED_ORIGINS' => $previewOrigin,
    ];
    $node = new Process([...$command, 'broker:run'], $root, $nodeEnvironment);
    compatWaitTls($node, $tlsPort, $certs['ca']);
    $deadline = microtime(true) + 12;
    do {
        expect($node->running(), '兼容节点提前退出：' . $node->stderr());
        $nodes = $request('GET', '/broker/nodes', $token, null, 200);
        if (($nodes['total'] ?? 0) === 1) {
            break;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    expect(($nodes['total'] ?? 0) === 1, '兼容节点未上报');
    $queuedPayload = 'queued-' . bin2hex(random_bytes(8));
    $retainPayload = 'retain-' . bin2hex(random_bytes(8));
    $willPayload = 'will-' . bin2hex(random_bytes(8));
    $secrets[] = $queuedPayload;
    $secrets[] = $retainPayload;
    $secrets[] = $willPayload;
    $session = new Client('127.0.0.1', $tlsPort, 'compat-session', 'broker-client', $mqttPassword, $certs['ca'], '127.0.0.1', 30, 86400);
    expect($session->connect(true) === false, '持久会话首次 CONNECT 失败');
    $session->subscribe('broker-compat/queued', 1);
    $session->close(true);
    $session = null;
    $willSub = new Client('127.0.0.1', $tlsPort, 'compat-will-sub', 'broker-client', $mqttPassword, $certs['ca'], '127.0.0.1', 30, 86400);
    expect($willSub->connect(true) === false, '遗嘱订阅 CONNECT 失败');
    $willSub->subscribe('broker-compat/will', 1);
    $willSub->close(true);
    $willSub = null;
    $publisher = new Client('127.0.0.1', $tlsPort, 'compat-publisher', 'broker-client', $mqttPassword, $certs['ca'], '127.0.0.1');
    expect($publisher->connect(true) === false, '发布者 CONNECT 失败');
    expect($publisher->publish(new Message('broker-compat/queued', $queuedPayload, '', 1)) === 0, 'QoS1 排队发布失败');
    expect($publisher->publish(new Message('broker-compat/retain', $retainPayload, '', 1, 0, true)) === 0, '保留发布失败');
    $publisher->stop();
    $publisher = null;
    $revokedPassword = bin2hex(random_bytes(16));
    $secrets[] = $revokedPassword;
    $access = $request('GET', '/broker/access/principals', $token, null, 200);
    $created = $request('POST', '/broker/access/principals', $token, [
        'name' => '待撤销主体', 'login' => 'broker-revoked', 'password' => $revokedPassword, 'enabled' => 1,
        'expected_version' => $access['current_version'],
        'grants' => [['topic' => 'broker-compat/', 'publish' => 1, 'subscribe' => 1, 'max_qos' => 1]],
    ], 200)['data'];
    $created = compatWaitRevision($request, $token, $created, '待撤销主体未生效');
    $listed = $request('GET', '/broker/access/principals', $token, null, 200);
    $revokedItem = null;
    foreach ($listed['items'] as $item) {
        if (($item['login'] ?? '') === 'broker-revoked') {
            $revokedItem = $item;
            break;
        }
    }
    expect(is_array($revokedItem), '未找到待撤销主体');
    $revokedLive = new Client('127.0.0.1', $tlsPort, 'compat-revoked', 'broker-revoked', $revokedPassword, $certs['ca'], '127.0.0.1');
    expect($revokedLive->connect(true) === false, '撤销前主体接入失败');
    $revokedLive->stop();
    $revokedLive = null;
    $disabled = $request('POST', '/broker/access/principals/' . $revokedItem['id'], $token, [
        'name' => $revokedItem['name'], 'enabled' => 0, 'expected_version' => $created['version'], 'grants' => $revokedItem['grants'],
    ], 200)['data'];
    compatWaitRevision($request, $token, $disabled, '撤销主体未生效');
    $willHold = new Process(['node', $root . '/tests/broker-compat-client.mjs', $clientRoot, 'will-hold', $certs['ca']], $root, array_replace(getenv(), [
        'COMPAT_MQTT_HOST' => '127.0.0.1', 'COMPAT_MQTT_PORT' => (string) $tlsPort,
        'COMPAT_MQTT_USERNAME' => 'broker-client', 'COMPAT_MQTT_PASSWORD' => $mqttPassword,
        'COMPAT_MQTT_CLIENT_ID' => 'compat-will-hold',
        'COMPAT_WILL_TOPIC' => 'broker-compat/will', 'COMPAT_WILL_PAYLOAD' => $willPayload,
    ]));
    compatWaitHeld($willHold);
    $willHold->stop(0);
    $willHold = null;
    usleep(1500000);
    expect($node->stop(5)->successful(), '兼容节点未正常排空');
    $node = null;
    $pdo->prepare('INSERT INTO type_migrations (version, checksum, description, state, transactional, attempts, started_at, finished_at, error) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$head->version(), $head->checksum(), $head->description(), 'failed', $head->transactional() ? 1 : 0, 1, gmdate('c'), gmdate('c'), 'interrupted']);
    $blocked = new Process([...$command, 'broker:install'], $root, $environment);
    try {
        $blockedResult = $blocked->wait(30);
        expect(!$blockedResult->successful() && str_contains($blockedResult->stderr, 'TYPE_MIGRATION_RECOVERY_REQUIRED'), '中断迁移未要求显式恢复：' . $blockedResult->stdout . $blockedResult->stderr);
    } finally {
        $blocked->stop();
    }
    $recover = new Process([...$command, 'broker:migrate', 'recover', '036_broker_compatibility', 'retry', '核对中断后的兼容迁移后重试'], $root, $environment);
    try {
        expect($recover->wait(30)->successful(), '兼容迁移恢复失败：' . $recover->stdout() . $recover->stderr());
    } finally {
        $recover->stop();
    }
    $install = new Process([...$command, 'broker:install'], $root, $environment);
    try {
        expect($install->wait(30)->successful(), '升级安装失败：' . $install->stdout() . $install->stderr());
    } finally {
        $install->stop();
    }
    $storeAgain = new Process([...$command, 'broker:store-install'], $root, $environment);
    try {
        expect($storeAgain->wait(30)->successful(), '升级后持久存储安装失败：' . $storeAgain->stdout() . $storeAgain->stderr());
    } finally {
        $storeAgain->stop();
    }
    $tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_name IN ('broker_debug_occupancy','broker_compatibility','broker_access_crl_serials')")->fetchAll(PDO::FETCH_COLUMN);
    expect(in_array('broker_debug_occupancy', $tables, true) && in_array('broker_compatibility', $tables, true)
        && in_array('broker_access_crl_serials', $tables, true), '升级后缺少占用、兼容或 CRL 粘性表');
    $compat = $request('GET', '/broker/compat', $token, null, 200);
    expect(($compat['recorded'] ?? false) === true && ($compat['runtime_epoch'] ?? 0) === 19 && ($compat['min_runtime_epoch'] ?? 0) === 19
        && ($compat['rollback_allowed'] ?? true) === false && ($compat['store_ready'] ?? 0) === 1
        && str_contains((string) ($compat['maintenance']['do_not_drop_state'] ?? ''), 'broker_debug_occupancy'), '升级后兼容契约错误');
    $request('POST', '/broker/compat', $token, [], 405);
    $node = new Process([...$command, 'broker:run'], $root, $nodeEnvironment);
    compatWaitTls($node, $tlsPort, $certs['ca']);
    $deadline = microtime(true) + 12;
    do {
        expect($node->running(), '升级后节点提前退出：' . $node->stderr());
        $nodes = $request('GET', '/broker/nodes', $token, null, 200);
        if (($nodes['total'] ?? 0) === 1) {
            break;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    expect(($nodes['total'] ?? 0) === 1, '升级后节点未上报');
    $restored = new Client('127.0.0.1', $tlsPort, 'compat-session', 'broker-client', $mqttPassword, $certs['ca'], '127.0.0.1', 30, 86400);
    expect($restored->connect(false) === true, '升级后会话未恢复');
    $queued = $restored->receive(15.0);
    expect($queued !== null && $queued['message']->payload === $queuedPayload, '升级后未交付排队的 QoS1 消息');
    $restored->acknowledge($queued['receipt']);
    $restored->stop();
    $restored = null;
    $retainClient = new Client('127.0.0.1', $tlsPort, 'compat-retain', 'broker-client', $mqttPassword, $certs['ca'], '127.0.0.1');
    expect($retainClient->connect(true) === false, '保留核对 CONNECT 失败');
    $retainClient->subscribe('broker-compat/retain', 1);
    $retained = $retainClient->receive(15.0);
    expect($retained !== null && $retained['message']->payload === $retainPayload && $retained['message']->retain === true, '升级后未交付保留原件');
    if ($retained['receipt'] !== '') {
        $retainClient->acknowledge($retained['receipt']);
    }
    $retainClient->stop();
    $retainClient = null;
    $willRestored = new Client('127.0.0.1', $tlsPort, 'compat-will-sub', 'broker-client', $mqttPassword, $certs['ca'], '127.0.0.1', 30, 86400);
    expect($willRestored->connect(false) === true, '升级后遗嘱订阅会话未恢复');
    $willMessage = $willRestored->receive(15.0);
    expect($willMessage !== null && $willMessage['message']->payload === $willPayload, '升级后未交付遗嘱');
    if ($willMessage['receipt'] !== '') {
        $willRestored->acknowledge($willMessage['receipt']);
    }
    $willRestored->stop();
    $willRestored = null;
    try {
        $denied = new Client('127.0.0.1', $tlsPort, 'compat-revoked-after', 'broker-revoked', $revokedPassword, $certs['ca'], '127.0.0.1');
        $denied->connect(true);
        expect(false, '已撤销身份在升级后仍被接入');
    } catch (ProtocolError $error) {
        expect(in_array($error->reason, [0x87, 0x86, 0x84, 0x8c], true), '撤销后原因码异常：' . $error->reason);
    }
    $appBase = $base . '/app';
    expect(mkdir($appBase, 0700), '无法创建双端升级回退测试目录');
    $appEnvironment = $environment;
    $appEnvironment['APP_BASE_PATH'] = $appBase;
    $appEnvironment['APP_PORT'] = substr(strrchr($addresses[2], ':'), 1);
    $appEnvironment['APP_ALLOWED_HOSTS'] = $addresses[2];
    unset($appEnvironment['BROKER_CLIENT_USERNAME'], $appEnvironment['BROKER_CLIENT_PASSWORD'], $appEnvironment['BROKER_TOPIC_PREFIX'], $appEnvironment['BROKER_NODE_ID'], $appEnvironment['BROKER_PLAINTEXT'], $appEnvironment['BROKER_PORT'], $appEnvironment['BROKER_COMMAND'], $appEnvironment['BROKER_STANDBY_NAMES'], $appEnvironment['BROKER_ALLOWED_ORIGINS']);
    $appEnvironment['DB_DRIVER'] = 'sqlite';
    $appEnvironment['DB_SQLITE_FILE'] = 'app.sqlite';
    unset($appEnvironment['DB_HOST'], $appEnvironment['DB_PORT'], $appEnvironment['DB_DATABASE'], $appEnvironment['DB_USERNAME'], $appEnvironment['DB_PASSWORD']);
    $appPassword = bin2hex(random_bytes(16));
    $secrets[] = $appPassword;
    $appInstall = new Process([...$command, 'app:install', 'platform-admin', '平台管理员', 'customer-admin', '客户管理员', '升级回退租户'], $root, $appEnvironment + [
        'APP_ADMIN_PASSWORD' => $appPassword, 'APP_CUSTOMER_PASSWORD' => $appPassword . '-customer',
    ]);
    try {
        expect($appInstall->wait(45)->successful(), '双端升级回退安装失败：' . $appInstall->stderr());
    } finally {
        $appInstall->stop();
    }
    $appServer = new Process([...$command, 'serve'], $root, $appEnvironment);
    $appClient = new HttpClient('http://' . $addresses[2], 8.0);
    $deadline = microtime(true) + 15;
    do {
        expect($appServer->running(), '双端升级回退HTTP提前退出：' . $appServer->stderr());
        try {
            if ($appClient->request('GET', '/readyz')->status === 200) {
                break;
            }
        } catch (RuntimeException) {
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    $appRequest = static function (string $method, string $path, string $token, array $headers, ?array $data, int $status) use ($appClient, $appServer, &$checks): array {
        $response = $appClient->request($method, $path, $headers + ($token === '' ? [] : ['Authorization' => 'Bearer ' . $token]) + ['Content-Type' => 'application/json'], $data === null ? '' : json_encode($data === [] ? (object) [] : $data, JSON_THROW_ON_ERROR));
        expect($response->status === $status, '双端 ' . $method . ' ' . $path . ' 预期 ' . $status . '，实际 ' . $response->status . ' ' . $response->body
            . ($response->status >= 500 ? ' ' . $appServer->stdout() . $appServer->stderr() : ''));
        $checks++;
        return $response->json();
    };
    $platformToken = $appRequest('POST', '/admin/auth/login', '', [], ['login' => 'platform-admin', 'password' => $appPassword], 200)['data']['accessToken'];
    $customerToken = $appRequest('POST', '/customer/auth/login', '', [], ['login' => 'customer-admin', 'password' => $appPassword . '-customer'], 200)['data']['accessToken'];
    $secrets[] = $platformToken;
    $secrets[] = $customerToken;
    $me = $appRequest('GET', '/customer/auth/me', $customerToken, [], null, 200)['data'];
    $tenantId = $me['tenant_id'];
    $tenantHeaders = ['X-Tenant-Id' => $tenantId];
    $tenantCompat = $appRequest('GET', '/customer/tenants/' . $tenantId . '/broker/compat', $customerToken, $tenantHeaders, null, 200);
    expect(($tenantCompat['rollback_allowed'] ?? true) === false && ($tenantCompat['runtime_epoch'] ?? 0) === 19, '租户兼容查询失败');
    $appRequest('POST', '/customer/tenants/' . $tenantId . '/broker/compat', $customerToken, $tenantHeaders, [], 405);
    $platformCompat = $appRequest('GET', '/admin/broker/compat', $platformToken, [], null, 200);
    expect(($platformCompat['recorded'] ?? false) === true, '平台兼容查询失败');
    $appRequest('POST', '/admin/broker/compat', $platformToken, [], [], 405);
    $appRequest('GET', '/customer/tenants/' . $tenantId . '/broker/compat', $customerToken, ['X-Tenant-Id' => bin2hex(random_bytes(16))], null, 403);
    $report['http_checks'] = $checks;
    if ($browserDist !== '') {
        expect(is_file($browserDist . '/index.html'), '浏览器验收需要本次隔离前端产物');
        $browser = new Process(['node', $root . '/tests/broker-compat-browser.mjs', $base, $browserDist, 'http://' . $addresses[0], 'http://' . $addresses[2], $previewOrigin], $root, array_replace(getenv(), [
            'BROKER_BROWSER_PASSWORD' => $password,
            'APP_BROWSER_PASSWORD' => $appPassword,
            'APP_BROWSER_CUSTOMER_PASSWORD' => $appPassword . '-customer',
            'APP_BROWSER_TENANT' => $tenantId,
        ]));
        $browserResult = $browser->wait(180);
        file_put_contents($base . '/browser.log', str_replace($secrets, '<REDACTED>', $browserResult->stdout . $browserResult->stderr));
        expect($browserResult->successful(), '升级回退浏览器失败，见browser-compat-report.json');
        $report['browser'] = json_decode(file_get_contents($base . '/browser-compat-report.json'), true, 32, JSON_THROW_ON_ERROR);
        expect(($report['browser']['status'] ?? '') === 'passed', '升级回退浏览器报告未通过');
    }
    expect($node->stop(5)->successful(), '升级后节点停止失败');
    $node = null;
    $pdo->exec('UPDATE broker_compatibility SET runtime_epoch = 20, min_runtime_epoch = 20 WHERE id = \'default\'');
    $staleRun = new Process([...$command, 'broker:run'], $root, $nodeEnvironment);
    try {
        $stale = $staleRun->wait(15);
        expect(!$stale->successful() && str_contains($stale->stderr, 'broker_compat_runtime_stale'), '更高代次库未阻止旧二进制启动节点：' . $stale->stdout . $stale->stderr);
    } finally {
        $staleRun->stop();
    }
    $server->stop(5);
    $server = null;
    $staleServe = new Process([...$command, 'broker:serve'], $root, $environment);
    try {
        $staleHttp = $staleServe->wait(15);
        expect(!$staleHttp->successful() && str_contains($staleHttp->stderr, 'broker_compat_runtime_stale'), '更高代次库未阻止旧二进制启动管理 HTTP：' . $staleHttp->stdout . $staleHttp->stderr);
    } finally {
        $staleServe->stop();
    }
    $pdo->exec('UPDATE broker_compatibility SET runtime_epoch = 19, min_runtime_epoch = 19 WHERE id = \'default\'');
    $report['status'] = 'passed';
} finally {
    $pdo = null;
    try {
        $willHold?->stop(0);
        $report['cleanup']['will'] = $willHold === null || !$willHold->running();
    } catch (Throwable $failure) {
        $report['cleanup']['will'] = false;
        $report['cleanup_errors'][] = 'will:' . $failure->getMessage();
    }
    foreach (['browser' => $browser, 'node' => $node, 'app' => $appServer, 'http' => $server] as $role => $process) {
        try {
            $process?->stop(5.0);
            $report['cleanup'][$role] = $process === null || !$process->running();
        } catch (Throwable $failure) {
            $report['cleanup'][$role] = false;
            $report['cleanup_errors'][] = $role . ':' . $failure->getMessage();
        }
    }
    try {
        $fixtureSync->close();
        $report['cleanup']['standby'] = true;
    } catch (Throwable $failure) {
        $report['cleanup']['standby'] = false;
        $report['cleanup_errors'][] = 'standby:' . $failure->getMessage();
    }
    try {
        $fixtureDatabase->close();
        $report['cleanup']['primary'] = true;
    } catch (Throwable $failure) {
        $report['cleanup']['primary'] = false;
        $report['cleanup_errors'][] = 'primary:' . $failure->getMessage();
    }
    if (($report['status'] ?? '') !== 'passed') {
        $report['status'] = 'failed';
    }
    $report['http_checks'] = $checks;
    file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
expect(($report['status'] ?? '') === 'passed', '升级回退验收未通过，见 ' . $base . '/verification.json');
echo 'Broker 升级回退通过：' . $base . "/verification.json\n";
exit(0);
