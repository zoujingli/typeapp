<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';
require __DIR__ . '/postgres-ha.php';

use Type\Testing\HttpClient;
use Type\Testing\Process;

/** 设备回执先落本地账本，再对照选主后的事实；测试监督者只以原身份重启失败角色。 */
function iotHaMqttChecks(array $fixture, array $command, array $environment, array $clientEnvironment, string $base, Process &$broker, #[SensitiveParameter] string $password, Closure $restartBroker): array
{
    $ha = $GLOBALS['postgresHa'];
    $root = dirname(__DIR__);
    $report = ['scope' => 'small-same-host-ha', 'client' => 'MQTT.js 5.15.0', 'tls' => true, 'stages' => [], 'role_exits' => [], 'confirmed_ids' => []];
    $clientEnvironment += ['TYPE_INGESTION_PASSWORD' => $password, 'TYPE_HA_LEDGER' => $base . '/ha-receipts.json',
        'TYPE_HA_PENDING' => $base . '/ha-pending.json', 'TYPE_HA_GATE' => $base . '/ha-gate'];
    $workerEnvironment = array_replace($environment, ['IOT_INGESTION_PASSWORD' => $password, 'IOT_INGESTION_PORT' => $environment['IOT_MQTT_PORT'],
        'IOT_INGESTION_CA' => 'certificate.pem', 'IOT_INGESTION_INSTANCE' => 'ha']);
    unset($workerEnvironment['IOT_INGESTION_SECRET_HASH']);
    $worker = null;
    $pendingClient = null;
    $wait = static function (Closure $condition, string $message, int $seconds = 15): void {
        $until = microtime(true) + $seconds;
        do {
            if ($condition()) {
                return;
            }
            usleep(30000);
        } while (microtime(true) < $until);
        throw new RuntimeException($message);
    };
    $startWorker = static function () use (&$worker, $workerEnvironment, $command, $root, $ha, $wait): void {
        $worker = new Process([...$command, 'iot:ingest'], $root, $workerEnvironment, 4194304);
        $wait(static function () use ($worker, $ha): bool {
            expect($worker->running(), 'HA接收角色提前退出：' . $worker->stderr());
            $subscription = $ha->connection()->query("SELECT subscriptions::text FROM type_mqtt_sessions WHERE client_id = 'iot-ingestion-ha' AND owner_id IS NOT NULL")->fetchColumn();
            return is_string($subscription) && str_contains($subscription, '$share/ingestion/');
        }, 'HA接收共享订阅未就绪');
    };
    $stopRoles = static function (bool $normal) use (&$worker, &$broker, &$report, $password, $fixture, $base): void {
        foreach (['ingestion' => $worker, 'broker' => $broker] as $role => $process) {
            if ($process === null) {
                continue;
            }
            $result = $process->stop(15);
            $log = $result->stdout . $result->stderr;
            foreach ([$password, hash('sha256', $password), $fixture['second']['credential']['password']] as $secret) {
                expect(!str_contains($log, $secret), 'HA角色日志泄漏凭据');
            }
            file_put_contents($base . '/ha-' . $role . '.log', $log, FILE_APPEND);
            expect(!$process->running(), 'HA角色未停止');
            if ($normal) {
                expect($result->successful(), '无故障时HA角色未正常退出：' . $role . ' ' . $result->stderr);
                $statistics = json_decode(trim($result->stdout), true, 32, JSON_THROW_ON_ERROR);
                foreach ($role === 'broker' ? ['connections', 'pendingCommits', 'closingSessions'] : ['pending', 'quarantined', 'running'] as $field) {
                    expect(!$statistics[$field], 'HA角色未释放资源：' . $field);
                }
            }
            $report['role_exits'][] = ['role' => $role, 'normal_expected' => $normal, 'exit_code' => $result->exitCode, 'stopped' => true];
        }
        $worker = null;
    };
    $recoverHttp = static function () use ($clientEnvironment, $fixture, &$report): void {
        $client = new HttpClient($clientEnvironment['TYPE_DEVICE_HTTP']);
        $statuses = [];
        $until = microtime(true) + 12;
        do {
            $response = $client->request('GET', $fixture['path'], ['Authorization' => 'Bearer ' . $fixture['token'], 'X-Tenant-Id' => $fixture['tenant']]);
            $statuses[] = $response->status;
            if ($response->status === 200) {
                $report['http_pool_reconnect'][] = $statuses;
                return;
            }
            expect($response->status >= 500, 'HA查询恢复出现非暂时性HTTP错误');
            usleep(50000);
        } while (microtime(true) < $until);
        throw new RuntimeException('同一HTTP进程未通过新请求回收旧主连接');
    };
    $step = static function (int $stage) use ($clientEnvironment, $root, $ha, $fixture, $base, &$report, &$worker, &$broker): void {
        $client = new Process(['node', $root . '/tests/iot-device-client.mjs', 'ha-step'], $root, $clientEnvironment + ['TYPE_HA_STAGE' => (string) $stage]);
        try {
            $result = $client->wait(90);
            if (!$result->successful()) {
                $observations = ['worker_running' => $worker?->running(), 'broker_running' => $broker->running(), 'stage' => $stage,
                    'sessions' => $ha->connection()->query('SELECT client_id, owner_id, subscriptions::text FROM type_mqtt_sessions ORDER BY client_id')->fetchAll(PDO::FETCH_ASSOC),
                    'deliveries' => $ha->connection()->query('SELECT client_id, packet_id, state, qos, started FROM type_mqtt_deliveries ORDER BY created_at')->fetchAll(PDO::FETCH_ASSOC),
                    'shared' => $ha->connection()->query("SELECT state, count(*) FROM type_mqtt_deliveries WHERE group_id <> '' GROUP BY state")->fetchAll(PDO::FETCH_ASSOC)];
                file_put_contents($base . '/ha-failure.json', json_encode($observations, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                throw new RuntimeException('HA标准设备阶段失败：' . $stage . ' ' . $result->stderr . $result->stdout . '；接收角色：' . $worker?->stderr() . '；Broker：' . $broker->stderr());
            }
            $report['stages'][] = json_decode(trim($result->stdout), true, 16, JSON_THROW_ON_ERROR);
        } finally {
            $client->stop();
        }
        $ledger = json_decode(file_get_contents($base . '/ha-receipts.json'), true, 32, JSON_THROW_ON_ERROR);
        $facts = $ha->connection()->prepare('SELECT i.message_id, i.sequence, i.content_hash, i.status, i.received_at FROM iot_ingestion i JOIN iot_ingestion_facts f ON f.message_id = i.message_id WHERE i.device_id = ? ORDER BY i.sequence');
        $facts->execute([$fixture['second']['device']['id']]);
        $rows = $facts->fetchAll(PDO::FETCH_ASSOC);
        expect(count($rows) === count($ledger) && count($rows) === $stage, '主切换后已确认ID丢失或原件重传生成了额外事实');
        foreach ($rows as $index => $row) {
            $receipt = $ledger[$index]['receipt'];
            expect($row['sequence'] === $receipt['sequence'] && $row['content_hash'] === $receipt['content_hash'] && $row['status'] === 'accepted'
                && (int) $row['received_at'] === $receipt['received_at'], '新主事实不匹配设备已经收到的原回执');
        }
        $ids = array_column($rows, 'message_id');
        expect(array_diff($report['confirmed_ids'], $ids) === [], '已确认ID集合在切换后缩小');
        $report['confirmed_ids'] = $ids;
        $report['snapshots'][] = $ha->snapshot();
    };
    try {
        $startWorker();
        $step(1);
        $pendingClient = new Process(['node', $root . '/tests/iot-device-client.mjs', 'ha-pending'], $root, $clientEnvironment);
        $wait(static fn (): bool => str_contains($pendingClient->stdout(), "pending\n"), '设备尚未收到待PUBACK消息');
        $report['primary_loss'] = $ha->crashLeader();
        $pending = $pendingClient->wait(20);
        expect($pending->successful(), '主切换前QoS1未确认交换失败：' . $pending->stderr);
        $report['qos1_before_loss'] = json_decode(trim(substr($pending->stdout, strlen("pending\n"))), true, 16, JSON_THROW_ON_ERROR);
        $stopRoles(false);
        $recoverHttp();
        $broker = $restartBroker();
        $startWorker();
        $step(2);
        if (in_array('--ha-primary-only', $GLOBALS['argv'], true)) {
            $stopRoles(true);
            return $report;
        }
        $report['network_partition'] = $ha->partitionLeader();
        $stopRoles(false);
        $recoverHttp();
        $broker = $restartBroker();
        $startWorker();
        $step(3);
        $pendingClient = new Process(['node', $root . '/tests/iot-device-client.mjs', 'ha-no-sync'], $root, $clientEnvironment);
        $wait(static fn (): bool => str_contains($pendingClient->stdout(), "connected\n"), '无同步故障前设备未连接');
        $standbys = $ha->stopStandbys();
        try {
            file_put_contents($base . '/ha-gate', 'standbys-stopped');
            $failed = $pendingClient->wait(20);
            expect($failed->successful(), '无同步条件仍发送成功确认：' . $failed->stderr);
            $report['no_sync'] = json_decode(trim(substr($failed->stdout, strlen("connected\n"))), true, 16, JSON_THROW_ON_ERROR);
        } finally {
            $ha->restartStandbys($standbys);
        }
        $stopRoles(false);
        $recoverHttp();
        $broker = $restartBroker();
        $startWorker();
        $step(4);
        $stopRoles(true);
        $broker = $restartBroker();
        $startWorker();
        $step(5);
        $stopRoles(true);
        $report['backend_count_after_stop'] = (int) $ha->connection()->query("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name LIKE 'type_mqtt_%'")->fetchColumn();
        expect($report['backend_count_after_stop'] === 0, 'HA角色退出后仍保留持久工作后端');
        return $report;
    } finally {
        $pendingClient?->stop();
        if ($worker !== null || $broker->running()) {
            $stopRoles(false);
        }
    }
}

/** 独立入口复用完整身份、产品、设备和原生应用装配；故障仅触及PostgresHa持有的测试进程。 */
$haRoot = dirname(__DIR__);
if (!in_array('--infrastructure-only', $argv, true)) {
    // 与单主备设备装置相同：先解析外部客户端目录，再交给需要绝对路径的Node createRequire。
    $haClientRoot = realpath((string) getenv('TYPE_MQTT_CLIENT_ROOT'));
    expect(is_string($haClientRoot) && is_file($haClientRoot . '/node_modules/mqtt/package.json'), 'TYPE_MQTT_CLIENT_ROOT需要已安装MQTT.js 5.15.0的测试依赖根');
    putenv('TYPE_MQTT_CLIENT_ROOT=' . $haClientRoot);
}
$haDirectory = $haRoot . '/build/iot-ha-' . bin2hex(random_bytes(6));
$ha = new PostgresHa($haDirectory, NativeDatabase::tools('pgsql', (string) getenv('TYPE_PGSQL_TOOLS')));
$GLOBALS['postgresHa'] = $ha;
try {
    if (in_array('--infrastructure-only', $argv, true)) {
        $ha->crashLeader();
        $ha->partitionLeader();
        $haStandbys = $ha->stopStandbys();
        $ha->restartStandbys($haStandbys);
        echo json_encode($ha->evidence(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    } else {
        foreach ($ha->environment() as $key => $value) {
            putenv($key . '=' . $value);
        }
        $haArguments = array_values(array_filter(array_slice($argv, 2), static fn (string $value): bool => $value !== '--ha'));
        $argv = [$argv[0], $argv[1] ?? '--php', 'pgsql', '--products', '--devices', '--device-mqtt', '--ha', ...$haArguments];
        require __DIR__ . '/iot-identity.php';
    }
} catch (Throwable $failure) {
    $ha->failed();
    throw $failure;
} finally {
    $ha->close();
}
