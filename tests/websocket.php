<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Core\WebSocket\Client;
use Type\Core\WebSocket\Server;
use Type\Runtime\ResourceBudget;
use Type\Runtime\TaskException;
use Type\Testing\Process;

/**
 * 有界 WebSocket 会话验收：真实握手、文本与二进制双向、分片重组、控制帧隔离、
 * 单条上限拒绝、HTTP 共存、子协议与 Origin、WSS/TLS 1.2/1.3，以及慢对端下消息
 * 作用域（租约）在连接仍存活时已经关闭。
 */

/** 手工组装一条客户端帧；掩码为客户端必选项，用于验证原生解掩码与分片重组。 */
function wsFrame(string $payload, int $opcode, bool $fin = true): string
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

/** 借助回环临时监听获取空闲端口；返回前关闭监听，因此端口不被预留。 */
function wsPort(): int
{
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect(is_resource($listener), '无法分配测试端口');
    $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
    fclose($listener);
    return $port;
}

/** @param array<string, mixed> $options @return array{process: Process, port: int, lease: string} */
function wsStart(array $options = [], bool $lease = false): array
{
    $root = dirname(__DIR__);
    $port = wsPort();
    $marker = $lease ? $root . '/build/websocket-lease-' . bin2hex(random_bytes(4)) : '';
    if ($marker !== '') {
        expect(file_put_contents($marker, 'idle') !== false, '无法准备租约标记');
    }
    $environment = getenv();
    expect(is_array($environment), '无法读取测试进程环境');
    unset($environment['PHPRC'], $environment['PHP_INI_SCAN_DIR']);
    $environment['TYPE_WS_PORT'] = (string) $port;
    $environment['TYPE_WS_OPTIONS'] = json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $environment['TYPE_WS_LEASE'] = $marker;
    $process = new Process(
        [...wsPhp(), $root . '/tests/fixtures/websocket-echo.php', $root . '/vendor/autoload.php'],
        $root,
        $environment
    );
    wsReady($process, $port, isset($options['open_ssl']) && $options['open_ssl'] === true ? (string) $options['ssl_cert_file'] : '');
    return ['process' => $process, 'port' => $port, 'lease' => $marker];
}

/** 在 10 秒轮询预算内检查进程存活及 TCP/TLS 握手；探测连接立即关闭，不代表业务初始化完成。 */
function wsReady(Process $process, int $port, string $cafile = ''): void
{
    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline) {
        expect($process->running(), 'WebSocket 服务提前退出：' . $process->stderr());
        if ($cafile === '') {
            $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.2);
        } else {
            $probe = @stream_socket_client(
                'ssl://127.0.0.1:' . $port,
                $errno,
                $error,
                0.2,
                STREAM_CLIENT_CONNECT,
                wsTlsContext($cafile)
            );
        }
        if (is_resource($probe)) {
            fclose($probe);
            return;
        }
        usleep(20000);
    }
    throw new RuntimeException('WebSocket 服务未就绪：' . $process->stderr());
}

/**
 * 创建验证测试 CA 和回环主机名的 TLS 客户端上下文。
 *
 * @return resource 供测试连接借用的流上下文。
 */
function wsTlsContext(string $cafile, int $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)
{
    return stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => '127.0.0.1',
            'cafile' => $cafile,
            'crypto_method' => $crypto,
        ],
    ]);
}

/** @return array{certificate: string, key: string, directory: string} */
function wsCertificate(): array
{
    $directory = dirname(__DIR__) . '/build/websocket-tls-' . bin2hex(random_bytes(4));
    expect(mkdir($directory, 0700, true), '无法创建 WebSocket 证书目录');
    $configuration = $directory . '/certificate.cnf';
    file_put_contents($configuration, "[req]\ndistinguished_name=dn\nx509_extensions=server\n[dn]\n[server]\nsubjectAltName=IP:127.0.0.1\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\n");
    $options = ['config' => $configuration, 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
    $key = openssl_pkey_new($options);
    $request = openssl_csr_new(['commonName' => '127.0.0.1'], $key, $options);
    $certificateObject = openssl_csr_sign($request, null, $key, 1, $options);
    expect($key !== false && $request !== false && $certificateObject !== false, '无法生成 WebSocket 测试证书');
    expect(openssl_x509_export($certificateObject, $certificatePem) && $certificatePem !== '', '无法导出 WebSocket 测试证书');
    expect(openssl_pkey_export($key, $privatePem, null, $options) && $privatePem !== '', '无法导出 WebSocket 测试私钥');
    $certificate = $directory . '/certificate.pem';
    $private = $directory . '/private.pem';
    file_put_contents($certificate, $certificatePem);
    file_put_contents($private, $privatePem);
    chmod($private, 0600);
    return ['certificate' => $certificate, 'key' => $private, 'directory' => $directory];
}

/** 删除本轮拥有的临时目录及其内容，不跟随符号链接；调用者必须先确认目录归属。 */
function wsCleanup(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $path = $file->getPathname();
        if (is_link($path) || $file->isFile()) {
            unlink($path);
        } else {
            rmdir($path);
        }
    }
    rmdir($directory);
}

/** 用裸 socket 完成一次标准握手，返回连接资源与响应头。 */
function wsHandshake(int $port, string $path = '/ws', array $headers = [], bool $tls = false, string $cafile = '')
{
    if ($tls) {
        $connection = stream_socket_client(
            'ssl://127.0.0.1:' . $port,
            $errno,
            $error,
            5,
            STREAM_CLIENT_CONNECT,
            wsTlsContext($cafile)
        );
    } else {
        $connection = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 5);
    }
    expect(is_resource($connection), '无法连接 WebSocket 服务');
    stream_set_timeout($connection, 5);
    $key = base64_encode(random_bytes(16));
    $wire = "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
        . "Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n";
    foreach ($headers as $header) {
        $wire .= $header . "\r\n";
    }
    $wire .= "\r\n";
    expect(fwrite($connection, $wire) === strlen($wire), 'WebSocket 握手请求未写完');
    $header = '';
    while (!feof($connection)) {
        $line = fgets($connection, 1024);
        if ($line === false) {
            break;
        }
        $header .= $line;
        if ($line === "\r\n") {
            break;
        }
    }
    return [$connection, $header, $key];
}

/** 发送一次独立 HTTP 或 HTTPS 请求，返回原始响应并关闭连接；HTTPS 使用指定测试 CA。 */
function wsHttp(int $port, string $path, bool $tls = false, string $cafile = ''): string
{
    if ($tls) {
        $http = stream_socket_client(
            'ssl://127.0.0.1:' . $port,
            $errno,
            $error,
            5,
            STREAM_CLIENT_CONNECT,
            wsTlsContext($cafile)
        );
    } else {
        $http = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 5);
    }
    expect(is_resource($http), '无法连接同一监听的 HTTP 入口');
    stream_set_timeout($http, 5);
    $request = "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nConnection: close\r\n\r\n";
    expect(fwrite($http, $request) === strlen($request), 'HTTP 请求未写完');
    $response = (string) stream_get_contents($http);
    fclose($http);
    return $response;
}

/** 最多等待 5 秒观察租约文件变为 closed；超时返回最后内容，由调用方断言清理结果。 */
function wsLease(string $path): string
{
    $deadline = microtime(true) + 5;
    $value = '';
    while (microtime(true) < $deadline) {
        $value = is_file($path) ? (string) file_get_contents($path) : '';
        if ($value === 'closed') {
            return $value;
        }
        usleep(10000);
    }
    return $value;
}

/** 在已有协程内尝试握手并通信；被服务端关闭（收不到回显）即视为拒绝。 */
function wsRejected(int $port, array $headers, bool $tls = false, array $tlsOptions = []): bool
{
    $client = Client::create(new ResourceBudget(2), '127.0.0.1', $port, '/ws', $tls, $headers, 1048576, 5.0, $tlsOptions);
    try {
        $client->start();
        $client->send('probe', true);
        return $client->receive(2.0) === null;
    } catch (\Throwable $error) {
        return true;
    } finally {
        $client->stop();
    }
}

/**
 * 构造显式加载所需 mysqlnd 和 Swoole 的测试 PHP 命令，保留数组参数边界。
 *
 * @return list<string>
 */
function wsPhp(): array
{
    $directory = (string) ini_get('extension_dir');
    $swoole = (string) (getenv('TYPE_SWOOLE_MODULE') ?: $directory . '/swoole.so');
    expect(is_file($swoole), 'WebSocket 验收需要 TYPE_SWOOLE_MODULE 或 extension_dir 中的 swoole.so');
    $command = [PHP_BINARY];
    foreach (['mysqlnd', 'swoole'] as $extension) {
        $module = $extension === 'swoole' ? $swoole : $directory . '/' . $extension . '.so';
        if (!is_file($module)) {
            continue;
        }
        $command[] = '-d';
        $command[] = 'extension=' . $module;
        if ($extension === 'swoole') {
            $command[] = '-d';
            $command[] = 'swoole.enable_library=On';
        }
    }
    return $command;
}

/** 扩展尚未加载时以明确配置重启测试一次；环境哨兵防止递归重启，并透传子进程退出码。 */
function wsReexec(): void
{
    if (getenv('TYPE_WS_REEXEC') === '1' || extension_loaded('swoole')) {
        return;
    }
    $command = [...wsPhp(), __FILE__, ...array_slice($GLOBALS['argv'], 1)];
    $environment = getenv();
    expect(is_array($environment), '无法读取测试进程环境');
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, null, array_replace($environment, ['TYPE_WS_REEXEC' => '1']));
    expect(is_resource($process), '无法以 Swoole 运行扩展重启 WebSocket 测试');
    exit(proc_close($process));
}

wsReexec();
expect(extension_loaded('swoole'), 'WebSocket 验收需要已加载的 Swoole 扩展');

$rejected = false;
try {
    Server::create(new ResourceBudget(1), '127.0.0.1', 0, ['open_ssl' => true]);
} catch (TaskException $error) {
    $rejected = str_contains($error->getMessage(), 'WSS');
}
expect($rejected, '缺少证书的 WSS 配置应在启动前拒绝');

$rejected = false;
try {
    Client::create(new ResourceBudget(1), '127.0.0.1', 443, '/', false, [], 1024, 5.0, ['ssl_cafile' => '/tmp/ca.pem']);
} catch (TaskException $error) {
    $rejected = str_contains($error->getMessage(), '未启用 TLS');
}
expect($rejected, '明文客户端不应接受 TLS 选项');

$echo = ['max_frame_bytes' => 65536, 'package_max_bytes' => 131072, 'max_queued_bytes' => 131072];
$plain = wsStart($echo);
try {
    // 1. 握手必须是 101，且 Sec-WebSocket-Accept 由原生按标准计算。
    [$handshake, $header, $key] = wsHandshake($plain['port']);
    expect(str_contains($header, '101'), 'WebSocket 握手未返回 101：' . $header);
    $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    expect(str_contains($header, $accept), 'Sec-WebSocket-Accept 与标准计算不一致');

    // 2. 分片：连续两帧（首帧无 FIN）必须由原生重组为一条消息后回显。
    fwrite($handshake, wsFrame('frag-', WEBSOCKET_OPCODE_BINARY, false));
    fwrite($handshake, wsFrame('mented', 0x0, true));
    $echoed = '';
    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline && strlen($echoed) < 2) {
        $chunk = fread($handshake, 1024);
        if ($chunk === false || $chunk === '') {
            usleep(10000);
            continue;
        }
        $echoed .= $chunk;
    }
    expect(str_contains($echoed, 'frag-mented'), '分片消息未被原生重组回显：' . bin2hex(substr($echoed, 0, 24)));
    fclose($handshake);

    \Swoole\Coroutine\run(function () use ($plain): void {
        $budget = new ResourceBudget(4);
        $port = $plain['port'];

        // 3. 文本与二进制双向回显，二进制内容保持原字节（含空字节）。
        $client = Client::create($budget, '127.0.0.1', $port, '/ws', false, [], 65536);
        $client->start();
        expect($client->statistics()['state'] === 'active', 'WebSocket 客户端未完成握手');

        foreach (['slow', 'second', 'third'] as $message) {
            $client->send($message, false);
        }
        foreach (['slow', 'second', 'third'] as $message) {
            expect($client->receive(5.0) === $message, '慢消息后续回调丢失或乱序');
        }

        $client->send('文本消息', false);
        expect($client->receive(5.0) === '文本消息', '文本消息未正确回显');

        $binary = "\x00\x01\xff\xfe" . random_bytes(32);
        $client->send($binary, true);
        expect($client->receive(5.0) === $binary, '二进制消息未保持原字节回显');

        // 4. 控制帧不进入业务消息路径：PING 后仍然收到后续业务消息。
        $client->send('after-ping', false);
        expect($client->receive(5.0) === 'after-ping', '控制帧隔离后业务消息丢失');

        // 5. 单条消息超过上限必须被拒绝，且不提交给原生。
        $tooLarge = false;
        try {
            $client->send(str_repeat('x', 65537), true);
        } catch (TaskException $error) {
            $tooLarge = $error->getMessage() === 'WebSocket 单条消息超过字节上限，未提交'
                || str_contains($error->getMessage(), '超过字节上限');
        }
        expect($tooLarge, '超过上限的消息未被拒绝');

        $client->stop();
        expect($client->statistics()['state'] === 'closed', 'WebSocket 客户端关闭后状态不正确');
    });

    // 6. 同一监听上的普通 HTTP 请求继续可用，与长连接共存。
    $response = wsHttp($plain['port'], '/health');
    expect(str_contains($response, '200') && str_contains($response, 'http-ok'), '同一监听上的 HTTP 请求未共存：' . substr($response, 0, 80));
} finally {
    $plain['process']->stop();
}

// 7. 子协议与 Origin 策略：原生 websocket_subprotocol 会无条件回显配置值，
//    因此必须由本组件核对客户端是否真的提供该子协议，并精确匹配 Origin。
$policy = wsStart(['subprotocol' => 'mqtt', 'allowed_origins' => ['http://allowed.test']]);
try {
    \Swoole\Coroutine\run(function () use ($policy): void {
        $port = $policy['port'];
        $allowed = Client::create(
            new ResourceBudget(2),
            '127.0.0.1',
            $port,
            '/ws',
            false,
            ['Origin' => 'http://allowed.test', 'Sec-WebSocket-Protocol' => 'mqtt']
        );
        $allowed->start();
        $allowed->send('mqtt-payload', true);
        expect($allowed->receive(5.0) === 'mqtt-payload', '子协议与 Origin 均合规时通信失败');
        $allowed->stop();

        expect(
            !wsRejected($port, ['Origin' => 'http://allowed.test', 'Sec-WebSocket-Protocol' => 'mqtt']),
            '合规连接被误判为拒绝，拒绝断言失去意义'
        );
        expect(wsRejected($port, ['Origin' => 'http://allowed.test']), '未提供 mqtt 子协议的连接未被拒绝');
        expect(
            wsRejected($port, ['Origin' => 'http://allowed.test', 'Sec-WebSocket-Protocol' => 'chat']),
            '提供错误子协议的连接未被拒绝'
        );
        expect(
            wsRejected($port, ['Origin' => 'http://evil.test', 'Sec-WebSocket-Protocol' => 'mqtt']),
            'Origin 不在白名单的连接未被拒绝'
        );

        $anonymous = Client::create(
            new ResourceBudget(2),
            '127.0.0.1',
            $port,
            '/ws',
            false,
            ['Sec-WebSocket-Protocol' => 'mqtt']
        );
        $anonymous->start();
        $anonymous->send('no-origin', true);
        expect($anonymous->receive(5.0) === 'no-origin', '无 Origin 的标准工具被误拒，仍应可连接后由鉴权决定');
        $anonymous->stop();
    });
} finally {
    $policy['process']->stop();
}

$tls = wsCertificate();
try {
    $secure = wsStart([
        'open_ssl' => true,
        'ssl_cert_file' => $tls['certificate'],
        'ssl_key_file' => $tls['key'],
        'max_frame_bytes' => 65536,
        'package_max_bytes' => 131072,
        'max_queued_bytes' => 131072,
    ], true);
    try {
        [$wss, $wssHeader, $wssKey] = wsHandshake($secure['port'], '/ws', [], true, $tls['certificate']);
        expect(str_contains($wssHeader, '101'), 'WSS 握手未返回 101：' . $wssHeader);
        $accept = base64_encode(sha1($wssKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        expect(str_contains($wssHeader, $accept), 'WSS Sec-WebSocket-Accept 与标准计算不一致');
        fwrite($wss, wsFrame('wss-plain', WEBSOCKET_OPCODE_BINARY, true));
        $wssEcho = '';
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline && strlen($wssEcho) < 2) {
            $chunk = fread($wss, 1024);
            if ($chunk === false || $chunk === '') {
                usleep(10000);
                continue;
            }
            $wssEcho .= $chunk;
        }
        expect(str_contains($wssEcho, 'wss-plain'), 'WSS 二进制回显失败：' . bin2hex(substr($wssEcho, 0, 24)));
        fclose($wss);

        $legacy = @stream_socket_client(
            'ssl://127.0.0.1:' . $secure['port'],
            $errno,
            $error,
            2,
            STREAM_CLIENT_CONNECT,
            wsTlsContext($tls['certificate'], STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT)
        );
        expect($legacy === false, 'TLS 1.1 不应完成 WSS 握手');

        \Swoole\Coroutine\run(function () use ($secure, $tls): void {
            $unverified = false;
            try {
                Client::create(new ResourceBudget(2), '127.0.0.1', $secure['port'], '/ws', true)->start();
            } catch (\Throwable $error) {
                $unverified = true;
            }
            expect($unverified, 'WSS 客户端缺少 CA 时必须拒绝自签名证书');

            $wrongName = false;
            try {
                Client::create(
                    new ResourceBudget(2),
                    '127.0.0.1',
                    $secure['port'],
                    '/ws',
                    true,
                    [],
                    65536,
                    5.0,
                    ['ssl_cafile' => $tls['certificate'], 'ssl_host_name' => 'wrong.invalid']
                )->start();
            } catch (\Throwable $error) {
                $wrongName = true;
            }
            expect($wrongName, 'WSS 客户端主机名不匹配时必须失败');

            $client = Client::create(
                new ResourceBudget(2),
                '127.0.0.1',
                $secure['port'],
                '/ws',
                true,
                [],
                65536,
                5.0,
                ['ssl_cafile' => $tls['certificate'], 'ssl_host_name' => '127.0.0.1']
            );
            $client->start();
            expect($client->statistics()['tls'] === true, 'WSS 客户端统计未标记 TLS');
            $client->send('wss-ok', true);
            expect($client->receive(5.0) === 'wss-ok', 'WSS 标准客户端回显失败');

            $client->send('lease-check', true);
            expect(wsLease($secure['lease']) === 'closed', '消息回调返回后租约仍占用，慢对端会拖住事务');
            expect($client->receive(5.0) === 'lease-check', '租约释放后连接应仍可读取回显');

            $client->send('hold', true);
            expect($client->receive(0.8) === null, '取消/超时接收应返回 null，而不是一条空消息');
            expect(wsLease($secure['lease']) === 'closed', '不发送回显的消息结束后租约仍应关闭');
            $client->stop();
        });

        $https = wsHttp($secure['port'], '/health', true, $tls['certificate']);
        expect(str_contains($https, '200') && str_contains($https, 'http-ok'), 'WSS 同一监听上的 HTTPS 未共存：' . substr($https, 0, 80));

        $stop = wsHttp($secure['port'], '/stop', true, $tls['certificate']);
        expect(str_contains($stop, 'stopping'), '线程退役停止请求未送达：' . substr($stop, 0, 80));
        $exited = $secure['process']->wait(5);
        expect($exited->successful() || !$secure['process']->running(), '停止后服务未退出：' . $exited->stderr);
    } finally {
        $secure['process']->stop();
        if (is_file($secure['lease'])) {
            unlink($secure['lease']);
        }
    }
} finally {
    wsCleanup($tls['directory']);
}

echo "有界 WebSocket 会话验收通过：真实握手、分片重组、二进制保真、控制帧隔离、单条上限、HTTP 共存、子协议与 Origin、WSS/TLS 1.2/1.3、消息作用域与取消。\n";
