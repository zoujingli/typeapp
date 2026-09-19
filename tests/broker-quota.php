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
 * 独立管理端预览并确认降低连接、会话与消息额度；已确认积压保留，新超额占用返回 0x97。
 * 需要 PostgreSQL 同步存储；SQLite 双端身份路径只证明 403/422。
 */

$root = dirname(__DIR__);
$target = $argv[1] ?? '--php';
$driver = $argv[2] ?? 'pgsql';
expect($driver === 'pgsql', '在线降配额验收需要 PostgreSQL 同步存储');
if ($target === '--php') {
    $swoole = getenv('TYPE_SWOOLE_MODULE');
    $swoole = is_string($swoole) && $swoole !== '' ? $swoole : rtrim((string) ini_get('extension_dir'), '/') . '/swoole.so';
    expect(is_file($swoole), 'PHP 降配额验收需要 TYPE_SWOOLE_MODULE 或 extension_dir 中的 swoole.so');
    $command = [PHP_BINARY, '-d', 'extension=' . $swoole, $root . '/bin/typeapp'];
} else {
    $command = nativeCommand($target);
}
$base = $root . '/build/broker-quota-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700, true), '无法创建降配额测试目录');
$noSource = in_array('--no-source', $argv, true);
$browserDist = '';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--browser-dist=')) {
        $browserDist = substr($argument, 15);
    }
}
if ($noSource) {
    expect($target !== '--php' && PHP_OS_FAMILY === 'Darwin', '无源码降配额验收使用macOS内核策略及原生产物');
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
$fixtureSync = new PostgresSync($fixtureDatabase, $base . '/standby', $databaseTools, 'broker_quota_sync');
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
$environment['BROKER_STANDBY_NAMES'] = 'broker_quota_sync';
$checks = 0;
$report = ['status' => 'running', 'scope' => 'broker-quota', 'native' => $target !== '--php', 'driver' => 'pgsql',
    'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'no_source' => $noSource];
$server = null;
$node = null;
$first = null;
$second = null;
$denied = null;
$publisher = null;
$appServer = null;
$browser = null;
$secrets = [];
try {
    $install = new Process([...$command, 'broker:install'], $root, $environment);
    try {
        expect($install->wait(30)->successful(), '独立降配额库安装失败：' . $install->stdout() . $install->stderr());
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
    identityCommand([...$command, 'broker:user', 'access-admin', '配额管理员'], $environment + ['BROKER_ADMIN_PASSWORD' => $password]);
    $addresses = [];
    for ($index = 0; $index < 3; $index++) {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorText);
        expect(is_resource($listener), '无法选择降配额测试端口');
        $addresses[] = stream_socket_get_name($listener, false);
        fclose($listener);
    }
    $environment['APP_PORT'] = substr(strrchr($addresses[0], ':'), 1);
    $environment['APP_ALLOWED_HOSTS'] = $addresses[0];
    $server = new Process([...$command, 'broker:serve'], $root, $environment);
    $client = new HttpClient('http://' . $addresses[0], 12.0);
    $deadline = microtime(true) + 10;
    do {
        expect($server->running(), '独立降配额管理提前退出：' . $server->stderr());
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
        'BROKER_TOPIC_PREFIX' => 'broker-quota/', 'BROKER_NODE_ID' => 'quota-node',
        'BROKER_PLAINTEXT' => 'true', 'BROKER_IO_DRIVER' => 'stream',
        'BROKER_PORT' => substr(strrchr($addresses[1], ':'), 1),
    ];
    $node = new Process([...$command, 'broker:run'], $root, $nodeEnvironment);
    $deadline = microtime(true) + 12;
    do {
        expect($node->running(), '降配额节点提前退出：' . $node->stderr());
        $nodes = $request('GET', '/broker/nodes', $token, null, 200);
        if (($nodes['total'] ?? 0) === 1) {
            break;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    expect(($nodes['total'] ?? 0) === 1, '降配额节点未上报');
    $port = (int) $nodeEnvironment['BROKER_PORT'];
    $current = $request('GET', '/broker/quotas', $token, null, 200);
    expect(isset($current['limits']['maximumConnections'], $current['usage'], $current['ceilings']) && $current['current_version'] >= 1, '额度查询丢失限额或版本');
    $request('GET', '/broker/quotas', '', null, 401);
    $plain('POST', '/broker/quotas', $token, 'confirmed=true', 415);
    $request('POST', '/broker/quotas', $token, ['expected_version' => $current['current_version'], 'maximumConnections' => 1], 422);
    $first = new Client('127.0.0.1', $port, 'quota-first', 'broker-client', $mqttPassword, sessionExpiry: 86400, allowPlaintext: true);
    expect(!$first->connect(true), '首个会话不应报告已恢复');
    expect($first->subscribe('broker-quota/keep') === 1, '首个会话应获得 QoS1 订阅');
    $second = new Client('127.0.0.1', $port, 'quota-second', 'broker-client', $mqttPassword, sessionExpiry: 86400, allowPlaintext: true);
    expect(!$second->connect(true), '第二个会话不应报告已恢复');
    expect($second->subscribe('broker-quota/keep') === 1, '第二个会话应获得 QoS1 订阅');
    $pump = static function () use (&$first, &$second): void {
        foreach ([$first, $second] as $mqtt) {
            try {
                $mqtt?->receive(0.05);
            } catch (Throwable) {
            }
        }
    };
    $deadline = microtime(true) + 10;
    do {
        $pump();
        $current = $request('GET', '/broker/quotas', $token, null, 200);
        if (($current['usage']['connections'] ?? 0) >= 2) {
            break;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    expect(($current['usage']['connections'] ?? 0) >= 2, '连接用量未计入已建立会话');
    $preview = $request('POST', '/broker/quotas/preview', $token, ['expected_version' => $current['current_version'], 'maximumConnections' => 1], 200);
    expect($preview['lowering'] === true && $preview['waiting_release'] === true && ($preview['excess']['connections'] ?? 0) >= 1, '降低连接额度预览未标超额等待释放');
    $published = $request('POST', '/broker/quotas', $token, ['expected_version' => $current['current_version'], 'maximumConnections' => 1, 'confirmed' => true], 200)['data'];
    expect($published['lowering'] === true && $published['version'] === $current['current_version'] + 1, '降低连接额度未形成新版本');
    $applied = $published;
    $deadline = microtime(true) + 12;
    do {
        $pump();
        $applied = $request('GET', '/broker/quotas', $token, null, 200);
        $state = $applied['revision']['nodes'][0]['state'] ?? '';
        if (($applied['revision']['status'] ?? '') === 'effective' || $state === 'applied') {
            break;
        }
        usleep(40000);
    } while (microtime(true) < $deadline);
    expect((($applied['revision']['status'] ?? '') === 'effective' || ($applied['revision']['nodes'][0]['state'] ?? '') === 'applied')
        && ($applied['limits']['maximumConnections'] ?? 0) === 1, '节点未加载降低后的连接额度：' . json_encode($applied['revision'] ?? []));
    $pump();
    $denied = new Client('127.0.0.1', $port, 'quota-denied', 'broker-client', $mqttPassword, sessionExpiry: 0, allowPlaintext: true);
    try {
        $denied->connect(true);
        expect(false, '超额连接仍被接纳');
    } catch (ProtocolError $error) {
        expect($error->reason === 0x97, '超额连接未返回标准配额失败');
    }
    $publisher = new Client('127.0.0.1', $port, 'quota-publisher', 'broker-client', $mqttPassword, sessionExpiry: 0, allowPlaintext: true);
    try {
        $publisher->connect(true);
        expect(false, '发布者在连接额度已满时仍被接纳');
    } catch (ProtocolError $error) {
        expect($error->reason === 0x97, '发布者超额未返回标准配额失败');
    }
    $publisher = null;
    $payloads = [];
    for ($index = 0; $index < 3; $index++) {
        $payloads[] = 'quota-backlog-' . bin2hex(random_bytes(8));
        $secrets[] = $payloads[$index];
        expect($first->publish(new Message('broker-quota/keep', $payloads[$index], '', 1)) === 0, '已确认发布失败');
    }
    $backlog = ['items' => []];
    $deadline = microtime(true) + 10;
    do {
        $pump();
        $backlog = $request('GET', '/broker/resources/backlog?topic=' . rawurlencode('broker-quota/keep') . '&limit=20', $token, null, 200);
        if (count($backlog['items']) >= 1) {
            break;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    expect($backlog['items'] !== [], '已确认积压未进入资源查询');
    $kept = count($backlog['items']);
    $current = $request('GET', '/broker/quotas', $token, null, 200);
    $preview = $request('POST', '/broker/quotas/preview', $token, ['expected_version' => $current['current_version'], 'maximumPendingMessages' => 1], 200);
    expect($preview['lowering'] === true, '降低消息额度预览未标为降低');
    $published = $request('POST', '/broker/quotas', $token, ['expected_version' => $current['current_version'], 'maximumPendingMessages' => 1, 'confirmed' => true], 200)['data'];
    $deadline = microtime(true) + 12;
    do {
        $pump();
        $applied = $request('GET', '/broker/quotas', $token, null, 200);
        if (($applied['limits']['maximumPendingMessages'] ?? 0) === 1 && (($applied['revision']['status'] ?? '') === 'effective'
            || ($applied['revision']['nodes'][0]['state'] ?? '') === 'applied')) {
            break;
        }
        usleep(40000);
    } while (microtime(true) < $deadline);
    expect(($applied['limits']['maximumPendingMessages'] ?? 0) === 1, '节点未加载降低后的消息额度');
    $still = $request('GET', '/broker/resources/backlog?topic=' . rawurlencode('broker-quota/keep') . '&limit=20', $token, null, 200);
    expect(count($still['items']) >= $kept, '降低消息额度删除了已确认积压');
    try {
        $second->publish(new Message('broker-quota/keep', 'quota-overflow', '', 1));
        expect(false, '新的超额发布仍被接纳');
    } catch (ProtocolError $error) {
        expect($error->reason === 0x97, '新的超额发布未返回标准配额失败');
    }
    $current = $request('GET', '/broker/quotas', $token, null, 200);
    $request('POST', '/broker/quotas', $token, ['expected_version' => $current['current_version'], 'maximumSubscriptions' => 1, 'confirmed' => true], 200);
    $deadline = microtime(true) + 12;
    do {
        $pump();
        $applied = $request('GET', '/broker/quotas', $token, null, 200);
        if (($applied['limits']['maximumSubscriptions'] ?? 0) === 1 && (($applied['revision']['status'] ?? '') === 'effective'
            || ($applied['revision']['nodes'][0]['state'] ?? '') === 'applied')) {
            break;
        }
        usleep(40000);
    } while (microtime(true) < $deadline);
    expect(($applied['limits']['maximumSubscriptions'] ?? 0) === 1 && (($applied['revision']['status'] ?? '') === 'effective'
        || ($applied['revision']['nodes'][0]['state'] ?? '') === 'applied'), '节点未加载降低后的每会话订阅额度');
    expect($first->subscribe('broker-quota/keep') === 1, '替换订阅被当成新增占用');
    try {
        $first->subscribe('broker-quota/extra');
        expect(false, '超额订阅仍被授予');
    } catch (ProtocolError $error) {
        expect($error->reason === 0x97, '超额订阅未返回标准配额失败');
    }
    $audit = $request('GET', '/broker/audit?action=broker.quota.publish&operation_id=' . $published['id'], $token, null, 200);
    expect($audit['items'] !== [] && $audit['items'][0]['operation_id'] === $published['id'], '降配额未留下可对账审计');
    $encoded = json_encode($audit, JSON_THROW_ON_ERROR);
    expect(!str_contains($encoded, $mqttPassword) && !str_contains($encoded, $password), '降配额审计包含秘密');
    $appBase = $base . '/app';
    expect(mkdir($appBase, 0700), '无法创建双端降配额测试目录');
    $appEnvironment = $environment;
    $appEnvironment['APP_BASE_PATH'] = $appBase;
    $appEnvironment['APP_PORT'] = substr(strrchr($addresses[2], ':'), 1);
    $appEnvironment['APP_ALLOWED_HOSTS'] = $addresses[2];
    unset($appEnvironment['BROKER_CLIENT_USERNAME'], $appEnvironment['BROKER_CLIENT_PASSWORD'], $appEnvironment['BROKER_TOPIC_PREFIX'], $appEnvironment['BROKER_NODE_ID'], $appEnvironment['BROKER_PLAINTEXT'], $appEnvironment['BROKER_IO_DRIVER'], $appEnvironment['BROKER_PORT'], $appEnvironment['BROKER_COMMAND'], $appEnvironment['BROKER_STANDBY_NAMES']);
    $appEnvironment['DB_DRIVER'] = 'sqlite';
    $appEnvironment['DB_SQLITE_FILE'] = 'app.sqlite';
    unset($appEnvironment['DB_HOST'], $appEnvironment['DB_PORT'], $appEnvironment['DB_DATABASE'], $appEnvironment['DB_USERNAME'], $appEnvironment['DB_PASSWORD']);
    $appPassword = bin2hex(random_bytes(16));
    $secrets[] = $appPassword;
    $appInstall = new Process([...$command, 'app:install', 'platform-admin', '平台管理员', 'customer-admin', '客户管理员', '配额租户'], $root, $appEnvironment + [
        'APP_ADMIN_PASSWORD' => $appPassword, 'APP_CUSTOMER_PASSWORD' => $appPassword . '-customer',
    ]);
    try {
        expect($appInstall->wait(45)->successful(), '双端降配额安装失败：' . $appInstall->stderr());
    } finally {
        $appInstall->stop();
    }
    $appServer = new Process([...$command, 'serve'], $root, $appEnvironment);
    $appClient = new HttpClient('http://' . $addresses[2], 8.0);
    $deadline = microtime(true) + 15;
    do {
        expect($appServer->running(), '双端降配额HTTP提前退出：' . $appServer->stderr());
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
    $tenantQuotas = $appRequest('GET', '/customer/tenants/' . $tenantId . '/broker/quotas', $customerToken, $tenantHeaders, null, 200);
    $appRequest('POST', '/customer/tenants/' . $tenantId . '/broker/quotas', $customerToken, $tenantHeaders, [
        'expected_version' => $tenantQuotas['current_version'], 'maximumConnections' => 1, 'confirmed' => true,
    ], 403);
    $platformQuotas = $appRequest('GET', '/admin/broker/quotas', $platformToken, [], null, 200);
    $appRequest('POST', '/admin/broker/quotas', $platformToken, [], ['expected_version' => $platformQuotas['current_version'], 'maximumConnections' => 1], 422);
    $report['http_checks'] = $checks;
    $report['revision'] = ['id' => $published['id'], 'version' => $applied['current_version']];
    $report['status'] = 'passed';
    if ($browserDist !== '') {
        expect(is_file($browserDist . '/index.html'), '浏览器验收需要本次隔离前端产物');
        $browser = new Process(['node', $root . '/tests/broker-quota-browser.mjs', $base, $browserDist, 'http://' . $addresses[0], 'http://' . $addresses[2]], $root, array_replace(getenv(), [
            'BROKER_BROWSER_PASSWORD' => $password,
            'APP_BROWSER_PASSWORD' => $appPassword,
            'APP_BROWSER_CUSTOMER_PASSWORD' => $appPassword . '-customer',
            'APP_BROWSER_TENANT' => $tenantId,
        ]));
        $browserResult = $browser->wait(180);
        file_put_contents($base . '/browser.log', str_replace($secrets, '<REDACTED>', $browserResult->stdout . $browserResult->stderr));
        expect($browserResult->successful(), '降配额浏览器失败，见browser-quota-report.json');
        $report['browser'] = json_decode(file_get_contents($base . '/browser-quota-report.json'), true, 32, JSON_THROW_ON_ERROR);
        expect(($report['browser']['status'] ?? '') === 'passed', '降配额浏览器报告未通过');
    }
} finally {
    foreach (['first' => $first, 'second' => $second, 'denied' => $denied, 'publisher' => $publisher] as $role => $mqtt) {
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

expect(($report['status'] ?? '') === 'passed', '降配额验收未通过，见 ' . $base . '/verification.json');
echo 'Broker 在线降配额通过：' . $base . "/verification.json\n";
exit(0);
