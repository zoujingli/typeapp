<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Testing\Process;

/** 读取精确协议字节；EOF 与超时都失败，不能作为关闭结算成功的替代。 */
function compatRead($socket, int $length): string
{
    $result = '';
    while (strlen($result) < $length) {
        $chunk = fread($socket, min(1048576, $length - strlen($result)));
        expect(is_string($chunk) && $chunk !== '', 'WebSocket 协议读取不完整');
        $result .= $chunk;
    }
    return $result;
}

/** @return array{opcode:int,data:string} 独立客户端只解包服务端帧，不使用被测封装。 */
function compatFrame($socket): array
{
    $header = compatRead($socket, 2);
    $length = ord($header[1]) & 127;
    expect((ord($header[1]) & 128) === 0, '服务端帧不应掩码');
    if ($length === 126) {
        $length = unpack('n', compatRead($socket, 2))[1];
    } elseif ($length === 127) {
        $length = unpack('J', compatRead($socket, 8))[1];
    }
    expect($length <= 64 * 1024 * 1024, '测试读取超出有界容量');
    return ['opcode' => ord($header[0]) & 15, 'data' => compatRead($socket, $length)];
}

/** 发送真正的掩码帧，包括 Ping/Pong 与大消息，不用文本字符串模拟控制帧。 */
function compatSend($socket, string $data, int $opcode = 2, bool $fin = true): void
{
    $length = strlen($data);
    $head = chr(($fin ? 128 : 0) | $opcode);
    $head .= $length < 126 ? chr(128 | $length) : ($length < 65536 ? chr(254) . pack('n', $length) : chr(255) . pack('J', $length));
    $mask = "\x19\x38\x57\x76";
    $wire = $head . $mask . ($data ^ substr(str_repeat($mask, intdiv($length + 3, 4)), 0, $length));
    for ($offset = 0; $offset < strlen($wire);) {
        $written = fwrite($socket, substr($wire, $offset, 1048576));
        expect(is_int($written) && $written > 0, 'WebSocket 协议写入不完整');
        $offset += $written;
    }
}

/** 完成真实握手，URI 与原始查询不经测试客户端重写。 */
function compatConnect(int $port, string $target = '/device?ssid=0&scene=3&token=a%2Bb')
{
    $socket = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 5);
    expect(is_resource($socket), '无法连接协议夹具');
    stream_set_timeout($socket, 15);
    $request = "GET {$target} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Key: " . base64_encode(random_bytes(16)) . "\r\n\r\n";
    expect(fwrite($socket, $request) === strlen($request), '握手写入失败');
    $header = '';
    while (($line = fgets($socket)) !== false) {
        $header .= $line;
        if ($line === "\r\n") {
            break;
        }
    }
    expect(str_contains($header, '101 Switching Protocols'), '握手未升级：' . $header);
    return $socket;
}

/** HTTP 与 WebSocket 必须继续共用同一真实监听。 */
function compatHttp(int $port, string $path = '/statistics'): array
{
    $body = file_get_contents('http://127.0.0.1:' . $port . $path, false, stream_context_create(['http' => ['timeout' => 5]]));
    expect(is_string($body), '同端口 HTTP 请求失败');
    return json_decode($body, true, 16, JSON_THROW_ON_ERROR);
}

/** 等待独立结果文件观察 close，不用一次短暂休眠推断异步回调已完成。 */
function compatClosed(string $file, int $opened): array
{
    $deadline = microtime(true) + 8;
    do {
        $events = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        if (is_array($events) && count(array_filter($events, static fn (array $event): bool => $event['kind'] === 'close')) >= $opened) {
            return $events;
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('已接受连接没有完成关闭结算');
}

/** 每次接受都必须有唯一且位于其后的 close；允许原生连接标识在关闭后被复用。 */
function compatSettlements(array $events, int $expected): void
{
    $active = [];
    $opened = 0;
    $closed = 0;
    foreach ($events as $event) {
        $fd = $event['fd'];
        if ($event['kind'] === 'open') {
            expect(!isset($active[$fd]), '同一活动连接被重复接受');
            $active[$fd] = true;
            $opened++;
        } elseif ($event['kind'] === 'close') {
            expect(isset($active[$fd]), '未接受的连接或已结算连接再次触发 close');
            expect(is_int($event['details']['reactor_id']), 'close 第二参数不是 reactor ID');
            unset($active[$fd]);
            $closed++;
        }
    }
    expect($active === [] && $opened === $expected && $closed === $expected, '连接关闭结算遗漏');
}

/** @return list<string> 同一组断言可运行源码夹具或固定的原生产物。 */
function compatCommand(): array
{
    $native = getenv('TYPE_WS_NATIVE_COMMAND');
    return is_string($native) && $native !== '' ? json_decode($native, true, 16, JSON_THROW_ON_ERROR) : [
        PHP_BINARY, '-d', 'extension=' . (string) getenv('TYPE_SWOOLE_MODULE'), '-r',
        'require $argv[1]; require $argv[2]; main(4, [$argv[2], $argv[3], $argv[4], $argv[5]]);',
        dirname(__DIR__) . '/vendor/autoload.php', __DIR__ . '/fixtures/websocket-compat.php',
    ];
}

/** @return array{process:Process,port:int,file:string} */
function compatStart(string $work, array $options): array
{
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect(is_resource($listener), '无法分配回环端口');
    $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
    fclose($listener);
    $file = $work . '/' . $port . '.json';
    $process = new Process([...compatCommand(), (string) $port, $file, json_encode($options, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)], $work);
    $deadline = microtime(true) + 10;
    try {
        do {
            expect($process->running(), '协议夹具提前退出：' . $process->stderr());
            $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.1);
            if (is_resource($probe)) {
                fclose($probe);
                return ['process' => $process, 'port' => $port, 'file' => $file];
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('协议夹具未就绪');
    } catch (Throwable $error) {
        $process->stop();
        file_put_contents($file . '.stderr.log', $process->stderr());
        file_put_contents($file . '.stdout.log', $process->stdout());
        throw $error;
    }
}

$root = dirname(__DIR__);
$work = $root . '/build/websocket-compat-' . bin2hex(random_bytes(5));
expect(mkdir($work, 0700, true), '无法创建协议验收目录');
$checks = [];
$cases = [];
$passed = false;
try {
    foreach ([['max_frame_bytes' => 67108865], ['max_queued_bytes' => 67108865],
        ['package_max_length' => 1024, 'package_max_bytes' => 2048],
        ['heartbeat_idle_time' => 45, 'idle_seconds' => 46.0],
        ['heartbeat_idle_time' => 10, 'heartbeat_check_interval' => 45]] as $invalid) {
        $validation = successful([...compatCommand(), 'validate', 'unused', json_encode($invalid, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)], $work);
        expect(json_decode($validation, true) === ['accepted' => false, 'error' => 'websocket_invalid_configuration'], '无效/冲突配置未拒绝');
    }
    $checks[] = 'configuration_rejections';
    $closing = compatStart($work, []);
    $cases[] = $closing;
    expect(compatHttp($closing['port'], '/disconnect-result?fd=2147483647') === ['first' => false, 'repeat' => false], '未知连接不能报告关闭提交成功');
    foreach ([false, true] as $fallback) {
        $socket = compatConnect($closing['port'], '/submission');
        compatFrame($socket);
        $events = json_decode((string) file_get_contents($closing['file']), true, 16, JSON_THROW_ON_ERROR);
        $fd = (int) end($events)['fd'];
        $path = '/disconnect-result?fd=' . $fd . ($fallback ? '&fallback=1' : '');
        expect(compatHttp($closing['port'], $path) === ['first' => true, 'repeat' => false], '关闭提交结果或重复关闭结果不正确');
        if ($fallback) {
            // reset close 可产生 EOF 或连接重置；超时不能作为已关闭的证据。
            stream_set_timeout($socket, 3);
            $end = @fread($socket, 1);
            expect(($end === '' || $end === false) && !stream_get_meta_data($socket)['timed_out'] && feof($socket), '回退关闭没有实际断开连接');
        } else {
            $frame = compatFrame($socket);
            expect($frame['opcode'] === 8 && $frame['data'] === pack('n', 1000) . 'test_close', '关闭提交未产生指定关闭帧');
        }
        fclose($socket);
        compatClosed($closing['file'], $fallback ? 2 : 1);
        expect(compatHttp($closing['port'], '/disconnect-result?fd=' . $fd) === ['first' => false, 'repeat' => false], '已结算连接不能再次报告提交成功');
    }
    compatSettlements(compatClosed($closing['file'], 2), 2);
    compatHttp($closing['port'], '/stop');
    expect($closing['process']->wait(10)->successful(), '关闭提交验收服务未正常退出');
    expect(str_contains($closing['process']->stdout() . $closing['process']->stderr(), 'the max length of close reason is 123'), '未观察到原生 disconnect 拒绝，不能证明 close 回退通过');
    $checks[] = 'disconnect_submission_and_fallback';
    $rejectedUpgrade = compatStart($work, ['subprotocol' => 'device-v1']);
    $cases[] = $rejectedUpgrade;
    $socket = compatConnect($rejectedUpgrade['port']);
    $rejection = compatFrame($socket);
    expect($rejection['opcode'] === 8 && unpack('n', substr($rejection['data'], 0, 2))[1] === 1002, '旧 disconnect 入口未关闭尚未接受的握手');
    fclose($socket);
    $rejectedStatistics = compatHttp($rejectedUpgrade['port'], '/stop');
    expect($rejectedStatistics['accepted'] === 0 && $rejectedStatistics['rejected'] === 1, '拒绝握手被错误登记为业务连接');
    expect($rejectedUpgrade['process']->wait(10)->successful(), '握手拒绝验收服务未正常退出');
    compatSettlements(json_decode((string) file_get_contents($rejectedUpgrade['file']), true, 16, JSON_THROW_ON_ERROR), 0);
    $checks[] = 'legacy_unaccepted_handshake_close';
    $options = ['max_frame_bytes' => 50 * 1024 * 1024, 'max_queued_bytes' => 50 * 1024 * 1024,
        'package_max_length' => 50 * 1024 * 1024 + 14, 'max_connections' => 4,
        'heartbeat_idle_time' => 45, 'heartbeat_check_interval' => 10,
        'open_websocket_ping_frame' => true, 'open_websocket_pong_frame' => true];
    $server = compatStart($work, $options);
    $cases[] = $server;
    $port = $server['port'];
    $socket = compatConnect($port);
    $snapshot = json_decode(compatFrame($socket)['data'], true, 8, JSON_THROW_ON_ERROR);
    expect($snapshot === ['path' => '/device', 'query' => 'ssid=0&scene=3&token=a%2Bb', 'remote_addr' => '127.0.0.1'], '握手快照丢失原始查询或对端');
    $checks[] = 'handshake_snapshot';
    $ping = "same\0payload";
    compatSend($socket, $ping, 9);
    expect(compatFrame($socket) === ['opcode' => 10, 'data' => $ping], 'Ping 没有原样回复 Pong');
    expect(compatFrame($socket)['data'] === 'observed:9', 'Ping 观察回调缺失或重复 Pong');
    compatSend($socket, 'after-ping', 1);
    expect(compatFrame($socket)['data'] === 'after-ping', '控制帧进入消息路径或重复应答');
    compatSend($socket, 'peer-pong', 10);
    expect(compatFrame($socket)['data'] === 'observed:10', 'Pong 观察回调缺失');
    compatSend($socket, 'server-ping');
    expect(compatFrame($socket) === ['opcode' => 9, 'data' => "ping\0payload"], '主动 Ping 不符合协议');
    compatSend($socket, 'server-pong');
    expect(compatFrame($socket) === ['opcode' => 10, 'data' => "pong\0payload"], '主动 Pong 不符合协议');
    compatSend($socket, 'invalid-control');
    expect(str_contains(compatFrame($socket)['data'], '125'), '超长控制帧未明确拒绝');
    $checks[] = 'control_frames_and_scope';
    compatSend($socket, 'fragment-', 2, false);
    compatSend($socket, 'complete', 0);
    expect(compatFrame($socket)['data'] === 'fragment-complete', '分片重组回归');
    $large = str_repeat('x', 50 * 1024 * 1024);
    compatSend($socket, $large);
    expect(json_decode(compatFrame($socket)['data'], true) === ['bytes' => strlen($large), 'sha256' => hash('sha256', $large)], '50 MiB 入站载荷不完整');
    unset($large);
    compatSend($socket, 'big-send');
    $large = compatFrame($socket);
    expect($large['opcode'] === 2 && strlen($large['data']) === 50 * 1024 * 1024 && $large['data'] === str_repeat('z', 50 * 1024 * 1024), '50 MiB 出站载荷不完整');
    unset($large);
    $checks[] = 'fifty_mib_bidirectional';
    $statistics = compatHttp($port);
    expect($statistics['heartbeat_idle_time'] === 45 && $statistics['heartbeat_check_interval'] === 10, '原生心跳选项不一致');
    compatSend($socket, 'close');
    expect(compatFrame($socket)['opcode'] === 8, '主动关闭未发起关闭握手');
    fclose($socket);
    compatClosed($server['file'], 1);
    foreach (['/open-close', '/open-fail', '/passive', '/failure'] as $path) {
        $socket = compatConnect($port, $path);
        if ($path === '/passive' || $path === '/failure') {
            compatFrame($socket);
            if ($path === '/failure') {
                compatSend($socket, 'fail');
                expect(compatFrame($socket)['opcode'] === 8, '异常关闭未完成握手');
            }
        } else {
            expect(compatFrame($socket)['opcode'] === 8, 'open 退出未关闭连接');
        }
        fclose($socket);
    }
    compatClosed($server['file'], 5);
    $socket = compatConnect($port, '/stop');
    compatFrame($socket);
    compatSend($socket, 'stop');
    expect(compatFrame($socket)['opcode'] === 8, '服务停止未关闭连接');
    fclose($socket);
    expect($server['process']->wait(10)->successful(), '服务停止未正常退出');
    $events = compatClosed($server['file'], 6);
    compatSettlements($events, 6);
    expect(end($events)['details']['state'] === 'closed' && end($events)['details']['allocated'] === false, '停止后额度未释放');
    $checks[] = 'close_once_all_paths';
    $idle = compatStart($work, ['heartbeat_idle_time' => 1, 'heartbeat_check_interval' => 1]);
    $cases[] = $idle;
    $socket = compatConnect($idle['port']);
    compatFrame($socket);
    compatClosed($idle['file'], 1);
    fclose($socket);
    compatHttp($idle['port'], '/stop');
    expect($idle['process']->wait(10)->successful(), '心跳关闭后服务未正常停止');
    compatSettlements(compatClosed($idle['file'], 1), 1);
    $checks[] = 'native_idle_close_and_http';
    $bounded = compatStart($work, ['max_frame_bytes' => 256, 'max_queued_bytes' => 512, 'package_max_length' => 512]);
    $cases[] = $bounded;
    $socket = compatConnect($bounded['port']);
    compatFrame($socket);
    compatSend($socket, 'automatic-pong', 9);
    expect(compatFrame($socket) === ['opcode' => 10, 'data' => 'automatic-pong'], '默认原生 Pong 行为发生变化');
    compatSend($socket, 'after-default-ping');
    expect(compatFrame($socket)['data'] === 'after-default-ping', '默认控制帧被错误投递或重复应答');
    compatSend($socket, str_repeat('x', 257));
    $rejected = compatFrame($socket);
    expect($rejected['opcode'] === 8 && unpack('n', substr($rejected['data'], 0, 2))[1] === 1009, '入站单消息超限未按 1009 关闭');
    fclose($socket);
    compatSettlements(compatClosed($bounded['file'], 1), 1);
    compatHttp($bounded['port'], '/stop');
    expect($bounded['process']->wait(10)->successful(), '容量拒绝后服务未正常停止');
    $checks[] = 'default_pong_and_capacity_rejection';
    $passed = true;
    echo json_encode(['ok' => true, 'checks' => $checks], JSON_THROW_ON_ERROR), "\n";
} finally {
    foreach ($cases as $case) {
        $case['process']->stop();
        if (!$passed) {
            file_put_contents($case['file'] . '.stdout.log', $case['process']->stdout());
            file_put_contents($case['file'] . '.stderr.log', $case['process']->stderr());
        }
    }
    if ($passed) {
        removeTestDirectory($work);
    } else {
        fwrite(STDERR, '失败证据保留于：' . $work . "\n");
    }
}
