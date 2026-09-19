<?php

declare(strict_types=1);

use Type\Testing\Process;

/** 复用真实设备管理与MQTT.js授权客户端；集群完成位必须等待远端旧所有者关闭。 */
function iotClusterMqttChecks(Closure $request, array $evidence, array $command, array $environment, string $base, PDO $database, Process $first, #[SensitiveParameter] string $servicePassword): array
{
    ini_set('zend.exception_ignore_args', '1');
    $root = dirname(__DIR__);
    $devices = '/iot/tenants/' . $evidence['tenant'] . '/devices';
    $nodes = [$environment['IOT_MQTT_NODE_ID'] => $first];
    $ports = [$environment['IOT_MQTT_NODE_ID'] => $environment['IOT_MQTT_PORT']];
    $clients = [];
    $frozen = null;
    $checks = [];
    $statistics = [];
    $secrets = [$servicePassword, $evidence['admin_token'], $evidence['token']];
    $wait = static function (Closure $condition, string $message, float $seconds = 20): mixed {
        $until = microtime(true) + $seconds;
        do {
            $value = $condition();
            if ($value) {
                return $value;
            }
            usleep(30000);
        } while (microtime(true) < $until);
        throw new RuntimeException($message);
    };
    $create = static function (string $name) use ($request, $devices, $evidence, &$secrets): array {
        $result = $request(
            'POST',
            $devices,
            $evidence['admin_token'],
            $evidence['tenant'],
            ['name' => '集群撤权-' . $name, 'product_id' => $evidence['device']['product_id'], 'model_version' => 1],
            201
        )['data'];
        $secrets[] = $result['credential']['password'];
        return $result;
    };
    $read = static fn (array $created): array => $request('GET', $devices . '/' . $created['device']['id'], $evidence['token'], $evidence['tenant'], null, 200)['data'];
    $manage = static function (array $created, string $action) use ($request, $devices, $evidence, $read, &$secrets): array {
        $current = $read($created);
        $result = $request(
            'POST',
            $devices . '/' . $current['id'] . '/' . $action,
            $evidence['admin_token'],
            $evidence['tenant'],
            ['version' => (int) $current['version'], 'confirm_device_id' => $current['id']],
            202
        )['data'];
        if ($result['credential'] !== null) {
            $secrets[] = $result['credential']['password'];
        }
        return $result;
    };
    $enforced = static function (array $created) use ($wait, $read): void {
        $wait(static fn (): bool => $read($created)['authorization']['status'] === 'enforced', '集群撤权没有完成全部所有者隔离');
    };
    $ready = static function (Process $client) use ($wait): void {
        $wait(static function () use ($client): bool {
            expect($client->running(), '集群设备客户端准备失败：' . $client->stderr());
            return str_contains($client->stdout(), "ready\n");
        }, '集群设备客户端未就绪');
    };
    $finish = static function (Process $client): array {
        $result = $client->wait(25);
        expect($result->successful(), '集群设备客户端失败：' . $result->stderr);
        $lines = explode("\n", trim($result->stdout));
        return json_decode($lines[count($lines) - 1], true, 16, JSON_THROW_ON_ERROR);
    };
    // Closure捕获端口表的引用，确保启动后新节点对所有客户端可见。
    $spawn = static function (string $mode, array $created, string $node) use ($root, $environment, $base, &$ports, $devices, $evidence, $servicePassword, &$clients): array {
        $gate = $base . '/cluster-client-' . bin2hex(random_bytes(6));
        $fixture = ['credential' => $created['credential'], 'path' => $devices . '/' . $created['device']['id'], 'tenant' => $evidence['tenant'], 'token' => $evidence['token']];
        $client = new Process(['node', $root . '/tests/iot-lifecycle-mqtt.mjs', $mode], $root, array_replace(
            $environment,
            ['IOT_MQTT_PORT' => $ports[$node], 'TYPE_LIFECYCLE_FIXTURE' => json_encode($fixture, JSON_THROW_ON_ERROR),
                'TYPE_LIFECYCLE_GATE' => $gate, 'TYPE_INGESTION_PASSWORD' => $servicePassword]
        ));
        $clients[] = $client;
        return [$client, $gate];
    };
    $run = static function (string $mode, array $created, string $node) use ($spawn, $finish): array {
        [$client] = $spawn($mode, $created, $node);
        return $finish($client);
    };
    try {
        foreach (['t29-beta', 't29-gamma'] as $node) {
            $listener = stream_socket_server('tcp://127.0.0.1:0', $number, $message);
            expect(is_resource($listener), '无法准备设备集群端口');
            $ports[$node] = substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
            fclose($listener);
            $nodes[$node] = new Process([...$command, 'iot:mqtt'], $root, array_replace($environment, ['IOT_MQTT_PORT' => $ports[$node], 'IOT_MQTT_NODE_ID' => $node]));
        }
        $wait(static fn (): bool => (int) $database->query("SELECT COUNT(*) FROM type_mqtt_nodes WHERE state = 'active'")->fetchColumn() === 3, '三个原生设备Broker未注册');
        $wait(static fn (): bool => (int) $database->query('SELECT COUNT(*) FROM iot_authorization_invalidations WHERE completed_at IS NULL')->fetchColumn() === 0, '未处理已有管理意图');
        foreach (['rotate', 'revoke', 'disable', 'retire'] as $action) {
            $created = $create($action);
            [$oldClient] = $spawn('idle', $created, $environment['IOT_MQTT_NODE_ID']);
            $ready($oldClient);
            [$currentClient] = $spawn('idle', $created, 't29-beta');
            $ready($currentClient);
            $finish($oldClient);
            $owner = $database->prepare('SELECT node_id, generation FROM type_mqtt_sessions WHERE client_id = ?');
            $owner->execute([$created['device']['id']]);
            $current = $owner->fetch(PDO::FETCH_ASSOC);
            expect($current['node_id'] === 't29-beta' && (int) $current['generation'] === 2, '真实设备没有跨Broker恢复');
            $frozen = $nodes['t29-beta'];
            expect(posix_kill($frozen->pid(), SIGSTOP), '无法暂停本轮设备节点');
            $changed = $manage($created, $action);
            $wait(static function () use ($database, $created): bool {
                $fences = $database->prepare('SELECT COUNT(*) FROM type_mqtt_fences WHERE client_id = ?');
                $fences->execute([$created['device']['id']]);
                return (int) $fences->fetchColumn() === 1;
            }, '远端撤权未建立持久关闭意图');
            expect($read($created)['authorization']['status'] === 'pending', '旧节点暂停时首个Broker提前完成了全集群撤权');
            expect(posix_kill($frozen->pid(), SIGCONT), '无法恢复本轮设备节点');
            $frozen = null;
            $finish($currentClient);
            $enforced($created);
            $remaining = $database->prepare("SELECT (SELECT COUNT(*) FROM type_mqtt_sessions WHERE client_id = ?) + (SELECT COUNT(*) FROM type_mqtt_deliveries WHERE client_id = ? AND state = 'pending')");
            $remaining->execute([$created['device']['id'], $created['device']['id']]);
            expect((int) $remaining->fetchColumn() === 0, '集群撤权完成后残留旧会话或交付');
            foreach (array_keys($nodes) as $node) {
                $run('denied', $created, $node);
            }
            $checks[$action] = ['same-identity-restored-on-beta', 'old-node-suspended-keeps-global-invalidation-pending', 'old-socket-closed-before-enforced', 'old-credential-rejected-on-all-three-nodes'];
            if ($action === 'rotate') {
                [$fresh, $gate] = $spawn('new-identity', $changed, 't29-gamma');
                $ready($fresh);
                $repeat = $database->prepare('UPDATE iot_authorization_invalidations SET completed_at = NULL, node_id = NULL WHERE id = ?');
                $repeat->execute([$changed['device']['authorization']['id']]);
                $enforced($changed);
                file_put_contents($gate, 'late-old-intent-replayed');
                $run('control', $changed, 't29-gamma');
                $finish($fresh);
                $checks['rotate'][] = 'late-old-invalidation-keeps-new-credential-control';
            }
            $offline = $create('offline-' . $action);
            $run('offline', $offline, 't29-gamma');
            $run('control', $offline, 't29-gamma');
            $pending = $database->prepare("SELECT COUNT(*) FROM type_mqtt_deliveries WHERE client_id = ? AND state = 'pending'");
            $pending->execute([$offline['device']['id']]);
            expect((int) $pending->fetchColumn() === 1, '离线撤权装置缺少真实排队交付');
            $manage($offline, $action);
            $enforced($offline);
            $pending->execute([$offline['device']['id']]);
            expect((int) $pending->fetchColumn() === 0, '授权失效留下可跨节点恢复的离线投递');
            $run('denied', $offline, 't29-beta');
            $checks[$action][] = 'offline-delivery-cleared-and-other-node-resume-denied';
        }
        foreach ($nodes as $node => $process) {
            $result = $process->stop(20);
            expect($result->successful(), '设备集群正常退出失败：' . $node . $result->stderr);
            foreach ($secrets as $secret) {
                expect(!str_contains($result->stdout . $result->stderr, $secret), '设备集群日志泄漏凭据');
            }
            $counts = json_decode(trim($result->stdout), true, 32, JSON_THROW_ON_ERROR);
            foreach (['connections', 'subscriptions', 'bufferedBytes', 'pendingCommits', 'closingSessions', 'pendingFences', 'nodeFailures', 'invalidationFailures', 'quarantinedCommits'] as $field) {
                expect($counts[$field] === 0, '设备集群退出状态异常：' . $node . '/' . $field);
            }
            $statistics[$node] = $counts;
        }
        return ['client' => 'MQTT.js 5.15.0', 'tls' => true, 'cases' => $checks, 'statistics' => $statistics, 'E1' => 'independent-fault-domains-not-verified'];
    } finally {
        if ($frozen !== null && $frozen->running()) {
            posix_kill($frozen->pid(), SIGCONT);
        }
        foreach ($clients as $client) {
            $client->stop();
        }
        foreach ($nodes as $node => $process) {
            $result = $process->stop(20);
            file_put_contents($base . '/cluster-' . $node . '.log', $result->stdout . $result->stderr);
        }
    }
}
