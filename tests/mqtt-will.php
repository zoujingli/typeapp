<?php

declare(strict_types=1);

use Type\Testing\Process;

/** 独立标准线编码；遗嘱载荷是 MQTT Binary Data，不是业务 JSON。 */
function mqttWillConnect(int $version, string $id, string $topic, string $payload, int $qos = 0, bool $retain = false, int $delay = 0, ?int $expiry = 0, bool $clean = true, string $properties = '', int $keepalive = 0): string
{
    $connectProperties = $expiry === null ? '' : "\x11" . pack('N', $expiry);
    $willProperties = ($delay === 0 ? '' : "\x18" . pack('N', $delay)) . $properties;
    return mqttPacket(0x10, mqttField('MQTT') . chr($version) . chr(0xc4 | ($clean ? 2 : 0) | ($qos << 3) | ($retain ? 32 : 0))
        . pack('n', $keepalive) . ($version === 5 ? mqttLength(strlen($connectProperties)) . $connectProperties : '') . mqttField($id)
        . ($version === 5 ? mqttLength(strlen($willProperties)) . $willProperties : '') . mqttField($topic) . mqttField($payload)
        . mqttField('example') . mqttField('mqtt-test-secret'));
}

/** 不发送 PING，以免误把已排队 PUBLISH 当成响应；持续观察真正网络时间窗口。 */
function mqttWillSilence(mixed $socket, float $seconds): void
{
    $read = [$socket];
    $write = [];
    $except = [];
    expect(stream_select($read, $write, $except, (int) $seconds, (int) (($seconds - floor($seconds)) * 1000000)) === 0, '遗嘱在禁止窗口内发布或连接意外关闭');
}

function mqttWillSubscriber(int $port, string $id, string $topic, int $version = 5, int $qos = 2, ?string $certificate = null, string $properties = ''): mixed
{
    $socket = mqttSocket($port, $certificate);
    mqttWrite($socket, mqttConnect($version, $id, 0, $properties));
    mqttAck($socket, $version);
    mqttWrite($socket, mqttSubscription($version, $topic, 1, $qos));
    expect(mqttRead($socket) === mqttSubscriptionAck($version, chr($qos)), '遗嘱观察者订阅失败');
    return $socket;
}

/** 独立消费者、真实同步主备与进程重启；所有运行目录属于本轮 build。 */
function mqttWillCases(string $root, string $consumer, array $command, array $workerCommand, array $environment): array
{
    ini_set('zend.exception_ignore_args', '1');
    require_once $root . '/tests/native-database.php';
    require_once $root . '/tests/postgres-sync.php';
    require_once $root . '/tests/mqtt-qos1.php';
    require_once $root . '/tests/mqtt-retained.php';
    require_once $root . '/tests/mqtt-sessions.php';
    $tools = NativeDatabase::tools('pgsql', (string) (getenv('TYPE_PGSQL_TOOLS') ?: $root . '/.cache/macos-libpq/17.11'));
    $database = new NativeDatabase($consumer . '/will-primary', 'pgsql', $tools);
    $sync = null;
    $process = null;
    $primary = null;
    $standby = null;
    $gate = null;
    $sockets = [];
    $cases = 0;
    $statistics = [];
    $windows = [];
    try {
        $sync = new PostgresSync($database, $consumer . '/will-standby', $tools);
        $environment = array_replace($environment, $database->environment());
        $environment['MQTT_WORKER_COMMAND'] = json_encode($workerCommand, JSON_THROW_ON_ERROR);
        foreach ([1, 2] as $migration) {
            $installed = (new Process([...$command, '--install-store'], $consumer, $environment))->wait(10);
            expect($installed->successful() && $installed->stderr === '' && json_decode($installed->stdout, true)['state'] === 'committed', '遗嘱安装/重复迁移缺少同步证明');
        }
        $primary = $sync->connection();
        $standby = $sync->standby();
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        fclose($listener);
        $start = function (bool $tls = false, array $overrides = []) use ($command, $consumer, $environment, $port): Process {
            $settings = array_replace($environment, $overrides);
            $settings['MQTT_CERTIFICATE'] = $tls ? $consumer . '/certificate.pem' : '';
            $settings['MQTT_PRIVATE_KEY'] = $tls ? $consumer . '/private.pem' : '';
            $running = new Process([...$command, '--port=' . $port, ...($tls ? [] : ['--plaintext'])], $consumer, $settings);
            mqttUntil(function () use ($running, $port, $tls, $consumer): bool {
                expect($running->running(), '遗嘱 Broker 提前退出：' . $running->stdout() . $running->stderr());
                $probe = null;
                try {
                    $probe = mqttSocket($port, $tls ? $consumer . '/certificate.pem' : null);
                    stream_set_timeout($probe, 1);
                    mqttWrite($probe, mqttConnect(5, 'will-ready-probe'));
                    mqttAck($probe, 5);
                    mqttWrite($probe, "\xe0\0");
                    return true;
                } catch (RuntimeException) {
                    return false;
                } finally {
                    if (is_resource($probe)) {
                        fclose($probe);
                    }
                }
            }, '遗嘱 Broker 未启动', 12.0);
            return $running;
        };
        $restartOnly = in_array('--will-restart-only', $_SERVER['argv'], true);
        $failureOnly = in_array('--will-failure-only', $_SERVER['argv'], true);
        if (!$restartOnly && !$failureOnly) {
            foreach ([false, true] as $tls) {
                $process = $start($tls);
                $certificate = $tls ? $consumer . '/certificate.pem' : null;
                foreach ([4, 5] as $version) {
                    foreach ([0, 1, 2] as $qos) {
                        $id = 'will-binary-' . (int) $tls . '-' . $version . '-' . $qos;
                        $topic = 'example/' . $id;
                        $payload = $qos === 2 ? str_repeat("\0\xff\xfe", 21845) : "\0\xffbinary";
                        $subscriber = mqttWillSubscriber($port, $id . '-s', $topic, $version === 4 ? 5 : 4, 2, $certificate);
                        $publisher = mqttSocket($port, $certificate);
                        array_push($sockets, $subscriber, $publisher);
                        $properties = "\x03" . mqttField('binary/test') . "\x09" . mqttField("\0\xff") . "\x26" . mqttField('x') . mqttField('a') . "\x26" . mqttField('x') . mqttField('b');
                        mqttWrite($publisher, mqttWillConnect($version, $id, $topic, $payload, $qos, true, properties: $version === 5 ? $properties : ''));
                        mqttAck($publisher, $version);
                        expect((int) $standby->query("SELECT COUNT(*) FROM type_mqtt_wills WHERE client_id = '" . $id . "'")->fetchColumn() === 1, 'CONNACK 早于遗嘱同步保存');
                        fclose($publisher);
                        $delivered = mqttRetainedRead($subscriber, $version === 4 ? 5 : 4);
                        expect($delivered['topic'] === $topic && $delivered['payload'] === $payload && $delivered['qos'] === $qos && !$delivered['retain'], '跨版本实时遗嘱内容/QoS/RETAIN 错误');
                        mqttRetainedComplete($subscriber, $delivered);
                        mqttWillSilence($subscriber, 0.1);
                        fclose($subscriber);
                        $late = mqttWillSubscriber($port, $id . '-late', $topic, 5, 2, $certificate);
                        $sockets[] = $late;
                        $retained = mqttRetainedRead($late, 5);
                        expect($retained['payload'] === $payload && $retained['qos'] === $qos && $retained['retain']
                            && $retained['properties'] === ($version === 5 ? $properties : ''), '遗嘱保留重放或属性原件错误');
                        mqttRetainedComplete($late, $retained);
                        fclose($late);
                        $cases += 3;
                    }
                }
                foreach ([4, 5] as $version) {
                    foreach ([0, 1, 2] as $qos) {
                        $id = 'will-shared-' . (int) $tls . '-' . $version . '-' . $qos;
                        $topic = 'example/' . $id;
                        $filter = '$share/wills/' . $topic;
                        $member = mqttWillSubscriber($port, $id . '-member', $filter, 5, 2, $certificate);
                        $ordinary = mqttWillSubscriber($port, $id . '-ordinary', $topic, 5, 2, $certificate);
                        $publisher = mqttSocket($port, $certificate);
                        array_push($sockets, $member, $ordinary, $publisher);
                        $payload = "\0\xffshared-will";
                        mqttWrite($publisher, mqttWillConnect($version, $id, $topic, $payload, $qos));
                        mqttAck($publisher, $version);
                        fclose($publisher);
                        foreach ([$member, $ordinary] as $target) {
                            $delivery = mqttRetainedRead($target, 5);
                            expect(
                                $delivery['topic'] === $topic && $delivery['payload'] === $payload && $delivery['qos'] === $qos,
                                '遗嘱必须独立路由到普通订阅与共享组，保留二进制和QoS'
                            );
                            mqttRetainedComplete($target, $delivery);
                            mqttWillSilence($target, 0.1);
                            mqttWrite($target, "\xe0\0");
                            fclose($target);
                            $cases++;
                        }
                    }
                }
                echo successful(['node', $root . '/tests/mqtt-standard-client.mjs', $consumer, (string) $port, $certificate ?? 'plain', 'wills'], $consumer);
                $stopped = $process->stop(12);
                expect($stopped->successful() && $stopped->stderr === '', '遗嘱 TCP/TLS 停止失败：' . $stopped->stderr);
                $statistics[] = json_decode($stopped->stdout, true);
            }
            $process = $start();
            foreach ([[4, 0], [5, 0], [5, 0x80], [5, 4]] as [$version, $reason]) {
                $id = 'will-disconnect-' . $version . '-' . $reason;
                $topic = 'example/' . $id;
                $subscriber = mqttWillSubscriber($port, $id . '-s', $topic);
                $publisher = mqttSocket($port);
                array_push($sockets, $subscriber, $publisher);
                mqttWrite($publisher, mqttWillConnect($version, $id, $topic, 'disconnect', 1));
                mqttAck($publisher, $version);
                mqttWrite($publisher, $version === 4 ? "\xe0\0" : mqttPacket(0xe0, chr($reason)));
                expect(mqttRead($publisher) === '', '客户端 DISCONNECT 没有结束网络');
                fclose($publisher);
                if ($reason === 4) {
                    $message = mqttRetainedRead($subscriber, 5);
                    expect($message['payload'] === 'disconnect', '主动遗嘱 DISCONNECT 未发布');
                    mqttRetainedComplete($subscriber, $message);
                } else {
                    mqttWillSilence($subscriber, 1.3);
                    expect($standby->query("SELECT outcome FROM type_mqtt_will_audit WHERE client_id = '" . $id . "'")->fetchColumn() === 'cancelled', '正常断开未持久取消遗嘱');
                    expect($standby->query("SELECT cause FROM type_mqtt_will_audit WHERE client_id = '" . $id . "'")->fetchColumn() === 'normal', '正常取消的结束原因不一致');
                }
                fclose($subscriber);
                $cases++;
            }
            foreach ([[2, 10, 2], [5, 1, 1], [5, 0, 0], [5, null, 0], [2, 4294967295, 2]] as [$delay, $expiry, $expected]) {
                $id = 'will-delay-' . $delay . '-' . ($expiry ?? 'default');
                $topic = 'example/' . $id;
                $subscriber = mqttWillSubscriber($port, $id . '-s', $topic);
                $publisher = mqttSocket($port);
                array_push($sockets, $subscriber, $publisher);
                mqttWrite($publisher, mqttWillConnect(5, $id, $topic, 'deadline', 1, false, $delay, $expiry, properties: "\x02" . pack('N', 10)));
                mqttAck($publisher, 5);
                $began = microtime(true);
                fclose($publisher);
                stream_set_timeout($subscriber, 6);
                $message = mqttRetainedRead($subscriber, 5);
                $elapsed = microtime(true) - $began;
                expect($elapsed >= $expected && $elapsed < $expected + 2.5 && $message['payload'] === 'deadline', 'Will Delay 与会话过期没有取先到期者');
                expect(ord($message['properties'][0]) === 2 && unpack('N', substr($message['properties'], 1, 4))[1] >= 9, '遗嘱等待期间错误消耗 Message Expiry 或转发 Will Delay');
                mqttRetainedComplete($subscriber, $message);
                $windows[] = ['delay' => $delay, 'expiry' => $expiry, 'elapsed' => $elapsed];
                fclose($subscriber);
                $cases += 2;
            }
            // 接管与离线重连各覆盖继续同会话、Clean Start 和 Delay=0。
            foreach ([false, true] as $online) {
                foreach ([[false, 3], [true, 3], [false, 0]] as [$clean, $delay]) {
                    $id = 'will-race-' . (int) $online . '-' . (int) $clean . '-' . $delay;
                    $topic = 'example/' . $id;
                    $subscriber = mqttWillSubscriber($port, $id . '-s', $topic);
                    $old = mqttSocket($port);
                    array_push($sockets, $subscriber, $old);
                    mqttWrite($old, mqttWillConnect(5, $id, $topic, 'old', 1, false, $delay, 30, false));
                    mqttAck($old, 5);
                    if (!$online) {
                        fclose($old);
                    }
                    $next = mqttSocket($port);
                    $sockets[] = $next;
                    mqttWrite($next, mqttSessionConnect(5, $id, $clean, 30));
                    mqttSessionAck($next, !$clean);
                    if ($online) {
                        expect(mqttRead($old) === "\xe0\x02\x8e\0", '接管没有关闭旧网络');
                        fclose($old);
                    }
                    if (!$clean && $delay > 0) {
                        mqttWillSilence($subscriber, 3.5);
                    } else {
                        $message = mqttRetainedRead($subscriber, 5);
                        expect($message['payload'] === 'old', '接管或 Clean Start 丢失旧遗嘱');
                        mqttRetainedComplete($subscriber, $message);
                        mqttWillSilence($subscriber, 0.5);
                    }
                    mqttWrite($next, "\xe0\0");
                    expect(mqttRead($next) === '', '新连接正常结束失败');
                    fclose($next);
                    fclose($subscriber);
                    $cases++;
                }
            }
            foreach ([false, true] as $endSession) {
                $id = 'will-request-delay-' . (int) $endSession;
                $topic = 'example/' . $id;
                $subscriber = mqttWillSubscriber($port, $id . '-s', $topic);
                $publisher = mqttSocket($port);
                array_push($sockets, $subscriber, $publisher);
                mqttWrite($publisher, mqttWillConnect(5, $id, $topic, 'requested-delay', 1, false, 2, 30));
                mqttAck($publisher, 5);
                $began = microtime(true);
                mqttWrite($publisher, mqttPacket(0xe0, $endSession ? "\x04\x05\x11\0\0\0\0" : "\x04"));
                expect(mqttRead($publisher) === '', '主动遗嘱未关闭');
                fclose($publisher);
                stream_set_timeout($subscriber, 5);
                $message = mqttRetainedRead($subscriber, 5);
                $elapsed = microtime(true) - $began;
                expect($message['payload'] === 'requested-delay' && ($endSession ? $elapsed < 2 : $elapsed >= 2), '主动遗嘱忽略 Delay 或 DISCONNECT Session Expiry');
                mqttRetainedComplete($subscriber, $message);
                fclose($subscriber);
                $cases++;
            }
            // 到期后才继续同一会话不能取消旧遗嘱，即使调度尚未读到该记录。
            $subscriber = mqttWillSubscriber($port, 'will-late-s', 'example/will-late');
            $publisher = mqttSocket($port);
            array_push($sockets, $subscriber, $publisher);
            mqttWrite($publisher, mqttWillConnect(5, 'will-late', 'example/will-late', 'too-late', 1, false, 1, 30, false));
            mqttAck($publisher, 5);
            fclose($publisher);
            usleep(1300000);
            $next = mqttSocket($port);
            $sockets[] = $next;
            mqttWrite($next, mqttSessionConnect(5, 'will-late', false, 30));
            mqttSessionAck($next, true);
            $message = mqttRetainedRead($subscriber, 5);
            expect($message['payload'] === 'too-late', '到期重连错误取消遗嘱');
            mqttRetainedComplete($subscriber, $message);
            mqttWillSilence($subscriber, 1.2);
            fclose($next);
            fclose($subscriber);
            $cases++;
            // 合法性与 CONNECT 授权拒绝不会留下遗嘱；失败报文通过真实监听输入。
            foreach ([
                mqttWillConnect(5, 'bad-will-topic', 'example/+', 'x'),
                mqttWillConnect(4, 'bad-will-empty', '', 'x'),
                mqttWillConnect(5, 'bad-will-qos', 'example/bad', 'x', 3),
                mqttWillConnect(5, 'bad-will-property', 'example/bad', 'x', properties: "\x18\0\0\0\x01\x18\0\0\0\x01"),
                mqttWillConnect(5, 'bad-will-alias', 'example/bad', 'x', properties: "\x23\0\x01"),
                mqttWillConnect(5, 'bad-will-utf8', 'example/bad', "\xff", properties: "\x01\x01"),
                mqttWillConnect(5, 'bad-will-response', 'example/bad', 'x', properties: "\x08" . mqttField('example/#')),
                mqttWillConnect(5, 'bad-will-access', 'example/read-only/will', 'x'),
                mqttWillConnect(4, 'bad-will-access4', 'example/read-only/will', 'x'),
            ] as $invalid) {
                $bad = mqttSocket($port);
                $sockets[] = $bad;
                mqttWrite($bad, $invalid);
                $reply = mqttRead($bad);
                expect($reply === '' || (ord($reply[0]) === 0x20 && ord($reply[3]) !== 0), '非法遗嘱 CONNECT 得到成功响应');
                fclose($bad);
                $cases++;
            }
            expect((int) $standby->query("SELECT COUNT(*) FROM type_mqtt_wills WHERE client_id LIKE 'bad-will-%'")->fetchColumn() === 0, '非法配置遗留可发布遗嘱');
            // Keep Alive 和协议错误都是 Broker 可观察到的网络结束原因。
            foreach ([true, false] as $timeout) {
                $id = $timeout ? 'will-keepalive' : 'will-protocol';
                $topic = 'example/' . $id;
                $subscriber = mqttWillSubscriber($port, $id . '-s', $topic);
                $publisher = mqttSocket($port);
                array_push($sockets, $subscriber, $publisher);
                mqttWrite($publisher, mqttWillConnect(5, $id, $topic, 'lost', 1, keepalive: $timeout ? 1 : 0));
                mqttAck($publisher, 5);
                $began = microtime(true);
                if (!$timeout) {
                    mqttWrite($publisher, "\xc1\0");
                }
                // 非法PINGREQ保留位属于Malformed Packet，沿用固定头条款回归的0x81分类。
                $ended = mqttRead($publisher);
                expect($ended === ($timeout ? "\xe0\x02\x8d\0" : "\xe0\x02\x81\0"), '异常结束未返回标准原因：' . $id . ' ' . bin2hex($ended));
                stream_set_timeout($subscriber, 5);
                $message = mqttRetainedRead($subscriber, 5);
                expect($message['payload'] === 'lost' && (!$timeout || microtime(true) - $began >= 1.5), '异常结束遗嘱错误或 Keep Alive 提前');
                mqttRetainedComplete($subscriber, $message);
                fclose($publisher);
                fclose($subscriber);
                $cases++;
            }
        }
        if ($restartOnly || $failureOnly) {
            $process = $start();
        }
        // 已安排期限重启不刷新；崩溃时仍在线的遗嘱从重启发现失联计时。
        foreach ($failureOnly ? [] : ['scheduled', 'scheduled-cancel', 'crashed', 'shutdown'] as $mode) {
            echo '验证遗嘱重启：' . $mode . "\n";
            $id = 'will-restart-' . $mode;
            $topic = 'example/' . $id;
            $subscriber = mqttSocket($port);
            $publisher = mqttSocket($port);
            array_push($sockets, $subscriber, $publisher);
            mqttWrite($subscriber, mqttSessionConnect(5, $id . '-s', false, 60));
            mqttSessionAck($subscriber, false);
            mqttWrite($subscriber, mqttSubscription(5, $topic, 1, 2));
            expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\x02"), '重启遗嘱订阅失败');
            mqttWrite($publisher, mqttWillConnect(5, $id, $topic, "restart\0\xff", 2, true, $mode === 'scheduled-cancel' ? 5 : 3, 30));
            mqttAck($publisher, 5);
            $before = null;
            if (str_starts_with($mode, 'scheduled')) {
                fclose($publisher);
                $before = mqttUntil(fn (): mixed => $standby->query("SELECT due_at::text FROM type_mqtt_wills WHERE client_id = '" . $id . "'")->fetchColumn(), '延迟遗嘱没有持久截止');
            }
            if ($mode === 'crashed') {
                expect(posix_kill($process->pid(), SIGKILL), '无法硬停止隔离 Broker');
                $process->wait(5);
            } else {
                $stopped = $process->stop(12);
                expect($stopped->successful() && $stopped->stderr === '', '重启前停止失败');
                $statistics[] = json_decode($stopped->stdout, true);
            }
            fclose($subscriber);
            if (is_resource($publisher)) {
                fclose($publisher);
            }
            $process = $start();
            $subscriber = mqttSocket($port);
            $sockets[] = $subscriber;
            mqttWrite($subscriber, mqttSessionConnect(5, $id . '-s', false, 60));
            mqttSessionAck($subscriber, true);
            if ($before !== null) {
                expect($standby->query("SELECT due_at::text FROM type_mqtt_wills WHERE client_id = '" . $id . "'")->fetchColumn() === $before, '重启刷新了既有遗嘱截止');
            }
            if ($mode === 'scheduled-cancel') {
                $next = mqttSocket($port);
                $sockets[] = $next;
                mqttWrite($next, mqttSessionConnect(5, $id, false, 30));
                mqttSessionAck($next, true);
                mqttWillSilence($subscriber, 5.5);
                expect($standby->query("SELECT outcome FROM type_mqtt_will_audit WHERE client_id = '" . $id . "'")->fetchColumn() === 'cancelled', '重启后继续会话未取消遗嘱');
                fclose($next);
                fclose($subscriber);
                $cases += 3;
                continue;
            }
            stream_set_timeout($subscriber, 6);
            $message = mqttRetainedRead($subscriber, 5);
            expect($message['payload'] === "restart\0\xff" && $message['qos'] === 2, '重启后遗嘱原件丢失');
            mqttRetainedComplete($subscriber, $message);
            mqttWillSilence($subscriber, 1.2);
            expect((int) $standby->query("SELECT COUNT(*) FROM type_mqtt_messages WHERE topic = '" . $topic . "'")->fetchColumn() === 1, '重启重复发布遗嘱');
            fclose($subscriber);
            $cases += 3;
        }
        if ($restartOnly) {
            $stopped = $process->stop(12);
            expect($stopped->successful() && $stopped->stderr === '', '遗嘱重启专项停止失败');
            return ['cases' => $cases, 'scope' => 'restart-only', 'statistics' => json_decode($stopped->stdout, true)];
        }
        // 当前授权变化在发布时重新检查。只在受控旧状态夹具中改变 Topic，观察网络拒绝与持久审计。
        $subscriber = mqttWillSubscriber($port, 'will-denied-s', 'example/read-only/revoked');
        $publisher = mqttSocket($port);
        array_push($sockets, $subscriber, $publisher);
        mqttWrite($publisher, mqttWillConnect(5, 'will-denied', 'example/allowed-before', 'revoked', 1, false, 2, 30));
        mqttAck($publisher, 5);
        $primary->exec("UPDATE type_mqtt_wills SET message = jsonb_set(message, '{topic}', '\"example/read-only/revoked\"') WHERE client_id = 'will-denied'");
        fclose($publisher);
        mqttUntil(fn (): bool => $standby->query("SELECT outcome FROM type_mqtt_will_audit WHERE client_id = 'will-denied'")->fetchColumn() === 'denied', '到期遗嘱未重新授权并记录失败');
        mqttWillSilence($subscriber, 0.5);
        fclose($subscriber);
        $cases++;
        // CONNECT 在 SyncRep 中被取消时，不能把已落库而未接纳的遗嘱留到重启误发。
        foreach ([true, false] as $clean) {
            $id = 'will-open-unknown-' . (int) $clean;
            $topic = 'example/' . $id;
            $previous = mqttSocket($port);
            $sockets[] = $previous;
            mqttWrite($previous, mqttSessionConnect(5, $id, false, 60));
            mqttSessionAck($previous, false);
            mqttWrite($previous, "\xe0\0");
            expect(mqttRead($previous) === '', '未知打开前原会话未离线');
            fclose($previous);
            mqttUntil(fn (): bool => $standby->query("SELECT owner_id FROM type_mqtt_sessions WHERE client_id = '" . $id . "'")->fetchColumn() === null, '原会话关闭尚未同步');
            $subscriber = mqttWillSubscriber($port, $id . '-s', $topic);
            $publisher = mqttSocket($port);
            array_push($sockets, $subscriber, $publisher);
            mqttDatabaseIdle($primary);
            $gate = $sync->connection();
            $gate->beginTransaction();
            $gate->query("SELECT id FROM type_mqtt_sessions WHERE client_id = '" . $id . "' FOR UPDATE")->fetchColumn();
            mqttWrite($publisher, mqttWillConnect(5, $id, $topic, 'never-accepted', 1, false, 0, 60, $clean));
            $backend = mqttUntil(fn (): mixed => $primary->query("SELECT pid FROM pg_stat_activity WHERE application_name LIKE 'type_mqtt_%'"
                . " AND wait_event_type = 'Lock' AND query LIKE 'SELECT *, (expires_at IS NOT NULL%'")->fetchColumn(), '未知打开未到达指定会话');
            $standby->query('SELECT pg_wal_replay_pause()')->fetchColumn();
            $gate->rollBack();
            $gate = null;
            mqttUntil(fn (): mixed => $primary->query('SELECT pid FROM pg_stat_activity WHERE pid = ' . (int) $backend . " AND wait_event = 'SyncRep'")->fetchColumn(), '打开遗嘱未进入同步等待');
            $primary->query('SELECT pg_cancel_backend(' . (int) $backend . ')')->fetchColumn();
            stream_set_timeout($publisher, 10);
            expect(mqttRead($publisher) === "\x20\x03\0\x88\0", '未知保存被误认为已接纳 CONNECT');
            fclose($publisher);
            mqttWillSilence($subscriber, 0.3);
            $standby->query('SELECT pg_wal_replay_resume()')->fetchColumn();
            mqttUntil(fn (): bool => $standby->query("SELECT outcome FROM type_mqtt_will_audit WHERE client_id = '" . $id . "'")->fetchColumn() === 'cancelled', '未接纳的遗嘱未持久取消');
            mqttUntil(fn (): bool => $standby->query("SELECT owner_id FROM type_mqtt_sessions WHERE client_id = '" . $id . "'")->fetchColumn() === null, '未知打开留下在线所有者');
            mqttWillSilence($subscriber, 1.3);
            fclose($subscriber);
            $cases += 3;
        }
        // 暂停真实备库回放，使遗嘱接管达到本地可见但尚未同步证明；未知结果不能再次发布。
        $faults = [];
        foreach ([true, false] as $cancel) {
            echo '验证遗嘱同步故障：' . ($cancel ? 'cancel' : 'deadline') . "\n";
            $id = $cancel ? 'will-syncrep-cancel' : 'will-worker-deadline';
            $topic = 'example/' . $id;
            $subscriber = mqttSocket($port);
            $publisher = mqttSocket($port);
            array_push($sockets, $subscriber, $publisher);
            mqttWrite($subscriber, mqttSessionConnect(5, $id . '-s', false, 60));
            mqttSessionAck($subscriber, false);
            mqttWrite($subscriber, mqttSubscription(5, $topic, 1, 2));
            expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\x02"), '故障遗嘱观察订阅失败');
            mqttWrite($subscriber, "\xe0\0");
            expect(mqttRead($subscriber) === '', '故障持久订阅未离线');
            fclose($subscriber);
            mqttUntil(fn (): bool => $standby->query("SELECT owner_id FROM type_mqtt_sessions WHERE client_id = '" . $id . "-s'")->fetchColumn() === null, '故障持久订阅未保存');
            $subscriber = mqttWillSubscriber($port, $id . '-live', $topic);
            $sockets[] = $subscriber;
            mqttWrite($publisher, mqttWillConnect(5, $id, $topic, 'durable-once', 2, true, 3, 30));
            mqttAck($publisher, 5);
            fclose($publisher);
            mqttUntil(fn (): mixed => $standby->query("SELECT due_at FROM type_mqtt_wills WHERE client_id = '" . $id . "'")->fetchColumn(), '故障遗嘱未安排');
            mqttDatabaseIdle($primary);
            // 先让读取原件取得同步证明，再锁住接管中的保留写入；会话过期清理也会写消息表，因此不锁消息表。
            $gate = $sync->connection();
            $gate->beginTransaction();
            $gate->exec('LOCK TABLE type_mqtt_retained IN SHARE MODE');
            $backend = mqttUntil(fn (): mixed => $primary->query("SELECT pid FROM pg_stat_activity WHERE application_name LIKE 'type_mqtt_%'"
                . " AND wait_event = 'relation' AND query LIKE 'DELETE FROM type_mqtt_retained WHERE topic IN%'")->fetchColumn(), '遗嘱写入未到达受控数据库锁');
            $standby->query('SELECT pg_wal_replay_pause()')->fetchColumn();
            $gate->rollBack();
            $gate = null;
            mqttUntil(fn (): mixed => $primary->query('SELECT pid FROM pg_stat_activity WHERE pid = ' . (int) $backend . " AND wait_event = 'SyncRep'")->fetchColumn(), '遗嘱接管未进入真实同步等待');
            expect((int) $standby->query("SELECT COUNT(*) FROM type_mqtt_messages WHERE topic = '" . $topic . "'")->fetchColumn() === 0, '同步暂停失效');
            if ($cancel) {
                $primary->query('SELECT pg_cancel_backend(' . (int) $backend . ')')->fetchColumn();
            }
            stream_set_timeout($subscriber, 12);
            $began = microtime(true);
            expect(mqttRead($subscriber) === "\xe0\x02\x83\0", '未知提交提前输出了遗嘱');
            $elapsed = microtime(true) - $began;
            expect($elapsed < 11.0, '遗嘱 worker 没有硬截止');
            fclose($subscriber);
            mqttUntil(fn (): bool => (int) $primary->query('SELECT COUNT(*) FROM pg_stat_activity WHERE pid = ' . (int) $backend)->fetchColumn() === 0, '遗嘱故障 worker 遗留后端');
            expect((int) $primary->query("SELECT COUNT(*) FROM type_mqtt_messages WHERE topic = '" . $topic . "'")->fetchColumn() === 1, '未知遗嘱提交丢失或重复接管');
            $standby->query('SELECT pg_wal_replay_resume()')->fetchColumn();
            mqttUntil(fn (): bool => (int) $standby->query("SELECT COUNT(*) FROM type_mqtt_messages WHERE topic = '" . $topic . "'")->fetchColumn() === 1, '遗嘱未知事实恢复后丢失');
            $subscriber = mqttSocket($port);
            $sockets[] = $subscriber;
            mqttWrite($subscriber, mqttSessionConnect(5, $id . '-s', false, 60));
            mqttSessionAck($subscriber, true);
            $message = mqttRetainedRead($subscriber, 5);
            expect($message['payload'] === 'durable-once' && $message['qos'] === 2 && !$message['duplicate'], '遗嘱未知提交未按离线首次交付恢复');
            mqttRetainedComplete($subscriber, $message);
            mqttWillSilence($subscriber, 1.3);
            expect((int) $standby->query("SELECT COUNT(*) FROM type_mqtt_messages WHERE topic = '" . $topic . "'")->fetchColumn() === 1, '故障重试生成重复遗嘱原件');
            fclose($subscriber);
            $faults[] = ['cancelled' => $cancel, 'seconds' => $elapsed, 'backend_released' => true, 'message_count' => 1];
            $cases += 4;
        }
        $subscriber = mqttWillSubscriber($port, 'will-close-retry-s', 'example/will-close-retry');
        $sockets[] = $subscriber;
        $publishers = [];
        foreach (['normal', 'lost'] as $mode) {
            $publisher = mqttSocket($port);
            $sockets[] = $publisher;
            mqttWrite($publisher, mqttWillConnect(5, 'will-close-retry-' . $mode, 'example/will-close-retry', $mode, 1, false, 1, 30));
            mqttAck($publisher, 5);
            $publishers[$mode] = $publisher;
        }
        $standby = null;
        $sync->stopStandby();
        mqttWrite($publishers['normal'], "\xe0\0");
        expect(mqttRead($publishers['normal']) === '', '存储故障期间正常 DISCONNECT 未结束网络');
        fclose($publishers['normal']);
        fclose($publishers['lost']);
        mqttWillSilence($subscriber, 1.5);
        $sync->restartStandby();
        $standby = $sync->standby();
        stream_set_timeout($subscriber, 6);
        $message = mqttRetainedRead($subscriber, 5);
        expect($message['payload'] === 'lost', '关闭重试丢失遗嘱或把正常断开误发为遗嘱');
        mqttRetainedComplete($subscriber, $message);
        mqttUntil(fn (): bool => $standby->query("SELECT outcome FROM type_mqtt_will_audit WHERE client_id = 'will-close-retry-normal'")->fetchColumn() === 'cancelled', '关闭重试丢失正常结束原因');
        mqttWillSilence($subscriber, 1.5);
        fclose($subscriber);
        $cases += 3;
        // 保留配额失败不会堵住调度或删除待发原件；释放实际配额后恢复同一遗嘱。
        $stopped = $process->stop(12);
        expect($stopped->successful() && $stopped->stderr === '', '低配额前停止失败');
        $statistics[] = json_decode($stopped->stdout, true);
        $primary->exec('DELETE FROM type_mqtt_retained');
        $process = $start(false, ['MQTT_RETAINED_MAX_MESSAGES' => '1']);
        $publisher = mqttSocket($port);
        $sockets[] = $publisher;
        mqttWrite($publisher, mqttConnect(5, 'will-quota-holder', 0));
        mqttAck($publisher, 5);
        mqttRetainedPublish($publisher, 5, 'example/will-quota-full', 'occupied', 1);
        $subscriber = mqttWillSubscriber($port, 'will-quota-s', 'example/will-quota');
        $sockets[] = $subscriber;
        $willPublisher = mqttSocket($port);
        $sockets[] = $willPublisher;
        mqttWrite($willPublisher, mqttWillConnect(5, 'will-quota', 'example/will-quota', 'after-release', 1, true));
        mqttAck($willPublisher, 5);
        fclose($willPublisher);
        mqttUntil(fn (): bool => (int) $standby->query("SELECT last_reason FROM type_mqtt_wills WHERE client_id = 'will-quota'")->fetchColumn() === 0x97, '遗嘱配额失败不可观察');
        mqttWillSilence($subscriber, 0.5);
        mqttRetainedPublish($publisher, 5, 'example/will-quota-full', '', 1);
        stream_set_timeout($subscriber, 6);
        $message = mqttRetainedRead($subscriber, 5);
        expect($message['payload'] === 'after-release', '配额释放后待发遗嘱未恢复');
        mqttRetainedComplete($subscriber, $message);
        mqttWillSilence($subscriber, 1.2);
        expect((int) $standby->query("SELECT COUNT(*) FROM type_mqtt_wills WHERE client_id = 'will-quota'")->fetchColumn() === 0, '遗嘱终结未释放载荷');
        fclose($subscriber);
        fclose($publisher);
        $cases += 3;
        $subscriber = mqttWillSubscriber($port, 'will-empty-s', 'example/will-quota');
        $sockets[] = $subscriber;
        $retained = mqttRetainedRead($subscriber, 5);
        mqttRetainedComplete($subscriber, $retained);
        $publisher = mqttSocket($port);
        $sockets[] = $publisher;
        mqttWrite($publisher, mqttWillConnect(4, 'will-empty', 'example/will-quota', '', 1, true));
        mqttAck($publisher, 4);
        fclose($publisher);
        $empty = mqttRetainedRead($subscriber, 5);
        expect($empty['payload'] === '' && !$empty['retain'], '空遗嘱没有正常实时交付');
        mqttRetainedComplete($subscriber, $empty);
        expect((int) $standby->query("SELECT COUNT(*) FROM type_mqtt_retained WHERE topic = 'example/will-quota'")->fetchColumn() === 0, '空遗嘱未清理保留事实');
        fclose($subscriber);
        $small = mqttWillSubscriber($port, 'will-small-s', 'example/will-small', properties: "\x27" . pack('N', 128) . "\x22\0\x01");
        $sockets[] = $small;
        foreach ([256, 3] as $bytes) {
            $publisher = mqttSocket($port);
            $sockets[] = $publisher;
            mqttWrite($publisher, mqttWillConnect(5, 'will-small-' . $bytes, 'example/will-small', str_repeat('x', $bytes)));
            mqttAck($publisher, 5);
            fclose($publisher);
            if ($bytes === 256) {
                mqttUntil(fn (): bool => $standby->query("SELECT outcome FROM type_mqtt_will_audit WHERE client_id = 'will-small-256'")->fetchColumn() === 'published', '超限遗嘱未完成接管');
                mqttWillSilence($small, 0.3);
            } else {
                $message = mqttRetainedRead($small, 5);
                expect($message['payload'] === 'xxx' && $message['properties'] === "\x23\0\x01", '大小丢弃破坏后续连接或出站别名');
            }
        }
        fclose($small);
        $cases += 3;
        $result = $process->stop(12);
        expect($result->successful() && $result->stderr === '', '遗嘱资源清理失败：' . $result->stderr);
        $statistics[] = json_decode($result->stdout, true);
        foreach ($statistics as $counts) {
            foreach (['connections', 'subscriptions', 'bufferedBytes', 'pendingCommits', 'closingSessions', 'retainedQueued', 'retainedPending', 'retainedBytes', 'willPending', 'quarantinedCommits'] as $field) {
                expect((int) $counts[$field] === 0, '遗嘱退出未释放：' . $field);
            }
        }
        return ['cases' => $cases, 'windows' => $windows, 'faults' => $faults, 'replication' => $sync->evidence(), 'statistics' => $statistics];
    } catch (Throwable $failure) {
        if ($gate?->inTransaction()) {
            $gate->rollBack();
        }
        $gate = null;
        $diagnostic = $process?->stop(12);
        file_put_contents($consumer . '/will-failure.json', json_encode(['error' => $failure->getMessage(),
            'cases' => $cases, 'mode' => $mode ?? '', 'stderr' => $diagnostic?->stderr, 'stdout' => $diagnostic?->stdout], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        throw $failure;
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
