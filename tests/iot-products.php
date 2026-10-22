<?php

declare(strict_types=1);

/** 在身份测试建立的真实HTTP与隔离数据库上执行产品纵向验收，不直接调用内部业务类。 */
function iotProductChecks(Closure $appRequest, PDO $inspection, string $driver, string $address, string $platform, string $password, Type\Testing\HttpClient $client): array
{
    $call = static fn (string $method, string $path, string $token, ?array $data = null, int $status = 200, array $headers = []): array => $appRequest($method, $path, $token, $headers, $data, $status)['data'] ?? [];
    $tenantA = bin2hex(random_bytes(16));
    $tenantB = bin2hex(random_bytes(16));
    $call('POST', '/admin/tenants', $platform, ['id' => $tenantA, 'name' => '分类甲', 'new_customer' => true, 'owner_login' => 'product-owner', 'owner_name' => '产品所有者', 'owner_password' => $password]);
    $call('POST', '/admin/tenants', $platform, ['id' => $tenantB, 'name' => '分类乙', 'new_customer' => false, 'owner_login' => 'product-owner']);
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
    // 独立产品检查 PATCH 的缺失语义，不改变分类夹具的版本与分页计数。
    $partial = $request('POST', $products, $admin, $tenantA, ['name' => '部分更新', 'description' => '必须保留'], 201)['data'];
    $partialPath = $products . '/' . $partial['id'];
    $partial = $request('PATCH', $partialPath, $admin, $tenantA, ['version' => 1, 'name' => '只改名称'], 200)['data'];
    expect($partial['name'] === '只改名称' && $partial['description'] === '必须保留' && $partial['version'] === 2, 'PATCH 未提供的描述必须保留');
    $partial = $request('PATCH', $partialPath, $admin, $tenantA, ['version' => 2, 'description' => '只改描述'], 200)['data'];
    expect($partial['name'] === '只改名称' && $partial['description'] === '只改描述' && $partial['version'] === 3, 'PATCH 未提供的名称必须保留');
    $request('PATCH', $partialPath, $admin, $tenantA, ['version' => 3, 'name' => null], 422, 'validation_failed');
    $request('PATCH', $partialPath, $admin, $tenantA, ['version' => 3, 'description' => null], 422, 'validation_failed');
    $request('PATCH', $partialPath, $admin, $tenantA, ['name' => '缺失版本'], 422, 'validation_failed');
    $request('PATCH', $partialPath, $admin, $tenantA, ['version' => 3, 'tenant_id' => $tenantB], 422, 'unexpected_field');
    expect($request('GET', $partialPath, $admin, $tenantA, null, 200)['data'] === $partial, '无效 PATCH 不能修改产品或推进版本');
    $request('DELETE', $partialPath, $admin, $tenantA, ['version' => 3], 200);
    $request('GET', $products, '', $tenantA, null, 401);
    $request('GET', $products, $admin, null, null, 403, 'tenant_context_mismatch');
    $request('GET', $products, $admin, $tenantB, null, 403, 'tenant_context_mismatch');
    $request('GET', $products, $tokens['platform'], $tenantA, null, 401, 'unauthenticated');
    $request('GET', $products, $tokens['outsider'], $tenantA, null, 403, 'permission_denied');
    $request('GET', $products . '?cursor=another-tenant', $admin, $tenantA, null, 422, 'product_query_invalid');
    $request('POST', $products, $readonly, $tenantA, ['name' => '无权新增'], 403, 'permission_denied');
    $request('POST', $products, $operator, $tenantA, ['name' => '无权新增'], 403, 'permission_denied');
    expect($request('GET', $products, $readonly, $tenantA, null, 200)['total'] === 0, '拒绝写入不能产生产品');
    $product = $request('POST', $products, $admin, $tenantA, ['name' => '环境控制器', 'description' => '用于设备分类'], 201)['data'];
    $request('POST', $products, $admin, $tenantA, ['name' => '伪造归属', 'tenant_id' => $tenantB], 422, 'unexpected_field');
    $resource = $products . '/' . $product['id'];
    $models = $resource . '/models';
    expect($product['tenant_id'] === $tenantA && $product['version'] === 1, '产品必须保存本租户与乐观版本');
    $request('GET', $other . '/' . $product['id'], $admin, $tenantB, null, 404, 'product_not_found');
    expect($request('GET', $other, $admin, $tenantB, null, 200)['total'] === 0, '产品列表不能串租户');
    $request('PATCH', $resource, $readonly, $tenantA, ['name' => '不允许', 'description' => '', 'version' => 1], 403, 'permission_denied');
    $edited = $request('PATCH', $resource, $admin, $tenantA, ['name' => '环境控制器甲', 'description' => '新说明', 'version' => 1], 200)['data'];
    expect($edited['version'] === 2 && $edited['description'] === '新说明', '产品资料更新需推进版本');
    $request('PATCH', $resource, $admin, $tenantA, ['name' => '旧页面', 'description' => '', 'version' => 1], 409, 'stale_version');
    $request('DELETE', $resource, $operator, $tenantA, ['version' => 2], 403, 'permission_denied');
    // 旧 API 完整退出，任何方法均不能再创建或修改模型。
    foreach (['GET', 'POST', 'PATCH', 'DELETE'] as $method) {
        $request($method, $models, $admin, $tenantA, $method === 'GET' ? null : [], 404, 'not_found');
        $request($method, $models . '/1', $admin, $tenantA, $method === 'GET' ? null : [], 404, 'not_found');
    }
    foreach (['publish', 'validate'] as $action) {
        $request('POST', $models . '/1/' . $action, $admin, $tenantA, [], 404, 'not_found');
    }
    // 隔离旧数据装置：草稿和发布原件都必须阻止删除，不由新 API 制造历史。
    $legacy = $request('POST', $products, $admin, $tenantA, ['name' => '保全旧分类'], 201)['data'];
    $insert = $inspection->prepare('INSERT INTO iot_models (tenant_id, product_id, model_version, version, status, definition, created_at, published_at) VALUES (?, ?, 1, 1, ?, ?, ?, NULL)');
    $insert->execute([$tenantA, $legacy['id'], 'draft', '{"properties":[],"events":[],"commands":[]}', time()]);
    $insert->closeCursor();
    $beforeLegacy = $inspection->query('SELECT * FROM iot_models')->fetchAll(PDO::FETCH_ASSOC);
    $request('DELETE', $products . '/' . $legacy['id'], $admin, $tenantA, ['version' => 1], 409, 'product_referenced');
    expect($inspection->query('SELECT * FROM iot_models')->fetchAll(PDO::FETCH_ASSOC) === $beforeLegacy, '分类删除改变了旧模型原件');
    for ($index = 0; $index < 19; $index++) {
        $request('POST', $products, $admin, $tenantA, ['name' => '分页分类-' . $index], 201);
    }
    $first = $request('GET', $products, $readonly, $tenantA, null, 200);
    $second = $request('GET', $products . '?page=2', $readonly, $tenantA, null, 200);
    expect($first['total'] === 21 && count($first['items']) === 20 && count($second['items']) === 1
        && array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id')) === [], '分类分页重复或截断');
    expect($request('GET', $products . '?name=' . rawurlencode('环境控制器'), $readonly, $tenantA, null, 200)['total'] === 1, '分类筛选须对应真实数据');
    $call('DELETE', '/customer/members/' . $member['id'], $admin, ['version' => 2], 200, $headers);
    $request('GET', $resource, $operator, $tenantA, null, 403, 'permission_denied');
    $raw = $client->request('POST', $products, ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $admin, 'X-Tenant-Id' => $tenantA], '{"name":"甲","name":"乙"}');
    expect($raw->status === 400 && $raw->json()['error'] === 'invalid_json', '分类正文重复 JSON 键必须拒绝');
    // 只注入隔离测试库审计故障，查询和断言仍通过公开HTTP观察草稿与版本。
    if ($driver === 'pgsql') {
        $inspection->exec("CREATE FUNCTION product_test_failure() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF NEW.action IN (''product.changed'', ''product.created'') THEN RAISE EXCEPTION ''controlled product audit failure''; END IF; RETURN NEW; END'");
        $inspection->exec('CREATE TRIGGER product_test_failure BEFORE INSERT ON customer_audit FOR EACH ROW EXECUTE FUNCTION product_test_failure()');
    } elseif ($driver === 'mysql') {
        $inspection->exec("CREATE TRIGGER product_test_failure BEFORE INSERT ON customer_audit FOR EACH ROW BEGIN IF NEW.action IN ('product.changed', 'product.created') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'controlled product audit failure'; END IF; END");
    } else {
        $inspection->exec("CREATE TRIGGER product_test_failure BEFORE INSERT ON customer_audit WHEN NEW.action IN ('product.changed', 'product.created') BEGIN SELECT RAISE(ABORT, 'controlled product audit failure'); END");
    }
    try {
        $request('PATCH', $resource, $admin, $tenantA, ['version' => 2, 'name' => '不应提交', 'description' => '审计故障'], 500);
        expect($request('GET', $resource, $admin, $tenantA, null, 200)['data'] === $edited, '审计失败未回滚产品内容和版本');
        $request('POST', $products, $admin, $tenantA, ['name' => '不应创建'], 500);
        expect($request('GET', $products, $admin, $tenantA, null, 200)['total'] === 21, '审计失败留下新建产品');
    } finally {
        $inspection->exec('DROP TRIGGER product_test_failure' . ($driver === 'pgsql' ? ' ON customer_audit' : ''));
        if ($driver === 'pgsql') {
            $inspection->exec('DROP FUNCTION product_test_failure()');
        }
    }
    $race = identityCompete($inspection, $driver, $address, [
        ['PATCH', $resource, $admin, ['version' => 2, 'name' => '分类竞争甲'], $headers],
        ['PATCH', $resource, $admin, ['version' => 2, 'name' => '分类竞争乙'], $headers],
    ]);
    sort($race);
    expect($race === [200, 409], '分类修改不能同时接受同一资料版本');
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
    $request('GET', $other . '/' . $product['id'], $readonly, $tenantB, null, 403, 'permission_denied');
    foreach (['X-Support-Id', 'X-Impersonation-Id', 'X-Identity-Realm'] as $spoofed) {
        $appRequest('GET', $products, $admin, $headers + [$spoofed => str_repeat('a', 32)], null, 403);
    }
    $role = $call('PUT', '/customer/roles/' . $role['id'] . '/permissions', $admin, ['version' => 2, 'permissions' => ['identity.read']], 200, $headers);
    $request('GET', $products, $readonly, $tenantA, null, 403, 'permission_denied');
    expect(!in_array('/products', menuPaths($call('GET', '/customer/auth/me', $readonly, null, 200, $headers)['menus']), true), '撤回查询节点后仍可见产品菜单');
    $call('PUT', '/customer/roles/' . $role['id'] . '/permissions', $admin, ['version' => 3, 'permissions' => ['identity.read', 'customer.products.read']], 200, $headers);
    return ['path' => $resource, 'tenant' => $tenantA, 'token' => $readonly,
        'product' => $request('GET', $resource, $readonly, $tenantA, null, 200)['data'], 'concurrent_update' => $race,
        'source_logout_write' => $sourceRace, 'revoked_queued_write' => $queuedWrite, 'audit_rollback' => true,
        'actor_preserved' => true, 'retired_model_api' => true, 'legacy_original_preserved' => true];
}
