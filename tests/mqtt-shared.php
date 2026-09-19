<?php

declare(strict_types=1);

use Type\Testing\Process;

/** 两秒持续观察并 PING，排除异步 worker 尚未完成造成的假阴性。 */
function mqttSharedQuiet(mixed $socket): void
{
    $until = microtime(true) + 2;
    do {
        mqttQuiet($socket);
        usleep(20000);
    } while (microtime(true) < $until);
}

/** 共享测试仍使用标准 PUBLISH，不要求组件理解测试业务载荷。 */
function mqttSharedPublish(mixed $socket, string $topic, string $payload, int $qos = 1, bool $retain = false, string $properties = ''): void
{
    mqttWrite($socket, mqttRetainedPacket(5, $topic, $payload, $qos, 19, $properties, $retain));
    if ($qos > 0) {
        expect(mqttRead($socket) === mqttQos2Ack($qos === 1 ? 0x40 : 0x50, 19), '共享发布没有同步确认：' . $topic);
        if ($qos === 2) {
            mqttWrite($socket, mqttQos2Ack(0x62, 19));
            expect(mqttRead($socket) === mqttQos2Ack(0x70, 19), '共享入站 QoS 2 未完成');
        }
    } else {
        mqttQuiet($socket);
    }
}

/** 选择真正有网络数据的成员，不预设公平调度或连接顺序。 */
function mqttSharedReceive(array $sockets): array
{
    $read = $sockets;
    $write = [];
    $except = [];
    expect(stream_select($read, $write, $except, 8) > 0, '共享组没有交付');
    $socket = reset($read);
    return [array_search($socket, $sockets, true), mqttRetainedRead($socket, 5)];
}

/** 同一持久会话同时积压两类消息；任何一类均不能独占轮询直至清空。 */
function mqttSharedSchedulingCase(int $port, ?string $certificate, string $prefix): array
{
    $sockets = [];
    try {
        $receiver = mqttSocket($port, $certificate);
        $publisher = mqttSocket($port, $certificate);
        $sockets = [$receiver, $publisher];
        $clientId = 'shared-scheduling-' . $prefix;
        mqttWrite($receiver, mqttSessionConnect(5, $clientId, true, 120));
        mqttSessionAck($receiver, false);
        mqttWrite($publisher, mqttSessionConnect(5, $prefix . 'scheduling-publisher', true, 120));
        mqttSessionAck($publisher, false);
        $ordinary = 'example/' . $prefix . 'scheduling/ordinary';
        $shared = 'example/' . $prefix . 'scheduling/shared';
        mqttWrite($receiver, mqttSubscribeFilters(5, [$ordinary => 1, '$share/scheduling/' . $shared => 1]));
        expect(mqttRead($receiver) === mqttSubscriptionAck(5, "\x01\x01"), '调度回归的两类订阅未建立');
        mqttWrite($receiver, "\xe0\0");
        expect(mqttRead($receiver) === '', '调度回归的持久会话未离线');
        fclose($receiver);
        for ($sequence = 0; $sequence < 8; $sequence++) {
            mqttSharedPublish($publisher, $ordinary, 'ordinary-' . $sequence);
            mqttSharedPublish($publisher, $shared, 'shared-' . $sequence);
        }
        mqttWrite($publisher, mqttPacket(0xe0, "\0\x05\x11\0\0\0\0"));
        expect(mqttRead($publisher) === '', '调度回归的发布会话未结束');
        fclose($publisher);
        $receiver = mqttSocket($port, $certificate);
        $sockets[] = $receiver;
        mqttWrite($receiver, mqttSessionConnect(5, $clientId, false, 120));
        mqttSessionAck($receiver, true);
        $messages = [];
        for ($index = 0; $index < 16; $index++) {
            $received = mqttRetainedRead($receiver, 5);
            expect($received['qos'] === 1 && !$received['duplicate'], '调度轮换改变了初次可靠交付');
            $messages[] = $received['payload'];
            mqttRetainedComplete($receiver, $received);
            if ($index === 3) {
                $ordinaryProgress = count(array_filter($messages, static fn (string $payload): bool => str_starts_with($payload, 'ordinary-')));
                expect($ordinaryProgress > 0 && $ordinaryProgress < 4, '普通与共享投递发生饥饿：' . json_encode($messages, JSON_THROW_ON_ERROR));
            }
        }
        expect(count(array_unique($messages)) === 16, '调度轮换丢失或重复积压消息');
        mqttQuiet($receiver);
        mqttWrite($receiver, mqttPacket(0xe0, "\0\x05\x11\0\0\0\0"));
        expect(mqttRead($receiver) === '', '调度回归未结束会话');
        return $messages;
    } finally {
        foreach ($sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }
}

/** 每轮独立安装应用、真实 PostgreSQL 同步主备和 TCP/TLS；不启动任何参考 Broker。 */
function mqttSharedCases(string $root, string $consumer, array $command, array $workerCommand, array $environment): array
{
    ini_set('zend.exception_ignore_args', '1');
    require_once __DIR__ . '/native-database.php';
    require_once __DIR__ . '/postgres-sync.php';
    require_once __DIR__ . '/mqtt-qos1.php';
    require_once __DIR__ . '/mqtt-qos2.php';
    require_once __DIR__ . '/mqtt-retained.php';
    require_once __DIR__ . '/mqtt-sessions.php';
    require_once __DIR__ . '/mqtt-subscriptions.php';
    $tools = NativeDatabase::tools('pgsql', (string) getenv('TYPE_PGSQL_TOOLS'));
    $database = new NativeDatabase($consumer . '/shared-primary', 'pgsql', $tools);
    $sync = null;
    $process = null;
    $primary = null;
    $standby = null;
    $sockets = [];
    $cases = 0;
    $traces = [];
    try {
        $sync = new PostgresSync($database, $consumer . '/shared-standby', $tools);
        $environment = array_replace($environment, $database->environment());
        $environment['MQTT_WORKER_COMMAND'] = json_encode($workerCommand, JSON_THROW_ON_ERROR);
        $installed = (new Process([...$command, '--install-store'], $consumer, $environment))->wait(10);
        expect($installed->successful() && $installed->stderr === '', '共享安装失败：' . $installed->stderr);
        expect(json_decode($installed->stdout, true, 32, JSON_THROW_ON_ERROR)['state'] === 'committed', '共享安装没有同步证明');
        $primary = $sync->connection();
        $standby = $sync->standby();
        foreach ([false, true] as $tls) {
            $certificate = $tls ? $consumer . '/certificate.pem' : null;
            $environment['MQTT_CERTIFICATE'] = $certificate ?? '';
            $environment['MQTT_PRIVATE_KEY'] = $tls ? $consumer . '/private.pem' : '';
            $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
            $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
            fclose($listener);
            $start = function () use ($command, $consumer, &$environment, $port, $tls): Process {
                $running = new Process([...$command, '--port=' . $port, ...($tls ? [] : ['--plaintext'])], $consumer, $environment);
                mqttUntil(function () use ($running, $port): bool {
                    expect($running->running(), '共享 Broker 提前退出：' . $running->stderr());
                    $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $number, $message, 0.1);
                    if (!is_resource($probe)) {
                        return false;
                    }
                    fclose($probe);
                    return true;
                }, '共享 Broker 未启动');
                return $running;
            };
            $process = $start();
            $prefix = 'shared-' . ($tls ? 'tls-' : 'tcp-');
            $traces[$prefix . 'scheduling'] = mqttSharedSchedulingCase($port, $certificate, $prefix);
            $cases += 2;
            echo successful(['node', $root . '/tests/mqtt-standard-client.mjs', $consumer, (string) $port, $certificate ?? 'plain', 'shared'], $consumer);
            $open = function (string $id, bool $clean = true, int $expiry = 120, string $properties = '') use ($port, $certificate, &$sockets, $prefix): mixed {
                $socket = mqttSocket($port, $certificate);
                $sockets[] = $socket;
                mqttWrite($socket, mqttSessionConnect(5, $prefix . $id, $clean, $expiry, $properties));
                $ack = mqttRead($socket);
                expect(ord($ack[0]) === 0x20 && ord($ack[2]) === ($clean ? 0 : 1) && ord($ack[3]) === 0, '共享会话未正确建立：' . bin2hex($ack));
                expect(str_contains($ack, "\x2a\x01"), '共享能力未准确声明');
                return $socket;
            };
            $subscribe = function (mixed $socket, string $filter, int $options = 1, int $identifier = 0): void {
                mqttWrite($socket, mqttSubscribeFilters(5, [$filter => $options], $identifier));
                $ack = mqttRead($socket);
                expect($ack === mqttSubscriptionAck(5, chr($options & 3)), '共享订阅未授予：' . substr($filter, 0, 80) . '，字节数=' . strlen($filter) . '，响应=' . bin2hex($ack));
            };
            $close = function (mixed $socket, bool $terminate = true): void {
                mqttWrite($socket, $terminate ? mqttPacket(0xe0, "\0\x05\x11\0\0\0\0") : "\xe0\0");
                expect(mqttRead($socket) === '', '共享成员未正常断开');
                fclose($socket);
            };
            $publisher = $open('publisher');
            // 不可压缩的长合法过滤器不能撞上数据库索引键的较小限制。
            $a = $open('long-filter');
            $actual = 'example/' . bin2hex(random_bytes(2000));
            $subscribe($a, '$share/long/' . $actual, 1, 83);
            mqttSharedPublish($publisher, $actual, 'long-filter');
            $received = mqttRetainedRead($a, 5);
            expect($received['topic'] === $actual && $received['payload'] === 'long-filter', '长共享过滤器未按原字节路由');
            mqttRetainedComplete($a, $received);
            $close($a);
            $cases++;
            // 公开 AccessPolicy 给予独立测试客户端系统 Topic 权限，匹配仍按真实外层过滤器。
            $system = [];
            foreach (['member', 'publisher'] as $role) {
                $socket = mqttSocket($port, $certificate);
                $sockets[] = $socket;
                mqttWrite($socket, mqttSessionConnect(5, 'subscription-system-' . $prefix . $role, true, 120));
                expect(ord(mqttRead($socket)[3]) === 0, '系统 Topic 测试会话拒绝');
                $system[] = $socket;
            }
            foreach ([
                ['$share/system/#', '$SYS/' . $prefix, false],
                ['$share/system/+/' . $prefix, '$SYS/' . $prefix, false],
                ['$share/system/$SYS/#', '$SYS/' . $prefix, true],
                ['$share/system/$share/literal/' . $prefix, '$share/literal/' . $prefix, true],
                ['$share/system/example/' . $prefix . '/+/tail', 'example/' . $prefix . '//tail', true],
                ['$share/system/example/' . $prefix . '/#', 'example/' . $prefix, true],
            ] as [$filter, $actual, $matched]) {
                $subscribe($system[0], $filter, 1);
                mqttSharedPublish($system[1], $actual, 'boundary');
                if ($matched) {
                    $received = mqttRetainedRead($system[0], 5);
                    expect($received['topic'] === $actual, '共享匹配改写了 Topic');
                    mqttRetainedComplete($system[0], $received);
                } else {
                    mqttSharedQuiet($system[0]);
                }
                mqttWrite($system[0], mqttSubscription(5, $filter, 1, 0, false));
                expect(mqttRead($system[0]) === mqttSubscriptionAck(5, "\0", 1, false), '系统 Topic 共享取消失败');
                $cases++;
            }
            foreach ($system as $socket) {
                $close($socket);
            }
            // 普通与共享共用100个过滤器预算；替换不增加数量。
            $a = $open('subscription-limit');
            $filters = [];
            for ($index = 0; $index < 99; $index++) {
                $filters['example/' . $prefix . 'limit-' . $index] = 0x21;
            }
            $filter = '$share/limit/example/' . $prefix . 'limit-shared';
            $filters[$filter] = 1;
            mqttWrite($a, mqttSubscribeFilters(5, $filters));
            expect(mqttRead($a) === mqttSubscriptionAck(5, str_repeat("\x01", 100)), '共享未与普通共用100过滤器额度');
            $subscribe($a, $filter, 2, 81);
            mqttWrite($a, mqttSubscription(5, '$share/another/example/' . $prefix . 'over-limit', 1, 1));
            expect(mqttRead($a) === mqttSubscriptionAck(5, "\x97"), '共享第101过滤器未拒绝');
            mqttSharedPublish($publisher, 'example/' . $prefix . 'limit-shared', 'at-limit', 2);
            $received = mqttRetainedRead($a, 5);
            expect($received['qos'] === 2 && mqttDeliveryProperties($received['properties'])['identifiers'] === [81], '满额替换未生效');
            mqttRetainedComplete($a, $received);
            $close($a);
            $cases += 3;
            // 授权在活跃派发和会话恢复时重新检查，通过消费者自己的策略撤销。
            $a = $open('revoked');
            $actual = 'example/revoked-' . $prefix;
            $filter = '$share/revoked/' . $actual;
            $subscribe($a, $filter, 1);
            expect(file_put_contents($consumer . '/shared-access-revoked', 'revoked') === 7, '无法设置独立消费者撤销事实');
            try {
                mqttSharedPublish($publisher, $actual, 'denied-live');
                mqttSharedQuiet($a);
                $close($a, false);
                $a = $open('revoked', false);
                mqttSharedQuiet($a);
                mqttWrite($a, mqttSubscription(5, '$share/renamed/' . $actual, 1, 1));
                expect(mqttRead($a) === mqttSubscriptionAck(5, "\x87"), '恢复后更换组名绕过撤销');
            } finally {
                expect(unlink($consumer . '/shared-access-revoked'), '独立消费者撤销事实未清理');
            }
            $subscribe($a, $filter, 1);
            mqttSharedPublish($publisher, $actual, 'allowed-control');
            $received = mqttRetainedRead($a, 5);
            expect($received['payload'] === 'allowed-control', '恢复未退出撤权旧组或恢复授权后未继续');
            mqttRetainedComplete($a, $received);
            $close($a);
            $cases += 3;
            $topic = 'example/' . $prefix . 'fanout/x';
            $group = '$share/a/' . 'example/' . $prefix . 'fanout/+';
            $a = $open('a');
            $b = $open('b');
            $c = $open('c');
            $subscribe($a, $group, 1, 7);
            $subscribe($b, $group, 1, 9);
            $subscribe($c, '$share/b/example/' . $prefix . 'fanout/+', 1, 11);
            $subscribe($c, 'example/' . $prefix . 'fanout/+', 1, 13);
            for ($sequence = 0; $sequence < 8; $sequence++) {
                mqttSharedPublish($publisher, $topic, 'm-' . $sequence);
                [$selected, $delivery] = mqttSharedReceive([$a, $b]);
                expect($delivery['payload'] === 'm-' . $sequence && mqttDeliveryProperties($delivery['properties'])['identifiers'] === [$selected === 0 ? 7 : 9], '组选择混用了成员标识');
                mqttRetainedComplete([$a, $b][$selected], $delivery);
                $ids = [];
                for ($copy = 0; $copy < 2; $copy++) {
                    $received = mqttRetainedRead($c, 5);
                    expect($received['payload'] === 'm-' . $sequence, '普通或另一组丢失独立副本');
                    $ids[] = mqttDeliveryProperties($received['properties'])['identifiers'][0];
                    mqttRetainedComplete($c, $received);
                }
                sort($ids);
                expect($ids === [11, 13], '普通和共享被错误合并');
                $cases++;
            }
            mqttSharedQuiet($a);
            mqttSharedQuiet($b);
            $close($a);
            $close($b);
            $close($c);
            // 同名不同过滤器各自是独立组；RAP/RH/标识与发布 QoS 九种组合。
            foreach ([0, 1, 2] as $granted) {
                $a = $open('qos-' . $granted);
                $filter = '$share/qos/example/' . $prefix . 'qos-' . $granted;
                $subscribe($a, $filter, $granted | 8 | ($granted << 4), 21);
                foreach ([0, 1, 2] as $published) {
                    $payload = "\0\xff" . $granted . '-' . $published;
                    mqttSharedPublish($publisher, substr($filter, 11), $payload, $published, true);
                    $received = mqttRetainedRead($a, 5);
                    expect($received['qos'] === min($granted, $published) && $received['retain'] && $received['payload'] === $payload
                        && mqttDeliveryProperties($received['properties'])['identifiers'] === [21], '共享 QoS/RAP/标识不匹配');
                    mqttRetainedComplete($a, $received);
                    $cases++;
                }
                $close($a);
            }
            // 离线积压在真正重启后恢复，新成员也可消费未分配副本。
            $filter = '$share/offline/example/' . $prefix . 'offline';
            $a = $open('offline');
            $subscribe($a, $filter, 2, 31);
            $close($a, false);
            mqttSharedPublish($publisher, 'example/' . $prefix . 'offline', 'offline-first', 2);
            $close($publisher);
            $result = $process->stop(12);
            expect($result->successful() && $result->stderr === '', '共享重启前清理失败：' . $result->stderr);
            $process = $start();
            $a = $open('offline', false);
            $received = mqttRetainedRead($a, 5);
            expect($received['payload'] === 'offline-first' && !$received['duplicate'], '离线组积压未从重启恢复');
            mqttRetainedComplete($a, $received);
            $close($a);
            $cases++;
            $publisher = $open('publisher-again');
            // 格式、选项和共享前缀授权。所有非法报文使用新连接。
            foreach (['$share//example/x', '$share/g/', '$share/g', '$share/g+/example/x', '$share/g/example/#/x'] as $invalidFilter) {
                $a = $open('invalid');
                mqttWrite($a, mqttSubscription(5, $invalidFilter, 1, 1));
                expect(mqttRead($a) === "\xe0\x02\x82\0", '非法共享格式未按协议错误拒绝');
                fclose($a);
                $cases++;
            }
            foreach ([5, 49, 65, 3] as $options) {
                $a = $open('options');
                mqttWrite($a, mqttSubscription(5, '$share/g/example/x', 1, $options));
                expect(mqttRead($a) === "\xe0\x02" . chr($options === 65 ? 0x81 : 0x82) . "\0", '非法共享选项未拒绝');
                fclose($a);
                $cases++;
            }
            $a = $open('auth');
            foreach (['$share/g/denied/#', '$share/another/denied/#', '$share/g/#'] as $deniedFilter) {
                mqttWrite($a, mqttSubscription(5, $deniedFilter, 1, 1));
                expect(mqttRead($a) === mqttSubscriptionAck(5, "\x87"), '改共享组名扩大了 Topic 权限');
                $cases++;
            }
            $subscribe($a, '$share/组 名/example/' . $prefix . 'utf8', 1);
            mqttSharedPublish($publisher, 'example/' . $prefix . 'utf8', 'utf8');
            mqttRetainedComplete($a, mqttRetainedRead($a, 5));
            $close($a);
            $cases++;
            $a = $open('identity');
            $actual = 'example/' . $prefix . 'identity';
            $subscribe($a, '$share/same/' . $actual . '/#', 1, 42);
            $subscribe($a, '$share/same/' . $actual . '/+', 1, 43);
            mqttSharedPublish($publisher, $actual . '/x', 'overlap');
            $ids = [];
            for ($copy = 0; $copy < 2; $copy++) {
                $received = mqttRetainedRead($a, 5);
                $ids[] = mqttDeliveryProperties($received['properties'])['identifiers'][0];
                mqttRetainedComplete($a, $received);
            }
            sort($ids);
            expect($ids === [42, 43], '同名不同过滤器被合并');
            $close($a);
            $cases++;
            // 历史保留对共享 RH=0/1/2 都不重放，重订选项/标识覆盖且精确取消。
            $actual = 'example/' . $prefix . 'retained';
            mqttSharedPublish($publisher, $actual, 'historical', 1, true);
            foreach ([0, 1, 2] as $handling) {
                $a = $open('retained');
                $filter = '$share/retained/' . $actual;
                $subscribe($a, $filter, 1 | ($handling << 4), 7);
                mqttSharedQuiet($a);
                $subscribe($a, $filter, 9 | ($handling << 4), 11);
                mqttSharedQuiet($a);
                mqttSharedPublish($publisher, $actual, '', 1, true);
                $received = mqttRetainedRead($a, 5);
                expect($received['payload'] === '' && $received['retain'] && mqttDeliveryProperties($received['properties'])['identifiers'] === [11], '共享实时删除保留/替换标识错误');
                mqttRetainedComplete($a, $received);
                $subscribe($a, $filter, 1);
                mqttSharedPublish($publisher, $actual, 'cleared-id', 1, true);
                $received = mqttRetainedRead($a, 5);
                expect(!$received['retain'] && mqttDeliveryProperties($received['properties'])['identifiers'] === [], '共享省略标识未清除或 RAP 未生效');
                mqttRetainedComplete($a, $received);
                mqttWrite($a, mqttSubscription(5, $actual, 1, 0, false));
                expect(mqttRead($a) === mqttSubscriptionAck(5, "\x11", 1, false), '取消普通过滤器错误退出共享');
                mqttWrite($a, mqttSubscription(5, $filter, 1, 0, false));
                expect(mqttRead($a) === mqttSubscriptionAck(5, "\0", 1, false), '精确取消共享失败');
                mqttSharedPublish($publisher, $actual, 'after-unsubscribe');
                mqttSharedQuiet($a);
                $close($a);
                $cases += 4;
            }
            // QoS 2 的 PUBREC 前/后中断均固定原 Session；负确认不换成员。
            foreach (['before', 'after', 'terminate-before', 'terminate-after', 'negative'] as $stage) {
                $actual = 'example/' . $prefix . 'qos2-' . $stage;
                $filter = '$share/recovery/' . $actual;
                $a = $open('recover-a', true, 120, "\x21\0\x01\x22\0\x01");
                $subscribe($a, $filter, 2, 51);
                mqttSharedPublish($publisher, $actual, $stage, 2, false, "\x02" . pack('N', 3));
                $initial = mqttRetainedRead($a, 5);
                expect($initial['qos'] === 2 && !$initial['duplicate'] && $initial['topic'] === $actual, '共享 QoS 2 初次发送错误');
                $b = $open('recover-b', true, 120, "\x21\0\x01");
                $subscribe($b, $filter, 2, 53);
                if ($stage === 'negative') {
                    mqttWrite($a, mqttQos2Ack(0x50, $initial['id'], 0x97));
                    mqttSharedQuiet($a);
                    mqttSharedQuiet($b);
                } else {
                    if (str_contains($stage, 'after')) {
                        mqttWrite($a, mqttQos2Ack(0x50, $initial['id']));
                        expect(mqttRead($a) === mqttQos2Ack(0x62, $initial['id']), '共享 QoS 2 未进入 PUBREL');
                    }
                    $terminate = str_starts_with($stage, 'terminate-');
                    $close($a, $terminate);
                    mqttSharedQuiet($b);
                    if (!$terminate) {
                        $a = $open('recover-a', false, 120, "\x22\0\x01");
                        if ($stage === 'after') {
                            expect(mqttRead($a) === mqttQos2Ack(0x62, $initial['id']), 'PUBREC 后恢复回退 PUBLISH');
                            mqttWrite($a, mqttQos2Ack(0x70, $initial['id']));
                        } else {
                            $restored = mqttRetainedRead($a, 5);
                            expect($restored['duplicate'] && $restored['id'] === $initial['id'] && $restored['topic'] === $actual
                                && $restored['payload'] === $stage && mqttDeliveryProperties($restored['properties'])['identifiers'] === [51], 'PUBREC 前恢复换了成员、标识、Topic 或没有 DUP');
                            mqttRetainedComplete($a, $restored);
                        }
                    } else {
                        $a = $open('recover-a');
                        mqttSharedQuiet($a);
                    }
                }
                $close($a);
                mqttSharedPublish($publisher, $actual, 'control-' . $stage, 2);
                $control = mqttRetainedRead($b, 5);
                expect($control['payload'] === 'control-' . $stage, '禁止改投检查后组失去活性');
                mqttRetainedComplete($b, $control);
                $close($b);
                $traces[$prefix . $stage] = ['first' => $initial, 'interruption' => $stage, 'other-member-control' => $control];
                $cases += 3;
            }
            // 慢成员 Receive Maximum=1，组内其他成员继续消费；QoS 1 等原会话并在终止时改投。
            foreach ([false, true] as $terminate) {
                $actual = 'example/' . $prefix . 'qos1-' . (int) $terminate;
                $filter = '$share/qos1/' . $actual;
                $a = $open('qos1-a', true, 120, "\x21\0\x01");
                $subscribe($a, $filter, 1);
                mqttSharedPublish($publisher, $actual, 'first', 2);
                $first = mqttRetainedRead($a, 5);
                $b = $open('qos1-b');
                $subscribe($b, $filter, 1);
                mqttSharedPublish($publisher, $actual, 'second');
                $second = mqttRetainedRead($b, 5);
                expect($second['payload'] === 'second', '慢成员阻塞了可用成员');
                mqttRetainedComplete($b, $second);
                mqttSharedQuiet($a);
                $close($a, $terminate);
                if ($terminate) {
                    $retry = mqttRetainedRead($b, 5);
                    expect($retry['payload'] === 'first' && !$retry['duplicate'], 'QoS 1 原会话终止未新交换改投');
                    mqttRetainedComplete($b, $retry);
                } else {
                    mqttSharedQuiet($b);
                    $a = $open('qos1-a', false);
                    $retry = mqttRetainedRead($a, 5);
                    expect($retry['payload'] === 'first' && $retry['duplicate'] && $retry['id'] === $first['id'], 'QoS 1 未按原会话恢复');
                    mqttWrite($a, mqttQos2Ack(0x40, $retry['id'], 0x97));
                    mqttSharedQuiet($b);
                    $close($a);
                }
                $close($b);
                $cases += 3;
            }
            // 最后成员取消只清未开始积压，已经开始的 QoS 2 仍完成。
            $actual = 'example/' . $prefix . 'last';
            $filter = '$share/last/' . $actual;
            $a = $open('last', true, 120, "\x21\0\x01");
            $subscribe($a, $filter, 2);
            mqttSharedPublish($publisher, $actual, 'inflight', 2);
            $first = mqttRetainedRead($a, 5);
            mqttSharedPublish($publisher, $actual, 'queued', 2);
            mqttWrite($a, mqttSubscription(5, $filter, 1, 0, false));
            expect(mqttRead($a) === mqttSubscriptionAck(5, "\0", 1, false), '最后成员取消失败');
            mqttRetainedComplete($a, $first);
            $subscribe($a, $filter, 2);
            mqttSharedQuiet($a);
            mqttSharedPublish($publisher, $actual, 'new-generation', 2);
            $received = mqttRetainedRead($a, 5);
            expect($received['payload'] === 'new-generation', '新组继承旧积压');
            mqttRetainedComplete($a, $received);
            $close($a);
            $cases += 3;
            // 离线期限绝不刷新；可接收新成员消费旧的尚未分配副本。
            $actual = 'example/' . $prefix . 'expiry';
            $filter = '$share/expiry/' . $actual;
            $a = $open('expiry');
            $subscribe($a, $filter, 1);
            $close($a, false);
            mqttSharedPublish($publisher, $actual, 'short', 1, false, "\x02" . pack('N', 1));
            mqttSharedPublish($publisher, $actual, 'long', 1, false, "\x02" . pack('N', 90));
            usleep(2100000);
            $b = $open('new-member');
            $subscribe($b, $filter, 1);
            $received = mqttRetainedRead($b, 5);
            expect($received['payload'] === 'long' && ord($received['properties'][0]) === 2 && unpack('N', substr($received['properties'], 1, 4))[1] < 90, '组等待刷新期限或投递过期副本');
            mqttRetainedComplete($b, $received);
            mqttSharedQuiet($b);
            $a = $open('expiry', false);
            $close($a);
            $close($b);
            $cases += 2;
            // 完整报文包含 Topic、属性和标识；所选成员不能容纳时终结组副本并保持连接。
            $actual = 'example/' . $prefix . 'size';
            $a = $open('small', true, 120, "\x27" . pack('N', 80));
            $subscribe($a, '$share/size/' . $actual, 1, 268435455);
            mqttSharedPublish($publisher, $actual, str_repeat('x', 100));
            mqttSharedQuiet($a);
            mqttSharedPublish($publisher, $actual, 'ok');
            $received = mqttRetainedRead($a, 5);
            expect($received['payload'] === 'ok', '超大组副本关闭了合法接收连接');
            mqttRetainedComplete($a, $received);
            $close($a);
            $cases += 2;
            // 有限会话终止退出组；相同 Client ID 的新会话不能恢复旧组积压。
            $actual = 'example/' . $prefix . 'session-expiry';
            $a = $open('short-session', true, 1);
            $subscribe($a, '$share/short/' . $actual);
            $close($a, false);
            mqttSharedPublish($publisher, $actual, 'old-session');
            usleep(2100000);
            $a = $open('short-session');
            $subscribe($a, '$share/short/' . $actual);
            mqttSharedQuiet($a);
            mqttSharedPublish($publisher, $actual, 'new-session');
            $received = mqttRetainedRead($a, 5);
            expect($received['payload'] === 'new-session', '到期会话或新组恢复了旧积压');
            mqttRetainedComplete($a, $received);
            $close($a);
            $cases++;
            $close($publisher);
            // 精确截住共享归属提交：主库 started 可见但同步取消，socket 不得收到 PUBLISH。
            $actual = 'example/' . $prefix . 'claim-unknown';
            $filter = '$share/unknown/' . $actual;
            $a = $open('unknown-a');
            $subscribe($a, $filter, 2, 71);
            $close($a, false);
            $publisher = $open('unknown-p');
            mqttSharedPublish($publisher, $actual, 'intent-before-socket', 2);
            $close($publisher);
            $primary->exec(<<<'SQL'
CREATE FUNCTION mqtt_test_shared_claim() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.group_id <> '' AND NEW.started AND NOT OLD.started THEN
        PERFORM pg_advisory_xact_lock(1954115693, 103);
    END IF;
    RETURN NEW;
END $$
SQL);
            $primary->exec('CREATE TRIGGER mqtt_test_shared_claim BEFORE UPDATE ON type_mqtt_deliveries FOR EACH ROW EXECUTE FUNCTION mqtt_test_shared_claim()');
            $primary->query('SELECT pg_advisory_lock(1954115693, 103)')->fetchColumn();
            try {
                $a = $open('unknown-a', false);
                $pid = (int) mqttUntil(fn (): mixed => $primary->query('SELECT a.pid FROM pg_stat_activity a JOIN pg_locks l ON l.pid = a.pid '
                    . "WHERE a.application_name LIKE 'type_mqtt_%' AND l.locktype = 'advisory' AND l.classid = 1954115693 AND l.objid = 103 AND NOT l.granted")->fetchColumn(), '共享归属没有进入精确故障窗口');
                $standby->query('SELECT pg_wal_replay_pause()')->fetchColumn();
                $primary->query('SELECT pg_advisory_unlock(1954115693, 103)')->fetchColumn();
                mqttUntil(fn (): mixed => $primary->query('SELECT pid FROM pg_stat_activity WHERE pid = ' . $pid . " AND wait_event = 'SyncRep'")->fetchColumn(), '共享归属提交没有等待同步');
                $primary->query('SELECT pg_cancel_backend(' . $pid . ')')->fetchColumn();
                stream_set_timeout($a, 8);
                expect(mqttRead($a) === "\xe0\x02\x83\0", '未知共享归属仍发 PUBLISH 或没有隔离连接');
                fclose($a);
                $statement = $primary->prepare('SELECT d.session_id, d.packet_id, d.started, d.phase FROM type_mqtt_deliveries d JOIN type_mqtt_messages m ON m.id = d.message_id WHERE m.topic = ?');
                $statement->execute([$actual]);
                $intent = $statement->fetch(PDO::FETCH_ASSOC);
                $statement = null;
                expect($intent['started'] && (int) $intent['packet_id'] > 0 && $intent['phase'] === 'wait_pubrec', '取消同步没有保留共享发送意图');
            } finally {
                $standby->query('SELECT pg_wal_replay_resume()')->fetchColumn();
                $primary->query('SELECT pg_advisory_unlock_all()')->fetchColumn();
                $primary->exec('DROP TRIGGER mqtt_test_shared_claim ON type_mqtt_deliveries');
                $primary->exec('DROP FUNCTION mqtt_test_shared_claim()');
            }
            $b = $open('unknown-b');
            $subscribe($b, $filter, 2, 73);
            mqttSharedQuiet($b);
            $a = $open('unknown-a', false);
            $received = mqttRetainedRead($a, 5);
            expect($received['duplicate'] && $received['id'] === (int) $intent['packet_id'] && $received['payload'] === 'intent-before-socket'
                && mqttDeliveryProperties($received['properties'])['identifiers'] === [71], '未知归属恢复没有保持原成员、标识及 DUP');
            mqttRetainedComplete($a, $received);
            $close($a);
            $close($b);
            $traces[$prefix . 'claim-unknown'] = ['intent' => $intent, 'initial_publish_sent' => false, 'restored' => $received];
            $cases += 3;
            $result = $process->stop(12);
            expect($result->successful() && $result->stderr === '', '共享 Broker 清理失败：' . $result->stderr);
            $statistics = json_decode($result->stdout, true, 32, JSON_THROW_ON_ERROR);
            foreach (['connections', 'subscriptions', 'bufferedBytes', 'retainedQueued', 'retainedPending', 'retainedBytes', 'pendingCommits', 'quarantinedCommits', 'closingSessions'] as $field) {
                expect($statistics[$field] === 0, '共享资源没有归零：' . $field);
            }
            $traces[$tls ? 'tls' : 'tcp'] = $statistics;
            // 小配置验证组条数与字节独立预算；入站失败不驱逐已确认的两条副本。
            foreach (['count', 'bytes'] as $limit) {
                $environment['MQTT_SHARED_MAX_MESSAGES'] = $limit === 'count' ? '2' : '100';
                $environment['MQTT_SHARED_MAX_BYTES'] = $limit === 'bytes' ? '100' : '2147483648';
                $process = $start();
                $actual = 'example/' . $prefix . 'quota-' . $limit;
                $filter = '$share/quota/' . $actual;
                $a = $open('quota-a');
                $b = $open('quota-b');
                $subscribe($a, $filter);
                $subscribe($b, $filter);
                $close($a, false);
                $close($b, false);
                $publisher = $open('quota-p');
                if ($limit === 'count') {
                    mqttSharedPublish($publisher, $actual, 'saved-1');
                    mqttSharedPublish($publisher, $actual, 'saved-2');
                }
                mqttWrite($publisher, mqttRetainedPacket(5, $actual, $limit === 'bytes' ? str_repeat('x', 101) : 'refused', 1, 19, '', false));
                expect(mqttRead($publisher) === "\xe0\x02\x97\0", '共享满额仍成功确认');
                fclose($publisher);
                $a = $open('quota-a', false);
                if ($limit === 'count') {
                    $payloads = [];
                    for ($copy = 0; $copy < 2; $copy++) {
                        $received = mqttRetainedRead($a, 5);
                        $payloads[] = $received['payload'];
                        mqttRetainedComplete($a, $received);
                    }
                    sort($payloads);
                    expect($payloads === ['saved-1', 'saved-2'], '共享容量驱逐或改写旧可靠消息');
                }
                mqttSharedQuiet($a);
                $close($a);
                $b = $open('quota-b', false);
                $close($b);
                $result = $process->stop(12);
                expect($result->successful() && $result->stderr === '', '共享低额度 Broker 未回收资源');
                $statistics = json_decode($result->stdout, true, 32, JSON_THROW_ON_ERROR);
                foreach (['connections', 'subscriptions', 'bufferedBytes', 'pendingCommits', 'quarantinedCommits', 'closingSessions'] as $field) {
                    expect($statistics[$field] === 0, '共享低额度资源未归零：' . $field);
                }
                $traces[$prefix . 'quota-' . $limit] = $statistics;
                $cases += 2;
            }
            unset($environment['MQTT_SHARED_MAX_MESSAGES'], $environment['MQTT_SHARED_MAX_BYTES']);
        }
        return ['cases' => $cases, 'statistics' => $traces, 'replication' => $sync->evidence()];
    } finally {
        foreach ($sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        $process?->stop(12);
        $statement = null;
        $primary = null;
        $standby = null;
        $sync?->close();
        $database->close();
    }
}
