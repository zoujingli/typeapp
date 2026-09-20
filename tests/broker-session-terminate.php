<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/native-database.php';
require_once __DIR__ . '/postgres-sync.php';

use Type\Mqtt\Client;
use Type\Mqtt\Message;
use Type\Mqtt\ProtocolError;
use Type\Testing\HttpClient;
use Type\Testing\Process;

if (!function_exists('identityCommand')) {
    /** 通过已公开命令写入隔离库中的管理员。 */
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

/**
 * 独立管理端预览并明确终止指定持久会话；按 session_id 与代次匹配，发送 MQTT 5 0x98 后删除该会话。
 * 需要 PostgreSQL 同步存储；SQLite QoS0 不能证明持久终止。
 */

$root = dirname(__DIR__);
$target = $argv[1] ?? '--php';
$driver = $argv[2] ?? 'pgsql';
expect($driver === 'pgsql', '会话终止验收需要 PostgreSQL 同步存储');
if ($target === '--php') {
    $swoole = getenv('TYPE_SWOOLE_MODULE');
    $swoole = is_string($swoole) && $swoole !== '' ? $swoole : rtrim((string) ini_get('extension_dir'), '/') . '/swoole.so';
    expect(is_file($swoole), 'PHP 会话终止验收需要 TYPE_SWOOLE_MODULE 或 extension_dir 中的 swoole.so');
    $command = [PHP_BINARY, '-d', 'extension=' . $swoole, $root . '/bin/typeapp'];
} else {
    $command = nativeCommand($target);
}
$base = $root . '/build/broker-session-terminate-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700, true), '无法创建会话终止测试目录');
$noSource = in_array('--no-source', $argv, true);
$browserDist = '';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--browser-dist=')) {
        $browserDist = substr($argument, 15);
    }
}
if ($noSource) {
    expect($target !== '--php' && PHP_OS_FAMILY === 'Darwin', '无源码会话终止验收使用macOS内核策略及原生产物');
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
$databaseTools = NativeDatabase::tools('pgsql', (string) getenv('TYPE_PGSQL_TOOLS'));
$fixtureDatabase = new NativeDatabase($base . '/primary', 'pgsql', $databaseTools);
$fixtureSync = new PostgresSync($fixtureDatabase, $base . '/standby', $databaseTools, 'broker_session_sync');
$environment = getenv();
foreach (array_keys($environment) as $key) {
    if (str_starts_with($key, 'APP_') || str_starts_with($key, 'DB_') || str_starts_with($key, 'IOT_') || str_starts_with($key, 'BROKER_')) {
        unset($environment[$key]);
    }
}
foreach ($fixtureDatabase->environment() as $key => $value) {
    if (str_starts_with($key, 'TYPE_PGSQL_')) {
        $environment[$key] = $value;
        putenv($key . '=' . $value);
    }
}
$environment['APP_BASE_PATH'] = $base;
$environment['DB_DRIVER'] = 'pgsql';
$environment['DB_HOST'] = $environment['TYPE_PGSQL_HOST'];
$environment['DB_PORT'] = $environment['TYPE_PGSQL_PORT'];
$environment['DB_DATABASE'] = $environment['TYPE_PGSQL_DATABASE'];
$environment['DB_USERNAME'] = $environment['TYPE_PGSQL_USER'];
$environment['DB_PASSWORD'] = $environment['TYPE_PGSQL_PASSWORD'];
$environment['APP_CACHE_ENABLED'] = 'false';
$environment['APP_DEBUG'] = 'true';
$environment['BROKER_COMMAND'] = json_encode($command, JSON_THROW_ON_ERROR);
$environment['BROKER_STANDBY_NAMES'] = 'broker_session_sync';
$checks = 0;
$report = ['status' => 'running', 'scope' => 'broker-session-terminate', 'native' => $target !== '--php', 'driver' => 'pgsql',
    'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'no_source' => $noSource];
$server = null;
$node = null;
$keep = null;
$other = null;
$publisher = null;
$offline = null;
$stale = null;
$appServer = null;
$browser = null;
$secrets = [];
try {
    $install = new Process([...$command, 'broker:install'], $root, $environment);
    try {
        expect($install->wait(30)->successful(), '独立终止库安装失败：' . $install->stdout() . $install->stderr());
    } finally {
        $install->stop();
    }
    $store = new Process([...$command, 'broker:store-install'], $root, $environment);
    try {
        expect($store->wait(30)->successful(), '独立持久存储安装失败：' . $store->stdout() . $store->stderr());
    } finally {
        $store->stop();
    }
    $password = bin2hex(random_bytes(16));
    $secrets[] = $password;
    identityCommand([...$command, 'broker:user', 'access-admin', '终止管理员'], $environment + ['BROKER_ADMIN_PASSWORD' => $password]);
    $addresses = [];
    for ($index = 0; $index < 3; $index++) {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorText);
        expect(is_resource($listener), '无法选择终止测试端口');
        $addresses[] = stream_socket_get_name($listener, false);
        fclose($listener);
    }
    $environment['APP_PORT'] = substr(strrchr($addresses[0], ':'), 1);
    $environment['APP_ALLOWED_HOSTS'] = $addresses[0];
    $server = new Process([...$command, 'broker:serve'], $root, $environment);
    $client = new HttpClient('http://' . $addresses[0], 12.0);
    $deadline = microtime(true) + 10;
    do {
        expect($server->running(), '独立终止管理提前退出：' . $server->stderr());
        try {
            if ($client->request('GET', '/readyz')->status === 200) {
                break;
            }
        } catch (RuntimeException) {
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
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
    $plain = static function (string $method, string $path, string $token, string $body, int $status) use ($client, $server, &$checks): array {
        $headers = ['Authorization' => 'Bearer ' . $token];
        if ($body !== '') {
            $headers['Content-Type'] = 'text/plain';
        }
        $response = $client->request($method, $path, $headers, $body);
        expect($response->status === $status, $method . ' ' . $path . ' 预期 ' . $status . '，实际 ' . $response->status . ' ' . $response->body
            . ($response->status >= 500 ? ' ' . $server->stdout() . $server->stderr() : ''));
        $checks++;
        return $response->json();
    };
    $auth = $request('POST', '/broker/auth/login', '', ['login' => 'access-admin', 'password' => $password], 200)['data'];
    $token = $auth['accessToken'];
    $secrets[] = $token;
    $mqttPassword = bin2hex(random_bytes(16));
    $secrets[] = $mqttPassword;
    $nodeEnvironment = $environment + [
        'BROKER_CLIENT_USERNAME' => 'broker-client', 'BROKER_CLIENT_PASSWORD' => $mqttPassword,
        'BROKER_TOPIC_PREFIX' => 'broker-session/', 'BROKER_NODE_ID' => 'terminate-node',
        'BROKER_PLAINTEXT' => 'true',
        'BROKER_PORT' => substr(strrchr($addresses[1], ':'), 1),
    ];
    $node = new Process([...$command, 'broker:run'], $root, $nodeEnvironment);
    $deadline = microtime(true) + 12;
    do {
        expect($node->running(), '终止节点提前退出：' . $node->stderr());
        $nodes = $request('GET', '/broker/nodes', $token, null, 200);
        if (($nodes['total'] ?? 0) === 1) {
            break;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    expect(($nodes['total'] ?? 0) === 1, '终止节点未上报');
    $keep = new Client('127.0.0.1', (int) $nodeEnvironment['BROKER_PORT'], 'session-keep', 'broker-client', $mqttPassword, sessionExpiry: 86400, allowPlaintext: true);
    $other = new Client('127.0.0.1', (int) $nodeEnvironment['BROKER_PORT'], 'session-other', 'broker-client', $mqttPassword, sessionExpiry: 86400, allowPlaintext: true);
    expect(!$keep->connect(true), '目标会话不应报告已恢复');
    expect($keep->subscribe('broker-session/keep') === 1, '目标会话应获得 QoS1 订阅');
    expect(!$other->connect(true), '对照会话不应报告已恢复');
    expect($other->subscribe('broker-session/other') === 1, '对照会话应获得 QoS1 订阅');
    $publisher = new Client('127.0.0.1', (int) $nodeEnvironment['BROKER_PORT'], 'session-publisher', 'broker-client', $mqttPassword, sessionExpiry: 0, allowPlaintext: true);
    $publisher->connect(true);
    expect($publisher->publish(new Message('broker-session/keep', 'queued-keep', qos: 1)) === 0, '目标积压发布未获 PUBACK');
    $held = $keep->receive(5.0);
    expect($held !== null && $held['receipt'] !== '', '目标会话未收到待确认积压');
    $sessions = ['items' => []];
    $deadline = microtime(true) + 10;
    do {
        $sessions = $request('GET', '/broker/resources/sessions?client_id=session-keep&limit=20', $token, null, 200);
        if ($sessions['items'] !== []) {
            break;
        }
        try {
            $keep->receive(0.05);
            $other->receive(0.05);
        } catch (ProtocolError) {
            throw new RuntimeException('目标会话在管理终止前被关闭');
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    expect($sessions['items'] !== [] && $sessions['items'][0]['client_id'] === 'session-keep', '持久会话未进入资源查询');
    $item = $sessions['items'][0];
    $preview = $request('GET', '/broker/resources/sessions/' . $item['id'] . '/termination-preview', $token, null, 200);
    expect($preview['session_id'] === $item['id'] && (int) $preview['session_generation'] === (int) $item['session_generation']
        && $preview['counts']['subscriptions'] >= 1 && ($preview['impact']['effect'] ?? '') === 'terminate_session'
        && ($preview['impact']['other_sessions_unaffected'] ?? false) === true
        && ((int) ($preview['counts']['pending_qos1'] ?? 0) >= 1 || (int) ($preview['counts']['pending_messages'] ?? 0) >= 1), '终止预览未反映真实订阅、积压或精确目标');
    $encodedPreview = json_encode($preview, JSON_THROW_ON_ERROR);
    expect(!str_contains($encodedPreview, $mqttPassword) && !str_contains($encodedPreview, '"payload"'), '终止预览包含秘密或载荷');
    $body = ['session_generation' => (int) $item['session_generation'], 'node_id' => $item['node_id'], 'confirmed' => true];
    $request('POST', '/broker/resources/sessions/' . $item['id'] . '/terminate', '', $body, 401);
    $request('POST', '/broker/resources/sessions/' . $item['id'] . '/terminate', $token, $body, 403, ['X-Tenant-Id' => bin2hex(random_bytes(16))]);
    $plain('POST', '/broker/resources/sessions/' . $item['id'] . '/terminate', $token, '{}', 415);
    $request('POST', '/broker/resources/sessions/' . $item['id'] . '/terminate', $token, $body + ['client_id' => 'session-keep'], 422);
    $request('POST', '/broker/resources/sessions/' . $item['id'] . '/terminate', $token, [
        'session_generation' => (int) $item['session_generation'], 'node_id' => $item['node_id'],
    ], 422);
    $request('POST', '/broker/resources/sessions/' . str_repeat('0', 32) . '/terminate', $token, $body, 404);
    $request('POST', '/broker/resources/sessions/' . $item['id'] . '/terminate', $token, [
        'session_generation' => (int) $item['session_generation'] + 1, 'node_id' => $item['node_id'], 'confirmed' => true,
    ], 409);
    $operationId = bin2hex(random_bytes(16));
    $accepted = $request('POST', '/broker/resources/sessions/' . $item['id'] . '/terminate', $token, $body + ['operation_id' => $operationId], 200);
    expect($accepted['operation_id'] === $operationId && $accepted['outcome'] === 'pending' && $accepted['kind'] === 'session_terminate'
        && $accepted['stage'] === 'executing', '终止受理被当成已完成');
    $repeat = $request('POST', '/broker/resources/sessions/' . $item['id'] . '/terminate', $token, $body + ['operation_id' => $operationId], 200);
    expect($repeat['operation_id'] === $operationId && in_array($repeat['outcome'], ['pending', 'terminated'], true), '同标识重试改变了冻结目标');
    $request('GET', '/broker/operations/' . $operationId, '', null, 401);
    $request('GET', '/broker/operations/' . str_repeat('f', 32), $token, null, 404);
    $status = $accepted;
    $disconnected = false;
    $deadline = microtime(true) + 12;
    do {
        $status = $request('GET', '/broker/operations/' . $operationId, $token, null, 200);
        if (!$disconnected) {
            try {
                $keep->receive(0.15);
            } catch (ProtocolError $failure) {
                expect($failure->reason === 0x98, '管理终止不是 MQTT 5 0x98，实际 ' . $failure->reason);
                $disconnected = true;
            }
        }
        if ($status['stage'] === 'completed' && $disconnected) {
            break;
        }
        usleep(40000);
    } while (microtime(true) < $deadline);
    expect($disconnected, '健康节点未向目标会话发送管理断开');
    expect(
        $status['stage'] === 'completed' && $status['result'] === 'success' && $status['outcome'] === 'terminated',
        '节点执行后终止仍待处理：' . json_encode($status)
    );
    $done = $request('POST', '/broker/resources/sessions/' . $item['id'] . '/terminate', $token, $body + ['operation_id' => $operationId], 200);
    expect($done['operation_id'] === $operationId && $done['outcome'] === 'terminated', '完成后重试改变了冻结结果');
    $request('GET', '/broker/resources/sessions/' . $item['id'], $token, null, 404);
    $request('GET', '/broker/resources/sessions/' . $item['id'] . '/termination-preview', $token, null, 404);
    try {
        $keep->stop();
    } catch (Throwable) {
    }
    $keep = new Client('127.0.0.1', (int) $nodeEnvironment['BROKER_PORT'], 'session-keep', 'broker-client', $mqttPassword, sessionExpiry: 86400, allowPlaintext: true);
    expect(!$keep->connect(false), '终止后旧会话仍被恢复');
    $others = $request('GET', '/broker/resources/sessions?client_id=session-other&limit=20', $token, null, 200);
    expect($others['items'] !== [] && $others['items'][0]['client_id'] === 'session-other', '终止误伤了其他持久会话');
    $offline = new Client('127.0.0.1', (int) $nodeEnvironment['BROKER_PORT'], 'session-offline', 'broker-client', $mqttPassword, sessionExpiry: 86400, allowPlaintext: true);
    expect(!$offline->connect(true), '离线目标不应报告已恢复');
    expect($offline->subscribe('broker-session/offline') === 1, '离线目标应获得 QoS1 订阅');
    $offlineSessions = ['items' => []];
    $deadline = microtime(true) + 10;
    do {
        $offlineSessions = $request('GET', '/broker/resources/sessions?client_id=session-offline&limit=20', $token, null, 200);
        if ($offlineSessions['items'] !== []) {
            break;
        }
        try {
            $offline->receive(0.05);
        } catch (ProtocolError) {
            throw new RuntimeException('离线目标在主动断开前被关闭');
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    expect($offlineSessions['items'] !== [] && $offlineSessions['items'][0]['client_id'] === 'session-offline', '离线目标会话未进入资源查询');
    $offlineItem = $offlineSessions['items'][0];
    $offline->close(true);
    $offlineBody = ['session_generation' => (int) $offlineItem['session_generation'], 'node_id' => $offlineItem['node_id'], 'confirmed' => true];
    $offlineOperation = bin2hex(random_bytes(16));
    $offlineAccepted = $request('POST', '/broker/resources/sessions/' . $offlineItem['id'] . '/terminate', $token, $offlineBody + ['operation_id' => $offlineOperation], 200);
    expect($offlineAccepted['kind'] === 'session_terminate' && $offlineAccepted['outcome'] === 'pending', '离线终止受理被当成已完成');
    $offlineStatus = $offlineAccepted;
    $deadline = microtime(true) + 12;
    do {
        $offlineStatus = $request('GET', '/broker/operations/' . $offlineOperation, $token, null, 200);
        if ($offlineStatus['stage'] === 'completed') {
            break;
        }
        usleep(40000);
    } while (microtime(true) < $deadline);
    expect(
        $offlineStatus['stage'] === 'completed' && $offlineStatus['result'] === 'success' && $offlineStatus['outcome'] === 'terminated',
        '离线会话终止未完成：' . json_encode($offlineStatus)
    );
    $request('GET', '/broker/resources/sessions/' . $offlineItem['id'], $token, null, 404);
    $stale = new Client('127.0.0.1', (int) $nodeEnvironment['BROKER_PORT'], 'session-stale', 'broker-client', $mqttPassword, sessionExpiry: 86400, allowPlaintext: true);
    expect(!$stale->connect(true), '代次对照不应报告已恢复');
    expect($stale->subscribe('broker-session/stale') === 1, '代次对照应获得 QoS1 订阅');
    $staleSessions = ['items' => []];
    $deadline = microtime(true) + 10;
    do {
        $staleSessions = $request('GET', '/broker/resources/sessions?client_id=session-stale&limit=20', $token, null, 200);
        if ($staleSessions['items'] !== []) {
            break;
        }
        try {
            $stale->receive(0.05);
        } catch (ProtocolError) {
            throw new RuntimeException('代次对照在重连前被关闭');
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    expect($staleSessions['items'] !== [] && $staleSessions['items'][0]['client_id'] === 'session-stale', '代次对照会话未进入资源查询');
    $staleItem = $staleSessions['items'][0];
    $oldGeneration = (int) $staleItem['session_generation'];
    $stale->close(true);
    $stale = new Client('127.0.0.1', (int) $nodeEnvironment['BROKER_PORT'], 'session-stale', 'broker-client', $mqttPassword, sessionExpiry: 86400, allowPlaintext: true);
    expect($stale->connect(false), '预览后重连应恢复仍有效会话');
    $deadline = microtime(true) + 10;
    do {
        $staleSessions = $request('GET', '/broker/resources/sessions?client_id=session-stale&limit=20', $token, null, 200);
        if ($staleSessions['items'] !== [] && (int) $staleSessions['items'][0]['session_generation'] !== $oldGeneration) {
            break;
        }
        try {
            $stale->receive(0.05);
        } catch (ProtocolError) {
            throw new RuntimeException('新代次会话在管理终止前被关闭');
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    expect($staleSessions['items'] !== [] && $staleSessions['items'][0]['id'] === $staleItem['id']
        && (int) $staleSessions['items'][0]['session_generation'] !== $oldGeneration, '重连未形成新的会话代次');
    $request('POST', '/broker/resources/sessions/' . $staleItem['id'] . '/terminate', $token, [
        'session_generation' => $oldGeneration, 'node_id' => $staleItem['node_id'], 'confirmed' => true,
    ], 409);
    $request('GET', '/broker/resources/sessions/' . $staleItem['id'], $token, null, 200);
    $audit = $request('GET', '/broker/audit?action=broker.session.terminate&operation_id=' . $operationId, $token, null, 200);
    expect($audit['items'] !== [] && $audit['items'][0]['operation_id'] === $operationId, '终止未留下可对账审计');
    $encoded = json_encode($audit, JSON_THROW_ON_ERROR);
    expect(!str_contains($encoded, $mqttPassword) && !str_contains($encoded, $password), '终止审计包含秘密');
    $detail = $request('GET', '/broker/audit/' . $audit['items'][0]['id'], $token, null, 200)['item'];
    expect(($detail['operation']['action'] ?? '') === 'broker.session.terminate'
        && ($detail['operation']['impact']['effect'] ?? '') === 'terminate_session', '终止审计丢失精确目标');
    $appBase = $base . '/app';
    expect(mkdir($appBase, 0700), '无法创建双端终止测试目录');
    $appEnvironment = $environment;
    $appEnvironment['APP_BASE_PATH'] = $appBase;
    $appEnvironment['APP_PORT'] = substr(strrchr($addresses[2], ':'), 1);
    $appEnvironment['APP_ALLOWED_HOSTS'] = $addresses[2];
    unset($appEnvironment['BROKER_CLIENT_USERNAME'], $appEnvironment['BROKER_CLIENT_PASSWORD'], $appEnvironment['BROKER_TOPIC_PREFIX'], $appEnvironment['BROKER_NODE_ID'], $appEnvironment['BROKER_PLAINTEXT'], $appEnvironment['BROKER_PORT'], $appEnvironment['BROKER_COMMAND'], $appEnvironment['BROKER_STANDBY_NAMES']);
    $appEnvironment['DB_DRIVER'] = 'sqlite';
    $appEnvironment['DB_SQLITE_FILE'] = 'app.sqlite';
    unset($appEnvironment['DB_HOST'], $appEnvironment['DB_PORT'], $appEnvironment['DB_DATABASE'], $appEnvironment['DB_USERNAME'], $appEnvironment['DB_PASSWORD']);
    $appPassword = bin2hex(random_bytes(16));
    $secrets[] = $appPassword;
    $appInstall = new Process([...$command, 'app:install', 'platform-admin', '平台管理员', 'customer-admin', '客户管理员', '终止租户'], $root, $appEnvironment + [
        'APP_ADMIN_PASSWORD' => $appPassword, 'APP_CUSTOMER_PASSWORD' => $appPassword . '-customer',
    ]);
    try {
        expect($appInstall->wait(45)->successful(), '双端终止安装失败：' . $appInstall->stderr());
    } finally {
        $appInstall->stop();
    }
    $appServer = new Process([...$command, 'serve'], $root, $appEnvironment);
    $appClient = new HttpClient('http://' . $addresses[2], 8.0);
    $deadline = microtime(true) + 15;
    do {
        expect($appServer->running(), '双端终止HTTP提前退出：' . $appServer->stderr());
        try {
            if ($appClient->request('GET', '/readyz')->status === 200) {
                break;
            }
        } catch (RuntimeException) {
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
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
    $appRequest('POST', '/customer/tenants/' . $tenantId . '/broker/resources/sessions/' . $item['id'] . '/terminate', $customerToken, $tenantHeaders, $body, 503);
    $appRequest('GET', '/customer/tenants/' . $tenantId . '/broker/operations/' . $operationId, $customerToken, $tenantHeaders, null, 404);
    $appRequest('GET', '/admin/broker/operations/' . $operationId, $platformToken, [], null, 404);
    $report['http_checks'] = $checks;
    $report['operation'] = ['id' => $operationId, 'outcome' => $status['outcome'], 'stage' => $status['stage']];
    $report['status'] = 'passed';
    if ($browserDist !== '') {
        expect(is_file($browserDist . '/index.html'), '浏览器验收需要本次隔离前端产物');
        $browser = new Process(['node', $root . '/tests/broker-session-terminate-browser.mjs', $base, $browserDist, 'http://' . $addresses[0], 'http://' . $addresses[2]], $root, array_replace(getenv(), [
            'BROKER_BROWSER_PASSWORD' => $password,
            'APP_BROWSER_PASSWORD' => $appPassword,
            'APP_BROWSER_CUSTOMER_PASSWORD' => $appPassword . '-customer',
            'APP_BROWSER_TENANT' => $tenantId,
            'BROKER_BROWSER_CLIENT_ID' => 'session-other',
        ]));
        $browserResult = $browser->wait(180);
        file_put_contents($base . '/browser.log', str_replace($secrets, '<REDACTED>', $browserResult->stdout . $browserResult->stderr));
        expect($browserResult->successful(), '终止浏览器失败，见browser-session-terminate-report.json');
        $report['browser'] = json_decode(file_get_contents($base . '/browser-session-terminate-report.json'), true, 32, JSON_THROW_ON_ERROR);
        expect(($report['browser']['status'] ?? '') === 'passed', '终止浏览器报告未通过');
    }
} finally {
    foreach (['keep' => $keep, 'other' => $other, 'publisher' => $publisher, 'offline' => $offline, 'stale' => $stale] as $role => $mqtt) {
        try {
            $mqtt?->stop();
        } catch (Throwable) {
        }
    }
    foreach (['browser' => $browser, 'node' => $node, 'app' => $appServer, 'http' => $server] as $role => $process) {
        try {
            $process?->stop(5.0);
            $report['cleanup'][$role] = $process === null || !$process->running();
        } catch (Throwable $failure) {
            $report['cleanup'][$role] = false;
            $report['cleanup_errors'][] = $role . ':' . $failure->getMessage();
        }
    }
    try {
        $fixtureSync->close();
        $report['cleanup']['standby'] = true;
    } catch (Throwable $failure) {
        $report['cleanup']['standby'] = false;
        $report['cleanup_errors'][] = 'standby:' . $failure->getMessage();
    }
    try {
        $fixtureDatabase->close();
        $report['cleanup']['primary'] = true;
    } catch (Throwable $failure) {
        $report['cleanup']['primary'] = false;
        $report['cleanup_errors'][] = 'primary:' . $failure->getMessage();
    }
    if (($report['status'] ?? '') !== 'passed') {
        $report['status'] = 'failed';
    }
    $report['http_checks'] = $checks;
    file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
expect(($report['status'] ?? '') === 'passed', '会话终止验收未通过，见 ' . $base . '/verification.json');
echo 'Broker 精确会话终止通过：' . $base . "/verification.json\n";
exit(0);
