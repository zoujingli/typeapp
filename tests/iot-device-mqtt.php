<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';
require __DIR__ . '/postgres-sync.php';

$root = dirname(__DIR__);
$target = $argv[1] ?? '--php';
if ($target === '--analyze-receipt-db-trace') {
    expect(count($argv) === 4, '离线数据库诊断需要原始日志和阶段文件');
    echo json_encode(iotReceiptDatabaseTrace($argv[2], $argv[3]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}
$ioReceiptBaseline = in_array('--io-receipt-baseline', $argv, true);
$ioReceiptTrace = in_array('--io-receipt-db-trace', $argv, true);
expect(!$ioReceiptBaseline || in_array('--ingestion', $argv, true), '回执阶段基线需要--ingestion');
expect(!$ioReceiptTrace || $ioReceiptBaseline, '数据库诊断需要--io-receipt-baseline');
$tools = NativeDatabase::tools('pgsql', (string) getenv('TYPE_PGSQL_TOOLS'));
$clientRoot = realpath((string) getenv('TYPE_MQTT_CLIENT_ROOT'));
expect($ioReceiptBaseline || (is_string($clientRoot) && is_file($clientRoot . '/node_modules/mqtt/package.json')), 'TYPE_MQTT_CLIENT_ROOT需要已安装MQTT.js 5.15.0的测试依赖根');
$base = $root . '/build/iot-device-mqtt-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建接入测试根');
$database = new NativeDatabase($base . '/primary', 'pgsql', $tools);
$sync = null;
try {
    $sync = new PostgresSync($database, $base . '/standby', $tools);
    $environment = array_replace(getenv(), $database->environment());
    $environment['PATH'] = (string) getenv('PATH');
    $environment['TYPE_MQTT_CLIENT_ROOT'] = $clientRoot ?: '';
    $environment['TYPE_PGSQL_STANDBY_PORT'] = (string) $sync->standby()->query('SHOW port')->fetchColumn();
    if ($ioReceiptTrace) {
        $environment['TYPE_IO_RECEIPT_TRACE_PATH'] = $base . '/receipt-phases.json';
    }
    $output = nativeDatabaseCommand(
        [PHP_BINARY, $root . '/tests/iot-identity.php', $target, 'pgsql', '--devices', '--device-mqtt', ...array_slice($argv, 2)],
        $environment,
        [$environment['TYPE_PGSQL_PASSWORD']],
        $base . '/application.log',
        // 对账/掉电场景包含真实 60 秒等待；模型场景包含两段 35 秒确认、重启及上报，组合预算须覆盖完整业务，单次网络/客户端预算为 180 秒。
        in_array('--broker-resources', $argv, true) && getenv('TYPE_BROKER_RESOURCE_DIST') ? 600
            : (in_array('--ingestion', $argv, true) ? 600 : (in_array('--lifecycle', $argv, true) || in_array('--lifecycle-mqtt', $argv, true) ? 360 : 240)),
        $ioReceiptTrace ? static fn () => $database->drainOutput() : null
    );
    echo $output;
} finally {
    try {
        $sync?->close();
    } finally {
        $database->close();
    }
}
if ($ioReceiptTrace) {
    $report = iotReceiptDatabaseTrace($base . '/primary/server.log', $base . '/receipt-phases.json');
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    expect(file_put_contents($base . '/receipt-db-trace.json', $json) === strlen($json) && chmod($base . '/receipt-db-trace.json', 0600), '无法保存数据库诊断');
    echo '回执数据库诊断通过：' . $base . "/receipt-db-trace.json\n";
}

/**
 * 停库后按 PostgreSQL session ID 关联建连、耗时和断连，不记录 SQL 文本或推断业务 action。
 * duration 包括扩展协议的 parse/bind/execute；区间并集避免把并行会话耗时相加当作墙钟时间。
 * @return array<string,mixed> 有界原始日志的身份、窗口及数据库耗时估计。
 */
function iotReceiptDatabaseTrace(string $logPath, string $phasesPath): array
{
    expect(filesize($logPath) < 16777216 && filesize($phasesPath) < 1048576, '数据库诊断输入超过预算');
    $phases = json_decode((string) file_get_contents($phasesPath), true, 32, JSON_THROW_ON_ERROR);
    $sessions = [];
    $events = [];
    $log = fopen($logPath, 'rb');
    expect(is_resource($log), '无法读取数据库日志');
    try {
        while (($line = fgets($log)) !== false) {
            if (!str_starts_with($line, 'IO|')) {
                continue;
            }
            expect(preg_match('/^IO\|([0-9]+\.[0-9]{3})\|([0-9]+)\|([a-f0-9.]+)\|([^|]*)\|(.*)$/D', rtrim($line, "\r\n"), $match) === 1, '数据库诊断日志前缀不完整');
            $time = (float) $match[1] * 1000;
            $id = $match[3];
            $sessions[$id] ??= ['pid' => (int) $match[2], 'application' => '', 'named_at_connect' => false, 'start' => null, 'end' => null];
            if (preg_match('/^type_mqtt_[a-f0-9]{32}$/D', $match[4]) === 1) {
                $sessions[$id]['application'] = $match[4];
            }
            $message = $match[5];
            if (str_contains($message, 'connection received:')) {
                $sessions[$id]['start'] = $time;
            } elseif (str_contains($message, 'connection authorized:')) {
                // 此时 %a 仍可能是 [unknown]；连接报文本身才包含启动参数中的 application_name。
                if (preg_match('/ application_name=(type_mqtt_[a-f0-9]{32})(?:\s|$)/D', $message, $application) === 1) {
                    $sessions[$id]['application'] = $application[1];
                    $sessions[$id]['named_at_connect'] = true;
                }
            } elseif (str_contains($message, 'disconnection:')) {
                $sessions[$id]['end'] = $time;
            } elseif (preg_match('/^LOG:  duration: ([0-9]+\.[0-9]+) ms\s*$/D', $message, $duration) === 1) {
                $events[] = ['session' => $id, 'start' => $time - (float) $duration[1], 'end' => $time];
            } else {
                expect(!str_contains($message, 'duration:') && !preg_match('/^(?:LOG:  statement:|STATEMENT:|DETAIL:  parameters:)/', $message), '数据库诊断出现非预期语句日志');
            }
        }
        expect(feof($log), '数据库日志读取未完成');
    } finally {
        fclose($log);
    }
    expect($events !== [], '数据库诊断没有耗时事件');
    $windows = [];
    foreach ($phases['rounds'] as $round => $row) {
        foreach (['warmup' => $row['warmup_observations'], 'normal' => $row['samples'], 'slow' => [$row['slow_dependency']]] as $kind => $observations) {
            foreach ($observations as $observation) {
                $start = $observation['epoch_ms']['publish_started'];
                $end = $observation['epoch_ms']['puback'];
                $wall = $end - $start;
                expect($wall > 0 && abs($wall - $observation['receipt_publish_puback_ms']) < 1, '诊断墙钟与单调时钟偏差过大');
                $overlap = [];
                $lifetimes = [];
                foreach ($sessions as $id => $session) {
                    if ($session['application'] === '' || $session['start'] === null || $session['end'] === null
                        || $session['end'] < $start || $session['start'] > $end) {
                        continue;
                    }
                    $lifetimes[] = [$session['start'], $session['end']];
                    $overlap[$id] = ['pid' => $session['pid'], 'application' => $session['application'], 'named_at_connect' => $session['named_at_connect'],
                        'start_offset_ms' => $session['start'] - $start, 'end_offset_ms' => $session['end'] - $start];
                }
                $intervals = [];
                $mqttIntervals = [];
                $padded = [];
                $durationSum = 0.0;
                foreach ($events as $event) {
                    if ($event['end'] < $start || $event['start'] > $end) {
                        continue;
                    }
                    $intervals[] = [$event['start'], $event['end']];
                    $padded[] = [$event['start'] - 1, $event['end'] + 1];
                    $durationSum += max(0, min($end, $event['end']) - max($start, $event['start']));
                    if ($sessions[$event['session']]['application'] !== '') {
                        expect(isset($overlap[$event['session']]), '发布窗口内的命名数据库会话缺少完整建连或断连证据');
                        $mqttIntervals[] = [$event['start'], $event['end']];
                    }
                }
                expect($overlap !== [] && $mqttIntervals !== [], '发布窗口没有对应的数据库会话或耗时');
                $windows[] = ['round' => $round + 1, 'kind' => $kind, 'sequence' => $observation['sequence'], 'wall_ms' => $wall,
                    'clock_delta_ms' => $wall - $observation['receipt_publish_puback_ms'], 'mqtt_sessions' => $overlap,
                    'mqtt_session_union_ms' => iotReceiptIntervalUnion($lifetimes, $start, $end),
                    'all_backend_duration_events' => count($intervals), 'all_backend_duration_sum_ms' => $durationSum,
                    'all_backend_duration_union_ms' => iotReceiptIntervalUnion($intervals, $start, $end),
                    'all_backend_duration_padded_union_ms' => iotReceiptIntervalUnion($padded, $start, $end),
                    'mqtt_duration_union_ms' => iotReceiptIntervalUnion($mqttIntervals, $start, $end)];
            }
        }
    }
    return ['scope' => 'receipt-publish-puback-postgresql-log-diagnostic', 'log_sha256' => hash_file('sha256', $logPath),
        'phases_sha256' => hash_file('sha256', $phasesPath), 'log_bytes' => filesize($logPath), 'duration_events' => count($events),
        'session_ids' => count($sessions), 'timestamp_resolution_ms' => 1, 'duration_interval_padding_ms' => 1, 'windows' => $windows];
}

/** @param list<array{float,float}> $intervals 同一墙钟的区间；裁剪到当前窗口后计算并集。 */
function iotReceiptIntervalUnion(array $intervals, float $start, float $end): float
{
    usort($intervals, static fn (array $left, array $right): int => $left[0] <=> $right[0]);
    $cursor = $start;
    $total = 0.0;
    foreach ($intervals as [$left, $right]) {
        $left = max($cursor, $left, $start);
        $right = min($end, $right);
        if ($right > $left) {
            $total += $right - $left;
            $cursor = $right;
        }
    }
    return $total;
}
