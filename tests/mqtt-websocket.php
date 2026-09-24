<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Testing\Process;

/**
 * MQTT over WS/WSS：真实握手选择 mqtt、二进制流、跨消息半包、同进程 TCP 跨传输，
 * 以及独立 MQTT.js 客户端。WebSocket PING 不得延长 MQTT Keep Alive。
 */

function mqttWsField(string $value): string
{
    return pack('n', strlen($value)) . $value;
}

/** 独立编码 MQTT 可变长度字段，避免测试与生产编解码器共享实现缺陷；输入须在协议范围内。 */
function mqttWsLength(int $value): string
{
    $bytes = '';
    do {
        $byte = $value % 128;
        $value = intdiv($value, 128);
        $bytes .= chr($value > 0 ? $byte | 128 : $byte);
    } while ($value > 0);
    return $bytes;
}

/** 构造 MQTT 3.1.1 或 5 的测试 CONNECT 报文；keepalive 单位为秒。 */
function mqttWsConnect(int $version, string $id, int $keepalive = 10): string
{
    $payload = mqttWsField('MQTT') . chr($version) . "\xc2" . pack('n', $keepalive)
        . ($version === 5 ? "\0" : '') . mqttWsField($id) . mqttWsField('example') . mqttWsField('mqtt-test-secret');
    return "\x10" . mqttWsLength(strlen($payload)) . $payload;
}

/** 构造 MQTT 5 的 QoS 0 订阅报文，使用测试固定报文标识符。 */
function mqttWsSubscribe(string $topic): string
{
    $body = "\x00\x01\x00" . mqttWsField($topic) . "\x00";
    return "\x82" . mqttWsLength(strlen($body)) . $body;
}

/** 构造无属性的 MQTT 5 QoS 0 发布报文，用于核对 WebSocket 传输后的字节。 */
function mqttWsPublish(string $topic, string $payload): string
{
    $body = mqttWsField($topic) . "\0" . $payload;
    return "\x30" . mqttWsLength(strlen($body)) . $body;
}

/** 获取回环空闲端口并关闭临时监听；返回值只用于本轮测试，不保留端口占用。 */
function mqttWsPort(): int
{
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect(is_resource($listener), '无法分配临时端口');
    $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
    fclose($listener);
    return $port;
}

/** @return list<string> */
function mqttWsPhp(): array
{
    $command = [PHP_BINARY, ...mqttWsExtensionArgs(['mysqlnd', 'pdo_pgsql', 'swoole'], true)];
    expect(successful([...$command, '-r', 'echo extension_loaded("swoole") ? "yes" : "no";']) === 'yes', 'WebSocket MQTT 测试需要匹配 SDK 的 Swoole 模块');
    return $command;
}

/**
 * @param list<string> $extensions
 * @return list<string>
 */
function mqttWsExtensionArgs(array $extensions, bool $forChild = false): array
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

/** 按指定操作码和 FIN 位构造带随机客户端掩码的 WebSocket 帧。 */
function mqttWsFrame(string $payload, int $opcode, bool $fin = true): string
{
    $length = strlen($payload);
    $header = chr(($fin ? 0x80 : 0x00) | $opcode);
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
 * 读取一个测试 WebSocket 数据帧载荷，跳过 ping/pong，关闭帧或截断导致失败；连接仍归调用者。
 *
 * @param resource $socket
 */
function mqttWsRead(mixed $socket): string
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
        return mqttWsRead($socket);
    }
    return $payload;
}

/**
 * @param list<string> $headers
 * @return array{0: mixed, 1: string}
 */
function mqttWsHandshake(int $port, array $headers = [], bool $tls = false, ?string $ca = null): array
{
    $scheme = $tls ? 'tls' : 'tcp';
    $options = $tls ? ['ssl' => ['cafile' => $ca, 'verify_peer' => true, 'verify_peer_name' => true,
        'peer_name' => '127.0.0.1', 'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT]] : [];
    $socket = @stream_socket_client($scheme . '://127.0.0.1:' . $port, $errno, $error, 5, STREAM_CLIENT_CONNECT, stream_context_create($options));
    expect(is_resource($socket), '无法连接 MQTT WebSocket：' . $errno . ' ' . $error);
    stream_set_timeout($socket, 5);
    $key = base64_encode(random_bytes(16));
    $wire = "GET /mqtt HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
        . "Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n";
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

/**
 * 将 MQTT 报文封装为客户端二进制帧并要求一次完整写出；连接仍归调用者。
 *
 * @param resource $socket
 */
function mqttWsWrite(mixed $socket, string $packet): void
{
    $frame = mqttWsFrame($packet, 0x2);
    expect(fwrite($socket, $frame) === strlen($frame), 'MQTT WebSocket 报文未写完');
}

/**
 * 建立 5 秒超时的测试 TCP/TLS 连接；TLS 验证指定 CA 和主机名，成功连接由调用者关闭。
 *
 * @return resource
 */
function mqttTcpSocket(int $port, ?string $certificate = null): mixed
{
    $options = $certificate === null ? [] : ['ssl' => ['cafile' => $certificate, 'verify_peer' => true,
        'verify_peer_name' => true, 'peer_name' => '127.0.0.1',
        'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT]];
    $socket = @stream_socket_client(($certificate === null ? 'tcp' : 'tls') . '://127.0.0.1:' . $port, $errno, $error, 5, STREAM_CLIENT_CONNECT, stream_context_create($options));
    expect(is_resource($socket), 'MQTT TCP 连接失败：' . $errno . ' ' . $error);
    stream_set_timeout($socket, 5);
    return $socket;
}

/**
 * 读取完整 MQTT 固定头、剩余长度及正文，截断时失败；不关闭借用的连接。
 *
 * @param resource $socket
 */
function mqttTcpRead(mixed $socket): string
{
    $first = fread($socket, 1);
    expect($first !== false && $first !== '', 'MQTT TCP 首字节读取失败');
    $wire = $first;
    $length = 0;
    $multiplier = 1;
    do {
        $byte = fread($socket, 1);
        expect($byte !== false && $byte !== '', 'MQTT TCP 长度不完整');
        $wire .= $byte;
        $value = ord($byte);
        $length += ($value & 127) * $multiplier;
        $multiplier *= 128;
    } while (($value & 128) !== 0);
    while ($length > 0) {
        $chunk = fread($socket, $length);
        expect($chunk !== false && $chunk !== '', 'MQTT TCP 正文不完整');
        $wire .= $chunk;
        $length -= strlen($chunk);
    }
    return $wire;
}

/**
 * 要求测试 MQTT 报文一次完整写出，短写视为失败；不转移连接所有权。
 *
 * @param resource $socket
 */
function mqttTcpWrite(mixed $socket, string $bytes): void
{
    expect(fwrite($socket, $bytes) === strlen($bytes), 'MQTT TCP 写入失败');
}

/** @param array<string, string> $environment */
function mqttWsWait(Process $process, int $port, ?string $certificate = null): void
{
    $ready = false;
    $until = microtime(true) + 15;
    do {
        expect($process->running(), 'MQTT WebSocket 进程提前退出：' . $process->stderr() . $process->stdout());
        try {
            $probe = mqttTcpSocket($port, $certificate);
            mqttTcpWrite($probe, mqttWsConnect(5, 'ready'));
            $ack = mqttTcpRead($probe);
            expect(ord($ack[0]) === 0x20 && ord($ack[3]) === 0, 'MQTT 就绪 CONNECT 失败：' . bin2hex($ack));
            fclose($probe);
            $ready = true;
        } catch (RuntimeException) {
            usleep(20000);
        }
    } while (!$ready && microtime(true) < $until);
    expect($ready, 'MQTT 未通过 TCP CONNECT 就绪：' . $process->stderr());
}

/** @param list<string> $arguments @param array<string, string> $environment */
function mqttWsBroker(array $command, string $directory, array $environment, array $arguments): Process
{
    return new Process([...$command, ...$arguments], $directory, $environment);
}

/** @param list<string> $command */
function mqttJsClient(array $command, string $directory): void
{
    $last = '';
    for ($attempt = 0; $attempt < 2; $attempt++) {
        try {
            echo successful($command, $directory);
            return;
        } catch (RuntimeException $error) {
            $last = $error->getMessage();
        }
    }
    expect(false, $last);
}

/** 缺少运行扩展时以显式扩展参数重启一次测试，并通过环境哨兵防止循环。 */
function mqttWsReexec(): void
{
    if (getenv('MQTT_WS_REEXEC') === '1') {
        return;
    }
    $arguments = mqttWsExtensionArgs(['mysqlnd', 'pdo_pgsql', 'swoole']);
    if ($arguments === []) {
        return;
    }
    $command = [PHP_BINARY, ...$arguments, __FILE__, ...array_slice($GLOBALS['argv'], 1)];
    $environment = getenv();
    expect(is_array($environment), '无法读取测试进程环境');
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, null, array_replace($environment, ['MQTT_WS_REEXEC' => '1']));
    expect(is_resource($process), '无法以运行扩展重启 MQTT WebSocket 测试');
    exit(proc_close($process));
}

mqttWsReexec();

/** 锁定 PHPX 源码含异常策略头，构建目录中的 PHPX 可能仍是旧头文件。 */
function mqttWsPhpxHome(string $consumer): string
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

$root = dirname(__DIR__);
$nativeOnly = in_array('--native-only', $argv, true);
$native = in_array('--native', $argv, true) || $nativeOnly;
$durableOnly = in_array('--durable-only', $argv, true);
$consumer = $root . '/build/mqtt-websocket-' . bin2hex(random_bytes(5));
expect(mkdir($consumer, 0700, true), '无法创建 MQTT WebSocket 验收目录');
file_put_contents($consumer . '/package.json', json_encode(['name' => 'type-mqtt-websocket-client', 'private' => true,
    'dependencies' => ['mqtt' => '5.15.0']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
successful(['npm', 'install', '--ignore-scripts', '--no-audit', '--no-fund'], $consumer);

$certificateConfiguration = $consumer . '/certificate.cnf';
file_put_contents($certificateConfiguration, "[req]\ndistinguished_name=dn\nx509_extensions=server\n[dn]\n[server]\nsubjectAltName=IP:127.0.0.1\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\nextendedKeyUsage=serverAuth\n");
$certificateOptions = ['config' => $certificateConfiguration, 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
$key = openssl_pkey_new($certificateOptions);
$request = openssl_csr_new(['commonName' => '127.0.0.1'], $key, $certificateOptions);
$certificateObject = openssl_csr_sign($request, null, $key, 1, $certificateOptions);
expect($key !== false && $request !== false && $certificateObject !== false, '无法生成 MQTT WebSocket 测试证书');
expect(openssl_x509_export($certificateObject, $certificatePem) && $certificatePem !== '', '无法导出 MQTT WebSocket 测试证书');
expect(openssl_pkey_export($key, $privatePem, null, $certificateOptions) && $privatePem !== '', '无法导出 MQTT WebSocket 测试私钥');
file_put_contents($consumer . '/certificate.pem', $certificatePem);
file_put_contents($consumer . '/private.pem', $privatePem);
chmod($consumer . '/private.pem', 0600);

$php = $nativeOnly ? [PHP_BINARY] : mqttWsPhp();
$launcher = [...$php, '-r', 'require "vendor/autoload.php"; require "examples/mqtt/main.php"; main($argc, $argv);', '--'];
$environment = getenv();
expect(is_array($environment), '无法读取 MQTT WebSocket 测试环境');
$environment['MQTT_PASSWORD'] = 'mqtt-test-secret';
$environment['MQTT_CERTIFICATE'] = '';
$environment['MQTT_PRIVATE_KEY'] = '';
$environment['MQTT_PRIVATE_KEY_PASSPHRASE'] = '';
$environment['MQTT_WORKER_COMMAND'] = '';
putenv('MQTT_PASSWORD=mqtt-test-secret');

$verified = [];
try {
    if (!$durableOnly && !$nativeOnly) {
        $tcpPort = mqttWsPort();
        $wsPort = mqttWsPort();
        $process = mqttWsBroker($launcher, $root, $environment, [
            '--host=127.0.0.1', '--port=' . $tcpPort, '--plaintext', '--ws-port=' . $wsPort,
            '--allowed-origins=http://allowed.test',
        ]);
        try {
            mqttWsWait($process, $tcpPort);

            [$denied, $deniedHeader] = mqttWsHandshake($wsPort);
            expect(str_contains($deniedHeader, '101'), '未提供子协议仍应先由原生返回 101：' . $deniedHeader);
            $deniedClose = false;
            try {
                mqttWsRead($denied);
            } catch (RuntimeException $error) {
                $deniedClose = str_contains($error->getMessage(), '1002');
            }
            fclose($denied);
            expect($deniedClose, '未提供 mqtt 子协议没有按 1002 关闭');

            [$wrong, $wrongHeader] = mqttWsHandshake($wsPort, ['Sec-WebSocket-Protocol: chat']);
            expect(str_contains($wrongHeader, '101'), '错误子协议仍应返回 101');
            $wrongClose = false;
            try {
                mqttWsRead($wrong);
            } catch (RuntimeException $error) {
                $wrongClose = str_contains($error->getMessage(), '1002');
            }
            fclose($wrong);
            expect($wrongClose, '错误子协议没有按 1002 关闭');

            [$blocked, $blockedHeader] = mqttWsHandshake($wsPort, [
                'Sec-WebSocket-Protocol: mqtt', 'Origin: http://denied.test',
            ]);
            expect(str_contains($blockedHeader, '101'), '拒绝 Origin 仍应返回 101');
            $originClose = false;
            try {
                mqttWsRead($blocked);
            } catch (RuntimeException $error) {
                $originClose = str_contains($error->getMessage(), '1008');
            }
            fclose($blocked);
            expect($originClose, '不匹配 Origin 没有按 1008 关闭');

            [$socket, $header] = mqttWsHandshake($wsPort, ['Sec-WebSocket-Protocol: mqtt']);
            expect(str_contains($header, '101') && str_contains(strtolower($header), 'sec-websocket-protocol: mqtt'), '握手未选择 mqtt 子协议：' . $header);
            $connect = mqttWsConnect(5, 'ws-binary');
            mqttWsWrite($socket, substr($connect, 0, 8));
            mqttWsWrite($socket, substr($connect, 8));
            $ack = mqttWsRead($socket);
            expect(ord($ack[0]) === 0x20 && ord($ack[3]) === 0, '跨 WebSocket 消息的 CONNECT 半包未重组：' . bin2hex($ack));

            $subscribe = mqttWsSubscribe('example/ws-multi');
            fwrite($socket, mqttWsFrame($subscribe . "\xc0\x00", 0x2));
            $combined = mqttWsRead($socket);
            expect(str_contains($combined, "\xd0\x00") || $combined[0] === "\x90", '一帧多包未解析：' . bin2hex($combined));
            if (!str_contains($combined, "\xd0\x00")) {
                expect(mqttWsRead($socket) === "\xd0\x00", '一帧内的 PINGREQ 没有 PINGRESP');
            }

            fwrite($socket, mqttWsFrame('not-mqtt', 0x1));
            $textClosed = false;
            try {
                mqttWsRead($socket);
            } catch (RuntimeException $error) {
                $textClosed = str_contains($error->getMessage(), '1003');
            }
            fclose($socket);
            expect($textClosed, '文本帧没有按 1003 关闭');

            [$alive, $aliveHeader] = mqttWsHandshake($wsPort, ['Sec-WebSocket-Protocol: mqtt']);
            expect(str_contains($aliveHeader, '101'), 'Keep Alive 握手失败');
            mqttWsWrite($alive, mqttWsConnect(5, 'ws-keepalive', 1));
            $aliveAck = mqttWsRead($alive);
            expect(ord($aliveAck[0]) === 0x20, 'Keep Alive CONNECT 失败');
            stream_set_timeout($alive, 1);
            $keepAliveExpired = false;
            $deadline = microtime(true) + 3.0;
            while (microtime(true) < $deadline) {
                fwrite($alive, mqttWsFrame('', 0x9));
                try {
                    $message = mqttWsRead($alive);
                    if ($message !== '' && ord($message[0]) === 0xe0 && isset($message[2]) && ord($message[2]) === 0x8d) {
                        $keepAliveExpired = true;
                        break;
                    }
                } catch (RuntimeException) {
                    $keepAliveExpired = true;
                    break;
                }
                usleep(150000);
            }
            fclose($alive);
            expect($keepAliveExpired, 'WebSocket PING 延长了 MQTT Keep Alive');

            $subscriber = mqttTcpSocket($tcpPort);
            mqttTcpWrite($subscriber, mqttWsConnect(5, 'tcp-sub'));
            expect(ord(mqttTcpRead($subscriber)[0]) === 0x20, 'TCP 订阅者 CONNECT 失败');
            mqttTcpWrite($subscriber, mqttWsSubscribe('example/cross'));
            expect(ord(mqttTcpRead($subscriber)[0]) === 0x90, 'TCP 订阅失败');
            [$publisher, $publisherHeader] = mqttWsHandshake($wsPort, ['Sec-WebSocket-Protocol: mqtt']);
            expect(str_contains($publisherHeader, '101'), '跨传输 WS 发布者握手失败');
            mqttWsWrite($publisher, mqttWsConnect(5, 'ws-pub'));
            expect(ord(mqttWsRead($publisher)[0]) === 0x20, '跨传输 WS 发布者 CONNECT 失败');
            $publish = mqttWsPublish('example/cross', 'cross');
            mqttWsWrite($publisher, $publish);
            $delivered = mqttTcpRead($subscriber);
            expect(str_contains($delivered, 'cross'), 'WS 发布没有到达 TCP 订阅者：' . bin2hex($delivered));

            [$wsSub, $wsSubHeader] = mqttWsHandshake($wsPort, ['Sec-WebSocket-Protocol: mqtt']);
            expect(str_contains($wsSubHeader, '101'), '反向跨传输握手失败');
            mqttWsWrite($wsSub, mqttWsConnect(5, 'ws-sub'));
            expect(ord(mqttWsRead($wsSub)[0]) === 0x20, '反向 WS 订阅者 CONNECT 失败');
            mqttWsWrite($wsSub, mqttWsSubscribe('example/cross-back'));
            $subAck = mqttWsRead($wsSub);
            expect(ord($subAck[0]) === 0x90, '反向 WS 订阅失败：' . bin2hex($subAck));
            $tcpPub = mqttTcpSocket($tcpPort);
            mqttTcpWrite($tcpPub, mqttWsConnect(5, 'tcp-pub'));
            expect(ord(mqttTcpRead($tcpPub)[0]) === 0x20, '反向 TCP 发布者 CONNECT 失败');
            $back = mqttWsPublish('example/cross-back', 'back');
            mqttTcpWrite($tcpPub, $back);
            $backDelivered = mqttWsRead($wsSub);
            expect(str_contains($backDelivered, 'back'), 'TCP 发布没有到达 WS 订阅者：' . bin2hex($backDelivered));
            fclose($subscriber);
            fclose($publisher);
            fclose($wsSub);
            fclose($tcpPub);

            mqttJsClient(['node', $root . '/tests/mqtt-websocket-client.mjs', $consumer, (string) $wsPort, 'ws'], $consumer);

            $verified['ws'] = ['subprotocol' => true, 'origin' => true, 'binary' => true, 'cross-tcp' => true, 'mqtt.js' => '3.1.1/5.0-qos0'];
            $result = $process->stop(5);
            expect($result->successful() && $result->stderr === '', '明文 WS Broker 停止失败：' . $result->stderr);
        } finally {
            $process->stop();
        }

        $tlsPort = mqttWsPort();
        $wssPort = mqttWsPort();
        $tlsEnvironment = $environment;
        $tlsEnvironment['MQTT_CERTIFICATE'] = $consumer . '/certificate.pem';
        $tlsEnvironment['MQTT_PRIVATE_KEY'] = $consumer . '/private.pem';
        $tlsProcess = mqttWsBroker($launcher, $root, $tlsEnvironment, [
            '--host=127.0.0.1', '--port=' . $tlsPort, '--wss-port=' . $wssPort,
            '--allowed-origins=http://allowed.test',
        ]);
        try {
            mqttWsWait($tlsProcess, $tlsPort, $consumer . '/certificate.pem');
            [$wss, $wssHeader] = mqttWsHandshake($wssPort, ['Sec-WebSocket-Protocol: mqtt'], true, $consumer . '/certificate.pem');
            expect(str_contains($wssHeader, '101'), 'WSS 握手失败：' . $wssHeader);
            mqttWsWrite($wss, mqttWsConnect(5, 'wss-client'));
            $wssAck = mqttWsRead($wss);
            expect(ord($wssAck[0]) === 0x20 && ord($wssAck[3]) === 0, 'WSS CONNECT 失败：' . bin2hex($wssAck));
            $tlsSub = mqttTcpSocket($tlsPort, $consumer . '/certificate.pem');
            mqttTcpWrite($tlsSub, mqttWsConnect(5, 'tls-sub'));
            expect(ord(mqttTcpRead($tlsSub)[0]) === 0x20, 'TLS 订阅者 CONNECT 失败');
            mqttTcpWrite($tlsSub, mqttWsSubscribe('example/wss-cross'));
            expect(ord(mqttTcpRead($tlsSub)[0]) === 0x90, 'TLS 订阅失败');
            $wssPublish = mqttWsPublish('example/wss-cross', 'wss');
            mqttWsWrite($wss, $wssPublish);
            $wssDelivered = mqttTcpRead($tlsSub);
            expect(str_contains($wssDelivered, 'wss'), 'WSS 发布没有到达 TLS 订阅者：' . bin2hex($wssDelivered));
            fclose($wss);
            fclose($tlsSub);
            mqttJsClient(['node', $root . '/tests/mqtt-websocket-client.mjs', $consumer, (string) $wssPort, 'wss', $consumer . '/certificate.pem'], $consumer);
            $verified['wss'] = ['handshake' => true, 'cross-tls' => true, 'mqtt.js' => '3.1.1/5.0-qos0'];
            $tlsResult = $tlsProcess->stop(5);
            expect($tlsResult->successful() && $tlsResult->stderr === '', 'WSS Broker 停止失败：' . $tlsResult->stderr);
        } finally {
            $tlsProcess->stop();
        }
    } else {
        $reason = $nativeOnly ? 'native-only' : 'durable-only';
        $verified['ws'] = ['skipped' => $reason];
        $verified['wss'] = ['skipped' => $reason];
    }

    if ($nativeOnly) {
        $verified['durable'] = ['skipped' => 'native-only'];
    } elseif (!extension_loaded('pdo_pgsql')) {
        expect(!$durableOnly, '持久 MQTT WebSocket QoS 需要当前 PHP 加载 pdo_pgsql');
        $verified['durable'] = ['skipped' => 'pdo_pgsql'];
    } else {
        require_once $root . '/tests/native-database.php';
        require_once $root . '/tests/postgres-sync.php';
        ini_set('zend.exception_ignore_args', '1');
        $tools = NativeDatabase::tools('pgsql', (string) (getenv('TYPE_PGSQL_TOOLS') ?: $root . '/.cache/macos-libpq/17.11'));
        $database = new NativeDatabase($consumer . '/primary', 'pgsql', $tools);
        $sync = null;
        $durableProcess = null;
        try {
            $sync = new PostgresSync($database, $consumer . '/standby', $tools);
            $durableEnvironment = array_replace($environment, $database->environment());
            $durableEnvironment['MQTT_CERTIFICATE'] = '';
            $durableEnvironment['MQTT_PRIVATE_KEY'] = '';
            $durableEnvironment['MQTT_WORKER_COMMAND'] = json_encode($launcher, JSON_THROW_ON_ERROR);
            $install = mqttWsBroker($launcher, $root, $durableEnvironment, ['--install-store']);
            try {
                $installed = $install->wait(12);
                expect($installed->successful() && $installed->stderr === '', 'MQTT WebSocket 存储安装失败：' . $installed->stderr . $installed->stdout);
                $proof = json_decode(trim($installed->stdout), true, 32, JSON_THROW_ON_ERROR);
                expect(($proof['state'] ?? '') === 'committed' && ($proof['released'] ?? false), 'MQTT WebSocket 存储安装未取得同步证明：' . $installed->stdout);
            } finally {
                $install->stop();
            }
            $durableTcp = mqttWsPort();
            $durableWs = mqttWsPort();
            $durableProcess = mqttWsBroker($launcher, $root, $durableEnvironment, [
                '--host=127.0.0.1', '--port=' . $durableTcp, '--plaintext', '--ws-port=' . $durableWs,
            ]);
            mqttWsWait($durableProcess, $durableTcp);
            mqttJsClient(['node', $root . '/tests/mqtt-websocket-client.mjs', $consumer, (string) $durableWs, 'ws', 'qos12'], $consumer);
            $durableResult = $durableProcess->stop(8);
            expect($durableResult->successful() && $durableResult->stderr === '', '持久 WS Broker 停止失败：' . $durableResult->stderr);
            $verified['durable'] = ['mqtt.js' => '3.1.1-qos1/5.0-qos2', 'store' => true];
        } finally {
            $durableProcess?->stop();
            $sync?->close();
            $database->close();
        }
    }

    if ($native) {
        expect(mkdir($consumer . '/app', 0700, true), '无法创建独立消费者目录');
        $toolchain = json_decode(file_get_contents($root . '/toolchain.lock.json'), true, 512, JSON_THROW_ON_ERROR);
        $repositories = [];
        foreach (['type-mqtt', 'type-runtime', 'type-orm', 'type-orm-pgsql', 'type-build'] as $package) {
            $repositories[] = ['type' => 'path', 'url' => '../../plugin/' . $package,
                'options' => ['symlink' => false, 'versions' => ['zoujingli/' . $package => '1.0.x-dev']]];
        }
        $composer = ['name' => 'type-tests/mqtt-websocket-consumer', 'type' => 'project', 'license' => 'Apache-2.0',
            'require' => ['zoujingli/type-mqtt' => '~1.0.0@dev', 'zoujingli/type-orm-pgsql' => '~1.0.0@dev'],
            'require-dev' => ['zoujingli/type-build' => '~1.0.0@dev', 'swoole/typephp' => $toolchain['typephp']['version'], 'swoole/phpx' => $toolchain['phpx']['version']],
            'autoload' => ['classmap' => ['app/main.php']], 'repositories' => $repositories,
            'minimum-stability' => 'dev', 'prefer-stable' => true, 'config' => ['allow-plugins' => false]];
        file_put_contents($consumer . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        copy($root . '/examples/mqtt/main.php', $consumer . '/app/main.php');
        copy($root . '/toolchain.lock.json', $consumer . '/toolchain.lock.json');
        $module = (string) (getenv('TYPE_SWOOLE_MODULE') ?: ini_get('extension_dir') . '/swoole.so');
        expect(is_file($module), '原生 WebSocket 编译需要匹配 SDK 的 Swoole 模块');
        expect(copy($module, $consumer . '/swoole.so'), '无法固定 Swoole 运行模块');
        $hash = hash_file('sha256', $consumer . '/swoole.so');
        expect(is_string($hash) && $hash !== '', '无法计算 Swoole 模块摘要');
        file_put_contents($consumer . '/type-app.json', json_encode([
            'name' => 'type-mqtt-websocket', 'entry' => 'app/main.php', 'sources' => ['app/main.php'],
            'output' => 'build/mqtt/type-app', 'build-directory' => 'build/mqtt/compiler',
            'runtime' => [PHP_OS_FAMILY => [
                'extensions' => ['mysqlnd', 'swoole'],
                'modules' => ['swoole' => ['file' => 'swoole.so', 'sha256' => $hash]],
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        successful([getenv('COMPOSER_BINARY') ?: 'composer', 'install', '--no-interaction', '--no-scripts', '--no-plugins', '--no-progress', '--ignore-platform-req=ext-pdo_pgsql'], $consumer);
        expect(!is_link($consumer . '/vendor/zoujingli/type-mqtt') && !is_link($consumer . '/vendor/zoujingli/type-runtime'), '独立 WebSocket 消费不能使用主仓生产软链接');
        putenv('PHPX_HOME=' . mqttWsPhpxHome($consumer));
        $buildOutput = successful([PHP_BINARY, ...mqttWsExtensionArgs(['pdo_pgsql'], true), $consumer . '/vendor/bin/type', $consumer . '/type-app.json'], $consumer);
        file_put_contents($consumer . '/build.log', $buildOutput);
        $report = json_decode(file_get_contents($consumer . '/build/mqtt/type-app.build.json'), true, 512, JSON_THROW_ON_ERROR);
        $production = array_keys($report['production-packages']);
        sort($production);
        expect($production === ['zoujingli/type-mqtt', 'zoujingli/type-orm', 'zoujingli/type-orm-pgsql', 'zoujingli/type-runtime'], '独立 MQTT WebSocket 编译生产依赖不完整');
        foreach ($report['sources'] as $source) {
            expect(str_starts_with($source, $consumer . '/'), '独立 MQTT WebSocket 仍编译主仓源码');
        }
        $command = nativeCommand($consumer . '/build/mqtt/type-app');
        if (PHP_OS_FAMILY === 'Darwin') {
            $runtime = $consumer . '/runtime';
            expect(mkdir($runtime, 0700), '无法创建 MQTT WebSocket 无源码运行目录');
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
                $consumer . '/app/main.php', $consumer . '/vendor/autoload.php', $root . '/composer.json'], $runtime) === "source-denied\n", 'MQTT WebSocket 无源码边界未生效');
            $command = [...$policy, 'env', 'PHPRC=' . $runtime . '/php.ini', 'PHP_INI_SCAN_DIR=', $runtime . '/type-app'];
        }
        $nativeTcp = mqttWsPort();
        $nativeWs = mqttWsPort();
        $nativeEnv = $environment;
        $nativeProcess = mqttWsBroker($command, $consumer, $nativeEnv, [
            '--host=127.0.0.1', '--port=' . $nativeTcp, '--plaintext', '--ws-port=' . $nativeWs,
        ]);
        try {
            mqttWsWait($nativeProcess, $nativeTcp);
            [$nativeSocket, $nativeHeader] = mqttWsHandshake($nativeWs, ['Sec-WebSocket-Protocol: mqtt']);
            expect(str_contains($nativeHeader, '101'), '原生产物 WS 握手失败');
            mqttWsWrite($nativeSocket, mqttWsConnect(5, 'native-ws'));
            $nativeAck = mqttWsRead($nativeSocket);
            expect(ord($nativeAck[0]) === 0x20 && ord($nativeAck[3]) === 0, '原生产物 WS CONNECT 失败');
            fclose($nativeSocket);
            $nativeResult = $nativeProcess->stop(5);
            expect($nativeResult->successful(), '原生产物 Broker 停止失败：' . $nativeResult->stderr);
            expect(isset($report['sha256']) && is_string($report['sha256']) && $report['sha256'] !== '', '缺少 MQTT WebSocket 原生产物摘要');
            $verified['native'] = [
                'artifact' => $report['sha256'],
                'platform' => PHP_OS_FAMILY,
                'architecture' => (string) ($report['architecture'] ?? ''),
                'php' => (string) ($report['php'] ?? ''),
            ];
        } finally {
            $nativeProcess->stop();
        }
    }
} finally {
    // 保留失败现场时不删除；成功则回收独立 npm 与证书。
    if (is_dir($consumer) && ($verified['ws'] ?? null) !== null && ($verified['wss'] ?? null) !== null
        && ($verified['durable'] ?? null) !== null && (!$native || isset($verified['native']))) {
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
