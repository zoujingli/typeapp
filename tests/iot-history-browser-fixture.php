<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use app\iot\service\DeviceService;
use app\iot\service\CommandService;
use app\iot\service\IngestionService;
use app\common\service\IdentityService;
use Type\Orm\Connection;
use Type\Orm\Database;
use Type\Orm\Sqlite\SqliteDriver;
use Type\Runtime\ExecutionScope;
use Type\Testing\HttpClient;
use Type\Testing\Process;

$root = dirname(__DIR__);
expect(isset($argv[1], $argv[2]) && ctype_digit($argv[2]), '用法：php tests/iot-history-browser-fixture.php <原生产物或--php> <隔离HTTP端口>');
$command = $argv[1] === '--php' ? [PHP_BINARY, $root . '/bin/typeapp'] : nativeCommand($argv[1]);
$port = (int) $argv[2];
$aggregate = in_array('--aggregate', $argv, true);
$alarms = in_array('--alarms', $argv, true);
$exports = in_array('--exports', $argv, true);
$commands = in_array('--commands', $argv, true);
$modelSwitches = in_array('--models', $argv, true);
$transfers = in_array('--transfers', $argv, true);
$notices = in_array('--notices', $argv, true);
$support = in_array('--support', $argv, true);
expect(!$support, '旧支持装置须在对应任务迁移后启用，不能沿用旧身份通过验收');
$noticeRedis = null;
expect($port > 1024 && $port < 65536, '端口范围无效');
$base = $root . '/build/iot-history-browser-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建隔离浏览器装置');
$environment = getenv();
foreach (array_keys($environment) as $key) {
    if (str_starts_with($key, 'APP_') || str_starts_with($key, 'DB_') || str_starts_with($key, 'IOT_')) {
        unset($environment[$key]);
    }
}
$environment = array_replace($environment, ['APP_BASE_PATH' => $base, 'DB_DRIVER' => 'sqlite', 'DB_SQLITE_FILE' => 'history.sqlite',
    'APP_API_TOKEN' => bin2hex(random_bytes(32)), 'APP_CACHE_ENABLED' => 'false', 'APP_PORT' => (string) $port,
    'APP_ALLOWED_HOSTS' => '127.0.0.1:' . $port, 'APP_ADMIN_PASSWORD' => 'History-platform-password-2026', 'APP_CUSTOMER_PASSWORD' => 'History-browser-password-2026']);
if ($notices) {
    require_once __DIR__ . '/native-rollout-redis.php';
    expect($alarms && is_string(getenv('TYPE_REDIS_SERVER')), '通知浏览器装置需要--alarms和TYPE_REDIS_SERVER');
    $noticeRedis = new NativeRolloutRedis($base . '/notice-redis', getenv('TYPE_REDIS_SERVER'));
    register_shutdown_function(static function () use ($noticeRedis): void {
        $noticeRedis->close();
    });
    $environment['IOT_NOTICES_REDIS_HOST'] = $noticeRedis->environment()['TYPE_REDIS_HOST'];
    $environment['IOT_NOTICES_REDIS_PORT'] = $noticeRedis->environment()['TYPE_REDIS_PORT'];
    $environment['IOT_NOTICES_NAMESPACE'] = 'browser-' . basename($base);
}
$run = static function (array $arguments) use ($command, $root, $environment): array {
    $process = new Process([...$command, ...$arguments], $root, $environment);
    try {
        $result = $process->wait(30);
        expect($result->successful(), $result->stderr);
        return json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
    } finally {
        $process->stop();
    }
};
$installed = $run(['app:install', 'webplatform', '浏览器平台管理员', 'webadmin', '浏览器客户管理员', '历史数据验收组织'])['data'];
$server = new Process([...$command, 'serve'], $root, $environment);
$redis = null;
$client = new HttpClient('http://127.0.0.1:' . $port);
$stop = false;
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function () use (&$stop): void {
    $stop = true;
});
pcntl_signal(SIGINT, static function () use (&$stop): void {
    $stop = true;
});
try {
    if ($exports) {
        require __DIR__ . '/native-rollout-redis.php';
        $redis = new NativeRolloutRedis($base . '/redis', (string) getenv('TYPE_REDIS_SERVER'));
    }
    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        try {
            if ($client->request('GET', '/readyz')->status === 200) {
                $ready = true;
                break;
            }
        } catch (Throwable) {
        }
        usleep(100000);
    }
    expect($ready, '历史浏览器HTTP未就绪');
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
    $platform = $call('POST', '/admin/auth/login', ['login' => 'webplatform', 'password' => $environment['APP_ADMIN_PASSWORD']])['data']['accessToken'];
    $token = $call('POST', '/customer/auth/login', ['login' => 'webadmin', 'password' => $environment['APP_CUSTOMER_PASSWORD']])['data']['accessToken'];
    $tenant = $installed['tenant_id'];
    $other = $call('POST', '/admin/tenants', ['id' => bin2hex(random_bytes(16)), 'name' => '历史空数据组织', 'new_customer' => false, 'owner_login' => 'webadmin'], $platform)['data']['id'];
    $transferTenant = $transfers ? $call('POST', '/admin/tenants', ['id' => bin2hex(random_bytes(16)), 'name' => '转移接收组织', 'new_customer' => true,
        'owner_login' => 'webtarget', 'owner_name' => '转移目标管理员', 'owner_password' => $environment['APP_CUSTOMER_PASSWORD']], $platform)['data']['id'] : '';
    $path = '/customer/tenants/' . $tenant;
    $reader = static function (string $scope, string $owner, bool $create, string $login = 'webread', ?array $permissions = null) use ($call, $environment): array {
        $role = $call('POST', '/customer/roles', ['name' => '浏览器权限-' . $login, 'permissions' => $permissions ?? ['identity.read', 'customer.devices.read', 'customer.products.read',
            'customer.telemetry.read', 'customer.commands.read', 'customer.transfers.read', 'customer.alarms.read', 'customer.notifications.read']], $owner, $scope)['data'];
        $call('POST', '/customer/roles/' . $role['id'] . '/status', ['version' => 1, 'enabled' => true], $owner, $scope);
        $input = ['login' => $login, 'new_customer' => $create, 'name' => '浏览器成员-' . $login, 'roles' => [['id' => $role['id'], 'version' => 2]]];
        if ($create) {
            $input += ['account_name' => '浏览器客户-' . $login, 'password' => $environment['APP_CUSTOMER_PASSWORD']];
        }
        return $call('POST', '/customer/members', $input, $owner, $scope)['data'];
    };
    $member = $reader($tenant, $token, true);
    $reader($other, $token, false);
    if ($exports) {
        $reader($tenant, $token, true, 'webexport', ['identity.read', 'customer.exports.create']);
    }
    if ($commands) {
        foreach (['webcontrol' => 'create', 'webcancel' => 'cancel'] as $login => $action) {
            $reader($tenant, $token, true, $login, ['identity.read', 'customer.commands.' . $action]);
        }
    }
    if ($transfers) {
        $targetToken = $call('POST', '/customer/auth/login', ['login' => 'webtarget', 'password' => $environment['APP_CUSTOMER_PASSWORD']])['data']['accessToken'];
        $reader($transferTenant, $targetToken, false);
    }
    $product = $call('POST', $path . '/products', ['name' => '历史多类型传感器'], $token, $tenant)['data'];
    $definition = ['properties' => [
        ['identifier' => 'temperature', 'name' => '温度', 'type' => 'number', 'required' => false, 'unit' => '°C'],
        ['identifier' => 'relay', 'name' => '继电器', 'type' => 'boolean', 'required' => false],
        ['identifier' => 'note', 'name' => '备注', 'type' => 'string', 'required' => false, 'max_length' => 4096],
    ], 'events' => [], 'commands' => []];
    if ($aggregate || $modelSwitches) {
        $definition['properties'][0]['name'] = '设备历史温度长名称用于窄屏详情换行验收';
    }
    if ($commands || $transfers) {
        $definition['commands'] = [['identifier' => 'switch', 'name' => '继电器单次控制长名称用于窄屏指令详情验收', 'parameters' => [
            ['identifier' => 'on', 'name' => '继电器开启', 'type' => 'boolean', 'required' => true],
            ['identifier' => 'note', 'name' => '操作说明', 'type' => 'string', 'required' => false, 'max_length' => 4096],
        ]]];
    }
    $models = $path . '/products/' . $product['id'] . '/models';
    $call('POST', $models, ['definition' => $definition], $token, $tenant);
    $call('POST', $models . '/1/publish', ['version' => 1], $token, $tenant);
    $device = $call('POST', $path . '/devices', ['name' => '历史曲线设备', 'product_id' => $product['id'], 'model_version' => 1], $token, $tenant)['data']['device'];
    $empty = $call('POST', $path . '/devices', ['name' => '尚未上报设备', 'product_id' => $product['id'], 'model_version' => 1], $token, $tenant)['data']['device'];
    $pool = new Database(new SqliteDriver($base . '/history.sqlite', 1000, true), 1, 1);
    $scope = new ExecutionScope();
    $now = time() - 10;
    try {
        $fixtureConnection = $pool->connect($scope);
        $actor = (new IdentityService('customer'))->authenticate($token);
        expect($actor !== null, '浏览器数据装置必须使用真实客户会话');
        if ($transfers) {
            $node = 'transfer-browser';
            $runId = bin2hex(random_bytes(16));
            $fixtureConnection->table('iot_devices')->where('id', '=', $device['id'])->update(['lifecycle' => 'enabled', 'name' => str_repeat('转移设备长名称', 12)]);
            $fixtureConnection->table('iot_broker_observations')->insert(['node_id' => $node, 'run_id' => $runId, 'observed_at' => time(), 'expires_at' => time() + 3600]);
            $fixtureConnection->table('iot_device_connections')->where('device_id', '=', $device['id'])->update(['status' => 'online', 'node_id' => $node, 'run_id' => $runId, 'observed_at' => time()]);
        }
        if ($commands) {
            $node = 'command-browser';
            $runId = bin2hex(random_bytes(16));
            $fixtureConnection->table('iot_devices')->where('id', '=', $device['id'])->update(['lifecycle' => 'enabled']);
            $fixtureConnection->table('iot_broker_observations')->insert(['node_id' => $node, 'run_id' => $runId, 'observed_at' => time(), 'expires_at' => time() + 3600]);
            $fixtureConnection->table('iot_device_connections')->where('device_id', '=', $device['id'])->update(['status' => 'online', 'node_id' => $node, 'run_id' => $runId, 'observed_at' => time()]);
            $fixtureCommand = CommandService::create($fixtureConnection, $actor, $tenant, $device['id'], 'switch', (object) ['on' => true, 'note' => str_repeat('窄屏多行说明<仅文字>', 80)], bin2hex(random_bytes(16)), (int) $device['version']);
            $dispatch = $fixtureConnection->transaction(static fn (Connection $transaction): ?array => CommandService::claim($transaction), 'immediate');
            $fixtureConnection->transaction(static fn (Connection $transaction): array => CommandService::transport($transaction, $fixtureCommand['id'], 0), 'immediate');
            $receipt = ['app_version' => 1, 'type' => 'command_receipt', 'device_id' => $device['id'], 'ownership_id' => $device['ownership_id'],
                'command_id' => $fixtureCommand['id'], 'content_hash' => $fixtureCommand['content_hash'], 'status' => 'succeeded', 'code' => 'executed',
                'started_at' => $fixtureCommand['accepted_at'], 'finished_at' => $fixtureCommand['accepted_at'] + 1, 'result' => (object) ['note' => str_repeat('长执行结果<仅文字>', 80)]];
            $fixtureConnection->transaction(static fn (Connection $transaction): array => IngestionService::accept($transaction, DeviceService::topics($device)['publish'], json_encode($receipt, JSON_THROW_ON_ERROR), 1, time()), 'immediate');
            $unknown = CommandService::create($fixtureConnection, $actor, $tenant, $device['id'], 'switch', (object) ['on' => false], bin2hex(random_bytes(16)), (int) $fixtureConnection->table('iot_devices')->where('id', '=', $device['id'])->first()['version']);
            $unknownIssued = time() - 360;
            $fixtureConnection->table('iot_commands')->where('id', '=', $unknown['id'])->update(['accepted_at' => $unknownIssued, 'deadline_at' => $unknownIssued + 60, 'dispatch_at' => $unknownIssued, 'next_action_at' => $unknownIssued + 300, 'schedule_stage' => 5]);
            $queryDispatch = $fixtureConnection->transaction(static fn (Connection $transaction): ?array => CommandService::claim($transaction), 'immediate');
            $fixtureConnection->transaction(static fn (Connection $transaction): array => CommandService::transport($transaction, $unknown['id'], 0, $queryDispatch['attempt_id']), 'immediate');
            $queryResponse = array_replace(json_decode($queryDispatch['payload'], true, 32, JSON_THROW_ON_ERROR), ['type' => 'command_query_result', 'receipt' => null]);
            $fixtureConnection->transaction(static fn (Connection $transaction): array => IngestionService::accept($transaction, DeviceService::topics($device)['publish'], json_encode($queryResponse, JSON_THROW_ON_ERROR), 1, time()), 'immediate');
        }
        $fixtureConnection->transaction(static function (Connection $transaction) use ($device, $now): void {
            for ($i = 1; $i <= 2051; $i++) {
                $values = $i === 2050 ? ['note' => '这次缺少温度'] : ['temperature' => 20 + sin($i / 12) * 5];
                if ($i === 2051) {
                    $values += ['relay' => false, 'note' => '原始长文本' . str_repeat('<script>仅作为文字</script>', 80)];
                }
                $message = ['app_version' => 1, 'type' => 'telemetry', 'device_id' => $device['id'], 'ownership_id' => $device['ownership_id'],
                    'model_version' => 1, 'sequence' => (string) $i, 'sampled_at' => $i <= 2001 ? $now - 7200 + $i : $now - 2051 + $i, 'values' => $values];
                $receipt = IngestionService::accept($transaction, DeviceService::topics($device)['publish'], json_encode($message, JSON_THROW_ON_ERROR), 1, $now);
                expect($receipt['code'] === 'accepted', '浏览器事实必须通过真正接收校验');
            }
        }, 'immediate');
        if ($modelSwitches) {
            $service = new DeviceService();
            $nextDefinition = $definition;
            $nextDefinition['properties'][0]['unit'] = '°F';
            $call('POST', $models, ['definition' => $nextDefinition], $token, $tenant);
            $target = $call('POST', $models . '/2/publish', ['version' => 1], $token, $tenant)['data'];
            $call('POST', $models, ['definition' => $definition], $token, $tenant);
            $node = 'model-browser';
            $runId = bin2hex(random_bytes(16));
            $fixtureConnection->table('iot_devices')->where('id', '=', $device['id'])->update(['lifecycle' => 'enabled']);
            $fixtureConnection->table('iot_broker_observations')->insert(['node_id' => $node, 'run_id' => $runId, 'observed_at' => time(), 'expires_at' => time() + 3600]);
            $fixtureConnection->table('iot_device_connections')->where('device_id', '=', $device['id'])->update(['status' => 'online', 'node_id' => $node, 'run_id' => $runId, 'observed_at' => time()]);
            $switch = $service->switchModel($fixtureConnection, $actor, $tenant, $device['id'], bin2hex(random_bytes(16)), 2, (int) $fixtureConnection->table('iot_devices')->where('id', '=', $device['id'])->first()['version']);
            $fixtureConnection->transaction(static fn (Connection $tx): ?array => DeviceService::claimModelSwitch($tx), 'immediate');
            $receipt = ['app_version' => 1, 'type' => 'model_switch_receipt', 'device_id' => $device['id'], 'ownership_id' => $device['ownership_id'],
                'switch_id' => $switch['id'], 'source_version' => 1, 'target_version' => 2, 'structure_hash' => $target['structure_hash'],
                'boundary_sequence' => '2051', 'status' => 'confirmed', 'code' => 'model_switched'];
            $fixtureConnection->transaction(static fn (Connection $tx): array => IngestionService::accept($tx, DeviceService::topics($device)['publish'], json_encode($receipt, JSON_THROW_ON_ERROR), 1, time()), 'immediate');
            $telemetry = ['app_version' => 1, 'type' => 'telemetry', 'device_id' => $device['id'], 'ownership_id' => $device['ownership_id'],
                'model_version' => 2, 'sequence' => '2052', 'sampled_at' => time(), 'values' => ['temperature' => 86]];
            $fixtureConnection->transaction(static fn (Connection $tx): array => IngestionService::accept($tx, DeviceService::topics($device)['publish'], json_encode($telemetry, JSON_THROW_ON_ERROR), 1, time()), 'immediate');
        }
        if ($aggregate) {
            $oldest = intdiv($now - 89 * 86400, 60) * 60;
            foreach ([3000 => $oldest, 3001 => $now - 8 * 86400] as $sequence => $sampled) {
                $fixtureConnection->transaction(static function (Connection $transaction) use ($device, $sequence, $sampled): void {
                    $message = ['app_version' => 1, 'type' => 'telemetry', 'device_id' => $device['id'], 'ownership_id' => $device['ownership_id'],
                        'model_version' => 1, 'sequence' => (string) $sequence, 'sampled_at' => $sampled, 'values' => ['temperature' => 12.5]];
                    $receipt = IngestionService::accept($transaction, DeviceService::topics($device)['publish'], json_encode($message, JSON_THROW_ON_ERROR), 1, $sampled + 1);
                    expect($receipt['code'] === 'accepted', '过往分钟装置必须通过真实接收校验');
                    // 此装置只有遥测查询，没有告警规则；不声明本次已经实现告警消费者。
                    $transaction->table('iot_ingestion_completed')->insert(['message_id' => $receipt['message_id'], 'consumer' => 'alarm', 'completed_at' => time()]);
                }, 'immediate');
            }
        }
    } finally {
        $scope->close();
        $pool->close();
    }
    if ($aggregate) {
        $rounds = 0;
        do {
            $batch = $run(['iot:aggregate', '100'])['data'];
            $rounds++;
        } while ($batch['has_more'] && $rounds < 30);
        expect(!$batch['has_more'], '浏览器分钟装置未完成有界消费');
        expect($run(['iot:history-clean'])['data']['deleted'] === 2, '浏览器需验证原始已删除后的分钟曲线');
    }
    if ($alarms) {
        $rule = $call('POST', $path . '/alarm-rules', ['name' => str_repeat('温度超限长规则', 10), 'device_id' => $device['id'], 'field' => 'temperature', 'lower' => 0, 'upper' => 10, 'hysteresis' => 2], $token, $tenant)['data'];
        $alarmPool = new Database(new SqliteDriver($base . '/history.sqlite', 1000, true), 1, 1);
        $alarmScope = new ExecutionScope();
        try {
            $alarmConnection = $alarmPool->connect($alarmScope);
            foreach ([1, 2] as $alarmVersion) {
                for ($index = 1; $index <= 3; $index++) {
                    $alarmConnection->transaction(static function (Connection $transaction) use ($device, $now, $alarmVersion, $index): void {
                        $message = ['app_version' => 1, 'type' => 'telemetry', 'device_id' => $device['id'], 'ownership_id' => $device['ownership_id'],
                            'model_version' => 1, 'sequence' => (string) (4000 + $alarmVersion * 10 + $index), 'sampled_at' => $now + $index, 'values' => ['temperature' => 40]];
                        expect(IngestionService::accept($transaction, DeviceService::topics($device)['publish'], json_encode($message, JSON_THROW_ON_ERROR), 1, $now + $index)['code'] === 'accepted', '告警装置须经过真实接收');
                    }, 'immediate');
                }
                do {
                    $batch = $run(['iot:alarm'])['data'];
                } while ($batch['has_more']);
                if ($alarmVersion === 1) {
                    $call('PATCH', $path . '/alarm-rules/' . $rule['id'], ['version' => 1, 'name' => '当前温度规则', 'upper' => 30], $token, $tenant);
                }
            }
        } finally {
            $alarmScope->close();
            $alarmPool->close();
        }
    }
    $metadata = ['pid' => getmypid(), 'base' => $base, 'tenant' => $tenant, 'other' => $other, 'device' => $device['id'], 'empty' => $empty['id'], 'member' => $member['id'], 'member_version' => $member['version'], 'port' => $port];
    if ($support) {
        $metadata['support'] = ['login' => 'websupport', 'member_role' => 'admin'];
    }
    if ($alarms) {
        $metadata['alarms'] = ['rule' => $rule['id']];
    }
    if ($notices) {
        $metadata['notices'] = $run(['iot:notices'])['data'];
    }
    if ($aggregate) {
        $metadata['aggregate'] = ['oldest' => $oldest, 'raw_expired_deleted' => 2, 'worker_rounds' => $rounds];
    }
    if ($exports) {
        $redisEnvironment = $redis->environment();
        $metadata['exports'] = ['command' => $command, 'fixturePhp' => PHP_BINARY, 'environment' => ['APP_BASE_PATH' => $base, 'DB_DRIVER' => 'sqlite', 'DB_SQLITE_FILE' => 'history.sqlite',
            'IOT_EXPORT_REDIS_HOST' => $redisEnvironment['TYPE_REDIS_HOST'], 'IOT_EXPORT_REDIS_PORT' => $redisEnvironment['TYPE_REDIS_PORT'], 'IOT_EXPORT_NAMESPACE' => basename($base)]];
    }
    if ($commands) {
        $metadata['commands'] = ['succeeded' => $fixtureCommand['id'], 'unknown' => $unknown['id'], 'connection' => 'controlled-persistent-observation'];
    }
    if ($modelSwitches) {
        $metadata['models'] = ['confirmed' => $switch['id'], 'connection' => 'controlled-persistent-observation-and-public-receipt'];
    }
    if ($transfers) {
        $metadata['transfers'] = ['target' => $transferTenant, 'fixturePhp' => PHP_BINARY, 'connection' => 'controlled-persistent-observation-and-stage-fixtures-no-mqtt-isolation-proof'];
    }
    file_put_contents($base . '/fixture.json', json_encode($metadata, JSON_THROW_ON_ERROR));
    echo json_encode($metadata, JSON_THROW_ON_ERROR), "\n";
    while (!$stop && $server->running()) {
        usleep(100000);
    }
} finally {
    $server->stop();
    $redis?->close();
    $noticeRedis?->close();
}
