<?php

declare(strict_types=1);

/** 固定仅支持 QoS 1 的表形态，真实迁移必须保留此前接管的二进制事实。 */
function mqttQos2Migration(PDO $primary): array
{
    $primary->exec('CREATE TABLE type_mqtt_messages (id CHAR(32) PRIMARY KEY, publisher_session CHAR(32) NOT NULL, '
        . 'client_id TEXT NOT NULL, packet_id INTEGER NOT NULL CHECK (packet_id BETWEEN 1 AND 65535), topic TEXT NOT NULL, '
        . 'payload BYTEA NOT NULL, properties BYTEA NOT NULL, qos INTEGER NOT NULL CHECK (qos = 1), byte_size INTEGER NOT NULL CHECK (byte_size > 0), '
        . "state VARCHAR(16) NOT NULL CHECK (state IN ('accepted', 'complete')), created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp())");
    $primary->exec('CREATE TABLE type_mqtt_deliveries (id CHAR(64) PRIMARY KEY, message_id CHAR(32) NOT NULL REFERENCES type_mqtt_messages (id), '
        . 'session_id CHAR(32) NOT NULL, client_id TEXT NOT NULL, packet_id INTEGER NOT NULL CHECK (packet_id BETWEEN 0 AND 65535), '
        . 'qos INTEGER NOT NULL CHECK (qos IN (0, 1)), '
        . "state VARCHAR(16) NOT NULL CHECK (state IN ('pending', 'acknowledged', 'queued', 'expired', 'closed')), ack_reason INTEGER NULL, "
        . 'created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(), UNIQUE (message_id, session_id))');
    $statement = $primary->prepare("INSERT INTO type_mqtt_messages VALUES (?, ?, 'legacy', 1, 'example/legacy', decode('00ff', 'hex'), ''::bytea, 1, 16, 'complete', clock_timestamp())");
    $statement->execute([str_repeat('a', 32), str_repeat('b', 32)]);
    return ['baseline' => 'tables with qos=1 checks', 'preserved_topic' => 'example/legacy', 'payload_hex' => '00ff'];
}

/** 测试线编码不调用组件消息与确认实现。 */
function mqttQos2Publish(int $version, string $topic, string $payload, int $identifier, bool $duplicate = false, string $properties = ''): string
{
    return mqttPacket($duplicate ? 0x3c : 0x34, mqttField($topic) . pack('n', $identifier)
        . ($version === 5 ? mqttLength(strlen($properties)) . $properties : '') . $payload);
}

/** 生成 QoS 2 确认链中的指定报文；reason 为 null 时省略原因码字节。 */
function mqttQos2Ack(int $header, int $identifier, ?int $reason = null): string
{
    return mqttPacket($header, pack('n', $identifier) . ($reason === null ? '' : chr($reason)));
}

/** 真实 socket 观察双向四步握手，独立同步备库核验事实，故障只作用于本轮数据库。 */
function mqttQos2Cases(int $port, PDO $primary, PDO $standby): array
{
    $sockets = [];
    $cases = 0;
    $failures = [];
    try {
        expect($standby->query("SELECT encode(payload, 'hex') FROM type_mqtt_messages WHERE topic = 'example/legacy'")->fetchColumn() === '00ff', '旧 QoS 1 原件未在迁移后保留');
        $cases++;
        $publisher = mqttSocket($port);
        $subscriber = mqttSocket($port);
        array_push($sockets, $publisher, $subscriber);
        mqttWrite($publisher, mqttConnect(5, 'alias-durable-p', 10, "\x22\0\0"));
        mqttWrite($subscriber, mqttConnect(5, 'alias-durable-s', 10, "\x22\0\x01"));
        mqttAck($publisher, 5);
        mqttAck($subscriber, 5);
        mqttWrite($subscriber, mqttSubscription(5, 'example/alias-durable', 1, 2));
        expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\x02"), 'QoS 2 别名订阅失败');
        $property = "\x09" . mqttField("\0\xff");
        foreach ([1, 2] as $exchange) {
            $name = $exchange === 1 ? 'example/alias-durable' : '';
            mqttWrite($publisher, mqttQos2Publish(5, $name, 'alias-' . $exchange, $exchange, false, "\x23\0\x20" . $property));
            expect(mqttRead($publisher) === mqttQos2Ack(0x50, $exchange), 'QoS 2 别名接管失败');
            expect(mqttRead($subscriber) === mqttQos2Publish(5, $name, 'alias-' . $exchange, $exchange, false, $property . "\x23\0\x01"), 'QoS 2 出站别名没有独立建立或复用');
            mqttWrite($subscriber, mqttQos2Ack(0x50, $exchange));
            expect(mqttRead($subscriber) === mqttQos2Ack(0x62, $exchange), 'QoS 2 别名交换未推进到 PUBREL');
            mqttWrite($subscriber, mqttQos2Ack(0x70, $exchange));
            mqttWrite($publisher, mqttQos2Ack(0x62, $exchange));
            expect(mqttRead($publisher) === mqttQos2Ack(0x70, $exchange), 'QoS 2 别名交换未完成');
            mqttDatabaseIdle($primary);
            $cases++;
        }
        $facts = $standby->query("SELECT topic, encode(properties, 'hex') AS properties FROM type_mqtt_messages WHERE client_id = 'alias-durable-p'")->fetchAll(PDO::FETCH_ASSOC);
        expect(count($facts) === 2, '别名发布未持久接管两次');
        foreach ($facts as $fact) {
            expect($fact['topic'] === 'example/alias-durable' && $fact['properties'] === bin2hex($property), '持久原件含连接别名或缺少真实 Topic');
        }
        $expandedTopic = 'example/' . str_repeat('t', 1024);
        mqttWrite($publisher, mqttQos2Publish(5, $expandedTopic, 'register', 3, false, "\x23\0\x20"));
        expect(mqttRead($publisher) === mqttQos2Ack(0x50, 3), '持久大 Topic 别名没有建立');
        mqttWrite($publisher, mqttQos2Ack(0x62, 3));
        expect(mqttRead($publisher) === mqttQos2Ack(0x70, 3), '持久大 Topic 注册交换未结束');
        $expandedWire = mqttQos2Publish(5, '', str_repeat('b', 1048564), 4, false, "\x23\0\x20");
        expect(strlen($expandedWire) === 1048576, '可靠别名最大完整报文夹具错误');
        mqttWrite($publisher, $expandedWire);
        expect(mqttRead($publisher) === mqttQos2Ack(0x50, 4), '持久层用展开长度误拒绝合法1MiB报文');
        mqttWrite($publisher, mqttQos2Ack(0x62, 4));
        expect(mqttRead($publisher) === mqttQos2Ack(0x70, 4), '可靠大别名交换未终结');
        expect((int) $standby->query("SELECT MAX(octet_length(payload)) FROM type_mqtt_messages WHERE client_id = 'alias-durable-p'")->fetchColumn() === 1048564, '大别名载荷未同步保存');
        $cases++;
        fclose($publisher);
        fclose($subscriber);
        $cases++;
        foreach ([4, 5] as $version) {
            $publisher = mqttSocket($port);
            $subscriber = mqttSocket($port);
            array_push($sockets, $publisher, $subscriber);
            mqttWrite($publisher, mqttConnect($version, 'qos2-publisher-' . $version));
            mqttWrite($subscriber, mqttConnect($version, 'qos2-subscriber-' . $version));
            mqttAck($publisher, $version);
            mqttAck($subscriber, $version);
            $topic = 'example/qos2/' . $version;
            mqttWrite($subscriber, mqttSubscription($version, $topic, 1, 2));
            expect(mqttRead($subscriber) === mqttSubscriptionAck($version, "\x02"), '未授予 QoS 2');
            $payload = "\0\xff" . str_repeat('binary', 100);
            mqttWrite($publisher, mqttQos2Publish($version, $topic, $payload, 17));
            expect(mqttRead($publisher) === mqttQos2Ack(0x50, 17), '接管后缺少 PUBREC');
            expect(mqttRead($subscriber) === mqttQos2Publish($version, $topic, $payload, 1), '出站 QoS 2 内容或标识错误');
            expect($standby->query("SELECT inbound_state FROM type_mqtt_messages WHERE topic = '" . $topic . "'")->fetchColumn() === 'received', 'PUBREC 早于同步接管');
            mqttWrite($publisher, mqttQos2Publish($version, $topic, 'duplicate-body-not-new-message', 17, true));
            expect(mqttRead($publisher) === mqttQos2Ack(0x50, 17), '重复 PUBLISH 未返回 PUBREC');
            mqttQuiet($subscriber);
            mqttWrite($subscriber, mqttQos2Ack(0x50, 1));
            expect(mqttRead($subscriber) === mqttQos2Ack(0x62, 1), '成功 PUBREC 后没有 PUBREL');
            expect($standby->query("SELECT phase FROM type_mqtt_deliveries WHERE client_id = 'qos2-subscriber-" . $version . "'")->fetchColumn() === 'wait_pubcomp', 'PUBREL 早于同步阶段推进');
            mqttWrite($subscriber, mqttQos2Ack(0x50, 1));
            expect(mqttRead($subscriber) === mqttQos2Ack(0x62, 1), '重复 PUBREC 未重复 PUBREL');
            mqttQuiet($subscriber);
            mqttWrite($publisher, mqttQos2Ack(0x62, 17));
            expect(mqttRead($publisher) === mqttQos2Ack(0x70, 17), '缺少 PUBCOMP');
            expect($standby->query("SELECT inbound_state FROM type_mqtt_messages WHERE topic = '" . $topic . "'")->fetchColumn() === 'released', 'PUBCOMP 早于同步终结');
            mqttWrite($publisher, mqttQos2Ack(0x62, 17));
            expect(mqttRead($publisher) === mqttQos2Ack(0x70, 17, $version === 5 ? 0x92 : null), '已清理交换的重复 PUBREL 行为错误');
            mqttWrite($subscriber, mqttQos2Ack(0x70, 1));
            mqttUntil(fn (): bool => $standby->query("SELECT state FROM type_mqtt_messages WHERE topic = '" . $topic . "'")->fetchColumn() === 'complete', 'QoS 2 终结未持久化');
            mqttWrite($subscriber, mqttQos2Ack(0x70, 1));
            mqttQuiet($subscriber);
            mqttWrite($publisher, mqttQos2Publish($version, $topic, 'new-exchange', 17, true));
            expect(mqttRead($publisher) === mqttQos2Ack(0x50, 17), '完成后相同标识不能复用');
            expect(mqttRead($subscriber) === mqttQos2Publish($version, $topic, 'new-exchange', 2), '旧标识被永久去重');
            mqttWrite($publisher, mqttQos2Ack(0x62, 17));
            expect(mqttRead($publisher) === mqttQos2Ack(0x70, 17), '新交换未完成');
            mqttWrite($subscriber, mqttQos2Ack(0x50, 2, $version === 5 ? 0x87 : null));
            if ($version === 5) {
                mqttUntil(fn (): bool => (int) $standby->query("SELECT COUNT(*) FROM type_mqtt_deliveries WHERE client_id = 'qos2-subscriber-5' AND state = 'rejected' AND ack_reason = 135")->fetchColumn() === 1, '负 PUBREC 未持久终结');
                mqttQuiet($subscriber);
            } else {
                expect(mqttRead($subscriber) === mqttQos2Ack(0x62, 2), '第二份 PUBREL 缺失');
                mqttWrite($subscriber, mqttQos2Ack(0x70, 2));
            }
            mqttDatabaseIdle($primary);
            expect((int) $standby->query("SELECT COUNT(*) FROM type_mqtt_messages WHERE topic = '" . $topic . "'")->fetchColumn() === 2, '重复握手产生多次持久交付');
            $cases += 12;
            fclose($publisher);
            fclose($subscriber);
        }
        foreach ([4, 5] as $version) {
            $packets = [mqttQos2Ack(0x50, 0), mqttQos2Ack(0x62, 0), mqttQos2Ack(0x70, 0),
                mqttQos2Ack(0x60, 1), mqttQos2Ack(0x6a, 1), mqttQos2Ack(0x50, 1, 0x7f),
                mqttPacket(0x62, "\0\x01\0\x03\x21\0\x01"), mqttPacket(0x70, "\0\x01\0\x02\x1f\0")];
            foreach ($packets as $index => $packet) {
                $invalid = mqttSocket($port);
                $sockets[] = $invalid;
                mqttWrite($invalid, mqttConnect($version, 'qos2-invalid-' . $version . '-' . $index));
                mqttAck($invalid, $version);
                mqttWrite($invalid, $packet);
                $reply = mqttRead($invalid);
                expect($version === 4 ? $reply === '' : str_starts_with($reply, "\xe0\x02"), '非法 QoS 2 控制报文未拒绝');
                fclose($invalid);
                $cases++;
            }
        }
        // 接收额度覆盖 QoS 2 到 PUBCOMP 的整个交换，PUBREC 不能提前归还发送窗口。
        $publisher = mqttSocket($port);
        $subscriber = mqttSocket($port);
        array_push($sockets, $publisher, $subscriber);
        mqttWrite($publisher, mqttConnect(5, 'qos2-window-p'));
        mqttWrite($subscriber, mqttConnect(5, 'qos2-window-s', 10, "\x21\0\x01"));
        mqttAck($publisher, 5);
        mqttAck($subscriber, 5);
        mqttWrite($subscriber, mqttSubscription(5, 'example/qos2-window', 1, 2));
        expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\x02"), 'QoS 2 窗口订阅失败');
        mqttWrite($publisher, mqttQos2Publish(5, 'example/qos2-window', 'one', 1));
        expect(mqttRead($publisher) === mqttQos2Ack(0x50, 1), 'QoS 2 窗口首条接管失败');
        expect(mqttRead($subscriber) === mqttQos2Publish(5, 'example/qos2-window', 'one', 1), 'QoS 2 窗口首条交付失败');
        mqttWrite($subscriber, mqttQos2Ack(0x50, 1));
        expect(mqttRead($subscriber) === mqttQos2Ack(0x62, 1), 'QoS 2 窗口 PUBREL 缺失');
        mqttWrite($publisher, mqttQos2Publish(5, 'example/qos2-window', 'two', 2));
        expect(mqttRead($publisher) === "\xe0\x02\x97\0", 'PUBREC 提前归还 QoS 2 窗口');
        mqttQuiet($subscriber);
        mqttWrite($subscriber, mqttQos2Ack(0x70, 1, 0x92));
        mqttUntil(fn (): bool => (int) $standby->query("SELECT COUNT(*) FROM type_mqtt_deliveries WHERE client_id = 'qos2-window-s' AND state = 'not_found'")->fetchColumn() === 1, 'PUBCOMP 0x92 被记作成功送达');
        fclose($publisher);
        fclose($subscriber);
        $cases += 3;
        // 过期只影响尚未投递的应用消息；已发送 PUBLISH 的交换和零期限接管都必须完成握手。
        foreach ([0, 1] as $expiry) {
            mqttDatabaseIdle($primary);
            $publisher = mqttSocket($port);
            $subscriber = mqttSocket($port);
            array_push($sockets, $publisher, $subscriber);
            mqttWrite($publisher, mqttConnect(5, 'qos2-expiry-p-' . $expiry));
            mqttWrite($subscriber, mqttConnect(5, 'qos2-expiry-s-' . $expiry));
            mqttAck($publisher, 5);
            mqttAck($subscriber, 5);
            $topic = 'example/qos2-expiry/' . $expiry;
            mqttWrite($subscriber, mqttSubscription(5, $topic, 1, 2));
            expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\x02"), 'QoS 2 过期订阅失败');
            mqttWrite($publisher, mqttQos2Publish(5, $topic, 'expiry', 1, false, "\x02" . pack('N', $expiry)));
            expect(mqttRead($publisher) === mqttQos2Ack(0x50, 1), 'QoS 2 过期消息未继续 PUBREC');
            if ($expiry > 0) {
                expect(mqttRead($subscriber) === mqttQos2Publish(5, $topic, 'expiry', 1, false, "\x02" . pack('N', $expiry)), '过期前消息未发送');
                usleep(1200000);
                mqttWrite($subscriber, mqttQos2Ack(0x50, 1));
                expect(mqttRead($subscriber) === mqttQos2Ack(0x62, 1), '已发 PUBLISH 被 Message Expiry 中止');
                mqttWrite($subscriber, mqttQos2Ack(0x70, 1));
            } else {
                mqttQuiet($subscriber);
            }
            mqttWrite($publisher, mqttQos2Ack(0x62, 1));
            expect(mqttRead($publisher) === mqttQos2Ack(0x70, 1), '到期后未完成接收握手');
            mqttUntil(fn (): bool => $standby->query("SELECT state FROM type_mqtt_messages WHERE topic = '" . $topic . "'")->fetchColumn() === 'complete', '到期交换未同步终结');
            fclose($publisher);
            fclose($subscriber);
            $cases += 3;
        }
        // 在持久推进期间合并工作但逐包回应；PUBLISH/订阅共用客户端的 Packet Identifier 空间。
        mqttDatabaseIdle($primary);
        $publisher = mqttSocket($port);
        $sockets[] = $publisher;
        mqttWrite($publisher, mqttConnect(5, 'qos2-pending-duplicates'));
        mqttAck($publisher, 5);
        $standby->query('SELECT pg_wal_replay_pause()')->fetchColumn();
        mqttWrite($publisher, mqttQos2Publish(5, 'example/qos2-pending', 'once', 7));
        mqttUntil(fn (): mixed => $primary->query("SELECT pid FROM pg_stat_activity WHERE application_name LIKE 'type_mqtt_%' AND wait_event = 'SyncRep'")->fetchColumn(), '重复测试未阻塞真实提交');
        mqttWrite($publisher, mqttQos2Publish(5, 'example/qos2-pending', 'once', 7, true));
        mqttQuiet($publisher);
        $standby->query('SELECT pg_wal_replay_resume()')->fetchColumn();
        expect(mqttRead($publisher) === mqttQos2Ack(0x50, 7) && mqttRead($publisher) === mqttQos2Ack(0x50, 7), '合并提交丢失重复报文响应');
        mqttWrite($publisher, mqttSubscription(5, 'example/qos2-collision', 7, 0));
        expect(mqttRead($publisher) === mqttSubscriptionAck(5, "\x91", 7), '订阅覆盖未完成 QoS 2 标识');
        mqttDatabaseIdle($primary);
        $standby->query('SELECT pg_wal_replay_pause()')->fetchColumn();
        mqttWrite($publisher, mqttQos2Ack(0x62, 7));
        mqttUntil(fn (): mixed => $primary->query("SELECT pid FROM pg_stat_activity WHERE application_name LIKE 'type_mqtt_%' AND wait_event = 'SyncRep'")->fetchColumn(), '重复 PUBREL 未阻塞真实提交');
        mqttWrite($publisher, mqttQos2Ack(0x62, 7));
        mqttQuiet($publisher);
        $standby->query('SELECT pg_wal_replay_resume()')->fetchColumn();
        expect(mqttRead($publisher) === mqttQos2Ack(0x70, 7) && mqttRead($publisher) === mqttQos2Ack(0x70, 7), '重复 PUBREL 丢失 PUBCOMP');
        expect((int) $standby->query("SELECT COUNT(*) FROM type_mqtt_messages WHERE topic = 'example/qos2-pending'")->fetchColumn() === 1, '在途重复被再次持久接管');
        fclose($publisher);
        $cases += 4;
        // 每个新增持久推进阶段都在真实 SyncRep 中取消；可见状态不能换成成功协议响应。
        foreach (['accept', 'release', 'received', 'complete'] as $stage) {
            mqttDatabaseIdle($primary);
            $publisher = mqttSocket($port);
            $subscriber = mqttSocket($port);
            array_push($sockets, $publisher, $subscriber);
            stream_set_timeout($publisher, 12);
            stream_set_timeout($subscriber, 12);
            mqttWrite($publisher, mqttConnect(5, 'qos2-fault-p-' . $stage));
            mqttWrite($subscriber, mqttConnect(5, 'qos2-fault-s-' . $stage));
            mqttAck($publisher, 5);
            mqttAck($subscriber, 5);
            $topic = 'example/qos2-fault/' . $stage;
            mqttWrite($subscriber, mqttSubscription(5, $topic, 1, 2));
            expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\x02"), '故障订阅失败');
            if ($stage !== 'accept') {
                mqttWrite($publisher, mqttQos2Publish(5, $topic, 'durable-state', 23));
                expect(mqttRead($publisher) === mqttQos2Ack(0x50, 23), '故障前接管失败');
                expect(mqttRead($subscriber) === mqttQos2Publish(5, $topic, 'durable-state', 1), '故障前交付失败');
            }
            if ($stage === 'complete') {
                mqttWrite($subscriber, mqttQos2Ack(0x50, 1));
                expect(mqttRead($subscriber) === mqttQos2Ack(0x62, 1), '故障前阶段推进失败');
            }
            mqttDatabaseIdle($primary);
            $standby->query('SELECT pg_wal_replay_pause()')->fetchColumn();
            $affected = in_array($stage, ['accept', 'release'], true) ? $publisher : $subscriber;
            $input = match ($stage) {
                'accept' => mqttQos2Publish(5, $topic, 'durable-state', 23),
                'release' => mqttQos2Ack(0x62, 23),
                'received' => mqttQos2Ack(0x50, 1),
                default => mqttQos2Ack(0x70, 1),
            };
            mqttWrite($affected, $input);
            $backend = mqttUntil(fn (): mixed => $primary->query("SELECT pid FROM pg_stat_activity WHERE application_name LIKE 'type_mqtt_%' AND wait_event = 'SyncRep'")->fetchColumn(), 'QoS 2 阶段没有进入真实 SyncRep');
            mqttQuiet($affected);
            $primary->query('SELECT pg_cancel_backend(' . (int) $backend . ')')->fetchColumn();
            expect(mqttRead($affected) === "\xe0\x02\x83\0", '未知 QoS 2 同步结果未返回标准 Implementation specific error');
            mqttUntil(fn (): bool => (int) $primary->query('SELECT COUNT(*) FROM pg_stat_activity WHERE pid = ' . (int) $backend)->fetchColumn() === 0, 'QoS 2 取消后遗留后端');
            $standby->query('SELECT pg_wal_replay_resume()')->fetchColumn();
            fclose($publisher);
            fclose($subscriber);
            mqttDatabaseIdle($primary);
            expect((int) $standby->query("SELECT COUNT(*) FROM type_mqtt_messages WHERE topic = '" . $topic . "'")->fetchColumn() === 1, '未知事实被删除或自动重复接管');
            $failures[$stage] = 'unknown-without-success-response-backend-released';
            $cases += 3;
        }
        return ['wire-cases' => $cases, 'unknown-commit-stages' => $failures,
            'standards' => ['MQTT 3.1.1 §2.2.1, §3.5–3.7, §4.3.3', 'MQTT 5 §3.5–3.7, §4.3.3-1..13, §4.4, §4.9'],
            'reconnect' => 'session recovery is checked by the session suite; this case verifies clean-session interruption'];
    } finally {
        foreach ($sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        $standby->query('SELECT pg_wal_replay_resume()')->fetchColumn();
    }
}
