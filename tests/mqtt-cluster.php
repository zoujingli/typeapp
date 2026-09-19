<?php

declare(strict_types=1);

use Type\Testing\Process;

/** 独立安装的PendingCommit只负责受控IPC；实际存储动作仍由本轮PHP/原生worker执行。 */
function mqttClusterRequest(string $consumer, array $workerCommand, array $environment, array $request): array
{
    $program = 'require $argv[1]; $p = new Type\\Mqtt\\PendingCommit(json_decode($argv[2], true), json_decode($argv[3], true)); '
        . 'do { $r = $p->poll(); if ($r === null) usleep(10000); } while ($r === null); echo json_encode($r->data(), JSON_THROW_ON_ERROR);';
    $request['operation_id'] = bin2hex(random_bytes(16));
    $result = (new Process([PHP_BINARY, '-r', $program, '--', $consumer . '/vendor/autoload.php', json_encode($workerCommand, JSON_THROW_ON_ERROR),
        json_encode($request, JSON_THROW_ON_ERROR)], $consumer, $environment))->wait(12);
    expect($result->successful() && $result->stderr === '', '集群公开worker请求失败：' . $result->stderr);
    return json_decode($result->stdout, true, 32, JSON_THROW_ON_ERROR);
}

/** 真实备库暂停使原隔离提交未知；恢复后只能按原动作对账，不能重发隔离或把本机可见当成成功。 */
function mqttClusterFenceUnknownCase(PostgresSync $sync, string $consumer, array $workerCommand, array $environment): array
{
    $primary = $sync->connection();
    $standby = $sync->standby();
    $target = $standby->query("SELECT run_id, generation, state FROM type_mqtt_nodes WHERE node_id = 'beta'")->fetch(PDO::FETCH_ASSOC);
    expect(is_array($target) && $target['state'] === 'fenced', '未知隔离回归仅可使用已经结束的本轮节点');
    $actionId = bin2hex(random_bytes(16));
    $request = ['action' => 'node_fence', 'action_operation_id' => $actionId, 'node_id' => 'beta', 'node_run_id' => $target['run_id'],
        'generation' => (int) $target['generation'], 'observation_run' => '', 'actor' => 'cluster-test',
        'proof_ref' => 'sha256:' . hash('sha256', 'owned-node-stopped-before-replication-fault')];
    $query = array_replace($request, ['action' => 'node_fence_result']);
    $missing = mqttClusterRequest($consumer, $workerCommand, $environment, $query);
    expect($missing['state'] === 'committed' && $missing['released'] && ($missing['value']['fenced'] ?? null) === false
        && $missing['value']['operation_id'] === $actionId, '原事实缺失只能返回未找到，不能伪造隔离或判定原动作失败');
    $paused = false;
    try {
        $standby->query('SELECT pg_wal_replay_pause()')->fetchColumn();
        $paused = true;
        $unknown = mqttClusterRequest($consumer, $workerCommand, $environment, $request);
        expect($unknown['state'] === 'unknown' && $unknown['value'] === [], '备库未回放时原动作不能返回成功事实');
        // 只记录主库本地可见性；它不是对外隔离成功的证据。
        $local = $primary->query('SELECT * FROM type_mqtt_node_audit WHERE operation_id = ' . $primary->quote($actionId))->fetch(PDO::FETCH_ASSOC);
        expect(is_array($local), '未形成真实本地已提交但同步结果未知的故障窗口');
    } finally {
        if ($paused) {
            $standby->query('SELECT pg_wal_replay_resume()')->fetchColumn();
        }
        expect($standby->query('SELECT pg_is_wal_replay_paused()')->fetchColumn() === false, '未知隔离回归未恢复备库回放');
    }
    $query['origin_request_id'] = $unknown['operation_id'];
    $resolved = mqttClusterRequest($consumer, $workerCommand, $environment, $query);
    expect($resolved['state'] === 'committed' && $resolved['released'] && ($resolved['value']['origin_released'] ?? false)
        && ($resolved['value']['fenced'] ?? false) && $resolved['value']['operation_id'] === $actionId
        && $resolved['proof']['wal_lsn'] !== '' && $resolved['proof']['replicas'] !== [], '未知动作恢复必须取得同步证明及原后端释放事实');
    $repeated = mqttClusterRequest($consumer, $workerCommand, $environment, $query);
    expect($repeated['state'] === 'committed' && $repeated['released'] && $repeated['value'] === $resolved['value']
        && $repeated['operation_id'] !== $resolved['operation_id'] && $resolved['operation_id'] !== $unknown['operation_id'], '原动作对账必须复用事实并独立分配传输身份');
    expect(
        $standby->query('SELECT * FROM type_mqtt_node_audit WHERE operation_id = ' . $standby->quote($actionId))->fetch(PDO::FETCH_ASSOC) === $local,
        '未知恢复或重复对账修改了原隔离事实及时间'
    );
    return ['operation_id' => $actionId, 'missing_fenced' => false, 'initial_state' => $unknown['state'], 'resolved_state' => $resolved['state'],
        'origin_released' => $resolved['value']['origin_released'], 'fact_unchanged' => true, 'standby_replay_resumed' => true];
}

function mqttClusterPort(): int
{
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect(is_resource($listener), '无法准备集群隔离端口');
    $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
    fclose($listener);
    return $port;
}

/** 关闭前已交给网络的拒绝/断开报文可以被读取；旧PUBLISH、PINGRESP或成功CONNACK不能出现。 */
function mqttClusterClosed(mixed $socket, bool $opening = false): void
{
    $packet = mqttRead($socket);
    if ($packet !== '') {
        expect(
            ord($packet[0]) === 0xe0 || ($opening && ord($packet[0]) === 0x20 && ord($packet[3]) !== 0),
            '旧节点在接管后仍输出有效协议动作：' . bin2hex($packet)
        );
        expect(mqttRead($socket) === '', '拒绝之后旧连接仍然开放');
    }
}

/** 普通发布只确认相应入站交换，QoS0没有虚构ACK；报文由独立编码器形成。 */
function mqttClusterPublish(mixed $publisher, int $version, string $topic, string $payload, int $qos, string $properties = '', bool $retain = false): void
{
    mqttWrite($publisher, mqttRetainedPacket($version, $topic, $payload, $qos, 17, $properties, $retain));
    if ($qos > 0) {
        try {
            $response = mqttRead($publisher);
        } catch (RuntimeException $error) {
            throw new RuntimeException('跨节点发布确认读取失败：' . $topic . ' qos=' . $qos . ' retain=' . (int) $retain
                . ' metadata=' . json_encode(stream_get_meta_data($publisher)) . ' cause=' . $error->getMessage(), 0, $error);
        }
        expect($response === mqttPacket($qos === 1 ? 0x40 : 0x50, "\0\x11"), '跨节点发布没有成功确认：' . $topic . ' response=' . bin2hex($response));
        if ($qos === 2) {
            mqttWrite($publisher, "\x62\x02\0\x11");
            expect(mqttRead($publisher) === "\x70\x02\0\x11", '跨节点发布没有PUBCOMP');
        }
    }
}

/** 三节点真实普通/共享接收；核对所有发布与订阅QoS组合、二进制及逐跳属性。 */
function mqttClusterRoutingCases(array $ports, ?string $certificate, PDO $standby, array &$sockets): int
{
    $cases = 0;
    foreach ([4, 5] as $version) {
        foreach ([0, 1, 2] as $publishedQos) {
            foreach ([0, 1, 2] as $subscribedQos) {
                $identity = 'route-' . (int) ($certificate !== null) . '-' . $version . '-' . $publishedQos . '-' . $subscribedQos;
                $topic = 'example/' . $identity;
                $publisher = mqttSocket($ports['alpha'], $certificate);
                $ordinary = mqttSocket($ports['beta'], $certificate);
                array_push($sockets, $publisher, $ordinary);
                mqttWrite($publisher, mqttConnect($version, $identity . '-publisher', 0));
                mqttAck($publisher, $version);
                mqttWrite($ordinary, mqttConnect($version, $identity . '-ordinary', 0));
                mqttAck($ordinary, $version);
                $options = $subscribedQos | ($version === 5 ? 8 : 0);
                mqttWrite($ordinary, $version === 5
                    ? mqttPacket(0x82, "\0\x01\x02\x0b\x07" . mqttField($topic) . chr($options))
                    : mqttSubscription($version, $topic, 1, $options));
                expect(mqttRead($ordinary) === mqttSubscriptionAck($version, chr($subscribedQos)), '跨节点普通订阅未安装');
                $members = [];
                if ($version === 5) {
                    foreach (['beta', 'gamma'] as $node) {
                        $member = mqttSocket($ports[$node], $certificate);
                        $members[] = $member;
                        $sockets[] = $member;
                        mqttWrite($member, mqttConnect(5, $identity . '-shared-' . $node, 0));
                        mqttAck($member, 5);
                        mqttWrite($member, mqttSubscription(5, '$share/' . $identity . '/' . $topic, 1, $options));
                        expect(mqttRead($member) === mqttSubscriptionAck(5, chr($subscribedQos)), '跨节点共享成员未安装');
                    }
                }
                $properties = $version === 5 ? "\x03" . mqttField('application/octet-stream') . "\x09" . mqttField("\0\xffcorrelation")
                    . "\x26" . mqttField('route') . mqttField('three-nodes') : '';
                foreach ([false, true] as $retain) {
                    $payload = "\0\xffpayload-" . $identity . '-' . (int) $retain;
                    mqttClusterPublish($publisher, $version, $topic, $payload, $publishedQos, $properties, $retain);
                    $message = mqttRetainedRead($ordinary, $version);
                    expect($message['topic'] === $topic && $message['payload'] === $payload && $message['qos'] === min($publishedQos, $subscribedQos)
                        && !$message['duplicate'] && $message['retain'] === ($version === 5 && $retain)
                        && $message['properties'] === $properties . ($version === 5 ? "\x0b\x07" : ''), '跨节点普通交付改变QoS、RAP、标识或二进制属性');
                    mqttRetainedComplete($ordinary, $message);
                    if ($members !== []) {
                        $read = $members;
                        $write = $except = [];
                        expect(stream_select($read, $write, $except, 8) > 0, '跨节点共享组没有PUBLISH');
                        $chosen = reset($read);
                        $shared = mqttRetainedRead($chosen, 5);
                        expect($shared['topic'] === $topic && $shared['payload'] === $payload && $shared['properties'] === $properties
                            && $shared['qos'] === min($publishedQos, $subscribedQos) && $shared['retain'] === $retain && !$shared['duplicate'], '共享交付跨节点改变内容或QoS');
                        mqttRetainedComplete($chosen, $shared);
                        foreach ($members as $member) {
                            mqttWillSilence($member, 0.3);
                        }
                        $copies = (int) $standby->query('SELECT COUNT(*) FROM type_mqtt_deliveries d JOIN type_mqtt_messages m ON m.id = d.message_id '
                            . 'WHERE m.topic = ' . $standby->quote($topic) . " AND d.group_id <> ''")->fetchColumn();
                        expect($copies === ($retain ? 2 : 1), '节点边界为同一共享组派生多个副本');
                        $cases++;
                    }
                    mqttWillSilence($ordinary, 0.2);
                    $cases++;
                }
                foreach ([$publisher, $ordinary, ...$members] as $socket) {
                    mqttWrite($socket, "\xe0\0");
                    expect(mqttRead($socket) === '', '跨节点路由客户端退出失败');
                    fclose($socket);
                }
            }
        }
    }
    return $cases;
}

/** 路由切点与连接寿命使用真实SUBACK、断线和接管观察，不靠直接改写存储状态。 */
function mqttClusterRouteStateCases(array $ports, ?string $certificate, PDO $standby, array $processes, array &$sockets): int
{
    $cases = 0;
    $suffix = (string) (int) ($certificate !== null);
    $publisher = mqttSocket($ports['alpha'], $certificate);
    $ordinary = mqttSocket($ports['beta'], $certificate);
    array_push($sockets, $publisher, $ordinary);
    mqttWrite($publisher, mqttConnect(5, 'route-options-publisher-' . $suffix, 0));
    mqttAck($publisher, 5);
    mqttWrite($ordinary, mqttConnect(5, 'route-options-ordinary-' . $suffix, 0));
    mqttAck($ordinary, 5);
    $topic = 'example/route-options-' . $suffix;
    mqttWrite($publisher, mqttSubscription(5, $topic, 1, 6));
    expect(mqttRead($publisher) === mqttSubscriptionAck(5, "\x02"), '集群No Local订阅失败');
    mqttWrite($ordinary, mqttSubscription(5, $topic, 1, 1));
    expect(mqttRead($ordinary) === mqttSubscriptionAck(5, "\x01"), '集群替换前订阅失败');
    mqttClusterPublish($publisher, 5, $topic, 'no-local', 1);
    $message = mqttRetainedRead($ordinary, 5);
    expect($message['payload'] === 'no-local' && $message['qos'] === 1, 'No Local误伤远端接收者');
    mqttRetainedComplete($ordinary, $message);
    mqttWillSilence($publisher, 0.3);
    mqttWrite($ordinary, mqttPacket(0x82, "\0\x02\x02\x0b\x09" . mqttField($topic) . "\0"));
    expect(mqttRead($ordinary) === mqttSubscriptionAck(5, "\0", 2), '集群订阅替换失败');
    mqttClusterPublish($publisher, 5, $topic, 'replacement', 2, '', true);
    $message = mqttRetainedRead($ordinary, 5);
    expect($message['payload'] === 'replacement' && $message['qos'] === 0 && !$message['retain'] && $message['properties'] === "\x0b\x09", '跨节点没有使用替换后的QoS、RAP或标识');
    mqttWrite($ordinary, mqttSubscription(5, $topic, 3, 0, false));
    expect(mqttRead($ordinary) === mqttSubscriptionAck(5, "\0", 3, false), '跨节点取消订阅失败');
    mqttClusterPublish($publisher, 5, $topic, 'after-unsubscribe', 1);
    mqttWillSilence($ordinary, 0.4);
    fclose($ordinary);
    fclose($publisher);
    $cases += 3;

    foreach ([4, 5] as $version) {
        $identity = 'route-offline-' . $suffix . '-' . $version;
        $topic = 'example/' . $identity;
        $publisher = mqttSocket($ports['alpha'], $certificate);
        $subscriber = mqttSocket($ports['beta'], $certificate);
        array_push($sockets, $publisher, $subscriber);
        mqttWrite($publisher, mqttConnect($version, $identity . '-publisher', 0));
        mqttAck($publisher, $version);
        mqttWrite($subscriber, mqttSessionConnect($version, $identity));
        mqttSessionAck($subscriber, false);
        mqttWrite($subscriber, mqttSubscription($version, $topic, 1, 2));
        expect(mqttRead($subscriber) === mqttSubscriptionAck($version, "\x02"), '跨节点离线订阅失败');
        $shared = null;
        if ($version === 5) {
            $shared = mqttSocket($ports['gamma'], $certificate);
            $sockets[] = $shared;
            mqttWrite($shared, mqttSessionConnect(5, $identity . '-shared'));
            mqttSessionAck($shared, false);
            mqttWrite($shared, mqttSubscription(5, '$share/' . $identity . '/' . $topic, 1, 2));
            expect(mqttRead($shared) === mqttSubscriptionAck(5, "\x02"), '共享离线订阅失败');
        }
        foreach ($shared === null ? [$subscriber] : [$subscriber, $shared] as $socket) {
            mqttWrite($socket, "\xe0\0");
            expect(mqttRead($socket) === '', '离线用例未完成正常断线');
            fclose($socket);
        }
        mqttUntil(static fn (): bool => (int) $standby->query('SELECT COUNT(*) FROM type_mqtt_sessions WHERE client_id IN ('
            . $standby->quote($identity) . ',' . $standby->quote($identity . '-shared') . ') AND owner_id IS NOT NULL')->fetchColumn() === 0, '接收会话没有同步离线');
        mqttClusterPublish($publisher, $version, $topic, 'volatile-offline', 0);
        mqttUntil(static fn (): bool => (int) $standby->query('SELECT COUNT(*) FROM type_mqtt_messages WHERE topic = ' . $standby->quote($topic))->fetchColumn() === 1, 'QoS0跨节点原件没有结束接管');
        expect((int) $standby->query('SELECT COUNT(*) FROM type_mqtt_deliveries d JOIN type_mqtt_messages m ON m.id = d.message_id WHERE m.topic = '
            . $standby->quote($topic))->fetchColumn() === 0, '全员离线仍为QoS0派生副本');
        mqttClusterPublish($publisher, $version, $topic, 'durable-offline', 2);
        $restored = mqttSocket($ports['gamma'], $certificate);
        $sockets[] = $restored;
        mqttWrite($restored, mqttSessionConnect($version, $identity));
        mqttSessionAck($restored, true);
        $message = mqttRetainedRead($restored, $version);
        expect($message['payload'] === 'durable-offline' && $message['qos'] === 2 && !$message['duplicate'], '离线恢复丢失可靠消息或混入QoS0');
        mqttRetainedComplete($restored, $message);
        mqttWillSilence($restored, 0.3);
        if ($version === 5) {
            $shared = mqttSocket($ports['beta'], $certificate);
            $sockets[] = $shared;
            mqttWrite($shared, mqttSessionConnect(5, $identity . '-shared'));
            mqttSessionAck($shared, true);
            $message = mqttRetainedRead($shared, 5);
            expect($message['payload'] === 'durable-offline' && $message['qos'] === 2, '共享离线恢复错误');
            mqttRetainedComplete($shared, $message);
            mqttWillSilence($shared, 0.3);
            fclose($shared);
        }
        fclose($restored);
        fclose($publisher);
        $cases += 2;
    }

    foreach ([0, 1] as $publishedQos) {
        $identity = 'route-volatile-owner-' . $suffix . '-' . $publishedQos;
        $topic = 'example/' . $identity;
        $publisher = mqttSocket($ports['alpha'], $certificate);
        $old = mqttSocket($ports['beta'], $certificate);
        array_push($sockets, $publisher, $old);
        mqttWrite($publisher, mqttConnect(5, $identity . '-publisher', 0));
        mqttAck($publisher, 5);
        mqttWrite($old, mqttSessionConnect(5, $identity));
        mqttSessionAck($old, false);
        mqttWrite($old, mqttSubscription(5, $topic));
        expect(mqttRead($old) === mqttSubscriptionAck(5), 'QoS0接管前订阅失败');
        expect(posix_kill($processes['beta']->pid(), SIGSTOP), '无法暂停QoS0旧接收节点');
        try {
            mqttClusterPublish($publisher, 5, $topic, 'old-owner-only', $publishedQos);
            mqttUntil(static fn (): bool => (int) $standby->query('SELECT COUNT(*) FROM type_mqtt_deliveries d JOIN type_mqtt_messages m ON m.id = d.message_id '
                . 'WHERE m.topic = ' . $standby->quote($topic) . " AND d.state = 'pending' AND d.qos = 0 AND NOT d.started")->fetchColumn() === 1, 'QoS0没有绑定旧在线连接');
            $new = mqttSocket($ports['gamma'], $certificate);
            $sockets[] = $new;
            mqttWrite($new, mqttSessionConnect(5, $identity));
            mqttUntil(static fn (): bool => $standby->query('SELECT node_id FROM type_mqtt_sessions WHERE client_id = ' . $standby->quote($identity))->fetchColumn() === 'gamma', 'QoS0接管意图未同步');
            // 子进程/底层流就绪不等于MQTT数据；只以窗口内实际应用字节或EOF判定屏障失效。
            stream_set_blocking($new, false);
            $until = microtime(true) + 0.2;
            do {
                $early = fread($new, 65536);
                expect(is_string($early) && $early === '' && !feof($new), 'QoS0接管屏障内存在应用字节或关闭：' . $identity
                    . ' bytes=' . (is_string($early) ? bin2hex($early) : 'read-failed') . ' metadata=' . json_encode(stream_get_meta_data($new)));
                usleep(10000);
            } while (microtime(true) < $until);
            stream_set_blocking($new, true);
        } finally {
            expect(posix_kill($processes['beta']->pid(), SIGCONT), '无法恢复QoS0旧接收节点');
        }
        mqttSessionAck($new, true);
        mqttClusterClosed($old);
        mqttWillSilence($new, 0.4);
        mqttUntil(static fn (): bool => $standby->query('SELECT d.state FROM type_mqtt_deliveries d JOIN type_mqtt_messages m ON m.id = d.message_id WHERE m.topic = '
            . $standby->quote($topic))->fetchColumn() === 'expired', '已离开的QoS0目标没有终结释放预算');
        foreach ([$publisher, $old, $new] as $socket) {
            fclose($socket);
        }
        $cases++;
    }
    $identity = 'route-volatile-group-' . $suffix;
    $topic = 'example/' . $identity;
    $filter = '$share/' . $identity . '/' . $topic;
    $publisher = mqttSocket($ports['alpha'], $certificate);
    $old = mqttSocket($ports['beta'], $certificate);
    array_push($sockets, $publisher, $old);
    mqttWrite($publisher, mqttConnect(5, $identity . '-publisher', 0));
    mqttAck($publisher, 5);
    mqttWrite($old, mqttSessionConnect(5, $identity . '-old'));
    mqttSessionAck($old, false);
    mqttWrite($old, mqttSubscription(5, $filter, 1, 2));
    expect(mqttRead($old) === mqttSubscriptionAck(5, "\x02"), 'QoS0组原成员订阅失败');
    $new = mqttSocket($ports['gamma'], $certificate);
    $sockets[] = $new;
    mqttWrite($new, mqttConnect(5, $identity . '-new', 0));
    mqttAck($new, 5);
    expect(posix_kill($processes['beta']->pid(), SIGSTOP), '无法暂停QoS0组原节点');
    try {
        mqttClusterPublish($publisher, 5, $topic, 'original-members-only', 0);
        mqttUntil(static fn (): bool => (int) $standby->query('SELECT COUNT(*) FROM type_mqtt_deliveries d JOIN type_mqtt_messages m ON m.id = d.message_id WHERE m.topic = '
            . $standby->quote($topic) . " AND d.group_id <> '' AND d.state = 'pending'")->fetchColumn() === 1, 'QoS0组没有在线转发事实');
        mqttWrite($new, mqttSubscription(5, $filter, 1, 2));
        expect(mqttRead($new) === mqttSubscriptionAck(5, "\x02"), 'QoS0组后加入成员订阅失败');
        mqttWillSilence($new, 0.4);
        fclose($old);
    } finally {
        expect(posix_kill($processes['beta']->pid(), SIGCONT), '无法恢复QoS0组原节点');
    }
    mqttUntil(static fn (): bool => $standby->query('SELECT d.state FROM type_mqtt_deliveries d JOIN type_mqtt_messages m ON m.id = d.message_id WHERE m.topic = '
        . $standby->quote($topic))->fetchColumn() === 'expired', 'QoS0组无原在线成员后没有释放预算');
    mqttWillSilence($new, 0.3);
    mqttClusterPublish($publisher, 5, $topic, 'current-member', 0);
    $message = mqttRetainedRead($new, 5);
    expect($message['payload'] === 'current-member' && $message['qos'] === 0, 'QoS0组新成员不能接收当前发布');
    mqttWrite($new, mqttSubscription(5, $filter, 2, 0, false));
    expect(mqttRead($new) === mqttSubscriptionAck(5, "\0", 2, false), '跨节点共享取消失败');
    mqttClusterPublish($publisher, 5, $topic, 'after-shared-unsubscribe', 0);
    mqttWillSilence($new, 0.4);
    fclose($publisher);
    fclose($new);
    return $cases + 3;
}

/** 105个真实在线目的跨越100所有者节点页；读取游标不能锁住或遗漏后五个接收者。 */
function mqttClusterRoutePageCases(array $ports, PDO $standby, array $processes, string $consumer, array $workerCommand, array $environment, array &$sockets): int
{
    $subscribers = [];
    $topic = 'example/route-owner-pages';
    $publisher = mqttSocket($ports['alpha']);
    $sockets[] = $publisher;
    mqttWrite($publisher, mqttConnect(5, 'route-pages-publisher', 0));
    mqttAck($publisher, 5);
    for ($index = 0; $index < 105; $index++) {
        $subscriber = mqttSocket($ports['beta']);
        $subscribers[] = $subscriber;
        $sockets[] = $subscriber;
        mqttWrite($subscriber, mqttConnect(5, 'route-page-' . $index, 0));
        mqttAck($subscriber, 5);
        mqttWrite($subscriber, mqttSubscription(5, $topic, 1, 1));
        expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\x01"), '跨页目的订阅失败');
    }
    expect(posix_kill($processes['beta']->pid(), SIGSTOP), '无法暂停跨页目的节点');
    try {
        mqttClusterPublish($publisher, 5, $topic, 'all-105-recipients', 1);
        $runId = $standby->query("SELECT run_id FROM type_mqtt_nodes WHERE node_id = 'beta'")->fetchColumn();
        $request = ['action' => 'node_poll', 'node_id' => 'beta', 'node_run_id' => $runId, 'closed_owners' => []];
        $first = mqttClusterRequest($consumer, $workerCommand, $environment, $request);
        expect($first['state'] === 'committed' && count($first['value']['ready_owners']) === 100 && strlen($first['value']['delivery_cursor']) === 32, '节点就绪页没有100项明确游标');
        $second = mqttClusterRequest($consumer, $workerCommand, $environment, [...$request, 'delivery_cursor' => $first['value']['delivery_cursor']]);
        expect($second['state'] === 'committed' && count($second['value']['ready_owners']) === 5 && $second['value']['delivery_cursor'] === '', '节点就绪页没有到达后五个所有者并重置');
        expect(count(array_unique(array_column([...$first['value']['ready_owners'], ...$second['value']['ready_owners']], 'owner_id'))) === 105, '节点页重复或遗漏所有者');
        $invalid = mqttClusterRequest($consumer, $workerCommand, $environment, [...$request, 'delivery_cursor' => str_repeat('g', 32)]);
        expect($invalid['state'] === 'rejected', '节点页接受非法游标');
    } finally {
        expect(posix_kill($processes['beta']->pid(), SIGCONT), '无法恢复跨页目的节点');
    }
    foreach ($subscribers as $subscriber) {
        $message = mqttRetainedRead($subscriber, 5);
        expect($message['payload'] === 'all-105-recipients' && $message['qos'] === 1 && !$message['duplicate'], '跨页所有者未交付一次原消息');
        mqttRetainedComplete($subscriber, $message);
        mqttWrite($subscriber, "\xe0\0");
        $ending = mqttRead($subscriber);
        expect($ending === '', '跨页目的退出失败：' . bin2hex($ending));
        fclose($subscriber);
    }
    fclose($publisher);
    return 109;
}

/** 跨节点普通/共享义务复用真实全局、会话和组配额；拒绝保留旧原件，ACK后才能重用额度。 */
function mqttClusterRouteCapacityCases(array $ports, PDO $standby, array &$processes, Closure $start, Closure $stop, array &$sockets): int
{
    $cases = 0;
    foreach (['global-count', 'global-bytes', 'device-count', 'device-bytes', 'shared-count', 'shared-bytes'] as $profile) {
        $identity = 'route-capacity-' . $profile;
        $topic = 'example/' . $identity;
        $shared = str_starts_with($profile, 'shared-');
        $filter = $shared ? '$share/' . $identity . '/' . $topic : $topic;
        $payload = 'keep-original';
        $bytes = strlen($topic) + strlen($payload);
        $budget = match ($profile) {
            'global-count' => ['MQTT_PENDING_MAX_MESSAGES' => '2'],
            'global-bytes' => ['MQTT_PENDING_MAX_BYTES' => (string) ($bytes * 2)],
            'device-count' => ['MQTT_DEVICE_MAX_MESSAGES' => '1'],
            'device-bytes' => ['MQTT_DEVICE_MAX_BYTES' => (string) $bytes],
            'shared-count' => ['MQTT_SHARED_MAX_MESSAGES' => '1'],
            default => ['MQTT_SHARED_MAX_BYTES' => (string) $bytes],
        };
        mqttUntil(static fn (): bool => (int) $standby->query("SELECT COUNT(*) FROM type_mqtt_deliveries WHERE state = 'pending'")->fetchColumn() === 0, '低配额回归前已有未完成义务');
        $stop($processes['alpha']);
        unset($processes['alpha']);
        $processes['alpha'] = $start('alpha', false, $budget);
        $subscriber = mqttSocket($ports['beta']);
        $sockets[] = $subscriber;
        mqttWrite($subscriber, mqttSessionConnect(5, $identity));
        mqttSessionAck($subscriber, false);
        mqttWrite($subscriber, mqttSubscription(5, $filter, 1, 1));
        expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\x01"), '跨节点容量目的未订阅');
        mqttWrite($subscriber, "\xe0\0");
        expect(mqttRead($subscriber) === '', '跨节点容量目的未断线');
        fclose($subscriber);
        mqttUntil(static fn (): bool => $standby->query('SELECT owner_id FROM type_mqtt_sessions WHERE client_id = ' . $standby->quote($identity))->fetchColumn() === null, '容量目的未同步离线');
        foreach ([true, false] as $accepted) {
            $publisher = mqttSocket($ports['alpha']);
            $sockets[] = $publisher;
            mqttWrite($publisher, mqttConnect(5, $identity . '-' . (int) $accepted, 0));
            mqttAck($publisher, 5);
            mqttWrite($publisher, mqttRetainedPacket(5, $topic, $payload, 1, 17, '', false));
            $response = mqttRead($publisher);
            expect($response === ($accepted ? "\x40\x02\0\x11" : "\xe0\x02\x97\0"), '跨节点配额没有原子接受/拒绝：' . $profile . ' ' . bin2hex($response));
            if ($accepted) {
                mqttWrite($publisher, "\xe0\0");
            }
            expect(mqttRead($publisher) === '', '配额发布者没有结束');
            fclose($publisher);
        }
        expect((int) $standby->query('SELECT COUNT(*) FROM type_mqtt_messages WHERE topic = ' . $standby->quote($topic))->fetchColumn() === 1, '容量拒绝插入第二原件或删除旧原件');
        $subscriber = mqttSocket($ports['gamma']);
        $sockets[] = $subscriber;
        mqttWrite($subscriber, mqttSessionConnect(5, $identity));
        mqttSessionAck($subscriber, true);
        $message = mqttRetainedRead($subscriber, 5);
        expect($message['payload'] === $payload && $message['qos'] === 1 && !$message['duplicate'], '配额拒绝后旧原件没有跨节点恢复');
        mqttRetainedComplete($subscriber, $message);
        mqttUntil(static fn (): bool => (int) $standby->query('SELECT COUNT(*) FROM type_mqtt_deliveries d JOIN type_mqtt_messages m ON m.id = d.message_id WHERE m.topic = '
            . $standby->quote($topic) . " AND d.state = 'pending'")->fetchColumn() === 0, '真实ACK没有释放跨节点义务');
        $publisher = mqttSocket($ports['alpha']);
        $sockets[] = $publisher;
        mqttWrite($publisher, mqttConnect(5, $identity . '-after-ack', 0));
        mqttAck($publisher, 5);
        mqttClusterPublish($publisher, 5, $topic, $payload, 1);
        $message = mqttRetainedRead($subscriber, 5);
        expect($message['payload'] === $payload && !$message['duplicate'], 'ACK后未恢复可用配额');
        mqttRetainedComplete($subscriber, $message);
        foreach ([$publisher, $subscriber] as $socket) {
            mqttWrite($socket, "\xe0\0");
            expect(mqttRead($socket) === '', '跨节点容量恢复后退出失败');
            fclose($socket);
        }
        $cases += 4;
    }
    $stop($processes['alpha']);
    unset($processes['alpha']);
    $processes['alpha'] = $start('alpha');
    return $cases;
}

/** 直接观察四步交换、期限、遗嘱及共享成员在当前所有者节点上的恢复。 */
function mqttClusterStateCases(array $ports, PDO $standby, array &$sockets): int
{
    require_once __DIR__ . '/mqtt-qos2.php';
    $cases = 0;
    $old = mqttSocket($ports['alpha']);
    $publisher = mqttSocket($ports['alpha']);
    array_push($sockets, $old, $publisher);
    mqttWrite($old, mqttSessionConnect(5, 'cluster-pubrel'));
    mqttSessionAck($old, false);
    mqttWrite($old, mqttSubscription(5, 'example/cluster-pubrel', 1, 2));
    expect(mqttRead($old) === mqttSubscriptionAck(5, "\x02"), 'PUBREL恢复订阅失败');
    mqttWrite($publisher, mqttSessionConnect(5, 'cluster-incoming'));
    mqttSessionAck($publisher, false);
    mqttWrite($publisher, mqttQos2Publish(5, 'example/cluster-pubrel', 'one-exchange', 27));
    expect(mqttRead($publisher) === mqttQos2Ack(0x50, 27), '入站PUBREC未完成');
    $message = mqttRetainedRead($old, 5);
    mqttWrite($old, mqttQos2Ack(0x50, $message['id']));
    expect(mqttRead($old) === mqttQos2Ack(0x62, $message['id']), '出站PUBREL未完成');
    $subscriber = mqttSocket($ports['beta']);
    $newPublisher = mqttSocket($ports['gamma']);
    array_push($sockets, $subscriber, $newPublisher);
    mqttWrite($subscriber, mqttSessionConnect(5, 'cluster-pubrel'));
    mqttSessionAck($subscriber, true);
    expect(mqttRead($subscriber) === mqttQos2Ack(0x62, $message['id']), '跨节点未恢复原Packet Identifier的PUBREL');
    mqttWrite($subscriber, mqttQos2Ack(0x70, $message['id']));
    mqttWrite($newPublisher, mqttSessionConnect(5, 'cluster-incoming'));
    mqttSessionAck($newPublisher, true);
    mqttWrite($newPublisher, mqttQos2Ack(0x62, 27));
    expect(mqttRead($newPublisher) === mqttQos2Ack(0x70, 27), '跨节点入站QoS2未恢复原交换');
    mqttWrite($newPublisher, mqttQos2Ack(0x62, 27));
    expect(mqttRead($newPublisher) === mqttQos2Ack(0x70, 27, 0x92), '完成后重复PUBREL应返回Packet Identifier not found且不重建交付');
    mqttWillSilence($subscriber, 0.2);
    mqttClusterClosed($old);
    mqttClusterClosed($publisher);
    foreach ([$old, $publisher, $subscriber, $newPublisher] as $socket) {
        fclose($socket);
    }
    $cases += 5;
    foreach ([false, true] as $clean) {
        $id = 'cluster-delay-' . (int) $clean;
        $topic = 'example/' . $id;
        $observer = mqttWillSubscriber($ports['beta'], $id . '-observer', $topic);
        $old = mqttSocket($ports['alpha']);
        array_push($sockets, $observer, $old);
        mqttWrite($old, mqttWillConnect(5, $id, $topic, "\0\xffdelayed", 1, false, 15, 30, false));
        mqttSessionAck($old, false);
        $new = mqttSocket($ports['beta']);
        $sockets[] = $new;
        mqttWrite($new, mqttSessionConnect(5, $id, $clean));
        mqttSessionAck($new, !$clean);
        mqttClusterClosed($old);
        if ($clean) {
            $will = mqttRetainedRead($observer, 5);
            expect($will['payload'] === "\0\xffdelayed", 'Clean Start跨节点丢失原遗嘱');
            mqttRetainedComplete($observer, $will);
        } else {
            mqttWillSilence($observer, 0.3);
            expect($standby->query('SELECT outcome FROM type_mqtt_will_audit WHERE client_id = ' . $standby->quote($id))->fetchColumn() === 'cancelled', '跨节点恢复没有取消未到期延迟遗嘱');
        }
        foreach ([$old, $new, $observer] as $socket) {
            fclose($socket);
        }
        $cases += 2;
    }
    $old = mqttSocket($ports['alpha']);
    $sockets[] = $old;
    mqttWrite($old, mqttSessionConnect(5, 'cluster-expired', false, 1));
    mqttSessionAck($old, false);
    mqttWrite($old, "\xe0\0");
    mqttClusterClosed($old);
    mqttUntil(static fn (): bool => $standby->query("SELECT owner_id FROM type_mqtt_sessions WHERE client_id = 'cluster-expired'")->fetchColumn() === null, '期限装置尚未离线');
    usleep(1200000);
    $new = mqttSocket($ports['beta']);
    $sockets[] = $new;
    mqttWrite($new, mqttSessionConnect(5, 'cluster-expired'));
    mqttSessionAck($new, false);
    fclose($old);
    fclose($new);
    $cases++;
    $old = mqttSocket($ports['alpha']);
    $sockets[] = $old;
    mqttWrite($old, mqttSessionConnect(5, 'cluster-shared'));
    mqttSessionAck($old, false);
    mqttWrite($old, mqttSubscription(5, '$share/cluster/example/cluster-group', 1, 2));
    expect(mqttRead($old) === mqttSubscriptionAck(5, "\x02"), '跨节点共享成员订阅失败');
    $member = $standby->query("SELECT m.group_id, m.session_id FROM type_mqtt_shared_members m JOIN type_mqtt_sessions s ON s.id=m.session_id WHERE s.client_id='cluster-shared'")->fetch(PDO::FETCH_ASSOC);
    $new = mqttSocket($ports['beta']);
    $sockets[] = $new;
    mqttWrite($new, mqttSessionConnect(5, 'cluster-shared'));
    mqttSessionAck($new, true);
    $restored = $standby->query("SELECT m.group_id, m.session_id FROM type_mqtt_shared_members m JOIN type_mqtt_sessions s ON s.id=m.session_id WHERE s.client_id='cluster-shared'")->fetchAll(PDO::FETCH_ASSOC);
    expect($restored === [$member], '共享组成员在接管后重复或丢失');
    mqttClusterClosed($old);
    fclose($old);
    fclose($new);
    $cases += 2;
    foreach ([4, 5] as $version) {
        foreach ([0, 1, 2] as $qos) {
            $topic = 'example/cluster-retained-' . $version . '-' . $qos;
            $clientId = 'cluster-retained-' . $version . '-' . $qos;
            $publisher = mqttSocket($ports['alpha']);
            $old = mqttSocket($ports['beta']);
            array_push($sockets, $publisher, $old);
            mqttWrite($publisher, mqttSessionConnect($version, $clientId . '-publisher'));
            mqttSessionAck($publisher, false);
            mqttRetainedPublish($publisher, $version, $topic, "\0\xffcluster-retained", $qos);
            mqttWrite($old, mqttSessionConnect($version, $clientId));
            mqttSessionAck($old, false);
            mqttWrite($old, mqttSubscription($version, $topic, 1, $qos));
            expect(mqttRead($old) === mqttSubscriptionAck($version, chr($qos)), '集群保留回放订阅失败');
            $message = mqttRetainedRead($old, $version);
            expect($message['payload'] === "\0\xffcluster-retained" && $message['qos'] === $qos && $message['retain'], '集群保留回放丢失载荷或属性');
            $new = mqttSocket($ports['gamma']);
            $sockets[] = $new;
            mqttWrite($new, mqttSessionConnect($version, $clientId));
            mqttSessionAck($new, true);
            mqttClusterClosed($old);
            if ($qos > 0) {
                $restoredMessage = mqttRetainedRead($new, $version);
                expect($restoredMessage['id'] === $message['id'] && $restoredMessage['payload'] === $message['payload']
                    && $restoredMessage['duplicate'] && $restoredMessage['retain'], '集群保留在途副本没有恢复原交换');
                mqttRetainedComplete($new, $restoredMessage);
            } else {
                mqttWillSilence($new, 0.2);
            }
            foreach ([$publisher, $old, $new] as $socket) {
                fclose($socket);
            }
            $cases += 2;
        }
    }
    return $cases;
}

/** 三个独立Broker共享真实同步主备，所有故障仅作用于本轮创建并持有的子进程。 */
function mqttClusterCases(string $root, string $consumer, array $command, array $workerCommand, array $environment): array
{
    ini_set('zend.exception_ignore_args', '1');
    require_once __DIR__ . '/native-database.php';
    require_once __DIR__ . '/postgres-sync.php';
    require_once __DIR__ . '/mqtt-qos1.php';
    require_once __DIR__ . '/mqtt-sessions.php';
    require_once __DIR__ . '/mqtt-retained.php';
    require_once __DIR__ . '/mqtt-will.php';
    $tools = NativeDatabase::tools('pgsql', (string) (getenv('TYPE_PGSQL_TOOLS') ?: $root . '/.cache/macos-libpq/17.11'));
    $database = new NativeDatabase($consumer . '/cluster-primary', 'pgsql', $tools);
    $sync = null;
    $primary = null;
    $standby = null;
    $processes = [];
    $sockets = [];
    $statistics = [];
    $cases = 0;
    $frozen = null;
    $proxy = null;
    try {
        $sync = new PostgresSync($database, $consumer . '/cluster-standby', $tools);
        $environment = array_replace($environment, $database->environment());
        $environment['MQTT_WORKER_COMMAND'] = json_encode($workerCommand, JSON_THROW_ON_ERROR);
        foreach ([1, 2] as $migration) {
            $installed = mqttClusterRequest($consumer, $workerCommand, $environment, ['action' => 'install']);
            expect($installed['state'] === 'committed' && $installed['released'], '集群迁移/重复迁移未取得同步证明');
        }
        $primary = $sync->connection();
        $standby = $sync->standby();
        $ports = ['alpha' => mqttClusterPort(), 'beta' => mqttClusterPort(), 'gamma' => mqttClusterPort()];
        $start = static function (string $node, bool $tls = false, array $overrides = []) use ($command, $consumer, $environment, $ports): Process {
            $settings = array_replace($environment, ['MQTT_CERTIFICATE' => $tls ? $consumer . '/certificate.pem' : '',
                'MQTT_PRIVATE_KEY' => $tls ? $consumer . '/private.pem' : ''], $overrides);
            $running = new Process([...$command, '--clustered', '--node-id=' . $node, '--port=' . $ports[$node], ...($tls ? [] : ['--plaintext'])], $consumer, $settings);
            mqttUntil(static function () use ($running, $ports, $node, $tls, $consumer): bool {
                expect($running->running(), '集群节点启动前退出：' . $running->stdout() . $running->stderr());
                $probe = null;
                try {
                    $probe = mqttSocket($ports[$node], $tls ? $consumer . '/certificate.pem' : null);
                    mqttWrite($probe, mqttConnect(5, 'cluster-ready-' . $node));
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
            }, '集群节点未就绪', 12.0);
            return $running;
        };
        $stop = static function (Process $process) use (&$statistics, $consumer): void {
            $result = $process->stop(15);
            file_put_contents($consumer . '/cluster-stop-' . count($statistics) . '.json', $result->stdout);
            expect($result->successful() && $result->stderr === '', '集群节点退出失败：' . $result->stdout . $result->stderr);
            $counts = json_decode($result->stdout, true, 32, JSON_THROW_ON_ERROR);
            foreach (['connections', 'subscriptions', 'bufferedBytes', 'pendingCommits', 'closingSessions', 'pendingFences', 'quarantinedCommits'] as $field) {
                expect($counts[$field] === 0, '集群退出资源未回收：' . $field);
            }
            expect(!$counts['nodePending'] && $counts['nodeFailures'] === 0, '正常关闭缺少节点退休证明：' . $result->stdout);
            $statistics[] = $counts;
        };
        foreach ([false, true] as $tls) {
            if ($tls && in_array('--cluster-page-only', $GLOBALS['argv'], true)) {
                continue;
            }
            foreach (array_keys($ports) as $node) {
                $processes[$node] = $start($node, $tls);
            }
            $certificate = $tls ? $consumer . '/certificate.pem' : null;
            if (!in_array('--cluster-state-only', $GLOBALS['argv'], true) && !in_array('--cluster-page-only', $GLOBALS['argv'], true)) {
                $cases += mqttClusterRoutingCases($ports, $certificate, $standby, $sockets);
            }
            if (!in_array('--cluster-page-only', $GLOBALS['argv'], true)) {
                $cases += mqttClusterRouteStateCases($ports, $certificate, $standby, $processes, $sockets);
            }
            if (!$tls && !in_array('--cluster-state-only', $GLOBALS['argv'], true)) {
                $cases += mqttClusterRoutePageCases($ports, $standby, $processes, $consumer, $workerCommand, $environment, $sockets);
            }
            if (!$tls && !in_array('--cluster-state-only', $GLOBALS['argv'], true) && !in_array('--cluster-page-only', $GLOBALS['argv'], true)) {
                $cases += mqttClusterRouteCapacityCases($ports, $standby, $processes, $start, $stop, $sockets);
            }
            if (in_array('--cluster-routing-only', $GLOBALS['argv'], true)) {
                foreach (array_keys($ports) as $node) {
                    $stop($processes[$node]);
                    unset($processes[$node]);
                }
                continue;
            }
            foreach ([4, 5] as $version) {
                foreach ([1, 2] as $qos) {
                    $id = 'cluster-' . (int) $tls . '-' . $version . '-' . $qos;
                    $topic = 'example/' . $id;
                    $old = mqttSocket($ports['alpha'], $certificate);
                    $publisher = mqttSocket($ports['alpha'], $certificate);
                    array_push($sockets, $old, $publisher);
                    mqttWrite($old, mqttSessionConnect($version, $id));
                    mqttSessionAck($old, false);
                    mqttWrite($old, mqttSubscription($version, $topic, 1, $qos));
                    expect(mqttRead($old) === mqttSubscriptionAck($version, chr($qos)), '跨节点订阅未持久保存');
                    mqttWrite($publisher, mqttConnect(5, $id . '-publisher'));
                    mqttAck($publisher, 5);
                    mqttRetainedPublish($publisher, 5, $topic, "\0\xffcluster-payload", $qos);
                    $initial = mqttRetainedRead($old, $version);
                    $previous = $standby->query('SELECT * FROM type_mqtt_sessions WHERE client_id = ' . $standby->quote($id))->fetch(PDO::FETCH_ASSOC);
                    $new = mqttSocket($ports['beta'], $certificate);
                    $sockets[] = $new;
                    mqttWrite($new, mqttSessionConnect($version, $id));
                    mqttSessionAck($new, true);
                    mqttClusterClosed($old);
                    $restored = mqttRetainedRead($new, $version);
                    expect(
                        $restored['id'] === $initial['id'] && $restored['duplicate'] && $restored['payload'] === $initial['payload'] && $restored['qos'] === $qos,
                        '跨节点恢复没有保留Packet Identifier、DUP、二进制或QoS'
                    );
                    mqttRetainedComplete($new, $restored);
                    $current = $standby->query('SELECT * FROM type_mqtt_sessions WHERE client_id = ' . $standby->quote($id))->fetch(PDO::FETCH_ASSOC);
                    expect($current['node_id'] === 'beta' && $current['owner_id'] !== $previous['owner_id']
                        && (int) $current['generation'] === (int) $previous['generation'] + 1, '接管代次或所有者没有同步转移');
                    $stale = mqttClusterRequest($consumer, $workerCommand, $environment, ['action' => 'session_save', 'session_id' => $previous['id'],
                        'owner_id' => $previous['owner_id'], 'subscriptions' => []]);
                    expect($stale['state'] === 'rejected', '旧所有者恢复后仍可有效写入');
                    expect(
                        $standby->query('SELECT subscriptions::text FROM type_mqtt_sessions WHERE client_id = ' . $standby->quote($id))->fetchColumn() === $current['subscriptions'],
                        '旧所有者修改了新会话订阅'
                    );
                    mqttWrite($new, "\xe0\0");
                    expect(mqttRead($new) === '', '新所有者正常结束失败');
                    foreach ([$old, $new, $publisher] as $socket) {
                        fclose($socket);
                    }
                    $cases += 6;
                }
            }
            foreach (array_keys($ports) as $node) {
                $stop($processes[$node]);
                unset($processes[$node]);
            }
        }
        if (in_array('--cluster-routing-only', $GLOBALS['argv'], true)) {
            return ['wire-cases' => $cases, 'nodes' => 3, 'routing-only' => true, 'statistics' => $statistics];
        }
        foreach (array_keys($ports) as $node) {
            $processes[$node] = $start($node);
        }
        $cases += mqttClusterStateCases($ports, $standby, $sockets);
        // 进程暂停可任意长于心跳；两个竞争者均须等真正旧连接关闭，不能以租约到期批准。
        $old = mqttSocket($ports['alpha']);
        $sockets[] = $old;
        mqttWrite($old, mqttSessionConnect(5, 'cluster-race'));
        mqttSessionAck($old, false);
        $frozen = $processes['alpha'];
        expect(posix_kill($frozen->pid(), SIGSTOP), '无法暂停本轮旧节点');
        $second = mqttSocket($ports['beta']);
        $sockets[] = $second;
        mqttWrite($second, mqttSessionConnect(5, 'cluster-race'));
        mqttUntil(static fn (): bool => $standby->query("SELECT node_id FROM type_mqtt_sessions WHERE client_id = 'cluster-race'")->fetchColumn() === 'beta', '第二节点没有接管意图');
        mqttWillSilence($second, 0.3);
        $third = mqttSocket($ports['gamma']);
        $sockets[] = $third;
        mqttWrite($third, mqttSessionConnect(5, 'cluster-race'));
        mqttUntil(static fn (): bool => $standby->query("SELECT node_id FROM type_mqtt_sessions WHERE client_id = 'cluster-race'")->fetchColumn() === 'gamma', '第三节点没有接管意图');
        mqttWillSilence($third, 0.3);
        expect(posix_kill($frozen->pid(), SIGCONT), '无法恢复本轮旧节点');
        $frozen = null;
        mqttSessionAck($third, true);
        mqttClusterClosed($old);
        mqttClusterClosed($second, true);
        expect((int) $standby->query("SELECT generation FROM type_mqtt_sessions WHERE client_id = 'cluster-race'")->fetchColumn() === 3, '连续接管代次错误');
        $cases += 5;
        foreach ([$old, $second, $third] as $socket) {
            fclose($socket);
        }
        // 精确撤权终止等待远端关闭；迟到旧主体不能删掉新凭据建立的会话。
        $old = mqttSocket($ports['alpha']);
        $sockets[] = $old;
        mqttWrite($old, mqttSessionConnect(5, 'cluster-revoke'));
        mqttSessionAck($old, false);
        $frozen = $processes['alpha'];
        expect(posix_kill($frozen->pid(), SIGSTOP), '无法暂停撤权目标节点');
        $terminated = mqttClusterRequest($consumer, $workerCommand, $environment, ['action' => 'session_terminate', 'client_id' => 'cluster-revoke', 'principal' => 'example', 'actor' => 'cluster-test']);
        expect($terminated['state'] === 'committed' && $terminated['value']['waiting'], '远端撤权未保留等待隔离状态');
        expect(posix_kill($frozen->pid(), SIGCONT), '无法恢复撤权目标节点');
        $frozen = null;
        mqttClusterClosed($old);
        mqttUntil(static fn (): bool => (int) $standby->query("SELECT COUNT(*) FROM type_mqtt_fences WHERE client_id = 'cluster-revoke'")->fetchColumn() === 0, '远端撤权未同步确认关闭');
        $terminated = mqttClusterRequest($consumer, $workerCommand, $environment, ['action' => 'session_terminate', 'client_id' => 'cluster-revoke', 'principal' => 'example', 'actor' => 'cluster-test']);
        expect($terminated['state'] === 'committed' && !$terminated['value']['waiting'], '撤权关闭完成仍未释放意图');
        fclose($old);
        $cases += 3;
        // 暂停父进程无法回收僵尸worker；先确认无执行者，再硬退出并观察所有PID消失。
        $old = mqttSocket($ports['alpha']);
        $sockets[] = $old;
        mqttWrite($old, mqttSessionConnect(5, 'cluster-killed'));
        mqttSessionAck($old, false);
        $fenceTarget = $standby->query("SELECT run_id, generation FROM type_mqtt_nodes WHERE node_id = 'alpha'")->fetch(PDO::FETCH_ASSOC);
        $run = $fenceTarget['run_id'];
        $frozen = $processes['alpha'];
        expect(posix_kill($frozen->pid(), SIGSTOP), '无法冻结待硬退出的本轮节点');
        $parent = $frozen->pid();
        $workers = [];
        mqttUntil(static function () use ($parent, &$workers): bool {
            $workers = unixProcessStates($parent);
            foreach ($workers as $worker) {
                if (!str_starts_with($worker['state'], 'Z')) {
                    return false;
                }
            }
            return true;
        }, '旧节点受控worker仍在执行，禁止登记隔离', 12.0);
        expect(posix_kill($parent, SIGKILL), '无法硬退出本轮节点');
        $killed = $frozen->wait(5);
        expect(!$killed->successful() && !$killed->timedOut && !$frozen->running(), '旧节点没有实际硬退出');
        $ownedPids = array_fill_keys([$parent, ...array_keys($workers)], true);
        mqttUntil(static fn (): bool => array_intersect_key(unixProcessStates(), $ownedPids) === [], '硬退出后的节点或worker记录尚未回收', 5.0);
        $frozen = null;
        unset($processes['alpha']);
        $new = mqttSocket($ports['beta']);
        $sockets[] = $new;
        mqttWrite($new, mqttSessionConnect(5, 'cluster-killed'));
        mqttUntil(static fn (): bool => $standby->query("SELECT node_id FROM type_mqtt_sessions WHERE client_id = 'cluster-killed'")->fetchColumn() === 'beta', '退出后的接管意图未记录');
        mqttWillSilence($new, 0.4);
        $rejected = mqttClusterRequest($consumer, $workerCommand, $environment, ['action' => 'node_fence', 'node_id' => 'alpha', 'node_run_id' => $run, 'actor' => 'cluster-test', 'proof_ref' => '']);
        expect($rejected['state'] === 'rejected', '没有硬隔离证据标识仍被接受');
        $fenceActionId = bin2hex(random_bytes(16));
        $fenceRequest = ['action' => 'node_fence', 'action_operation_id' => $fenceActionId, 'node_id' => 'alpha', 'node_run_id' => $run,
            'generation' => (int) $fenceTarget['generation'], 'observation_run' => '', 'actor' => 'cluster-test',
            'proof_ref' => 'sha256:' . hash('sha256', 'owned_process_exited_children_absent')];
        $fenced = mqttClusterRequest($consumer, $workerCommand, $environment, $fenceRequest);
        expect($fenced['state'] === 'committed' && $fenced['released'] && $fenced['value']['fenced']
            && $fenced['value']['operation_id'] === $fenceActionId && $fenced['value']['generation'] === (int) $fenceTarget['generation'], '硬退出完成登记没有冻结动作身份和同步证明');
        $fenceAudit = $standby->query('SELECT * FROM type_mqtt_node_audit WHERE operation_id = ' . $standby->quote($fenceActionId))->fetch(PDO::FETCH_ASSOC);
        $fencedNode = $standby->query("SELECT * FROM type_mqtt_nodes WHERE node_id = 'alpha'")->fetch(PDO::FETCH_ASSOC);
        foreach (['node_fence', 'node_fence_result'] as $action) {
            $repeated = mqttClusterRequest($consumer, $workerCommand, $environment, array_replace($fenceRequest, ['action' => $action, 'origin_request_id' => $fenced['operation_id']]));
            expect($repeated['state'] === 'committed' && $repeated['released'] && ($repeated['value']['origin_released'] ?? false)
                && $repeated['value']['operation_id'] === $fenceActionId && $repeated['operation_id'] !== $fenced['operation_id'], '重试未沿用动作事实或复用了worker身份');
            expect($standby->query('SELECT * FROM type_mqtt_node_audit WHERE operation_id = ' . $standby->quote($fenceActionId))->fetchAll(PDO::FETCH_ASSOC) === [$fenceAudit]
                && $standby->query("SELECT * FROM type_mqtt_nodes WHERE node_id = 'alpha'")->fetch(PDO::FETCH_ASSOC) === $fencedNode, '重复隔离或结果查询增加审计、刷新时间或重复修改节点');
        }
        foreach (['node_id' => 'gamma', 'node_run_id' => bin2hex(random_bytes(16)), 'generation' => (int) $fenceTarget['generation'] + 1,
            'observation_run' => bin2hex(random_bytes(16)), 'actor' => 'another-actor', 'proof_ref' => 'sha256:' . hash('sha256', 'different-proof')] as $field => $conflict) {
            foreach (['node_fence', 'node_fence_result'] as $action) {
                $conflicted = mqttClusterRequest($consumer, $workerCommand, $environment, array_replace($fenceRequest, ['action' => $action, $field => $conflict]));
                expect($conflicted['state'] === 'rejected', '原动作ID不能接受不同目标或隔离依据：' . $field . '/' . $action);
            }
        }
        mqttSessionAck($new, true);
        $processes['alpha'] = $start('alpha');
        $newNode = $standby->query("SELECT * FROM type_mqtt_nodes WHERE node_id = 'alpha'")->fetch(PDO::FETCH_ASSOC);
        expect($newNode['state'] === 'active' && $newNode['run_id'] !== $run && (int) $newNode['generation'] === (int) $fenceTarget['generation'] + 1, '隔离后未形成新的准确节点运行');
        foreach (['node_fence', 'node_fence_result'] as $action) {
            $oldResult = mqttClusterRequest($consumer, $workerCommand, $environment, array_replace($fenceRequest, ['action' => $action]));
            expect($oldResult['state'] === 'committed' && $oldResult['released'] && $oldResult['value']['fenced']
                && $oldResult['value']['node_run_id'] === $run && $oldResult['value']['operation_id'] === $fenceActionId, '新运行启动后旧动作未返回原事实');
            expect($standby->query("SELECT * FROM type_mqtt_nodes WHERE node_id = 'alpha'")->fetch(PDO::FETCH_ASSOC) === $newNode
                && $standby->query('SELECT * FROM type_mqtt_node_audit WHERE operation_id = ' . $standby->quote($fenceActionId))->fetchAll(PDO::FETCH_ASSOC) === [$fenceAudit], '旧动作重试影响新运行或刷新旧审计');
        }
        $late = mqttClusterRequest($consumer, $workerCommand, $environment, ['action' => 'node_fence', 'node_id' => 'alpha', 'node_run_id' => $run,
            'actor' => 'cluster-test', 'proof_ref' => 'late_old_run']);
        expect($late['state'] === 'rejected', '迟到隔离登记影响新运行：' . json_encode($late) . ' old=' . $run
            . ' current=' . $standby->query("SELECT run_id FROM type_mqtt_nodes WHERE node_id = 'alpha'")->fetchColumn());
        $legacy = mqttClusterRequest($consumer, $workerCommand, $environment, ['action' => 'session_recover', 'node_id' => 'alpha']);
        expect($legacy['state'] === 'rejected', '单节点恢复绕过集群运行身份');
        $cases += 23;
        foreach ([$old, $new] as $socket) {
            fclose($socket);
        }
        // 仅alpha的数据库链路经本轮代理；停止代理不影响其他节点及同步复制。
        $stop($processes['alpha']);
        unset($processes['alpha']);
        $proxyPort = mqttClusterPort();
        $proxyConfig = $consumer . '/cluster-database-proxy.cfg';
        file_put_contents($proxyConfig, "defaults\n    mode tcp\n    timeout connect 1s\n    timeout client 15s\n    timeout server 15s\nlisten database\n    bind 127.0.0.1:" . $proxyPort
            . "\n    server primary 127.0.0.1:" . $environment['TYPE_PGSQL_PORT'] . "\n");
        $proxyCommand = [(string) (getenv('TYPE_HAPROXY_BINARY') ?: 'haproxy'), '-db', '-f', $proxyConfig];
        $proxy = new Process($proxyCommand, $consumer, $environment);
        $processes['alpha'] = $start('alpha', false, ['TYPE_PGSQL_PORT' => (string) $proxyPort]);
        $old = mqttSocket($ports['alpha']);
        $sockets[] = $old;
        mqttWrite($old, mqttSessionConnect(5, 'cluster-partition'));
        mqttSessionAck($old, false);
        $partitionRun = $standby->query("SELECT run_id FROM type_mqtt_nodes WHERE node_id = 'alpha'")->fetchColumn();
        $proxy->stop(2);
        $partitioned = $processes['alpha']->wait(15);
        expect(!$processes['alpha']->running(), '失去数据库链路的节点没有停止输出并退出');
        $partitionStatistics = json_decode($partitioned->stdout, true, 32, JSON_THROW_ON_ERROR);
        expect($partitionStatistics['nodeFailures'] > 0 && $partitionStatistics['connections'] === 0 && $partitionStatistics['pendingCommits'] === 0, '分区节点没有保留失败及关闭证据');
        unset($processes['alpha']);
        mqttClusterClosed($old);
        $new = mqttSocket($ports['beta']);
        $sockets[] = $new;
        mqttWrite($new, mqttSessionConnect(5, 'cluster-partition'));
        mqttUntil(static fn (): bool => $standby->query("SELECT node_id FROM type_mqtt_sessions WHERE client_id='cluster-partition'")->fetchColumn() === 'beta', '分区接管意图未持久保存');
        mqttWillSilence($new, 0.3);
        $fenced = mqttClusterRequest($consumer, $workerCommand, $environment, ['action' => 'node_fence', 'node_id' => 'alpha', 'node_run_id' => $partitionRun,
            'actor' => 'cluster-test', 'proof_ref' => 'partitioned_owned_process_exited_workers_reaped']);
        expect($fenced['state'] === 'committed', '已退出分区节点的隔离登记失败');
        mqttSessionAck($new, true);
        $proxy = new Process($proxyCommand, $consumer, $environment);
        $processes['alpha'] = $start('alpha', false, ['TYPE_PGSQL_PORT' => (string) $proxyPort]);
        $staleRun = mqttClusterRequest($consumer, $workerCommand, $environment, ['action' => 'node_poll', 'node_id' => 'alpha', 'node_run_id' => $partitionRun]);
        expect($staleRun['state'] === 'rejected', '恢复网络后旧运行重新取得输出权限');
        foreach ([$old, $new] as $socket) {
            fclose($socket);
        }
        $cases += 5;
        foreach (array_keys($processes) as $node) {
            $stop($processes[$node]);
            unset($processes[$node]);
        }
        mqttDatabaseIdle($primary);
        expect((int) $standby->query("SELECT COUNT(*) FROM type_mqtt_nodes WHERE state = 'active'")->fetchColumn() === 0, '正常结束后仍有active节点');
        expect((int) $standby->query('SELECT COUNT(*) FROM type_mqtt_fences')->fetchColumn() === 0, '隔离意图没有有界回收');
        $fenceUnknown = mqttClusterFenceUnknownCase($sync, $consumer, $workerCommand, $environment);
        $cases += 6;
        return ['wire_cases' => $cases, 'statistics' => $statistics, 'node_generations' => $standby->query('SELECT node_id, generation, state FROM type_mqtt_nodes ORDER BY node_id')->fetchAll(PDO::FETCH_ASSOC),
            'faults' => ['SIGSTOP/SIGCONT-owner-race', 'SIGKILL-owned-broker-after-workers-exited', 'broker-database-tcp-partition-and-return'],
            'partition_statistics' => $partitionStatistics, 'fence_operation_id' => $fenceActionId, 'fence_unknown_recovery' => $fenceUnknown,
            'E1' => 'independent-fault-domains-not-verified'];
    } finally {
        if ($frozen !== null && $frozen->running()) {
            posix_kill($frozen->pid(), SIGCONT);
        }
        foreach ($sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        foreach ($processes as $process) {
            $process->stop(15);
        }
        $proxy?->stop(2);
        $primary = null;
        $standby = null;
        try {
            $sync?->close();
        } finally {
            $database->close();
        }
    }
}
