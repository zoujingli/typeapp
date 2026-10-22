<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';
require __DIR__ . '/postgres-sync.php';

use Type\Testing\HttpClient;
use Type\Testing\Process;

$root = dirname(__DIR__);
expect(isset($argv[1], $argv[2]) && ctype_digit($argv[2]), '用法：php tests/iot-lifecycle-browser-fixture.php <原生产物或--php> <隔离HTTP端口>；TYPE_PGSQL_TOOLS提供原生工具');
$command = $argv[1] === '--php' ? [PHP_BINARY, '-d', 'memory_limit=512M', $root . '/bin/typeapp'] : nativeCommand($argv[1]);
$port = (int) $argv[2];
expect($port > 1024 && $port < 65536, 'HTTP端口无效');
$base = $root . '/build/iot-lifecycle-browser-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建隔离浏览器装置');
$tools = NativeDatabase::tools('pgsql', (string) getenv('TYPE_PGSQL_TOOLS'));
$database = new NativeDatabase($base . '/primary', 'pgsql', $tools);
$sync = null;
$server = null;
$broker = null;
$stop = false;
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function () use (&$stop): void {
    $stop = true;
});
pcntl_signal(SIGINT, static function () use (&$stop): void {
    $stop = true;
});
try {
    $sync = new PostgresSync($database, $base . '/standby', $tools);
    $environment = array_replace(getenv(), $database->environment());
    foreach (array_keys($environment) as $key) {
        if (str_starts_with($key, 'APP_') || str_starts_with($key, 'DB_') || str_starts_with($key, 'IOT_')) {
            unset($environment[$key]);
        }
    }
    $environment = array_replace($environment, ['PATH' => (string) getenv('PATH'), 'APP_BASE_PATH' => $base, 'DB_DRIVER' => 'pgsql',
        'APP_API_TOKEN' => bin2hex(random_bytes(32)), 'APP_CACHE_ENABLED' => 'false', 'APP_DEBUG' => 'true', 'APP_PORT' => (string) $port,
        'APP_ALLOWED_HOSTS' => '127.0.0.1:' . $port,
        'APP_ADMIN_PASSWORD' => 'Lifecycle-admin-password-2026', 'APP_CUSTOMER_PASSWORD' => 'Lifecycle-browser-password-2026']);
    foreach (['HOST' => 'HOST', 'PORT' => 'PORT', 'DATABASE' => 'DATABASE', 'USERNAME' => 'USER', 'PASSWORD' => 'PASSWORD'] as $destination => $source) {
        $environment['DB_' . $destination] = $environment['TYPE_PGSQL_' . $source];
    }
    $certificateConfiguration = $base . '/certificate.cnf';
    file_put_contents($certificateConfiguration, "[req]\ndistinguished_name=dn\nx509_extensions=server\n[dn]\n[server]\nsubjectAltName=IP:127.0.0.1\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\nextendedKeyUsage=serverAuth\n");
    $options = ['config' => $certificateConfiguration, 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
    $key = openssl_pkey_new($options);
    $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key, $options);
    $certificate = openssl_csr_sign($csr, null, $key, 1, $options);
    openssl_x509_export($certificate, $certificatePem);
    openssl_pkey_export($key, $privatePem);
    file_put_contents($base . '/certificate.pem', $certificatePem);
    file_put_contents($base . '/private.pem', $privatePem);
    chmod($base . '/private.pem', 0600);
    $listener = stream_socket_server('tcp://127.0.0.1:0', $number, $error);
    expect(is_resource($listener), '无法分配Broker端口');
    $mqttPort = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
    fclose($listener);
    $environment += ['IOT_MQTT_COMMAND' => json_encode($command, JSON_THROW_ON_ERROR), 'IOT_MQTT_CERTIFICATE' => 'certificate.pem',
        'IOT_MQTT_PRIVATE_KEY' => 'private.pem', 'IOT_MQTT_PORT' => (string) $mqttPort, 'IOT_MQTT_NODE_ID' => 'lifecycle-browser'];
    $run = static function (array $arguments) use ($command, $root, $environment): string {
        $process = new Process([...$command, ...$arguments], $root, $environment);
        try {
            $result = $process->wait(30);
            expect($result->successful(), $result->stderr);
            return $result->stdout;
        } finally {
            $process->stop();
        }
    };
    $installed = json_decode($run(['app:install', 'lifecycle-platform', '生命周期管理人员', 'webadmin', '生命周期客户管理员', '生命周期验收组织']), true, 32, JSON_THROW_ON_ERROR)['data'];
    $run(['iot:mqtt-install']);
    $server = new Process([...$command, 'serve'], $root, $environment);
    $client = new HttpClient('http://127.0.0.1:' . $port);
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        try {
            if ($client->request('GET', '/readyz')->status === 200) {
                $ready = true;
                break;
            }
        } catch (Throwable) {
        }
        usleep(100000);
    }
    expect($ready, '生命周期HTTP未就绪');
    $call = static function (string $method, string $path, array $data, ?string $token = null, ?string $tenant = null) use ($client): array {
        $headers = ['Content-Type' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        if ($tenant !== null) {
            $headers['X-Tenant-Id'] = $tenant;
        }
        $response = $client->request($method, $path, $headers, $method === 'GET' ? '' : json_encode($data, JSON_THROW_ON_ERROR));
        expect($response->status >= 200 && $response->status < 300, $response->body);
        return $response->json();
    };
    $platform = $call('POST', '/admin/auth/login', ['login' => 'lifecycle-platform', 'password' => $environment['APP_ADMIN_PASSWORD']])['data']['accessToken'];
    $token = $call('POST', '/customer/auth/login', ['login' => 'webadmin', 'password' => $environment['APP_CUSTOMER_PASSWORD']])['data']['accessToken'];
    $tenant = $installed['tenant_id'];
    $other = $call('POST', '/admin/tenants', ['id' => bin2hex(random_bytes(16)), 'name' => '生命周期空组织', 'new_customer' => false, 'owner_login' => 'webadmin'], $platform)['data']['id'];
    $path = '/customer/tenants/' . $tenant;
    $members = [];
    foreach ([$tenant, $other] as $index => $memberTenant) {
        $role = $call('POST', '/customer/roles', ['name' => '生命周期只读', 'permissions' => ['identity.read', 'customer.devices.read']], $token, $memberTenant)['data'];
        $role = $call('POST', '/customer/roles/' . $role['id'] . '/status', ['version' => 1, 'enabled' => true], $token, $memberTenant)['data'];
        $values = ['login' => 'webread', 'new_customer' => $index === 0, 'name' => '生命周期只读人员', 'roles' => [['id' => $role['id'], 'version' => $role['version']]]];
        if ($index === 0) {
            $values += ['account_name' => '生命周期只读客户', 'password' => $environment['APP_CUSTOMER_PASSWORD']];
        }
        $members[] = $call('POST', '/customer/members', $values, $token, $memberTenant)['data'];
    }
    $member = $members[0];
    $grant = $call('POST', '/admin/device-authorizations', ['id' => bin2hex(random_bytes(16)), 'tenant_id' => $tenant,
        'name' => '生命周期页面验收', 'capacity' => 4, 'register_expires_at' => time() + 3600], $platform, null)['data'];
    $devices = [];
    foreach (['main' => '生命周期长设备名称' . str_repeat('用于窄屏换行', 8), 'lost' => '响应丢失设备', 'conflict' => '并发变更设备', 'switch' => '切租户凭据清理设备'] as $purpose => $name) {
        $devices[$purpose] = $call('POST', '/public/devices/register', ['id' => bin2hex(random_bytes(16)), 'tenant_id' => $tenant,
            'device_id' => bin2hex(random_bytes(16)), 'name' => $name, 'code' => $grant['code']], '', null)['data']['device'];
    }
    $metadata = ['pid' => getmypid(), 'base' => $base, 'port' => $port, 'mqtt_port' => $mqttPort, 'native' => $argv[1] !== '--php',
        'binary_sha256' => $argv[1] === '--php' ? null : hash_file('sha256', $argv[1]), 'tenant' => $tenant, 'other' => $other, 'member' => $member['id'], 'member_version' => $member['version'],
        'devices' => array_map(static fn (array $device): string => $device['id'], $devices), 'broker_start_marker' => $base . '/start-broker'];
    file_put_contents($base . '/fixture.json', json_encode($metadata, JSON_THROW_ON_ERROR));
    echo json_encode($metadata, JSON_THROW_ON_ERROR), "\n";
    while (!$stop && $server->running()) {
        if ($broker === null && is_file($metadata['broker_start_marker'])) {
            $broker = new Process([...$command, 'iot:mqtt'], $root, $environment);
        }
        if ($broker !== null) {
            expect($broker->running(), '隔离Broker提前退出：' . $broker->stderr());
        }
        usleep(100000);
    }
} finally {
    $brokerResult = $broker?->stop(10);
    $serverResult = $server?->stop(10);
    foreach (['broker' => $broker, 'http' => $server] as $role => $process) {
        if ($process !== null) {
            file_put_contents($base . '/' . $role . '.log', str_replace([$environment['APP_ADMIN_PASSWORD'], $environment['APP_CUSTOMER_PASSWORD']], '<REDACTED>', $process->stdout() . $process->stderr()));
        }
    }
    $sync?->close();
    $database->close();
    if (is_file($base . '/private.pem')) {
        unlink($base . '/private.pem');
    }
    file_put_contents($base . '/cleanup.json', json_encode(['broker_stopped' => $brokerResult?->successful(), 'http_stopped' => $serverResult?->successful(),
        'database' => $database->evidence(), 'private_key_removed' => !is_file($base . '/private.pem')], JSON_THROW_ON_ERROR));
}
