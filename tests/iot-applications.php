<?php

declare(strict_types=1);

/** 双端真实 HTTP 验证接入应用管理；协议认证和撤权另由同一 MQTT 装置验证。 */
function iotApplicationChecks(Closure $request, PDO $inspection, string $driver, string $address, string $platform, string $password): array
{
    $call = static fn (string $method, string $path, string $token, ?array $body = null, int $status = 200, array $headers = [], string $error = ''): array
        => $request($method, $path, $token, $headers, $body, $status, $error);
    $call('GET', '/admin/applications', $platform);
    $tenant = bin2hex(random_bytes(16));
    $other = bin2hex(random_bytes(16));
    $call('POST', '/admin/tenants', $platform, ['id' => $tenant, 'name' => '接入应用客户', 'new_customer' => true, 'owner_login' => 'application-owner', 'owner_name' => '应用管理员', 'owner_password' => $password]);
    $call('POST', '/admin/tenants', $platform, ['id' => $other, 'name' => '另一个应用客户', 'new_customer' => false, 'owner_login' => 'application-owner']);
    $customer = $call('POST', '/customer/auth/login', '', ['login' => 'application-owner', 'password' => $password])['data']['accessToken'];
    $path = '/customer/tenants/' . $tenant . '/applications';
    $otherPath = '/customer/tenants/' . $other . '/applications';
    $headers = ['X-Tenant-Id' => $tenant];
    $call('GET', $path, $platform, null, 401, $headers);
    $call('GET', '/admin/applications', $customer, null, 401);
    $call('GET', '/admin/applications', $platform, null, 403, $headers, 'identity_context_invalid');
    $call('GET', $path, $customer, null, 403, [], 'tenant_context_mismatch');
    $call('GET', $path . '?tenant_id=' . $other, $customer, null, 422, $headers, 'application_query_invalid');
    $input = ['id' => bin2hex(random_bytes(16)), 'name' => '客户业务系统'];
    $call('POST', $path, $customer, $input + ['enabled' => true], 422, $headers, 'unexpected_field');
    $created = $call('POST', $path, $customer, $input, 201, $headers)['data'];
    $application = $created['application'];
    $credential = $created['credential'];
    expect($application['id'] === $input['id'] && $application['tenant_id'] === $tenant && $application['version'] === 1
        && $credential['client_id'] === 'app:' . $input['id'] && $credential['username'] === 'app:' . $input['id']
        && preg_match('/^[a-f0-9]{64}$/D', $credential['password']) === 1 && !$created['replayed'], '应用没有形成独立身份或一次性交付');
    $replayed = $call('POST', $path, $customer, $input, 200, $headers)['data'];
    expect($replayed['replayed'] && $replayed['credential'] === null && $replayed['application'] === $application, '创建重试重复签发密钥或新增应用');
    $call('POST', $path, $customer, array_replace($input, ['name' => '另一意图']), 409, $headers, 'application_request_conflict');
    $call('POST', $otherPath, $customer, $input, 409, ['X-Tenant-Id' => $other], 'application_request_conflict');
    $call('GET', $otherPath . '/' . $input['id'], $customer, null, 404, ['X-Tenant-Id' => $other], 'application_not_found');
    $mine = $call('GET', $path, $customer, null, 200, $headers);
    $global = $call('GET', '/admin/applications?tenant_id=' . $tenant, $platform);
    expect($mine['items'] === $global['items'] && $mine['total'] === 1, '双端应用目录不一致');
    $encoded = json_encode([$mine, $global], JSON_THROW_ON_ERROR);
    expect(!str_contains($encoded, $credential['password']) && !str_contains($encoded, 'secret_hash') && !str_contains($encoded, 'request_hash'), '普通应用投影泄漏秘密或验证值');
    $role = $call('POST', '/customer/roles', $customer, ['name' => '应用只读', 'permissions' => ['identity.read', 'customer.applications.read']], 200, $headers)['data'];
    $call('POST', '/customer/roles/' . $role['id'] . '/status', $customer, ['version' => 1, 'enabled' => true], 200, $headers);
    $call('POST', '/customer/members', $customer, ['login' => 'application-reader', 'new_customer' => true, 'account_name' => '应用只读人员', 'password' => $password,
        'name' => '只读人员', 'roles' => [['id' => $role['id'], 'version' => 2]]], 200, $headers);
    $reader = $call('POST', '/customer/auth/login', '', ['login' => 'application-reader', 'password' => $password])['data']['accessToken'];
    $call('GET', $path, $reader, null, 200, $headers);
    $call('POST', $path, $reader, ['id' => bin2hex(random_bytes(16)), 'name' => '越权应用'], 403, $headers, 'permission_denied');
    $base = $path . '/' . $input['id'];
    $devices = $call('GET', $base . '/devices', $reader, null, 200, $headers);
    expect($devices['items'] === [] && $devices['total'] === 0, '新应用不能自动获得客户设备权限');
    foreach (['update', 'disable', 'rotate', 'revoke'] as $action) {
        $call('POST', $base . '/' . $action, $reader, ['version' => 1] + ($action === 'update' ? ['name' => '越权修改'] : []), 403, $headers, 'permission_denied');
    }
    $changed = $call('POST', $base . '/update', $customer, ['version' => 1, 'name' => '更新后的客户应用'], 200, $headers)['data'];
    expect($changed['application']['version'] === 2 && $changed['credential'] === null, '资料修改不应更换密钥');
    $call('POST', $base . '/rotate', $customer, ['version' => 1], 409, $headers, 'stale_version');
    $changed = $call('POST', $base . '/rotate', $customer, ['version' => 2], 200, $headers)['data'];
    expect($changed['credential']['password'] !== $credential['password'] && $changed['application']['key']['version'] === 2, '换证没有推进独立凭据代次');
    $changed = $call('POST', $base . '/revoke', $customer, ['version' => 3], 200, $headers)['data'];
    expect(!$changed['application']['key']['active'] && $changed['credential'] === null, '撤销密钥未持久生效');
    $call('POST', $base . '/rotate', $customer, ['version' => 4], 409, $headers, 'application_key_unavailable');
    $changed = $call('POST', $base . '/issue', $customer, ['version' => 4], 200, $headers)['data'];
    expect($changed['application']['key']['active'] && $changed['application']['key']['version'] === 4, '重新签发必须继续单调递增，不能复活旧密钥');
    $changed = $call('POST', '/admin/applications/' . $input['id'] . '/disable', $platform, ['tenant_id' => $tenant, 'version' => 5])['data'];
    expect(!$changed['application']['enabled'], '控制端不能按独立权限停用客户应用');
    $call('POST', $base . '/issue', $customer, ['version' => 6], 409, $headers, 'application_disabled');
    $call('POST', $base . '/enable', $customer, ['version' => 6], 200, $headers);
    $brokerVersion = (int) $inspection->query('SELECT MAX(version) FROM broker_access_revisions')->fetchColumn();
    $call(
        'POST',
        '/customer/tenants/' . $tenant . '/broker/access/principals/' . $input['id'],
        $customer,
        ['name' => '绕过应用密钥权限', 'enabled' => 1, 'rotate' => 1, 'password' => $password, 'expected_version' => $brokerVersion],
        403,
        $headers,
        'broker_access_managed_principal'
    );
    $audit = $inspection->prepare("SELECT actor_id, details FROM admin_audit WHERE action = 'application.disable' AND subject_id = ?");
    $audit->execute([$input['id']]);
    $events = $audit->fetchAll(PDO::FETCH_ASSOC);
    $audit->closeCursor();
    $actor = $call('GET', '/admin/auth/me', $platform)['data']['user']['id'];
    expect(count($events) === 1 && $events[0]['actor_id'] === $actor && !str_contains(json_encode($events, JSON_THROW_ON_ERROR), $credential['password']), '跨客户应用管理缺失真实平台来源或泄漏密钥');
    $duplicate = ['id' => bin2hex(random_bytes(16)), 'name' => '并发应用', 'tenant_id' => $tenant];
    $duplicates = identityCompete($inspection, $driver, $address, [['POST', '/admin/applications', $platform, $duplicate], ['POST', '/admin/applications', $platform, $duplicate]]);
    sort($duplicates);
    expect($duplicates === [200, 201], '并发创建不得重复身份或交付密钥');
    $race = identityCompete($inspection, $driver, $address, [
        ['POST', '/admin/applications/' . $duplicate['id'] . '/rotate', $platform, ['tenant_id' => $tenant, 'version' => 1]],
        ['POST', '/admin/applications/' . $duplicate['id'] . '/disable', $platform, ['tenant_id' => $tenant, 'version' => 1]],
    ]);
    sort($race);
    expect($race === [200, 409], '并发应用变更必须串行核对表单版本');
    $grant = $call('POST', '/admin/device-authorizations', $platform, ['id' => bin2hex(random_bytes(16)), 'tenant_id' => $tenant,
        'name' => '身份冲突检查', 'capacity' => 1, 'register_expires_at' => time() + 3600], 201)['data'];
    $registration = ['id' => bin2hex(random_bytes(16)), 'tenant_id' => $tenant, 'device_id' => $input['id'], 'name' => '重复主体', 'code' => $grant['code']];
    $call('POST', '/public/devices/register', '', $registration, 409, [], 'device_already_registered');
    $registration['device_id'] = bin2hex(random_bytes(16));
    $registered = $call('POST', '/public/devices/register', '', $registration, 201)['data'];
    $call('POST', $path, $customer, ['id' => $registration['device_id'], 'name' => '冒用设备主体'], 409, $headers, 'application_request_conflict');
    $customerId = $call('GET', '/customer/auth/me', $customer)['data']['user']['id'];
    $simulation = $call('POST', '/admin/customers/' . $customerId . '/impersonate', $platform, ['version' => 1])['data'];
    $impersonated = $call('POST', $path, $simulation['accessToken'], ['id' => bin2hex(random_bytes(16)), 'name' => '模拟来源应用'], 201, $headers)['data'];
    $origin = $inspection->prepare("SELECT actor_id, details FROM customer_audit WHERE action = 'application.create' AND subject_id = ?");
    $origin->execute([$impersonated['application']['id']]);
    $originEvent = $origin->fetch(PDO::FETCH_ASSOC);
    $origin->closeCursor();
    expect($originEvent['actor_id'] === $actor && str_contains($originEvent['details'], $customerId), '模拟应用操作丢失真实平台和目标客户来源');
    if ($driver === 'pgsql') {
        $inspection->exec("CREATE FUNCTION application_test_failure() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF NEW.action = ''application.create'' THEN RAISE EXCEPTION ''controlled application audit failure''; END IF; RETURN NEW; END'");
        $inspection->exec('CREATE TRIGGER application_test_failure BEFORE INSERT ON admin_audit FOR EACH ROW EXECUTE FUNCTION application_test_failure()');
    } elseif ($driver === 'mysql') {
        $inspection->exec("CREATE TRIGGER application_test_failure BEFORE INSERT ON admin_audit FOR EACH ROW BEGIN IF NEW.action = 'application.create' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'controlled application audit failure'; END IF; END");
    } else {
        $inspection->exec("CREATE TRIGGER application_test_failure BEFORE INSERT ON admin_audit WHEN NEW.action = 'application.create' BEGIN SELECT RAISE(ABORT, 'controlled application audit failure'); END");
    }
    $counts = static function () use ($inspection): array {
        $result = [];
        foreach (['iot_applications', 'broker_access_principals', 'broker_access_credentials', 'broker_access_revisions'] as $table) {
            $result[$table] = (int) $inspection->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        }
        return $result;
    };
    $before = $counts();
    try {
        $call('POST', '/admin/applications', $platform, ['id' => bin2hex(random_bytes(16)), 'tenant_id' => $tenant, 'name' => '审计失败应用'], 500);
        expect($counts() === $before, '审计失败留下应用、凭据或未完整提交的授权发布');
    } finally {
        $inspection->exec('DROP TRIGGER application_test_failure' . ($driver === 'pgsql' ? ' ON admin_audit' : ''));
        if ($driver === 'pgsql') {
            $inspection->exec('DROP FUNCTION application_test_failure()');
        }
    }
    $communication = iotApplicationGrantChecks($call, $inspection, $driver, $address, $platform, $customer, $reader, $simulation['accessToken'], $password, $tenant, $other, $input['id'], $registered['device']);
    return ['communication' => $communication, 'realm_and_tenant_isolation' => true, 'permission_separation' => true, 'idempotent_create' => true,
        'one_time_secret' => true, 'rotate_revoke_reissue' => true, 'platform_actor' => true, 'generic_broker_bypass_denied' => true,
        'concurrent_create' => $duplicates, 'version_race' => $race, 'audit_atomicity' => true, 'impersonation_origin' => true, 'device_application_collision_denied' => true];
}

/** 逐设备授权经真实 HTTP、数据库约束和事务失败验证；协议收发另用真实 Broker。 */
function iotApplicationGrantChecks(Closure $call, PDO $database, string $driver, string $address, string $platform, string $customer, string $reader, string $simulation, string $password, string $tenant, string $other, string $application, array $device): array
{
    $headers = ['X-Tenant-Id' => $tenant];
    $base = '/customer/tenants/' . $tenant . '/applications/' . $application;
    $path = $base . '/devices/' . $device['id'];
    $input = ['ownership_id' => $device['ownership_id'], 'version' => 0, 'receive' => true, 'send' => false, 'subscribe' => false];
    $call('GET', '/customer/tenants/' . $other . '/applications/' . $application . '/devices', $customer, null, 404, ['X-Tenant-Id' => $other], 'application_not_found');
    $call('POST', $path, $reader, $input, 403, $headers, 'permission_denied');
    $call('POST', $path, $customer, $input + ['topic' => 'iot/#'], 422, $headers, 'unexpected_field');
    $call('POST', $path, $customer, array_replace($input, ['receive' => false, 'subscribe' => true]), 422, $headers, 'application_subscription_requires_receive');
    $call('POST', $path, $customer, array_replace($input, ['receive' => false]), 422, $headers, 'application_grant_empty');
    $call('POST', $path, $customer, array_replace($input, ['ownership_id' => bin2hex(random_bytes(16))]), 409, $headers, 'application_device_relationship_changed');
    $foreignGrant = $call('POST', '/admin/device-authorizations', $platform, ['id' => bin2hex(random_bytes(16)), 'tenant_id' => $other,
        'name' => '跨客户设备边界', 'capacity' => 1, 'register_expires_at' => time() + 3600], 201)['data'];
    $foreign = $call('POST', '/public/devices/register', '', ['id' => bin2hex(random_bytes(16)), 'tenant_id' => $other, 'device_id' => bin2hex(random_bytes(16)), 'name' => '其他客户设备', 'code' => $foreignGrant['code']], 201)['data']['device'];
    $call('POST', $base . '/devices/' . $foreign['id'], $customer, array_replace($input, ['ownership_id' => $foreign['ownership_id']]), 409, $headers, 'application_device_relationship_changed');
    $before = $call('GET', $base, $customer, null, 200, $headers)['data']['application'];
    $saved = $call('POST', $path, $customer, $input, 200, $headers)['data'];
    expect($saved['changed'] && $saved['grant']['version'] === 1 && $saved['grant']['current'] && $saved['grant']['receive'] && !$saved['grant']['subscribe']
        && $saved['application']['version'] === $before['version'] + 1 && $saved['application']['key'] === $before['key'], '接收资格必须显式创建，不能自动订阅或轮换密钥');
    $rules = $database->prepare('SELECT publish, subscribe, max_qos FROM broker_access_grants WHERE principal_id = ?');
    $rules->execute([$application]);
    $rows = $rules->fetchAll(PDO::FETCH_ASSOC);
    $rules->closeCursor();
    expect(count($rows) === 1 && (int) $rows[0]['publish'] === 0 && (int) $rows[0]['subscribe'] === 0 && (int) $rows[0]['max_qos'] === 2, '接收资格不应自动扩大 MQTT 收发或裁剪既有 QoS');
    $repeated = $call('POST', $path, $customer, array_replace($input, ['version' => 1]), 200, $headers)['data'];
    expect(!$repeated['changed'] && $repeated['application'] === $saved['application'] && $repeated['grant'] === $saved['grant'], '相同权限不能发布空变更');
    $call('POST', $path, $customer, $input, 409, $headers, 'stale_version');
    $role = $call('POST', '/customer/roles', $customer, ['name' => '仅通信授权', 'permissions' => ['identity.read', 'customer.applications.authorize']], 200, $headers)['data'];
    $call('POST', '/customer/roles/' . $role['id'] . '/status', $customer, ['version' => 1, 'enabled' => true], 200, $headers);
    $call('POST', '/customer/members', $customer, ['login' => 'application-authorizer', 'new_customer' => true, 'account_name' => '仅授权人员', 'password' => $password,
        'name' => '仅授权人员', 'roles' => [['id' => $role['id'], 'version' => 2]]], 200, $headers);
    $authorizer = $call('POST', '/customer/auth/login', '', ['login' => 'application-authorizer', 'password' => $password])['data']['accessToken'];
    $call('GET', $base . '/devices', $authorizer, null, 403, $headers, 'permission_denied');
    $saved = $call('POST', $path, $authorizer, array_replace($input, ['version' => 1, 'receive' => false, 'send' => true]), 200, $headers)['data'];
    expect($saved['grant']['version'] === 2 && $saved['grant']['send'] && !$saved['grant']['receive'], '发送权限须可独立赋予且不能隐式要求读取权限');
    $saved = $call('POST', $path, $simulation, array_replace($input, ['version' => 2, 'send' => true, 'subscribe' => true]), 200, $headers)['data'];
    $audit = $database->prepare("SELECT actor_id, details FROM customer_audit WHERE action = 'application.device_authorization' AND subject_id = ? AND result = 'success'");
    $audit->execute([$application]);
    $events = $audit->fetchAll(PDO::FETCH_ASSOC);
    $audit->closeCursor();
    $actor = $call('GET', '/admin/auth/me', $platform)['data']['user']['id'];
    $found = false;
    foreach ($events as $event) {
        $facts = json_decode($event['details'], true, 8, JSON_THROW_ON_ERROR);
        if ($event['actor_id'] === $actor && $facts['version'] === 3) {
            $found = $facts['receive_allowed'] === 1 && $facts['send_allowed'] === 1 && $facts['subscribe_allowed'] === 1
                && $facts['device_id'] === $device['id'] && $facts['ownership_id'] === $device['ownership_id'] && $facts['impersonation_id'] !== '';
        }
    }
    expect($found, '授权审计须保存三项能力及模拟管理的真实来源');
    $mine = $call('GET', $base . '/devices', $reader, null, 200, $headers);
    $global = $call('GET', '/admin/applications/' . $application . '/devices', $platform);
    expect($mine['items'] === $global['items'] && $mine['total'] === 1, '双端授权目录范围或当前关系不一致');
    $race = identityCompete($database, $driver, $address, [
        ['POST', '/admin/applications/' . $application . '/devices/' . $device['id'], $platform, array_replace($input, ['version' => 3, 'tenant_id' => $tenant])],
        ['POST', '/admin/applications/' . $application . '/devices/' . $device['id'], $platform, array_replace($input, ['version' => 3, 'receive' => false, 'send' => true, 'tenant_id' => $tenant])],
    ]);
    sort($race);
    expect($race === [200, 409], '并发授权必须锁定并拒绝旧版本');
    if ($driver === 'pgsql') {
        $database->exec("CREATE FUNCTION grant_test_failure() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF NEW.action = ''application.device_authorization'' THEN RAISE EXCEPTION ''controlled grant audit failure''; END IF; RETURN NEW; END'");
        $database->exec('CREATE TRIGGER grant_test_failure BEFORE INSERT ON admin_audit FOR EACH ROW EXECUTE FUNCTION grant_test_failure()');
    } elseif ($driver === 'mysql') {
        $database->exec("CREATE TRIGGER grant_test_failure BEFORE INSERT ON admin_audit FOR EACH ROW BEGIN IF NEW.action = 'application.device_authorization' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'controlled grant audit failure'; END IF; END");
    } else {
        $database->exec("CREATE TRIGGER grant_test_failure BEFORE INSERT ON admin_audit WHEN NEW.action = 'application.device_authorization' BEGIN SELECT RAISE(ABORT, 'controlled grant audit failure'); END");
    }
    $snapshot = static function () use ($database): array {
        $rows = [];
        foreach (['iot_applications', 'iot_application_grants', 'broker_access_grants', 'broker_access_revisions'] as $table) {
            $rows[$table] = $database->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        }
        return $rows;
    };
    $before = $snapshot();
    $revoked = array_replace($input, ['version' => 4, 'receive' => false]);
    try {
        $call('POST', '/admin/applications/' . $application . '/devices/' . $device['id'], $platform, $revoked + ['tenant_id' => $tenant], 500);
        expect($snapshot() === $before, '审计失败时授权、Broker 规则、应用版本与发布必须共同回滚');
    } finally {
        $database->exec('DROP TRIGGER grant_test_failure' . ($driver === 'pgsql' ? ' ON admin_audit' : ''));
        if ($driver === 'pgsql') {
            $database->exec('DROP FUNCTION grant_test_failure()');
        }
    }
    $saved = $call('POST', $path, $customer, $revoked, 200, $headers)['data'];
    expect($saved['grant']['version'] === 5 && !$saved['grant']['receive'] && !$saved['grant']['send'] && !$saved['grant']['subscribe'], '撤销必须保留版本化关系而非删除后接受旧表单');
    $call('POST', $path, $customer, array_replace($input, ['version' => 4]), 409, $headers, 'stale_version');
    $app = $saved['application'];
    $disabled = $call('POST', $base . '/disable', $customer, ['version' => $app['version']], 200, $headers)['data'];
    $call('POST', $path, $customer, array_replace($input, ['version' => 5]), 409, $headers, 'application_disabled');
    $call('POST', $base . '/enable', $customer, ['version' => $disabled['application']['version']], 200, $headers);
    return ['explicit_relationship_only' => true, 'receive_send_subscribe_separated' => true, 'same_change_no_publication' => true,
        'write_permission_independent' => true, 'cross_tenant_denied' => true, 'both_realms' => true, 'real_actor_and_flags' => true,
        'version_race' => $race, 'audit_rollback' => true, 'revocation_cannot_resurrect' => true, 'disabled_application_denied' => true];
}
