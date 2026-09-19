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
    /** 通过已公开命令写入隔离库中的管理员。 */
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
 * 握手 CA 在线重载，以及监听配置校验保存后由运维重启生效。
 * 需要 PostgreSQL 同步存储；SQLite 双端身份路径只证明 403/422。
 */

function runtimeCertField(string $value): string
{
    return pack('n', strlen($value)) . $value;
}

function runtimeCertLength(int $value): string
{
    $bytes = '';
    do {
        $byte = $value % 128;
        $value = intdiv($value, 128);
        $bytes .= chr($value > 0 ? $byte | 128 : $byte);
    } while ($value > 0);
    return $bytes;
}

function runtimeCertConnect(string $id, string $username = '', string $password = ''): string
{
    $flags = 0x02;
    if ($username !== '') {
        $flags |= 0x80;
    }
    if ($password !== '') {
        $flags |= 0x40;
    }
    $payload = runtimeCertField('MQTT') . "\x05" . chr($flags) . "\x00\x0a\x00" . runtimeCertField($id);
    if ($username !== '') {
        $payload .= runtimeCertField($username);
    }
    if ($password !== '') {
        $payload .= runtimeCertField($password);
    }
    return "\x10" . runtimeCertLength(strlen($payload)) . $payload;
}

function runtimeCertWrite(mixed $socket, string $bytes): void
{
    $written = fwrite($socket, $bytes);
    expect($written === strlen($bytes), 'MQTT 写入不完整');
}

function runtimeCertRead(mixed $socket): string
{
    $first = @fread($socket, 1);
    expect($first !== false && $first !== '', 'MQTT 首字节读取失败');
    $wire = $first;
    $length = 0;
    $multiplier = 1;
    do {
        $byte = fread($socket, 1);
        expect($byte !== false && $byte !== '', 'MQTT 长度不完整');
        $wire .= $byte;
        $value = ord($byte);
        $length += ($value & 127) * $multiplier;
        $multiplier *= 128;
    } while (($value & 128) === 128);
    while ($length > 0) {
        $chunk = fread($socket, $length);
        expect($chunk !== false && $chunk !== '', 'MQTT 报文不完整');
        $wire .= $chunk;
        $length -= strlen($chunk);
    }
    return $wire;
}

function runtimeCertSocket(int $port, string $ca, ?string $client = null, ?string $clientKey = null): mixed
{
    $ssl = [
        'cafile' => $ca, 'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => '127.0.0.1',
        'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        'disable_compression' => true,
    ];
    if ($client !== null && $clientKey !== null) {
        $ssl['local_cert'] = $client;
        $ssl['local_pk'] = $clientKey;
    }
    $socket = @stream_socket_client('tls://127.0.0.1:' . $port, $errno, $error, 5, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => $ssl]));
    expect(is_resource($socket), '无法建立 MQTT TLS：' . $errno . ' ' . $error);
    stream_set_timeout($socket, 5);
    return $socket;
}

function runtimeCertWait(Process $process, int $port, string $ca): void
{
    $deadline = microtime(true) + 15;
    do {
        expect($process->running(), '运行配置节点提前退出：' . $process->stderr());
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
    expect(false, '运行配置节点 TLS 入口未就绪：' . $process->stderr());
}

function runtimeCertFingerprint(string $pem): string
{
    $certificate = openssl_x509_read($pem);
    expect($certificate !== false, '无法读取测试证书');
    $fingerprint = openssl_x509_fingerprint($certificate, 'sha256', false);
    expect(is_string($fingerprint), '无法计算测试证书指纹');
    return strtolower(str_replace(':', '', $fingerprint));
}

/** @return array<string, string> */
function runtimeCertFiles(string $directory): array
{
    expect(mkdir($directory, 0700), '无法创建证书目录');
    $configuration = $directory . '/openssl.cnf';
    file_put_contents($configuration, "[req]\ndistinguished_name=dn\nx509_extensions=ext\n[dn]\n[ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n[server]\nsubjectAltName=IP:127.0.0.1\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n[client]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=clientAuth\n");
    $options = ['config' => $configuration, 'private_key_bits' => 2048, 'digest_alg' => 'sha256', 'x509_extensions' => 'ca'];
    $caKey = openssl_pkey_new($options);
    $caRequest = openssl_csr_new(['commonName' => 'broker-runtime-ca'], $caKey, $options);
    $ca = openssl_csr_sign($caRequest, null, $caKey, 1, $options);
    $extraCaKey = openssl_pkey_new($options);
    $extraCaRequest = openssl_csr_new(['commonName' => 'broker-runtime-extra-ca'], $extraCaKey, $options);
    $extraCa = openssl_csr_sign($extraCaRequest, null, $extraCaKey, 1, $options);
    expect($caKey !== false && $ca !== false && $extraCaKey !== false && $extraCa !== false, '无法生成测试 CA');
    $serverOptions = $options;
    $serverOptions['x509_extensions'] = 'server';
    $serverKey = openssl_pkey_new($serverOptions);
    $serverRequest = openssl_csr_new(['commonName' => '127.0.0.1'], $serverKey, $serverOptions);
    $server = openssl_csr_sign($serverRequest, $ca, $caKey, 1, $serverOptions);
    $clientOptions = $options;
    $clientOptions['x509_extensions'] = 'client';
    $clientKey = openssl_pkey_new($clientOptions);
    $clientRequest = openssl_csr_new(['commonName' => 'broker-runtime-device'], $clientKey, $clientOptions);
    $client = openssl_csr_sign($clientRequest, $ca, $caKey, 1, $clientOptions);
    $extraKey = openssl_pkey_new($clientOptions);
    $extraRequest = openssl_csr_new(['commonName' => 'broker-runtime-extra'], $extraKey, $clientOptions);
    $extra = openssl_csr_sign($extraRequest, $extraCa, $extraCaKey, 1, $clientOptions);
    $rogueKey = openssl_pkey_new($options);
    $rogueRequest = openssl_csr_new(['commonName' => 'rogue-ca'], $rogueKey, $options);
    $rogueCa = openssl_csr_sign($rogueRequest, null, $rogueKey, 1, $options);
    $rogueClientRequest = openssl_csr_new(['commonName' => 'rogue-device'], $rogueKey, $clientOptions);
    $rogue = openssl_csr_sign($rogueClientRequest, $rogueCa, $rogueKey, 1, $clientOptions);
    expect($server !== false && $client !== false && $extra !== false && $rogue !== false, '无法生成测试客户端证书');
    $paths = [
        'ca' => $directory . '/ca.pem', 'server' => $directory . '/server.pem', 'key' => $directory . '/server.key',
        'client' => $directory . '/client.pem', 'clientKey' => $directory . '/client.key',
        'extraCa' => $directory . '/extra-ca.pem', 'extra' => $directory . '/extra.pem', 'extraKey' => $directory . '/extra.key',
        'rogue' => $directory . '/rogue.pem', 'rogueKey' => $directory . '/rogue.key',
    ];
    expect(openssl_x509_export($ca, $caPem) && openssl_x509_export($extraCa, $extraCaPem)
        && openssl_x509_export($server, $serverPem) && openssl_pkey_export($serverKey, $serverKeyPem, null, $serverOptions)
        && openssl_x509_export($client, $clientPem) && openssl_pkey_export($clientKey, $clientKeyPem, null, $clientOptions)
        && openssl_x509_export($extra, $extraPem) && openssl_pkey_export($extraKey, $extraKeyPem, null, $clientOptions)
        && openssl_x509_export($rogue, $roguePem) && openssl_pkey_export($rogueKey, $rogueKeyPem, null, $clientOptions)
        && file_put_contents($paths['ca'], $caPem) !== false && file_put_contents($paths['extraCa'], $extraCaPem) !== false
        && file_put_contents($paths['server'], $serverPem) !== false && file_put_contents($paths['key'], $serverKeyPem) !== false
        && file_put_contents($paths['client'], $clientPem) !== false && file_put_contents($paths['clientKey'], $clientKeyPem) !== false
        && file_put_contents($paths['extra'], $extraPem) !== false && file_put_contents($paths['extraKey'], $extraKeyPem) !== false
        && file_put_contents($paths['rogue'], $roguePem) !== false && file_put_contents($paths['rogueKey'], $rogueKeyPem) !== false, '无法写出测试证书');
    chmod($paths['key'], 0600);
    chmod($paths['clientKey'], 0600);
    chmod($paths['extraKey'], 0600);
    chmod($paths['rogueKey'], 0600);
    $paths['caPem'] = $caPem;
    $paths['extraCaPem'] = $extraCaPem;
    $paths['clientPem'] = $clientPem;
    $paths['extraPem'] = $extraPem;
    $paths['fingerprint'] = runtimeCertFingerprint($clientPem);
    $paths['extraFingerprint'] = runtimeCertFingerprint($extraPem);
    return $paths;
}

$root = dirname(__DIR__);
$target = $argv[1] ?? '--php';
$driver = $argv[2] ?? 'pgsql';
expect($driver === 'pgsql', '运行配置验收需要 PostgreSQL 同步存储');
if ($target === '--php') {
    $swoole = getenv('TYPE_SWOOLE_MODULE');
    $swoole = is_string($swoole) && $swoole !== '' ? $swoole : rtrim((string) ini_get('extension_dir'), '/') . '/swoole.so';
    expect(is_file($swoole), 'PHP 运行配置验收需要 TYPE_SWOOLE_MODULE 或 extension_dir 中的 swoole.so');
    $command = [PHP_BINARY, '-d', 'extension=' . $swoole, $root . '/bin/typeapp'];
} else {
    $command = nativeCommand($target);
}
$base = $root . '/build/broker-runtime-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700, true), '无法创建运行配置测试目录');
$noSource = in_array('--no-source', $argv, true);
$browserDist = '';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--browser-dist=')) {
        $browserDist = substr($argument, 15);
    }
}
if ($noSource) {
    expect($target !== '--php' && PHP_OS_FAMILY === 'Darwin', '无源码运行配置验收使用macOS内核策略及原生产物');
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
$fixtureSync = new PostgresSync($fixtureDatabase, $base . '/standby', $databaseTools, 'broker_runtime_sync');
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
$environment['BROKER_STANDBY_NAMES'] = 'broker_runtime_sync';
$checks = 0;
$report = ['status' => 'running', 'scope' => 'broker-runtime', 'native' => $target !== '--php', 'driver' => 'pgsql',
    'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'no_source' => $noSource];
$server = null;
$node = null;
$session = null;
$restored = null;
$appServer = null;
$browser = null;
$secrets = [];
$certs = runtimeCertFiles($base . '/certs');
try {
    $install = new Process([...$command, 'broker:install'], $root, $environment);
    try {
        expect($install->wait(30)->successful(), '独立运行配置库安装失败：' . $install->stdout() . $install->stderr());
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
    identityCommand([...$command, 'broker:user', 'access-admin', '运行配置管理员'], $environment + ['BROKER_ADMIN_PASSWORD' => $password]);
    $addresses = [];
    for ($index = 0; $index < 5; $index++) {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorText);
        expect(is_resource($listener), '无法选择运行配置测试端口');
        $addresses[] = stream_socket_get_name($listener, false);
        fclose($listener);
    }
    $environment['APP_PORT'] = substr(strrchr($addresses[0], ':'), 1);
    $environment['APP_ALLOWED_HOSTS'] = $addresses[0];
    $server = new Process([...$command, 'broker:serve'], $root, $environment);
    $client = new HttpClient('http://' . $addresses[0], 12.0);
    $deadline = microtime(true) + 10;
    do {
        expect($server->running(), '独立运行配置管理提前退出：' . $server->stderr());
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
    $mqttPassword = bin2hex(random_bytes(16));
    $secrets[] = $mqttPassword;
    $tlsPort = (int) substr(strrchr($addresses[1], ':'), 1);
    $mtlsPort = (int) substr(strrchr($addresses[3], ':'), 1);
    $nextPort = (int) substr(strrchr($addresses[4], ':'), 1);
    $handshakeCa = $base . '/certs/handshake-ca.pem';
    expect(copy($certs['ca'], $handshakeCa) === true, '无法复制握手 CA 文件');
    $nodeEnvironment = $environment + [
        'BROKER_CLIENT_USERNAME' => 'broker-client', 'BROKER_CLIENT_PASSWORD' => $mqttPassword,
        'BROKER_TOPIC_PREFIX' => 'broker-runtime/', 'BROKER_NODE_ID' => 'runtime-node',
        'BROKER_LISTEN' => '127.0.0.1', 'BROKER_PLAINTEXT' => 'false', 'BROKER_IO_DRIVER' => 'swoole',
        'BROKER_PORT' => (string) $tlsPort, 'BROKER_MTLS_PORT' => (string) $mtlsPort,
        'BROKER_CERTIFICATE' => $certs['server'], 'BROKER_PRIVATE_KEY' => $certs['key'],
        'BROKER_CLIENT_CA' => $handshakeCa,
    ];
    $node = new Process([...$command, 'broker:run'], $root, $nodeEnvironment);
    runtimeCertWait($node, $tlsPort, $certs['ca']);
    $deadline = microtime(true) + 12;
    do {
        expect($node->running(), '运行配置节点提前退出：' . $node->stderr());
        $nodes = $request('GET', '/broker/nodes', $token, null, 200);
        if (($nodes['total'] ?? 0) === 1) {
            break;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    expect(($nodes['total'] ?? 0) === 1, '运行配置节点未上报');
    $current = $request('GET', '/broker/runtime', $token, null, 200);
    expect(($current['config']['port'] ?? 0) === $tlsPort && ($current['config']['mtls_port'] ?? 0) === $mtlsPort
        && $current['restart_required'] === true && $current['current_version'] >= 1, '运行配置引导身份错误');
    $encodedCurrent = json_encode($current, JSON_THROW_ON_ERROR);
    expect(!str_contains($encodedCurrent, 'BEGIN PRIVATE KEY') && !str_contains($encodedCurrent, $certs['key']), '运行配置响应包含私钥');
    $request('GET', '/broker/runtime', '', null, 401);
    $request('POST', '/broker/runtime', $token, ['expected_version' => $current['current_version'], 'port' => $nextPort], 422);
    $request('POST', '/broker/runtime/preview', $token, [
        'expected_version' => $current['current_version'], 'plaintext' => true, 'mtls_port' => $mtlsPort,
    ], 422);
    $request('POST', '/broker/runtime/preview', $token, [
        'expected_version' => $current['current_version'], 'port' => $mtlsPort,
    ], 422);
    $listed = $request('GET', '/broker/access/principals', $token, null, 200);
    $principal = $listed['items'][0];
    $caPublished = $request('POST', '/broker/access/cas', $token, ['pem' => $certs['caPem'], 'expected_version' => $listed['current_version']], 200)['data'];
    $deadline = microtime(true) + 12;
    do {
        $caPublished = $request('GET', '/broker/access/revisions/' . $caPublished['id'], $token, null, 200)['item'];
        if (($caPublished['status'] ?? '') === 'effective') {
            break;
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    expect(($caPublished['status'] ?? '') === 'effective', '登记 CA 后节点必须上报已加载版本');
    $bound = $request('POST', '/broker/access/principals/' . $principal['id'], $token, [
        'name' => $principal['name'], 'enabled' => 1, 'expected_version' => $caPublished['version'], 'grants' => $principal['grants'],
        'certificates' => [['pem' => $certs['clientPem']]],
    ], 200)['data'];
    $deadline = microtime(true) + 12;
    do {
        $bound = $request('GET', '/broker/access/revisions/' . $bound['id'], $token, null, 200)['item'];
        if (($bound['status'] ?? '') === 'effective') {
            break;
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    $firstSocket = runtimeCertSocket($mtlsPort, $certs['ca'], $certs['client'], $certs['clientKey']);
    runtimeCertWrite($firstSocket, runtimeCertConnect('runtime-mtls'));
    $firstAck = runtimeCertRead($firstSocket);
    expect(ord($firstAck[0]) === 0x20 && ord($firstAck[3]) === 0, '首个握手 CA 客户端未能接入');
    $beforeReload = $request('GET', '/broker/runtime', $token, null, 200);
    $reloadAt = (int) ($beforeReload['handshake']['reloads'] ?? 0);
    $extraCa = $request('POST', '/broker/access/cas', $token, ['pem' => $certs['extraCaPem'], 'expected_version' => $bound['version']], 200)['data'];
    $deadline = microtime(true) + 12;
    do {
        $extraCa = $request('GET', '/broker/access/revisions/' . $extraCa['id'], $token, null, 200)['item'];
        if (($extraCa['status'] ?? '') === 'effective') {
            break;
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    $reloaded = false;
    $deadline = microtime(true) + 12;
    do {
        $runtime = $request('GET', '/broker/runtime', $token, null, 200);
        if (($runtime['handshake']['reloads'] ?? 0) > $reloadAt) {
            $reloaded = true;
            break;
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    expect($reloaded, '登记第二份 CA 后握手文件未被重载：' . json_encode($runtime['handshake'] ?? []));
    $secondSocket = runtimeCertSocket($mtlsPort, $certs['ca'], $certs['extra'], $certs['extraKey']);
    runtimeCertWrite($secondSocket, runtimeCertConnect('runtime-extra'));
    $secondAck = runtimeCertRead($secondSocket);
    expect(ord($secondAck[0]) === 0x20, '第二份 CA 客户端在未重启时 TLS 握手失败');
    fclose($secondSocket);
    $extraBound = $request('POST', '/broker/access/principals/' . $principal['id'], $token, [
        'name' => $principal['name'], 'enabled' => 1, 'expected_version' => $extraCa['version'], 'grants' => $principal['grants'],
        'certificates' => [['pem' => $certs['extraPem'], 'overlap_seconds' => 86400]],
    ], 200)['data'];
    $deadline = microtime(true) + 12;
    do {
        $extraBound = $request('GET', '/broker/access/revisions/' . $extraBound['id'], $token, null, 200)['item'];
        if (($extraBound['status'] ?? '') === 'effective') {
            break;
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    $boundSocket = runtimeCertSocket($mtlsPort, $certs['ca'], $certs['extra'], $certs['extraKey']);
    runtimeCertWrite($boundSocket, runtimeCertConnect('runtime-extra-bound'));
    $boundAck = runtimeCertRead($boundSocket);
    expect(ord($boundAck[0]) === 0x20 && ord($boundAck[3]) === 0, '已绑定的第二份 CA 客户端未能接入');
    fclose($boundSocket);
    runtimeCertWrite($firstSocket, "\xc0\x00");
    runtimeCertRead($firstSocket);
    $rogueFailed = false;
    try {
        $rogue = runtimeCertSocket($mtlsPort, $certs['ca'], $certs['rogue'], $certs['rogueKey']);
        runtimeCertWrite($rogue, runtimeCertConnect('runtime-rogue'));
        runtimeCertRead($rogue);
        fclose($rogue);
    } catch (Throwable) {
        $rogueFailed = true;
    }
    expect($rogueFailed, '未登记 CA 签发的客户端证书没有被拒绝');
    fclose($firstSocket);
    $current = $request('GET', '/broker/runtime', $token, null, 200);
    $request('POST', '/broker/runtime', $token, ['expected_version' => $current['current_version'], 'port' => $nextPort], 422);
    $portPreview = $request('POST', '/broker/runtime/preview', $token, [
        'expected_version' => $current['current_version'], 'port' => $nextPort,
    ], 200);
    expect($portPreview['tightening'] === false && ($portPreview['config']['port'] ?? 0) === $nextPort, '端口变更预览错误');
    $published = $request('POST', '/broker/runtime', $token, [
        'expected_version' => $current['current_version'], 'port' => $nextPort, 'confirmed' => true,
    ], 200)['data'];
    expect(($published['status'] ?? '') === 'pending' && $published['tightening'] === false, '端口保存应保持待运维重启');
    runtimeCertWait($node, $tlsPort, $certs['ca']);
    $rolled = $request('POST', '/broker/runtime/revisions/' . $published['id'] . '/rollback', $token, [], 200)['data'];
    $deadline = microtime(true) + 12;
    do {
        $rolled = $request('GET', '/broker/runtime/revisions/' . $rolled['id'], $token, null, 200)['item'];
        if (($rolled['status'] ?? '') === 'effective') {
            break;
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    expect(($rolled['status'] ?? '') === 'effective' && ($rolled['config']['port'] ?? 0) === $tlsPort, '非收紧回退后运行中节点应匹配旧端口');
    $current = $request('GET', '/broker/runtime', $token, null, 200);
    $published = $request('POST', '/broker/runtime', $token, [
        'expected_version' => $current['current_version'], 'port' => $nextPort, 'confirmed' => true,
    ], 200)['data'];
    expect(($published['status'] ?? '') === 'pending', '再次保存新端口应待运维重启');
    $session = new Client('127.0.0.1', $tlsPort, 'runtime-session', 'broker-client', $mqttPassword, $certs['ca'], '127.0.0.1', sessionExpiry: 86400);
    expect($session->connect(true) === false, '重启前会话不应报告已恢复');
    expect($session->subscribe('broker-runtime/keep') === 1, '重启前会话应获得订阅');
    $session->stop();
    $session = null;
    $node->stop(5.0);
    expect(!$node->running(), '旧节点在运维重启前未退出');
    $oldFailed = false;
    try {
        runtimeCertSocket($tlsPort, $certs['ca']);
    } catch (Throwable) {
        $oldFailed = true;
    }
    expect($oldFailed, '运维重启前旧端口仍可接入');
    $nodeEnvironment['BROKER_PORT'] = (string) $nextPort;
    $node = new Process([...$command, 'broker:run'], $root, $nodeEnvironment);
    runtimeCertWait($node, $nextPort, $certs['ca']);
    $certHash = hash_file('sha256', $certs['server']);
    $deadline = microtime(true) + 12;
    do {
        expect($node->running(), '重启后的运行配置节点提前退出：' . $node->stderr());
        $applied = $request('GET', '/broker/runtime', $token, null, 200);
        if (($applied['revision']['status'] ?? '') === 'effective' && ($applied['config']['port'] ?? 0) === $nextPort
            && ($applied['loaded'][0]['certificate_sha256'] ?? '') === $certHash) {
            break;
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    expect(($applied['revision']['status'] ?? '') === 'effective' && ($applied['loaded'][0]['certificate_sha256'] ?? '') === $certHash, '运维重启后节点未加载新端口或证书身份变化：' . json_encode($applied['revision'] ?? []));
    $restored = new Client('127.0.0.1', $nextPort, 'runtime-session', 'broker-client', $mqttPassword, $certs['ca'], '127.0.0.1', sessionExpiry: 86400);
    expect($restored->connect(false) === true, '运维重启后持久会话应恢复');
    $current = $request('GET', '/broker/runtime', $token, null, 200);
    $tight = $request('POST', '/broker/runtime/preview', $token, [
        'expected_version' => $current['current_version'], 'allowed_origins' => 'https://denied.test',
    ], 200);
    expect($tight['tightening'] === true, '收紧 Origin 预览未标为收紧');
    $tightened = $request('POST', '/broker/runtime', $token, [
        'expected_version' => $current['current_version'], 'allowed_origins' => 'https://denied.test', 'confirmed' => true,
    ], 200)['data'];
    expect($tightened['tightening'] === true && ($tightened['status'] ?? '') === 'pending', '收紧保存应保持待运维重启');
    $request('POST', '/broker/runtime/revisions/' . $tightened['id'] . '/rollback', $token, [], 409);
    $request('POST', '/broker/runtime/revisions/' . $tightened['id'] . '/retry', $token, [], 200);
    try {
        $restored?->stop();
    } catch (Throwable) {
    }
    $restored = null;
    $node->stop(5.0);
    expect(!$node->running(), '收紧配置生效前旧节点未退出');
    $nodeEnvironment['BROKER_ALLOWED_ORIGINS'] = 'https://denied.test';
    $node = new Process([...$command, 'broker:run'], $root, $nodeEnvironment);
    runtimeCertWait($node, $nextPort, $certs['ca']);
    $appliedTight = [];
    $deadline = microtime(true) + 12;
    do {
        expect($node->running(), '收紧配置重启后的节点提前退出：' . $node->stderr());
        $appliedTight = $request('GET', '/broker/runtime', $token, null, 200);
        if (($appliedTight['revision']['status'] ?? '') === 'effective'
            && ($appliedTight['config']['allowed_origins'] ?? '') === 'https://denied.test') {
            break;
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    expect(($appliedTight['revision']['status'] ?? '') === 'effective', '运维重启后收紧 Origin 未生效：' . json_encode($appliedTight['revision'] ?? []));
    $audit = $request('GET', '/broker/audit?action=broker.runtime.publish&operation_id=' . $published['id'], $token, null, 200);
    expect($audit['items'] !== [] && $audit['items'][0]['operation_id'] === $published['id'], '运行配置未留下可对账审计');
    $encoded = json_encode($audit, JSON_THROW_ON_ERROR);
    expect(!str_contains($encoded, $mqttPassword) && !str_contains($encoded, $password) && !str_contains($encoded, 'BEGIN PRIVATE KEY'), '运行配置审计包含秘密');
    $appBase = $base . '/app';
    expect(mkdir($appBase, 0700), '无法创建双端运行配置测试目录');
    $appEnvironment = $environment;
    $appEnvironment['APP_BASE_PATH'] = $appBase;
    $appEnvironment['APP_PORT'] = substr(strrchr($addresses[2], ':'), 1);
    $appEnvironment['APP_ALLOWED_HOSTS'] = $addresses[2];
    unset($appEnvironment['BROKER_CLIENT_USERNAME'], $appEnvironment['BROKER_CLIENT_PASSWORD'], $appEnvironment['BROKER_TOPIC_PREFIX'], $appEnvironment['BROKER_NODE_ID'], $appEnvironment['BROKER_PLAINTEXT'], $appEnvironment['BROKER_IO_DRIVER'], $appEnvironment['BROKER_PORT'], $appEnvironment['BROKER_COMMAND'], $appEnvironment['BROKER_STANDBY_NAMES']);
    $appEnvironment['DB_DRIVER'] = 'sqlite';
    $appEnvironment['DB_SQLITE_FILE'] = 'app.sqlite';
    unset($appEnvironment['DB_HOST'], $appEnvironment['DB_PORT'], $appEnvironment['DB_DATABASE'], $appEnvironment['DB_USERNAME'], $appEnvironment['DB_PASSWORD']);
    $appPassword = bin2hex(random_bytes(16));
    $secrets[] = $appPassword;
    $appInstall = new Process([...$command, 'app:install', 'platform-admin', '平台管理员', 'customer-admin', '客户管理员', '运行配置租户'], $root, $appEnvironment + [
        'APP_ADMIN_PASSWORD' => $appPassword, 'APP_CUSTOMER_PASSWORD' => $appPassword . '-customer',
    ]);
    try {
        expect($appInstall->wait(45)->successful(), '双端运行配置安装失败：' . $appInstall->stderr());
    } finally {
        $appInstall->stop();
    }
    $appServer = new Process([...$command, 'serve'], $root, $appEnvironment);
    $appClient = new HttpClient('http://' . $addresses[2], 8.0);
    $deadline = microtime(true) + 15;
    do {
        expect($appServer->running(), '双端运行配置HTTP提前退出：' . $appServer->stderr());
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
    $tenantRuntime = $appRequest('GET', '/customer/tenants/' . $tenantId . '/broker/runtime', $customerToken, $tenantHeaders, null, 200);
    $appRequest('POST', '/customer/tenants/' . $tenantId . '/broker/runtime', $customerToken, $tenantHeaders, [
        'expected_version' => $tenantRuntime['current_version'], 'port' => 1884, 'confirmed' => true,
    ], 403);
    $platformRuntime = $appRequest('GET', '/admin/broker/runtime', $platformToken, [], null, 200);
    $appRequest('POST', '/admin/broker/runtime', $platformToken, [], ['expected_version' => $platformRuntime['current_version'], 'port' => 1884], 422);
    $report['http_checks'] = $checks;
    $report['revision'] = ['id' => $published['id'], 'version' => $applied['current_version']];
    $report['status'] = 'passed';
    if ($browserDist !== '') {
        expect(is_file($browserDist . '/index.html'), '浏览器验收需要本次隔离前端产物');
        $browser = new Process(['node', $root . '/tests/broker-runtime-browser.mjs', $base, $browserDist, 'http://' . $addresses[0], 'http://' . $addresses[2]], $root, array_replace(getenv(), [
            'BROKER_BROWSER_PASSWORD' => $password,
            'APP_BROWSER_PASSWORD' => $appPassword,
            'APP_BROWSER_CUSTOMER_PASSWORD' => $appPassword . '-customer',
            'APP_BROWSER_TENANT' => $tenantId,
        ]));
        $browserResult = $browser->wait(180);
        file_put_contents($base . '/browser.log', str_replace($secrets, '<REDACTED>', $browserResult->stdout . $browserResult->stderr));
        expect($browserResult->successful(), '运行配置浏览器失败，见browser-runtime-report.json');
        $report['browser'] = json_decode(file_get_contents($base . '/browser-runtime-report.json'), true, 32, JSON_THROW_ON_ERROR);
        expect(($report['browser']['status'] ?? '') === 'passed', '运行配置浏览器报告未通过');
    }
} finally {
    foreach (['session' => $session, 'restored' => $restored] as $role => $mqtt) {
        try {
            $mqtt?->stop();
        } catch (Throwable) {
        }
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
expect(($report['status'] ?? '') === 'passed', '运行配置验收未通过，见 ' . $base . '/verification.json');
echo 'Broker 运行配置通过：' . $base . "/verification.json\n";
exit(0);
