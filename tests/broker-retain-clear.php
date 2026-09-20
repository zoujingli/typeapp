<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/native-database.php';
require_once __DIR__ . '/postgres-sync.php';

use Type\Mqtt\Client;
use Type\Mqtt\Message;
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
 * 独立管理端预览并明确清除指定保留原件；按 resource_id 与代次匹配，不制造普通发布、不撤回已有交付。
 * 需要 PostgreSQL 同步存储；SQLite QoS0 不能证明持久清保留。
 */

$root = dirname(__DIR__);
$target = $argv[1] ?? '--php';
$driver = $argv[2] ?? 'pgsql';
expect($driver === 'pgsql', '清保留验收需要 PostgreSQL 同步存储');
if ($target === '--php') {
    $swoole = getenv('TYPE_SWOOLE_MODULE');
    $swoole = is_string($swoole) && $swoole !== '' ? $swoole : rtrim((string) ini_get('extension_dir'), '/') . '/swoole.so';
    expect(is_file($swoole), 'PHP 清保留验收需要 TYPE_SWOOLE_MODULE 或 extension_dir 中的 swoole.so');
    $command = [PHP_BINARY, '-d', 'extension=' . $swoole, $root . '/bin/typeapp'];
} else {
    $command = nativeCommand($target);
}
$base = $root . '/build/broker-retain-clear-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700, true), '无法创建清保留测试目录');
$noSource = in_array('--no-source', $argv, true);
$browserDist = '';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--browser-dist=')) {
        $browserDist = substr($argument, 15);
    }
}
if ($noSource) {
    expect($target !== '--php' && PHP_OS_FAMILY === 'Darwin', '无源码清保留验收使用macOS内核策略及原生产物');
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
$fixtureSync = new PostgresSync($fixtureDatabase, $base . '/standby', $databaseTools, 'broker_retain_sync');
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
$environment['BROKER_STANDBY_NAMES'] = 'broker_retain_sync';
$checks = 0;
$report = ['status' => 'running', 'scope' => 'broker-retain-clear', 'native' => $target !== '--php', 'driver' => 'pgsql',
    'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'no_source' => $noSource];
$server = null;
$node = null;
$holder = null;
$publisher = null;
$fresh = null;
$stale = null;
$appServer = null;
$browser = null;
$secrets = [];
try {
    $install = new Process([...$command, 'broker:install'], $root, $environment);
    try {
        expect($install->wait(30)->successful(), '独立清保留库安装失败：' . $install->stdout() . $install->stderr());
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
    identityCommand([...$command, 'broker:user', 'access-admin', '清保留管理员'], $environment + ['BROKER_ADMIN_PASSWORD' => $password]);
    $addresses = [];
    for ($index = 0; $index < 3; $index++) {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorText);
        expect(is_resource($listener), '无法选择清保留测试端口');
        $addresses[] = stream_socket_get_name($listener, false);
        fclose($listener);
    }
    $environment['APP_PORT'] = substr(strrchr($addresses[0], ':'), 1);
    $environment['APP_ALLOWED_HOSTS'] = $addresses[0];
    $server = new Process([...$command, 'broker:serve'], $root, $environment);
    $client = new HttpClient('http://' . $addresses[0], 12.0);
    $deadline = microtime(true) + 10;
    do {
        expect($server->running(), '独立清保留管理提前退出：' . $server->stderr());
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
        'BROKER_TOPIC_PREFIX' => 'broker-retain/', 'BROKER_NODE_ID' => 'retain-node',
        'BROKER_PLAINTEXT' => 'true',
        'BROKER_PORT' => substr(strrchr($addresses[1], ':'), 1),
    ];
    $node = new Process([...$command, 'broker:run'], $root, $nodeEnvironment);
    $deadline = microtime(true) + 12;
    do {
        expect($node->running(), '清保留节点提前退出：' . $node->stderr());
        $nodes = $request('GET', '/broker/nodes', $token, null, 200);
        if (($nodes['total'] ?? 0) === 1) {
            break;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    expect(($nodes['total'] ?? 0) === 1, '清保留节点未上报');
    $port = (int) $nodeEnvironment['BROKER_PORT'];
    $holder = new Client('127.0.0.1', $port, 'retain-holder', 'broker-client', $mqttPassword, sessionExpiry: 0, allowPlaintext: true);
    expect(!$holder->connect(true), '在途订阅者不应报告已恢复');
    expect($holder->subscribe('broker-retain/keep') === 1, '在途订阅者应获得 QoS1 订阅');
    $publisher = new Client('127.0.0.1', $port, 'retain-publisher', 'broker-client', $mqttPassword, sessionExpiry: 0, allowPlaintext: true);
    $publisher->connect(true);
    $keepPayload = 'keep-secret-' . bin2hex(random_bytes(8));
    $otherPayload = 'other-secret-' . bin2hex(random_bytes(8));
    $secrets[] = $keepPayload;
    $secrets[] = $otherPayload;
    expect($publisher->publish(new Message('broker-retain/keep', $keepPayload, '', 1, 0, true)) === 0, '目标保留发布失败');
    expect($publisher->publish(new Message('broker-retain/other', $otherPayload, '', 1, 0, true)) === 0, '对照保留发布失败');
    $held = null;
    $deadline = microtime(true) + 8;
    do {
        $held = $holder->receive(0.2);
        if ($held !== null) {
            break;
        }
    } while (microtime(true) < $deadline);
    expect($held !== null && $held['message']->topic === 'broker-retain/keep' && $held['message']->payload === $keepPayload, '在途订阅者未收到保留发布');
    $keepItem = ['items' => []];
    $otherItem = ['items' => []];
    $deadline = microtime(true) + 10;
    do {
        $keepItem = $request('GET', '/broker/resources/retained?topic=' . rawurlencode('broker-retain/keep') . '&limit=20', $token, null, 200);
        $otherItem = $request('GET', '/broker/resources/retained?topic=' . rawurlencode('broker-retain/other') . '&limit=20', $token, null, 200);
        if ($keepItem['items'] !== [] && $otherItem['items'] !== []) {
            break;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    expect($keepItem['items'] !== [] && $keepItem['items'][0]['topic'] === 'broker-retain/keep', '目标保留未进入资源查询');
    expect($otherItem['items'] !== [] && $otherItem['items'][0]['topic'] === 'broker-retain/other', '对照保留未进入资源查询');
    $item = $keepItem['items'][0];
    $preview = $request('GET', '/broker/resources/retained/' . $item['id'] . '/clearance-preview', $token, null, 200);
    expect($preview['id'] === $item['id'] && $preview['generation'] === (int) $item['generation']
        && $preview['impact']['future_replay_cancelled'] === true && $preview['impact']['no_ordinary_publish'] === true
        && $preview['impact']['existing_deliveries_kept'] === true, '清除预览丢失精确目标或影响');
    $encodedPreview = json_encode($preview, JSON_THROW_ON_ERROR);
    expect(!str_contains($encodedPreview, $keepPayload) && !str_contains($encodedPreview, $otherPayload), '清除预览泄漏保留载荷');
    $request('GET', '/broker/resources/retained/' . $item['id'] . '/clearance-preview', '', null, 401);
    $plain('POST', '/broker/resources/retained/' . $item['id'] . '/clear', $token, 'confirmed=true', 415);
    $request('POST', '/broker/resources/retained/' . $item['id'] . '/clear', $token, ['generation' => (int) $item['generation']], 422);
    $request('POST', '/broker/resources/retained/' . str_repeat('0', 32) . '/clear', $token, ['generation' => 1, 'confirmed' => true], 404);
    $body = ['generation' => (int) $item['generation'], 'confirmed' => true];
    $operationId = bin2hex(random_bytes(16));
    $accepted = $request('POST', '/broker/resources/retained/' . $item['id'] . '/clear', $token, $body + ['operation_id' => $operationId], 200);
    expect($accepted['kind'] === 'retain_clear' && $accepted['outcome'] === 'pending', '清保留受理被当成已完成');
    $status = $accepted;
    $deadline = microtime(true) + 12;
    do {
        $status = $request('GET', '/broker/operations/' . $operationId, $token, null, 200);
        if ($status['stage'] === 'completed') {
            break;
        }
        usleep(40000);
    } while (microtime(true) < $deadline);
    expect(
        $status['stage'] === 'completed' && $status['result'] === 'success' && $status['outcome'] === 'cleared',
        '节点执行后清保留仍待处理：' . json_encode($status)
    );
    expect($holder->receive(0.4) === null, '清除保留向在线订阅者制造了普通发布');
    $holder->acknowledge($held['receipt']);
    $done = $request('POST', '/broker/resources/retained/' . $item['id'] . '/clear', $token, $body + ['operation_id' => $operationId], 200);
    expect($done['operation_id'] === $operationId && $done['outcome'] === 'cleared', '完成后重试改变了冻结结果');
    $request('GET', '/broker/resources/retained/' . $item['id'], $token, null, 404);
    $request('GET', '/broker/resources/retained/' . $item['id'] . '/clearance-preview', $token, null, 404);
    $others = $request('GET', '/broker/resources/retained?topic=' . rawurlencode('broker-retain/other') . '&limit=20', $token, null, 200);
    expect($others['items'] !== [] && $others['items'][0]['id'] === $otherItem['items'][0]['id'], '清除误删了其他保留原件');
    $fresh = new Client('127.0.0.1', $port, 'retain-fresh', 'broker-client', $mqttPassword, sessionExpiry: 0, allowPlaintext: true);
    expect(!$fresh->connect(true), '新订阅者不应报告已恢复');
    expect($fresh->subscribe('broker-retain/keep') === 1, '新订阅者应获得 QoS1 订阅');
    expect($fresh->receive(0.8) === null, '清除后新订阅仍重放了旧原件');
    expect($publisher->publish(new Message('broker-retain/keep', 'live-after-clear', '', 1, 0, false)) === 0, '普通发布失败');
    $live = null;
    $deadline = microtime(true) + 6;
    do {
        $live = $holder->receive(0.2);
        if ($live !== null) {
            break;
        }
    } while (microtime(true) < $deadline);
    expect($live !== null && $live['message']->payload === 'live-after-clear' && !$live['message']->retain, '清除后普通消费中断');
    $holder->acknowledge($live['receipt']);
    $stalePayload = 'stale-secret-' . bin2hex(random_bytes(8));
    $secrets[] = $stalePayload;
    expect($publisher->publish(new Message('broker-retain/stale', $stalePayload, '', 1, 0, true)) === 0, '代次对照首次保留失败');
    $staleList = ['items' => []];
    $deadline = microtime(true) + 10;
    do {
        $staleList = $request('GET', '/broker/resources/retained?topic=' . rawurlencode('broker-retain/stale') . '&limit=20', $token, null, 200);
        if ($staleList['items'] !== []) {
            break;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    expect($staleList['items'] !== [], '代次对照保留未进入资源查询');
    $staleItem = $staleList['items'][0];
    $oldGeneration = (int) $staleItem['generation'];
    $replaced = 'replaced-secret-' . bin2hex(random_bytes(8));
    $secrets[] = $replaced;
    expect($publisher->publish(new Message('broker-retain/stale', $replaced, '', 1, 0, true)) === 0, '代次对照替换保留失败');
    $deadline = microtime(true) + 10;
    do {
        $staleList = $request('GET', '/broker/resources/retained?topic=' . rawurlencode('broker-retain/stale') . '&limit=20', $token, null, 200);
        if ($staleList['items'] !== [] && (int) $staleList['items'][0]['generation'] !== $oldGeneration) {
            break;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    expect($staleList['items'] !== [] && $staleList['items'][0]['id'] === $staleItem['id']
        && (int) $staleList['items'][0]['generation'] !== $oldGeneration, '替换未形成新的原件代次');
    $request('POST', '/broker/resources/retained/' . $staleItem['id'] . '/clear', $token, [
        'generation' => $oldGeneration, 'confirmed' => true,
    ], 409);
    $current = $request('GET', '/broker/resources/retained/' . $staleItem['id'], $token, null, 200)['item'];
    expect((int) $current['generation'] !== $oldGeneration, '旧代次清除删除了并发替换的新原件');
    $audit = $request('GET', '/broker/audit?action=broker.retained.clear&operation_id=' . $operationId, $token, null, 200);
    expect($audit['items'] !== [] && $audit['items'][0]['operation_id'] === $operationId, '清保留未留下可对账审计');
    $encoded = json_encode($audit, JSON_THROW_ON_ERROR);
    expect(!str_contains($encoded, $mqttPassword) && !str_contains($encoded, $password)
        && !str_contains($encoded, $keepPayload), '清保留审计包含秘密或载荷');
    $detail = $request('GET', '/broker/audit/' . $audit['items'][0]['id'], $token, null, 200)['item'];
    expect(($detail['operation']['action'] ?? '') === 'broker.retained.clear'
        && ($detail['operation']['impact']['effect'] ?? '') === 'clear_retained', '清保留审计丢失精确目标');
    $appBase = $base . '/app';
    expect(mkdir($appBase, 0700), '无法创建双端清保留测试目录');
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
    $appInstall = new Process([...$command, 'app:install', 'platform-admin', '平台管理员', 'customer-admin', '客户管理员', '清保留租户'], $root, $appEnvironment + [
        'APP_ADMIN_PASSWORD' => $appPassword, 'APP_CUSTOMER_PASSWORD' => $appPassword . '-customer',
    ]);
    try {
        expect($appInstall->wait(45)->successful(), '双端清保留安装失败：' . $appInstall->stderr());
    } finally {
        $appInstall->stop();
    }
    $appServer = new Process([...$command, 'serve'], $root, $appEnvironment);
    $appClient = new HttpClient('http://' . $addresses[2], 8.0);
    $deadline = microtime(true) + 15;
    do {
        expect($appServer->running(), '双端清保留HTTP提前退出：' . $appServer->stderr());
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
    $appRequest('POST', '/customer/tenants/' . $tenantId . '/broker/resources/retained/' . $item['id'] . '/clear', $customerToken, $tenantHeaders, $body, 503);
    $appRequest('GET', '/customer/tenants/' . $tenantId . '/broker/operations/' . $operationId, $customerToken, $tenantHeaders, null, 404);
    $appRequest('GET', '/admin/broker/operations/' . $operationId, $platformToken, [], null, 404);
    $report['http_checks'] = $checks;
    $report['operation'] = ['id' => $operationId, 'outcome' => $status['outcome'], 'stage' => $status['stage']];
    $report['status'] = 'passed';
    if ($browserDist !== '') {
        expect(is_file($browserDist . '/index.html'), '浏览器验收需要本次隔离前端产物');
        $browser = new Process(['node', $root . '/tests/broker-retain-clear-browser.mjs', $base, $browserDist, 'http://' . $addresses[0], 'http://' . $addresses[2]], $root, array_replace(getenv(), [
            'BROKER_BROWSER_PASSWORD' => $password,
            'APP_BROWSER_PASSWORD' => $appPassword,
            'APP_BROWSER_CUSTOMER_PASSWORD' => $appPassword . '-customer',
            'APP_BROWSER_TENANT' => $tenantId,
            'BROKER_BROWSER_TOPIC' => 'broker-retain/other',
        ]));
        $browserResult = $browser->wait(180);
        file_put_contents($base . '/browser.log', str_replace($secrets, '<REDACTED>', $browserResult->stdout . $browserResult->stderr));
        expect($browserResult->successful(), '清保留浏览器失败，见browser-retain-clear-report.json');
        $report['browser'] = json_decode(file_get_contents($base . '/browser-retain-clear-report.json'), true, 32, JSON_THROW_ON_ERROR);
        expect(($report['browser']['status'] ?? '') === 'passed', '清保留浏览器报告未通过');
    }
} finally {
    foreach (['holder' => $holder, 'publisher' => $publisher, 'fresh' => $fresh, 'stale' => $stale] as $role => $mqtt) {
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
expect(($report['status'] ?? '') === 'passed', '清保留验收未通过，见 ' . $base . '/verification.json');
echo 'Broker 指定保留清除通过：' . $base . "/verification.json\n";
exit(0);
