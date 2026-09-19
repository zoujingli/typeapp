<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Type\Mqtt\Client;
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
 * 独立管理端按精确 owner 与会话代次断开当前网络连接；发送 MQTT 5 0x98，保留协议允许恢复的会话。
 * 默认 SQLite QoS0 明文路径；持久会话恢复由 mqtt-sessions 的 PostgreSQL 身份场景覆盖。
 */

$root = dirname(__DIR__);
$target = $argv[1] ?? '--php';
$driver = $argv[2] ?? 'sqlite';
expect(in_array($driver, ['sqlite', 'mysql', 'pgsql'], true), '断开验收需要明确的受支持驱动');
if ($target === '--php') {
    $swoole = getenv('TYPE_SWOOLE_MODULE');
    $swoole = is_string($swoole) && $swoole !== '' ? $swoole : rtrim((string) ini_get('extension_dir'), '/') . '/swoole.so';
    expect(is_file($swoole), 'PHP 断开验收需要 TYPE_SWOOLE_MODULE 或 extension_dir 中的 swoole.so');
    $command = [PHP_BINARY, '-d', 'extension=' . $swoole, $root . '/bin/typeapp'];
} else {
    $command = nativeCommand($target);
}
$base = $root . '/build/broker-disconnect-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700, true), '无法创建断开测试目录');
$noSource = in_array('--no-source', $argv, true);
$browserDist = '';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--browser-dist=')) {
        $browserDist = substr($argument, 15);
    }
}
if ($noSource) {
    expect($target !== '--php' && PHP_OS_FAMILY === 'Darwin', '无源码断开验收使用macOS内核策略及原生产物');
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
$environment = getenv();
foreach (array_keys($environment) as $key) {
    if (str_starts_with($key, 'APP_') || str_starts_with($key, 'DB_') || str_starts_with($key, 'IOT_') || str_starts_with($key, 'BROKER_')) {
        unset($environment[$key]);
    }
}
$environment['APP_BASE_PATH'] = $base;
$environment['DB_DRIVER'] = $driver;
$environment['DB_SQLITE_FILE'] = 'disconnect.sqlite';
$environment['APP_CACHE_ENABLED'] = 'false';
$environment['APP_DEBUG'] = 'true';
if ($driver !== 'sqlite') {
    $prefix = 'TYPE_' . strtoupper($driver) . '_';
    foreach (['HOST' => 'HOST', 'PORT' => 'PORT', 'DATABASE' => 'DATABASE', 'USERNAME' => 'USER', 'PASSWORD' => 'PASSWORD'] as $destination => $source) {
        $value = getenv($prefix . $source);
        expect(is_string($value), '数据库验证必须由隔离实例提供参数：' . $source);
        $environment['DB_' . $destination] = $value;
    }
}
$checks = 0;
$report = ['status' => 'running', 'scope' => 'broker-disconnect', 'native' => $target !== '--php', 'driver' => $driver,
    'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'no_source' => $noSource];
$server = null;
$node = null;
$mqtt = null;
$appServer = null;
$browser = null;
$secrets = [];
try {
    $install = new Process([...$command, 'broker:install'], $root, $environment);
    try {
        expect($install->wait(30)->successful(), '独立断开库安装失败：' . $install->stdout() . $install->stderr());
    } finally {
        $install->stop();
    }
    $password = bin2hex(random_bytes(16));
    $secrets[] = $password;
    identityCommand([...$command, 'broker:user', 'access-admin', '断开管理员'], $environment + ['BROKER_ADMIN_PASSWORD' => $password]);
    $addresses = [];
    for ($index = 0; $index < 3; $index++) {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorText);
        expect(is_resource($listener), '无法选择断开测试端口');
        $addresses[] = stream_socket_get_name($listener, false);
        fclose($listener);
    }
    $environment['APP_PORT'] = substr(strrchr($addresses[0], ':'), 1);
    $environment['APP_ALLOWED_HOSTS'] = $addresses[0];
    $server = new Process([...$command, 'broker:serve'], $root, $environment);
    $client = new HttpClient('http://' . $addresses[0], 8.0);
    $deadline = microtime(true) + 10;
    do {
        expect($server->running(), '独立断开管理提前退出：' . $server->stderr());
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
        'BROKER_TOPIC_PREFIX' => 'broker-disconnect/', 'BROKER_NODE_ID' => 'disconnect-node',
        'BROKER_PLAINTEXT' => 'true', 'BROKER_IO_DRIVER' => 'stream',
        'BROKER_PORT' => substr(strrchr($addresses[1], ':'), 1),
    ];
    $node = new Process([...$command, 'broker:run'], $root, $nodeEnvironment);
    $deadline = microtime(true) + 12;
    $nodes = [];
    do {
        expect($node->running(), '断开节点提前退出：' . $node->stderr());
        $nodes = $request('GET', '/broker/nodes', $token, null, 200);
        if (($nodes['total'] ?? 0) === 1 && ($nodes['items'][0]['state'] ?? '') === 'reporting') {
            break;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    expect(($nodes['total'] ?? 0) === 1, '断开节点未上报');
    $mqtt = new Client('127.0.0.1', (int) $nodeEnvironment['BROKER_PORT'], 'disconnect-live', 'broker-client', $mqttPassword, sessionExpiry: 0, allowPlaintext: true);
    expect(!$mqtt->connect(true), '管理断开前不应报告会话恢复');
    expect($mqtt->subscribe('broker-disconnect/keep', 0) === 0, '引导前缀应授予QoS0订阅');
    $connections = ['items' => []];
    $deadline = microtime(true) + 8;
    do {
        $connections = $request('GET', '/broker/resources/connections?limit=20', $token, null, 200);
        if (count($connections['items']) === 1) {
            break;
        }
        try {
            $mqtt->receive(0.05);
        } catch (ProtocolError) {
            throw new RuntimeException('目标连接在管理断开前被关闭');
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    expect(count($connections['items']) === 1 && $connections['items'][0]['client_id'] === 'disconnect-live'
        && $connections['items'][0]['state'] === 'connected', '真实连接未进入资源观察');
    $item = $connections['items'][0];
    $body = ['session_id' => $item['session_id'], 'session_generation' => $item['session_generation'], 'node_id' => $item['node_id']];
    $request('POST', '/broker/resources/connections/' . $item['id'] . '/disconnect', '', $body, 401);
    $request('POST', '/broker/resources/connections/' . $item['id'] . '/disconnect', $token, $body, 403, ['X-Tenant-Id' => bin2hex(random_bytes(16))]);
    $plain('POST', '/broker/resources/connections/' . $item['id'] . '/disconnect', $token, '{}', 415);
    $request('POST', '/broker/resources/connections/' . $item['id'] . '/disconnect', $token, $body + ['client_id' => 'disconnect-live'], 422);
    $request('POST', '/broker/resources/connections/' . str_repeat('0', 32) . '/disconnect', $token, $body, 404);
    $request('POST', '/broker/resources/connections/' . $item['id'] . '/disconnect', $token, [
        'session_id' => $item['session_id'], 'session_generation' => $item['session_generation'] + 1, 'node_id' => $item['node_id'],
    ], 409);
    $operationId = bin2hex(random_bytes(16));
    $accepted = $request('POST', '/broker/resources/connections/' . $item['id'] . '/disconnect', $token, $body + ['operation_id' => $operationId], 200);
    expect($accepted['operation_id'] === $operationId && $accepted['outcome'] === 'pending' && $accepted['stage'] === 'executing'
        && $accepted['result'] === 'pending' && $accepted['kind'] === 'disconnect', '断开受理被当成已完成');
    $repeat = $request('POST', '/broker/resources/connections/' . $item['id'] . '/disconnect', $token, $body + ['operation_id' => $operationId], 200);
    expect($repeat['operation_id'] === $operationId && ($repeat['outcome'] === 'pending' || $repeat['outcome'] === 'disconnected'), '同标识重试改变了冻结目标');
    $request('POST', '/broker/resources/connections/' . $item['id'] . '/disconnect', $token, [
        'session_id' => $item['session_id'], 'session_generation' => $item['session_generation'], 'node_id' => $item['node_id'],
        'operation_id' => $operationId, 'node_run_id' => str_repeat('a', 32),
    ], 409);
    $request('GET', '/broker/operations/' . $operationId, '', null, 401);
    $request('GET', '/broker/operations/' . $operationId, $token, null, 403, ['X-Tenant-Id' => bin2hex(random_bytes(16))]);
    $request('GET', '/broker/operations/' . str_repeat('f', 32), $token, null, 404);
    $status = $accepted;
    $disconnected = false;
    $deadline = microtime(true) + 8;
    do {
        $status = $request('GET', '/broker/operations/' . $operationId, $token, null, 200);
        if (!$disconnected) {
            try {
                $mqtt->receive(0.15);
            } catch (ProtocolError $failure) {
                expect($failure->reason === 0x98, '管理断开不是 MQTT 5 0x98，实际 ' . $failure->reason);
                $disconnected = true;
            }
        }
        if ($status['stage'] === 'completed' && $disconnected) {
            break;
        }
        usleep(40000);
    } while (microtime(true) < $deadline);
    expect($disconnected, '健康节点未发送管理断开');
    expect(
        $status['stage'] === 'completed' && $status['result'] === 'success' && $status['outcome'] === 'disconnected',
        '节点执行后操作仍待处理：' . json_encode($status)
    );
    $done = $request('POST', '/broker/resources/connections/' . $item['id'] . '/disconnect', $token, $body + ['operation_id' => $operationId], 200);
    expect($done['operation_id'] === $operationId && $done['outcome'] === 'disconnected' && $done['stage'] === 'completed', '完成后重试改变了冻结结果');
    $request('POST', '/broker/resources/connections/' . $item['id'] . '/disconnect', $token, $body, 404);
    try {
        $mqtt->stop();
    } catch (Throwable) {
    }
    $mqtt = new Client('127.0.0.1', (int) $nodeEnvironment['BROKER_PORT'], 'disconnect-live', 'broker-client', $mqttPassword, sessionExpiry: 0, allowPlaintext: true);
    expect(!$mqtt->connect(true), '凭据有效时重连失败');
    expect($mqtt->subscribe('broker-disconnect/keep', 0) === 0, '管理断开后合法凭据不能再订阅');
    $late = ['items' => []];
    $deadline = microtime(true) + 8;
    do {
        $late = $request('GET', '/broker/resources/connections?limit=20', $token, null, 200);
        if (count($late['items']) === 1 && $late['items'][0]['id'] !== $item['id']) {
            break;
        }
        try {
            $mqtt->receive(0.05);
        } catch (ProtocolError) {
            throw new RuntimeException('新连接在迟到断开核对前被关闭');
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    expect(count($late['items']) === 1 && $late['items'][0]['id'] !== $item['id'], '迟到核对时没有新的精确连接');
    $request('POST', '/broker/resources/connections/' . $late['items'][0]['id'] . '/disconnect', $token, [
        'session_id' => $item['session_id'], 'session_generation' => $item['session_generation'], 'node_id' => $item['node_id'],
    ], 409);
    $audit = $request('GET', '/broker/audit?action=broker.connection.disconnect&operation_id=' . $operationId, $token, null, 200);
    expect($audit['items'] !== [] && $audit['items'][0]['operation_id'] === $operationId
        && in_array($audit['items'][0]['result'], ['pending', 'success'], true), '断开未留下可对账审计');
    $encoded = json_encode($audit, JSON_THROW_ON_ERROR);
    expect(
        !str_contains($encoded, $mqttPassword) && !str_contains($encoded, $password) && !str_contains($encoded, '"client_id"'),
        '断开审计包含秘密或客户端标识'
    );
    $detail = $request('GET', '/broker/audit/' . $audit['items'][0]['id'], $token, null, 200)['item'];
    expect(($detail['operation']['action'] ?? '') === 'broker.connection.disconnect'
        && ($detail['operation']['target']['kind'] ?? '') === 'connection'
        && ($detail['operation']['impact']['effect'] ?? '') === 'disconnect_connection', '断开审计丢失精确目标');
    $appBase = $base . '/app';
    expect(mkdir($appBase, 0700), '无法创建双端断开测试目录');
    $appEnvironment = $environment;
    $appEnvironment['APP_BASE_PATH'] = $appBase;
    $appEnvironment['DB_SQLITE_FILE'] = 'app.sqlite';
    $appEnvironment['APP_PORT'] = substr(strrchr($addresses[2], ':'), 1);
    $appEnvironment['APP_ALLOWED_HOSTS'] = $addresses[2];
    unset($appEnvironment['BROKER_CLIENT_USERNAME'], $appEnvironment['BROKER_CLIENT_PASSWORD'], $appEnvironment['BROKER_TOPIC_PREFIX'], $appEnvironment['BROKER_NODE_ID'], $appEnvironment['BROKER_PLAINTEXT'], $appEnvironment['BROKER_IO_DRIVER'], $appEnvironment['BROKER_PORT']);
    $appPassword = bin2hex(random_bytes(16));
    $secrets[] = $appPassword;
    $appInstall = new Process([...$command, 'app:install', 'platform-admin', '平台管理员', 'customer-admin', '客户管理员', '断开租户'], $root, $appEnvironment + [
        'APP_ADMIN_PASSWORD' => $appPassword, 'APP_CUSTOMER_PASSWORD' => $appPassword . '-customer',
    ]);
    try {
        expect($appInstall->wait(45)->successful(), '双端断开安装失败：' . $appInstall->stderr());
    } finally {
        $appInstall->stop();
    }
    $appServer = new Process([...$command, 'serve'], $root, $appEnvironment);
    $appClient = new HttpClient('http://' . $addresses[2], 8.0);
    $deadline = microtime(true) + 15;
    do {
        expect($appServer->running(), '双端断开HTTP提前退出：' . $appServer->stderr());
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
    expect(in_array('/broker-resources', menuPaths($me['menus']), true), '客户最高管理员应看到资源菜单');
    $appRequest('POST', '/customer/tenants/' . $tenantId . '/broker/resources/connections/' . $item['id'] . '/disconnect', $customerToken, $tenantHeaders, $body, 404);
    $appRequest('GET', '/customer/tenants/' . $tenantId . '/broker/operations/' . $operationId, $customerToken, $tenantHeaders, null, 404);
    $appRequest('GET', '/admin/broker/operations/' . $operationId, $platformToken, [], null, 404);
    $appRequest('POST', '/admin/broker/resources/connections/' . $item['id'] . '/disconnect', $platformToken, [], $body, 404);
    $roles = $appRequest('GET', '/customer/roles', $customerToken, $tenantHeaders, null, 200)['data'];
    expect(isset($roles['catalog']['customer.broker.write']), '客户目录必须包含连接管理写入节点');
    $report['http_checks'] = $checks;
    $report['operation'] = ['id' => $operationId, 'outcome' => $status['outcome'], 'stage' => $status['stage']];
    $report['status'] = 'passed';
    if ($browserDist !== '') {
        expect(is_file($browserDist . '/index.html'), '浏览器验收需要本次隔离前端产物');
        $browser = new Process(['node', $root . '/tests/broker-disconnect-browser.mjs', $base, $browserDist, 'http://' . $addresses[0], 'http://' . $addresses[2]], $root, array_replace(getenv(), [
            'BROKER_BROWSER_PASSWORD' => $password,
            'APP_BROWSER_PASSWORD' => $appPassword,
            'APP_BROWSER_CUSTOMER_PASSWORD' => $appPassword . '-customer',
            'APP_BROWSER_TENANT' => $tenantId,
            'BROKER_BROWSER_CLIENT_ID' => 'disconnect-live',
        ]));
        $browserResult = $browser->wait(180);
        file_put_contents($base . '/browser.log', str_replace($secrets, '<REDACTED>', $browserResult->stdout . $browserResult->stderr));
        expect($browserResult->successful(), '断开浏览器失败，见browser-disconnect-report.json');
        $report['browser'] = json_decode(file_get_contents($base . '/browser-disconnect-report.json'), true, 32, JSON_THROW_ON_ERROR);
        expect(($report['browser']['status'] ?? '') === 'passed', '断开浏览器报告未通过');
    }
} finally {
    try {
        $mqtt?->stop();
    } catch (Throwable) {
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
    if (($report['status'] ?? '') !== 'passed') {
        $report['status'] = 'failed';
    }
    $report['http_checks'] = $checks;
    file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
expect(($report['status'] ?? '') === 'passed', '断开验收未通过，见 ' . $base . '/verification.json');
echo 'Broker 精确断开通过：' . $base . "/verification.json\n";
exit(0);
