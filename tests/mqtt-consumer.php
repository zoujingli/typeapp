<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Testing\Process;

/** 编码测试输入，独立于 Broker 实现。 */
function mqttField(string $value): string
{
    return pack('n', strlen($value)) . $value;
}

/** 独立编码 MQTT 可变长度整数；调用方提供协议允许范围内的值。 */
function mqttLength(int $value): string
{
    $bytes = '';
    do {
        $byte = $value % 128;
        $value = intdiv($value, 128);
        $bytes .= chr($value > 0 ? $byte | 128 : $byte);
    } while ($value > 0);
    return $bytes;
}

/** 构造 MQTT 3.1.1 或 5 的线协议 CONNECT；keepalive 单位为秒，properties 为已编码 MQTT 5 属性。 */
function mqttConnect(int $version, string $id = 'wire-client', int $keepalive = 10, string $properties = '', string $password = 'mqtt-test-secret'): string
{
    $payload = mqttField('MQTT') . chr($version) . "\xc2" . pack('n', $keepalive)
        . ($version === 5 ? mqttLength(strlen($properties)) . $properties : '')
        . mqttField($id) . mqttField('example') . mqttField($password);
    return "\x10" . mqttLength(strlen($payload)) . $payload;
}

/** @return resource */
function mqttSocket(int $port, ?string $certificate = null, string $peer = '127.0.0.1'): mixed
{
    $options = $certificate === null ? [] : ['ssl' => ['cafile' => $certificate, 'verify_peer' => true,
        'verify_peer_name' => true, 'peer_name' => $peer, 'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT]];
    $socket = @stream_socket_client(($certificate === null ? 'tcp' : 'tls') . '://127.0.0.1:' . $port, $errno, $error, 3, STREAM_CLIENT_CONNECT, stream_context_create($options));
    expect(is_resource($socket), 'MQTT 客户端连接失败：' . $errno . ' ' . $error);
    stream_set_timeout($socket, 3);
    return $socket;
}

/** 逐段读完整报文；只在报文开始前的真实EOF返回空串，错误保留读取阶段与流状态。 */
function mqttRead(mixed $socket): string
{
    $first = fread($socket, 1);
    if ($first === false || $first === '') {
        expect($first === '' && feof($socket), 'MQTT 首字节读取失败：type=' . get_debug_type($first)
            . ' metadata=' . json_encode(array_intersect_key(stream_get_meta_data($socket), array_flip(['timed_out', 'blocked', 'eof', 'unread_bytes']))));
        return '';
    }
    $wire = $first;
    $length = 0;
    $multiplier = 1;
    do {
        $byte = fread($socket, 1);
        if ($byte === false || $byte === '') {
            throw new RuntimeException('MQTT 响应长度不完整：prefix=' . bin2hex($wire) . ' type=' . get_debug_type($byte)
                . ' metadata=' . json_encode(array_intersect_key(stream_get_meta_data($socket), array_flip(['timed_out', 'blocked', 'eof', 'unread_bytes']))));
        }
        $wire .= $byte;
        $value = ord($byte);
        $length += ($value & 127) * $multiplier;
        $multiplier *= 128;
        expect($multiplier <= 268435456, 'MQTT 响应变长整数越界');
    } while (($value & 128) !== 0);
    while ($length > 0) {
        $chunk = fread($socket, $length);
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('MQTT 响应正文不完整：prefix=' . bin2hex(substr($wire, 0, 8)) . ' remaining=' . $length . ' type=' . get_debug_type($chunk)
                . ' metadata=' . json_encode(array_intersect_key(stream_get_meta_data($socket), array_flip(['timed_out', 'blocked', 'eof', 'unread_bytes']))));
        }
        $wire .= $chunk;
        $length -= strlen($chunk);
    }
    return $wire;
}

/** 真实TCP分片、粘包、超时及半包EOF；发送进程等待读取端关闭，避免靠固定休眠保持连接。 */
function mqttReadCases(): int
{
    $packet = "\x30\x80\x01" . str_repeat('a', 128);
    $cases = [
        'fragmented' => [str_split($packet), [$packet], ''],
        'coalesced' => [["\xd0\0" . $packet], ["\xd0\0", $packet], ''],
        'first-timeout' => [[], [], 'MQTT 首字节读取失败：'],
        'length-timeout' => [["\x30"], [], 'MQTT 响应长度不完整：prefix=30'],
        'variable-length-timeout' => [["\x30\x80"], [], 'MQTT 响应长度不完整：prefix=3080'],
        'body-timeout' => [["\x30\x02a"], [], 'MQTT 响应正文不完整：prefix=300261 remaining=1'],
        'length-eof' => [["\x30"], [], 'MQTT 响应长度不完整：prefix=30'],
        'variable-length-eof' => [["\x30\x80"], [], 'MQTT 响应长度不完整：prefix=3080'],
        'body-eof' => [["\x30\x02a"], [], 'MQTT 响应正文不完整：prefix=300261 remaining=1'],
        'clean-eof' => [[], [''], ''],
    ];
    foreach ($cases as $name => [$chunks, $packets, $expectedFailure]) {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        expect(is_resource($listener), '读取回归无法创建TCP监听');
        $address = stream_socket_get_name($listener, false);
        $program = '$socket = stream_socket_client("tcp://" . $argv[1], $errno, $error, 3); '
            . 'if (!is_resource($socket)) exit(1); stream_set_timeout($socket, 3); '
            . 'foreach (json_decode($argv[2], true) as $chunk) { $bytes = hex2bin($chunk); '
            . 'if (fwrite($socket, $bytes) !== strlen($bytes)) exit(2); '
            . 'if ($argv[3] === "fragmented") usleep(10000); } '
            . 'if (str_ends_with($argv[3], "timeout")) { '
            . 'if (fread($socket, 1) !== "" || !feof($socket)) exit(3); } fclose($socket);';
        $sender = null;
        $socket = null;
        try {
            $sender = new Process([PHP_BINARY, '-r', $program, '--', $address, json_encode(array_map('bin2hex', $chunks), JSON_THROW_ON_ERROR), $name]);
            $socket = stream_socket_accept($listener, 3);
            expect(is_resource($socket), '读取回归没有收到TCP连接');
            $timeout = str_ends_with($name, 'timeout');
            stream_set_timeout($socket, $timeout ? 0 : 3, $timeout ? 200000 : 0);
            $failure = null;
            try {
                if ($expectedFailure !== '') {
                    mqttRead($socket);
                } else {
                    foreach ($packets as $packetBytes) {
                        expect(mqttRead($socket) === $packetBytes, 'TCP分片或粘包改变报文字节');
                    }
                }
            } catch (RuntimeException $caught) {
                $failure = $caught->getMessage();
            }
            if ($expectedFailure === '') {
                expect($failure === null, $name . '：' . ($failure ?? ''));
            } else {
                expect(is_string($failure) && str_starts_with($failure, $expectedFailure), $name . '没有准确标出失败阶段：' . ($failure ?? '未失败'));
                $metadata = json_decode(substr($failure, strpos($failure, ' metadata=') + 10), true, 8, JSON_THROW_ON_ERROR);
                expect(
                    $metadata['timed_out'] === $timeout && $metadata['eof'] === !$timeout && $metadata['blocked'] === true,
                    $name . '混淆了超时和EOF'
                );
            }
            fclose($socket);
            $socket = null;
            $result = $sender->wait(3);
            expect($result->successful() && $result->stderr === '', '读取回归发送进程没有干净退出：' . $name);
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
            fclose($listener);
            if ($sender !== null && $sender->running()) {
                $sender->stop();
            }
        }
    }
    return count($cases);
}

/**
 * 处理测试连接短写，直到报文字节全部发送；连接错误使测试失败，所有权仍归调用者。
 *
 * @param resource $socket
 */
function mqttWrite(mixed $socket, string $bytes): void
{
    $offset = 0;
    while ($offset < strlen($bytes)) {
        $written = fwrite($socket, substr($bytes, $offset));
        expect(is_int($written) && $written > 0, 'MQTT 测试输入写入失败');
        $offset += $written;
    }
}

/**
 * 读取成功且无历史会话的 CONNACK，并核验对应协议版本的确认布局。
 *
 * @param resource $socket
 */
function mqttAck(mixed $socket, int $version): string
{
    $ack = mqttRead($socket);
    expect(strlen($ack) >= 4 && ord($ack[0]) === 0x20 && ord($ack[2]) === 0 && ord($ack[3]) === 0, 'MQTT CONNECT 未成功：' . bin2hex($ack));
    expect($version !== 4 || $ack === "\x20\x02\x00\x00", 'MQTT 3.1.1 CONNACK 携带其他版本属性');
    return $ack;
}

/** 以固定头和独立编码的剩余长度包装 MQTT 正文字节，用于构造预期线协议数据。 */
function mqttPacket(int $header, string $body): string
{
    return chr($header) . mqttLength(strlen($body)) . $body;
}

/** 按协议版本构造订阅或取消订阅报文；调用者明确报文标识符和订阅选项。 */
function mqttSubscription(int $version, string $topic, int $identifier = 1, int $options = 0, bool $subscribe = true): string
{
    return mqttPacket($subscribe ? 0x82 : 0xa2, pack('n', $identifier) . ($version === 5 ? "\0" : '')
        . mqttField($topic) . ($subscribe ? chr($options) : ''));
}

/** 构造指定版本的 QoS 0 发布报文，MQTT 5 属性由调用者提供编码后的字节。 */
function mqttPublish(int $version, string $topic, string $payload, string $properties = ''): string
{
    return mqttPacket(0x30, mqttField($topic) . ($version === 5 ? mqttLength(strlen($properties)) . $properties : '') . $payload);
}

/** 生成预期 SUBACK 或 UNSUBACK 字节，供实际服务响应进行独立比对。 */
function mqttSubscriptionAck(int $version, string $reasons = "\0", int $identifier = 1, bool $subscribe = true): string
{
    return mqttPacket($subscribe ? 0x90 : 0xb0, pack('n', $identifier) . ($version === 5 ? "\0" : '')
        . ($subscribe || $version === 5 ? $reasons : ''));
}

/** 不以读超时冒充 EOF；有序控制响应证明之前的 PUBLISH 已处理。 */
function mqttQuiet(mixed $socket): void
{
    mqttWrite($socket, "\xc0\0");
    expect(mqttRead($socket) === "\xd0\0", '出现不应交付的消息或连接被误关闭');
}

/** UTF-8合法边界、BOM/规范化区别和最大Topic通过真实订阅、发布与取消检验。 */
function mqttTextCases(int $port, ?string $certificate): int
{
    $cases = 0;
    foreach ([4, 5] as $version) {
        $publisher = mqttSocket($port, $certificate);
        $subscriber = mqttSocket($port, $certificate);
        try {
            mqttWrite($publisher, mqttConnect($version, 'text-publisher-' . $version));
            mqttWrite($subscriber, mqttConnect($version, 'text-subscriber-' . $version));
            mqttAck($publisher, $version);
            mqttAck($subscriber, $version);
            $topics = [
                ["example/\xef\xbb\xbftext", 'example/text'],
                ["example/text\xef\xbb\xbf", 'example/text'],
                ["example/t\xef\xbb\xbfext", 'example/text'],
                ["example/\xc3\xa9", "example/e\xcc\x81"],
                ["example/\xc2\xa0\xdf\xbf\xe0\xa0\x80\xed\x9f\xbf\xee\x80\x80\xef\xbf\xbd\xf0\x90\x80\x80\xf4\x8f\xbf\xbd", 'example/utf8-boundaries'],
                ['example/' . str_repeat('m', 65535 - strlen('example/')), 'example/short'],
            ];
            foreach ($topics as [$topic, $distinct]) {
                mqttWrite($subscriber, mqttSubscription($version, $topic, 65535));
                expect(mqttRead($subscriber) === mqttSubscriptionAck($version, "\0", 65535), '合法UTF-8或65535字节Topic订阅被拒绝');
                mqttWrite($publisher, mqttPublish($version, $distinct, 'must-not-match'));
                mqttQuiet($publisher);
                mqttQuiet($subscriber);
                $properties = $version === 5 ? "\x03" . mqttField("text/\xef\xbb\xbfplain")
                    . "\x26" . mqttField("\xef\xbb\xbfkey") . mqttField("value\xef\xbb\xbf") : '';
                $wire = mqttPublish($version, $topic, "\0\xff\xed\xa0\x80", $properties);
                mqttWrite($publisher, $wire);
                expect(mqttRead($subscriber) === $wire, 'BOM、UTF-8、Content Type或User Property被剥除、规范化或插入Packet Identifier');
                mqttWrite($subscriber, mqttSubscription($version, $topic, 65534, 0, false));
                expect(mqttRead($subscriber) === mqttSubscriptionAck($version, "\0", 65534, false), 'UTF-8取消订阅标识或Topic语义改变');
                mqttWrite($publisher, $wire);
                mqttQuiet($publisher);
                mqttQuiet($subscriber);
                $cases++;
            }
            // 一个UNSUBSCRIBE包含存在/不存在/重复项，响应顺序、标识和随后无交付同时可观察。
            $prefix = pack('n', 32768) . ($version === 5 ? "\0" : '');
            mqttWrite($subscriber, mqttPacket(0x82, $prefix . mqttField('example/multi/first') . "\0" . mqttField('example/multi/second') . "\0"));
            expect(mqttRead($subscriber) === mqttSubscriptionAck($version, "\0\0", 32768), '多Topic订阅没有合并等量有序SUBACK');
            mqttWrite($subscriber, mqttPacket(0xa2, $prefix . mqttField('example/multi/second') . mqttField('example/multi/missing')
                . mqttField('example/multi/first') . mqttField('example/multi/second')));
            expect(mqttRead($subscriber) === mqttSubscriptionAck($version, "\0\x11\0\x11", 32768, false), '多Topic取消没有合并等量有序UNSUBACK');
            mqttWrite($publisher, mqttPublish($version, 'example/multi/first', 'after-unsubscribe') . mqttPublish($version, 'example/multi/second', 'after-unsubscribe'));
            mqttQuiet($publisher);
            mqttQuiet($subscriber);
            $cases++;
        } finally {
            fclose($publisher);
            fclose($subscriber);
        }
    }
    return $cases;
}

/** 发布订阅经过安装后的公开网络入口，与标准客户端形成独立编码交叉验证。 */
function mqttMessageCases(int $port, ?string $certificate): int
{
    $cases = 0;
    foreach ([4, 5] as $version) {
        $subscriber = mqttSocket($port, $certificate);
        $publisher = mqttSocket($port, $certificate);
        mqttWrite($subscriber, mqttConnect($version, 'wire-subscriber-' . $version));
        mqttWrite($publisher, mqttConnect($version, 'wire-publisher-' . $version));
        mqttAck($subscriber, $version);
        mqttAck($publisher, $version);
        $subscription = mqttSubscription($version, 'example/wire');
        for ($index = 0; $index < strlen($subscription); $index++) {
            mqttWrite($subscriber, $subscription[$index]);
        }
        expect(mqttRead($subscriber) === mqttSubscriptionAck($version), '分段 SUBSCRIBE/SUBACK 错误');
        mqttWrite($subscriber, $subscription);
        expect(mqttRead($subscriber) === mqttSubscriptionAck($version), '重复订阅没有替换原订阅');
        foreach (['', "\0\xff\xed\xa0\x80", str_repeat("\0\xff", 524277 - ($version === 5 ? 1 : 0))] as $payload) {
            $publish = mqttPublish($version, 'example/wire', $payload);
            mqttWrite($publisher, substr($publish, 0, 3));
            mqttWrite($publisher, substr($publish, 3));
            expect(mqttRead($subscriber) === $publish, '二进制 PUBLISH 被重复、截断或转码');
            mqttQuiet($publisher);
            mqttQuiet($subscriber);
            $cases++;
        }
        mqttWrite($subscriber, mqttSubscription($version, 'example/wire', 2, 0, false));
        expect(mqttRead($subscriber) === mqttSubscriptionAck($version, "\0", 2, false), 'UNSUBACK 与版本不符');
        mqttWrite($subscriber, mqttSubscription($version, 'example/wire', 3, 0, false));
        expect(mqttRead($subscriber) === mqttSubscriptionAck($version, "\x11", 3, false), '重复取消订阅语义错误');
        mqttWrite($publisher, mqttPublish($version, 'example/wire', 'unsubscribed'));
        mqttQuiet($publisher);
        mqttQuiet($subscriber);
        $cases++;

        // 每 Topic 独立授予；含属性和授权拒绝的多项订阅不影响合法项。
        $body = "\0\x04" . ($version === 5 ? "\x07\x26\0\x01k\0\x01v" : '')
            . mqttField('denied/topic') . "\0" . mqttField('example/allowed') . "\x02";
        mqttWrite($subscriber, mqttPacket(0x82, $body));
        expect(mqttRead($subscriber) === mqttSubscriptionAck($version, $version === 5 ? "\x87\0" : "\x80\0", 4), '多项订阅授权拒绝错误');
        mqttWrite($publisher, mqttPublish($version, 'example/allowed', 'allowed'));
        expect(mqttRead($subscriber) === mqttPublish($version, 'example/allowed', 'allowed'), '合法授权项没有交付');
        mqttWrite($publisher, mqttPublish($version, 'example/read-only/denied', 'denied'));
        expect(mqttRead($publisher) === ($version === 5 ? "\xe0\x02\x87\0" : ''), '发布授权拒绝未采用对应版本语义');
        fclose($publisher);
        fclose($subscriber);
        $cases++;

        $invalid = [
            [mqttPacket(0x80, "\0\x01"), 0x81],
            [mqttPacket(0xa0, "\0\x01"), 0x81],
            [mqttSubscription($version, 'example/x', 0), 0x82],
            [mqttSubscription($version, '', 1), 0x82],
            [mqttSubscription($version, 'example/a+b'), 0x82],
            [mqttSubscription($version, 'example/#/tail'), 0x82],
            [mqttSubscription($version, 'example/x', 1, 3), 0x82],
            [mqttSubscription($version, 'example/x', 1, 0xc0), 0x81],
            [mqttSubscription($version, "example/\0x"), 0x81],
            [mqttSubscription($version, 'example/x', 0, 0, false), 0x82],
            [mqttPacket(0x82, "\0\x01" . ($version === 5 ? "\0" : '')), 0x82],
            [mqttPacket(0xa2, "\0\x01" . ($version === 5 ? "\0" : '')), 0x82],
            [mqttPacket(0x82, "\0"), 0x81],
            [mqttPacket(0xa2, "\0"), 0x81],
            [mqttPublish($version, 'example/+', 'x'), 0x90],
            [mqttPublish($version, '', 'x'), 0x90],
            [mqttPublish($version, "example/\xed\xa0\x80", 'x'), 0x81],
            [mqttPacket(0x38, mqttField('example/x') . "\0"), 0x81],
            [mqttPacket(0x36, mqttField('example/x') . "\0"), 0x81],
            [mqttPacket(0x32, mqttField('example/x') . "\0\x01" . ($version === 5 ? "\0" : '')), 0x9b],
            [mqttPacket(0x31, mqttField('example/x') . ($version === 5 ? "\0" : '')), 0x9a],
        ];
        if ($version === 5) {
            $invalid[] = [mqttPacket(0x82, "\0\x01"), 0x81];
            $invalid[] = [mqttPacket(0xa2, "\0\x01"), 0x81];
            $invalid[] = [mqttSubscription(5, '$share/group/example/x'), 0x9e];
            foreach ([["\x01\x02", 0x82], ["\x01\0\x01\0", 0x82], ["\x11\0\0\0\0", 0x82],
                ["\x23\0\x21", 0x94], ["\x23\0\0", 0x94], ["\x08" . mqttField('example/#'), 0x82],
                ["\x01\x01", 0x99], ["\x0b\x01", 0x82]] as [$property, $reason]) {
                $invalid[] = [mqttPublish(5, 'example/x', "\xff", $property), $reason];
            }
            $invalid[] = [mqttPacket(0x82, "\0\x01\x02\x0b\0" . mqttField('example/x') . "\0"), 0x82];
            $invalid[] = [mqttPacket(0x82, "\0\x01\x04\x0b\x01\x0b\x02" . mqttField('example/x') . "\0"), 0x82];
            $invalid[] = [mqttPacket(0xa2, "\0\x01\x02\x01\0" . mqttField('example/x')), 0x82];
        } else {
            $legacy = mqttSocket($port, $certificate);
            mqttWrite($legacy, mqttConnect(4, 'legacy-unsupported-subscribe'));
            mqttAck($legacy, 4);
            mqttWrite($legacy, mqttSubscription(4, 'example/+'));
            expect(mqttRead($legacy) === mqttSubscriptionAck(4), '3.1.1 合法通配订阅未授予');
            fclose($legacy);
            $cases++;
        }
        foreach ($invalid as [$wire, $reason]) {
            $socket = mqttSocket($port, $certificate);
            mqttWrite($socket, mqttConnect($version, 'bad-message'));
            mqttAck($socket, $version);
            mqttWrite($socket, $wire);
            expect(mqttRead($socket) === ($version === 5 ? "\xe0\x02" . chr($reason) . "\0" : ''), '非法发布订阅未正确拒绝：' . bin2hex($wire));
            fclose($socket);
            $cases++;
        }
        $limited = mqttSocket($port, $certificate);
        mqttWrite($limited, mqttConnect($version, 'subscription-limit'));
        mqttAck($limited, $version);
        $body = "\0\x01" . ($version === 5 ? "\0" : '');
        for ($index = 0; $index < 101; $index++) {
            $body .= mqttField('example/limit/' . $index) . "\0";
        }
        mqttWrite($limited, mqttPacket(0x82, $body));
        expect(mqttRead($limited) === mqttSubscriptionAck($version, str_repeat("\0", 100) . ($version === 5 ? "\x97" : "\x80")), '订阅上限没有按 Topic 返回失败');
        fclose($limited);
        $reconnected = mqttSocket($port, $certificate);
        mqttWrite($reconnected, mqttConnect($version, 'subscription-limit'));
        mqttAck($reconnected, $version);
        mqttWrite($reconnected, mqttSubscription($version, 'example/new'));
        expect(mqttRead($reconnected) === mqttSubscriptionAck($version), '重连遗留了旧连接的订阅配额');
        fclose($reconnected);
        $cases++;
    }

    $subscriber = mqttSocket($port, $certificate);
    $publisher = mqttSocket($port, $certificate);
    mqttWrite($subscriber, mqttConnect(5, 'small-message-receiver', 0, "\x27\0\0\0\x80"));
    mqttWrite($publisher, mqttConnect(5, 'small-message-publisher', 0));
    mqttAck($subscriber, 5);
    mqttAck($publisher, 5);
    mqttWrite($subscriber, mqttSubscription(5, 'example/small', 1, 4));
    expect(mqttRead($subscriber) === mqttSubscriptionAck(5), 'No Local 订阅未成功');
    mqttWrite($subscriber, mqttPublish(5, 'example/small', 'self'));
    mqttQuiet($subscriber);
    mqttWrite($publisher, mqttPublish(5, 'example/small', str_repeat('x', 200)));
    mqttWrite($publisher, mqttPublish(5, 'example/small', 'expired', "\x02\0\0\0\0"));
    mqttWrite($publisher, mqttPublish(5, 'example/small', 'small'));
    expect(mqttRead($subscriber) === mqttPublish(5, 'example/small', 'small'), 'Maximum Packet Size/零到期消息丢弃错误或误关闭连接');
    mqttQuiet($subscriber);
    fclose($subscriber);
    fclose($publisher);
    $cases++;

    // 接收者停止读取，仍让发布者获得 PING；超过有界输出后只回收慢连接。
    $slow = mqttSocket($port, $certificate);
    $publisher = mqttSocket($port, $certificate);
    mqttWrite($slow, mqttConnect(5, 'slow-subscriber', 0));
    mqttWrite($publisher, mqttConnect(5, 'slow-publisher', 0));
    mqttAck($slow, 5);
    mqttAck($publisher, 5);
    mqttWrite($slow, mqttSubscription(5, 'example/slow'));
    expect(mqttRead($slow) === mqttSubscriptionAck(5), '慢客户端订阅失败');
    $large = mqttPublish(5, 'example/slow', str_repeat('s', 1048557));
    expect(strlen($large) === 1048576, '最大消息测试必须恰好为 1 MiB');
    for ($index = 0; $index < 12; $index++) {
        mqttWrite($publisher, $large);
        mqttQuiet($publisher);
    }
    stream_set_timeout($slow, 3);
    $received = 0;
    while (!feof($slow)) {
        $bytes = fread($slow, 65536);
        expect($bytes !== '' || feof($slow), '慢接收者没有在输出预算内回收');
        $received += strlen($bytes);
        expect($received < 12 * strlen($large), '慢客户端没有受到背压约束');
    }
    fclose($slow);
    mqttQuiet($publisher);
    fclose($publisher);
    return $cases + 1;
}

/** 全部断言通过真实网络公开入口；没有直接调用协议私有解析器。 */
/** 双向连接别名与完整报文边界，不借用组件编解码器。 */
function mqttAliasCases(int $port, ?string $certificate): int
{
    $cases = 0;
    $publisher = mqttSocket($port, $certificate);
    $subscriber = mqttSocket($port, $certificate);
    mqttWrite($publisher, mqttConnect(5, 'alias-p', 10, "\x22\0\0"));
    mqttWrite($subscriber, mqttConnect(5, 'alias-s', 10, "\x22\0\x01"));
    $ack = mqttAck($publisher, 5);
    expect(str_contains($ack, "\x22\0\x20") && str_contains($ack, "\x21\0\x20")
        && str_contains($ack, "\x27\0\x10\0\0"), 'CONNACK 未声明独立的接收别名、额度和完整包上限');
    mqttAck($subscriber, 5);
    $topic = 'example/alias/first';
    mqttWrite($subscriber, mqttSubscription(5, $topic));
    expect(mqttRead($subscriber) === mqttSubscriptionAck(5), '别名订阅失败');
    $properties = "\x26" . mqttField('k') . mqttField('first') . "\x09" . mqttField("\0\xff")
        . "\x26" . mqttField('k') . mqttField('second');
    mqttWrite($publisher, mqttPublish(5, $topic, 'establish', "\x23\0\x20" . $properties));
    expect(mqttRead($subscriber) === mqttPublish(5, $topic, 'establish', $properties . "\x23\0\x01"), '入站别名未剥除或出站别名未独立分配');
    mqttWrite($publisher, mqttPublish(5, '', 'reuse', $properties . "\x23\0\x20"));
    expect(mqttRead($subscriber) === mqttPublish(5, '', 'reuse', $properties . "\x23\0\x01"), '别名复用改变消息或属性顺序');
    $cases += 3;
    // 出站上限为一不影响入站别名32；已满时新 Topic 使用完整名称。
    $other = 'example/alias/other';
    mqttWrite($subscriber, mqttSubscription(5, $other));
    expect(mqttRead($subscriber) === mqttSubscriptionAck(5), '替换别名订阅失败');
    mqttWrite($publisher, mqttPublish(5, $other, 'replace', "\x23\0\x20"));
    expect(mqttRead($subscriber) === mqttPublish(5, $other, 'replace'), '出站别名超过接收者上限');
    mqttWrite($publisher, mqttPublish(5, '', 'replacement', "\x23\0\x20"));
    expect(mqttRead($subscriber) === mqttPublish(5, $other, 'replacement'), '入站映射没有替换');
    mqttQuiet($publisher);
    mqttQuiet($subscriber);
    fclose($publisher);
    fclose($subscriber);
    $cases += 2;
    // 相同 Client ID 重连不能复用前一条网络连接的入站映射。
    foreach ([[0, ''], [33, 'example/alias/invalid'], [32, '']] as [$alias, $name]) {
        $socket = mqttSocket($port, $certificate);
        mqttWrite($socket, mqttConnect(5, 'alias-p'));
        mqttAck($socket, 5);
        mqttWrite($socket, mqttPublish(5, $name, 'invalid', "\x23" . pack('n', $alias)));
        expect(mqttRead($socket) === "\xe0\x02\x94\0", '非法或跨连接别名引用没有拒绝');
        fclose($socket);
        $cases++;
    }
    foreach (["\x23\0\x01\x23\0\x01", "\x23\0"] as $invalid) {
        $socket = mqttSocket($port, $certificate);
        mqttWrite($socket, mqttConnect(5, 'alias-invalid'));
        mqttAck($socket, 5);
        mqttWrite($socket, mqttPublish(5, $topic, 'invalid', $invalid));
        expect(mqttRead($socket) === "\xe0\x02" . (strlen($invalid) === 2 ? "\x81" : "\x82") . "\0", '重复或截断别名属性未拒绝');
        fclose($socket);
        $cases++;
    }
    // 注册别名会超上限时回退完整普通编码；失败预览不得留下未发送的映射。
    $publisher = mqttSocket($port, $certificate);
    $subscriber = mqttSocket($port, $certificate);
    mqttWrite($publisher, mqttConnect(5, 'alias-size-p'));
    mqttWrite($subscriber, mqttConnect(5, 'alias-s', 10, "\x22\0\x01\x27\0\0\0\x80"));
    mqttAck($publisher, 5);
    mqttAck($subscriber, 5);
    mqttWrite($subscriber, mqttSubscription(5, $topic));
    expect(mqttRead($subscriber) === mqttSubscriptionAck(5), '大小边界订阅失败');
    $payload = str_repeat('x', 128 - strlen(mqttPublish(5, $topic, '')));
    $wire = mqttPublish(5, $topic, $payload);
    expect(strlen($wire) === 128, '普通最大包夹具长度错误');
    mqttWrite($publisher, $wire);
    expect(mqttRead($subscriber) === $wire, '增加别名导致合法完整包被丢弃');
    mqttWrite($publisher, mqttPublish(5, $topic, 'register'));
    expect(mqttRead($subscriber) === mqttPublish(5, $topic, 'register', "\x23\0\x01"), '未发送的映射被误登记或出站别名跨连接残留');
    mqttWrite($publisher, mqttPublish(5, $topic, str_repeat('z', 119)));
    expect(mqttRead($subscriber) === mqttPublish(5, '', str_repeat('z', 119), "\x23\0\x01"), '已知别名没有缩短完整包');
    mqttQuiet($publisher);
    mqttQuiet($subscriber);
    fclose($publisher);
    fclose($subscriber);
    $cases += 3;
    // 合法1MiB入站短别名展开后超限：继续处理发布者，出站各自遵守完整包限制。
    $topic = 'example/' . str_repeat('t', 1024);
    $publisher = mqttSocket($port, $certificate);
    $subscriber = mqttSocket($port, $certificate);
    $plain = mqttSocket($port, $certificate);
    mqttWrite($publisher, mqttConnect(5, 'alias-expanded-p'));
    mqttWrite($subscriber, mqttConnect(5, 'alias-expanded-s', 10, "\x22\0\x01"));
    mqttWrite($plain, mqttConnect(4, 'alias-expanded-legacy'));
    mqttAck($publisher, 5);
    mqttAck($subscriber, 5);
    mqttAck($plain, 4);
    mqttWrite($subscriber, mqttSubscription(5, $topic));
    mqttWrite($plain, mqttSubscription(4, $topic));
    expect(mqttRead($subscriber) === mqttSubscriptionAck(5) && mqttRead($plain) === mqttSubscriptionAck(4), '展开边界订阅失败');
    mqttWrite($publisher, mqttPublish(5, $topic, 'register', "\x23\0\x01"));
    expect(mqttRead($subscriber) === mqttPublish(5, $topic, 'register', "\x23\0\x01")
        && mqttRead($plain) === mqttPublish(4, $topic, 'register'), '展开边界映射建立失败');
    $large = mqttPublish(5, '', str_repeat('b', 1048566), "\x23\0\x01");
    expect(strlen($large) === 1048576, '别名最大完整包夹具不为1MiB');
    mqttWrite($publisher, $large);
    expect(mqttRead($subscriber) === $large, '合法短别名入站被展开长度错误拒绝');
    mqttQuiet($publisher);
    mqttQuiet($plain);
    mqttQuiet($subscriber);
    fclose($publisher);
    fclose($subscriber);
    fclose($plain);
    return $cases + 1;
}

/** CONNECT字段按标志存在；格式错误不得进入认证或接受后续合并报文。 */
function mqttConnectFieldCases(int $port, ?string $certificate): int
{
    $cases = 0;
    foreach ([5, 4] as $version) {
        $prefix = mqttField('MQTT') . chr($version);
        $tail = pack('n', 10) . ($version === 5 ? "\0" : '');
        $identity = mqttField('connect-fields');
        $credentials = mqttField('example') . mqttField('mqtt-test-secret');
        $inputs = [
            ['reserved-flag', $prefix . "\xc3" . $tail . $identity . $credentials, 0x81],
            ['will-qos-without-will', $prefix . "\xca" . $tail . $identity . $credentials, 0x82],
            ['will-retain-without-will', $prefix . "\xe2" . $tail . $identity . $credentials, 0x82],
            ['will-qos-three', $prefix . "\xde" . $tail . $identity . ($version === 5 ? "\0" : '')
                . mqttField('example/will') . mqttField('will') . $credentials, 0x81],
            ['missing-client-id', $prefix . "\xc2" . $tail, 0x81],
            ['truncated-client-id', $prefix . "\xc2" . $tail . "\0\x05id", 0x81],
            ['missing-username', $prefix . "\x82" . $tail . $identity, 0x81],
            ['missing-password', $prefix . "\xc2" . $tail . $identity . mqttField('example'), 0x81],
            ['unflagged-username', $prefix . "\x02" . $tail . $identity . mqttField('example'), 0x81],
            ['unflagged-password', $prefix . "\x82" . $tail . $identity . $credentials, 0x81],
            ['unflagged-will', $prefix . "\x02" . $tail . $identity . mqttField('example/will') . mqttField('will'), 0x81],
            ['missing-will-topic', $prefix . "\x06" . $tail . $identity . ($version === 5 ? "\0" : ''), 0x81],
            ['missing-will-payload', $prefix . "\x06" . $tail . $identity . ($version === 5 ? "\0" : '') . mqttField('example/will'), 0x81],
            ['username-invalid-utf8', $prefix . "\xc2" . $tail . $identity . mqttField("\xed\xa0\x80") . mqttField('mqtt-test-secret'), 0x81],
        ];
        if ($version === 4) {
            $inputs[] = ['password-without-username', $prefix . "\x42" . $tail . $identity . mqttField('mqtt-test-secret'), 0x82];
        }
        foreach ($inputs as [$name, $body, $reason]) {
            $socket = mqttSocket($port, $certificate);
            try {
                mqttWrite($socket, mqttPacket(0x10, $body) . "\xc0\0");
                $response = mqttRead($socket);
                expect(
                    $version === 4 ? $response === '' : $response === mqttPacket(0x20, "\0" . chr($reason) . "\0"),
                    'CONNECT字段分类错误：' . $version . '/' . $name . '/' . bin2hex($response)
                );
                expect(mqttRead($socket) === '', '非法CONNECT后仍处理报文：' . $name);
                $cases++;
            } finally {
                fclose($socket);
            }
        }
        foreach (['0', 'a', 'Z', '0123456789abcdefghijklm'] as $identifier) {
            $socket = mqttSocket($port, $certificate);
            try {
                mqttWrite($socket, mqttConnect($version, $identifier) . "\xc0\0");
                mqttAck($socket, $version);
                expect(mqttRead($socket) === "\xd0\0", '合法1或23字节字母数字Client ID不能建立连接');
                $cases++;
            } finally {
                fclose($socket);
            }
        }
        foreach ([mqttField('mqtt'), mqttField('MQIsdp')] as $protocol) {
            $socket = mqttSocket($port, $certificate);
            try {
                mqttWrite($socket, mqttPacket(0x10, $protocol . chr($version) . "\xc2" . $tail . $identity . $credentials));
                expect(mqttRead($socket) === '', '错误协议名仍按MQTT接入');
                $cases++;
            } finally {
                fclose($socket);
            }
        }
    }
    foreach (["\xc0\0", "\xe0\0", mqttPublish(5, 'example/first', 'x'), mqttSubscription(5, 'example/first')] as $first) {
        $socket = mqttSocket($port, $certificate);
        try {
            mqttWrite($socket, $first . mqttConnect(5));
            expect(mqttRead($socket) === '', '非CONNECT首包之后仍接受同流CONNECT');
            $cases++;
        } finally {
            fclose($socket);
        }
    }
    return $cases;
}

/** 以真实连接覆盖 MQTT 3.1.1/5 的分片、粘包、认证与错误输入，返回已完成用例数。 */
function mqttWireCases(int $port, ?string $certificate): int
{
    $cases = 0;
    foreach ([4, 5] as $version) {
        $socket = mqttSocket($port, $certificate);
        $wire = mqttConnect($version, '分段-' . $version);
        for ($position = 0; $position < strlen($wire); $position++) {
            mqttWrite($socket, $wire[$position]);
            usleep(500);
        }
        mqttAck($socket, $version);
        mqttWrite($socket, str_repeat("\xc0\x00", 70));
        for ($ping = 0; $ping < 70; $ping++) {
            expect(mqttRead($socket) === "\xd0\x00", '合并报文或循环公平预算丢失 PING');
        }
        mqttWrite($socket, "\xe0\x00");
        expect(mqttRead($socket) === '', '正常 DISCONNECT 没有关闭');
        fclose($socket);
        $cases++;

        $socket = mqttSocket($port, $certificate);
        mqttWrite($socket, mqttConnect($version, 'wrong-password', 10, '', 'invalid'));
        $failure = mqttRead($socket);
        expect(ord($failure[3]) === ($version === 5 ? 0x86 : 4), '认证拒绝原因码错误');
        expect(mqttRead($socket) === '', '认证拒绝后未关闭');
        fclose($socket);
        $cases++;

        $socket = mqttSocket($port, $certificate);
        mqttWrite($socket, mqttConnect($version, 'keepalive', 1));
        mqttAck($socket, $version);
        $start = microtime(true);
        usleep(800000);
        mqttWrite($socket, "\xc0");
        $expired = mqttRead($socket);
        $elapsed = microtime(true) - $start;
        expect($version === 5 ? $expired === "\xe0\x02\x8d\x00" : $expired === '', 'Keep Alive 超时行为错误');
        expect($elapsed >= 1.3 && $elapsed < 2.5, '半包延长了 Keep Alive 或未按 1.5 倍超时');
        fclose($socket);
        $cases++;

        foreach (["\x11\x00", "\xc1\x00", "\xc0\x01\x00", "\xc0\x80\x80\x80\x80", "\xc0\x80\x00",
            "\xe0\x01\x7f", mqttConnect($version), "\xc0\xff\xff\x7f"] as $malformed) {
            $socket = mqttSocket($port, $certificate);
            mqttWrite($socket, mqttConnect($version, 'malformed'));
            mqttAck($socket, $version);
            mqttWrite($socket, $malformed);
            $response = mqttRead($socket);
            expect($version === 4 ? $response === '' : str_starts_with($response, "\xe0\x02"), '畸形控制报文未拒绝：' . bin2hex($malformed));
            fclose($socket);
            $cases++;
        }

        // OASIS Table 2-2：逐种控制包翻转每个保留位；格式错误必须与有效报文的顺序错误分开。
        $invalidHeaders = [0x00, 0x06, 0x36, 0x37, 0x3e, 0x3f, 0x38, 0x39];
        foreach ([0x10, 0x20, 0x40, 0x50, 0x62, 0x70, 0x82, 0x90, 0xa2, 0xb0, 0xc0, 0xd0, 0xe0, 0xf0] as $validHeader) {
            foreach ([1, 2, 4, 8] as $reservedBit) {
                $invalidHeaders[] = $validHeader ^ $reservedBit;
            }
        }
        $classification = array_map(static fn (int $header): array => [chr($header) . "\0", 0x81], $invalidHeaders);
        // SUBSCRIBE保留位用完整可解析的正文，排除“只有截断正文”造成的假阳性。
        $classification[] = [mqttPacket(0x80, "\0\x01" . ($version === 5 ? "\0" : '') . mqttField('example/header') . "\0"), 0x81];
        $classification[] = [mqttConnect($version), 0x82];
        $classification[] = ["\x20" . ($version === 5 ? "\x03\0\0\0" : "\x02\0\0"), 0x82];
        if ($version === 5) {
            $classification[] = ["\xf0\0", 0x82];
        }
        foreach ($classification as [$wire, $reason]) {
            $socket = mqttSocket($port, $certificate);
            try {
                mqttWrite($socket, mqttConnect($version, 'header-classification'));
                mqttAck($socket, $version);
                mqttWrite($socket, $wire);
                expect(mqttRead($socket) === ($version === 5 ? "\xe0\x02" . chr($reason) . "\0" : ''), '固定头格式与协议顺序原因码混淆：' . bin2hex($wire));
                expect(mqttRead($socket) === '', '错误控制响应之后连接未关闭：' . bin2hex($wire));
                $cases++;
            } finally {
                fclose($socket);
            }
        }

        foreach (["a\0b", "\xc0\xaf", "\xed\xa0\x80"] as $invalidId) {
            $socket = mqttSocket($port, $certificate);
            mqttWrite($socket, mqttConnect($version, $invalidId));
            $response = mqttRead($socket);
            expect($version === 4 ? $response === '' : (strlen($response) >= 4 && ord($response[3]) === 0x81), '非法 UTF-8 未拒绝');
            fclose($socket);
            $cases++;
        }
    }
    foreach (["\x21\x00\x00", "\x27\x00\x00\x00\x00", "\x17\x02", "\x11\x00\x00\x00\x00\x11\x00\x00\x00\x00",
        "\x16\x00\x01x", "\x12\x00\x01x"] as $property) {
        $socket = mqttSocket($port, $certificate);
        mqttWrite($socket, mqttConnect(5, 'properties', 10, $property));
        $response = mqttRead($socket);
        expect(strlen($response) >= 4 && ord($response[3]) === 0x82, '非法属性未按协议拒绝');
        fclose($socket);
        $cases++;
    }
    $socket = mqttSocket($port, $certificate);
    mqttWrite($socket, mqttConnect(5, '', 0, "\x26" . mqttField('同名') . mqttField('a') . "\x26" . mqttField('同名') . mqttField('b')));
    $ack = mqttAck($socket, 5);
    expect(str_contains($ack, "\x12\x00\x18"), '空标识未返回 Assigned Client Identifier');
    usleep(1700000);
    mqttWrite($socket, "\xc0\x00");
    expect(mqttRead($socket) === "\xd0\x00", '零 Keep Alive 被擅自改成固定心跳');
    fclose($socket);
    $cases++;

    foreach ([3, 6] as $unsupported) {
        $socket = mqttSocket($port, $certificate);
        mqttWrite($socket, mqttConnect($unsupported));
        $response = mqttRead($socket);
        expect(strlen($response) >= 4 && ord($response[3]) === ($unsupported < 5 ? 1 : 0x84), '不支持的协议版本未拒绝');
        fclose($socket);
        $cases++;
    }

    // 属性填充使整个合法 CONNECT 恰好达到 1 MiB，不能只计算载荷而遗漏报头。
    $properties = '';
    $property = "\x26" . mqttField('k') . mqttField(str_repeat('x', 65535));
    for ($index = 0; $index < 15; $index++) {
        $properties .= $property;
    }
    $baseLength = strlen(mqttConnect(5, 'maximum', 10, $properties));
    $properties .= "\x26" . mqttField('k') . mqttField(str_repeat('x', 1048576 - $baseLength - 6));
    $maximum = mqttConnect(5, 'maximum', 10, $properties);
    expect(strlen($maximum) === 1048576, '测试最大完整 Control Packet 编码错误');
    for ($iteration = 0; $iteration < 3; $iteration++) {
        $socket = mqttSocket($port, $certificate);
        // 最大报文后紧接另一个控制报文，覆盖流预读及描述符重用后的就绪通知。
        mqttWrite($socket, $maximum . "\xc0\0");
        mqttAck($socket, 5);
        expect(mqttRead($socket) === "\xd0\0", '最大报文后的 PINGRESP 丢失');
        fclose($socket);
        $cases++;
    }
    // CONNECT在同一TLS记录中偏移最大PUBLISH的分段边界，末尾PING可能只留在SSL解密缓冲。
    $prefix = mqttField('example/coalesced-maximum') . "\0";
    $publish = mqttPacket(0x30, $prefix . str_repeat('x', 1048572 - strlen($prefix)));
    expect(strlen($publish) === 1048576, '合并报文的最大PUBLISH编码错误');
    $socket = mqttSocket($port, $certificate);
    mqttWrite($socket, mqttConnect(5, 'coalesced-maximum') . $publish . "\xc0\0");
    mqttAck($socket, 5);
    expect(mqttRead($socket) === "\xd0\0", '合并TLS报文后的PINGRESP丢失');
    fclose($socket);
    $cases++;

    $socket = mqttSocket($port, $certificate);
    mqttWrite($socket, mqttConnect(5, 'small-receiver', 10, "\x27\x00\x00\x00\x04"));
    expect(mqttRead($socket) === '', '服务端发送了超过接收者 Maximum Packet Size 的响应');
    fclose($socket);
    $cases++;

    $socket = mqttSocket($port, $certificate);
    mqttWrite($socket, mqttConnect(5, 'binary-password', 10, '', "\0\xff\xed\xa0\x80"));
    $response = mqttRead($socket);
    expect(ord($response[3]) === 0x86, '二进制密码被错误当作 UTF-8 协议畸形');
    fclose($socket);
    $cases++;

    if ($certificate === null) {
        $slow = mqttSocket($port);
        mqttWrite($slow, mqttConnect(5, 'partial-budget', 0));
        mqttAck($slow, 5);
        mqttWrite($slow, "\xc0");
        stream_set_timeout($slow, 18);
        $idle = mqttSocket($port);
        mqttWrite($idle, "\x10");
        stream_set_timeout($idle, 12);
        $healthy = mqttSocket($port);
        mqttWrite($healthy, mqttConnect(5, 'other-client'));
        mqttAck($healthy, 5);
        fclose($healthy);
        expect(mqttRead($idle) === '', '未完成 CONNECT 无限保留连接');
        expect(mqttRead($slow) === "\xe0\x02\x81\x00", '零 Keep Alive 使半包缓冲失去独立截止');
        fclose($idle);
        fclose($slow);
        $cases += 2;
    }

    $socket = mqttSocket($port, $certificate);
    mqttWrite($socket, mqttConnect(5, 'enhanced-auth', 10, "\x15" . mqttField('unsupported')));
    $response = mqttRead($socket);
    expect(ord($response[3]) === 0x8c, '不支持的增强认证未返回 Bad Authentication Method');
    fclose($socket);
    $cases++;

    $first = mqttSocket($port, $certificate);
    mqttWrite($first, mqttConnect(5, 'duplicate-id'));
    mqttAck($first, 5);
    $second = mqttSocket($port, $certificate);
    mqttWrite($second, mqttConnect(5, 'duplicate-id'));
    mqttAck($second, 5);
    expect(mqttRead($first) === "\xe0\x02\x8e\x00", '客户端标识接管没有隔离旧连接');
    mqttWrite($second, "\xc0\x00");
    expect(mqttRead($second) === "\xd0\x00", '新连接不能正常使用');
    fclose($first);
    fclose($second);
    return $cases + 1;
}

/** 仅替换独立消费者的公开策略/观察接口，让真实网络检查作用域与串行收尾。 */
function mqttScopeApplication(string $source): string
{
    $start = 'new Broker(new ExampleMqttAccess(), new BrokerOptions(';
    $end = "    });\n    \$broker->serve";
    expect(substr_count($source, $start) === 1 && substr_count($source, $end) === 1, '作用域装配入口变化');
    $source = str_replace($start, "new Broker(new ScopeMqttAccess(), new BrokerOptions(callbackSeconds: getenv('MQTT_SCOPE_TIMEOUT') === '1' ? 0.05 : 30.0,", $source);
    return str_replace($end, "    }, observer: new ScopeMqttObserver());\n    \$broker->serve", $source) . <<<'PHP'

final class ScopeMqttAccess implements \Type\Mqtt\AccessPolicy
{
    private ExampleMqttAccess $access;
    private ?\Type\Runtime\ExecutionScope $previous = null;
    private bool $childActive = false;
    public function __construct()
    {
        $this->access = new ExampleMqttAccess();
    }
    public function authenticate(ConnectPacket $connect, string $peer, bool $secure): bool
    {
        $scope = \Type\Runtime\ExecutionScope::current();
        if ($this->childActive || ($this->previous !== null && $this->previous->state() !== 'closed')
            || $scope->binding('tenant_id') !== null) {
            throw new RuntimeException('mqtt_scope_not_isolated');
        }
        $this->previous = $scope;
        $scope->run(function (\Type\Runtime\ExecutionScope $current): void {
            $current->spawn(static function (\Type\Runtime\ExecutionScope $child) use ($current): void {
                if (\Type\Runtime\ExecutionScope::current() !== $child || $child === $current
                    || $child->binding('tenant_id') !== 'verified-tenant') {
                    throw new RuntimeException('mqtt_child_scope_invalid');
                }
                \Swoole\Coroutine::sleep(0.001);
            })->await();
        }, ['tenant_id' => 'verified-tenant']);
        if ($scope->binding('tenant_id') !== null) {
            throw new RuntimeException('mqtt_binding_not_restored');
        }
        // 不主动 await：下一次认证必须等本次入口真正收尾，即使本次认证抛错。
        $this->childActive = true;
        $seconds = $connect->clientId === 'scope-cleanup-late' ? 5.2 : (in_array($connect->clientId, ['scope-deadline', 'scope-signal'], true) ? 0.15 : 0.02);
        $scope->spawn(function (\Type\Runtime\ExecutionScope $child) use ($seconds): void {
            \Swoole\Coroutine::sleep($seconds);
            $this->childActive = false;
        });
        if ($connect->clientId === 'scope-deadline') {
            \Swoole\Coroutine::sleep(0.08);
            $scope->assertActive();
        }
        \Swoole\Coroutine::sleep(0.001);
        if ($connect->clientId === 'scope-throw') {
            throw new RuntimeException('mqtt_scope_expected_failure');
        }
        return $this->access->authenticate($connect, $peer, $secure);
    }
    public function authorize(ConnectPacket $connect, string $topic, string $action, int $qos): bool
    {
        if (\Type\Runtime\ExecutionScope::current()->binding('tenant_id') !== null) {
            throw new RuntimeException('mqtt_authorization_binding_leaked');
        }
        return $this->access->authorize($connect, $topic, $action, $qos);
    }
}

final class ScopeMqttObserver implements \Type\Mqtt\ConnectionObserver
{
    public function connected(string $clientId, string $username, string $ownerId, int $observedAt): void { $this->check(); }
    public function disconnected(string $clientId, string $username, string $ownerId, int $observedAt, int $reason): void { $this->check(); }
    public function heartbeat(int $observedAt): void { $this->check(); }
    public function stopped(int $observedAt): void { $this->check(); }
    private function check(): void
    {
        if (\Type\Runtime\ExecutionScope::current()->binding('tenant_id') !== null) {
            throw new RuntimeException('mqtt_observer_binding_leaked');
        }
    }
}
PHP;
}

/** 同时提交多连接认证，验证全局串行及异常之后的独立作用域。 */
function mqttScopeCases(int $port, ?string $certificate): int
{
    $clients = [];
    try {
        foreach (['scope-first', 'scope-throw', 'scope-after-failure', 'scope-final'] as $id) {
            $clients[$id] = mqttSocket($port, $certificate);
            mqttWrite($clients[$id], mqttConnect(5, $id));
        }
        foreach ($clients as $id => $client) {
            if ($id === 'scope-throw') {
                $ack = mqttRead($client);
                expect(strlen($ack) >= 4 && ord($ack[0]) === 0x20 && ord($ack[3]) >= 0x80, '认证异常未拒绝');
            } else {
                mqttAck($client, 5);
                mqttQuiet($client);
            }
        }
    } finally {
        foreach ($clients as $client) {
            fclose($client);
        }
    }
    return 4;
}

/** 截止与清理超时分别验证；资源仍被使用时不能运行下一事件或提前停止角色。 */
function mqttScopeLifecycleCases(string $consumer, array $command, array $environment): array
{
    $environment['MQTT_CERTIFICATE'] = '';
    $environment['MQTT_PRIVATE_KEY'] = '';
    $environment['MQTT_PRIVATE_KEY_PASSPHRASE'] = '';
    $results = [];
    foreach (['signal', 'deadline', 'cleanup-late'] as $case) {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        expect(is_resource($listener), '无法分配作用域专项端口');
        $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        fclose($listener);
        $environment['MQTT_SCOPE_TIMEOUT'] = $case === 'deadline' ? '1' : '0';
        $process = new Process([...$command, '--plaintext', '--port=' . $port], $consumer, $environment);
        $socket = null;
        try {
            $until = microtime(true) + 10;
            do {
                expect($process->running(), '作用域专项提前退出：' . $process->stderr());
                try {
                    $socket = mqttSocket($port, null);
                    break;
                } catch (RuntimeException) {
                    usleep(10000);
                }
            } while (microtime(true) < $until);
            expect(is_resource($socket), '作用域专项未就绪');
            $started = microtime(true);
            mqttWrite($socket, mqttConnect(5, 'scope-' . $case));
            if ($case === 'signal') {
                mqttAck($socket, 5);
                $stopped = $process->stop(3);
                expect(microtime(true) - $started >= 0.14, '停止信号提前结束了在途子任务');
            } elseif ($case === 'deadline') {
                $ack = mqttRead($socket);
                expect(strlen($ack) >= 4 && ord($ack[3]) >= 0x80, '截止后的认证仍然成功');
                fclose($socket);
                $socket = null;
                // 子任务持续 150ms，已超过维护事件的 50ms 排队预算；角色停止仍须等真实收尾。
                $stopped = $process->wait(3);
                expect(microtime(true) - $started >= 0.14, '维护超时提前释放了未完成子任务');
            } else {
                mqttAck($socket, 5);
                // 已发送 CONNACK 不表示后代结束；清理预算耗尽后应等待真实收尾再退出。
                $stopped = $process->wait(8);
                expect(microtime(true) - $started >= 5.15, '清理超时提前释放了角色');
            }
            expect($stopped->successful() && $stopped->stderr === '', '作用域专项退出失败：' . $stopped->stderr);
            $statistics = json_decode(trim($stopped->stdout), true, 512, JSON_THROW_ON_ERROR);
            expect($statistics['readyEvents'] === 0 && $statistics['pendingEventBytes'] === 0 && $statistics['connections'] === 0
                && $statistics['observationFailures'] === 0, '作用域专项遗留资源或停止观察失去上下文');
            expect($statistics['callbackFailures'] === ($case === 'signal' ? 0 : 1), '作用域专项未按预期处理异常');
            $results[$case] = ['passed' => true, 'statistics' => $statistics];
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
            $stopped = $process->stop();
            file_put_contents($consumer . '/scope-' . $case . '.log', $stopped->stdout . $stopped->stderr);
        }
    }
    return $results;
}

$readCaseCount = mqttReadCases();
if (in_array('--read-only', $argv, true)) {
    echo json_encode(['readCases' => $readCaseCount], JSON_THROW_ON_ERROR), "\n";
    return;
}

$root = dirname(__DIR__);
$native = in_array('--native', $argv, true);
$commitLifecycleOnly = in_array('--commit-lifecycle-only', $argv, true);
expect(!$commitLifecycleOnly || array_diff(array_slice($argv, 1), ['--native', '--commit-lifecycle-only']) === [], '持久生命周期专项不能与其他场景组合');
$connectFieldsOnly = in_array('--connect-fields-only', $argv, true);
expect(!$connectFieldsOnly || array_diff(array_slice($argv, 1), ['--native', '--connect-fields-only']) === [], '连接字段专项不能与其他场景组合');
expect(!in_array('--client-only', $argv, true) || in_array('--client', $argv, true), '--client-only必须同时选择--client');
expect(!in_array('--client-peer-only', $argv, true) || (in_array('--client', $argv, true) && in_array('--client-only', $argv, true)), '--client-peer-only必须同时选择--client和--client-only');
$clientCoroutine = in_array('--client-coroutine', $argv, true);
$clientThread = in_array('--client-thread', $argv, true);
expect(!$clientCoroutine || in_array('--client-peer-only', $argv, true), '协程客户端专项需要--client-peer-only');
expect(!$clientThread || ($clientCoroutine && $native), '业务线程客户端专项需要原生协程产物');
$conformance = in_array('--conformance-only', $argv, true);
expect(!$conformance || array_diff(array_slice($argv, 1), ['--native', '--conformance-only']) === [], '一致性套件使用独立授权装置，不能组合其他场景');
$identityOnly = in_array('--identity-only', $argv, true);
expect(!$identityOnly || array_diff(array_slice($argv, 1), ['--native', '--identity-only']) === [], '身份专项使用独立授权装置，不能组合其他场景');
$scopes = in_array('--scopes', $argv, true);
expect(!$scopes || array_diff(array_slice($argv, 1), ['--native', '--scopes']) === [], '作用域专项使用独立授权装置，不能组合其他场景');
$consumer = $root . '/build/mqtt-consumer-' . bin2hex(random_bytes(5));
expect(mkdir($consumer . '/app', 0700, true), '无法创建独立 MQTT 消费者');
$toolchain = json_decode(file_get_contents($root . '/toolchain.lock.json'), true, 512, JSON_THROW_ON_ERROR);
$repositories = [];
foreach (['type-mqtt', 'type-runtime', 'type-orm', 'type-orm-pgsql', 'type-build'] as $package) {
    $repositories[] = ['type' => 'path', 'url' => '../../plugin/' . $package,
        'options' => ['symlink' => false, 'versions' => ['zoujingli/' . $package => '1.0.x-dev']]];
}
$composer = ['name' => 'type-tests/mqtt-consumer', 'type' => 'project', 'license' => 'Apache-2.0',
    'require' => ['zoujingli/type-mqtt' => '~1.0.0@dev', 'zoujingli/type-orm-pgsql' => '~1.0.0@dev'],
    'require-dev' => ['zoujingli/type-build' => '~1.0.0@dev', 'swoole/typephp' => $toolchain['typephp']['version'], 'swoole/phpx' => $toolchain['phpx']['version']],
    'autoload' => ['classmap' => ['app/main.php']], 'repositories' => $repositories,
    'minimum-stability' => 'dev', 'prefer-stable' => true, 'config' => ['allow-plugins' => false]];
file_put_contents($consumer . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
file_put_contents($consumer . '/package.json', json_encode(['name' => 'type-mqtt-client-test', 'private' => true,
    'dependencies' => ['mqtt' => '5.15.0']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
copy($root . '/examples/mqtt/main.php', $consumer . '/app/main.php');
if ($scopes) {
    file_put_contents($consumer . '/app/main.php', mqttScopeApplication(file_get_contents($consumer . '/app/main.php')));
}
if ($commitLifecycleOnly) {
    require_once __DIR__ . '/mqtt-commit-lifecycle.php';
    file_put_contents($consumer . '/app/main.php', mqttCommitLifecycleApplication(file_get_contents($consumer . '/app/main.php')));
}
if ($identityOnly) {
    require_once __DIR__ . '/mqtt-sessions.php';
    file_put_contents($consumer . '/app/main.php', mqttIdentityApplication($root));
}
if ($conformance) {
    require_once __DIR__ . '/mqtt-conformance.php';
    file_put_contents($consumer . '/app/main.php', mqttConformanceApplication($root));
}
if (in_array('--subscriptions', $argv, true) || in_array('--shared', $argv, true)) {
    require_once __DIR__ . '/mqtt-subscriptions.php';
    // 独立消费者使用公开 AccessPolicy 注入特殊 Topic/权限测试，不向组件添加测试开关。
    file_put_contents($consumer . '/app/main.php', mqttSubscriptionApplication($root));
}
if (in_array('--qos1', $argv, true) || in_array('--qos1-only', $argv, true) || in_array('--qos2', $argv, true) || in_array('--retained', $argv, true)) {
    require_once __DIR__ . '/mqtt-qos1.php';
    file_put_contents($consumer . '/app/main.php', mqttOrderingApplication(file_get_contents($consumer . '/app/main.php')));
}
if (in_array('--client', $argv, true)) {
    require_once __DIR__ . '/mqtt-client.php';
    file_put_contents($consumer . '/app/main.php', mqttClientApplication($root, file_get_contents($consumer . '/app/main.php')));
}
copy($root . '/toolchain.lock.json', $consumer . '/toolchain.lock.json');
$configuration = ['name' => 'type-mqtt-connection', 'entry' => 'app/main.php', 'sources' => ['app/main.php'],
    'output' => 'build/mqtt/type-app', 'build-directory' => 'build/mqtt/compiler'];
$configuration['runtime'] = [PHP_OS_FAMILY => ['extensions' => ['swoole']]];
if ($native && is_string(getenv('TYPE_SWOOLE_MODULE')) && getenv('TYPE_SWOOLE_MODULE') !== '') {
    $module = realpath(getenv('TYPE_SWOOLE_MODULE'));
    expect(is_string($module) && is_file($module), '指定的 Swoole 运行模块不存在');
    expect(copy($module, $consumer . '/swoole.so'), '无法固定 Swoole 运行模块');
    $configuration['runtime'][PHP_OS_FAMILY]['modules']['swoole'] = [
        'file' => 'swoole.so', 'sha256' => hash_file('sha256', $consumer . '/swoole.so'),
    ];
}
if ($clientThread) {
    $configuration['threads'] = ['client-peer' => 'clientPeerThread'];
}
file_put_contents($consumer . '/type-app.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
successful([getenv('COMPOSER_BINARY') ?: 'composer', 'install', '--no-interaction', '--no-scripts', '--no-plugins', '--no-progress'], $consumer);
expect(!is_link($consumer . '/vendor/zoujingli/type-mqtt') && !is_link($consumer . '/vendor/zoujingli/type-runtime'), '独立消费不能使用主仓生产软链接');
successful(['npm', 'install', '--ignore-scripts', '--no-audit', '--no-fund'], $consumer);
if ($native) {
    $buildOutput = successful([PHP_BINARY, $consumer . '/vendor/bin/type', $consumer . '/type-app.json'], $consumer);
    file_put_contents($consumer . '/build.log', $buildOutput);
    echo "MQTT 独立消费者全量 AOT 编译完成。\n";
    $report = json_decode(file_get_contents($consumer . '/build/mqtt/type-app.build.json'), true, 512, JSON_THROW_ON_ERROR);
    $production = array_keys($report['production-packages']);
    sort($production);
    expect($production === ['zoujingli/type-mqtt', 'zoujingli/type-orm', 'zoujingli/type-orm-pgsql', 'zoujingli/type-runtime'], '独立 MQTT 编译生产依赖不完整或混入业务');
    foreach ($report['sources'] as $source) {
        expect(str_starts_with($source, $consumer . '/'), '独立 MQTT 仍编译主仓源码');
    }
    $command = nativeCommand($consumer . '/build/mqtt/type-app');
    $workerCommand = $command;
    if (PHP_OS_FAMILY === 'Darwin') {
        // 内核禁止原生进程读取主仓/独立消费者源码及生成实现；只放行搬迁后的数据配置。
        $runtime = $consumer . '/runtime';
        expect(mkdir($runtime, 0700), '无法创建无源码运行目录');
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
            $consumer . '/app/main.php', $consumer . '/vendor/autoload.php', $root . '/composer.json'], $runtime) === "source-denied\n", 'MQTT 无源码边界未生效');
        $workerCommand = ['env', 'PHPRC=' . $runtime . '/php.ini', 'PHP_INI_SCAN_DIR=', $runtime . '/type-app'];
        $command = [...$policy, ...$workerCommand];
    }
} else {
    $runtimeOptions = [];
    if (successful([PHP_BINARY, '-r', 'echo extension_loaded("swoole") ? "yes" : "no";'], $consumer) === 'no') {
        $module = (string) (getenv('TYPE_SWOOLE_MODULE') ?: ini_get('extension_dir') . '/swoole.so');
        expect(is_file($module), '原生事件测试需要匹配SDK的Swoole模块');
        $runtimeOptions = ['-d', 'extension=' . $module, '-d', 'swoole.enable_library=On'];
    }
    $command = [PHP_BINARY, ...$runtimeOptions, '-r', 'require "vendor/autoload.php"; require "app/main.php"; main($argc, $argv);', '--'];
    $workerCommand = $command;
}
$environment = getenv();
$environment['CLIENT_COROUTINE'] = $clientCoroutine ? '1' : '0';
$environment['CLIENT_THREAD'] = $clientThread ? '1' : '0';
$environment['MQTT_PASSWORD'] = 'mqtt-test-secret';
$environment['MQTT_CERTIFICATE'] = '';
$environment['MQTT_PRIVATE_KEY'] = '';
$environment['MQTT_PRIVATE_KEY_PASSPHRASE'] = '';
$environment['MQTT_WORKER_COMMAND'] = '';
putenv('MQTT_PASSWORD=mqtt-test-secret');
$certificateConfiguration = $consumer . '/certificate.cnf';
file_put_contents($certificateConfiguration, "[req]\ndistinguished_name=dn\nx509_extensions=server\n[dn]\n[server]\nsubjectAltName=IP:127.0.0.1\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\nextendedKeyUsage=serverAuth\n");
$certificateOptions = ['config' => $certificateConfiguration, 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
$key = openssl_pkey_new($certificateOptions);
$request = openssl_csr_new(['commonName' => '127.0.0.1'], $key, $certificateOptions);
$certificateObject = openssl_csr_sign($request, null, $key, 1, $certificateOptions);
openssl_x509_export($certificateObject, $certificatePem);
openssl_pkey_export($key, $privatePem);
file_put_contents($consumer . '/certificate.pem', $certificatePem);
file_put_contents($consumer . '/private.pem', $privatePem);
chmod($consumer . '/private.pem', 0600);
$verified = [];
foreach ($commitLifecycleOnly || in_array('--qos1-only', $argv, true) || in_array('--session-only', $argv, true) || in_array('--will-only', $argv, true)
    || in_array('--session-shutdown-only', $argv, true)
    || in_array('--will-restart-only', $argv, true) || in_array('--will-failure-only', $argv, true)
    || in_array('--shared-only', $argv, true) || in_array('--client-only', $argv, true) || in_array('--cluster-only', $argv, true)
    || in_array('--capacity-only', $argv, true) || in_array('--connection-scale-only', $argv, true)
    || in_array('--conformance-only', $argv, true) || $identityOnly ? [] : [false, true] as $tls) {
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
    fclose($listener);
    $certificate = $tls ? $consumer . '/certificate.pem' : null;
    $environment['MQTT_CERTIFICATE'] = $certificate ?? '';
    $environment['MQTT_PRIVATE_KEY'] = $tls ? $consumer . '/private.pem' : '';
    $arguments = ['--port=' . $port, ...($tls ? [] : ['--plaintext'])];
    $process = new Process([...$command, ...$arguments], $consumer, $environment);
    try {
        $ready = false;
        $until = microtime(true) + 10;
        do {
            expect($process->running(), 'MQTT 进程提前退出：' . $process->stderr());
            try {
                $probe = mqttSocket($port, $certificate);
                mqttWrite($probe, mqttConnect(5, 'ready'));
                mqttAck($probe, 5);
                fclose($probe);
                $ready = true;
            } catch (RuntimeException) {
                usleep(10000);
            }
        } while (!$ready && microtime(true) < $until);
        expect($ready, 'MQTT 未通过真实 CONNECT 就绪检查：' . $process->stderr());
        $scopeCases = $scopes ? mqttScopeCases($port, $certificate) : 0;
        $connectFieldCaseCount = mqttConnectFieldCases($port, $certificate);
        $caseCount = $connectFieldsOnly ? 0 : mqttWireCases($port, $certificate);
        $messageCaseCount = $connectFieldsOnly ? 0 : mqttMessageCases($port, $certificate);
        $textCaseCount = $connectFieldsOnly ? 0 : mqttTextCases($port, $certificate);
        $aliasCaseCount = $connectFieldsOnly ? 0 : mqttAliasCases($port, $certificate);
        $subscriptionCaseCount = in_array('--subscriptions', $argv, true) ? mqttDelayedAccessCases($port, $certificate) + mqttSubscriptionCases($port, $certificate) : 0;
        if (!$connectFieldsOnly) {
            echo successful(['node', $root . '/tests/mqtt-standard-client.mjs', $consumer, (string) $port, $certificate ?? 'plain'], $consumer);
        }
        if ($tls) {
            $wrongPeer = @stream_socket_client(
                'tls://127.0.0.1:' . $port,
                $errno,
                $error,
                3,
                STREAM_CLIENT_CONNECT,
                stream_context_create(['ssl' => ['cafile' => $certificate, 'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => 'invalid.example']])
            );
            expect($wrongPeer === false, '客户端接受了不匹配的服务端证书');
            $untrusted = @stream_socket_client(
                'tls://127.0.0.1:' . $port,
                $errno,
                $error,
                3,
                STREAM_CLIENT_CONNECT,
                stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => '127.0.0.1']])
            );
            expect($untrusted === false, '客户端接受了不可信证书');
            $caseCount += 2;
        }
        $held = mqttSocket($port, $certificate);
        mqttWrite($held, mqttConnect(5, 'shutdown-with-subscription', 0));
        mqttAck($held, 5);
        mqttWrite($held, mqttSubscription(5, 'example/shutdown'));
        expect(mqttRead($held) === mqttSubscriptionAck(5), '退出用例未建立真实订阅');
        mqttWrite($held, "\x30\x7f\0\x10partial");
        $result = $process->stop(3);
        fclose($held);
        expect($result->successful() && $result->stderr === '', 'MQTT 停止或资源清理失败：' . $result->stderr);
        $statistics = json_decode(trim($result->stdout), true, 512, JSON_THROW_ON_ERROR);
        expect($statistics['connections'] === 0 && $statistics['stopping'] && $statistics['closed'] === $statistics['accepted'], 'MQTT 连接资源没有完整回收');
        expect($statistics['subscriptions'] === 0 && $statistics['bufferedBytes'] === 0, 'MQTT 订阅或半包/发送缓冲没有完整回收');
        expect(!$scopes || ($statistics['observationFailures'] === 0 && $statistics['readyEvents'] === 0
            && $statistics['pendingEventBytes'] === 0 && $statistics['callbackFailures'] === 0), '作用域、观察或事件清理失败');
        expect($connectFieldsOnly || ($statistics['delivered'] > 0 && $statistics['dropped'] > 0), '消息测试未覆盖交付及丢弃');
        $verified[$tls ? 'tls' : 'tcp'] = ['connect-field-cases' => $connectFieldCaseCount, 'wire-cases' => $caseCount,
            'message-cases' => $connectFieldsOnly ? 0 : $messageCaseCount + 1, 'text-cases' => $textCaseCount, 'alias-cases' => $aliasCaseCount, 'subscription-cases' => $subscriptionCaseCount,
            'scope-cases' => $scopeCases,
            'client' => $connectFieldsOnly ? 'raw-wire' : 'MQTT.js 5.15.0', 'client-version-pairs' => $connectFieldsOnly ? [] : [[4, 4], [4, 5], [5, 4], [5, 5]], 'statistics' => $statistics];
    } finally {
        $stopped = $process->stop();
        file_put_contents($consumer . '/' . ($tls ? 'tls' : 'tcp') . '-broker.log', $stopped->stdout . $stopped->stderr);
    }
}
if ($commitLifecycleOnly || in_array('--qos1', $argv, true) || in_array('--qos1-only', $argv, true) || in_array('--qos2', $argv, true) || in_array('--retained', $argv, true)) {
    require_once __DIR__ . '/mqtt-qos1.php';
    $verified['qos1'] = mqttQos1Cases($root, $consumer, $command, $workerCommand, $environment);
}
if (in_array('--shared', $argv, true)) {
    require_once __DIR__ . '/mqtt-shared.php';
    $verified['shared'] = mqttSharedCases($root, $consumer, $command, $workerCommand, $environment);
}
if (in_array('--capacity', $argv, true) || in_array('--capacity-only', $argv, true)) {
    require_once __DIR__ . '/mqtt-capacity.php';
    $verified['capacity'] = mqttCapacityCases($root, $consumer, $command, $workerCommand, $environment);
}
if (in_array('--connection-scale', $argv, true) || in_array('--connection-scale-only', $argv, true)) {
    require_once __DIR__ . '/mqtt-capacity.php';
    // 同机临时端口是共享预算，两个万连接发生器不能并行占用；其余协议和存储场景仍可并行。
    $scaleLock = fopen(sys_get_temp_dir() . '/typeapp-mqtt-connection-scale.lock', 'c');
    expect(is_resource($scaleLock), '无法取得连接规模验证的主机锁');
    try {
        $scaleDeadline = microtime(true) + 600;
        do {
            $scaleLocked = flock($scaleLock, LOCK_EX | LOCK_NB);
            if (!$scaleLocked) {
                expect(microtime(true) < $scaleDeadline, '等待其他连接规模验证超过十分钟');
                usleep(100000);
            }
        } while (!$scaleLocked);
        $verified['connection-scale'] = mqttConnectionScaleCases($consumer, $command, $environment);
    } finally {
        fclose($scaleLock);
    }
}
if (in_array('--session', $argv, true) || in_array('--session-only', $argv, true)) {
    require_once __DIR__ . '/mqtt-sessions.php';
    $verified['sessions'] = mqttSessionCases($root, $consumer, $command, $workerCommand, $environment);
}
if ($identityOnly) {
    $verified['identity'] = mqttIdentityCases($root, $consumer, $command, $workerCommand, $environment);
}
if (in_array('--session', $argv, true) || in_array('--session-only', $argv, true) || in_array('--session-shutdown-only', $argv, true)) {
    require_once __DIR__ . '/mqtt-sessions.php';
    $verified['session-shutdown'] = mqttSessionShutdownCases($root, $consumer, $command, $workerCommand, $environment);
}
if (in_array('--will', $argv, true) || in_array('--will-only', $argv, true) || in_array('--will-restart-only', $argv, true) || in_array('--will-failure-only', $argv, true)) {
    require __DIR__ . '/mqtt-will.php';
    $verified['wills'] = mqttWillCases($root, $consumer, $command, $workerCommand, $environment);
}
if (in_array('--client', $argv, true)) {
    $verified['client'] = in_array('--client-peer-only', $argv, true)
        ? ['independent-peer' => mqttClientPeerCases($consumer, $command, $environment)]
        : mqttClientCases($root, $consumer, $command, $workerCommand, $environment);
    if (in_array('--client-peer-only', $argv, true) && !$clientThread) {
        // 同一产物验证两种公开调用方式，避免只验证已有协程而遗漏默认非协程入口。
        $verified['client'][$clientCoroutine ? 'synchronous-entrypoint' : 'coroutine-entrypoint'] = mqttClientPeerCases(
            $consumer,
            $command,
            array_replace($environment, ['CLIENT_COROUTINE' => $clientCoroutine ? '0' : '1'])
        );
    }
}
if (in_array('--cluster', $argv, true) || in_array('--cluster-only', $argv, true)) {
    require_once __DIR__ . '/mqtt-cluster.php';
    $verified['cluster'] = mqttClusterCases($root, $consumer, $command, $workerCommand, $environment);
}
if ($conformance) {
    $verified['conformance'] = mqttConformanceCases($root, $consumer, $command, $workerCommand, $environment);
}
if ($scopes) {
    $verified['scope-lifecycle'] = mqttScopeLifecycleCases($consumer, $command, $environment);
}
$evidence = ['mode' => $native ? 'aot' : 'php', 'transport' => 'swoole', 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'),
    'read-cases' => $readCaseCount,
    'no-source-runtime' => $native && PHP_OS_FAMILY === 'Darwin' ? 'kernel-denied-production-and-generated-source' : 'not-verified',
    'checks' => $verified, 'build' => $native ? array_intersect_key($report, array_flip(['build-id', 'sha256', 'typephp', 'typephp-reference', 'phpx', 'phpx-reference', 'production-packages'])) : null];
file_put_contents($consumer . '/verification.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo ($native ? '原生' : 'PHP') . ' MQTT 独立安装与所选网络路径'
    . (isset($verified['conformance']) && $verified['conformance']['status'] !== 'passed' ? '完成，保留未通过项：' : '验证通过：')
    . substr($consumer, strlen($root) + 1) . "\n";
if ($conformance && $verified['conformance']['status'] !== 'passed') {
    exit(1);
}
