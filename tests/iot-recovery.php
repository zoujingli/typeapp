<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/native-database.php';
require_once __DIR__ . '/postgres-sync.php';

use Type\Testing\Process;

/** 本轮维护进程只有显式命令和有界输出；失败响应必须确实退出且不冒充成功JSON。 */
function iotRecoveryRun(array $command, array $environment, bool $success = true, int $seconds = 30, string $errorCode = ''): array
{
    $process = new Process($command, dirname(__DIR__), $environment, 1048576);
    try {
        $result = $process->wait($seconds);
        expect(!$result->timedOut && ($success ? $result->successful() : $result->exitCode === 1), '恢复命令结果不符：' . $result->stderr);
        if ($errorCode !== '') {
            expect(!$success && trim($result->stderr) === 'TypeApp 物联中心启动或命令失败：' . $errorCode, '恢复失败没有返回准确稳定码：' . $result->stderr);
        }
        return $success ? json_decode($result->stdout, true, 32, JSON_THROW_ON_ERROR) : ['exit' => $result->exitCode, 'error' => trim($result->stderr)];
    } finally {
        $process->stop();
    }
}

/** 公开维护CLI观察不可变归档、恢复、冲突/损坏/路径失败和真实并发。 */
function iotRecoveryWal(array $command, array $environment, string $base): array
{
    $repository = $base . '/wal archive';
    expect(mkdir($repository, 0700), '不能创建私有WAL测试目录');
    $name = '000000010000000000000001';
    $source = $base . '/wal source';
    $bytes = random_bytes(524288);
    expect(file_put_contents($source, $bytes) === strlen($bytes), '不能写入本轮WAL输入');
    $expected = ['name' => $name, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    $archive = [...$command, 'iot:wal', 'archive', $repository, $source, $name];
    expect(iotRecoveryRun($archive, $environment) === $expected, '归档没有确认完整输入');
    expect(iotRecoveryRun($archive, $environment) === $expected, '相同WAL重试未幂等');
    expect(iotRecoveryRun([...$command, 'iot:wal', 'verify', $repository, $name], $environment) === ['bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)], '归档摘要复核失败');
    expect((fileperms($repository . '/' . $name) & 0077) === 0 && (fileperms($repository . '/' . $name . '.sha256') & 0077) === 0, '归档原件或摘要暴露给其他用户');
    $target = $base . '/restored wal';
    expect(iotRecoveryRun([...$command, 'iot:wal', 'restore', $repository, $name, $target], $environment) === $expected
        && file_get_contents($target) === $bytes, '恢复没有保留完整二进制字节');
    iotRecoveryRun([...$command, 'iot:wal', 'restore', $repository, $name, $target], $environment, false, 30, 'recovery_restore_target_exists');
    expect(file_get_contents($target) === $bytes, '已存在恢复目标被覆盖');
    $different = $base . '/conflict';
    file_put_contents($different, str_repeat('x', strlen($bytes)));
    iotRecoveryRun([...$command, 'iot:wal', 'archive', $repository, $different, $name], $environment, false, 30, 'recovery_archive_content_conflict');
    expect(file_get_contents($repository . '/' . $name) === $bytes, '同名不同WAL冲突覆盖原件');
    iotRecoveryRun([...$command, 'iot:wal', 'archive', $repository, $source, '../escape'], $environment, false, 30, 'recovery_archive_name_invalid');
    iotRecoveryRun([...$command, 'iot:wal', 'restore', $repository, '000000010000000000000002', $base . '/missing'], $environment, false, 30, 'recovery_archive_regular_file_required');
    expect(!file_exists($base . '/missing'), '缺失归档仍创建恢复目标');
    $link = $base . '/source-link';
    expect(symlink($source, $link), '不能创建本轮符号链接反例');
    iotRecoveryRun([...$command, 'iot:wal', 'archive', $repository, $link, '000000010000000000000003'], $environment, false, 30, 'recovery_archive_regular_file_required');
    chmod($repository, 0755);
    iotRecoveryRun($archive, $environment, false, 30, 'recovery_archive_directory_invalid');
    chmod($repository, 0700);
    $parallelName = '000000010000000000000004';
    $parallelCommand = [...$command, 'iot:wal', 'archive', $repository, $source, $parallelName];
    $first = new Process($parallelCommand, dirname(__DIR__), $environment, 1048576);
    $second = new Process($parallelCommand, dirname(__DIR__), $environment, 1048576);
    try {
        $firstResult = $first->wait(30);
        $secondResult = $second->wait(30);
        expect($firstResult->successful() && $secondResult->successful(), '并发相同WAL没有一致完成');
        expect(json_decode($firstResult->stdout, true, 16, JSON_THROW_ON_ERROR) === json_decode($secondResult->stdout, true, 16, JSON_THROW_ON_ERROR), '并发归档返回不同身份');
    } finally {
        $first->stop();
        $second->stop();
    }
    $lock = fopen($repository . '/.archive.lock', 'r+b');
    expect(is_resource($lock) && flock($lock, LOCK_EX | LOCK_NB), '不能建立归档锁争用');
    try {
        $started = hrtime(true);
        iotRecoveryRun($archive, $environment, false, 12, 'recovery_archive_lock_timeout');
        expect((hrtime(true) - $started) / 1e9 < 10, '归档锁等待无界');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    // 等长损坏不得仅因文件存在/长度相同而进入PostgreSQL恢复目标。
    file_put_contents($repository . '/' . $name, str_repeat('y', strlen($bytes)));
    iotRecoveryRun([...$command, 'iot:wal', 'restore', $repository, $name, $base . '/corrupt-target'], $environment, false, 30, 'recovery_archive_corrupt');
    expect(!file_exists($base . '/corrupt-target'), '损坏归档仍生成恢复目标');
    $temporaries = glob($repository . '/.wal-*');
    $checksums = glob($repository . '/.checksum-*');
    expect($temporaries === [] && $checksums === [], '维护失败遗留本轮临时文件');
    return ['cases' => 15, 'binary_bytes' => strlen($bytes), 'sha256' => $expected['sha256'], 'same_archive_concurrent' => true,
        'conflicting_archive_preserved' => true, 'corrupt_restore_refused' => true, 'existing_target_preserved' => true,
        'temporary_files' => 0, 'owned_processes_stopped' => true, 'capacity_claim' => false];
}

/** PostgreSQL只替换独立的占位参数；常量中的百分号和shell元字符不能改变维护命令。 */
function iotRecoveryHook(array $command, array $environment, array $arguments): string
{
    $prefix = ['/usr/bin/env'];
    foreach (['PATH', 'PHPRC', 'PHP_INI_SCAN_DIR', 'APP_BASE_PATH', 'DB_DRIVER', 'APP_DEBUG'] as $key) {
        if (isset($environment[$key])) {
            $value = $environment[$key];
            if (in_array($key, ['PHPRC', 'PHP_INI_SCAN_DIR'], true) && $value !== '') {
                $value = realpath($value);
                expect($value !== false, '恢复维护运行配置路径不存在');
            }
            $prefix[] = $key . '=' . $value;
        }
    }
    return implode(' ', array_map(static fn (string $value): string => escapeshellarg(str_replace('%', '%%', $value)), [...$prefix, ...$command]))
        . ' ' . implode(' ', $arguments);
}

/** 小型真实业务数据的逐表内容身份；排序仅用于测试核对，不作为生产大库备份方法。 */
function iotRecoveryFacts(PDO $connection): array
{
    $facts = [];
    $tables = $connection->query("SELECT tablename FROM pg_tables WHERE schemaname='public' AND (tablename LIKE 'iot_%' OR tablename LIKE 'type_mqtt_%' OR tablename='recovery_probe') ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        expect(preg_match('/^[a-z][a-z0-9_]+$/D', $table) === 1, '恢复测试表名无效');
        $rows = $connection->query('SELECT row_to_json(t)::text FROM "' . $table . '" t ORDER BY row_to_json(t)::text')->fetchAll(PDO::FETCH_COLUMN);
        $facts[$table] = ['rows' => count($rows), 'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
    }
    return $facts;
}

/** 复用管理HTTP、TLS标准客户端和既有消费者建立非空恢复事实；退出全部业务角色后才允许备份取点。 */
function iotRecoveryPhysicalSeed(array $command, array $environment, string $directory, PDO $database, array &$report, array &$context, string $phase = 'seed'): void
{
    require_once __DIR__ . '/native-rollout-redis.php';
    $root = dirname(__DIR__);
    if ($phase !== 'seed') {
        $environment = array_replace($environment, $context['environment'], ['DB_PORT' => $environment['TYPE_PGSQL_PORT']]);
        $servicePassword = $context['service_password'];
    } else {
        foreach (array_keys($environment) as $name) {
            if (str_starts_with($name, 'APP_') || str_starts_with($name, 'DB_') || str_starts_with($name, 'IOT_')) {
                unset($environment[$name]);
            }
        }
        $environment['APP_BASE_PATH'] = $directory;
        $environment['DB_DRIVER'] = 'pgsql';
        foreach (['HOST' => 'HOST', 'PORT' => 'PORT', 'DATABASE' => 'DATABASE', 'USERNAME' => 'USER', 'PASSWORD' => 'PASSWORD'] as $destination => $source) {
            $environment['DB_' . $destination] = $environment['TYPE_PGSQL_' . $source];
        }
        $environment['APP_CACHE_ENABLED'] = 'false';
        $environment['APP_API_TOKEN'] = bin2hex(random_bytes(32));
        $environment['APP_DEBUG'] = 'false';
        // 生产原生应用由已编译的 Swoole 线程入口承载 HTTP；物理恢复验证也必须走同一入口。
        $environment['IOT_MQTT_COMMAND'] = json_encode($command, JSON_THROW_ON_ERROR);
        $environment['IOT_MQTT_NODE_ID'] = 'recovery-seed';
        $environment['IOT_MQTT_MAXIMUM_CONNECTIONS'] = '32';
        $environment['IOT_MQTT_MAXIMUM_DEVICE_CONNECTIONS'] = '28';
        $environment['IOT_MQTT_MAXIMUM_SERVICE_CONNECTIONS'] = '4';
        $environment['IOT_MQTT_CERTIFICATE'] = 'certificate.pem';
        $environment['IOT_MQTT_PRIVATE_KEY'] = 'private.pem';
        $servicePassword = bin2hex(random_bytes(32));
        $environment['IOT_INGESTION_CREDENTIAL_ID'] = bin2hex(random_bytes(16));
        $environment['IOT_INGESTION_SECRET_HASH'] = hash('sha256', $servicePassword);
        foreach (['APP_PORT', 'IOT_MQTT_PORT'] as $name) {
            $listener = stream_socket_server('tcp://127.0.0.1:0', $number, $error);
            expect(is_resource($listener), '不能分配恢复事实验证端口');
            $environment[$name] = substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
            fclose($listener);
        }
        $environment['APP_ALLOWED_HOSTS'] = '127.0.0.1:' . $environment['APP_PORT'];
        $certificateConfiguration = $directory . '/certificate.cnf';
        file_put_contents($certificateConfiguration, "[req]\ndistinguished_name=dn\nx509_extensions=server\n[dn]\n[server]\nsubjectAltName=IP:127.0.0.1\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\n");
        $options = ['config' => $certificateConfiguration, 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
        $key = openssl_pkey_new($options);
        $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key, $options);
        $certificate = openssl_csr_sign($csr, null, $key, 1, $options);
        expect(openssl_x509_export($certificate, $certificatePem) && openssl_pkey_export($key, $privatePem), '恢复测试TLS证书生成失败');
        file_put_contents($directory . '/certificate.pem', $certificatePem);
        file_put_contents($directory . '/private.pem', $privatePem);
        expect(chmod($directory . '/private.pem', 0600), '恢复测试TLS私钥未限制权限');
    }
    $http = new Type\Testing\HttpClient('http://127.0.0.1:' . $environment['APP_PORT']);
    $request = static function (string $method, string $path, string $token, string $tenant, ?array $data, int $status = 200, string $support = '') use ($http, &$report): array {
        $headers = ['Content-Type' => 'application/json'];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        if ($tenant !== '') {
            $headers['X-Tenant-Id'] = $tenant;
        }
        if ($support !== '') {
            $headers['X-Support-Id'] = $support;
        }
        $response = $http->request($method, $path, $headers, $data === null ? '' : json_encode($data === [] ? (object) [] : $data, JSON_THROW_ON_ERROR));
        expect($response->status === $status, '恢复事实管理入口失败：' . $method . ' ' . $path . ' ' . $response->status . ' ' . $response->body);
        $report['http_checks'] = ($report['http_checks'] ?? 0) + 1;
        return $response->json();
    };
    $server = null;
    $broker = null;
    $worker = null;
    $redis = null;
    $report['status'] = 'preparing';
    try {
        $server = new Process([...$command, 'serve'], $root, $environment);
        $ready = false;
        $deadline = microtime(true) + 15;
        do {
            expect($server->running(), '恢复事实HTTP提前退出：' . $server->stderr());
            try {
                $ready = $http->request('GET', '/readyz')->status === 200;
            } catch (RuntimeException) {
            }
            if (!$ready) {
                usleep(20000);
            }
        } while (!$ready && microtime(true) < $deadline);
        expect($ready, '恢复事实HTTP未就绪');
        if ($phase === 'reopen') {
            foreach ($context['old_sessions'] as $oldSession) {
                $request('GET', '/' . $oldSession['realm'] . '/auth/me', $oldSession['token'], '', null, 401);
            }
            $report['old_sessions_refused'] = true;
        }
        $tokens = [];
        $accounts = [];
        $login = static function (string $realm, string $account) use ($request): array {
            return $request('POST', '/' . $realm . '/auth/login', '', '', ['login' => $account, 'password' => 'Test-ingestion-password'])['data'];
        };
        foreach (['admin' => ['platform'], 'customer' => ['owner', 'outsider']] as $realm => $logins) {
            foreach ($logins as $account) {
                $accounts[$account] = $login($realm, $account);
                $tokens[$account] = $accounts[$account]['accessToken'];
            }
        }
        if ($phase === 'seed') {
            $tenant = bin2hex(random_bytes(16));
            $tenant = $request('POST', '/admin/tenants', $tokens['platform'], '', ['id' => $tenant, 'name' => '恢复非空事实租户', 'owner_login' => 'owner', 'new_customer' => false], 200)['data']['id'];
            $path = '/customer/tenants/' . $tenant;
            $token = $tokens['owner'];
            $product = $request('POST', $path . '/products', $token, $tenant, ['name' => '恢复温度产品'], 201)['data'];
            $models = $path . '/products/' . $product['id'] . '/models';
            $request('POST', $models, $token, $tenant, ['definition' => ['properties' => [
                ['identifier' => 'temperature', 'name' => '温度', 'type' => 'number', 'required' => true, 'unit' => '°C'],
            ], 'events' => [], 'commands' => [['identifier' => 'switch', 'name' => '切换开关', 'parameters' => [
                ['identifier' => 'on', 'name' => '开关状态', 'type' => 'boolean', 'required' => true],
            ]]]]], 201);
            $request('POST', $models . '/1/publish', $token, $tenant, ['version' => 1]);
            $registered = $request('POST', $path . '/devices', $token, $tenant, ['name' => '恢复温度设备', 'product_id' => $product['id'], 'model_version' => 1], 201)['data'];
            $device = $registered['device'];
            $request('POST', $path . '/alarm-rules', $token, $tenant, ['name' => '恢复温度阈值', 'device_id' => $device['id'], 'field' => 'temperature', 'lower' => 0, 'upper' => 10, 'hysteresis' => 2], 201);
            $context = ['environment' => $environment, 'service_password' => $servicePassword, 'tokens' => $tokens,
                'tenant' => $tenant, 'registration' => $registered, 'devices' => []];
            foreach (['stable' => '恢复后合法控制设备', 'revoked' => '备份后吊销设备', 'transferred' => '备份后真实转移设备'] as $kind => $name) {
                $context['devices'][$kind] = $request(
                    'POST',
                    $path . '/devices',
                    $token,
                    $tenant,
                    ['name' => $name, 'product_id' => $product['id'], 'model_version' => 1],
                    201
                )['data'];
            }
            $target = bin2hex(random_bytes(16));
            $context['target'] = $request('POST', '/admin/tenants', $tokens['platform'], '', ['id' => $target, 'name' => '恢复核对目标租户', 'owner_login' => 'outsider', 'new_customer' => false], 200)['data']['id'];
            $context['impersonation'] = $request('POST', '/admin/customers/' . $accounts['owner']['user']['id'] . '/impersonate', $tokens['platform'], '', ['version' => $accounts['owner']['user']['version']], 200)['data'];
            $request('GET', '/customer/auth/me', $context['impersonation']['accessToken'], $tenant, null, 200);
            $context['old_sessions'] = [
                ['realm' => 'admin', 'token' => $tokens['platform']],
                ['realm' => 'customer', 'token' => $tokens['owner']],
                ['realm' => 'customer', 'token' => $tokens['outsider']],
                ['realm' => 'customer', 'token' => $context['impersonation']['accessToken']],
            ];
            nativeDatabaseCommand([...$command, 'iot:mqtt-install'], $environment, [$environment['DB_PASSWORD']], $directory . '/mqtt-install.log');
        } else {
            $tenant = $context['tenant'];
            $path = '/customer/tenants/' . $tenant;
            $token = $tokens['owner'];
            $registered = $context['registration'];
            $device = $registered['device'];
        }
        $broker = new Process([...$command, 'iot:mqtt'], $root, $environment);
        $ready = false;
        $deadline = microtime(true) + 15;
        do {
            expect($broker->running(), '恢复事实Broker提前退出：' . $broker->stderr());
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $environment['IOT_MQTT_PORT'], $number, $error, 0.1);
            if (is_resource($socket)) {
                fclose($socket);
                $ready = true;
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        expect($ready, '恢复事实Broker未就绪');
        $workerEnvironment = array_replace($environment, ['IOT_INGESTION_PASSWORD' => $servicePassword,
            'IOT_INGESTION_PORT' => $environment['IOT_MQTT_PORT'], 'IOT_INGESTION_CA' => 'certificate.pem', 'IOT_INGESTION_INSTANCE' => 'recovery']);
        unset($workerEnvironment['IOT_INGESTION_SECRET_HASH']);
        $worker = new Process([...$command, 'iot:ingest'], $root, $workerEnvironment);
        $deadline = microtime(true) + 15;
        do {
            expect($worker->running(), '恢复事实接收角色提前退出：' . $worker->stderr());
            $subscription = $database->query("SELECT subscriptions::text FROM type_mqtt_sessions WHERE client_id='iot-ingestion-recovery' AND owner_id IS NOT NULL")->fetchColumn();
            if (is_string($subscription) && str_contains($subscription, '$share/ingestion/')) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        expect(is_string($subscription) && str_contains($subscription, '$share/ingestion/'), '恢复事实接收订阅未就绪');
        $fixture = ['credential' => $registered['credential'], 'token' => $token, 'tenant' => $tenant,
            'path' => $path . '/devices/' . $device['id'], 'ownership_id' => $device['ownership_id']];
        $mqtt = static function (string $mode, array $extra = []) use ($environment, $fixture, $directory, $root, $servicePassword, &$report): void {
            $client = new Process(['node', $root . '/tests/iot-lifecycle-mqtt.mjs', $mode], $root, array_replace($environment, [
                'TYPE_LIFECYCLE_FIXTURE' => json_encode(array_replace($fixture, $extra), JSON_THROW_ON_ERROR),
                'TYPE_DEVICE_HTTP' => 'http://127.0.0.1:' . $environment['APP_PORT'],
                'TYPE_DEVICE_CA' => $directory . '/certificate.pem', 'TYPE_INGESTION_PASSWORD' => $servicePassword,
            ]));
            try {
                $result = $client->wait(60);
                expect($result->successful(), '恢复MQTT公开入口失败：' . $result->stderr . $result->stdout);
                $report['mqtt'][] = json_decode(trim($result->stdout), true, 16, JSON_THROW_ON_ERROR);
            } finally {
                $client->stop();
            }
        };
        $alarm = static function () use ($command, $environment): void {
            for ($batch = 0; $batch < 100; $batch++) {
                if (!iotRecoveryRun([...$command, 'iot:alarm', '100'], $environment)['data']['has_more']) {
                    return;
                }
            }
            throw new RuntimeException('恢复告警消费者未在有界批次排空');
        };
        if ($phase === 'seed') {
            $mqtt('recovery-telemetry', ['sequence_start' => 1, 'values' => [11, 12, 13]]);
            $alarm();
            $active = $request('GET', $path . '/alarms?status=active', $token, $tenant, null);
            expect($active['total'] === 1, '真实连续三次超限没有建立唯一告警');
            $alarmPath = $path . '/alarms/' . $active['items'][0]['id'];
            $acknowledged = $request('POST', $alarmPath . '/acknowledge', $token, $tenant, [])['data'];
            expect($acknowledged['acknowledged_at'] !== null, '公开确认未保存人员与时间');
            $mqtt('recovery-telemetry', ['sequence_start' => 4, 'values' => [2, 8, 5]]);
            $alarm();
            $ended = $request('GET', $alarmPath, $token, $tenant, null)['data'];
            expect($ended['status'] === 'ended' && $ended['end_reason'] === 'value_recovered' && $ended['acknowledged_at'] === $acknowledged['acknowledged_at'], '真实恢复样本未保留结束与确认事实');
            $redisServer = getenv('TYPE_REDIS_SERVER');
            expect(is_string($redisServer) && is_file($redisServer), '非空通知恢复需要TYPE_REDIS_SERVER指向本机原生Redis');
            $redis = new NativeRolloutRedis($directory . '/notice-redis', $redisServer);
            $redisEnvironment = $redis->environment();
            $noticeEnvironment = array_replace($environment, ['IOT_NOTICES_REDIS_HOST' => $redisEnvironment['TYPE_REDIS_HOST'],
                'IOT_NOTICES_REDIS_PORT' => $redisEnvironment['TYPE_REDIS_PORT'], 'IOT_NOTICES_NAMESPACE' => 'recovery-' . basename(dirname($directory))]);
            iotRecoveryRun([...$command, 'iot:notices', '100'], $noticeEnvironment);
            foreach (['triggered', 'ended'] as $kind) {
                expect($request('GET', $path . '/notifications?kind=' . $kind, $token, $tenant, null)['total'] === 1, '告警触发或结束通知没有形成非空读模型');
            }
            $report['stable_device'] = iotRecoveryPhysicalControl($request, $context, $token, $command, $environment, $directory, $database);
            $mqtt('transfer-retained');
            $retained = $database->prepare("SELECT encode(payload, 'base64') FROM type_mqtt_retained WHERE topic=?");
            $retained->execute([$registered['credential']['topics']['subscribe']]);
            expect($retained->fetchColumn() === base64_encode("lifecycle-control\0binary"), '真实MQTT保留消息字节不符');
            $queued = $database->prepare("SELECT COUNT(*) FROM type_mqtt_deliveries WHERE client_id=? AND state='pending' AND qos=1 AND NOT started");
            $queued->execute([$device['id']]);
            expect((int) $queued->fetchColumn() === 1, '离线持久会话没有保留真实待投递消息');
            $report['device_id'] = $device['id'];
            $report['alarm_id'] = $active['items'][0]['id'];
            $report['client'] = 'MQTT.js 5.15.0';
            $report['tls'] = true;
        } elseif ($phase === 'current') {
            $revoked = $context['devices']['revoked']['device'];
            $request(
                'POST',
                $path . '/devices/' . $revoked['id'] . '/rotate',
                $token,
                $tenant,
                ['version' => $revoked['version'], 'confirm_device_id' => $revoked['id']],
                202
            );
            $clientEnvironment = array_replace($environment, ['TYPE_DEVICE_HTTP' => 'http://127.0.0.1:' . $environment['APP_PORT'],
                'TYPE_DEVICE_CA' => $directory . '/certificate.pem', 'TYPE_INGESTION_PASSWORD' => $servicePassword]);
            $report['transfer'] = iotRecoveryPhysicalTransfer($request, $context, $tokens, $command, $environment, $clientEnvironment, $directory, $database, $worker);
            $report['credential_rotated_by_http'] = true;
            $report['impersonation_session_created'] = isset($context['impersonation']);
        } elseif ($phase === 'reopen') {
            foreach ($context['old_sessions'] as $oldSession) {
                $request('GET', '/' . $oldSession['realm'] . '/auth/me', $oldSession['token'], '', null, 401);
            }
            $report['old_sessions_refused'] = true;
            foreach ($context['devices'] as $kind => $registration) {
                $detail = $request('GET', $path . '/devices/' . $registration['device']['id'], $token, $tenant, null)['data'];
                expect((int) $detail['recovery_verified'] === ($kind === 'stable' ? 1 : 0), '真实恢复后错误放行或隔离设备：' . $kind);
                $report['device_authorization'][$kind] = ['id' => $detail['id'], 'verified' => (int) $detail['recovery_verified']];
                if ($kind !== 'stable') {
                    $denied = $request(
                        'POST',
                        $path . '/devices/' . $detail['id'] . '/commands',
                        $token,
                        $tenant,
                        ['command_id' => bin2hex(random_bytes(16)), 'identifier' => 'switch', 'values' => ['on' => true], 'version' => (int) $detail['version']],
                        409
                    );
                    expect($denied['error'] === 'command_device_not_online', '旧设备控制没有返回现有指令不可用稳定码');
                    $mqtt('denied', ['credential' => $registration['credential']]);
                }
            }
            $report['stable_device'] = iotRecoveryPhysicalControl($request, $context, $token, $command, $environment, $directory, $database);
        } else {
            throw new RuntimeException('未定义的物理恢复公开入口阶段');
        }
    } finally {
        $failures = [];
        foreach (['ingestion' => $worker, 'broker' => $broker, 'http' => $server] as $role => $process) {
            if ($process === null) {
                continue;
            }
            try {
                $result = $process->stop(20);
                $log = str_replace([$servicePassword, hash('sha256', $servicePassword), $environment['DB_PASSWORD']], '<REDACTED>', $result->stdout . $result->stderr);
                file_put_contents($directory . '/' . $phase . '-' . $role . '.log', $log);
                chmod($directory . '/' . $phase . '-' . $role . '.log', 0600);
                expect($result->successful(), '恢复事实角色未正常退出：' . $role . ' ' . $log);
                if ($role !== 'http') {
                    $report[$role] = json_decode(trim($result->stdout), true, 32, JSON_THROW_ON_ERROR);
                }
                $report['stopped'][$role] = true;
            } catch (Throwable $failure) {
                $failures[] = $failure->getMessage();
            }
        }
        try {
            $redis?->close();
            $report['stopped']['redis'] = $redis !== null;
        } catch (Throwable $failure) {
            $failures[] = $failure->getMessage();
        }
        $report['cleanup_failures'] = $failures;
        expect($failures === [], implode('；', $failures));
    }
    expect(!$report['ingestion']['pending'] && !$report['ingestion']['quarantined'] && !$report['ingestion']['running'], '备份前接收角色仍有受管工作');
    foreach (['connections', 'subscriptions', 'bufferedBytes', 'incomingExchanges', 'outgoingExchanges', 'eventRegistrations',
        'eventTimers', 'pendingCommits', 'closingSessions'] as $resource) {
        expect($report['broker'][$resource] === 0, '备份前Broker仍有活动资源：' . $resource);
    }
    expect((int) $database->query("SELECT COUNT(*) FROM pg_stat_activity WHERE datname=current_database() AND application_name LIKE 'type_mqtt_%'")->fetchColumn() === 0, '备份前Broker后台数据库连接未清理');
    $facts = iotRecoveryFacts($database);
    foreach (['iot_alarm_rules', 'iot_alarm_rule_versions', 'iot_alarm_states', 'iot_alarms', 'iot_notice_outbox', 'iot_notifications',
        'type_mqtt_sessions', 'type_mqtt_messages', 'type_mqtt_deliveries', 'type_mqtt_retained'] as $table) {
        expect(($facts[$table]['rows'] ?? 0) > 0, '恢复事实仍为空：' . $table);
        $report['required_nonempty'][$table] = $facts[$table]['rows'];
    }
    $report['status'] = 'passed';
}

/** 恢复点已包含该设备；只在备份之后通过正式设备与HTTP实施转移。 */
function iotRecoveryPhysicalTransfer(Closure $request, array $context, array $tokens, array $command, array $environment, array $clientEnvironment, string $base, PDO $database, Process $worker): array
{
    require_once __DIR__ . '/iot-ingestion-mqtt.php';
    $source = $context['tenant'];
    $target = $context['target'];
    $registration = $context['devices']['transferred'];
    $device = $registration['device'];
    $call = static fn (string $method, string $path, string $tenant, ?array $data = null, int $expected = 200): array =>
        $request($method, $path, $tenant === $source ? $tokens['owner'] : $tokens['outsider'], $tenant, $data, $expected);
    $prefix = '/customer/tenants/' . $source;
    $sourceDetail = $call('GET', $prefix . '/devices/' . $device['id'], $source)['data'];
    $model = $sourceDetail['model'];
    $deviceEnvironment = array_replace($environment, ['IOT_DEVICE_CACHE' => 'transfer-device.sqlite', 'IOT_DEVICE_ID' => $device['id'],
        'IOT_DEVICE_OWNERSHIP_ID' => $device['ownership_id'], 'IOT_DEVICE_TENANT_ID' => $source, 'IOT_DEVICE_MODEL_VERSION' => '1',
        'IOT_DEVICE_CREDENTIAL_ID' => $registration['credential']['id'], 'IOT_DEVICE_PASSWORD' => $registration['credential']['password'],
        'IOT_DEVICE_HOST' => '127.0.0.1', 'IOT_DEVICE_PORT' => $environment['IOT_MQTT_PORT'], 'IOT_DEVICE_CA' => 'certificate.pem',
        'IOT_DEVICE_PEER_NAME' => '127.0.0.1', 'IOT_DEVICE_SUPPORTED_MODELS' => json_encode([1 => $model['structure_hash']], JSON_THROW_ON_ERROR),
        'DB_HOST' => '192.0.2.1', 'DB_PORT' => '1']);
    $run = static fn (string $action, ?array $input = null, bool $success = true, array $overrides = []): array =>
        iotRecoveryDeviceRun($command, array_replace($deviceEnvironment, $overrides), $base, 'transfer-device', $action, $input, $success);
    $run('enqueue', ['type' => 'telemetry', 'sampled_at' => time(), 'values' => ['temperature' => 20]]);
    expect($run('send')['delivery']['accepted'] === 1, '备份后的待转移设备未取得真实回执');
    $deadline = microtime(true) + 15;
    do {
        $sourceDetail = $call('GET', $prefix . '/devices/' . $device['id'], $source)['data'];
        if ($sourceDetail['connection']['status'] === 'offline') {
            break;
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    expect($sourceDetail['connection']['status'] === 'offline', '备份后转移设备尚未完成持久关闭');
    $run('enqueue', ['type' => 'telemetry', 'sampled_at' => time(), 'values' => ['temperature' => 21]]);
    $intent = ['transfer_id' => bin2hex(random_bytes(16)), 'device_id' => $device['id'], 'target_tenant_id' => $target,
        'version' => (int) $sourceDetail['version']];
    $requested = $call('POST', $prefix . '/transfers', $source, $intent, 202)['data'];
    $transferPath = '/customer/tenants/' . $target . '/transfers/' . $intent['transfer_id'];
    $requested = $call('GET', $prefix . '/transfers/' . $intent['transfer_id'], $source)['data'];
    expect($call('POST', $transferPath, $target, ['action' => 'accept', 'decision_id' => bin2hex(random_bytes(16)),
        'copy_name' => '恢复核对实际接收产品', 'version' => $requested['device_version']], 202)['data']['status'] === 'frozen', '备份后的转移未进入设备冻结阶段');
    $listener = new Process([...$command, 'iot:device', 'listen'], dirname(__DIR__), $deviceEnvironment);
    try {
        $deadline = microtime(true) + 45;
        do {
            expect($worker->running() && $listener->running(), '备份后的冻结排空角色提前退出：' . $listener->stderr() . $worker->stderr());
            $observed = $call('GET', $transferPath, $target)['data'];
            if ($observed['ready_for_switch']) {
                break;
            }
            usleep(50000);
        } while (microtime(true) < $deadline);
        expect($observed['ready_for_switch'] && $observed['device_status']['pending_count'] === 0, '备份后设备未实际冻结并排空旧缓存');
    } finally {
        expect($listener->stop(15)->successful(), '备份后转移监听未正常退出');
    }
    $fixture = ['tenant' => $source, 'transfer_target' => $target, 'admin_token' => $tokens['owner'], 'transfer_target_token' => $tokens['outsider']];
    $switch = iotDeviceTransferSwitchChecks(
        $call,
        $run,
        $fixture,
        $registration,
        $intent,
        $clientEnvironment,
        $database,
        $base,
        $command,
        $environment,
        static function (): never {
            throw new RuntimeException('物理恢复场景不注入第二套接收角色接管');
        }
    );
    expect($call('GET', $transferPath, $target)['data']['status'] === 'completed', '备份后真实转移尚未完成');
    return ['device_existed_at_backup' => true, 'independent_admins' => true, 'completed' => true, 'switch' => $switch];
}

/** 正式设备入口共享本轮输入生命周期；设备仅持有自己的SQLite，平台数据库故意不可达。 */
function iotRecoveryDeviceRun(array $command, array $environment, string $base, string $name, string $action, ?array $input = null, bool $success = true): array
{
    $file = $input === null ? null : $base . '/' . $name . '-input.json';
    if ($file !== null) {
        expect(file_put_contents($file, json_encode($input, JSON_THROW_ON_ERROR)) !== false && chmod($file, 0600), '不能创建私有设备命令输入');
    }
    $process = new Process([...$command, 'iot:device', $action], dirname(__DIR__), $environment, 65536, $file);
    try {
        $result = $process->wait(65);
        expect(!$result->timedOut && $result->successful() === $success, '恢复设备命令失败：' . $action . ' ' . $result->stderr . $result->stdout);
        expect(!str_contains($result->stdout . $result->stderr, $environment['IOT_DEVICE_PASSWORD']), '恢复设备命令泄漏凭据');
        return trim($result->stdout) === '' ? [] : json_decode(trim($result->stdout), true, 32, JSON_THROW_ON_ERROR);
    } finally {
        $process->stop();
        if ($file !== null && is_file($file)) {
            expect(unlink($file), '本轮设备一次性输入未清理');
        }
    }
}

/** 核对一致的备份内设备重新上报，并以设备执行回执闭合真实HTTP控制。 */
function iotRecoveryPhysicalControl(Closure $request, array $context, string $token, array $command, array $environment, string $base, PDO $database): array
{
    $registration = $context['devices']['stable'];
    $device = $registration['device'];
    $tenant = $context['tenant'];
    $path = '/customer/tenants/' . $tenant . '/devices/' . $device['id'];
    $deviceEnvironment = array_replace($environment, ['IOT_DEVICE_CACHE' => 'reopened-device.sqlite', 'IOT_DEVICE_ID' => $device['id'],
        'IOT_DEVICE_OWNERSHIP_ID' => $device['ownership_id'], 'IOT_DEVICE_TENANT_ID' => $tenant, 'IOT_DEVICE_MODEL_VERSION' => '1',
        'IOT_DEVICE_CREDENTIAL_ID' => $registration['credential']['id'], 'IOT_DEVICE_PASSWORD' => $registration['credential']['password'],
        'IOT_DEVICE_HOST' => '127.0.0.1', 'IOT_DEVICE_PORT' => $environment['IOT_MQTT_PORT'], 'IOT_DEVICE_CA' => 'certificate.pem',
        'IOT_DEVICE_PEER_NAME' => '127.0.0.1', 'DB_HOST' => '192.0.2.1', 'DB_PORT' => '1']);
    $run = static fn (string $action, ?array $input = null): array => iotRecoveryDeviceRun($command, $deviceEnvironment, $base, 'reopened-device', $action, $input);
    $queued = $run('enqueue', ['type' => 'telemetry', 'sampled_at' => time(), 'values' => ['temperature' => 28]]);
    expect($run('send')['delivery']['accepted'] === 1, '物理恢复后合法设备未取得持久业务回执');
    $current = $request('GET', $path . '/current', $token, $tenant, null)['data'];
    expect($current['sequence'] === $queued['sequence'] && $current['fields'][0]['value'] === 28, '物理恢复后HTTP未读到实际新采样');
    $deadline = microtime(true) + 15;
    do {
        $closed = $request('GET', $path, $token, $tenant, null)['data']['connection']['status'];
        if ($closed === 'offline') {
            break;
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    expect($closed === 'offline', '恢复后设备send尚未完成持久关闭');
    $deviceVersion = (int) $request('GET', $path, $token, $tenant, null)['data']['version'];
    $started = microtime(true);
    $listener = new Process([...$command, 'iot:device', 'listen'], dirname(__DIR__), $deviceEnvironment);
    try {
        $clock = $database->prepare("SELECT encode(m.payload, 'base64') FROM type_mqtt_messages m JOIN type_mqtt_deliveries d ON d.message_id=m.id"
            . " WHERE m.topic=? AND d.client_id=? AND d.state='acknowledged' AND m.created_at >= to_timestamp(?)");
        $ready = false;
        $deadline = microtime(true) + 25;
        do {
            expect($listener->running(), '恢复后设备控制监听提前退出：' . $listener->stderr());
            $clock->execute([$registration['credential']['topics']['subscribe'], $device['id'], $started]);
            foreach ($clock->fetchAll(PDO::FETCH_COLUMN) as $bytes) {
                $message = json_decode(base64_decode($bytes), true);
                if (($message['type'] ?? '') === 'time_response' && ($message['ownership_id'] ?? '') === $device['ownership_id']) {
                    $ready = true;
                }
            }
            if ($ready) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        expect($ready, '恢复后设备尚未完成真实时间挑战');
        $record = $request(
            'POST',
            $path . '/commands',
            $token,
            $tenant,
            ['command_id' => bin2hex(random_bytes(16)), 'identifier' => 'switch', 'values' => ['on' => true], 'version' => $deviceVersion],
            201
        )['data'];
        $deadline = microtime(true) + 30;
        do {
            expect($listener->running(), '恢复后执行回执前设备退出：' . $listener->stderr());
            $execution = $request('GET', $path . '/commands', $token, $tenant, null)['items'][0];
            if ($execution['execution'] !== 'pending') {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        expect(
            $execution['id'] === $record['id'] && $execution['execution'] === 'succeeded'
            && $execution['device_received_at'] !== null && $execution['mqtt_at'] !== null && $execution['result']['simulated'] === true,
            '物理恢复后真实控制未闭环：' . json_encode($execution, JSON_THROW_ON_ERROR)
        );
        $ledger = new PDO('sqlite:' . $base . '/reopened-device.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $receipt = $ledger->prepare('SELECT status, pending FROM device_commands WHERE id = ?');
        $deadline = microtime(true) + 15;
        do {
            $receipt->execute([$record['id']]);
            $local = $receipt->fetch(PDO::FETCH_ASSOC);
            if (is_array($local) && (int) $local['pending'] === 0) {
                break;
            }
            expect($listener->running(), '恢复设备没有收到最终执行结果业务确认');
            usleep(20000);
        } while (microtime(true) < $deadline);
        expect(is_array($local) && $local['status'] === 'succeeded' && (int) $local['pending'] === 0, '恢复设备本地执行结果没有得到平台业务确认');
        $receipt = null;
        $ledger = null;
        return ['tls' => true, 'protocol' => 5, 'business_receipt' => true, 'http_current_visible' => true,
            'sequence' => $queued['sequence'], 'command_id' => $record['id'], 'execution' => 'succeeded',
            'execution_business_ack' => true, 'database_unavailable_to_device' => true];
    } finally {
        expect($listener->stop(15)->successful(), '恢复后设备控制监听未正常退出');
    }
}

/** 全量→增量→合成→真实WAL恢复点→独立权威核对→新同步主备重新开放。 */
function iotRecoveryPhysical(array $command, array $environment, string $base, array &$report): void
{
    $root = dirname(__DIR__);
    $clientRoot = realpath((string) getenv('TYPE_MQTT_CLIENT_ROOT'));
    expect(is_string($clientRoot) && is_file($clientRoot . '/node_modules/mqtt/package.json'), 'TYPE_MQTT_CLIENT_ROOT需要已安装MQTT.js 5.15.0的测试依赖根');
    $directory = $base . '/physical chain';
    expect(mkdir($directory, 0700), '不能建立物理恢复测试目录');
    $archive = $directory . '/wal';
    expect(mkdir($archive, 0700), '不能建立独立WAL测试目录');
    $tools = NativeDatabase::tools('pgsql', (string) getenv('TYPE_PGSQL_TOOLS'));
    foreach (['pg_basebackup', 'pg_combinebackup', 'pg_verifybackup'] as $name) {
        $binary = $tools['root'] . '/bin/' . $name;
        expect(is_executable($binary), '缺少PostgreSQL物理备份工具：' . $name);
        $report['tools'][$name] = ['sha256' => hash_file('sha256', $binary), 'version' => trim(successful([$binary, '--version'], $root))];
    }
    $primaryData = $directory . '/primary/data';
    $hook = iotRecoveryHook(
        [...$command, 'iot:wal', 'archive', $archive],
        $environment,
        [escapeshellarg(str_replace('%', '%%', $primaryData) . '/%p'), "'%f'"]
    );
    $database = null;
    $sync = null;
    $recoveredSync = null;
    $recovered = null;
    $source = null;
    $restored = null;
    $passwordFile = $directory . '/pgpass';
    $password = '';
    $report['status'] = 'preparing';
    try {
        $database = new NativeDatabase($directory . '/primary', 'pgsql', $tools, [], [
            'archive_mode' => 'on', 'archive_command' => $hook, 'summarize_wal' => 'on', 'wal_summary_keep_time' => '10d']);
        $sync = new PostgresSync($database, $directory . '/standby', $tools);
        $source = $sync->connection();
        $dbEnvironment = array_replace(getenv(), $database->environment());
        $dbEnvironment['TYPE_MQTT_CLIENT_ROOT'] = $clientRoot;
        $password = $dbEnvironment['TYPE_PGSQL_PASSWORD'];
        $pass = '127.0.0.1:' . $dbEnvironment['TYPE_PGSQL_PORT'] . ':*:' . $dbEnvironment['TYPE_PGSQL_USER'] . ':' . $password . "\n";
        expect(file_put_contents($passwordFile, $pass) === strlen($pass) && chmod($passwordFile, 0600), '不能创建私有物理备份凭据');
        $dbEnvironment['PGPASSFILE'] = $passwordFile;
        $dbEnvironment['INGESTION_STATUS_CACHE'] = $directory . '/status.sqlite';
        // 复用已存在的公开服务场景建立真实IoT表与行为，不用手写SQL冒充接入/指令业务。
        nativeDatabaseCommand([PHP_BINARY, '-r', 'require $argv[1]; require $argv[2]; main(2, [$argv[2], "pgsql"]);',
            $root . '/vendor/autoload.php', $root . '/tests/fixtures/iot-ingestion-cases.php'], $dbEnvironment, [$password], $directory . '/seed.log', 120);
        $report['seed'] = 'existing-php-public-services-fixture';
        $report['public_seed'] = [];
        $context = [];
        iotRecoveryPhysicalSeed($command, $dbEnvironment, $directory, $source, $report['public_seed'], $context);
        $source->exec('CREATE TABLE recovery_probe (id INTEGER PRIMARY KEY, phase TEXT NOT NULL)');
        $source->exec("INSERT INTO recovery_probe VALUES (1, 'full')");
        $baseCommand = [$tools['root'] . '/bin/pg_basebackup', '-h', '127.0.0.1', '-p', $dbEnvironment['TYPE_PGSQL_PORT'],
            '-U', $dbEnvironment['TYPE_PGSQL_USER'], '-X', 'stream', '-c', 'fast', '--no-password', '--no-clean', '--manifest-checksums=SHA256'];
        nativeDatabaseCommand([...$baseCommand, '-D', $directory . '/full'], $dbEnvironment, [$password], $directory . '/full.log', 120);
        nativeDatabaseCommand([$tools['root'] . '/bin/pg_verifybackup', $directory . '/full'], $dbEnvironment, [$password], $directory . '/verify-full.log');
        $report['full_manifest_sha256'] = hash_file('sha256', $directory . '/full/backup_manifest');
        $source->exec("INSERT INTO recovery_probe VALUES (2, 'incremental')");
        nativeDatabaseCommand(
            [...$baseCommand, '-D', $directory . '/incremental', '--incremental=' . $directory . '/full/backup_manifest'],
            $dbEnvironment,
            [$password],
            $directory . '/incremental.log',
            120
        );
        $report['incremental_manifest_sha256'] = hash_file('sha256', $directory . '/incremental/backup_manifest');
        $combined = $directory . '/combined';
        nativeDatabaseCommand([$tools['root'] . '/bin/pg_combinebackup', '--manifest-checksums=SHA256', '-o', $combined,
            $directory . '/full', $directory . '/incremental'], $dbEnvironment, [$password], $directory . '/combine.log', 120);
        nativeDatabaseCommand([$tools['root'] . '/bin/pg_verifybackup', $combined], $dbEnvironment, [$password], $directory . '/verify-combined.log');
        $report['combined_manifest_sha256'] = hash_file('sha256', $combined . '/backup_manifest');
        $source->exec("INSERT INTO recovery_probe VALUES (3, 'wal-only')");
        $expected = iotRecoveryFacts($source);
        $restoreLsn = (string) $source->query("SELECT pg_create_restore_point('iot_recovery_target')")->fetchColumn();
        $walName = (string) $source->query('SELECT pg_walfile_name(' . $source->quote($restoreLsn) . '::pg_lsn)')->fetchColumn();
        $report['current_authority'] = [];
        iotRecoveryPhysicalSeed($command, $dbEnvironment, $directory, $source, $report['current_authority'], $context, 'current');
        // 当前权威来源仍是实际完成撤权与转移的源主库；此时所有业务写入角色均已退出。
        $snapshot = iotRecoveryRun([...$command, 'iot:recovery', 'snapshot'], $context['environment']);
        $authorityBytes = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $authorityFile = $directory . '/current authority.json';
        expect(file_put_contents($authorityFile, $authorityBytes) === strlen($authorityBytes) && chmod($authorityFile, 0600), '不能冻结私有当前权威清单');
        $authorityDigest = hash('sha256', $authorityBytes);
        expect(!str_contains($authorityBytes, 'password_hash') && !str_contains($authorityBytes, 'secret_hash')
            && !str_contains($authorityBytes, $password), '权威清单泄漏秘密字段');
        $report['current_authority']['snapshot'] = ['sha256' => $authorityDigest, 'records' => count($snapshot['records']),
            'writers_stopped' => true, 'source' => 'actual-post-backup-http-and-device-changes'];
        $source->exec("INSERT INTO recovery_probe VALUES (4, 'after-target-must-not-appear')");
        $source->query('SELECT pg_switch_wal()')->fetchColumn();
        $deadline = microtime(true) + 90;
        while (!is_file($archive . '/' . $walName . '.sha256') && microtime(true) < $deadline) {
            usleep(100000);
            clearstatcache();
        }
        expect(is_file($archive . '/' . $walName . '.sha256'), '恢复点WAL未在预算内完成归档');
        $report['restore_point'] = ['name' => 'iot_recovery_target', 'lsn' => $restoreLsn, 'wal' => $walName,
            'archive' => iotRecoveryRun([...$command, 'iot:wal', 'verify', $archive, $walName], $environment)];
        $report['facts_at_target'] = $expected;
        $source = null;
        $sync->close();
        $report['source_sync'] = $sync->evidence();
        $database->close();
        $report['source_stopped_before_restore'] = $database->evidence()['owned-server-stopped'];
        // 合成备份已先通过官方校验，再写恢复控制文件；不回写原全量/增量备份。
        expect(file_put_contents($combined . '/recovery.signal', '') === 0, '不能建立恢复信号');
        $listener = stream_socket_server('tcp://127.0.0.1:0', $number, $message);
        expect(is_resource($listener), '不能分配隔离恢复端口');
        $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        fclose($listener);
        $restoreHook = iotRecoveryHook(
            [...$command, 'iot:wal', 'restore', $archive],
            $environment,
            ["'%f'", escapeshellarg(str_replace('%', '%%', $combined) . '/%p')]
        );
        $started = hrtime(true);
        $recovered = new Process(
            [$tools['postgres'], '-D', $combined, '-h', '127.0.0.1', '-p', (string) $port, '-k', '',
            '-c', 'archive_mode=off', '-c', 'restore_command=' . $restoreHook,
            '-c', 'recovery_target_name=iot_recovery_target', '-c', 'recovery_target_action=pause', '-c', 'hot_standby=on'],
            $directory,
            $dbEnvironment,
            16777216
        );
        $deadline = microtime(true) + 90;
        do {
            expect($recovered->running(), '隔离恢复库提前退出：' . $recovered->stderr());
            try {
                $restored = new PDO(
                    'pgsql:host=127.0.0.1;port=' . $port . ';dbname=type_app_test;connect_timeout=1',
                    $dbEnvironment['TYPE_PGSQL_USER'],
                    $password,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                if ($restored->query("SELECT pg_get_wal_replay_pause_state() = 'paused'")->fetchColumn() === true) {
                    break;
                }
                $restored = null;
            } catch (PDOException) {
                $restored = null;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        expect($restored !== null, '恢复未在预算内到达指定暂停点');
        expect($restored->query('SELECT pg_is_in_recovery()')->fetchColumn() === true, '授权核对前恢复库已开放写入');
        expect(realpath((string) $restored->query('SHOW data_directory')->fetchColumn()) === $combined, '恢复查询连接了原库');
        expect(iotRecoveryFacts($restored) === $expected, '恢复点业务事实与备份链不一致');
        expect($restored->query('SELECT count(*) FROM recovery_probe')->fetchColumn() === 3, '未应用WAL增量或越过恢复点');
        $report['recovery_seconds'] = (hrtime(true) - $started) / 1e9;
        $report['facts_identical'] = true;
        $report['isolated_readonly_pause'] = true;
        $report['old_backup_manifests_preserved'] = hash_file('sha256', $directory . '/full/backup_manifest') === $report['full_manifest_sha256']
            && hash_file('sha256', $directory . '/incremental/backup_manifest') === $report['incremental_manifest_sha256'];
        expect($report['old_backup_manifests_preserved'], '恢复改写原备份');
        expect($restored->query('SELECT pg_promote(true, 60)')->fetchColumn() === true, '受控恢复主库没有在旧源退出后提升');
        $restored = null;
        $newEnvironment = array_replace($dbEnvironment, ['TYPE_PGSQL_PORT' => (string) $port]);
        $recoveredSync = new PostgresSync($newEnvironment, $directory . '/recovered-standby', $tools);
        $restored = $recoveredSync->connection();
        $report['reopened_sync'] = $recoveredSync->evidence();
        $maintenanceEnvironment = array_replace($context['environment'], ['DB_PORT' => (string) $port]);
        $report['authorization_reconciliation'] = [];
        $reconcileStarted = hrtime(true);
        $id = bin2hex(random_bytes(16));
        $state = iotRecoveryRun([...$command, 'iot:recovery', 'begin', $id, 'physical-recovery', 'owned-source-primary-standby-and-business-processes-stopped'], $maintenanceEnvironment);
        foreach (['serve', 'iot:mqtt'] as $role) {
            iotRecoveryRun([...$command, $role], $maintenanceEnvironment, false, 30, 'recovery_isolated');
        }
        $steps = ['isolating' => 0, 'reviewing' => 0, 'restoring' => 0];
        foreach (['isolating' => 'isolate', 'reviewing' => 'review', 'restoring' => 'restore'] as $stage => $action) {
            for ($batch = 0; $batch < 100 && $state['state'] === $stage; $batch++) {
                $arguments = $action === 'review' ? [$authorityFile, $authorityDigest, $state['cursor'] === '' ? '0' : $state['cursor']] : [];
                $state = iotRecoveryRun([...$command, 'iot:recovery', $action, $id, ...$arguments], $maintenanceEnvironment);
                $steps[$stage]++;
            }
            expect($state['state'] !== $stage, '物理恢复核对未在有界批次内完成：' . $stage);
        }
        expect($state['state'] === 'ready', '真实物理恢复核对没有允许重新开放');
        expect(
            (int) $restored->query('SELECT count(*) FROM admin_sessions')->fetchColumn() === 0
            && (int) $restored->query('SELECT count(*) FROM customer_sessions')->fetchColumn() === 0,
            '物理恢复后旧登录或模拟会话复活'
        );
        $report['authorization_reconciliation'] = ['state' => $state, 'steps' => $steps, 'seconds' => (hrtime(true) - $reconcileStarted) / 1e9,
            'snapshot_sha256' => $authorityDigest, 'startup_denied_until_reviewed' => true, 'old_sessions_removed' => true,
            'old_impersonation_sessions_removed' => true, 'old_rows_rewritten_by_test' => false];
        $report['reopened'] = [];
        iotRecoveryPhysicalSeed($command, $newEnvironment, $directory, $restored, $report['reopened'], $context, 'reopen');
        $report['reopened_facts'] = iotRecoveryFacts($restored);
        $standby = $recoveredSync->standby();
        expect(iotRecoveryFacts($standby) === $report['reopened_facts'], '重新开放后的业务事实未同步到新备库');
        $standby = null;
        $report['reopened_standby_identical'] = true;
        $report['recovery_to_reopened_seconds'] = (hrtime(true) - $started) / 1e9;
        $report['status'] = 'passed';
    } finally {
        $restored = null;
        $source = null;
        $standby = null;
        $cleanupFailures = [];
        if ($recoveredSync !== null) {
            try {
                $recoveredSync->close();
                $report['reopened_sync'] = $recoveredSync->evidence();
            } catch (Throwable $cleanupFailure) {
                $cleanupFailures[] = $cleanupFailure->getMessage();
            }
        }
        if ($recovered !== null) {
            try {
                $result = $recovered->stop(20);
                $log = str_replace($password, '<REDACTED>', $result->stdout . $result->stderr);
                expect(file_put_contents($directory . '/recovered.log', $log) === strlen($log) && chmod($directory . '/recovered.log', 0600), '恢复进程日志未安全保存');
                $report['recovered_process_stopped'] = $result->successful();
                expect($result->successful(), '本轮恢复进程未正常退出');
            } catch (Throwable $cleanupFailure) {
                $cleanupFailures[] = $cleanupFailure->getMessage();
            }
        }
        foreach ([$sync, $database] as $owner) {
            try {
                $owner?->close();
            } catch (Throwable $cleanupFailure) {
                $cleanupFailures[] = $cleanupFailure->getMessage();
            }
        }
        if (is_file($passwordFile) && !unlink($passwordFile)) {
            $cleanupFailures[] = '物理备份临时凭据未清理';
        }
        $report['capacity_claim'] = false;
        $report['independent_failure_domain'] = false;
        $report['seven_day_window'] = 'not-verified';
        $report['authorization_reconciliation'] ??= 'not-reached';
        $report['cleanup_failures'] = $cleanupFailures;
        expect($cleanupFailures === [], implode('；', $cleanupFailures));
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

$root = dirname(__DIR__);
$target = $argv[1] ?? '--php';
expect(array_diff(array_slice($argv, 2), ['--no-source', '--physical']) === [], '用法：php tests/iot-recovery.php <--php|原生产物> [--no-source] [--physical]');
$command = $target === '--php' ? [PHP_BINARY, $root . '/bin/typeapp'] : nativeCommand($target);
$base = $root . '/build/iot-recovery-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '不能覆盖恢复测试目录');
$noSource = in_array('--no-source', $argv, true);
if ($noSource) {
    expect($target !== '--php' && PHP_OS_FAMILY === 'Darwin', '当前无源码策略需要macOS原生应用');
    $runtime = $base . '/runtime';
    (new Type\Build\NativePackage())->create($target, $runtime);
    $built = json_decode(file_get_contents($target . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
    $policy = ['sandbox-exec', '-f', $root . '/tests/fixtures/iot-no-source.sb'];
    foreach (['APP' => $root . '/app', 'PLUGIN' => $root . '/plugin', 'VENDOR' => $root . '/vendor', 'CONFIG' => $root . '/config',
        'COMPILER' => dirname($built['runtime-profile']['ini'], 2), 'COMPOSER' => $root . '/composer.json'] as $role => $path) {
        array_push($policy, '-D', $role . '=' . $path);
    }
    expect(successful([...$policy, PHP_BINARY, '-n', '-r', 'foreach(array_slice($argv,1) as $path){if(@file_get_contents($path)!==false)exit(1);} echo "denied";',
        $root . '/app/main.php', $root . '/plugin/type-core/src/Application.php', $root . '/vendor/autoload.php', $root . '/config/app.php'], $runtime) === 'denied', '源码隔离没有生效');
    $command = [...$policy, $runtime . '/run'];
}
$environment = getenv();
$environment['APP_BASE_PATH'] = $base;
$environment['DB_DRIVER'] = 'invalid-no-database-for-wal';
$environment['APP_DEBUG'] = 'false';
$report = ['status' => 'running', 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'native' => $target !== '--php',
    'no_source' => $noSource ? 'kernel-denied-production-and-generated-source' : 'not-verified',
    'binary_sha256' => $target === '--php' ? null : hash_file('sha256', $target), 'scope' => 'wal-archive-only'];
try {
    $report['wal'] = iotRecoveryWal($command, $environment, $base);
    if (in_array('--physical', $argv, true)) {
        $report['scope'] = 'wal-and-physical-recovery-chain';
        $report['physical'] = [];
        iotRecoveryPhysical($command, $environment, $base, $report['physical']);
    }
    $report['status'] = 'passed';
} finally {
    if ($report['status'] !== 'passed') {
        $report['status'] = 'failed';
    }
    file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
echo 'WAL维护公开入口通过：' . $base . "/verification.json\n";
