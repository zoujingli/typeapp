<?php

declare(strict_types=1);

/** HTTP身份/动作、真实数据库锁及独立导出进程验证；边界装置不代替业务执行或MQTT证据。 */
function iotSupportChecks(Closure $request, array $tokens, string $tenantA, string $tenantB, PDO $database, array $command, array $environment, string $base, Type\Testing\HttpClient $client): array
{
    $path = '/iot/tenants/' . $tenantA;
    $grants = $path . '/support-grants';
    $admin = $tokens['bob'];
    $platform = $tokens['platform'];
    $data = ['id' => bin2hex(random_bytes(16)), 'login' => 'platform', 'duration_seconds' => 3600, 'reason' => str_repeat('核查设备与历史数据', 10)];
    $create = static fn (array $values): array => $request('POST', $grants, $admin, $tenantA, $values, 200)['data'];
    $revoke = static fn (array $grant): array => $request('DELETE', $grants . '/' . $grant['id'], $admin, $tenantA, ['version' => $grant['version']], 200)['data'];
    $request('POST', $grants, $tokens['alice'], $tenantA, $data, 403);
    $request('POST', $grants, $platform, $tenantA, $data, 403);
    foreach ([['duration_seconds' => 3601], ['duration_seconds' => 0], ['reason' => ' '], ['control' => 'true'], ['permissions' => ['device.manage']]] as $invalid) {
        $request('POST', $grants, $admin, $tenantA, array_replace($data, $invalid), 422);
    }
    $request('POST', $grants, $admin, $tenantA, array_replace($data, ['login' => 'alice']), 422, 'support_account_unavailable');
    $readonly = $create($data);
    expect($readonly['expires_at'] - $readonly['created_at'] === 3600 && !$readonly['control_allowed'] && !$readonly['alarm_allowed'] && !$readonly['export_allowed'], '默认只读且原期限最多一小时');
    expect($create($data) == $readonly, '响应未知重试必须返回原ID、原期限和原范围');
    $request('POST', $grants, $admin, $tenantA, array_replace($data, ['control' => true]), 409, 'support_grant_conflict');
    $own = $request('GET', '/iot/support-grants?status=active&per_page=1&search=' . rawurlencode('租户甲'), $platform, null, null, 200);
    expect($own['total'] === 1 && $own['items'][0]['id'] === $readonly['id'] && $own['items'][0]['tenant_name'] === '租户甲', '自身授权按租户名筛选、有界分页并返回真实范围');
    $request('GET', '/iot/support-grants', $tokens['alice'], null, null, 403);
    $request('GET', '/iot/auth/me', $platform, $tenantB, null, 403, 'support_forbidden', $readonly['id']);
    $request('GET', '/iot/auth/me', $tokens['alice'], $tenantA, null, 403, 'support_forbidden', $readonly['id']);
    $request('GET', '/iot/auth/me', $platform, $tenantA, null, 403, 'support_context_invalid', 'invalid');
    $context = $request('GET', '/iot/auth/me', $platform, $tenantA, null, 200, null, $readonly['id'])['data']['context'];
    expect($context['role'] === 'support' && !in_array('command.create', $context['permissions'], true) && $context['support']['id'] === $readonly['id'], '支持上下文不能隐含控制');
    $request('POST', $path . '/members', $admin, $tenantA, ['login' => 'platform', 'role' => 'admin'], 201);
    expect($request('GET', '/iot/auth/me', $platform, $tenantA, null, 200)['data']['context']['role'] === 'admin', '显式成员上下文保持独立');
    $request('POST', $path . '/members', $platform, $tenantA, ['login' => 'outsider', 'role' => 'admin'], 403, 'support_forbidden', $readonly['id']);
    $request('POST', $grants, $platform, $tenantA, array_replace($data, ['id' => bin2hex(random_bytes(16))]), 403, 'support_forbidden', $readonly['id']);
    $request('POST', $path . '/products', $platform, $tenantA, ['name' => '越权产品'], 403, 'support_forbidden', $readonly['id']);
    $product = $request('POST', $path . '/products', $admin, $tenantA, ['name' => '限时支持验收产品'], 201)['data'];
    $definition = ['properties' => [['identifier' => 'temperature', 'name' => '温度', 'type' => 'number', 'required' => false, 'unit' => '°C']], 'events' => [],
        'commands' => [['identifier' => 'switch', 'name' => '开关', 'parameters' => [['identifier' => 'on', 'name' => '开启', 'type' => 'boolean', 'required' => true]]]]];
    $models = $path . '/products/' . $product['id'] . '/models';
    $request('POST', $models, $admin, $tenantA, ['definition' => $definition], 201);
    $request('POST', $models . '/1/publish', $admin, $tenantA, ['version' => 1], 200);
    $registered = $request('POST', $path . '/devices', $admin, $tenantA, ['name' => '真实支持查询设备', 'product_id' => $product['id'], 'model_version' => 1], 201)['data'];
    $device = $registered['device'];
    $devicePath = $path . '/devices/' . $device['id'];
    expect($request('GET', $devicePath, $platform, $tenantA, null, 200, null, $readonly['id'])['data']['id'] === $device['id'], '默认支持可读取真实设备');
    $control = ['command_id' => bin2hex(random_bytes(16)), 'identifier' => 'switch', 'values' => ['on' => true]];
    $request('POST', $devicePath . '/commands', $platform, $tenantA, $control, 403, 'support_forbidden', $readonly['id']);
    $filters = ['kind' => 'records', 'from' => time() - 3600, 'to' => time(), 'timezone' => 'Asia/Shanghai'];
    $request('POST', $devicePath . '/exports', $platform, $tenantA, $filters, 403, 'support_forbidden', $readonly['id']);
    $request('GET', $grants, $platform, $tenantA, null, 403, 'support_forbidden', $readonly['id']);
    $controlGrant = $create(array_replace($data, ['id' => bin2hex(random_bytes(16)), 'control' => true]));
    $alarmGrant = $create(array_replace($data, ['id' => bin2hex(random_bytes(16)), 'alarm' => true]));
    $exportGrant = $create(array_replace($data, ['id' => bin2hex(random_bytes(16)), 'export' => true]));
    foreach ([[$controlGrant, 'command.create', 'alarm.acknowledge'], [$alarmGrant, 'alarm.acknowledge', 'export.create'], [$exportGrant, 'export.create', 'command.create']] as [$grant, $allowed, $denied]) {
        $scope = $request('GET', '/iot/auth/me', $platform, $tenantA, null, 200, null, $grant['id'])['data']['context'];
        expect(in_array($allowed, $scope['permissions'], true) && !in_array($denied, $scope['permissions'], true) && !in_array('transfer.manage', $scope['permissions'], true), '三个可选范围独立、不授予管理/转移');
    }
    $request('POST', $devicePath . '/commands', $platform, $tenantA, $control, 409, 'command_device_not_online', $controlGrant['id']);
    $runId = bin2hex(random_bytes(16));
    $database->prepare("UPDATE iot_devices SET lifecycle = 'enabled' WHERE id = ?")->execute([$device['id']]);
    $database->prepare('INSERT INTO iot_broker_observations (node_id, run_id, observed_at, expires_at) VALUES (?, ?, ?, ?)')->execute(['support-fixture', $runId, time(), time() + 3600]);
    $database->prepare("UPDATE iot_device_connections SET status = 'online', node_id = ?, run_id = ?, observed_at = ? WHERE device_id = ?")->execute(['support-fixture', $runId, time(), $device['id']]);
    $accepted = $request('POST', $devicePath . '/commands', $platform, $tenantA, $control, 201, null, $controlGrant['id'])['data'];
    expect($accepted['execution'] === 'pending', '明确控制权限只受理指令，不能伪造执行成功');
    $commandPath = $devicePath . '/commands/' . $accepted['id'];
    expect($request('GET', $commandPath, $tokens['alice'], $tenantA, null, 200)['data'] === $accepted, '只读成员按原ID应读取相同受理事实');
    expect($request('GET', $commandPath, $platform, $tenantA, null, 200, null, $readonly['id'])['data'] === $accepted, '默认只读支持应读取相同指令投影');
    $request('GET', $commandPath, '', $tenantA, null, 401);
    $request('GET', $commandPath, $tokens['outsider'], $tenantA, null, 403, 'tenant_forbidden');
    $request('GET', $commandPath, $admin, $tenantB, null, 403, 'tenant_context_mismatch');
    $request('GET', '/iot/tenants/' . $tenantB . '/devices/' . $device['id'] . '/commands/' . $accepted['id'], $admin, $tenantB, null, 404, 'command_not_found');
    $request('GET', $path . '/devices/' . str_repeat('0', 32) . '/commands/' . $accepted['id'], $admin, $tenantA, null, 404, 'command_not_found');
    $request('GET', $devicePath . '/commands/' . str_repeat('0', 32), $admin, $tenantA, null, 404, 'command_not_found');
    $database->prepare('UPDATE iot_commands SET accepted_at = ?, deadline_at = ?, next_action_at = NULL, schedule_stage = 6 WHERE id = ?')->execute([time() - 360, time() - 300, $accepted['id']]);
    // 超过一页的真实受理只验证读取入口；没有启动Broker或将这些意图当作设备执行证据。
    for ($index = 0; $index < 101; $index++) {
        $request('POST', $devicePath . '/commands', $platform, $tenantA, array_replace($control, ['command_id' => bin2hex(random_bytes(16))]), 201, null, $controlGrant['id']);
    }
    $latest = $request('GET', $devicePath . '/commands?per_page=100', $tokens['alice'], $tenantA, null, 200);
    expect($latest['total'] === 102 && !in_array($accepted['id'], array_column($latest['items'], 'id'), true), '原指令应已翻出最近100条');
    $beforeRead = $database->query('SELECT COUNT(*) FROM iot_command_attempts')->fetchColumn();
    $unknown = $request('GET', $commandPath, $tokens['alice'], $tenantA, null, 200)['data'];
    expect($unknown['id'] === $accepted['id'] && $unknown['execution'] === 'unknown' && $unknown['result_status'] === null
        && $unknown['unknown_reason'] === 'execution_receipt_missing' && !isset($unknown['timeline']), '单条读取须保留旧未知事实且不隐式读取时间线');
    expect($database->query('SELECT COUNT(*) FROM iot_command_attempts')->fetchColumn() === $beforeRead, '读取未知结果不能创建主动查询或发送节点');
    $queryPath = $devicePath . '/commands/' . $accepted['id'] . '/queries';
    $query = ['query_id' => bin2hex(random_bytes(16))];
    $request('POST', $queryPath, $platform, $tenantA, $query, 403, 'support_forbidden', $readonly['id']);
    $requested = $request('POST', $queryPath, $platform, $tenantA, $query, 202, null, $controlGrant['id'])['data'];
    expect($requested['support_id'] === $controlGrant['id'] && $requested['state'] === 'queued', '主动结果查询保留确切支持来源');
    $request('POST', $queryPath, $platform, $tenantA, $query, 409, 'command_query_identity_conflict');
    $rule = $request('POST', $path . '/alarm-rules', $admin, $tenantA, ['name' => '支持确认阈值', 'device_id' => $device['id'], 'field' => 'temperature', 'upper' => 10, 'hysteresis' => 1], 201)['data'];
    $adapter = match ($environment['DB_DRIVER']) {
        'mysql' => new Type\Orm\Mysql\MysqlDriver($environment['DB_HOST'], (int) $environment['DB_PORT'], $environment['DB_DATABASE'], $environment['DB_USERNAME'], $environment['DB_PASSWORD']),
        'pgsql' => new Type\Orm\Pgsql\PgsqlDriver($environment['DB_HOST'], (int) $environment['DB_PORT'], $environment['DB_DATABASE'], $environment['DB_USERNAME'], $environment['DB_PASSWORD']),
        default => new Type\Orm\Sqlite\SqliteDriver($base . '/identity.sqlite', 1000, true),
    };
    $pool = new Type\Orm\Database($adapter, 1, 1);
    $executionScope = new Type\Runtime\ExecutionScope();
    try {
        $connection = $pool->connect($executionScope);
        $connection->transaction(static function (Type\Orm\Connection $transaction) use ($device, $registered): void {
            for ($sequence = 1; $sequence <= 205; $sequence++) {
                $now = time() - 1;
                $message = ['app_version' => 1, 'type' => 'telemetry', 'device_id' => $device['id'], 'ownership_id' => $device['ownership_id'], 'model_version' => 1,
                    'sequence' => (string) $sequence, 'sampled_at' => $now, 'values' => ['temperature' => 20]];
                $receipt = app\iot\service\IngestionService::accept($transaction, $registered['credential']['topics']['publish'], json_encode($message, JSON_THROW_ON_ERROR), 1, $now);
                expect($receipt['code'] === 'accepted', '支持验收遥测必须真实接收');
            }
        }, $environment['DB_DRIVER'] === 'sqlite' ? 'immediate' : 'default');
    } finally {
        $executionScope->close();
        $pool->close();
    }
    for ($round = 0; $round < 100; $round++) {
        if (!identityCommand([...$command, 'iot:alarm'], $environment)['data']['has_more']) {
            break;
        }
    }
    expect($round < 100, '告警待办应有界排空');
    $alarm = $request('GET', $path . '/alarms?device_id=' . $device['id'], $platform, $tenantA, null, 200, null, $readonly['id'])['items'][0];
    expect($alarm['rule_id'] === $rule['id'], '支持者读取真实触发告警');
    $ackPath = $path . '/alarms/' . $alarm['id'] . '/acknowledge';
    $request('POST', $ackPath, $platform, $tenantA, [], 403, 'support_forbidden', $readonly['id']);
    $request('POST', $ackPath, $platform, $tenantA, [], 403, 'support_forbidden', $controlGrant['id']);
    $ack = $request('POST', $ackPath, $platform, $tenantA, [], 200, null, $alarmGrant['id'])['data'];
    expect($ack['acknowledged_at'] !== null && $ack['status'] === 'active', '明确确认权限记录真实确认，不伪造恢复');
    $revoke($alarmGrant);
    $request('POST', $ackPath, $platform, $tenantA, [], 403, 'support_forbidden', $alarmGrant['id']);
    $redis = new NativeRolloutRedis($base . '/support-redis', (string) getenv('TYPE_REDIS_SERVER'));
    try {
        $redisEnvironment = $redis->environment();
        $workerEnvironment = $environment + ['IOT_EXPORT_REDIS_HOST' => $redisEnvironment['TYPE_REDIS_HOST'], 'IOT_EXPORT_REDIS_PORT' => $redisEnvironment['TYPE_REDIS_PORT'], 'IOT_EXPORT_NAMESPACE' => 'support-' . basename($base)];
        $filters['to'] = time();
        $export = static fn (string $support): array => $request('POST', $devicePath . '/exports', $platform, $tenantA, $filters, 202, null, $support)['data'];
        $completed = $export($exportGrant['id']);
        identityCommand([...$command, 'iot:exports', '100'], $workerEnvironment);
        $jobsPath = $path . '/exports';
        $headers = ['Authorization' => 'Bearer ' . $platform, 'X-Tenant-Id' => $tenantA, 'X-Support-Id' => $exportGrant['id']];
        $file = $client->request('GET', $jobsPath . '/' . $completed['id'] . '/download', $headers);
        expect($file->status === 200 && str_contains($file->body, '温度') && $completed['total_rows'] === 205, '明确导出授权可生成并下载完整真实历史');
        $pending = $export($exportGrant['id']);
        identityCommand([...$command, 'iot:exports', '1'], $workerEnvironment);
        $revoke($exportGrant);
        $request('GET', $jobsPath . '/' . $completed['id'] . '/download', $platform, $tenantA, null, 403, 'support_forbidden', $exportGrant['id']);
        identityCommand([...$command, 'iot:exports', '100'], $workerEnvironment);
        $storedJob = $database->prepare('SELECT status, error_code, support_id FROM iot_exports WHERE id = ?');
        $storedJob->execute([$pending['id']]);
        $stopped = $storedJob->fetch(PDO::FETCH_ASSOC);
        $storedJob->closeCursor();
        expect($stopped['status'] === 'failed' && $stopped['error_code'] === 'export_permission_revoked' && $stopped['support_id'] === $exportGrant['id']
            && !is_file($base . '/storage/exports/' . $pending['id'] . '.csv'), '分块间撤销不能因合法成员身份继续生成，且清理部分文件');
        $racingGrant = $create(array_replace($data, ['id' => bin2hex(random_bytes(16)), 'export' => true]));
        $racingJob = $export($racingGrant['id']);
        $database->beginTransaction();
        $database->prepare('UPDATE iot_support_grants SET revoked_at = ?, version = version + 1 WHERE id = ?')->execute([time(), $racingGrant['id']]);
        $worker = new Type\Testing\Process([...$command, 'iot:exports', '100'], dirname(__DIR__), $workerEnvironment);
        try {
            usleep(300000);
            expect($worker->running(), '并发撤销尚未提交时后台必须等待真实授权锁');
            $database->commit();
            $processed = $worker->wait(30);
            expect($processed->successful(), $processed->stderr);
        } finally {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            $worker->stop();
        }
        $storedJob->execute([$racingJob['id']]);
        expect($storedJob->fetch(PDO::FETCH_ASSOC)['error_code'] === 'export_permission_revoked', '等待授权锁之后必须读已提交撤销，不能使用旧事务快照');
        $storedJob->closeCursor();
        $expiringGrant = $create(array_replace($data, ['id' => bin2hex(random_bytes(16)), 'export' => true]));
        $expiringJob = $export($expiringGrant['id']);
        $database->prepare('UPDATE iot_support_grants SET expires_at = ? WHERE id = ?')->execute([time() - 1, $expiringGrant['id']]);
        identityCommand([...$command, 'iot:exports', '100'], $workerEnvironment);
        $storedJob->execute([$expiringJob['id']]);
        expect($storedJob->fetch(PDO::FETCH_ASSOC)['error_code'] === 'export_permission_revoked', '排队到期和撤销采用同样的当前权限重验');
        $storedJob->closeCursor();
    } finally {
        $redis->close();
    }
    $request('DELETE', $grants . '/' . $readonly['id'], $admin, $tenantA, ['version' => 2], 409, 'stale_version');
    $revoked = $revoke($readonly);
    expect($revoke($readonly) === $revoked && $revoked['status'] === 'revoked', '重复撤销保留同一个终态');
    $request('GET', $commandPath, $platform, $tenantA, null, 403, 'support_forbidden', $readonly['id']);
    $request('GET', $devicePath, $platform, $tenantA, null, 403, 'support_forbidden', $readonly['id']);
    $request('GET', $devicePath, $platform, $tenantA, null, 200);
    $expired = $create(array_replace($data, ['id' => bin2hex(random_bytes(16)), 'duration_seconds' => 1]));
    $deadline = microtime(true) + 3;
    while (time() < $expired['expires_at'] && microtime(true) < $deadline) {
        usleep(20000);
    }
    $request('GET', $devicePath, $platform, $tenantA, null, 403, 'support_forbidden', $expired['id']);
    $request('GET', $devicePath, $platform, $tenantA, null, 403, 'support_forbidden', $expired['id']);
    $expiryAudit = $request('GET', $path . '/audit?action=support.expired&subject_id=' . $expired['id'], $admin, $tenantA, null, 200);
    expect($expiryAudit['total'] === 1 && $expiryAudit['items'][0]['details']['expires_at'] === $expired['expires_at'], '首次观察到期只审计一次且保留原时间');
    expect($request('GET', $grants . '?status=expired', $admin, $tenantA, null, 200)['total'] === 2, '到期列表与执行采用同一期限');
    $database->prepare('UPDATE iot_users SET platform_admin = 0 WHERE login = ?')->execute(['platform']);
    $request('GET', $devicePath, $platform, $tenantA, null, 403, 'support_forbidden', $controlGrant['id']);
    $database->prepare('UPDATE iot_users SET platform_admin = 1 WHERE login = ?')->execute(['platform']);
    $request('GET', $devicePath, $platform, $tenantA, null, 200, null, $controlGrant['id']);
    $revoke($controlGrant);
    $request('POST', $queryPath, $platform, $tenantA, $query, 403, 'support_forbidden', $controlGrant['id']);
    $database->prepare('UPDATE iot_support_grants SET expires_at = ? WHERE id = ?')->execute([time() - 180 * 86400 - 2, $expired['id']]);
    $cleaned = identityCommand([...$command, 'iot:support-clean', '1'], $environment)['data'];
    expect($cleaned['deleted'] === 1 && !$cleaned['has_more'] && identityCommand([...$command, 'iot:support-clean', '1'], $environment)['data']['deleted'] === 0, '授权180天清理有界并可恢复重试');
    $audit = $request('GET', $path . '/audit?action=support.granted&subject_id=' . $readonly['id'], $admin, $tenantA, null, 200);
    expect($audit['total'] === 1, '重复授权只记录一次授予');
    $response = json_encode($request('GET', $path . '/audit?per_page=100', $admin, $tenantA, null, 200), JSON_THROW_ON_ERROR);
    foreach ([$platform, $registered['credential']['password'], 'secret_hash', 'password_hash'] as $secret) {
        expect(!str_contains($response, $secret), '支持审计不得泄漏令牌和设备凭据');
    }
    return ['revoked_id' => $readonly['id'], 'checks' => ['default-readonly-max-hour-idempotent-grant', 'explicit-member-support-no-union-no-fallback', 'independent-optional-scopes-real-device-query',
        'real-command-and-manual-query-accepted-source-frozen', 'real-threshold-alarm-acknowledged-then-revoked', 'real-redis-csv-worker-chunk-revoked-old-download-link',
        'real-db-worker-revocation-lock-race-expiry-no-stale-snapshot', 'current-platform-status-and-manual-query-revoked', 'old-token-revoke-expiry-once-audit-bounded-retention']];
}

/** 使用既有TLS Broker、同步主备与正式设备入口，给支持场景独立的一轮有界连接。 */
function iotSupportMqttScenario(array $fixture, array $command, array $environment, string $base, PDO $database, Type\Testing\Process $worker): array
{
    $registered = $fixture['registration'];
    $device = $registered['device'];
    $deviceEnvironment = array_replace($environment, [
        'IOT_DEVICE_CACHE' => 'support-device.sqlite', 'IOT_DEVICE_ID' => $device['id'], 'IOT_DEVICE_OWNERSHIP_ID' => $device['ownership_id'],
        'IOT_DEVICE_TENANT_ID' => $fixture['tenant'], 'IOT_DEVICE_MODEL_VERSION' => '1', 'IOT_DEVICE_CREDENTIAL_ID' => $registered['credential']['id'],
        'IOT_DEVICE_PASSWORD' => $registered['credential']['password'], 'IOT_DEVICE_HOST' => '127.0.0.1', 'IOT_DEVICE_PORT' => $environment['IOT_MQTT_PORT'],
        'IOT_DEVICE_CA' => 'certificate.pem', 'IOT_DEVICE_PEER_NAME' => '127.0.0.1', 'APP_DEBUG' => 'true', 'DB_HOST' => '192.0.2.1', 'DB_PORT' => '1',
    ]);
    $http = new Type\Testing\HttpClient('http://127.0.0.1:' . $environment['APP_PORT']);
    $headers = ['Authorization' => 'Bearer ' . $fixture['admin_token'], 'X-Tenant-Id' => $fixture['tenant']];
    $actor = $http->request('GET', '/iot/auth/me', $headers)->json()['data']['user']['id'];
    $started = microtime(true);
    $listener = new Type\Testing\Process([...$command, 'iot:device', 'listen'], dirname(__DIR__), $deviceEnvironment);
    try {
        $clock = $database->prepare("SELECT encode(m.payload, 'base64') FROM type_mqtt_messages m JOIN type_mqtt_deliveries d ON d.message_id = m.id WHERE m.topic = ? AND d.client_id = ? AND d.state = 'acknowledged' AND m.created_at >= to_timestamp(?)");
        $until = microtime(true) + 20;
        $ready = false;
        do {
            expect($listener->running(), '支持设备提前退出：' . $listener->stderr() . $listener->stdout());
            $clock->execute([$registered['credential']['topics']['subscribe'], $device['id'], $started]);
            foreach ($clock->fetchAll(PDO::FETCH_COLUMN) as $encoded) {
                $message = json_decode(base64_decode($encoded), true);
                if (($message['type'] ?? '') === 'time_response' && ($message['ownership_id'] ?? '') === $device['ownership_id']) {
                    $ready = true;
                }
            }
            if ($ready) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $until);
        expect($ready, '支持设备没有确认真实时间挑战：' . $listener->stderr());
        expect($http->request('GET', $fixture['path'], $headers)->json()['data']['connection']['status'] === 'online', '支持设备必须由实际Broker确认在线');
        // 历史未知指令从未投递，主动查询应得到真实无留存回包；没有安排自动调度或新的设备动作。
        $missingId = bin2hex(random_bytes(16));
        $issued = time() - 360;
        $payload = json_encode(['app_version' => 1, 'type' => 'command', 'command_id' => $missingId, 'device_id' => $device['id'],
            'ownership_id' => $device['ownership_id'], 'model_version' => 1, 'identifier' => 'switch', 'values' => (object) ['on' => true],
            'issued_at' => $issued, 'deadline_at' => $issued + 60], JSON_THROW_ON_ERROR);
        $insert = $database->prepare('INSERT INTO iot_commands (id, tenant_id, device_id, ownership_id, model_version, identifier, payload, content_hash, actor_id, accepted_at, deadline_at, schedule_stage, next_action_at) VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, 6, NULL)');
        $insert->execute([$missingId, $fixture['tenant'], $device['id'], $device['ownership_id'], 'switch', $payload, app\iot\service\IngestionService::contentHash($payload), $actor, $issued, $issued + 60]);
        $ledger = new PDO('sqlite:' . $base . '/support-device.sqlite');
        $result = iotSupportMqttChecks($fixture, $command, $environment, $http, $database, $worker, $listener, $ledger, $missingId);
        $ledger = null;
        $stopped = $listener->stop(15);
        expect($stopped->successful(), '支持设备未正常释放连接：' . $stopped->stderr);
        return $result + ['device_stopped' => true];
    } finally {
        $listener->stop(15);
    }
}

/** 借现有真实TLS设备和接收角色验证支持指令、主动查询及撤销后未领取动作不再发出。 */
function iotSupportMqttChecks(array $fixture, array $command, array $environment, Type\Testing\HttpClient $http, PDO $database, Type\Testing\Process $worker, Type\Testing\Process $listener, PDO $deviceLedger, string $missingId): array
{
    $password = bin2hex(random_bytes(16));
    identityCommand([...$command, 'iot:user', 'mqtt-support', '支持验收人员', 'platform'], $environment + ['IOT_USER_PASSWORD' => $password]);
    $login = $http->request('POST', '/iot/auth/login', ['Content-Type' => 'application/json'], json_encode(['login' => 'mqtt-support', 'password' => $password], JSON_THROW_ON_ERROR));
    expect($login->status === 200, 'MQTT支持人员登录失败');
    $token = $login->json()['data']['accessToken'];
    $path = '/iot/tenants/' . $fixture['tenant'];
    $adminHeaders = ['Authorization' => 'Bearer ' . $fixture['admin_token'], 'X-Tenant-Id' => $fixture['tenant'], 'Content-Type' => 'application/json'];
    $request = static function (string $method, string $uri, array $headers, ?array $data, int $status) use ($http): array {
        $response = $http->request($method, $uri, $headers, $data === null ? '' : json_encode($data, JSON_THROW_ON_ERROR));
        expect($response->status === $status, '支持MQTT接口不符：' . $response->body);
        return $response->json();
    };
    $grantData = ['id' => bin2hex(random_bytes(16)), 'login' => 'mqtt-support', 'duration_seconds' => 3600, 'reason' => '真实TLS控制验收'];
    $grant = $request('POST', $path . '/support-grants', $adminHeaders, $grantData, 200)['data'];
    $headers = ['Authorization' => 'Bearer ' . $token, 'X-Tenant-Id' => $fixture['tenant'], 'X-Support-Id' => $grant['id'], 'Content-Type' => 'application/json'];
    $payload = ['command_id' => bin2hex(random_bytes(16)), 'identifier' => 'switch', 'values' => ['on' => false]];
    $request('GET', $fixture['path'], $headers, null, 200);
    $request('POST', $fixture['path'] . '/commands', $headers, $payload, 403);
    $request('POST', $fixture['path'] . '/commands/' . $missingId . '/queries', $headers, ['query_id' => bin2hex(random_bytes(16))], 403);
    $grant = $request('POST', $path . '/support-grants', $adminHeaders, array_replace($grantData, ['id' => bin2hex(random_bytes(16)), 'control' => true]), 200)['data'];
    $headers['X-Support-Id'] = $grant['id'];
    $request('POST', $fixture['path'] . '/commands', $headers, $payload, 201);
    $result = $database->prepare('SELECT result_status, support_id FROM iot_commands WHERE id = ?');
    $until = microtime(true) + 20;
    do {
        $result->execute([$payload['command_id']]);
        $record = $result->fetch(PDO::FETCH_ASSOC);
        if ($record['result_status'] === 'succeeded') {
            break;
        }
        expect($listener->running(), '支持指令等待期间设备退出：' . $listener->stderr() . $listener->stdout());
        usleep(20000);
    } while (microtime(true) < $until);
    $commandEvidence = $request('GET', $fixture['path'] . '/commands/' . $payload['command_id'] . '/queries', $adminHeaders, null, 200);
    expect($record['result_status'] === 'succeeded' && $record['support_id'] === $grant['id'], '支持指令必须收到真实设备执行回执且冻结来源：' . json_encode($commandEvidence, JSON_THROW_ON_ERROR) . $listener->stderr());
    $query = ['query_id' => bin2hex(random_bytes(16))];
    $recentQuery = $database->prepare('SELECT MAX(scheduled_at) FROM iot_command_attempts WHERE command_id = ?');
    $recentQuery->execute([$missingId]);
    $queryAfter = (int) $recentQuery->fetchColumn() + 11;
    $waitUntil = microtime(true) + 12;
    while (time() < $queryAfter && microtime(true) < $waitUntil) {
        usleep(20000);
    }
    $request('POST', $fixture['path'] . '/commands/' . $missingId . '/queries', $headers, $query, 202);
    $attempt = $database->prepare('SELECT response_code, support_id FROM iot_command_attempts WHERE id = ?');
    // 查询有领取后的30秒交付期；观察另给领取与回包各5秒，不能在202后的20秒提前宣告失败。
    $until = microtime(true) + 40;
    do {
        $attempt->execute([$query['query_id']]);
        $observed = $attempt->fetch(PDO::FETCH_ASSOC);
        if ($observed['response_code'] === 'result_not_retained') {
            break;
        }
        expect($listener->running(), '支持主动查询等待期间设备退出：' . $listener->stderr() . $listener->stdout());
        usleep(20000);
    } while (microtime(true) < $until);
    $queryEvidence = $request('GET', $fixture['path'] . '/commands/' . $missingId . '/queries', $adminHeaders, null, 200);
    expect($observed['response_code'] === 'result_not_retained' && $observed['support_id'] === $grant['id'], '主动查询得到设备真实无留存回应，不能伪造未执行：' . json_encode($queryEvidence, JSON_THROW_ON_ERROR) . $listener->stderr());
    foreach (['command.query_claimed', 'command.query_transport', 'command.query_response'] as $action) {
        $audit = $request('GET', $path . '/audit?action=' . $action . '&subject_id=' . $missingId, $adminHeaders, null, 200);
        $matched = array_filter($audit['items'], static fn (array $item): bool => ($item['details']['attempt_id'] ?? null) === $query['query_id']);
        expect(count($matched) === 1, '主动查询每个实际阶段保留唯一审计');
        $details = array_values($matched)[0]['details'];
        expect($details['context'] === 'tenant-support' && $details['support_id'] === $grant['id'], '支持人员查询其他成员原指令时，每阶段审计仍标明本次支持来源');
    }
    expect(posix_kill($worker->pid(), SIGSTOP), '无法暂停本次接收角色');
    try {
        // 上一条主动查询已经观察到设备回包；暂停后等待原有一次持久领取自然完成，再生成待发动作。
        usleep(400000);
        $pending = ['command_id' => bin2hex(random_bytes(16)), 'identifier' => 'switch', 'values' => ['on' => true]];
        $request('POST', $fixture['path'] . '/commands', $headers, $pending, 201);
        $request('DELETE', $path . '/support-grants/' . $grant['id'], $adminHeaders, ['version' => 1], 200);
        $request('GET', $fixture['path'], $headers, null, 403);
    } finally {
        posix_kill($worker->pid(), SIGCONT);
    }
    $attempts = $database->prepare('SELECT state FROM iot_command_attempts WHERE command_id = ?');
    $until = microtime(true) + 15;
    do {
        $attempts->execute([$pending['command_id']]);
        $states = $attempts->fetchAll(PDO::FETCH_COLUMN);
        if ($states !== []) {
            break;
        }
        usleep(20000);
    } while (microtime(true) < $until);
    expect($states === ['support_permission_revoked'], '撤销先于领取时不能继续向真实MQTT发布');
    $onDevice = $deviceLedger->prepare('SELECT COUNT(*) FROM device_commands WHERE id = ?');
    $onDevice->execute([$pending['command_id']]);
    expect((int) $onDevice->fetchColumn() === 0, '撤销后的待发动作不能进入设备执行账本');
    return ['real_readonly_device_query_control_and_manual_query_denied' => true, 'real_support_tls_execution_receipt' => true, 'real_support_manual_query_response' => true,
        'manual_query_audit_uses_attempt_support_source' => true, 'revoked_before_claim_no_device_action' => true, 'old_login_refused' => true];
}
