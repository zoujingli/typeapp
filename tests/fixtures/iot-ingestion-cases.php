<?php

declare(strict_types=1);

use app\common\database\Schema;
use app\common\service\RoleService;
use app\common\service\AuditLog;
use app\iot\service\CommandService;
use app\iot\service\DeviceService;
use app\iot\service\DeviceBuffer;
use app\common\service\IdentityService;
use app\iot\service\IngestionService;
use app\iot\service\ModelDefinition;
use app\iot\service\HistoryService;
use app\iot\service\AggregateService;
use app\iot\service\ProductService;
use app\iot\service\TenantService;
use app\iot\service\TransferService;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Orm\Connection;
use Type\Orm\Database;
use Type\Orm\Migration\Migrator;
use Type\Orm\Mysql\MysqlDriver;
use Type\Orm\Pgsql\PgsqlDriver;
use Type\Orm\Sqlite\SqliteDriver;
use Type\Runtime\ExecutionScope;


/** 测试持有真实登录令牌；缓存只在本装置进程中存在，不写入业务来源或审计。 */
function fixtureSession(Connection $connection, string $login, string $realm = 'customer'): array
{
    static $sessions = [];
    $key = $realm . ':' . $login;
    if (!isset($sessions[$key])) {
        $service = new IdentityService($realm);
        $token = $service->login($connection, $login, 'Test-ingestion-password')['accessToken'];
        $sessions[$key] = ['token' => $token, 'identity' => $service->authenticate($connection, $token)];
    }
    return $sessions[$key];
}

/** 沿已登记的角色管理服务创建数据，不补写旧成员或角色字段。 */
function fixtureChange(Connection $connection, Identity $identity, string $action, string $id, array $data, string $tenant = ''): array
{
    $realm = $identity->attributes()['realm'];
    $login = (new IdentityService($realm))->user($connection, $identity->subject())['login'];
    $session = fixtureSession($connection, $login, $realm);
    return RoleService::change($connection, $identity, $session['token'], $action, $id, $data, $realm, $realm === 'admin' ? 'platform' : $tenant);
}

function fixtureTenant(Connection $connection, Identity $platform, string $name, string $owner): array
{
    return fixtureChange($connection, $platform, 'admin.tenants.create', '', ['id' => bin2hex(random_bytes(16)), 'name' => $name, 'new_customer' => false, 'owner_login' => $owner]);
}

function fixtureMember(Connection $connection, Identity $owner, string $tenant, string $login, array $permissions): Identity
{
    $role = fixtureChange($connection, $owner, 'customer.roles.create', '', ['name' => $login, 'permissions' => $permissions], $tenant);
    $role = fixtureChange($connection, $owner, 'customer.roles.status', $role['id'], ['version' => 1, 'enabled' => true], $tenant);
    fixtureChange($connection, $owner, 'customer.members.create', '', ['login' => $login, 'new_customer' => false, 'name' => $login, 'roles' => [['id' => $role['id'], 'version' => $role['version']]]], $tenant);
    return fixtureSession($connection, $login)['identity'];
}

function fixtureDeviceVersion(Connection $connection, string $device): int
{
    return (int) $connection->table('iot_devices')->where('id', '=', $device)->first()['version'];
}

/** 场景装置默认取准确输入；原ID重放保留首次版本，HTTP专项另测过时版本拒绝。 */
function fixtureCommand(Connection $connection, Identity $identity, string $tenant, string $device, string $identifier, mixed $values, string $id = ''): array
{
    $record = $id === '' ? null : $connection->table('iot_commands')->where('id', '=', $id)->first();
    return CommandService::create($connection, $identity, $tenant, $device, $identifier, $values, $id === '' ? bin2hex(random_bytes(16)) : $id,
        $record === null ? fixtureDeviceVersion($connection, $device) : (int) $record['request_version']);
}

function fixtureSwitchVersion(Connection $connection, string $device, string $id, bool $retry): int
{
    $record = $connection->table('iot_model_switches')->where('id', '=', $id)->first();
    return $record !== null && !$retry ? (int) $record['request_version'] : fixtureDeviceVersion($connection, $device);
}

function fixtureTransfer(Connection $connection, Identity $identity, string $tenant, string $device, string $target, string $id): array
{
    $record = $connection->table('iot_transfers')->where('id', '=', $id)->first();
    return TransferService::request($connection, $identity, $tenant, $device, $target, $id, $record === null ? fixtureDeviceVersion($connection, $device) : (int) $record['request_version']);
}

function fixtureDecision(Connection $connection, Identity $identity, string $tenant, string $id, array $decision): array
{
    static $versions = [];
    $record = $connection->table('iot_transfers')->where('id', '=', $id)->first();
    $decision['version'] = $versions[$decision['decision_id']] ?? fixtureDeviceVersion($connection, $record['device_id']);
    $result = TransferService::decide($connection, $identity, $tenant, $id, $decision);
    $versions[$decision['decision_id']] = $decision['version'];
    return $result;
}

function fixtureAdvance(Connection $connection, Identity $identity, string $tenant, string $id, string $switch): array
{
    $record = $connection->table('iot_transfers')->where('id', '=', $id)->first();
    return TransferService::advance($connection, $identity, $tenant, $id, $switch, $record['switch_version'] === null ? fixtureDeviceVersion($connection, $record['device_id']) : (int) $record['switch_version']);
}

/** 设备持久确认与公开历史查询证明版本含义；只用SQL制造连接观察和已经流逝的超时条件。 */
function modelSwitchCases(Connection $connection, Identity $identity, string $tenantId): array
{
    $tenants = new TenantService();
    $products = new ProductService();
    $devices = new DeviceService();
    $product = $products->create($connection, $identity, $tenantId, '切换结构产品', '原版本不可改写');
    $definition = ['properties' => [['identifier' => 'temperature', 'name' => '温度', 'type' => 'number', 'required' => true, 'unit' => '°C', 'min' => -40, 'max' => 300],
        ['identifier' => 'mode', 'name' => '模式', 'type' => 'enum', 'required' => false, 'values' => ['auto', 'manual']]], 'events' => [],
        'commands' => [['identifier' => 'switch', 'name' => '开关', 'parameters' => []]]];
    $equivalent = $definition;
    $equivalent['properties'][0]['name'] = '不同显示名称'; $equivalent['properties'][0]['min'] = -40.0;
    $equivalent['properties'][1]['values'] = ['manual', 'auto']; $equivalent['properties'] = array_reverse($equivalent['properties']);
    expect(ModelDefinition::structurallyEqual($definition, $equivalent), '等价结构被显示名称、顺序或数值写法改变');
    $fahrenheit = $definition; $fahrenheit['properties'][0]['unit'] = '°F';
    $enumeration = $definition; $enumeration['properties'][0] = ['identifier' => 'temperature', 'name' => '温度级别', 'type' => 'enum', 'required' => false, 'values' => ['cold', 'hot']];
    expect(!ModelDefinition::structurallyEqual($definition, $fahrenheit) && !ModelDefinition::structurallyEqual($definition, $enumeration), '单位或类型变化被当成同一结构');
    $models = [];
    foreach ([$definition, $fahrenheit, $enumeration] as $index => $modelDefinition) {
        $products->createModel($connection, $identity, $tenantId, $product['id'], $modelDefinition);
        $models[$index + 1] = $products->changeModel($connection, $identity, $tenantId, $product['id'], $index + 1, 1, 'publish');
    }
    $registered = $devices->register($connection, $identity, $tenantId, $product['id'], 1, '持久切换设备');
    $device = $registered['device']; $id = $device['id']; $topic = $registered['credential']['topics']['publish'];
    $request = static fn (string $requestId, int $target, bool $retry = false): array => $devices->switchModel($connection, $identity, $tenantId, $id, $requestId, $target, fixtureSwitchVersion($connection, $id, $requestId, $retry), $retry);
    $read = static fn (): array => $devices->device($connection, $identity, $tenantId, $id);
    $current = static fn (): array => IngestionService::current($connection, $identity, $tenantId, $id);
    $transaction = static fn (Closure $work): mixed => $connection->transaction($work, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    $send = static fn (string $payload, int $at): array => $transaction(static fn (Connection $tx): array => IngestionService::accept($tx, $topic, $payload, 1, $at));
    $claim = static fn (): ?array => $transaction(static fn (Connection $tx): ?array => DeviceService::claimModelSwitch($tx));
    $requestId = bin2hex(random_bytes(16));
    try { $request($requestId, 2); throw new RuntimeException('未启用设备切换成功'); } catch (HttpError $unavailable) { expect($unavailable->errorCode() === 'model_switch_device_unavailable', '生命周期拒绝不明确'); }
    $connection->table('iot_devices')->where('id', '=', $id)->update(['lifecycle' => 'enabled']);
    $pending = $request($requestId, 2);
    expect($pending['state'] === 'pending' && $read()['model_version'] === 1 && $claim() === null, '管理受理或离线领取伪装切换成功');
    expect($request($requestId, 2)['id'] === $requestId, '同ID请求未返回原事实');
    try { $request($requestId, 3); throw new RuntimeException('同ID换目标成功'); } catch (HttpError $conflict) { expect($conflict->errorCode() === 'model_switch_identity_conflict', '同ID目标冲突不明确'); }
    try { $request(bin2hex(random_bytes(16)), 3); throw new RuntimeException('并发新意图越过待确认切换'); } catch (HttpError $conflict) { expect($conflict->errorCode() === 'model_switch_in_progress', '并发意图拒绝不明确'); }
    $node = 'model-' . $id; $run = bin2hex(random_bytes(16));
    $connection->table('iot_broker_observations')->insert(['node_id' => $node, 'run_id' => $run, 'observed_at' => time(), 'expires_at' => time() + 3600]);
    $connection->table('iot_device_connections')->where('device_id', '=', $id)->update(['status' => 'online', 'node_id' => $node, 'run_id' => $run, 'observed_at' => time()]);
    $connection->table('iot_model_switches')->where('id', '=', $requestId)->update(['next_attempt_at' => time()]);
    $dispatch = $claim(); expect($dispatch !== null && $claim() === null, '同节点重复领取切换');
    $path = sys_get_temp_dir() . '/type-iot-model-' . bin2hex(random_bytes(16)) . '.sqlite';
    $buffer = new DeviceBuffer($path, $id, $device['ownership_id']);
    try {
        expect($buffer->modelVersion(1) === 1, '初始本地绑定错误');
        $sampledAt = intdiv(time(), 60) * 60 - 60;
        $first = $buffer->enqueue(1, 'telemetry', $sampledAt, (object) ['temperature' => 20]);
        $old = $buffer->enqueue(1, 'telemetry', $sampledAt, (object) ['temperature' => 21]);
        $expired = $buffer->enqueue(1, 'telemetry', $sampledAt - 172800, (object) ['temperature' => 22]);
        expect($send($first['payload'], time())['code'] === 'accepted', '旧版初始采样失败');
        $rejected = $buffer->switchModel($dispatch['payload'], []);
        expect($rejected['status'] === 'rejected' && $buffer->modelVersion(1) === 1, '未声明支持却切换了设备');
        $ack = $send(json_encode($rejected, JSON_THROW_ON_ERROR), time());
        expect($read()['model_version'] === 1 && $read()['model_switch'] === null && $buffer->acknowledgeModel($ack['receipt']), '拒绝结果未结束原请求或错误改绑');
        $oldCommand = fixtureCommand($connection, $identity, $tenantId, $id, 'switch', (object) []);
        $transaction(static fn (Connection $tx): ?array => CommandService::claim($tx));
        $requestId = bin2hex(random_bytes(16)); $request($requestId, 2); $dispatch = $claim();
        $confirmed = $buffer->switchModel($dispatch['payload'], [2 => $models[2]['structure_hash']]);
        expect($confirmed['status'] === 'confirmed' && $confirmed['boundary_sequence'] === '3' && $read()['model_version'] === 1, '设备未持久确认前平台已经改绑');
        $buffer->close(); $buffer = new DeviceBuffer($path, $id, $device['ownership_id']);
        expect($buffer->modelVersion(1) === 2 && $buffer->switchModel($dispatch['payload'], []) === $confirmed, '重启或旧配置丢失原切换结果');
        expect($buffer->pending()[0] === $first, '切换重写了旧缓存原件');
        $bad = $confirmed; $bad['boundary_sequence'] = '0';
        expect($send(json_encode($bad, JSON_THROW_ON_ERROR), time())['receipt'] === null && $read()['model_version'] === 1, '不一致边界覆盖已接受序号');
        $ack = $send(json_encode($confirmed, JSON_THROW_ON_ERROR), time());
        $bound = $read(); expect($bound['model_version'] === 2 && $bound['model_switch'] === null && $current()['fields'] === [], '新绑定继承旧当前字段');
        expect($send(json_encode($confirmed, JSON_THROW_ON_ERROR), time())['receipt'] === $ack['receipt'] && $read()['version'] === $bound['version'], '重复确认推进了设备版本');
        expect($buffer->acknowledgeModel($ack['receipt']) && !$buffer->acknowledgeModel($ack['receipt']), '业务ACK未幂等释放待确认');
        $connection->table('iot_commands')->where('id', '=', $oldCommand['id'])->update(['accepted_at' => time() - 360, 'deadline_at' => time() - 300,
            'schedule_stage' => 5, 'next_action_at' => time() - 60]);
        $query = $transaction(static fn (Connection $tx): ?array => CommandService::claim($tx));
        expect($query !== null && $query['id'] === $oldCommand['id'] && $query['kind'] === 'query', '切换模型禁止了同归属原指令结果查询');
        $answer = $buffer->queryCommand(json_decode($query['payload'], true, 32, JSON_THROW_ON_ERROR));
        expect($send(json_encode($answer, JSON_THROW_ON_ERROR), time())['code'] === 'command_query_observed', '切换后原结果查询响应被新模型拒绝');
        expect($send($old['payload'], time())['code'] === 'accepted' && $current()['fields'] === [], '合法旧版本补传未进历史或推进了新当前');
        expect($send($expired['payload'], time())['code'] === 'sample_expired', '旧版本绕过48小时首次接收窗');
        $new = $buffer->enqueue(2, 'telemetry', $sampledAt, (object) ['temperature' => 200]);
        expect($send($new['payload'], time())['code'] === 'accepted' && $current()['fields'][0]['value'] === 200, '新模型采样未按新单位推进');
        $forged = json_decode($new['payload'], true, 32, JSON_THROW_ON_ERROR); $forged['sequence'] = '900'; $forged['model_version'] = 1;
        expect($send(json_encode($forged, JSON_THROW_ON_ERROR), time())['code'] === 'model_mismatch', '已结束旧版本接受新序号');
        $forged['sequence'] = '901'; $forged['model_version'] = 3;
        expect($send(json_encode($forged, JSON_THROW_ON_ERROR), time())['code'] === 'model_mismatch', '从未确认版本进入历史');
        $requestId = bin2hex(random_bytes(16)); $request($requestId, 3); $dispatch = $claim();
        $third = $buffer->switchModel($dispatch['payload'], [3 => $models[3]['structure_hash']]);
        try { $transaction(static function (Connection $tx) use ($third, $topic): void {
            IngestionService::accept($tx, $topic, json_encode($third, JSON_THROW_ON_ERROR), 1, time()); throw new RuntimeException('model_switch_rollback');
        }); } catch (RuntimeException $rollback) { expect($rollback->getMessage() === 'model_switch_rollback', '确认事务失败场景错误'); }
        expect($read()['model_version'] === 2 && $read()['model_switch']['id'] === $requestId, '确认事务失败留下部分绑定');
        $ack = $send(json_encode($third, JSON_THROW_ON_ERROR), time()); $buffer->acknowledgeModel($ack['receipt']);
        $enum = $buffer->enqueue(3, 'telemetry', $sampledAt, (object) ['temperature' => 'hot']);
        expect($send($enum['payload'], time())['code'] === 'accepted' && $current()['fields'][0]['value'] === 'hot', '新枚举模型没有保留原类型');
        expect($send($old['payload'], time() + 172801)['code'] === 'accepted' && $current()['fields'][0]['value'] === 'hot', '合法旧重复回执被换模型或48小时重新拒绝');
        // 历史快照排除仍在接收的当前秒；真实越过该秒后读取，不伪造未来快照。
        usleep(1100000);
        $history = HistoryService::search($connection, $identity, $tenantId, $id, ['from' => $sampledAt - 60, 'to' => time()]);
        expect($history['total'] === 4 && count(array_unique(array_column($history['items'], 'model_version'))) === 3, '跨版本原始历史丢失版本');
        foreach ($history['items'] as $row) { expect($row['model'] === $models[$row['model_version']]['definition'], '历史没有保留当时模型定义'); }
        for ($batch = 0; $batch < 10; $batch++) { if (!AggregateService::run($connection)['has_more']) { break; } }
        $minutes = AggregateService::search($connection, $identity, $tenantId, $id, ['from' => $sampledAt, 'to' => $sampledAt + 60]);
        expect($minutes['total'] === 2, '数值单位版本被合并或枚举生成了数值统计');
        foreach ($minutes['items'] as $row) { expect($row['fields']['temperature']['count'] === ($row['model_version'] === 1 ? 2 : 1)
            && (float) $row['fields']['temperature']['sum'] === ($row['model_version'] === 1 ? 41.0 : 200.0), '单位变化混算或重复确认增加聚合'); }
        $requestId = bin2hex(random_bytes(16)); $request($requestId, 1);
        $connection->table('iot_device_connections')->where('device_id', '=', $id)->update(['status' => 'offline']);
        $connection->table('iot_model_switches')->where('id', '=', $requestId)->update(['created_at' => time() - 55, 'next_attempt_at' => time()]);
        expect($claim() === null && $connection->table('iot_model_switches')->where('id', '=', $requestId)->first()['next_attempt_at'] === null,
            '临界离线轮询把自动节点排到60秒之后');
        $connection->table('iot_model_switches')->where('id', '=', $requestId)->update(['created_at' => time() - 61, 'next_attempt_at' => time() - 31]);
        expect($read()['model_switch']['state'] === 'unconfirmed' && $claim() === null && $read()['model_version'] === 3, '超时隐式完成或改变绑定');
        $connection->table('iot_device_connections')->where('device_id', '=', $id)->update(['status' => 'online']);
        $request($requestId, 1, true); $retry = $claim();
        expect(json_decode($retry['payload'], true, 16, JSON_THROW_ON_ERROR)['switch_id'] === $requestId, '人工重试更换原意图');
        $rejected = $buffer->switchModel($retry['payload'], []); $ack = $send(json_encode($rejected, JSON_THROW_ON_ERROR), time()); $buffer->acknowledgeModel($ack['receipt']);
        // 回切后尚未采样又切回3，不能重新展示先前3区间的枚举当前值。
        foreach ([1, 3] as $target) {
            $requestId = bin2hex(random_bytes(16)); $request($requestId, $target); $dispatch = $claim();
            $receipt = $buffer->switchModel($dispatch['payload'], [$target => $models[$target]['structure_hash']]);
            $ack = $send(json_encode($receipt, JSON_THROW_ON_ERROR), time()); $buffer->acknowledgeModel($ack['receipt']);
            expect($current()['fields'] === [] && $current()['buffer'] === null, '回切旧版本复活旧区间当前值或状态');
        }
        $partial = $buffer->enqueue(3, 'telemetry', time(), (object) ['mode' => 'auto']);
        expect($send($partial['payload'], time())['code'] === 'accepted' && count($current()['fields']) === 1
            && $current()['fields'][0]['identifier'] === 'mode', '回切首次部分更新合并了旧区间字段');
        $readerUser = (new IdentityService())->provision($connection, 'switch-reader', '模型只读', 'Test-ingestion-password', false);
        $reader = fixtureMember($connection, $identity, $tenantId, 'switch-reader', ['customer.devices.read']);
        expect($devices->modelSwitches($connection, $reader, $tenantId, $id, 1, 2)['total'] === 6, '只读分页缺失模型切换事实');
        try { $devices->switchModel($connection, $reader, $tenantId, $id, bin2hex(random_bytes(16)), 1, fixtureDeviceVersion($connection, $id)); throw new RuntimeException('只读主动切换成功'); }
        catch (HttpError $denied) { expect($denied->status() === 403, '切换权限未由服务端拒绝'); }
        expect(ProductService::publishedModel($connection, $tenantId, $product['id'], 1)['definition'] === $definition, '切换改写已发布模型');
        (new IdentityService())->provision($connection, 'switch-origin', '单一模型切换权限', 'Test-ingestion-password', false);
        $limited = fixtureMember($connection, $identity, $tenantId, 'switch-origin', ['customer.devices.model-switch']);
        $switchRole = $connection->table('customer_roles')->where('scope_id', '=', $tenantId)->where('name', '=', 'switch-origin')->first();
        $revokedId = bin2hex(random_bytes(16));
        $devices->switchModel($connection, $limited, $tenantId, $id, $revokedId, 1, fixtureDeviceVersion($connection, $id));
        fixtureChange($connection, $identity, 'customer.roles.status', $switchRole['id'], ['version' => 2, 'enabled' => false], $tenantId);
        expect($claim() === null && $read()['model_switch'] === null && $read()['model_version'] === 3
            && $connection->table('iot_model_switches')->where('id', '=', $revokedId)->first()['result_code'] === 'source_permission_revoked', '领取前真实撤权没有解除未发送模型冻结');
        fixtureChange($connection, $identity, 'customer.roles.status', $switchRole['id'], ['version' => 3, 'enabled' => true], $tenantId);
        $sentId = bin2hex(random_bytes(16));
        $devices->switchModel($connection, $limited, $tenantId, $id, $sentId, 1, fixtureDeviceVersion($connection, $id));
        $sent = $claim();
        expect($sent !== null, '恢复真实模型权限后没有领取');
        fixtureChange($connection, $identity, 'customer.roles.status', $switchRole['id'], ['version' => 4, 'enabled' => false], $tenantId);
        $connection->table('iot_model_switches')->where('id', '=', $sentId)->update(['next_attempt_at' => time()]);
        expect($claim() === null && $read()['model_switch']['id'] === $sentId && $read()['model_version'] === 3, '已领取后撤权丢失模型未知事实');
        $lateSwitch = $buffer->switchModel($sent['payload'], [1 => $models[1]['structure_hash']]);
        $lateAck = $send(json_encode($lateSwitch, JSON_THROW_ON_ERROR), time());
        expect($buffer->acknowledgeModel($lateAck['receipt']) && $read()['model_version'] === 1, '模型来源撤权阻止已发生效果继续确认');
        $devices->change($connection, $identity, $tenantId, $id, 'rotate', $read()['version'], $id);
        try { $request(bin2hex(random_bytes(16)), 1); throw new RuntimeException('待撤权期间受理模型切换'); }
        catch (HttpError $denied) { expect($denied->errorCode() === 'model_switch_device_unavailable', '待撤权模型拒绝不明确'); }
    } finally { $buffer->close(); }
    return ['model-structure-canonical-public-comparison', 'admin-only-published-switch-single-pending', 'offline-and-timeout-stay-unconfirmed',
        'explicit-firmware-support-and-durable-restart', 'same-id-confirmation-and-business-ack', 'confirmation-rollback-recovery',
        'old-model-sequence-boundary-and-48h', 'current-does-not-mix-versions', 'unit-type-enum-history-and-aggregate-isolation', 'bounded-readonly-switch-history',
        'old-command-query-survives-model-switch', 'pending-credential-invalidation-blocks-model-switch', 'offline-poll-never-schedules-beyond-automatic-deadline',
        'real-model-permission-revoked-before-and-after-dispatch', 'model-receipt-survives-source-revocation'];
}

/** 双方身份经公开服务审批，设备FULL缓存与原接收入口证明冻结、排空、重启与未知效果边界。 */
function transferCases(Connection $connection, Identity $sourceIdentity, Identity $platform, string $sourceTenant, string $sourceProduct): array
{
    $tenants = new TenantService(); $products = new ProductService(); $devices = new DeviceService();
    $targetUser = (new IdentityService())->provision($connection, 'transfer-target', '目标管理员', 'Test-ingestion-password', false);
    $targetIdentity = fixtureSession($connection, 'transfer-target')['identity'];
    $targetTenant = fixtureTenant($connection, $platform, '转入组织', 'transfer-target')['id'];
    $registration = $devices->register($connection, $sourceIdentity, $sourceTenant, $sourceProduct, 1, '双方冻结设备');
    $device = $registration['device']; $deviceId = $device['id']; $transferId = bin2hex(random_bytes(16));
    $request = static fn (string $id): array => fixtureTransfer($connection, $sourceIdentity, $sourceTenant, $deviceId, $targetTenant, $id);
    $read = static fn (string $id): array => TransferService::detail($connection, $sourceIdentity, $sourceTenant, $id);
    $transaction = static fn (Closure $work): mixed => $connection->transaction($work, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    $send = static fn (array $payload): array => $transaction(static fn (Connection $tx): array => IngestionService::accept($tx, $registration['credential']['topics']['publish'], json_encode($payload, JSON_THROW_ON_ERROR), 1, time()));
    try { $request($transferId); throw new RuntimeException('未激活设备可以转移'); } catch (HttpError $denied) { expect($denied->errorCode() === 'transfer_device_unavailable', '未激活拒绝不准确'); }
    $connection->table('iot_devices')->where('id', '=', $deviceId)->update(['lifecycle' => 'enabled']);
    $node = 'transfer-' . $deviceId; $run = bin2hex(random_bytes(16));
    $connection->table('iot_broker_observations')->insert(['node_id' => $node, 'run_id' => $run, 'observed_at' => time(), 'expires_at' => time() + 3600]);
    $connection->table('iot_device_connections')->where('device_id', '=', $deviceId)->update(['status' => 'online', 'node_id' => $node, 'run_id' => $run, 'observed_at' => time()]);
    $invitation = $request($transferId);
    expect($invitation['status'] === 'requested' && $request($transferId)['id'] === $transferId && $read($transferId)['pending_reasons'] === ['target_approval_required'], '申请重复或状态查询改变原请求');
    $decision = ['decision_id' => bin2hex(random_bytes(16)), 'action' => 'reject'];
    foreach (['operator', 'readonly'] as $role) {
        $login = 'transfer-' . $role;
        $user = (new IdentityService())->provision($connection, $login, '转移查看人员', 'Test-ingestion-password', false);
        $identity = fixtureSession($connection, $login)['identity'];
        foreach ([$sourceTenant, $targetTenant] as $fixtureTenant) {
            fixtureMember($connection, $fixtureTenant === $sourceTenant ? $sourceIdentity : $targetIdentity, $fixtureTenant, $login, ['customer.transfers.read']);
        }
        try { fixtureTransfer($connection, $identity, $sourceTenant, $deviceId, $targetTenant, bin2hex(random_bytes(16))); throw new RuntimeException('非管理员发起转移'); }
        catch (HttpError $denied) { expect($denied->status() === 403, '转移发起权限错误'); }
        try { fixtureDecision($connection, $identity, $targetTenant, $transferId, $decision); throw new RuntimeException('非管理员审批转移'); }
        catch (HttpError $denied) { expect($denied->status() === 403, '转移审批权限错误'); }
        expect(TransferService::listing($connection, $identity, $targetTenant, 1, 20, 'target', '', '')['total'] === 1, '获邀组织只读成员无法查看转移事实');
    }
    try { fixtureDecision($connection, $sourceIdentity, $sourceTenant, $transferId, $decision); throw new RuntimeException('源方替目标审批'); }
    catch (HttpError $denied) { expect($denied->errorCode() === 'transfer_target_admin_required', '本方审批边界错误'); }
    $rejected = fixtureDecision($connection, $targetIdentity, $targetTenant, $transferId, $decision);
    expect($rejected['status'] === 'rejected' && fixtureDecision($connection, $targetIdentity, $targetTenant, $transferId, $decision)['status'] === 'rejected'
        && $devices->device($connection, $sourceIdentity, $sourceTenant, $deviceId)['transfer_id'] === null, '拒绝或重复审批没有释放原申请');
    try { fixtureDecision($connection, $targetIdentity, $targetTenant, $transferId, ['action' => 'accept', 'decision_id' => $decision['decision_id'], 'copy_name' => '冲突复制']); throw new RuntimeException('重复决策换成接受'); }
    catch (HttpError $denied) { expect($denied->errorCode() === 'transfer_decision_conflict', '相反审批未保持原事实'); }
    $transferId = bin2hex(random_bytes(16)); $invitation = $request($transferId);
    $different = $products->create($connection, $targetIdentity, $targetTenant, '同名不同单位', '');
    $wrongDefinition = $invitation['source_definition']; $wrongDefinition['properties'][0]['unit'] = '°F';
    $products->createModel($connection, $targetIdentity, $targetTenant, $different['id'], $wrongDefinition);
    $products->changeModel($connection, $targetIdentity, $targetTenant, $different['id'], 1, 1, 'publish');
    try { fixtureDecision($connection, $targetIdentity, $targetTenant, $transferId, ['action' => 'accept', 'decision_id' => bin2hex(random_bytes(16)), 'target_product_id' => $different['id'], 'target_model_version' => 1]); throw new RuntimeException('同版本号替代结构校验'); }
    catch (HttpError $denied) { expect($denied->errorCode() === 'transfer_model_mismatch' && $read($transferId)['status'] === 'requested', '不匹配审批没有完整回滚'); }
    $command = fixtureCommand($connection, $sourceIdentity, $sourceTenant, $deviceId, 'switch', (object) ['on' => true]);
    $dispatch = $transaction(static fn (Connection $tx): ?array => CommandService::claim($tx));
    expect($dispatch !== null && $dispatch['id'] === $command['id'], '冻结前的指令没有真实领取');
    $lateCommand = fixtureCommand($connection, $sourceIdentity, $sourceTenant, $deviceId, 'switch', (object) ['on' => false]);
    $lateDispatch = $transaction(static fn (Connection $tx): ?array => CommandService::claim($tx));
    expect($lateDispatch !== null && $lateDispatch['id'] === $lateCommand['id'], '冻结前在途指令没有真实领取');
    $decision = ['action' => 'accept', 'decision_id' => bin2hex(random_bytes(16)), 'copy_name' => '目标自有复制模型'];
    $accepted = fixtureDecision($connection, $targetIdentity, $targetTenant, $transferId, $decision);
    expect($accepted['status'] === 'frozen' && !$accepted['ready_for_switch'] && in_array('commands_unresolved', $accepted['pending_reasons'], true), '未知指令没有阻止正常切换');
    $copied = fixtureDecision($connection, $targetIdentity, $targetTenant, $transferId, $decision);
    expect($accepted['target_product_id'] === $copied['target_product_id'] && $products->products($connection, $targetIdentity, $targetTenant, 1, 20, '')['total'] === 2, '重复接受复制了第二个产品');
    expect($products->model($connection, $targetIdentity, $targetTenant, $accepted['target_product_id'], 1)['structure_hash'] === $invitation['structure_hash'], '复制结构不同或仍引用源产品');
    try { fixtureCommand($connection, $sourceIdentity, $sourceTenant, $deviceId, 'switch', (object) ['on' => true]); throw new RuntimeException('冻结后受理新动作'); }
    catch (HttpError $denied) { expect($denied->errorCode() === 'transfer_control_frozen', '冻结动作拒绝错误'); }
    expect(fixtureCommand($connection, $sourceIdentity, $sourceTenant, $deviceId, 'switch', (object) ['on' => true], $command['id'])['id'] === $command['id'], '冻结使原受理结果不可查询');
    $path = sys_get_temp_dir() . '/type-iot-transfer-' . bin2hex(random_bytes(16)) . '.sqlite';
    $buffer = new DeviceBuffer($path, $deviceId, $device['ownership_id']);
    try {
        $buffer->modelVersion(1);
        $old = $buffer->enqueue(1, 'telemetry', time(), (object) ['temperature' => 21]);
        $started = $buffer->beginCommand($dispatch['payload'], 1, time()); expect($started['execute'], '冻结前合法动作未开始');
        $freeze = $transaction(static fn (Connection $tx): ?array => TransferService::claim($tx));
        expect($freeze !== null, '已批准在线冻结没有下发');
        $buffer->freezeTransfer($freeze['payload']); $buffer->freezeTransfer($freeze['payload']);
        try { $buffer->enqueue(1, 'telemetry', time(), (object) ['temperature' => 22]); throw new RuntimeException('冻结后仍允许新采样'); }
        catch (LogicException $frozen) { expect($frozen->getMessage() === 'device_transfer_frozen', '冻结采样未明确拒绝'); }
        $status = $buffer->transferStatus([]); $send($status);
        expect(in_array('cache_not_drained', $read($transferId)['pending_reasons'], true) && in_array('device_model_not_ready', $read($transferId)['pending_reasons'], true), '未排空或未支持模型被隐藏');
        $buffer->close(); $buffer = new DeviceBuffer($path, $deviceId, $device['ownership_id']);
        expect($buffer->statistics()['transfer_id'] === $transferId && $buffer->pending()[0] === $old, '冻结重启丢失意图或改写缓存原字节');
        $replayed = $buffer->beginCommand($dispatch['payload'], 1, time()); expect(!$replayed['execute'] && $replayed['receipt']['status'] === 'unknown', '冻结重启重新启动未知动作');
        $deniedCommand = $buffer->beginCommand($lateDispatch['payload'], 1, time());
        expect(!$deniedCommand['execute'] && $deniedCommand['receipt']['code'] === 'device_transfer_frozen', '在途新动作越过设备冻结');
        $deniedAck = $send($deniedCommand['receipt']);
        expect($deniedAck['code'] === 'accepted' && $buffer->acknowledgeCommand($deniedAck['receipt']), '在途拒绝没有经平台业务回执对账');
        $raw = $transaction(static fn (Connection $tx): array => IngestionService::accept($tx, $registration['credential']['topics']['publish'], $old['payload'], 1, time()));
        expect($buffer->applyReceipt($raw['receipt']), '冻结后的原缓存没有按原阶段接受');
        $result = $buffer->completeCommand($command['id'], 'succeeded', 'completed', (object) ['done' => true], time());
        $resultAck = $send($result); expect($resultAck['code'] === 'accepted' && $buffer->acknowledgeCommand($resultAck['receipt']), '原指令对账结果没有业务确认');
        $ready = $buffer->transferStatus([1 => $invitation['structure_hash']]);
        $send($ready); expect($read($transferId)['ready_for_switch'], '真实排空、执行回执和设备支持完成后仍不显示条件齐备');
        $send($status); expect($read($transferId)['ready_for_switch'], '迟到旧状态回退排空事实');
        $tampered = $ready; $tampered['pending_count'] = 1;
        expect($send($tampered)['code'] === 'invalid_transfer_status', '同状态序号不同内容未拒绝');
        $wrongBoundary = $buffer->transferStatus([1 => $invitation['structure_hash']]); $wrongBoundary['boundary_sequence'] = '0';
        expect($send($wrongBoundary)['code'] === 'invalid_transfer_status', '新状态改变了持久冻结边界');
        $newSample = json_decode($old['payload'], true, 32, JSON_THROW_ON_ERROR); $newSample['sequence'] = $wrongBoundary['sequence'];
        expect($send($newSample)['code'] === 'transfer_frozen', '平台仍接受设备冻结边界之后的新采样');
        $unchanged = $devices->device($connection, $sourceIdentity, $sourceTenant, $deviceId);
        expect($unchanged['tenant_id'] === $sourceTenant && $unchanged['ownership_id'] === $device['ownership_id'] && $unchanged['credential_active'], '冻结或条件齐备提前转移归属或凭据');
        try { $devices->device($connection, $targetIdentity, $targetTenant, $deviceId); throw new RuntimeException('目标提前读取源设备'); }
        catch (HttpError $denied) { expect($denied->status() === 404, '目标设备隔离错误'); }
        $connection->table('iot_transfers')->where('id', '=', $transferId)->update(['device_status_at' => time() - 60]);
        expect(!$read($transferId)['ready_for_switch'] && in_array('device_confirmation_stale', $read($transferId)['pending_reasons'], true), '过期设备确认仍允许正常切换');
        $send($buffer->transferStatus([1 => $invitation['structure_hash']]));
        $connection->table('iot_commands')->where('id', '=', $command['id'])->update(['accepted_at' => time() - 180 * 86400, 'result_status' => 'unknown']);
        CommandService::prune($connection, 100);
        expect(!$read($transferId)['ready_for_switch'] && $read($transferId)['expired_uncertain_commands'] === 1, '保留期清理抹掉了未知转移阻塞');
        return ['transfer-both-admins-and-no-platform-inheritance', 'transfer-rejection-and-conflicting-decision', 'transfer-model-structure-and-owned-copy', 'transfer-freeze-blocks-new-control-and-samples',
            'transfer-durable-freeze-and-original-cache', 'transfer-old-result-query-without-action', 'transfer-receipt-order-and-content-conflict', 'transfer-drain-readiness-and-stale-confirmation',
            'transfer-unknown-retention-does-not-clear-blocker', 'transfer-source-history-and-credentials-unchanged'];
    } finally { $buffer->close(); }
}

/** 公开业务入口验证切换事务和本地恢复；Broker完成回调在此只验证契约，真实网络隔离另由MQTT验收证明。 */
function ownershipCases(Connection $connection, Identity $sourceIdentity, Identity $platform, string $sourceTenant, string $sourceProduct): array
{
    $tenants = new TenantService(); $devices = new DeviceService();
    $user = (new IdentityService())->provision($connection, 'ownership-target', '切换目标管理员', 'Test-ingestion-password', false);
    $targetIdentity = fixtureSession($connection, 'ownership-target')['identity'];
    $targetTenant = fixtureTenant($connection, $platform, '正式归属目标', 'ownership-target')['id'];
    $registration = $devices->register($connection, $sourceIdentity, $sourceTenant, $sourceProduct, 1, '正式切换设备');
    $device = $registration['device']; $id = $device['id']; $oldTopic = $registration['credential']['topics']['publish'];
    $transaction = static fn (Closure $work): mixed => $connection->transaction($work, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    $send = static fn (string $topic, array $data): array => $transaction(static fn (Connection $tx): array => IngestionService::accept($tx, $topic, json_encode($data, JSON_THROW_ON_ERROR), 1, time() - 1));
    $node = 'ownership-' . $id; $run = bin2hex(random_bytes(16));
    $connection->table('iot_devices')->where('id', '=', $id)->update(['lifecycle' => 'enabled']);
    $connection->table('iot_broker_observations')->insert(['node_id' => $node, 'run_id' => $run, 'observed_at' => time(), 'expires_at' => time() + 3600]);
    $connection->table('iot_device_connections')->where('device_id', '=', $id)->update(['status' => 'online', 'node_id' => $node, 'run_id' => $run, 'observed_at' => time()]);
    $command = fixtureCommand($connection, $sourceIdentity, $sourceTenant, $id, 'switch', (object) ['on' => true]);
    $dispatch = $transaction(static fn (Connection $tx): ?array => CommandService::claim($tx));
    expect($dispatch !== null && $dispatch['id'] === $command['id'], '切换前指令未领取');
    $transferId = bin2hex(random_bytes(16)); $switchId = bin2hex(random_bytes(16));
    fixtureTransfer($connection, $sourceIdentity, $sourceTenant, $id, $targetTenant, $transferId);
    $record = fixtureDecision($connection, $targetIdentity, $targetTenant, $transferId,
        ['action' => 'accept', 'decision_id' => bin2hex(random_bytes(16)), 'copy_name' => '正式切换模型']);
    $advance = static fn (): array => fixtureAdvance($connection, $targetIdentity, $targetTenant, $transferId, $switchId);
    try { $advance(); throw new RuntimeException('缺少设备排空仍开始隔离'); }
    catch (HttpError $denied) { expect($denied->errorCode() === 'transfer_prerequisites_pending', '切换前置拒绝不准确'); }
    $path = sys_get_temp_dir() . '/type-iot-ownership-' . bin2hex(random_bytes(16)) . '.sqlite';
    $buffer = new DeviceBuffer($path, $id, $device['ownership_id']);
    try {
        $buffer->modelVersion(1);
        $sample = $buffer->enqueue(1, 'telemetry', time(), (object) ['temperature' => 23]);
        $buffer->beginCommand($dispatch['payload'], 1, time());
        $freeze = $transaction(static fn (Connection $tx): ?array => TransferService::claim($tx));
        expect($freeze !== null && json_decode($freeze['payload'], true)['transfer_id'] === $transferId, '正式切换冻结未领取');
        $buffer->freezeTransfer($freeze['payload']);
        $old = json_decode($sample['payload'], true, 32, JSON_THROW_ON_ERROR);
        $buffer->applyReceipt($send($oldTopic, $old)['receipt']);
        $done = $buffer->completeCommand($command['id'], 'succeeded', 'completed', (object) ['done' => true], time());
        $buffer->acknowledgeCommand($send($oldTopic, $done)['receipt']);
        $supported = [(int) $record['target_model_version'] => $record['structure_hash']];
        $send($oldTopic, $buffer->transferStatus($supported));
        try { fixtureAdvance($connection, $sourceIdentity, $sourceTenant, $transferId, $switchId); throw new RuntimeException('源方获取新凭据'); }
        catch (HttpError $denied) { expect($denied->errorCode() === 'transfer_target_admin_required', '正式切换目标管理权限错误'); }
        $isolating = $advance();
        expect($isolating['status'] === 'isolating' && $isolating['credential'] === null && $advance()['new_ownership_id'] === null, '无隔离证明激活了目标');
        $oldAccess = ['action' => 'authenticate', 'client_id' => $id, 'username' => $registration['credential']['username'],
            'verifier' => hash('sha256', $registration['credential']['password']), 'secure' => true, 'protocol' => 5, 'keep_alive' => 30, 'expiry' => 86400];
        expect(!DeviceService::access($connection, $oldAccess)['allowed'], '隔离开始没有立即吊销旧凭据');
        expect($send($oldTopic, $old)['code'] === 'transfer_frozen', '已排空阶段的迟到重放仍被接受');
        try { $devices->change($connection, $sourceIdentity, $sourceTenant, $id, 'rotate', (int) $devices->device($connection, $sourceIdentity, $sourceTenant, $id)['version'], $id); throw new RuntimeException('隔离中轮换恢复源授权'); }
        catch (HttpError $denied) { expect($denied->errorCode() === 'transfer_switch_in_progress', '隔离中生命周期边界错误'); }
        // 契约级回调仅代表测试驱动完成事实；不能当作真实跨节点隔离成功。
        DeviceService::access($connection, ['action' => 'invalidation_completed', 'invalidation_id' => $isolating['isolation_id'], 'node_id' => $node]);
        $activated = $advance();
        expect($activated['status'] === 'activating' && $activated['credential'] !== null && $activated['new_ownership_id'] !== $device['ownership_id'], '隔离完成没有原子生成新归属和凭据');
        $newTopic = $activated['credential']['topics']['publish'];
        $repeat = $advance();
        expect($repeat['credential'] === null && $repeat['new_ownership_id'] === $activated['new_ownership_id'], '重复激活泄露秘密或重复归属');
        try { fixtureAdvance($connection, $targetIdentity, $targetTenant, $transferId, bin2hex(random_bytes(16))); throw new RuntimeException('新请求ID覆盖正式切换'); }
        catch (HttpError $denied) { expect($denied->errorCode() === 'transfer_switch_conflict', '切换ID冲突错误'); }
        expect($connection->table('iot_device_credentials')->where('device_id', '=', $id)->where('status', '=', 'active')->aggregate('COUNT') == 1
            && $connection->table('iot_device_ownerships')->where('device_id', '=', $id)->whereNull('ended_at')->aggregate('COUNT') == 1, '存在双重有效归属或凭据');
        expect(IngestionService::current($connection, $targetIdentity, $targetTenant, $id)['fields'] === [], '旧当前值带入目标');
        expect($send($oldTopic, $old)['code'] === 'topic_forbidden' && $send($newTopic, $old)['code'] === 'topic_forbidden', '旧载荷在原Topic或新Topic越过归属');
        expect(HistoryService::search($connection, $sourceIdentity, $sourceTenant, $id, [])['total'] === 1
            && HistoryService::search($connection, $targetIdentity, $targetTenant, $id, [])['total'] === 0, '历史原始事实归属变化');
        expect(CommandService::history($connection, $sourceIdentity, $sourceTenant, $id)['total'] === 1
            && CommandService::history($connection, $targetIdentity, $targetTenant, $id)['total'] === 0, '历史指令归属变化或源方失去查询');
        AggregateService::run($connection, 100);
        expect(AggregateService::search($connection, $sourceIdentity, $sourceTenant, $id, [])['total'] === 1
            && AggregateService::search($connection, $targetIdentity, $targetTenant, $id, [])['total'] === 0, '历史分钟聚合越界');
        $buffer->provisionTransfer($activated['provisioning'], $supported);
        $confirmation = $buffer->transferStatus($supported);
        $buffer->close();
        // 同一受控配置在本地提交后响应丢失，仍可用原配置幂等恢复到新阶段。
        $buffer = new DeviceBuffer($path, $id, $device['ownership_id'], 8640, 8640000, 128, 1048576, $activated['provisioning'], $supported);
        expect($buffer->transferStatus($supported) === $confirmation, '设备重启丢失持久切换确认');
        try { $buffer->enqueue(1, 'telemetry', time(), (object) ['temperature' => 24]); throw new RuntimeException('新阶段确认前解除采样冻结'); }
        catch (LogicException $denied) { expect($denied->getMessage() === 'device_transfer_frozen', '新阶段本地冻结错误'); }
        $ack = $send($newTopic, $confirmation);
        expect($ack['code'] === 'accepted' && $send($newTopic, $confirmation)['receipt'] === $ack['receipt'], '平台新阶段确认不可幂等恢复');
        expect(TransferService::detail($connection, $sourceIdentity, $sourceTenant, $transferId)['status'] === 'completed', '源方不能看到实际完成阶段');
        expect($buffer->acknowledgeTransfer($ack['receipt']) && !$buffer->acknowledgeTransfer($ack['receipt']), '设备业务确认没有幂等解冻');
        $newSample = $buffer->enqueue(1, 'telemetry', time(), (object) ['temperature' => 24]);
        expect($buffer->applyReceipt($send($newTopic, json_decode($newSample['payload'], true, 32, JSON_THROW_ON_ERROR))['receipt']), '新阶段不能接收新采样');
        expect(HistoryService::search($connection, $sourceIdentity, $sourceTenant, $id, [])['total'] === 1
            && HistoryService::search($connection, $targetIdentity, $targetTenant, $id, [])['total'] === 1, '切换后源和目标历史混合');
        expect(!DeviceService::access($connection, $oldAccess)['allowed'], '完成后旧凭据恢复有效');
        return ['ownership-prerequisite-gate', 'ownership-target-admin-only', 'ownership-isolation-before-activation', 'ownership-old-credential-immediate-rejection',
            'ownership-idempotent-stages-and-one-time-secret', 'ownership-no-dual-active-stage', 'ownership-old-payload-rejected-on-both-topics',
            'ownership-source-raw-command-and-aggregate-history', 'ownership-new-current-is-empty', 'ownership-local-provision-restart',
            'ownership-new-device-ack-loss-and-idempotence', 'ownership-new-samples-only-after-confirmation'];
    } finally { $buffer->close(); }
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** 有效实时事实与当前值分别推进，观察边界仍为正式接收与授权查询。 */
function freshnessCases(Connection $connection, Identity $identity, string $tenantId, string $productId): array
{
    $registered = (new DeviceService())->register($connection, $identity, $tenantId, $productId, 1, '实时边界设备');
    $device = $registered['device'];
    $topic = $registered['credential']['topics']['publish'];
    $envelope = ['app_version' => 1, 'type' => 'telemetry', 'device_id' => $device['id'], 'ownership_id' => $device['ownership_id'],
        'model_version' => 1, 'sequence' => '10', 'sampled_at' => time() - 90, 'values' => ['temperature' => 20]];
    $send = static function (array $message, int $receivedAt, string $target) use ($connection): array {
        return $connection->transaction(static fn (Connection $transaction): array => IngestionService::accept($transaction, $target, json_encode($message, JSON_THROW_ON_ERROR), 1, $receivedAt), $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    };
    $read = static fn (): array => IngestionService::current($connection, $identity, $tenantId, $device['id']);
    $empty = $read();
    expect($empty['freshness'] === 'empty' && $empty['realtime_sequence'] === null && $empty['realtime_sampled_at'] === null && $empty['realtime_received_at'] === null, '未上报设备必须明确无实时数据');
    $moment = time();
    $envelope['sampled_at'] = $moment - 90;
    expect($send($envelope, $moment - 60, $topic)['code'] === 'accepted', '前30秒闭边界首次接收未接受');
    $stale = $read();
    expect($stale['freshness'] === 'stale' && $stale['realtime_sequence'] === '10' && $stale['realtime_sampled_at'] === $moment - 90 && $stale['realtime_received_at'] === $moment - 60, '60秒边界必须陈旧并保留独立双时间');
    $old = array_replace($envelope, ['sequence' => '20', 'sampled_at' => $moment - 31]);
    expect($send($old, $moment, $topic)['code'] === 'accepted', '前31秒仍应接收历史事实');
    $afterOld = $read();
    expect($afterOld['sequence'] === '20' && $afterOld['received_at'] === $moment && $afterOld['realtime_sequence'] === '10'
        && $afterOld['realtime_received_at'] === $moment - 60 && $afterOld['freshness'] === 'stale', '旧大序号补传冒充实时新鲜数据');
    // 只断言本次读写均落在同一真实秒内的59秒边界，不注入生产时钟或等待一分钟。
    $boundaryObserved = false;
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $now = time();
        $fresh = array_replace($envelope, ['sequence' => (string) (30 + $attempt), 'sampled_at' => $now - 54]);
        expect($send($fresh, $now - 59, $topic)['code'] === 'accepted', '后5秒闭边界未接受');
        $observed = $read();
        if (time() === $now) {
            expect($observed['freshness'] === 'fresh' && $observed['realtime_sequence'] === $fresh['sequence']
                && $observed['realtime_sampled_at'] === $now - 54 && $observed['realtime_received_at'] === $now - 59, '59秒边界不应提前陈旧');
            $boundaryObserved = true;
            break;
        }
    }
    expect($boundaryObserved, '实际机器未能在一秒内完成边界观察');
    $firstRealtime = $read();
    $send($fresh, $now + 172801, $topic);
    $event = array_replace($envelope, ['sequence' => '40', 'type' => 'event', 'identifier' => 'fault', 'sampled_at' => $now, 'values' => ['code' => 42]]);
    $send($event, $now, $topic);
    $send(array_replace($envelope, ['sequence' => '25', 'sampled_at' => $now]), $now, $topic);
    $unchanged = $read();
    expect($unchanged['realtime_sequence'] === $firstRealtime['realtime_sequence'] && $unchanged['realtime_sampled_at'] === $firstRealtime['realtime_sampled_at']
        && $unchanged['realtime_received_at'] === $firstRealtime['realtime_received_at'], '重复、事件或乱序刷新了实时事实');
    $send(array_replace($envelope, ['sequence' => '50', 'sampled_at' => $now - 30]), $now, $topic);
    $real = $read();
    expect($real['realtime_sequence'] === '50' && $real['freshness'] === 'fresh', '新的前30秒边界未推进实时事实');
    expect($send(array_replace($envelope, ['sequence' => '60', 'sampled_at' => $now + 6]), $now, $topic)['code'] === 'clock_ahead', '后6秒应永久拒绝');
    $send(array_replace($envelope, ['sequence' => '70', 'sampled_at' => $now - 31]), $now, $topic);
    expect($read()['sequence'] === '70' && $read()['realtime_sequence'] === '50', '前31秒错误覆盖最近实时事实');
    try {
        $connection->transaction(static function (Connection $transaction) use ($envelope, $topic, $now): void {
            IngestionService::accept($transaction, $topic, json_encode(array_replace($envelope, ['sequence' => '80', 'sampled_at' => $now]), JSON_THROW_ON_ERROR), 1, $now);
            throw new RuntimeException('realtime_rollback');
        }, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    } catch (RuntimeException $rollback) {
        expect($rollback->getMessage() === 'realtime_rollback', '实时回滚场景失败');
    }
    expect($read()['sequence'] === '70' && $read()['realtime_sequence'] === '50', '事务失败未撤回实时效果');
    $connection->table('iot_devices')->where('id', '=', $device['id'])->update(['model_version' => 2]);
    expect($read()['freshness'] === 'empty' && $read()['realtime_sequence'] === null, '新模型继承了旧模型的实时状态');
    $model = array_replace($envelope, ['model_version' => 2, 'sequence' => '90', 'sampled_at' => $now - 31]);
    $send($model, $now, $topic);
    expect($read()['sequence'] === '90' && $read()['freshness'] === 'stale' && $read()['realtime_sequence'] === null
        && $read()['realtime_sampled_at'] === null && $read()['realtime_received_at'] === null, '新模型首条旧补传不得继承实时双时间');
    $send(array_replace($model, ['sequence' => '100', 'sampled_at' => $now]), $now, $topic);
    expect($read()['realtime_sequence'] === '100', '新模型的有效实时事实未建立');
    $ownership = str_repeat('f', 32);
    $connection->table('iot_devices')->where('id', '=', $device['id'])->update(['ownership_id' => $ownership]);
    expect($read()['freshness'] === 'empty' && $read()['realtime_sequence'] === null, '新归属阶段继承了旧阶段实时状态');
    $nextTopic = DeviceService::topics(array_replace($device, ['ownership_id' => $ownership]))['publish'];
    $next = array_replace($model, ['ownership_id' => $ownership, 'sequence' => '1']);
    $send($next, $now, $nextTopic);
    expect($read()['sequence'] === '1' && $read()['freshness'] === 'stale' && $read()['realtime_sequence'] === null
        && $read()['realtime_sampled_at'] === null && $read()['realtime_received_at'] === null, '新阶段首条旧补传继承了实时事实');
    return ['30s-and-5s-inclusive-window', '31s-backfill-keeps-realtime', '59s-fresh-and-60s-stale', 'no-telemetry-empty',
        'backfill-without-realtime-stale', 'duplicate-event-out-of-order-do-not-refresh', 'realtime-transaction-rollback', 'realtime-model-and-ownership-isolation'];
}

/** 单次指令与本地真实SQLite去重共用正式公开服务；PDO事实只用于制造已观察连接和确定的截止边界。 */
function commandCases(Connection $connection, Identity $identity, string $tenantId, string $productId): array
{
    $tenants = new TenantService();
    $devices = new DeviceService();
    $registered = $devices->register($connection, $identity, $tenantId, $productId, 1, '单次指令设备');
    $device = $registered['device'];
    $id = $device['id'];
    $make = static fn (): array => fixtureCommand($connection, $identity, $tenantId, $id, 'switch', (object) ['on' => true]);
    try { $make(); throw new RuntimeException('无在线观察竟然接受控制'); } catch (HttpError $offline) { expect($offline->errorCode() === 'command_device_not_online', '离线控制错误不明确'); }
    $node = 'command-' . $id;
    $run = bin2hex(random_bytes(16));
    $connection->table('iot_devices')->where('id', '=', $id)->update(['lifecycle' => 'enabled']);
    $connection->table('iot_broker_observations')->insert(['node_id' => $node, 'run_id' => $run, 'observed_at' => time(), 'expires_at' => time() + 3600]);
    $connection->table('iot_device_connections')->where('device_id', '=', $id)->update(['status' => 'online', 'node_id' => $node, 'run_id' => $run, 'observed_at' => time()]);
    try { fixtureCommand($connection, $identity, $tenantId, $id, 'switch', (object) ['on' => 'true']); throw new RuntimeException('指令值未按发布模型校验'); }
    catch (\Type\Validate\ValidationException) { }
    $readonly = (new IdentityService())->provision($connection, 'command-reader', '指令只读', 'Test-ingestion-password', false);
    $reader = fixtureMember($connection, $identity, $tenantId, 'command-reader', ['customer.commands.read']);
    try { fixtureCommand($connection, $reader, $tenantId, $id, 'switch', (object) ['on' => true]); throw new RuntimeException('只读成员发起了控制'); }
    catch (HttpError $denied) { expect($denied->status() === 403, '控制权限必须服务端校验'); }
    $transaction = static fn (Closure $operation): mixed => $connection->transaction($operation, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    $cancelled = $make();
    $cancelId = bin2hex(random_bytes(16));
    expect(CommandService::cancel($connection, $identity, $tenantId, $id, $cancelled['id'], $cancelId)['execution'] === 'cancelled', '未领取指令没有明确取消');
    expect(CommandService::cancel($connection, $identity, $tenantId, $id, $cancelled['id'], $cancelId)['id'] === $cancelled['id'], '取消重放改变原事实');
    expect($transaction(static fn (Connection $tx): ?array => CommandService::claim($tx)) === null, '已取消指令仍被领取');
    $expired = $make();
    $connection->table('iot_commands')->where('id', '=', $expired['id'])->update(['accepted_at' => time() - 61, 'deadline_at' => time() - 1]);
    expect($transaction(static fn (Connection $tx): ?array => CommandService::claim($tx)) === null
        && CommandService::detail($connection, $identity, $tenantId, $id, $expired['id'])['execution'] === 'not_dispatched', '从未领取且到期的指令不能误标设备结果未知');
    (new IdentityService())->provision($connection, 'command-origin', '单一指令权限', 'Test-ingestion-password', false);
    $limited = fixtureMember($connection, $identity, $tenantId, 'command-origin', ['customer.commands.create']);
    $revokedCommand = fixtureCommand($connection, $limited, $tenantId, $id, 'switch', (object) ['on' => true]);
    $role = $connection->table('customer_roles')->where('scope_id', '=', $tenantId)->where('name', '=', 'command-origin')->first();
    fixtureChange($connection, $identity, 'customer.roles.status', $role['id'], ['version' => (int) $role['version'], 'enabled' => false], $tenantId);
    expect($transaction(static fn (Connection $tx): ?array => CommandService::claim($tx)) === null
        && CommandService::detail($connection, $identity, $tenantId, $id, $revokedCommand['id'])['dispatch_stop_reason'] === 'source_permission_revoked', '真实角色撤权后仍领取排队动作');
    $original = $make();
    $otherLogin = (new IdentityService())->login($connection, 'owner', 'Test-ingestion-password');
    $otherSession = (new IdentityService())->authenticate($connection, $otherLogin['accessToken']);
    try { fixtureCommand($connection, $otherSession, $tenantId, $id, 'switch', (object) ['on' => true], $original['id']); throw new RuntimeException('同账号另一会话重用了原动作'); }
    catch (HttpError $conflict) { expect($conflict->errorCode() === 'command_identity_conflict', '指令幂等没有绑定原会话'); }
    CommandService::cancel($connection, $identity, $tenantId, $id, $original['id'], bin2hex(random_bytes(16)));
    $adminService = new IdentityService('admin');
    $adminLogin = $adminService->login($connection, 'platform', 'Test-ingestion-password');
    $adminIdentity = $adminService->authenticate($connection, $adminLogin['accessToken']);
    $customerVersion = (int) $connection->table('customer_users')->where('id', '=', $identity->subject())->first()['version'];
    $simulated = RoleService::change($connection, $adminIdentity, $adminLogin['accessToken'], 'admin.customers.impersonate', $identity->subject(), ['version' => $customerVersion]);
    $simulatedIdentity = (new IdentityService())->authenticate($connection, $simulated['accessToken']);
    $simulatedCommand = fixtureCommand($connection, $simulatedIdentity, $tenantId, $id, 'switch', (object) ['on' => true]);
    $storedSource = json_decode($connection->table('iot_commands')->where('id', '=', $simulatedCommand['id'])->first()['source_context'], true, 8, JSON_THROW_ON_ERROR);
    expect($storedSource['actor_id'] === $adminIdentity->subject() && $storedSource['customer_id'] === $identity->subject()
        && $storedSource['source_session_id'] === $adminIdentity->attributes()['session_id'] && $storedSource['tenant_id'] === $tenantId, '模拟指令丢失真实来源');
    try { fixtureCommand($connection, $identity, $tenantId, $id, 'switch', (object) ['on' => true], $simulatedCommand['id']); throw new RuntimeException('普通会话重用了模拟动作'); }
    catch (HttpError $conflict) { expect($conflict->errorCode() === 'command_identity_conflict', '模拟与普通上下文未隔离'); }
    $adminService->logout($connection, $adminIdentity, $adminLogin['accessToken']);
    expect($transaction(static fn (Connection $tx): ?array => CommandService::claim($tx)) === null
        && CommandService::detail($connection, $identity, $tenantId, $id, $simulatedCommand['id'])['execution'] === 'not_dispatched', '真实模拟来源退出后仍领取排队动作');
    $record = $make();
    expect($record['execution'] === 'pending' && $record['deadline_at'] === $record['accepted_at'] + 60 && $record['dispatch_at'] === null, '受理伪装发送或执行成功');
    $confirmed = fixtureCommand($connection, $identity, $tenantId, $id, 'switch', (object) ['on' => true], $record['id']);
    expect($confirmed['id'] === $record['id'] && $confirmed['deadline_at'] === $record['deadline_at'], '同ID确认改变了原截止时间');
    try { fixtureCommand($connection, $identity, $tenantId, $id, 'switch', (object) ['on' => false], $record['id']); throw new RuntimeException('同ID不同内容未拒绝'); }
    catch (HttpError $conflict) { expect($conflict->errorCode() === 'command_identity_conflict', '同ID冲突结果不明确'); }
    $transaction = static fn (Closure $operation): mixed => $connection->transaction($operation, $connection->driverName() === 'sqlite' ? 'immediate' : 'default');
    $dispatch = $transaction(static fn (Connection $tx): ?array => CommandService::claim($tx));
    expect($dispatch['id'] === $record['id'] && $transaction(static fn (Connection $tx): ?array => CommandService::claim($tx)) === null, '指令领取必须单次');
    try { CommandService::cancel($connection, $identity, $tenantId, $id, $record['id'], bin2hex(random_bytes(16))); throw new RuntimeException('可能已发送的指令被取消'); }
    catch (HttpError $dispatched) { expect($dispatched->errorCode() === 'command_already_dispatched', '发送领取后没有保留未知'); }
    $transaction(static fn (Connection $tx): array => CommandService::transport($tx, $record['id'], 0));
    $read = static fn (): array => CommandService::detail($connection, $reader, $tenantId, $id, $record['id']);
    expect($read()['mqtt_at'] !== null && $read()['device_received_at'] === null && $read()['execution'] === 'pending', 'Broker PUBACK被当作设备执行成功');
    $path = sys_get_temp_dir() . '/type-iot-command-' . bin2hex(random_bytes(16)) . '.sqlite';
    $buffer = new DeviceBuffer($path, $id, $device['ownership_id']);
    try {
        $started = $buffer->beginCommand($dispatch['payload'], 1, $record['accepted_at']);
        expect($started['execute'] && $started['receipt']['status'] === 'unknown', '实际动作前没有持久未知事实');
        $buffer->close();
        $buffer = new DeviceBuffer($path, $id, $device['ownership_id']);
        $again = $buffer->beginCommand($dispatch['payload'], 1, $record['accepted_at'] + 1);
        expect(!$again['execute'] && $again['receipt']['status'] === 'unknown', '掉电重启重复执行了未知动作');
        $send = static fn (array $value): array => $transaction(static fn (Connection $tx): array => IngestionService::accept($tx,
            $registered['credential']['topics']['publish'], json_encode($value, JSON_THROW_ON_ERROR), 1, time()));
        expect($send($again['receipt'])['receipt'] !== null && $read()['execution'] === 'unknown', '重启未知结果未关联原指令');
        $receipt = $buffer->completeCommand($record['id'], 'succeeded', 'executed', (object) ['on' => true], $record['accepted_at'] + 2);
        $ack = $transaction(static fn (Connection $tx): array => IngestionService::accept($tx,
            $registered['credential']['topics']['publish'], json_encode($receipt, JSON_THROW_ON_ERROR), 1, $record['deadline_at'] + 120));
        expect($ack['code'] === 'accepted' && $ack['receipt']['type'] === 'command_receipt_ack', '设备结果未关联原指令');
        expect($read()['execution'] === 'succeeded' && $read()['device_received_at'] !== null && $read()['result']->on === true
            && $read()['result_received_at'] > $record['deadline_at'], '迟到确定结果未补全未知事实');
        expect($buffer->acknowledgeCommand($ack['receipt']) && !$buffer->acknowledgeCommand($ack['receipt']) && $buffer->commandReceipts() === [], '业务确认没有幂等停止回执重发');
        expect(!$buffer->beginCommand($dispatch['payload'], 1, $record['accepted_at'] + 86401)['execute'], '超过24小时的旧指令再次产生动作');
        expect($send($receipt)['receipt']['result_hash'] === $ack['receipt']['result_hash'], '重复终局结果不幂等');
        $altered = $receipt;
        $altered['result'] = (object) ['on' => false];
        expect($send($altered)['receipt'] === null && $read()['result']->on === true, '相互矛盾的终局结果覆盖已知事实');
        $challenge = ['app_version' => 1, 'type' => 'time_request', 'device_id' => $id, 'ownership_id' => $device['ownership_id'], 'nonce' => bin2hex(random_bytes(16))];
        $clock = $send($challenge);
        expect($clock['receipt']['nonce'] === $challenge['nonce'] && $clock['receipt']['server_time'] >= time() - 1, 'TLS时间挑战未关联新nonce');
        foreach ([['clock_untrusted', null], ['command_expired', $record['deadline_at']]] as $case) {
            $command = json_decode($dispatch['payload'], true, 32, JSON_THROW_ON_ERROR);
            $command['command_id'] = bin2hex(random_bytes(16));
            $rejectedCommand = $buffer->beginCommand(json_encode($command, JSON_THROW_ON_ERROR), 1, $case[1]);
            expect(!$rejectedCommand['execute'] && $rejectedCommand['receipt']['code'] === $case[0], '失去可信时间或过期仍开始动作');
        }
        $buffer->acknowledgeCommand($ack['receipt']);
        $retention = $record['deadline_at'] + 86400;
        $probe = json_decode($dispatch['payload'], true, 32, JSON_THROW_ON_ERROR);
        $probe['command_id'] = bin2hex(random_bytes(16));
        $probe['issued_at'] = $retention;
        $probe['deadline_at'] = $retention + 60;
        $buffer->beginCommand(json_encode($probe, JSON_THROW_ON_ERROR), 1, $retention + 1, $retention - 1);
        expect($buffer->beginCommand($dispatch['payload'], 1, $retention + 1)['receipt']['status'] === 'succeeded', '时间上界提前清理了未满24小时的确定结果');
        $buffer->acknowledgeCommand($ack['receipt']);
        $probe['command_id'] = bin2hex(random_bytes(16));
        $buffer->beginCommand(json_encode($probe, JSON_THROW_ON_ERROR), 1, $retention + 1, $retention);
        expect($buffer->beginCommand($dispatch['payload'], 1, $retention + 1)['receipt']['code'] === 'command_expired', '下界越过保留期后未清理已确认终局，或旧指令重新获得动作');
        // 制造真实持久的第60秒边界，不给生产服务增加可注入测试时钟。
        $unknown = $make();
        expect($transaction(static fn (Connection $tx): ?array => CommandService::claim($tx))['id'] === $unknown['id'], '未知场景必须先有发送领取');
        $connection->table('iot_commands')->where('id', '=', $unknown['id'])->update(['accepted_at' => time() - 60, 'deadline_at' => time(), 'next_action_at' => time()]);
        $items = CommandService::history($connection, $reader, $tenantId, $id)['items'];
        $found = false;
        foreach ($items as $item) { if ($item['id'] === $unknown['id']) { $found = $item['execution'] === 'unknown'; } }
        $expiryQuery = $transaction(static fn (Connection $tx): ?array => CommandService::claim($tx));
        expect($found && $expiryQuery['kind'] === 'query' && json_decode($expiryQuery['payload'], true)['command_id'] === $unknown['id'], '第60秒无证据应未知且只查询原结果');
        $missing = $buffer->queryCommand(json_decode($expiryQuery['payload'], true, 32, JSON_THROW_ON_ERROR));
        expect($missing['receipt'] === null && $send($missing)['code'] === 'command_query_observed', '不存在的设备结果不得伪造未执行或开始新动作');
        expect($connection->table('iot_commands')->where('id', '=', $unknown['id'])->first()['result_status'] === null, '查询没有记录被当作明确失败');
        $connection->table('iot_commands')->where('id', '=', $unknown['id'])->update(['next_action_at' => null, 'schedule_stage' => 6]);
        // 创建后0/10/30秒使用真实墙上时间；不修改已发原报文、原身份或原截止制造重投通过。
        $retry = $make();
        $first = $transaction(static fn (Connection $tx): ?array => CommandService::claim($tx));
        expect($first['kind'] === 'send' && $first['id'] === $retry['id'], '首节点未发送');
        foreach ([10, 30] as $offset) {
            expect($transaction(static fn (Connection $tx): ?array => CommandService::claim($tx)) === null, '节点之前重复领取');
            while (time() < $retry['accepted_at'] + $offset) { usleep(100000); }
            $nextDispatch = $transaction(static fn (Connection $tx): ?array => CommandService::claim($tx));
            expect($nextDispatch['kind'] === 'send' && $nextDispatch['payload'] === $first['payload'] && $nextDispatch['deadline_at'] === $first['deadline_at'], '重投修改原字节身份或截止');
            $transaction(static fn (Connection $tx): array => CommandService::transport($tx, $retry['id'], 0, $nextDispatch['attempt_id']));
        }
        expect($transaction(static fn (Connection $tx): ?array => CommandService::claim($tx)) === null, '超过三次发送预算');
        $retryTimeline = CommandService::timeline($connection, $reader, $tenantId, $id, $retry['id']);
        expect($retryTimeline['total'] === 3 && $retryTimeline['items'][0]['scheduled_at'] === $retry['accepted_at'] + 30, '原节点发送证据不完整');
        $connection->table('iot_commands')->where('id', '=', $retry['id'])->update(['next_action_at' => null, 'schedule_stage' => 6]);
        // 独立原始指令在任何发送前建立历史受理事实，检查恢复不会补发0/10秒错过的节点。
        $aged = $make();
        $original = $connection->table('iot_commands')->where('id', '=', $aged['id'])->first();
        $originalPayload = json_decode($original['payload'], true, 32, JSON_THROW_ON_ERROR);
        $originalPayload['issued_at'] = time() - 35;
        $originalPayload['deadline_at'] = $originalPayload['issued_at'] + 60;
        $originalJson = json_encode($originalPayload, JSON_THROW_ON_ERROR);
        $connection->table('iot_commands')->where('id', '=', $aged['id'])->update(['accepted_at' => $originalPayload['issued_at'], 'deadline_at' => $originalPayload['deadline_at'],
            'payload' => $originalJson, 'content_hash' => IngestionService::contentHash($originalJson), 'next_action_at' => $originalPayload['issued_at']]);
        $recovered = $transaction(static fn (Connection $tx): ?array => CommandService::claim($tx));
        expect($recovered['id'] === $aged['id'] && $recovered['payload'] === $originalJson && $transaction(static fn (Connection $tx): ?array => CommandService::claim($tx)) === null, '调度恢复集中补发旧节点');
        $recovery = CommandService::timeline($connection, $reader, $tenantId, $id, $aged['id']);
        expect(count(array_filter($recovery['items'], static fn (array $row): bool => $row['state'] === 'schedule_missed')) === 2, '恢复未记录跳过的原节点');
        $startedRecovery = $buffer->beginCommand($recovered['payload'], 1, time());
        $send($startedRecovery['receipt']);
        $connection->table('iot_commands')->where('id', '=', $aged['id'])->update(['schedule_stage' => 2, 'next_action_at' => time()]);
        expect($transaction(static fn (Connection $tx): ?array => CommandService::claim($tx)) === null, '已启动未知动作仍重投');
        $connection->table('iot_commands')->where('id', '=', $aged['id'])->update(['next_action_at' => null, 'schedule_stage' => 6]);
        // 自动查询的每个原节点与只读主动查询拒绝；查询返回原记录而不再次动作。
        foreach ([60 => 3, 120 => 4, 300 => 5] as $offset => $stage) {
            $queryRecord = $make();
            expect($transaction(static fn (Connection $tx): ?array => CommandService::claim($tx))['id'] === $queryRecord['id'], '自动对账场景必须先有发送领取');
            $created = time() - $offset;
            $connection->table('iot_commands')->where('id', '=', $queryRecord['id'])->update(['accepted_at' => $created, 'deadline_at' => $created + 60,
                'schedule_stage' => $stage, 'next_action_at' => $created + $offset]);
            $automatic = $transaction(static fn (Connection $tx): ?array => CommandService::claim($tx));
            expect($automatic['id'] === $queryRecord['id'] && $automatic['kind'] === 'query', '原自动查询节点错误');
            $response = $buffer->queryCommand(json_decode($automatic['payload'], true, 32, JSON_THROW_ON_ERROR));
            expect($response['receipt'] === null && $send($response)['code'] === 'command_query_observed', '自动查询改变了设备执行状态');
            $connection->table('iot_commands')->where('id', '=', $queryRecord['id'])->update(['next_action_at' => null, 'schedule_stage' => 6]);
        }
        try { CommandService::query($connection, $reader, $tenantId, $id, $queryRecord['id'], bin2hex(random_bytes(16))); throw new RuntimeException('只读主动联系了设备'); }
        catch (HttpError $queryDenied) { expect($queryDenied->status() === 403, '主动查询未独立授权'); }
        $connection->table('iot_command_attempts')->where('command_id', '=', $queryRecord['id'])->update(['scheduled_at' => time() - 11]);
        $manualId = bin2hex(random_bytes(16));
        $manual = CommandService::query($connection, $identity, $tenantId, $id, $queryRecord['id'], $manualId);
        expect(CommandService::query($connection, $identity, $tenantId, $id, $queryRecord['id'], $manualId)['id'] === $manualId, '同查询ID确认创建另一查询');
        try { CommandService::query($connection, $identity, $tenantId, $id, $queryRecord['id'], bin2hex(random_bytes(16))); throw new RuntimeException('同指令并发查询无限入队'); }
        catch (HttpError $coalesced) { expect($coalesced->errorCode() === 'command_query_in_progress', '查询合并错误不明确'); }
        $manualDispatch = $transaction(static fn (Connection $tx): ?array => CommandService::claim($tx));
        expect($manualDispatch['attempt_id'] === $manualId && $manualDispatch['kind'] === 'query', '主动查询领取身份变化');
        expect($transaction(static fn (Connection $tx): ?array => CommandService::claim($tx)) === null, '并发领取重复了同查询');
        $connection->table('iot_commands')->where('id', '=', $queryRecord['id'])->update(['accepted_at' => time() - 180 * 86400]);
        $beforePrune = (int) $connection->table('iot_commands')->aggregate('COUNT');
        $connection->execute('CREATE TABLE iot_cleanup_guard (attempt_id VARCHAR(32) NOT NULL PRIMARY KEY, FOREIGN KEY (attempt_id) REFERENCES iot_command_attempts(id))');
        $guarded = $connection->table('iot_command_attempts')->where('command_id', '=', $queryRecord['id'])->orderBy('scheduled_at')->orderBy('id')->first();
        $connection->table('iot_cleanup_guard')->insert(['attempt_id' => $guarded['id']]);
        $cleanupFailed = false;
        try { CommandService::prune($connection, 1); } catch (Throwable) { $cleanupFailed = true; }
        expect($cleanupFailed && $connection->table('iot_command_attempts')->where('id', '=', $guarded['id'])->first() !== null, '实际数据库清理失败没有保留原证据');
        $connection->execute('DROP TABLE iot_cleanup_guard');
        expect((int) $connection->table('iot_commands')->aggregate('COUNT') === $beforePrune, '清理失败没有保持原事实');
        $prunedUnknown = 0;
        do {
            $pruned = CommandService::prune($connection, 1);
            expect($pruned['deleted'] + $pruned['deleted_evidence'] <= 1, '清理突破每次记录预算');
            $prunedUnknown += $pruned['deleted_unknown'];
        } while ($pruned['has_more']);
        expect($prunedUnknown === 1 && (int) $connection->table('iot_commands')->aggregate('COUNT') === $beforePrune - 1, '180天边界清理范围错误');
        $retired = $connection->table('customer_audit')->where('action', '=', 'command.retention_expired')->where('subject_id', '=', $queryRecord['id'])->first();
        expect($retired['result'] === 'unknown' && json_decode($retired['details'], true)['deadline_at'] === $created + 60, '清理把未知伪装完成或遗失原截止身份');
        $lateCommand = $make();
        $latePayload = json_decode($connection->table('iot_commands')->where('id', '=', $lateCommand['id'])->first()['payload'], true, 32, JSON_THROW_ON_ERROR);
        $latePayload['issued_at'] = time() - 400;
        $latePayload['deadline_at'] = $latePayload['issued_at'] + 60;
        $lateJson = json_encode($latePayload, JSON_THROW_ON_ERROR);
        $connection->table('iot_commands')->where('id', '=', $lateCommand['id'])->update(['accepted_at' => $latePayload['issued_at'], 'deadline_at' => $latePayload['deadline_at'],
            'dispatch_at' => $latePayload['issued_at'], 'payload' => $lateJson, 'content_hash' => IngestionService::contentHash($lateJson), 'schedule_stage' => 5, 'next_action_at' => $latePayload['issued_at'] + 300]);
        expect($buffer->beginCommand($lateJson, 1, $latePayload['issued_at'])['execute'], '历史动作初始事实未持久保存');
        $buffer->completeCommand($lateCommand['id'], 'succeeded', 'executed', (object) ['on' => false], $latePayload['issued_at'] + 65);
        $lateDispatch = $transaction(static fn (Connection $tx): ?array => CommandService::claim($tx));
        $lateResponse = $buffer->queryCommand(json_decode($lateDispatch['payload'], true, 32, JSON_THROW_ON_ERROR));
        expect($lateResponse['receipt']->finished_at > $latePayload['deadline_at'] && $send($lateResponse)['receipt']['type'] === 'command_receipt_ack', '长动作迟到查询结果未复用原回执确认');
        expect($connection->table('iot_commands')->where('id', '=', $lateCommand['id'])->first()['result_status'] === 'succeeded'
            && !$buffer->beginCommand($lateJson, 1, time())['execute'], '查询结果补全后重新执行了原动作');
    } finally {
        $buffer->close();
        unlink($path);
    }
    return ['command-online-and-model-authorization', 'readonly-control-denied', 'single-dispatch-and-original-60s-deadline',
        'cancel-before-claim-and-idempotent-replay', 'never-dispatched-expiry', 'real-role-revocation-before-claim',
        'same-account-session-idempotency-isolation', 'impersonation-origin-and-logout-before-claim', 'claimed-command-cannot-be-cancelled',
        'mqtt-ack-not-execution', 'persist-before-action-and-crash-unknown', 'execution-result-and-ack-idempotency',
        'same-http-id-keeps-deadline-and-content-conflict', 'unknown-to-late-terminal-result', 'terminal-result-conflict',
        '24h-dedup-and-expired-replay', 'retention-uses-trusted-lower-bound', 'trusted-clock-challenge', 'untrusted-and-expired-no-action', '60s-no-evidence-unknown',
        'real-0-10-30-original-payload-and-maximum-three', 'missed-slots-no-catchup-burst', 'execution-evidence-stops-retry', '60-120-300-query-only',
        'query-missing-record-stays-unknown', 'manual-query-authorization-and-idempotency', 'concurrent-query-coalesced-and-claim-once', '180d-bounded-cleanup-rollback-and-unknown-audit', 'late-query-result-completes-original-long-action'];
}

function main(int $argc, array $argv): void
{
    $name = $argv[1];
    $environment = getenv();
    $prefix = 'TYPE_' . strtoupper($name) . '_';
    $driver = match ($name) {
        'mysql' => new MysqlDriver($environment[$prefix . 'HOST'], (int) $environment[$prefix . 'PORT'], $environment[$prefix . 'DATABASE'], $environment[$prefix . 'USER'], $environment[$prefix . 'PASSWORD']),
        'pgsql' => new PgsqlDriver($environment[$prefix . 'HOST'], (int) $environment[$prefix . 'PORT'], $environment[$prefix . 'DATABASE'], $environment[$prefix . 'USER'], $environment[$prefix . 'PASSWORD']),
        default => new SqliteDriver($environment['INGESTION_SQLITE'], 1000, true),
    };
    $pool = null;
    $scope = null;
    try {
        $installation = Schema::install($driver, ['platform', '平台', 'owner', '租户管理员', '接收租户'], 'Test-ingestion-password', 'Test-ingestion-password');
        $pool = new Database($driver, 2, 1);
        $scope = new ExecutionScope();
        $connection = $pool->connect($scope);
        $identityService = new IdentityService();
        $owner = $installation['customer'];
        $outsider = $identityService->provision($connection, 'outsider', '其他人员', 'Test-ingestion-password', false);
        $platform = fixtureSession($connection, 'platform', 'admin')['identity'];
        $identity = fixtureSession($connection, 'owner')['identity'];
        $tenants = new TenantService();
        $tenant = ['id' => $installation['tenant_id']];
        $tenantB = fixtureTenant($connection, $platform, '另一租户', 'owner');
        $products = new ProductService();
        $product = $products->create($connection, $identity, $tenant['id'], '采集器', '');
        $definition = ['properties' => [
            ['identifier' => 'temperature', 'name' => '温度', 'type' => 'number', 'required' => true, 'unit' => '°C'],
            ['identifier' => 'relay', 'name' => '继电器', 'type' => 'boolean', 'required' => false],
            ['identifier' => 'note', 'name' => '说明', 'type' => 'string', 'required' => false, 'max_length' => 4096],
        ], 'events' => [['identifier' => 'fault', 'name' => '故障', 'parameters' => [['identifier' => 'code', 'name' => '故障码', 'type' => 'integer', 'required' => true]]]],
            'commands' => [['identifier' => 'switch', 'name' => '切换开关', 'parameters' => [['identifier' => 'on', 'name' => '开关状态', 'type' => 'boolean', 'required' => true]]]]];
        $products->createModel($connection, $identity, $tenant['id'], $product['id'], $definition);
        $products->changeModel($connection, $identity, $tenant['id'], $product['id'], 1, 1, 'publish');
        $registered = (new DeviceService())->register($connection, $identity, $tenant['id'], $product['id'], 1, '测试设备');
        $device = $registered['device'];
        $topic = $registered['credential']['topics']['publish'];
        $receivedAt = time();
        $envelope = ['app_version' => 1, 'type' => 'telemetry', 'device_id' => $device['id'], 'ownership_id' => $device['ownership_id'],
            'model_version' => 1, 'sequence' => '1', 'sampled_at' => $receivedAt, 'values' => ['temperature' => 20, 'relay' => true]];
        $send = static function (array|string $message, int $at, int $qos = 1, ?string $target = null) use ($connection, $topic, $name): array {
            $payload = is_string($message) ? $message : json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            return $connection->transaction(static fn (Connection $transaction): array => IngestionService::accept($transaction, $target ?? $topic, $payload, $qos, $at), $name === 'sqlite' ? 'immediate' : 'default');
        };
        $current = static fn (): array => IngestionService::current($connection, $identity, $tenant['id'], $device['id']);
        expect($current()['fields'] === [] && $current()['last_receipt'] === null, '注册后没有伪造当前值或接收结果');
        try {
            IngestionService::accept($connection, $topic, '{}', 1, $receivedAt);
            throw new RuntimeException('事务外接收必须拒绝');
        } catch (LogicException $expected) {
            expect($expected->getMessage() === 'ingestion_transaction_required', '事务边界错误');
        }
        $first = $send($envelope, $receivedAt);
        expect($first['receipt']['status'] === 'accepted' && $first['topic'] === $registered['credential']['topics']['subscribe'], '合法上报应返回独立业务回执');
        expect($current()['sequence'] === '1' && count($current()['fields']) === 2, '当前字段应在同一接收事务可见');
        expect($current()['last_receipt'] === ['sequence' => '1', 'status' => 'accepted', 'code' => 'accepted', 'received_at' => $receivedAt], '最新平台结果应来自本阶段首次接收事实');
        $proof = $connection->table('iot_ingestion')->where('message_id', '=', $first['message_id'])->first()['receipt_proof_nonce'];
        $reordered = array_reverse($envelope, true);
        $reordered['values'] = ['relay' => true, 'temperature' => 20.0];
        $repeat = $send($reordered, $receivedAt + 172801);
        expect($repeat['receipt'] === $first['receipt'], '键顺序和等价数值表示不影响身份，重复不重新校验年龄');
        expect(count(IngestionService::pending($connection, 'history', 100)) === 1, '重复不得增殖原始事实');
        expect($proof !== $connection->table('iot_ingestion')->where('message_id', '=', $first['message_id'])->first()['receipt_proof_nonce'], '重复回执必须产生新的真实写入屏障');
        expect($current()['last_receipt']['received_at'] === $receivedAt, '重试请求时间不能充当新的首次接收时间');
        expect($send(array_replace($envelope, ['app_version' => 1.0]), $receivedAt)['code'] === 'invalid_envelope', '相等数值不能绕过版本字段的严格类型');
        expect($send($envelope, $receivedAt, 0)['code'] === 'invalid_envelope', '已经接受的身份也不能绕过QoS契约');
        $maximumPayload = json_encode($envelope, JSON_THROW_ON_ERROR);
        expect($send(str_pad($maximumPayload, 16384, ' '), $receivedAt)['receipt'] === $first['receipt'], '完整合法16KiB包必须接纳');
        $conflict = $envelope;
        $conflict['values']['temperature'] = 21;
        expect($send($conflict, $receivedAt)['code'] === 'content_conflict', '同ID异内容必须永久拒绝');
        expect((int) $connection->table('customer_audit')->where('tenant_id', '=', $tenant['id'])->where('action', '=', 'ingestion.rejected')->aggregate('COUNT') === 1, '内容冲突需脱敏审计');
        $newer = $envelope;
        $newer['sequence'] = '10000000000000000000000000000000000000';
        $newer['sampled_at'] = $receivedAt + 1;
        $newer['values'] = ['temperature' => 25];
        $send($newer, $receivedAt + 1);
        $fields = array_column($current()['fields'], null, 'identifier');
        expect($fields['relay']['sampled_at'] === $receivedAt && $fields['temperature']['sampled_at'] === $receivedAt + 1, '缺省属性不能覆盖原采样时间');
        $late = $envelope;
        $late['sequence'] = '2';
        $late['values']['temperature'] = 99;
        expect($send($late, $receivedAt)['code'] === 'accepted' && $current()['sequence'] === $newer['sequence'], '乱序写原始事实但不能回退当前值');
        $event = $envelope;
        $event['sequence'] = '3';
        $event['type'] = 'event';
        $event['identifier'] = 'fault';
        $event['values'] = ['code' => 42];
        expect($send($event, $receivedAt)['code'] === 'accepted' && $current()['sequence'] === $newer['sequence'], '事件不能推进遥测当前值');
        foreach ([['sequence' => '4', 'sampled_at' => $receivedAt - 172800], ['sequence' => '5', 'sampled_at' => $receivedAt + 5]] as $edge) {
            expect($send(array_replace($envelope, $edge), $receivedAt)['code'] === 'accepted', '接收时间边界应为闭区间');
        }
        foreach ([['sequence' => '6', 'sampled_at' => $receivedAt - 172801, 'expected' => 'sample_expired'], ['sequence' => '7', 'sampled_at' => $receivedAt + 6, 'expected' => 'clock_ahead'],
            ['sequence' => '8', 'model_version' => 2, 'expected' => 'model_mismatch'], ['sequence' => '9', 'values' => ['temperature' => '20'], 'expected' => 'invalid_model_values'],
            ['sequence' => '10', 'values' => [], 'expected' => 'invalid_envelope'], ['sequence' => '11', 'app_version' => 2, 'expected' => 'invalid_envelope']] as $invalid) {
            $expectedCode = $invalid['expected'];
            unset($invalid['expected']);
            expect($send(array_replace($envelope, $invalid), $receivedAt)['code'] === $expectedCode, '应永久拒绝无效业务报文');
        }
        foreach (['0', '01', str_repeat('1', 39), 12] as $invalidSequence) {
            expect($send(array_replace($envelope, ['sequence' => $invalidSequence]), $receivedAt)['receipt'] === null, '无法信任稳定身份时不能发送回执');
        }
        expect($send($envelope, $receivedAt, 1, str_replace($tenant['id'], $tenantB['id'], $topic))['receipt'] === null, 'Topic不能冒用别的租户');
        expect($send(array_replace($envelope, ['ownership_id' => str_repeat('a', 32)]), $receivedAt)['receipt'] === null, '旧阶段身份不能形成新租户事实');
        expect($send(str_repeat(' ', 16385), $receivedAt)['receipt'] === null, '业务包上限为16KiB');
        expect($send('{"device_id":"x","device_id":"y"}', $receivedAt)['receipt'] === null, '重复JSON对象键必须拒绝');
        $qosInvalid = array_replace($envelope, ['sequence' => '12']);
        expect($send($qosInvalid, $receivedAt, 0)['code'] === 'invalid_envelope', '业务只接受QoS1');
        expect($send(array_replace($envelope, ['sequence' => '7', 'sampled_at' => $receivedAt + 6]), $receivedAt + 10)['code'] === 'clock_ahead', '永久拒绝不能随着时钟推进变成成功');
        $numeric = array_replace($envelope, ['sequence' => '13', 'values' => ['temperature' => 9007199254740992]]);
        $numericPayload = json_encode($numeric, JSON_THROW_ON_ERROR);
        expect($send($numericPayload, $receivedAt)['code'] === 'accepted', '合法number原始消息可接收');
        expect($send(str_replace('9007199254740992', '9007199254740993', $numericPayload), $receivedAt)['code'] === 'content_conflict', '十进制数字不能因浮点舍入丢失内容身份');
        $before = count(IngestionService::pending($connection, 'history', 100));
        try {
            $connection->transaction(static function (Connection $transaction) use ($envelope, $topic, $receivedAt): void {
                IngestionService::accept($transaction, $topic, json_encode(array_replace($envelope, ['sequence' => str_repeat('9', 38)]), JSON_THROW_ON_ERROR), 1, $receivedAt);
                throw new RuntimeException('rollback');
            }, $name === 'sqlite' ? 'immediate' : 'default');
        } catch (RuntimeException $rolledBack) {
            expect($rolledBack->getMessage() === 'rollback', '回滚错误不应被吞掉');
        }
        expect(count(IngestionService::pending($connection, 'history', 100)) === $before && $current()['sequence'] === $newer['sequence'], '接收回滚必须同时撤回原始、账本及当前字段');
        $effect = static function (Connection $transaction, array $fact) use ($owner): void {
            AuditLog::append($transaction, $fact['tenant_id'], $owner['id'], 'ingestion.effect', $fact['message_id'], 'success', ['context' => 'consumer'], 'customer');
        };
        expect(IngestionService::consume($connection, $first['message_id'], 'history', $effect), '首个消费者应实际运行效果');
        expect(!IngestionService::consume($connection, $first['message_id'], 'history', $effect), '重复消费者不能再产生效果');
        expect(IngestionService::consume($connection, $first['message_id'], 'alarm', $effect), '另一消费者有独立完成事实');
        expect(count(IngestionService::pending($connection, 'current', 100)) === 0, '当前值消费者完成在原始接收事务中');
        try {
            IngestionService::consume($connection, $first['message_id'], 'retry', static function (Connection $transaction, array $fact) use ($effect): void {
                $effect($transaction, $fact);
                throw new RuntimeException('effect_failed');
            });
        } catch (RuntimeException $failedEffect) {
            expect($failedEffect->getMessage() === 'effect_failed', '消费者错误需传播');
        }
        expect((int) $connection->table('customer_audit')->where('tenant_id', '=', $tenant['id'])->where('action', '=', 'ingestion.effect')->aggregate('COUNT') === 2, '失败消费者的业务效果需回滚');
        expect(IngestionService::consume($connection, $first['message_id'], 'retry', $effect), '失败不留下完成标记，之后可以恢复');
        foreach ([[$tenantB['id'], $identity, 404], [$tenant['id'], fixtureSession($connection, 'outsider')['identity'], 403]] as $denied) {
            try {
                IngestionService::current($connection, $denied[1], $denied[0], $device['id']);
                throw new RuntimeException('越权当前值查询未拒绝');
            } catch (HttpError $forbidden) {
                expect($forbidden->status() === $denied[2], '当前数据必须核对实际租户权限与归属');
            }
        }
        $send(array_replace($envelope, ['sequence' => '20', 'model_version' => 2]), $receivedAt + 20);
        expect($current()['last_receipt'] === ['sequence' => '20', 'status' => 'rejected', 'code' => 'model_mismatch', 'received_at' => $receivedAt + 20]
            && $current()['sequence'] === $newer['sequence'], '最新拒绝结果可见但不能覆盖已接收当前值');
        $send($envelope, $receivedAt + 21);
        expect($current()['last_receipt']['sequence'] === '20', '旧消息重试不能改变最新首次接收排序');
        $ties = [];
        foreach (['21', '22'] as $sequence) {
            $result = $send(array_replace($envelope, ['sequence' => $sequence, 'model_version' => 2]), $receivedAt + 22);
            $ties[$result['message_id']] = $sequence;
        }
        krsort($ties, SORT_STRING);
        expect($current()['last_receipt']['sequence'] === array_values($ties)[0], '同秒首次接收按稳定消息标识排序');
        $products->createModel($connection, $identity, $tenant['id'], $product['id'], $definition);
        $products->changeModel($connection, $identity, $tenant['id'], $product['id'], 2, 1, 'publish');
        $connection->table('iot_devices')->where('id', '=', $device['id'])->update(['model_version' => 2]);
        expect($current()['fields'] === [] && $current()['last_receipt']['sequence'] === array_values($ties)[0], '模型改变清空旧模型当前值，但保留本归属阶段接收结果');
        $connection->table('iot_devices')->where('id', '=', $device['id'])->update(['model_version' => 1, 'ownership_id' => str_repeat('b', 32)]);
        expect($current()['fields'] === [] && $current()['last_receipt'] === null, '旧归属阶段当前值与接收事实不得出现在新阶段');
        $connection->table('iot_devices')->where('id', '=', $device['id'])->update(['ownership_id' => $device['ownership_id']]);
        // 状态观察复用真实缓存快照；只用装置启用新设备，不把此处当MQTT认证证明。
        $statusDevice = (new DeviceService())->register($connection, $identity, $tenant['id'], $product['id'], 1, '缓存状态设备');
        $statusId = $statusDevice['device']['id'];
        $connection->table('iot_devices')->where('id', '=', $statusId)->update(['lifecycle' => 'enabled']);
        $buffer = new DeviceBuffer((string) getenv('INGESTION_STATUS_CACHE'), $statusId, $statusDevice['device']['ownership_id'], 1, 1000);
        try {
            $record = $buffer->enqueue(1, 'telemetry', $receivedAt, (object) ['temperature' => 20]);
            expect($buffer->enqueue(1, 'telemetry', $receivedAt, (object) ['temperature' => 21]) === null, '满额装置未拒绝采样');
            $snapshot = $buffer->snapshot(1);
            $statusTopic = $statusDevice['credential']['topics']['publish'];
            $statusSend = static function (array $data, int $at, int $qos = 1) use ($connection, $statusTopic, $name): array {
                return $connection->transaction(static fn (Connection $transaction): array => IngestionService::accept(
                    $transaction,
                    $statusTopic,
                    json_encode($data, JSON_THROW_ON_ERROR),
                    $qos,
                    $at
                ), $name === 'sqlite' ? 'immediate' : 'default');
            };
            $statusCurrent = static fn (): array => IngestionService::current($connection, $identity, $tenant['id'], $statusId);
            expect($snapshot['sequence'] === '2' && $buffer->pending() === [$record], '快照改变原件或占用可靠采样容量');
            expect($statusSend($snapshot, $receivedAt)['code'] === 'device_status_observed', '合法缓存观察未持久保存');
            $observed = $statusCurrent();
            expect($observed['buffer']['values']['full'] && $observed['buffer']['values']['not_admitted'] === 1
                && $observed['buffer']['values']['pending_count'] === 1 && $observed['fields'] === [] && $observed['last_receipt'] === null, '缓存满未可见或状态观察伪造业务接收');
            expect($statusSend($snapshot, $receivedAt + 100)['code'] === 'device_status_ignored'
                && $statusCurrent()['buffer']['received_at'] === $receivedAt, '重复快照刷新了平台观察时间');
            $invalidStatus = $snapshot;
            $invalidStatus['sequence'] = '3';
            $invalidStatus['values']['pending_count'] = 2;
            expect($statusSend($invalidStatus, $receivedAt)['code'] === 'invalid_device_status' && $statusCurrent()['buffer'] === $observed['buffer'], '非法额度改写有效观察');
            expect($statusSend($snapshot, $receivedAt, 0)['code'] === 'invalid_device_status', 'QoS0不能伪造设备业务状态');
            $buffer->applyReceipt(['app_version' => 1, 'type' => 'ingestion_receipt', 'device_id' => $statusId,
                'ownership_id' => $statusDevice['device']['ownership_id'], 'sequence' => $record['sequence'], 'content_hash' => $record['content_hash'],
                'status' => 'accepted', 'code' => 'accepted', 'received_at' => $receivedAt]);
            $cleared = $buffer->snapshot(1);
            expect($statusSend($cleared, $receivedAt)['code'] === 'device_status_observed'
                && !$statusCurrent()['buffer']['values']['full'] && $statusCurrent()['buffer']['values']['pending_count'] === 0, '排空后的新快照未解除满额观察');
            $expired = $buffer->snapshot(1);
            $expired['sampled_at'] = $receivedAt - 61;
            $statusSend($expired, $receivedAt);
            expect($statusCurrent()['buffer']['freshness'] === 'stale', '旧状态补传被标为新鲜观察');
            $connection->table('iot_devices')->where('id', '=', $statusId)->update(['model_version' => 2]);
            expect($statusCurrent()['buffer'] === null, '旧模型状态泄漏到新模型');
            $connection->table('iot_devices')->where('id', '=', $statusId)->update(['model_version' => 1, 'ownership_id' => str_repeat('e', 32)]);
            expect($statusCurrent()['buffer'] === null, '旧归属状态泄漏到新阶段');
        } finally {
            $buffer->close();
        }
        $freshnessChecks = freshnessCases($connection, $identity, $tenant['id'], $product['id']);
        $commandChecks = commandCases($connection, $identity, $tenant['id'], $product['id']);
        $modelChecks = modelSwitchCases($connection, $identity, $tenant['id']);
        $transferChecks = transferCases($connection, $identity, $platform, $tenant['id'], $product['id']);
        $ownershipChecks = ownershipCases($connection, $identity, $platform, $tenant['id'], $product['id']);
        $scope->close();
        $scope = new ExecutionScope();
        $reopened = $pool->connect($scope);
        expect(IngestionService::current($reopened, $identity, $tenant['id'], $device['id'])['sequence'] === $newer['sequence'], '更换连接后仍读取持久当前事实');
        echo json_encode(['status' => 'passed', 'accepted-facts' => $before, 'checks' => ['idempotency', 'new-receipt-write-barrier', 'exact-canonical-content', 'sequence-order', 'per-field-time', 'event', '48h-and-clock-boundaries', 'rollback', 'independent-consumers', 'tenant-isolation', 'latest-first-reception', 'receipt-tie-order', 'ownership-model-isolation', 'reopen', 'bounded-device-status', 'status-no-receipt', 'status-stale-isolation', ...$freshnessChecks, ...$commandChecks, ...$modelChecks, ...$transferChecks, ...$ownershipChecks]], JSON_THROW_ON_ERROR), "\n";
    } finally {
        $scope?->close();
        $pool?->close();
    }
}
