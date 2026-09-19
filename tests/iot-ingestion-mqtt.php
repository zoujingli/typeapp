<?php

declare(strict_types=1);

use Type\Testing\Process;

/** 复用真实设备注册、TLS Broker和独立同步主备，验证业务回执与数据库效果的边界。 */
function iotIngestionMqttChecks(array $fixture, array $command, array $environment, array $clientEnvironment, string $base, PDO $database, Process $broker, #[SensitiveParameter] string $password, Closure $restartBroker): array
{
    if (in_array('--io-receipt-baseline', $GLOBALS['argv'], true)) {
        return iotReceiptPhaseBaseline($fixture, $command, $environment, $base, $database, $broker, $password);
    }
    $root = dirname(__DIR__);
    $workerEnvironment = array_replace($environment, [
        'IOT_INGESTION_PASSWORD' => $password, 'IOT_INGESTION_PORT' => $environment['IOT_MQTT_PORT'],
        'IOT_INGESTION_CA' => 'certificate.pem', 'IOT_INGESTION_INSTANCE' => 'test',
    ]);
    unset($workerEnvironment['IOT_INGESTION_SECRET_HASH']);
    $worker = new Process([...$command, 'iot:ingest'], $root, $workerEnvironment);
    $client = null;
    try {
        $until = microtime(true) + 12;
        do {
            if (!$worker->running()) {
                $stoppedBroker = $broker->stop(15);
                throw new RuntimeException('接收角色提前退出：' . $worker->stderr() . '；Broker：' . $stoppedBroker->stderr . $stoppedBroker->stdout);
            }
            $subscription = $database->query("SELECT subscriptions::text FROM type_mqtt_sessions WHERE client_id = 'iot-ingestion-test'")->fetchColumn();
            if (is_string($subscription) && str_contains($subscription, '$share/ingestion/')) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $until);
        expect(is_string($subscription) && str_contains($subscription, '$share/ingestion/'), '业务共享订阅未持久保存');
        if (in_array('--transfers-only', $GLOBALS['argv'], true)) {
            $clientEnvironment['TYPE_INGESTION_PASSWORD'] = $password;
            $resumeReceiver = static function (bool $requireExit = false) use (&$worker, $workerEnvironment, $command, $root, $base, $database): ?array {
                if ($worker->running() && !$requireExit) {
                    return null;
                }
                // 故障注入期间的协议拒绝按既定监督契约退出；仅恢复一次同名实例，原交付交由业务账本对账。
                $failed = $worker->wait(5);
                expect(!$failed->successful() && !$failed->timedOut && $failed->exitCode === 1, '故障接收角色没有完成预期退出');
                file_put_contents($base . '/transfer-receiver-exit.log', $failed->stderr . $failed->stdout);
                $original = $database->query("SELECT id, owner_id, generation FROM type_mqtt_sessions WHERE client_id = 'iot-ingestion-test'")->fetch(PDO::FETCH_ASSOC);
                expect(is_array($original), '退出接收角色没有保留原持久会话');
                $worker = new Process([...$command, 'iot:ingest'], $root, $workerEnvironment);
                $until = microtime(true) + 12;
                do {
                    expect($worker->running(), '同身份接收角色恢复失败：' . $worker->stderr());
                    $restored = $database->query("SELECT id, owner_id, generation, subscriptions::text FROM type_mqtt_sessions WHERE client_id = 'iot-ingestion-test'")->fetch(PDO::FETCH_ASSOC);
                    if (is_array($restored) && $restored['owner_id'] !== null && $restored['owner_id'] !== $original['owner_id']
                        && (int) $restored['generation'] > (int) $original['generation'] && str_contains($restored['subscriptions'], '$share/ingestion/')) {
                        expect($restored['id'] === $original['id'], '接收角色恢复创建了另一会话');
                        return ['exit' => $failed->exitCode, 'original_session_restored' => true, 'attempts' => 1];
                    }
                    usleep(50000);
                } while (microtime(true) < $until);
                throw new RuntimeException('同身份接收角色未在原预算恢复');
            };
            $transfers = iotDeviceTransferChecks($fixture, $command, $environment, $base, $worker, $clientEnvironment, $database, $resumeReceiver);
            $stopped = $worker->stop(15);
            expect($stopped->successful(), '冻结专项接收角色退出失败：' . $stopped->stderr);
            $statistics = json_decode(trim($stopped->stdout), true, 16, JSON_THROW_ON_ERROR);
            expect(!$statistics['pending'] && !$statistics['quarantined'] && !$statistics['running'], '冻结专项接收角色退出遗留资源');
            $brokerStopped = $broker->stop(20);
            expect($brokerStopped->successful(), '转移Broker退出失败：' . $brokerStopped->stderr);
            $brokerStatistics = json_decode(trim($brokerStopped->stdout), true, 32, JSON_THROW_ON_ERROR);
            foreach (['connections', 'subscriptions', 'eventRegistrations', 'eventTimers', 'readyEvents', 'pendingReads', 'bufferedBytes',
                'pendingCommits', 'closingSessions', 'pendingFences', 'nodeFailures', 'invalidationFailures', 'quarantinedCommits'] as $field) {
                expect($brokerStatistics[$field] === 0, '转移Broker退出后遗留资源：' . $field);
            }
            $quarantine = in_array('--operations', $GLOBALS['argv'], true) ? iotOperationsQuarantine($command, $environment, $fixture, $database) : null;
            return ['scope' => 'device-transfers-only', 'transfers' => $transfers, 'ingestion' => $statistics, 'broker' => $brokerStatistics,
                'operations_quarantine' => $quarantine, 'worker_stopped' => true];
        }
        if (in_array('--operations', $GLOBALS['argv'], true)) {
            return iotOperationsMqttChecks($fixture, $command, $environment, $workerEnvironment, $clientEnvironment, $base, $database, $broker, $worker, $password, $restartBroker);
        }
        if (in_array('--models-only', $GLOBALS['argv'], true)) {
            return ['scope' => 'device-models-only', 'model_switches' => iotDeviceModelSwitchChecks($fixture, $command, $environment, $base, $database)];
        }
        if (in_array('--support-mqtt', $GLOBALS['argv'], true)) {
            expect(false, '旧支持授权 MQTT 场景已退出生产候选；临时进入使用模拟登录');
        }
        if (in_array('--load-mqtt', $GLOBALS['argv'], true)) {
            require __DIR__ . '/iot-load.php';
            try {
                return iotLoadMqtt($command, $environment, $base, $database, $worker);
            } catch (Throwable $failure) {
                $stopped = $worker->stop(15);
                throw new RuntimeException($failure->getMessage() . '；接收进程：' . $stopped->stderr . $stopped->stdout . '；Broker：' . $broker->stderr(), 0, $failure);
            }
        }
        if (in_array('--control-only', $GLOBALS['argv'], true)) {
            return ['scope' => 'device-control-only', 'device_commands' => iotDeviceCommandChecks($fixture, $command, $environment, $base, $database, $worker, $workerEnvironment)];
        }
        $startedAt = microtime(true);
        $client = new Process(['node', $root . '/tests/iot-device-client.mjs', 'ingestion'], $root, $clientEnvironment + [
            'TYPE_INGESTION_PASSWORD' => $password,
        ]);
        // 顺序覆盖可靠接收、重复原件及授权；单次发布/回执仍各有硬截止，总预算另计装配与关闭。
        $result = $client->wait(300);
        $clientSeconds = microtime(true) - $startedAt;
        if (!$result->successful()) {
            $workerFailure = $worker->stop(15);
            $brokerFailure = $broker->stop(15);
            throw new RuntimeException('业务接收标准客户端失败（exit=' . $result->exitCode . ', timeout=' . (int) $result->timedOut . '）：'
                . $result->stderr . $result->stdout . $workerFailure->stderr . $workerFailure->stdout . $brokerFailure->stderr . $brokerFailure->stdout);
        }
        $cases = json_decode(trim($result->stdout), true, 16, JSON_THROW_ON_ERROR);
        $facts = $database->prepare('SELECT sequence FROM iot_ingestion_facts WHERE device_id = ? ORDER BY received_at, message_id');
        $facts->execute([$fixture['second']['device']['id']]);
        $sequences = $facts->fetchAll(PDO::FETCH_COLUMN);
        sort($sequences);
        expect($sequences === ['1', '2', '3', '8'], '重复或冲突生成了额外原始接收事实：' . json_encode($sequences));
        $receipt = $database->prepare('SELECT COUNT(*) FROM iot_ingestion_completed c JOIN iot_ingestion_facts f ON f.message_id = c.message_id WHERE f.device_id = ? AND c.consumer = ?');
        $receipt->execute([$fixture['second']['device']['id'], 'current']);
        expect((int) $receipt->fetchColumn() === 4, '当前消费者完成事实不匹配原始接收');
        $historyBrowser = null;
        if (in_array('--history-browser-only', $GLOBALS['argv'], true)) {
            $dist = realpath((string) getenv('TYPE_IOT_HISTORY_DIST'));
            expect(is_string($dist) && is_file($dist . '/index.html'), '真实上报页面验收需要TYPE_IOT_HISTORY_DIST');
            $browser = new Process(['node', $root . '/tests/iot-history-browser.mjs', '--mqtt', $base, $dist], $root, $clientEnvironment);
            try {
                $browserResult = $browser->wait(75);
                expect($browserResult->successful(), '真实上报页面验收失败：' . $browserResult->stderr . $browserResult->stdout);
                $historyBrowser = json_decode((string) file_get_contents($base . '/mqtt-history-browser-verification.json'), true, 16, JSON_THROW_ON_ERROR);
            } finally {
                $browser->stop();
            }
            $stopped = $worker->stop(15);
            expect($stopped->successful(), '页面验收后接收角色退出失败：' . $stopped->stderr);
            $statistics = json_decode(trim($stopped->stdout), true, 16, JSON_THROW_ON_ERROR);
            expect(!$statistics['pending'] && !$statistics['quarantined'] && !$statistics['running'], '页面验收后接收角色遗留资源');
            return ['scope' => 'real-mqtt-history-browser', 'cases' => $cases, 'facts' => count($sequences),
                'history_browser' => $historyBrowser, 'ingestion' => $statistics, 'worker_stopped' => true];
        }
        $deviceCommands = iotDeviceCommandChecks($fixture, $command, $environment, $base, $database, $worker, $workerEnvironment);
        $modelSwitches = iotDeviceModelSwitchChecks($fixture, $command, $environment, $base, $database);
        $stopped = $worker->stop(15);
        expect($stopped->successful(), '接收角色正常退出失败：' . $stopped->stderr);
        $statistics = json_decode(trim($stopped->stdout), true, 16, JSON_THROW_ON_ERROR);
        expect(!$statistics['pending'] && !$statistics['quarantined'] && !$statistics['running'], '接收角色退出未释放受管工作');
        expect($broker->running(), '业务拒绝导致整个Broker退出');
        foreach ([$password, hash('sha256', $password), $fixture['second']['credential']['password']] as $secret) {
            expect(!str_contains($stopped->stdout . $stopped->stderr . $broker->stdout() . $broker->stderr(), $secret), '接收角色日志泄漏凭据');
        }
        $fault = iotIngestionUnknownCommit($fixture, $command, $workerEnvironment, $clientEnvironment, $base, $database, $broker, $restartBroker);
        return ['client' => 'MQTT.js 5.15.0', 'tls' => true, 'cases' => $cases, 'client_seconds' => $clientSeconds, 'ingestion' => $statistics, 'unknown_commit' => $fault, 'device_commands' => $deviceCommands,
            'model_switches' => $modelSwitches, 'facts' => count($sequences), 'worker_stopped' => true, 'secrets_redacted' => true];
    } catch (Throwable $failure) {
        // 先读取归属和投递事实，再停止角色；清理本身会改变活跃会话，不能拿清理后的状态冒充故障现场。
        $snapshot = ['receiver_running' => $worker->running(), 'broker_running' => $broker->running(), 'observed_at' => microtime(true)];
        try {
            $snapshot['sessions'] = $database->query('SELECT id, client_id, owner_id, node_id, protocol, expiry, expires_at, subscriptions, updated_at FROM type_mqtt_sessions ORDER BY updated_at DESC LIMIT 40')->fetchAll(PDO::FETCH_ASSOC);
            $snapshot['nodes'] = $database->query('SELECT node_id, run_id, observed_at, expires_at FROM iot_broker_observations ORDER BY node_id LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
            $snapshot['members'] = $database->query('SELECT g.id AS group_id, g.filter, b.session_id FROM type_mqtt_shared_groups g JOIN type_mqtt_shared_members b ON b.group_id = g.id ORDER BY g.id, b.session_id LIMIT 40')->fetchAll(PDO::FETCH_ASSOC);
            $pendingRows = $database->query("SELECT d.id, d.client_id, d.session_id, d.packet_id, d.state, d.phase, d.started, d.group_id, d.created_at, encode(m.payload, 'base64') AS encoded FROM type_mqtt_deliveries d JOIN type_mqtt_messages m ON m.id = d.message_id WHERE d.state = 'pending' ORDER BY d.created_at, d.id LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($pendingRows as &$pendingRow) {
                $payload = json_decode(base64_decode($pendingRow['encoded']), true);
                unset($pendingRow['encoded']);
                $pendingRow['message'] = is_array($payload) ? array_intersect_key($payload, array_flip(['type', 'sequence', 'pending_count', 'pending_command_receipts', 'unresolved_commands'])) : null;
            }
            unset($pendingRow);
            $snapshot['pending'] = $pendingRows;
        } catch (Throwable $probeFailure) {
            $snapshot['probe_error'] = get_class($probeFailure);
        }
        $workerFailure = $worker->stop(15);
        $snapshot['receiver_stop'] = ['exit' => $workerFailure->exitCode, 'timeout' => $workerFailure->timedOut,
            'stdout' => str_replace([$password, hash('sha256', $password)], '<REDACTED>', $workerFailure->stdout),
            'stderr' => str_replace([$password, hash('sha256', $password)], '<REDACTED>', $workerFailure->stderr)];
        $brokerFailure = $broker->stop(15);
        $snapshot['broker_stop'] = ['exit' => $brokerFailure->exitCode, 'timeout' => $brokerFailure->timedOut,
            'stdout' => str_replace([$password, hash('sha256', $password)], '<REDACTED>', $brokerFailure->stdout),
            'stderr' => str_replace([$password, hash('sha256', $password)], '<REDACTED>', $brokerFailure->stderr)];
        file_put_contents($base . '/ingestion-failure-observation.json', json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        throw new RuntimeException($failure->getMessage() . '；接收进程：' . $snapshot['receiver_stop']['stderr'] . $snapshot['receiver_stop']['stdout']
            . '；Broker：' . $snapshot['broker_stop']['stderr'] . $snapshot['broker_stop']['stdout'], 0, $failure);
    } finally {
        $client?->stop();
        $worker->stop(15);
    }
}

/**
 * 通过公开客户端和编译持久角色分段测量；发布含 Broker 持久化/PUBACK，设备消费时间不是网络到达时间。
 * 只在独立接入装置中运行，PHP 控制采样，不给生产 IngestionWorker 添加计时或替代入口。
 */
function iotReceiptPhaseBaseline(array $fixture, array $command, array $environment, string $base, PDO $database, Process $broker, #[SensitiveParameter] string $password): array
{
    $trace = in_array('--io-receipt-db-trace', $GLOBALS['argv'], true);
    $samples = filter_var(getenv('TYPE_IO_SAMPLES') ?: ($trace ? '5' : '30'), FILTER_VALIDATE_INT);
    $warmupValue = getenv('TYPE_IO_WARMUP');
    $warmup = filter_var($warmupValue === false ? ($trace ? '1' : '5') : $warmupValue, FILTER_VALIDATE_INT);
    expect(is_int($samples) && $samples >= 2 && $samples <= 100 && is_int($warmup) && $warmup >= 0 && $warmup <= 20, '回执基线样本或预热数无效');
    $tracePath = (string) getenv('TYPE_IO_RECEIPT_TRACE_PATH');
    if ($trace) {
        expect($samples <= 5 && $warmup <= 1, '数据库诊断限制每轮最多5条正常样本和1条预热，避免耗尽日志预算');
        expect(basename($tracePath) === 'receipt-phases.json' && !file_exists($tracePath) && !is_link($tracePath)
            && str_starts_with((string) realpath(dirname($tracePath)), dirname(__DIR__) . '/build/'), '数据库诊断需要外层隔离入口提供新的阶段文件');
    }
    $credential = $fixture['second']['credential'];
    $device = new Type\Mqtt\Client(
        '127.0.0.1',
        (int) $environment['IOT_MQTT_PORT'],
        $credential['client_id'],
        $credential['username'],
        $credential['password'],
        $base . '/certificate.pem'
    );
    $receiver = new Type\Mqtt\Client(
        '127.0.0.1',
        (int) $environment['IOT_MQTT_PORT'],
        'iot-ingestion-io-baseline',
        'service:ingestion:' . $environment['IOT_INGESTION_CREDENTIAL_ID'],
        $password,
        $base . '/certificate.pem'
    );
    $previous = [];
    $pending = null;
    $report = ['scope' => 'io-receipt-phases', 'client_execution' => 'php-public-client-and-pending-commit',
        'seed' => 'type-io-receipt-v1', 'rounds' => [], 'warmup' => $warmup, 'samples' => $samples, 'concurrency' => 1,
        'tls' => true, 'client_connections' => 2, 'maximum_local_ingest_workers' => 1, 'poll_interval_ms' => 10];
    $sequence = 0;
    try {
        // PendingCommit 子角色继承进程环境；临时替换仅存在于本测试进程，finally 恢复。
        foreach ($environment as $key => $value) {
            $previous[$key] = getenv($key);
            expect(putenv($key . '=' . $value), '无法设置隔离持久角色环境');
        }
        $device->connect();
        expect($device->subscribe($credential['topics']['subscribe']) === 1, '设备回执订阅未完成');
        $receiver->connect();
        expect($receiver->subscribe('$share/ingestion/iot/+/devices/+/epochs/+/up') === 1, '接收角色共享订阅未完成');
        $database->exec('CREATE TABLE io_receipt_delay_hits (id INTEGER NOT NULL)');
        $database->exec('CREATE FUNCTION io_receipt_delay() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.topic = '
            . $database->quote($credential['topics']['subscribe'])
            . ' THEN PERFORM pg_sleep(0.05); INSERT INTO io_receipt_delay_hits VALUES (1); END IF; RETURN NEW; END $$');
        if ($trace) {
            // 只修改外层入口持有并将在 finally 停止的隔离主库；不记录 SQL 文本和参数。
            $settings = ['log_line_prefix' => 'IO|%n|%p|%c|%a|', 'log_connections' => 'on', 'log_disconnections' => 'on',
                'log_duration' => 'on', 'log_statement' => 'none', 'log_min_duration_statement' => '-1',
                'log_min_duration_sample' => '-1', 'log_parameter_max_length' => '0', 'log_parameter_max_length_on_error' => '0',
                'log_min_error_statement' => 'panic', 'log_error_verbosity' => 'terse'];
            foreach ($settings as $key => $value) {
                $database->exec('ALTER SYSTEM SET ' . $key . ' = ' . $database->quote($value));
            }
            expect($database->query('SELECT pg_reload_conf()')->fetchColumn(), '无法启用隔离主库日志');
            $until = microtime(true) + 3;
            do {
                $probe = new PDO(
                    'pgsql:host=127.0.0.1;port=' . $environment['DB_PORT'] . ';dbname=' . $environment['DB_DATABASE'] . ';connect_timeout=1',
                    $environment['DB_USERNAME'],
                    $environment['DB_PASSWORD'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $actual = [];
                foreach ($settings as $key => $value) {
                    $actual[$key] = (string) $probe->query('SHOW ' . $key)->fetchColumn();
                }
                $probe = null;
                if ($actual === $settings) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $until);
            expect($actual === $settings, '新数据库连接未继承完整诊断设置');
            $report['database_trace_settings'] = $actual;
        }
        $send = static function () use (&$sequence, &$pending, $device, $receiver, $credential, $fixture, $command, $database, $trace): array {
            $sequence++;
            $payload = json_encode(['app_version' => 1, 'type' => 'telemetry', 'device_id' => $credential['client_id'],
                'ownership_id' => $fixture['second']['device']['ownership_id'], 'model_version' => 1,
                'sequence' => (string) $sequence, 'sampled_at' => time(), 'values' => ['temperature' => 20 + $sequence % 21]], JSON_THROW_ON_ERROR);
            expect($device->publish(new Type\Mqtt\Message($credential['topics']['publish'], $payload, '', 1)) === 0, '上报未取得传输确认');
            $delivery = $receiver->receive(5.0);
            expect($delivery !== null && $delivery['message']->payload === $payload && $delivery['receipt'] !== '', '接收角色没有取得原始上报');
            $persistStarted = hrtime(true);
            $persistEpoch = $trace ? microtime(true) * 1000 : 0;
            $pending = new Type\Mqtt\PendingCommit([...$command, 'iot:ingest-store'], ['operation_id' => bin2hex(random_bytes(16)),
                'action' => 'ingest', 'topic' => $credential['topics']['publish'], 'payload' => base64_encode($payload), 'qos' => 1, 'received_at' => time()]);
            do {
                $result = $pending->poll();
                if ($result === null) {
                    usleep(10000);
                }
            } while ($result === null);
            $persistFinished = hrtime(true);
            $persistedEpoch = $trace ? microtime(true) * 1000 : 0;
            $pending = null;
            expect($result->state === 'committed' && $result->released, '持久结果未确认，不允许发送成功回执');
            $receipt = $result->value['receipt'];
            expect($receipt['status'] === 'accepted' && $receipt['sequence'] === (string) $sequence, '持久角色未返回本次接受回执');
            $message = new Type\Mqtt\Message($result->value['topic'], json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), '', 1);
            $publishStarted = hrtime(true);
            $publishEpoch = $trace ? microtime(true) * 1000 : 0;
            $reason = $receiver->publish($message);
            $published = hrtime(true);
            $publishedEpoch = $trace ? microtime(true) * 1000 : 0;
            expect($reason === 0 && $receiver->statistics()['unacknowledged'] === 1, '回执发布失败或提前确认了上报');
            $received = $device->receive(5.0);
            $consumed = hrtime(true);
            $consumedEpoch = $trace ? microtime(true) * 1000 : 0;
            expect(
                $received !== null && $received['message']->topic === $message->topic && $received['message']->payload === $message->payload,
                '设备收到的回执与已证明持久结果不一致'
            );
            $device->acknowledge($received['receipt']);
            $receiver->acknowledge($delivery['receipt']);
            $fact = $database->prepare('SELECT i.content_hash FROM iot_ingestion_facts f '
                . 'JOIN iot_ingestion i ON i.message_id = f.message_id WHERE f.device_id = ? AND f.sequence = ?');
            $fact->execute([$credential['client_id'], (string) $sequence]);
            expect($fact->fetchColumn() === $receipt['content_hash'] && $fact->fetchColumn() === false, '回执与唯一持久事实不符');
            $observation = ['sequence' => $sequence, 'payload_sha256' => hash('sha256', $payload), 'receipt_sha256' => hash('sha256', $message->payload),
                'receipt_bytes' => strlen($message->payload), 'persist_worker_ms' => ($persistFinished - $persistStarted) / 1e6,
                'receipt_publish_puback_ms' => ($published - $publishStarted) / 1e6, 'publish_to_device_consumed_ms' => ($consumed - $publishStarted) / 1e6];
            if ($trace) {
                $observation['epoch_ms'] = ['persist_started' => $persistEpoch, 'persist_finished' => $persistedEpoch,
                    'publish_started' => $publishEpoch, 'puback' => $publishedEpoch, 'device_consumed' => $consumedEpoch];
            }
            return $observation;
        };
        for ($round = 0; $round < 3; $round++) {
            $row = ['samples' => [], 'warmup_observations' => [], 'slow_dependency' => null];
            for ($index = 0; $index < $warmup + $samples; $index++) {
                $observation = $send();
                if ($index < $warmup) {
                    $row['warmup_observations'][] = $observation;
                } else {
                    $row['samples'][] = $observation;
                }
            }
            $database->exec('CREATE TRIGGER io_receipt_delay BEFORE INSERT ON type_mqtt_messages FOR EACH ROW EXECUTE FUNCTION io_receipt_delay()');
            try {
                $row['slow_dependency'] = $send();
            } finally {
                $database->exec('DROP TRIGGER io_receipt_delay ON type_mqtt_messages');
            }
            expect((int) $database->query('SELECT COUNT(*) FROM io_receipt_delay_hits')->fetchColumn() === $round + 1, '慢依赖未精确作用于本轮回执发布');
            $row['slow_dependency']['delay_ms'] = 50;
            $row['slow_dependency']['trigger_hits'] = 1;
            foreach (['persist_worker_ms', 'receipt_publish_puback_ms', 'publish_to_device_consumed_ms'] as $phase) {
                $ordered = array_column($row['samples'], $phase);
                sort($ordered, SORT_NUMERIC);
                $row['percentiles'][$phase] = ['p50' => $ordered[(int) ceil($samples * 0.5) - 1],
                    'p95' => $ordered[(int) ceil($samples * 0.95) - 1], 'p99' => $ordered[(int) ceil($samples * 0.99) - 1]];
            }
            $report['rounds'][] = $row;
        }
        $count = $database->prepare('SELECT COUNT(*) FROM iot_ingestion_facts WHERE device_id = ?');
        $count->execute([$credential['client_id']]);
        expect((int) $count->fetchColumn() === $sequence, '样本外产生额外上报事实');
        expect($broker->running(), '回执基线期间 Broker 提前退出');
        $report['accepted_facts'] = $sequence;
    } finally {
        $pendingReleased = true;
        if ($pending !== null) {
            $pending->cancel();
            do {
                $closed = $pending->poll();
                if ($closed === null) {
                    usleep(10000);
                }
            } while ($closed === null);
            $pendingReleased = $closed->released;
        }
        $device->close(false);
        $receiver->close(false);
        foreach ($previous as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
        $database->exec('DROP TRIGGER IF EXISTS io_receipt_delay ON type_mqtt_messages');
        $database->exec('DROP FUNCTION IF EXISTS io_receipt_delay()');
        $database->exec('DROP TABLE IF EXISTS io_receipt_delay_hits');
        expect($pendingReleased, '回执基线持久角色无法证明远端资源回收');
    }
    $report['clients_after_close'] = [$device->statistics(), $receiver->statistics()];
    foreach ($report['clients_after_close'] as $statistics) {
        expect(!$statistics['connected'] && $statistics['buffered_bytes'] === 0 && $statistics['unacknowledged'] === 0, '测量客户端没有释放资源');
    }
    $stopped = $broker->stop(20);
    expect($stopped->successful(), '回执基线 Broker 未正常退出：' . $stopped->stderr);
    $report['broker_after_stop'] = json_decode(trim($stopped->stdout), true, 32, JSON_THROW_ON_ERROR);
    foreach (['connections', 'subscriptions', 'eventRegistrations', 'eventTimers', 'readyEvents', 'pendingReads', 'bufferedBytes',
        'pendingCommits', 'closingSessions', 'pendingFences', 'nodeFailures', 'invalidationFailures', 'quarantinedCommits'] as $field) {
        expect($report['broker_after_stop'][$field] === 0, '回执基线 Broker 退出后遗留资源：' . $field);
    }
    if ($trace) {
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        expect(file_put_contents($tracePath, $json) === strlen($json) && chmod($tracePath, 0600), '无法保存数据库诊断阶段');
    }
    return $report;
}

/** 双方HTTP审批与正式设备CLI使用真实TLS完成冻结、原缓存回执和重启；设备不可访问平台数据库。 */
function iotDeviceTransferChecks(array $fixture, array $command, array $environment, string $base, Process $worker, array $clientEnvironment, PDO $database, Closure $resumeReceiver): array
{
    $root = dirname(__DIR__);
    $source = $fixture['tenant'];
    $target = $fixture['transfer_target'];
    $http = new Type\Testing\HttpClient('http://127.0.0.1:' . $environment['APP_PORT']);
    $call = static function (string $method, string $path, string $tenant, ?array $data = null, int $expected = 200) use ($http, $fixture, $source): array {
        $response = $http->request($method, $path, ['Authorization' => 'Bearer ' . ($tenant === $source ? $fixture['admin_token'] : $fixture['transfer_target_token']),
            'X-Tenant-Id' => $tenant, 'Content-Type' => 'application/json'], $data === null ? '' : json_encode($data, JSON_THROW_ON_ERROR));
        expect($response->status === $expected, '真实转移HTTP结果不符：' . $response->body);
        return $response->json();
    };
    $prefix = '/customer/tenants/' . $source;
    $targetPrefix = '/customer/tenants/' . $target;
    $registration = $call('POST', $prefix . '/devices', $source, ['name' => '真实TLS转移冻结设备',
        'product_id' => $fixture['registration']['device']['product_id'], 'model_version' => 1], 201)['data'];
    $device = $registration['device'];
    $devicePath = $prefix . '/devices/' . $device['id'];
    $model = $call('GET', $devicePath, $source)['data']['model'];
    $deviceEnvironment = array_replace($environment, ['IOT_DEVICE_CACHE' => 'transfer-device.sqlite', 'IOT_DEVICE_ID' => $device['id'],
        'IOT_DEVICE_OWNERSHIP_ID' => $device['ownership_id'], 'IOT_DEVICE_TENANT_ID' => $source, 'IOT_DEVICE_MODEL_VERSION' => '1',
        'IOT_DEVICE_CREDENTIAL_ID' => $registration['credential']['id'], 'IOT_DEVICE_PASSWORD' => $registration['credential']['password'],
        'IOT_DEVICE_HOST' => '127.0.0.1', 'IOT_DEVICE_PORT' => $environment['IOT_MQTT_PORT'], 'IOT_DEVICE_CA' => 'certificate.pem',
        'IOT_DEVICE_PEER_NAME' => '127.0.0.1', 'IOT_DEVICE_SUPPORTED_MODELS' => json_encode([1 => $model['structure_hash']], JSON_THROW_ON_ERROR),
        'DB_HOST' => '192.0.2.1', 'DB_PORT' => '1']);
    $run = static function (string $action, ?array $input = null, bool $success = true, array $overrides = []) use ($command, $deviceEnvironment, $root, $base): array {
        $inputPath = $input === null ? null : $base . '/transfer-device-input.json';
        if ($inputPath !== null) {
            file_put_contents($inputPath, json_encode($input, JSON_THROW_ON_ERROR));
        }
        $runtimeEnvironment = array_replace($deviceEnvironment, $overrides);
        $process = new Process([...$command, 'iot:device', $action], $root, $runtimeEnvironment, 65536, $inputPath);
        try {
            $result = $process->wait(65);
            expect(!$result->timedOut && $result->successful() === $success, '转移设备命令失败：' . $result->stderr . $result->stdout);
            expect(!str_contains($result->stderr . $result->stdout, $runtimeEnvironment['IOT_DEVICE_PASSWORD']), '转移设备输出泄漏凭据');
            return trim($result->stdout) === '' ? [] : json_decode(trim($result->stdout), true, 32, JSON_THROW_ON_ERROR);
        } finally {
            $process->stop();
        }
    };
    $run('enqueue', ['type' => 'telemetry', 'sampled_at' => time(), 'values' => ['temperature' => 20]]);
    expect($run('send')['delivery']['accepted'] === 1, '转移设备首次接入未取得真实回执');
    $old = $run('enqueue', ['type' => 'telemetry', 'sampled_at' => time(), 'values' => ['temperature' => 21]]);
    $version = (int) $call('GET', $devicePath, $source)['data']['version'];
    $intent = ['transfer_id' => bin2hex(random_bytes(16)), 'device_id' => $device['id'], 'target_tenant_id' => $target, 'version' => $version];
    $requested = $call('POST', $prefix . '/transfers', $source, $intent, 202)['data'];
    $targetPath = $targetPrefix . '/transfers/' . $intent['transfer_id'];
    $sourcePath = $prefix . '/transfers/' . $intent['transfer_id'];
    $decision = ['action' => 'accept', 'decision_id' => bin2hex(random_bytes(16)), 'copy_name' => 'TLS接收自有产品', 'version' => (int) $requested['device_version']];
    $frozen = $call('POST', $targetPath, $target, $decision, 202)['data'];
    expect($frozen['status'] === 'frozen' && !$frozen['ready_for_switch'], 'HTTP审批冒充设备排空');
    $call('POST', $devicePath . '/commands', $source, ['command_id' => bin2hex(random_bytes(16)), 'identifier' => 'switch', 'values' => ['on' => true], 'version' => (int) $frozen['device_version']], 409);
    $listener = new Process([...$command, 'iot:device', 'listen'], $root, $deviceEnvironment);
    try {
        $until = microtime(true) + 45;
        $observed = [];
        do {
            expect($worker->running(), '接收角色在设备冻结排空阶段退出：' . $worker->stderr() . $worker->stdout());
            expect($listener->running(), '转移监听提前退出：' . $listener->stderr() . $listener->stdout());
            $observed = $call('GET', $sourcePath, $source)['data'];
            if ($observed['ready_for_switch']) {
                break;
            } usleep(50000);
        } while (microtime(true) < $until);
        expect($observed['device_status'] !== null && $observed['device_status']['supported'], 'TLS设备没有确认持久冻结及目标支持：' . json_encode($observed));
        expect($observed['device_status']['pending_count'] === 0 && $observed['ready_for_switch'], '设备监听排空原缓存后未更新就绪条件：' . json_encode($observed, JSON_THROW_ON_ERROR));
        expect($call('POST', $targetPath, $target, $decision, 202)['data']['target_product_id'] === $frozen['target_product_id'], '真实审批重复复制目标产品');
    } finally {
        $running = $listener->running();
        $stopped = $listener->stop(15);
        if ($running) {
            expect($stopped->successful(), '转移监听退出失败：' . $stopped->stderr);
        }
    }
    $state = $run('state');
    expect($state['transfer_id'] === $intent['transfer_id'] && $state['pending_count'] === 0 && $state['accepted_total'] === 2, '设备重启遗失冻结或未通过业务回执排空原缓存');
    $run('enqueue', ['type' => 'telemetry', 'sampled_at' => time(), 'values' => ['temperature' => 22]], false);
    $previousSequence = $observed['device_status_sequence'];
    $listener = new Process([...$command, 'iot:device', 'listen'], $root, $deviceEnvironment);
    try {
        $until = microtime(true) + 35;
        do {
            expect($worker->running(), '接收角色在冻结设备重启阶段退出：' . $worker->stderr() . $worker->stdout());
            expect($listener->running(), '排空确认监听提前退出：' . $listener->stderr());
            $observed = $call('GET', $sourcePath, $source)['data'];
            if ($observed['ready_for_switch'] && $observed['device_status_sequence'] !== $previousSequence) {
                break;
            } usleep(50000);
        } while (microtime(true) < $until);
        expect($observed['ready_for_switch'] && $observed['status'] === 'frozen' && $observed['device_status_sequence'] !== $previousSequence, '重启后条件未重新确认或提前切换归属：' . json_encode($observed));
        $current = $call('GET', $devicePath, $source)['data'];
        expect($current['ownership_id'] === $device['ownership_id'] && $current['credential_active'] && $call('GET', $devicePath . '/current', $source)['data']['sequence'] === $old['sequence'], '源归属、凭据或原历史提前转移');
        $call('GET', $targetPrefix . '/devices/' . $device['id'], $target, null, 404);
        $call('GET', $targetPath, $target);
    } finally {
        $stopped = $listener->stop(15);
        expect($stopped->successful(), '排空监听未正常释放资源：' . $stopped->stderr);
    }
    $switch = in_array('--transfer-switch', $GLOBALS['argv'], true)
        ? iotDeviceTransferSwitchChecks($call, $run, $fixture, $registration, $intent, $clientEnvironment, $database, $base, $command, $environment, $resumeReceiver) : null;
    return ['tls' => true, 'independent_admins' => true, 'platform_database_unavailable_to_device' => true, 'original_cache_receipt' => true,
        'durable_freeze_after_restart' => true, 'new_sampling_rejected' => true, 'ready_still_frozen' => true, 'ownership_and_credential_preserved' => true, 'processes_stopped' => true, 'switch' => $switch];
}

/** 复用已真实冻结设备、标准TLS客户端与设备CLI；数据库只读观察，隔离完成从不由装置补写。 */
function iotDeviceTransferSwitchChecks(Closure $call, Closure $run, array $fixture, array $registration, array $intent, array $clientEnvironment, PDO $database, string $base, array $command, array $environment, Closure $resumeReceiver): array
{
    $source = $fixture['tenant'];
    $target = $fixture['transfer_target'];
    $device = $registration['device'];
    $sourceDevice = '/customer/tenants/' . $source . '/devices/' . $device['id'];
    $targetDevice = '/customer/tenants/' . $target . '/devices/' . $device['id'];
    $targetPath = '/customer/tenants/' . $target . '/transfers/' . $intent['transfer_id'];
    $wait = static function (Closure $probe, string $message, float $seconds = 20): mixed {
        $until = microtime(true) + $seconds;
        do {
            $value = $probe();
            if ($value) {
                return $value;
            } usleep(50000);
        } while (microtime(true) < $until);
        throw new RuntimeException($message);
    };
    $clientFixture = ['credential' => $registration['credential'], 'path' => $sourceDevice, 'tenant' => $source, 'token' => $fixture['admin_token'],
        'ownership_id' => $device['ownership_id'], 'transfer_id' => $intent['transfer_id']];
    $transferRecord = $call('GET', $targetPath, $target)['data'];
    $clientFixture['freeze'] = ['app_version' => 1, 'type' => 'transfer_freeze', 'device_id' => $device['id'], 'ownership_id' => $device['ownership_id'],
        'transfer_id' => $intent['transfer_id'], 'source_model_version' => (int) $transferRecord['source_model_version'],
        'target_tenant_id' => $target, 'target_product_id' => $transferRecord['target_product_id'],
        'target_model_version' => (int) $transferRecord['target_model_version'], 'structure_hash' => $transferRecord['structure_hash']];
    $faults = in_array('--transfer-faults', $GLOBALS['argv'], true);
    $beta = null;
    $paused = false;
    $faultCases = [];
    $betaStatistics = [];
    $receiverRecovery = null;
    $operations = [];
    $observeOperations = $faults && in_array('--operations', $GLOBALS['argv'], true);
    $betaEnvironment = $environment;
    $network = static function (string $mode, array $data, array $overrides = []) use ($clientEnvironment): array {
        $process = new Process(['node', dirname(__DIR__) . '/tests/iot-lifecycle-mqtt.mjs', $mode], dirname(__DIR__), array_replace(
            $clientEnvironment,
            ['TYPE_LIFECYCLE_FIXTURE' => json_encode($data, JSON_THROW_ON_ERROR)],
            $overrides
        ));
        try {
            $result = $process->wait(20);
            expect($result->successful(), '转移网络故障装置失败：' . $mode . ' ' . $result->stderr);
            return json_decode(trim($result->stdout), true, 16, JSON_THROW_ON_ERROR);
        } finally {
            $process->stop();
        }
    };
    $drop = static function (string $path, array $body, string $tenant, string $token) use ($network, $clientFixture): void {
        $network('transfer-response-drop', array_replace($clientFixture, ['tenant' => $tenant, 'token' => $token, 'operation' => ['path' => $path, 'body' => $body]]));
    };
    $single = static function (int $active, string $ownership) use ($database, $device): void {
        $query = $database->prepare("SELECT (SELECT COUNT(*) FROM iot_device_credentials WHERE device_id = ? AND status = 'active') AS credentials,"
            . ' (SELECT COUNT(*) FROM iot_device_ownerships WHERE device_id = ? AND ended_at IS NULL) AS ownerships,'
            . ' (SELECT ownership_id FROM iot_devices WHERE id = ?) AS ownership_id');
        $query->execute([$device['id'], $device['id'], $device['id']]);
        $state = $query->fetch(PDO::FETCH_ASSOC);
        expect((int) $state['credentials'] === $active && (int) $state['ownerships'] === 1 && $state['ownership_id'] === $ownership, '故障阶段存在双重有效归属或错误凭据');
    };
    $startBeta = static function () use ($command, &$betaEnvironment, $wait, $database): Process {
        // 持久节点隔离不等于资源观察失效；硬退出没有stopped回调，按既有15秒期限等待，不补写观察表。
        $wait(static function () use ($database): bool {
            $expires = $database->query("SELECT expires_at FROM broker_resource_runs WHERE node_id = 't32-beta'")->fetchColumn();
            return $expires === false || (int) $expires <= time();
        }, '旧节点资源观察尚未到期，禁止以新运行覆盖', 20.0);
        $process = new Process([...$command, 'iot:mqtt'], dirname(__DIR__), $betaEnvironment);
        $wait(static function () use ($database, $process, $betaEnvironment): bool {
            expect($process->running(), '转移第二节点启动失败：' . $process->stderr());
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $betaEnvironment['IOT_MQTT_PORT'], $number, $message, 0.1);
            if (!is_resource($socket)) {
                return false;
            }
            fclose($socket);
            return (int) $database->query('SELECT COUNT(*) FROM type_mqtt_nodes n JOIN iot_broker_observations b ON b.node_id = n.node_id'
                . ' JOIN broker_resource_runs r ON r.node_id = b.node_id AND r.run_id = b.run_id'
                . ' JOIN iot_runtime_metrics m ON m.node_id = b.node_id AND m.run_id = b.run_id'
                . " WHERE n.node_id = 't32-beta' AND n.state = 'active' AND m.kind = 'broker'"
                . " AND (m.metrics_json::json->>'nodeGeneration')::bigint = n.generation"
                . ' AND r.expires_at > EXTRACT(EPOCH FROM clock_timestamp()) AND b.expires_at > EXTRACT(EPOCH FROM clock_timestamp())')->fetchColumn() === 1;
        }, '转移第二节点没有注册');
        return $process;
    };
    $killBeta = static function (Process $process, string $stage, bool $alreadyPaused) use ($wait, $database, $base, $command, $environment): void {
        $runId = $database->query("SELECT run_id FROM type_mqtt_nodes WHERE node_id = 't32-beta'")->fetchColumn();
        expect(is_string($runId), '待隔离节点缺少实际运行身份');
        if (!$alreadyPaused) {
            expect(posix_kill($process->pid(), SIGSTOP), '无法暂停待硬退出节点');
        }
        // 暂停的父进程无法回收已退出worker；先确认无执行者，再硬退出父进程并核对全部PID消失。
        $parent = $process->pid();
        $workers = [];
        $wait(static function () use ($parent, &$workers): bool {
            $workers = unixProcessStates($parent);
            foreach ($workers as $worker) {
                if (!str_starts_with($worker['state'], 'Z')) {
                    return false;
                }
            }
            return true;
        }, '旧节点尚有执行中的工作进程，禁止登记硬隔离', 12.0);
        expect(posix_kill($parent, SIGKILL), '无法退出本轮转移节点');
        $killed = $process->wait(5);
        file_put_contents($base . '/transfer-beta-' . $stage . '-kill.log', $killed->stdout . $killed->stderr);
        expect(!$killed->successful() && !$killed->timedOut && !$process->running(), '节点未实际硬退出');
        $ownedPids = array_fill_keys([$parent, ...array_keys($workers)], true);
        $wait(static fn (): bool => array_intersect_key(unixProcessStates(), $ownedPids) === [], '硬退出后的节点或worker记录尚未回收', 5.0);
        file_put_contents($base . '/transfer-beta-' . $stage . '-worker-observation.json', json_encode([
            'parent_pid' => $parent, 'workers_before_parent_exit' => $workers,
            'all_owned_pids_absent_after_parent_exit' => true,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        $proof = identityCommand([...$command, 'iot:mqtt-fence', 't32-beta', $runId, 'transfer-test', 'owned_process_exited_children_absent'], $environment);
        expect($proof['fenced'], '实际硬退出后的公开隔离登记没有同步确认');
        $operation = $database->prepare('SELECT stage, result FROM admin_broker_operations WHERE operation_id = ?');
        $operation->execute([$proof['operation_id']]);
        expect($operation->fetch(PDO::FETCH_ASSOC) === ['stage' => 'completed', 'result' => 'success'], '隔离完成没有写入新平台操作账本');
        $audit = $database->prepare('SELECT COUNT(*) FROM admin_audit WHERE operation_id = ?');
        $audit->execute([$proof['operation_id']]);
        $events = (int) $audit->fetchColumn();
        expect($events === 3, '隔离受理、执行、完成阶段没有落入新平台审计');
        $replayed = identityCommand([...$command, 'iot:mqtt-fence-result', $proof['operation_id']], $environment);
        expect($replayed['fenced'] && $replayed['event_id'] === $proof['event_id'], '隔离对账没有返回原完成事实');
        $audit->execute([$proof['operation_id']]);
        expect((int) $audit->fetchColumn() === $events, '只读对账生成了重复隔离审计');
    };
    if ($faults) {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $number, $message);
        expect(is_resource($listener), '无法分配转移第二节点端口');
        $betaEnvironment['IOT_MQTT_PORT'] = substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        $betaEnvironment['IOT_MQTT_NODE_ID'] = 't32-beta';
        fclose($listener);
        $beta = $startBeta();
        $clientEnvironment['IOT_MQTT_PORT'] = $betaEnvironment['IOT_MQTT_PORT'];
    }
    $clientEnvironment['TYPE_LIFECYCLE_FIXTURE'] = json_encode($clientFixture, JSON_THROW_ON_ERROR);
    $clientEnvironment['TYPE_LIFECYCLE_GATE'] = $base . '/transfer-unused-gate';
    $old = new Process(['node', dirname(__DIR__) . '/tests/iot-lifecycle-mqtt.mjs', $faults ? 'transfer-hold' : 'transfer-idle'], dirname(__DIR__), $clientEnvironment);
    try {
        $wait(static function () use ($old): bool {
            expect($old->running(), '旧阶段标准客户端准备失败：' . $old->stderr());
            return str_contains($old->stdout(), "ready\n");
        }, '旧阶段标准客户端未建立真实订阅');
        $network('transfer-freeze-replay', $clientFixture);
        $wait(static function () use ($old): bool {
            expect($old->running(), '重发冻结观察客户端提前退出：' . $old->stderr());
            return str_contains($old->stdout(), "freeze-checked\n");
        }, '当前转移的重发冻结通知未被真实客户端完整核对', 5.0);
        $ready = $call('GET', $targetPath, $target)['data'];
        expect($ready['ready_for_switch'], '正式切换前缺失新鲜冻结证明');
        if ($observeOperations) {
            $operations['connected'] = iotOperationsWait(
                static fn (): array => iotOperationsPlatform($environment, $fixture),
                static function (array $view): bool {
                    $brokers = array_values(array_filter($view['nodes'], static fn (array $node): bool => $node['kind'] === 'broker'));
                    return count($brokers) === 2 && $view['store']['state'] === 'available'
                        && count(array_filter($brokers, static fn (array $node): bool => $node['state'] === 'reporting')) === 2
                        && count(array_filter($brokers, static fn (array $node): bool => $node['node_id'] === 't32-beta' && $node['metrics']['deviceConnections'] > 0)) === 1;
                },
                '运行概览未显示两个真实Broker与第二节点设备连接'
            );
        }
        if ($faults) {
            $drop('/customer/tenants/' . $source . '/transfers', $intent, $source, $fixture['admin_token']);
            $single(1, $device['ownership_id']);
            $owner = $database->prepare('SELECT node_id FROM type_mqtt_sessions WHERE client_id = ?');
            $owner->execute([$device['id']]);
            expect($owner->fetchColumn() === 't32-beta', '冻结设备未在第二节点恢复持久会话');
            expect(posix_kill($beta->pid(), SIGSTOP), '无法暂停本轮旧归属节点');
            $paused = true;
            $network('transfer-retained', $clientFixture);
            $queued = $database->prepare('SELECT COUNT(*) FROM type_mqtt_deliveries d JOIN type_mqtt_messages m ON m.id = d.message_id'
                . " WHERE d.client_id = ? AND d.state = 'pending' AND m.payload = decode(?, 'hex')");
            $queued->execute([$device['id'], bin2hex("lifecycle-control\0binary")]);
            expect((int) $queued->fetchColumn() === 1, '旧阶段没有通过第一节点生成真实排队载荷');
            $retained = $database->prepare('SELECT COUNT(*) FROM type_mqtt_retained WHERE topic = ?');
            $retained->execute([$registration['credential']['topics']['subscribe']]);
            expect((int) $retained->fetchColumn() === 1, '旧归属保留消息装置未持久提交');
            $faultCases['frozen'] = ['native-device-disconnect-and-restart-still-frozen', 'duplicate-request-response-dropped-caller-exited',
                'same-session-restored-on-beta', 'alpha-publishes-real-pending-and-retained-old-payload'];
        }
        $switch = ['action' => 'switch', 'switch_id' => bin2hex(random_bytes(16)), 'version' => (int) $ready['device_version']];
        if ($faults) {
            $drop($targetPath, $switch, $target, $fixture['transfer_target_token']);
            $isolating = $call('POST', $targetPath, $target, $switch, 202)['data'];
        } else {
            $isolating = $call('POST', $targetPath, $target, $switch, 202)['data'];
        }
        expect($isolating['status'] === 'isolating' && $isolating['credential'] === null && $isolating['new_ownership_id'] === null, '撤旧与激活没有分离');
        if ($faults) {
            $wait(static function () use ($database, $device): bool {
                $query = $database->prepare('SELECT COUNT(*) FROM type_mqtt_fences WHERE client_id = ?');
                $query->execute([$device['id']]);
                return (int) $query->fetchColumn() === 1;
            }, '切换没有为暂停的旧节点建立持久隔离屏障');
            expect($call('GET', $targetPath, $target)['data']['pending_reasons'] === ['old_authorization_isolation_pending'], '旧节点未关闭却提前激活');
            $single(0, $device['ownership_id']);
            if ($observeOperations) {
                $operations['unreachable'] = iotOperationsWait(
                    static fn (): array => iotOperationsPlatform($environment, $fixture),
                    static function (array $view): bool {
                        $betaNodes = array_values(array_filter($view['nodes'], static fn (array $node): bool => $node['kind'] === 'broker' && $node['node_id'] === 't32-beta'));
                        $otherBrokers = array_values(array_filter($view['nodes'], static fn (array $node): bool => $node['kind'] === 'broker' && $node['node_id'] !== 't32-beta'));
                        return count($betaNodes) === 1 && $betaNodes[0]['state'] === 'unreachable'
                            && count($otherBrokers) === 1 && $otherBrokers[0]['state'] === 'reporting' && $view['store']['state'] === 'available';
                    },
                    '暂停单节点没有独立显示暂不可达，或误报其他Broker故障',
                    20.0
                );
                $before = array_values(array_filter($operations['connected']['nodes'], static fn (array $node): bool => $node['node_id'] === 't32-beta'))[0];
                $unreachable = array_values(array_filter($operations['unreachable']['nodes'], static fn (array $node): bool => $node['node_id'] === 't32-beta'))[0];
                expect(
                    $before['run_id'] === $unreachable['run_id'] && $unreachable['observed_at'] < $operations['unreachable']['generated_at'] - 14,
                    '暂不可达丢失旧运行身份或伪造了新鲜采样'
                );
            }
            $killBeta($beta, 'isolating', true);
            $paused = false;
            $beta = $startBeta();
            if ($observeOperations) {
                $operations['recovered'] = iotOperationsWait(
                    static fn (): array => iotOperationsPlatform($environment, $fixture),
                    static function (array $view) use ($operations): bool {
                        $previous = array_values(array_filter($operations['unreachable']['nodes'], static fn (array $node): bool => $node['node_id'] === 't32-beta'))[0];
                        $nodes = array_values(array_filter($view['nodes'], static fn (array $node): bool => $node['kind'] === 'broker'));
                        return count($nodes) === 2 && $view['store']['state'] === 'available'
                            && count(array_filter($nodes, static fn (array $node): bool => $node['state'] === 'reporting')) === 2
                            && count(array_filter($nodes, static fn (array $node): bool => $node['node_id'] === 't32-beta' && $node['run_id'] !== $previous['run_id'])) === 1;
                    },
                    '第二节点恢复后概览未显示新运行或保留了故障状态'
                );
            }
            // 正式同身份客户端实际接管接收会话，强制原网络退出；监督恢复必须继续原持久会话。
            $network('transfer-receiver-takeover', $clientFixture);
            $receiverRecovery = $resumeReceiver(true);
            $faultCases['isolating'] = ['switch-response-lost-repeated-id-keeps-no-new-identity', 'suspended-owner-keeps-proof-pending',
                'old-broker-hard-killed-children-absent-explicit-fence-and-restarted', 'zero-active-credentials-before-isolation-proof',
                'receiver-session-takeover-network-exit-and-same-instance-supervised-recovery'];
        }
        $wait(static function () use ($call, $targetPath, $target): bool {
            return $call('GET', $targetPath, $target)['data']['pending_reasons'] === ['target_activation_required'];
        }, '真实Broker未完成旧阶段授权隔离');
        $closed = $old->wait(10);
        expect($closed->successful() && str_contains($closed->stdout, 'transfer-prior-status-acks-and-active-connection-closed'), '旧网络主体未被真实隔离：' . $closed->stderr);
        $sessions = $database->prepare('SELECT COUNT(*) FROM type_mqtt_sessions WHERE client_id = ?');
        $sessions->execute([$device['id']]);
        expect((int) $sessions->fetchColumn() === 0, '激活前旧会话仍可恢复');
        // 完成/终止事实保留用于协议对账；只有pending仍具有未来交付能力。
        $pending = $database->prepare("SELECT COUNT(*) FROM type_mqtt_deliveries WHERE client_id = ? AND state = 'pending'");
        $pending->execute([$device['id']]);
        expect((int) $pending->fetchColumn() === 0, '隔离后旧下行仍可交付');
        if ($faults) {
            $drop($targetPath, $switch, $target, $fixture['transfer_target_token']);
            $activated = $call('POST', $targetPath, $target, $switch, 202)['data'];
            expect($activated['status'] === 'activating' && $activated['credential'] === null, '丢失激活响应后重新暴露原秘密');
            $current = $call('GET', $targetDevice, $target)['data'];
            $rotated = $call('POST', $targetDevice . '/rotate', $target, ['version' => (int) $current['version'], 'confirm_device_id' => $device['id']], 202)['data'];
            $activated['credential'] = $rotated['credential'];
            $wait(static fn (): bool => $call('GET', $targetDevice, $target)['data']['authorization']['status'] === 'enforced', '丢失新秘密后的显式轮换没有完成隔离');
            $single(1, $activated['new_ownership_id']);
        } else {
            $activated = $call('POST', $targetPath, $target, $switch, 202)['data'];
        }
        expect($activated['status'] === 'activating' && $activated['credential'] !== null && $activated['new_ownership_id'] !== $device['ownership_id'], '新归属未经隔离证明激活');
        expect($call('POST', $targetPath, $target, $switch, 202)['data']['credential'] === null, '真实激活重复泄漏秘密');
        expect(!isset($call('GET', $targetPath, $target)['data']['credential']), '查询转移泄漏秘密');
        $call('GET', $sourceDevice, $source, null, 404);
        expect($call('GET', $targetDevice . '/current', $target)['data']['fields'] === [], '新归属继承旧实时值');
        $denied = new Process(['node', dirname(__DIR__) . '/tests/iot-lifecycle-mqtt.mjs', 'denied'], dirname(__DIR__), $clientEnvironment);
        try {
            $result = $denied->wait(15);
            expect($result->successful(), '旧凭据重连未被标准CONNACK拒绝：' . $result->stderr);
        } finally {
            $denied->stop();
        }
        $provisioned = $run('transfer', $activated['provisioning']);
        $again = $run('transfer', $activated['provisioning']);
        expect($provisioned['ownership_id'] === $activated['new_ownership_id'] && $again['last_sequence'] === $provisioned['last_sequence'], '设备配置提交后重试改变序号或归属');
        $overrides = ['IOT_DEVICE_TENANT_ID' => $target, 'IOT_DEVICE_OWNERSHIP_ID' => $activated['new_ownership_id'],
            'IOT_DEVICE_CREDENTIAL_ID' => $activated['credential']['id'], 'IOT_DEVICE_PASSWORD' => $activated['credential']['password']];
        expect((int) $run('state', null, true, $overrides)['transfer_activation_pending'] === 1, '配置重启丢失持久最终确认');
        $run('enqueue', ['type' => 'telemetry', 'sampled_at' => time(), 'values' => ['temperature' => 30]], false, $overrides);
        $call('POST', $targetDevice . '/commands', $target, ['command_id' => bin2hex(random_bytes(16)), 'identifier' => 'switch', 'values' => ['on' => true],
            'version' => (int) $call('GET', $targetDevice, $target)['data']['version']], 409);
        if ($faults) {
            $freshFixture = array_replace($clientFixture, ['credential' => $activated['credential'], 'path' => $targetDevice,
                'tenant' => $target, 'token' => $fixture['transfer_target_token']]);
            $network('transfer-new-clean', $freshFixture, ['IOT_MQTT_PORT' => $betaEnvironment['IOT_MQTT_PORT']]);
            $paused = true;
            $killBeta($beta, 'activating', false);
            $paused = false;
            $beta = $startBeta();
            expect($call('GET', $targetPath, $target)['data']['status'] === 'activating', '节点退出导致自动完成归属');
            $cache = new PDO('sqlite:' . $base . '/transfer-device.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $confirmation = $cache->query('SELECT transfer_activation FROM device_buffer_state WHERE id = 1')->fetchColumn();
            expect(is_string($confirmation), '正式设备没有持久新阶段确认原件');
            $cache = null;
            $network('transfer-confirm-drop', $freshFixture + ['confirmation' => $confirmation], ['IOT_MQTT_PORT' => $betaEnvironment['IOT_MQTT_PORT']]);
            $wait(static fn (): bool => $call('GET', $targetPath, $target)['data']['status'] === 'completed', '新确认断线后没有由真实跨节点接收角色完成');
            expect((int) $run('state', null, true, $overrides)['transfer_activation_pending'] === 1, 'MQTT协议确认或平台完成冒充设备业务ACK');
            $run('enqueue', ['type' => 'telemetry', 'sampled_at' => time(), 'values' => ['temperature' => 30]], false, $overrides);
            $single(1, $activated['new_ownership_id']);
            $faultCases['activating'] = ['lost-secret-not-repeated-explicit-rotate-recovers', 'fresh-new-session-cannot-inherit-old-retained-or-queue',
                'broker-hard-exit-restart-still-awaits-device', 'native-provision-restart-idempotent', 'confirmation-sent-on-beta-consumed-on-alpha',
                'client-exit-with-business-ack-lost-keeps-local-freeze'];
        }
        $run('send', null, true, $overrides);
        $completed = $call('GET', $targetPath, $target)['data'];
        expect($completed['status'] === 'completed' && $completed['completed_at'] !== null, '新阶段TLS确认没有同步完成归属');
        $state = $run('state', null, true, $overrides);
        expect($state['transfer_id'] === null && (int) $state['transfer_activation_pending'] === 0 && $state['accepted_total'] === 2, '精确平台确认未解除本地冻结或改写旧统计');
        $next = $run('enqueue', ['type' => 'telemetry', 'sampled_at' => time(), 'values' => ['temperature' => 30]], true, $overrides);
        expect(app\iot\service\DeviceService::sequenceCompare($next['sequence'], $provisioned['last_sequence']) > 0, '新阶段复用了原序号');
        expect($run('send', null, true, $overrides)['delivery']['accepted'] === 1, '新归属合法遥测未取得业务回执');
        $history = $wait(static function () use ($call, $targetDevice, $target): array|false {
            $data = $call('GET', $targetDevice . '/history', $target);
            return $data['total'] === 1 ? $data : false;
        }, '目标历史没有独立记录新采样');
        expect($history['items'][0]['ownership_id'] === $activated['new_ownership_id'] && $call('GET', $sourceDevice . '/history', $source)['total'] === 2, '真实新旧历史未按归属和租户隔离');
        if ($faults) {
            $drop($targetPath, $switch, $target, $fixture['transfer_target_token']);
            $repeat = $call('POST', $targetPath, $target, $switch, 202)['data'];
            expect($repeat['status'] === 'completed' && $repeat['new_ownership_id'] === $activated['new_ownership_id'] && $repeat['credential'] === null, '完成后丢失响应和重复请求改变归属');
            $network('denied', $clientFixture);
            $network('denied', $clientFixture, ['IOT_MQTT_PORT' => $betaEnvironment['IOT_MQTT_PORT']]);
            $single(1, $activated['new_ownership_id']);
            $stopped = $beta->stop(20);
            expect($stopped->successful(), '转移第二节点最终退出失败：' . $stopped->stderr);
            $betaStatistics = json_decode(trim($stopped->stdout), true, 32, JSON_THROW_ON_ERROR);
            if ($observeOperations) {
                $operations['stopped'] = iotOperationsPlatform($environment, $fixture);
                $nodes = array_values(array_filter($operations['stopped']['nodes'], static fn (array $node): bool => $node['kind'] === 'broker' && $node['node_id'] === 't32-beta'));
                expect(
                    count($nodes) === 1 && $nodes[0]['state'] === 'stopped' && $nodes[0]['metrics']['connections'] === 0,
                    '正常停止的第二节点被误报为暂不可达或仍有连接'
                );
            }
            foreach (['connections', 'subscriptions', 'bufferedBytes', 'pendingCommits', 'closingSessions', 'pendingFences', 'nodeFailures', 'invalidationFailures', 'quarantinedCommits'] as $field) {
                expect($betaStatistics[$field] === 0, '转移第二节点退出后遗留资源：' . $field);
            }
            $faultCases['completed'] = ['native-device-reconnect-recovers-exact-business-ack', 'duplicate-switch-response-lost-caller-exits-and-retries',
                'both-nodes-reject-original-credential', 'one-new-ownership-and-credential-only'];
        }
        return ['network' => json_decode(trim($closed->stdout) === '' ? '[]' : substr(trim($closed->stdout), strpos(trim($closed->stdout), '[')), true, 16, JSON_THROW_ON_ERROR),
            'broker_isolation_observed' => true, 'old_session_and_pending_delivery_removed' => true, 'old_credential_connack_denied' => true,
            'provision_restart_idempotent' => true, 'new_phase_confirmation_over_tls' => true, 'new_sampling_only_after_ack' => true, 'source_history_rows' => 2, 'target_history_rows' => 1,
            'fault_cases' => $faultCases, 'beta_statistics' => $betaStatistics, 'receiver_recovery' => $receiverRecovery, 'operations' => $operations];
    } finally {
        $old->stop();
        if ($paused && $beta !== null && $beta->running()) {
            posix_kill($beta->pid(), SIGCONT);
        }
        if ($beta !== null) {
            $stopped = $beta->stop(20);
            file_put_contents($base . '/transfer-beta-final.log', $stopped->stdout . $stopped->stderr);
        }
    }
}

/** 全新设备通过HTTP选版本，正式设备CLI在真实TLS下确认；状态查询与重启不访问平台数据库。 */
function iotDeviceModelSwitchChecks(array $fixture, array $command, array $environment, string $base, PDO $database): array
{
    $root = dirname(__DIR__);
    $http = new Type\Testing\HttpClient('http://127.0.0.1:' . $environment['APP_PORT']);
    $headers = ['Authorization' => 'Bearer ' . $fixture['admin_token'], 'X-Tenant-Id' => $fixture['tenant'], 'Content-Type' => 'application/json'];
    $call = static function (string $method, string $path, ?array $data = null, int $status = 200) use ($http, $headers): array {
        $response = $http->request($method, $path, $headers, $data === null ? '' : json_encode($data, JSON_THROW_ON_ERROR));
        expect($response->status === $status, '真实模型HTTP结果不符：' . $response->body);
        return $response->json();
    };
    $path = '/customer/tenants/' . $fixture['tenant'];
    $product = $call('POST', $path . '/products', ['name' => 'TLS设备模型变更'], 201)['data'];
    $models = $path . '/products/' . $product['id'] . '/models';
    $definition = ['properties' => [['identifier' => 'temperature', 'name' => '温度', 'type' => 'number', 'required' => true, 'unit' => '°C']], 'events' => [], 'commands' => []];
    $call('POST', $models, ['definition' => $definition], 201);
    $call('POST', $models . '/1/publish', ['version' => 1]);
    $definition['properties'][0]['unit'] = '°F';
    $call('POST', $models, ['definition' => $definition], 201);
    $target = $call('POST', $models . '/2/publish', ['version' => 1])['data'];
    $registration = $call('POST', $path . '/devices', ['name' => '设备持久模型确认', 'product_id' => $product['id'], 'model_version' => 1], 201)['data'];
    $device = $registration['device'];
    $path .= '/devices/' . $device['id'];
    $deviceEnvironment = array_replace($environment, ['IOT_DEVICE_CACHE' => 'model-switch-device.sqlite', 'IOT_DEVICE_ID' => $device['id'],
        'IOT_DEVICE_OWNERSHIP_ID' => $device['ownership_id'], 'IOT_DEVICE_TENANT_ID' => $fixture['tenant'], 'IOT_DEVICE_MODEL_VERSION' => '1',
        'IOT_DEVICE_CREDENTIAL_ID' => $registration['credential']['id'], 'IOT_DEVICE_PASSWORD' => $registration['credential']['password'],
        'IOT_DEVICE_HOST' => '127.0.0.1', 'IOT_DEVICE_PORT' => $environment['IOT_MQTT_PORT'], 'IOT_DEVICE_CA' => 'certificate.pem',
        'IOT_DEVICE_PEER_NAME' => '127.0.0.1', 'IOT_DEVICE_SUPPORTED_MODELS' => '{}', 'DB_HOST' => '192.0.2.1', 'DB_PORT' => '1']);
    $run = static function (string $action, ?array $input = null) use ($root, $base, $command, &$deviceEnvironment): array {
        $inputPath = $input === null ? null : $base . '/model-device-input.json';
        if ($inputPath !== null) {
            file_put_contents($inputPath, json_encode($input, JSON_THROW_ON_ERROR));
        }
        $process = new Process([...$command, 'iot:device', $action], $root, $deviceEnvironment, 65536, $inputPath);
        try {
            $result = $process->wait(65);
            expect($result->successful(), '模型设备命令失败：' . $result->stderr . $result->stdout);
            return json_decode(trim($result->stdout), true, 32, JSON_THROW_ON_ERROR);
        } finally {
            $process->stop();
        }
    };
    $run('enqueue', ['type' => 'telemetry', 'sampled_at' => time(), 'values' => ['temperature' => 20]]);
    $run('send');
    expect($call('GET', $path)['data']['lifecycle'] === 'enabled', '真实首次接入未激活模型设备');
    $states = [];
    foreach (['rejected', 'confirmed'] as $expected) {
        if ($expected === 'confirmed') {
            $deviceEnvironment['IOT_DEVICE_SUPPORTED_MODELS'] = json_encode([2 => $target['structure_hash']], JSON_THROW_ON_ERROR);
        }
        // 等待前一个连接的关闭观察完成，再从离线受理新的原意图。
        $until = microtime(true) + 15;
        do {
            $detail = $call('GET', $path)['data'];
            if ($detail['connection']['status'] === 'offline') {
                break;
            } usleep(20000);
        } while (microtime(true) < $until);
        expect($detail['connection']['status'] === 'offline', '模型设备连接未正常释放');
        $intent = ['switch_id' => bin2hex(random_bytes(16)), 'model_version' => 2, 'version' => (int) $detail['version']];
        expect($call('POST', $path . '/model-switches', $intent, 202)['data']['state'] === 'pending'
            && $call('GET', $path)['data']['model_version'] === 1, '离线受理提前改绑');
        $listener = new Process([...$command, 'iot:device', 'listen'], $root, $deviceEnvironment);
        try {
            $until = microtime(true) + 35;
            do {
                expect($listener->running(), '模型确认监听提前退出：' . $listener->stderr() . $listener->stdout());
                $record = $call('GET', $path . '/model-switches')['items'][0];
                if ($record['status'] !== 'pending') {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $until);
            if ($record['id'] !== $intent['switch_id'] || $record['status'] !== $expected) {
                $wire = $database->query("SELECT convert_from(m.payload, 'UTF8')::jsonb->>'type' AS type, d.state, d.client_id, d.packet_id, m.inbound_state FROM type_mqtt_messages m LEFT JOIN type_mqtt_deliveries d ON d.message_id = m.id ORDER BY m.created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
                throw new RuntimeException('TLS设备未返回明确模型结果：' . json_encode(['switch' => $record, 'deliveries' => $wire], JSON_THROW_ON_ERROR));
            }
            expect($call('POST', $path . '/model-switches', $intent, 202)['data']['id'] === $intent['switch_id'], '原HTTP受理恢复不幂等');
            $detail = $call('GET', $path)['data'];
            expect($detail['model_version'] === ($expected === 'confirmed' ? 2 : 1) && $detail['model_switch'] === null, '绑定与真实设备确认不符');
            if ($expected === 'confirmed') {
                expect($call('GET', $path . '/current')['data']['fields'] === [], '新模型继承了旧单位当前值');
            }
            // 业务ACK必须实际到达设备并释放本地待确认；只读设备缓存观察，不伪造确认。
            $cache = new PDO('sqlite:' . $base . '/model-switch-device.sqlite');
            $until = microtime(true) + 10;
            do {
                $pending = (int) $cache->query('SELECT COUNT(*) FROM device_model_switches WHERE pending = 1')->fetchColumn();
                if ($pending === 0) {
                    break;
                } usleep(20000);
            } while (microtime(true) < $until);
            expect($pending === 0, '设备未收到同步提交后的业务ACK');
            $cache = null;
            $states[] = $record['status'];
        } finally {
            $wasRunning = $listener->running();
            $stopped = $listener->stop(15);
            if ($wasRunning) {
                expect($stopped->successful(), '模型监听退出未释放资源：' . $stopped->stderr);
            }
            expect(!str_contains($stopped->stdout . $stopped->stderr, $registration['credential']['password']), '模型监听日志泄漏凭据');
        }
    }
    expect($run('state')['model_version'] === 2, '设备重启使用旧配置倒退模型');
    $run('enqueue', ['type' => 'telemetry', 'sampled_at' => time(), 'values' => ['temperature' => 86]]);
    expect($run('send')['delivery']['accepted'] === 1, '重启后新模型原件未得到业务回执');
    $detail = $call('GET', $path)['data'];
    expect($detail['model_version'] === 2 && $call('GET', $path . '/current')['data']['fields'][0]['value'] === 86
        && $detail['model']['definition']['properties'][0]['unit'] === '°F', '新当前值未按设备确认模型解释');
    $audit = $database->prepare("SELECT COUNT(*) FROM customer_audit WHERE tenant_id = ? AND subject_id = ? AND action = 'device.model_switch_confirmed'");
    $audit->execute([$fixture['tenant'], $device['id']]);
    expect((int) $audit->fetchColumn() === 2, '设备确认和拒绝缺少新客户审计事实');
    return ['tls' => true, 'platform_database_unavailable_to_device' => true, 'states' => $states, 'same_id_recovery' => true,
        'business_ack_observed' => true, 'restart_model' => 2, 'current_unit' => '°F', 'processes_stopped' => true];
}

/** 正式设备CLI只读自己的缓存；通过同一原生/开发入口、真实TLS接收和授权HTTP核对状态。 */
function iotDeviceCommandChecks(array $fixture, array $command, array $environment, string $base, PDO $database, Process &$worker, array $workerEnvironment): array
{
    $root = dirname(__DIR__);
    $registration = $fixture['registration'];
    $deviceId = $registration['device']['id'];
    $deviceEnvironment = array_replace($environment, [
        'IOT_DEVICE_CACHE' => 'command-device.sqlite', 'IOT_DEVICE_ID' => $deviceId,
        'IOT_DEVICE_OWNERSHIP_ID' => $registration['device']['ownership_id'], 'IOT_DEVICE_TENANT_ID' => $fixture['tenant'],
        'IOT_DEVICE_MODEL_VERSION' => '1', 'IOT_DEVICE_CREDENTIAL_ID' => $registration['credential']['id'],
        'IOT_DEVICE_PASSWORD' => $registration['credential']['password'], 'IOT_DEVICE_MAXIMUM_RECORDS' => '1',
        'IOT_DEVICE_MAXIMUM_BYTES' => '1000', 'IOT_DEVICE_HOST' => '127.0.0.1', 'IOT_DEVICE_PORT' => $environment['IOT_MQTT_PORT'],
        'IOT_DEVICE_CA' => 'certificate.pem', 'IOT_DEVICE_PEER_NAME' => '127.0.0.1', 'APP_DEBUG' => 'false',
        // 设备角色必须在平台数据库不可用时仍能工作；设备自己只打开本地SQLite。
        'DB_HOST' => '192.0.2.1', 'DB_PORT' => '1',
    ]);
    $run = static function (string $action, ?string $input = null, ?array $override = null, bool $success = true) use ($command, $deviceEnvironment, $root, $base): array {
        $path = $input === null ? null : $base . '/device-input.json';
        if ($path !== null) {
            file_put_contents($path, $input);
        }
        $process = new Process([...$command, 'iot:device', $action], $root, array_replace($deviceEnvironment, $override ?? []), 65536, $path);
        try {
            $result = $process->wait(65);
            expect(!$result->timedOut && $result->successful() === $success, '设备CLI结果不符：' . $action . ' ' . $result->stderr . $result->stdout);
            expect(!str_contains($result->stdout . $result->stderr, $deviceEnvironment['IOT_DEVICE_PASSWORD']), '设备CLI泄漏秘密');
            return trim($result->stdout) === '' ? [] : json_decode(trim($result->stdout), true, 32, JSON_THROW_ON_ERROR);
        } finally {
            $process->stop();
        }
    };
    expect($run('state')['pending_count'] === 0, '初始CLI缓存不为空');
    $sample = json_encode(['type' => 'telemetry', 'sampled_at' => time(), 'values' => ['temperature' => 28]], JSON_THROW_ON_ERROR);
    $buffered = $run('enqueue', $sample);
    expect($buffered['status'] === 'buffered' && $buffered['sequence'] === '1', 'CLI未持久分配首序号');
    $full = $run('enqueue', $sample);
    expect($full['status'] === 'not_admitted' && $full['buffer']['not_admitted'] === 1 && $full['buffer']['pending_count'] === 1, 'CLI满额覆盖原件');
    $run('enqueue', '{bad-json', null, false);
    $run('enqueue', str_repeat('x', 16385), null, false);
    expect($run('state')['pending_count'] === 1, 'CLI非法采样改写已有缓存');
    $listener = stream_socket_server('tcp://127.0.0.1:0', $number, $message);
    expect(is_resource($listener), '无法分配离线设备装置端口');
    $offlinePort = substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
    fclose($listener);
    $offline = $run('send', null, ['IOT_DEVICE_PORT' => $offlinePort], false);
    expect($offline['status'] === 'retry_required' && $offline['buffer']['pending_count'] === 1 && $offline['buffer']['accepted_total'] === 0, '离线CLI清理未确认记录');
    $sent = $run('send');
    expect($sent['status'] === 'finished' && $sent['delivery']['accepted'] === 1 && $sent['buffer']['pending_count'] === 0
        && $sent['buffer']['accepted_total'] === 1 && !$sent['buffer']['full'], '正式设备命令未凭真实业务回执排空');
    $http = new Type\Testing\HttpClient('http://127.0.0.1:' . $environment['APP_PORT']);
    $read = static function () use ($http, $fixture): array {
        $response = $http->request('GET', $fixture['path'] . '/current', ['Authorization' => 'Bearer ' . $fixture['token'], 'X-Tenant-Id' => $fixture['tenant']]);
        expect($response->status === 200, 'CLI状态HTTP读取失败');
        return $response->json()['data'];
    };
    $until = microtime(true) + 15;
    do {
        $current = $read();
        if (($current['buffer']['values']['pending_count'] ?? null) === 0) {
            break;
        }
        usleep(20000);
    } while (microtime(true) < $until);
    expect($current['buffer']['values']['not_admitted'] === 1 && $current['buffer']['values']['pending_count'] === 0
        && !$current['buffer']['values']['full'] && $current['buffer']['freshness'] === 'fresh', '授权HTTP未显示实际缓存排空和未接纳计数');
    $expired = $run('enqueue', json_encode(['type' => 'telemetry', 'sampled_at' => time() - 172900, 'values' => ['temperature' => 29]], JSON_THROW_ON_ERROR));
    expect((int) $expired['sequence'] > (int) $buffered['sequence'], 'CLI重启重置了共享持久序号');
    $rejected = $run('send');
    expect($rejected['delivery']['rejected'] === 1 && $rejected['buffer']['rejected_total'] === 1
        && $rejected['buffer']['exception_count'] === 1 && $rejected['buffer']['pending_count'] === 0, '真实永久拒绝未进入设备有界异常集合');
    $facts = $database->prepare('SELECT COUNT(*) FROM iot_ingestion_facts WHERE device_id = ?');
    $facts->execute([$deviceId]);
    expect((int) $facts->fetchColumn() === 1, '缓存状态或拒绝被记为可靠遥测事实');
    $ownerQuery = $database->prepare('SELECT owner_id FROM iot_device_connections WHERE device_id = ?');
    $ownerQuery->execute([$deviceId]);
    $previousOwner = $ownerQuery->fetchColumn();
    // 上一轮send已释放本地socket；等待Broker完成该连接的持久关闭，避免把重连验收变成接管竞态。
    $until = microtime(true) + 15;
    do {
        $closed = $http->request('GET', $fixture['path'], ['Authorization' => 'Bearer ' . $fixture['admin_token'], 'X-Tenant-Id' => $fixture['tenant']])->json()['data']['connection']['status'];
        if ($closed === 'offline') {
            break;
        }
        usleep(20000);
    } while (microtime(true) < $until);
    expect($closed === 'offline', '先前send连接没有完成正常关闭观察');
    $listenStarted = microtime(true);
    $listener = new Process([...$command, 'iot:device', 'listen'], $root, array_replace($deviceEnvironment, ['APP_DEBUG' => 'true']));
    try {
        $headers = ['Authorization' => 'Bearer ' . $fixture['admin_token'], 'X-Tenant-Id' => $fixture['tenant'], 'Content-Type' => 'application/json'];
        $until = microtime(true) + 15;
        do {
            expect($listener->running(), '指令监听设备提前退出：' . $listener->stderr() . $listener->stdout());
            $status = $http->request('GET', $fixture['path'], $headers)->json()['data']['connection']['status'];
            $ownerQuery->execute([$deviceId]);
            $owner = $ownerQuery->fetchColumn();
            if ($status === 'online' && $owner !== null && $owner !== $previousOwner) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $until);
        expect($status === 'online' && $owner !== null && $owner !== $previousOwner, '指令设备没有取得自身连接的真实在线观察');
        // 只读装置观察用于确定测试发起顺序；不授予服务身份读取设备下行的新权限。
        // 设备在处理时间回应后才PUBACK，实际指令成功仍只依据关联的设备执行回执。
        $clockQuery = $database->prepare("SELECT encode(m.payload, 'base64') FROM type_mqtt_messages m JOIN type_mqtt_deliveries d ON d.message_id = m.id WHERE m.topic = ? AND d.client_id = ? AND d.state = 'acknowledged' AND m.created_at >= to_timestamp(?)");
        $timeObserved = false;
        $until = microtime(true) + 20;
        do {
            expect($listener->running(), '时间挑战期间设备退出：' . $listener->stderr() . $listener->stdout());
            $clockQuery->execute([$registration['credential']['topics']['subscribe'], $deviceId, $listenStarted]);
            foreach ($clockQuery->fetchAll(PDO::FETCH_COLUMN) as $encoded) {
                $clockMessage = json_decode(base64_decode($encoded), true);
                if (($clockMessage['type'] ?? '') === 'time_response' && ($clockMessage['ownership_id'] ?? '') === $registration['device']['ownership_id']) {
                    $timeObserved = true;
                }
            }
            if ($timeObserved) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $until);
        expect($timeObserved, '设备没有完成首次时间回应的确认：' . $listener->stderr());
        $payload = ['command_id' => bin2hex(random_bytes(16)), 'identifier' => 'switch', 'values' => ['on' => true]];
        $beforeControl = $http->request('GET', $fixture['path'], $headers)->json()['data'];
        $payload['version'] = (int) $beforeControl['version'];
        $controlStarted = microtime(true);
        $accepted = $http->request('POST', $fixture['path'] . '/commands', $headers, json_encode($payload, JSON_THROW_ON_ERROR));
        expect($accepted->status === 201, '真实HTTP未受理在线指令：' . $accepted->body . json_encode(['before' => ['lifecycle' => $beforeControl['lifecycle'], 'connection' => $beforeControl['connection']],
            'elapsed' => microtime(true) - $controlStarted, 'now' => time(), 'observations' => $database->query('SELECT * FROM iot_broker_observations')->fetchAll(PDO::FETCH_ASSOC)], JSON_THROW_ON_ERROR) . $listener->stderr() . $listener->stdout());
        $commandRecord = $accepted->json()['data'];
        expect($commandRecord['execution'] === 'pending' && $commandRecord['deadline_at'] === $commandRecord['accepted_at'] + 60, 'HTTP受理未保留原期限');
        $repeated = $http->request('POST', $fixture['path'] . '/commands', $headers, json_encode($payload, JSON_THROW_ON_ERROR));
        expect($repeated->status === 201 && $repeated->json()['data']['id'] === $commandRecord['id'], 'HTTP响应恢复创建第二条指令');
        $until = microtime(true) + 25;
        do {
            $response = $http->request('GET', $fixture['path'] . '/commands', $headers);
            expect($response->status === 200, '执行结果HTTP查询失败');
            $execution = $response->json()['items'][0];
            if ($execution['execution'] !== 'pending') {
                break;
            }
            expect($listener->running(), '指令监听设备退出：' . $listener->stderr() . $listener->stdout());
            usleep(20000);
        } while (microtime(true) < $until);
        expect($execution['id'] === $commandRecord['id'] && $execution['execution'] === 'succeeded'
            && $execution['device_received_at'] !== null && $execution['mqtt_at'] !== null
            && $execution['result']['simulated'] === true, '真实TLS执行未闭环：' . json_encode($execution, JSON_THROW_ON_ERROR) . $listener->stderr());
        $count = $database->prepare('SELECT COUNT(*) FROM iot_commands WHERE device_id = ?');
        $count->execute([$deviceId]);
        expect((int) $count->fetchColumn() === 1, '同一HTTP身份创建重复动作');
        $ledger = new PDO('sqlite:' . $base . '/command-device.sqlite');
        $until = microtime(true) + 15;
        do {
            $stored = $ledger->query('SELECT status, pending, retain_until, deadline_at FROM device_commands')->fetchAll(PDO::FETCH_ASSOC);
            if (count($stored) === 1 && (int) $stored[0]['pending'] === 0) {
                break;
            }
            expect($listener->running(), '执行结果业务确认前设备退出：' . $listener->stderr());
            usleep(20000);
        } while (microtime(true) < $until);
        expect(
            count($stored) === 1 && $stored[0]['status'] === 'succeeded' && (int) $stored[0]['pending'] === 0
            && (int) $stored[0]['retain_until'] >= (int) $stored[0]['deadline_at'] + 86400,
            '正式CLI没有持久保存24小时执行去重与结果'
        );
        // 准备已领取但未到达设备的历史指令，保留真实来源；结果查询不能变成执行。
        $missingId = bin2hex(random_bytes(16));
        $issued = time() - 360;
        $missingPayload = json_encode(['app_version' => 1, 'type' => 'command', 'command_id' => $missingId, 'device_id' => $deviceId,
            'ownership_id' => $registration['device']['ownership_id'], 'model_version' => 1, 'identifier' => 'switch', 'values' => (object) ['on' => true],
            'issued_at' => $issued, 'deadline_at' => $issued + 60], JSON_THROW_ON_ERROR);
        $originQuery = $database->prepare('SELECT source_context FROM iot_commands WHERE id = ?');
        $originQuery->execute([$commandRecord['id']]);
        $origin = $originQuery->fetchColumn();
        $insert = $database->prepare('INSERT INTO iot_commands (id, tenant_id, device_id, ownership_id, model_version, identifier, payload, content_hash, actor_id, accepted_at, deadline_at, source_context, request_version, dispatch_at, schedule_stage, next_action_at) VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, 5, ?)');
        $insert->execute([$missingId, $fixture['tenant'], $deviceId, $registration['device']['ownership_id'], 'switch', $missingPayload,
            app\iot\service\IngestionService::contentHash($missingPayload), $commandRecord['actor_id'], $issued, $issued + 60, $origin, $payload['version'], $issued, $issued + 300]);
        $queryResult = $database->prepare('SELECT last_query_code FROM iot_commands WHERE id = ?');
        $until = microtime(true) + 20;
        do {
            $queryResult->execute([$missingId]);
            if ($queryResult->fetchColumn() === 'result_not_retained') {
                break;
            }
            expect($listener->running(), '原生设备查询期间退出：' . $listener->stderr());
            usleep(20000);
        } while (microtime(true) < $until);
        $queryResult->execute([$missingId]);
        expect($queryResult->fetchColumn() === 'result_not_retained' && (int) $ledger->query('SELECT COUNT(*) FROM device_commands')->fetchColumn() === 1, '正式设备查询创建动作或伪造未执行结果');
        $ledger = null;
        $stopped = $listener->stop(15);
        expect($stopped->successful(), '指令监听未正常停止：' . $stopped->stderr);
    } finally {
        $listener->stop(15);
    }
    $until = microtime(true) + 15;
    do {
        $closed = $http->request('GET', $fixture['path'], $headers)->json()['data']['connection']['status'];
        if ($closed === 'offline') {
            break;
        }
        usleep(20000);
    } while (microtime(true) < $until);
    expect($closed === 'offline', '正式设备退出后未完成连接关闭');
    $clientEnvironment = $environment + ['TYPE_DEVICE_FIXTURE' => json_encode($fixture, JSON_THROW_ON_ERROR),
        'TYPE_DEVICE_HTTP' => 'http://127.0.0.1:' . $environment['APP_PORT'], 'TYPE_DEVICE_CA' => $base . '/certificate.pem', 'TYPE_COMMAND_LEDGER' => $base . '/command-reference.json'];
    $loss = new Process(['node', $root . '/tests/iot-device-client.mjs', 'command-loss'], $root, $clientEnvironment);
    $recovery = null;
    try {
        $until = microtime(true) + 35;
        do {
            expect($loss->running(), '丢回执设备提前退出：' . $loss->stderr() . $loss->stdout());
            if (str_contains($loss->stdout(), 'command-ready')) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $until);
        expect(str_contains($loss->stdout(), 'command-ready'), '参考设备没有持久保存动作');
        // 已送达的模拟指令丢失回执；停止领取后再受理第二条，真实退出同一管理来源。
        $beforeRevocation = $worker->stop(15);
        expect($beforeRevocation->successful(), '撤权装置停止接收角色失败：' . $beforeRevocation->stderr);
        $released = json_decode(trim($beforeRevocation->stdout), true, 16, JSON_THROW_ON_ERROR);
        expect(!$released['pending'] && !$released['quarantined'] && !$released['running'], '撤权前领取角色仍有在途工作');
        file_put_contents($base . '/command-receiver-before-revocation.json', json_encode($released, JSON_THROW_ON_ERROR));
        $version = (int) $http->request('GET', $fixture['path'], $headers)->json()['data']['version'];
        $queuedId = bin2hex(random_bytes(16));
        $queued = $http->request(
            'POST',
            $fixture['path'] . '/commands',
            array_replace($headers, ['Authorization' => 'Bearer ' . $fixture['simulated']['accessToken']]),
            json_encode(['command_id' => $queuedId, 'identifier' => 'switch', 'values' => ['on' => false], 'version' => $version], JSON_THROW_ON_ERROR)
        );
        expect($queued->status === 201 && $queued->json()['data']['dispatch_at'] === null, '模拟排队指令没有准确保留未领取事实：' . $queued->body);
        $origin = $database->prepare('SELECT actor_id, source_context FROM iot_commands WHERE id = ?');
        $origin->execute([$queuedId]);
        $storedSource = $origin->fetch(PDO::FETCH_ASSOC);
        $source = json_decode($storedSource['source_context'], true, 8, JSON_THROW_ON_ERROR);
        expect($source['actor_id'] === $fixture['source']['identity']['actor_id'] && $source['tenant_id'] === $fixture['tenant']
            && $source['source_session_id'] === $fixture['source']['identity']['session_id']
            && $source['customer_id'] === $fixture['simulated']['identity']['customer_id']
            && $storedSource['actor_id'] === $source['customer_id'], '真实MQTT指令未冻结准确模拟来源及有效客户');
        $logout = $http->request('POST', '/admin/auth/logout', ['Authorization' => 'Bearer ' . $fixture['source']['accessToken']]);
        expect($logout->status === 200, '真实管理来源退出失败');
        expect(posix_kill($loss->pid(), SIGKILL), '无法注入本次设备进程掉电');
        $lost = $loss->wait(5);
        expect(!$lost->successful(), 'SIGKILL未中断参考设备');
        $until = microtime(true) + 15;
        do {
            $closed = $http->request('GET', $fixture['path'], $headers)->json()['data']['connection']['status'];
            if ($closed === 'offline') {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $until);
        expect($closed === 'offline', '掉电连接未释放');
        $worker = new Process([...$command, 'iot:ingest'], $root, $workerEnvironment);
        $until = microtime(true) + 15;
        do {
            expect($worker->running(), '撤权后同实例接收恢复失败：' . $worker->stderr());
            $stoppedCommand = $http->request('GET', $fixture['path'] . '/commands/' . $queuedId, $headers)->json()['data'];
            if ($stoppedCommand['execution'] === 'not_dispatched') {
                break;
            }
            usleep(50000);
        } while (microtime(true) < $until);
        expect($stoppedCommand['execution'] === 'not_dispatched' && $stoppedCommand['dispatch_at'] === null
            && $stoppedCommand['dispatch_stop_reason'] === 'source_permission_revoked', '原管理来源退出后仍领取未发送指令');
        $recovery = new Process(['node', $root . '/tests/iot-device-client.mjs', 'command-recover'], $root, $clientEnvironment);
        $reconciled = $recovery->wait(100);
        expect($reconciled->successful(), '真实掉电对账未闭环：' . $reconciled->stderr . $reconciled->stdout);
        $reconciliation = json_decode(trim($reconciled->stdout), true, 32, JSON_THROW_ON_ERROR);
    } finally {
        $loss->stop();
        $recovery?->stop();
    }
    return ['checks' => ['offline-cache-kept', 'restart-monotonic-sequence', 'bounded-stdin', 'full-not-admitted', 'tls-business-receipt-clears', 'device-status-authorized-http', 'permanent-rejection-bounded', 'no-platform-database'],
        'facts' => 1, 'control' => ['real_http_acceptance' => true, 'same_http_id_once' => true, 'real_tls_execution' => true, 'result_business_ack' => true, 'persistent_24h_result' => true,
            'native_simulator_query_does_not_execute' => true, 'exact_impersonation_origin' => true, 'revoked_queued_command_not_dispatched' => true,
            'revoked_dispatched_command_reconciled' => true, 'reconciliation' => $reconciliation], 'processes_stopped' => true];
}

/** 用行锁安排真实业务COMMIT进入SyncRep，再取消；主库记录可见时仍不得发送业务回执。 */
function iotIngestionUnknownCommit(array $fixture, array $command, array $workerEnvironment, array $clientEnvironment, string $base, PDO $database, Process $broker, Closure $restartBroker): array
{
    $root = dirname(__DIR__);
    $connect = static fn (string $port): PDO => new PDO(
        'pgsql:host=127.0.0.1;port=' . $port . ';dbname=' . $workerEnvironment['DB_DATABASE'],
        $workerEnvironment['DB_USERNAME'],
        $workerEnvironment['DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $gate = $connect($workerEnvironment['DB_PORT']);
    $gate->exec("SET synchronous_commit = 'local'");
    $standby = $connect($clientEnvironment['TYPE_PGSQL_STANDBY_PORT']);
    $wait = static function (Closure $probe, string $message, float $seconds = 10.0): mixed {
        $until = microtime(true) + $seconds;
        do {
            $value = $probe();
            if ($value) {
                return $value;
            }
            usleep(10000);
        } while (microtime(true) < $until);
        throw new RuntimeException($message);
    };
    $worker = new Process([...$command, 'iot:ingest'], $root, $workerEnvironment);
    $client = new Process(['node', $root . '/tests/iot-device-client.mjs', 'ingestion-fault'], $root, $clientEnvironment + ['TYPE_INGESTION_GATE' => $base . '/ingestion-go']);
    $recovered = null;
    $recoveryClient = null;
    try {
        $wait(static fn (): bool => str_contains($client->stdout(), "connected\n"), '故障客户端未连接：' . $client->stderr());
        if (in_array('--operations', $GLOBALS['argv'], true)) {
            iotOperationsWait(
                static fn (): array => iotOperationsPlatform($workerEnvironment, $fixture),
                static fn (array $view): bool => count(array_filter($view['nodes'], static fn (array $node): bool => $node['kind'] === 'ingestion' && $node['state'] === 'reporting')) === 1,
                '同步故障前的新接收运行尚未开始采样'
            );
        }
        $gate->beginTransaction();
        $lock = $gate->prepare('SELECT id FROM iot_devices WHERE id = ? FOR UPDATE');
        $lock->execute([$fixture['second']['device']['id']]);
        file_put_contents($base . '/ingestion-go', 'go');
        $wait(static fn (): bool => str_contains($client->stdout(), "published\n"), '故障上报未收到Broker PUBACK：' . $client->stderr());
        $backend = $wait(static fn (): mixed => $database->query("SELECT pid FROM pg_stat_activity WHERE application_name LIKE 'type_mqtt_%' AND wait_event_type = 'Lock' AND query LIKE '%iot_devices%' ORDER BY query_start LIMIT 1")->fetchColumn(), '业务接收未进入设备行锁等待');
        $standby->query('SELECT pg_wal_replay_pause()')->fetchColumn();
        $gate->commit();
        $wait(static fn (): mixed => $database->query('SELECT pid FROM pg_stat_activity WHERE pid = ' . (int) $backend . " AND wait_event = 'SyncRep'")->fetchColumn(), '业务COMMIT未进入同步重放等待');
        expect($database->query('SELECT pg_cancel_backend(' . (int) $backend . ')')->fetchColumn(), '无法取消隔离业务同步等待');
        $id = hash('sha256', $fixture['second']['device']['id'] . ':' . $fixture['second']['device']['ownership_id'] . ':20');
        $row = $wait(static function () use ($database, $id): mixed {
            $query = $database->prepare('SELECT receipt_proof_nonce, received_at FROM iot_ingestion WHERE message_id = ?');
            $query->execute([$id]);
            return $query->fetch(PDO::FETCH_ASSOC);
        }, '已取消同步等待的本地主库接收记录不可见');
        $result = $client->wait(15);
        expect($result->successful() && str_contains($result->stdout, 'no-receipt'), '同步证明未知时收到成功回执：' . $result->stderr);
        $operations = [];
        if (in_array('--operations', $GLOBALS['argv'], true)) {
            $operations['unconfirmed'] = iotOperationsPlatform($workerEnvironment, $fixture);
            expect(
                $operations['unconfirmed']['store']['state'] === 'unavailable' && $operations['unconfirmed']['store']['metrics'] === null,
                '暂停重放时持久概览冒充已确认或零积压'
            );
            $operations['unreachable'] = iotOperationsWait(
                static fn (): array => iotOperationsPlatform($workerEnvironment, $fixture),
                static fn (array $view): bool => count($view['nodes']) === 2 && count(array_filter($view['nodes'], static fn (array $node): bool => $node['state'] === 'unreachable')) === 2,
                '节点故障后旧采样继续冒充健康',
                20.0
            );
        }
        $failed = $worker->wait(15);
        expect(!$failed->successful(), '同步结果未知的接收进程应退出且保留原交付');
        $standby->query('SELECT pg_wal_replay_resume()')->fetchColumn();
        $broker->stop(15);
        $recovered = $restartBroker();
        $worker = new Process([...$command, 'iot:ingest'], $root, $workerEnvironment);
        $recoveryClient = new Process(['node', $root . '/tests/iot-device-client.mjs', 'ingestion-recover'], $root, $clientEnvironment);
        $recovery = $recoveryClient->wait(30);
        expect($recovery->successful(), '未知提交恢复未补发回执：' . $recovery->stderr . $worker->stderr());
        $query = $database->prepare('SELECT receipt_proof_nonce, received_at FROM iot_ingestion WHERE message_id = ?');
        $query->execute([$id]);
        $after = $query->fetch(PDO::FETCH_ASSOC);
        expect($after['receipt_proof_nonce'] !== $row['receipt_proof_nonce'] && $after['received_at'] === $row['received_at'], '未知记录恢复没有新的同步写屏障或改变首次接收时间');
        $query = $database->prepare('SELECT COUNT(*) FROM iot_ingestion_facts WHERE message_id = ?');
        $query->execute([$id]);
        expect((int) $query->fetchColumn() === 1, '未知提交恢复重复产生业务事实');
        if (in_array('--operations', $GLOBALS['argv'], true)) {
            $operations['recovered'] = iotOperationsWait(
                static fn (): array => iotOperationsPlatform($workerEnvironment, $fixture),
                static fn (array $view): bool => count($view['nodes']) === 2 && $view['store']['state'] === 'available'
                    && count(array_filter($view['nodes'], static fn (array $node): bool => $node['state'] === 'reporting')) === 2,
                '恢复后节点与同步持久指标没有重新出现'
            );
            foreach ($operations['recovered']['nodes'] as $index => $node) {
                expect($node['run_id'] !== $operations['unconfirmed']['nodes'][$index]['run_id'], '故障恢复没有保留新旧运行身份的区别');
            }
        }
        $stopped = $worker->stop(15);
        expect($stopped->successful(), '恢复消费进程未正常退出');
        return ['cancelled_sync_rep' => true, 'primary_visible_without_receipt' => true, 'new_synchronous_write_on_recovery' => true,
            'first_received_at_preserved' => true, 'facts' => 1, 'operations' => $operations];
    } catch (Throwable $failure) {
        $workerFailure = $worker->stop(15);
        $brokerFailure = $broker->stop(15);
        throw new RuntimeException($failure->getMessage() . '；接收进程：' . $workerFailure->stderr . $workerFailure->stdout
            . '；Broker：' . $brokerFailure->stderr . $brokerFailure->stdout, 0, $failure);
    } finally {
        if ($gate->inTransaction()) {
            $gate->rollBack();
        }
        $standby->query('SELECT pg_wal_replay_resume()')->fetchColumn();
        $client->stop();
        $recoveryClient?->stop();
        $worker->stop(15);
        $recovered?->stop(15);
    }
}
