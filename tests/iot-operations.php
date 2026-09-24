<?php

declare(strict_types=1);

use Type\Testing\HttpClient;
use Type\Testing\Process;

/** 复用身份和设备装置，通过正式HTTP边界验证各数据库的权限、分页和真实空数据。 */
function iotOperationsChecks(Closure $request, array $tokens, string $tenantA, string $tenantB, string $driver, PDO $database, array $fixture): array
{
    $request('GET', '/admin/operations', '', null, null, 401);
    $request('GET', '/admin/operations', $tokens['alice'], null, null, 401, 'unauthenticated');
    $platform = $request('GET', '/admin/operations', $tokens['platform'], null, null, 200);
    expect($platform['nodes'] === [] && $platform['store']['metrics'] === null, '没有采样时不能伪造节点或零积压');
    expect($platform['store']['state'] === ($driver === 'pgsql' ? 'unavailable' : 'unsupported'), '未安装持久后端的状态错误');
    expect($platform['health']['http_alive'] && $platform['health']['durable_store_ready'] === ($driver === 'pgsql' ? false : null), 'HTTP存活不能代替持久依赖就绪');
    $path = '/customer/tenants/' . $tenantA . '/operations';
    $request('GET', $path, $tokens['outsider'], $tenantA, null, 403, 'permission_denied');
    $request('GET', $path, $tokens['platform'], $tenantA, null, 401, 'unauthenticated');
    $request('GET', $path, $tokens['alice'], $tenantA, null, 403, 'permission_denied');
    $request('GET', $path, $tokens['bob'], $tenantB, null, 403, 'tenant_context_mismatch');
    $request('GET', $path . '?per_page=101', $tokens['bob'], $tenantA, null, 422);
    $request('GET', $path . '?fields=payload', $tokens['bob'], $tenantA, null, 422);
    $read = static fn (): array => $request('GET', $path, $tokens['bob'], $tenantA, null, 200);
    $first = $request('GET', $path . '?per_page=1', $tokens['bob'], $tenantA, null, 200);
    $second = $request('GET', $path . '?per_page=1&page=2', $tokens['bob'], $tenantA, null, 200);
    expect(count($first['devices']['items']) === 1 && $first['devices']['items'][0]['id'] !== $second['devices']['items'][0]['id'], '设备观察分页重复或越界');
    $other = $request('GET', '/customer/tenants/' . $tenantB . '/operations', $tokens['bob'], $tenantB, null, 200);
    expect($other['recorded'] === 0 && $other['devices']['total'] === 0, '其他租户的空概览混入当前租户数据');
    $empty = $request('GET', $path . '?name=does-not-exist', $tokens['bob'], $tenantA, null, 200);
    expect($empty['devices']['items'] === [] && $empty['devices']['total'] === 0, '设备名称空筛选无效');
    $device = $fixture['second']['device'];
    $beforeAudits = $database->query('SELECT COUNT(*) FROM customer_audit')->fetchColumn();
    // 只构造本次新设备的投影边界；实际可靠接收仍由原MQTT专项验收。
    $database->prepare('INSERT INTO iot_current_data (device_id, ownership_id, model_version, sequence, sampled_at, received_at, fields_json, realtime_sequence, realtime_sampled_at, realtime_received_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$device['id'], $device['ownership_id'], $device['model_version'], '1', time(), time(), '{"secret":"must-not-read-telemetry-payload"}', '1', time(), time()]);
    $pick = static function (array $page) use ($device): array {
        return array_values(array_filter($page['devices']['items'], static fn (array $row): bool => $row['id'] === $device['id']))[0];
    };
    try {
        expect($pick($read())['current']['freshness'] === 'fresh', '实时新鲜度丢失');
        $database->prepare('UPDATE iot_current_data SET realtime_received_at = ? WHERE device_id = ?')->execute([time() - 60, $device['id']]);
        expect($pick($read())['current']['freshness'] === 'stale', '60秒边界应为陈旧');
        $database->prepare('UPDATE iot_current_data SET ownership_id = ? WHERE device_id = ?')->execute([bin2hex(random_bytes(16)), $device['id']]);
        expect($pick($read())['current']['freshness'] === 'empty', '旧归属遥测不得进入当前概览');
        $database->prepare('UPDATE iot_current_data SET ownership_id = ?, model_version = 2 WHERE device_id = ?')->execute([$device['ownership_id'], $device['id']]);
        expect($pick($read())['current']['freshness'] === 'empty', '其他模型遥测不得进入当前概览');
        $json = json_encode($read(), JSON_THROW_ON_ERROR);
        foreach (['must-not-read-telemetry-payload', 'fields_json', 'password', 'secret_hash', 'run_id', 'model_start_sequence'] as $forbidden) {
            expect(!str_contains($json, $forbidden), '运行元数据泄漏秘密、载荷或内部基础设施字段');
        }
        expect($database->query('SELECT COUNT(*) FROM customer_audit')->fetchColumn() === $beforeAudits, '无副作用轮询制造审计噪音');
    } finally {
        $database->prepare('DELETE FROM iot_current_data WHERE device_id = ?')->execute([$device['id']]);
    }
    $request('GET', '/iot/operations', $tokens['platform'], null, null, 404);
    return ['checks' => ['fixed-node-isolation', 'http-live-distinct-from-durable-ready', 'unknown-without-samples', 'bounded-device-pages', 'tenant-empty-filter',
        'fresh-stale-ownership-model-boundaries', 'metadata-without-payload', 'polling-without-audit-writes', 'old-route-removed'], 'driver' => $driver];
}

/**
 * 读取真实平台运行概览，检查身份脱敏、节点数量及响应字节预算。
 *
 * @param array<string, string> $environment
 * @param array<string, mixed> $fixture
 * @return array<string, mixed>
 */
function iotOperationsPlatform(array $environment, array $fixture): array
{
    $client = new HttpClient('http://127.0.0.1:' . $environment['APP_PORT'], 12.0);
    $response = $client->request('GET', '/admin/operations', ['Authorization' => 'Bearer ' . $fixture['platform_token']]);
    expect($response->status === 200, '运行概览HTTP失败：' . $response->body);
    $value = $response->json();
    $encoded = json_encode($value, JSON_THROW_ON_ERROR);
    foreach ([$fixture['token'], $fixture['platform_token'], $fixture['second']['credential']['password'], $environment['DB_PASSWORD']] as $secret) {
        expect(!str_contains($encoded, $secret), '平台采样泄漏身份或数据库秘密');
    }
    expect(count($value['nodes']) <= 64 && strlen($encoded) <= 400000, '运行采样突破固定资源边界');
    return $value;
}

/**
 * 在秒数预算内轮询运行观测，接受谓词成立后返回，超时附最后一次观测。
 *
 * @param Closure(): array<string, mixed> $read
 * @param Closure(array<string, mixed>): bool $accept
 * @return array<string, mixed>
 */
function iotOperationsWait(Closure $read, Closure $accept, string $failure, float $seconds = 15.0): array
{
    $until = microtime(true) + $seconds;
    do {
        $value = $read();
        if ($accept($value)) {
            return $value;
        }
        usleep(200000);
    } while (microtime(true) < $until);
    throw new RuntimeException($failure . '：' . json_encode($value, JSON_THROW_ON_ERROR));
}

/** 仅在本轮独占主备上阻止统计角色重新登录，以真实清理失败验证HTTP隔离须经确认并重启。 */
function iotOperationsQuarantine(array $command, array $environment, array $fixture, PDO $database): array
{
    $root = dirname(__DIR__);
    $role = 'iot_ops_' . bin2hex(random_bytes(8));
    $server = null;
    $request = null;
    $locked = false;
    $created = false;
    $serverExits = [];
    $evidence = [];
    try {
        // 不改变装置的管理角色；只有本轮随机角色可创建统计连接，密码仅通过环境继承。
        $database->exec('CREATE ROLE ' . $role . ' LOGIN SUPERUSER PASSWORD ' . $database->quote($environment['DB_PASSWORD']));
        $created = true;
        $listener = stream_socket_server('tcp://127.0.0.1:0', $number, $error);
        expect(is_resource($listener), '无法选择隔离概览HTTP端口');
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $testEnvironment = array_replace($environment, ['APP_PORT' => substr(strrchr($address, ':'), 1), 'APP_ALLOWED_HOSTS' => $address,
            'IOT_MQTT_COMMAND' => json_encode(['env', 'DB_USERNAME=' . $role, ...$command], JSON_THROW_ON_ERROR)]);
        $start = static function () use ($command, $testEnvironment, $root): Process {
            $process = new Process([...$command, 'serve'], $root, $testEnvironment);
            try {
                iotOperationsWait(static function () use ($process, $testEnvironment): array {
                    expect($process->running(), '隔离概览HTTP提前退出：' . $process->stderr());
                    try {
                        return ['ready' => (new HttpClient('http://127.0.0.1:' . $testEnvironment['APP_PORT']))->request('GET', '/readyz')->status === 200];
                    } catch (RuntimeException) {
                        return ['ready' => false];
                    }
                }, static fn (array $state): bool => $state['ready'], '隔离概览HTTP未就绪', 10);
                return $process;
            } catch (Throwable $failure) {
                $process->stop();
                throw $failure;
            }
        };
        $server = $start();
        $evidence['initial'] = iotOperationsPlatform($testEnvironment, $fixture)['store'];
        expect($evidence['initial']['state'] === 'available' && !$evidence['initial']['quarantined'], '独立统计角色未取得真实同步证明');
        $database->query('SELECT pg_advisory_lock(1954115693, 1)');
        $locked = true;
        $probe = <<<'PHP'
require $argv[1] . '/vendor/autoload.php';
$response = (new Type\Testing\HttpClient('http://127.0.0.1:' . getenv('APP_PORT'), 12.0))->request('GET', '/admin/operations', ['Authorization' => 'Bearer ' . getenv('TYPE_OPERATIONS_TOKEN')]);
if ($response->status !== 200) { throw new RuntimeException('operations request failed'); }
echo $response->body;
PHP;
        $request = new Process([PHP_BINARY, '-r', $probe, $root], $root, $testEnvironment + ['TYPE_OPERATIONS_TOKEN' => $fixture['platform_token']]);
        $backendQuery = $database->prepare("SELECT pid, application_name, wait_event FROM pg_stat_activity WHERE datname = current_database() AND usename = ? AND application_name ~ '^type_mqtt_[a-f0-9]{32}$' AND wait_event = 'advisory'");
        $waiting = iotOperationsWait(static function () use ($backendQuery, $role): array {
            $backendQuery->execute([$role]);
            return $backendQuery->fetchAll(PDO::FETCH_ASSOC);
        }, static fn (array $rows): bool => count($rows) === 1, '未观察到本轮统计工作等待真实事务锁', 3);
        $evidence['blocked_backend'] = $waiting[0];
        $database->exec('ALTER ROLE ' . $role . ' NOLOGIN');
        // 单纯锁超时会正常回滚并released=true；须让原工作进程实际丢失结果，才会启动精确清理。
        $workers = array_keys(unixProcessStates($server->pid()));
        expect(count($workers) === 1, '不能精确定位本轮HTTP拥有的统计子进程');
        $workerPid = $workers[0];
        expect(posix_kill($workerPid, SIGKILL), '不能终止本轮统计子进程');
        $evidence['worker_fault'] = ['signal' => 'SIGKILL', 'pid' => $workerPid, 'owner_pid' => $server->pid(), 'login_disabled' => true];
        $result = $request->wait(13);
        expect($result->successful(), '清理失败场景HTTP未在预算内返回：' . $result->stderr);
        $unknown = json_decode($result->stdout, true, 32, JSON_THROW_ON_ERROR);
        $evidence['unconfirmed'] = $unknown['store'];
        expect($unknown['store']['state'] === 'unavailable' && $unknown['store']['metrics'] === null && $unknown['store']['nodes'] === null
            && $unknown['store']['quarantined'], '未证明清理时概览没有保留未知与隔离');
        $database->exec('ALTER ROLE ' . $role . ' LOGIN');
        $terminate = $database->prepare('SELECT pg_terminate_backend(pid, 500) FROM pg_stat_activity WHERE datname = current_database() AND usename = ? AND pid = ? AND application_name = ?');
        $terminate->execute([$role, $waiting[0]['pid'], $waiting[0]['application_name']]);
        $remaining = $database->prepare('SELECT COUNT(*) FROM pg_stat_activity WHERE datname = current_database() AND usename = ?');
        iotOperationsWait(static function () use ($remaining, $role): array {
            $remaining->execute([$role]);
            return ['count' => (int) $remaining->fetchColumn()];
        }, static fn (array $state): bool => $state['count'] === 0, '人工精确清理后统计后端仍存在', 3);
        $database->query('SELECT pg_advisory_unlock(1954115693, 1)');
        $locked = false;
        $evidence['manual_cleanup_confirmed'] = true;
        $statistics = identityCommand([...$command, 'iot:mqtt-statistics'], $testEnvironment);
        expect(isset($statistics['pendingMessages']), '恢复登录和清理后独立正式统计入口仍不可用');
        $evidence['independent_statistics'] = $statistics;
        for ($refresh = 0; $refresh < 2; $refresh++) {
            $retained = iotOperationsPlatform($testEnvironment, $fixture)['store'];
            expect($retained['state'] === 'unavailable' && $retained['metrics'] === null && $retained['quarantined'], '同一HTTP进程自动解除未确认清理隔离');
            $evidence['retained'][] = $retained;
        }
        $firstStop = $server->stop(15);
        expect($firstStop->successful(), '隔离HTTP未正常退出');
        $serverExits[] = $firstStop->exitCode;
        $server = $start();
        $evidence['restarted'] = iotOperationsPlatform($testEnvironment, $fixture)['store'];
        expect($evidence['restarted']['state'] === 'available' && !$evidence['restarted']['quarantined'], '确认清理并重启后统计未恢复');
    } finally {
        $request?->stop();
        if ($server !== null) {
            $stopped = $server->stop(15);
            $serverExits[] = $stopped->exitCode;
        }
        if ($created) {
            $database->exec('ALTER ROLE ' . $role . ' LOGIN');
            $cleanup = $database->prepare('SELECT pg_terminate_backend(pid, 500) FROM pg_stat_activity WHERE datname = current_database() AND usename = ?');
            $cleanup->execute([$role]);
        }
        if ($locked) {
            $database->query('SELECT pg_advisory_unlock(1954115693, 1)');
        }
        if ($created) {
            $database->exec('DROP ROLE ' . $role);
        }
        $evidence['server_exits'] = $serverExits;
        $evidence['temporary_role_removed'] = $created;
        file_put_contents($environment['APP_BASE_PATH'] . '/operations-quarantine.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
    expect($serverExits === [0, 0], '隔离HTTP重启或最终退出失败');
    $evidence['server_exits'] = $serverExits;
    $evidence['temporary_role_removed'] = true;
    return $evidence;
}

/** 实际TLS业务回执、进程故障和恢复均复用原验收装置，新增断言只观察正式概览接口。 */
function iotOperationsMqttChecks(
    array $fixture,
    array $command,
    array $environment,
    array $workerEnvironment,
    array $clientEnvironment,
    string $base,
    PDO $database,
    Process $broker,
    Process $worker,
    #[SensitiveParameter] string $password,
    Closure $restartBroker
): array {
    $root = dirname(__DIR__);
    $initial = iotOperationsWait(
        static fn (): array => iotOperationsPlatform($environment, $fixture),
        static fn (array $view): bool => count($view['nodes']) === 2 && $view['store']['state'] === 'available',
        '运行角色没有生成真实采样'
    );
    $client = new Process(['node', $root . '/tests/iot-device-client.mjs', 'ingestion'], $root, $clientEnvironment + ['TYPE_INGESTION_PASSWORD' => $password]);
    try {
        $traffic = $client->wait(180);
        expect($traffic->successful(), '运行概览流量装置失败：' . $traffic->stderr . $worker->stderr() . $broker->stderr());
    } finally {
        $client->stop();
    }
    $received = iotOperationsWait(static fn (): array => iotOperationsPlatform($environment, $fixture), static function (array $view): bool {
        foreach ($view['nodes'] as $node) {
            if ($node['kind'] === 'ingestion' && $node['state'] === 'reporting' && $node['metrics']['receiptCount'] > 0
                && $node['received_per_second'] > 0 && $node['receipt_latency_mean_ms'] > 0 && $node['receipt_samples'] > 0) {
                return true;
            }
        }
        return false;
    }, '实际业务交付没有产生接收速率和回执处理延迟');
    $http = new HttpClient('http://127.0.0.1:' . $environment['APP_PORT'], 12.0);
    $headers = ['Authorization' => 'Bearer ' . $fixture['admin_token'], 'X-Tenant-Id' => $fixture['tenant']];
    $tenant = $http->request('GET', '/customer/tenants/' . $fixture['tenant'] . '/operations', $headers)->json();
    expect($tenant['recorded'] > 0 && $tenant['rejected'] > 0 && !isset($tenant['nodes']), '实际租户接收或拒绝记录丢失或混入全局指标');
    $browser = null;
    if (in_array('--operations-browser', $GLOBALS['argv'], true)) {
        $longName = str_repeat('长名称', 25) . '<script>文本</script>';
        $registered = $http->request(
            'POST',
            '/customer/tenants/' . $fixture['tenant'] . '/devices',
            ['Authorization' => 'Bearer ' . $fixture['admin_token'], 'X-Tenant-Id' => $fixture['tenant'], 'Content-Type' => 'application/json'],
            json_encode(['name' => $longName, 'product_id' => $fixture['second']['device']['product_id'], 'model_version' => 1], JSON_THROW_ON_ERROR)
        );
        expect($registered->status === 201, '长名称设备必须经正式注册入口创建');
        $dist = realpath((string) getenv('TYPE_OPERATIONS_DIST'));
        expect(is_string($dist) && is_file($dist . '/index.html'), '概览浏览器需要本轮独立生产前端');
        $call = static function (string $method, string $path, string $token, array $body, array $extra = []) use ($http): array {
            $response = $http->request($method, $path, $extra + ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'], json_encode($body === [] ? (object) [] : $body, JSON_THROW_ON_ERROR));
            expect($response->status === 200, '观察浏览器身份准备失败：' . $response->body);
            return $response->json()['data'];
        };
        $deniedPassword = bin2hex(random_bytes(16));
        $call('POST', '/admin/users', $fixture['platform_token'], ['login' => 'observation-denied', 'name' => '无观察权限', 'password' => $deniedPassword]);
        $denied = $call('POST', '/admin/auth/login', '', ['login' => 'observation-denied', 'password' => $deniedPassword]);
        $auditDevice = $registered->json()['data']['device'];
        $call(
            'PATCH',
            '/customer/tenants/' . $fixture['tenant'] . '/devices/' . $auditDevice['id'],
            $fixture['simulated']['accessToken'],
            ['name' => $longName, 'version' => $auditDevice['version']],
            ['X-Tenant-Id' => $fixture['tenant']]
        );
        $browserProcess = null;
        $fixturePath = $base . '/operations-browser-fixture.json';
        try {
            file_put_contents($fixturePath, json_encode(['base' => $base, 'token' => $fixture['admin_token'], 'platform_token' => $fixture['platform_token'],
                'denied_token' => $denied['accessToken'], 'simulated_token' => $fixture['simulated']['accessToken'],
                'audit_subject' => $auditDevice['id'], 'identity' => $fixture['simulated']['identity'],
                'tenant' => $fixture['tenant'], 'device' => $fixture['second']['device']['id'], 'long_name' => $longName], JSON_THROW_ON_ERROR));
            chmod($fixturePath, 0600);
            $browserProcess = new Process(['node', $root . '/tests/iot-operations-browser.mjs', $fixturePath, 'http://127.0.0.1:' . $environment['APP_PORT'], $dist], $root, getenv());
            $result = $browserProcess->wait(210);
            expect($result->successful(), '运行概览浏览器失败：' . $result->stderr . $result->stdout);
            $browser = json_decode(trim($result->stdout), true, 32, JSON_THROW_ON_ERROR);
        } finally {
            $browserProcess?->stop();
            if (is_file($fixturePath)) {
                unlink($fixturePath);
            }
        }
    }
    $stopped = $worker->stop(15);
    expect($stopped->successful(), '概览接收角色未正常清理：' . $stopped->stderr);
    $statistics = json_decode(trim($stopped->stdout), true, 16, JSON_THROW_ON_ERROR);
    expect(!$statistics['pending'] && !$statistics['quarantined'] && !$statistics['running'], '接收退出资源未归还');
    $fault = iotIngestionUnknownCommit($fixture, $command, $workerEnvironment, $clientEnvironment, $base, $database, $broker, $restartBroker);
    return ['scope' => 'operations-real-traffic-fault-recovery', 'initial' => $initial, 'received' => $received, 'tenant_records' => $tenant['recorded'],
        'tenant_rejected' => $tenant['rejected'], 'ingestion' => $statistics, 'fault' => $fault, 'browser' => $browser,
        'latency_scope' => 'application_receive_to_receipt_publish_ack', 'e1' => 'not-verified'];
}
