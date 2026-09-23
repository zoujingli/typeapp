<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/native-database.php';
require_once __DIR__ . '/postgres-sync.php';
require_once __DIR__ . '/iot-recovery.php';

use Type\Mqtt\Client;
use Type\Mqtt\Message;
use Type\Mqtt\ProtocolError;
use Type\Testing\HttpClient;
use Type\Testing\Process;

if (!function_exists('identityCommand')) {
    function identityCommand(array $command, array $environment): array
    {
        $process = new Process($command, dirname(__DIR__), $environment);
        try {
            $result = $process->wait(30);
            expect($result->successful(), '人员命令失败：' . $result->stderr);
            return json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            $process->stop();
        }
    }
}

function recoveryCertFiles(string $directory): array
{
    expect(mkdir($directory, 0700), '无法创建证书目录');
    $configuration = $directory . '/openssl.cnf';
    file_put_contents($configuration, "[req]\ndistinguished_name=dn\nx509_extensions=ext\n[dn]\n[ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n[server]\nsubjectAltName=IP:127.0.0.1\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n");
    $options = ['config' => $configuration, 'private_key_bits' => 2048, 'digest_alg' => 'sha256', 'x509_extensions' => 'ca'];
    $caKey = openssl_pkey_new($options);
    $caRequest = openssl_csr_new(['commonName' => 'broker-recovery-ca'], $caKey, $options);
    $ca = openssl_csr_sign($caRequest, null, $caKey, 1, $options);
    $serverOptions = $options;
    $serverOptions['x509_extensions'] = 'server';
    $serverKey = openssl_pkey_new($serverOptions);
    $serverRequest = openssl_csr_new(['commonName' => '127.0.0.1'], $serverKey, $serverOptions);
    $server = openssl_csr_sign($serverRequest, $ca, $caKey, 1, $serverOptions);
    expect($ca !== false && $server !== false, '无法生成恢复测试证书');
    $paths = ['ca' => $directory . '/ca.pem', 'server' => $directory . '/server.pem', 'key' => $directory . '/server.key'];
    expect(openssl_x509_export($ca, $caPem) && openssl_x509_export($server, $serverPem)
        && openssl_pkey_export($serverKey, $serverKeyPem, null, $serverOptions)
        && file_put_contents($paths['ca'], $caPem) !== false && file_put_contents($paths['server'], $serverPem) !== false
        && file_put_contents($paths['key'], $serverKeyPem) !== false, '无法写出恢复测试证书');
    chmod($paths['key'], 0600);
    return $paths;
}

function recoveryWaitTls(Process $process, int $port, string $ca): void
{
    $deadline = microtime(true) + 15;
    do {
        expect($process->running(), '恢复节点提前退出：' . $process->stderr());
        $socket = @stream_socket_client('tls://127.0.0.1:' . $port, $errno, $error, 1, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => [
            'cafile' => $ca, 'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => '127.0.0.1',
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ]]));
        if (is_resource($socket)) {
            fclose($socket);
            return;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    expect(false, '恢复节点 TLS 入口未就绪：' . $process->stderr());
}

function recoveryWaitHttp(Process $process, HttpClient $client): void
{
    $deadline = microtime(true) + 15;
    do {
        expect($process->running(), '恢复管理 HTTP 提前退出：' . $process->stderr());
        try {
            if ($client->request('GET', '/readyz')->status === 200) {
                return;
            }
        } catch (RuntimeException) {
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    expect(false, '恢复管理 HTTP 未就绪：' . $process->stderr());
}

function recoveryRun(array $command, array $environment, bool $success = true, int $seconds = 30, string $errorCode = ''): array
{
    $process = new Process($command, dirname(__DIR__), $environment, 1048576);
    try {
        $result = $process->wait($seconds);
        expect(!$result->timedOut && ($success ? $result->successful() : $result->exitCode === 1), '恢复命令结果不符：' . $result->stderr);
        if ($errorCode !== '') {
            expect(!$success && str_contains($result->stderr, $errorCode), '恢复失败没有返回准确稳定码：' . $result->stderr);
        }
        return $success ? json_decode($result->stdout, true, 32, JSON_THROW_ON_ERROR) : ['exit' => $result->exitCode, 'error' => trim($result->stderr)];
    } finally {
        $process->stop();
    }
}

function recoveryWaitRevision(callable $request, string $token, array $revision, string $message): array
{
    $deadline = microtime(true) + 20;
    $current = $revision;
    do {
        $current = $request('GET', '/broker/access/revisions/' . $revision['id'], $token, null, 200)['item'];
        if (($current['status'] ?? '') === 'effective' && ($current['nodes'] ?? []) !== []) {
            return $current;
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    expect(false, $message . '：' . json_encode($current ?? []));
    return [];
}

$root = dirname(__DIR__);
$target = $argv[1] ?? '--php';
$driver = $argv[2] ?? 'pgsql';
expect($driver === 'pgsql', 'Broker 恢复核对验收需要 PostgreSQL 物理备份链');
if ($target === '--php') {
    $command = [PHP_BINARY, '-d', 'memory_limit=512M'];
    if (!extension_loaded('swoole')) {
        $swoole = getenv('TYPE_SWOOLE_MODULE');
        $swoole = is_string($swoole) && $swoole !== '' ? $swoole : rtrim((string) ini_get('extension_dir'), '/') . '/swoole.so';
        expect(is_file($swoole), 'PHP 恢复核对验收需要 TYPE_SWOOLE_MODULE 或 extension_dir 中的 swoole.so');
        array_push($command, '-d', 'extension=' . $swoole);
    }
    $command[] = $root . '/bin/typeapp';
} else {
    $command = nativeCommand($target);
}
$base = $root . '/build/broker-recovery-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700, true), '无法创建恢复核对测试目录');
$noSource = in_array('--no-source', $argv, true);
$browserDist = '';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--browser-dist=')) {
        $browserDist = substr($argument, 15);
    }
}
if ($noSource) {
    expect($target !== '--php' && PHP_OS_FAMILY === 'Darwin', '无源码恢复核对验收使用macOS内核策略及原生产物');
    $runtime = $base . '/runtime';
    (new Type\Build\NativePackage())->create($target, $runtime);
    $built = json_decode(file_get_contents($target . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
    $sourceRoot = realpath($built['identity']['description']['facts']['workspace']);
    expect(is_string($sourceRoot), '无源码验收必须能定位实际编译输入');
    $policy = ['sandbox-exec', '-f', $root . '/tests/fixtures/iot-no-source.sb'];
    foreach (['APP' => $sourceRoot . '/app', 'PLUGIN' => $sourceRoot . '/plugin', 'VENDOR' => $sourceRoot . '/vendor', 'CONFIG' => $sourceRoot . '/config',
        'COMPILER' => dirname($built['runtime-profile']['ini'], 2), 'COMPOSER' => $sourceRoot . '/composer.json'] as $role => $path) {
        array_push($policy, '-D', $role . '=' . $path);
    }
    $command = [...$policy, $runtime . '/run'];
}
$directory = $base . '/physical';
expect(mkdir($directory, 0700), '不能建立物理恢复测试目录');
$archive = $directory . '/wal';
expect(mkdir($archive, 0700), '不能建立独立WAL测试目录');
$tools = NativeDatabase::tools('pgsql', (string) getenv('TYPE_PGSQL_TOOLS'));
foreach (['pg_basebackup', 'pg_combinebackup', 'pg_verifybackup'] as $name) {
    $binary = $tools['root'] . '/bin/' . $name;
    expect(is_executable($binary), '缺少PostgreSQL物理备份工具：' . $name);
}
$environment = getenv();
foreach (array_keys($environment) as $key) {
    if (str_starts_with($key, 'APP_') || str_starts_with($key, 'DB_') || str_starts_with($key, 'IOT_') || str_starts_with($key, 'BROKER_')) {
        unset($environment[$key]);
    }
}
$environment['APP_BASE_PATH'] = $base;
$environment['DB_DRIVER'] = 'pgsql';
$environment['APP_CACHE_ENABLED'] = 'false';
$environment['APP_DEBUG'] = 'true';
$primaryData = $directory . '/primary/data';
$hook = iotRecoveryHook(
    [...$command, 'iot:wal', 'archive', $archive],
    $environment,
    [escapeshellarg(str_replace('%', '%%', $primaryData) . '/%p'), "'%f'"]
);
$checks = 0;
$report = ['status' => 'running', 'scope' => 'broker-recovery', 'native' => $target !== '--php', 'driver' => 'pgsql',
    'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'no_source' => $noSource];
$server = null;
$node = null;
$appServer = null;
$browser = null;
$database = null;
$sync = null;
$recoveredSync = null;
$recovered = null;
$source = null;
$pdo = null;
$restored = null;
$secrets = [];
$certs = recoveryCertFiles($base . '/certs');
$passwordFile = $directory . '/pgpass';
try {
    $database = new NativeDatabase($directory . '/primary', 'pgsql', $tools, [], [
        'archive_mode' => 'on', 'archive_command' => $hook, 'summarize_wal' => 'on', 'wal_summary_keep_time' => '10d']);
    $sync = new PostgresSync($database, $directory . '/standby', $tools, 'broker_recovery_sync');
    $source = $sync->connection();
    $dbEnvironment = array_replace($environment, $database->environment());
    $password = $dbEnvironment['TYPE_PGSQL_PASSWORD'];
    $secrets[] = $password;
    $pass = '127.0.0.1:' . $dbEnvironment['TYPE_PGSQL_PORT'] . ':*:' . $dbEnvironment['TYPE_PGSQL_USER'] . ':' . $password . "\n";
    expect(file_put_contents($passwordFile, $pass) === strlen($pass) && chmod($passwordFile, 0600), '不能创建私有物理备份凭据');
    $dbEnvironment['PGPASSFILE'] = $passwordFile;
    $dbEnvironment['APP_BASE_PATH'] = $base;
    $dbEnvironment['DB_DRIVER'] = 'pgsql';
    $dbEnvironment['DB_HOST'] = $dbEnvironment['TYPE_PGSQL_HOST'];
    $dbEnvironment['DB_PORT'] = $dbEnvironment['TYPE_PGSQL_PORT'];
    $dbEnvironment['DB_DATABASE'] = $dbEnvironment['TYPE_PGSQL_DATABASE'];
    $dbEnvironment['DB_USERNAME'] = $dbEnvironment['TYPE_PGSQL_USER'];
    $dbEnvironment['DB_PASSWORD'] = $dbEnvironment['TYPE_PGSQL_PASSWORD'];
    $dbEnvironment['APP_CACHE_ENABLED'] = 'false';
    $dbEnvironment['APP_DEBUG'] = 'true';
    $dbEnvironment['BROKER_COMMAND'] = json_encode($command, JSON_THROW_ON_ERROR);
    $dbEnvironment['BROKER_STANDBY_NAMES'] = 'broker_recovery_sync';
    $install = new Process([...$command, 'broker:install'], $root, $dbEnvironment);
    try {
        expect($install->wait(30)->successful(), '独立恢复库安装失败：' . $install->stdout() . $install->stderr());
    } finally {
        $install->stop();
    }
    $store = new Process([...$command, 'broker:store-install'], $root, $dbEnvironment);
    try {
        expect($store->wait(30)->successful(), '独立持久存储安装失败：' . $store->stdout() . $store->stderr());
    } finally {
        $store->stop();
    }
    $adminPassword = bin2hex(random_bytes(16));
    $secrets[] = $adminPassword;
    identityCommand([...$command, 'broker:user', 'access-admin', '恢复核对管理员'], $dbEnvironment + ['BROKER_ADMIN_PASSWORD' => $adminPassword]);
    $addresses = [];
    for ($index = 0; $index < 5; $index++) {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorText);
        expect(is_resource($listener), '无法选择恢复核对测试端口');
        $addresses[] = stream_socket_get_name($listener, false);
        fclose($listener);
    }
    $previewOrigin = 'http://' . $addresses[4];
    $dbEnvironment['APP_PORT'] = substr(strrchr($addresses[0], ':'), 1);
    $dbEnvironment['APP_ALLOWED_HOSTS'] = $addresses[0];
    $server = new Process([...$command, 'broker:serve'], $root, $dbEnvironment);
    $client = new HttpClient('http://' . $addresses[0], 12.0);
    recoveryWaitHttp($server, $client);
    $request = static function (string $method, string $path, string $token, ?array $data, int $status, array $extra = []) use ($client, $server, &$checks): array {
        $headers = ['Content-Type' => 'application/json'] + $extra;
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $response = $client->request($method, $path, $headers, $data === null ? '' : json_encode($data === [] ? (object) [] : $data, JSON_THROW_ON_ERROR));
        expect($response->status === $status, $method . ' ' . $path . ' 预期 ' . $status . '，实际 ' . $response->status . ' ' . $response->body
            . ($response->status >= 500 ? ' ' . $server->stdout() . $server->stderr() : ''));
        $checks++;
        return $response->json();
    };
    $auth = $request('POST', '/broker/auth/login', '', ['login' => 'access-admin', 'password' => $adminPassword], 200)['data'];
    $token = $auth['accessToken'];
    $secrets[] = $token;
    $request('GET', '/broker/recovery', '', null, 401);
    $none = $request('GET', '/broker/recovery', $token, null, 200);
    expect(($none['state'] ?? '') === 'none' && ($none['host'] ?? '') === 'broker', '安装后恢复进度应为 none');
    $request('POST', '/broker/recovery', $token, [], 405);
    $mqttPassword = bin2hex(random_bytes(16));
    $secrets[] = $mqttPassword;
    $tlsPort = (int) substr(strrchr($addresses[1], ':'), 1);
    $wssPort = (int) substr(strrchr($addresses[3], ':'), 1);
    $nodeEnvironment = $dbEnvironment + [
        'BROKER_CLIENT_USERNAME' => 'broker-client', 'BROKER_CLIENT_PASSWORD' => $mqttPassword,
        'BROKER_TOPIC_PREFIX' => 'broker-recovery/', 'BROKER_NODE_ID' => 'recovery-node',
        'BROKER_LISTEN' => '127.0.0.1', 'BROKER_PLAINTEXT' => 'false',
        'BROKER_PORT' => (string) $tlsPort, 'BROKER_WSS_PORT' => (string) $wssPort,
        'BROKER_CERTIFICATE' => $certs['server'], 'BROKER_PRIVATE_KEY' => $certs['key'],
        'BROKER_ALLOWED_ORIGINS' => $previewOrigin,
    ];
    $node = new Process([...$command, 'broker:run'], $root, $nodeEnvironment);
    recoveryWaitTls($node, $tlsPort, $certs['ca']);
    $deadline = microtime(true) + 12;
    do {
        expect($node->running(), '恢复节点提前退出：' . $node->stderr());
        $nodes = $request('GET', '/broker/nodes', $token, null, 200);
        if (($nodes['total'] ?? 0) === 1) {
            break;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    expect(($nodes['total'] ?? 0) === 1, '恢复节点未上报');
    $queuedPayload = 'queued-' . bin2hex(random_bytes(8));
    $secrets[] = $queuedPayload;
    $session = new Client('127.0.0.1', $tlsPort, 'recovery-session', 'broker-client', $mqttPassword, $certs['ca'], '127.0.0.1', 30, 86400);
    expect($session->connect(true) === false, '持久会话首次 CONNECT 失败');
    $session->subscribe('broker-recovery/queued', 1);
    $session->close(true);
    $session = null;
    $publisher = new Client('127.0.0.1', $tlsPort, 'recovery-publisher', 'broker-client', $mqttPassword, $certs['ca'], '127.0.0.1');
    expect($publisher->connect(true) === false, '发布者 CONNECT 失败');
    expect($publisher->publish(new Message('broker-recovery/queued', $queuedPayload, '', 1)) === 0, 'QoS1 排队发布失败');
    $publisher->stop();
    $publisher = null;
    $revokedPassword = bin2hex(random_bytes(16));
    $secrets[] = $revokedPassword;
    $access = $request('GET', '/broker/access/principals', $token, null, 200);
    $created = $request('POST', '/broker/access/principals', $token, [
        'name' => '待轮换主体', 'login' => 'broker-revoked', 'password' => $revokedPassword, 'enabled' => 1,
        'expected_version' => $access['current_version'],
        'grants' => [['topic' => 'broker-recovery/', 'publish' => 1, 'subscribe' => 1, 'max_qos' => 1]],
    ], 200)['data'];
    $created = recoveryWaitRevision($request, $token, $created, '待轮换主体未生效');
    $listed = $request('GET', '/broker/access/principals', $token, null, 200);
    $revokedItem = null;
    foreach ($listed['items'] as $item) {
        if (($item['login'] ?? '') === 'broker-revoked') {
            $revokedItem = $item;
            break;
        }
    }
    expect(is_array($revokedItem), '未找到待轮换主体');
    $revokedLive = new Client('127.0.0.1', $tlsPort, 'recovery-revoked', 'broker-revoked', $revokedPassword, $certs['ca'], '127.0.0.1');
    expect($revokedLive->connect(true) === false, '轮换前主体接入失败');
    $revokedLive->stop();
    $revokedLive = null;
    $issued = $request('POST', '/broker/debug', $token, [], 200)['data'];
    expect(is_string($issued['username'] ?? null) && ($issued['recovery_verified'] ?? 0) === 1, '调试凭据签发失败');
    $debugPassword = (string) $issued['password'];
    $secrets[] = $debugPassword;
    $baseCommand = [$tools['root'] . '/bin/pg_basebackup', '-h', '127.0.0.1', '-p', $dbEnvironment['TYPE_PGSQL_PORT'],
        '-U', $dbEnvironment['TYPE_PGSQL_USER'], '-X', 'stream', '-c', 'fast', '--no-password', '--no-clean', '--manifest-checksums=SHA256'];
    nativeDatabaseCommand([...$baseCommand, '-D', $directory . '/full'], $dbEnvironment, $secrets, $directory . '/full.log', 120);
    nativeDatabaseCommand([$tools['root'] . '/bin/pg_verifybackup', $directory . '/full'], $dbEnvironment, $secrets, $directory . '/verify-full.log');
    $report['full_manifest_sha256'] = hash_file('sha256', $directory . '/full/backup_manifest');
    nativeDatabaseCommand(
        [...$baseCommand, '-D', $directory . '/incremental', '--incremental=' . $directory . '/full/backup_manifest'],
        $dbEnvironment,
        $secrets,
        $directory . '/incremental.log',
        120
    );
    $report['incremental_manifest_sha256'] = hash_file('sha256', $directory . '/incremental/backup_manifest');
    $combined = $directory . '/combined';
    nativeDatabaseCommand([$tools['root'] . '/bin/pg_combinebackup', '--manifest-checksums=SHA256', '-o', $combined,
        $directory . '/full', $directory . '/incremental'], $dbEnvironment, $secrets, $directory . '/combine.log', 120);
    nativeDatabaseCommand([$tools['root'] . '/bin/pg_verifybackup', $combined], $dbEnvironment, $secrets, $directory . '/verify-combined.log');
    $restoreLsn = (string) $source->query("SELECT pg_create_restore_point('broker_recovery_target')")->fetchColumn();
    expect($restoreLsn !== '', '不能建立命名恢复点');
    $walName = (string) $source->query('SELECT pg_walfile_name(' . $source->quote($restoreLsn) . '::pg_lsn)')->fetchColumn();
    $rotatedPassword = bin2hex(random_bytes(16));
    $secrets[] = $rotatedPassword;
    $rotated = $request('POST', '/broker/access/principals/' . $revokedItem['id'], $token, [
        'name' => $revokedItem['name'], 'enabled' => 1, 'rotate' => 1, 'password' => $rotatedPassword,
        'expected_version' => $created['version'], 'grants' => $revokedItem['grants'],
    ], 200)['data'];
    recoveryWaitRevision($request, $token, $rotated, '轮换凭据未生效');
    $request('POST', '/broker/debug/revoke', $token, [], 200);
    $serials = $request('GET', '/broker/access/revocations', $token, null, 200);
    $revokedSerial = $request('POST', '/broker/access/revocations', $token, [
        'serial' => '0a1b', 'expected_version' => $serials['current_version'] ?? $rotated['version'],
    ], 200)['data'];
    recoveryWaitRevision($request, $token, $revokedSerial, '平台吊销未生效');
    $snapshotProcess = new Process([...$command, 'broker:recovery', 'snapshot'], $root, $dbEnvironment, 1048576);
    try {
        $snapshotResult = $snapshotProcess->wait(30);
        expect($snapshotResult->successful(), '当前授权清单导出失败：' . $snapshotResult->stderr);
        $authorityBytes = $snapshotResult->stdout;
        $snapshot = json_decode($authorityBytes, true, 32, JSON_THROW_ON_ERROR);
    } finally {
        $snapshotProcess->stop();
    }
    expect(($snapshot['version'] ?? 0) === 1 && is_array($snapshot['records'] ?? null), '当前授权清单无效');
    $authorityFile = $directory . '/current-authority.json';
    expect(file_put_contents($authorityFile, $authorityBytes) === strlen($authorityBytes) && chmod($authorityFile, 0600), '不能保存当前授权清单');
    $authorityDigest = hash('sha256', $authorityBytes);
    $source->query('SELECT pg_switch_wal()')->fetchColumn();
    $deadline = microtime(true) + 90;
    while (!is_file($archive . '/' . $walName . '.sha256') && microtime(true) < $deadline) {
        usleep(100000);
        clearstatcache();
    }
    expect(is_file($archive . '/' . $walName . '.sha256'), '恢复点WAL未在预算内完成归档');
    expect($node->stop(5)->successful(), '备份后节点停止失败');
    $node = null;
    expect($server->stop(5)->successful(), '备份后管理 HTTP 停止失败');
    $server = null;
    $source = null;
    $pdo = null;
    $sync->close();
    $sync = null;
    $database->close();
    $report['source_stopped_before_restore'] = $database->evidence()['owned-server-stopped'];
    $database = null;
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
    $recovered = new Process(
        [$tools['postgres'], '-D', $combined, '-h', '127.0.0.1', '-p', (string) $port, '-k', '',
        '-c', 'archive_mode=off', '-c', 'restore_command=' . $restoreHook,
        '-c', 'recovery_target_name=broker_recovery_target', '-c', 'recovery_target_action=pause', '-c', 'hot_standby=on'],
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
    $pauseEnvironment = array_replace($dbEnvironment, [
        'TYPE_PGSQL_PORT' => (string) $port, 'DB_PORT' => (string) $port,
    ]);
    $pausedBegin = new Process([...$command, 'broker:recovery', 'begin', bin2hex(random_bytes(16)), 'physical-recovery', 'owned-source-stopped'], $root, $pauseEnvironment);
    try {
        $paused = $pausedBegin->wait(20);
        expect(!$paused->successful(), '只读暂停点不能开始恢复核对');
    } finally {
        $pausedBegin->stop();
    }
    $report['isolated_readonly_pause'] = true;
    expect($restored->query('SELECT pg_promote(true, 60)')->fetchColumn() === true, '受控恢复主库没有在旧源退出后提升');
    $restored = null;
    $newEnvironment = array_replace($dbEnvironment, [
        'TYPE_PGSQL_PORT' => (string) $port, 'DB_PORT' => (string) $port, 'BROKER_STANDBY_NAMES' => 'broker_recovery_reopened',
    ]);
    $recoveredSync = new PostgresSync($newEnvironment, $directory . '/recovered-standby', $tools, 'broker_recovery_reopened');
    $restored = $recoveredSync->connection();
    $id = bin2hex(random_bytes(16));
    $state = recoveryRun([...$command, 'broker:recovery', 'begin', $id, 'physical-recovery', 'owned-source-primary-standby-and-business-processes-stopped'], $newEnvironment);
    foreach (['broker:serve', 'broker:run'] as $role) {
        recoveryRun([...$command, $role], $role === 'broker:run' ? array_replace($nodeEnvironment, ['DB_PORT' => (string) $port, 'TYPE_PGSQL_PORT' => (string) $port, 'BROKER_STANDBY_NAMES' => 'broker_recovery_reopened']) : $newEnvironment, false, 30, 'recovery_isolated');
    }
    $steps = ['isolating' => 0, 'reviewing' => 0, 'restoring' => 0];
    foreach (['isolating' => 'isolate', 'reviewing' => 'review', 'restoring' => 'restore'] as $stage => $action) {
        for ($batch = 0; $batch < 100 && $state['state'] === $stage; $batch++) {
            $arguments = $action === 'review' ? [$authorityFile, $authorityDigest, $state['cursor'] === '' ? '0' : $state['cursor']] : [];
            $state = recoveryRun([...$command, 'broker:recovery', $action, $id, ...$arguments], $newEnvironment);
            $steps[$stage]++;
        }
        expect($state['state'] !== $stage, '物理恢复核对未在有界批次内完成：' . $stage);
    }
    expect($state['state'] === 'ready' && ($state['host'] ?? '') === 'broker', '真实物理恢复核对没有允许重新开放');
    expect((int) $restored->query('SELECT count(*) FROM broker_sessions')->fetchColumn() === 0, '物理恢复后旧管理登录会话复活');
    expect((int) $restored->query("SELECT count(*) FROM broker_access_platform_serials WHERE serial = '0a1b'")->fetchColumn() >= 1
        || (int) $restored->query('SELECT count(*) FROM broker_access_platform_serials')->fetchColumn() >= 1, '当前平台吊销序列未并入恢复库');
    $report['authorization_reconciliation'] = ['state' => $state, 'steps' => $steps,
        'snapshot_sha256' => $authorityDigest, 'startup_denied_until_reviewed' => true, 'old_sessions_removed' => true];
    $server = new Process([...$command, 'broker:serve'], $root, $newEnvironment);
    $client = new HttpClient('http://' . $addresses[0], 12.0);
    recoveryWaitHttp($server, $client);
    $request = static function (string $method, string $path, string $token, ?array $data, int $status, array $extra = []) use ($client, $server, &$checks): array {
        $headers = ['Content-Type' => 'application/json'] + $extra;
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $response = $client->request($method, $path, $headers, $data === null ? '' : json_encode($data === [] ? (object) [] : $data, JSON_THROW_ON_ERROR));
        expect($response->status === $status, $method . ' ' . $path . ' 预期 ' . $status . '，实际 ' . $response->status . ' ' . $response->body
            . ($response->status >= 500 ? ' ' . $server->stdout() . $server->stderr() : ''));
        $checks++;
        return $response->json();
    };
    $auth = $request('POST', '/broker/auth/login', '', ['login' => 'access-admin', 'password' => $adminPassword], 200)['data'];
    $token = $auth['accessToken'];
    $secrets[] = $token;
    $ready = $request('GET', '/broker/recovery', $token, null, 200);
    expect(($ready['state'] ?? '') === 'ready' && ($ready['host'] ?? '') === 'broker', '重新开放后恢复进度应为 ready');
    expect(($request('GET', '/broker/recovery', $token, null, 200)['state'] ?? '') === 'ready', '恢复进度查询不能因重复审计失败');
    $request('POST', '/broker/recovery', $token, [], 405);
    $reopened = $request('GET', '/broker/access/principals', $token, null, 200);
    $keepVerified = false;
    $revokedIsolated = false;
    foreach ($reopened['items'] as $item) {
        if (($item['login'] ?? '') === 'broker-client') {
            $keepVerified = (int) ($item['recovery_verified'] ?? 0) === 1;
        }
        if (($item['login'] ?? '') === 'broker-revoked') {
            $revokedIsolated = (int) ($item['recovery_verified'] ?? 1) === 0;
        }
    }
    expect($keepVerified && $revokedIsolated, '合法主体应已核对，轮换后的旧身份应保持隔离');
    $nodeEnvironment['DB_PORT'] = (string) $port;
    $nodeEnvironment['TYPE_PGSQL_PORT'] = (string) $port;
    $nodeEnvironment['BROKER_STANDBY_NAMES'] = 'broker_recovery_reopened';
    $node = new Process([...$command, 'broker:run'], $root, $nodeEnvironment);
    recoveryWaitTls($node, $tlsPort, $certs['ca']);
    $deadline = microtime(true) + 30;
    do {
        expect($node->running(), '重新开放节点提前退出：' . $node->stderr());
        $nodes = $request('GET', '/broker/nodes', $token, null, 200);
        if (($nodes['total'] ?? 0) === 1) {
            break;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    expect(($nodes['total'] ?? 0) === 1, '重新开放节点未上报：' . json_encode($nodes ?? []) . $node->stdout() . $node->stderr());
    $restoredSession = new Client('127.0.0.1', $tlsPort, 'recovery-session', 'broker-client', $mqttPassword, $certs['ca'], '127.0.0.1', 30, 86400);
    expect($restoredSession->connect(false) === true, '核对后合法会话未恢复');
    $queued = $restoredSession->receive(15.0);
    expect($queued !== null && $queued['message']->payload === $queuedPayload, '核对后未交付排队的 QoS1 消息');
    $restoredSession->acknowledge($queued['receipt']);
    $restoredSession->stop();
    $restoredSession = null;
    try {
        $denied = new Client('127.0.0.1', $tlsPort, 'recovery-revoked-after', 'broker-revoked', $revokedPassword, $certs['ca'], '127.0.0.1');
        $denied->connect(true);
        expect(false, '轮换前口令在恢复核对后仍被接入');
    } catch (ProtocolError $error) {
        expect(in_array($error->reason, [0x87, 0x86, 0x84, 0x8c], true), '隔离后原因码异常：' . $error->reason);
    }
    try {
        $debugDenied = new Client('127.0.0.1', $tlsPort, (string) $issued['client_id'], (string) $issued['username'], $debugPassword, $certs['ca'], '127.0.0.1', 30, 0);
        $debugDenied->connect(true);
        expect(false, '已撤销调试凭据在恢复核对后仍被接入');
    } catch (ProtocolError $error) {
        expect(in_array($error->reason, [0x87, 0x86, 0x84, 0x8c, 0x82], true), '调试隔离原因码异常：' . $error->reason);
    }
    $debugPage = $request('GET', '/broker/debug', $token, null, 200);
    $debugSubjects = $ready['subjects']['broker_debug'] ?? ['total' => 0, 'approved' => 0];
    expect(($debugSubjects['total'] ?? 0) > ($debugSubjects['approved'] ?? 0)
        || (is_array($debugPage['credential'] ?? null) && (int) ($debugPage['credential']['recovery_verified'] ?? 1) === 0), '未核对调试凭据应保持隔离');
    $appBase = $base . '/app';
    expect(mkdir($appBase, 0700), '无法创建双端恢复核对测试目录');
    $appEnvironment = $dbEnvironment;
    $appEnvironment['APP_BASE_PATH'] = $appBase;
    $appEnvironment['APP_PORT'] = substr(strrchr($addresses[2], ':'), 1);
    $appEnvironment['APP_ALLOWED_HOSTS'] = $addresses[2];
    unset($appEnvironment['BROKER_CLIENT_USERNAME'], $appEnvironment['BROKER_CLIENT_PASSWORD'], $appEnvironment['BROKER_TOPIC_PREFIX'], $appEnvironment['BROKER_NODE_ID'], $appEnvironment['BROKER_PLAINTEXT'], $appEnvironment['BROKER_PORT'], $appEnvironment['BROKER_COMMAND'], $appEnvironment['BROKER_STANDBY_NAMES'], $appEnvironment['BROKER_ALLOWED_ORIGINS'], $appEnvironment['BROKER_WSS_PORT']);
    $appEnvironment['DB_DRIVER'] = 'sqlite';
    $appEnvironment['DB_SQLITE_FILE'] = 'app.sqlite';
    unset($appEnvironment['DB_HOST'], $appEnvironment['DB_PORT'], $appEnvironment['DB_DATABASE'], $appEnvironment['DB_USERNAME'], $appEnvironment['DB_PASSWORD']);
    $appPassword = bin2hex(random_bytes(16));
    $secrets[] = $appPassword;
    $appInstall = new Process([...$command, 'app:install', 'platform-admin', '平台管理员', 'customer-admin', '客户管理员', '恢复核对租户'], $root, $appEnvironment + [
        'APP_ADMIN_PASSWORD' => $appPassword, 'APP_CUSTOMER_PASSWORD' => $appPassword . '-customer',
    ]);
    try {
        expect($appInstall->wait(45)->successful(), '双端恢复核对安装失败：' . $appInstall->stderr());
    } finally {
        $appInstall->stop();
    }
    $appServer = new Process([...$command, 'serve'], $root, $appEnvironment);
    $appClient = new HttpClient('http://' . $addresses[2], 8.0);
    recoveryWaitHttp($appServer, $appClient);
    $appRequest = static function (string $method, string $path, string $token, array $headers, ?array $data, int $status) use ($appClient, $appServer, &$checks): array {
        $response = $appClient->request($method, $path, $headers + ($token === '' ? [] : ['Authorization' => 'Bearer ' . $token]) + ['Content-Type' => 'application/json'], $data === null ? '' : json_encode($data === [] ? (object) [] : $data, JSON_THROW_ON_ERROR));
        expect($response->status === $status, '双端 ' . $method . ' ' . $path . ' 预期 ' . $status . '，实际 ' . $response->status . ' ' . $response->body
            . ($response->status >= 500 ? ' ' . $appServer->stdout() . $appServer->stderr() : ''));
        $checks++;
        return $response->json();
    };
    $platformToken = $appRequest('POST', '/admin/auth/login', '', [], ['login' => 'platform-admin', 'password' => $appPassword], 200)['data']['accessToken'];
    $customerToken = $appRequest('POST', '/customer/auth/login', '', [], ['login' => 'customer-admin', 'password' => $appPassword . '-customer'], 200)['data']['accessToken'];
    $secrets[] = $platformToken;
    $secrets[] = $customerToken;
    $me = $appRequest('GET', '/customer/auth/me', $customerToken, [], null, 200)['data'];
    $tenantId = $me['tenant_id'];
    $tenantHeaders = ['X-Tenant-Id' => $tenantId];
    $tenantRecovery = $appRequest('GET', '/customer/tenants/' . $tenantId . '/broker/recovery', $customerToken, $tenantHeaders, null, 200);
    expect(($tenantRecovery['host'] ?? '') === 'app', '租户恢复查询失败');
    $appRequest('POST', '/customer/tenants/' . $tenantId . '/broker/recovery', $customerToken, $tenantHeaders, [], 405);
    $platformRecovery = $appRequest('GET', '/admin/broker/recovery', $platformToken, [], null, 200);
    expect(($platformRecovery['state'] ?? '') === 'none' || ($platformRecovery['state'] ?? '') === 'ready', '平台恢复查询失败');
    $appRequest('POST', '/admin/broker/recovery', $platformToken, [], [], 405);
    $appRequest('GET', '/customer/tenants/' . $tenantId . '/broker/recovery', $customerToken, ['X-Tenant-Id' => bin2hex(random_bytes(16))], null, 403);
    $report['http_checks'] = $checks;
    if ($browserDist !== '') {
        expect(is_file($browserDist . '/index.html'), '浏览器验收需要本次隔离前端产物');
        $browser = new Process(['node', $root . '/tests/broker-recovery-browser.mjs', $base, $browserDist, 'http://' . $addresses[0], 'http://' . $addresses[2], $previewOrigin], $root, array_replace(getenv(), [
            'BROKER_BROWSER_PASSWORD' => $adminPassword,
            'APP_BROWSER_PASSWORD' => $appPassword,
            'APP_BROWSER_CUSTOMER_PASSWORD' => $appPassword . '-customer',
            'APP_BROWSER_TENANT' => $tenantId,
        ]));
        $browserResult = $browser->wait(180);
        file_put_contents($base . '/browser.log', str_replace($secrets, '<REDACTED>', $browserResult->stdout . $browserResult->stderr));
        expect($browserResult->successful(), '恢复核对浏览器失败，见browser-recovery-report.json');
        $report['browser'] = json_decode(file_get_contents($base . '/browser-recovery-report.json'), true, 32, JSON_THROW_ON_ERROR);
        expect(($report['browser']['status'] ?? '') === 'passed', '恢复核对浏览器报告未通过');
    }
    $report['status'] = 'passed';
} finally {
    $restored = null;
    $source = null;
    $pdo = null;
    foreach (['browser' => $browser, 'node' => $node, 'app' => $appServer, 'http' => $server] as $role => $process) {
        try {
            $process?->stop(5.0);
            $report['cleanup'][$role] = $process === null || !$process->running();
        } catch (Throwable $failure) {
            $report['cleanup'][$role] = false;
            $report['cleanup_errors'][] = $role . ':' . $failure->getMessage();
        }
    }
    foreach (['reopened' => $recoveredSync, 'standby' => $sync] as $role => $owner) {
        try {
            $owner?->close();
            $report['cleanup'][$role] = true;
        } catch (Throwable $failure) {
            $report['cleanup'][$role] = false;
            $report['cleanup_errors'][] = $role . ':' . $failure->getMessage();
        }
    }
    if ($recovered !== null) {
        try {
            $result = $recovered->stop(20);
            $report['cleanup']['recovered'] = $result->successful() || !$recovered->running();
        } catch (Throwable $failure) {
            $report['cleanup']['recovered'] = false;
            $report['cleanup_errors'][] = 'recovered:' . $failure->getMessage();
        }
    }
    try {
        $database?->close();
        $report['cleanup']['primary'] = true;
    } catch (Throwable $failure) {
        $report['cleanup']['primary'] = false;
        $report['cleanup_errors'][] = 'primary:' . $failure->getMessage();
    }
    if (is_file($passwordFile)) {
        unlink($passwordFile);
    }
    if (($report['status'] ?? '') !== 'passed') {
        $report['status'] = 'failed';
    }
    $report['http_checks'] = $checks;
    $report['capacity_claim'] = false;
    $report['independent_failure_domain'] = false;
    $report['seven_day_window'] = 'not-verified';
    file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
expect(($report['status'] ?? '') === 'passed', 'Broker 恢复核对验收未通过，见 ' . $base . '/verification.json');
echo 'Broker 恢复核对通过：' . $base . "/verification.json\n";
exit(0);
