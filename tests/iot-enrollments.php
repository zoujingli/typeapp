<?php

declare(strict_types=1);

/** 复用双端隔离装置，通过真实 HTTP 检查注册资格的权限、幂等、期限和审计事务。 */
function iotEnrollmentChecks(Closure $request, PDO $inspection, string $driver, string $address, string $platform, string $password): array
{
    $call = static fn (string $method, string $path, string $token, ?array $body = null, int $status = 200, array $headers = [], string $error = ''): array
        => $request($method, $path, $token, $headers, $body, $status, $error);
    $tenant = bin2hex(random_bytes(16));
    $other = bin2hex(random_bytes(16));
    $call('POST', '/admin/tenants', $platform, ['id' => $tenant, 'name' => '注册授权甲', 'new_customer' => true, 'owner_login' => 'enrollment-owner', 'owner_name' => '授权客户', 'owner_password' => $password]);
    $call('POST', '/admin/tenants', $platform, ['id' => $other, 'name' => '注册授权乙', 'new_customer' => false, 'owner_login' => 'enrollment-owner']);
    $customer = $call('POST', '/customer/auth/login', '', ['login' => 'enrollment-owner', 'password' => $password])['data']['accessToken'];
    $headers = ['X-Tenant-Id' => $tenant];
    $path = '/admin/device-authorizations';
    $customerPath = '/customer/tenants/' . $tenant . '/device-authorizations';
    $otherPath = '/customer/tenants/' . $other . '/device-authorizations';
    $call('GET', $path, '', null, 401);
    $call('GET', $path, $customer, null, 401);
    $call('GET', $path, $platform, null, 403, $headers, 'identity_context_invalid');
    $call('GET', $customerPath, $platform, null, 401, $headers);
    $call('GET', $customerPath, $customer, null, 403, [], 'tenant_context_mismatch');
    $call('GET', $customerPath, $customer, null, 403, ['X-Tenant-Id' => $other], 'tenant_context_mismatch');
    $call('GET', $customerPath . '?tenant_id=' . $other, $customer, null, 422, $headers, 'enrollment_query_invalid');
    $call('GET', $path . '?per_page=101', $platform, null, 422);
    $call('GET', $path . '?secret_hash=1', $platform, null, 422, [], 'enrollment_query_invalid');
    $catalog = $call('GET', '/admin/roles', $platform)['data'];
    expect(isset($catalog['catalog']['admin.enrollments.issue']) && isset($catalog['catalog']['admin.enrollments.reset']), '平台缺少独立签发和换码权限');
    $role = $call('POST', '/admin/roles', $platform, ['name' => '注册资格只读', 'permissions' => ['identity.read', 'admin.devices.read', 'admin.enrollments.read']])['data'];
    $call('POST', '/admin/roles/' . $role['id'] . '/status', $platform, ['version' => 1, 'enabled' => true]);
    $readerUser = $call('POST', '/admin/users', $platform, ['login' => 'enrollment-reader', 'name' => '只读授权人员', 'password' => $password])['data'];
    $call('PUT', '/admin/users/roles', $platform, ['users' => [['id' => $readerUser['id'], 'version' => $readerUser['version']]], 'roles' => [['id' => $role['id'], 'version' => 2]]]);
    $reader = $call('POST', '/admin/auth/login', '', ['login' => 'enrollment-reader', 'password' => $password])['data']['accessToken'];
    $id = bin2hex(random_bytes(16));
    $input = ['id' => $id, 'tenant_id' => $tenant, 'name' => '安装批次甲', 'capacity' => 2, 'register_expires_at' => time() + 3600];
    $call('POST', $path, $reader, $input, 403, [], 'permission_denied');
    $call('POST', $path, $customer, $input, 401);
    $call('POST', $customerPath, $customer, $input, 405, $headers, 'method_not_allowed');
    $call('POST', $path, $platform, $input + ['registered_count' => 0], 422, [], 'unexpected_field');
    $call('POST', $path, $platform, array_replace($input, ['capacity' => 0]), 422);
    $call('POST', $path, $platform, array_replace($input, ['register_expires_at' => time() - 1]), 422, [], 'enrollment_expired');
    $issued = $call('POST', $path, $platform, $input, 201)['data'];
    expect($issued['authorization']['id'] === $id && $issued['authorization']['remaining'] === 2 && $issued['authorization']['status'] === 'available'
        && $issued['replayed'] === false && preg_match('/^' . $id . '\\.[a-f0-9]{64}$/D', $issued['code']) === 1, '签发结果或一次性注册码不完整');
    $secret = explode('.', $issued['code'], 2)[1];
    $stored = $inspection->prepare('SELECT secret_hash FROM iot_device_enrollments WHERE id = ?');
    $stored->execute([$id]);
    expect($stored->fetchColumn() === hash('sha256', $secret), '注册码必须以验证摘要保存');
    $stored->closeCursor();
    $replay = $call('POST', $path, $platform, $input)['data'];
    expect($replay['authorization'] === $issued['authorization'] && $replay['code'] === null && $replay['replayed'] === true, '响应丢失重试不能增额或重复暴露秘密');
    $call('POST', $path, $platform, array_replace($input, ['capacity' => 3]), 409, [], 'enrollment_request_conflict');
    $call('POST', $path, $platform, array_replace($input, ['tenant_id' => $other]), 409, [], 'enrollment_request_conflict');
    $list = $call('GET', $path . '?tenant_id=' . $tenant, $reader);
    $mine = $call('GET', $customerPath, $customer, null, 200, $headers);
    expect($list['items'] === $mine['items'] && $mine['total'] === 1, '两端必须看到同一持久资格');
    expect($call('GET', $otherPath, $customer, null, 200, ['X-Tenant-Id' => $other])['total'] === 0, '注册授权串租户');
    $detail = $call('GET', $customerPath . '/' . $id, $customer, null, 200, $headers)['data'];
    expect($detail['authorization'] === $issued['authorization'] && $detail['bindings']['total'] === 0, '详情不能捏造设备绑定结果');
    $call('GET', $otherPath . '/' . $id, $customer, null, 404, ['X-Tenant-Id' => $other], 'enrollment_not_found');
    $encoded = json_encode([$list, $mine, $detail], JSON_THROW_ON_ERROR);
    expect(!str_contains($encoded, $secret) && !str_contains($encoded, 'secret_hash') && !str_contains($encoded, 'request_hash'), '普通投影包含可用秘密或内部验证值');
    $changed = $call('POST', $path . '/' . $id . '/reset', $platform, ['tenant_id' => $tenant, 'version' => 1])['data'];
    expect($changed['authorization']['version'] === 2 && $changed['authorization']['capacity'] === 2 && $changed['authorization']['registered_count'] === 0
        && $changed['authorization']['register_expires_at'] === $input['register_expires_at'] && $changed['code'] !== $issued['code'], '重置必须只轮换注册码及版本');
    $call('POST', $path . '/' . $id . '/reset', $platform, ['tenant_id' => $tenant, 'version' => 1], 409, [], 'stale_version');
    $call('POST', $path . '/' . $id . '/reset', $platform, ['tenant_id' => $other, 'version' => 2], 404, [], 'enrollment_not_found');
    $call('POST', $path . '/' . $id . '/disable', $reader, ['tenant_id' => $tenant, 'version' => 2], 403, [], 'permission_denied');
    $race = identityCompete($inspection, $driver, $address, [
        ['POST', $path . '/' . $id . '/reset', $platform, ['tenant_id' => $tenant, 'version' => 2]],
        ['POST', $path . '/' . $id . '/disable', $platform, ['tenant_id' => $tenant, 'version' => 2]],
    ]);
    sort($race);
    expect($race === [200, 409], '并发换码停用不得同时接受相同表单版本');
    $disabled = $call('POST', $path . '/' . $id . '/disable', $platform, ['tenant_id' => $tenant, 'version' => 3])['data'];
    expect($disabled['authorization']['status'] === 'disabled' && $disabled['authorization']['version'] === 4, '停用未持久保存');
    $call('POST', $path . '/' . $id . '/reset', $platform, ['tenant_id' => $tenant, 'version' => 4], 409, [], 'enrollment_unavailable');
    $duplicate = array_replace($input, ['id' => bin2hex(random_bytes(16)), 'name' => '并发签发']);
    $duplicates = identityCompete($inspection, $driver, $address, [['POST', $path, $platform, $duplicate], ['POST', $path, $platform, $duplicate]]);
    sort($duplicates);
    expect($duplicates === [200, 201] && $call('GET', $customerPath, $customer, null, 200, $headers)['total'] === 2, '并发签发必须形成唯一资格与一次交付');
    $expiry = $inspection->prepare('UPDATE iot_device_enrollments SET register_expires_at = ? WHERE id = ?');
    $expiry->execute([time() - 1, $duplicate['id']]);
    $expired = $call('GET', $path . '/' . $duplicate['id'], $platform)['data']['authorization'];
    expect($expired['status'] === 'expired' && $expired['version'] === 1, '期限观察不能通过 GET 修改状态或版本');
    $call('POST', $path . '/' . $duplicate['id'] . '/reset', $platform, ['tenant_id' => $tenant, 'version' => 1], 409, [], 'enrollment_unavailable');
    $call('POST', '/admin/tenants/' . $other . '/status', $platform, ['version' => 1, 'enabled' => false]);
    $call('POST', $path, $platform, array_replace($input, ['id' => bin2hex(random_bytes(16)), 'tenant_id' => $other]), 409, [], 'enrollment_customer_disabled');
    $call('GET', $otherPath, $customer, null, 403, ['X-Tenant-Id' => $other]);
    if ($driver === 'pgsql') {
        $inspection->exec("CREATE FUNCTION enrollment_test_failure() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF NEW.action = ''enrollment.issued'' THEN RAISE EXCEPTION ''controlled enrollment audit failure''; END IF; RETURN NEW; END'");
        $inspection->exec('CREATE TRIGGER enrollment_test_failure BEFORE INSERT ON admin_audit FOR EACH ROW EXECUTE FUNCTION enrollment_test_failure()');
    } elseif ($driver === 'mysql') {
        $inspection->exec("CREATE TRIGGER enrollment_test_failure BEFORE INSERT ON admin_audit FOR EACH ROW BEGIN IF NEW.action = 'enrollment.issued' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'controlled enrollment audit failure'; END IF; END");
    } else {
        $inspection->exec("CREATE TRIGGER enrollment_test_failure BEFORE INSERT ON admin_audit WHEN NEW.action = 'enrollment.issued' BEGIN SELECT RAISE(ABORT, 'controlled enrollment audit failure'); END");
    }
    try {
        $call('POST', $path, $platform, array_replace($input, ['id' => bin2hex(random_bytes(16))]), 500);
        expect($call('GET', $customerPath, $customer, null, 200, $headers)['total'] === 2, '审计失败留下了未完整提交的注册资格');
    } finally {
        $inspection->exec('DROP TRIGGER enrollment_test_failure' . ($driver === 'pgsql' ? ' ON admin_audit' : ''));
        if ($driver === 'pgsql') {
            $inspection->exec('DROP FUNCTION enrollment_test_failure()');
        }
    }
    $audit = $inspection->prepare("SELECT tenant_id, actor_id, details FROM admin_audit WHERE action = 'enrollment.issued' AND subject_id = ?");
    $audit->execute([$id]);
    $events = $audit->fetchAll(PDO::FETCH_ASSOC);
    $audit->closeCursor();
    $actor = $call('GET', '/admin/auth/me', $platform)['data']['user']['id'];
    expect(count($events) === 1 && $events[0]['tenant_id'] === $tenant && $events[0]['actor_id'] === $actor
        && !str_contains(json_encode($events, JSON_THROW_ON_ERROR), $secret), '签发审计缺少真实来源、重复记录成功或泄漏秘密');
    return ['idempotent_issue' => $duplicates, 'concurrent_reset_disable' => $race, 'audit_rollback' => true,
        'tenant_isolation' => true, 'one_time_code' => true, 'expiration_without_write' => true, 'platform_only_issue' => true];
}
