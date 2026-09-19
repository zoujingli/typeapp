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

$root = dirname(__DIR__);
$target = $argv[1] ?? '--php';
$driver = $argv[2] ?? 'sqlite';
expect(in_array($driver, ['sqlite', 'mysql', 'pgsql'], true), '授权验收需要明确的受支持驱动');
if ($target === '--php') {
    $swoole = getenv('TYPE_SWOOLE_MODULE');
    $swoole = is_string($swoole) && $swoole !== '' ? $swoole : rtrim((string) ini_get('extension_dir'), '/') . '/swoole.so';
    expect(is_file($swoole), 'PHP 授权验收需要 TYPE_SWOOLE_MODULE 或 extension_dir 中的 swoole.so');
    $command = [PHP_BINARY, '-d', 'extension=' . $swoole, $root . '/bin/typeapp'];
} else {
    $command = nativeCommand($target);
}
$base = $root . '/build/broker-access-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700, true), '无法创建授权测试目录');
$noSource = in_array('--no-source', $argv, true);
$browserDist = '';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--browser-dist=')) {
        $browserDist = substr($argument, 15);
    }
}
if ($noSource) {
    expect($target !== '--php' && PHP_OS_FAMILY === 'Darwin', '无源码授权验收使用macOS内核策略及原生产物');
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
$environment['DB_SQLITE_FILE'] = 'access.sqlite';
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
$report = ['status' => 'running', 'scope' => 'broker-access', 'native' => $target !== '--php', 'driver' => $driver,
    'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'no_source' => $noSource];
$server = null;
$node = null;
$mqttOld = null;
$mqttNew = null;
$appServer = null;
$browser = null;
$secrets = [];
$revision = [];
$rolled = [];
try {
    $install = new Process([...$command, 'broker:install'], $root, $environment);
    try {
        expect($install->wait(30)->successful(), '独立授权库安装失败：' . $install->stdout() . $install->stderr());
    } finally {
        $install->stop();
    }
    $password = bin2hex(random_bytes(16));
    $secrets[] = $password;
    identityCommand([...$command, 'broker:user', 'access-admin', '授权管理员'], $environment + ['BROKER_ADMIN_PASSWORD' => $password]);
    $addresses = [];
    for ($index = 0; $index < 3; $index++) {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorText);
        expect(is_resource($listener), '无法选择授权测试端口');
        $addresses[] = stream_socket_get_name($listener, false);
        fclose($listener);
    }
    $environment['APP_PORT'] = substr(strrchr($addresses[0], ':'), 1);
    $environment['APP_ALLOWED_HOSTS'] = $addresses[0];
    $server = new Process([...$command, 'broker:serve'], $root, $environment);
    $client = new HttpClient('http://' . $addresses[0], 8.0);
    $deadline = microtime(true) + 10;
    do {
        expect($server->running(), '独立授权管理提前退出：' . $server->stderr());
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
    $auth = $request('POST', '/broker/auth/login', '', ['login' => 'access-admin', 'password' => $password], 200)['data'];
    $token = $auth['accessToken'];
    $secrets[] = $token;
    $request('GET', '/broker/access/principals', '', null, 401);
    $request('GET', '/broker/access/principals', $token, null, 403, ['X-Tenant-Id' => bin2hex(random_bytes(16))]);
    $request('GET', '/broker/access/principals?unknown=1', $token, null, 400);
    $empty = $request('GET', '/broker/access/principals', $token, null, 200);
    expect($empty['items'] === [] && $empty['current_version'] === 0 && ($empty['publish_paused'] ?? false) === false, '未启动节点前不应有引导授权');
    $mqttPassword = bin2hex(random_bytes(16));
    $secrets[] = $mqttPassword;
    $nodeEnvironment = $environment + [
        'BROKER_CLIENT_USERNAME' => 'broker-client', 'BROKER_CLIENT_PASSWORD' => $mqttPassword,
        'BROKER_TOPIC_PREFIX' => 'broker-access/', 'BROKER_NODE_ID' => 'access-node',
        'BROKER_PLAINTEXT' => 'true', 'BROKER_IO_DRIVER' => 'stream',
        'BROKER_PORT' => substr(strrchr($addresses[1], ':'), 1),
    ];
    $node = new Process([...$command, 'broker:run'], $root, $nodeEnvironment);
    $deadline = microtime(true) + 12;
    $nodes = [];
    do {
        expect($node->running(), '授权节点提前退出：' . $node->stderr());
        $nodes = $request('GET', '/broker/nodes', $token, null, 200);
        if (($nodes['total'] ?? 0) === 1 && ($nodes['items'][0]['state'] ?? '') === 'reporting') {
            break;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    expect(($nodes['total'] ?? 0) === 1, '授权节点未上报');
    $listed = $request('GET', '/broker/access/principals', $token, null, 200);
    expect($listed['current_version'] === 1 && count($listed['items']) === 1 && $listed['items'][0]['login'] === 'broker-client'
        && ($listed['publish_paused'] ?? true) === false, '引导主体未写入已生效版本1');
    $principal = $listed['items'][0];
    $request('POST', '/broker/access/principals/' . $principal['id'], $token, [
        'name' => $principal['name'], 'enabled' => 1, 'expected_version' => 0, 'grants' => $principal['grants'],
    ], 409);
    $mqttOld = new Client('127.0.0.1', (int) $nodeEnvironment['BROKER_PORT'], 'access-old', 'broker-client', $mqttPassword, sessionExpiry: 0, allowPlaintext: true);
    expect(!$mqttOld->connect(true), '旧连接不应报告会话恢复');
    expect($mqttOld->subscribe('broker-access/one', 0) === 0, '引导前缀应授予QoS0订阅');
    $connections = ['items' => []];
    $deadline = microtime(true) + 8;
    do {
        $connections = $request('GET', '/broker/resources/connections?limit=20', $token, null, 200);
        if (count($connections['items']) === 1) {
            break;
        }
        try {
            $mqttOld->receive(0.05);
        } catch (ProtocolError) {
            throw new RuntimeException('旧连接在发布前被断开');
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    expect(count($connections['items']) === 1, '真实连接未进入资源观察');
    $rotated = bin2hex(random_bytes(16));
    $secrets[] = $rotated;
    $published = $request('POST', '/broker/access/principals/' . $principal['id'], $token, [
        'name' => $principal['name'], 'enabled' => 1, 'rotate' => 1, 'password' => $rotated,
        'expected_version' => 1, 'grants' => [['topic' => 'broker-access/', 'publish' => 1, 'subscribe' => 1, 'max_qos' => 0]],
        'client_id' => 'access-old',
    ], 200)['data'];
    expect($published['tightening'] === true && $published['version'] === 2, '轮换凭据必须记为收紧并形成版本2');
    $request('POST', '/broker/access/revisions/' . $published['id'] . '/rollback', $token, [], 409);
    $disconnected = false;
    $deadline = microtime(true) + 5.2;
    do {
        try {
            $mqttOld->subscribe('broker-access/two', 0);
        } catch (ProtocolError $failure) {
            expect(in_array($failure->reason, [0x87, 0x83, 0x82, 0x8e], true), '旧连接拒绝原因应是未授权或会话接管');
            $disconnected = true;
            break;
        }
        try {
            $mqttOld->receive(0.15);
        } catch (ProtocolError $failure) {
            expect($failure->reason === 0x87 || $failure->reason === 0x8e, '旧连接断开原因应是未授权或会话接管');
            $disconnected = true;
            break;
        }
    } while (microtime(true) < $deadline);
    expect($disconnected, '健康节点未在5秒内停止旧连接收发并断开');
    $mqttNew = new Client('127.0.0.1', (int) $nodeEnvironment['BROKER_PORT'], 'access-new', 'broker-client', $rotated, sessionExpiry: 0, allowPlaintext: true);
    expect(!$mqttNew->connect(true), '新代次连接失败');
    expect($mqttNew->subscribe('broker-access/allowed', 0) === 0, '新凭据应获得收紧后的前缀订阅');
    $oldRejected = false;
    try {
        $stale = new Client('127.0.0.1', (int) $nodeEnvironment['BROKER_PORT'], 'access-stale', 'broker-client', $mqttPassword, sessionExpiry: 0, allowPlaintext: true);
        $stale->connect(true);
    } catch (ProtocolError) {
        $oldRejected = true;
    }
    expect($oldRejected, '旧秘密在收紧后不能再次认证');
    $deadline = microtime(true) + 12;
    do {
        $revision = $request('GET', '/broker/access/revisions/' . $published['id'], $token, null, 200)['item'];
        if ($revision['status'] === 'effective' && $revision['nodes'] !== []) {
            break;
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    expect(($revision['status'] ?? '') === 'effective' && $revision['nodes'] !== [], '节点必须上报已加载版本');
    $audit = $request('GET', '/broker/audit?action=broker.access.publish&result=success', $token, null, 200);
    expect($audit['items'] !== [] && $audit['items'][0]['details']['reason'] === 'access_published', '授权发布必须留下脱敏审计');
    $mqttNew->stop();
    $mqttNew = null;
    expect($node->stop(5)->successful(), '授权节点未正常排空');
    $node = null;
    $expanded = $request('POST', '/broker/access/principals', $token, [
        'name' => '第二主体', 'login' => 'broker-second', 'password' => bin2hex(random_bytes(16)),
        'enabled' => 1, 'expected_version' => $request('GET', '/broker/access/principals', $token, null, 200)['current_version'],
        'grants' => [['topic' => 'broker-second/', 'publish' => 1, 'subscribe' => 1, 'max_qos' => 0]],
    ], 200)['data'];
    expect($expanded['tightening'] === false && in_array($expanded['status'], ['pending', 'partial'], true), '无上报节点时新增主体必须保持待生效');
    $paused = $request('POST', '/broker/access/principals/' . $principal['id'], $token, [
        'name' => $principal['name'], 'enabled' => 1, 'expected_version' => $expanded['version'], 'grants' => $principal['grants'],
    ], 409);
    expect(($paused['error'] ?? '') === 'broker_access_publish_paused', '部分未完成时后续发布必须暂停');
    $rolled = $request('POST', '/broker/access/revisions/' . $expanded['id'] . '/rollback', $token, [], 200)['data'];
    expect($rolled['version'] > $expanded['version'] && $rolled['status'] !== 'rolled_back', '回退必须形成新版本');
    $retry = $request('POST', '/broker/access/revisions/' . $rolled['id'] . '/retry', $token, [], 200)['data'];
    expect($retry['id'] === $rolled['id'], '重试必须作用于同一版本');
    $request('GET', '/admin/broker/access/principals', $token, null, 404);
    $appBase = $base . '/app';
    expect(mkdir($appBase, 0700), '无法创建双端授权测试目录');
    $appEnvironment = $environment;
    $appEnvironment['APP_BASE_PATH'] = $appBase;
    $appEnvironment['DB_SQLITE_FILE'] = 'app.sqlite';
    $appEnvironment['APP_PORT'] = substr(strrchr($addresses[2], ':'), 1);
    $appEnvironment['APP_ALLOWED_HOSTS'] = $addresses[2];
    unset($appEnvironment['BROKER_CLIENT_USERNAME'], $appEnvironment['BROKER_CLIENT_PASSWORD'], $appEnvironment['BROKER_TOPIC_PREFIX'], $appEnvironment['BROKER_NODE_ID'], $appEnvironment['BROKER_PLAINTEXT'], $appEnvironment['BROKER_IO_DRIVER'], $appEnvironment['BROKER_PORT']);
    $appPassword = bin2hex(random_bytes(16));
    $secrets[] = $appPassword;
    $appInstall = new Process([...$command, 'app:install', 'platform-admin', '平台管理员', 'customer-admin', '客户管理员', '授权租户'], $root, $appEnvironment + [
        'APP_ADMIN_PASSWORD' => $appPassword, 'APP_CUSTOMER_PASSWORD' => $appPassword . '-customer',
    ]);
    try {
        expect($appInstall->wait(45)->successful(), '双端授权安装失败：' . $appInstall->stderr());
    } finally {
        $appInstall->stop();
    }
    $appServer = new Process([...$command, 'serve'], $root, $appEnvironment);
    $appClient = new HttpClient('http://' . $addresses[2], 8.0);
    $deadline = microtime(true) + 15;
    do {
        expect($appServer->running(), '双端授权HTTP提前退出：' . $appServer->stderr());
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
    expect(in_array('/broker-access', menuPaths($me['menus']), true), '客户最高管理员应看到授权菜单');
    $appRequest('GET', '/customer/tenants/' . $tenantId . '/broker/access/principals', $customerToken, $tenantHeaders, null, 200);
    $appRequest('GET', '/admin/broker/access/principals', $platformToken, [], null, 200);
    $appRequest('GET', '/customer/tenants/' . $tenantId . '/broker/access/principals', $customerToken, ['X-Tenant-Id' => bin2hex(random_bytes(16))], null, 403);
    $appRequest('GET', '/broker/access/principals', $customerToken, $tenantHeaders, null, 404);
    $created = $appRequest('POST', '/customer/tenants/' . $tenantId . '/broker/access/principals', $customerToken, $tenantHeaders, [
        'name' => '租户主体', 'login' => 'tenant-client', 'password' => bin2hex(random_bytes(16)),
        'enabled' => 1, 'expected_version' => 0, 'grants' => [['topic' => 'iot/' . $tenantId . '/', 'publish' => 1, 'subscribe' => 1, 'max_qos' => 0]],
    ], 200)['data'];
    expect($created['actor_realm'] === 'customer' && $created['version'] === 1, '租户发布必须记录客户操作者');
    $roles = $appRequest('GET', '/customer/roles', $customerToken, $tenantHeaders, null, 200)['data'];
    expect(isset($roles['catalog']['customer.broker.write']), '客户目录必须包含授权写入节点');
    $report['http_checks'] = $checks;
    $report['revision'] = ['id' => $revision['id'] ?? '', 'status' => $revision['status'] ?? '', 'version' => $revision['version'] ?? 0];
    $report['rollback'] = ['id' => $rolled['id'] ?? '', 'version' => $rolled['version'] ?? 0, 'status' => $rolled['status'] ?? ''];
    $report['status'] = 'passed';
    if ($browserDist !== '') {
        expect(is_file($browserDist . '/index.html'), '浏览器验收需要本次隔离前端产物');
        $browser = new Process(['node', $root . '/tests/broker-access-browser.mjs', $base, $browserDist, 'http://' . $addresses[0], 'http://' . $addresses[2]], $root, array_replace(getenv(), [
            'BROKER_BROWSER_PASSWORD' => $password,
            'APP_BROWSER_PASSWORD' => $appPassword,
            'APP_BROWSER_CUSTOMER_PASSWORD' => $appPassword . '-customer',
            'APP_BROWSER_TENANT' => $tenantId,
        ]));
        $browserResult = $browser->wait(180);
        file_put_contents($base . '/browser.log', str_replace($secrets, '<REDACTED>', $browserResult->stdout . $browserResult->stderr));
        expect($browserResult->successful(), '授权浏览器失败，见browser-access-report.json');
        $report['browser'] = json_decode(file_get_contents($base . '/browser-access-report.json'), true, 32, JSON_THROW_ON_ERROR);
        expect(($report['browser']['status'] ?? '') === 'passed', '授权浏览器报告未通过');
    }
} finally {
    foreach ([$mqttOld, $mqttNew] as $mqttClient) {
        try {
            $mqttClient?->stop();
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
    if (($report['status'] ?? '') !== 'passed') {
        $report['status'] = 'failed';
    }
    $report['http_checks'] = $checks;
    file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
expect(($report['status'] ?? '') === 'passed', '授权验收未通过，见 ' . $base . '/verification.json');
echo 'Broker 授权通过：' . $base . "/verification.json\n";
exit(0);
