<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/native-database.php';
require_once __DIR__ . '/postgres-sync.php';

use Type\Mqtt\Client;
use Type\Testing\HttpClient;
use Type\Testing\Process;

if (!function_exists('identityCommand')) {
    /**
     * 在 30 秒内执行人员管理命令并读取 JSON 结果，退出路径均停止子进程。
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
 * 在本轮私有目录签发测试 CA 和服务端证书，私钥限制为 0600；目录由调用者回收。
 *
 * @return array{ca: string, server: string, key: string}
 */
function debugCertFiles(string $directory): array
{
    expect(mkdir($directory, 0700), '无法创建证书目录');
    $configuration = $directory . '/openssl.cnf';
    file_put_contents($configuration, "[req]\ndistinguished_name=dn\nx509_extensions=ext\n[dn]\n[ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n[server]\nsubjectAltName=IP:127.0.0.1\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n");
    $options = ['config' => $configuration, 'private_key_bits' => 2048, 'digest_alg' => 'sha256', 'x509_extensions' => 'ca'];
    $caKey = openssl_pkey_new($options);
    $caRequest = openssl_csr_new(['commonName' => 'broker-debug-ca'], $caKey, $options);
    $ca = openssl_csr_sign($caRequest, null, $caKey, 1, $options);
    $serverOptions = $options;
    $serverOptions['x509_extensions'] = 'server';
    $serverKey = openssl_pkey_new($serverOptions);
    $serverRequest = openssl_csr_new(['commonName' => '127.0.0.1'], $serverKey, $serverOptions);
    $server = openssl_csr_sign($serverRequest, $ca, $caKey, 1, $serverOptions);
    expect($ca !== false && $server !== false, '无法生成调试测试证书');
    $paths = ['ca' => $directory . '/ca.pem', 'server' => $directory . '/server.pem', 'key' => $directory . '/server.key'];
    expect(openssl_x509_export($ca, $caPem) && openssl_x509_export($server, $serverPem)
        && openssl_pkey_export($serverKey, $serverKeyPem, null, $serverOptions)
        && file_put_contents($paths['ca'], $caPem) !== false && file_put_contents($paths['server'], $serverPem) !== false
        && file_put_contents($paths['key'], $serverKeyPem) !== false, '无法写出调试测试证书');
    chmod($paths['key'], 0600);
    return $paths;
}

/** 在 15 秒轮询预算内确认调试节点存活且通过证书与主机名验证的 TLS 握手。 */
function debugWaitTls(Process $process, int $port, string $ca): void
{
    $deadline = microtime(true) + 15;
    do {
        expect($process->running(), '调试节点提前退出：' . $process->stderr());
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
    expect(false, '调试节点 TLS 入口未就绪：' . $process->stderr());
}

/** 为已提交的异步观测留出 1 秒推进时间；此延时本身不证明业务就绪。 */
function debugSettle(): void
{
    usleep(1000000);
}

/**
 * @param array<string, string> $environment
 */
function debugMqtt(string $root, string $clientRoot, string $action, array $environment, string $ca, int $timeout = 45): string
{
    $process = new Process(['node', $root . '/tests/broker-debug-client.mjs', $clientRoot, $action, $ca], $root, $environment);
    try {
        $result = $process->wait($timeout);
        expect($result->successful(), '调试 MQTT.js ' . $action . ' 失败：' . $result->stdout . $result->stderr);
        return trim($result->stdout);
    } finally {
        $process->stop();
        debugSettle();
    }
}

/** 等待进程报告全部占用连接就绪，轮询预算取 90 秒与连接数乘 12 秒的较大值。 */
function debugWaitHeld(Process $process, int $count): void
{
    $deadline = microtime(true) + max(90, $count * 12);
    do {
        expect($process->running(), '调试占用进程提前退出：' . $process->stderr());
        if (str_contains($process->stdout(), 'held:' . $count)) {
            return;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    expect(false, '调试占用未就绪：' . $process->stdout() . $process->stderr());
}

/** 在 35 秒轮询预算内等待调试客户端输出 connected，期间持续确认进程存活。 */
function debugWaitConnected(Process $process): void
{
    $deadline = microtime(true) + 35;
    do {
        expect($process->running(), '调试连接进程提前退出：' . $process->stderr());
        if (str_contains($process->stdout(), 'connected')) {
            return;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    expect(false, '调试连接未就绪：' . $process->stdout() . $process->stderr());
}

/**
 * 确认服务连接额度等于预期，且修订已生效或节点已应用。
 *
 * @param array<string, mixed> $quotas
 */
function debugQuotaReady(array $quotas, int $services): bool
{
    $state = $quotas['revision']['nodes'][0]['state'] ?? '';
    return ($quotas['limits']['maximumServiceConnections'] ?? 0) === $services
        && (($quotas['revision']['status'] ?? '') === 'effective' || $state === 'applied');
}

/** 只匹配完整 closed 行或其带冒号的状态行，避免其他输出的子串被误判为连接已关闭。 */
function debugClosed(string $output): bool
{
    foreach (preg_split('/\r\n|\n|\r/', trim($output)) as $line) {
        if ($line === 'closed' || str_starts_with($line, 'closed:')) {
            return true;
        }
    }
    return false;
}

$root = dirname(__DIR__);
$target = $argv[1] ?? '--php';
$driver = $argv[2] ?? 'pgsql';
expect($driver === 'pgsql', '调试凭据验收需要 PostgreSQL 同步存储');
if ($target === '--php') {
    if (extension_loaded('swoole')) {
        // Swoole CLI 可以静态编译扩展，静态扩展没有可重复加载的 swoole.so 文件。
        $command = [PHP_BINARY, '-d', 'memory_limit=512M', $root . '/bin/typeapp'];
    } else {
        $swoole = getenv('TYPE_SWOOLE_MODULE');
        $swoole = is_string($swoole) && $swoole !== '' ? $swoole : rtrim((string) ini_get('extension_dir'), '/') . '/swoole.so';
        expect(is_file($swoole), 'PHP 调试凭据验收需要已加载Swoole或TYPE_SWOOLE_MODULE');
        $command = [PHP_BINARY, '-d', 'memory_limit=512M', '-d', 'extension=' . $swoole, $root . '/bin/typeapp'];
    }
} else {
    $command = nativeCommand($target);
}
$base = $root . '/build/broker-debug-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700, true), '无法创建调试凭据测试目录');
$noSource = in_array('--no-source', $argv, true);
$browserDist = '';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--browser-dist=')) {
        $browserDist = substr($argument, 15);
    }
}
if ($noSource) {
    expect($target !== '--php' && PHP_OS_FAMILY === 'Darwin', '无源码调试凭据验收使用macOS内核策略及原生产物');
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
$fixtureSync = new PostgresSync($fixtureDatabase, $base . '/standby', $databaseTools, 'broker_debug_sync');
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
$environment['BROKER_STANDBY_NAMES'] = 'broker_debug_sync';
$checks = 0;
$report = ['status' => 'running', 'scope' => 'broker-debug', 'native' => $target !== '--php', 'driver' => 'pgsql',
    'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'no_source' => $noSource];
$server = null;
$node = null;
$appServer = null;
$browser = null;
$waiter = null;
$holders = [];
$serviceClient = null;
$secrets = [];
$certs = debugCertFiles($base . '/certs');
$clientRoot = $base . '/mqtt-js';
expect(mkdir($clientRoot, 0700), '无法创建 MQTT.js 目录');
file_put_contents($clientRoot . '/package.json', json_encode(['name' => 'typeapp-broker-debug-client', 'private' => true, 'dependencies' => ['mqtt' => '5.15.0']], JSON_THROW_ON_ERROR));
$installJs = new Process(['npm', 'install', '--ignore-scripts', '--no-audit', '--no-fund'], $clientRoot, getenv());
try {
    expect($installJs->wait(120)->successful(), '安装 MQTT.js 5.15.0 失败：' . $installJs->stderr());
} finally {
    $installJs->stop();
}
try {
    $install = new Process([...$command, 'broker:install'], $root, $environment);
    try {
        expect($install->wait(30)->successful(), '独立调试凭据库安装失败：' . $install->stdout() . $install->stderr());
    } finally {
        $install->stop();
    }
    $store = new Process([...$command, 'broker:store-install'], $root, $environment);
    try {
        expect($store->wait(30)->successful(), '独立持久存储安装失败：' . $store->stdout() . $store->stderr());
    } finally {
        $store->stop();
    }
    $password = bin2hex(random_bytes(16));
    $secrets[] = $password;
    identityCommand([...$command, 'broker:user', 'access-admin', '调试订阅管理员'], $environment + ['BROKER_ADMIN_PASSWORD' => $password]);
    $addresses = [];
    for ($index = 0; $index < 5; $index++) {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorText);
        expect(is_resource($listener), '无法选择调试凭据测试端口');
        $addresses[] = stream_socket_get_name($listener, false);
        fclose($listener);
    }
    $previewOrigin = 'http://' . $addresses[4];
    $environment['APP_PORT'] = substr(strrchr($addresses[0], ':'), 1);
    $environment['APP_ALLOWED_HOSTS'] = $addresses[0];
    $server = new Process([...$command, 'broker:serve'], $root, $environment);
    $client = new HttpClient('http://' . $addresses[0], 12.0);
    $deadline = microtime(true) + 10;
    do {
        expect($server->running(), '独立调试凭据管理提前退出：' . $server->stderr());
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
    $request('POST', '/broker/debug', $token, ['unexpected' => true], 422);
    $mqttPassword = bin2hex(random_bytes(16));
    $secrets[] = $mqttPassword;
    $tlsPort = (int) substr(strrchr($addresses[1], ':'), 1);
    $wssPort = (int) substr(strrchr($addresses[3], ':'), 1);
    $nodeEnvironment = $environment + [
        'BROKER_CLIENT_USERNAME' => 'broker-client', 'BROKER_CLIENT_PASSWORD' => $mqttPassword,
        'BROKER_TOPIC_PREFIX' => 'broker-debug/', 'BROKER_NODE_ID' => 'debug-node',
        'BROKER_LISTEN' => '127.0.0.1', 'BROKER_PLAINTEXT' => 'false',
        'BROKER_PORT' => (string) $tlsPort, 'BROKER_WSS_PORT' => (string) $wssPort,
        'BROKER_CERTIFICATE' => $certs['server'], 'BROKER_PRIVATE_KEY' => $certs['key'],
        'BROKER_ALLOWED_ORIGINS' => $previewOrigin,
    ];
    $node = new Process([...$command, 'broker:run'], $root, $nodeEnvironment);
    debugWaitTls($node, $tlsPort, $certs['ca']);
    $deadline = microtime(true) + 12;
    do {
        expect($node->running(), '调试节点提前退出：' . $node->stderr());
        $nodes = $request('GET', '/broker/nodes', $token, null, 200);
        if (($nodes['total'] ?? 0) === 1) {
            break;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    expect(($nodes['total'] ?? 0) === 1, '调试节点未上报');
    $current = $request('GET', '/broker/debug', $token, null, 200);
    expect(($current['transport']['available'] ?? false) === true && ($current['transport']['wss_port'] ?? 0) === $wssPort
        && $current['credential'] === null, 'WSS 入口未进入调试查询');
    $issued = $request('POST', '/broker/debug', $token, [], 200)['data'];
    expect(isset($issued['password'], $issued['username'], $issued['client_id'], $issued['subscribe_topic'], $issued['publish_topic'])
        && str_starts_with($issued['username'], 'debug:') && $issued['subscribe_topic'] === 'broker-debug/'
        && $issued['publish_topic'] === 'broker-debug/debug/' && $issued['expires_at'] > time()
        && $issued['expires_at'] <= time() + 600, '签发字段或期限错误');
    $secrets[] = $issued['password'];
    $listed = $request('GET', '/broker/debug', $token, null, 200);
    expect(($listed['credential']['username'] ?? '') === $issued['username'] && !isset($listed['credential']['password']), '查询回显了调试口令');
    $mqttEnv = array_replace(getenv(), [
        'DEBUG_MQTT_URL' => $issued['transport']['url'],
        'DEBUG_MQTT_USERNAME' => $issued['username'],
        'DEBUG_MQTT_PASSWORD' => $issued['password'],
        'DEBUG_MQTT_CLIENT_ID' => $issued['client_id'],
        'DEBUG_MQTT_SUBSCRIBE' => $issued['subscribe_topic'] . '#',
        'DEBUG_MQTT_PUBLISH' => $issued['publish_topic'] . 'ping',
    ]);
    expect(debugMqtt($root, $clientRoot, 'roundtrip', $mqttEnv, $certs['ca']) === 'delivered', '标准 mqtt.js 未收到授权消息');
    $mqttEnv['DEBUG_MQTT_SUBSCRIBE'] = 'foreign/denied';
    expect(debugMqtt($root, $clientRoot, 'deny-subscribe', $mqttEnv, $certs['ca']) === 'denied', '越权订阅未被拒绝');
    $mqttEnv['DEBUG_MQTT_SUBSCRIBE'] = $issued['subscribe_topic'] . '#';
    $mqttEnv['DEBUG_MQTT_PUBLISH'] = 'broker-debug/devices/x/up';
    expect(debugMqtt($root, $clientRoot, 'deny-publish', $mqttEnv, $certs['ca']) === 'denied', '越权发布未被拒绝');
    $mqttEnv['DEBUG_MQTT_PASSWORD'] = $token;
    $mqttEnv['DEBUG_MQTT_PUBLISH'] = $issued['publish_topic'] . 'ping';
    expect(debugMqtt($root, $clientRoot, 'connect-fail', $mqttEnv, $certs['ca']) === 'denied', '人员令牌被当成 MQTT 口令');
    $mqttEnv['DEBUG_MQTT_PASSWORD'] = $issued['password'];
    expect(debugMqtt($root, $clientRoot, 'roundtrip', $mqttEnv, $certs['ca']) === 'delivered', '清理会话前的首次交付失败');
    expect(debugMqtt($root, $clientRoot, 'roundtrip', $mqttEnv, $certs['ca']) === 'delivered', '重连后应只收到新消息');
    debugSettle();
    $waiter = new Process(['node', $root . '/tests/broker-debug-client.mjs', $clientRoot, 'wait-close', $certs['ca']], $root, $mqttEnv);
    debugWaitConnected($waiter);
    $request('POST', '/broker/auth/logout', $token, [], 200);
    $closed = $waiter->wait(8);
    expect($closed->successful() && debugClosed($closed->stdout), '退出后旧连接未在 5 秒内断开：' . $closed->stdout . $closed->stderr);
    $waiter->stop();
    $waiter = null;
    debugSettle();
    expect(debugMqtt($root, $clientRoot, 'connect-fail', $mqttEnv, $certs['ca']) === 'denied', '退出后旧凭据仍可接入');
    $auth = $request('POST', '/broker/auth/login', '', ['login' => 'access-admin', 'password' => $password], 200)['data'];
    $token = $auth['accessToken'];
    $secrets[] = $token;
    $fresh = $request('POST', '/broker/debug', $token, [], 200)['data'];
    $secrets[] = $fresh['password'];
    debugSettle();
    $liveEnv = array_replace($mqttEnv, [
        'DEBUG_MQTT_USERNAME' => $fresh['username'], 'DEBUG_MQTT_PASSWORD' => $fresh['password'],
        'DEBUG_MQTT_CLIENT_ID' => $fresh['client_id'], 'DEBUG_MQTT_URL' => $fresh['transport']['url'],
        'DEBUG_MQTT_SUBSCRIBE' => $fresh['subscribe_topic'] . '#', 'DEBUG_MQTT_PUBLISH' => $fresh['publish_topic'] . 'ping',
    ]);
    $waiter = new Process(['node', $root . '/tests/broker-debug-client.mjs', $clientRoot, 'wait-close', $certs['ca']], $root, $liveEnv);
    debugWaitConnected($waiter);
    $renewed = $request('POST', '/broker/debug', $token, [], 200)['data'];
    $secrets[] = $renewed['password'];
    $replaced = $waiter->wait(8);
    expect($replaced->successful() && debugClosed($replaced->stdout), '重新签发后旧连接未断开：' . $replaced->stdout . $replaced->stderr);
    $waiter->stop();
    $waiter = null;
    debugSettle();
    $renewEnv = array_replace($liveEnv, [
        'DEBUG_MQTT_USERNAME' => $renewed['username'], 'DEBUG_MQTT_PASSWORD' => $renewed['password'],
        'DEBUG_MQTT_CLIENT_ID' => $renewed['client_id'],
    ]);
    expect(debugMqtt($root, $clientRoot, 'roundtrip', $renewEnv, $certs['ca']) === 'delivered', '新凭据重连失败');
    $renewEnv['DEBUG_MQTT_SUBSCRIBE'] = $renewed['subscribe_topic'] . '#';
    $renewEnv['DEBUG_MQTT_PUBLISH'] = $renewed['publish_topic'] . 'ping';
    expect(debugMqtt($root, $clientRoot, 'binary', $renewEnv, $certs['ca']) === 'binary', '二进制测试发布未原样交付');
    $audit = $request('GET', '/broker/audit?action=broker.debug.issue&limit=20', $token, null, 200);
    expect($audit['items'] !== [], '调试签发未留下审计');
    $encoded = json_encode($audit, JSON_THROW_ON_ERROR);
    expect(!str_contains($encoded, $issued['password']) && !str_contains($encoded, $fresh['password'])
        && !str_contains($encoded, $renewed['password']) && !str_contains($encoded, $password), '调试审计包含口令');
    $listed = $request('GET', '/broker/debug', $token, null, 200);
    expect(($listed['occupancy']['person_limit'] ?? 0) === 2 && ($listed['occupancy']['global_limit'] ?? 0) === 20, '调试名额上限未进入查询');
    $credA = $request('POST', '/broker/debug', $token, [], 200)['data'];
    $secrets[] = $credA['password'];
    $tokenB = $request('POST', '/broker/auth/login', '', ['login' => 'access-admin', 'password' => $password], 200)['data']['accessToken'];
    $secrets[] = $tokenB;
    $credB = $request('POST', '/broker/debug', $tokenB, [], 200)['data'];
    $secrets[] = $credB['password'];
    $holdA = new Process(['node', $root . '/tests/broker-debug-client.mjs', $clientRoot, 'hold', $certs['ca']], $root, array_replace($renewEnv, [
        'DEBUG_MQTT_USERNAME' => $credA['username'], 'DEBUG_MQTT_PASSWORD' => $credA['password'],
        'DEBUG_MQTT_CLIENT_ID' => $credA['client_id'], 'DEBUG_MQTT_URL' => $credA['transport']['url'],
    ]));
    $holders[] = $holdA;
    debugWaitHeld($holdA, 1);
    $holdB = new Process(['node', $root . '/tests/broker-debug-client.mjs', $clientRoot, 'hold', $certs['ca']], $root, array_replace($renewEnv, [
        'DEBUG_MQTT_USERNAME' => $credB['username'], 'DEBUG_MQTT_PASSWORD' => $credB['password'],
        'DEBUG_MQTT_CLIENT_ID' => $credB['client_id'], 'DEBUG_MQTT_URL' => $credB['transport']['url'],
    ]));
    $holders[] = $holdB;
    debugWaitHeld($holdB, 1);
    $takeover = new Process(['node', $root . '/tests/broker-debug-client.mjs', $clientRoot, 'hold', $certs['ca']], $root, array_replace($renewEnv, [
        'DEBUG_MQTT_USERNAME' => $credA['username'], 'DEBUG_MQTT_PASSWORD' => $credA['password'],
        'DEBUG_MQTT_CLIENT_ID' => $credA['client_id'], 'DEBUG_MQTT_URL' => $credA['transport']['url'],
    ]));
    $holders[] = $takeover;
    debugWaitHeld($takeover, 1);
    $taken = $holdA->wait(8);
    expect(debugClosed($taken->stdout), '同 Client ID 重连应接管旧连接：' . $taken->stdout . $taken->stderr);
    $holdA->stop();
    debugSettle();
    $tokenC = $request('POST', '/broker/auth/login', '', ['login' => 'access-admin', 'password' => $password], 200)['data']['accessToken'];
    $secrets[] = $tokenC;
    $credC = $request('POST', '/broker/debug', $tokenC, [], 200)['data'];
    $secrets[] = $credC['password'];
    expect(debugMqtt($root, $clientRoot, 'connect-quota', array_replace($renewEnv, [
        'DEBUG_MQTT_USERNAME' => $credC['username'], 'DEBUG_MQTT_PASSWORD' => $credC['password'],
        'DEBUG_MQTT_CLIENT_ID' => $credC['client_id'], 'DEBUG_MQTT_URL' => $credC['transport']['url'],
    ]), $certs['ca']) === 'quota', '同一人员第三路调试未按 0x97 拒绝');
    $occupancy = $request('GET', '/broker/debug', $tokenC, null, 200)['occupancy'];
    expect(($occupancy['person_used'] ?? 0) === 2 && ($occupancy['global_used'] ?? 0) === 2, '同一人员占用未计为 2：' . json_encode($occupancy));
    $holdItems = [];
    for ($index = 1; $index <= 9; $index++) {
        $login = sprintf('debug-slot-%02d', $index);
        identityCommand([...$command, 'broker:user', $login, '调试名额用户' . $index], $environment + ['BROKER_ADMIN_PASSWORD' => $password]);
        for ($session = 0; $session < 2; $session++) {
            $slotToken = $request('POST', '/broker/auth/login', '', ['login' => $login, 'password' => $password], 200)['data']['accessToken'];
            $secrets[] = $slotToken;
            $slotCred = $request('POST', '/broker/debug', $slotToken, [], 200)['data'];
            $secrets[] = $slotCred['password'];
            $holdItems[] = ['clientId' => $slotCred['client_id'], 'username' => $slotCred['username'], 'password' => $slotCred['password']];
        }
    }
    $holdFile = $base . '/debug-hold.json';
    file_put_contents($holdFile, json_encode($holdItems, JSON_THROW_ON_ERROR));
    chmod($holdFile, 0600);
    $holdMany = new Process(['node', $root . '/tests/broker-debug-client.mjs', $clientRoot, 'hold-many', $certs['ca']], $root, array_replace($renewEnv, [
        'DEBUG_MQTT_HOLD_FILE' => $holdFile, 'DEBUG_MQTT_URL' => $credA['transport']['url'],
    ]));
    $holders[] = $holdMany;
    debugWaitHeld($holdMany, 18);
    identityCommand([...$command, 'broker:user', 'debug-slot-10', '调试名额用户10'], $environment + ['BROKER_ADMIN_PASSWORD' => $password]);
    $tokenExtra = $request('POST', '/broker/auth/login', '', ['login' => 'debug-slot-10', 'password' => $password], 200)['data']['accessToken'];
    $secrets[] = $tokenExtra;
    $credExtra = $request('POST', '/broker/debug', $tokenExtra, [], 200)['data'];
    $secrets[] = $credExtra['password'];
    expect(debugMqtt($root, $clientRoot, 'connect-quota', array_replace($renewEnv, [
        'DEBUG_MQTT_USERNAME' => $credExtra['username'], 'DEBUG_MQTT_PASSWORD' => $credExtra['password'],
        'DEBUG_MQTT_CLIENT_ID' => $credExtra['client_id'], 'DEBUG_MQTT_URL' => $credExtra['transport']['url'],
    ]), $certs['ca']) === 'quota', '全局第 21 路调试未按 0x97 拒绝');
    $global = $request('GET', '/broker/debug', $tokenC, null, 200)['occupancy'];
    expect(($global['global_used'] ?? 0) === 20, '全局占用未计满 20：' . json_encode($global));
    foreach ($holders as $holder) {
        $holder->stop(5.0);
    }
    $holders = [];
    $deadline = microtime(true) + 8;
    do {
        $drained = $request('GET', '/broker/debug', $tokenC, null, 200)['occupancy'];
        if (($drained['global_used'] ?? -1) === 0) {
            break;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    expect(($drained['global_used'] ?? -1) === 0, '调试占用未在断开后释放：' . json_encode($drained));
    expect($node->running(), '调试节点在名额释放后退出：' . $node->stderr());
    debugSettle();
    $servicePassword = bin2hex(random_bytes(16));
    $secrets[] = $servicePassword;
    $access = $request('GET', '/broker/access/principals', $token, null, 200);
    $serviceRevision = $request('POST', '/broker/access/principals', $token, [
        'name' => '业务服务', 'login' => 'service:business', 'password' => $servicePassword, 'enabled' => 1,
        'expected_version' => $access['current_version'],
        'grants' => [['topic' => 'broker-debug/', 'publish' => 1, 'subscribe' => 1, 'max_qos' => 1]],
    ], 200)['data'];
    $deadline = microtime(true) + 12;
    do {
        $revision = $request('GET', '/broker/access/revisions/' . $serviceRevision['id'], $token, null, 200)['item'];
        if (($revision['status'] ?? '') === 'effective' && ($revision['nodes'] ?? []) !== []) {
            break;
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    expect(($revision['status'] ?? '') === 'effective', '业务服务主体未生效');
    $quotas = $request('GET', '/broker/quotas', $token, null, 200);
    $request('POST', '/broker/quotas', $token, [
        'expected_version' => $quotas['current_version'], 'maximumServiceConnections' => 1, 'confirmed' => true,
    ], 200);
    $deadline = microtime(true) + 12;
    do {
        $applied = $request('GET', '/broker/quotas', $token, null, 200);
        if (debugQuotaReady($applied, 1)) {
            break;
        }
        usleep(40000);
    } while (microtime(true) < $deadline);
    expect(debugQuotaReady($applied, 1), '服务连接额度未降到 1：' . json_encode($applied['revision'] ?? []));
    $preemptCred = $request('POST', '/broker/debug', $token, [], 200)['data'];
    $secrets[] = $preemptCred['password'];
    $preemptHold = new Process(['node', $root . '/tests/broker-debug-client.mjs', $clientRoot, 'hold', $certs['ca']], $root, array_replace($renewEnv, [
        'DEBUG_MQTT_USERNAME' => $preemptCred['username'], 'DEBUG_MQTT_PASSWORD' => $preemptCred['password'],
        'DEBUG_MQTT_CLIENT_ID' => $preemptCred['client_id'], 'DEBUG_MQTT_URL' => $preemptCred['transport']['url'],
    ]));
    $holders[] = $preemptHold;
    debugWaitHeld($preemptHold, 1);
    $serviceClient = new Client('127.0.0.1', $tlsPort, 'service-business-1', 'service:business', $servicePassword, $certs['ca'], '127.0.0.1', 30, 0);
    expect($serviceClient->connect(true) === false, '业务服务 CONNECT 失败');
    $preempted = $preemptHold->wait(8);
    expect(
        debugClosed($preempted->stdout) && str_contains($preempted->stdout, 'closed:152'),
        '业务服务接入后调试连接未以 0x98 断开：' . $preempted->stdout . $preempted->stderr
    );
    $preemptHold->stop();
    $holders = [];
    $serviceClient->stop();
    $serviceClient = null;
    $quotas = $request('GET', '/broker/quotas', $token, null, 200);
    $request('POST', '/broker/quotas', $token, [
        'expected_version' => $quotas['current_version'], 'maximumServiceConnections' => 100, 'confirmed' => true,
    ], 200);
    $deadline = microtime(true) + 12;
    do {
        $restored = $request('GET', '/broker/quotas', $token, null, 200);
        if (debugQuotaReady($restored, 100)) {
            break;
        }
        usleep(40000);
    } while (microtime(true) < $deadline);
    expect(debugQuotaReady($restored, 100), '服务连接额度未恢复：' . json_encode($restored['revision'] ?? []));
    $appBase = $base . '/app';
    expect(mkdir($appBase, 0700), '无法创建双端调试测试目录');
    $appEnvironment = $environment;
    $appEnvironment['APP_BASE_PATH'] = $appBase;
    $appEnvironment['APP_PORT'] = substr(strrchr($addresses[2], ':'), 1);
    $appEnvironment['APP_ALLOWED_HOSTS'] = $addresses[2];
    unset($appEnvironment['BROKER_CLIENT_USERNAME'], $appEnvironment['BROKER_CLIENT_PASSWORD'], $appEnvironment['BROKER_TOPIC_PREFIX'], $appEnvironment['BROKER_NODE_ID'], $appEnvironment['BROKER_PLAINTEXT'], $appEnvironment['BROKER_PORT'], $appEnvironment['BROKER_WSS_PORT'], $appEnvironment['BROKER_COMMAND'], $appEnvironment['BROKER_STANDBY_NAMES'], $appEnvironment['BROKER_ALLOWED_ORIGINS']);
    $appEnvironment['DB_DRIVER'] = 'sqlite';
    $appEnvironment['DB_SQLITE_FILE'] = 'app.sqlite';
    unset($appEnvironment['DB_HOST'], $appEnvironment['DB_PORT'], $appEnvironment['DB_DATABASE'], $appEnvironment['DB_USERNAME'], $appEnvironment['DB_PASSWORD']);
    $appPassword = bin2hex(random_bytes(16));
    $secrets[] = $appPassword;
    $appInstall = new Process([...$command, 'app:install', 'platform-admin', '平台管理员', 'customer-admin', '客户管理员', '调试订阅租户'], $root, $appEnvironment + [
        'APP_ADMIN_PASSWORD' => $appPassword, 'APP_CUSTOMER_PASSWORD' => $appPassword . '-customer',
    ]);
    try {
        expect($appInstall->wait(45)->successful(), '双端调试凭据安装失败：' . $appInstall->stderr());
    } finally {
        $appInstall->stop();
    }
    $appServer = new Process([...$command, 'serve'], $root, $appEnvironment);
    $appClient = new HttpClient('http://' . $addresses[2], 8.0);
    $deadline = microtime(true) + 15;
    do {
        expect($appServer->running(), '双端调试凭据HTTP提前退出：' . $appServer->stderr());
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
    $tenantDebug = $appRequest('GET', '/customer/tenants/' . $tenantId . '/broker/debug', $customerToken, $tenantHeaders, null, 200);
    expect(array_key_exists('credential', $tenantDebug) && ($tenantDebug['occupancy']['person_limit'] ?? 0) === 2, '租户调试查询失败');
    $appRequest('POST', '/customer/tenants/' . $tenantId . '/broker/debug', $customerToken, $tenantHeaders, [], 409);
    $appRequest('GET', '/admin/broker/debug', $platformToken, [], null, 403);
    $appRequest('POST', '/admin/broker/debug', $platformToken, [], [], 403);
    $report['http_checks'] = $checks;
    if ($browserDist !== '') {
        expect(is_file($browserDist . '/index.html'), '浏览器验收需要本次隔离前端产物');
        $browser = new Process(['node', $root . '/tests/broker-debug-browser.mjs', $base, $browserDist, 'http://' . $addresses[0], 'http://' . $addresses[2], $previewOrigin], $root, array_replace(getenv(), [
            'BROKER_BROWSER_PASSWORD' => $password,
            'APP_BROWSER_PASSWORD' => $appPassword,
            'APP_BROWSER_CUSTOMER_PASSWORD' => $appPassword . '-customer',
            'APP_BROWSER_TENANT' => $tenantId,
            'BROKER_SERVICE_USERNAME' => 'service:business',
            'BROKER_SERVICE_PASSWORD' => $servicePassword,
            'BROKER_SERVICE_PORT' => (string) $tlsPort,
            'BROKER_SERVICE_CA' => $certs['ca'],
            'BROKER_SERVICE_CLIENT_ID' => 'service-preempt-browser',
        ]));
        $browserResult = $browser->wait(210);
        file_put_contents($base . '/browser.log', str_replace($secrets, '<REDACTED>', $browserResult->stdout . $browserResult->stderr));
        expect($browserResult->successful(), '调试订阅浏览器失败，见browser-debug-report.json');
        $report['browser'] = json_decode(file_get_contents($base . '/browser-debug-report.json'), true, 32, JSON_THROW_ON_ERROR);
        expect(($report['browser']['status'] ?? '') === 'passed', '调试订阅浏览器报告未通过');
    }
    $report['status'] = 'passed';
} finally {
    foreach ($holders as $index => $process) {
        try {
            $process->stop(5.0);
            $report['cleanup']['holder-' . $index] = !$process->running();
        } catch (Throwable $failure) {
            $report['cleanup']['holder-' . $index] = false;
            $report['cleanup_errors'][] = 'holder-' . $index . ':' . $failure->getMessage();
        }
    }
    try {
        $serviceClient?->stop();
        $report['cleanup']['service'] = true;
    } catch (Throwable $failure) {
        $report['cleanup']['service'] = false;
        $report['cleanup_errors'][] = 'service:' . $failure->getMessage();
    }
    foreach (['waiter' => $waiter, 'browser' => $browser, 'node' => $node, 'app' => $appServer, 'http' => $server] as $role => $process) {
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
expect(($report['status'] ?? '') === 'passed', '调试凭据验收未通过，见 ' . $base . '/verification.json');
echo 'Broker 调试凭据通过：' . $base . "/verification.json\n";
exit(0);
