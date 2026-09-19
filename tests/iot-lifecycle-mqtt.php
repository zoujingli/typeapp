<?php

declare(strict_types=1);

use Type\Testing\Process;

/** 真实管理HTTP、TLS标准客户端及同步主备共同证明授权失效；只控制本函数拥有的进程和数据库。 */
function iotLifecycleMqttChecks(Closure $request, array $evidence, array $environment, string $base, PDO $database, Process $broker, #[SensitiveParameter] string $servicePassword, Closure $startBroker): array
{
    $root = dirname(__DIR__);
    $devices = '/customer/tenants/' . $evidence['tenant'] . '/devices';
    $clients = [];
    $secrets = [$servicePassword, $evidence['admin_token'], $evidence['token'], $evidence['platform_token'], $evidence['source']['accessToken'], $evidence['simulated']['accessToken']];
    $cases = [];
    $brokerLogs = '';
    $standby = new PDO(
        'pgsql:host=127.0.0.1;port=' . $environment['TYPE_PGSQL_STANDBY_PORT'] . ';dbname=' . $environment['DB_DATABASE'],
        $environment['DB_USERNAME'],
        $environment['DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $wait = static function (Closure $probe, string $message, float $seconds = 15.0): mixed {
        $until = microtime(true) + $seconds;
        do {
            $value = $probe();
            if ($value) {
                return $value;
            }
            usleep(20000);
        } while (microtime(true) < $until);
        throw new RuntimeException($message);
    };
    $create = static function (string $name) use ($request, $devices, $evidence, &$secrets): array {
        $created = $request(
            'POST',
            $devices,
            $evidence['admin_token'],
            $evidence['tenant'],
            ['name' => '网络撤权-' . $name, 'product_id' => $evidence['device']['product_id'], 'model_version' => 1],
            201
        )['data'];
        $secrets[] = $created['credential']['password'];
        return $created;
    };
    $read = static function (array $created) use ($request, $devices, $evidence): array {
        return $request('GET', $devices . '/' . $created['device']['id'], $evidence['token'], $evidence['tenant'], null, 200)['data'];
    };
    $manage = static function (array $created, string $action) use ($request, $devices, $evidence, $read, &$secrets): array {
        $device = $read($created);
        $changed = $request(
            'POST',
            in_array($action, ['disable', 'retire'], true) ? '/admin/devices/' . $device['id'] . '/' . $action : $devices . '/' . $device['id'] . '/' . $action,
            in_array($action, ['disable', 'retire'], true) ? $evidence['platform_token'] : $evidence['admin_token'],
            in_array($action, ['disable', 'retire'], true) ? null : $evidence['tenant'],
            ['version' => (int) $device['version'], 'confirm_device_id' => $device['id']],
            202
        )['data'];
        if ($changed['credential'] !== null) {
            $secrets[] = $changed['credential']['password'];
        }
        return $changed;
    };
    $spawn = static function (string $mode, array $created) use ($root, $environment, $base, $devices, $evidence, $servicePassword, &$clients): array {
        $gate = $base . '/lifecycle-' . bin2hex(random_bytes(8));
        $fixture = ['credential' => $created['credential'], 'path' => $devices . '/' . $created['device']['id'], 'tenant' => $evidence['tenant'], 'token' => $evidence['token']];
        $client = new Process(['node', $root . '/tests/iot-lifecycle-mqtt.mjs', $mode], $root, $environment + [
            'TYPE_LIFECYCLE_FIXTURE' => json_encode($fixture, JSON_THROW_ON_ERROR), 'TYPE_LIFECYCLE_GATE' => $gate,
            'TYPE_INGESTION_PASSWORD' => $servicePassword,
        ]);
        $clients[] = $client;
        return [$client, $gate];
    };
    $ready = static function (Process $client) use ($wait): void {
        $wait(static function () use ($client): bool {
            expect($client->running(), '生命周期客户端准备失败：' . $client->stderr());
            return str_contains($client->stdout(), "ready\n");
        }, '生命周期客户端未就绪', 20.0);
    };
    $finish = static function (Process $client, float $seconds = 25.0): array {
        $result = $client->wait($seconds);
        expect($result->successful(), '生命周期标准客户端失败（exit=' . $result->exitCode . ', timeout=' . (int) $result->timedOut . '）：' . $result->stderr);
        $lines = explode("\n", trim($result->stdout));
        return json_decode($lines[count($lines) - 1], true, 16, JSON_THROW_ON_ERROR);
    };
    $run = static function (string $mode, array $created) use ($spawn, $finish): array {
        [$client] = $spawn($mode, $created);
        return $finish($client);
    };
    $enforced = static function (array $created) use ($wait, $read): array {
        return $wait(static function () use ($read, $created): array|false {
            $device = $read($created);
            return $device['authorization']['status'] === 'enforced' ? $device : false;
        }, '管理HTTP没有观察到Broker撤权完成', 20.0);
    };
    $sessionCount = static function (array $created, PDO $connection): int {
        $query = $connection->prepare('SELECT COUNT(*) FROM type_mqtt_sessions WHERE client_id = ?');
        $query->execute([$created['device']['id']]);
        return (int) $query->fetchColumn();
    };
    $pendingCount = static function (array $created, PDO $connection): int {
        $query = $connection->prepare("SELECT COUNT(*) FROM type_mqtt_deliveries WHERE client_id = ? AND state = 'pending'");
        $query->execute([$created['device']['id']]);
        return (int) $query->fetchColumn();
    };
    $signal = static function (Process $process, int $signal): void {
        $pid = $process->pid();
        expect(is_int($pid) && posix_kill($pid, $signal), '无法控制本测试拥有的Broker进程');
    };
    try {
        // HTTP套件留下的意图也必须经真实Broker完成；装置不伪造完成字段。
        $wait(
            static fn (): bool => (int) $database->query('SELECT COUNT(*) FROM iot_authorization_invalidations WHERE completed_at IS NULL')->fetchColumn() === 0,
            'Broker未恢复已有管理意图',
            20.0
        );
        if (!in_array('--lifecycle-failure-only', $GLOBALS['argv'], true)) {
            foreach ([['idle', 'rotate'], ['publish', 'revoke'], ['subscribe', 'disable'], ['idle', 'retire']] as [$mode, $action]) {
                $created = $create($action);
                [$client, $gate] = $spawn($mode, $created);
                $ready($client);
                $manage($created, $action);
                file_put_contents($gate, 'management-committed');
                $control = $run(in_array($action, ['disable', 'retire'], true) ? 'control-denied' : 'control', $created);
                $cases[$action] = array_merge($finish($client), $control);
                $device = $enforced($created);
                expect($device['authorization']['completed_at'] !== null && $device['authorization']['node_id'] === $environment['IOT_MQTT_NODE_ID'], '撤权缺少节点完成事实');
                expect($sessionCount($created, $standby) === 0 && $pendingCount($created, $standby) === 0, '同步备库仍保留旧会话或待投递');
                $run('denied', $created);
                expect($broker->running(), '正常撤权使整个Broker退出：' . $broker->stderr());
            }
            expect(
                (int) $database->query("SELECT COUNT(*) FROM type_mqtt_messages WHERE payload = decode('" . bin2hex('revoked-publication') . "', 'hex')")->fetchColumn() === 0,
                '撤权后的旧发布仍进入持久消息'
            );

            $offline = $create('offline-queue');
            $run('offline', $offline);
            $run('control', $offline);
            expect($sessionCount($offline, $standby) === 1 && $pendingCount($offline, $standby) === 1, '离线装置没有真实持久订阅和待投递');
            $manage($offline, 'revoke');
            $enforced($offline);
            expect($sessionCount($offline, $standby) === 0 && $pendingCount($offline, $standby) === 0, '离线撤权没有同步清理待投递');
            $run('control', $offline);
            expect($pendingCount($offline, $standby) === 0, '撤权后控制消息再次进入旧离线订阅');
            $run('denied', $offline);
            $cases['offline'] = ['real-control-queued-before-revoke', 'old-persistent-subscription-and-pending-delivery-terminated', 'later-control-not-queued'];

            $old = $create('late-intent');
            $run('offline', $old);
            $rotated = $manage($old, 'rotate');
            $enforced($old);
            [$newClient, $gate] = $spawn('new-identity', $rotated);
            $ready($newClient);
            // 重复投递同一已完成意图，模拟消费者恢复后的迟到重放；它的旧主体保持原值。
            $replay = $database->prepare('UPDATE iot_authorization_invalidations SET completed_at = NULL, node_id = NULL WHERE id = ?');
            $replay->execute([$rotated['device']['authorization']['id']]);
            $enforced($rotated);
            $principal = $standby->prepare('SELECT principal, access_identity FROM type_mqtt_sessions WHERE client_id = ?');
            $principal->execute([$rotated['device']['id']]);
            $newSession = $principal->fetch(PDO::FETCH_ASSOC);
            expect(is_array($newSession) && $newSession['principal'] === $rotated['credential']['username'], '迟到旧意图误删新主体会话');
            $accessIdentity = json_decode($newSession['access_identity'], true, 4, JSON_THROW_ON_ERROR);
            expect($accessIdentity['principal_id'] === 'device:' . $old['device']['id']
                && $accessIdentity['credential_id'] === $rotated['credential']['id'] && $accessIdentity['credential_version'] === 1
                && $accessIdentity['authentication_method'] === 'connect', '换凭据未保留稳定设备主体或使用了旧凭据快照');
            file_put_contents($gate, 'old-intent-replayed');
            $run('control', $rotated);
            $cases['late-intent'] = $finish($newClient);
            $run('denied', $old);

            $crash = $create('restart-pending');
            [$crashClient] = $spawn('idle', $crash);
            $ready($crashClient);
            $signal($broker, SIGSTOP);
            $manage($crash, 'revoke');
            expect($read($crash)['authorization']['status'] === 'pending', '暂停Broker期间伪造了完成');
            $signal($broker, SIGKILL);
            $killed = $broker->wait(5);
            $brokerLogs .= $killed->stdout . $killed->stderr;
            $finish($crashClient);
            expect($sessionCount($crash, $database) === 1, '强杀装置没有保留未清理会话');
            $broker = $startBroker();
            $enforced($crash);
            expect($sessionCount($crash, $standby) === 0, '重启没有恢复并完成持久撤权意图');
            $run('denied', $crash);
            $cases['restart'] = ['hard-kill-keeps-intent-pending', 'restart-recovers-intent-and-terminates-old-session'];

            $origin = $create('source-session-exit');
            $run('offline', $origin);
            $signal($broker, SIGSTOP);
            $before = $read($origin);
            $request(
                'POST',
                $devices . '/' . $before['id'] . '/revoke',
                $evidence['simulated']['accessToken'],
                $evidence['tenant'],
                ['version' => $before['version'], 'confirm_device_id' => $before['id']],
                202
            );
            $request('POST', '/admin/auth/logout', $evidence['source']['accessToken'], null, [], 200);
            $request('GET', $devices . '/' . $before['id'], $evidence['simulated']['accessToken'], $evidence['tenant'], null, 401);
            $signal($broker, SIGCONT);
            $originCompleted = $enforced($origin);
            $run('denied', $origin);
            // 使用实际编译的受控访问入口重送已完成回调；不篡改持久完成状态。
            $repeatInput = $base . '/invalidation-completed.json';
            file_put_contents($repeatInput, json_encode(['action' => 'invalidation_completed', 'operation_id' => bin2hex(random_bytes(16)),
                'invalidation_id' => $originCompleted['authorization']['id'], 'node_id' => $originCompleted['authorization']['node_id']], JSON_THROW_ON_ERROR) . "\n");
            $repeat = new Process([...json_decode($environment['IOT_MQTT_COMMAND'], true, 8, JSON_THROW_ON_ERROR), 'iot:mqtt-access'], $root, $environment, 2048, $repeatInput);
            try {
                $repeated = $repeat->wait(10);
                expect($repeated->successful() && json_decode($repeated->stdout, true, 8, JSON_THROW_ON_ERROR)['allowed'] === true, '重复完成回调未正常收尾');
            } finally {
                $repeat->stop();
                unlink($repeatInput);
            }
            $auditQuery = $database->prepare("SELECT actor_id, details FROM customer_audit WHERE subject_id = ? AND action = 'device.authorization_completed'");
            $auditQuery->execute([$before['id']]);
            $completed = $auditQuery->fetchAll(PDO::FETCH_ASSOC);
            expect(count($completed) === 1, '准确撤权完成必须只有一条审计');
            $details = json_decode($completed[0]['details'], true, 8, JSON_THROW_ON_ERROR);
            expect($completed[0]['actor_id'] === $evidence['source']['identity']['actor_id']
                && $details['customer_id'] === $evidence['simulated']['user']['id']
                && $details['impersonation_id'] === $evidence['simulated']['identity']['impersonation_id']
                && $details['source_session_id'] === $evidence['source']['identity']['session_id'], '退出后完成审计丢失原始管理来源');
            $cases['source-exit'] = ['submitted-before-source-exit', 'later-access-denied', 'original-invalidation-completed-with-origin-audit', 'repeated-completion-without-duplicate-audit'];
        }

        $unknown = $create('unknown-commit');
        $run('offline', $unknown);
        $wait(static function () use ($standby, $unknown): bool {
            $query = $standby->prepare('SELECT owner_id FROM type_mqtt_sessions WHERE client_id = ?');
            $query->execute([$unknown['device']['id']]);
            return $query->fetchColumn() === null;
        }, '故障装置尚未完成离线会话提交');
        $signal($broker, SIGSTOP);
        $manage($unknown, 'revoke');
        // 先用真实行锁定位精确终止事务，再暂停复制；不能让无关遗嘱轮询抢先占住全局事务锁。
        $database->beginTransaction();
        $locked = $database->prepare('SELECT id FROM type_mqtt_sessions WHERE client_id = ? FOR UPDATE');
        $locked->execute([$unknown['device']['id']]);
        expect(is_string($locked->fetchColumn()), '故障装置缺少旧持久会话');
        $signal($broker, SIGCONT);
        $terminating = $wait(static function () use ($database): mixed {
            $database->query('SELECT pg_stat_clear_snapshot()')->fetchColumn();
            return $database->query("SELECT pid FROM pg_stat_activity WHERE application_name LIKE 'type_mqtt_%'"
                . " AND wait_event_type = 'Lock' AND query LIKE 'SELECT * FROM type_mqtt_sessions WHERE client_id = % FOR UPDATE' ORDER BY query_start LIMIT 1")->fetchColumn();
        }, '未定位等待旧会话行锁的精确撤权后端', 10.0);
        $standby->query('SELECT pg_wal_replay_pause()')->fetchColumn();
        $database->commit();
        $backend = $wait(static function () use ($database, $terminating): mixed {
            return $database->query('SELECT pid FROM pg_stat_activity WHERE pid = ' . (int) $terminating . " AND wait_event = 'SyncRep'")->fetchColumn();
        }, '撤权终止没有进入真实SyncRep等待', 10.0);
        expect($read($unknown)['authorization']['status'] === 'pending', '同步等待期间把撤权标成enforced');
        expect($database->query('SELECT pg_cancel_backend(' . (int) $backend . ')')->fetchColumn(), '无法取消本次撤权同步等待');
        $wait(static fn (): bool => $sessionCount($unknown, $database) === 0, '取消同步等待后主库终止事实不可见');
        expect($read($unknown)['authorization']['status'] === 'pending', '主库终止可见但没有同步证明时标成enforced');
        $failed = $broker->wait(20);
        $brokerLogs .= $failed->stdout . $failed->stderr;
        expect(!$failed->successful() && !$failed->timedOut, '未知撤权提交未使Broker明确失败退出');
        expect($read($unknown)['authorization']['status'] === 'pending', '未知终止结果错误确认了撤权');
        $standby->query('SELECT pg_wal_replay_resume()')->fetchColumn();
        $wait(
            static fn (): bool => (int) $database->query("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name LIKE 'type_mqtt_%'")->fetchColumn() === 0,
            '未知撤权退出后留下未清理数据库后端'
        );
        $broker = $startBroker();
        $enforced($unknown);
        $run('denied', $unknown);
        $cases['unknown'] = ['cancelled-real-sync-rep', 'primary-deletion-visible-still-pending', 'unknown-never-acknowledged', 'restart-reproves-and-completes'];
        $stopped = $broker->stop(15);
        expect($stopped->successful(), '生命周期Broker正常停止失败：' . $stopped->stderr);
        $wait(
            static fn (): bool => (int) $database->query("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name LIKE 'type_mqtt_%'")->fetchColumn() === 0,
            '正常停止后留下设备接入数据库后端'
        );
        $brokerLogs .= $stopped->stdout . $stopped->stderr;
        $audits = json_encode([$database->query('SELECT * FROM customer_audit')->fetchAll(PDO::FETCH_ASSOC), $database->query('SELECT * FROM admin_audit')->fetchAll(PDO::FETCH_ASSOC)], JSON_THROW_ON_ERROR);
        foreach ($secrets as $secret) {
            expect(!str_contains($audits . $brokerLogs, $secret)
                && !str_contains($audits . $brokerLogs, hash('sha256', $secret)), '生命周期审计或日志泄漏秘密');
        }
        return ['client' => 'MQTT.js 5.15.0', 'tls' => true, 'cases' => $cases, 'broker_stopped' => true, 'database_backends_released' => true, 'secrets_redacted' => true,
            'scope' => 'single-broker-real-management-and-protocol;late-intent-case-replays-the-same-persisted-identity'];
    } finally {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        $standby->query('SELECT pg_wal_replay_resume()')->fetchColumn();
        foreach ($clients as $client) {
            $client->stop();
        }
        if ($broker->running()) {
            $signal($broker, SIGCONT);
        }
        $stopped = $broker->stop(15);
        $diagnostic = $brokerLogs . $stopped->stdout . $stopped->stderr;
        foreach ($secrets as $secret) {
            $diagnostic = str_replace([$secret, hash('sha256', $secret)], '<REDACTED>', $diagnostic);
        }
        file_put_contents($base . '/lifecycle-broker.log', $diagnostic);
    }
}
