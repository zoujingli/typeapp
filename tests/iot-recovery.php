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

/** 通过注册资格、应用授权和 TLS 原始通信建立非空事实；全部写入角色退出后才允许备份取点。 */
function iotRecoveryPhysicalSeed(array $command, array $environment, string $directory, PDO $database, array &$report, array &$context, string $phase = 'seed'): void
{
    require_once __DIR__ . '/iot-communication-fixture.php';
    $root = dirname(__DIR__);
    if ($phase !== 'seed') {
        $environment = array_replace($environment, $context['environment'], ['DB_PORT' => $environment['TYPE_PGSQL_PORT']]);
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
        foreach (['APP_PORT', 'IOT_MQTT_PORT'] as $name) {
            $listener = stream_socket_server('tcp://127.0.0.1:0', $number, $error);
            expect(is_resource($listener), '不能分配恢复事实验证端口');
            $environment[$name] = substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
            fclose($listener);
        }
        $environment['APP_ALLOWED_HOSTS'] = '127.0.0.1:' . $environment['APP_PORT'];
        $certificateConfiguration = $directory . '/certificate.cnf';
        file_put_contents($certificateConfiguration, "[req]\ndistinguished_name=dn\nx509_extensions=server\n[dn]\n[server]\nsubjectAltName=IP:127.0.0.1\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\nextendedKeyUsage=serverAuth\n");
        $options = ['config' => $certificateConfiguration, 'private_key_bits' => 2048, 'digest_alg' => 'sha256', 'x509_extensions' => 'server'];
        $key = openssl_pkey_new($options);
        $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key, $options);
        $certificate = openssl_csr_sign($csr, null, $key, 1, $options);
        expect(openssl_x509_export($certificate, $certificatePem) && openssl_pkey_export($key, $privatePem), '恢复测试TLS证书生成失败');
        file_put_contents($directory . '/certificate.pem', $certificatePem);
        file_put_contents($directory . '/private.pem', $privatePem);
        expect(chmod($directory . '/private.pem', 0600), '恢复测试TLS私钥未限制权限');
    }
    if ($phase === 'seed') {
        $environment['APP_ENV'] = 'prod';
        $environment['APP_ADMIN_PASSWORD'] = 'Recovery-password-2026';
        $environment['APP_CUSTOMER_PASSWORD'] = 'Recovery-password-2026';
        $report['installation'] = iotRecoveryRun([...$command, 'app:install', 'platform', '恢复管理人员', 'owner', '恢复客户人员', '恢复初始客户'], $environment, true, 45)['data'];
    }
    $http = new Type\Testing\HttpClient('http://127.0.0.1:' . $environment['APP_PORT']);
    $request = static function (string $method, string $path, string $token, ?string $tenant, ?array $data, int $status = 200) use ($http, &$report): array {
        $headers = ['Content-Type' => 'application/json'];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        if ($tenant !== '' && $tenant !== null) {
            $headers['X-Tenant-Id'] = $tenant;
        }
        $response = $http->request($method, $path, $headers, $data === null ? '' : json_encode($data === [] ? (object) [] : $data, JSON_THROW_ON_ERROR));
        expect($response->status === $status, '恢复事实管理入口失败：' . $method . ' ' . $path . ' ' . $response->status . ' ' . $response->body);
        $report['http_checks'] = ($report['http_checks'] ?? 0) + 1;
        return $response->json();
    };
    $server = null;
    $broker = null;
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
            return $request('POST', '/' . $realm . '/auth/login', '', '', ['login' => $account, 'password' => 'Recovery-password-2026'])['data'];
        };
        $accounts['platform'] = $login('admin', 'platform');
        $tokens['platform'] = $accounts['platform']['accessToken'];
        if ($phase === 'seed') {
            $request('POST', '/admin/customers', $tokens['platform'], null, ['login' => 'outsider', 'name' => '恢复转配接收者', 'password' => 'Recovery-password-2026']);
        }
        foreach (['owner', 'outsider'] as $account) {
            $accounts[$account] = $login('customer', $account);
            $tokens[$account] = $accounts[$account]['accessToken'];
        }
        if ($phase === 'seed') {
            $tenant = bin2hex(random_bytes(16));
            $tenant = $request('POST', '/admin/tenants', $tokens['platform'], '', ['id' => $tenant, 'name' => '恢复非空事实租户', 'owner_login' => 'owner', 'new_customer' => false], 200)['data']['id'];
            $path = '/customer/tenants/' . $tenant;
            $token = $tokens['owner'];
            $registered = iotRegisteredFixture($request, $tokens['platform'], $tenant, '恢复原始通信设备');
            $device = $registered['device'];
            $context = ['environment' => $environment, 'tokens' => $tokens, 'tenant' => $tenant, 'registration' => $registered, 'devices' => [], 'applications' => []];
            foreach (['stable' => '恢复后合法通信设备', 'revoked' => '备份后吊销设备', 'transferred' => '备份后真实转配设备'] as $kind => $name) {
                $context['devices'][$kind] = iotRegisteredFixture($request, $tokens['platform'], $tenant, $name);
                $context['applications'][$kind] = iotApplicationFixture($request, $token, $tenant, [$context['devices'][$kind]], $name);
            }
            $context['application'] = iotApplicationFixture($request, $token, $tenant, [$registered], '恢复原始业务系统');
            $context['spare_enrollment'] = $request('POST', '/admin/device-authorizations', $tokens['platform'], null, [
                'id' => bin2hex(random_bytes(16)), 'tenant_id' => $tenant, 'name' => '备份后停用资格', 'capacity' => 1, 'register_expires_at' => time() + 3600,
            ], 201)['data'];
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
        $mqtt = static function (string $mode, array $registration, array $application = []) use ($environment, $directory, $root, &$report): void {
            $fixture = ['credential' => $registration['credential'], 'application' => $application];
            $client = new Process(['node', $root . '/tests/iot-recovery-client.mjs', $mode], $root, array_replace($environment, [
                'TYPE_RECOVERY_FIXTURE' => json_encode($fixture, JSON_THROW_ON_ERROR), 'TYPE_DEVICE_CA' => $directory . '/certificate.pem',
            ]));
            try {
                $result = $client->wait(45);
                expect($result->successful(), '恢复 MQTT 公开入口失败：' . $result->stderr . $result->stdout);
                $report['mqtt'][] = json_decode(trim($result->stdout), true, 16, JSON_THROW_ON_ERROR);
            } finally {
                $client->stop();
            }
        };
        if ($phase === 'seed') {
            $mqtt('seed', $registered, $context['application']);
            $mqtt('roundtrip', $context['devices']['stable'], $context['applications']['stable']);
            $retained = $database->prepare("SELECT encode(payload, 'base64') FROM type_mqtt_retained WHERE topic = ?");
            $retained->execute([$registered['credential']['topics']['subscribe']]);
            expect($retained->fetchColumn() === base64_encode("recovery-down\0binary"), '真实 MQTT 保留消息字节不符');
            $queued = $database->prepare("SELECT COUNT(*) FROM type_mqtt_deliveries WHERE client_id = ? AND state = 'pending' AND qos = 1 AND NOT started");
            $queued->execute([$device['id']]);
            expect((int) $queued->fetchColumn() === 1, '离线持久会话没有保留真实待投递消息');
            $report['device_id'] = $device['id'];
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
            $transferred = $context['devices']['transferred']['device'];
            $transfer = $request(
                'POST',
                '/admin/devices/' . $transferred['id'] . '/usage/reassign',
                $tokens['platform'],
                null,
                ['id' => bin2hex(random_bytes(16)), 'version' => $transferred['version'], 'target_tenant_id' => $context['target']],
                202
            )['data'];
            // 设备未接入，但应用授权撤销仍须等待活动节点的真实回执。
            $ready = $transfer;
            $deadline = microtime(true) + 12;
            do {
                $ready = $request(
                    'POST',
                    '/admin/reassignments/' . $transfer['id'],
                    $tokens['platform'],
                    null,
                    ['action' => 'advance', 'version' => $ready['version']]
                )['data'];
                if ($ready['status'] === 'awaiting_acceptance') {
                    break;
                }
                usleep(50000);
            } while (microtime(true) < $deadline);
            expect($ready['status'] === 'awaiting_acceptance', '备份后转配没有完成旧关系隔离');
            $accepted = $request(
                'POST',
                '/customer/tenants/' . $context['target'] . '/reassignments/' . $transfer['id'],
                $tokens['outsider'],
                $context['target'],
                ['action' => 'accept', 'version' => $ready['version'], 'id' => bin2hex(random_bytes(16))]
            )['data'];
            expect($accepted['operation']['status'] === 'completed', '备份后转配未完成');
            $enrollment = $context['spare_enrollment']['authorization'];
            $request(
                'POST',
                '/admin/device-authorizations/' . $enrollment['id'] . '/disable',
                $tokens['platform'],
                null,
                ['tenant_id' => $tenant, 'version' => $enrollment['version']]
            );
            $applicationPath = $path . '/applications/' . substr($context['application']['client_id'], 4);
            $application = $request('GET', $applicationPath, $token, $tenant, null)['data']['application'];
            $request('POST', $applicationPath . '/disable', $token, $tenant, ['version' => $application['version']]);
            $report['credential_rotated_by_http'] = true;
            $report['transfer'] = ['completed' => true, 'device_id' => $transferred['id']];
            $report['enrollment_disabled_by_http'] = true;
            $report['application_disabled_by_http'] = true;
        } elseif ($phase === 'reopen') {
            foreach ($context['devices'] as $kind => $registration) {
                $detail = $request('GET', $path . '/devices/' . $registration['device']['id'], $token, $tenant, null)['data'];
                expect((int) $detail['recovery_verified'] === ($kind === 'stable' ? 1 : 0), '真实恢复后错误放行或隔离设备：' . $kind);
                $report['device_authorization'][$kind] = ['id' => $detail['id'], 'verified' => (int) $detail['recovery_verified']];
                if ($kind !== 'stable') {
                    $denied = $request(
                        'POST',
                        $path . '/devices/' . $detail['id'] . '/rotate',
                        $token,
                        $tenant,
                        ['version' => $detail['version'], 'confirm_device_id' => $detail['id']],
                        409
                    );
                    expect($denied['error'] === 'recovery_reconciliation_required', '未核对设备仍能变更凭据');
                    $mqtt('denied', $registration);
                }
            }
            $refused = $request('POST', '/public/devices/register', '', null, ['id' => bin2hex(random_bytes(16)), 'tenant_id' => $tenant,
                'device_id' => bin2hex(random_bytes(16)), 'name' => '旧备份不得复活资格', 'code' => $context['spare_enrollment']['code']], 409);
            expect($refused['error'] === 'recovery_reconciliation_required', '恢复后已停用的注册码重新获得资格');
            $enrollment = $request('GET', '/admin/device-authorizations/' . $context['spare_enrollment']['authorization']['id'], $tokens['platform'], null, null)['data']['authorization'];
            expect($enrollment['status'] === 'unverified' && !$enrollment['recovery_verified'], '隔离的注册资格没有显示核对状态');
            $mqtt('application-denied', $registered, $context['application']);
            $mqtt('roundtrip', $context['devices']['stable'], $context['applications']['stable']);
            $report['old_enrollment_and_application_refused'] = true;
        } else {
            throw new RuntimeException('未定义的物理恢复公开入口阶段');
        }
    } finally {
        $failures = [];
        foreach (['broker' => $broker, 'http' => $server] as $role => $process) {
            if ($process === null) {
                continue;
            }
            try {
                $result = $process->stop(20);
                $log = str_replace([$environment['DB_PASSWORD'], 'Recovery-password-2026'], '<REDACTED>', $result->stdout . $result->stderr);
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
        $report['cleanup_failures'] = $failures;
        expect($failures === [], implode('；', $failures));
    }
    foreach (['connections', 'subscriptions', 'bufferedBytes', 'incomingExchanges', 'outgoingExchanges', 'eventRegistrations',
        'eventTimers', 'pendingCommits', 'closingSessions'] as $resource) {
        expect($report['broker'][$resource] === 0, '备份前Broker仍有活动资源：' . $resource);
    }
    expect((int) $database->query("SELECT COUNT(*) FROM pg_stat_activity WHERE datname=current_database() AND application_name LIKE 'type_mqtt_%'")->fetchColumn() === 0, '备份前Broker后台数据库连接未清理');
    $facts = iotRecoveryFacts($database);
    foreach (['iot_devices', 'iot_device_enrollments', 'iot_device_enrollment_bindings', 'iot_applications', 'iot_application_grants',
        'type_mqtt_sessions', 'type_mqtt_messages', 'type_mqtt_deliveries', 'type_mqtt_retained'] as $table) {
        expect(($facts[$table]['rows'] ?? 0) > 0, '恢复事实仍为空：' . $table);
        $report['required_nonempty'][$table] = $facts[$table]['rows'];
    }
    $report['status'] = 'passed';
}

/** 官方物理备份链与恢复点保留非空协议数据，当前权威清单来自备份之后的真实授权变更。 */
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
        $report['seed'] = 'same-program-install-http-registration-and-tls-raw-communication';
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
    $blockedSources = [$root . '/app/common/bootstrap/Application.php', $root . '/plugin/type-core/src/Application.php', $root . '/vendor/autoload.php', $root . '/config/app.php'];
    foreach ($blockedSources as $path) {
        expect(is_file($path) && is_readable($path) && file_get_contents($path) !== false, '源码隔离探针原文件不存在或控制端不可读');
    }
    expect(successful([...$policy, PHP_BINARY, '-n', '-r', 'foreach(array_slice($argv,1) as $path){if(@file_get_contents($path)!==false)exit(1);} echo "denied";',
        ...$blockedSources], $runtime) === 'denied', '源码隔离没有生效');
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
