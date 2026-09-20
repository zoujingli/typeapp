<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/native-database.php';
require_once __DIR__ . '/postgres-sync.php';

use Type\Testing\HttpClient;
use Type\Testing\Process;

if (!function_exists('identityCommand')) {
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
 * Broker 互操作：新增传输与身份组合的标准互操作。独立 mqtt.js 5.15.0 与原始 WebSocket 帧，
 * 不把 type-mqtt 编解码器当作唯一客户端。
 */

function interopField(string $value): string
{
    return pack('n', strlen($value)) . $value;
}

function interopLength(int $value): string
{
    $bytes = '';
    do {
        $byte = $value % 128;
        $value = intdiv($value, 128);
        $bytes .= chr($value > 0 ? $byte | 128 : $byte);
    } while ($value > 0);
    return $bytes;
}

function interopConnect(int $version, string $id, string $username, string $password, int $keepalive = 30, int $expiry = 0): string
{
    $flags = "\xc2";
    $properties = '';
    if ($version === 5) {
        $properties = "\x11" . pack('N', $expiry);
    }
    $payload = interopField('MQTT') . chr($version) . $flags . pack('n', $keepalive)
        . ($version === 5 ? interopLength(strlen($properties)) . $properties : '')
        . interopField($id) . interopField($username) . interopField($password);
    return "\x10" . interopLength(strlen($payload)) . $payload;
}

function interopFrame(string $payload, int $opcode): string
{
    $length = strlen($payload);
    $header = chr(0x80 | $opcode);
    if ($length < 126) {
        $header .= chr(0x80 | $length);
    } elseif ($length < 65536) {
        $header .= chr(0x80 | 126) . pack('n', $length);
    } else {
        $header .= chr(0x80 | 127) . pack('J', $length);
    }
    $mask = random_bytes(4);
    $masked = '';
    for ($i = 0; $i < $length; $i++) {
        $masked .= $payload[$i] ^ $mask[$i % 4];
    }
    return $header . $mask . $masked;
}

function interopWsRead(mixed $socket): string
{
    $header = '';
    while (strlen($header) < 2) {
        $chunk = fread($socket, 2 - strlen($header));
        expect($chunk !== false && $chunk !== '', 'WebSocket 帧头读取失败');
        $header .= $chunk;
    }
    $opcode = ord($header[0]) & 0x0f;
    $length = ord($header[1]) & 0x7f;
    $masked = (ord($header[1]) & 0x80) !== 0;
    if ($length === 126) {
        $extended = fread($socket, 2);
        expect(is_string($extended) && strlen($extended) === 2, 'WebSocket 16 位长度读取失败');
        $length = unpack('n', $extended)[1];
    } elseif ($length === 127) {
        $extended = fread($socket, 8);
        expect(is_string($extended) && strlen($extended) === 8, 'WebSocket 64 位长度读取失败');
        $length = unpack('J', $extended)[1];
    }
    $mask = '';
    if ($masked) {
        $mask = fread($socket, 4);
        expect(is_string($mask) && strlen($mask) === 4, 'WebSocket 掩码读取失败');
    }
    $payload = '';
    while (strlen($payload) < $length) {
        $chunk = fread($socket, $length - strlen($payload));
        expect($chunk !== false && $chunk !== '', 'WebSocket 载荷读取失败');
        $payload .= $chunk;
    }
    if ($masked) {
        $decoded = '';
        for ($i = 0; $i < $length; $i++) {
            $decoded .= $payload[$i] ^ $mask[$i % 4];
        }
        $payload = $decoded;
    }
    if ($opcode === 0x8) {
        $code = strlen($payload) >= 2 ? unpack('n', substr($payload, 0, 2))[1] : 0;
        throw new RuntimeException('WebSocket 已关闭：' . $code);
    }
    if ($opcode === 0x9 || $opcode === 0xa) {
        return interopWsRead($socket);
    }
    return $payload;
}

/**
 * @param list<string> $headers
 * @return array{0: mixed, 1: string}
 */
function interopWsHandshake(int $port, array $headers, string $ca, string $origin = ''): array
{
    $socket = @stream_socket_client('tls://127.0.0.1:' . $port, $errno, $error, 5, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => [
        'cafile' => $ca, 'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => '127.0.0.1',
        'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
    ]]));
    expect(is_resource($socket), '无法连接 MQTT WSS：' . $errno . ' ' . $error);
    stream_set_timeout($socket, 8);
    $key = base64_encode(random_bytes(16));
    $wire = "GET /mqtt HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
        . "Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n";
    if ($origin !== '') {
        $wire .= 'Origin: ' . $origin . "\r\n";
    }
    foreach ($headers as $header) {
        $wire .= $header . "\r\n";
    }
    $wire .= "\r\n";
    expect(fwrite($socket, $wire) === strlen($wire), 'WebSocket 握手未写完');
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 1024);
        if ($line === false) {
            break;
        }
        $response .= $line;
        if ($line === "\r\n") {
            break;
        }
    }
    return [$socket, $response];
}

function interopWsWrite(mixed $socket, string $packet): void
{
    $frame = interopFrame($packet, 0x2);
    expect(fwrite($socket, $frame) === strlen($frame), 'MQTT WSS 报文未写完');
}

function interopWaitTls(Process $process, int $port, string $ca): void
{
    $deadline = microtime(true) + 15;
    do {
        expect($process->running(), '互操作节点提前退出：' . $process->stderr());
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
    expect(false, '互操作节点 TLS 入口未就绪：' . $process->stderr());
}

/**
 * @return array<string, string>
 */
function interopCertFiles(string $directory): array
{
    expect(mkdir($directory, 0700), '无法创建证书目录');
    $configuration = $directory . '/openssl.cnf';
    file_put_contents($configuration, "[req]\ndistinguished_name=dn\nx509_extensions=ext\n[dn]\n[ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n[server]\nsubjectAltName=IP:127.0.0.1\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n[client]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=clientAuth\n");
    $options = ['config' => $configuration, 'private_key_bits' => 2048, 'digest_alg' => 'sha256', 'x509_extensions' => 'ca'];
    $caKey = openssl_pkey_new($options);
    $caRequest = openssl_csr_new(['commonName' => 'broker-interop-ca'], $caKey, $options);
    $ca = openssl_csr_sign($caRequest, null, $caKey, 1, $options);
    $serverOptions = $options;
    $serverOptions['x509_extensions'] = 'server';
    $serverKey = openssl_pkey_new($serverOptions);
    $serverRequest = openssl_csr_new(['commonName' => '127.0.0.1'], $serverKey, $serverOptions);
    $server = openssl_csr_sign($serverRequest, $ca, $caKey, 1, $serverOptions);
    $clientOptions = $options;
    $clientOptions['x509_extensions'] = 'client';
    $clientKey = openssl_pkey_new($clientOptions);
    $clientRequest = openssl_csr_new(['commonName' => 'broker-device'], $clientKey, $clientOptions);
    $client = openssl_csr_sign($clientRequest, $ca, $caKey, 1, $clientOptions);
    expect($ca !== false && $server !== false && $client !== false, '无法生成互操作测试证书');
    $paths = ['ca' => $directory . '/ca.pem', 'server' => $directory . '/server.pem', 'key' => $directory . '/server.key',
        'client' => $directory . '/client.pem', 'clientKey' => $directory . '/client.key'];
    expect(openssl_x509_export($ca, $caPem) && openssl_x509_export($server, $serverPem)
        && openssl_pkey_export($serverKey, $serverKeyPem, null, $serverOptions)
        && openssl_x509_export($client, $clientPem) && openssl_pkey_export($clientKey, $clientKeyPem, null, $clientOptions)
        && file_put_contents($paths['ca'], $caPem) !== false && file_put_contents($paths['server'], $serverPem) !== false
        && file_put_contents($paths['key'], $serverKeyPem) !== false && file_put_contents($paths['client'], $clientPem) !== false
        && file_put_contents($paths['clientKey'], $clientKeyPem) !== false, '无法写出互操作测试证书');
    chmod($paths['key'], 0600);
    chmod($paths['clientKey'], 0600);
    $paths['caPem'] = $caPem;
    $paths['clientPem'] = $clientPem;
    return $paths;
}

function interopWaitHttp(Process $process, HttpClient $client): void
{
    $deadline = microtime(true) + 10;
    do {
        expect($process->running(), '互操作管理提前退出：' . $process->stderr());
        try {
            if ($client->request('GET', '/readyz')->status === 200) {
                return;
            }
        } catch (RuntimeException) {
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    expect(false, '互操作管理 HTTP 未就绪');
}

function interopWaitRevision(callable $request, string $token, array $revision, string $message): array
{
    $deadline = microtime(true) + 12;
    do {
        $current = $request('GET', '/broker/access/revisions/' . $revision['id'], $token, null, 200)['item'];
        if (($current['status'] ?? '') === 'effective') {
            return $current;
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    expect(false, $message . '：' . json_encode($current ?? []));
    return [];
}

/**
 * @param array<string, string> $environment
 * @return array<string, mixed>
 */
function interopJs(string $root, string $clientRoot, string $action, array $environment, int $timeout = 90): array
{
    $process = new Process(['node', $root . '/tests/broker-interop-client.mjs', $clientRoot, $action], $root, $environment);
    try {
        $result = $process->wait($timeout);
        expect($result->successful(), '独立 mqtt.js ' . $action . ' 失败：' . $result->stdout . $result->stderr);
        $line = '';
        foreach (array_reverse(preg_split('/\r\n|\n|\r/', trim($result->stdout))) as $candidate) {
            if (str_starts_with($candidate, '{')) {
                $line = $candidate;
                break;
            }
        }
        expect($line !== '', '独立 mqtt.js ' . $action . ' 没有 JSON 结果：' . $result->stdout);
        $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        expect(($decoded['ok'] ?? false) === true, '独立 mqtt.js ' . $action . ' 未通过：' . $line);
        return $decoded;
    } finally {
        $process->stop();
    }
}

function interopConnackLimits(string $packet): void
{
    expect(ord($packet[0]) === 0x20 && ord($packet[2]) === 0 && ord($packet[3]) === 0, 'WSS MQTT5 CONNACK 失败：' . bin2hex($packet));
    expect(str_contains($packet, "\x21\x00\x20"), 'CONNACK 未声明 Receive Maximum 32');
    expect(str_contains($packet, "\x22\x00\x20"), 'CONNACK 未声明 Topic Alias Maximum 32');
    expect(str_contains($packet, "\x27" . pack('N', 1048576)), 'CONNACK 未声明 Maximum Packet Size 1 MiB');
}

function interopWsHandshakeCases(int $wssPort, string $ca, string $username, string $password, string $allowedOrigin): void
{
    [$denied, $deniedHeader] = interopWsHandshake($wssPort, ['Sec-WebSocket-Protocol: mqtt'], $ca, 'http://denied.test');
    expect(str_contains($deniedHeader, '101'), '拒绝 Origin 仍应返回 101');
    $originClose = false;
    try {
        interopWsRead($denied);
    } catch (RuntimeException $error) {
        $originClose = str_contains($error->getMessage(), '1008');
    }
    fclose($denied);
    expect($originClose, '不匹配 Origin 没有按 1008 关闭');

    [$missing, $missingHeader] = interopWsHandshake($wssPort, [], $ca, $allowedOrigin);
    expect(str_contains($missingHeader, '101'), '未提供子协议仍应返回 101');
    $missingClose = false;
    try {
        interopWsRead($missing);
    } catch (RuntimeException $error) {
        $missingClose = str_contains($error->getMessage(), '1002');
    }
    fclose($missing);
    expect($missingClose, '未提供 mqtt 子协议没有按 1002 关闭');

    [$socket, $header] = interopWsHandshake($wssPort, ['Sec-WebSocket-Protocol: mqtt'], $ca, $allowedOrigin);
    expect(str_contains($header, '101') && str_contains(strtolower($header), 'sec-websocket-protocol: mqtt'), '握手未选择 mqtt 子协议：' . $header);
    $connect = interopConnect(5, 'interop-ws-binary', $username, $password, 30, 0);
    interopWsWrite($socket, substr($connect, 0, 8));
    interopWsWrite($socket, substr($connect, 8));
    $ack = interopWsRead($socket);
    expect(ord($ack[0]) === 0x20, '跨 WebSocket 消息的 CONNECT 半包未重组：' . bin2hex($ack));
    interopConnackLimits($ack);
    fwrite($socket, interopFrame('not-mqtt', 0x1));
    $textClosed = false;
    try {
        interopWsRead($socket);
    } catch (RuntimeException $error) {
        $textClosed = str_contains($error->getMessage(), '1003');
    }
    $later = @fwrite($socket, interopFrame("\xc0\x00", 0x2));
    expect($textClosed, '文本帧没有按 1003 关闭');
    expect($later === false || $later === 0 || @fread($socket, 1) === '' || feof($socket), '文本帧关闭后后续报文仍生效');
    fclose($socket);

    $oversize = interopConnect(5, 'interop-oversize', $username, $password, 30, 0);
    [$big, $bigHeader] = interopWsHandshake($wssPort, ['Sec-WebSocket-Protocol: mqtt'], $ca, $allowedOrigin);
    expect(str_contains($bigHeader, '101'), '超大包握手失败');
    interopWsWrite($big, $oversize);
    $bigAck = interopWsRead($big);
    expect(ord($bigAck[0]) === 0x20, '超大包 CONNECT 失败');
    $tooBig = "\x30" . interopLength(1048577) . str_repeat('a', 64);
    interopWsWrite($big, $tooBig);
    $overClosed = false;
    $reason = 0;
    try {
        $reply = interopWsRead($big);
        if ($reply !== '' && ord($reply[0]) === 0xe0) {
            $reason = ord($reply[2] ?? "\0");
            $overClosed = $reason === 0x95;
        }
    } catch (RuntimeException) {
        $overClosed = true;
    }
    fclose($big);
    expect($overClosed, '超过 1 MiB 的报文没有关闭连接，原因码 ' . $reason);
}

/**
 * @param callable(string,string,string,?array,int,array): array $request
 */
function interopKeepAliveCases(int $wssPort, string $ca, string $username, string $password, string $allowedOrigin): void
{
    [$alive, $header] = interopWsHandshake($wssPort, ['Sec-WebSocket-Protocol: mqtt'], $ca, $allowedOrigin);
    expect(str_contains($header, '101'), 'Keep Alive 握手失败');
    interopWsWrite($alive, interopConnect(5, 'interop-keepalive', $username, $password, 30, 0));
    $ack = interopWsRead($alive);
    expect(ord($ack[0]) === 0x20, 'Keep Alive CONNECT 失败');
    stream_set_timeout($alive, 50);
    $expired = false;
    $deadline = microtime(true) + 48.0;
    while (microtime(true) < $deadline) {
        try {
            $message = interopWsRead($alive);
            if ($message !== '' && ord($message[0]) === 0xe0 && isset($message[2]) && ord($message[2]) === 0x8d) {
                $expired = true;
                break;
            }
        } catch (RuntimeException) {
            $expired = true;
            break;
        }
    }
    fclose($alive);
    expect($expired, 'Keep Alive 30 秒未按 1.5 倍超时关闭');
}

$root = dirname(__DIR__);
$target = $argv[1] ?? '--php';
$driver = $argv[2] ?? 'pgsql';
expect($driver === 'pgsql', '互操作验收需要 PostgreSQL 同步存储');
if ($target === '--php') {
    $swoole = getenv('TYPE_SWOOLE_MODULE');
    $swoole = is_string($swoole) && $swoole !== '' ? $swoole : rtrim((string) ini_get('extension_dir'), '/') . '/swoole.so';
    expect(is_file($swoole), 'PHP 互操作验收需要 TYPE_SWOOLE_MODULE 或 extension_dir 中的 swoole.so');
    $command = [PHP_BINARY, '-d', 'extension=' . $swoole, $root . '/bin/typeapp'];
} else {
    $command = nativeCommand($target);
}
$base = $root . '/build/broker-interop-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700, true), '无法创建互操作测试目录');
$noSource = in_array('--no-source', $argv, true);
$browserDist = '';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--browser-dist=')) {
        $browserDist = substr($argument, 15);
    }
}
if ($noSource) {
    expect($target !== '--php' && PHP_OS_FAMILY === 'Darwin', '无源码互操作验收使用macOS内核策略及原生产物');
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
$fixtureSync = new PostgresSync($fixtureDatabase, $base . '/standby', $databaseTools, 'broker_interop_sync');
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
$environment['BROKER_STANDBY_NAMES'] = 'broker_interop_sync';
$checks = 0;
$report = ['status' => 'running', 'scope' => 'broker-interop', 'native' => $target !== '--php', 'driver' => 'pgsql',
    'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'no_source' => $noSource];
$server = null;
$node = null;
$appServer = null;
$browser = null;
$holder = null;
$secrets = [];
$certs = interopCertFiles($base . '/certs');
$clientRoot = $base . '/mqtt-js';
expect(mkdir($clientRoot, 0700), '无法创建 MQTT.js 目录');
file_put_contents($clientRoot . '/package.json', json_encode(['name' => 'typeapp-broker-interop-client', 'private' => true, 'dependencies' => ['mqtt' => '5.15.0']], JSON_THROW_ON_ERROR));
$installJs = new Process(['npm', 'install', '--ignore-scripts', '--no-audit', '--no-fund'], $clientRoot, getenv());
try {
    expect($installJs->wait(120)->successful(), '安装 MQTT.js 5.15.0 失败：' . $installJs->stderr());
} finally {
    $installJs->stop();
}
try {
    $install = new Process([...$command, 'broker:install'], $root, $environment);
    try {
        expect($install->wait(30)->successful(), '独立互操作库安装失败：' . $install->stdout() . $install->stderr());
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
    identityCommand([...$command, 'broker:user', 'access-admin', '互操作管理员'], $environment + ['BROKER_ADMIN_PASSWORD' => $password]);
    $addresses = [];
    for ($index = 0; $index < 7; $index++) {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorText);
        expect(is_resource($listener), '无法选择互操作测试端口');
        $addresses[] = stream_socket_get_name($listener, false);
        fclose($listener);
    }
    $previewOrigin = 'http://' . $addresses[4];
    $deniedOrigin = 'http://' . $addresses[6];
    $environment['APP_PORT'] = substr(strrchr($addresses[0], ':'), 1);
    $environment['APP_ALLOWED_HOSTS'] = $addresses[0];
    $server = new Process([...$command, 'broker:serve'], $root, $environment);
    $client = new HttpClient('http://' . $addresses[0], 12.0);
    interopWaitHttp($server, $client);
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
    $wssPort = (int) substr(strrchr($addresses[3], ':'), 1);
    $mtlsPort = (int) substr(strrchr($addresses[5], ':'), 1);
    $nodeEnvironment = $environment + [
        'BROKER_CLIENT_USERNAME' => 'broker-client', 'BROKER_CLIENT_PASSWORD' => $mqttPassword,
        'BROKER_TOPIC_PREFIX' => 'broker-interop/', 'BROKER_NODE_ID' => 'interop-node',
        'BROKER_LISTEN' => '127.0.0.1', 'BROKER_PLAINTEXT' => 'false',
        'BROKER_PORT' => (string) $tlsPort, 'BROKER_WSS_PORT' => (string) $wssPort,
        'BROKER_CERTIFICATE' => $certs['server'], 'BROKER_PRIVATE_KEY' => $certs['key'],
        'BROKER_ALLOWED_ORIGINS' => $previewOrigin,
    ];
    $node = new Process([...$command, 'broker:run'], $root, $nodeEnvironment);
    interopWaitTls($node, $tlsPort, $certs['ca']);
    $deadline = microtime(true) + 12;
    do {
        expect($node->running(), '互操作节点提前退出：' . $node->stderr());
        $nodes = $request('GET', '/broker/nodes', $token, null, 200);
        if (($nodes['total'] ?? 0) === 1) {
            break;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    expect(($nodes['total'] ?? 0) === 1, '互操作节点未上报');
    interopWsHandshakeCases($wssPort, $certs['ca'], 'broker-client', $mqttPassword, $previewOrigin);
    $jsEnv = array_replace(getenv(), [
        'INTEROP_MQTT_TLS_PORT' => (string) $tlsPort,
        'INTEROP_MQTT_WSS_PORT' => (string) $wssPort,
        'INTEROP_MQTT_USERNAME' => 'broker-client',
        'INTEROP_MQTT_PASSWORD' => $mqttPassword,
        'INTEROP_MQTT_CA' => $certs['ca'],
        'INTEROP_MQTT_PREFIX' => 'broker-interop/',
    ]);
    $report['mqttjs_matrix'] = interopJs($root, $clientRoot, 'matrix', $jsEnv, 120);
    $report['mqttjs_features'] = interopJs($root, $clientRoot, 'features', $jsEnv, 90);
    $report['mqttjs_cross'] = interopJs($root, $clientRoot, 'cross', $jsEnv, 60);
    $report['mqttjs_session'] = interopJs($root, $clientRoot, 'session', $jsEnv, 90);
    $pdo = new PDO(
        'pgsql:host=' . $environment['DB_HOST'] . ';port=' . $environment['DB_PORT'] . ';dbname=' . $environment['DB_DATABASE'],
        $environment['DB_USERNAME'],
        $environment['DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $session5 = $pdo->query("SELECT expiry FROM type_mqtt_sessions WHERE client_id = 'interop-session-5'")->fetch(PDO::FETCH_ASSOC);
    $session311 = $pdo->query("SELECT expiry, expires_at FROM type_mqtt_sessions WHERE client_id = 'interop-session-311'")->fetch(PDO::FETCH_ASSOC);
    expect(is_array($session5) && (int) $session5['expiry'] === 86400, 'MQTT5 显式 Session Expiry 不是 86400');
    expect(is_array($session311) && (int) $session311['expiry'] === -1 && $session311['expires_at'] === null, 'MQTT 3.1.1 CleanSession=0 被套用了 24 小时 TTL');
    $retain = $request('GET', '/broker/resources/retained?topic=' . rawurlencode('broker-interop/retain') . '&limit=20', $token, null, 200);
    expect(($retain['items'][0]['topic'] ?? '') === 'broker-interop/retain', '保留消息未进入资源查询');
    $item = $retain['items'][0];
    $clearBody = ['generation' => (int) $item['generation'], 'confirmed' => true];
    $clearId = bin2hex(random_bytes(16));
    $cleared = $request('POST', '/broker/resources/retained/' . $item['id'] . '/clear', $token, $clearBody + ['operation_id' => $clearId], 200);
    $deadline = microtime(true) + 12;
    do {
        $cleared = $request('GET', '/broker/operations/' . $clearId, $token, null, 200);
        if (($cleared['stage'] ?? '') === 'completed') {
            break;
        }
        usleep(40000);
    } while (microtime(true) < $deadline);
    expect(($cleared['outcome'] ?? '') === 'cleared', '管理清除保留未完成');
    $holder = new Process(['node', $root . '/tests/broker-interop-client.mjs', $clientRoot, 'hold'], $root, array_replace($jsEnv, [
        'INTEROP_MQTT_CLIENT_ID' => 'interop-hold',
    ]));
    $deadline = microtime(true) + 25;
    do {
        expect($holder->running(), '占用客户端提前退出：' . $holder->stderr());
        if (str_contains($holder->stdout(), 'held:1')) {
            break;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    expect(str_contains($holder->stdout(), 'held:1'), '占用客户端未就绪');
    $connections = ['items' => []];
    $deadline = microtime(true) + 8;
    do {
        $connections = $request('GET', '/broker/resources/connections?limit=50', $token, null, 200);
        foreach ($connections['items'] as $candidate) {
            if (($candidate['client_id'] ?? '') === 'interop-hold' && ($candidate['state'] ?? '') === 'connected') {
                $connections['target'] = $candidate;
                break 2;
            }
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    expect(isset($connections['target']), '占用连接未进入资源观察');
    $targetConnection = $connections['target'];
    $disconnectBody = ['session_id' => $targetConnection['session_id'], 'session_generation' => $targetConnection['session_generation'], 'node_id' => $targetConnection['node_id']];
    $disconnectId = bin2hex(random_bytes(16));
    $request('POST', '/broker/resources/connections/' . $targetConnection['id'] . '/disconnect', $token, $disconnectBody + ['operation_id' => $disconnectId], 200);
    $deadline = microtime(true) + 8;
    do {
        $status = $request('GET', '/broker/operations/' . $disconnectId, $token, null, 200);
        if (($status['stage'] ?? '') === 'completed') {
            break;
        }
        usleep(40000);
    } while (microtime(true) < $deadline);
    expect(($status['outcome'] ?? '') === 'disconnected', '管理断开未完成');
    $holderResult = $holder->wait(8);
    expect(
        str_contains($holderResult->stdout, '"closed":152') || str_contains($holderResult->stdout, '"closed": 152'),
        '管理断开不是 MQTT 5 0x98：' . $holderResult->stdout
    );
    $holder->stop();
    $holder = null;
    $report['mqttjs_cross_after_admin'] = interopJs($root, $clientRoot, 'cross', $jsEnv, 60);
    interopKeepAliveCases($wssPort, $certs['ca'], 'broker-client', $mqttPassword, $previewOrigin);
    $appBase = $base . '/app';
    expect(mkdir($appBase, 0700), '无法创建双端互操作测试目录');
    $appEnvironment = $environment;
    $appEnvironment['APP_BASE_PATH'] = $appBase;
    $appEnvironment['APP_PORT'] = substr(strrchr($addresses[2], ':'), 1);
    $appEnvironment['APP_ALLOWED_HOSTS'] = $addresses[2];
    unset($appEnvironment['BROKER_CLIENT_USERNAME'], $appEnvironment['BROKER_CLIENT_PASSWORD'], $appEnvironment['BROKER_TOPIC_PREFIX'], $appEnvironment['BROKER_NODE_ID'], $appEnvironment['BROKER_PLAINTEXT'], $appEnvironment['BROKER_PORT'], $appEnvironment['BROKER_COMMAND'], $appEnvironment['BROKER_STANDBY_NAMES'], $appEnvironment['BROKER_ALLOWED_ORIGINS'], $appEnvironment['BROKER_WSS_PORT']);
    $appEnvironment['DB_DRIVER'] = 'sqlite';
    $appEnvironment['DB_SQLITE_FILE'] = 'app.sqlite';
    unset($appEnvironment['DB_HOST'], $appEnvironment['DB_PORT'], $appEnvironment['DB_DATABASE'], $appEnvironment['DB_USERNAME'], $appEnvironment['DB_PASSWORD']);
    $appPassword = bin2hex(random_bytes(16));
    $secrets[] = $appPassword;
    $appInstall = new Process([...$command, 'app:install', 'platform-admin', '平台管理员', 'customer-admin', '客户管理员', '互操作租户'], $root, $appEnvironment + [
        'APP_ADMIN_PASSWORD' => $appPassword, 'APP_CUSTOMER_PASSWORD' => $appPassword . '-customer',
    ]);
    try {
        expect($appInstall->wait(45)->successful(), '双端互操作安装失败：' . $appInstall->stderr());
    } finally {
        $appInstall->stop();
    }
    $appServer = new Process([...$command, 'serve'], $root, $appEnvironment);
    $appClient = new HttpClient('http://' . $addresses[2], 8.0);
    interopWaitHttp($appServer, $appClient);
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
    $appRequest('GET', '/customer/tenants/' . $tenantId . '/broker/resources/connections', $customerToken, $tenantHeaders, null, 200);
    $appRequest('POST', '/customer/tenants/' . $tenantId . '/broker/resources/connections/' . str_repeat('0', 32) . '/disconnect', $customerToken, $tenantHeaders, [
        'session_id' => str_repeat('0', 32), 'session_generation' => 1, 'node_id' => 'interop-node',
    ], 404);
    $appRequest('GET', '/admin/broker/resources/connections', $platformToken, [], null, 200);
    $appRequest('POST', '/admin/broker/resources/connections/' . str_repeat('0', 32) . '/disconnect', $platformToken, [], [
        'session_id' => str_repeat('0', 32), 'session_generation' => 1, 'node_id' => 'interop-node',
    ], 404);
    $appRequest('GET', '/customer/tenants/' . $tenantId . '/broker/resources/connections', $customerToken, ['X-Tenant-Id' => bin2hex(random_bytes(16))], null, 403);
    $report['http_checks'] = $checks;
    if ($browserDist !== '') {
        expect(is_file($browserDist . '/index.html'), '浏览器验收需要本次隔离前端产物');
        $browser = new Process(['node', $root . '/tests/broker-interop-browser.mjs', $base, $browserDist, 'http://' . $addresses[0], 'http://' . $addresses[2], $previewOrigin, $deniedOrigin], $root, array_replace(getenv(), [
            'BROKER_BROWSER_PASSWORD' => $password,
            'APP_BROWSER_PASSWORD' => $appPassword,
            'APP_BROWSER_CUSTOMER_PASSWORD' => $appPassword . '-customer',
            'APP_BROWSER_TENANT' => $tenantId,
            'INTEROP_WSS_PORT' => (string) $wssPort,
        ]));
        $browserResult = $browser->wait(180);
        file_put_contents($base . '/browser.log', str_replace($secrets, '<REDACTED>', $browserResult->stdout . $browserResult->stderr));
        expect($browserResult->successful(), '互操作浏览器失败，见browser-interop-report.json');
        $report['browser'] = json_decode(file_get_contents($base . '/browser-interop-report.json'), true, 32, JSON_THROW_ON_ERROR);
        expect(($report['browser']['status'] ?? '') === 'passed', '互操作浏览器报告未通过');
    }
    $listed = $request('GET', '/broker/access/principals', $token, null, 200);
    $principal = $listed['items'][0];
    $caPublished = $request('POST', '/broker/access/cas', $token, ['pem' => $certs['caPem'], 'expected_version' => $listed['current_version']], 200)['data'];
    $caPublished = interopWaitRevision($request, $token, $caPublished, '登记 CA 后节点必须上报已加载版本');
    $bound = $request('POST', '/broker/access/principals/' . $principal['id'], $token, [
        'name' => $principal['name'], 'enabled' => 1, 'expected_version' => $caPublished['version'],
        'grants' => $principal['grants'], 'certificates' => [['pem' => $certs['clientPem']]],
    ], 200)['data'];
    $bound = interopWaitRevision($request, $token, $bound, '绑定证书后节点必须上报已加载版本');
    $node->stop(5);
    $node = null;
    $mtlsEnvironment = $nodeEnvironment + [
        'BROKER_MTLS_PORT' => (string) $mtlsPort,
        'BROKER_CLIENT_CA' => $certs['ca'],
    ];
    $node = new Process([...$command, 'broker:run'], $root, $mtlsEnvironment);
    interopWaitTls($node, $tlsPort, $certs['ca']);
    $deadline = microtime(true) + 12;
    do {
        expect($node->running(), 'mTLS 节点提前退出：' . $node->stderr());
        $nodes = $request('GET', '/broker/nodes', $token, null, 200);
        if (($nodes['total'] ?? 0) === 1) {
            break;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    expect(($nodes['total'] ?? 0) === 1, 'mTLS 节点未上报');
    $jsEnv['INTEROP_MQTT_MTLS_PORT'] = (string) $mtlsPort;
    $jsEnv['INTEROP_MQTT_CERT'] = $certs['client'];
    $jsEnv['INTEROP_MQTT_KEY'] = $certs['clientKey'];
    $report['mqttjs_mtls'] = interopJs($root, $clientRoot, 'mtls', $jsEnv, 90);
    $report['status'] = 'passed';
} finally {
    $pdo = null;
    foreach (['browser' => $browser, 'holder' => $holder, 'node' => $node, 'app' => $appServer, 'http' => $server] as $role => $process) {
        try {
            $process?->stop(5.0);
            $report['cleanup'][$role] = $process === null || !$process->running();
        } catch (Throwable $failure) {
            $report['cleanup'][$role] = false;
            $report['cleanup_errors'][] = $role . ':' . $failure->getMessage();
        }
    }
    try {
        $fixtureSync?->close();
        $report['cleanup']['standby'] = true;
    } catch (Throwable $failure) {
        $report['cleanup']['standby'] = false;
        $report['cleanup_errors'][] = 'standby:' . $failure->getMessage();
    }
    try {
        $fixtureDatabase?->close();
        $report['cleanup']['primary'] = true;
    } catch (Throwable $failure) {
        $report['cleanup']['primary'] = false;
        $report['cleanup_errors'][] = 'primary:' . $failure->getMessage();
    }
    if (($report['status'] ?? '') !== 'passed') {
        $report['status'] = 'failed';
    }
    $report['http_checks'] = $checks;
    $report['capacity_claim'] = false;
    $report['independent_failure_domain'] = false;
    file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
expect(($report['status'] ?? '') === 'passed', 'Broker 互操作验收未通过，见 ' . $base . '/verification.json');
echo 'Broker 互操作通过：' . $base . "/verification.json\n";
exit(0);
