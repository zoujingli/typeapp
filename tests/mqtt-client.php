<?php

declare(strict_types=1);

use Type\Testing\Process;

/** 在独立安装应用内运行公开Client，Broker示例只更换入口名，不更换实现。 */
function mqttClientApplication(string $root, ?string $brokerSource = null): string
{
    return str_replace('function main(', 'function brokerMain(', $brokerSource ?? file_get_contents($root . '/examples/mqtt/main.php')) . <<<'PHP'


function clientCheck(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException($description);
    }
}

function testClient(string $id, int $window = 32, string $peer = '', bool $trusted = true): Type\Mqtt\Client
{
    $certificate = (string) getenv('CLIENT_CA');
    return new Type\Mqtt\Client('127.0.0.1', (int) getenv('CLIENT_PORT'), $id, 'example', (string) getenv('MQTT_PASSWORD'),
        $trusted ? $certificate : '', $peer, 1, 86400, 1048576, $window, $certificate === '');
}

function clientCases(): void
{
    $subscriber = testClient('production-subscriber', 2);
    $publisher = testClient('production-publisher');
    $checks = [];
    try {
        clientCheck(!$subscriber->connect(true) && !$publisher->connect(true), '新会话错误报告SessionPresent');
        clientCheck($subscriber->subscribe('example/client/#', 1, 42) === 1, '订阅没有获得QoS1');
        $payload = str_repeat("\0\xff", 8192);
        $properties = "\x26\0\x01k\0\x01v\x09\0\x02\0\xff";
        clientCheck($publisher->publish(new Type\Mqtt\Message('example/client/binary', $payload, $properties, 1)) === 0, 'QoS1没有等待PUBACK');
        $received = $subscriber->receive(3.0);
        clientCheck($received !== null && $received['message']->payload === $payload && $received['message']->properties === $properties
            && $received['subscription_identifiers'] === [42] && $received['receipt'] !== '' && !$received['duplicate'], '完整16KiB业务与属性被破坏');
        clientCheck($subscriber->statistics()['unacknowledged'] === 1, '接收后被自动确认');
        $checks[] = 'binary-16k-with-properties-and-subscription-identifier';
        $originalReceipt = $received['receipt'];
        $subscriber->close(false);
        clientCheck($subscriber->statistics()['buffered_bytes'] === 0 && $subscriber->statistics()['unacknowledged'] === 0, '关闭没有回收接收资源');
        clientCheck($subscriber->connect(), '恢复会话丢失SessionPresent');
        $restored = $subscriber->receive(4.0);
        clientCheck($restored !== null && $restored['duplicate'] && $restored['message']->payload === $payload && $restored['receipt'] !== $originalReceipt, '未确认入站没有原样恢复');
        $staleDenied = false;
        try {
            $subscriber->acknowledge($originalReceipt);
        } catch (LogicException) {
            $staleDenied = true;
        }
        clientCheck($staleDenied, '旧网络凭据确认了新连接');
        $subscriber->acknowledge($restored['receipt']);
        $checks[] = 'manual-acknowledgement-session-resume-and-stale-receipt';
        for ($index = 0; $index < 3; $index++) {
            $publisher->publish(new Type\Mqtt\Message('example/client/window', 'window-' . $index, '', 1));
        }
        $first = $subscriber->receive(4.0);
        $second = $subscriber->receive(4.0);
        clientCheck($first !== null && $second !== null && $subscriber->statistics()['unacknowledged'] === 2, '接收窗口不符');
        clientCheck($subscriber->receive(0.4) === null, '服务端超过显式接收窗口');
        $subscriber->acknowledge($first['receipt']);
        $third = $subscriber->receive(4.0);
        $windowPayloads = [$first['message']->payload, $second['message']->payload, $third === null ? '<timeout>' : $third['message']->payload];
        sort($windowPayloads);
        clientCheck($windowPayloads === ['window-0', 'window-1', 'window-2'], '释放窗口没有完整交付：' . json_encode($windowPayloads));
        $subscriber->acknowledge($second['receipt']);
        $subscriber->acknowledge($third['receipt']);
        $checks[] = 'receive-maximum-and-explicit-release';
        $subscriber->close();
        $publisher->publish(new Type\Mqtt\Message('example/client/offline', 'offline', '', 1));
        clientCheck($subscriber->connect(), '正常断开丢失持久订阅');
        $offline = $subscriber->receive(4.0);
        clientCheck($offline !== null && $offline['message']->payload === 'offline', '离线队列没有交付');
        $subscriber->acknowledge($offline['receipt']);
        $checks[] = 'offline-subscription-recovery';
        clientCheck($publisher->subscribe('example/client/receipt') === 1, '回执接收订阅失败');
        $publisher->publish(new Type\Mqtt\Message('example/client/up', 'stable-business-id', '', 1));
        $up = $subscriber->receive(4.0);
        clientCheck($up !== null && $subscriber->statistics()['unacknowledged'] === 1, '入站确认时机错误');
        $subscriber->publish(new Type\Mqtt\Message('example/client/receipt', 'durable-receipt', '', 1));
        clientCheck($subscriber->statistics()['unacknowledged'] === 1, '发布独立回执误确认原入站');
        $subscriber->acknowledge($up['receipt']);
        $receipt = $publisher->receive(4.0);
        clientCheck($receipt !== null && $receipt['message']->payload === 'durable-receipt', '双向QoS1业务闭环失败');
        $publisher->acknowledge($receipt['receipt']);
        $ownReceipt = $subscriber->receive(4.0);
        clientCheck($ownReceipt !== null, '普通订阅未收到自己的回执');
        $subscriber->acknowledge($ownReceipt['receipt']);
        $checks[] = 'publish-while-inbound-acknowledgement-is-deferred';
        for ($tick = 0; $tick < 8; $tick++) {
            clientCheck($subscriber->receive(0.4) === null && $publisher->receive(0.1) === null, '保活产生业务消息');
        }
        clientCheck($subscriber->statistics()['connected'] && $publisher->statistics()['connected'], '低KeepAlive空闲连接断开');
        $checks[] = 'keepalive-during-idle-polling';
        clientCheck($subscriber->unsubscribe('example/client/#') === 0, '取消订阅失败');
        $publisher->publish(new Type\Mqtt\Message('example/client/unsubscribed', 'no-delivery', '', 1));
        clientCheck($subscriber->receive(0.3) === null, '取消后仍然交付');
        $checks[] = 'unsubscribe';
        $denied = false;
        try {
            $subscriber->subscribe('denied/topic');
        } catch (Type\Mqtt\ProtocolError $failure) {
            $denied = $failure->reason === 0x87;
        }
        clientCheck($denied && !$subscriber->statistics()['connected'], '订阅拒绝被误报为成功');
        $checks[] = 'subscription-denial-cleans-network';
        $subscriber->connect();
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function (int $signal, array $information) use ($subscriber): void { $subscriber->stop(); });
        pcntl_alarm(1);
        $cancelled = false;
        try {
            $subscriber->receive(5.0);
        } catch (RuntimeException $cancelFailure) {
            $cancelled = $cancelFailure->getMessage() === 'mqtt_client_stopped';
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);
        }
        clientCheck($cancelled && !$subscriber->statistics()['connected'], '取消没有中断有界等待');
        $checks[] = 'cancelled-wait-cleans-network';
    } finally {
        $subscriber->close();
        $publisher->close();
    }
    foreach ([$subscriber, $publisher] as $closed) {
        $stats = $closed->statistics();
        clientCheck(!$stats['connected'] && $stats['buffered_bytes'] === 0 && $stats['queued'] === 0 && $stats['unacknowledged'] === 0, '客户端资源未归零');
    }
    if ((string) getenv('CLIENT_CA') !== '') {
        foreach ([['invalid.example', true], ['', false]] as $badTls) {
            $invalid = testClient('invalid-tls', 2, $badTls[0], $badTls[1]);
            $refused = false;
            try {
                $invalid->connect(true, 2.0);
            } catch (RuntimeException $tlsFailure) {
                $refused = $tlsFailure->getMessage() === 'mqtt_client_tls_failed';
            } finally {
                $invalid->close(false);
            }
            clientCheck($refused, '无效服务端TLS身份未拒绝');
        }
        $checks[] = 'tls-chain-and-hostname-rejection';
    }
    echo json_encode(['checks' => $checks, 'resources_released' => true], JSON_THROW_ON_ERROR), "\n";
}

function clientPeerCase(array $settings): void
{
    $scenario = $settings['scenario'];
    $coroutine = $settings['coroutine'];
    $tls = str_starts_with($scenario, 'tls-');
    $client = new Type\Mqtt\Client('127.0.0.1', $settings['port'], 'wire-production-client', 'example', 'test',
        $tls && $scenario !== 'tls-untrusted' ? $settings['certificate'] : '', $scenario === 'tls-hostname' ? 'invalid.example' : '',
        $scenario === 'keepalive-wait' ? 1 : 30, 86400, 1048576, 2, !$tls, $coroutine);
    $progress = new stdClass();
    $progress->ticks = 0;
    $timer = $coroutine ? Swoole\Timer::tick(10, static function (int $timerId) use ($progress): void { ++$progress->ticks; }) : null;
    $reason = null;
    $failureCode = '';
    $result = [];
    try {
        $client->connect(true, 1.0);
        if (in_array($scenario, ['short-wait-partial', 'tls-success'], true)) {
            clientCheck($client->receive(0.1) === null && $client->statistics()['connected'], '短等待误关闭连接');
            $received = $client->receive(1.0);
            clientCheck($received !== null && $received['message']->payload === 'split-payload', '短等待丢失半包');
            $client->acknowledge($received['receipt']);
            $result['partial_preserved'] = true;
        } elseif ($scenario === 'keepalive-wait') {
            clientCheck($client->receive(1.9) === null && $client->statistics()['connected'], '长等待没有持续保活');
            $result['kept_alive'] = true;
        } elseif ($scenario === 'partial-expiry') {
            for ($index = 0; $index < 55; ++$index) {
                $client->receive(0.1);
            }
            throw new RuntimeException('wire_partial_unexpected_success');
        } elseif ($scenario === 'stop-wait' || $scenario === 'cancel-wait') {
            $cid = Swoole\Coroutine::getCid();
            Swoole\Timer::after(40, static function () use ($client, $cid, $scenario): void {
                if ($scenario === 'stop-wait') {
                    $client->stop();
                } else {
                    Swoole\Coroutine::cancel($cid);
                }
            });
            $client->receive(5.0);
            throw new RuntimeException('wire_cancel_unexpected_success');
        } elseif ($scenario === 'foreign-owner') {
            $foreign = new Swoole\Coroutine\Channel(1);
            Swoole\Coroutine::create(static function () use ($client, $foreign): void {
                try {
                    $client->receive(0.1);
                    $foreign->push(false);
                } catch (RuntimeException $failure) {
                    $foreign->push(str_contains($failure->getMessage(), '执行者'));
                }
            });
            clientCheck($foreign->pop(1) === true && $client->statistics()['connected'], '其他协程使用或关闭了原连接');
            clientCheck($client->receive(0.05) === null, '所属协程无法继续使用连接');
            $result['foreign_denied'] = true;
        } elseif ($scenario === 'parallel-client') {
            $parallel = new Swoole\Coroutine\Channel(1);
            Swoole\Coroutine::create(static function () use ($settings, $parallel): void {
                $other = new Type\Mqtt\Client('127.0.0.1', $settings['port'], 'parallel-client', 'example', 'test', allowPlaintext: true, coroutine: true);
                try {
                    $other->connect(true, 1);
                    $message = $other->receive(1);
                    clientCheck($message !== null && $message['message']->payload === 'parallel', '独立连接没有取得消息');
                    $other->acknowledge($message['receipt']);
                    $parallel->push(true);
                } catch (Throwable $failure) {
                    $parallel->push($failure->getMessage());
                } finally {
                    $other->close(false);
                }
            });
            clientCheck($client->receive(0.3) === null && $parallel->length() === 1 && $parallel->pop(1) === true,
                '慢连接等待期间另一连接未完成交付和确认');
            $result['parallel_completed'] = true;
        } elseif (str_starts_with($scenario, 'publish-')) {
            $client->publish(new Type\Mqtt\Message('example/wire', str_repeat('x', 256), '', 1), 0.5);
            throw new RuntimeException('wire_publish_unexpected_success');
        } elseif ($scenario === 'resume-publication') {
            $message = new Type\Mqtt\Message('example/wire', 'same-publication', '', 1);
            $unknown = false;
            try {
                $client->publish($message, 0.3);
            } catch (RuntimeException) {
                $unknown = true;
            }
            clientCheck($unknown && $client->statistics()['publication_unknown'], '未保存未知发布事实');
            clientCheck($client->connect(false), '重连未恢复会话');
            $changed = false;
            try {
                $client->publish(new Type\Mqtt\Message('example/wire', 'different', '', 1));
            } catch (LogicException) {
                $changed = true;
            }
            clientCheck($changed, '未知交换允许改写内容');
            clientCheck($client->publish($message) === 0 && !$client->statistics()['publication_unknown'], '原交换恢复未取得PUBACK');
            $result['resumed'] = true;
        } elseif ($scenario === 'buffered-complete' || $scenario === 'buffered-partial-tail') {
            $first = $client->receive(1.0);
            clientCheck($first !== null && $first['message']->payload === 'first' && $client->statistics()['buffered_bytes'] > 0, '延迟处理测试没有收齐粘包');
            $client->acknowledge($first['receipt']);
            // 业务在同步持久边界处理首条时，其余完整报文和未完成尾包仍由客户端拥有。
            usleep(5200000);
            $second = $client->receive(1.0);
            clientCheck($second !== null && $second['message']->payload === 'second', '完整缓冲报文被业务处理时间误判超时');
            $client->acknowledge($second['receipt']);
            $result['complete_buffer_delivered'] = true;
            if ($scenario === 'buffered-partial-tail') {
                $client->receive(0.1);
                throw new RuntimeException('wire_partial_tail_unexpected_success');
            }
        } elseif ($scenario === 'duplicate-inbound') {
            $first = $client->receive(1.0);
            clientCheck($first !== null, '重复测试未收到首包');
            clientCheck($client->receive(0.2) === null && $client->statistics()['unacknowledged'] === 1, '活跃连接重复包重复交给业务');
            $client->acknowledge($first['receipt']);
            $result['delayed_ack'] = true;
        } elseif ($scenario === 'receive-excess-window') {
            for ($index = 0; $index < 3; $index++) {
                $client->receive(1.0);
            }
            throw new RuntimeException('wire_receive_unexpected_success');
        } else {
            $client->receive(0.5);
            throw new RuntimeException('wire_receive_unexpected_success');
        }
    } catch (Type\Mqtt\ProtocolError $protocolFailure) {
        $reason = $protocolFailure->reason;
    } catch (RuntimeException $networkFailure) {
        $failureCode = $networkFailure->getMessage();
    } finally {
        $unknownPublication = $client->statistics()['publication_unknown'];
        $client->close(false);
        if ($timer !== null) {
            Swoole\Timer::clear($timer);
        }
    }
    echo json_encode($result + ['reason' => $reason, 'failure' => $failureCode, 'unknown_publication' => $unknownPublication,
        'statistics' => $client->statistics(), 'coroutine' => $coroutine, 'progress_ticks' => $progress->ticks,
        'thread_id' => class_exists(Swoole\Thread::class, false) ? Swoole\Thread::getNativeId() : 0], JSON_THROW_ON_ERROR), "\n";
}

/** 同一编译实现分别在显式同步入口、主线程协程与原生业务线程执行。 */
function clientPeerRun(array $settings): void
{
    if (!$settings['coroutine']) {
        clientPeerCase($settings);
        return;
    }
    $state = new stdClass();
    $state->failure = null;
    Swoole\Coroutine::create(static function () use ($settings, $state): void {
        try {
            clientPeerCase($settings);
        } catch (Throwable $failure) {
            $state->failure = $failure;
        }
    });
    Swoole\Event::wait();
    if ($state->failure !== null) {
        throw $state->failure;
    }
    clientCheck(Swoole\Coroutine::stats()['coroutine_num'] === 0 && Swoole\Timer::stats()['num'] === 0, '客户端退出留下协程或定时器');
}

function clientPeerThread(string $payload): int
{
    clientCheck(!Swoole\Thread::getInfo()['is_main_thread'], '客户端角色没有进入真实业务线程');
    clientPeerRun(json_decode($payload, true, 32, JSON_THROW_ON_ERROR));
    return 0;
}

function main(int $argc, array $argv): void
{
    if (in_array('--client-peer', $argv, true)) {
        $settings = ['scenario' => (string) getenv('CLIENT_SCENARIO'), 'port' => (int) getenv('CLIENT_PORT'),
            'certificate' => (string) getenv('CLIENT_CA'), 'coroutine' => getenv('CLIENT_COROUTINE') === '1'];
        if (getenv('CLIENT_THREAD') === '1') {
            $thread = Type\Runtime\CoroutineRuntime::startThread('client-peer', json_encode($settings, JSON_THROW_ON_ERROR));
            $thread->join();
            clientCheck($thread->getExitStatus() === 0 && Swoole\Thread::activeCount() === 1, '客户端业务线程未正常退出');
        } else {
            clientPeerRun($settings);
        }
        return;
    }
    if (in_array('--client-test', $argv, true)) {
        clientCases();
        return;
    }
    brokerMain($argc, $argv);
}
PHP;
}

/** 真实独立TCP对端发送异常线编码；以公开Client结果和实际上网字节观察拒绝及未知。 */
function mqttClientPeerCases(string $consumer, array $command, array $environment, array $only = []): array
{
    $cases = [
        'connack-invalid-flags' => [mqttPacket(0x20, "\x02\0\0"), null, 0x82],
        'connack-invalid-qos' => [mqttPacket(0x20, "\0\0\x02\x24\x02"), null, 0x82],
        'connack-zero-window' => [mqttPacket(0x20, "\0\0\x03\x21\0\0"), null, 0x82],
        'connack-refused' => [mqttPacket(0x20, "\0\x86\0"), null, 0x86],
        'publish-negative-ack' => [null, mqttPacket(0x40, "\0\x01\x87\0"), 0x87],
        'publish-wrong-identifier' => [null, mqttPacket(0x40, "\0\x02"), 0x82],
        'publish-packet-budget' => [mqttPacket(0x20, "\0\0\x05\x27\0\0\0\x80"), null, 0x95],
        'publish-lost-ack' => [null, '', null],
        'receive-overlarge' => [null, "\x32" . mqttLength(1048576), 0x95],
        'receive-zero-identifier' => [null, mqttPacket(0x32, mqttField('example/wire') . "\0\0\0x"), 0x82],
        'receive-unnegotiated-alias' => [null, mqttPacket(0x32, mqttField('example/wire') . "\0\x01\x03\x23\0\x01x"), 0x82],
        'receive-invalid-utf8-payload' => [null, mqttPacket(0x32, mqttField('example/wire') . "\0\x01\x02\x01\x01\xff"), 0x99],
        'receive-excess-window' => [null, '', 0x93],
        'receive-bad-length' => [null, "\x30\x80\0", 0x81],
        'receive-eof' => [null, '', null],
        'duplicate-inbound' => [null, '', null],
        'resume-publication' => [null, '', null],
        'buffered-complete' => [null, '', null],
        'buffered-partial-tail' => [null, '', null],
        'short-wait-partial' => [null, '', null],
        'partial-expiry' => [null, '', null],
        'keepalive-wait' => [null, '', null],
        'tls-success' => [null, '', null],
        'tls-hostname' => [null, '', null],
        'tls-untrusted' => [null, '', null],
        'tls-stall' => [null, '', null],
    ];
    if (($environment['CLIENT_COROUTINE'] ?? '') === '1') {
        $cases += ['stop-wait' => [null, '', null], 'cancel-wait' => [null, '', null], 'foreign-owner' => [null, '', null], 'parallel-client' => [null, '', null]];
    }
    expect(array_diff($only, array_keys($cases)) === [], '指定的客户端场景不存在');
    if ($only !== []) {
        $cases = array_intersect_key($cases, array_flip($only));
    }
    $verified = [];
    $evidenceDirectory = $consumer . '/client-evidence-' . bin2hex(random_bytes(5));
    expect(mkdir($evidenceDirectory, 0700), '无法建立本轮客户端原始记录目录');
    foreach ($cases as $scenario => $expected) {
        $mode = ($environment['CLIENT_THREAD'] ?? '') === '1' ? 'thread' : (($environment['CLIENT_COROUTINE'] ?? '') === '1' ? 'coroutine' : 'sync');
        $listener = stream_socket_server('tcp://127.0.0.1:0', $number, $error);
        $environment['CLIENT_PORT'] = substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        $environment['CLIENT_SCENARIO'] = $scenario;
        $environment['CLIENT_CA'] = $consumer . '/certificate.pem';
        $client = new Process([...$command, '--client-peer'], $consumer, $environment);
        $socket = null;
        try {
            $socket = stream_socket_accept($listener, 3);
            expect(is_resource($socket), '公开Client未连接独立TCP对端：' . $scenario);
            stream_set_timeout($socket, 3);
            if (str_starts_with($scenario, 'tls-')) {
                if ($scenario === 'tls-stall') {
                    usleep(1200000);
                } else {
                    stream_context_set_options($socket, ['ssl' => ['local_cert' => $consumer . '/certificate.pem',
                        'local_pk' => $consumer . '/private.pem', 'verify_peer' => false]]);
                    $secured = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER);
                    if ($secured !== true && !in_array($scenario, ['tls-hostname', 'tls-untrusted'], true)) {
                        $diagnostic = ['secured' => $secured, 'error' => error_get_last(), 'stream' => stream_get_meta_data($socket)];
                        $failed = $client->wait(4);
                        $diagnostic['client_exit'] = $failed->exitCode;
                        $diagnostic['client_signal'] = $failed->signal;
                        $diagnostic['client_timed_out'] = $failed->timedOut;
                        expect(false, '独立TLS握手失败：' . json_encode($diagnostic, JSON_THROW_ON_ERROR) . $failed->stdout . $failed->stderr);
                    }
                }
            }
            $tlsRejected = in_array($scenario, ['tls-hostname', 'tls-untrusted', 'tls-stall'], true);
            if (!$tlsRejected) {
                $connect = mqttRead($socket);
                expect(ord($connect[0]) === 0x10 && str_contains($connect, "\x21\0\x02\x27\0\x10\0\0\x22\0\0"), 'Client未声明接收和别名预算');
                $greeting = $expected[0] ?? mqttPacket(0x20, "\0\0\0");
                if ($scenario === 'buffered-complete' || $scenario === 'buffered-partial-tail') {
                    $greeting .= mqttPacket(0x32, mqttField('example/wire') . "\0\x01\0first")
                        . mqttPacket(0x32, mqttField('example/wire') . "\0\x02\0second")
                        . ($scenario === 'buffered-partial-tail' ? "\x32\x7f" : '');
                }
                mqttWrite($socket, $greeting);
                if ($scenario === 'short-wait-partial' || $scenario === 'tls-success') {
                    $split = mqttPacket(0x32, mqttField('example/wire') . "\0\x01\0split-payload");
                    mqttWrite($socket, substr($split, 0, 3));
                    usleep(200000);
                    mqttWrite($socket, substr($split, 3));
                    expect(mqttRead($socket) === "\x40\x02\0\x01", '跨短等待接收的入站确认错误');
                } elseif ($scenario === 'parallel-client') {
                    $other = stream_socket_accept($listener, 1);
                    expect(is_resource($other), '同线程独立连接未建连');
                    try {
                        stream_set_timeout($other, 1);
                        mqttRead($other);
                        mqttWrite($other, mqttPacket(0x20, "\0\0\0") . mqttPacket(0x32, mqttField('example/wire') . "\0\x01\0parallel"));
                        expect(mqttRead($other) === "\x40\x02\0\x01", '独立连接没有确认');
                    } finally {
                        fclose($other);
                    }
                } elseif ($scenario === 'partial-expiry') {
                    mqttWrite($socket, "\x32\x7f");
                } elseif ($scenario === 'keepalive-wait') {
                    for ($ping = 0; $ping < 3; ++$ping) {
                        expect(mqttRead($socket) === "\xc0\0", '等待期间未按保活期限发送PING');
                        mqttWrite($socket, "\xd0\0");
                    }
                } elseif (str_starts_with($scenario, 'publish-') && $scenario !== 'publish-packet-budget') {
                    $publish = mqttRead($socket);
                    expect(ord($publish[0]) === 0x32 && strlen($publish) > 256, 'Client发布编码或QoS不符');
                    if ($scenario === 'publish-lost-ack') {
                        usleep(650000);
                    } else {
                        mqttWrite($socket, $expected[1]);
                    }
                } elseif ($scenario === 'duplicate-inbound') {
                    $body = mqttField('example/wire') . "\0\x01\0payload";
                    mqttWrite($socket, mqttPacket(0x32, $body) . mqttPacket(0x3a, $body));
                    $started = microtime(true);
                    expect(mqttRead($socket) === "\x40\x02\0\x01" && microtime(true) - $started >= 0.15, 'Client提前或错误确认入站');
                } elseif ($scenario === 'resume-publication') {
                    $original = mqttRead($socket);
                    usleep(400000);
                    fclose($socket);
                    $socket = stream_socket_accept($listener, 3);
                    expect(is_resource($socket), '未知发布未由调用方显式重连');
                    stream_set_timeout($socket, 3);
                    $resume = mqttRead($socket);
                    expect(ord($resume[9]) === 0xc0, '重连错误设置CleanStart');
                    mqttWrite($socket, mqttPacket(0x20, "\x01\0\0"));
                    $duplicate = mqttRead($socket);
                    expect(ord($duplicate[0]) === 0x3a && substr($duplicate, 1) === substr($original, 1), '重连没有复用原标识和内容');
                    mqttWrite($socket, "\x40\x02\0\x01");
                } elseif ($scenario === 'receive-excess-window') {
                    $bytes = '';
                    for ($identifier = 1; $identifier <= 3; $identifier++) {
                        $bytes .= mqttPacket(0x32, mqttField('example/wire') . pack('n', $identifier) . "\0x");
                    }
                    mqttWrite($socket, $bytes);
                } elseif ($scenario === 'receive-eof') {
                    fclose($socket);
                    $socket = null;
                } elseif ($expected[1] !== null && $expected[1] !== '') {
                    mqttWrite($socket, $expected[1]);
                }
            }
            $result = $client->wait(str_starts_with($scenario, 'buffered-') || $scenario === 'partial-expiry' ? 8 : 5);
            file_put_contents($evidenceDirectory . '/' . $mode . '-' . $scenario . '.json', json_encode([
                'exit' => $result->exitCode, 'stdout' => $result->stdout, 'stderr' => $result->stderr,
                'timed_out' => $result->timedOut, 'output_exceeded' => $result->outputExceeded, 'signal' => $result->signal,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
            expect($result->successful() && $result->stderr === '', 'Client独立TCP用例运行失败：' . $scenario . ' ' . $result->stdout . $result->stderr);
            $lines = explode("\n", trim($result->stdout));
            $observation = json_decode(array_pop($lines), true, 32, JSON_THROW_ON_ERROR);
            foreach ($lines as $line) {
                expect(in_array($scenario, ['tls-hostname', 'tls-untrusted'], true)
                    && preg_match('/^\[.+\]\s+(?:WARNING|NOTICE)\s+.*(?:ssl|SSL|certificate)/', $line) === 1, '出现非预期原生诊断：' . $line);
            }
            expect($observation['reason'] === $expected[2], 'Client协议拒绝不符：' . $scenario . ' ' . $result->stdout);
            if ($scenario === 'tls-stall') {
                expect($observation['failure'] === 'mqtt_client_timeout', 'TLS握手没有遵守总截止');
            } elseif ($scenario === 'tls-hostname' || $scenario === 'tls-untrusted') {
                expect($observation['failure'] === 'mqtt_client_tls_failed', 'TLS证书身份未拒绝');
            } elseif ($scenario === 'stop-wait' || $scenario === 'cancel-wait') {
                expect($observation['failure'] === ($scenario === 'stop-wait' ? 'mqtt_client_stopped' : 'mqtt_client_cancelled'), '取消等待结果不符');
            } elseif ($scenario === 'partial-expiry') {
                expect($observation['failure'] === 'mqtt_client_partial_timeout', '短等待重置了半包截止');
            } elseif ($scenario === 'publish-lost-ack') {
                expect($observation['unknown_publication'] && $observation['failure'] === 'mqtt_client_timeout', '丢失PUBACK误报已成功或确定未接收');
            } elseif ($scenario === 'publish-negative-ack' || $scenario === 'publish-packet-budget') {
                expect(!$observation['unknown_publication'], '明确拒绝或未上网的发布误标未知');
            } elseif ($scenario === 'buffered-partial-tail') {
                expect(($observation['complete_buffer_delivered'] ?? false) && $observation['failure'] === 'mqtt_client_partial_timeout', '处理完整帧延长了尾部半包原有截止：' . $result->stdout);
            } elseif ($scenario === 'receive-eof') {
                expect($observation['failure'] === 'mqtt_client_disconnected', 'EOF误报等待完成');
            } else {
                expect($observation['failure'] === '', '独立TCP用例出现其他失败：' . $scenario . ' ' . $result->stdout);
            }
            expect(!$observation['statistics']['connected'] && $observation['statistics']['buffered_bytes'] === 0
                && $observation['statistics']['unacknowledged'] === 0, '独立TCP用例资源未回收');
            if (($environment['CLIENT_COROUTINE'] ?? '') === '1' && in_array($scenario, ['short-wait-partial', 'tls-success', 'tls-stall', 'keepalive-wait', 'stop-wait', 'cancel-wait'], true)) {
                expect($observation['progress_ticks'] >= 2, '原生等待阻塞了同线程进度：' . $scenario);
            }
            $verified[] = $scenario;
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
            fclose($listener);
            $stopped = $client->stop();
            if (!is_file($evidenceDirectory . '/' . $mode . '-' . $scenario . '.json')) {
                file_put_contents($evidenceDirectory . '/' . $mode . '-' . $scenario . '.json', json_encode([
                    'exit' => $stopped->exitCode, 'stdout' => $stopped->stdout, 'stderr' => $stopped->stderr,
                    'timed_out' => $stopped->timedOut, 'output_exceeded' => $stopped->outputExceeded, 'signal' => $stopped->signal,
                ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
            }
        }
    }
    return $verified;
}

/** 真实同步数据库与安装后的Broker/Client使用同一PHP或原生产物。 */
function mqttClientCases(string $root, string $consumer, array $command, array $workerCommand, array $environment): array
{
    require_once $root . '/tests/native-database.php';
    require_once $root . '/tests/postgres-sync.php';
    $tools = NativeDatabase::tools('pgsql', (string) getenv('TYPE_PGSQL_TOOLS'));
    $database = new NativeDatabase($consumer . '/client-primary', 'pgsql', $tools);
    $sync = null;
    $broker = null;
    $client = null;
    $checks = [];
    try {
        $sync = new PostgresSync($database, $consumer . '/client-standby', $tools);
        $environment = array_replace($environment, $database->environment());
        $environment['MQTT_WORKER_COMMAND'] = json_encode($workerCommand, JSON_THROW_ON_ERROR);
        $installed = (new Process([...$command, '--install-store'], $consumer, $environment))->wait(10);
        expect($installed->successful() && $installed->stderr === '' && json_decode($installed->stdout, true, 32, JSON_THROW_ON_ERROR)['state'] === 'committed', '客户端测试Broker持久安装失败：' . $installed->stderr);
        foreach ([false, true] as $tls) {
            $listener = stream_socket_server('tcp://127.0.0.1:0', $number, $error);
            $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
            fclose($listener);
            $environment['CLIENT_PORT'] = (string) $port;
            $environment['CLIENT_CA'] = $tls ? $consumer . '/certificate.pem' : '';
            $environment['MQTT_CERTIFICATE'] = $environment['CLIENT_CA'];
            $environment['MQTT_PRIVATE_KEY'] = $tls ? $consumer . '/private.pem' : '';
            $broker = new Process([...$command, '--port=' . $port, ...($tls ? [] : ['--plaintext'])], $consumer, $environment);
            $until = microtime(true) + 10;
            $ready = false;
            do {
                expect($broker->running(), '客户端测试Broker提前退出：' . $broker->stderr());
                $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $number, $error, 0.1);
                if (is_resource($probe)) {
                    fclose($probe);
                    $ready = true;
                } else {
                    usleep(10000);
                }
            } while (!$ready && microtime(true) < $until);
            expect($ready, '客户端测试Broker未启动');
            $client = new Process([...$command, '--client-test'], $consumer, $environment);
            $result = $client->wait(60);
            file_put_contents($consumer . '/client-' . ($tls ? 'tls' : 'tcp') . '.log', $result->stdout . $result->stderr);
            expect($result->successful() && $result->stderr === '', '公开Client场景失败：' . $result->stdout . $result->stderr);
            $checks[$tls ? 'tls' : 'tcp'] = json_decode($result->stdout, true, 32, JSON_THROW_ON_ERROR);
            $stopped = $broker->stop(12);
            expect($stopped->successful() && $stopped->stderr === '', '客户端测试Broker未完整退出：' . $stopped->stderr);
            $statistics = json_decode($stopped->stdout, true, 32, JSON_THROW_ON_ERROR);
            foreach (['connections', 'subscriptions', 'bufferedBytes', 'pendingCommits', 'closingSessions'] as $field) {
                expect($statistics[$field] === 0, '客户端测试Broker资源未释放：' . $field);
            }
        }
    } finally {
        $client?->stop();
        $broker?->stop();
        $sync?->close();
        $database->close();
    }
    $checks['independent-peer'] = mqttClientPeerCases($consumer, $command, $environment);
    return $checks;
}
