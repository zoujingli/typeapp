<?php

declare(strict_types=1);

use Type\Testing\Process;

/** 认证身份与协议参数独立编码；服务名只在正确服务密码通过后取得较大额度。 */
function mqttCapacityConnect(int $version, string $id, bool $application = false, bool $clean = true, int $expiry = 120): string
{
    $properties = "\x11" . pack('N', $expiry) . "\x21\0\x01";
    return mqttPacket(0x10, mqttField('MQTT') . chr($version) . chr($clean ? 0xc2 : 0xc0) . pack('n', 30)
        . ($version === 5 ? mqttLength(strlen($properties)) . $properties : '') . mqttField($id)
        . mqttField($application ? 'service' : 'example') . mqttField($application ? 'service-test-secret' : 'mqtt-test-secret'));
}

/** 独立主备和安装后应用；低额度验证拒绝及恢复规则，不代替万连接或目标积压压测。 */
function mqttCapacityCases(string $root, string $consumer, array $command, array $workerCommand, array $environment): array
{
    ini_set('zend.exception_ignore_args', '1');
    require_once __DIR__ . '/native-database.php';
    require_once __DIR__ . '/postgres-sync.php';
    require_once __DIR__ . '/mqtt-qos1.php';
    require_once __DIR__ . '/mqtt-qos2.php';
    require_once __DIR__ . '/mqtt-retained.php';
    require_once __DIR__ . '/mqtt-subscriptions.php';
    $tools = NativeDatabase::tools('pgsql', (string) getenv('TYPE_PGSQL_TOOLS'));
    $database = new NativeDatabase($consumer . '/capacity-primary', 'pgsql', $tools);
    $sync = null;
    $process = null;
    $primary = null;
    $standby = null;
    $sockets = [];
    $cases = 0;
    $measurements = [];
    $statistics = [];
    try {
        $sync = new PostgresSync($database, $consumer . '/capacity-standby', $tools);
        $environment = array_replace($environment, $database->environment());
        $environment['MQTT_WORKER_COMMAND'] = json_encode($workerCommand, JSON_THROW_ON_ERROR);
        $environment['MQTT_SERVICE_PASSWORD'] = 'service-test-secret';
        $installed = (new Process([...$command, '--install-store'], $consumer, $environment))->wait(10);
        expect($installed->successful() && $installed->stderr === '' && json_decode($installed->stdout, true)['state'] === 'committed', '容量存储安装失败：' . $installed->stderr);
        $primary = $sync->connection();
        $standby = $sync->standby();
        $usage = function () use ($command, $consumer, &$environment): array {
            $result = (new Process([...$command, '--store-statistics'], $consumer, $environment))->wait(10);
            expect($result->successful() && $result->stderr === '', '公开容量统计失败：' . $result->stderr);
            $proof = json_decode($result->stdout, true, 32, JSON_THROW_ON_ERROR);
            expect($proof['state'] === 'committed' && $proof['released'], '容量统计没有同步快照证明：' . $result->stdout);
            return $proof['value'];
        };
        $defaults = $usage();
        expect($defaults['maximumSessions'] === 20000 && $defaults['maximumPendingMessages'] === 2000000 && $defaults['maximumPendingBytes'] === 4294967296
            && $defaults['maximumDeviceMessages'] === 10000 && $defaults['maximumDeviceBytes'] === 16777216
            && $defaults['maximumApplicationMessages'] === 1000000 && $defaults['maximumApplicationBytes'] === 2147483648
            && $defaults['maximumSharedMessages'] === 1000000 && $defaults['maximumSharedBytes'] === 2147483648, '默认持久额度与规格不符');
        $cases++;
        foreach ([false, true] as $tls) {
            $certificate = $tls ? $consumer . '/certificate.pem' : null;
            $environment['MQTT_CERTIFICATE'] = $certificate ?? '';
            $environment['MQTT_PRIVATE_KEY'] = $tls ? $consumer . '/private.pem' : '';
            $environment['MQTT_MAX_CONNECTIONS'] = '8';
            $environment['MQTT_MAX_DEVICE_CONNECTIONS'] = '2';
            $environment['MQTT_MAX_SERVICE_CONNECTIONS'] = '1';
            $environment['MQTT_MAX_SESSIONS'] = '3';
            $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
            $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
            fclose($listener);
            $start = function () use ($command, $consumer, &$environment, $port, $tls): Process {
                $running = new Process([...$command, '--port=' . $port, ...($tls ? [] : ['--plaintext'])], $consumer, $environment);
                mqttUntil(function () use ($running, $port): bool {
                    expect($running->running(), '容量 Broker 提前退出：' . $running->stderr());
                    $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $number, $message, 0.1);
                    if (!is_resource($probe)) {
                        return false;
                    }
                    fclose($probe);
                    return true;
                }, '容量 Broker 未启动');
                return $running;
            };
            $open = function (string $id, bool $application = false, bool $clean = true, int $expiry = 120) use ($port, $certificate, &$sockets): mixed {
                $socket = mqttSocket($port, $certificate);
                $sockets[] = $socket;
                mqttWrite($socket, mqttCapacityConnect(5, $id, $application, $clean, $expiry));
                $ack = mqttRead($socket);
                expect(strlen($ack) >= 4 && ord($ack[0]) === 0x20 && ord($ack[3]) === 0 && ord($ack[2]) === ($clean ? 0 : 1), '容量会话打开失败：' . bin2hex($ack));
                return $socket;
            };
            $close = function (mixed $socket, bool $terminate = true): void {
                mqttWrite($socket, $terminate ? mqttPacket(0xe0, "\0\x05\x11\0\0\0\0") : "\xe0\0");
                expect(mqttRead($socket) === '', '容量会话未关闭');
                fclose($socket);
            };
            $process = $start();
            $a = $open('capacity-a');
            $b = $open('capacity-b');
            foreach ([4, 5] as $version) {
                $denied = mqttSocket($port, $certificate);
                mqttWrite($denied, mqttCapacityConnect($version, 'capacity-extra'));
                $failure = mqttRead($denied);
                expect(ord($failure[3]) === ($version === 5 ? 0x97 : 3), '设备满额没有标准拒绝');
                fclose($denied);
                $cases++;
            }
            $service = $open('capacity-service', true);
            $denied = mqttSocket($port, $certificate);
            mqttWrite($denied, mqttCapacityConnect(5, 'capacity-service-extra', true));
            expect(ord(mqttRead($denied)[3]) === 0x97, '服务额度可无限扩大');
            fclose($denied);
            $wrong = mqttSocket($port, $certificate);
            mqttWrite($wrong, str_replace('service-test-secret', str_repeat('x', strlen('service-test-secret')), mqttCapacityConnect(5, 'capacity-service', true)));
            expect(ord(mqttRead($wrong)[3]) === 0x86, '客户端冒充服务身份获得额度');
            fclose($wrong);
            mqttQuiet($service);
            $filters = [];
            for ($index = 0; $index < 100; $index++) {
                $filters['example/capacity/filter/' . $index] = 33;
            }
            mqttWrite($a, mqttSubscribeFilters(5, $filters));
            expect(mqttRead($a) === mqttSubscriptionAck(5, str_repeat("\x01", 100)), '百条有效订阅未授予');
            mqttWrite($a, mqttSubscribeFilters(5, $filters, 7));
            expect(mqttRead($a) === mqttSubscriptionAck(5, str_repeat("\x01", 100)), '订阅替换错误占用额外配额');
            mqttWrite($a, mqttSubscribeFilters(5, ['example/capacity/overflow' => 33]));
            expect(mqttRead($a) === mqttSubscriptionAck(5, "\x97"), '101条有效订阅未拒绝');
            $snapshot = $usage();
            expect($snapshot['sessions'] === 3 && $snapshot['deviceSessions'] === 2 && $snapshot['applicationSessions'] === 1 && $snapshot['subscriptions'] === 100, '会话分类或替换统计错误');
            $close($b, false);
            mqttUntil(fn (): bool => $standby->query("SELECT owner_id FROM type_mqtt_sessions WHERE client_id = 'capacity-b'")->fetchColumn() === null, '容量会话未持久离线');
            $denied = mqttSocket($port, $certificate);
            mqttWrite($denied, mqttCapacityConnect(5, 'capacity-new'));
            expect(ord(mqttRead($denied)[3]) === 0x97, '持久会话满额仍接纳新身份');
            fclose($denied);
            $b = $open('capacity-b', false, false);
            expect($usage()['sessions'] === 3, '满额重连复制或删除持久会话');
            foreach ([$a, $b] as $socket) {
                $close($socket);
            }
            $close($service, false);
            mqttUntil(fn (): bool => $usage()['sessions'] === 1, '分类恢复检查未取得离线会话');
            $denied = mqttSocket($port, $certificate);
            mqttWrite($denied, mqttCapacityConnect(5, 'capacity-service', false, false));
            $changedClass = mqttRead($denied);
            expect(ord($changedClass[3]) === 0x87, '恢复会话通过改变容量分类绕过既有归属：' . bin2hex($changedClass));
            fclose($denied);
            expect($usage()['applicationSessions'] === 1, '分类拒绝删除原应用会话');
            $service = $open('capacity-service', true, false);
            $close($service);
            mqttUntil(fn (): bool => $usage()['sessions'] === 0, '退出未释放分类会话');
            for ($cycle = 0; $cycle < 20; $cycle++) {
                $repeated = $open('capacity-cycle', $cycle % 2 === 0, true, 0);
                $close($repeated);
            }
            mqttUntil(fn (): bool => $usage()['sessions'] === 0, '反复连接退出遗留持久记录');
            $result = $process->stop(12);
            expect($result->successful() && $result->stderr === '', '分类容量 Broker 退出失败：' . $result->stderr);
            $counts = json_decode($result->stdout, true);
            expect($counts['connectionQuotaRefusals'] >= 3 && $counts['commitQuotaRefusals'] >= 1 && $counts['subscriptionQuotaRefusals'] === 1, '配额拒绝未独立计量');
            $statistics[] = $counts;
            $cases += 32;
            $environment['MQTT_MAX_SESSIONS'] = '20';
            $profiles = [
                ['device-count', false, 2, ['MQTT_DEVICE_MAX_MESSAGES' => '2']],
                ['device-bytes', false, 2, ['MQTT_DEVICE_MAX_BYTES' => '48']],
                ['application-count', true, 3, ['MQTT_APPLICATION_MAX_MESSAGES' => '3']],
                ['application-bytes', true, 3, ['MQTT_APPLICATION_MAX_BYTES' => '72']],
                ['global-count', true, 3, ['MQTT_PENDING_MAX_MESSAGES' => '6']],
                ['global-bytes', true, 3, ['MQTT_PENDING_MAX_BYTES' => '144']],
                ['shared-count', true, 2, ['MQTT_SHARED_MAX_MESSAGES' => '2']],
                ['shared-bytes', true, 2, ['MQTT_SHARED_MAX_BYTES' => '48']],
            ];
            foreach ($profiles as [$name, $application, $limit, $budget]) {
                $environment = array_replace($environment, $budget);
                $process = $start();
                $id = 'capacity-' . $name;
                $topic = 'example/capacity';
                $shared = str_starts_with($name, 'shared-');
                $filter = $shared ? '$share/capacity/' . $topic : $topic;
                $subscriber = $open($id, $application);
                mqttWrite($subscriber, mqttSubscribeFilters(5, [$filter => 33]));
                expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\x01"), '容量消费者订阅失败');
                $close($subscriber, false);
                mqttUntil(fn (): bool => $usage()['sessions'] === 1, '容量消费者未离线');
                $publish = function (int $sequence, int $version, bool $accepted) use ($port, $certificate, $topic, $id): void {
                    $publisher = mqttSocket($port, $certificate);
                    mqttWrite($publisher, mqttCapacityConnect($version, $id . '-publisher-' . $sequence, false, true, 0));
                    mqttAck($publisher, $version);
                    $payload = str_pad((string) $sequence, 24 - strlen($topic), '.');
                    mqttWrite($publisher, mqttReliablePublish($version, $topic, $payload, 1));
                    $response = mqttRead($publisher);
                    expect($accepted ? $response === "\x40\x02\0\x01"
                        : ($version === 5 ? $response === "\xe0\x02\x97\0" : $response === ''), '满额发布结果错误：' . $id . ':' . $sequence . ':' . bin2hex($response));
                    if ($accepted) {
                        mqttWrite($publisher, "\xe0\0");
                        expect(mqttRead($publisher) === '', '瞬态发布者未退出');
                    } elseif ($version === 5) {
                        expect(mqttRead($publisher) === '', '配额拒绝后未关闭受影响发布者');
                    }
                    fclose($publisher);
                };
                for ($index = 1; $index <= $limit; $index++) {
                    $publish($index, 5, true);
                }
                $full = $usage();
                $category = $shared ? 'shared' : ($application ? 'application' : 'device');
                expect($full['pendingMessages'] === 2 * $limit && $full['pendingBytes'] === 48 * $limit
                    && $full[$category . 'PendingMessages'] >= $limit, '配额未按原件和副本逻辑字节计量');
                foreach ([4, 5] as $version) {
                    $publish(10 + $version, $version, false);
                }
                $stillFull = $usage();
                expect($stillFull['pendingMessages'] === $full['pendingMessages'] && $stillFull['pendingBytes'] === $full['pendingBytes'], '满额拒绝删除或假确认了旧消息');
                $subscriber = $open($id, $application, false);
                $first = mqttRetainedRead($subscriber, 5);
                $expected = [];
                for ($sequence = 1; $sequence <= $limit; $sequence++) {
                    $expected[] = str_pad((string) $sequence, 24 - strlen($topic), '.');
                }
                expect(in_array($first['payload'], $expected, true), '已接受原件在恢复前丢失');
                $publish(20, 5, false);
                mqttRetainedComplete($subscriber, $first);
                mqttUntil(fn (): bool => $usage()['pendingMessages'] === 2 * ($limit - 1), '确认未释放原件及副本容量');
                $publish(21, 5, true);
                $received = [];
                for ($index = 0; $index < $limit; $index++) {
                    $message = mqttRetainedRead($subscriber, 5);
                    $received[] = $message['payload'];
                    mqttRetainedComplete($subscriber, $message);
                }
                $expected[] = str_pad('21', 24 - strlen($topic), '.');
                $received[] = $first['payload'];
                sort($expected);
                sort($received);
                expect($received === $expected, '释放容量后交付集合不符或旧消息丢失');
                mqttUntil(fn (): bool => $usage()['pendingMessages'] === 0, '完成交付没有释放积压');
                $close($subscriber);
                mqttUntil(fn (): bool => $usage()['sessions'] === 0, '容量消费者未回收');
                $result = $process->stop(12);
                expect($result->successful() && $result->stderr === '', '持久容量 Broker 退出失败：' . $result->stderr);
                $counts = json_decode($result->stdout, true);
                expect($counts['commitQuotaRefusals'] >= 3, '持久配额拒绝没有指标');
                $statistics[] = $counts;
                $measurements[] = ['transport' => $tls ? 'tls' : 'tcp', 'profile' => $name, 'limit' => $limit, 'logical-bytes' => 24, 'full' => $full];
                foreach (array_keys($budget) as $key) {
                    unset($environment[$key]);
                }
                $cases += 12;
            }
        }
        foreach ($statistics as $counts) {
            expect($counts['connections'] === 0 && $counts['deviceConnections'] === 0 && $counts['serviceConnections'] === 0
                && $counts['subscriptions'] === 0 && $counts['bufferedBytes'] === 0 && $counts['incomingExchanges'] === 0 && $counts['outgoingExchanges'] === 0
                && $counts['pendingCommits'] === 0 && $counts['closingSessions'] === 0 && $counts['quarantinedCommits'] === 0, '容量路径退出资源未清零');
            expect($counts['eventRegistrations'] === 0 && $counts['eventTimers'] === 0 && $counts['readyEvents'] === 0 && $counts['pendingReads'] === 0, '容量路径原生事件未清理');
        }
        mqttDatabaseIdle($primary);
        return ['cases' => $cases, 'scope' => 'reduced-budget-functional-verification', 'target-scale-verified' => false,
            'defaults' => $defaults, 'measurements' => $measurements, 'statistics' => $statistics, 'replication' => $sync->evidence()];
    } finally {
        foreach ($sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        $process?->stop(12);
        $primary = null;
        $standby = null;
        $sync?->close();
        $database->close();
    }
}

/** 验证真实网络连接上限；无持久worker的QoS0引擎场景不能代替设备同步接收及24小时负载。 */
function mqttConnectionScaleCases(string $consumer, array $command, array $environment): array
{
    $devices = (int) (getenv('MQTT_SCALE_DEVICES') ?: 10000);
    expect($devices > 512 && $devices <= 10000, '连接规模须为513..10000');
    $services = 100;
    $environment['MQTT_MAX_CONNECTIONS'] = (string) ($devices + $services);
    $environment['MQTT_MAX_DEVICE_CONNECTIONS'] = (string) $devices;
    $environment['MQTT_MAX_SERVICE_CONNECTIONS'] = (string) $services;
    $environment['MQTT_SERVICE_PASSWORD'] = 'service-test-secret';
    $environment['MQTT_WORKER_COMMAND'] = '';
    $results = [];
    foreach ([false, true] as $tls) {
        $sockets = [];
        $certificate = $tls ? $consumer . '/certificate.pem' : null;
        $environment['MQTT_CERTIFICATE'] = $certificate ?? '';
        $environment['MQTT_PRIVATE_KEY'] = $tls ? $consumer . '/private.pem' : '';
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        fclose($listener);
        $process = new Process([...$command, '--port=' . $port, ...($tls ? [] : ['--plaintext'])], $consumer, $environment);
        try {
            $until = microtime(true) + 10;
            do {
                expect($process->running(), '原生事件Broker提前退出：' . $process->stderr());
                $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.1);
                if (is_resource($probe)) {
                    fclose($probe);
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $until);
            $started = microtime(true);
            for ($index = 0; $index < $devices; $index++) {
                $socket = mqttSocket($port, $certificate);
                $sockets[] = $socket;
                $version = $index % 2 === 0 ? 5 : 4;
                // Keep Alive=0是独立连接引擎验证；设备30秒契约另由业务负载验证。
                mqttWrite($socket, mqttConnect($version, 'scale-device-' . $index, 0));
                mqttAck($socket, $version);
            }
            $overflow = mqttSocket($port, $certificate);
            mqttWrite($overflow, mqttConnect(5, 'scale-device-overflow', 0));
            expect(ord(mqttRead($overflow)[3]) === 0x97, '设备满额未保留服务入口或未返回标准配额失败');
            fclose($overflow);
            for ($index = 0; $index < $services; $index++) {
                $socket = mqttSocket($port, $certificate);
                $sockets[] = $socket;
                mqttWrite($socket, mqttPacket(0x10, mqttField('MQTT') . "\x05\xc2\0\0\0" . mqttField('scale-service-' . $index)
                    . mqttField('service') . mqttField('service-test-secret')));
                mqttAck($socket, 5);
            }
            foreach ($sockets as $socket) {
                mqttWrite($socket, "\xc0\0");
            }
            foreach ($sockets as $socket) {
                expect(mqttRead($socket) === "\xd0\0", '满额时已有连接被挤掉或不能响应');
            }
            $liveConnections = count($sockets);
            $connectedSeconds = microtime(true) - $started;
            $rss = successful(['ps', '-o', 'rss=', '-p', (string) $process->pid()], $consumer);
            expect(preg_match('/^[0-9]+$/D', trim($rss)) === 1, '不能读取实际Broker RSS');
            foreach ($sockets as $socket) {
                mqttWrite($socket, "\xe0\0");
                fclose($socket);
            }
            $sockets = [];
            // 关闭后的事件注册须允许描述符复用，继续用公开连接和PING验证。
            for ($cycle = 0; $cycle < 20; $cycle++) {
                $socket = mqttSocket($port, $certificate);
                $sockets[] = $socket;
                mqttWrite($socket, mqttConnect(5, 'scale-reuse-' . $cycle, 0));
                mqttAck($socket, 5);
                mqttWrite($socket, "\xc0\0");
                expect(mqttRead($socket) === "\xd0\0", '描述符复用后的连接不能响应');
                mqttWrite($socket, "\xe0\0");
                fclose($socket);
            }
            $result = $process->stop(10);
            expect($result->successful() && $result->stderr === '', '原生事件Broker清理失败：' . $result->stderr);
            $counts = json_decode($result->stdout, true, 32, JSON_THROW_ON_ERROR);
            foreach (['connections', 'deviceConnections', 'serviceConnections', 'bufferedBytes', 'incomingExchanges', 'outgoingExchanges',
                'eventRegistrations', 'eventTimers', 'readyEvents', 'pendingReads', 'pendingCommits', 'closingSessions'] as $key) {
                expect($counts[$key] === 0, '规模退出资源未归零：' . $key);
            }
            expect($counts['maximumDeviceConnections'] === $devices && $counts['maximumServiceConnections'] === $services
                && $counts['connectionQuotaRefusals'] >= 1, '原生事件额度或拒绝统计错误');
            $results[] = ['transport' => $tls ? 'tls' : 'tcp', 'device_connections' => $devices, 'service_connections' => $services,
                'confirmed_live_connections' => $liveConnections, 'connect_and_ping_seconds' => $connectedSeconds,
                'broker_rss_sample_bytes' => (int) trim($rss) * 1024, 'statistics' => $counts];
        } catch (Throwable $error) {
            $failure = $process->stop(10);
            throw new RuntimeException('连接规模验证失败：transport=' . ($tls ? 'tls' : 'tcp') . ', opened=' . count($sockets)
                . ', broker=' . $failure->stdout . $failure->stderr, 0, $error);
        } finally {
            foreach ($sockets as $socket) {
                if (is_resource($socket)) {
                    fclose($socket);
                }
            }
            $process->stop(10);
        }
    }
    return ['scope' => 'qos0-connection-engine-only', 'target_load_verified' => false, 'runs' => $results];
}
