<?php

declare(strict_types=1);

use Type\Testing\Process;

/** 正式HTTP入口建立完整基线设备与四条规则；可选择两个原基线租户，第二轮复用持久状态。 */
function iotLoadPrepare(string $base, string $endpoint, string $password, bool $phases = false): array
{
    $root = dirname(__DIR__);
    $config = ['baseline' => 'iot-baseline-v1', 'namespace' => 'baseline-check', 'devices' => 1,
        'http' => $endpoint, 'platform_login' => 'platform', 'owner_logins' => ['bob'], 'state' => 'load-state.sqlite'];
    if ($phases) {
        $config = array_replace($config, ['devices' => 2, 'device_indices' => [0, 5000], 'owner_logins' => ['bob', 'outsider']]);
    }
    file_put_contents($base . '/load-config.json', json_encode($config, JSON_THROW_ON_ERROR));
    $reports = [];
    for ($pass = 0; $pass < 2; $pass++) {
        $load = new Process(
            ['node', $root . '/tests/iot-load.mjs', 'prepare', $base . '/load-config.json'],
            $root,
            ['PATH' => (string) getenv('PATH'), 'TYPE_LOAD_PLATFORM_PASSWORD' => $password, 'TYPE_LOAD_OWNER_PASSWORD' => $password]
        );
        try {
            $prepared = $load->wait(60);
            expect($prepared->successful(), '固定负载准备失败：' . $prepared->stderr);
            $reports[] = json_decode($prepared->stdout, true, 32, JSON_THROW_ON_ERROR);
        } finally {
            $load->stop();
        }
    }
    expect($reports[0]['devices'] === $config['devices'] && $reports[0]['rules'] === $config['devices'] * 4 && $reports[1]['requests'] === [], '固定负载恢复不应重复创建业务对象');
    return ['config' => $config, 'reports' => $reports, 'resume_without_duplicate' => true, 'model_validated_by_public_http' => true];
}

/** 数据和断点同事务提交；真实HTTP确认可读，合成账本不成为accepted或持久回执证明。 */
function iotLoadHistory(string $base, array $environment, #[SensitiveParameter] string $password): array
{
    $root = dirname(__DIR__);
    $database = new PDO(
        'pgsql:host=' . $environment['DB_HOST'] . ';port=' . $environment['DB_PORT'] . ';dbname=' . $environment['DB_DATABASE'],
        $environment['DB_USERNAME'],
        $environment['DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $config = json_decode(file_get_contents($base . '/load-config.json'), true, 16, JSON_THROW_ON_ERROR);
    $config['history'] = ['end_utc' => (int) floor(time() / 60) * 60 - 120, 'raw_seconds' => 1800, 'aggregate_seconds' => 3600,
        'database' => $environment['DB_DATABASE'], 'system_identifier' => (string) $database->query('SELECT system_identifier::text FROM pg_control_system()')->fetchColumn(), 'max_batches' => 1];
    $workerEnvironment = $environment + ['TYPE_LOAD_OWNER_PASSWORD' => $password, 'TYPE_LOAD_PHP' => PHP_BINARY,
        'TYPE_LOAD_SEED_HOST' => $environment['DB_HOST'], 'TYPE_LOAD_SEED_PORT' => $environment['DB_PORT'],
        'TYPE_LOAD_SEED_USER' => $environment['DB_USERNAME'], 'TYPE_LOAD_SEED_PASSWORD' => $environment['DB_PASSWORD']];
    $reports = [];
    $faults = [];
    foreach (['seed', 'seed', 'seed', 'inspect'] as $pass => $action) {
        if ($pass > 0) {
            unset($config['history']['max_batches']);
        }
        file_put_contents($base . '/load-config.json', json_encode($config, JSON_THROW_ON_ERROR));
        $worker = new Process(['node', $root . '/tests/iot-load.mjs', $action, $base . '/load-config.json'], $root, $workerEnvironment);
        try {
            $done = $worker->wait(90);
            expect($done->successful(), '合成历史生成/公开查询失败：' . $done->stderr);
            $reports[] = json_decode($done->stdout, true, 32, JSON_THROW_ON_ERROR);
        } finally {
            $worker->stop();
        }
        if ($pass === 0) {
            $faults = iotLoadHistoryFaults($base, $database, $workerEnvironment, $reports[0]['definition']);
        }
    }
    expect($reports[0]['status'] === 'checkpointed' && $reports[0]['cursors']['raw_cursor'] === 128
        && $reports[1]['status'] === 'completed' && $reports[2]['batches_this_run'] === 0, '预置中断/恢复产生重复或未完整提交');
    expect($reports[3]['reports'][0]['history_total'] === 180 && $reports[3]['reports'][0]['curve']['minute_count'] === 60, '预置原始或分钟数据无法从真实接口查询');
    $ledger = $database->query('SELECT status,receipt_proof_nonce,receipt_requested_at,COUNT(*) AS total FROM iot_ingestion GROUP BY status,receipt_proof_nonce,receipt_requested_at')->fetchAll(PDO::FETCH_ASSOC);
    expect(count($ledger) === 1 && $ledger[0]['status'] === 'synthetic' && $ledger[0]['receipt_proof_nonce'] === ''
        && (int) $ledger[0]['receipt_requested_at'] === 0 && (int) $ledger[0]['total'] === 180, '历史预置不能伪造平台持久回执');
    expect((int) $database->query("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name='typeapp-load-history'")->fetchColumn() === 0, '历史worker数据库会话未回收');
    $evidence = ['reports' => $reports, 'faults' => $faults, 'resume_without_duplicate' => true, 'public_raw_and_minute_queries' => true,
        'no_synthetic_receipt_proof' => true, 'owned_workers_stopped' => true, 'capacity_claim' => false];
    file_put_contents($base . '/load-history-verification.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    return $evidence;
}

/** 在真实批次事务中断前观察锁等待，验证已执行的写入与断点一起回滚；只操作当前隔离数据库。 */
function iotLoadHistoryFaults(string $base, PDO $database, array $environment, array $definition): array
{
    $root = dirname(__DIR__);
    $state = new PDO('sqlite:' . $base . '/load-state.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $query = $state->query("SELECT value FROM state WHERE key='device:0'");
    $record = json_decode($query->fetchColumn(), true, 32, JSON_THROW_ON_ERROR);
    $query->closeCursor();
    $device = $record['device'] + ['index' => 0];
    $open = ['action' => 'open', 'definition' => $definition, 'devices' => [$device]];
    $snapshot = static function () use ($database): array {
        return $database->query('SELECT (SELECT COUNT(*) FROM iot_ingestion) AS ledger, (SELECT COUNT(*) FROM iot_ingestion_facts) AS facts,
            (SELECT COUNT(*) FROM iot_ingestion_completed) AS markers, (SELECT SUM(raw_cursor) FROM iot_load_seed_jobs) AS cursor')->fetch(PDO::FETCH_ASSOC);
    };
    $before = $snapshot();
    $start = static function (string $name, array $requests) use ($root, $base, $environment): Process {
        $input = $base . '/history-' . $name . '.jsonl';
        file_put_contents($input, implode("\n", array_map(static fn (array $request): string => json_encode($request, JSON_THROW_ON_ERROR), $requests)) . "\n");
        chmod($input, 0600);
        return new Process([PHP_BINARY, $root . '/tests/iot-load-history.php'], $root, $environment, 2097152, $input);
    };
    $cases = [];
    foreach (['identity' => 'load_history_target_identity_mismatch', 'definition' => 'load_history_definition_changed'] as $name => $expected) {
        $changed = $open;
        if ($name === 'identity') {
            $changed['definition']['system_identifier'] .= '0';
        } else {
            $changed['definition']['end_utc'] -= 60;
        }
        $worker = $start($name, [$changed]);
        try {
            $done = $worker->wait(15);
            $answer = json_decode(trim($done->stdout), true, 16, JSON_THROW_ON_ERROR);
            expect($done->exitCode === 1 && $answer === ['status' => 'failed', 'error' => $expected], '历史预置目标或原任务描述变化未拒绝');
            expect($snapshot() === $before, '被拒绝的历史预置改变了原始事实或断点');
            $cases[] = $expected;
        } finally {
            $worker->stop();
        }
    }
    $row = $database->query('SELECT message_id,tenant_id,device_id,ownership_id,product_id,model_version,sequence,sampled_at,received_at,values_json FROM iot_ingestion_facts ORDER BY sampled_at LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    foreach (['model_version', 'sampled_at', 'received_at'] as $field) {
        $row[$field] = (int) $row[$field];
    }
    $row['content_hash'] = hash('sha256', 'rollback-only');
    $rows = [];
    foreach ([0, 1] as $offset) {
        $next = $row;
        $next['message_id'] = hash('sha256', random_bytes(16));
        $next['sequence'] = '900000000000000000000' . $offset;
        $rows[] = $next;
    }
    $batch = ['action' => 'batch', 'stage' => 'raw', 'cursor' => (int) $before['cursor'], 'rows' => $rows];
    $invalid = $batch;
    $invalid['rows'][1]['ownership_id'] = str_repeat('0', 32);
    $worker = $start('rollback', [$open, $invalid]);
    try {
        $done = $worker->wait(15);
        $lines = explode("\n", trim($done->stdout));
        $answer = json_decode($lines[count($lines) - 1], true, 16, JSON_THROW_ON_ERROR);
        expect($done->exitCode === 1 && $answer === ['status' => 'failed', 'error' => 'load_history_row_scope_mismatch'], '无效第二行未中止当前批次');
        expect($snapshot() === $before, '无效第二行导致前一行或断点部分提交');
        $cases[] = 'invalid-second-row-rolls-back-first-row-and-cursor';
    } finally {
        $worker->stop();
    }
    // 专用测试触发器仅阻塞第二条事实；此时第一条和第二条账本已执行但未提交。
    $gateId = random_int(1, 2147483647);
    $database->query('SELECT pg_advisory_lock(275001,' . $gateId . ')')->closeCursor();
    $worker = null;
    try {
        $database->exec('CREATE FUNCTION iot_load_test_gate() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.message_id = '
            . $database->quote($rows[1]['message_id']) . ' THEN PERFORM pg_advisory_xact_lock(275001,' . $gateId . '); END IF; RETURN NEW; END $$');
        $database->exec('CREATE TRIGGER iot_load_test_gate BEFORE INSERT ON iot_ingestion_facts FOR EACH ROW EXECUTE FUNCTION iot_load_test_gate()');
        $worker = $start('interrupted', [$open, $batch]);
        $until = microtime(true) + 4;
        $waiting = 0;
        do {
            expect($worker->running(), '历史批次在故障注入前退出：' . $worker->stdout());
            $waiting = (int) $database->query("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name='typeapp-load-history' AND wait_event_type='Lock' AND wait_event='advisory'")->fetchColumn();
            if ($waiting === 1) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $until);
        expect($waiting === 1, '历史批次没有到达第二行的真实事务锁');
        $stopped = $worker->stop(0);
        expect(in_array($stopped->signal, [9, 15], true) && !$worker->running(), '未确认历史worker异常退出');
        expect($snapshot() === $before, '历史worker退出后留下部分事实或提前推进断点');
        $cases[] = 'process-exit-during-second-row-rolls-back-batch-and-cursor';
    } finally {
        $worker?->stop();
        $database->query('SELECT pg_advisory_unlock(275001,' . $gateId . ')')->closeCursor();
        $database->exec('DROP TRIGGER IF EXISTS iot_load_test_gate ON iot_ingestion_facts');
        $database->exec('DROP FUNCTION IF EXISTS iot_load_test_gate()');
    }
    return ['cases' => $cases, 'unchanged_snapshot' => $before, 'owned_worker_stopped' => true];
}

/** 既有隔离主备、TLS和接收角色提供真实平台；额外负载不跳过鉴权、模型、聚合或告警计算。 */
function iotLoadMqtt(array $command, array $environment, string $base, PDO $database, Process $worker): array
{
    $root = dirname(__DIR__);
    $phases = in_array('--load-phases', $GLOBALS['argv'], true);
    $prepared = iotLoadPrepare($base, 'http://127.0.0.1:' . $environment['APP_PORT'], $environment['TYPE_LOAD_OWNER_PASSWORD'], $phases);
    $config = $prepared['config'] + ['mqtt' => 'mqtts://127.0.0.1:' . $environment['IOT_MQTT_PORT'], 'ca' => 'certificate.pem',
        'seconds' => 90, 'shard_count' => 1, 'cache_disk_mib' => 64, 'maximum_rss_mib' => 256];
    if ($phases) {
        $config['shard_count'] = 2;
        $config['phases'] = [['name' => 'steady', 'seconds' => 30, 'multiplier' => 1], ['name' => 'burst', 'seconds' => 10, 'multiplier' => 3], ['name' => 'recovery', 'seconds' => 50, 'multiplier' => 1]];
        $config['reconnect_seed'] = 'typeapp-iot-small-reconnect-v1';
        $config['reconnect'] = ['after_seconds' => 60, 'offline_seconds' => 2];
    }
    file_put_contents($base . '/load-config.json', json_encode($config, JSON_THROW_ON_ERROR));
    $load = new Process(['node', $root . '/tests/iot-load.mjs', 'run', $base . '/load-config.json'], $root, $environment);
    try {
        $downstream = [];
        if ($phases) {
            $until = microtime(true) + 110;
            while ($load->running() && microtime(true) < $until) {
                $downstream[] = ['observed_at' => time(), 'aggregate' => identityCommand([...$command, 'iot:aggregate'], $environment)['data'],
                    'alarm' => identityCommand([...$command, 'iot:alarm'], $environment)['data']];
                usleep(1000000);
            }
        }
        $run = $load->wait(115);
        expect($run->successful(), '混合负载执行失败：' . $run->stderr . $worker->stderr());
        $report = json_decode($run->stdout, true, 32, JSON_THROW_ON_ERROR);
        file_put_contents($base . '/load-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $counters = $report['metrics']['counters'];
        $checks = ['business_receipts' => ($counters['telemetry_accepted'] ?? 0) >= 6 && ($counters['event_accepted'] ?? 0) >= 1
            && ($counters['telemetry_rejected'] ?? 0) === 0 && ($counters['event_rejected'] ?? 0) === 0,
            'control_and_web_list' => ($counters['simulated_actions'] ?? 0) >= 1 && ($counters['command_result_succeeded'] ?? 0) >= 1
            && ($counters['web_list_success'] ?? 0) >= 1];
        if ($phases) {
            $checks['web_list_and_curve'] = ($counters['web_curve_success'] ?? 0) >= 1 && count($report['web_sessions']) === 2;
            $checks['phase_sampling_counts'] = array_map(static fn (array $phase): int => array_values(array_filter($phase['messages'], static fn (array $message): bool => $message['type'] === 'telemetry'))[0]['generated'] ?? 0, $report['phases']) === [6, 6, 10];
            $checks['tls_and_sessions_restored'] = $report['reconnect']['disconnect']['connected_before'] === 2 && count($report['reconnect']['resumed']) === 2
                && $report['reconnect']['elapsed_ms'] <= 60000 && array_sum(array_column($report['reconnect']['resumed'], 'session_present')) === 2
                && array_sum(array_column($report['reconnect']['resumed'], 'restored_subscription')) === 2;
            $checks['post_reconnect_receipt'] = $report['reconnect']['first_post_reconnect_receipt_ms'] !== null;
            $checks['backlog_recovery_observed'] = $report['backlog_recovery']['status'] === 'observed';
        }
        $state = new PDO('sqlite:' . $base . '/load-state.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $query = $state->query("SELECT value FROM state WHERE key='device:0'");
        $record = json_decode($query->fetchColumn(), true, 32, JSON_THROW_ON_ERROR);
        $query->closeCursor();
        for ($round = 0; $round < 10; $round++) {
            $aggregate = identityCommand([...$command, 'iot:aggregate'], $environment)['data'];
            $alarm = identityCommand([...$command, 'iot:alarm'], $environment)['data'];
            if (!$aggregate['has_more'] && !$alarm['has_more']) {
                break;
            }
        }
        expect($round < 10, '基线下游未在小规模预算内排空');
        $minutes = $database->prepare('SELECT fields_json FROM iot_minute_aggregates WHERE device_id=?');
        $minutes->execute([$record['device']['id']]);
        $rows = $minutes->fetchAll(PDO::FETCH_COLUMN);
        $checks['twenty_numeric_fields'] = $rows !== [] && count(json_decode($rows[0], true, 32, JSON_THROW_ON_ERROR)) === 20;
        $alarms = $database->prepare('SELECT status FROM iot_alarms WHERE device_id=?');
        $alarms->execute([$record['device']['id']]);
        $states = $alarms->fetchAll(PDO::FETCH_COLUMN);
        $alarmsRecovered = count($states) === 4 && count(array_filter($states, static fn (string $status): bool => $status === 'ended')) === 4;
        require_once __DIR__ . '/native-rollout-redis.php';
        $redis = new NativeRolloutRedis($base . '/load-notice-redis', $environment['TYPE_REDIS_SERVER']);
        try {
            $redisEnvironment = $redis->environment();
            $noticeEnvironment = array_replace($environment, ['IOT_NOTICES_REDIS_HOST' => $redisEnvironment['TYPE_REDIS_HOST'],
                'IOT_NOTICES_REDIS_PORT' => $redisEnvironment['TYPE_REDIS_PORT'], 'IOT_NOTICES_NAMESPACE' => 'load-' . basename($base)]);
            for ($round = 0; $round < 4; $round++) {
                identityCommand([...$command, 'iot:notices', '100'], $noticeEnvironment);
            }
            $inspection = new Process(['node', $root . '/tests/iot-load.mjs', 'inspect', $base . '/load-config.json'], $root, $environment);
            try {
                $inspected = $inspection->wait(30);
                expect($inspected->successful(), '基线公开查询对照失败：' . $inspected->stderr);
                $observations = json_decode($inspected->stdout, true, 32, JSON_THROW_ON_ERROR);
                file_put_contents($base . '/load-observations.json', json_encode($observations, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                $observed = $observations['reports'][0];
                $historyComplete = $observed['history_total'] >= 6 && $observed['curve']['minute_count'] >= 1;
                $noticesComplete = $observed['notices']['total'] === 8;
                $cache = new PDO('sqlite:' . $base . '/load-cache-0-' . $config['shard_count'] . '.sqlite');
                // 遥测历史不含事件；事件的真实回执已在上面的独立计数断言核验。
                foreach ($observations['reports'] as $deviceObservation) {
                    $receipts = $cache->prepare("SELECT sequence,server_received_at,sampled_ms FROM messages WHERE device=? AND status='accepted' AND type='telemetry'");
                    $receipts->execute([$deviceObservation['device_id']]);
                    foreach ($receipts->fetchAll(PDO::FETCH_ASSOC) as $receipt) {
                        $matching = array_values(array_filter($deviceObservation['history'], static fn (array $item): bool => $item['sequence'] === $receipt['sequence']));
                        expect(count($matching) === 1 && $matching[0]['received_at'] === (int) $receipt['server_received_at']
                            && $matching[0]['sampled_at'] === (int) floor($receipt['sampled_ms'] / 1000), '客户端回执与公开历史ID/双时间不一致');
                    }
                }
            } finally {
                $inspection->stop();
            }
        } finally {
            $redis->close();
        }
        $stopped = $worker->stop(15);
        expect($stopped->successful(), '基线接收角色正常退出失败：' . $stopped->stderr);
        $statistics = json_decode(trim($stopped->stdout), true, 16, JSON_THROW_ON_ERROR);
        expect(!$statistics['pending'] && !$statistics['quarantined'] && !$statistics['running'], '基线接收角色退出未释放受管工作');
        $checks += ['four_alarms_recovered' => $alarmsRecovered, 'history_complete' => $historyComplete, 'eight_notices' => $noticesComplete];
        $evidence = ['scope' => 'fixed-load-small', 'tls' => true, 'checks' => $checks, 'prepare' => $prepared['reports'], 'load' => $report,
            'numeric_fields_per_minute_row' => $rows === [] ? null : count(json_decode($rows[0], true, 32, JSON_THROW_ON_ERROR)), 'four_alarms_recovered' => $alarmsRecovered, 'alarm_states' => $states,
            'history_complete' => $historyComplete, 'notices_complete' => $noticesComplete,
            'notices' => $observed['notices']['total'], 'public_observations' => $observations,
            'receipt_id_and_times_match' => true, 'downstream_during_load' => $downstream, 'ingestion' => $statistics, 'worker_stopped' => true, 'capacity_claim' => false];
        // 保存各独立观察后再按原门槛失败；告警失败不能阻止已确认ID的真实历史对照。
        file_put_contents($base . '/load-verification.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        expect(!in_array(false, $checks, true), '混合负载未通过：' . json_encode(array_keys(array_filter($checks, static fn (bool $passed): bool => !$passed)), JSON_THROW_ON_ERROR));
        return $evidence;
    } finally {
        $load->stop();
    }
}

if (realpath($argv[0] ?? '') === __FILE__) {
    require __DIR__ . '/support.php';
    require dirname(__DIR__) . '/vendor/autoload.php';
    require __DIR__ . '/native-database.php';
    expect(count($argv) >= 2 && count($argv) <= 3 && array_diff(array_slice($argv, 2), ['--no-source']) === [], '用法：php tests/iot-load.php <原生产物或--php> [--no-source]');
    $root = dirname(__DIR__);
    $base = $root . '/build/iot-load-history-' . bin2hex(random_bytes(6));
    expect(mkdir($base, 0700), '无法创建历史负载隔离目录');
    $database = new NativeDatabase($base . '/database', 'pgsql', NativeDatabase::tools('pgsql', (string) getenv('TYPE_PGSQL_TOOLS')));
    try {
        $environment = array_replace(getenv(), $database->environment());
        $environment['PATH'] = (string) getenv('PATH');
        echo nativeDatabaseCommand(
            [PHP_BINARY, $root . '/tests/iot-identity.php', $argv[1], 'pgsql', '--load-baseline', '--load-history', ...array_slice($argv, 2)],
            $environment,
            [$environment['TYPE_PGSQL_PASSWORD']],
            $base . '/application.log',
            180
        );
    } finally {
        $database->close();
    }
}
