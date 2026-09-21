<?php

declare(strict_types=1);

use Type\Testing\Process;

/** 独立消费者在公开worker回调内模拟事务前后延迟，不向Broker加入测试开关。 */
function mqttOrderingApplication(string $source): string
{
    $original = "PendingCommit::work(\$store, 'pipe');";
    expect(substr_count($source, $original) === 1, '可靠发布worker装配入口变化');
    return str_replace($original, <<<'PHP'
PendingCommit::work($store, 'pipe', function (array $request) use ($store): \Type\Mqtt\CommitResult {
            $subscriptionGate = '';
            $subscriptionAfter = false;
            if (($request['action'] ?? '') === 'session_save' && isset($request['retained'][0])
                && str_contains($request['retained'][0]['topic'], '/save-')) {
                $subscriptionAfter = str_contains($request['retained'][0]['topic'], '/save-after');
                $subscriptionGate = getcwd() . '/snapshot-gate-' . basename($request['retained'][0]['topic']);
            }
            if ($subscriptionGate !== '' && !$subscriptionAfter) {
                file_put_contents($subscriptionGate . '.entered', 'waiting');
                $subscriptionUntil = microtime(true) + 3.0;
                while (!is_file($subscriptionGate . '.released')) {
                    if (microtime(true) >= $subscriptionUntil) {
                        return new \Type\Mqtt\CommitResult($request['operation_id'], 'rejected', 0x83);
                    }
                    usleep(10000);
                }
            }
            if (($request['action'] ?? '') === 'retained_read' && (str_starts_with($request['topic'] ?? '', 'example/retained-snapshot/')
                || ($request['client_id'] ?? '') === 'snapshot-capacity')) {
                $snapshotGate = getcwd() . '/snapshot-gate-' . basename($request['client_id']);
                if (str_contains($request['topic'], '/pages/') && ($request['cursor'] ?? '') !== '') {
                    $snapshotGate .= '-page';
                }
                file_put_contents($snapshotGate . '.entered', 'waiting');
                $snapshotUntil = microtime(true) + 3.0;
                while (!is_file($snapshotGate . '.released')) {
                    if (microtime(true) >= $snapshotUntil) {
                        return new \Type\Mqtt\CommitResult($request['operation_id'], 'rejected', 0x83);
                    }
                    usleep(10000);
                }
            }
            $delayed = ($request['action'] ?? '') === 'accept' && ($request['message']['payload'] ?? '') === 'Zmlyc3Q='
                && str_starts_with($request['message']['topic'] ?? '', 'example/order-delay/');
            $after = str_contains($request['message']['topic'] ?? '', '/after/');
            if ($delayed && str_contains($request['message']['topic'], '/concurrent/')) {
                $gate = getcwd() . '/order-gate-' . basename($request['message']['client_id']);
                file_put_contents($gate . '.entered', 'waiting');
                $until = microtime(true) + 3.0;
                while (!is_file($gate . '.released')) {
                    if (microtime(true) >= $until) {
                        return new \Type\Mqtt\CommitResult($request['operation_id'], 'rejected', 0x83);
                    }
                    usleep(10000);
                }
            }
            if ($delayed && str_contains($request['message']['topic'], '/reject/')) {
                usleep(300000);
                return new \Type\Mqtt\CommitResult($request['operation_id'], 'rejected', 0x83);
            }
            if ($delayed && !$after) {
                usleep(300000);
            }
            $result = $store->execute($request);
            if ($subscriptionGate !== '' && $subscriptionAfter) {
                file_put_contents($subscriptionGate . '.entered', 'waiting');
                $subscriptionUntil = microtime(true) + 3.0;
                while (!is_file($subscriptionGate . '.released')) {
                    if (microtime(true) >= $subscriptionUntil) {
                        return new \Type\Mqtt\CommitResult($request['operation_id'], 'unknown', 0x83);
                    }
                    usleep(10000);
                }
            }
            if ($delayed && $after) {
                usleep(300000);
            }
            return $result;
        });
PHP, $source);
}

/** 两条同源/Topic/QoS发布在接管前后受到不同延迟，仍按真实接收顺序交付。 */
function mqttPublicationOrderCases(int $port, ?string $certificate): array
{
    require_once __DIR__ . '/mqtt-retained.php';
    $traces = [];
    foreach ([4, 5] as $version) {
        foreach (['before/1', 'after/1', 'before/2', 'after/2'] as $phase) {
            $sockets = [];
            try {
                $receiver = mqttSocket($port, $certificate);
                $publisher = mqttSocket($port, $certificate);
                $sockets = [$receiver, $publisher];
                $qos = (int) substr($phase, -1);
                $id = 'order-' . $version . '-' . str_replace('/', '-', $phase);
                mqttWrite($receiver, mqttConnect($version, $id . '-r', 0));
                mqttAck($receiver, $version);
                mqttWrite($publisher, mqttConnect($version, $id . '-p', 0));
                mqttAck($publisher, $version);
                $topic = 'example/order-delay/' . $phase . '/' . $version;
                mqttWrite($receiver, mqttSubscription($version, $topic, 1, $version === 5 ? 0x21 : 1));
                expect(mqttRead($receiver) === mqttSubscriptionAck($version, "\x01"), '顺序回归未收到实际SUBACK');
                mqttWrite($publisher, mqttRetainedPacket($version, $topic, 'first', $qos, 1, '', false)
                    . mqttRetainedPacket($version, $topic, 'second', $qos, 2, '', false));
                $trace = ['deliveries' => [], 'acknowledgements' => []];
                for ($index = 0; $index < 2; $index++) {
                    $message = mqttRetainedRead($receiver, $version);
                    expect($message['qos'] === 1 && !$message['duplicate'], '顺序回归出现非首次QoS1交付');
                    $trace['deliveries'][] = $message['payload'];
                    mqttRetainedComplete($receiver, $message);
                }
                for ($index = 0; $index < 2; $index++) {
                    $ack = mqttRead($publisher);
                    expect($ack === mqttPacket($qos === 1 ? 0x40 : 0x50, pack('n', $index + 1)), '同源确认顺序或标识错误');
                    $trace['acknowledgements'][] = bin2hex($ack);
                }
                if ($qos === 2) {
                    foreach ([1, 2] as $identifier) {
                        mqttWrite($publisher, mqttPacket(0x62, pack('n', $identifier)));
                        expect(mqttRead($publisher) === mqttPacket(0x70, pack('n', $identifier)), '顺序回归QoS2没有完成PUBCOMP');
                    }
                }
                expect($trace['deliveries'] === ['first', 'second'], '同源有序Topic在' . $phase . '阶段乱序：' . json_encode($trace, JSON_THROW_ON_ERROR));
                mqttQuiet($receiver);
                mqttQuiet($publisher);
                $traces[$id] = $trace;
            } finally {
                foreach ($sockets as $socket) {
                    if (is_resource($socket)) {
                        fclose($socket);
                    }
                }
            }
        }
    }
    return $traces;
}

/** QoS0保留接管不能被同一来源后续无需存储的发布越过，也不能把后者改成离线持久消息。 */
function mqttVolatileOrderCases(int $port, ?string $certificate, PDO $standby): array
{
    $traces = [];
    foreach ([4, 5] as $version) {
        $sockets = [];
        try {
            $receiver = mqttSocket($port, $certificate);
            $publisher = mqttSocket($port, $certificate);
            $sockets = [$receiver, $publisher];
            $id = 'order-qos0-' . $version . '-' . ($certificate === null ? 'tcp' : 'tls');
            mqttWrite($receiver, mqttConnect($version, $id . '-r', 0));
            mqttAck($receiver, $version);
            mqttWrite($publisher, mqttConnect($version, $id . '-p', 0));
            mqttAck($publisher, $version);
            $topic = 'example/order-delay/qos0/' . $id;
            mqttWrite($receiver, mqttSubscription($version, $topic, 1, $version === 5 ? 0x20 : 0));
            expect(mqttRead($receiver) === mqttSubscriptionAck($version), 'QoS0顺序回归没有SUBACK');
            mqttWrite($publisher, mqttRetainedPacket($version, $topic, 'first', 0) . mqttPublish($version, $topic, 'second'));
            $trace = [];
            for ($index = 0; $index < 2; $index++) {
                $message = mqttRetainedRead($receiver, $version);
                expect($message['qos'] === 0 && !$message['duplicate'], 'QoS0顺序回归改变交付等级');
                $trace[] = $message['payload'];
            }
            expect($trace === ['first', 'second'], 'QoS0保留与普通发布乱序：' . json_encode($trace, JSON_THROW_ON_ERROR));
            mqttQuiet($receiver);
            mqttQuiet($publisher);
            expect((int) $standby->query('SELECT COUNT(*) FROM type_mqtt_messages WHERE topic = ' . $standby->quote($topic)
                . ' AND retain = false')->fetchColumn() === 0, '临时排序把QoS0普通发布改成持久消息');
            $traces[$id] = $trace;
        } finally {
            foreach ($sockets as $socket) {
                if (is_resource($socket)) {
                    fclose($socket);
                }
            }
        }
    }
    return $traces;
}

/** 第一来源停在公开worker入口时，第二来源仍能同步完成；拒绝后续未启动消息无副本。 */
function mqttPublicationQueueCases(int $port, ?string $certificate, string $consumer, PDO $standby): array
{
    $report = [];
    foreach (['concurrent', 'reject'] as $case) {
        $sockets = [];
        $identity = 'queue-' . $case . '-' . ($certificate === null ? 'tcp' : 'tls');
        $gate = $consumer . '/order-gate-' . $identity . '-p';
        try {
            $receiver = mqttSocket($port, $certificate);
            $publisher = mqttSocket($port, $certificate);
            $independent = mqttSocket($port, $certificate);
            $sockets = [$receiver, $publisher, $independent];
            foreach ([$receiver, $publisher, $independent] as $index => $socket) {
                mqttWrite($socket, mqttConnect(5, $identity . ['-r', '-p', '-i'][$index], 0));
                mqttAck($socket, 5);
            }
            $topic = 'example/order-delay/' . $case . '/' . $identity;
            mqttWrite($receiver, mqttSubscription(5, $topic, 1, 0x21));
            expect(mqttRead($receiver) === mqttSubscriptionAck(5, "\x01"), '队列回归没有SUBACK');
            mqttWrite($publisher, mqttReliablePublish(5, $topic, 'first', 1) . mqttReliablePublish(5, $topic, 'second', 2)
                . mqttReliablePublish(5, $topic, 'second', 2, true) . ($case === 'reject' ? mqttPublish(5, $topic, 'volatile-tail') : ''));
            if ($case === 'concurrent') {
                mqttUntil(static fn (): bool => is_file($gate . '.entered'), '第一来源未进入有界暂停');
                mqttQuiet($publisher);
                mqttWrite($independent, mqttReliablePublish(5, $topic, 'independent', 1));
                expect(mqttRead($independent) === "\x40\x02\0\x01", '第二来源未独立完成同步接管');
                $other = mqttRetainedRead($receiver, 5);
                expect($other['payload'] === 'independent', '第一来源暂停阻塞了其他来源');
                mqttRetainedComplete($receiver, $other);
                file_put_contents($gate . '.released', 'continue');
                foreach (['first', 'second'] as $payload) {
                    $message = mqttRetainedRead($receiver, 5);
                    expect($message['payload'] === $payload && !$message['duplicate'], '队列顺序或未完成DUP合并失败');
                    mqttRetainedComplete($receiver, $message);
                }
                expect(mqttRead($publisher) === "\x40\x02\0\x01" && mqttRead($publisher) === "\x40\x02\0\x02", '队列确认未按接收顺序完成');
                mqttQuiet($publisher);
                $report[$case] = 'independent-first-then-source-order-with-queued-duplicate-coalesced';
            } else {
                expect(mqttRead($publisher) === "\xe0\x02\x83\0", '首项拒绝没有关闭来源');
                expect(
                    (int) $standby->query('SELECT COUNT(*) FROM type_mqtt_messages WHERE topic = ' . $standby->quote($topic))->fetchColumn() === 0,
                    '首项拒绝后后继未启动消息仍持久接管'
                );
                $report[$case] = 'following-unstarted-publications-rejected-without-persistence';
            }
            mqttRetainedQuiet($receiver);
            mqttQuiet($independent);
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
    return $report;
}

/** 独立线编码，不借用 Broker 的消息或 PUBACK 实现。 */
function mqttReliablePublish(int $version, string $topic, string $payload, int $identifier, bool $duplicate = false, string $properties = ''): string
{
    return mqttPacket($duplicate ? 0x3a : 0x32, mqttField($topic) . pack('n', $identifier)
        . ($version === 5 ? mqttLength(strlen($properties)) . $properties : '') . $payload);
}

function mqttUntil(Closure $condition, string $failure, float $seconds = 6.0): mixed
{
    $until = microtime(true) + $seconds;
    do {
        $result = $condition();
        if ($result) {
            return $result;
        }
        usleep(10000);
    } while (microtime(true) < $until);
    throw new RuntimeException($failure);
}

/** 同时覆盖两个网络 tick 与 worker 建连间隙；一次零计数不足以证明异步系统已空闲。 */
function mqttDatabaseIdle(PDO $database): void
{
    $until = microtime(true) + 8.0;
    $stable = microtime(true) + 0.3;
    do {
        if ((int) $database->query("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name LIKE 'type_mqtt_%'")->fetchColumn() > 0) {
            $stable = microtime(true) + 0.3;
        } elseif (microtime(true) >= $stable) {
            return;
        }
        usleep(10000);
    } while (microtime(true) < $until);
    throw new RuntimeException('MQTT 空闲期仍有数据库租约');
}

/** 独立安装的真实同步统计入口，验证Patroni单备库写法及明确节点选择；不替代选主和旧主隔离。 */
function mqttSynchronousSelection(string $consumer, array $command, array $environment, NativeDatabase $database, array $tools, PDO $primary): array
{
    $report = [];
    $second = null;
    $select = static function (string $selection) use ($primary): void {
        $primary->exec('ALTER SYSTEM SET synchronous_standby_names = ' . $primary->quote($selection));
        $primary->query('SELECT pg_reload_conf()')->fetchColumn();
        mqttUntil(static fn (): bool => $primary->query('SHOW synchronous_standby_names')->fetchColumn() === $selection, '同步选择配置未在预算内生效');
    };
    $observe = static function (string $names, bool $committed, ?string $replica) use ($consumer, $command, $environment): array {
        $process = new Process([...$command, '--store-statistics'], $consumer, array_replace($environment, ['MQTT_STANDBY' => $names]));
        try {
            $result = $process->wait(12);
            expect($result->successful(), '同步统计入口异常：' . $result->stderr);
            $proof = json_decode(trim($result->stdout), true, 32, JSON_THROW_ON_ERROR);
            expect(($proof['state'] === 'committed') === $committed && $proof['released'], '同步选择与持久证明不符：' . $result->stdout);
            if ($committed) {
                expect($proof['proof']['wal_lsn'] !== '' && count($proof['proof']['replicas']) === 1
                    && $proof['proof']['replicas'][0]['application_name'] === $replica
                    && $proof['proof']['replicas'][0]['proven'], '不能将配置或主库可见性作为指定备库持久证明');
            }
            return ['state' => $proof['state'], 'released' => $proof['released'], 'proof' => $proof['proof']];
        } finally {
            $process->stop();
        }
    };
    try {
        foreach (['FIRST 1 (iot_sync)', 'iot_sync', '"iot_sync"', '1 ("iot_sync")'] as $selection) {
            $select($selection);
            $report[$selection] = $observe('iot_sync,iot_next', true, 'iot_sync');
        }
        foreach (['*', '', 'ANY 1 (iot_sync)', 'FIRST 2 (iot_sync,iot_next)', 'iot_sync,iot_next'] as $selection) {
            $select($selection);
            $report['rejected:' . $selection] = $observe('iot_sync,iot_next', false, null);
        }
        $select('iot_sync');
        $report['untrusted-current-node'] = $observe('iot_next', false, null);
        $second = new PostgresSync($database, $consumer . '/selection-standby', $tools, 'iot_next');
        $select('iot_next');
        $report['changed-current-node'] = $observe('iot_sync,iot_next', true, 'iot_next');
        $report['old-fixed-node-rejected'] = $observe('iot_sync', false, null);
        $second->stopStandby();
        $report['no-synchronous-candidate'] = $observe('iot_sync,iot_next', false, null);
        $second->restartStandby();
        $report['candidate-restored'] = $observe('iot_sync,iot_next', true, 'iot_next');
        $select('FIRST 1 (iot_sync)');
        $report['original-node-restored'] = $observe('iot_sync', true, 'iot_sync');
        mqttDatabaseIdle($primary);
        return $report;
    } finally {
        $select('FIRST 1 (iot_sync)');
        $second?->close();
    }
}

/** 独立安装应用、实际 MQTT socket 与真正物理同步备库交叉观察提交时序。 */
function mqttQos1Cases(string $root, string $consumer, array $command, array $workerCommand, array $environment): array
{
    // 异常栈不捕获 Closure/PDO 或环境参数，避免失败报告泄漏凭据或延长测试连接生命期。
    ini_set('zend.exception_ignore_args', '1');
    require_once $root . '/tests/native-database.php';
    require_once $root . '/tests/postgres-sync.php';
    $tools = NativeDatabase::tools('pgsql', (string) (getenv('TYPE_PGSQL_TOOLS') ?: $root . '/.cache/macos-libpq/17.11'));
    $database = new NativeDatabase($consumer . '/primary', 'pgsql', $tools);
    $sync = null;
    $process = null;
    $primary = null;
    $standby = null;
    $sockets = [];
    $report = [];
    try {
        $sync = new PostgresSync($database, $consumer . '/standby', $tools);
        $environment = array_replace($environment, $database->environment());
        $environment['MQTT_CERTIFICATE'] = '';
        $environment['MQTT_PRIVATE_KEY'] = '';
        $environment['MQTT_WORKER_COMMAND'] = json_encode($workerCommand, JSON_THROW_ON_ERROR);
        if (in_array('--qos2', $_SERVER['argv'], true)) {
            require_once $root . '/tests/mqtt-qos2.php';
            $report['qos2-migration'] = mqttQos2Migration($sync->connection());
        }
        $install = new Process([...$command, '--install-store'], $consumer, $environment);
        try {
            $installed = $install->wait(10);
            expect($installed->successful() && $installed->stderr === '', 'MQTT 存储安装入口失败：' . $installed->stderr);
            $proof = json_decode(trim($installed->stdout), true, 32, JSON_THROW_ON_ERROR);
            expect($proof['state'] === 'committed' && $proof['released'] && $proof['proof']['wal_lsn'] !== '', '安装没有同步证明');
            $report['install'] = $proof;
        } finally {
            $install->stop();
        }
        if (in_array('--qos2', $_SERVER['argv'], true)) {
            $reinstall = new Process([...$command, '--install-store'], $consumer, $environment);
            try {
                $reinstalled = $reinstall->wait(10);
                expect($reinstalled->successful() && $reinstalled->stderr === '', '重复迁移失败');
                $reinstallProof = json_decode(trim($reinstalled->stdout), true, 32, JSON_THROW_ON_ERROR);
                expect($reinstallProof['state'] === 'committed' && $reinstallProof['released'], '重复迁移缺少同步证明');
                $report['qos2-migration']['reinstall'] = 'committed';
            } finally {
                $reinstall->stop();
            }
        }
        $primary = $sync->connection();
        $standby = $sync->standby();
        if (in_array('--commit-lifecycle-only', $_SERVER['argv'], true)) {
            $report['commit-lifecycle'] = mqttCommitLifecycleCases($consumer, $command, $environment);
            return $report;
        }
        if (in_array('--sync-configuration', $_SERVER['argv'], true)) {
            $report['synchronous-selection'] = mqttSynchronousSelection($consumer, $command, $environment, $database, $tools, $primary);
            return $report;
        }
        $listener = stream_socket_server('tcp://127.0.0.1:0', $number, $error);
        $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        fclose($listener);
        $process = new Process([...$command, '--plaintext', '--port=' . $port], $consumer, $environment);
        mqttUntil(function () use ($port, $process): bool {
            expect($process->running(), 'QoS 1 服务提前退出：' . $process->stderr());
            $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $number, $error, 0.1);
            if ($probe === false) {
                return false;
            }
            fclose($probe);
            return true;
        }, 'QoS 1 服务未就绪');
        $cases = 0;
        if (in_array('--retained', $_SERVER['argv'], true)) {
            require_once __DIR__ . '/mqtt-retained.php';
            $report['retained-snapshot'] = mqttRetainedSnapshotCases($port, $consumer, $primary);
            if (in_array('--retained-snapshot-only', $_SERVER['argv'], true)) {
                mqttDatabaseIdle($primary);
                $stopped = $process->stop(12);
                expect($stopped->successful() && $stopped->stderr === '', '保留快照进程未正常结束：' . $stopped->stderr);
                $report['statistics'] = json_decode(trim($stopped->stdout), true, 32, JSON_THROW_ON_ERROR);
                foreach (['connections', 'pendingCommits', 'quarantinedCommits', 'closingSessions', 'retainedQueued', 'retainedPending', 'retainedBytes'] as $field) {
                    expect($report['statistics'][$field] === 0, '保留快照退出资源未清理：' . $field);
                }
                $report['retained']['capacity'] = mqttRetainedLimits($consumer, $command, $environment, $port, $primary, $standby);
                return $report;
            }
        }
        foreach ([4, 5] as $version) {
            $subscriber = mqttSocket($port);
            $publisher = mqttSocket($port);
            array_push($sockets, $subscriber, $publisher);
            mqttWrite($subscriber, mqttConnect($version, 'reliable-subscriber-' . $version));
            mqttWrite($publisher, mqttConnect($version, 'reliable-publisher-' . $version));
            $connack = mqttAck($subscriber, $version);
            mqttAck($publisher, $version);
            expect($version === 4 || !str_contains($connack, "\x24\x01"), 'QoS 2 可用时仍错误声明 Maximum QoS 1');
            $topic = 'example/reliable/' . $version;
            mqttWrite($subscriber, mqttSubscription($version, $topic, 1, 1));
            expect(mqttRead($subscriber) === mqttSubscriptionAck($version, "\x01"), '订阅没有授予 QoS 1');
            $payload = "\0\xff\xed\xa0\x80" . str_repeat('binary', 1000);
            mqttWrite($publisher, mqttReliablePublish($version, $topic, $payload, 7));
            expect(mqttRead($publisher) === "\x40\x02\0\x07", '没有成功 PUBACK');
            $firstDelivery = mqttRead($subscriber);
            expect($firstDelivery === mqttReliablePublish($version, $topic, $payload, 1), '发送标识或二进制改变：' . strlen($firstDelivery) . ':' . bin2hex(substr($firstDelivery, 0, 64)));
            $row = $standby->query("SELECT encode(payload, 'hex') AS payload, state FROM type_mqtt_messages WHERE topic = '" . $topic . "'")->fetch(PDO::FETCH_ASSOC);
            expect($row['payload'] === bin2hex($payload) && $row['state'] === 'accepted', 'PUBACK 前没有同步接管原件');
            mqttQuiet($subscriber);
            mqttWrite($subscriber, "\x40\x02\0\x01");
            mqttUntil(fn (): bool => (int) $standby->query("SELECT COUNT(*) FROM type_mqtt_deliveries WHERE client_id = 'reliable-subscriber-" . $version . "' AND state = 'acknowledged'")->fetchColumn() === 1, 'PUBACK 终结没有同步落地');
            // 已完成交换之后，DUP=1 的相同标识也必须视为新消息。
            mqttWrite($publisher, mqttReliablePublish($version, $topic, $payload, 7, true));
            expect(mqttRead($publisher) === "\x40\x02\0\x07", '相同标识不能用于新消息');
            expect(mqttRead($subscriber) === mqttReliablePublish($version, $topic, $payload, 2), '已完成标识被永久去重');
            mqttWrite($subscriber, $version === 5 ? "\x40\x04\0\x02\x80\0" : "\x40\x02\0\x02");
            mqttUntil(fn (): bool => (int) $standby->query("SELECT COUNT(*) FROM type_mqtt_messages WHERE topic = '" . $topic . "' AND state = 'complete'")->fetchColumn() === 2, '新消息与负 PUBACK 没有终结');
            mqttQuiet($subscriber);
            $cases += 6;
            fclose($publisher);
            fclose($subscriber);
        }
        $report['publication-order'] = mqttPublicationOrderCases($port, null);
        $report['volatile-order'] = mqttVolatileOrderCases($port, null, $standby);
        $report['publication-queue'] = mqttPublicationQueueCases($port, null, $consumer, $standby);
        $report['standard-client'] = trim(successful(['node', $root . '/tests/mqtt-standard-client.mjs', $consumer, (string) $port, 'plain', 'qos1'], $consumer));
        mqttDatabaseIdle($primary);
        $window = mqttSocket($port);
        $windowPublisher = mqttSocket($port);
        array_push($sockets, $window, $windowPublisher);
        mqttWrite($window, mqttConnect(5, 'receive-window', 10, "\x21\0\x01"));
        mqttWrite($windowPublisher, mqttConnect(5, 'window-publisher'));
        mqttAck($window, 5);
        mqttAck($windowPublisher, 5);
        mqttWrite($window, mqttSubscription(5, 'example/window', 1, 1));
        expect(mqttRead($window) === mqttSubscriptionAck(5, "\x01"), '接收窗口订阅失败');
        mqttWrite($windowPublisher, mqttReliablePublish(5, 'example/window', 'first', 1));
        expect(mqttRead($windowPublisher) === "\x40\x02\0\x01", '窗口首条消息失败');
        expect(mqttRead($window) === mqttReliablePublish(5, 'example/window', 'first', 1), '窗口首条交付错误');
        mqttWrite($windowPublisher, mqttReliablePublish(5, 'example/window', 'second', 2));
        expect(mqttRead($windowPublisher) === "\xe0\x02\x97\0", '超过 Receive Maximum 仍接管消息');
        mqttQuiet($window);
        expect((int) $standby->query("SELECT COUNT(*) FROM type_mqtt_messages WHERE topic = 'example/window'")->fetchColumn() === 1, '拒绝后仍写入第二条');
        mqttWrite($window, "\x40\x02\0\x01");
        mqttUntil(fn (): bool => $standby->query("SELECT state FROM type_mqtt_messages WHERE topic = 'example/window'")->fetchColumn() === 'complete', '接收窗口未持久释放');
        fclose($windowPublisher);
        fclose($window);
        $report['receive-maximum'] = 'one-inflight-then-rejected-without-write';
        $cases += 3;
        mqttDatabaseIdle($primary);
        $lower = mqttSocket($port);
        $lowerPublisher = mqttSocket($port);
        array_push($sockets, $lower, $lowerPublisher);
        mqttWrite($lower, mqttConnect(4, 'lower-qos-subscriber'));
        mqttWrite($lowerPublisher, mqttConnect(5, 'lower-qos-publisher'));
        mqttAck($lower, 4);
        mqttAck($lowerPublisher, 5);
        mqttWrite($lower, mqttSubscription(4, 'example/lower', 1, 0));
        expect(mqttRead($lower) === mqttSubscriptionAck(4), 'QoS 0 订阅被强制升级');
        mqttWrite($lowerPublisher, mqttReliablePublish(5, 'example/lower', "\0\xff", 1));
        expect(mqttRead($lowerPublisher) === "\x40\x02\0\x01", '降级订阅使发布者失去持久确认');
        expect(mqttRead($lower) === mqttPublish(4, 'example/lower', "\0\xff"), '交付 QoS 没有取订阅上限');
        mqttUntil(fn (): bool => (int) $standby->query("SELECT COUNT(*) FROM type_mqtt_deliveries WHERE client_id = 'lower-qos-subscriber' AND state = 'queued'")->fetchColumn() === 1, 'QoS 0 排队被伪记为接收者 ACK');
        fclose($lowerPublisher);
        fclose($lower);
        $report['lower-qos'] = 'durable-inbound-with-qos0-queue-fact';
        $cases += 2;
        mqttDatabaseIdle($primary);
        $expiring = mqttSocket($port);
        $expiringPublisher = mqttSocket($port);
        array_push($sockets, $expiring, $expiringPublisher);
        mqttWrite($expiring, mqttConnect(5, 'expiry-subscriber'));
        mqttWrite($expiringPublisher, mqttConnect(5, 'expiry-publisher'));
        mqttAck($expiring, 5);
        mqttAck($expiringPublisher, 5);
        mqttWrite($expiring, mqttSubscription(5, 'example/expiry', 1, 1));
        expect(mqttRead($expiring) === mqttSubscriptionAck(5, "\x01"), '过期订阅失败');
        mqttDatabaseIdle($primary);
        $standby->query('SELECT pg_wal_replay_pause()')->fetchColumn();
        mqttWrite($expiringPublisher, mqttReliablePublish(5, 'example/expiry', 'expires-in-commit', 1, false, "\x02\0\0\0\x01"));
        mqttUntil(fn (): mixed => $primary->query("SELECT pid FROM pg_stat_activity WHERE application_name LIKE 'type_mqtt_%' AND wait_event = 'SyncRep'")->fetchColumn(), '过期测试没有真实提交等待');
        usleep(1200000);
        $standby->query('SELECT pg_wal_replay_resume()')->fetchColumn();
        expect(mqttRead($expiringPublisher) === "\x40\x02\0\x01", '合法到期消息没有完成同步接管');
        mqttQuiet($expiring);
        mqttUntil(fn (): bool => (int) $standby->query("SELECT COUNT(*) FROM type_mqtt_deliveries WHERE client_id = 'expiry-subscriber' AND state = 'expired'")->fetchColumn() === 1, '过期交付没有保留终结事实');
        fclose($expiringPublisher);
        fclose($expiring);
        $report['expiry'] = 'expired-during-commit-without-delivery';
        $cases += 2;
        mqttDatabaseIdle($primary);
        $before = mqttSocket($port);
        $sockets[] = $before;
        mqttWrite($before, mqttConnect(5, 'before-commit'));
        mqttAck($before, 5);
        $primary->beginTransaction();
        $primary->query('SELECT pg_advisory_xact_lock(1954115693, 1)')->fetchColumn();
        mqttWrite($before, mqttReliablePublish(5, 'example/before-commit', 'must-rollback', 11));
        expect(mqttRead($before) === "\xe0\x02\x83\0", '提交前故障未返回标准 Implementation specific error');
        $primary->rollBack();
        expect((int) $primary->query("SELECT COUNT(*) FROM type_mqtt_messages WHERE topic = 'example/before-commit'")->fetchColumn() === 0, '提交前故障留下消息');
        $report['before-commit'] = 'rolled-back-without-puback';
        $cases++;
        fclose($before);
        // 应用不读取 PUBACK 就断开；新清洁连接重发属于新交换，允许业务收到重复。
        $lost = mqttSocket($port);
        $sockets[] = $lost;
        mqttWrite($lost, mqttConnect(5, 'ack-lost'));
        mqttAck($lost, 5);
        mqttWrite($lost, mqttReliablePublish(5, 'example/ack-lost', 'again', 21));
        mqttUntil(fn (): bool => (int) $standby->query("SELECT COUNT(*) FROM type_mqtt_messages WHERE topic = 'example/ack-lost'")->fetchColumn() === 1, '确认丢失前未同步提交');
        fclose($lost);
        $retried = mqttSocket($port);
        $sockets[] = $retried;
        mqttWrite($retried, mqttConnect(5, 'ack-lost'));
        mqttAck($retried, 5);
        mqttWrite($retried, mqttReliablePublish(5, 'example/ack-lost', 'again', 21, true));
        expect(mqttRead($retried) === "\x40\x02\0\x15", '确认丢失后新清洁交换失败');
        expect((int) $standby->query("SELECT COUNT(*) FROM type_mqtt_messages WHERE topic = 'example/ack-lost'")->fetchColumn() === 2, '错误宣称业务恰好一次');
        fclose($retried);
        $report['ack-lost'] = 'two-distinct-clean-session-messages-preserved';
        $cases++;
        foreach ([true, false] as $cancelCommit) {
            mqttDatabaseIdle($primary);
            $unknown = mqttSocket($port);
            $sockets[] = $unknown;
            stream_set_timeout($unknown, 12);
            $topic = $cancelCommit ? 'example/unknown-cancel' : 'example/unknown-timeout';
            mqttWrite($unknown, mqttConnect(5, $cancelCommit ? 'unknown-cancel' : 'unknown-timeout'));
            mqttAck($unknown, 5);
            $standby->query('SELECT pg_wal_replay_pause()')->fetchColumn();
            mqttWrite($unknown, mqttReliablePublish(5, $topic, 'local-visible-is-insufficient', 31));
            $backend = mqttUntil(fn (): mixed => $primary->query("SELECT pid FROM pg_stat_activity WHERE application_name LIKE 'type_mqtt_%' AND wait_event = 'SyncRep'")->fetchColumn(), '未观察到 SyncRep 等待');
            mqttWrite($unknown, mqttReliablePublish(5, $topic, 'local-visible-is-insufficient', 31, true));
            mqttWrite($unknown, mqttReliablePublish(5, $topic, 'queued-never-started', 32));
            mqttQuiet($unknown);
            expect((int) $primary->query("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name LIKE 'type_mqtt_%' AND wait_event = 'SyncRep'")->fetchColumn() === 1, '当前 pending DUP 派生了额外提交');
            expect((int) $standby->query("SELECT COUNT(*) FROM type_mqtt_messages WHERE topic = '" . $topic . "'")->fetchColumn() === 0, '暂停回放夹具失效');
            if ($cancelCommit) {
                $primary->query('SELECT pg_cancel_backend(' . (int) $backend . ')')->fetchColumn();
            }
            $started = microtime(true);
            expect(mqttRead($unknown) === "\xe0\x02\x83\0", '未知同步结果未返回标准 Implementation specific error');
            $elapsed = microtime(true) - $started;
            expect($elapsed < 11.0, '持久等待或后端清理失去截止');
            mqttUntil(fn (): bool => (int) $primary->query('SELECT COUNT(*) FROM pg_stat_activity WHERE pid = ' . (int) $backend)->fetchColumn() === 0, '超时遗留同步后端');
            $unknownMessages = $primary->query("SELECT packet_id, state FROM type_mqtt_messages WHERE topic = '" . $topic . "' ORDER BY packet_id")->fetchAll(PDO::FETCH_ASSOC);
            expect(count($unknownMessages) === 1, '未知提交被删除或重试：' . $topic . ':' . json_encode($unknownMessages, JSON_THROW_ON_ERROR));
            $standby->query('SELECT pg_wal_replay_resume()')->fetchColumn();
            mqttUntil(fn (): bool => (int) $standby->query("SELECT COUNT(*) FROM type_mqtt_messages WHERE topic = '" . $topic . "'")->fetchColumn() === 1, '恢复后未知事实丢失');
            $report[$cancelCommit ? 'cancelled-syncrep' : 'worker-deadline'] = ['local-visible' => true, 'puback' => false,
                'queued-following-written' => false, 'backend-released' => true, 'seconds' => $elapsed];
            $cases += 4;
            fclose($unknown);
        }
        $unavailable = mqttSocket($port);
        $sockets[] = $unavailable;
        mqttWrite($unavailable, mqttConnect(5, 'standby-unavailable'));
        mqttAck($unavailable, 5);
        $standby = null;
        $sync->stopStandby();
        mqttWrite($unavailable, mqttReliablePublish(5, 'example/no-standby', 'cannot-own', 41));
        expect(mqttRead($unavailable) === "\xe0\x02\x83\0", '缺同步备库未返回标准 Implementation specific error');
        expect((int) $primary->query("SELECT COUNT(*) FROM type_mqtt_messages WHERE topic = 'example/no-standby'")->fetchColumn() === 0, '前置条件失败仍写入');
        fclose($unavailable);
        $standby = null;
        $sync->restartStandby();
        $report['standby-unavailable'] = 'rejected-before-write';
        $cases++;
        $standby = $sync->standby();
        if (in_array('--qos2', $_SERVER['argv'], true)) {
            $report['qos2'] = mqttQos2Cases($port, $primary, $standby);
            $report['qos2-standard-client'] = trim(successful(['node', $root . '/tests/mqtt-standard-client.mjs', $consumer, (string) $port, 'plain', 'qos2'], $consumer));
        }
        if (in_array('--retained', $_SERVER['argv'], true)) {
            require_once $root . '/tests/mqtt-retained.php';
            $report['retained'] = mqttRetainedCases($port, $primary, $standby);
            $report['retained']['standard-client'] = trim(successful(['node', $root . '/tests/mqtt-standard-client.mjs', $consumer, (string) $port, 'plain', 'retained'], $consumer));
        }
        mqttUntil(fn (): bool => (int) $primary->query("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name LIKE 'type_mqtt_%'")->fetchColumn() === 0, '结束时遗留持久 worker 后端');
        $result = $process->stop(12);
        expect($result->successful() && $result->stderr === '', 'QoS 1 进程停止失败：' . $result->stderr);
        $statistics = json_decode(trim($result->stdout), true, 32, JSON_THROW_ON_ERROR);
        expect($statistics['connections'] === 0 && $statistics['pendingCommits'] === 0 && $statistics['quarantinedCommits'] === 0
            && $statistics['closingSessions'] === 0 && $statistics['unknownCommits'] >= 2 && $statistics['rejectedCommits'] >= 2, '资源回收或故障统计不符');
        $report['wire-cases'] = $cases;
        $report['statistics'] = $statistics;
        if (in_array('--retained', $_SERVER['argv'], true)) {
            $reinstall = new Process([...$command, '--install-store'], $consumer, $environment);
            try {
                $result = $reinstall->wait(10);
                $proof = json_decode(trim($result->stdout), true, 32, JSON_THROW_ON_ERROR);
                expect($result->successful() && $result->stderr === '' && $proof['state'] === 'committed' && $proof['released'], '保留事实存在时重复迁移失败');
            } finally {
                $reinstall->stop();
            }
        }
        $environment['MQTT_CERTIFICATE'] = $consumer . '/certificate.pem';
        $environment['MQTT_PRIVATE_KEY'] = $consumer . '/private.pem';
        $process = new Process([...$command, '--port=' . $port], $consumer, $environment);
        mqttUntil(function () use ($port, $process, $consumer): bool {
            expect($process->running(), 'QoS 1 TLS 服务提前退出：' . $process->stderr());
            try {
                $probe = mqttSocket($port, $consumer . '/certificate.pem');
                mqttWrite($probe, mqttConnect(5, 'qos1-tls-ready'));
                mqttAck($probe, 5);
                fclose($probe);
                return true;
            } catch (RuntimeException) {
                return false;
            }
        }, 'QoS 1 TLS 服务未就绪');
        if (in_array('--retained', $_SERVER['argv'], true)) {
            mqttRetainedRestart($port, $consumer . '/certificate.pem');
            $report['retained']['restart'] = 'preserved-with-qos2-handshake-over-tls';
            $report['retained']['tls-standard-client'] = trim(successful(['node', $root . '/tests/mqtt-standard-client.mjs', $consumer, (string) $port, $consumer . '/certificate.pem', 'retained'], $consumer));
        }
        $report['tls-publication-order'] = mqttPublicationOrderCases($port, $consumer . '/certificate.pem');
        $report['tls-volatile-order'] = mqttVolatileOrderCases($port, $consumer . '/certificate.pem', $standby);
        $report['tls-publication-queue'] = mqttPublicationQueueCases($port, $consumer . '/certificate.pem', $consumer, $standby);
        $report['tls-standard-client'] = trim(successful(['node', $root . '/tests/mqtt-standard-client.mjs', $consumer, (string) $port, $consumer . '/certificate.pem', 'qos1'], $consumer));
        if (in_array('--qos2', $_SERVER['argv'], true)) {
            $report['qos2-tls-standard-client'] = trim(successful(['node', $root . '/tests/mqtt-standard-client.mjs', $consumer, (string) $port, $consumer . '/certificate.pem', 'qos2'], $consumer));
        }
        mqttDatabaseIdle($primary);
        $tlsResult = $process->stop(12);
        expect($tlsResult->successful() && $tlsResult->stderr === '', 'QoS 1 TLS 进程停止失败：' . $tlsResult->stderr);
        $tlsStatistics = json_decode(trim($tlsResult->stdout), true, 32, JSON_THROW_ON_ERROR);
        expect($tlsStatistics['durableCommits'] > 0 && $tlsStatistics['connections'] === 0 && $tlsStatistics['pendingCommits'] === 0
            && $tlsStatistics['quarantinedCommits'] === 0 && $tlsStatistics['closingSessions'] === 0, 'QoS 1 TLS 资源未回收');
        $report['tls-statistics'] = $tlsStatistics;
        if (in_array('--retained', $_SERVER['argv'], true)) {
            $report['retained']['capacity'] = mqttRetainedLimits($consumer, $command, $environment, $port, $primary, $standby);
            foreach ([$statistics, $tlsStatistics] as $retainedStatistics) {
                expect($retainedStatistics['retainedQueued'] === 0 && $retainedStatistics['retainedPending'] === 0 && $retainedStatistics['retainedBytes'] === 0, '保留资源没有归零');
            }
        }
        $report['replication'] = $sync->evidence();
        return $report;
    } finally {
        foreach ($sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        if ($primary !== null && $primary->inTransaction()) {
            $primary->rollBack();
        }
        if ($standby !== null) {
            try {
                $standby->query('SELECT pg_wal_replay_resume()')->fetchColumn();
            } catch (Throwable) {
            }
        }
        $process?->stop(12);
        $primary = null;
        $standby = null;
        try {
            $sync?->close();
        } finally {
            $database->close();
        }
    }
}
