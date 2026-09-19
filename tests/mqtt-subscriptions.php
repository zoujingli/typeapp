<?php

declare(strict_types=1);

/** 独立应用保留完整示例入口，只替换公开 AccessPolicy 装配并追加测试消费者策略。 */
function mqttSubscriptionApplication(string $root): string
{
    $source = file_get_contents($root . '/examples/mqtt/main.php');
    expect(substr_count($source, 'new ExampleMqttAccess()') === 1, '独立授权装配入口变化');
    return str_replace('new ExampleMqttAccess()', 'new SubscriptionTestAccess()', $source) . <<<'PHP'


/** 独立消费者拥有的测试授权，不加入组件或示例生产实现。 */
final class SubscriptionTestAccess implements \Type\Mqtt\AccessPolicy
{
    private ExampleMqttAccess $example;

    public function __construct()
    {
        $this->example = new ExampleMqttAccess();
    }

    public function authenticate(ConnectPacket $connect, string $peer, bool $secure): bool
    {
        return $this->example->authenticate($connect, $peer, $secure);
    }

    public function authorize(ConnectPacket $connect, string $topic, string $action, int $qos): bool
    {
        if ($action === 'subscribe' && str_starts_with($connect->clientId, 'shared-scheduling-')) {
            // 消费者的有界同步授权会消耗空队列轮询间隔；两类持久投递仍须轮换取得工作机会。
            usleep(300000);
        }
        if ($action === 'publish' && str_starts_with($connect->clientId, 'subscription-delayed-')) {
            // 独立策略模拟有界外部授权，不能让已就绪的小控制响应被误判为慢接收方。
            usleep(1200000);
        }
        if ($action === 'subscribe' && str_starts_with($connect->clientId, 'shared-')
            && str_contains($topic, '/revoked-') && file_exists('shared-access-revoked')) {
            return false;
        }
        if (str_starts_with($connect->clientId, 'subscription-system-')) {
            return !str_starts_with($topic, 'denied/');
        }
        if (str_starts_with($connect->clientId, 'subscription-limited-') && str_starts_with($topic, 'example/private/')) {
            return false;
        }
        return $this->example->authorize($connect, $topic, $action, $qos);
    }
}
PHP;
}

/** 一个TCP写入包含先排队响应的PING及后续慢授权；两版协议都必须收到响应并继续连接。 */
function mqttDelayedAccessCases(int $port, ?string $certificate): int
{
    foreach ([4, 5] as $version) {
        $socket = mqttSocket($port, $certificate);
        try {
            mqttWrite($socket, mqttConnect($version, 'subscription-delayed-' . $version, 30));
            mqttAck($socket, $version);
            mqttWrite($socket, "\xc0\0" . mqttPublish($version, 'example/delayed', 'bounded-auth'));
            expect(mqttRead($socket) === "\xd0\0", '有界授权期间丢失已经排队的PINGRESP');
            mqttWrite($socket, "\xc0\0");
            expect(mqttRead($socket) === "\xd0\0", '慢授权错误关闭健康连接');
        } finally {
            fclose($socket);
        }
    }
    return 2;
}

/** 独立编码一组过滤器及一个 SUBSCRIBE 级标识，版本4完全省略属性。 */
function mqttSubscribeFilters(int $version, array $filters, int $subscriptionIdentifier = 0, int $packetId = 1): string
{
    $properties = $subscriptionIdentifier > 0 ? "\x0b" . mqttLength($subscriptionIdentifier) : '';
    $body = pack('n', $packetId) . ($version === 5 ? mqttLength(strlen($properties)) . $properties : '');
    foreach ($filters as $filter => $options) {
        $body .= mqttField((string) $filter) . chr($options);
    }
    return mqttPacket(0x82, $body);
}

/** 独立解析接收属性；订阅标识允许重复，其他原始属性字节顺序保留供断言。 */
function mqttDeliveryProperties(string $bytes): array
{
    $position = 0;
    $identifiers = [];
    $other = '';
    while ($position < strlen($bytes)) {
        $start = $position;
        $code = ord($bytes[$position++]);
        if ($code === 0x0b) {
            $value = 0;
            $multiple = 1;
            do {
                $byte = ord($bytes[$position++]);
                $value += ($byte & 127) * $multiple;
                $multiple *= 128;
            } while ($byte & 128);
            $identifiers[] = $value;
            continue;
        }
        if ($code === 0x01) {
            $position++;
        } elseif ($code === 0x02) {
            $position += 4;
        } elseif ($code === 0x23) {
            $position += 2;
        } else {
            expect(in_array($code, [0x03, 0x08, 0x09, 0x26], true), '未知交付属性：' . bin2hex($bytes));
            for ($index = 0; $index < ($code === 0x26 ? 2 : 1); $index++) {
                $length = unpack('n', substr($bytes, $position, 2))[1];
                $position += 2 + $length;
            }
        }
        $other .= substr($bytes, $start, $position - $start);
    }
    sort($identifiers);
    return ['identifiers' => $identifiers, 'other' => $other];
}

/** 普通 TCP/TLS 及两版协议，覆盖通配边界、权限、替换、重复标识和完整报文预算。 */
function mqttSubscriptionCases(int $port, ?string $certificate): int
{
    require_once __DIR__ . '/mqtt-retained.php';
    $cases = 0;
    $sockets = [];
    try {
        foreach ([4, 5] as $version) {
            $subscriber = mqttSocket($port, $certificate);
            $publisher = mqttSocket($port, $certificate);
            array_push($sockets, $subscriber, $publisher);
            mqttWrite($subscriber, mqttConnect($version, 'subscription-system-s', 0));
            mqttWrite($publisher, mqttConnect($version, 'subscription-system-p', 0));
            $connack = mqttAck($subscriber, $version);
            mqttAck($publisher, $version);
            expect($version === 4 || str_contains($connack, "\x28\x01\x29\x01\x2a\0"), 'CONNACK 订阅能力声明不准确');
            foreach ([['example/+', 'example/', true], ['example/+', 'example', false], ['example/+', 'example/a/b', false],
                ['example/#', 'example', true], ['example/#', 'example/', true], ['example/#', 'example//b', true],
                ['example/+/b', 'example//b', true], ['example/+/b', 'example/b', false],
                ['#', '$example/x', false], ['+/#', '$example/x', false], ['$example/#', '$example/x', true],
                ['#', '/', true], ['+/+', '/', true], ['/#', '/', true], ['/#', '//x', true],
                ['example/+', 'example/$value', true], ['example/Case', 'example/case', false],
                ['example/é', "example/e\xcc\x81", false], ['123', '123', true]] as [$filter, $topic, $match]) {
                mqttWrite($subscriber, mqttSubscribeFilters($version, [$filter => 0], 128));
                expect(mqttRead($subscriber) === mqttSubscriptionAck($version), '合法过滤器未授予：' . $filter);
                mqttWrite($publisher, mqttPublish($version, $topic, "\0\xffpayload"));
                mqttQuiet($publisher);
                if ($match) {
                    expect(mqttRead($subscriber) === mqttPublish($version, $topic, "\0\xffpayload", $version === 5 ? "\x0b\x80\x01" : ''), '通配交付错误：' . $filter . ' => ' . $topic);
                }
                mqttQuiet($subscriber);
                mqttWrite($subscriber, mqttSubscription($version, $filter, 1, 0, false));
                expect(mqttRead($subscriber) === mqttSubscriptionAck($version, "\0", 1, false), '通配取消失败');
                $cases++;
            }
            foreach (['example/++', 'example/a#', 'example/#/', 'example/+/a+'] as $filter) {
                $invalid = mqttSocket($port, $certificate);
                $sockets[] = $invalid;
                mqttWrite($invalid, mqttConnect($version, 'subscription-invalid'));
                mqttAck($invalid, $version);
                mqttWrite($invalid, mqttSubscription($version, $filter));
                expect(mqttRead($invalid) === ($version === 5 ? "\xe0\x02\x82\0" : ''), '非法通配未拒绝');
                fclose($invalid);
                $cases++;
            }
            if ($version === 4) {
                foreach ([4, 8, 16, 32] as $options) {
                    $invalid = mqttSocket($port, $certificate);
                    $sockets[] = $invalid;
                    mqttWrite($invalid, mqttConnect(4, 'subscription-legacy-options'));
                    mqttAck($invalid, 4);
                    mqttWrite($invalid, mqttSubscription(4, 'example/+', 1, $options));
                    expect(mqttRead($invalid) === '', '3.1.1 接受了 MQTT 5 订阅选项');
                    fclose($invalid);
                    $cases++;
                }
            }
            fclose($subscriber);
            fclose($publisher);
        }
        // 高频双向往返覆盖非阻塞空读与TLS内部缓冲的竞态，所有断言均观察真实标准报文。
        $subscriber = mqttSocket($port, $certificate);
        $publisher = mqttSocket($port, $certificate);
        array_push($sockets, $subscriber, $publisher);
        mqttWrite($subscriber, mqttConnect(5, 'subscription-exchange-s', 0));
        mqttWrite($publisher, mqttConnect(5, 'subscription-exchange-p', 0));
        mqttAck($subscriber, 5);
        mqttAck($publisher, 5);
        $exchange = mqttPublish(5, 'example/exchange', "binary\0\xff");
        for ($iteration = 0; $iteration < 2000; $iteration++) {
            mqttWrite($subscriber, mqttSubscription(5, 'example/#'));
            expect(mqttRead($subscriber) === mqttSubscriptionAck(5), '连续往返SUBACK丢失：' . $iteration);
            mqttWrite($publisher, $exchange);
            mqttQuiet($publisher);
            expect(mqttRead($subscriber) === $exchange, '连续往返交付丢失：' . $iteration);
            mqttQuiet($subscriber);
            mqttWrite($subscriber, mqttSubscription(5, 'example/#', 1, 0, false));
            expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\0", 1, false), '连续往返UNSUBACK丢失：' . $iteration);
            $cases++;
        }
        fclose($subscriber);
        fclose($publisher);
        $subscriber = mqttSocket($port, $certificate);
        $publisher = mqttSocket($port, $certificate);
        array_push($sockets, $subscriber, $publisher);
        mqttWrite($subscriber, mqttConnect(5, 'subscription-limited-live', 0));
        mqttWrite($publisher, mqttConnect(5, 'subscription-source', 0));
        mqttAck($subscriber, 5);
        mqttAck($publisher, 5);
        mqttWrite($subscriber, mqttSubscribeFilters(5, ['#' => 0, 'example/#' => 0], 7));
        expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\x87\0"), '通配放宽了前缀授权');
        mqttWrite($publisher, mqttPublish(5, 'example/private/x', 'denied'));
        mqttQuiet($publisher);
        mqttQuiet($subscriber);
        mqttWrite($publisher, mqttPublish(5, 'example/open', 'allowed'));
        expect(mqttRead($subscriber) === mqttPublish(5, 'example/open', 'allowed', "\x0b\x07"), '实际 Topic 拒绝删除了合法通配');
        $cases += 3;
        mqttWrite($subscriber, mqttSubscribeFilters(5, ['example/+' => 0], 8));
        expect(mqttRead($subscriber) === mqttSubscriptionAck(5), '重叠订阅失败');
        mqttWrite($subscriber, mqttSubscribeFilters(5, ['example/open' => 0], 7));
        expect(mqttRead($subscriber) === mqttSubscriptionAck(5), '精确重叠订阅失败');
        mqttWrite($publisher, mqttPublish(5, 'example/open', 'overlap'));
        $overlap = mqttRetainedRead($subscriber, 5);
        expect(mqttDeliveryProperties($overlap['properties'])['identifiers'] === [7, 7, 8], '重叠订阅遗漏、去重或重复交付标识');
        mqttQuiet($subscriber);
        $cases++;
        mqttWrite($subscriber, mqttSubscribeFilters(5, ['example/#' => 4, 'example/+' => 4], 9));
        expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\0\0"), 'No Local 替换失败');
        mqttWrite($subscriber, mqttPublish(5, 'example/open', 'self'));
        $self = mqttRetainedRead($subscriber, 5);
        expect(mqttDeliveryProperties($self['properties'])['identifiers'] === [7], 'No Local 未按匹配订阅分别排除');
        mqttQuiet($subscriber);
        $cases++;
        foreach (['example/#', 'example/+'] as $filter) {
            mqttWrite($subscriber, mqttSubscription(5, $filter, 1, 0, false));
            expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\0", 1, false), '重叠取消失败');
        }
        foreach ([1, 127, 128, 16383, 16384, 2097151, 2097152, 268435455, 0] as $identifier) {
            mqttWrite($subscriber, mqttSubscribeFilters(5, ['example/open' => 0], $identifier));
            expect(mqttRead($subscriber) === mqttSubscriptionAck(5), '替换订阅标识失败');
            mqttWrite($publisher, mqttPublish(5, 'example/open', 'identifier'));
            expect(mqttRead($subscriber) === mqttPublish(5, 'example/open', 'identifier', $identifier > 0 ? "\x0b" . mqttLength($identifier) : ''), '替换标识遗留或编码错误');
            mqttQuiet($subscriber);
            $cases++;
        }
        fclose($subscriber);
        $limited = mqttSocket($port, $certificate);
        $sockets[] = $limited;
        mqttWrite($limited, mqttConnect(5, 'subscription-size', 0, "\x27\0\0\0\x64\x22\0\x01"));
        mqttAck($limited, 5);
        mqttWrite($limited, mqttSubscribeFilters(5, ['example/size' => 0], 268435455));
        expect(mqttRead($limited) === mqttSubscriptionAck(5), '大小测试订阅失败');
        $properties = "\x0b\xff\xff\xff\x7f";
        $padding = 100 - strlen(mqttPublish(5, 'example/size', '', $properties));
        mqttWrite($publisher, mqttPublish(5, 'example/size', str_repeat('x', $padding + 1)));
        mqttQuiet($publisher);
        mqttQuiet($limited);
        mqttWrite($publisher, mqttPublish(5, 'example/size', str_repeat('x', $padding)));
        expect(mqttRead($limited) === mqttPublish(5, 'example/size', str_repeat('x', $padding), $properties), '订阅标识大小核验/别名回退错误');
        mqttWrite($publisher, mqttPublish(5, 'example/size', 'small'));
        expect(mqttRead($limited) === mqttPublish(5, 'example/size', 'small', "\x23\0\x01" . $properties), '未成功建立接收别名');
        mqttWrite($publisher, mqttPublish(5, 'example/size', str_repeat('z', $padding + 5)));
        expect(mqttRead($limited) === mqttPublish(5, '', str_repeat('z', $padding + 5), "\x23\0\x01" . $properties), '已知别名未将订阅标识计入完整长度');
        mqttQuiet($limited);
        $cases += 4;
        $many = mqttSocket($port, $certificate);
        $sockets[] = $many;
        mqttWrite($many, mqttConnect(5, 'subscription-many', 0));
        mqttAck($many, 5);
        $filters = [];
        for ($index = 0; $index < 100; $index++) {
            $filters['example/count/' . str_repeat('+/', $index) . '#'] = 0;
        }
        mqttWrite($many, mqttSubscribeFilters(5, $filters, 268435455));
        expect(mqttRead($many) === mqttSubscriptionAck(5, str_repeat("\0", 100)), '100项订阅上限未正确授予');
        $topic = 'example/count/' . str_repeat('/', 99);
        mqttWrite($publisher, mqttPublish(5, $topic, 'many-identifiers'));
        expect(mqttRead($many) === mqttPublish(5, $topic, 'many-identifiers', str_repeat($properties, 100)), '重叠标识越过变长属性长度边界后编码不完整');
        mqttQuiet($many);
        mqttWrite($many, mqttSubscribeFilters(5, ['example/count/#' => 0], 1));
        expect(mqttRead($many) === mqttSubscriptionAck(5), '满订阅表替换错误增加计数');
        mqttWrite($many, mqttSubscribeFilters(5, ['example/new' => 0], 1));
        expect(mqttRead($many) === mqttSubscriptionAck(5, "\x97"), '第101个新过滤器绕过额度');
        $cases += 3;
        return $cases;
    } finally {
        foreach ($sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }
}

/**
 * UNSUBACK不终结已经开始的QoS交换；用真实只读备库区分终结确认与静默丢弃。
 *
 * @return array<string,array<string,mixed>> 双版本三个取消切点的网络与持久事实。
 */
function mqttUnsubscribeInflightCases(int $port, ?string $certificate, PDO $standby): array
{
    require_once __DIR__ . '/mqtt-retained.php';
    $report = [];
    foreach ([4, 5] as $version) {
        foreach (['wait-puback' => 1, 'wait-pubrec' => 2, 'wait-pubcomp' => 2] as $stage => $qos) {
            $identity = 'unsubscribe-' . ($certificate === null ? 'tcp' : 'tls') . '-' . $version . '-' . $stage;
            $filter = 'example/' . $identity . '/+';
            $topic = 'example/' . $identity . '/value';
            $payload = "\0\xffstarted-" . $stage;
            $trace = ['version' => $version === 4 ? '3.1.1' : '5.0', 'stage' => $stage,
                'filter' => $filter, 'topic' => $topic, 'qos' => $qos, 'events' => [], 'snapshots' => []];
            $sockets = [];
            $write = static function (mixed $socket, string $peer, string $wire) use (&$trace): void {
                mqttWrite($socket, $wire);
                $trace['events'][] = ['peer' => $peer, 'direction' => 'client-to-broker',
                    'wire' => ord($wire[0]) === 0x10 ? 'CONNECT-credentials-omitted' : bin2hex($wire)];
            };
            $read = static function (mixed $socket, string $peer, string $expected, string $failure) use (&$trace): void {
                $wire = mqttRead($socket);
                $trace['events'][] = ['peer' => $peer, 'direction' => 'broker-to-client', 'wire' => bin2hex($wire)];
                expect($wire === $expected, $failure . '：' . bin2hex($wire));
            };
            try {
                $subscriber = mqttSocket($port, $certificate);
                $publisher = mqttSocket($port, $certificate);
                $sockets = [$subscriber, $publisher];
                foreach (['subscriber' => $subscriber, 'publisher' => $publisher] as $peer => $socket) {
                    $write($socket, $peer, mqttConnect($version, $identity . '-' . $peer, 30));
                    $ack = mqttAck($socket, $version);
                    $trace['events'][] = ['peer' => $peer, 'direction' => 'broker-to-client', 'wire' => bin2hex($ack)];
                }
                $write($subscriber, 'subscriber', mqttSubscribeFilters($version, [$filter => $qos | ($version === 5 ? 32 : 0)]));
                $read($subscriber, 'subscriber', mqttSubscriptionAck($version, chr($qos)), '在途取消前未收到匹配SUBACK');
                $write($publisher, 'publisher', mqttRetainedPacket($version, $topic, $payload, $qos, 71, '', false));
                $read($publisher, 'publisher', mqttPacket($qos === 1 ? 0x40 : 0x50, pack('n', 71)), '在途取消前发布未同步接管');
                if ($qos === 2) {
                    $write($publisher, 'publisher', mqttPacket(0x62, pack('n', 71)));
                    $read($publisher, 'publisher', mqttPacket(0x70, pack('n', 71)), '在途取消前上游QoS2未完成');
                }
                $delivery = mqttRetainedRead($subscriber, $version);
                expect(
                    $delivery['topic'] === $topic && $delivery['payload'] === $payload && $delivery['qos'] === $qos
                    && !$delivery['duplicate'] && !$delivery['retain'] && $delivery['properties'] === '' && $delivery['id'] > 0,
                    '取消前未实际开始目标QoS交付'
                );
                $trace['events'][] = ['peer' => 'subscriber', 'direction' => 'broker-to-client',
                    'decodedPublish' => ['topic' => $delivery['topic'], 'payloadHex' => bin2hex($delivery['payload']),
                        'packetIdentifier' => $delivery['id'], 'qos' => $delivery['qos'], 'duplicate' => $delivery['duplicate']]];
                $identifier = pack('n', $delivery['id']);
                if ($stage === 'wait-pubcomp') {
                    $write($subscriber, 'subscriber', mqttPacket(0x50, $identifier));
                    $read($subscriber, 'subscriber', mqttPacket(0x62, $identifier), '取消前PUBREC未推进到原标识PUBREL');
                }
                $query = $standby->prepare('SELECT d.id, d.message_id, d.packet_id, d.qos, d.state, d.phase, d.started, d.ack_reason, '
                    . "encode(m.payload, 'hex') AS payload_hex FROM type_mqtt_deliveries d JOIN type_mqtt_messages m ON m.id = d.message_id "
                    . 'WHERE d.client_id = ? AND m.topic = ? ORDER BY d.created_at, d.id');
                $snapshot = static function () use ($query, $identity, $topic): array {
                    $query->execute([$identity . '-subscriber', $topic]);
                    return $query->fetchAll(PDO::FETCH_ASSOC);
                };
                $before = $snapshot();
                $phase = $qos === 1 ? 'none' : ($stage === 'wait-pubcomp' ? 'wait_pubcomp' : 'wait_pubrec');
                expect(count($before) === 1 && $before[0]['state'] === 'pending' && $before[0]['phase'] === $phase
                    && $before[0]['started'] === true && (int) $before[0]['packet_id'] === $delivery['id'], '取消前在途持久阶段不符');
                $trace['snapshots']['beforeUnsubscribe'] = $before;
                $write($subscriber, 'subscriber', mqttSubscription($version, $filter, 60000, 0, false));
                $read($subscriber, 'subscriber', mqttSubscriptionAck($version, "\0", 60000, false), '在途取消未返回原标识UNSUBACK');
                $saved = $standby->prepare('SELECT subscriptions::text FROM type_mqtt_sessions WHERE client_id = ?');
                $saved->execute([$identity . '-subscriber']);
                $subscriptions = json_decode($saved->fetchColumn(), true, 16, JSON_THROW_ON_ERROR);
                expect($subscriptions === [], 'UNSUBACK之后目标过滤器仍存在');
                $after = $snapshot();
                expect($after === $before, 'UNSUBSCRIBE丢弃或改写已开始的QoS交换');
                $trace['snapshots']['afterUnsubscribe'] = $after;
                $trace['subscriptionsAfterUnsubscribe'] = $subscriptions;

                // 新发布必须在真实UNSUBACK之后；不把标准允许继续投递的旧缓冲消息当成违规。
                $newPayload = "\0\xffafter-unsuback";
                $write($publisher, 'publisher', mqttRetainedPacket($version, $topic, $newPayload, 1, 72, '', false));
                $read($publisher, 'publisher', mqttPacket(0x40, pack('n', 72)), '取消后新发布未获同步确认');
                $newMessage = $standby->prepare("SELECT m.id, m.state, encode(m.payload, 'hex') AS payload_hex, COUNT(d.id) AS deliveries "
                    . 'FROM type_mqtt_messages m LEFT JOIN type_mqtt_deliveries d ON d.message_id = m.id '
                    . "WHERE m.client_id = ? AND m.topic = ? AND encode(m.payload, 'hex') = ? GROUP BY m.id");
                $newMessage->execute([$identity . '-publisher', $topic, bin2hex($newPayload)]);
                $newRows = $newMessage->fetchAll(PDO::FETCH_ASSOC);
                expect(count($newRows) === 1 && (int) $newRows[0]['deliveries'] === 0, 'UNSUBACK后新匹配消息仍派生交付');
                $trace['snapshots']['newPublication'] = $newRows;
                mqttRetainedQuiet($subscriber);
                if ($stage === 'wait-pubrec') {
                    $write($subscriber, 'subscriber', mqttPacket(0x50, $identifier));
                    $read($subscriber, 'subscriber', mqttPacket(0x62, $identifier), '取消后PUBREC未继续原QoS2交换');
                }
                $write($subscriber, 'subscriber', mqttPacket($qos === 1 ? 0x40 : 0x70, $identifier));
                $completed = mqttUntil(static function () use ($snapshot, $before, $qos): array|false {
                    $rows = $snapshot();
                    return count($rows) === 1 && $rows[0]['id'] === $before[0]['id'] && $rows[0]['state'] === 'acknowledged'
                        && $rows[0]['phase'] === ($qos === 2 ? 'complete' : 'none') && (int) $rows[0]['ack_reason'] === 0 ? $rows : false;
                }, '取消后终结确认未完成原QoS交付');
                expect((int) $completed[0]['packet_id'] === $delivery['id'] && $completed[0]['payload_hex'] === bin2hex($payload), '取消后完成了错误的交换或载荷');
                $trace['snapshots']['afterTerminalAcknowledgement'] = $completed;
                mqttRetainedQuiet($subscriber);
                expect($snapshot() === $completed, '终结确认后出现新的匹配交付');
                $trace['quietObservation'] = ['beforeAndAfterTerminalAck' => true, 'secondsEach' => 0.7,
                    'method' => 'repeated-PINGRESP-and-no-new-durable-delivery'];
                foreach (['subscriber' => $subscriber, 'publisher' => $publisher] as $peer => $socket) {
                    $write($socket, $peer, "\xe0\0");
                    $read($socket, $peer, '', '在途取消验收结束后连接未关闭');
                }
                $trace['status'] = 'passed';
                $report[$identity] = $trace;
            } catch (Throwable $failure) {
                throw new RuntimeException('在途取消失败：' . json_encode($trace, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 0, $failure);
            } finally {
                foreach ($sockets as $socket) {
                    if (is_resource($socket)) {
                        fclose($socket);
                    }
                }
            }
        }
    }
    return $report;
}

/** 同一真实主备和 Broker 入口，核对接收者标识随未完成交付恢复及通配保留游标。 */
function mqttSubscriptionDurableCases(int $port, PDO $primary, PDO $standby, Closure $restart, string $consumer, array $environment): int
{
    require_once __DIR__ . '/mqtt-retained.php';
    $sockets = [];
    $cases = 0;
    try {
        $subscriber = mqttSocket($port);
        $publisher = mqttSocket($port);
        array_push($sockets, $subscriber, $publisher);
        mqttWrite($subscriber, mqttSessionConnect(5, 'subscription-durable', false, 86400, "\x21\0\x01"));
        mqttSessionAck($subscriber, false);
        mqttWrite($publisher, mqttConnect(5, 'subscription-publisher', 0));
        mqttAck($publisher, 5);
        foreach ([['example/subscription/#', 1 | 8 | 32, 11], ['example/subscription/+', 2 | 32, 12],
            ['example/subscription/value', 1 | 4 | 32, 11]] as [$filter, $options, $identifier]) {
            mqttWrite($subscriber, mqttSubscribeFilters(5, [$filter => $options], $identifier));
            expect(mqttRead($subscriber) === mqttSubscriptionAck(5, chr($options & 3)), '持久通配订阅失败');
        }
        $topic = 'example/subscription/value';
        $properties = "\x26" . mqttField('k') . mqttField('first') . "\x02" . pack('N', 5)
            . "\x03" . mqttField('application/octet-stream') . "\x26" . mqttField('k') . mqttField('last')
            . "\x09" . mqttField("\0\xff");
        mqttRetainedPublish($publisher, 5, $topic, "\0\xffinflight", 2, $properties);
        $initial = mqttRetainedRead($subscriber, 5);
        expect($initial['qos'] === 2 && $initial['retain'] && !$initial['duplicate']
            && mqttDeliveryProperties($initial['properties'])['identifiers'] === [11, 11, 12], '重叠最高 QoS/RAP/标识错误');
        expect(mqttDeliveryProperties($initial['properties'])['other'] === $properties, '发布属性被接收标识污染');
        // 已开始的交付不能随着后来的替换/取消改写其标识。
        mqttWrite($subscriber, mqttSubscribeFilters(5, ['example/subscription/+' => 1 | 32], 13));
        expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\x01"), '替换持久标识失败');
        mqttWrite($subscriber, mqttSubscription(5, $topic, 1, 0, false));
        expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\0", 1, false), '取消重叠精确订阅失败');
        mqttRetainedPublish($publisher, 5, $topic, 'queued-current', 2);
        mqttUntil(fn (): bool => (int) $standby->query("SELECT COUNT(*) FROM type_mqtt_deliveries WHERE client_id = 'subscription-durable' AND state = 'pending'")->fetchColumn() === 2, '满窗口通配交付没有持久排队');
        $original = $standby->query("SELECT encode(properties, 'hex') FROM type_mqtt_messages WHERE client_id = 'subscription-publisher' ORDER BY created_at LIMIT 1")->fetchColumn();
        expect($original === bin2hex($properties), '订阅标识污染发布者持久原件');
        mqttWrite($subscriber, "\xe0\0");
        expect(mqttRead($subscriber) === '', '持久订阅没有离线');
        fclose($subscriber);
        mqttUntil(fn (): bool => $standby->query("SELECT owner_id FROM type_mqtt_sessions WHERE client_id = 'subscription-durable'")->fetchColumn() === null, '订阅会话离线未保存');
        mqttRetainedPublish($publisher, 5, $topic, 'offline-current', 1);
        fclose($publisher);
        $restart();
        usleep(5100000);
        $subscriber = mqttSocket($port);
        $sockets[] = $subscriber;
        mqttWrite($subscriber, mqttSessionConnect(5, 'subscription-durable', false, 86400, "\x21\0\x01\x22\0\x01"));
        mqttSessionAck($subscriber, true);
        $resumed = mqttRetainedRead($subscriber, 5);
        $attributes = mqttDeliveryProperties($resumed['properties']);
        expect($resumed['id'] === $initial['id'] && $resumed['duplicate'] && $resumed['qos'] === 2 && $resumed['retain']
            && $resumed['topic'] === $topic && $resumed['payload'] === "\0\xffinflight" && $attributes['identifiers'] === [11, 11, 12], '重启恢复没有保留原交付身份和标识');
        expect($attributes['other'] === str_replace("\x02" . pack('N', 5), "\x02\0\0\0\0", $properties) . "\x23\0\x01", '恢复过期改写损坏 User Property 顺序、二进制或别名');
        mqttRetainedComplete($subscriber, $resumed);
        foreach (['queued-current', 'offline-current'] as $payload) {
            $delivery = mqttRetainedRead($subscriber, 5);
            expect($delivery['payload'] === $payload && $delivery['qos'] === 1 && !$delivery['duplicate'] && $delivery['retain']
                && mqttDeliveryProperties($delivery['properties'])['identifiers'] === [11, 13], '在线满窗口或离线通配使用了旧选项/标识');
            mqttRetainedComplete($subscriber, $delivery);
        }
        mqttUntil(fn (): bool => (int) $standby->query("SELECT COUNT(*) FROM type_mqtt_deliveries WHERE client_id = 'subscription-durable' AND state = 'pending'")->fetchColumn() === 0, '订阅恢复交换没有终结');
        $saved = json_decode($standby->query("SELECT subscriptions::text FROM type_mqtt_sessions WHERE client_id = 'subscription-durable'")->fetchColumn(), true);
        expect(count($saved) === 2 && $saved['t:example/subscription/+'] === ['options' => 33, 'identifier' => 13], '替换计数或持久选项/标识不符');
        foreach (['example/subscription/#', 'example/subscription/+'] as $filter) {
            mqttWrite($subscriber, mqttSubscription(5, $filter, 1, 0, false));
            expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\0", 1, false), '取消持久通配失败');
        }
        mqttWrite($subscriber, mqttPacket(0xe0, "\0\x05\x11\0\0\0\0"));
        expect(mqttRead($subscriber) === '', '订阅测试会话没有清理');
        fclose($subscriber);
        $cases += 10;

        $publisher = mqttSocket($port);
        $sockets[] = $publisher;
        mqttWrite($publisher, mqttConnect(5, 'subscription-cursor-source', 0));
        mqttAck($publisher, 5);
        // 前65个键不匹配，后3个键有96KB二进制；必须跨越空页且逐条加载载荷。
        for ($index = 0; $index < 65; $index++) {
            mqttRetainedPublish($publisher, 5, 'example/cursor/a' . sprintf('%02d', $index) . '/miss', 'skip', 1);
        }
        $binary = str_repeat("\0\xff", 48001);
        foreach (['x', 'y', 'z'] as $suffix) {
            mqttRetainedPublish($publisher, 5, 'example/cursor/' . $suffix . '/hit', $binary, 1);
        }
        $cursor = '';
        $probe = 'require "vendor/autoload.php"; $request = json_decode($argv[1], true, 32, JSON_THROW_ON_ERROR); '
            . '$worker = json_decode(getenv("MQTT_WORKER_COMMAND"), true, 32, JSON_THROW_ON_ERROR); '
            . '$pending = new Type\\Mqtt\\PendingCommit($worker, $request); '
            . 'do { $result = $pending->poll(); if ($result === null) { usleep(10000); } } while ($result === null); '
            . 'echo json_encode($result->data(), JSON_THROW_ON_ERROR);';
        foreach (['example/cursor/a31/miss', 'example/cursor/a63/miss', 'example/cursor/x/hit'] as $index => $expectedCursor) {
            $request = ['operation_id' => bin2hex(random_bytes(16)), 'action' => 'retained_read', 'topic' => 'example/cursor/+/hit',
                'cursor' => $cursor, 'no_local' => false, 'client_id' => 'subscription-cursor-probe'];
            $result = (new Type\Testing\Process([PHP_BINARY, '-r', $probe, json_encode($request, JSON_THROW_ON_ERROR)], $consumer, $environment))->wait(10);
            expect($result->successful() && $result->stderr === '', '独立安装游标调用失败：' . $result->stderr);
            $page = json_decode($result->stdout, true, 32, JSON_THROW_ON_ERROR);
            expect($page['state'] === 'committed' && $page['released'] && $page['value']['cursor'] === $expectedCursor
                && !$page['value']['done'] && $page['value']['found'] === ($index === 2), '保留读取没有按32键上限推进有界游标');
            $cursor = $page['value']['cursor'];
            $cases++;
        }
        foreach ([4, 5] as $version) {
            $subscriber = mqttSocket($port);
            $sockets[] = $subscriber;
            mqttWrite($subscriber, mqttConnect($version, 'subscription-cursor-' . $version, 0));
            mqttAck($subscriber, $version);
            mqttWrite($subscriber, mqttSubscribeFilters($version, ['example/cursor/+/hit' => 1], 101));
            expect(mqttRead($subscriber) === mqttSubscriptionAck($version, "\x01"), '通配保留订阅失败');
            foreach (['x', 'y', 'z'] as $suffix) {
                $delivery = mqttRetainedRead($subscriber, $version);
                expect($delivery['topic'] === 'example/cursor/' . $suffix . '/hit' && $delivery['payload'] === $binary && $delivery['retain']
                    && mqttDeliveryProperties($delivery['properties'])['identifiers'] === ($version === 5 ? [101] : []), '通配保留游标遗漏、重复或丢失标识/二进制');
                mqttRetainedComplete($subscriber, $delivery);
                $cases++;
            }
            mqttRetainedQuiet($subscriber);
            fclose($subscriber);
        }
        $subscriber = mqttSocket($port);
        $sockets[] = $subscriber;
        mqttWrite($subscriber, mqttConnect(5, 'subscription-rh', 0, "\x21\0\x01"));
        mqttAck($subscriber, 5);
        foreach ([[32, 102, false], [16, 103, false], [0, 104, true], [16, 105, false]] as [$handling, $identifier, $replay]) {
            mqttWrite($subscriber, mqttSubscribeFilters(5, ['example/cursor/+/hit' => 1 | $handling], $identifier));
            expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\x01"), 'RH 替换失败');
            if ($replay) {
                for ($index = 0; $index < 3; $index++) {
                    $delivery = mqttRetainedRead($subscriber, 5);
                    expect($delivery['retain'] && mqttDeliveryProperties($delivery['properties'])['identifiers'] === [$identifier], 'RH 重放未绑定新标识');
                    mqttRetainedComplete($subscriber, $delivery);
                }
            }
            mqttRetainedQuiet($subscriber);
            $cases++;
        }
        mqttWrite($subscriber, mqttSubscription(5, 'example/cursor/+/hit', 1, 0, false));
        expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\0", 1, false), 'RH 取消失败');
        mqttWrite($subscriber, mqttSubscribeFilters(5, ['example/cursor/+/hit' => 1 | 16], 106));
        expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\x01"), 'RH 新订阅失败');
        $first = mqttRetainedRead($subscriber, 5);
        expect(mqttDeliveryProperties($first['properties'])['identifiers'] === [106], 'RH=1 新订阅没有重放');
        mqttWrite($subscriber, mqttSubscription(5, 'example/cursor/+/hit', 1, 0, false));
        expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\0", 1, false), '窗口等待中取消失败');
        mqttRetainedComplete($subscriber, $first);
        mqttRetainedQuiet($subscriber);
        fclose($subscriber);
        $cases += 2;

        $local = mqttSocket($port);
        $sockets[] = $local;
        mqttWrite($local, mqttSessionConnect(5, 'subscription-local', false, 86400));
        mqttSessionAck($local, false);
        mqttRetainedPublish($local, 5, 'example/local/value', 'own-retained', 1);
        mqttWrite($local, mqttSubscribeFilters(5, ['example/local/#' => 1 | 4 | 16], 205));
        expect(mqttRead($local) === mqttSubscriptionAck(5, "\x01"), 'No Local 保留过滤器未授予');
        mqttRetainedQuiet($local);
        mqttWrite($local, "\xe0\0");
        expect(mqttRead($local) === '', 'No Local 会话未关闭');
        fclose($local);
        $local = mqttSocket($port);
        $sockets[] = $local;
        mqttWrite($local, mqttSessionConnect(5, 'subscription-local', false, 0));
        mqttSessionAck($local, true);
        mqttRetainedPublish($local, 5, 'example/local/value', 'own-live', 1);
        mqttRetainedQuiet($local);
        mqttRetainedPublish($publisher, 5, 'example/local/value', 'foreign-live', 1);
        $foreign = mqttRetainedRead($local, 5);
        expect($foreign['payload'] === 'foreign-live' && !$foreign['retain'] && mqttDeliveryProperties($foreign['properties'])['identifiers'] === [205], '恢复 No Local/RAP 或标识错误');
        mqttRetainedComplete($local, $foreign);
        fclose($local);
        $cases += 3;

        mqttRetainedPublish($publisher, 5, 'example/private/hidden', 'secret', 1);
        mqttRetainedPublish($publisher, 5, 'example/public/hidden', 'public', 1);
        $guarded = mqttSocket($port);
        $sockets[] = $guarded;
        mqttWrite($guarded, mqttConnect(5, 'subscription-limited-retained', 0));
        mqttAck($guarded, 5);
        mqttWrite($guarded, mqttSubscribeFilters(5, ['example/+/hidden' => 1], 206));
        expect(mqttRead($guarded) === mqttSubscriptionAck(5, "\x01"), '合法保留过滤器授权失败');
        $public = mqttRetainedRead($guarded, 5);
        expect($public['topic'] === 'example/public/hidden' && $public['payload'] === 'public'
            && mqttDeliveryProperties($public['properties'])['identifiers'] === [206], '通配保留泄漏无权 Topic 或错误关闭连接');
        mqttRetainedComplete($guarded, $public);
        mqttRetainedQuiet($guarded);
        fclose($guarded);
        $cases++;

        foreach ([4, 5] as $version) {
            $subscriber = mqttSocket($port);
            $sockets[] = $subscriber;
            $id = 'subscription-offline-' . $version;
            mqttWrite($subscriber, mqttSessionConnect($version, $id));
            mqttSessionAck($subscriber, false);
            mqttWrite($subscriber, mqttSubscribeFilters($version, ['example/offline-sub/#' => $version === 5 ? 1 | 4 | 32 : 1], 127));
            expect(mqttRead($subscriber) === mqttSubscriptionAck($version, "\x01"), '离线订阅选项失败');
            mqttWrite($subscriber, "\xe0\0");
            expect(mqttRead($subscriber) === '', '离线订阅未断开');
            fclose($subscriber);
            mqttUntil(fn (): bool => $standby->query("SELECT owner_id FROM type_mqtt_sessions WHERE client_id = '" . $id . "'")->fetchColumn() === null, '离线订阅未保存');
            mqttRetainedPublish($publisher, 5, 'example/offline-sub/value', 'cross-version', 1);
            $subscriber = mqttSocket($port);
            $sockets[] = $subscriber;
            mqttWrite($subscriber, mqttSessionConnect($version === 4 ? 5 : 4, $id, false, 0));
            mqttSessionAck($subscriber, true);
            $delivery = mqttRetainedRead($subscriber, $version === 4 ? 5 : 4);
            expect($delivery['payload'] === 'cross-version' && $delivery['qos'] === 1 && !$delivery['retain'] && $delivery['properties'] === '', '跨协议会话混入不存在的订阅字段');
            mqttRetainedComplete($subscriber, $delivery);
            mqttRetainedQuiet($subscriber);
            fclose($subscriber);
            $cases++;
        }
        fclose($publisher);
        $cases += count(mqttUnsubscribeInflightCases($port, null, $standby));
        mqttDatabaseIdle($primary);
        return $cases;
    } finally {
        foreach ($sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }
}
