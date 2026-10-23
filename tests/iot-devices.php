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
    $registration = ['name' => '一号温度计'];
    $request('GET', $devices, '', $tenantA, null, 401);
    $request('GET', $devices, $admin, null, null, 403, 'tenant_context_mismatch');
    $request('GET', $devices, $admin, $tenantB, null, 403, 'tenant_context_mismatch');
    $request('GET', $devices, $tokens['outsider'], $tenantA, null, 403, 'permission_denied');
    $request('GET', $devices, $platform, $tenantA, null, 401, 'unauthenticated');
    // 旧人工登记入口已退出，设备必须消费控制端签发的资格。
    foreach ([$admin, $readonly, $operator, $tokens['outsider']] as $actor) {
        $request('POST', $devices, $actor, $tenantA, $registration, 405);
    }
    $grant = $request(
        'POST',
        '/admin/device-authorizations',
        $platform,
        null,
        ['id' => bin2hex(random_bytes(16)), 'tenant_id' => $tenantA, 'name' => '设备管理验收', 'capacity' => 2, 'register_expires_at' => time() + 3600],
        201
    )['data'];
    $register = static fn (string $name): array => $request(
        'POST',
        '/public/devices/register',
        '',
        null,
        ['id' => bin2hex(random_bytes(16)), 'tenant_id' => $tenantA, 'device_id' => bin2hex(random_bytes(16)), 'name' => $name, 'code' => $grant['code']],
        201
    )['data'];
    $created = $register('一号温度计');
    $device = $created['device'];
    $credential = $created['credential'];
    expect($device['tenant_id'] === $tenantA && $device['product_id'] === null && !array_key_exists('model_version', $device)
        && $device['lifecycle'] === 'inactive' && $device['version'] === 1, '注册不依赖分类或模型，初始未激活');
    expect(preg_match('/^[a-f0-9]{32}$/D', $device['id']) === 1 && preg_match('/^[a-f0-9]{32}$/D', $device['ownership_id']) === 1
        && preg_match('/^[a-f0-9]{64}$/D', $credential['password']) === 1, '设备、关系身份及随机凭据格式错误');
    expect($credential['client_id'] === $device['id'] && $credential['username'] === $device['id'] . ':' . $credential['id']
        && $credential['protocol_version'] === 5 && $credential['tls_required'] === true && $credential['keep_alive'] === 30
        && $credential['session_expiry'] === 86400, '设备连接参数不符合已确认契约');
    $prefix = 'iot/' . $tenantA . '/devices/' . $device['id'] . '/epochs/' . $device['ownership_id'] . '/raw';
    expect($credential['topics'] === ['publish' => $prefix . '/up', 'subscribe' => $prefix . '/down'], '设备Topic须绑定租户、设备和当前使用关系');
    $stored = $database->prepare('SELECT * FROM iot_device_credentials WHERE device_id = ?');
    $stored->execute([$device['id']]);
    $records = $stored->fetchAll(PDO::FETCH_ASSOC);
    expect(count($records) === 1 && $records[0]['secret_hash'] === hash('sha256', $credential['password'])
        && !str_contains(json_encode($records, JSON_THROW_ON_ERROR), $credential['password']), '存储应只保留单向验证值');
    $detailPath = $devices . '/' . $device['id'];
    foreach ([$path . '/alarm-rules', $path . '/alarms', $path . '/notifications', $path . '/exports', $path . '/transfers',
        $detailPath . '/model-switches', $detailPath . '/commands', $detailPath . '/history', $detailPath . '/current', $detailPath . '/exports'] as $retiredPath) {
        foreach (['GET', 'POST'] as $method) {
            $request($method, $retiredPath, $admin, $tenantA, $method === 'POST' ? [] : null, 404);
        }
    }
    $detail = $request('GET', $detailPath, $readonly, $tenantA, null, 200)['data'];
    expect(!array_key_exists('model', $detail) && $detail['topics'] === $credential['topics'], '详情仅提供当前关系的原始通信主题');
    expect(!array_key_exists('current', $detail), '设备资料不隐式提供遥测权限');
    $request('GET', $other . '/' . $device['id'], $admin, $tenantB, null, 404, 'device_not_found');
    $request('GET', $detailPath, $tokens['outsider'], $tenantA, null, 403, 'permission_denied');
    $request('GET', $detailPath . '/credential', $admin, $tenantA, null, 404);
    $second = $register('二号温度计');
    expect($second['device']['id'] !== $device['id'] && $second['device']['ownership_id'] !== $device['ownership_id']
        && $second['credential']['password'] !== $credential['password'], '设备身份、阶段和凭据必须独立');
    $pageOne = $request('GET', $devices . '?per_page=1', $operator, $tenantA, null, 200);
    $pageTwo = $request('GET', $devices . '?per_page=1&page=2', $operator, $tenantA, null, 200);
    expect($pageOne['total'] === 2 && count($pageOne['items']) === 1 && count($pageTwo['items']) === 1
        && $pageOne['items'][0]['id'] !== $pageTwo['items'][0]['id'], '设备分页须有界且排序稳定');
    expect($request('GET', $devices . '?name=' . rawurlencode('一号'), $readonly, $tenantA, null, 200)['total'] === 1, '设备名称筛选须查真实资料');
    expect($request('GET', $devices . '?product_id=' . $product['id'], $readonly, $tenantA, null, 200)['total'] === 0, '未分类设备不能被分类筛选命中');
    expect($request('GET', $devices . '?lifecycle=inactive', $readonly, $tenantA, null, 200)['total'] === 2, '未激活与连接状态须分开');
    expect($request('GET', $devices . '?lifecycle=disabled', $readonly, $tenantA, null, 200)['total'] === 0, '空筛选结果不能混入其他生命周期');
    expect($request('GET', $other, $admin, $tenantB, null, 200)['total'] === 0, '设备列表不能跨租户');
    $request('GET', $devices . '?per_page=101', $readonly, $tenantA, null, 422);
    $request('GET', $devices . '?lifecycle=online', $readonly, $tenantA, null, 422);
    $auditQuery = $database->prepare("SELECT * FROM customer_audit WHERE subject_id = ? AND result = 'success'");
    $auditQuery->execute([$device['id']]);
    $audits = $auditQuery->fetchAll(PDO::FETCH_ASSOC);
    expect(count($audits) >= 1, '设备与凭据生成应在同事务保留审计');
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
        $request('GET', $detailPath . '/' . $business, $platform, $tenantA, null, 404);
        $request('POST', $detailPath . '/' . $business, $platform, $tenantA, [], 404);
        $request('GET', $assetPath . '/' . $business, $platform, null, null, 404);
    }
    $request('GET', '/iot/tenants/' . $tenantA . '/devices', $admin, $tenantA, null, 404);
    $request('GET', $devices . '?cursor=foreign', $admin, $tenantA, null, 422, 'device_query_invalid');
    $request('GET', $devices . '?tenant_id=' . $tenantB, $admin, $tenantA, null, 422, 'device_query_invalid');
    expect($request('GET', '/admin/devices?tenant_id=' . $tenantA, $platform, null, null, 200)['total'] === 2, '平台租户筛选错误');
    expect($request('GET', '/admin/devices?tenant_id=' . $tenantB, $platform, null, null, 200)['total'] === 0, '空归属筛选混入资产');
    $request('POST', '/admin/devices', $platform, null, $registration, 405);
    foreach (['tenant_id' => $tenantB, 'lifecycle' => 'enabled', 'model_version' => 2] as $field => $value) {
        $request('PATCH', $assetPath, $platform, null, ['name' => '绕过状态机', 'version' => 1, $field => $value], 422, 'unexpected_field');
    }
    $longName = str_repeat('温', 100);
    $request('PATCH', $assetPath, $platform, null, ['name' => $longName . '度', 'version' => 1], 422);
    $renamed = $request('PATCH', $assetPath, $platform, null, ['name' => $longName, 'version' => 1], 200)['data']['device'];
    expect($renamed['name'] === $longName, '资料名称应按Unicode字符而非UTF-8字节计数');
    expect($renamed['version'] === 2 && $renamed['ownership_id'] === $device['ownership_id'] && $renamed['product_id'] === null, '资产编辑只能修改资料并推进准确版本');
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
    // 分类只接受同客户资料，不改变 Topic；缺省保持，显式 null 清除。
    $foreign = $request('POST', '/customer/tenants/' . $tenantB . '/products', $admin, $tenantB, ['name' => '其他客户分类'], 201)['data'];
    $version = $renamed['version'];
    $request('PATCH', $detailPath, $admin, $tenantA, ['version' => $version, 'name' => $renamed['name'], 'product_id' => $foreign['id']], 404, 'product_not_found');
    $classified = $request('PATCH', $detailPath, $admin, $tenantA, ['version' => $version, 'name' => $renamed['name'], 'product_id' => $product['id']], 200)['data']['device'];
    expect($classified['product_id'] === $product['id'] && $classified['topics'] === $credential['topics'], '分类不能改变通信身份');
    expect($request('GET', $devices . '?product_id=' . $product['id'], $readonly, $tenantA, null, 200)['total'] === 1, '分类筛选没有实际用途');
    $request('DELETE', $path . '/products/' . $product['id'], $admin, $tenantA, ['version' => 1], 409, 'product_referenced');
    $kept = $request('PATCH', $detailPath, $admin, $tenantA, ['version' => $classified['version'], 'name' => '分类保持'], 200)['data']['device'];
    expect($kept['product_id'] === $product['id'], '缺省分类被清除');
    $cleared = $request('PATCH', $detailPath, $admin, $tenantA, ['version' => $kept['version'], 'name' => '分类保持', 'product_id' => null], 200)['data']['device'];
    expect($cleared['product_id'] === null, '显式 null 未清除分类');
    $request('DELETE', $path . '/products/' . $product['id'], $admin, $tenantA, ['version' => 1], 200);
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
    $ha = in_array('--ha', $GLOBALS['argv'], true);
    $cluster = in_array('--cluster', $GLOBALS['argv'], true);
    $resources = in_array('--broker-resources', $GLOBALS['argv'], true);
    if ($cluster || in_array('--reassignments-mqtt', $GLOBALS['argv'], true)) {
        $environment['IOT_MQTT_CLUSTERED'] = 'true';
    }
    $capacity = in_array('--capacity', $GLOBALS['argv'], true);
    $lifecycle = in_array('--lifecycle', $GLOBALS['argv'], true) || in_array('--lifecycle-mqtt', $GLOBALS['argv'], true);
    expect(count(array_filter([$cluster, $capacity, $lifecycle, $ha])) <= 1, '容量、集群、生命周期与 HA 分别拥有独立设备状态');
    if ($capacity || $ha) {
        require_once __DIR__ . '/iot-communication-fixture.php';
        $evidence['application'] = iotApplicationFixture($request, $evidence['admin_token'], $evidence['tenant'], [$evidence['registration'], $evidence['second']], '协议可靠性应用');
        $evidence['overflow_application'] = iotApplicationFixture($request, $evidence['admin_token'], $evidence['tenant'], [], '容量超额应用');
    }
    if ($capacity) {
        $environment['IOT_MQTT_MAXIMUM_CONNECTIONS'] = '4';
        $environment['IOT_MQTT_MAXIMUM_DEVICE_CONNECTIONS'] = '1';
        $environment['IOT_MQTT_MAXIMUM_SERVICE_CONNECTIONS'] = '1';
        $environment['IOT_MQTT_MAXIMUM_SESSIONS'] = '2';
        $environment['IOT_MQTT_MAXIMUM_DEVICE_MESSAGES'] = '1';
    }
    $process = null;
    $clientFixtureFile = $base . '/device-client-fixture.json';
    try {
        $installed = (new Type\Testing\Process([...$command, 'iot:mqtt-install'], $root, $environment))->wait(15);
        expect($installed->successful(), '应用MQTT存储安装失败：' . $installed->stderr);
        if (in_array('--operations-quarantine-only', $GLOBALS['argv'], true)) {
            expect(in_array('--operations', $GLOBALS['argv'], true), '隔离专项需要运行概览入口');
            return ['scope' => 'operations-quarantine-only', 'operations_quarantine' => iotOperationsQuarantine($command, $environment, $evidence, $database)];
        }
        $start = static function () use ($command, $environment, $port, $root, $database, $ha): Type\Testing\Process {
            // 调用者已确认旧进程退出；强杀没有停止观察，按真实有效期等待同名节点可重新登记。
            // HA故障会关闭旧主连接；观察须重新经受测写入口建立，不能复用测试控制器的旧PDO。
            $observationDatabase = $ha ? $GLOBALS['postgresHa']->connection($environment['DB_DATABASE']) : $database;
            $until = microtime(true) + 20;
            $observation = $observationDatabase->prepare('SELECT expires_at FROM broker_resource_runs WHERE node_id = ?');
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
        // Windows PHP 的显式环境块有长度限制；凭据夹具由本轮私有文件传递，最后统一销毁。
        $clientFixture = json_encode($evidence, JSON_THROW_ON_ERROR);
        expect(file_put_contents($clientFixtureFile, $clientFixture) === strlen($clientFixture)
            && chmod($clientFixtureFile, 0600), '无法保存设备客户端的私有测试夹具');
        $clientEnvironment = $environment + ['TYPE_DEVICE_FIXTURE_FILE' => $clientFixtureFile,
            'TYPE_DEVICE_HTTP' => 'http://127.0.0.1:' . $environment['APP_PORT'], 'TYPE_DEVICE_CA' => $base . '/certificate.pem'];
        if (in_array('--reassignments-mqtt', $GLOBALS['argv'], true)) {
            require_once __DIR__ . '/iot-reassignments.php';
            return iotReassignmentMqttChecks($command, $clientEnvironment, $base, $database, $process);
        }
        $run = static function (string $mode, Type\Testing\Process $broker) use ($clientEnvironment, $root, $database, $base, &$cases): void {
            $client = new Type\Testing\Process(
                ['node', $root . '/tests/iot-device-client.mjs', $mode],
                $root,
                $clientEnvironment + ['TYPE_DEVICE_BROKER_PID' => (string) $broker->pid()]
            );
            try {
                // 通信授权及资源分页包含多次发布、查询和持久确认；整组预算不改变单次协议/撤权期限。
                $result = $client->wait($mode === 'broker-resources' && getenv('TYPE_BROKER_RESOURCE_DIST') ? 480
                    : (in_array($mode, ['communication', 'broker-resources'], true) ? 240 : 80));
                if (!$result->successful()) {
                    if ($mode === 'capacity') {
                        file_put_contents($base . '/capacity-failure.json', json_encode([
                            'quotas' => $database->query('SELECT version, snapshot_json FROM type_mqtt_quotas')->fetchAll(PDO::FETCH_ASSOC),
                            'sessions' => $database->query('SELECT client_id, capacity_class, subscriptions, owner_id IS NULL AS offline, expires_at FROM type_mqtt_sessions')->fetchAll(PDO::FETCH_ASSOC),
                            'messages' => $database->query('SELECT state, COUNT(*) AS count FROM type_mqtt_messages GROUP BY state')->fetchAll(PDO::FETCH_ASSOC),
                            'deliveries' => $database->query('SELECT state, COUNT(*) AS count FROM type_mqtt_deliveries GROUP BY state')->fetchAll(PDO::FETCH_ASSOC),
                        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                    }
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
        if (in_array('--communication-mqtt', $GLOBALS['argv'], true)) {
            $before = [];
            foreach (['iot_models', 'iot_ingestion_facts', 'iot_alarms', 'iot_commands'] as $table) {
                $before[$table] = (int) $database->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
            }
            $run('communication', $process);
            foreach ($before as $table => $count) {
                expect((int) $database->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() === $count, '原始通信不能进入旧处理链：' . $table);
            }
            expect($process->stop(15)->successful(), '设备应用通信节点没有正常退出');
            return ['client' => 'MQTT.js 5.15.0', 'tls' => true, 'cases' => $cases, 'no_legacy_processing' => true, 'broker_stopped' => true];
        }
        if (in_array('--applications-mqtt', $GLOBALS['argv'], true)) {
            $run('applications', $process);
            expect($process->stop(15)->successful(), '应用身份节点没有正常退出');
            return ['client' => 'MQTT.js 5.15.0', 'tls' => true, 'cases' => $cases, 'broker_stopped' => true];
        }
        if (in_array('--registration-mqtt', $GLOBALS['argv'], true)) {
            $rawBefore = (int) $database->query('SELECT COUNT(*) FROM iot_devices WHERE model_version IS NULL')->fetchColumn();
            $telemetryBefore = (int) $database->query('SELECT COUNT(*) FROM iot_ingestion_facts')->fetchColumn();
            $modelsBefore = (int) $database->query('SELECT COUNT(*) FROM iot_models')->fetchColumn();
            $run('registration', $process);
            expect((int) $database->query('SELECT COUNT(*) FROM iot_ingestion_facts')->fetchColumn() === $telemetryBefore
                && (int) $database->query('SELECT COUNT(*) FROM iot_models')->fetchColumn() === $modelsBefore, '原始设备注册和上行创建了物模型或遥测');
            expect((int) $database->query('SELECT COUNT(*) FROM iot_devices WHERE model_version IS NULL')->fetchColumn() === $rawBefore + 2, '真实接入没有使用注册资格创建的设备');
            $retained = $database->query("SELECT encode(payload, 'hex') FROM type_mqtt_retained WHERE topic LIKE '%/raw/up'")->fetchAll(PDO::FETCH_COLUMN);
            expect($retained !== [] && array_values(array_unique($retained)) === ['00ff80110a41'], '原始二进制消息没有按原字节持久保存');
            expect($process->stop(15)->successful(), '注册接入节点没有正常退出');
            return ['client' => 'MQTT.js 5.15.0', 'tls' => true, 'cases' => $cases, 'no_telemetry_or_model' => true, 'broker_stopped' => true];
        }
        if ($resources) {
            $run('broker-resources', $process);
            expect($process->stop(30)->successful(), '资源查询验收节点没有正常退出');
            return ['client' => 'MQTT.js 5.15.0', 'tls' => true, 'cases' => $cases, 'broker_stopped' => true, 'secrets_redacted' => true];
        }
        if ($cluster) {
            require_once __DIR__ . '/iot-cluster-mqtt.php';
            return iotClusterMqttChecks($request, $evidence, $command, $clientEnvironment, $base, $database, $process);
        }
        if ($ha) {
            return iotHaMqttChecks($evidence, $command, $environment, $clientEnvironment, $base, $process, $start);
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
            return iotLifecycleMqttChecks($request, $evidence, $command, $clientEnvironment, $base, $database, $process, $start);
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
    } catch (Throwable $failure) {
        if (in_array('--registration-mqtt', $GLOBALS['argv'], true)) {
            $diagnostic = ['audits' => $database->query("SELECT action, subject_id, details FROM customer_audit WHERE action LIKE 'device.%' ORDER BY created_at, id")->fetchAll(PDO::FETCH_ASSOC),
                'connections' => $database->query('SELECT * FROM iot_device_connections')->fetchAll(PDO::FETCH_ASSOC),
                'node' => $database->query('SELECT * FROM iot_broker_observations')->fetchAll(PDO::FETCH_ASSOC)];
            file_put_contents($base . '/registration-diagnostic.json', json_encode($diagnostic, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            chmod($base . '/registration-diagnostic.json', 0600);
        }
        throw $failure;
    } finally {
        try {
            $process?->stop(15);
        } finally {
            unlink($base . '/private.pem');
            if (is_file($clientFixtureFile)) {
                unlink($clientFixtureFile);
            }
        }
    }
}
