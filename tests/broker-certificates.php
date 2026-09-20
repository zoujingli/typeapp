<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Type\Mqtt\Client;
use Type\Testing\HttpClient;
use Type\Testing\Process;

/**
 * 管理端登记受信 CA 与客户端证书后，mTLS 入口按指纹形成身份；换证重叠到期、CRL 列入、缺失/过期或吊销后旧连接按撤权意图断开。
 * 握手 CA 仍由节点文件提供。默认 SQLite 开发路径；浏览器脚本另验三端页面。不覆盖集群 5 秒证明。
 */

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

/** MQTT 字符串字段的剩余长度前缀。 */
function brokerCertField(string $value): string
{
    return pack('n', strlen($value)) . $value;
}

/** MQTT 可变剩余长度编码。 */
function brokerCertLength(int $value): string
{
    $bytes = '';
    do {
        $byte = $value % 128;
        $value = intdiv($value, 128);
        $bytes .= chr($value > 0 ? $byte | 128 : $byte);
    } while ($value > 0);
    return $bytes;
}

/** MQTT 5 CONNECT；证书身份可不带用户名口令。 */
function brokerCertConnect(string $id, string $username = '', string $password = ''): string
{
    $flags = 0x02;
    if ($username !== '') {
        $flags |= 0x80;
    }
    if ($password !== '') {
        $flags |= 0x40;
    }
    $payload = brokerCertField('MQTT') . "\x05" . chr($flags) . "\x00\x0a\x00" . brokerCertField($id);
    if ($username !== '') {
        $payload .= brokerCertField($username);
    }
    if ($password !== '') {
        $payload .= brokerCertField($password);
    }
    return "\x10" . brokerCertLength(strlen($payload)) . $payload;
}

/** MQTT 5 SUBSCRIBE，QoS 0。 */
function brokerCertSubscribe(string $topic): string
{
    $body = "\x00\x01\x00" . brokerCertField($topic) . "\x00";
    return "\x82" . brokerCertLength(strlen($body)) . $body;
}

/** 一次写完整报文；部分写入视为失败。 */
function brokerCertWrite(mixed $socket, string $bytes): void
{
    $written = fwrite($socket, $bytes);
    expect($written === strlen($bytes), 'MQTT 写入不完整');
}

/** 按剩余长度读一条完整 MQTT 报文。 */
function brokerCertRead(mixed $socket): string
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

/**
 * 带或不带客户端证书的 TLS 套接字；无客户端证书时只校验服务端。
 */
function brokerCertSocket(int $port, string $ca, ?string $client = null, ?string $clientKey = null): mixed
{
    $ssl = [
        'cafile' => $ca,
        'verify_peer' => true,
        'verify_peer_name' => true,
        'peer_name' => '127.0.0.1',
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

/** 等到节点 TLS 入口可连接，避免管理 API 先于监听就绪。 */
function brokerCertWait(Process $process, int $port, string $ca): void
{
    $deadline = microtime(true) + 15;
    do {
        expect($process->running(), '证书节点提前退出：' . $process->stderr());
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
    expect(false, '证书节点 TLS 入口未就绪：' . $process->stderr());
}

/** SHA-256 指纹小写十六进制，与管理库登记格式一致。 */
function brokerCertFingerprint(string $pem): string
{
    $certificate = openssl_x509_read($pem);
    expect($certificate !== false, '无法读取测试证书');
    $fingerprint = openssl_x509_fingerprint($certificate, 'sha256', false);
    expect(is_string($fingerprint), '无法计算测试证书指纹');
    return strtolower(str_replace(':', '', $fingerprint));
}

/** 等到授权版本被健康节点上报为已生效。 */
function brokerCertWaitRevision(callable $request, string $token, array $revision, string $label): array
{
    $deadline = microtime(true) + 12;
    do {
        $revision = $request('GET', '/broker/access/revisions/' . $revision['id'], $token, null, 200)['item'];
        if (($revision['status'] ?? '') === 'effective') {
            return $revision;
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    expect(($revision['status'] ?? '') === 'effective', $label);
    return $revision;
}

/** 在截止时间内对已连接套接字写 SUBSCRIBE，连接被撤权断开则通过。 */
function brokerCertDisconnected(mixed $socket, string $topic, float $seconds, string $label): void
{
    $disconnected = false;
    $deadline = microtime(true) + $seconds;
    do {
        try {
            brokerCertWrite($socket, brokerCertSubscribe($topic));
            brokerCertRead($socket);
        } catch (Throwable) {
            $disconnected = true;
            break;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    expect($disconnected, $label);
}

/**
 * 生成本次隔离目录内的测试 CA、服务端与客户端证书；私钥不进入管理 API。
 *
 * @return array<string, string>
 */
function brokerCertFiles(string $directory): array
{
    expect(mkdir($directory, 0700), '无法创建证书目录');
    $configuration = $directory . '/openssl.cnf';
    file_put_contents($configuration, "[req]\ndistinguished_name=dn\nx509_extensions=ext\n[dn]\n[ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n[server]\nsubjectAltName=IP:127.0.0.1\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n[client]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=clientAuth\n");
    $options = ['config' => $configuration, 'private_key_bits' => 2048, 'digest_alg' => 'sha256', 'x509_extensions' => 'ca'];
    $caKey = openssl_pkey_new($options);
    $caRequest = openssl_csr_new(['commonName' => 'broker-access-test-ca'], $caKey, $options);
    $ca = openssl_csr_sign($caRequest, null, $caKey, 1, $options);
    expect($caKey !== false && $caRequest !== false && $ca !== false, '无法生成测试 CA');
    openssl_pkey_export($caKey, $caKeyPem, null, $options);
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
    $extraKey = openssl_pkey_new($clientOptions);
    $extraRequest = openssl_csr_new(['commonName' => 'broker-unregistered'], $extraKey, $clientOptions);
    $extra = openssl_csr_sign($extraRequest, $ca, $caKey, 1, $clientOptions);
    $listedKey = openssl_pkey_new($clientOptions);
    $listedRequest = openssl_csr_new(['commonName' => 'broker-listed'], $listedKey, $clientOptions);
    $listed = openssl_csr_sign($listedRequest, $ca, $caKey, 1, $clientOptions);
    $rogueKey = openssl_pkey_new($options);
    $rogueRequest = openssl_csr_new(['commonName' => 'rogue-ca'], $rogueKey, $options);
    $rogueCa = openssl_csr_sign($rogueRequest, null, $rogueKey, 1, $options);
    $rogueClientRequest = openssl_csr_new(['commonName' => 'rogue-device'], $rogueKey, $clientOptions);
    $rogue = openssl_csr_sign($rogueClientRequest, $rogueCa, $rogueKey, 1, $clientOptions);
    expect($server !== false && $client !== false && $extra !== false && $listed !== false && $rogue !== false, '无法生成测试客户端证书');
    $paths = [
        'ca' => $directory . '/ca.pem', 'caKey' => $directory . '/ca.key', 'server' => $directory . '/server.pem', 'key' => $directory . '/server.key',
        'client' => $directory . '/client.pem', 'clientKey' => $directory . '/client.key',
        'extra' => $directory . '/extra.pem', 'extraKey' => $directory . '/extra.key',
        'listed' => $directory . '/listed.pem', 'listedKey' => $directory . '/listed.key',
        'rogue' => $directory . '/rogue.pem', 'rogueKey' => $directory . '/rogue.key',
        'crl' => $directory . '/crl.pem',
    ];
    expect(openssl_x509_export($ca, $caPem) && openssl_x509_export($server, $serverPem)
        && openssl_pkey_export($serverKey, $serverKeyPem, null, $serverOptions)
        && openssl_x509_export($client, $clientPem) && openssl_pkey_export($clientKey, $clientKeyPem, null, $clientOptions)
        && openssl_x509_export($extra, $extraPem) && openssl_pkey_export($extraKey, $extraKeyPem, null, $clientOptions)
        && openssl_x509_export($listed, $listedPem) && openssl_pkey_export($listedKey, $listedKeyPem, null, $clientOptions)
        && openssl_x509_export($rogue, $roguePem) && openssl_pkey_export($rogueKey, $rogueKeyPem, null, $clientOptions)
        && file_put_contents($paths['ca'], $caPem) !== false && file_put_contents($paths['caKey'], $caKeyPem) !== false
        && file_put_contents($paths['server'], $serverPem) !== false
        && file_put_contents($paths['key'], $serverKeyPem) !== false && file_put_contents($paths['client'], $clientPem) !== false
        && file_put_contents($paths['clientKey'], $clientKeyPem) !== false && file_put_contents($paths['extra'], $extraPem) !== false
        && file_put_contents($paths['extraKey'], $extraKeyPem) !== false && file_put_contents($paths['listed'], $listedPem) !== false
        && file_put_contents($paths['listedKey'], $listedKeyPem) !== false && file_put_contents($paths['rogue'], $roguePem) !== false
        && file_put_contents($paths['rogueKey'], $rogueKeyPem) !== false, '无法写出测试证书');
    chmod($paths['key'], 0600);
    chmod($paths['caKey'], 0600);
    chmod($paths['clientKey'], 0600);
    chmod($paths['extraKey'], 0600);
    chmod($paths['listedKey'], 0600);
    chmod($paths['rogueKey'], 0600);
    $paths['caPem'] = $caPem;
    $paths['clientPem'] = $clientPem;
    $paths['extraPem'] = $extraPem;
    $paths['listedPem'] = $listedPem;
    $paths['fingerprint'] = brokerCertFingerprint($clientPem);
    $paths['extraFingerprint'] = brokerCertFingerprint($extraPem);
    $paths['listedFingerprint'] = brokerCertFingerprint($listedPem);
    $paths['listedSerial'] = brokerCertSerial($listedPem);
    $paths['extraSerial'] = brokerCertSerial($extraPem);
    return $paths;
}

/** 规范化证书序列号十六进制。 */
function brokerCertSerial(string $pem): string
{
    $parsed = openssl_x509_parse($pem, false);
    expect(is_array($parsed), '无法解析测试证书序列号');
    $hex = '';
    if (isset($parsed['serialNumberHex']) && is_string($parsed['serialNumberHex'])) {
        $hex = strtolower(str_replace(':', '', $parsed['serialNumberHex']));
    } elseif (isset($parsed['serialNumber'])) {
        $hex = dechex((int) $parsed['serialNumber']);
    }
    $hex = ltrim($hex, '0');
    $hex = $hex === '' ? '00' : (strlen($hex) % 2 === 1 ? '0' . $hex : $hex);
    expect(preg_match('/^[0-9a-f]+$/D', $hex) === 1, '测试证书序列号无效');
    return $hex;
}

/**
 * @param list<string> $serials
 */
function brokerCertCrl(string $directory, string $output, array $serials): void
{
    $configuration = $directory . '/crl.cnf';
    file_put_contents($configuration, "[ca]\ndefault_ca=CA_default\n[CA_default]\ndir=.\ndatabase=index.txt\ncrlnumber=crlnumber\ncertificate=ca.pem\nprivate_key=ca.key\ndefault_md=sha256\ndefault_crl_days=365\nnew_certs_dir=.\nunique_subject=no\n");
    $lines = '';
    foreach ($serials as $serial) {
        $hex = strtoupper($serial);
        $hex = strlen($hex) % 2 === 1 ? '0' . $hex : $hex;
        $lines .= "R\t" . gmdate('ymdHis', time() + 86400 * 30) . "Z\t" . gmdate('ymdHis') . "Z\t" . $hex . "\tunknown\t/CN=broker-device\n";
    }
    file_put_contents($directory . '/index.txt', $lines);
    file_put_contents($directory . '/crlnumber', "01\n");
    $process = new Process(['openssl', 'ca', '-gencrl', '-config', $configuration, '-out', $output], $directory);
    try {
        $result = $process->wait(10);
        expect($result->successful(), '无法生成测试 CRL：' . $result->stderr);
    } finally {
        $process->stop();
    }
    expect(is_file($output) && is_readable($output), '无法写出测试 CRL');
}

function brokerCertServeCrlHttps(): void
{
    $port = (int) getenv('BROKER_CRL_HTTPS_PORT');
    $file = (string) getenv('BROKER_CRL_HTTPS_FILE');
    $certificate = (string) getenv('BROKER_CRL_HTTPS_CERT');
    $privateKey = (string) getenv('BROKER_CRL_HTTPS_KEY');
    expect($port > 0 && is_file($file) && is_file($certificate) && is_file($privateKey), 'HTTPS CRL 源配置无效');
    $context = stream_context_create(['ssl' => [
        'local_cert' => $certificate,
        'local_pk' => $privateKey,
        'allow_self_signed' => true,
        'verify_peer' => false,
        'disable_compression' => true,
        'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER,
    ]]);
    $server = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
    expect(is_resource($server), '无法监听 HTTPS CRL：' . $errno . ' ' . $error);
    while (true) {
        $client = @stream_socket_accept($server, 1);
        if (!is_resource($client)) {
            continue;
        }
        stream_set_timeout($client, 3);
        if (@stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER) !== true) {
            fclose($client);
            continue;
        }
        $request = '';
        while (!str_contains($request, "\r\n\r\n")) {
            $chunk = @fread($client, 1024);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $request .= $chunk;
            if (strlen($request) > 8192) {
                break;
            }
        }
        $body = (string) file_get_contents($file);
        $wire = 'HTTP/1.0 200 OK' . "\r\n" . 'Content-Type: application/pkix-crl' . "\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n" . 'Connection: close' . "\r\n\r\n" . $body;
        @fwrite($client, $wire);
        fclose($client);
    }
}

function brokerCertCrlHttpsWait(int $port, string $ca): void
{
    $deadline = microtime(true) + 8;
    do {
        $socket = @stream_socket_client('tls://127.0.0.1:' . $port, $errno, $error, 1, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => [
            'cafile' => $ca, 'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => '127.0.0.1',
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ]]));
        if (is_resource($socket)) {
            fwrite($socket, "GET /crl.pem HTTP/1.0\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
            $body = stream_get_contents($socket);
            fclose($socket);
            if (is_string($body) && str_contains($body, 'BEGIN X509 CRL')) {
                return;
            }
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    expect(false, 'HTTPS CRL 源未就绪');
}

if (getenv('BROKER_CRL_HTTPS') === '1') {
    brokerCertServeCrlHttps();
    exit(0);
}

$root = dirname(__DIR__);
$target = $argv[1] ?? '--php';
$driver = $argv[2] ?? 'sqlite';
expect(in_array($driver, ['sqlite', 'mysql', 'pgsql'], true), '证书授权验收需要明确的受支持驱动');
if ($target === '--php') {
    $swoole = getenv('TYPE_SWOOLE_MODULE');
    $swoole = is_string($swoole) && $swoole !== '' ? $swoole : rtrim((string) ini_get('extension_dir'), '/') . '/swoole.so';
    expect(is_file($swoole), 'PHP 证书授权验收需要 TYPE_SWOOLE_MODULE 或 extension_dir 中的 swoole.so');
    $command = [PHP_BINARY, '-d', 'extension=' . $swoole, $root . '/bin/typeapp'];
} else {
    $command = nativeCommand($target);
}
$base = $root . '/build/broker-certificates-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700, true), '无法创建证书授权测试目录');
$noSource = in_array('--no-source', $argv, true);
$browserDist = '';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--browser-dist=')) {
        $browserDist = substr($argument, 15);
    }
}
if ($noSource) {
    expect($target !== '--php' && PHP_OS_FAMILY === 'Darwin', '无源码证书授权验收使用macOS内核策略及原生产物');
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
$environment = getenv();
foreach (array_keys($environment) as $key) {
    if (str_starts_with($key, 'APP_') || str_starts_with($key, 'DB_') || str_starts_with($key, 'IOT_') || str_starts_with($key, 'BROKER_')) {
        unset($environment[$key]);
    }
}
$environment['APP_BASE_PATH'] = $base;
$environment['DB_DRIVER'] = $driver;
$environment['DB_SQLITE_FILE'] = 'access.sqlite';
$environment['APP_CACHE_ENABLED'] = 'false';
$environment['APP_DEBUG'] = 'true';
if ($driver !== 'sqlite') {
    $prefix = 'TYPE_' . strtoupper($driver) . '_';
    foreach (['HOST' => 'HOST', 'PORT' => 'PORT', 'DATABASE' => 'DATABASE', 'USERNAME' => 'USER', 'PASSWORD' => 'PASSWORD'] as $destination => $source) {
        $value = getenv($prefix . $source);
        expect(is_string($value), '数据库验证必须由隔离实例提供参数：' . $source);
        $environment['DB_' . $destination] = $value;
    }
}
$checks = 0;
$report = ['status' => 'running', 'scope' => 'broker-certificates', 'native' => $target !== '--php', 'driver' => $driver,
    'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'no_source' => $noSource];
$server = null;
$node = null;
$appServer = null;
$browser = null;
$mqttTls = null;
$https = null;
$secrets = [];
$certs = brokerCertFiles($base . '/certs');
try {
    $install = new Process([...$command, 'broker:install'], $root, $environment);
    try {
        expect($install->wait(30)->successful(), '独立证书授权库安装失败：' . $install->stdout() . $install->stderr());
    } finally {
        $install->stop();
    }
    $password = bin2hex(random_bytes(16));
    $secrets[] = $password;
    identityCommand([...$command, 'broker:user', 'access-admin', '授权管理员'], $environment + ['BROKER_ADMIN_PASSWORD' => $password]);
    $addresses = [];
    for ($index = 0; $index < 5; $index++) {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorText);
        expect(is_resource($listener), '无法选择证书授权测试端口');
        $addresses[] = stream_socket_get_name($listener, false);
        fclose($listener);
    }
    $environment['APP_PORT'] = substr(strrchr($addresses[0], ':'), 1);
    $environment['APP_ALLOWED_HOSTS'] = $addresses[0];
    $server = new Process([...$command, 'broker:serve'], $root, $environment);
    $client = new HttpClient('http://' . $addresses[0], 8.0);
    $deadline = microtime(true) + 10;
    do {
        expect($server->running(), '独立证书授权管理提前退出：' . $server->stderr());
        try {
            if ($client->request('GET', '/readyz')->status === 200) {
                break;
            }
        } catch (RuntimeException) {
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    $request = static function (string $method, string $path, string $token, ?array $data = null, int $status = 200, array $headers = []) use ($client, $server, &$checks): array {
        $response = $client->request($method, $path, $headers + ($token === '' ? [] : ['Authorization' => 'Bearer ' . $token]) + ['Content-Type' => 'application/json'], $data === null ? '' : json_encode($data === [] ? (object) [] : $data, JSON_THROW_ON_ERROR));
        expect($response->status === $status, $method . ' ' . $path . ' 预期 ' . $status . '，实际 ' . $response->status . ' ' . $response->body
            . ($response->status >= 500 ? ' ' . $server->stdout() . $server->stderr() : ''));
        $checks++;
        return $response->json();
    };
    $login = $request('POST', '/broker/auth/login', '', ['login' => 'access-admin', 'password' => $password], 200);
    $token = $login['data']['accessToken'];
    $secrets[] = $token;
    $request('GET', '/broker/access/cas', '', null, 401);
    $request('GET', '/broker/access/cas', $token, null, 403, ['X-Tenant-Id' => bin2hex(random_bytes(16))]);
    $empty = $request('GET', '/broker/access/cas', $token, null, 200);
    expect($empty['items'] === [] && $empty['current_version'] === 0, '未启动节点前不应有受信 CA');
    $mqttPassword = bin2hex(random_bytes(16));
    $secrets[] = $mqttPassword;
    $nodeEnvironment = $environment + [
        'BROKER_CLIENT_USERNAME' => 'broker-client', 'BROKER_CLIENT_PASSWORD' => $mqttPassword,
        'BROKER_TOPIC_PREFIX' => 'broker-access/', 'BROKER_NODE_ID' => 'cert-node',
        'BROKER_PLAINTEXT' => 'false',
        'BROKER_PORT' => substr(strrchr($addresses[1], ':'), 1),
        'BROKER_MTLS_PORT' => substr(strrchr($addresses[3], ':'), 1),
        'BROKER_CERTIFICATE' => $certs['server'], 'BROKER_PRIVATE_KEY' => $certs['key'],
        'BROKER_CLIENT_CA' => $certs['ca'],
    ];
    $node = new Process([...$command, 'broker:run'], $root, $nodeEnvironment);
    brokerCertWait($node, (int) $nodeEnvironment['BROKER_PORT'], $certs['ca']);
    $deadline = microtime(true) + 12;
    $nodes = [];
    do {
        expect($node->running(), '证书节点提前退出：' . $node->stderr());
        $nodes = $request('GET', '/broker/nodes', $token, null, 200);
        if (($nodes['total'] ?? 0) === 1 && ($nodes['items'][0]['state'] ?? '') === 'reporting') {
            break;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    expect(($nodes['total'] ?? 0) === 1, '证书节点未上报');
    $listed = $request('GET', '/broker/access/principals', $token, null, 200);
    expect($listed['current_version'] === 1 && count($listed['items']) === 1, '引导主体未写入已生效版本1');
    $principal = $listed['items'][0];
    $request('POST', '/broker/access/principals/' . $principal['id'], $token, [
        'name' => $principal['name'], 'enabled' => 1, 'expected_version' => 1,
        'grants' => $principal['grants'], 'certificates' => [['pem' => $certs['clientPem']]],
    ], 422);
    $caPublished = $request('POST', '/broker/access/cas', $token, ['pem' => $certs['caPem'], 'expected_version' => 1], 200)['data'];
    expect($caPublished['tightening'] === false && $caPublished['version'] === 2, '登记 CA 应形成非收紧版本2');
    $deadline = microtime(true) + 12;
    do {
        $caPublished = $request('GET', '/broker/access/revisions/' . $caPublished['id'], $token, null, 200)['item'];
        if ($caPublished['status'] === 'effective') {
            break;
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    expect(($caPublished['status'] ?? '') === 'effective', '登记 CA 后节点必须上报已加载版本');
    $cas = $request('GET', '/broker/access/cas', $token, null, 200);
    expect(count($cas['items']) === 1 && $cas['items'][0]['fingerprint'] === brokerCertFingerprint($certs['caPem'])
        && !isset($cas['items'][0]['pem']), 'CA 列表应有指纹且不含 PEM');
    $request('POST', '/broker/access/cas', $token, ['pem' => $certs['clientPem'], 'expected_version' => 2], 422);
    $request('POST', '/broker/access/cas', $token, ['pem' => (string) file_get_contents($certs['clientKey']), 'expected_version' => 2], 422);
    $bound = $request('POST', '/broker/access/principals/' . $principal['id'], $token, [
        'name' => $principal['name'], 'enabled' => 1, 'expected_version' => 2, 'grants' => $principal['grants'],
        'certificates' => [['pem' => $certs['clientPem']]],
    ], 200)['data'];
    expect($bound['tightening'] === false && $bound['version'] === 3, '绑定证书应形成非收紧版本3');
    $deadline = microtime(true) + 12;
    do {
        $bound = $request('GET', '/broker/access/revisions/' . $bound['id'], $token, null, 200)['item'];
        if ($bound['status'] === 'effective') {
            break;
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    expect(($bound['status'] ?? '') === 'effective', '绑定证书后节点必须上报已加载版本');
    $detail = $request('GET', '/broker/access/principals/' . $principal['id'], $token, null, 200)['item'];
    expect(count($detail['certificates']) === 1 && $detail['certificates'][0]['fingerprint'] === $certs['fingerprint']
        && $detail['certificates'][0]['status'] === 'active' && (int) $detail['certificates'][0]['overlap_until'] === 0
        && !isset($detail['certificates'][0]['pem']), '主体应投影活动证书指纹');
    $request('POST', '/broker/access/principals/' . $principal['id'] . '/certificate-preview', $token, [
        'pem' => $certs['extraPem'], 'overlap_seconds' => 86401,
    ], 422);
    $previewDefault = $request('POST', '/broker/access/principals/' . $principal['id'] . '/certificate-preview', $token, [
        'pem' => $certs['extraPem'],
    ], 200);
    expect($previewDefault['overlap_seconds'] === 86400 && $previewDefault['tightening'] === false
        && $previewDefault['incoming']['fingerprint'] === $certs['extraFingerprint']
        && count($previewDefault['retiring']) === 1 && $previewDefault['retiring'][0]['action'] === 'overlap'
        && $previewDefault['retiring'][0]['fingerprint'] === $certs['fingerprint']
        && !isset($previewDefault['incoming']['pem']), '缺省重叠应为 24 小时且不含 PEM');
    $previewZero = $request('POST', '/broker/access/principals/' . $principal['id'] . '/certificate-preview', $token, [
        'pem' => $certs['extraPem'], 'overlap_seconds' => 0,
    ], 200);
    expect($previewZero['tightening'] === true && $previewZero['retiring'][0]['action'] === 'revoke', '重叠为零应立即吊销旧证');
    $previewShort = $request('POST', '/broker/access/principals/' . $principal['id'] . '/certificate-preview', $token, [
        'pem' => $certs['extraPem'], 'overlap_seconds' => 2,
    ], 200);
    expect($previewShort['overlap_seconds'] === 2 && $previewShort['tightening'] === false
        && $previewShort['overlap_until'] > time(), '短重叠预览应给出未来截止时间');
    $tlsPort = (int) $nodeEnvironment['BROKER_PORT'];
    $mtlsPort = (int) $nodeEnvironment['BROKER_MTLS_PORT'];
    $mqttTls = new Client('127.0.0.1', $tlsPort, 'access-tls', 'broker-client', $mqttPassword, $certs['ca'], '127.0.0.1', sessionExpiry: 0);
    expect(!$mqttTls->connect(true), '凭据 TLS 入口连接失败');
    expect($mqttTls->subscribe('broker-access/tls', 0) === 0, '凭据 TLS 入口仍应可用');
    $only = brokerCertSocket($mtlsPort, $certs['ca'], $certs['client'], $certs['clientKey']);
    brokerCertWrite($only, brokerCertConnect('access-cert'));
    $onlyAck = brokerCertRead($only);
    expect(ord($onlyAck[0]) === 0x20 && ord($onlyAck[3]) === 0, '登记证书单独认证失败：' . bin2hex($onlyAck));
    brokerCertWrite($only, brokerCertSubscribe('broker-access/cert'));
    $onlySub = brokerCertRead($only);
    expect(ord($onlySub[0]) === 0x90, '证书身份未获得 Topic 授权');
    $unregistered = brokerCertSocket($mtlsPort, $certs['ca'], $certs['extra'], $certs['extraKey']);
    brokerCertWrite($unregistered, brokerCertConnect('access-unregistered'));
    $unregisteredAck = brokerCertRead($unregistered);
    expect(ord($unregisteredAck[0]) === 0x20 && ord($unregisteredAck[3]) === 0x87, '未登记同 CA 证书应返回 0x87');
    fclose($unregistered);
    $unknownFailed = false;
    try {
        $unknown = brokerCertSocket($mtlsPort, $certs['ca'], $certs['rogue'], $certs['rogueKey']);
        brokerCertWrite($unknown, brokerCertConnect('access-rogue'));
        $unknownAck = brokerCertRead($unknown);
        $unknownFailed = !(ord($unknownAck[0]) === 0x20 && ord($unknownAck[3]) === 0);
        fclose($unknown);
    } catch (RuntimeException) {
        $unknownFailed = true;
    }
    expect($unknownFailed, '未知 CA 签发的证书不能形成 MQTT 会话');
    $both = brokerCertSocket($mtlsPort, $certs['ca'], $certs['client'], $certs['clientKey']);
    brokerCertWrite($both, brokerCertConnect('access-both', 'broker-client', $mqttPassword));
    $bothAck = brokerCertRead($both);
    expect(ord($bothAck[0]) === 0x20 && ord($bothAck[3]) === 0, '同主体双重身份认证失败');
    fclose($both);
    $mismatch = brokerCertSocket($mtlsPort, $certs['ca'], $certs['client'], $certs['clientKey']);
    brokerCertWrite($mismatch, brokerCertConnect('access-mismatch', 'broker-client', 'wrong-password-12'));
    $mismatchAck = brokerCertRead($mismatch);
    expect(ord($mismatchAck[0]) === 0x20 && ord($mismatchAck[3]) === 0x87, '错误密码不应降级为仅证书');
    fclose($mismatch);
    $rotated = $request('POST', '/broker/access/principals/' . $principal['id'], $token, [
        'name' => $principal['name'], 'enabled' => 1, 'expected_version' => 3, 'grants' => $principal['grants'],
        'certificates' => [['pem' => $certs['extraPem'], 'overlap_seconds' => 12]],
    ], 200)['data'];
    expect($rotated['tightening'] === false && $rotated['version'] === 4 && $rotated['operation_id'] === $rotated['id']
        && in_array($rotated['stage'], ['accepted', 'executing', 'completed'], true), '换证重叠必须形成非收紧版本4并给出操作身份');
    $rotatedDetail = $request('GET', '/broker/access/principals/' . $principal['id'], $token, null, 200)['item'];
    $oldCert = null;
    $newCert = null;
    foreach ($rotatedDetail['certificates'] as $item) {
        if ($item['fingerprint'] === $certs['fingerprint']) {
            $oldCert = $item;
        }
        if ($item['fingerprint'] === $certs['extraFingerprint']) {
            $newCert = $item;
        }
    }
    expect($oldCert !== null && $newCert !== null && $oldCert['status'] === 'active' && $newCert['status'] === 'active'
        && (int) $oldCert['overlap_until'] > time() && (int) $newCert['overlap_until'] === 0, '重叠期内新旧证书都应保持活动');
    $fresh = brokerCertSocket($mtlsPort, $certs['ca'], $certs['extra'], $certs['extraKey']);
    brokerCertWrite($fresh, brokerCertConnect('access-extra'));
    $freshAck = brokerCertRead($fresh);
    expect(ord($freshAck[0]) === 0x20 && ord($freshAck[3]) === 0, '新证书应能在重叠期内单独认证');
    brokerCertWrite($only, brokerCertSubscribe('broker-access/overlap'));
    $onlyOverlap = brokerCertRead($only);
    expect(ord($onlyOverlap[0]) === 0x90, '旧证书在重叠期内应仍可收发');
    $remain = (int) $oldCert['overlap_until'] - time();
    if ($remain > 0) {
        usleep(($remain + 1) * 1000000);
    }
    $expired = brokerCertSocket($mtlsPort, $certs['ca'], $certs['client'], $certs['clientKey']);
    brokerCertWrite($expired, brokerCertConnect('access-overlap-expired'));
    $expiredAck = brokerCertRead($expired);
    expect(ord($expiredAck[0]) === 0x20 && ord($expiredAck[3]) === 0x87, '重叠结束后旧证书不能再次单独认证');
    fclose($expired);
    brokerCertDisconnected($only, 'broker-access/overlap-closed', 6.5, '重叠结束后健康节点未断开旧证书会话');
    try {
        fclose($only);
    } catch (Throwable) {
    }
    brokerCertWrite($fresh, brokerCertSubscribe('broker-access/extra'));
    $freshSub = brokerCertRead($fresh);
    expect(ord($freshSub[0]) === 0x90, '迟到的旧证清理不得断开新证书会话');
    $rotated = brokerCertWaitRevision($request, $token, $rotated, '换证后节点必须上报已加载版本');
    expect($rotated['stage'] === 'completed', '换证重叠生效后操作阶段应为已完成');
    $revoked = $request('POST', '/broker/access/principals/' . $principal['id'], $token, [
        'name' => $principal['name'], 'enabled' => 1, 'expected_version' => 4, 'grants' => $principal['grants'],
        'certificates' => [['fingerprint' => $certs['extraFingerprint'], 'revoke' => 1]], 'client_id' => 'access-extra',
    ], 200)['data'];
    expect($revoked['tightening'] === true && $revoked['version'] === 5 && $revoked['operation_id'] === $revoked['id'], '吊销证书必须记为收紧并形成版本5');
    brokerCertDisconnected($fresh, 'broker-access/revoked', 5.2, '吊销后健康节点未断开新证书会话');
    try {
        fclose($fresh);
    } catch (Throwable) {
    }
    $revoked = brokerCertWaitRevision($request, $token, $revoked, '吊销后节点必须上报已加载版本');
    $after = brokerCertSocket($mtlsPort, $certs['ca'], $certs['extra'], $certs['extraKey']);
    brokerCertWrite($after, brokerCertConnect('access-revoked'));
    $afterAck = brokerCertRead($after);
    expect(ord($afterAck[0]) === 0x20 && ord($afterAck[3]) === 0x87, '已吊销证书不能再次单独认证');
    fclose($after);
    $boundListed = $request('POST', '/broker/access/principals/' . $principal['id'], $token, [
        'name' => $principal['name'], 'enabled' => 1, 'expected_version' => 5, 'grants' => $principal['grants'],
        'certificates' => [['pem' => $certs['listedPem']]],
    ], 200)['data'];
    expect($boundListed['tightening'] === false && $boundListed['version'] === 6, '登记另一张证书应形成非收紧版本6');
    $boundListed = brokerCertWaitRevision($request, $token, $boundListed, '登记列入测试证书后节点必须上报已加载版本');
    $listedLive = brokerCertSocket($mtlsPort, $certs['ca'], $certs['listed'], $certs['listedKey']);
    brokerCertWrite($listedLive, brokerCertConnect('access-listed'));
    $listedAck = brokerCertRead($listedLive);
    expect(ord($listedAck[0]) === 0x20 && ord($listedAck[3]) === 0, '未配置 CRL 时列入测试证书应能单独认证');
    $cas = $request('GET', '/broker/access/cas', $token, null, 200);
    $caId = $cas['items'][0]['id'];
    expect(($cas['items'][0]['crl']['state'] ?? '') === 'none' && !isset($cas['items'][0]['pem']), '未配置 CRL 时状态应为 none 且不含 PEM');
    $httpsPort = (int) substr(strrchr($addresses[4], ':'), 1);
    $request('POST', '/broker/access/cas/' . $caId . '/crl', $token, [
        'url' => 'http://127.0.0.1:' . $httpsPort . '/crl.pem', 'expected_version' => 6,
    ], 422);
    $missing = $request('POST', '/broker/access/cas/' . $caId . '/crl', $token, [
        'url' => 'https://127.0.0.1:' . $httpsPort . '/crl.pem', 'fetch_interval' => 1, 'expected_version' => 6,
    ], 200)['data'];
    expect($missing['tightening'] === true && $missing['version'] === 7, '配置 CRL 但尚未接纳有效列表必须收紧');
    brokerCertDisconnected($listedLive, 'broker-access/crl-missing', 5.2, 'CRL 缺失后健康节点未断开该 CA 会话');
    try {
        fclose($listedLive);
    } catch (Throwable) {
    }
    $missing = brokerCertWaitRevision($request, $token, $missing, 'CRL 缺失发布后节点必须上报已加载版本');
    $missingAgain = brokerCertSocket($mtlsPort, $certs['ca'], $certs['listed'], $certs['listedKey']);
    brokerCertWrite($missingAgain, brokerCertConnect('access-crl-missing'));
    $missingAck = brokerCertRead($missingAgain);
    expect(ord($missingAck[0]) === 0x20 && ord($missingAck[3]) === 0x87, 'CRL 缺失后该 CA 新连接不能单独认证');
    fclose($missingAgain);
    $cas = $request('GET', '/broker/access/cas', $token, null, 200);
    expect(($cas['items'][0]['crl']['state'] ?? '') === 'missing' && ($cas['items'][0]['crl']['recovery'] ?? '') !== ''
        && ($cas['items'][0]['crl']['fetch_status'] ?? '') !== '', '缺失时应展示获取状态、执行状态和恢复条件');
    brokerCertCrl($base . '/certs', $certs['crl'], ['04']);
    $restoredCrl = (string) file_get_contents($certs['crl']);
    $restored = $request('POST', '/broker/access/cas/' . $caId . '/crl', $token, [
        'pem' => $restoredCrl, 'expected_version' => 7,
    ], 200)['data'];
    expect($restored['version'] === 8, '导入有效空名单 CRL 应形成版本8');
    $restored = brokerCertWaitRevision($request, $token, $restored, '导入有效 CRL 后节点必须上报已加载版本');
    $listedRestored = brokerCertSocket($mtlsPort, $certs['ca'], $certs['listed'], $certs['listedKey']);
    brokerCertWrite($listedRestored, brokerCertConnect('access-crl-restored'));
    $listedRestoredAck = brokerCertRead($listedRestored);
    expect(ord($listedRestoredAck[0]) === 0x20 && ord($listedRestoredAck[3]) === 0, '有效 CRL 未列入的证书应能恢复接入');
    sleep(1);
    brokerCertCrl($base . '/certs', $certs['crl'], ['04', $certs['listedSerial']]);
    $https = new Process([PHP_BINARY, __FILE__], $root, array_replace(getenv(), [
        'BROKER_CRL_HTTPS' => '1',
        'BROKER_CRL_HTTPS_PORT' => (string) $httpsPort,
        'BROKER_CRL_HTTPS_FILE' => $certs['crl'],
        'BROKER_CRL_HTTPS_CERT' => $certs['server'],
        'BROKER_CRL_HTTPS_KEY' => $certs['key'],
    ]));
    brokerCertCrlHttpsWait($httpsPort, $certs['ca']);
    $httpsDeadline = microtime(true) + 20;
    $httpsCas = [];
    do {
        $httpsCas = $request('GET', '/broker/access/cas', $token, null, 200);
        if (($httpsCas['items'][0]['crl']['fetch_status'] ?? '') === 'success'
            && (int) ($httpsCas['items'][0]['crl']['serial_count'] ?? 0) >= 2) {
            break;
        }
        usleep(200000);
    } while (microtime(true) < $httpsDeadline);
    expect(($httpsCas['items'][0]['crl']['fetch_status'] ?? '') === 'success'
        && (int) ($httpsCas['items'][0]['crl']['serial_count'] ?? 0) >= 2
        && !isset($httpsCas['items'][0]['pem']), 'HTTPS 接纳后应更新获取状态且序列号只增不减');
    brokerCertDisconnected($listedRestored, 'broker-access/crl-https', 5.2, 'HTTPS 刷新列入后健康节点未断开会话');
    try {
        fclose($listedRestored);
    } catch (Throwable) {
    }
    $httpsBlocked = brokerCertSocket($mtlsPort, $certs['ca'], $certs['listed'], $certs['listedKey']);
    brokerCertWrite($httpsBlocked, brokerCertConnect('access-crl-https'));
    $httpsBlockedAck = brokerCertRead($httpsBlocked);
    expect(ord($httpsBlockedAck[0]) === 0x20 && ord($httpsBlockedAck[3]) === 0x87, 'HTTPS 接纳列入后不能再次单独认证');
    fclose($httpsBlocked);
    $request('POST', '/broker/access/cas/' . $caId . '/crl', $token, [
        'pem' => $restoredCrl, 'expected_version' => 8,
    ], 422);
    $request('POST', '/broker/access/cas/' . $caId . '/crl', $token, [
        'pem' => (string) file_get_contents($certs['rogue']), 'expected_version' => 8,
    ], 422);
    $platform = $request('POST', '/broker/access/revocations', $token, [
        'serial' => $certs['extraSerial'], 'expected_version' => 8,
    ], 200)['data'];
    expect($platform['tightening'] === true && $platform['version'] === 9, '平台直接吊销必须独立收紧且不等待 CA CRL');
    $platform = brokerCertWaitRevision($request, $token, $platform, '平台吊销后节点必须上报已加载版本');
    $revocations = $request('GET', '/broker/access/revocations', $token, null, 200);
    expect($revocations['items'] !== [] && $revocations['items'][0]['serial'] === $certs['extraSerial'], '平台吊销序列号应可查询');
    $disabled = $request('POST', '/broker/access/principals/' . $principal['id'], $token, [
        'name' => $principal['name'], 'enabled' => 0, 'expected_version' => 9, 'grants' => $principal['grants'],
        'client_id' => 'access-tls',
    ], 200)['data'];
    expect($disabled['tightening'] === true && $disabled['version'] === 10, '停用主体必须立即收紧且无证书重叠');
    $tlsGone = false;
    $deadline = microtime(true) + 5.2;
    do {
        try {
            $mqttTls->subscribe('broker-access/disabled', 0, 0, 0.4);
        } catch (Throwable) {
            $tlsGone = true;
            break;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    expect($tlsGone, '停用主体后健康节点未断开凭据会话');
    $audit = $request('GET', '/broker/audit?action=broker.access.publish&result=success', $token, null, 200);
    expect($audit['items'] !== [] && in_array($audit['items'][0]['details']['reason'], ['ca_published', 'access_published', 'crl_published', 'platform_serial_published'], true), '证书发布必须留下脱敏审计');
    foreach ($audit['items'] as $item) {
        $encoded = json_encode($item, JSON_THROW_ON_ERROR);
        expect(!str_contains($encoded, 'PRIVATE KEY') && !str_contains($encoded, $mqttPassword), '审计不得包含私钥或接入密码');
    }
    try {
        $mqttTls->stop();
    } catch (Throwable) {
    }
    $mqttTls = null;
    expect($node->stop(5)->successful(), '证书节点未正常排空');
    $node = null;
    $appBase = $base . '/app';
    expect(mkdir($appBase, 0700), '无法创建双端证书授权测试目录');
    $appEnvironment = $environment;
    $appEnvironment['APP_BASE_PATH'] = $appBase;
    $appEnvironment['DB_SQLITE_FILE'] = 'app.sqlite';
    $appEnvironment['APP_PORT'] = substr(strrchr($addresses[2], ':'), 1);
    $appEnvironment['APP_ALLOWED_HOSTS'] = $addresses[2];
    unset($appEnvironment['BROKER_CLIENT_USERNAME'], $appEnvironment['BROKER_CLIENT_PASSWORD'], $appEnvironment['BROKER_TOPIC_PREFIX'], $appEnvironment['BROKER_NODE_ID'], $appEnvironment['BROKER_PLAINTEXT'], $appEnvironment['BROKER_PORT'], $appEnvironment['BROKER_MTLS_PORT'], $appEnvironment['BROKER_CERTIFICATE'], $appEnvironment['BROKER_PRIVATE_KEY'], $appEnvironment['BROKER_CLIENT_CA']);
    $appPassword = bin2hex(random_bytes(16));
    $secrets[] = $appPassword;
    $appInstall = new Process([...$command, 'app:install', 'platform-admin', '平台管理员', 'customer-admin', '客户管理员', '授权租户'], $root, $appEnvironment + [
        'APP_ADMIN_PASSWORD' => $appPassword, 'APP_CUSTOMER_PASSWORD' => $appPassword . '-customer',
    ]);
    try {
        expect($appInstall->wait(45)->successful(), '双端证书授权安装失败：' . $appInstall->stderr());
    } finally {
        $appInstall->stop();
    }
    $appServer = new Process([...$command, 'serve'], $root, $appEnvironment);
    $appClient = new HttpClient('http://' . $addresses[2], 8.0);
    $deadline = microtime(true) + 15;
    do {
        expect($appServer->running(), '双端证书授权HTTP提前退出：' . $appServer->stderr());
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
    $created = $appRequest('POST', '/customer/tenants/' . $tenantId . '/broker/access/principals', $customerToken, $tenantHeaders, [
        'name' => '租户主体', 'login' => 'tenant-client', 'password' => bin2hex(random_bytes(16)),
        'enabled' => 1, 'expected_version' => 0, 'ca' => $certs['caPem'],
        'grants' => [['topic' => 'iot/' . $tenantId . '/', 'publish' => 1, 'subscribe' => 1, 'max_qos' => 0]],
        'certificates' => [['pem' => $certs['clientPem']]],
    ], 200)['data'];
    expect($created['actor_realm'] === 'customer' && $created['version'] === 1, '租户可在同一版本登记 CA 并绑定证书');
    $tenantCas = $appRequest('GET', '/customer/tenants/' . $tenantId . '/broker/access/cas', $customerToken, $tenantHeaders, null, 200);
    expect(count($tenantCas['items']) === 1, '租户受信 CA 应可查询');
    $tenantPrincipals = $appRequest('GET', '/customer/tenants/' . $tenantId . '/broker/access/principals', $customerToken, $tenantHeaders, null, 200);
    expect(count($tenantPrincipals['items'][0]['certificates'] ?? []) === 1, '租户主体应投影已绑定证书');
    $tenantPreview = $appRequest('POST', '/customer/tenants/' . $tenantId . '/broker/access/principals/' . $tenantPrincipals['items'][0]['id'] . '/certificate-preview', $customerToken, $tenantHeaders, [
        'pem' => $certs['extraPem'], 'overlap_seconds' => 3600,
    ], 200);
    expect($tenantPreview['overlap_seconds'] === 3600 && $tenantPreview['incoming']['fingerprint'] === $certs['extraFingerprint']
        && $tenantPreview['retiring'][0]['action'] === 'overlap', '租户应能预览换证重叠');
    $tenantCaId = $tenantCas['items'][0]['id'];
    brokerCertCrl($base . '/certs', $base . '/certs/tenant-crl.pem', ['04']);
    $tenantCrl = $appRequest('POST', '/customer/tenants/' . $tenantId . '/broker/access/cas/' . $tenantCaId . '/crl', $customerToken, $tenantHeaders, [
        'pem' => (string) file_get_contents($base . '/certs/tenant-crl.pem'), 'expected_version' => $created['version'],
    ], 200)['data'];
    expect($tenantCrl['actor_realm'] === 'customer' && $tenantCrl['tightening'] === true, '租户应能导入签名 CRL');
    $tenantCas = $appRequest('GET', '/customer/tenants/' . $tenantId . '/broker/access/cas', $customerToken, $tenantHeaders, null, 200);
    expect(($tenantCas['items'][0]['crl']['configured'] ?? false) === true && ($tenantCas['items'][0]['crl']['serial_count'] ?? 0) >= 1
        && !isset($tenantCas['items'][0]['pem']), '租户 CRL 应投影序列号数量且不含 PEM');
    $appRequest('POST', '/customer/tenants/' . $tenantId . '/broker/access/cas', $customerToken, $tenantHeaders, [
        'pem' => $certs['caPem'], 'expected_version' => $tenantCrl['version'],
    ], 409);
    $appRequest('GET', '/admin/broker/access/cas', $platformToken, [], null, 200);
    $report['http_checks'] = $checks;
    $report['revision'] = ['id' => $revoked['id'] ?? '', 'status' => $revoked['status'] ?? '', 'version' => $revoked['version'] ?? 0];
    $report['status'] = 'passed';
    if ($browserDist !== '') {
        expect(is_file($browserDist . '/index.html'), '浏览器验收需要本次隔离前端产物');
        $browser = new Process(['node', $root . '/tests/broker-certificates-browser.mjs', $base, $browserDist, 'http://' . $addresses[0], 'http://' . $addresses[2]], $root, array_replace(getenv(), [
            'BROKER_BROWSER_PASSWORD' => $password,
            'APP_BROWSER_PASSWORD' => $appPassword,
            'APP_BROWSER_CUSTOMER_PASSWORD' => $appPassword . '-customer',
            'APP_BROWSER_TENANT' => $tenantId,
            'BROKER_BROWSER_CA_PEM' => $certs['caPem'],
            'BROKER_BROWSER_CLIENT_PEM' => $certs['clientPem'],
            'BROKER_BROWSER_EXTRA_PEM' => $certs['extraPem'],
        ]));
        $browserResult = $browser->wait(180);
        file_put_contents($base . '/browser.log', str_replace($secrets, '<REDACTED>', $browserResult->stdout . $browserResult->stderr));
        expect($browserResult->successful(), '证书授权浏览器失败，见browser-certificates-report.json');
        $report['browser'] = json_decode(file_get_contents($base . '/browser-certificates-report.json'), true, 32, JSON_THROW_ON_ERROR);
        expect(($report['browser']['status'] ?? '') === 'passed', '证书授权浏览器报告未通过');
    }
} finally {
    try {
        $mqttTls?->stop();
    } catch (Throwable) {
    }
    foreach (['browser' => $browser, 'https' => $https, 'node' => $node, 'app' => $appServer, 'http' => $server] as $role => $process) {
        try {
            $process?->stop(5.0);
            $report['cleanup'][$role] = $process === null || !$process->running();
        } catch (Throwable $failure) {
            $report['cleanup'][$role] = false;
            $report['cleanup_errors'][] = $role . ':' . $failure->getMessage();
        }
    }
    if (($report['status'] ?? '') !== 'passed') {
        $report['status'] = 'failed';
    }
    $report['http_checks'] = $checks;
    file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
expect(($report['status'] ?? '') === 'passed', '证书授权验收未通过，见 ' . $base . '/verification.json');
echo 'Broker 证书授权通过：' . $base . "/verification.json\n";
exit(0);
