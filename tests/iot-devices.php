<?php

declare(strict_types=1);

/** 通过双端真实HTTP登记设备；数据库仅用于核对持久事实和确定性并发故障装置。 */
function iotDeviceChecks(Closure $appRequest, PDO $database, string $driver, string $address, string $platform, string $password): array
{
    $call = static fn (string $method, string $url, string $token, ?array $data = null, array $headers = []): array => $appRequest($method, $url, $token, $headers, $data, 200)['data'];
    $tenantA = bin2hex(random_bytes(16));
    $tenantB = bin2hex(random_bytes(16));
    $owner = $call('POST', '/admin/tenants', $platform, ['id' => $tenantA, 'name' => '设备甲', 'new_customer' => true, 'owner_login' => 'device-owner', 'owner_name' => '设备所有者', 'owner_password' => $password]);
    $call('POST', '/admin/tenants', $platform, ['id' => $tenantB, 'name' => '设备乙', 'new_customer' => false, 'owner_login' => 'device-owner']);
    $login = static fn (string $name): string => $call('POST', '/customer/auth/login', '', ['login' => $name, 'password' => $password])['accessToken'];
    $admin = $login('device-owner');
    $headers = ['X-Tenant-Id' => $tenantA];
    $role = $call('POST', '/customer/roles', $admin, ['name' => '设备查看', 'permissions' => ['identity.read', 'customer.devices.read']], $headers);
    $role = $call('POST', '/customer/roles/' . $role['id'] . '/status', $admin, ['version' => 1, 'enabled' => true], $headers);
    foreach (['device-reader', 'device-operator'] as $name) {
        $call('POST', '/customer/members', $admin, ['login' => $name, 'new_customer' => true, 'account_name' => $name, 'password' => $password, 'name' => $name, 'roles' => [['id' => $role['id'], 'version' => 2]]], $headers);
    }
    $readonly = $login('device-reader');
    $operator = $login('device-operator');
    $call('POST', '/admin/customers', $platform, ['login' => 'device-outsider', 'name' => '设备无成员客户', 'password' => $password]);
    $tokens = ['bob' => $admin, 'alice' => $readonly, 'carol' => $operator, 'platform' => $platform, 'outsider' => $login('device-outsider')];
    $request = static function (string $method, string $url, string $token, ?string $tenant, ?array $data, int $status, string $code = '') use ($appRequest): array {
        $response = $appRequest($method, $url, $token, $tenant === null ? [] : ['X-Tenant-Id' => $tenant], $data, $status);
        expect($code === '' || ($response['error'] ?? '') === $code, '设备错误码不符：' . $url . ' actual=' . ($response['error'] ?? ''));
        return $response;
    };
    $path = '/customer/tenants/' . $tenantA;
    $devices = $path . '/devices';
    $other = '/customer/tenants/' . $tenantB . '/devices';
    $product = $request('POST', $path . '/products', $admin, $tenantA, ['name' => '设备接入产品'], 201)['data'];
    $models = $path . '/products/' . $product['id'] . '/models';
    $definition = ['properties' => [['identifier' => 'temperature', 'name' => '温度', 'type' => 'number', 'required' => true, 'unit' => '°C']],
        'events' => [['identifier' => 'fault', 'name' => '故障', 'parameters' => [['identifier' => 'code', 'name' => '故障码', 'type' => 'integer', 'required' => true]]]],
        'commands' => [['identifier' => 'switch', 'name' => '切换开关', 'parameters' => [['identifier' => 'on', 'name' => '开关状态', 'type' => 'boolean', 'required' => true]]]]];
    $request('POST', $models, $admin, $tenantA, ['definition' => $definition], 201);
    $registration = ['name' => '一号温度计', 'product_id' => $product['id'], 'model_version' => 1];
    $request('POST', $devices, $admin, $tenantA, $registration, 409, 'model_not_published');
    $request('POST', $models . '/1/publish', $admin, $tenantA, ['version' => 1], 200);
    $request('GET', $devices, '', $tenantA, null, 401);
    $request('GET', $devices, $admin, null, null, 403, 'tenant_context_mismatch');
    $request('GET', $devices, $admin, $tenantB, null, 403, 'tenant_context_mismatch');
    foreach ([$tokens['outsider']] as $outsider) {
        $request('GET', $devices, $outsider, $tenantA, null, 403, 'permission_denied');
        $request('POST', $devices, $outsider, $tenantA, $registration, 403, 'permission_denied');
    }
    foreach ([$readonly, $operator] as $viewer) {
        expect($request('GET', $devices, $viewer, $tenantA, null, 200)['total'] === 0, '拒绝注册不能生成设备');
        $request('POST', $devices, $viewer, $tenantA, $registration, 403, 'permission_denied');
    }
    $request('POST', $other, $admin, $tenantB, $registration, 404, 'model_not_found');
    $request('POST', $devices, $admin, $tenantA, array_replace($registration, ['model_version' => 2]), 404, 'model_not_found');
    foreach ([['name' => ' '], ['model_version' => '1'], ['model_version' => 0], ['product_id' => 'unknown']] as $invalid) {
        $request('POST', $devices, $admin, $tenantA, array_replace($registration, $invalid), 422);
    }
    $request('GET', $devices, $platform, $tenantA, null, 401, 'unauthenticated');
    $request('POST', $devices, $admin, $tenantA, $registration + ['tenant_id' => $tenantB, 'lifecycle' => 'enabled'], 422, 'unexpected_field');
    $created = $request('POST', $devices, $admin, $tenantA, $registration, 201)['data'];
    $device = $created['device'];
    $credential = $created['credential'];
    expect($device['tenant_id'] === $tenantA && $device['product_id'] === $product['id'] && $device['model_version'] === 1
        && $device['lifecycle'] === 'inactive' && $device['version'] === 1, '注册必须绑定当前租户模型，且初始未激活');
    expect(preg_match('/^[a-f0-9]{32}$/D', $device['id']) === 1 && preg_match('/^[a-f0-9]{32}$/D', $device['ownership_id']) === 1
        && preg_match('/^[a-f0-9]{64}$/D', $credential['password']) === 1, '设备、阶段身份及随机凭据格式错误');
    expect($credential['client_id'] === $device['id'] && $credential['username'] === $device['id'] . ':' . $credential['id']
        && $credential['protocol_version'] === 5 && $credential['tls_required'] === true && $credential['keep_alive'] === 30
        && $credential['session_expiry'] === 86400, '设备连接参数不符合已确认契约');
    $prefix = 'iot/' . $tenantA . '/devices/' . $device['id'] . '/epochs/' . $device['ownership_id'];
    expect($credential['topics'] === ['publish' => $prefix . '/up', 'subscribe' => $prefix . '/down'], '设备Topic须绑定租户、设备和归属阶段');
    $stored = $database->prepare('SELECT * FROM iot_device_credentials WHERE device_id = ?');
    $stored->execute([$device['id']]);
    $records = $stored->fetchAll(PDO::FETCH_ASSOC);
    expect(count($records) === 1 && $records[0]['secret_hash'] === hash('sha256', $credential['password'])
        && !str_contains(json_encode($records, JSON_THROW_ON_ERROR), $credential['password']), '存储应只保留单向验证值');
    $detailPath = $devices . '/' . $device['id'];
    $detail = $request('GET', $detailPath, $readonly, $tenantA, null, 200)['data'];
    expect($detail['model']['model_version'] === 1 && $detail['model']['definition'] === $definition, '详情须按注册时模型解释');
    expect(!array_key_exists('current', $detail), '设备资料不隐式提供遥测权限');
    $request('GET', $other . '/' . $device['id'], $admin, $tenantB, null, 404, 'device_not_found');
    $request('GET', $detailPath, $tokens['outsider'], $tenantA, null, 403, 'permission_denied');
    $request('GET', $detailPath . '/credential', $admin, $tenantA, null, 404);
    $request('POST', $models, $admin, $tenantA, ['definition' => ['properties' => [['identifier' => 'humidity', 'name' => '湿度', 'type' => 'number', 'required' => true]], 'events' => [], 'commands' => []]], 201);
    $request('POST', $models . '/2/publish', $admin, $tenantA, ['version' => 1], 200);
    expect($request('GET', $detailPath, $admin, $tenantA, null, 200)['data'] === $detail, '新模型发布不能隐式改绑已注册设备');
    $second = $request('POST', $devices, $admin, $tenantA, array_replace($registration, ['name' => '二号温度计']), 201)['data'];
    expect($second['device']['id'] !== $device['id'] && $second['device']['ownership_id'] !== $device['ownership_id']
        && $second['credential']['password'] !== $credential['password'], '设备身份、阶段和凭据必须独立');
    $pageOne = $request('GET', $devices . '?per_page=1', $operator, $tenantA, null, 200);
    $pageTwo = $request('GET', $devices . '?per_page=1&page=2', $operator, $tenantA, null, 200);
    expect($pageOne['total'] === 2 && count($pageOne['items']) === 1 && count($pageTwo['items']) === 1
        && $pageOne['items'][0]['id'] !== $pageTwo['items'][0]['id'], '设备分页须有界且排序稳定');
    expect($request('GET', $devices . '?name=' . rawurlencode('一号'), $readonly, $tenantA, null, 200)['total'] === 1, '设备名称筛选须查真实资料');
    expect($request('GET', $devices . '?product_id=' . $product['id'], $readonly, $tenantA, null, 200)['total'] === 2, '设备产品筛选错误');
    expect($request('GET', $devices . '?lifecycle=inactive', $readonly, $tenantA, null, 200)['total'] === 2, '未激活与连接状态须分开');
    expect($request('GET', $devices . '?lifecycle=disabled', $readonly, $tenantA, null, 200)['total'] === 0, '空筛选结果不能混入其他生命周期');
    expect($request('GET', $other, $admin, $tenantB, null, 200)['total'] === 0, '设备列表不能跨租户');
    $request('GET', $devices . '?per_page=101', $readonly, $tenantA, null, 422);
    $request('GET', $devices . '?lifecycle=online', $readonly, $tenantA, null, 422);
    $auditQuery = $database->prepare("SELECT * FROM customer_audit WHERE subject_id = ? AND result = 'success'");
    $auditQuery->execute([$device['id']]);
    $audits = $auditQuery->fetchAll(PDO::FETCH_ASSOC);
    expect(count($audits) === 2, '设备与凭据生成应在同事务保留审计');
    foreach ([$detail, $pageOne, $pageTwo, $audits] as $public) {
        $json = json_encode($public, JSON_THROW_ON_ERROR);
        expect(!str_contains($json, $credential['password']) && !str_contains($json, $records[0]['secret_hash'])
            && !str_contains($json, 'secret_hash') && !str_contains($json, 'password'), '普通查询或审计泄漏设备凭据');
    }
    $assetPath = '/admin/devices/' . $device['id'];
    $asset = $request('GET', $assetPath, $platform, null, null, 200)['data'];
    expect($asset['id'] === $device['id'] && $asset['tenant_id'] === $tenantA && $asset['tenant_name'] === '设备甲', '平台资产必须显示准确归属');
    expect(array_intersect(array_keys($asset), ['model', 'topics', 'current', 'commands', 'exports']) === [], '平台资产权限不得包含租户私有业务投影');
    $request('GET', $assetPath, $admin, null, null, 401);
    $request('GET', $assetPath, $platform, $tenantA, null, 403, 'identity_context_invalid');
    foreach (['X-Support-Id', 'X-Impersonation-Id', 'X-Identity-Realm'] as $header) {
        $appRequest('GET', $assetPath, $platform, [$header => 'admin'], null, 403);
        $appRequest('GET', $detailPath, $admin, $headers + [$header => 'admin'], null, 403);
    }
    foreach (['commands', 'history', 'exports'] as $business) {
        $request('GET', $detailPath . '/' . $business, $platform, $tenantA, null, $business === 'exports' ? 405 : 401);
        $request('POST', $detailPath . '/' . $business, $platform, $tenantA, [], $business === 'history' ? 405 : 401);
        $request('GET', $assetPath . '/' . $business, $platform, null, null, 404);
    }
    $request('GET', '/iot/tenants/' . $tenantA . '/devices', $admin, $tenantA, null, 404);
    $request('GET', $devices . '?cursor=foreign', $admin, $tenantA, null, 422, 'device_query_invalid');
    $request('GET', $devices . '?tenant_id=' . $tenantB, $admin, $tenantA, null, 422, 'device_query_invalid');
    expect($request('GET', '/admin/devices?tenant_id=' . $tenantA, $platform, null, null, 200)['total'] === 2, '平台租户筛选错误');
    expect($request('GET', '/admin/devices?tenant_id=' . $tenantB, $platform, null, null, 200)['total'] === 0, '空归属筛选混入资产');
    $request('POST', '/admin/devices', $platform, null, $registration, 405);
    foreach (['tenant_id' => $tenantB, 'lifecycle' => 'enabled', 'product_id' => $product['id'], 'model_version' => 2] as $field => $value) {
        $request('PATCH', $assetPath, $platform, null, ['name' => '绕过状态机', 'version' => 1, $field => $value], 422, 'unexpected_field');
    }
    $longName = str_repeat('温', 100);
    $request('PATCH', $assetPath, $platform, null, ['name' => $longName . '度', 'version' => 1], 422);
    $renamed = $request('PATCH', $assetPath, $platform, null, ['name' => $longName, 'version' => 1], 200)['data']['device'];
    expect($renamed['name'] === $longName, '资料名称应按Unicode字符而非UTF-8字节计数');
    expect($renamed['version'] === 2 && $renamed['ownership_id'] === $device['ownership_id'] && $renamed['model_version'] === 1, '资产编辑只能修改资料并推进准确版本');
    $request('PATCH', $detailPath, $admin, $tenantA, ['name' => '过时写入', 'version' => 1], 409, 'stale_version');
    // 资料节点不隐含查看、凭据和生命周期节点；客户端必须使用已知的准确目标及版本。
    foreach (['admin', 'customer'] as $realm) {
        $rolePath = $realm === 'admin' ? '/admin/roles' : '/customer/roles';
        $actor = $realm === 'admin' ? $platform : $admin;
        $scopeHeaders = $realm === 'admin' ? [] : $headers;
        $editRole = $call('POST', $rolePath, $actor, ['name' => '仅设备资料-' . $realm, 'permissions' => [$realm . '.devices.update']], $scopeHeaders);
        $editRole = $call('POST', $rolePath . '/' . $editRole['id'] . '/status', $actor, ['version' => 1, 'enabled' => true], $scopeHeaders);
        if ($realm === 'admin') {
            $person = $call('POST', '/admin/users', $platform, ['login' => 'device-editor', 'name' => '资产资料维护', 'password' => $password]);
            $call('PUT', '/admin/users/roles', $platform, ['users' => [['id' => $person['id'], 'version' => 1]], 'roles' => [['id' => $editRole['id'], 'version' => 2]]]);
            $editor = $call('POST', '/admin/auth/login', '', ['login' => 'device-editor', 'password' => $password])['accessToken'];
        } else {
            $call('POST', '/customer/members', $admin, ['login' => 'device-customer-editor', 'new_customer' => true, 'account_name' => '设备资料维护', 'password' => $password, 'name' => '维护员', 'roles' => [['id' => $editRole['id'], 'version' => 2]]], $headers);
            $editor = $login('device-customer-editor');
        }
        $target = $realm === 'admin' ? $assetPath : $detailPath;
        $tenant = $realm === 'admin' ? null : $tenantA;
        $request('GET', $target, $editor, $tenant, null, 403, 'permission_denied');
        foreach (['enable', 'disable', 'retire', 'rotate', 'revoke'] as $action) {
            $request('POST', $target . '/' . $action, $editor, $tenant, ['version' => $renamed['version'], 'confirm_device_id' => $device['id']], 403, 'permission_denied');
        }
        $renamed = $request('PATCH', $target, $editor, $tenant, ['name' => '独立资料节点-' . $realm, 'version' => $renamed['version']], 200)['data']['device'];
        $call('POST', $rolePath . '/' . $editRole['id'] . '/status', $actor, ['version' => 2, 'enabled' => false], $scopeHeaders);
        $request('PATCH', $target, $editor, $tenant, ['name' => '撤权写入', 'version' => $renamed['version']], 403, 'permission_denied');
    }
    $before = (int) $renamed['version'];
    $races = identityCompete($database, $driver, $address, [
        ['PATCH', $assetPath, $platform, ['name' => '平台竞争', 'version' => $before]],
        ['PATCH', $detailPath, $admin, ['name' => '客户竞争', 'version' => $before], $headers],
    ]);
    sort($races);
    expect($races === [200, 409], '双端同设备准确版本竞争必须只有一次提交');
    $ownerId = $database->query("SELECT id FROM customer_users WHERE login = 'device-owner'")->fetchColumn();
    $source = $call('POST', '/admin/auth/login', '', ['login' => 'same-login', 'password' => $password]);
    $source['identity'] = $call('GET', '/admin/auth/me', $source['accessToken'])['identity'];
    $simulated = $call('POST', '/admin/customers/' . $ownerId . '/impersonate', $source['accessToken'], ['version' => 1]);
    $simulated['identity'] = $call('GET', '/customer/auth/me', $simulated['accessToken'], null, $headers)['identity'];
    $renamed = $request('PATCH', $detailPath, $simulated['accessToken'], $tenantA, ['name' => '模拟来源资料修改', 'version' => $before + 1], 200)['data']['device'];
    $auditQuery = $database->prepare("SELECT actor_id, details FROM customer_audit WHERE subject_id = ? AND action = 'device.update' AND result = 'success' ORDER BY created_at DESC, id");
    $auditQuery->execute([$device['id']]);
    $found = false;
    foreach ($auditQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $details = json_decode($row['details'], true, 8, JSON_THROW_ON_ERROR);
        if (($details['impersonation_id'] ?? '') === $simulated['identity']['impersonation_id']) {
            expect($row['actor_id'] === $source['identity']['actor_id'] && $details['customer_id'] === $ownerId
                && $details['source_session_id'] === $source['identity']['session_id'], '设备模拟审计必须保留准确管理来源');
            $found = true;
        }
    }
    expect($found, '缺少设备模拟审计');
    $revoked = identityCompete($database, $driver, $address, [
        ['PATCH', $detailPath, $simulated['accessToken'], ['name' => '来源退出不落地', 'version' => $renamed['version']], $headers],
    ], static function () use ($database, $source): void {
        $database->prepare('DELETE FROM admin_sessions WHERE id = ?')->execute([$source['identity']['session_id']]);
    });
    expect($revoked === [401], '等待期间来源退出必须拒绝设备写入');
    expect($request('GET', $detailPath, $admin, $tenantA, null, 200)['data']['name'] === '模拟来源资料修改', '来源退出后设备资料仍被修改');
    // MQTT专项在暂停Broker后提交模拟撤权，再撤销准确来源，验证原意图继续完成。
    $mqttSource = $call('POST', '/admin/auth/login', '', ['login' => 'same-login', 'password' => $password]);
    $mqttSource['identity'] = $call('GET', '/admin/auth/me', $mqttSource['accessToken'])['identity'];
    $mqttSimulated = $call('POST', '/admin/customers/' . $ownerId . '/impersonate', $mqttSource['accessToken'], ['version' => 1]);
    $mqttSimulated['identity'] = $call('GET', '/customer/auth/me', $mqttSimulated['accessToken'], null, $headers)['identity'];
    $device = $request('GET', $detailPath, $readonly, $tenantA, null, 200)['data'];
    return ['path' => $detailPath, 'tenant' => $tenantA, 'other_tenant' => $tenantB, 'token' => $readonly, 'admin_token' => $admin, 'platform_token' => $platform,
        'device' => $device, 'registration' => $created, 'second' => $second, 'tokens' => $tokens,
        'source' => $mqttSource, 'simulated' => $mqttSimulated,
        'checks' => ['dual-realm-assets', 'strict-fields', 'independent-device-identities', 'no-secret-projection', 'no-private-platform-business', 'independent-update-node', 'current-role-revocation', 'cross-realm-version-race', 'exact-impersonation-origin']];
}

/** 设备业务组合；设备资料用例不代替遥测、模型切换与指令控制的独立验证。 */
function iotDeviceBusinessChecks(Closure $request, array $tokens, string $tenantA, string $tenantB, PDO $database, array $environment, array $evidence): array
{
    $path = '/customer/tenants/' . $tenantA;
    $devices = $path . '/devices';
    $other = '/customer/tenants/' . $tenantB . '/devices';
    $admin = $tokens['bob'];
    $readonly = $tokens['alice'];
    $operator = $tokens['carol'];
    $device = $evidence['device'];
    $detailPath = $evidence['path'];
    $registration = ['name' => '业务切换设备', 'product_id' => $device['product_id'], 'model_version' => 1];
    $models = $path . '/products/' . $device['product_id'] . '/models';
    $definition = $device['model']['definition'];
    $second = $evidence['second'];
    iotDeviceBusinessPermissions($request, $admin, $tenantA);
    $detail = $request('GET', $detailPath, $readonly, $tenantA, null, 200)['data'];
    expect($request('GET', $detailPath . '/commands', $readonly, $tenantA, null, 200)['items'] === [], '只读成员不能查看已有指令事实');
    $control = ['command_id' => bin2hex(random_bytes(16)), 'identifier' => 'switch', 'values' => ['on' => true], 'version' => (int) $detail['version']];
    $request('POST', $detailPath . '/commands', $readonly, $tenantA, $control, 403, 'permission_denied');
    $request('POST', $detailPath . '/commands', $admin, $tenantA, $control, 409, 'command_device_not_online');
    $request('GET', $other . '/' . $device['id'] . '/commands', $admin, $tenantB, null, 404, 'device_not_found');
    $queryPath = $detailPath . '/commands/' . $control['command_id'] . '/queries';
    $request('POST', $queryPath, $readonly, $tenantA, ['query_id' => bin2hex(random_bytes(16))], 403, 'permission_denied');
    $request('POST', $queryPath, $operator, $tenantA, ['query_id' => bin2hex(random_bytes(16))], 404, 'command_not_found');
    $request('GET', $queryPath, $readonly, $tenantA, null, 404, 'command_not_found');
    $request('GET', $detailPath . '/current', $operator, $tenantA, null, 403, 'permission_denied');
    $request('GET', $detailPath . '/current', $tokens['platform'], $tenantA, null, 401, 'unauthenticated');
    $request('GET', $other . '/' . $device['id'] . '/current', $admin, $tenantB, null, 404, 'device_not_found');
    $empty = $request('GET', $detailPath . '/current', $readonly, $tenantA, null, 200)['data'];
    expect($empty['model']['definition'] === $definition, '独立遥测权限必须得到准确模型解释');
    unset($empty['model']);
    expect($empty === ['device_id' => $device['id'], 'ownership_id' => $device['ownership_id'], 'model_version' => 1,
        'sequence' => null, 'sampled_at' => null, 'received_at' => null, 'fields' => [], 'freshness' => 'empty',
        'realtime_sequence' => null, 'realtime_sampled_at' => null, 'realtime_received_at' => null,
        'buffer' => null, 'last_receipt' => null], '未上报设备的HTTP详情须明确返回空当前值、缓存观察和空平台接收结果');
    // 投影读取装置只验证HTTP及三库查询；真实接收、去重和推进另由iot-ingestion.php经过公开服务验证。
    $receivedAt = time();
    $sequence = str_repeat('8', 38);
    $field = ['identifier' => 'temperature', 'value' => 21.5, 'sequence' => $sequence, 'sampled_at' => $receivedAt - 2, 'received_at' => $receivedAt];
    $projection = $database->prepare('INSERT INTO iot_current_data (device_id, ownership_id, model_version, sequence, sampled_at, received_at, fields_json) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $projection->execute([$device['id'], $device['ownership_id'], 1, $sequence, $receivedAt - 2, $receivedAt, json_encode(['temperature' => $field], JSON_THROW_ON_ERROR)]);
    $ledger = $database->prepare('INSERT INTO iot_ingestion (message_id, tenant_id, device_id, ownership_id, sequence, content_hash, status, code, received_at, receipt_proof_nonce, receipt_requested_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ([[$device['ownership_id'], $sequence, 'accepted', 'accepted', $receivedAt],
        [$device['ownership_id'], '2', 'rejected', 'invalid_model_values', $receivedAt + 1],
        [str_repeat('a', 32), '3', 'accepted', 'accepted', $receivedAt + 2]] as $fact) {
        $ledger->execute([hash('sha256', $device['id'] . ':' . $fact[0] . ':' . $fact[1]), $tenantA, $device['id'], $fact[0], $fact[1],
            str_repeat('c', 64), $fact[2], $fact[3], $fact[4], str_repeat('d', 32), $fact[4]]);
    }
    $detail = $request('GET', $detailPath . '/current', $readonly, $tenantA, null, 200)['data'];
    expect($detail['fields'] === [$field] && $detail['sequence'] === $sequence
        && $detail['sampled_at'] === $receivedAt - 2 && $detail['received_at'] === $receivedAt, 'HTTP当前值须保留类型、38位序号与双时间');
    expect($detail['freshness'] === 'stale' && $detail['realtime_sequence'] === null
        && $detail['realtime_sampled_at'] === null && $detail['realtime_received_at'] === null, 'HTTP有当前值但没有实时证据时不能伪装新鲜');
    $realtime = $database->prepare('UPDATE iot_current_data SET realtime_sequence = ?, realtime_sampled_at = ?, realtime_received_at = ? WHERE device_id = ?');
    $realtime->execute(['1', $receivedAt - 30, $receivedAt, $device['id']]);
    $fresh = $request('GET', $detailPath . '/current', $readonly, $tenantA, null, 200)['data'];
    expect($fresh['freshness'] === 'fresh' && $fresh['realtime_sequence'] === '1' && $fresh['realtime_sampled_at'] === $receivedAt - 30
        && $fresh['realtime_received_at'] === $receivedAt && $fresh['sequence'] === $sequence, 'HTTP没有分别表达实时事实和当前值');
    $staleAt = time() - 60;
    $realtime->execute(['1', $staleAt - 30, $staleAt, $device['id']]);
    $detail = $request('GET', $detailPath . '/current', $readonly, $tenantA, null, 200)['data'];
    expect($detail['freshness'] === 'stale' && $detail['received_at'] === $receivedAt
        && $detail['realtime_received_at'] === $staleAt, 'HTTP不能根据近期收到的旧补传判为实时新鲜');
    expect($detail['last_receipt'] === ['sequence' => '2', 'status' => 'rejected', 'code' => 'invalid_model_values', 'received_at' => $receivedAt + 1], 'HTTP应显示本阶段最新平台拒绝结果，并隔离更新的其他归属阶段记录');
    expect($request('GET', $devices . '/' . $second['device']['id'] . '/current', $readonly, $tenantA, null, 200)['data']['last_receipt'] === null, '平台接收结果不能跨设备');
    $request('GET', $other . '/' . $device['id'], $admin, $tenantB, null, 404, 'device_not_found');
    $switchDevice = $request('POST', $devices, $admin, $tenantA, $registration, 201)['data']['device'];
    $switchPath = $devices . '/' . $switchDevice['id'] . '/model-switches';
    $switch = ['switch_id' => bin2hex(random_bytes(16)), 'model_version' => 2, 'version' => (int) $switchDevice['version']];
    foreach ([$readonly, $operator] as $viewer) {
        expect($request('GET', $switchPath, $viewer, $tenantA, null, 200)['items'] === [], '只读切换记录初始应为空');
        $request('POST', $switchPath, $viewer, $tenantA, $switch, 403, 'permission_denied');
    }
    $request('POST', $switchPath, $admin, $tenantA, $switch, 409, 'model_switch_device_unavailable');
    // 只建立离线enabled生命周期装置，实际设备确认由独立TLS链路验收。
    $enable = $database->prepare('UPDATE iot_devices SET lifecycle = ? WHERE id = ?');
    $enable->execute(['enabled', $switchDevice['id']]);
    $request('POST', $models, $admin, $tenantA, ['definition' => $definition], 201);
    $request('POST', $switchPath, $admin, $tenantA, array_replace($switch, ['model_version' => 3]), 409, 'model_not_published');
    $request('POST', $switchPath, $admin, $tenantA, array_replace($switch, ['model_version' => 4]), 404, 'model_not_found');
    $request('POST', $switchPath, $admin, $tenantA, array_replace($switch, ['model_version' => '2']), 422);
    $pending = $request('POST', $switchPath, $admin, $tenantA, $switch, 202)['data'];
    expect($pending['state'] === 'pending' && $pending['target_version'] === 2 && $pending['source_version'] === 1, 'HTTP离线受理冒充完成');
    expect($request('POST', $switchPath, $admin, $tenantA, $switch, 202)['data']['id'] === $switch['switch_id'], 'HTTP重复创建第二条切换');
    $request('POST', $switchPath, $admin, $tenantA, array_replace($switch, ['model_version' => 1]), 409, 'model_switch_identity_conflict');
    $request('POST', $switchPath, $admin, $tenantA, array_replace($switch, ['switch_id' => bin2hex(random_bytes(16)), 'version' => $switch['version'] + 1]), 409, 'model_switch_in_progress');
    expect($request('GET', $switchPath . '?per_page=1', $readonly, $tenantA, null, 200)['total'] === 1, 'HTTP只读分页出现额外事实');
    $request('GET', $switchPath . '?per_page=101', $readonly, $tenantA, null, 422);
    $request('GET', $other . '/' . $switchDevice['id'] . '/model-switches', $admin, $tenantB, null, 404, 'device_not_found');
    $target = iotTransferHttpChecks($request, $tokens, $tenantA, $registration, $database, $environment);
    return ['target' => $target, 'checks' => ['independent-current-command-and-query-permissions', 'empty-stale-realtime-and-stage-isolation',
        'model-switch-version-idempotency-and-no-offline-confirmation', 'transfer-approval-cancel-and-isolated-activation']];
}

/** HTTP三库验证双方独立管理员和冻结事实；离线装置不能替代设备确认及网络排空验收。 */
function iotTransferHttpChecks(Closure $request, array $tokens, string $source, array $registration, PDO $database, array $environment): string
{
    $target = $request('POST', '/admin/tenants', $tokens['platform'], null, ['id' => bin2hex(random_bytes(16)), 'name' => '设备接收组织', 'new_customer' => false, 'owner_login' => 'device-outsider'], 200)['data']['id'];
    $sourcePath = '/customer/tenants/' . $source;
    $targetPath = '/customer/tenants/' . $target;
    $device = $request('POST', $sourcePath . '/devices', $tokens['bob'], $source, $registration, 201)['data']['device'];
    $activate = $database->prepare('UPDATE iot_devices SET lifecycle = ? WHERE id = ?');
    $activate->execute(['enabled', $device['id']]);
    $intent = ['transfer_id' => bin2hex(random_bytes(16)), 'device_id' => $device['id'], 'target_tenant_id' => $target, 'version' => (int) $device['version']];
    foreach (['alice', 'carol'] as $login) {
        $request('POST', $sourcePath . '/transfers', $tokens[$login], $source, $intent, 403, 'permission_denied');
    }
    $request('POST', $sourcePath . '/transfers', $tokens['platform'], $source, $intent, 401, 'unauthenticated');
    $request('POST', '/admin/devices/' . $device['id'] . '/transfers', $tokens['platform'], null, $intent, 404);
    $request('POST', '/iot/tenants/' . $source . '/transfers', $tokens['bob'], $source, $intent, 404);
    $record = $request('POST', $sourcePath . '/transfers', $tokens['bob'], $source, $intent, 202)['data'];
    expect($record['status'] === 'requested' && $record['pending_reasons'] === ['target_approval_required'], 'HTTP转移申请未保留等待审批');
    expect($request('POST', $sourcePath . '/transfers', $tokens['bob'], $source, $intent, 202)['data']['id'] === $record['id'], 'HTTP重试改变原转移身份');
    $sourceRecord = $sourcePath . '/transfers/' . $record['id'];
    $targetRecord = $targetPath . '/transfers/' . $record['id'];
    $reject = ['action' => 'reject', 'decision_id' => bin2hex(random_bytes(16)), 'version' => (int) $record['device_version']];
    $request('POST', $sourceRecord, $tokens['bob'], $source, $reject, 403, 'transfer_target_admin_required');
    $request('POST', $targetRecord, $tokens['bob'], $target, $reject, 403, 'permission_denied');
    $request('GET', $targetRecord, $tokens['platform'], $target, null, 401, 'unauthenticated');
    $request('POST', $targetRecord, $tokens['platform'], $target, $reject, 401, 'unauthenticated');
    expect($request('GET', $targetPath . '/transfers?direction=target&per_page=1', $tokens['outsider'], $target, null, 200)['total'] === 1, '目标无法读取受邀转移');
    $request('GET', $targetRecord, $tokens['outsider'], $source, null, 403, 'tenant_context_mismatch');
    expect($request('POST', $targetRecord, $tokens['outsider'], $target, $reject, 202)['data']['status'] === 'rejected', '目标拒绝未持久保存');
    $request('POST', $targetRecord, $tokens['outsider'], $target, $reject, 202);
    $request('POST', $targetRecord, $tokens['outsider'], $target, ['action' => 'accept', 'decision_id' => $reject['decision_id'], 'copy_name' => '相反决策', 'version' => $reject['version']], 409, 'transfer_decision_conflict');
    $version = static fn (): int => (int) $request('GET', $sourcePath . '/devices/' . $device['id'], $tokens['bob'], $source, null, 200)['data']['version'];
    $intent['transfer_id'] = bin2hex(random_bytes(16));
    $intent['version'] = $version();
    $cancelled = $request('POST', $sourcePath . '/transfers', $tokens['bob'], $source, $intent, 202)['data'];
    $cancel = ['action' => 'cancel', 'decision_id' => bin2hex(random_bytes(16)), 'version' => $cancelled['device_version']];
    $cancelPath = $sourcePath . '/transfers/' . $intent['transfer_id'];
    expect($request('POST', $cancelPath, $tokens['bob'], $source, $cancel, 202)['data']['status'] === 'cancelled', '源方取消没有终止邀请');
    expect($request('POST', $cancelPath, $tokens['bob'], $source, $cancel, 202)['data']['status'] === 'cancelled', '取消重放改变事实');
    $request(
        'POST',
        $targetPath . '/transfers/' . $intent['transfer_id'],
        $tokens['outsider'],
        $target,
        ['action' => 'accept', 'decision_id' => bin2hex(random_bytes(16)), 'copy_name' => '已取消不得接受', 'version' => $version()],
        409,
        'transfer_decision_conflict'
    );
    $intent['transfer_id'] = bin2hex(random_bytes(16));
    $intent['version'] = $version();
    $record = $request('POST', $sourcePath . '/transfers', $tokens['bob'], $source, $intent, 202)['data'];
    $targetRecord = $targetPath . '/transfers/' . $intent['transfer_id'];
    $decision = ['action' => 'accept', 'decision_id' => bin2hex(random_bytes(16)), 'copy_name' => str_repeat('转入产品', 20), 'version' => $record['device_version']];
    // 真实角色流程撤回审批权限，再在安装锁处等待；无权请求不得复制模型或改变邀请。
    $role = $request('POST', '/customer/roles', $tokens['outsider'], $target, ['name' => '转移审批员', 'permissions' =>
        ['customer.transfers.read', 'customer.transfers.accept', 'customer.products.manage']], 200)['data'];
    $request('POST', '/customer/roles/' . $role['id'] . '/status', $tokens['outsider'], $target, ['version' => 1, 'enabled' => true], 200);
    $request('POST', '/customer/members', $tokens['outsider'], $target, ['login' => 'device-operator', 'new_customer' => false, 'name' => '目标审批员',
        'roles' => [['id' => $role['id'], 'version' => 2]]], 200);
    $request('POST', '/customer/roles/' . $role['id'] . '/status', $tokens['outsider'], $target, ['version' => 2, 'enabled' => false], 200);
    $address = '127.0.0.1:' . $environment['APP_PORT'];
    $driver = $database->getAttribute(PDO::ATTR_DRIVER_NAME);
    $denied = identityCompete($database, $driver, $address, [['POST', $targetRecord, $tokens['carol'], $decision, ['X-Tenant-Id' => $target]]]);
    expect($denied === [403], '真实角色撤权后的审批未拒绝');
    expect($request('GET', $targetRecord, $tokens['outsider'], $target, null, 200)['data']['status'] === 'requested', '撤权后的审批改变未决邀请');
    expect($request('GET', $targetPath . '/products', $tokens['outsider'], $target, null, 200)['total'] === 0, '撤权后的审批复制了产品');
    $request('POST', '/customer/roles/' . $role['id'] . '/status', $tokens['outsider'], $target, ['version' => 3, 'enabled' => true], 200);
    $competed = identityCompete($database, $driver, $address, [
        ['POST', $targetRecord, $tokens['outsider'], $decision, ['X-Tenant-Id' => $target]],
        ['POST', $targetRecord, $tokens['outsider'], $decision, ['X-Tenant-Id' => $target]],
    ]);
    expect($competed === [202, 202], '同一审批的并发确认没有收敛原事实');
    $frozen = $request('POST', $targetRecord, $tokens['outsider'], $target, $decision, 202)['data'];
    expect($frozen['status'] === 'frozen' && !$frozen['ready_for_switch'] && in_array('device_confirmation_required', $frozen['pending_reasons'], true), '离线审批伪造设备确认');
    expect($request('POST', $targetRecord, $tokens['outsider'], $target, $decision, 202)['data']['target_product_id'] === $frozen['target_product_id'], '重复接受又复制产品');
    expect($request('GET', $targetPath . '/products', $tokens['outsider'], $target, null, 200)['total'] === 1, '目标复制模型数量不幂等');
    $request(
        'POST',
        $sourcePath . '/devices/' . $device['id'] . '/commands',
        $tokens['bob'],
        $source,
        ['command_id' => bin2hex(random_bytes(16)), 'identifier' => 'switch', 'values' => ['on' => true], 'version' => $version()],
        409,
        'transfer_control_frozen'
    );
    $request('GET', $targetPath . '/devices/' . $device['id'], $tokens['outsider'], $target, null, 404, 'device_not_found');
    $unchanged = $request('GET', $sourcePath . '/devices/' . $device['id'], $tokens['bob'], $source, null, 200)['data'];
    expect($unchanged['ownership_id'] === $device['ownership_id'] && $unchanged['credential_active'] && $unchanged['transfer_id'] === $intent['transfer_id'], '审批提前变更归属或凭据');
    $request('POST', $targetRecord, $tokens['platform'], $target, ['action' => 'retry', 'version' => $version()], 401, 'unauthenticated');
    $request('GET', $sourcePath . '/transfers?per_page=101', $tokens['alice'], $source, null, 422);
    $switch = ['action' => 'switch', 'switch_id' => bin2hex(random_bytes(16)), 'version' => $version()];
    $request('POST', $targetRecord, $tokens['platform'], $target, $switch, 401, 'unauthenticated');
    $request('POST', $sourcePath . '/transfers/' . $intent['transfer_id'], $tokens['bob'], $source, $switch, 403, 'transfer_target_admin_required');
    $request('POST', $targetRecord, $tokens['outsider'], $target, $switch, 409, 'transfer_prerequisites_pending');
    // HTTP装置仅准备设备已排空的持久边界；真实设备报告及Broker隔离分别由MQTT专项验证。
    $node = 'transfer-http-' . $device['id'];
    $runId = bin2hex(random_bytes(16));
    $database->prepare('INSERT INTO iot_broker_observations (node_id, run_id, observed_at, expires_at) VALUES (?, ?, ?, ?)')->execute([$node, $runId, time(), time() + 3600]);
    $database->prepare('UPDATE iot_device_connections SET status = ?, node_id = ?, run_id = ?, observed_at = ? WHERE device_id = ?')->execute(['online', $node, $runId, time(), $device['id']]);
    $status = ['app_version' => 1, 'type' => 'transfer_status', 'device_id' => $device['id'], 'ownership_id' => $device['ownership_id'],
        'transfer_id' => $intent['transfer_id'], 'model_version' => 1, 'structure_hash' => $frozen['structure_hash'], 'sequence' => '1', 'boundary_sequence' => '0',
        'supported' => true, 'pending_count' => 0, 'pending_command_receipts' => 0, 'unresolved_commands' => 0, 'model_pending' => false];
    $database->prepare('UPDATE iot_transfers SET device_status = ?, device_status_at = ?, device_status_sequence = ?, last_attempt_at = ?, next_attempt_at = NULL WHERE id = ?')
        ->execute([json_encode($status, JSON_THROW_ON_ERROR), time(), '1', time(), $intent['transfer_id']]);
    $isolation = $request('POST', $targetRecord, $tokens['outsider'], $target, $switch, 202)['data'];
    expect($isolation['status'] === 'isolating' && $isolation['credential'] === null, 'HTTP没有先进入持久隔离');
    expect($request('POST', $targetRecord, $tokens['outsider'], $target, $switch, 202)['data']['new_ownership_id'] === null, 'HTTP重复请求在隔离未证实前激活');
    $request('GET', $targetPath . '/devices/' . $device['id'], $tokens['outsider'], $target, null, 404);
    $sourceDuring = $request('GET', $sourcePath . '/devices/' . $device['id'], $tokens['bob'], $source, null, 200)['data'];
    expect(!$sourceDuring['credential_active'] && $sourceDuring['authorization']['status'] === 'pending', 'HTTP隔离阶段仍有源凭据或伪称撤权完成');
    $database->prepare('UPDATE iot_authorization_invalidations SET completed_at = ?, node_id = ? WHERE id = ?')->execute([time(), $node, $isolation['isolation_id']]);
    $activated = $request('POST', $targetRecord, $tokens['outsider'], $target, $switch, 202)['data'];
    expect($activated['status'] === 'activating' && $activated['credential'] !== null && $activated['provisioning']['ownership_id'] === $activated['new_ownership_id'], 'HTTP新归属与一次性凭据未共同激活');
    expect($request('POST', $targetRecord, $tokens['outsider'], $target, $switch, 202)['data']['credential'] === null, 'HTTP重试再次显示原密码');
    $queried = $request('GET', $targetRecord, $tokens['outsider'], $target, null, 200)['data'];
    expect(!isset($queried['credential']) && $queried['pending_reasons'] === ['new_device_confirmation_required'], 'HTTP查询泄露凭据或伪造设备完成');
    $request('POST', $targetRecord, $tokens['outsider'], $target, ['action' => 'switch', 'switch_id' => bin2hex(random_bytes(16)), 'version' => $switch['version']], 409, 'transfer_switch_conflict');
    expect($request('POST', $sourcePath . '/transfers', $tokens['bob'], $source, $intent, 202)['data']['status'] === 'activating', '切换后源申请原ID不能读取持久结果');
    $request('GET', $sourcePath . '/devices/' . $device['id'], $tokens['bob'], $source, null, 404);
    $targetDevice = $request('GET', $targetPath . '/devices/' . $device['id'], $tokens['outsider'], $target, null, 200)['data'];
    expect($targetDevice['ownership_id'] === $activated['new_ownership_id'] && $targetDevice['transfer_frozen'] == 1, '目标缺失新归属或提前解冻');
    foreach ([[$sourcePath, $source, 'bob'], [$targetPath, $target, 'outsider']] as [$historyBase, $historyTenant, $login]) {
        foreach (['history', 'commands'] as $historyKind) {
            expect($request('GET', $historyBase . '/devices/' . $device['id'] . '/' . $historyKind, $tokens[$login], $historyTenant, null, 200)['total'] === 0, '空历史无法按独立归属读取');
        }
    }
    expect($request('GET', $targetPath . '/transfers?direction=target&status=activating', $tokens['outsider'], $target, null, 200)['total'] === 1, '新阶段筛选未同步HTTP入口');
    return $target;
}

/** HTTP投影和MQTT分别使用新设备数据，共用真实角色及成员授权流程。 */
function iotDeviceBusinessPermissions(Closure $request, string $admin, string $tenant): void
{
    foreach (['device-reader' => ['customer.devices.read', 'customer.telemetry.read', 'customer.commands.read', 'customer.transfers.read'],
        'device-operator' => ['customer.devices.read', 'customer.commands.query']] as $login => $permissions) {
        $role = $request('POST', '/customer/roles', $admin, $tenant, ['name' => $login . '-business', 'permissions' => $permissions], 200)['data'];
        $request('POST', '/customer/roles/' . $role['id'] . '/status', $admin, $tenant, ['version' => 1, 'enabled' => true], 200);
        $member = $request('GET', '/customer/members?search=' . $login, $admin, $tenant, null, 200)['data']['items'][0];
        $request('PUT', '/customer/members/roles', $admin, $tenant, ['members' => [['id' => $member['id'], 'version' => (int) $member['version']]],
            'roles' => [['id' => $role['id'], 'version' => 2]]], 200);
    }
}

/** 标准客户端同时观察真实HTTP；本函数只拥有自己启动的TLS Broker进程。 */
function iotDeviceMqttChecks(Closure $request, array $evidence, array $command, array $environment, string $base, PDO $database): array
{
    $root = dirname(__DIR__);
    $certificateConfiguration = $base . '/certificate.cnf';
    file_put_contents($certificateConfiguration, "[req]\ndistinguished_name=dn\nx509_extensions=server\n[dn]\n[server]\nsubjectAltName=IP:127.0.0.1\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\nextendedKeyUsage=serverAuth\n");
    $options = ['config' => $certificateConfiguration, 'private_key_bits' => 2048, 'digest_alg' => 'sha256', 'x509_extensions' => 'server'];
    $key = openssl_pkey_new($options);
    $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key, $options);
    $certificate = openssl_csr_sign($csr, null, $key, 1, $options);
    openssl_x509_export($certificate, $certificatePem);
    openssl_pkey_export($key, $privatePem);
    file_put_contents($base . '/certificate.pem', $certificatePem);
    file_put_contents($base . '/private.pem', $privatePem);
    chmod($base . '/private.pem', 0600);
    $listener = stream_socket_server('tcp://127.0.0.1:0', $number, $message);
    $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
    fclose($listener);
    $environment['IOT_MQTT_COMMAND'] ??= json_encode($command, JSON_THROW_ON_ERROR);
    $environment['IOT_MQTT_CERTIFICATE'] = 'certificate.pem';
    $environment['IOT_MQTT_PRIVATE_KEY'] = 'private.pem';
    $environment['IOT_MQTT_PORT'] = (string) $port;
    $environment['IOT_MQTT_NODE_ID'] = 't14-test';
    $ingestion = in_array('--ingestion', $GLOBALS['argv'], true);
    if ($ingestion) {
        expect(array_intersect($GLOBALS['argv'], ['--business', '--history', '--aggregate']) === [], '真实接收与查询投影使用各自独立数据');
        iotDeviceBusinessPermissions($request, $evidence['admin_token'], $evidence['tenant']);
        if (in_array('--transfers-only', $GLOBALS['argv'], true)) {
            $evidence['transfer_target'] = $request(
                'POST',
                '/admin/tenants',
                $evidence['platform_token'],
                null,
                ['id' => bin2hex(random_bytes(16)), 'name' => 'TLS转移目标组织', 'new_customer' => false, 'owner_login' => 'device-outsider'],
                200
            )['data']['id'];
            $evidence['transfer_target_token'] = $evidence['tokens']['outsider'];
        }
    }
    $ha = in_array('--ha', $GLOBALS['argv'], true);
    $cluster = in_array('--cluster', $GLOBALS['argv'], true);
    $resources = in_array('--broker-resources', $GLOBALS['argv'], true);
    if ($cluster || in_array('--transfer-faults', $GLOBALS['argv'], true)) {
        $environment['IOT_MQTT_CLUSTERED'] = 'true';
    }
    $capacity = in_array('--capacity', $GLOBALS['argv'], true);
    $lifecycle = in_array('--lifecycle', $GLOBALS['argv'], true) || in_array('--lifecycle-mqtt', $GLOBALS['argv'], true);
    expect(!$cluster || (!$ingestion && !$capacity && !$lifecycle && !$ha), '集群入口独立拥有跨节点会话和设备状态');
    expect(!$capacity || (!$ingestion && !$lifecycle), '容量与业务接收、生命周期场景分别拥有自己的设备状态');
    expect(!$ingestion || !$lifecycle, '生命周期和业务回执分别拥有自己的设备装置');
    expect(!$ha || (!$ingestion && !$capacity && !$lifecycle), 'HA入口独立拥有故障设备状态');
    $servicePassword = $ingestion || $capacity || $lifecycle || $ha || $cluster || $resources ? bin2hex(random_bytes(32)) : '';
    if ($ingestion || $capacity || $lifecycle || $ha || $cluster || $resources) {
        $environment['IOT_INGESTION_CREDENTIAL_ID'] = bin2hex(random_bytes(16));
        $environment['IOT_INGESTION_SECRET_HASH'] = hash('sha256', $servicePassword);
    }
    if ($capacity) {
        $environment['IOT_MQTT_MAXIMUM_CONNECTIONS'] = '4';
        $environment['IOT_MQTT_MAXIMUM_DEVICE_CONNECTIONS'] = '1';
        $environment['IOT_MQTT_MAXIMUM_SERVICE_CONNECTIONS'] = '1';
        $environment['IOT_MQTT_MAXIMUM_SESSIONS'] = '2';
        $environment['IOT_MQTT_MAXIMUM_DEVICE_MESSAGES'] = '1';
    }
    $process = null;
    try {
        $installed = (new Type\Testing\Process([...$command, 'iot:mqtt-install'], $root, $environment))->wait(15);
        expect($installed->successful(), '应用MQTT存储安装失败：' . $installed->stderr);
        if (in_array('--operations-quarantine-only', $GLOBALS['argv'], true)) {
            expect(in_array('--operations', $GLOBALS['argv'], true), '隔离专项需要运行概览入口');
            return ['scope' => 'operations-quarantine-only', 'operations_quarantine' => iotOperationsQuarantine($command, $environment, $evidence, $database)];
        }
        $start = static function () use ($command, $environment, $port, $root, $database): Type\Testing\Process {
            // 调用者已确认旧进程退出；强杀没有停止观察，按真实有效期等待同名节点可重新登记。
            $until = microtime(true) + 20;
            $observation = $database->prepare('SELECT expires_at FROM broker_resource_runs WHERE node_id = ?');
            do {
                $observation->execute([$environment['IOT_MQTT_NODE_ID']]);
                $expiresAt = $observation->fetchColumn();
                if ($expiresAt === false || (int) $expiresAt <= time()) {
                    break;
                }
                expect(microtime(true) < $until, '旧节点观察未在既定有效期内结束');
                usleep(20000);
            } while (true);
            $process = new Type\Testing\Process([...$command, 'iot:mqtt'], $root, $environment);
            try {
                $until = microtime(true) + 10;
                do {
                    expect($process->running(), '应用MQTT提前退出：' . $process->stderr());
                    $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $number, $message, 0.1);
                    if (is_resource($socket)) {
                        fclose($socket);
                        return $process;
                    }
                    usleep(10000);
                } while (microtime(true) < $until);
                throw new RuntimeException('应用MQTT未就绪');
            } catch (Throwable $failure) {
                $process->stop(15);
                throw $failure;
            }
        };
        $process = $start();
        $cases = [];
        $clientEnvironment = $environment + ['TYPE_DEVICE_FIXTURE' => json_encode($evidence, JSON_THROW_ON_ERROR),
            'TYPE_DEVICE_HTTP' => 'http://127.0.0.1:' . $environment['APP_PORT'], 'TYPE_DEVICE_CA' => $base . '/certificate.pem'];
        if ($capacity || $resources) {
            $clientEnvironment['TYPE_INGESTION_PASSWORD'] = $servicePassword;
        }
        $run = static function (string $mode, Type\Testing\Process $broker) use ($clientEnvironment, $root, &$cases): void {
            $client = new Type\Testing\Process(
                ['node', $root . '/tests/iot-device-client.mjs', $mode],
                $root,
                $clientEnvironment + ['TYPE_DEVICE_BROKER_PID' => (string) $broker->pid()]
            );
            try {
                $result = $client->wait($mode === 'broker-resources' && getenv('TYPE_BROKER_RESOURCE_DIST') ? 480 : 80);
                if (!$result->successful()) {
                    $broker->stop(15);
                }
                expect($result->successful(), '设备标准客户端验证失败（mode=' . $mode . ', exit=' . $result->exitCode
                    . ', timeout=' . (int) $result->timedOut . ', outputExceeded=' . (int) $result->outputExceeded
                    . ', signal=' . ($result->signal ?? 'none') . '）：'
                    . $result->stderr . $result->stdout . $broker->stderr() . $broker->stdout());
                $cases[$mode][] = json_decode(trim($result->stdout), true, 8, JSON_THROW_ON_ERROR);
            } finally {
                $client->stop();
            }
        };
        if ($resources) {
            $run('broker-resources', $process);
            expect($process->stop(30)->successful(), '资源查询验收节点没有正常退出');
            return ['client' => 'MQTT.js 5.15.0', 'tls' => true, 'cases' => $cases, 'broker_stopped' => true, 'secrets_redacted' => true];
        }
        if ($cluster) {
            require_once __DIR__ . '/iot-cluster-mqtt.php';
            return iotClusterMqttChecks($request, $evidence, $command, $clientEnvironment, $base, $database, $process, $servicePassword);
        }
        if ($ha) {
            return iotHaMqttChecks($evidence, $command, $environment, $clientEnvironment, $base, $process, $servicePassword, $start);
        }
        if ($capacity) {
            $run('capacity', $process);
            $operations = [];
            if (in_array('--operations', $GLOBALS['argv'], true)) {
                $operations['saturated'] = iotOperationsWait(
                    static fn (): array => iotOperationsPlatform($environment, $evidence),
                    static fn (array $view): bool => count($view['nodes']) > 0 && $view['nodes'][0]['metrics']['connectionQuotaRefusals'] >= 2
                        && $view['nodes'][0]['metrics']['commitQuotaRefusals'] >= 1
                        && $view['store']['state'] === 'available' && $view['store']['metrics']['devicePendingMessages'] === 1,
                    '概览没有反映真实连接拒绝和慢消费者积压'
                );
            }
            $usage = identityCommand([...$command, 'iot:mqtt-statistics'], $environment);
            expect(
                $usage['sessions'] === 2 && $usage['deviceSessions'] === 1 && $usage['applicationSessions'] === 1
                && $usage['maximumSessions'] === 2 && $usage['maximumDeviceMessages'] === 1 && $usage['devicePendingMessages'] === 1,
                '应用容量分类、持久会话或设备积压额度未传入持久worker'
            );
            expect($usage['maximumDeviceBytes'] === 16777216 && $usage['maximumApplicationMessages'] === 1000000
                && $usage['maximumApplicationBytes'] === 2147483648 && $usage['maximumSharedMessages'] === 1000000
                && $usage['maximumSharedBytes'] === 2147483648 && $usage['maximumPendingMessages'] === 2000000
                && $usage['maximumPendingBytes'] === 4294967296, '应用未保持协议侧完整队列预算');
            $stopped = $process->stop(15);
            expect($stopped->successful(), '容量Broker退出失败：' . $stopped->stderr);
            $statistics = json_decode(trim($stopped->stdout), true, 16, JSON_THROW_ON_ERROR);
            expect($statistics['maximumConnections'] === 4 && $statistics['maximumDeviceConnections'] === 1
                && $statistics['maximumServiceConnections'] === 1 && $statistics['connectionQuotaRefusals'] >= 2
                && $statistics['rejectedCommits'] >= 1, '应用没有输出真实分类拒绝和持久容量指标');
            foreach (['connections', 'eventRegistrations', 'eventTimers', 'readyEvents', 'pendingReads', 'bufferedBytes', 'pendingCommits', 'closingSessions'] as $counter) {
                expect($statistics[$counter] === 0, '容量Broker退出后未释放资源：' . $counter);
            }
            if (in_array('--operations', $GLOBALS['argv'], true)) {
                $operations['stopped'] = iotOperationsPlatform($environment, $evidence);
                expect($operations['stopped']['nodes'][0]['state'] === 'stopped', '正常停止被显示为仍在上报');
            }
            $process = $start();
            $run('capacity-resume', $process);
            $recovered = identityCommand([...$command, 'iot:mqtt-statistics'], $environment);
            expect($recovered['sessions'] === 2 && $recovered['devicePendingMessages'] === 0, '满额重启丢失会话或未排空原始可靠消息');
            if (in_array('--operations', $GLOBALS['argv'], true)) {
                $operations['recovered'] = iotOperationsWait(
                    static fn (): array => iotOperationsPlatform($environment, $evidence),
                    static fn (array $view): bool => $view['nodes'][0]['state'] === 'reporting' && $view['nodes'][0]['run_id'] !== $operations['saturated']['nodes'][0]['run_id']
                        && $view['store']['state'] === 'available' && $view['store']['metrics']['devicePendingMessages'] === 0,
                    '重启排空后概览未切换运行身份和积压'
                );
            }
            return ['client' => 'MQTT.js 5.15.0', 'tls' => true, 'cases' => $cases, 'usage' => $usage, 'recovered' => $recovered, 'statistics' => $statistics, 'operations' => $operations];
        }
        if ($lifecycle) {
            require_once __DIR__ . '/iot-lifecycle-mqtt.php';
            return iotLifecycleMqttChecks($request, $evidence, $command, $clientEnvironment, $base, $database, $process, $servicePassword, $start);
        }
        if ($ingestion) {
            require_once __DIR__ . '/iot-ingestion-mqtt.php';
            return iotIngestionMqttChecks($evidence, $command, $environment, $clientEnvironment, $base, $database, $process, $servicePassword, $start);
        }
        $run('normal', $process);
        // 遗嘱恢复不持久保存密码；真实接入后观察同步发布事实及当前撤权对到期遗嘱的影响。
        $willCount = static function (string $outcome) use ($database, $evidence): int {
            $query = $database->prepare('SELECT COUNT(*) FROM type_mqtt_will_audit WHERE client_id = ? AND outcome = ?');
            $query->execute([$evidence['device']['id'], $outcome]);
            return (int) $query->fetchColumn();
        };
        $awaitWill = static function (string $outcome, int $previous) use ($willCount, $process): void {
            $until = microtime(true) + 16;
            do {
                expect($process->running(), '遗嘱处理期间Broker退出：' . $process->stderr());
                if ($willCount($outcome) === $previous + 1) {
                    return;
                }
                usleep(50000);
            } while (microtime(true) < $until);
            throw new RuntimeException('设备遗嘱未得到预期终结结果：' . $outcome);
        };
        $publishedBefore = $willCount('published');
        $run('will', $process);
        $awaitWill('published', $publishedBefore);
        $retained = $database->prepare("SELECT encode(payload, 'base64') FROM type_mqtt_retained WHERE topic = ?");
        $retained->execute([$evidence['registration']['credential']['topics']['publish']]);
        expect($retained->fetchColumn() === base64_encode("device-will\0binary"), '设备遗嘱未通过真实授权与保留发布路径');
        foreach (['revoked', 'disabled', 'ownership'] as $revocation) {
            $deniedBefore = $willCount('denied');
            $run('will-delayed', $process);
            $pending = $database->query('SELECT message::text FROM type_mqtt_wills')->fetchAll(PDO::FETCH_COLUMN);
            $pendingJson = json_encode($pending, JSON_THROW_ON_ERROR);
            expect($pending !== [] && !str_contains($pendingJson, $evidence['registration']['credential']['password'])
                && !str_contains($pendingJson, hash('sha256', $evidence['registration']['credential']['password'])), '遗嘱持久恢复保存了设备秘密');
            if ($revocation === 'disabled') {
                $database->exec("UPDATE iot_devices SET lifecycle = 'disabled' WHERE id = '" . $evidence['device']['id'] . "'");
            } elseif ($revocation === 'revoked') {
                $database->exec("UPDATE iot_device_credentials SET status = 'revoked' WHERE device_id = '" . $evidence['device']['id'] . "'");
            } else {
                $database->exec("UPDATE iot_device_credentials SET ownership_id = '" . str_repeat('a', 32) . "' WHERE device_id = '" . $evidence['device']['id'] . "'");
            }
            $awaitWill('denied', $deniedBefore);
            $retained->execute([$evidence['registration']['credential']['topics']['publish']]);
            expect($retained->fetchColumn() === base64_encode("device-will\0binary"), '撤权后遗嘱仍替换了合法保留消息');
            $database->exec("UPDATE iot_devices SET lifecycle = 'enabled' WHERE id = '" . $evidence['device']['id'] . "'");
            $database->exec("UPDATE iot_device_credentials SET status = 'active', ownership_id = '" . $evidence['device']['ownership_id']
                . "' WHERE device_id = '" . $evidence['device']['id'] . "'");
            $cases['will-authorization'][] = $revocation . '-denied-without-retained-replacement';
        }
        foreach (['disabled', 'retired'] as $lifecycle) {
            $statement = $database->prepare('UPDATE iot_devices SET lifecycle = ? WHERE id = ?');
            $statement->execute([$lifecycle, $evidence['device']['id']]);
            $run('denied', $process);
        }
        $statement->execute(['enabled', $evidence['device']['id']]);
        $database->exec("UPDATE iot_device_credentials SET status = 'revoked' WHERE device_id = '" . $evidence['device']['id'] . "'");
        $run('denied', $process);
        $database->exec("UPDATE iot_device_credentials SET status = 'active' WHERE device_id = '" . $evidence['device']['id'] . "'");
        $database->exec("UPDATE iot_device_credentials SET ownership_id = '" . str_repeat('a', 32) . "' WHERE device_id = '" . $evidence['device']['id'] . "'");
        $run('denied', $process);
        $database->exec("UPDATE iot_device_credentials SET ownership_id = '" . $evidence['device']['ownership_id'] . "' WHERE device_id = '" . $evidence['device']['id'] . "'");
        // 故障装置放入以前获准、现在跨设备的持久订阅，恢复必须重新授权并删除它。
        $subscription = 't:' . $evidence['second']['credential']['topics']['subscribe'];
        $statement = $database->prepare('UPDATE type_mqtt_sessions SET subscriptions = ?::jsonb WHERE client_id = ?');
        $statement->execute([json_encode([$subscription => 1], JSON_THROW_ON_ERROR), $evidence['device']['id']]);
        $run('resume', $process);
        $stored = $database->query("SELECT subscriptions::text FROM type_mqtt_sessions WHERE client_id = '" . $evidence['device']['id'] . "'")->fetchColumn();
        expect(!str_contains($stored, $subscription), '恢复拒绝的旧订阅仍被保存');
        $run('crash', $process);
        expect(!$process->running(), '故障Broker未退出');
        $process = $start();
        $run('restart', $process);
        $faultClient = new Type\Testing\Process(['node', $root . '/tests/iot-device-client.mjs', 'observation-failure'], $root, $clientEnvironment);
        try {
            $until = microtime(true) + 15;
            do {
                expect($faultClient->running(), '连接观察故障装置提前退出：' . $faultClient->stderr());
                if (str_contains($faultClient->stdout(), "ready\n")) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $until);
            expect(str_contains($faultClient->stdout(), "ready\n"), '连接观察故障装置没有在线');
            $database->beginTransaction();
            // 只阻塞本测试节点的心跳写入，普通HTTP读取继续看到MVCC快照。
            $database->exec("UPDATE iot_broker_observations SET expires_at = expires_at WHERE node_id = 't14-test'");
            $fault = $faultClient->wait(30);
            expect($fault->successful(), '观察故障验证失败：' . $fault->stderr);
            expect(!$process->running(), '观察工作失败后Broker未停止');
            $cases['observation-failure'] = json_decode(trim(substr($fault->stdout, strlen("ready\n"))), true, 8, JSON_THROW_ON_ERROR);
        } finally {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            $faultClient->stop();
        }
        expect(
            (int) $database->query("SELECT COUNT(*) FROM pg_stat_activity WHERE datname = current_database() AND application_name LIKE 'type_mqtt_%'")->fetchColumn() === 0,
            '接入工作进程退出后仍留有数据库后端'
        );
        $process = $start();
        $run('restart', $process);
        $stopped = $process->stop(15);
        expect($stopped->successful(), '应用MQTT正常退出失败：' . $stopped->stderr);
        $audits = json_encode($database->query("SELECT * FROM customer_audit WHERE action LIKE 'device.%'")->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR);
        foreach ([$evidence['registration']['credential']['password'], $evidence['second']['credential']['password']] as $secret) {
            expect(!str_contains($audits . $process->stdout() . $process->stderr(), $secret)
                && !str_contains($audits, hash('sha256', $secret)), '接入审计或日志泄漏凭据');
        }
        expect(str_contains($audits, 'unknown_device') && str_contains($audits, 'device.connection_denied')
            && str_contains($audits, 'device.topic_denied') && str_contains($audits, 'device.connected') && str_contains($audits, 'device.disconnected'), '真实拒绝或连接审计缺失');
        return ['client' => 'MQTT.js 5.15.0', 'tls' => true, 'cases' => $cases, 'broker_stopped' => true, 'secrets_redacted' => true];
    } finally {
        $process?->stop(15);
        unlink($base . '/private.pem');
    }
}
