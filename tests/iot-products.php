<?php

declare(strict_types=1);

/** 在身份测试建立的真实HTTP与隔离数据库上执行产品纵向验收，不直接调用内部业务类。 */
function iotProductChecks(Closure $appRequest, PDO $inspection, string $driver, string $address, string $platform, string $password, Type\Testing\HttpClient $client): array
{
    $call = static fn (string $method, string $path, string $token, ?array $data = null, int $status = 200, array $headers = []): array => $appRequest($method, $path, $token, $headers, $data, $status)['data'] ?? [];
    $tenantA = bin2hex(random_bytes(16));
    $tenantB = bin2hex(random_bytes(16));
    $call('POST', '/admin/tenants', $platform, ['id' => $tenantA, 'name' => '模型甲', 'new_customer' => true, 'owner_login' => 'product-owner', 'owner_name' => '产品所有者', 'owner_password' => $password]);
    $call('POST', '/admin/tenants', $platform, ['id' => $tenantB, 'name' => '模型乙', 'new_customer' => false, 'owner_login' => 'product-owner']);
    $login = static fn (string $name): string => $call('POST', '/customer/auth/login', '', ['login' => $name, 'password' => $password])['accessToken'];
    $admin = $login('product-owner');
    $headers = ['X-Tenant-Id' => $tenantA];
    $role = $call('POST', '/customer/roles', $admin, ['name' => '产品查看', 'permissions' => ['identity.read', 'customer.products.read']], 200, $headers);
    $role = $call('POST', '/customer/roles/' . $role['id'] . '/status', $admin, ['version' => 1, 'enabled' => true], 200, $headers);
    $members = [];
    foreach (['product-reader', 'product-operator'] as $name) {
        $members[] = $call('POST', '/customer/members', $admin, ['login' => $name, 'new_customer' => true, 'account_name' => $name, 'password' => $password, 'name' => $name, 'roles' => [['id' => $role['id'], 'version' => 2]]], 200, $headers);
    }
    $member = $members[1];
    $readonly = $login('product-reader');
    $operator = $login('product-operator');
    $call('POST', '/admin/customers', $platform, ['login' => 'product-outsider', 'name' => '无成员客户', 'password' => $password]);
    $tokens = ['platform' => $platform, 'outsider' => $login('product-outsider')];
    $request = static function (string $method, string $path, string $token, ?string $tenant, ?array $data, int $status, string $code = '') use ($appRequest): array {
        $result = $appRequest($method, $path, $token, $tenant === null ? [] : ['X-Tenant-Id' => $tenant], $data, $status);
        expect($code === '' || ($result['error'] ?? '') === $code, '产品错误码不匹配：' . $path . ' expected=' . $code . ' actual=' . ($result['error'] ?? ''));
        return $result;
    };
    $path = '/customer/tenants/' . $tenantA;
    $products = $path . '/products';
    $other = '/customer/tenants/' . $tenantB . '/products';
    $request('GET', $products, '', $tenantA, null, 401);
    $request('GET', $products, $admin, null, null, 403, 'tenant_context_mismatch');
    $request('GET', $products, $admin, $tenantB, null, 403, 'tenant_context_mismatch');
    $request('GET', $products, $tokens['platform'], $tenantA, null, 401, 'unauthenticated');
    $request('GET', $products, $tokens['outsider'], $tenantA, null, 403, 'permission_denied');
    $request('GET', $products . '?cursor=another-tenant', $admin, $tenantA, null, 422, 'product_query_invalid');
    $request('POST', $products, $readonly, $tenantA, ['name' => '无权新增'], 403, 'permission_denied');
    $request('POST', $products, $operator, $tenantA, ['name' => '无权新增'], 403, 'permission_denied');
    expect($request('GET', $products, $readonly, $tenantA, null, 200)['total'] === 0, '拒绝写入不能产生产品');
    $product = $request('POST', $products, $admin, $tenantA, ['name' => '环境控制器', 'description' => '保留旧版本单位与事件含义'], 201)['data'];
    $request('POST', $products, $admin, $tenantA, ['name' => '伪造归属', 'tenant_id' => $tenantB], 422, 'unexpected_field');
    $resource = $products . '/' . $product['id'];
    $models = $resource . '/models';
    expect($product['tenant_id'] === $tenantA && $product['version'] === 1, '产品必须保存本租户与乐观版本');
    $request('GET', $other . '/' . $product['id'], $admin, $tenantB, null, 404, 'product_not_found');
    $request('GET', $other . '/' . $product['id'] . '/models', $admin, $tenantB, null, 404, 'product_not_found');
    expect($request('GET', $other, $admin, $tenantB, null, 200)['total'] === 0, '产品列表不能串租户');
    $request('PATCH', $resource, $readonly, $tenantA, ['name' => '不允许', 'description' => '', 'version' => 1], 403, 'permission_denied');
    $edited = $request('PATCH', $resource, $admin, $tenantA, ['name' => '环境控制器甲', 'description' => '新说明', 'version' => 1], 200)['data'];
    expect($edited['version'] === 2 && $edited['description'] === '新说明', '产品资料更新需推进版本');
    $request('PATCH', $resource, $admin, $tenantA, ['name' => '旧页面', 'description' => '', 'version' => 1], 409, 'stale_version');
    $request('DELETE', $resource, $operator, $tenantA, ['version' => 2], 403, 'permission_denied');
    $definition = [
        'properties' => [
            ['identifier' => 'temperature', 'name' => '温度', 'type' => 'number', 'required' => true, 'unit' => '°C', 'min' => -40, 'max' => 125],
            ['identifier' => 'relay', 'name' => '继电器', 'type' => 'boolean', 'required' => false],
            ['identifier' => 'mode', 'name' => '运行模式', 'type' => 'enum', 'required' => false, 'values' => ['auto', 'manual']],
            ['identifier' => 'firmware', 'name' => '固件版本', 'type' => 'string', 'required' => false, 'min_length' => 1, 'max_length' => 20],
        ],
        'events' => [['identifier' => 'fault', 'name' => '故障', 'parameters' => [['identifier' => 'code', 'name' => '代码', 'type' => 'integer', 'required' => true, 'min' => 1, 'max' => 999]]]],
        'commands' => [['identifier' => 'set_target', 'name' => '设置温度', 'parameters' => [['identifier' => 'target', 'name' => '目标温度', 'type' => 'number', 'required' => true, 'unit' => '°C', 'min' => 0, 'max' => 100]]], ['identifier' => 'restart', 'name' => '重新启动', 'parameters' => []]],
    ];
    $request('POST', $models, $operator, $tenantA, ['definition' => $definition], 403, 'permission_denied');
    $invalidDefinitions = [];
    $invalidDefinitions[] = ['properties' => [], 'events' => [], 'commands' => [], 'typo' => true];
    $invalid = $definition;
    $invalid['properties'][] = $invalid['properties'][0];
    $invalidDefinitions[] = $invalid;
    foreach ([['type' => 'object'], ['min' => 130], ['min' => '0'], ['unit' => null], ['required' => 'true'], ['identifier' => 'bad.field'], ['unexpected' => true]] as $override) {
        $invalid = $definition;
        $invalid['properties'][0] = array_replace($invalid['properties'][0], $override);
        $invalidDefinitions[] = $invalid;
    }
    $invalid = $definition;
    $invalid['properties'][2]['values'] = ['auto', 'auto'];
    $invalidDefinitions[] = $invalid;
    $invalid = $definition;
    $invalid['properties'][3]['min_length'] = 21;
    $invalidDefinitions[] = $invalid;
    $invalid = $definition;
    $invalid['events'][0]['parameters'][0]['min'] = 1.5;
    $invalidDefinitions[] = $invalid;
    $invalid = $definition;
    $invalid['commands'][0]['parameters'][] = $invalid['commands'][0]['parameters'][0];
    $invalidDefinitions[] = $invalid;
    $invalid = $definition;
    $invalid['commands'][0]['parameters'] = array_fill(0, 33, $invalid['commands'][0]['parameters'][0]);
    $invalidDefinitions[] = $invalid;
    $invalid = $definition;
    $invalid['properties'] = array_fill(0, 65, ['identifier' => 'active', 'name' => '启用', 'type' => 'boolean', 'required' => false]);
    $invalidDefinitions[] = $invalid;
    foreach ($invalidDefinitions as $invalid) {
        $failure = $request('POST', $models, $admin, $tenantA, ['definition' => $invalid], 422, 'validation_failed');
        expect(isset($failure['fields']) && $failure['fields'] !== [], '定义错误必须携带字段定位');
    }
    expect($request('GET', $models, $readonly, $tenantA, null, 200)['total'] === 0, '非法定义不能留存草稿或消耗版本号');
    $draft = $request('POST', $models, $admin, $tenantA, ['definition' => $definition], 201)['data'];
    expect($draft['model_version'] === 1 && $draft['version'] === 1 && $draft['status'] === 'draft' && $draft['published_at'] === null, '草稿状态或永久编号错误');
    $model = $models . '/1';
    $request('GET', $other . '/' . $product['id'] . '/models/1', $admin, $tenantB, null, 404, 'model_not_found');
    $request('POST', $model . '/validate', $admin, $tenantA, ['kind' => 'properties', 'values' => ['temperature' => 25]], 409, 'model_not_published');
    $changedDefinition = $definition;
    $changedDefinition['properties'][0]['name'] = '环境温度';
    $saved = $request('PATCH', $model, $admin, $tenantA, ['version' => 1, 'definition' => $changedDefinition], 200)['data'];
    expect($saved['version'] === 2 && $saved['definition'] === $changedDefinition, '草稿定义应完整替换并实际持久化');
    $request('PATCH', $model, $admin, $tenantA, ['version' => 1, 'definition' => $definition], 409, 'stale_version');
    $request('POST', $model . '/publish', $readonly, $tenantA, ['version' => 2], 403, 'permission_denied');
    $request('POST', $model . '/publish', $operator, $tenantA, ['version' => 2], 403, 'permission_denied');
    $request('POST', $model . '/publish', $admin, $tenantA, ['version' => 1], 409, 'stale_version');
    $published = $request('POST', $model . '/publish', $admin, $tenantA, ['version' => 2], 200)['data'];
    expect($published['version'] === 3 && $published['status'] === 'published' && $published['published_at'] > 0, '发布应冻结版本并记录时间');
    $request('PATCH', $model, $admin, $tenantA, ['version' => 3, 'definition' => $definition], 409, 'model_immutable');
    $request('DELETE', $model, $admin, $tenantA, ['version' => 3], 409, 'model_immutable');
    $request('POST', $model . '/publish', $admin, $tenantA, ['version' => 3], 409, 'model_immutable');
    $request('DELETE', $resource, $admin, $tenantA, ['version' => 2], 409, 'published_model_retained');
    foreach ([$readonly, $operator] as $reader) {
        expect($request('GET', $model, $reader, $tenantA, null, 200)['data'] === $published, '只读与操作员应能查看同一冻结定义');
    }
    $request('POST', $model . '/validate', $readonly, $tenantA, ['kind' => 'properties', 'values' => ['temperature' => -40, 'relay' => false, 'mode' => 'auto', 'firmware' => '1.0']], 200);
    $request('POST', $model . '/validate', $readonly, $tenantA, ['kind' => 'properties', 'values' => ['temperature' => 125]], 200);
    foreach ([['temperature' => 126], ['temperature' => '25'], ['temperature' => null], ['temperature' => 25, 'relay' => 1], ['temperature' => 25, 'mode' => 'unknown'], ['temperature' => 25, 'firmware' => ''], ['temperature' => 25, 'extra' => 1], ['relay' => true], []] as $values) {
        $request('POST', $model . '/validate', $readonly, $tenantA, ['kind' => 'properties', 'values' => (object) $values], 422, 'validation_failed');
    }
    $request('POST', $model . '/validate', $operator, $tenantA, ['kind' => 'event', 'identifier' => 'fault', 'values' => ['code' => 42]], 200);
    $request('POST', $model . '/validate', $operator, $tenantA, ['kind' => 'event', 'identifier' => 'fault', 'values' => ['code' => 1.2]], 422, 'validation_failed');
    $request('POST', $model . '/validate', $operator, $tenantA, ['kind' => 'command', 'identifier' => 'set_target', 'values' => ['target' => 50]], 200);
    $request('POST', $model . '/validate', $operator, $tenantA, ['kind' => 'command', 'identifier' => 'set_target', 'values' => ['target' => 101]], 422, 'validation_failed');
    $request('POST', $model . '/validate', $operator, $tenantA, ['kind' => 'command', 'identifier' => 'restart', 'values' => (object) []], 200);
    $request('POST', $model . '/validate', $operator, $tenantA, ['kind' => 'command', 'identifier' => 'missing', 'values' => (object) []], 422, 'validation_failed');
    $request('POST', $model . '/validate', $operator, $tenantA, ['kind' => 'event', 'values' => ['code' => 1]], 422, 'validation_failed');
    $next = $published['definition'];
    $next['properties'][0]['unit'] = '°F';
    $next['properties'][0]['max'] = 257;
    $newDraft = $request('POST', $models, $admin, $tenantA, ['definition' => $next], 201)['data'];
    expect($newDraft['model_version'] === 2 && $newDraft['definition']['properties'][0]['unit'] === '°F', '修改已发布模型必须形成独立版本');
    $request('POST', $models . '/2/publish', $admin, $tenantA, ['version' => 1], 200);
    expect($request('GET', $model, $admin, $tenantA, null, 200)['data'] === $published, '新发布不得改变旧版本的内容、单位或发布时间');
    $request('POST', $model . '/validate', $admin, $tenantA, ['kind' => 'properties', 'values' => ['temperature' => 200]], 422, 'validation_failed');
    $request('POST', $models . '/2/validate', $admin, $tenantA, ['kind' => 'properties', 'values' => ['temperature' => 200]], 200);
    $empty = ['properties' => [], 'events' => [], 'commands' => []];
    $request('POST', $models, $admin, $tenantA, ['definition' => $empty], 201);
    $request('POST', $models . '/3/publish', $admin, $tenantA, ['version' => 1], 422, 'empty_model');
    $request('DELETE', $models . '/3', $admin, $tenantA, ['version' => 1], 200);
    expect($request('POST', $models, $admin, $tenantA, ['definition' => $definition], 201)['data']['model_version'] === 4, '删除草稿后编号不能复用');
    $versions = $request('GET', $models . '?per_page=2', $readonly, $tenantA, null, 200);
    expect($versions['total'] === 3 && array_column($versions['items'], 'model_version') === [4, 2], '模型分页应按永久编号稳定倒序');
    expect($request('GET', $models . '?per_page=2&page=2', $readonly, $tenantA, null, 200)['items'][0]['model_version'] === 1, '第二页应返回历史版本');
    $request('GET', $models . '?per_page=101', $readonly, $tenantA, null, 422);
    $request('GET', $models . '/3', $readonly, $tenantA, null, 404, 'model_not_found');
    $disposable = $request('POST', $products, $admin, $tenantA, ['name' => '临时未发布产品'], 201)['data'];
    $largeDefinition = ['properties' => [], 'events' => [], 'commands' => []];
    for ($propertyIndex = 0; $propertyIndex < 64; $propertyIndex++) {
        $largeDefinition['properties'][] = ['identifier' => 'state_' . $propertyIndex, 'name' => '状态', 'type' => 'boolean', 'required' => false];
    }
    $boundedModel = $request('POST', $products . '/' . $disposable['id'] . '/models', $admin, $tenantA, ['definition' => $largeDefinition], 201)['data'];
    expect(count($boundedModel['definition']['properties']) === 64, '合法64项属性不得被旧100字段传输预算拒绝');
    $request('DELETE', $products . '/' . $disposable['id'], $admin, $tenantA, ['version' => 1], 200);
    $request('GET', $products . '/' . $disposable['id'] . '/models/1', $admin, $tenantA, null, 404, 'model_not_found');
    for ($index = 0; $index < 20; $index++) {
        $request('POST', $products, $admin, $tenantA, ['name' => '分页产品-' . $index], 201);
    }
    $first = $request('GET', $products, $readonly, $tenantA, null, 200);
    $second = $request('GET', $products . '?page=2', $readonly, $tenantA, null, 200);
    expect($first['total'] === 21 && count($first['items']) === 20 && count($second['items']) === 1 && array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id')) === [], '产品分页不能重复或截断');
    expect($request('GET', $products . '?name=' . rawurlencode('环境控制器'), $readonly, $tenantA, null, 200)['total'] === 1, '产品筛选须对应真实数据');
    $query = $inspection->prepare("SELECT * FROM customer_audit WHERE tenant_id = ? AND action = 'model.publish' AND subject_id = ?");
    $query->execute([$tenantA, $product['id']]);
    $audits = $query->fetchAll(PDO::FETCH_ASSOC);
    $query->closeCursor();
    expect(count(array_filter($audits, static fn (array $row): bool => $row['result'] === 'success')) === 2, '每次成功发布必须同事务保留审计');
    $query = $inspection->prepare("SELECT COUNT(*) FROM customer_audit WHERE tenant_id = ? AND result = 'denied'");
    $query->execute([$tenantA]);
    expect((int) $query->fetchColumn() >= 6, '权限与实体拒绝必须有适用审计');
    $query->closeCursor();
    expect(!str_contains(json_encode($audits), 'temperature') && !str_contains(json_encode($audits), 'set_target'), '审计不能写入模型完整载荷');
    $call('DELETE', '/customer/members/' . $member['id'], $admin, ['version' => 2], 200, $headers);
    $request('GET', $model, $operator, $tenantA, null, 403, 'permission_denied');
    $raw = $client->request('POST', $models, ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $admin, 'X-Tenant-Id' => $tenantA], '{"definition":{"properties":[],"properties":[],"events":[],"commands":[]}}');
    expect($raw->status === 400 && $raw->json()['error'] === 'invalid_json', '模型定义重复JSON键必须拒绝');
    $large = $client->request('POST', $models, ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $admin, 'X-Tenant-Id' => $tenantA], json_encode(['definition' => ['properties' => [], 'events' => [], 'commands' => [], 'padding' => str_repeat('a', 16384)]]));
    expect($large->status === 413, '模型入口必须在16KiB边界拒绝超限');
    // 只注入隔离测试库审计故障，查询和断言仍通过公开HTTP观察草稿与版本。
    if ($driver === 'pgsql') {
        $inspection->exec("CREATE FUNCTION product_test_failure() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF NEW.action = ''model.publish'' THEN RAISE EXCEPTION ''controlled product audit failure''; END IF; RETURN NEW; END'");
        $inspection->exec('CREATE TRIGGER product_test_failure BEFORE INSERT ON customer_audit FOR EACH ROW EXECUTE FUNCTION product_test_failure()');
    } elseif ($driver === 'mysql') {
        $inspection->exec("CREATE TRIGGER product_test_failure BEFORE INSERT ON customer_audit FOR EACH ROW BEGIN IF NEW.action = 'model.publish' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'controlled product audit failure'; END IF; END");
    } else {
        $inspection->exec("CREATE TRIGGER product_test_failure BEFORE INSERT ON customer_audit WHEN NEW.action = 'model.publish' BEGIN SELECT RAISE(ABORT, 'controlled product audit failure'); END");
    }
    $before = $request('GET', $models . '/4', $admin, $tenantA, null, 200)['data'];
    try {
        $request('POST', $models . '/4/publish', $admin, $tenantA, ['version' => 1], 500);
        expect($request('GET', $models . '/4', $admin, $tenantA, null, 200)['data'] === $before, '审计失败留下半发布版本');
    } finally {
        $inspection->exec('DROP TRIGGER product_test_failure' . ($driver === 'pgsql' ? ' ON customer_audit' : ''));
        if ($driver === 'pgsql') {
            $inspection->exec('DROP FUNCTION product_test_failure()');
        }
    }
    $race = identityCompete($inspection, $driver, $address, [
        ['POST', $models . '/4/publish', $admin, ['version' => 1], $headers],
        ['PATCH', $models . '/4', $admin, ['version' => 1, 'definition' => $definition], $headers],
    ]);
    sort($race);
    expect($race === [200, 409], '发布与编辑不能同时接受同一草稿版本');
    $owner = $call('GET', '/customer/auth/me', $admin, null, 200, $headers);
    $customer = $call('GET', '/admin/customers/' . $owner['user']['id'], $platform)['items'][0];
    $simulation = $call('POST', '/admin/customers/' . $customer['id'] . '/impersonate', $platform, ['version' => (int) $customer['version']]);
    $simToken = $simulation['accessToken'];
    $simProduct = $request('POST', $products, $simToken, $tenantA, ['name' => '模拟产品'], 201)['data'];
    $query = $inspection->prepare("SELECT actor_id, details FROM customer_audit WHERE subject_id = ? AND action = 'product.created'");
    $query->execute([$simProduct['id']]);
    $audit = $query->fetch(PDO::FETCH_ASSOC);
    $query->closeCursor();
    $origin = $call('GET', '/admin/auth/me', $platform);
    $context = $call('GET', '/customer/auth/me', $simToken, null, 200, $headers)['identity'];
    $details = json_decode($audit['details'], true, 32, JSON_THROW_ON_ERROR);
    expect($audit['actor_id'] === $origin['user']['id'] && $details['customer_id'] === $customer['id'] && $details['source_session_id'] === $context['source_session_id'], '产品审计丢失真实来源');
    $sourceRace = identityCompete($inspection, $driver, $address, [
        ['POST', '/admin/auth/logout', $platform, []],
        ['POST', $products, $simToken, ['name' => '来源退出竞争'], $headers],
    ]);
    expect($sourceRace[0] === 200 && in_array($sourceRace[1], [201, 401], true), '来源退出与产品变更没有确定终态：' . json_encode($sourceRace));
    $request('POST', $products, $simToken, $tenantA, ['name' => '已失效模拟'], 401, 'unauthenticated');
    // 锁竞争不保证请求顺序；在测试持锁期间撤销准确来源，确定性验证锁后复核。
    $freshPlatform = $call('POST', '/admin/auth/login', '', ['login' => 'same-login', 'password' => $password])['accessToken'];
    $queuedToken = $call('POST', '/admin/customers/' . $customer['id'] . '/impersonate', $freshPlatform, ['version' => (int) $customer['version']])['accessToken'];
    $queuedContext = $call('GET', '/customer/auth/me', $queuedToken, null, 200, $headers)['identity'];
    $queuedWrite = identityCompete($inspection, $driver, $address, [
        ['POST', $products, $queuedToken, ['name' => '持锁撤权禁止写入'], $headers],
    ], static function () use ($inspection, $queuedContext): void {
        $deletion = $inspection->prepare('DELETE FROM admin_sessions WHERE id = ?');
        $deletion->execute([$queuedContext['source_session_id']]);
        expect($deletion->rowCount() === 1, '未撤销准确来源会话');
        $deletion->closeCursor();
    });
    expect($queuedWrite === [401], '排队产品写入未在授权锁后重验准确模拟来源：' . json_encode($queuedWrite));
    expect($request('GET', $products . '?name=' . rawurlencode('持锁撤权禁止写入'), $admin, $tenantA, null, 200)['total'] === 0, '来源撤销后仍写入产品');
    $request('GET', $other . '/' . $product['id'] . '/models/1', $readonly, $tenantB, null, 403, 'permission_denied');
    foreach (['X-Support-Id', 'X-Impersonation-Id', 'X-Identity-Realm'] as $spoofed) {
        $appRequest('GET', $products, $admin, $headers + [$spoofed => str_repeat('a', 32)], null, 403);
    }
    $role = $call('PUT', '/customer/roles/' . $role['id'] . '/permissions', $admin, ['version' => 2, 'permissions' => ['identity.read']], 200, $headers);
    $request('GET', $products, $readonly, $tenantA, null, 403, 'permission_denied');
    expect(!in_array('/products', menuPaths($call('GET', '/customer/auth/me', $readonly, null, 200, $headers)['menus']), true), '撤回查询节点后仍可见产品菜单');
    $call('PUT', '/customer/roles/' . $role['id'] . '/permissions', $admin, ['version' => 3, 'permissions' => ['identity.read', 'customer.products.read']], 200, $headers);
    return ['path' => $model, 'tenant' => $tenantA, 'token' => $readonly, 'published' => $published, 'concurrent_publish_edit' => $race, 'source_logout_write' => $sourceRace, 'revoked_queued_write' => $queuedWrite, 'audit_rollback' => true, 'actor_preserved' => true];
}
