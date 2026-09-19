<?php

declare(strict_types=1);

use Type\Testing\Process;

/** 独立线编码覆盖 RETAIN 和交付 QoS，不调用组件内部编码器。 */
function mqttRetainedPacket(int $version, string $topic, string $payload, int $qos, int $identifier = 1, string $properties = '', bool $retain = true): string
{
    return mqttPacket(0x30 | ($qos << 1) | ($retain ? 1 : 0), mqttField($topic)
        . ($qos > 0 ? pack('n', $identifier) : '') . ($version === 5 ? mqttLength(strlen($properties)) . $properties : '') . $payload);
}

/** 只处理测试使用的完整 PUBLISH，返回独立解码结果以核对真实网络属性。 */
function mqttRetainedRead(mixed $socket, int $version): array
{
    $packet = mqttRead($socket);
    expect($packet !== '' && (ord($packet[0]) >> 4) === 3, '没有收到保留 PUBLISH：' . bin2hex(substr($packet, 0, 16)));
    $offset = 1;
    do {
        $byte = ord($packet[$offset++]);
    } while (($byte & 128) !== 0);
    $topicLength = unpack('n', substr($packet, $offset, 2))[1];
    $offset += 2;
    $topic = substr($packet, $offset, $topicLength);
    $offset += $topicLength;
    $qos = (ord($packet[0]) >> 1) & 3;
    $identifier = $qos === 0 ? 0 : unpack('n', substr($packet, $offset, 2))[1];
    $offset += $qos === 0 ? 0 : 2;
    $propertyLength = 0;
    if ($version === 5) {
        $multiplier = 1;
        do {
            $byte = ord($packet[$offset++]);
            $propertyLength += ($byte & 127) * $multiplier;
            $multiplier *= 128;
        } while (($byte & 128) !== 0);
    }
    return ['topic' => $topic, 'qos' => $qos, 'id' => $identifier, 'retain' => (ord($packet[0]) & 1) !== 0, 'duplicate' => (ord($packet[0]) & 8) !== 0,
        'properties' => substr($packet, $offset, $propertyLength), 'payload' => substr($packet, $offset + $propertyLength)];
}

/** 完成实际网络握手；QoS 0 不制造协议确认。 */
function mqttRetainedComplete(mixed $socket, array $message): void
{
    if ($message['qos'] > 0) {
        mqttWrite($socket, mqttPacket($message['qos'] === 1 ? 0x40 : 0x50, pack('n', $message['id'])));
        if ($message['qos'] === 2) {
            expect(mqttRead($socket) === mqttPacket(0x62, pack('n', $message['id'])), '保留交付没有 PUBREL');
            mqttWrite($socket, mqttPacket(0x70, pack('n', $message['id'])));
        }
    }
}

function mqttRetainedPublish(mixed $socket, int $version, string $topic, string $payload, int $qos, string $properties = ''): void
{
    try {
        mqttWrite($socket, mqttRetainedPacket($version, $topic, $payload, $qos, 17, $properties));
        if ($qos > 0) {
            expect(mqttRead($socket) === mqttPacket($qos === 1 ? 0x40 : 0x50, "\0\x11"), '保留发布没有同步成功确认');
            if ($qos === 2) {
                mqttWrite($socket, "\x62\x02\0\x11");
                expect(mqttRead($socket) === "\x70\x02\0\x11", '保留发布没有 PUBCOMP');
            }
        }
    } catch (Throwable $failure) {
        throw new RuntimeException('保留发布失败：version=' . $version . ',qos=' . $qos . ',topic=' . $topic . ',bytes=' . strlen($payload), 0, $failure);
    }
}

/** 异步读取可能在 SUBACK/PINGRESP 后结束；持续观察多个 worker tick，不能以一次 PING 冒充空结果。 */
function mqttRetainedQuiet(mixed $socket): void
{
    $until = microtime(true) + 0.7;
    do {
        mqttQuiet($socket);
        usleep(20000);
    } while (microtime(true) < $until);
}

/** 实际SUBACK后暂停保留扫描；新增不能幻读，替换与删除仍读取订阅时的旧值。 */
function mqttRetainedSnapshotCases(int $port, string $consumer, PDO $primary): array
{
    $report = [];
    foreach ([4, 5] as $version) {
        foreach (['empty', 'replace', 'delete'] as $mode) {
            $sockets = [];
            $identity = 'snapshot-' . $mode . '-' . $version;
            $gate = $consumer . '/snapshot-gate-' . $identity;
            try {
                $publisher = mqttSocket($port);
                $subscriber = mqttSocket($port);
                $sockets = [$publisher, $subscriber];
                mqttWrite($publisher, mqttConnect($version, $identity . '-publisher', 0));
                mqttAck($publisher, $version);
                mqttWrite($subscriber, mqttConnect($version, $identity, 0));
                mqttAck($subscriber, $version);
                $topic = 'example/retained-snapshot/' . $mode . '/' . $version;
                if ($mode !== 'empty') {
                    mqttRetainedPublish($publisher, $version, $topic, 'before-subscription', 1);
                }
                mqttWrite($subscriber, mqttSubscription($version, $topic, 1, 1));
                expect(mqttRead($subscriber) === mqttSubscriptionAck($version, "\x01"), '保留快照回归没有实际SUBACK');
                mqttUntil(static fn (): bool => is_file($gate . '.entered'), '保留扫描未进入公开worker暂停');
                $after = $mode === 'delete' ? '' : 'created-after-subscription';
                mqttRetainedPublish($publisher, $version, $topic, $after, 1);
                $live = mqttRetainedRead($subscriber, $version);
                expect($live['payload'] === $after && !$live['retain'] && !$live['duplicate'], '订阅后的实时发布不符');
                mqttRetainedComplete($subscriber, $live);
                file_put_contents($gate . '.released', 'continue');
                if ($mode !== 'empty') {
                    $previous = mqttRetainedRead($subscriber, $version);
                    expect($previous['payload'] === 'before-subscription' && $previous['retain'] && !$previous['duplicate'], '订阅时保留值被后来替换或删除覆盖');
                    mqttRetainedComplete($subscriber, $previous);
                }
                $until = microtime(true) + 0.7;
                do {
                    mqttWrite($subscriber, "\xc0\0");
                    $packet = mqttRead($subscriber);
                    expect($packet === "\xd0\0", '订阅后保留扫描产生额外报文：' . bin2hex($packet));
                    usleep(20000);
                } while (microtime(true) < $until);
                mqttQuiet($publisher);
                $report[$identity] = 'one-live-delivery-and-subscription-time-retained-value';
            } finally {
                foreach ($sockets as $socket) {
                    if (is_resource($socket)) {
                        fclose($socket);
                    }
                }
                foreach (['.entered', '.released'] as $suffix) {
                    if (is_file($gate . $suffix)) {
                        unlink($gate . $suffix);
                    }
                }
            }
        }
    }
    $publisher = null;
    $subscriber = null;
    $gate = $consumer . '/snapshot-gate-snapshot-pages';
    $prefix = 'example/retained-snapshot/pages/';
    try {
        $publisher = mqttSocket($port);
        $subscriber = mqttSocket($port);
        mqttWrite($publisher, mqttConnect(5, 'snapshot-pages-publisher', 0));
        mqttAck($publisher, 5);
        mqttWrite($subscriber, mqttConnect(5, 'snapshot-pages', 0));
        mqttAck($subscriber, 5);
        foreach (['a', 'b', 'c'] as $key) {
            mqttRetainedPublish($publisher, 5, $prefix . $key, 'old-' . $key, 1);
        }
        mqttWrite($subscriber, mqttSubscription(5, $prefix . '#', 1, 1));
        expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\x01"), '跨页快照没有SUBACK');
        mqttUntil(static fn (): bool => is_file($gate . '.entered'), '跨页第一键未暂停');
        file_put_contents($gate . '.released', 'continue');
        $first = mqttRetainedRead($subscriber, 5);
        expect($first['payload'] === 'old-a' && $first['retain'], '跨页第一键不符');
        mqttRetainedComplete($subscriber, $first);
        mqttUntil(static fn (): bool => is_file($gate . '-page.entered'), '跨页后继读取未暂停');
        foreach (['b' => 'new-b', 'c' => '', 'd' => 'new-d'] as $key => $payload) {
            mqttRetainedPublish($publisher, 5, $prefix . $key, $payload, 1);
            $live = mqttRetainedRead($subscriber, 5);
            expect($live['payload'] === $payload && !$live['retain'], '跨页期间实时消息不符');
            mqttRetainedComplete($subscriber, $live);
        }
        file_put_contents($gate . '-page.released', 'continue');
        foreach (['b', 'c'] as $key) {
            $previous = mqttRetainedRead($subscriber, 5);
            expect($previous['topic'] === $prefix . $key && $previous['payload'] === 'old-' . $key && $previous['retain'], '跨页快照丢失旧键或混入新值');
            mqttRetainedComplete($subscriber, $previous);
        }
        mqttRetainedQuiet($subscriber);
        $report['wildcard-pages'] = 'old-values-survive-replace-delete-and-new-key-is-not-replayed';
    } finally {
        foreach ([$publisher, $subscriber] as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        foreach (['.entered', '.released', '-page.entered', '-page.released'] as $suffix) {
            if (is_file($gate . $suffix)) {
                unlink($gate . $suffix);
            }
        }
    }
    require_once __DIR__ . '/mqtt-sessions.php';
    $publisher = null;
    $subscriber = null;
    $gate = $consumer . '/snapshot-gate-snapshot-resume';
    $topic = 'example/retained-snapshot/resume';
    try {
        $publisher = mqttSocket($port);
        $subscriber = mqttSocket($port);
        mqttWrite($publisher, mqttConnect(5, 'snapshot-resume-publisher', 0));
        mqttAck($publisher, 5);
        mqttWrite($subscriber, mqttSessionConnect(5, 'snapshot-resume', true, 60));
        mqttSessionAck($subscriber, false);
        mqttRetainedPublish($publisher, 5, $topic, 'old-before-disconnect', 1);
        mqttWrite($subscriber, mqttSubscription(5, $topic, 1, 1));
        expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\x01"), '恢复快照没有SUBACK');
        mqttUntil(static fn (): bool => is_file($gate . '.entered'), '恢复快照未暂停');
        fclose($subscriber);
        mqttRetainedPublish($publisher, 5, $topic, 'new-after-disconnect', 0);
        mqttUntil(fn (): bool => $primary->query("SELECT encode(payload, 'hex') FROM type_mqtt_retained WHERE topic = 'example/retained-snapshot/resume'")->fetchColumn() === bin2hex('new-after-disconnect'), '断线期间新保留值没有提交');
        file_put_contents($gate . '.released', 'continue');
        mqttUntil(fn (): bool => (int) $primary->query("SELECT COUNT(*) FROM type_mqtt_sessions WHERE client_id = 'snapshot-resume' AND owner_id IS NULL")->fetchColumn() === 1, '持久快照会话没有结束旧所有权');
        $subscriber = mqttSocket($port);
        mqttWrite($subscriber, mqttSessionConnect(5, 'snapshot-resume', false, 60));
        mqttSessionAck($subscriber, true);
        $previous = mqttRetainedRead($subscriber, 5);
        expect($previous['payload'] === 'old-before-disconnect' && $previous['retain'] && !$previous['duplicate'], '恢复没有继续订阅时的未接管快照');
        mqttRetainedComplete($subscriber, $previous);
        mqttRetainedQuiet($subscriber);
        mqttWrite($subscriber, mqttPacket(0xe0, "\0\x05\x11\0\0\0\0"));
        expect(mqttRead($subscriber) === '', '快照恢复会话没有按零期限结束');
        $report['persistent-resume'] = 'unaccepted-original-survives-disconnect-and-later-replacement';
    } finally {
        foreach ([$publisher, $subscriber] as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        foreach (['.entered', '.released'] as $suffix) {
            if (is_file($gate . $suffix)) {
                unlink($gate . $suffix);
            }
        }
    }
    foreach (['before', 'after'] as $phase) {
        $publisher = null;
        $subscriber = null;
        $gate = $consumer . '/snapshot-gate-save-' . $phase;
        $readGate = $consumer . '/snapshot-gate-snapshot-save-' . $phase;
        $topic = 'example/retained-snapshot/save-' . $phase;
        try {
            $publisher = mqttSocket($port);
            $subscriber = mqttSocket($port);
            mqttWrite($publisher, mqttConnect(5, 'snapshot-save-' . $phase . '-publisher', 0));
            mqttAck($publisher, 5);
            mqttWrite($subscriber, mqttConnect(5, 'snapshot-save-' . $phase, 0));
            mqttAck($subscriber, 5);
            mqttRetainedPublish($publisher, 5, $topic, 'old-before-save', 1);
            file_put_contents($readGate . '.released', 'continue');
            mqttWrite($subscriber, mqttSubscription(5, $topic, 1, 1));
            mqttUntil(static fn (): bool => is_file($gate . '.entered'), '订阅保存切点未暂停');
            mqttRetainedPublish($publisher, 5, $topic, 'new-during-save', 1);
            file_put_contents($gate . '.released', 'continue');
            expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\x01"), '订阅保存切点没有SUBACK');
            $previous = mqttRetainedRead($subscriber, 5);
            expect($previous['payload'] === ($phase === 'before' ? 'new-during-save' : 'old-before-save') && $previous['retain'], '订阅保存边界的保留值不符');
            mqttRetainedComplete($subscriber, $previous);
            if ($phase === 'after') {
                $live = mqttRetainedRead($subscriber, 5);
                expect($live['payload'] === 'new-during-save' && !$live['retain'] && !$live['duplicate'], '订阅保存已提交但未回收时丢失实时副本');
                mqttRetainedComplete($subscriber, $live);
            }
            mqttRetainedQuiet($subscriber);
            $report['subscription-save-' . $phase] = $phase === 'before' ? 'later-snapshot-only' : 'old-snapshot-and-one-live-copy';
        } finally {
            foreach ([$publisher, $subscriber] as $socket) {
                if (is_resource($socket)) {
                    fclose($socket);
                }
            }
            foreach ([$gate, $readGate] as $cleanupGate) {
                foreach (['.entered', '.released'] as $suffix) {
                    if (is_file($cleanupGate . $suffix)) {
                        unlink($cleanupGate . $suffix);
                    }
                }
            }
        }
    }
    foreach ([4, 5] as $version) {
        $publisher = null;
        $subscriber = null;
        $gate = $consumer . '/order-gate-cut-publisher-' . $version;
        $topic = 'example/order-delay/concurrent/before/snapshot-' . $version;
        try {
            $publisher = mqttSocket($port);
            $subscriber = mqttSocket($port);
            mqttWrite($publisher, mqttConnect($version, 'cut-publisher-' . $version, 0));
            mqttAck($publisher, $version);
            mqttWrite($subscriber, mqttConnect($version, 'cut-subscriber-' . $version, 0));
            mqttAck($subscriber, $version);
            mqttWrite($publisher, mqttRetainedPacket($version, $topic, 'first', 1));
            mqttUntil(static fn (): bool => is_file($gate . '.entered'), '订阅前发布接管未暂停');
            mqttWrite($subscriber, mqttSubscription($version, $topic, 1, 1));
            expect(mqttRead($subscriber) === mqttSubscriptionAck($version, "\x01"), '等待发布期间的订阅没有SUBACK');
            mqttRetainedQuiet($subscriber);
            file_put_contents($gate . '.released', 'continue');
            expect(mqttRead($publisher) === "\x40\x02\0\x01", '订阅之后释放的发布没有PUBACK');
            $live = mqttRetainedRead($subscriber, $version);
            expect($live['payload'] === 'first' && !$live['retain'] && !$live['duplicate'], '订阅之后提交的发布丢失或错误重放');
            mqttRetainedComplete($subscriber, $live);
            mqttRetainedQuiet($subscriber);
            $report['publication-waiting-' . $version] = 'subscription-installed-before-accept-gets-one-live-copy';
        } finally {
            foreach ([$publisher, $subscriber] as $socket) {
                if (is_resource($socket)) {
                    fclose($socket);
                }
            }
            foreach (['.entered', '.released'] as $suffix) {
                if (is_file($gate . $suffix)) {
                    unlink($gate . $suffix);
                }
            }
        }
    }
    mqttUntil(fn (): bool => (int) $primary->query('SELECT COUNT(*) FROM type_mqtt_retained_snapshots')->fetchColumn() === 0, '完成读取后快照引用未回收');
    mqttUntil(fn (): bool => (int) $primary->query('SELECT COUNT(*) FROM type_mqtt_retained_history')->fetchColumn() === 0, '完成读取后旧版本未回收');
    return $report;
}

/** 真实主备、双版本及三个 QoS 的保留保存、替换、删除、订阅选项和过期。 */
function mqttRetainedCases(int $port, PDO $primary, PDO $standby): array
{
    $sockets = [];
    $cases = 0;
    try {
        $publisher = mqttSocket($port);
        $subscriber = mqttSocket($port);
        array_push($sockets, $publisher, $subscriber);
        mqttWrite($publisher, mqttConnect(5, 'retained-wal-p', 0));
        mqttWrite($subscriber, mqttConnect(5, 'retained-wal-s', 0));
        mqttAck($publisher, 5);
        mqttAck($subscriber, 5);
        mqttRetainedPublish($publisher, 5, 'example/retained/wal-boundary', 'page-end', 1);
        mqttDatabaseIdle($primary);
        // 真实 WAL 页尾的 insert 指针包含下一页 24 字节头，而已刷盘末尾没有下一条记录。
        // 只在本轮隔离数据库生成有界填充；保留读取不能因为不存在的 WAL 而判为未知。
        $positions = [];
        for ($attempt = 0; $attempt < 2048; $attempt++) {
            $primary->query("SELECT pg_logical_emit_message(true, 'mqtt_test', '')")->fetchColumn();
            $positions = $primary->query('SELECT pg_current_wal_insert_lsn()::text AS inserted, pg_current_wal_flush_lsn()::text AS flushed, '
                . 'pg_wal_lsn_diff(pg_current_wal_insert_lsn(), pg_current_wal_flush_lsn())::int AS gap')->fetch(PDO::FETCH_ASSOC);
            if ((int) $positions['gap'] === 24) {
                break;
            }
        }
        expect((int) $positions['gap'] === 24, '未建立真实 WAL 页边界夹具');
        mqttWrite($subscriber, mqttSubscription(5, 'example/retained/wal-boundary'));
        expect(mqttRead($subscriber) === mqttSubscriptionAck(5), 'WAL 页边界订阅失败');
        $boundary = mqttRetainedRead($subscriber, 5);
        expect($boundary['payload'] === 'page-end' && $boundary['retain'], 'WAL 页边界错误阻止保留交付');
        fclose($publisher);
        fclose($subscriber);
        mqttDatabaseIdle($primary);
        $cases++;
        foreach ([4, 5] as $version) {
            foreach ([0, 1, 2] as $qos) {
                $topic = 'example/retained/' . $version . '/' . $qos;
                $publisher = mqttSocket($port);
                $live = mqttSocket($port);
                $later = mqttSocket($port);
                array_push($sockets, $publisher, $live, $later);
                mqttWrite($publisher, mqttConnect($version, 'retain-p-' . $version . '-' . $qos));
                mqttWrite($live, mqttConnect($version, 'retain-live-' . $version . '-' . $qos));
                mqttWrite($later, mqttConnect($version, 'retain-later-' . $version . '-' . $qos));
                mqttAck($publisher, $version);
                mqttAck($live, $version);
                mqttAck($later, $version);
                mqttWrite($live, mqttSubscription($version, $topic, 1, 2));
                expect(mqttRead($live) === mqttSubscriptionAck($version, "\x02"), '保留实时订阅失败');
                mqttDatabaseIdle($primary);
                $payload = "\0\xff" . str_repeat('binary', $qos === 2 ? 16000 : 10);
                $properties = $version === 5 ? "\x09" . mqttField("\0\xff") . "\x26" . mqttField('key') . mqttField('value') : '';
                mqttRetainedPublish($publisher, $version, $topic, $payload, $qos, $properties);
                $message = mqttRetainedRead($live, $version);
                expect($message['topic'] === $topic && $message['payload'] === $payload && $message['properties'] === $properties
                    && $message['qos'] === $qos && !$message['retain'], '实时转发的载荷、属性、QoS 或 RETAIN 错误');
                mqttRetainedComplete($live, $message);
                expect($standby->query("SELECT encode(payload, 'hex') FROM type_mqtt_retained WHERE topic = '" . $topic . "'")->fetchColumn() === bin2hex($payload), '实时交付早于保留同步事实');
                mqttWrite($later, mqttSubscription($version, $topic, 1, 2));
                expect(mqttRead($later) === mqttSubscriptionAck($version, "\x02"), '新保留订阅失败');
                $replay = mqttRetainedRead($later, $version);
                expect($replay['topic'] === $topic && $replay['payload'] === $payload && $replay['properties'] === $properties
                    && $replay['qos'] === $qos && $replay['retain'], '新订阅没有得到正确保留消息');
                mqttRetainedComplete($later, $replay);
                mqttDatabaseIdle($primary);
                $replacementQos = ($qos + 1) % 3;
                mqttRetainedPublish($publisher, $version, $topic, 'replacement', $replacementQos);
                foreach ([$live, $later] as $receiver) {
                    $replacement = mqttRetainedRead($receiver, $version);
                    expect($replacement['payload'] === 'replacement' && $replacement['qos'] === $replacementQos && !$replacement['retain'], '替换没有遵守新 QoS');
                    mqttRetainedComplete($receiver, $replacement);
                }
                mqttDatabaseIdle($primary);
                mqttWrite($later, mqttSubscription($version, $topic, 2, 2));
                $resubscribe = mqttRead($later);
                expect($resubscribe === mqttSubscriptionAck($version, "\x02", 2), '重复订阅失败：version=' . $version . ', qos=' . $qos . ', wire=' . bin2hex($resubscribe));
                $replacement = mqttRetainedRead($later, $version);
                expect($replacement['payload'] === 'replacement' && $replacement['qos'] === $replacementQos && $replacement['retain'], '重复订阅读到旧保留值');
                mqttRetainedComplete($later, $replacement);
                mqttDatabaseIdle($primary);
                mqttRetainedPublish($publisher, $version, $topic, '', $qos);
                foreach ([$live, $later] as $receiver) {
                    $deleted = mqttRetainedRead($receiver, $version);
                    expect($deleted['payload'] === '' && !$deleted['retain'], '清除保留仍应实时转发空载荷');
                    mqttRetainedComplete($receiver, $deleted);
                }
                expect((int) $standby->query("SELECT COUNT(*) FROM type_mqtt_retained WHERE topic = '" . $topic . "'")->fetchColumn() === 0, '空载荷没有同步清除保留事实');
                mqttDatabaseIdle($primary);
                mqttWrite($later, mqttSubscription($version, $topic, 3, 2));
                expect(mqttRead($later) === mqttSubscriptionAck($version, "\x02", 3), '删除后订阅失败');
                mqttRetainedQuiet($later);
                mqttQuiet($publisher);
                fclose($publisher);
                fclose($live);
                fclose($later);
                $cases += 7;
            }
        }
        $publisher = mqttSocket($port);
        $subscriber = mqttSocket($port);
        array_push($sockets, $publisher, $subscriber);
        mqttWrite($publisher, mqttConnect(5, 'retain-options-p'));
        mqttWrite($subscriber, mqttConnect(5, 'retain-options-s'));
        mqttAck($publisher, 5);
        mqttAck($subscriber, 5);
        $topic = 'example/retained/options';
        mqttRetainedPublish($publisher, 5, $topic, 'options', 1);
        foreach ([[1, 0x11, true], [2, 0x11, false], [3, 0x21, false], [4, 0x01, true]] as [$id, $options, $expected]) {
            mqttWrite($subscriber, mqttSubscription(5, $topic, $id, $options));
            expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\x01", $id), 'RH 订阅失败');
            if ($expected) {
                $message = mqttRetainedRead($subscriber, 5);
                expect($message['payload'] === 'options' && $message['retain'], 'RH 没有重放');
                mqttRetainedComplete($subscriber, $message);
            } else {
                mqttRetainedQuiet($subscriber);
            }
            mqttDatabaseIdle($primary);
            $cases++;
        }
        mqttWrite($subscriber, mqttSubscription(5, $topic, 5, 0x29));
        expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\x01", 5), 'RAP 订阅失败');
        mqttRetainedPublish($publisher, 5, $topic, 'rap', 1);
        $message = mqttRetainedRead($subscriber, 5);
        expect($message['retain'] && $message['payload'] === 'rap', 'RAP 没有保留实时 RETAIN');
        mqttRetainedComplete($subscriber, $message);
        mqttWrite($publisher, mqttSubscription(5, $topic, 2, 0x05));
        expect(mqttRead($publisher) === mqttSubscriptionAck(5, "\x01", 2), 'No Local 订阅失败');
        mqttRetainedQuiet($publisher);
        $cases += 2;
        fclose($subscriber);
        fclose($publisher);
        mqttDatabaseIdle($primary);
        $publisher = mqttSocket($port);
        $sockets[] = $publisher;
        mqttWrite($publisher, mqttConnect(5, 'retain-expiry-p'));
        mqttAck($publisher, 5);
        mqttRetainedPublish($publisher, 5, 'example/retained/expiry', 'expiry', 1, "\x02" . pack('N', 4));
        usleep(1100000);
        $subscriber = mqttSocket($port);
        $sockets[] = $subscriber;
        mqttWrite($subscriber, mqttConnect(5, 'retain-expiry-s'));
        mqttAck($subscriber, 5);
        mqttWrite($subscriber, mqttSubscription(5, 'example/retained/expiry', 1, 0));
        expect(mqttRead($subscriber) === mqttSubscriptionAck(5), '过期保留订阅失败');
        $message = mqttRetainedRead($subscriber, 5);
        $remaining = unpack('N', substr($message['properties'], 1, 4))[1];
        expect($message['properties'][0] === "\x02" && $remaining > 0 && $remaining < 4 && $message['qos'] === 0, '保留期限被刷新或未降低 QoS');
        mqttUntil(fn (): bool => time() >= (int) $standby->query("SELECT expires_at FROM type_mqtt_retained WHERE topic = 'example/retained/expiry'")->fetchColumn(), '保留期限未到期');
        mqttWrite($subscriber, mqttSubscription(5, 'example/retained/expiry', 2, 0));
        expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\0", 2), '到期后订阅失败');
        mqttRetainedQuiet($subscriber);
        expect((int) $standby->query("SELECT COUNT(*) FROM type_mqtt_retained WHERE topic = 'example/retained/expiry'")->fetchColumn() === 0, '到期记录没有清理');
        mqttRetainedPublish($publisher, 5, 'example/retained/zero', 'old', 1);
        mqttRetainedPublish($publisher, 5, 'example/retained/zero', 'zero', 1, "\x02\0\0\0\0");
        expect((int) $standby->query("SELECT COUNT(*) FROM type_mqtt_retained WHERE topic = 'example/retained/zero'")->fetchColumn() === 0, '零期限没有清除旧值');
        $cases += 4;
        fclose($subscriber);
        mqttRetainedPublish($publisher, 5, 'example/retained/large', str_repeat('x', 256), 1);
        $limited = mqttSocket($port);
        $sockets[] = $limited;
        mqttWrite($limited, mqttConnect(5, 'retain-limited', 10, "\x27" . pack('N', 100)));
        mqttAck($limited, 5);
        mqttWrite($limited, mqttSubscription(5, 'example/retained/large', 1, 1));
        expect(mqttRead($limited) === mqttSubscriptionAck(5, "\x01"), '接收报文受限订阅失败');
        mqttRetainedQuiet($limited);
        mqttWrite($limited, mqttSubscription(5, 'denied/retained', 2, 1));
        expect(mqttRead($limited) === mqttSubscriptionAck(5, "\x87", 2), '订阅权限拒绝不符');
        mqttRetainedQuiet($limited);
        expect((int) $standby->query("SELECT COUNT(*) FROM type_mqtt_deliveries WHERE client_id = 'retain-limited'")->fetchColumn() === 0, '超限保留副本占用持久积压');
        fclose($limited);
        $denied = mqttSocket($port);
        $sockets[] = $denied;
        mqttWrite($denied, mqttConnect(5, 'retain-denied-p'));
        mqttAck($denied, 5);
        mqttWrite($denied, mqttRetainedPacket(5, 'example/read-only/retained', 'denied', 1));
        expect(mqttRead($denied) === "\xe0\x02\x87\0", '保留发布授权拒绝不符');
        expect((int) $standby->query("SELECT COUNT(*) FROM type_mqtt_retained WHERE topic = 'example/read-only/retained'")->fetchColumn() === 0, '拒绝授权仍保存保留事实');
        fclose($denied);
        $cases += 3;
        $parallel = mqttSocket($port);
        $sockets[] = $parallel;
        mqttWrite($parallel, mqttConnect(5, 'retain-concurrent-p'));
        mqttAck($parallel, 5);
        mqttWrite($publisher, mqttRetainedPacket(5, 'example/retained/concurrent', str_repeat('a', 512), 1, 21));
        mqttWrite($parallel, mqttRetainedPacket(5, 'example/retained/concurrent', str_repeat('b', 768), 1, 22));
        expect(mqttRead($publisher) === "\x40\x02\0\x15" && mqttRead($parallel) === "\x40\x02\0\x16", '并发替换未分别确认');
        $latest = $standby->query("SELECT encode(payload, 'hex') FROM type_mqtt_retained WHERE topic = 'example/retained/concurrent'")->fetchColumn();
        expect(in_array($latest, [bin2hex(str_repeat('a', 512)), bin2hex(str_repeat('b', 768))], true), '并发保留值被拼接或截断');
        mqttWrite($parallel, mqttSubscription(5, 'example/retained/concurrent', 2, 1));
        expect(mqttRead($parallel) === mqttSubscriptionAck(5, "\x01", 2), '并发替换后订阅失败');
        $message = mqttRetainedRead($parallel, 5);
        expect(bin2hex($message['payload']) === $latest && $message['retain'], '新订阅未得到最后已提交的完整值');
        mqttRetainedComplete($parallel, $message);
        fclose($parallel);
        $cases += 2;
        $window = mqttSocket($port);
        $sockets[] = $window;
        mqttWrite($window, mqttConnect(5, 'retain-window', 10, "\x21\0\x01"));
        mqttAck($window, 5);
        mqttWrite($window, mqttSubscription(5, 'example/retained/concurrent', 1, 1));
        expect(mqttRead($window) === mqttSubscriptionAck(5, "\x01"), '保留窗口订阅失败');
        $first = mqttRetainedRead($window, 5);
        mqttWrite($window, mqttSubscription(5, 'example/retained/concurrent', 2, 1));
        expect(mqttRead($window) === mqttSubscriptionAck(5, "\x01", 2), '窗口占用时重复订阅失败');
        mqttRetainedQuiet($window);
        mqttRetainedComplete($window, $first);
        $second = mqttRetainedRead($window, 5);
        expect($second['payload'] === $first['payload'] && $second['id'] !== $first['id'] && $second['retain'], '保留重放没有等待窗口或丢失重复订阅');
        mqttRetainedComplete($window, $second);
        fclose($window);
        $cases += 2;
        mqttDatabaseIdle($primary);
        // 主库可见并不证明同步接管，取消 SyncRep 后不能成功确认或实时交付。
        $observer = mqttSocket($port);
        $sockets[] = $observer;
        mqttWrite($observer, mqttConnect(5, 'retain-unknown-s'));
        mqttAck($observer, 5);
        mqttWrite($observer, mqttSubscription(5, 'example/retained/unknown', 1, 0x21));
        expect(mqttRead($observer) === mqttSubscriptionAck(5, "\x01"), '未知提交观察订阅失败');
        mqttDatabaseIdle($primary);
        $standby->query('SELECT pg_wal_replay_pause()')->fetchColumn();
        mqttWrite($publisher, mqttRetainedPacket(5, 'example/retained/unknown', 'unconfirmed', 1, 23));
        $pid = mqttUntil(fn (): mixed => $primary->query("SELECT pid FROM pg_stat_activity WHERE application_name LIKE 'type_mqtt_%' AND wait_event = 'SyncRep'")->fetchColumn(), '保留写入没有等待真实同步');
        mqttQuiet($publisher);
        mqttQuiet($observer);
        $primary->query('SELECT pg_cancel_backend(' . (int) $pid . ')')->fetchColumn();
        stream_set_timeout($publisher, 8);
        expect(mqttRead($publisher) === "\xe0\x02\x83\0", '未知保留写入未返回标准 Implementation specific error');
        expect($primary->query("SELECT encode(payload, 'hex') FROM type_mqtt_retained WHERE topic = 'example/retained/unknown'")->fetchColumn() === bin2hex('unconfirmed'), '取消同步后本地事实未保留');
        expect(mqttRead($observer) === "\xe0\x02\x83\0", '未知保留写入仍交付或未使用标准错误隔离接收者');
        $standby->query('SELECT pg_wal_replay_resume()')->fetchColumn();
        fclose($publisher);
        fclose($observer);
        $cases += 2;
        mqttDatabaseIdle($primary);
        $publisher = mqttSocket($port);
        $sockets[] = $publisher;
        mqttWrite($publisher, mqttConnect(5, 'retain-restart-p'));
        mqttAck($publisher, 5);
        // 留下重启验收使用的已同步值。
        mqttRetainedPublish($publisher, 5, 'example/retained/restart', "restart\0\xff", 2);
        fclose($publisher);
        mqttDatabaseIdle($primary);
        return ['wire-cases' => $cases, 'large-payload-bytes' => 96002, 'wal-boundary' => $positions];
    } finally {
        $standby->query('SELECT pg_wal_replay_resume()')->fetchColumn();
        foreach ($sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }
}

/** 新建低配额 Broker，观察消息数与字节拒绝不破坏旧值，删除立即释放保留配额。 */
function mqttRetainedLimits(string $consumer, array $command, array $environment, int $port, PDO $primary, PDO $standby): array
{
    mqttDatabaseIdle($primary);
    $primary->exec('DELETE FROM type_mqtt_retained');
    $environment['MQTT_CERTIFICATE'] = '';
    $environment['MQTT_PRIVATE_KEY'] = '';
    $environment['MQTT_RETAINED_MAX_MESSAGES'] = '2';
    $environment['MQTT_RETAINED_MAX_BYTES'] = '128';
    $process = new Process([...$command, '--plaintext', '--port=' . $port], $consumer, $environment);
    $sockets = [];
    $gate = $consumer . '/snapshot-gate-snapshot-capacity';
    try {
        mqttUntil(function () use ($port, $process): bool {
            expect($process->running(), '低配额 Broker 提前退出：' . $process->stderr());
            $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $number, $error, 0.1);
            if ($probe === false) {
                return false;
            }
            fclose($probe);
            return true;
        }, '低配额 Broker 未就绪');
        $publisher = mqttSocket($port);
        $sockets[] = $publisher;
        mqttWrite($publisher, mqttConnect(5, 'retain-capacity-p'));
        mqttAck($publisher, 5);
        mqttRetainedPublish($publisher, 5, 'example/capacity/a', 'a', 1);
        mqttRetainedPublish($publisher, 5, 'example/capacity/b', 'b', 1);
        mqttWrite($publisher, mqttRetainedPacket(5, 'example/capacity/c', 'c', 1));
        expect(mqttRead($publisher) === "\xe0\x02\x97\0", '保留条数超限仍接管');
        fclose($publisher);
        $publisher = mqttSocket($port);
        $sockets[] = $publisher;
        mqttWrite($publisher, mqttConnect(5, 'retain-byte-capacity-p'));
        mqttAck($publisher, 5);
        mqttWrite($publisher, mqttRetainedPacket(5, 'example/capacity/a', str_repeat('a', 128), 1));
        expect(mqttRead($publisher) === "\xe0\x02\x97\0", '保留字节超限仍替换');
        expect($standby->query("SELECT encode(payload, 'hex') FROM type_mqtt_retained WHERE topic = 'example/capacity/a'")->fetchColumn() === '61', '失败替换破坏旧值');
        fclose($publisher);
        $publisher = mqttSocket($port);
        $sockets[] = $publisher;
        mqttWrite($publisher, mqttConnect(5, 'retain-capacity-delete'));
        mqttAck($publisher, 5);
        mqttRetainedPublish($publisher, 5, 'example/capacity/a', '', 1);
        mqttRetainedPublish($publisher, 5, 'example/capacity/c', 'c', 1);
        expect((int) $standby->query('SELECT COUNT(*) FROM type_mqtt_retained')->fetchColumn() === 2, '保留删除没有释放独立配额');
        mqttWrite($publisher, mqttSubscription(5, 'example/capacity/b', 2, 1));
        expect(mqttRead($publisher) === mqttSubscriptionAck(5, "\x01", 2), '满额保留存储不能建立独立待投递副本');
        $message = mqttRetainedRead($publisher, 5);
        expect($message['payload'] === 'b' && $message['retain'], '保留和积压预算被混用');
        mqttRetainedComplete($publisher, $message);
        fclose($publisher);
        mqttDatabaseIdle($primary);
        $publisher = mqttSocket($port);
        $snapshot = mqttSocket($port);
        array_push($sockets, $publisher, $snapshot);
        mqttWrite($publisher, mqttConnect(5, 'retain-history-p', 0));
        mqttAck($publisher, 5);
        mqttWrite($snapshot, mqttConnect(5, 'snapshot-capacity', 0));
        mqttAck($snapshot, 5);
        mqttRetainedPublish($publisher, 5, 'example/capacity/c', '', 1);
        mqttWrite($snapshot, mqttSubscription(5, 'example/capacity/b', 1, 1));
        expect(mqttRead($snapshot) === mqttSubscriptionAck(5, "\x01"), '快照容量没有SUBACK');
        mqttUntil(static fn (): bool => is_file($gate . '.entered'), '快照容量读取未暂停');
        mqttRetainedPublish($publisher, 5, 'example/capacity/b', 'new-b', 1);
        $live = mqttRetainedRead($snapshot, 5);
        expect($live['payload'] === 'new-b' && !$live['retain'], '容量内保留替换没有实时消息');
        mqttRetainedComplete($snapshot, $live);
        expect((int) $standby->query('SELECT COUNT(*) FROM type_mqtt_retained_history')->fetchColumn() === 1, '旧快照没有计入保留容量');
        mqttWrite($publisher, mqttRetainedPacket(5, 'example/capacity/c', 'c', 1));
        expect(mqttRead($publisher) === "\xe0\x02\x97\0", '当前值与旧快照共同超出条数仍接管');
        fclose($publisher);
        file_put_contents($gate . '.released', 'continue');
        $previous = mqttRetainedRead($snapshot, 5);
        expect($previous['payload'] === 'b' && $previous['retain'], '容量拒绝破坏了订阅时旧值');
        mqttRetainedComplete($snapshot, $previous);
        mqttUntil(fn (): bool => (int) $standby->query('SELECT COUNT(*) FROM type_mqtt_retained_history')->fetchColumn() === 0, '已接管快照没有归还旧版本容量');
        $publisher = mqttSocket($port);
        $sockets[] = $publisher;
        mqttWrite($publisher, mqttConnect(5, 'retain-history-after', 0));
        mqttAck($publisher, 5);
        mqttRetainedPublish($publisher, 5, 'example/capacity/c', 'c', 1);
        mqttRetainedQuiet($snapshot);
        fclose($publisher);
        fclose($snapshot);
        mqttDatabaseIdle($primary);
        $result = $process->stop(12);
        expect($result->successful() && $result->stderr === '', '低配额 Broker 停止失败：' . $result->stderr);
        $statistics = json_decode(trim($result->stdout), true, 32, JSON_THROW_ON_ERROR);
        foreach (['connections', 'pendingCommits', 'quarantinedCommits', 'closingSessions', 'retainedQueued', 'retainedPending', 'retainedBytes'] as $field) {
            expect($statistics[$field] === 0, '低配额 Broker 资源未回收：' . $field);
        }
        return ['wire-cases' => 8, 'statistics' => $statistics, 'limits' => ['messages' => 2, 'bytes' => 128],
            'snapshot-history' => 'shared-capacity-rejection-preserves-old-value-and-completion-releases-capacity'];
    } finally {
        foreach ($sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        $process->stop(12);
        foreach (['.entered', '.released'] as $suffix) {
            if (is_file($gate . $suffix)) {
                unlink($gate . $suffix);
            }
        }
    }
}

/** 只用重启后的网络入口读取先前已同步的保留值。 */
function mqttRetainedRestart(int $port, ?string $certificate = null): void
{
    $socket = mqttSocket($port, $certificate);
    try {
        mqttWrite($socket, mqttConnect(5, 'retain-restart'));
        mqttAck($socket, 5);
        mqttWrite($socket, mqttSubscription(5, 'example/retained/restart', 1, 2));
        expect(mqttRead($socket) === mqttSubscriptionAck(5, "\x02"), '重启后保留订阅失败');
        $message = mqttRetainedRead($socket, 5);
        expect($message['payload'] === "restart\0\xff" && $message['qos'] === 2 && $message['retain'], '进程重启丢失保留事实');
        mqttRetainedComplete($socket, $message);
    } finally {
        fclose($socket);
    }
}
