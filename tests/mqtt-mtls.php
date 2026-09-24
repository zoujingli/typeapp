<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Testing\Process;
use Type\Mqtt\CertificateRevocationList;

/**
 * 专用 mTLS 入口：Swoole 校验客户端证书链，Broker 再核用途、有效期和签名 CRL。
 * 已连接会话在 CRL 更新列入、HTTPS 刷新列入、CRL 文件缺失、平台吊销名单列入、换证重叠结束或叶证书到期后断开。普通 TLS 端口仍只接受 CONNECT 凭据。
 * `--native` 另做独立消费者全量 AOT。不覆盖管理端登记、换证页面或集群 5 秒证明。
 */

function mqttMtlsField(string $value): string
{
    return pack('n', strlen($value)) . $value;
}

/** 独立编码 MQTT 可变长度整数，用于 mTLS 测试报文，输入须在协议范围内。 */
function mqttMtlsLength(int $value): string
{
    $bytes = '';
    do {
        $byte = $value % 128;
        $value = intdiv($value, 128);
        $bytes .= chr($value > 0 ? $byte | 128 : $byte);
    } while ($value > 0);
    return $bytes;
}

/** 构造 MQTT 5 CONNECT，按开关携带测试用户名及密码；keepAlive 单位为秒。 */
function mqttMtlsConnect(string $id, bool $credentials, string $password = 'mqtt-test-secret', int $keepAlive = 10): string
{
    $flags = $credentials ? "\xc2" : "\x02";
    $payload = mqttMtlsField('MQTT') . "\x05" . $flags . pack('n', $keepAlive) . "\x00" . mqttMtlsField($id);
    if ($credentials) {
        $payload .= mqttMtlsField('example') . mqttMtlsField($password);
    }
    return "\x10" . mqttMtlsLength(strlen($payload)) . $payload;
}

/** 构造 MQTT 5 QoS 0 订阅，使用固定测试报文标识符 1。 */
function mqttMtlsSubscribe(string $topic): string
{
    $body = "\x00\x01\x00" . mqttMtlsField($topic) . "\x00";
    return "\x82" . mqttMtlsLength(strlen($body)) . $body;
}

/** 构造无属性的 MQTT 5 QoS 0 发布报文。 */
function mqttMtlsPublish(string $topic, string $payload): string
{
    $body = mqttMtlsField($topic) . "\0" . $payload;
    return "\x30" . mqttMtlsLength(strlen($body)) . $body;
}

/** 在指定 IPv4 或 IPv6 地址获取空闲端口后关闭监听；返回值不预留端口占用。 */
function mqttMtlsPort(string $host = '127.0.0.1'): int
{
    $address = str_contains($host, ':') ? '[' . $host . ']' : $host;
    $listener = stream_socket_server('tcp://' . $address . ':0', $errno, $error);
    expect(is_resource($listener), '无法分配临时端口：' . $host . ' ' . $error);
    $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
    fclose($listener);
    return $port;
}

/** @return list<string> */
function mqttMtlsPhp(): array
{
    $command = [PHP_BINARY, ...mqttMtlsExtensionArgs(['mysqlnd', 'pdo_pgsql', 'swoole'], true)];
    expect(successful([...$command, '-r', 'echo extension_loaded("swoole") ? "yes" : "no";']) === 'yes', 'mTLS MQTT 测试需要匹配 SDK 的 Swoole 模块');
    return $command;
}

/**
 * @param list<string> $extensions
 * @return list<string>
 */
function mqttMtlsExtensionArgs(array $extensions, bool $forChild = false): array
{
    $arguments = [];
    $directory = (string) ini_get('extension_dir');
    // 子进程继承 INI，但不继承父进程命令行的 -d 扩展配置。
    $loaded = $forChild
        ? json_decode(successful([PHP_BINARY, '-r', 'echo json_encode(get_loaded_extensions(), JSON_THROW_ON_ERROR);']), true, 32, JSON_THROW_ON_ERROR)
        : get_loaded_extensions();
    foreach ($extensions as $extension) {
        if (in_array($extension, $loaded, true)) {
            continue;
        }
        $module = $directory . '/' . $extension . '.so';
        if ($extension === 'swoole') {
            $module = (string) (getenv('TYPE_SWOOLE_MODULE') ?: $module);
        }
        if (!is_file($module)) {
            continue;
        }
        $arguments[] = '-d';
        $arguments[] = 'extension=' . $module;
        if ($extension === 'swoole') {
            $arguments[] = '-d';
            $arguments[] = 'swoole.enable_library=On';
        }
    }
    return $arguments;
}

/** 需要时以明确扩展参数重启 mTLS 测试一次，环境哨兵防循环并透传退出码。 */
function mqttMtlsReexec(): void
{
    if (getenv('MQTT_MTLS_REEXEC') === '1') {
        return;
    }
    $arguments = mqttMtlsExtensionArgs(['mysqlnd', 'pdo_pgsql', 'swoole']);
    if ($arguments === []) {
        return;
    }
    $command = [PHP_BINARY, ...$arguments, __FILE__, ...array_slice($GLOBALS['argv'], 1)];
    $environment = getenv();
    expect(is_array($environment), '无法读取测试进程环境');
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, null, array_replace($environment, ['MQTT_MTLS_REEXEC' => '1']));
    expect(is_resource($process), '无法以运行扩展重启 MQTT mTLS 测试');
    exit(proc_close($process));
}

/** @return array{ca:string,server:string,leaf:string,expiredServer:string,wrongServer:string,futureServer:string,sni:string,expiredSni:string,sniKey:string,key:string,keyEnc:string,client:string,clientKey:string,fingerprint:string,rogue:string,rogueKey:string,expired:string,future:string,wrong:string,revoked:string,short:string,platform:string,listed:string,rotated:string,chained:string,extra:string,bundle:string,revoke:string,overlap:string,crl:string} */
function mqttMtlsCertificates(string $directory): array
{
    $configuration = $directory . '/openssl.cnf';
    file_put_contents($configuration, "[req]\ndistinguished_name=dn\nx509_extensions=ext\n[dn]\n[ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n[intermediate]\nbasicConstraints=critical,CA:TRUE,pathlen:0\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n[server]\nsubjectAltName=IP:127.0.0.1,IP:::1\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n[sni]\nsubjectAltName=DNS:mqtt.sni.test\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n[client]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=clientAuth\n");
    $options = ['config' => $configuration, 'private_key_bits' => 2048, 'digest_alg' => 'sha256', 'x509_extensions' => 'ca'];
    $caKey = openssl_pkey_new($options);
    $caRequest = openssl_csr_new(['commonName' => 'type-mqtt-test-ca'], $caKey, $options);
    $ca = openssl_csr_sign($caRequest, null, $caKey, 1, $options);
    expect($caKey !== false && $caRequest !== false && $ca !== false, '无法生成测试 CA');
    $clientOptions = $options;
    $clientOptions['x509_extensions'] = 'client';
    $clientKey = openssl_pkey_new($clientOptions);
    $clientRequest = openssl_csr_new(['commonName' => 'example-device'], $clientKey, $clientOptions);
    $client = openssl_csr_sign($clientRequest, $ca, $caKey, 1, $clientOptions);
    expect($clientKey !== false && $clientRequest !== false && $client !== false, '无法生成客户端证书');
    $intOptions = $options;
    $intOptions['x509_extensions'] = 'intermediate';
    $intKey = openssl_pkey_new($intOptions);
    $intRequest = openssl_csr_new(['commonName' => 'type-mqtt-test-int'], $intKey, $intOptions);
    $int = openssl_csr_sign($intRequest, $ca, $caKey, 1, $intOptions);
    expect($intKey !== false && $intRequest !== false && $int !== false, '无法生成中间 CA');
    $serverOptions = $options;
    $serverOptions['x509_extensions'] = 'server';
    $serverKey = openssl_pkey_new($serverOptions);
    $serverRequest = openssl_csr_new(['commonName' => '127.0.0.1'], $serverKey, $serverOptions);
    $server = openssl_csr_sign($serverRequest, $int, $intKey, 1, $serverOptions);
    expect($serverKey !== false && $serverRequest !== false && $server !== false, '无法生成服务端证书');
    $extraIntKey = openssl_pkey_new($intOptions);
    $extraIntRequest = openssl_csr_new(['commonName' => 'type-mqtt-test-int-2'], $extraIntKey, $intOptions);
    $extraInt = openssl_csr_sign($extraIntRequest, $ca, $caKey, 1, $intOptions);
    expect($extraIntKey !== false && $extraIntRequest !== false && $extraInt !== false, '无法生成未登记中间 CA');
    $rogueKey = openssl_pkey_new($options);
    $rogueRequest = openssl_csr_new(['commonName' => 'rogue-ca'], $rogueKey, $options);
    $rogueCa = openssl_csr_sign($rogueRequest, null, $rogueKey, 1, $options);
    $rogueClientRequest = openssl_csr_new(['commonName' => 'rogue-device'], $rogueKey, $clientOptions);
    $rogue = openssl_csr_sign($rogueClientRequest, $rogueCa, $rogueKey, 1, $clientOptions);
    expect($rogueKey !== false && $rogue !== false, '无法生成未登记客户端证书');
    $wrong = openssl_csr_sign($clientRequest, $ca, $caKey, 1, $serverOptions);
    expect($wrong !== false, '无法生成错误用途客户端证书');
    $fingerprint = openssl_x509_fingerprint($client, 'sha256', false);
    expect(is_string($fingerprint), '无法计算客户端证书指纹');
    $paths = [
        'ca' => $directory . '/ca.pem',
        'server' => $directory . '/server.pem',
        'leaf' => $directory . '/server-leaf.pem',
        'expiredServer' => $directory . '/expired-server.pem',
        'wrongServer' => $directory . '/wrong-server.pem',
        'futureServer' => $directory . '/future-server.pem',
        'sni' => $directory . '/sni.pem',
        'expiredSni' => $directory . '/expired-sni.pem',
        'sniKey' => $directory . '/sni.key',
        'key' => $directory . '/server.key',
        'keyEnc' => $directory . '/server.enc.key',
        'client' => $directory . '/client.pem',
        'clientKey' => $directory . '/client.key',
        'rogue' => $directory . '/rogue.pem',
        'rogueKey' => $directory . '/rogue.key',
        'expired' => $directory . '/expired.pem',
        'future' => $directory . '/future.pem',
        'wrong' => $directory . '/wrong.pem',
        'revoked' => $directory . '/revoked.pem',
        'short' => $directory . '/short.pem',
        'platform' => $directory . '/platform.pem',
        'listed' => $directory . '/listed.pem',
        'rotated' => $directory . '/rotated.pem',
        'chained' => $directory . '/chained.pem',
        'extra' => $directory . '/extra.pem',
        'bundle' => $directory . '/bundle.pem',
        'revoke' => $directory . '/revoke.txt',
        'overlap' => $directory . '/overlap.txt',
        'crl' => $directory . '/crl.pem',
        'fingerprint' => strtolower(str_replace(':', '', $fingerprint)),
    ];
    expect(openssl_x509_export($ca, $caPem) && openssl_pkey_export($caKey, $caKeyPem, null, $options)
        && file_put_contents($paths['ca'], $caPem) !== false && file_put_contents($directory . '/ca.key', $caKeyPem) !== false, '无法写出 CA');
    expect(openssl_x509_export($int, $intPem) && openssl_pkey_export($intKey, $intKeyPem, null, $intOptions)
        && file_put_contents($directory . '/intermediate.pem', $intPem) !== false && file_put_contents($directory . '/intermediate.key', $intKeyPem) !== false, '无法写出中间 CA');
    expect(openssl_x509_export($extraInt, $extraIntPem) && openssl_pkey_export($extraIntKey, $extraIntKeyPem, null, $intOptions)
        && file_put_contents($directory . '/extra-int.pem', $extraIntPem) !== false && file_put_contents($directory . '/extra-int.key', $extraIntKeyPem) !== false, '无法写出未登记中间 CA');
    expect(file_put_contents($paths['bundle'], $intPem . $caPem) !== false, '无法写出客户端 CA 包');
    expect(openssl_csr_export($clientRequest, $csrPem) && file_put_contents($directory . '/client.csr', $csrPem) !== false, '无法写出客户端 CSR');
    expect(openssl_csr_export($serverRequest, $serverCsrPem) && file_put_contents($directory . '/server.csr', $serverCsrPem) !== false, '无法写出服务端 CSR');
    expect(openssl_x509_export($server, $serverPem) && openssl_pkey_export($serverKey, $serverKeyPem, null, $serverOptions)
        && openssl_pkey_export($serverKey, $serverKeyEncPem, 'mqtt-key-secret', $serverOptions)
        && file_put_contents($paths['leaf'], $serverPem) !== false && file_put_contents($paths['server'], $serverPem . $intPem) !== false
        && file_put_contents($paths['key'], $serverKeyPem) !== false
        && file_put_contents($paths['keyEnc'], $serverKeyEncPem) !== false, '无法写出服务端证书');
    expect(openssl_x509_export($client, $clientPem) && openssl_pkey_export($clientKey, $clientKeyPem, null, $clientOptions)
        && file_put_contents($paths['client'], $clientPem) !== false && file_put_contents($paths['clientKey'], $clientKeyPem) !== false, '无法写出客户端证书');
    expect(openssl_x509_export($rogue, $roguePem) && openssl_pkey_export($rogueKey, $rogueKeyPem, null, $clientOptions)
        && file_put_contents($paths['rogue'], $roguePem) !== false && file_put_contents($paths['rogueKey'], $rogueKeyPem) !== false, '无法写出未登记证书');
    expect(openssl_x509_export($wrong, $wrongPem) && file_put_contents($paths['wrong'], $wrongPem) !== false, '无法写出错误用途证书');
    chmod($paths['key'], 0600);
    chmod($paths['keyEnc'], 0600);
    $sniOptions = $options;
    $sniOptions['x509_extensions'] = 'sni';
    $sniKey = openssl_pkey_new($sniOptions);
    $sniRequest = openssl_csr_new(['commonName' => 'mqtt.sni.test'], $sniKey, $sniOptions);
    $sni = openssl_csr_sign($sniRequest, $int, $intKey, 1, $sniOptions);
    expect($sniKey !== false && $sniRequest !== false && $sni !== false, '无法生成 SNI 证书');
    expect(openssl_csr_export($sniRequest, $sniCsrPem) && file_put_contents($directory . '/sni.csr', $sniCsrPem) !== false, '无法写出 SNI CSR');
    expect(openssl_x509_export($sni, $sniPem) && openssl_pkey_export($sniKey, $sniKeyPem, null, $sniOptions)
        && file_put_contents($paths['sni'], $sniPem . $intPem) !== false
        && file_put_contents($paths['sniKey'], $sniKeyPem) !== false, '无法写出 SNI 证书');
    chmod($paths['sniKey'], 0600);
    chmod($paths['clientKey'], 0600);
    chmod($paths['rogueKey'], 0600);
    chmod($directory . '/ca.key', 0600);
    chmod($directory . '/intermediate.key', 0600);
    chmod($directory . '/extra-int.key', 0600);
    mqttMtlsIssue($directory, 'client', '20200101000000Z', '20200102000000Z', $paths['expired'], 2);
    mqttMtlsIssue($directory, 'client', '20400101000000Z', '20400102000000Z', $paths['future'], 3);
    mqttMtlsIssue($directory, 'client', gmdate('YmdHis', time() - 3600) . 'Z', gmdate('YmdHis', time() + 86400 * 30) . 'Z', $paths['revoked'], 4);
    mqttMtlsIssue($directory, 'client', gmdate('YmdHis', time() - 60) . 'Z', gmdate('YmdHis', time() + 15) . 'Z', $paths['short'], 5);
    mqttMtlsIssue($directory, 'client', gmdate('YmdHis', time() - 3600) . 'Z', gmdate('YmdHis', time() + 86400 * 30) . 'Z', $paths['platform'], 6);
    mqttMtlsIssue($directory, 'client', gmdate('YmdHis', time() - 3600) . 'Z', gmdate('YmdHis', time() + 86400 * 30) . 'Z', $paths['listed'], 7);
    mqttMtlsIssue($directory, 'client', gmdate('YmdHis', time() - 3600) . 'Z', gmdate('YmdHis', time() + 86400 * 30) . 'Z', $paths['rotated'], 8);
    mqttMtlsIssue($directory, 'client', gmdate('YmdHis', time() - 3600) . 'Z', gmdate('YmdHis', time() + 86400 * 30) . 'Z', $paths['chained'], 9, 'intermediate.pem', 'intermediate.key');
    mqttMtlsIssue($directory, 'client', gmdate('YmdHis', time() - 3600) . 'Z', gmdate('YmdHis', time() + 86400 * 30) . 'Z', $paths['extra'], 10, 'extra-int.pem', 'extra-int.key');
    mqttMtlsIssue($directory, 'server', '20200101000000Z', '20200102000000Z', $directory . '/expired-server-leaf.pem', 11, 'intermediate.pem', 'intermediate.key', 'server.csr');
    mqttMtlsIssue($directory, 'client', gmdate('YmdHis', time() - 3600) . 'Z', gmdate('YmdHis', time() + 86400 * 30) . 'Z', $directory . '/wrong-server-leaf.pem', 12, 'intermediate.pem', 'intermediate.key', 'server.csr');
    mqttMtlsIssue($directory, 'server', '20400101000000Z', '20400102000000Z', $directory . '/future-server-leaf.pem', 13, 'intermediate.pem', 'intermediate.key', 'server.csr');
    expect(file_put_contents($paths['expiredServer'], (string) file_get_contents($directory . '/expired-server-leaf.pem') . $intPem) !== false, '无法写出过期服务端证书链');
    expect(file_put_contents($paths['wrongServer'], (string) file_get_contents($directory . '/wrong-server-leaf.pem') . $intPem) !== false, '无法写出错误用途服务端证书链');
    expect(file_put_contents($paths['futureServer'], (string) file_get_contents($directory . '/future-server-leaf.pem') . $intPem) !== false, '无法写出尚未生效服务端证书链');
    mqttMtlsIssue($directory, 'sni', '20200101000000Z', '20200102000000Z', $directory . '/expired-sni-leaf.pem', 14, 'intermediate.pem', 'intermediate.key', 'sni.csr');
    expect(file_put_contents($paths['expiredSni'], (string) file_get_contents($directory . '/expired-sni-leaf.pem') . $intPem) !== false, '无法写出过期 SNI 证书链');
    mqttMtlsRevokeFile($paths['revoke'], [mqttMtlsSerial($paths['platform'])]);
    mqttMtlsOverlapFile($paths['overlap'], []);
    mqttMtlsCrl($directory, $paths['crl'], ['04']);
    return $paths;
}

/**
 * 建立 5 秒超时的测试 TLS 连接，独立指定连接地址与证书主机名，并可携带客户端证书。
 *
 * @return resource 成功连接及捕获的证书信息由调用者使用，连接由调用者关闭。
 */
function mqttMtlsSocket(int $port, string $ca, ?string $client = null, ?string $clientKey = null, int $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT, string $ciphers = '', string $peer = '127.0.0.1', string $connectHost = ''): mixed
{
    $ssl = [
        'cafile' => $ca,
        'verify_peer' => true,
        'verify_peer_name' => true,
        'peer_name' => $peer,
        'crypto_method' => $crypto,
        'disable_compression' => true,
        'capture_peer_cert' => true,
        'capture_peer_cert_chain' => true,
    ];
    if ($ciphers !== '') {
        $ssl['ciphers'] = $ciphers;
    }
    if ($client !== null && $clientKey !== null) {
        $ssl['local_cert'] = $client;
        $ssl['local_pk'] = $clientKey;
    }
    $context = stream_context_create(['ssl' => $ssl]);
    $host = $connectHost !== '' ? $connectHost : $peer;
    $address = str_contains($host, ':') ? '[' . $host . ']' : $host;
    $socket = @stream_socket_client('tls://' . $address . ':' . $port, $errno, $error, 5, STREAM_CLIENT_CONNECT, $context);
    expect(is_resource($socket), '无法建立 MQTT TLS：' . $errno . ' ' . $error);
    stream_set_timeout($socket, 5);
    return $socket;
}

/**
 * 读取实际握手采用的 TLS 协议版本，缺失协商信息时失败。
 *
 * @param resource $socket
 */
function mqttMtlsProtocol(mixed $socket): string
{
    $meta = stream_get_meta_data($socket);
    $crypto = isset($meta['crypto']) && is_array($meta['crypto']) ? $meta['crypto'] : [];
    $protocol = isset($crypto['protocol']) && is_string($crypto['protocol']) ? $crypto['protocol'] : '';
    expect($protocol !== '', '无法读取 MQTT TLS 协议版本');
    return $protocol;
}

/**
 * 读取实际握手采用的密码套件，缺失协商信息时失败。
 *
 * @param resource $socket
 */
function mqttMtlsCipher(mixed $socket): string
{
    $meta = stream_get_meta_data($socket);
    $crypto = isset($meta['crypto']) && is_array($meta['crypto']) ? $meta['crypto'] : [];
    $cipher = isset($crypto['cipher_name']) && is_string($crypto['cipher_name']) ? $crypto['cipher_name'] : '';
    expect($cipher !== '', '无法读取 MQTT TLS 套件');
    return $cipher;
}

/** @param list<string> $flags @return array{status:int,output:string} */
function mqttMtlsOpensslClient(int $port, string $ca, array $flags): array
{
    [$status, $stdout, $stderr] = execute([
        'openssl', 's_client',
        '-connect', '127.0.0.1:' . $port,
        '-CAfile', $ca,
        '-verify_return_error',
        '-brief',
        ...$flags,
    ]);
    return ['status' => $status, 'output' => $stdout . $stderr];
}

/**
 * 读取测试证书主题的 commonName，缺失字段或解析失败时中止断言。
 *
 * @param OpenSSLCertificate|string $certificate
 */
function mqttMtlsCommonName(mixed $certificate): string
{
    $parsed = openssl_x509_parse($certificate, false);
    expect(is_array($parsed) && isset($parsed['subject']['commonName']) && is_string($parsed['subject']['commonName']), '无法读取证书 CN');
    return $parsed['subject']['commonName'];
}

/**
 * 读取完整 MQTT 报文，任何头部、长度或正文截断都视为失败；不关闭连接。
 *
 * @param resource $socket
 */
function mqttMtlsRead(mixed $socket): string
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
    } while (($value & 128) !== 0);
    while ($length > 0) {
        $chunk = fread($socket, $length);
        expect($chunk !== false && $chunk !== '', 'MQTT 正文不完整');
        $wire .= $chunk;
        $length -= strlen($chunk);
    }
    return $wire;
}

/**
 * 要求 MQTT 测试报文一次完整写出，短写即失败；不转移连接所有权。
 *
 * @param resource $socket
 */
function mqttMtlsWrite(mixed $socket, string $bytes): void
{
    expect(fwrite($socket, $bytes) === strlen($bytes), 'MQTT 写入失败');
}

/**
 * @param list<string> $launcher
 * @param array<string, string> $environment
 * @param array<string, string> $overrides
 */
function mqttMtlsRejectsStartup(array $launcher, string $root, array $environment, string $certificate, string $message, string $needle = 'MQTT TLS 证书已过期、尚未生效或不是服务端用途', array $overrides = []): void
{
    $tlsPort = mqttMtlsPort();
    $mtlsPort = mqttMtlsPort();
    $process = new Process([...$launcher, '--host=127.0.0.1', '--port=' . $tlsPort, '--mtls-port=' . $mtlsPort], $root, array_replace($environment, $overrides, [
        'MQTT_CERTIFICATE' => $certificate,
    ]));
    $result = $process->wait(5);
    expect(!$result->successful() && !$result->timedOut, $message . '：进程仍在运行');
    expect(str_contains($result->stderr . $result->stdout, $needle), $message . '：' . $result->stderr . $result->stdout);
}

/** 在 15 秒轮询预算内验证节点存活、TLS 握手及成功 CONNACK，确认 MQTT 入口可用。 */
function mqttMtlsWait(Process $process, int $port, string $ca, string $peer = '127.0.0.1'): void
{
    $ready = false;
    $until = microtime(true) + 15;
    do {
        expect($process->running(), 'MQTT mTLS 进程提前退出：' . $process->stderr() . $process->stdout());
        try {
            $probe = mqttMtlsSocket($port, $ca, null, null, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT, '', $peer);
            mqttMtlsWrite($probe, mqttMtlsConnect('ready', true));
            $ack = mqttMtlsRead($probe);
            expect(ord($ack[0]) === 0x20 && ord($ack[3]) === 0, 'MQTT 就绪 CONNECT 失败：' . bin2hex($ack));
            fclose($probe);
            $ready = true;
        } catch (RuntimeException) {
            usleep(20000);
        }
    } while (!$ready && microtime(true) < $until);
    expect($ready, 'MQTT 未通过 TLS CONNECT 就绪：' . $process->stderr());
}

/** 以指定操作码生成 FIN 置位、带随机客户端掩码的 WebSocket 帧。 */
function mqttMtlsWsFrame(string $payload, int $opcode = 0x2): string
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

/**
 * 读取 WebSocket 数据载荷并跳过 ping/pong；关闭帧及截断使测试失败，连接由调用者回收。
 *
 * @param resource $socket
 */
function mqttMtlsWsRead(mixed $socket): string
{
    $header = '';
    while (strlen($header) < 2) {
        $chunk = @fread($socket, 2 - strlen($header));
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
        return mqttMtlsWsRead($socket);
    }
    return $payload;
}

/** 读取 PEM 证书文件，返回小写无冒号的 SHA-256 指纹用于证书身份比对。 */
function mqttMtlsFingerprint(string $pem): string
{
    $certificate = openssl_x509_read((string) file_get_contents($pem));
    expect($certificate !== false, '无法读取测试证书');
    $fingerprint = openssl_x509_fingerprint($certificate, 'sha256', false);
    expect(is_string($fingerprint), '无法计算证书指纹');
    return strtolower(str_replace(':', '', $fingerprint));
}

/** 使用测试 CA 为指定 CSR 签发明确序列号和有效期的证书，并确认输出可读；日期采用 OpenSSL 参数格式。 */
function mqttMtlsIssue(string $directory, string $extensions, string $notBefore, string $notAfter, string $output, int $serial, string $issuer = 'ca.pem', string $issuerKey = 'ca.key', string $csr = 'client.csr'): void
{
    successful([
        'openssl', 'x509', '-req', '-in', $directory . '/' . $csr, '-CA', $directory . '/' . $issuer, '-CAkey', $directory . '/' . $issuerKey,
        '-set_serial', (string) $serial, '-extfile', $directory . '/openssl.cnf', '-extensions', $extensions,
        '-not_before', $notBefore, '-not_after', $notAfter, '-out', $output,
    ]);
    expect(is_file($output) && is_readable($output), '无法签发测试证书');
}

/**
 * @param list<string> $serials
 */
function mqttMtlsCrl(string $directory, string $output, array $serials): void
{
    $configuration = $directory . '/crl.cnf';
    file_put_contents($configuration, "[ca]\ndefault_ca=CA_default\n[CA_default]\ndir=.\ndatabase=index.txt\ncrlnumber=crlnumber\ncertificate=ca.pem\nprivate_key=ca.key\ndefault_md=sha256\ndefault_crl_days=365\nnew_certs_dir=.\nunique_subject=no\n");
    $lines = '';
    foreach ($serials as $serial) {
        $hex = strtoupper($serial);
        $hex = strlen($hex) % 2 === 1 ? '0' . $hex : $hex;
        $lines .= "R\t" . gmdate('ymdHis', time() + 86400 * 30) . "Z\t" . gmdate('ymdHis') . "Z\t" . $hex . "\tunknown\t/CN=example-device\n";
    }
    file_put_contents($directory . '/index.txt', $lines);
    if (!is_file($directory . '/crlnumber')) {
        file_put_contents($directory . '/crlnumber', "01\n");
    }
    successful(['openssl', 'ca', '-gencrl', '-config', $configuration, '-out', $output], $directory);
    expect(is_file($output) && is_readable($output), '无法生成测试 CRL');
}

/**
 * @param list<string> $serials
 */
function mqttMtlsRevokeFile(string $path, array $serials): void
{
    $body = '';
    foreach ($serials as $serial) {
        $body .= $serial . "\n";
    }
    expect(file_put_contents($path, $body) !== false, '无法写出平台吊销名单');
}

/**
 * @param list<string> $lines
 */
function mqttMtlsOverlapFile(string $path, array $lines): void
{
    $body = '';
    foreach ($lines as $line) {
        $body .= $line . "\n";
    }
    expect(file_put_contents($path, $body) !== false, '无法写出换证重叠名单');
}

/** 运行本轮 mTLS 测试专属 HTTPS CRL 源，参数来自显式测试环境；服务端不要求客户端证书。 */
function mqttMtlsServeCrlHttps(): void
{
    $port = (int) getenv('MQTT_MTLS_CRL_HTTPS_PORT');
    $file = (string) getenv('MQTT_MTLS_CRL_HTTPS_FILE');
    $certificate = (string) getenv('MQTT_CERTIFICATE');
    $privateKey = (string) getenv('MQTT_PRIVATE_KEY');
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

/** 在 5 秒轮询预算内以校验证书和主机名的 HTTPS 请求确认 CRL 内容可读。 */
function mqttMtlsCrlHttpsWait(int $port, string $ca): void
{
    $until = microtime(true) + 5;
    do {
        $body = @file_get_contents('https://127.0.0.1:' . $port . '/crl.pem', false, stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 1, 'ignore_errors' => true],
            'ssl' => [
                'cafile' => $ca,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => '127.0.0.1',
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            ],
        ]));
        if (is_string($body) && str_contains($body, 'BEGIN X509 CRL')) {
            return;
        }
        usleep(20000);
    } while (microtime(true) < $until);
    expect(false, 'HTTPS CRL 源未就绪');
}

/** PHP 和 AOT 复用真实 HTTPS 源，验证刷新、超大响应拒绝及停止收尾。 */
function mqttMtlsCrlRefresh(array $launcher, array $php, string $root, string $consumer, array $environment, array $certs): void
{
    $crlCache = $consumer . '/crl-cache.pem';
    mqttMtlsCrl(dirname($certs['crl']), $certs['crl'], ['04']);
    expect(copy($certs['crl'], $crlCache), '无法复制 CRL 缓存');
    $httpsPort = mqttMtlsPort();
    $https = new Process([...$php, __FILE__], $root, array_replace($environment, [
        'MQTT_MTLS_CRL_HTTPS' => '1',
        'MQTT_MTLS_CRL_HTTPS_PORT' => (string) $httpsPort,
        'MQTT_MTLS_CRL_HTTPS_FILE' => $certs['crl'],
    ]));
    try {
        mqttMtlsCrlHttpsWait($httpsPort, $certs['ca']);
        $tlsPort = mqttMtlsPort();
        $mtlsPort = mqttMtlsPort();
        $broker = new Process([...$launcher, '--host=127.0.0.1', '--port=' . $tlsPort, '--mtls-port=' . $mtlsPort], $root, array_replace($environment, [
            'MQTT_CLIENT_CRL' => $crlCache,
            'MQTT_CLIENT_CRL_URL' => 'https://127.0.0.1:' . $httpsPort . '/crl.pem',
            'MQTT_CLIENT_CRL_INTERVAL' => '1',
        ]));
        $live = null;
        try {
            mqttMtlsWait($broker, $tlsPort, $certs['ca']);
            $live = mqttMtlsSocket($mtlsPort, $certs['ca'], $certs['client'], $certs['clientKey']);
            mqttMtlsWrite($live, mqttMtlsConnect('mtls-https', false));
            $ack = mqttMtlsRead($live);
            expect(ord($ack[0]) === 0x20 && ord($ack[3]) === 0, 'HTTPS CRL 刷新前 CONNECT 失败：' . bin2hex($ack));
            // 吊销列表本身有效，但整个 HTTP 正文超限；不能写入缓存或断开当前合法连接。
            $accepted = (string) file_get_contents($crlCache);
            mqttMtlsCrl(dirname($certs['crl']), $certs['crl'], ['04', mqttMtlsSerial($certs['client'])]);
            $revoked = (string) file_get_contents($certs['crl']);
            expect(file_put_contents($certs['crl'], $revoked . str_repeat("\n", 1048577)) !== false, '无法写入超限 CRL 装置');
            usleep(2200000);
            expect(file_get_contents($crlCache) === $accepted, '超限 HTTPS 响应覆盖了已接纳 CRL');
            mqttMtlsWrite($live, "\xc0\x00");
            expect(mqttMtlsRead($live) === "\xd0\x00", '拒绝超限 CRL 后合法连接未保持可用');
            expect(file_put_contents($certs['crl'], $revoked) === strlen($revoked), '无法恢复有效 CRL');
            expect(mqttMtlsClosed($live, 8), 'HTTPS 刷新 CRL 后已连接证书没有断开');
            $result = $broker->stop(5);
            expect($result->successful() && $result->stderr === '', 'HTTPS CRL Broker 停止失败：' . $result->stderr);
        } finally {
            if (is_resource($live)) {
                fclose($live);
            }
            $broker->stop();
        }
    } finally {
        $https->stop();
        mqttMtlsCrl(dirname($certs['crl']), $certs['crl'], ['04']);
    }
}

/** 读取 PEM 文件中的证书序列号并按吊销列表规则统一十六进制表示。 */
function mqttMtlsSerial(string $pem): string
{
    $certificate = openssl_x509_read((string) file_get_contents($pem));
    expect($certificate !== false, '无法读取测试证书序列号');
    $parsed = openssl_x509_parse($certificate, false);
    expect(is_array($parsed) && isset($parsed['serialNumberHex']) && is_string($parsed['serialNumberHex']), '无法解析证书序列号');
    return CertificateRevocationList::serialHex($parsed['serialNumberHex']);
}

/** 读取 PEM 文件中证书的到期时间，返回 Unix 秒数。 */
function mqttMtlsNotAfter(string $pem): int
{
    $certificate = openssl_x509_read((string) file_get_contents($pem));
    expect($certificate !== false, '无法读取测试证书有效期');
    $parsed = openssl_x509_parse($certificate, false);
    expect(is_array($parsed) && isset($parsed['validTo_time_t']) && is_int($parsed['validTo_time_t']), '无法解析证书到期时间');
    return $parsed['validTo_time_t'];
}

/**
 * 在秒数轮询预算内发送 PING 并观察断开，过滤 PINGRESP 后返回 DISCONNECT 或剩余字节供调用方断言。
 *
 * @param resource $socket 连接由调用者持有并关闭。
 */
function mqttMtlsAwaitDisconnect(mixed $socket, float $seconds): string
{
    stream_set_timeout($socket, 1);
    $until = microtime(true) + $seconds;
    $wire = '';
    do {
        if (@fwrite($socket, "\xc0\x00") !== 2) {
            return $wire;
        }
        $chunk = @fread($socket, 8);
        $meta = stream_get_meta_data($socket);
        if ($meta['timed_out'] ?? false) {
            continue;
        }
        if (is_string($chunk) && $chunk !== '') {
            $wire .= $chunk;
            while ($wire !== '') {
                $type = ord($wire[0]) >> 4;
                if ($type === 0xe && strlen($wire) >= 4) {
                    return substr($wire, 0, 4);
                }
                if ($type === 0xd && strlen($wire) >= 2) {
                    $wire = substr($wire, 2);
                    continue;
                }
                break;
            }
        }
        if ($chunk === false || ($meta['eof'] ?? false)) {
            return $wire;
        }
        if ($chunk === '') {
            usleep(20000);
        }
    } while (microtime(true) < $until);
    return $wire;
}

/**
 * 在秒数轮询预算内以 PING 探测关闭，写失败、EOF 或 DISCONNECT 均视为关闭；超时返回 false。
 *
 * @param resource $socket 连接由调用者持有并关闭。
 */
function mqttMtlsClosed(mixed $socket, float $seconds): bool
{
    stream_set_timeout($socket, 1);
    $until = microtime(true) + $seconds;
    do {
        if (@fwrite($socket, "\xc0\x00") !== 2) {
            return true;
        }
        $chunk = @fread($socket, 1);
        $meta = stream_get_meta_data($socket);
        if ($meta['timed_out'] ?? false) {
            continue;
        }
        if (is_string($chunk) && $chunk !== '') {
            $type = ord($chunk) >> 4;
            if ($type === 0xe) {
                return true;
            }
            if ($type === 0xd) {
                @fread($socket, 1);
                continue;
            }
        }
        if ($chunk === false || ($meta['eof'] ?? false)) {
            return true;
        }
        if ($chunk === '') {
            usleep(20000);
        }
    } while (microtime(true) < $until);
    return false;
}

/** 尝试使用指定客户端证书连接并发送 CONNECT；握手或读取失败以及非成功 CONNACK 均表示测试拒绝。 */
function mqttMtlsRejected(int $port, string $ca, string $client, string $clientKey, string $id = 'mtls-bad-cert'): bool
{
    try {
        $socket = mqttMtlsSocket($port, $ca, $client, $clientKey);
        mqttMtlsWrite($socket, mqttMtlsConnect($id, false));
        $ack = mqttMtlsRead($socket);
        $rejected = !(strlen($ack) >= 4 && ord($ack[0]) === 0x20 && ord($ack[3]) === 0);
        fclose($socket);
        return $rejected;
    } catch (RuntimeException) {
        return true;
    }
}

/**
 * @param list<string> $headers
 * @return array{0: mixed, 1: string}
 */
function mqttMtlsWsHandshake(int $port, string $ca, ?string $client = null, ?string $clientKey = null, array $headers = ['Sec-WebSocket-Protocol: mqtt']): array
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
    expect(is_resource($socket), '无法连接 MQTT WSS：' . $errno . ' ' . $error);
    stream_set_timeout($socket, 5);
    $key = base64_encode(random_bytes(16));
    $wire = "GET /mqtt HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
        . "Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n";
    foreach ($headers as $header) {
        $wire .= $header . "\r\n";
    }
    $wire .= "\r\n";
    expect(fwrite($socket, $wire) === strlen($wire), 'WSS 握手未写完');
    $response = '';
    while (!feof($socket)) {
        $line = @fgets($socket, 1024);
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

/**
 * 把 MQTT 报文封装为客户端二进制 WSS 帧，要求一次完整写出。
 *
 * @param resource $socket 连接仍由调用者负责关闭。
 */
function mqttMtlsWsWrite(mixed $socket, string $packet): void
{
    $frame = mqttMtlsWsFrame($packet);
    expect(fwrite($socket, $frame) === strlen($frame), 'MQTT WSS 报文未写完');
}

/** 尝试证书认证、WSS 升级和 MQTT CONNECT，返回测试观察到的拒绝结果。 */
function mqttMtlsWsRejected(int $port, string $ca, ?string $client = null, ?string $clientKey = null, string $id = 'wss-bad-cert'): bool
{
    try {
        [$socket, $header] = mqttMtlsWsHandshake($port, $ca, $client, $clientKey);
        if (!str_contains($header, '101 Switching Protocols')) {
            fclose($socket);
            return true;
        }
        mqttMtlsWsWrite($socket, mqttMtlsConnect($id, false));
        $reply = mqttMtlsWsRead($socket);
        $rejected = $reply === '' || (strlen($reply) >= 4 && (ord($reply[0]) >> 4) === 2 && ord($reply[3]) !== 0);
        fclose($socket);
        return $rejected;
    } catch (RuntimeException) {
        return true;
    }
}

/** 锁定 PHPX 源码含异常策略头，构建目录中的 PHPX 可能仍是旧头文件。 */
function mqttMtlsPhpxHome(string $consumer): string
{
    $source = getenv('PHPX_HOME');
    expect(is_string($source) && is_dir($source . '/include') && is_dir($source . '/lib'), '原生编译需要 PHPX_HOME');
    $home = $consumer . '/phpx-home';
    expect(mkdir($home, 0700), '无法创建 PHPX 适配目录');
    foreach (['bin', 'lib', 'src', 'build', 'thirdparty'] as $part) {
        if (file_exists($source . '/' . $part)) {
            expect(symlink($source . '/' . $part, $home . '/' . $part), '无法链接 PHPX ' . $part);
        }
    }
    expect(mkdir($home . '/include', 0700), '无法创建 PHPX 头文件目录');
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source . '/include', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($files as $file) {
        $relative = substr($file->getPathname(), strlen(rtrim($source, '/') . '/include') + 1);
        $target = $home . '/include/' . $relative;
        if ($file->isDir()) {
            expect(mkdir($target, 0700), '无法复制 PHPX 头目录');
            continue;
        }
        expect(copy($file->getPathname(), $target), '无法复制 PHPX 头文件');
    }
    $locked = dirname(__DIR__) . '/vendor/swoole/phpx/include';
    foreach (['phpx_exception_policy.h', 'phpx_cast_policy.h'] as $header) {
        expect(is_file($locked . '/' . $header) && copy($locked . '/' . $header, $home . '/include/' . $header), '无法覆盖 PHPX 策略头文件');
    }
    $phpx = (string) file_get_contents($home . '/include/phpx.h');
    if (!str_contains($phpx, 'phpx_exception_policy.h')) {
        $needle = "#include \"phpx_native_gc.h\"\n";
        expect(str_contains($phpx, $needle), 'PHPX 头文件缺少 native_gc 插入点');
        $phpx = str_replace($needle, $needle . "#include \"phpx_exception_policy.h\"\n#include \"phpx_cast_policy.h\"\n", $phpx);
        expect(file_put_contents($home . '/include/phpx.h', $phpx) !== false, '无法写入 PHPX 头文件适配');
    }
    return $home;
}

if (getenv('MQTT_MTLS_CRL_HTTPS') === '1') {
    mqttMtlsServeCrlHttps();
    exit(0);
}

mqttMtlsReexec();

$nativeOnly = in_array('--native-only', $argv, true);
$native = in_array('--native', $argv, true) || $nativeOnly;
$root = dirname(__DIR__);
$consumer = $root . '/build/mqtt-mtls-' . bin2hex(random_bytes(5));
expect(mkdir($consumer, 0700, true), '无法创建 MQTT mTLS 验收目录');
$certs = mqttMtlsCertificates($consumer);
[$leafStatus] = execute(['openssl', 'verify', '-CAfile', $certs['ca'], $certs['leaf']]);
expect($leafStatus !== 0, '缺少中间 CA 时叶证书仍通过根校验');
successful(['openssl', 'verify', '-CAfile', $certs['ca'], '-untrusted', dirname($certs['server']) . '/intermediate.pem', $certs['leaf']]);
$php = $nativeOnly ? [PHP_BINARY] : mqttMtlsPhp();
$launcher = [...$php, '-r', 'require "vendor/autoload.php"; require "examples/mqtt/main.php"; main($argc, $argv);', '--'];
$environment = getenv();
expect(is_array($environment), '无法读取 MQTT mTLS 测试环境');
$environment['MQTT_PASSWORD'] = 'mqtt-test-secret';
$environment['MQTT_CERTIFICATE'] = $certs['server'];
$environment['MQTT_PRIVATE_KEY'] = $certs['key'];
$environment['MQTT_PRIVATE_KEY_PASSPHRASE'] = '';
$environment['MQTT_SNI_HOST'] = 'mqtt.sni.test';
$environment['MQTT_SNI_CERTIFICATE'] = $certs['sni'];
$environment['MQTT_SNI_PRIVATE_KEY'] = $certs['sniKey'];
$environment['MQTT_WORKER_COMMAND'] = '';
$environment['MQTT_CLIENT_CA'] = $certs['bundle'];
$environment['MQTT_CLIENT_FINGERPRINT'] = implode(',', [
    $certs['fingerprint'],
    mqttMtlsFingerprint($certs['expired']),
    mqttMtlsFingerprint($certs['future']),
    mqttMtlsFingerprint($certs['wrong']),
    mqttMtlsFingerprint($certs['revoked']),
    mqttMtlsFingerprint($certs['short']),
    mqttMtlsFingerprint($certs['platform']),
    mqttMtlsFingerprint($certs['listed']),
    mqttMtlsFingerprint($certs['chained']),
]);
$environment['MQTT_CLIENT_CRL'] = $certs['crl'];
$environment['MQTT_CLIENT_REVOKE'] = $certs['revoke'];
$environment['MQTT_CLIENT_OVERLAP'] = $certs['overlap'];
putenv('MQTT_PASSWORD=mqtt-test-secret');

$verified = [];
if (!$nativeOnly) {
    mqttMtlsRejectsStartup($launcher, $root, $environment, $certs['expiredServer'], '过期服务端证书没有被拒绝');
    $verified['reject-expired-server'] = true;
    mqttMtlsRejectsStartup($launcher, $root, $environment, $certs['wrongServer'], 'clientAuth 服务端证书没有被拒绝');
    $verified['reject-wrong-server'] = true;
    mqttMtlsRejectsStartup($launcher, $root, $environment, $certs['futureServer'], '尚未生效的服务端证书没有被拒绝');
    $verified['reject-not-yet-valid-server'] = true;
    mqttMtlsRejectsStartup($launcher, $root, $environment, $certs['server'], '错误私钥口令没有被拒绝', 'MQTT TLS 证书或私钥无效、口令错误或二者不匹配', [
        'MQTT_PRIVATE_KEY' => $certs['keyEnc'],
        'MQTT_PRIVATE_KEY_PASSPHRASE' => 'wrong-passphrase',
    ]);
    $verified['reject-wrong-passphrase'] = true;
    mqttMtlsRejectsStartup($launcher, $root, $environment, $certs['server'], '证书与私钥不匹配没有被拒绝', 'MQTT TLS 证书或私钥无效、口令错误或二者不匹配', [
        'MQTT_PRIVATE_KEY' => $certs['clientKey'],
    ]);
    $verified['reject-key-mismatch'] = true;
    mqttMtlsRejectsStartup($launcher, $root, $environment, $certs['server'], '过期 SNI 证书没有被拒绝', 'MQTT TLS 证书已过期、尚未生效或不是服务端用途', [
        'MQTT_SNI_CERTIFICATE' => $certs['expiredSni'],
    ]);
    $verified['reject-expired-sni'] = true;
    $passPort = mqttMtlsPort();
    $passMtls = mqttMtlsPort();
    $passBroker = new Process([...$launcher, '--host=127.0.0.1', '--port=' . $passPort, '--mtls-port=' . $passMtls], $root, array_replace($environment, [
        'MQTT_PRIVATE_KEY' => $certs['keyEnc'],
        'MQTT_PRIVATE_KEY_PASSPHRASE' => 'mqtt-key-secret',
    ]));
    try {
        mqttMtlsWait($passBroker, $passPort, $certs['ca']);
        $passClient = mqttMtlsSocket($passMtls, $certs['ca'], $certs['client'], $certs['clientKey']);
        mqttMtlsWrite($passClient, mqttMtlsConnect('mtls-passphrase', false));
        $passAck = mqttMtlsRead($passClient);
        expect(ord($passAck[0]) === 0x20 && ord($passAck[3]) === 0, '加密私钥 CONNECT 失败：' . bin2hex($passAck));
        fclose($passClient);
        $passResult = $passBroker->stop(5);
        expect($passResult->successful() && $passResult->stderr === '', '加密私钥 Broker 停止失败：' . $passResult->stderr);
        expect(!str_contains($passResult->stdout . $passResult->stderr, 'mqtt-key-secret'), '私钥口令出现在进程输出');
        $leftover = glob(dirname($certs['keyEnc']) . '/.mqtt-native-*.key') ?: [];
        expect($leftover === [], '解密私钥临时文件没有删除：' . implode(',', $leftover));
        $verified['passphrase'] = true;
    } finally {
        $passBroker->stop();
    }

    $v6Tls = mqttMtlsPort('::1');
    $v6Mtls = mqttMtlsPort('::1');
    $v6Broker = new Process([...$launcher, '--host=::1', '--port=' . $v6Tls, '--mtls-port=' . $v6Mtls], $root, $environment);
    try {
        mqttMtlsWait($v6Broker, $v6Tls, $certs['ca'], '::1');
        $v6Password = mqttMtlsSocket($v6Tls, $certs['ca'], null, null, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT, '', '::1');
        mqttMtlsWrite($v6Password, mqttMtlsConnect('tls-ipv6', true));
        $v6Ack = mqttMtlsRead($v6Password);
        expect(ord($v6Ack[0]) === 0x20 && ord($v6Ack[3]) === 0, 'IPv6 TLS CONNECT 失败：' . bin2hex($v6Ack));
        fclose($v6Password);
        $v6Cert = mqttMtlsSocket($v6Mtls, $certs['ca'], $certs['client'], $certs['clientKey'], STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT, '', '::1');
        mqttMtlsWrite($v6Cert, mqttMtlsConnect('mtls-ipv6', false));
        $v6CertAck = mqttMtlsRead($v6Cert);
        expect(ord($v6CertAck[0]) === 0x20 && ord($v6CertAck[3]) === 0, 'IPv6 mTLS CONNECT 失败：' . bin2hex($v6CertAck));
        fclose($v6Cert);
        $v6Result = $v6Broker->stop(5);
        expect($v6Result->successful() && $v6Result->stderr === '', 'IPv6 Broker 停止失败：' . $v6Result->stderr);
        $verified['ipv6'] = true;
    } finally {
        $v6Broker->stop();
    }
}
$tlsPort = mqttMtlsPort();
$mtlsPort = mqttMtlsPort();
$process = $nativeOnly ? null : new Process([...$launcher, '--host=127.0.0.1', '--port=' . $tlsPort, '--mtls-port=' . $mtlsPort], $root, $environment);
try {
    if (!$nativeOnly) {
        mqttMtlsWait($process, $tlsPort, $certs['ca']);

        $password = mqttMtlsSocket($tlsPort, $certs['ca']);
        mqttMtlsWrite($password, mqttMtlsConnect('tls-password', true));
        $passwordAck = mqttMtlsRead($password);
        expect(ord($passwordAck[0]) === 0x20 && ord($passwordAck[3]) === 0, '凭据 TLS 入口 CONNECT 失败：' . bin2hex($passwordAck));
        fclose($password);
        $verified['tls-password'] = true;

        $chainProbe = mqttMtlsSocket($tlsPort, $certs['ca']);
        $peer = stream_context_get_options($chainProbe);
        $sslPeer = isset($peer['ssl']) && is_array($peer['ssl']) ? $peer['ssl'] : [];
        expect(isset($sslPeer['peer_certificate']) && mqttMtlsCommonName($sslPeer['peer_certificate']) === '127.0.0.1', '服务端叶证书 CN 不是 127.0.0.1');
        $names = [];
        if (isset($sslPeer['peer_certificate_chain']) && is_array($sslPeer['peer_certificate_chain'])) {
            foreach ($sslPeer['peer_certificate_chain'] as $item) {
                $names[] = mqttMtlsCommonName($item);
            }
        }
        expect(in_array('type-mqtt-test-int', $names, true), '服务端没有下发中间 CA：' . implode(',', $names));
        mqttMtlsWrite($chainProbe, mqttMtlsConnect('tls-server-chain', true));
        $chainAck = mqttMtlsRead($chainProbe);
        expect(ord($chainAck[0]) === 0x20 && ord($chainAck[3]) === 0, '服务端证书链 CONNECT 失败：' . bin2hex($chainAck));
        fclose($chainProbe);
        $verified['server-chain'] = true;

        $sniProbe = mqttMtlsSocket($tlsPort, $certs['ca'], null, null, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT, '', 'mqtt.sni.test', '127.0.0.1');
        $sniPeer = stream_context_get_options($sniProbe);
        $sniSsl = isset($sniPeer['ssl']) && is_array($sniPeer['ssl']) ? $sniPeer['ssl'] : [];
        expect(isset($sniSsl['peer_certificate']) && mqttMtlsCommonName($sniSsl['peer_certificate']) === 'mqtt.sni.test', 'SNI 主机没有拿到对应叶证书');
        mqttMtlsWrite($sniProbe, mqttMtlsConnect('tls-sni', true));
        $sniAck = mqttMtlsRead($sniProbe);
        expect(ord($sniAck[0]) === 0x20 && ord($sniAck[3]) === 0, 'SNI CONNECT 失败：' . bin2hex($sniAck));
        fclose($sniProbe);
        $sniWire = mqttMtlsOpensslClient($tlsPort, $certs['ca'], ['-servername', 'mqtt.sni.test']);
        expect($sniWire['status'] === 0 && str_contains($sniWire['output'], 'mqtt.sni.test'), 'openssl SNI 未选中主机证书：' . $sniWire['output']);
        $defaultWire = mqttMtlsOpensslClient($tlsPort, $certs['ca'], ['-servername', '127.0.0.1']);
        expect($defaultWire['status'] === 0 && str_contains($defaultWire['output'], '127.0.0.1'), '缺省证书在 SNI 后丢失：' . $defaultWire['output']);
        $verified['sni'] = true;

        $preferred = mqttMtlsSocket($tlsPort, $certs['ca']);
        expect(mqttMtlsProtocol($preferred) === 'TLSv1.3', '同时提供 TLS 1.2/1.3 的客户端没有协商到 1.3');
        mqttMtlsWrite($preferred, mqttMtlsConnect('tls-13-pref', true));
        $preferredAck = mqttMtlsRead($preferred);
        expect(ord($preferredAck[0]) === 0x20 && ord($preferredAck[3]) === 0, '优先 TLS 1.3 CONNECT 失败：' . bin2hex($preferredAck));
        fclose($preferred);
        $verified['tls-1.3-preferred'] = true;

        $tls12 = mqttMtlsSocket($tlsPort, $certs['ca'], null, null, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);
        $tls12Version = mqttMtlsProtocol($tls12);
        expect($tls12Version === 'TLSv1.2', '仅 TLS 1.2 客户端没有停留在 1.2：' . $tls12Version);
        $tls12Cipher = mqttMtlsCipher($tls12);
        expect(str_contains($tls12Cipher, 'ECDHE') && (str_contains($tls12Cipher, 'GCM') || str_contains($tls12Cipher, 'CHACHA')), 'TLS 1.2 没有协商到 ECDHE AEAD：' . $tls12Cipher);
        mqttMtlsWrite($tls12, mqttMtlsConnect('tls-12', true));
        $tls12Ack = mqttMtlsRead($tls12);
        expect(ord($tls12Ack[0]) === 0x20 && ord($tls12Ack[3]) === 0, 'TLS 1.2 CONNECT 失败：' . bin2hex($tls12Ack));
        fclose($tls12);
        $verified['tls-1.2'] = true;

        $staticRsa = false;
        try {
            mqttMtlsSocket($tlsPort, $certs['ca'], null, null, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT, 'AES128-GCM-SHA256');
        } catch (RuntimeException) {
            $staticRsa = true;
        }
        expect($staticRsa, '静态 RSA AES-GCM 套件没有被拒绝');
        $verified['reject-static-rsa'] = true;

        $x25519 = mqttMtlsOpensslClient($tlsPort, $certs['ca'], ['-tls1_3', '-groups', 'X25519']);
        expect($x25519['status'] === 0 && str_contains($x25519['output'], 'X25519'), 'X25519 密钥交换失败：' . $x25519['output']);
        $verified['ecdh-x25519'] = true;
        $p256 = mqttMtlsOpensslClient($tlsPort, $certs['ca'], ['-tls1_3', '-groups', 'P-256']);
        expect($p256['status'] === 0 && (str_contains($p256['output'], 'P-256') || str_contains($p256['output'], 'prime256v1')), 'P-256 密钥交换失败：' . $p256['output']);
        $verified['ecdh-p256'] = true;
        $p384 = mqttMtlsOpensslClient($tlsPort, $certs['ca'], ['-tls1_3', '-groups', 'P-384']);
        expect($p384['status'] !== 0, 'P-384 密钥交换没有被拒绝：' . $p384['output']);
        $verified['reject-p384'] = true;

        $tls13 = mqttMtlsSocket($tlsPort, $certs['ca'], null, null, STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
        expect(mqttMtlsProtocol($tls13) === 'TLSv1.3', '仅 TLS 1.3 客户端没有协商到 1.3');
        mqttMtlsWrite($tls13, mqttMtlsConnect('tls-13', true));
        $tls13Ack = mqttMtlsRead($tls13);
        expect(ord($tls13Ack[0]) === 0x20 && ord($tls13Ack[3]) === 0, 'TLS 1.3 CONNECT 失败：' . bin2hex($tls13Ack));
        fclose($tls13);
        $verified['tls-1.3'] = true;

        $legacyFailed = false;
        try {
            mqttMtlsSocket($tlsPort, $certs['ca'], null, null, STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT);
        } catch (RuntimeException) {
            $legacyFailed = true;
        }
        expect($legacyFailed, 'TLS 1.1 客户端没有被拒绝');
        $verified['reject-tls-1.1'] = true;

        $none = @stream_socket_client('tls://127.0.0.1:' . $mtlsPort, $errno, $error, 3, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => [
            'cafile' => $certs['ca'], 'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => '127.0.0.1',
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ]]));
        if (is_resource($none)) {
            stream_set_timeout($none, 2);
            $first = @fread($none, 1);
            expect($first === false || $first === '', '未提供客户端证书仍进入了 MQTT');
            fclose($none);
        }
        $verified['reject-no-cert'] = true;

        $rogueFailed = false;
        try {
            $rogue = mqttMtlsSocket($mtlsPort, $certs['ca'], $certs['rogue'], $certs['rogueKey']);
            mqttMtlsWrite($rogue, mqttMtlsConnect('mtls-rogue', false));
            $rogueAck = mqttMtlsRead($rogue);
            $rogueFailed = !(ord($rogueAck[0]) === 0x20 && ord($rogueAck[3]) === 0);
            fclose($rogue);
        } catch (RuntimeException) {
            $rogueFailed = true;
        }
        expect($rogueFailed, '未登记 CA 签发的客户端证书没有被拒绝');
        $verified['reject-unknown-ca'] = true;

        $chained = mqttMtlsSocket($mtlsPort, $certs['ca'], $certs['chained'], $certs['clientKey']);
        mqttMtlsWrite($chained, mqttMtlsConnect('mtls-chain', false));
        $chainedAck = mqttMtlsRead($chained);
        expect(ord($chainedAck[0]) === 0x20 && ord($chainedAck[3]) === 0, '中间 CA 签发的客户端证书 CONNECT 失败：' . bin2hex($chainedAck));
        fclose($chained);
        $verified['cert-chain'] = true;
        expect(mqttMtlsRejected($mtlsPort, $certs['ca'], $certs['extra'], $certs['clientKey'], 'mtls-extra-int'), '未列入 CA 包的中间 CA 签发证书没有被拒绝');
        $verified['reject-unknown-intermediate'] = true;

        expect(mqttMtlsRejected($mtlsPort, $certs['ca'], $certs['expired'], $certs['clientKey'], 'mtls-expired'), '过期客户端证书没有被拒绝');
        $verified['reject-expired'] = true;
        expect(mqttMtlsRejected($mtlsPort, $certs['ca'], $certs['future'], $certs['clientKey'], 'mtls-future'), '尚未生效的客户端证书没有被拒绝');
        $verified['reject-not-yet-valid'] = true;
        expect(mqttMtlsRejected($mtlsPort, $certs['ca'], $certs['wrong'], $certs['clientKey'], 'mtls-wrong-purpose'), 'serverAuth 客户端证书没有被拒绝');
        $verified['reject-wrong-purpose'] = true;
        expect(mqttMtlsRejected($mtlsPort, $certs['ca'], $certs['revoked'], $certs['clientKey'], 'mtls-revoked'), 'CRL 已吊销的客户端证书没有被拒绝');
        $verified['reject-revoked'] = true;
        expect(mqttMtlsRejected($mtlsPort, $certs['ca'], $certs['platform'], $certs['clientKey'], 'mtls-platform'), '平台吊销名单中的客户端证书没有被拒绝');
        $verified['reject-platform'] = true;

        $expires = mqttMtlsNotAfter($certs['short']);
        expect($expires - time() >= 4, '短时证书剩余有效期过短');
        $short = mqttMtlsSocket($mtlsPort, $certs['ca'], $certs['short'], $certs['clientKey']);
        mqttMtlsWrite($short, mqttMtlsConnect('mtls-short', false));
        $shortAck = mqttMtlsRead($short);
        expect(ord($shortAck[0]) === 0x20 && ord($shortAck[3]) === 0, '短时证书 CONNECT 失败：' . bin2hex($shortAck));
        expect(!mqttMtlsClosed($short, 2.0), '证书尚未到期会话已被断开');
        expect(mqttMtlsClosed($short, (float) max(5, $expires - time() + 5)), '客户端证书到期后已连接会话没有断开');
        fclose($short);
        $verified['expire-live'] = true;

        $liveDir = dirname($certs['server']);
        $liveLeaf = $liveDir . '/live-server-leaf.pem';
        $liveChain = $liveDir . '/live-server.pem';
        mqttMtlsIssue($liveDir, 'server', gmdate('YmdHis', time() - 60) . 'Z', gmdate('YmdHis', time() + 15) . 'Z', $liveLeaf, 15, 'intermediate.pem', 'intermediate.key', 'server.csr');
        expect(file_put_contents($liveChain, (string) file_get_contents($liveLeaf) . (string) file_get_contents($liveDir . '/intermediate.pem')) !== false, '无法写出短时服务端证书链');
        $liveExpires = mqttMtlsNotAfter($liveChain);
        expect($liveExpires - time() >= 8, '短时服务端证书剩余有效期过短');
        $liveTls = mqttMtlsPort();
        $liveMtls = mqttMtlsPort();
        $liveBroker = new Process([...$launcher, '--host=127.0.0.1', '--port=' . $liveTls, '--mtls-port=' . $liveMtls], $root, array_replace($environment, [
            'MQTT_CERTIFICATE' => $liveChain,
            'MQTT_SNI_HOST' => '',
            'MQTT_SNI_CERTIFICATE' => '',
            'MQTT_SNI_PRIVATE_KEY' => '',
        ]));
        try {
            mqttMtlsWait($liveBroker, $liveTls, $certs['ca']);
            $liveClient = mqttMtlsSocket($liveTls, $certs['ca']);
            mqttMtlsWrite($liveClient, mqttMtlsConnect('tls-server-expire', true));
            $liveAck = mqttMtlsRead($liveClient);
            expect(ord($liveAck[0]) === 0x20 && ord($liveAck[3]) === 0, '短时服务端证书 CONNECT 失败：' . bin2hex($liveAck));
            expect(!mqttMtlsClosed($liveClient, 2.0), '服务端证书尚未到期会话已被断开');
            $stop = mqttMtlsAwaitDisconnect($liveClient, (float) max(6, $liveExpires - time() + 4));
            expect(strlen($stop) >= 3 && ord($stop[0]) === 0xe0 && ord($stop[2]) === 0x8b, '服务端证书到期没有 DISCONNECT 0x8b：' . bin2hex($stop));
            fclose($liveClient);
            $lateFailed = false;
            try {
                $late = mqttMtlsSocket($liveTls, $certs['ca']);
                mqttMtlsWrite($late, mqttMtlsConnect('tls-server-expired', true));
                $lateAck = mqttMtlsRead($late);
                $lateFailed = !(ord($lateAck[0]) === 0x20 && ord($lateAck[3]) === 0);
                fclose($late);
            } catch (RuntimeException) {
                $lateFailed = true;
            }
            expect($lateFailed, '服务端证书到期后新会话仍被接受');
            $liveResult = $liveBroker->stop(5);
            expect($liveResult->successful() && $liveResult->stderr === '', '短时服务端证书 Broker 停止失败：' . $liveResult->stderr);
            $verified['expire-server-live'] = true;
        } finally {
            $liveBroker->stop();
        }

        $certOnly = mqttMtlsSocket($mtlsPort, $certs['ca'], $certs['client'], $certs['clientKey']);
        mqttMtlsWrite($certOnly, mqttMtlsConnect('mtls-cert', false));
        $certAck = mqttMtlsRead($certOnly);
        expect(ord($certAck[0]) === 0x20 && ord($certAck[3]) === 0, '证书单独认证失败：' . bin2hex($certAck));
        $verified['cert-only'] = true;

        $keepAlive = mqttMtlsSocket($mtlsPort, $certs['ca'], $certs['client'], $certs['clientKey']);
        mqttMtlsWrite($keepAlive, mqttMtlsConnect('mtls-keepalive', false, 'mqtt-test-secret', 1));
        $keepAck = mqttMtlsRead($keepAlive);
        expect(ord($keepAck[0]) === 0x20 && ord($keepAck[3]) === 0, 'Keep Alive CONNECT 失败：' . bin2hex($keepAck));
        $keepStarted = microtime(true);
        usleep(800000);
        mqttMtlsWrite($keepAlive, "\xc0");
        $idle = mqttMtlsRead($keepAlive);
        $elapsed = microtime(true) - $keepStarted;
        expect($idle === "\xe0\x02\x8d\x00", 'mTLS Keep Alive 超时没有 DISCONNECT 0x8d：' . bin2hex($idle));
        expect($elapsed >= 1.3 && $elapsed < 2.8, 'mTLS Keep Alive 没有按 1.5 倍超时：' . (string) $elapsed);
        fclose($keepAlive);
        $verified['keep-alive'] = true;

        $both = mqttMtlsSocket($mtlsPort, $certs['ca'], $certs['client'], $certs['clientKey']);
        mqttMtlsWrite($both, mqttMtlsConnect('mtls-both', true));
        $bothAck = mqttMtlsRead($both);
        expect(ord($bothAck[0]) === 0x20 && ord($bothAck[3]) === 0, '证书与凭据同时认证失败：' . bin2hex($bothAck));
        $verified['cert-and-password'] = true;

        $mismatch = mqttMtlsSocket($mtlsPort, $certs['ca'], $certs['client'], $certs['clientKey']);
        mqttMtlsWrite($mismatch, mqttMtlsConnect('mtls-mismatch', true, 'wrong-password'));
        $mismatchAck = mqttMtlsRead($mismatch);
        expect(ord($mismatchAck[0]) === 0x20 && ord($mismatchAck[3]) === 0x87, '错误密码降级成了证书认证：' . bin2hex($mismatchAck));
        fclose($mismatch);
        $verified['no-downgrade'] = true;

        $subscriber = mqttMtlsSocket($tlsPort, $certs['ca']);
        mqttMtlsWrite($subscriber, mqttMtlsConnect('tls-sub', true));
        expect(ord(mqttMtlsRead($subscriber)[0]) === 0x20, '跨入口订阅者 CONNECT 失败');
        mqttMtlsWrite($subscriber, mqttMtlsSubscribe('example/mtls-cross'));
        expect(ord(mqttMtlsRead($subscriber)[0]) === 0x90, '跨入口订阅失败');
        mqttMtlsWrite($certOnly, mqttMtlsPublish('example/mtls-cross', 'mtls'));
        $delivered = mqttMtlsRead($subscriber);
        expect(str_contains($delivered, 'mtls'), 'mTLS 发布没有到达 TLS 订阅者：' . bin2hex($delivered));
        fclose($certOnly);
        fclose($both);
        fclose($subscriber);
        $verified['cross-tls'] = true;

        $missingLive = mqttMtlsSocket($mtlsPort, $certs['ca'], $certs['client'], $certs['clientKey']);
        mqttMtlsWrite($missingLive, mqttMtlsConnect('mtls-crl-missing', false));
        $missingAck = mqttMtlsRead($missingLive);
        expect(ord($missingAck[0]) === 0x20 && ord($missingAck[3]) === 0, 'CRL 缺失前 CONNECT 失败：' . bin2hex($missingAck));
        $hidden = $certs['crl'] . '.hidden';
        try {
            expect(rename($certs['crl'], $hidden), '无法移走测试 CRL');
            expect(mqttMtlsClosed($missingLive, 5), 'CRL 文件缺失后已连接证书没有断开');
            fclose($missingLive);
            $verified['crl-missing-live'] = true;
            expect(mqttMtlsRejected($mtlsPort, $certs['ca'], $certs['client'], $certs['clientKey'], 'mtls-crl-missing'), 'CRL 文件缺失后新连接仍被接受');
            $verified['crl-missing-reject'] = true;
            expect(rename($hidden, $certs['crl']), '无法恢复测试 CRL');
            usleep(200000);
            $restored = mqttMtlsSocket($mtlsPort, $certs['ca'], $certs['client'], $certs['clientKey']);
            mqttMtlsWrite($restored, mqttMtlsConnect('mtls-crl-restored', false));
            $restoredAck = mqttMtlsRead($restored);
            expect(ord($restoredAck[0]) === 0x20 && ord($restoredAck[3]) === 0, 'CRL 文件恢复后 CONNECT 失败：' . bin2hex($restoredAck));
            fclose($restored);
            $verified['crl-missing-restore'] = true;
        } finally {
            if (is_file($hidden) && !is_file($certs['crl'])) {
                rename($hidden, $certs['crl']);
            }
        }

        $live = mqttMtlsSocket($mtlsPort, $certs['ca'], $certs['client'], $certs['clientKey']);
        mqttMtlsWrite($live, mqttMtlsConnect('mtls-live', false));
        $liveAck = mqttMtlsRead($live);
        expect(ord($liveAck[0]) === 0x20 && ord($liveAck[3]) === 0, '待吊销连接 CONNECT 失败：' . bin2hex($liveAck));
        mqttMtlsCrl(dirname($certs['crl']), $certs['crl'], ['04', mqttMtlsSerial($certs['client'])]);
        expect(mqttMtlsClosed($live, 5), '更新 CRL 后已连接证书没有在 5 秒内断开');
        $verified['revoke-live'] = true;
        mqttMtlsCrl(dirname($certs['crl']), $certs['crl'], ['04']);
        fclose($live);

        $platformLive = mqttMtlsSocket($mtlsPort, $certs['ca'], $certs['listed'], $certs['clientKey']);
        mqttMtlsWrite($platformLive, mqttMtlsConnect('mtls-platform-live', false));
        $platformAck = mqttMtlsRead($platformLive);
        expect(ord($platformAck[0]) === 0x20 && ord($platformAck[3]) === 0, '待平台吊销连接 CONNECT 失败：' . bin2hex($platformAck));
        mqttMtlsRevokeFile($certs['revoke'], [mqttMtlsSerial($certs['platform']), mqttMtlsSerial($certs['listed'])]);
        expect(mqttMtlsClosed($platformLive, 5), '更新平台吊销名单后已连接证书没有断开');
        fclose($platformLive);
        $verified['revoke-platform-live'] = true;
        mqttMtlsRevokeFile($certs['revoke'], [mqttMtlsSerial($certs['platform'])]);
        expect(mqttMtlsRejected($mtlsPort, $certs['ca'], $certs['listed'], $certs['clientKey'], 'mtls-platform-sticky'), '缩短平台吊销名单后已接纳序列号又被接受');
        $verified['revoke-platform-sticky'] = true;

        expect(mqttMtlsRejected($mtlsPort, $certs['ca'], $certs['rotated'], $certs['clientKey'], 'mtls-rotated-before'), '未进入重叠窗口的换证证书已被接受');
        $verified['reject-overlap-before'] = true;
        mqttMtlsOverlapFile($certs['overlap'], [mqttMtlsFingerprint($certs['rotated']) . ' ' . (string) (time() + 30)]);
        usleep(200000);
        $overlapLive = mqttMtlsSocket($mtlsPort, $certs['ca'], $certs['rotated'], $certs['clientKey']);
        mqttMtlsWrite($overlapLive, mqttMtlsConnect('mtls-overlap-live', false));
        $overlapAck = mqttMtlsRead($overlapLive);
        expect(ord($overlapAck[0]) === 0x20 && ord($overlapAck[3]) === 0, '换证重叠窗口内 CONNECT 失败：' . bin2hex($overlapAck));
        $verified['overlap-accept'] = true;
        mqttMtlsOverlapFile($certs['overlap'], [
            mqttMtlsFingerprint($certs['rotated']) . ' ' . (string) (time() + 30),
            mqttMtlsFingerprint($certs['revoked']) . ' ' . (string) (time() + 30),
        ]);
        expect(mqttMtlsRejected($mtlsPort, $certs['ca'], $certs['revoked'], $certs['clientKey'], 'mtls-overlap-revoked'), '重叠窗口给了已吊销证书宽限');
        $verified['overlap-no-revoke-grace'] = true;
        mqttMtlsOverlapFile($certs['overlap'], []);
        expect(mqttMtlsClosed($overlapLive, 5), '清零换证重叠后已连接旧证书没有断开');
        fclose($overlapLive);
        $verified['overlap-live'] = true;
        expect(mqttMtlsRejected($mtlsPort, $certs['ca'], $certs['rotated'], $certs['clientKey'], 'mtls-overlap-ended'), '重叠结束后旧证书仍被接受');
        $verified['overlap-ended'] = true;
        mqttMtlsOverlapFile($certs['overlap'], [mqttMtlsFingerprint($certs['rotated']) . ' ' . (string) (time() + 30)]);
        usleep(200000);
        $renewed = mqttMtlsSocket($mtlsPort, $certs['ca'], $certs['rotated'], $certs['clientKey']);
        mqttMtlsWrite($renewed, mqttMtlsConnect('mtls-overlap-renew', false));
        $renewedAck = mqttMtlsRead($renewed);
        expect(ord($renewedAck[0]) === 0x20 && ord($renewedAck[3]) === 0, '重新打开重叠窗口后旧证书仍被拒绝：' . bin2hex($renewedAck));
        fclose($renewed);
        $verified['overlap-renew'] = true;
        mqttMtlsOverlapFile($certs['overlap'], []);

        $result = $process->stop(5);
        expect($result->successful() && $result->stderr === '', 'mTLS Broker 停止失败：' . $result->stderr);

        mqttMtlsCrlRefresh($launcher, $php, $root, $consumer, $environment, $certs);
        $verified['revoke-https'] = true;
        $verified['crl-https-size-limit'] = true;

        $tlsPort = mqttMtlsPort();
        $wssPort = mqttMtlsPort();
        $wsProcess = new Process([...$launcher, '--host=127.0.0.1', '--port=' . $tlsPort, '--wss-port=' . $wssPort], $root, $environment);
        try {
            mqttMtlsWait($wsProcess, $tlsPort, $certs['ca']);

            expect(mqttMtlsWsRejected($wssPort, $certs['ca']), '未提供客户端证书仍进入了 WSS');
            $verified['wss-reject-no-cert'] = true;
            expect(mqttMtlsWsRejected($wssPort, $certs['ca'], $certs['expired'], $certs['clientKey'], 'wss-expired'), '过期客户端证书仍进入了 WSS');
            $verified['wss-reject-expired'] = true;
            expect(mqttMtlsWsRejected($wssPort, $certs['ca'], $certs['wrong'], $certs['clientKey'], 'wss-wrong-purpose'), 'serverAuth 客户端证书仍进入了 WSS');
            $verified['wss-reject-wrong-purpose'] = true;
            expect(mqttMtlsWsRejected($wssPort, $certs['ca'], $certs['revoked'], $certs['clientKey'], 'wss-revoked'), 'CRL 已吊销的客户端证书仍进入了 WSS');
            $verified['wss-reject-revoked'] = true;
            expect(mqttMtlsWsRejected($wssPort, $certs['ca'], $certs['platform'], $certs['clientKey'], 'wss-platform'), '平台吊销名单中的客户端证书仍进入了 WSS');
            $verified['wss-reject-platform'] = true;

            [$wss, $header] = mqttMtlsWsHandshake($wssPort, $certs['ca'], $certs['client'], $certs['clientKey']);
            expect(str_contains($header, '101') && str_contains(strtolower($header), 'sec-websocket-protocol: mqtt'), 'WSS mTLS 握手未选择 mqtt：' . $header);
            mqttMtlsWsWrite($wss, mqttMtlsConnect('wss-cert', false));
            $wssAck = mqttMtlsWsRead($wss);
            expect(ord($wssAck[0]) === 0x20 && ord($wssAck[3]) === 0, 'WSS 证书单独认证失败：' . bin2hex($wssAck));
            $verified['wss-cert-only'] = true;

            $subscriber = mqttMtlsSocket($tlsPort, $certs['ca']);
            mqttMtlsWrite($subscriber, mqttMtlsConnect('wss-tls-sub', true));
            expect(ord(mqttMtlsRead($subscriber)[0]) === 0x20, 'WSS 跨传输订阅者 CONNECT 失败');
            mqttMtlsWrite($subscriber, mqttMtlsSubscribe('example/wss-mtls'));
            expect(ord(mqttMtlsRead($subscriber)[0]) === 0x90, 'WSS 跨传输订阅失败');
            mqttMtlsWsWrite($wss, mqttMtlsPublish('example/wss-mtls', 'wss-mtls'));
            $delivered = mqttMtlsRead($subscriber);
            expect(str_contains($delivered, 'wss-mtls'), 'WSS 发布没有到达 TLS 订阅者：' . bin2hex($delivered));
            fclose($wss);
            fclose($subscriber);
            $verified['wss-cross-tls'] = true;

            $wsResult = $wsProcess->stop(5);
            expect($wsResult->successful() && $wsResult->stderr === '', 'WSS mTLS Broker 停止失败：' . $wsResult->stderr);
        } finally {
            $wsProcess->stop();
        }
    }

    if ($native) {
        expect(mkdir($consumer . '/app', 0700, true), '无法创建独立消费者目录');
        $toolchain = json_decode((string) file_get_contents($root . '/toolchain.lock.json'), true, 512, JSON_THROW_ON_ERROR);
        $repositories = [];
        foreach (['type-mqtt', 'type-runtime', 'type-orm', 'type-orm-pgsql', 'type-build'] as $package) {
            $repositories[] = ['type' => 'path', 'url' => '../../plugin/' . $package,
                'options' => ['symlink' => false, 'versions' => ['zoujingli/' . $package => '1.0.x-dev']]];
        }
        $composer = ['name' => 'type-tests/mqtt-mtls-consumer', 'type' => 'project', 'license' => 'Apache-2.0',
            'require' => ['zoujingli/type-mqtt' => '~1.0.0@dev', 'zoujingli/type-orm-pgsql' => '~1.0.0@dev'],
            'require-dev' => ['zoujingli/type-build' => '~1.0.0@dev', 'swoole/typephp' => $toolchain['typephp']['version'], 'swoole/phpx' => $toolchain['phpx']['version']],
            'autoload' => ['classmap' => ['app/main.php']], 'repositories' => $repositories,
            'minimum-stability' => 'dev', 'prefer-stable' => true, 'config' => ['allow-plugins' => false]];
        file_put_contents($consumer . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        copy($root . '/examples/mqtt/main.php', $consumer . '/app/main.php');
        copy($root . '/toolchain.lock.json', $consumer . '/toolchain.lock.json');
        $module = (string) (getenv('TYPE_SWOOLE_MODULE') ?: ini_get('extension_dir') . '/swoole.so');
        expect(is_file($module), '原生 mTLS 编译需要匹配 SDK 的 Swoole 模块');
        expect(copy($module, $consumer . '/swoole.so'), '无法固定 Swoole 运行模块');
        if (PHP_OS_FAMILY === 'Darwin') {
            expect(str_contains(successful(['otool', '-L', $consumer . '/swoole.so']), 'libssl'), '原生 mTLS 需要链接 OpenSSL 的 Swoole 模块');
        }
        $hash = hash_file('sha256', $consumer . '/swoole.so');
        expect(is_string($hash) && $hash !== '', '无法计算 Swoole 模块摘要');
        file_put_contents($consumer . '/type-app.json', json_encode([
            'name' => 'type-mqtt-mtls', 'entry' => 'app/main.php', 'sources' => ['app/main.php'],
            'output' => 'build/mqtt/type-app', 'build-directory' => 'build/mqtt/compiler',
            'runtime' => [PHP_OS_FAMILY => [
                'extensions' => ['mysqlnd', 'swoole'],
                'modules' => ['swoole' => ['file' => 'swoole.so', 'sha256' => $hash]],
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        successful([getenv('COMPOSER_BINARY') ?: 'composer', 'install', '--no-interaction', '--no-scripts', '--no-plugins', '--no-progress', '--ignore-platform-req=ext-pdo_pgsql'], $consumer);
        expect(!is_link($consumer . '/vendor/zoujingli/type-mqtt') && !is_link($consumer . '/vendor/zoujingli/type-runtime'), '独立 mTLS 消费不能使用主仓生产软链接');
        putenv('PHPX_HOME=' . mqttMtlsPhpxHome($consumer));
        $buildOutput = successful([PHP_BINARY, ...mqttMtlsExtensionArgs(['pdo_pgsql'], true), $consumer . '/vendor/bin/type', $consumer . '/type-app.json'], $consumer);
        file_put_contents($consumer . '/build.log', $buildOutput);
        $report = json_decode((string) file_get_contents($consumer . '/build/mqtt/type-app.build.json'), true, 512, JSON_THROW_ON_ERROR);
        $production = array_keys($report['production-packages']);
        sort($production);
        expect($production === ['zoujingli/type-mqtt', 'zoujingli/type-orm', 'zoujingli/type-orm-pgsql', 'zoujingli/type-runtime'], '独立 MQTT mTLS 编译生产依赖不完整');
        foreach ($report['sources'] as $source) {
            expect(str_starts_with($source, $consumer . '/'), '独立 MQTT mTLS 仍编译主仓源码');
        }
        $command = nativeCommand($consumer . '/build/mqtt/type-app');
        if (PHP_OS_FAMILY === 'Darwin') {
            $runtime = $consumer . '/runtime';
            expect(mkdir($runtime, 0700), '无法创建 MQTT mTLS 无源码运行目录');
            copy($consumer . '/build/mqtt/type-app', $runtime . '/type-app');
            chmod($runtime . '/type-app', 0700);
            copy($report['runtime-profile']['ini'], $runtime . '/php.ini');
            $policy = ['sandbox-exec', '-f', $root . '/tests/fixtures/mqtt-no-source.sb'];
            $roles = ['ROOT_APP' => $root . '/app', 'ROOT_PLUGIN' => $root . '/plugin', 'ROOT_EXAMPLE' => $root . '/examples',
                'ROOT_VENDOR' => $root . '/vendor', 'APP' => $consumer . '/app', 'VENDOR' => $consumer . '/vendor',
                'COMPILER' => $consumer . '/build/mqtt/compiler', 'ROOT_COMPOSER' => $root . '/composer.json', 'COMPOSER' => $consumer . '/composer.json'];
            foreach ($roles as $role => $path) {
                array_push($policy, '-D', $role . '=' . $path);
            }
            $probe = 'foreach (array_slice($argv, 1) as $file) { if (@file_get_contents($file) !== false) { throw new RuntimeException("生产源码仍可读"); } } echo "source-denied\n";';
            expect(successful([...$policy, PHP_BINARY, '-n', '-r', $probe, $root . '/plugin/type-mqtt/src/Broker.php',
                $consumer . '/app/main.php', $consumer . '/vendor/autoload.php', $root . '/composer.json'], $runtime) === "source-denied\n", 'MQTT mTLS 无源码边界未生效');
            $command = [...$policy, 'env', 'PHPRC=' . $runtime . '/php.ini', 'PHP_INI_SCAN_DIR=', $runtime . '/type-app'];
        }
        $nativeTls = mqttMtlsPort();
        $nativeMtls = mqttMtlsPort();
        $nativeProcess = new Process([...$command, '--host=127.0.0.1', '--port=' . $nativeTls, '--mtls-port=' . $nativeMtls], $consumer, $environment);
        try {
            mqttMtlsWait($nativeProcess, $nativeTls, $certs['ca']);
            $nativeCert = mqttMtlsSocket($nativeMtls, $certs['ca'], $certs['client'], $certs['clientKey']);
            mqttMtlsWrite($nativeCert, mqttMtlsConnect('native-mtls', false));
            $nativeAck = mqttMtlsRead($nativeCert);
            expect(ord($nativeAck[0]) === 0x20 && ord($nativeAck[3]) === 0, '原生产物证书单独认证失败：' . bin2hex($nativeAck));
            fclose($nativeCert);
            $nativeResult = $nativeProcess->stop(5);
            expect($nativeResult->successful(), '原生产物 mTLS Broker 停止失败：' . $nativeResult->stderr);
        } finally {
            $nativeProcess->stop();
        }
        $nativeWssTls = mqttMtlsPort();
        $nativeWss = mqttMtlsPort();
        $nativeWssProcess = new Process([...$command, '--host=127.0.0.1', '--port=' . $nativeWssTls, '--wss-port=' . $nativeWss], $consumer, $environment);
        try {
            mqttMtlsWait($nativeWssProcess, $nativeWssTls, $certs['ca']);
            [$nativeSocket, $nativeHeader] = mqttMtlsWsHandshake($nativeWss, $certs['ca'], $certs['client'], $certs['clientKey']);
            expect(str_contains($nativeHeader, '101'), '原生产物 WSS mTLS 握手失败：' . $nativeHeader);
            mqttMtlsWsWrite($nativeSocket, mqttMtlsConnect('native-wss', false));
            $nativeWssAck = mqttMtlsWsRead($nativeSocket);
            expect(ord($nativeWssAck[0]) === 0x20 && ord($nativeWssAck[3]) === 0, '原生产物 WSS 证书单独认证失败：' . bin2hex($nativeWssAck));
            fclose($nativeSocket);
            $nativeWssResult = $nativeWssProcess->stop(5);
            expect($nativeWssResult->successful(), '原生产物 WSS mTLS Broker 停止失败：' . $nativeWssResult->stderr);
        } finally {
            $nativeWssProcess->stop();
        }
        mqttMtlsCrlRefresh($command, mqttMtlsPhp(), $root, $consumer, $environment, $certs);
        expect(isset($report['sha256']) && is_string($report['sha256']) && $report['sha256'] !== '', '缺少 MQTT mTLS 原生产物摘要');
        $verified['native'] = [
            'artifact' => $report['sha256'],
            'platform' => PHP_OS_FAMILY,
            'architecture' => (string) ($report['architecture'] ?? ''),
            'php' => (string) ($report['php'] ?? ''),
            'mtls' => true,
            'wss' => true,
            'revoke-https' => true,
            'crl-https-size-limit' => true,
        ];
    }
} finally {
    $process?->stop();
    $phpOk = $nativeOnly || isset($verified['wss-cross-tls']);
    $nativeOk = !$native || isset($verified['native']);
    if (is_dir($consumer) && $phpOk && $nativeOk) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($consumer, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (is_link($path) || $file->isFile()) {
                unlink($path);
            } else {
                rmdir($path);
            }
        }
        rmdir($consumer);
    }
}

echo json_encode(['ok' => true, 'verified' => $verified, 'native' => $native], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), "\n";
