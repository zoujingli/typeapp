<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Type\Testing\HttpClient;
use Type\Testing\Process;

/** 通过已公开的命令与真实 HTTP 验证租户授权；每轮只修改独立测试数据库。 */
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

/** 真实HTTP请求等待安装行锁后竞争；只用于本轮隔离数据库。 */
function identityCompete(PDO $inspection, string $driver, string $address, array $requests, ?Closure $beforeRelease = null): array
{
    $sockets = [];
    if ($driver === 'sqlite') {
        $inspection->exec('BEGIN IMMEDIATE');
    } else {
        $inspection->beginTransaction();
        $inspection->query('SELECT id FROM app_installation WHERE id = 1 FOR UPDATE')->closeCursor();
    }
    $locked = true;
    try {
        foreach ($requests as $entry) {
            [$method, $path, $credential, $data] = $entry;
            $socket = stream_socket_client('tcp://' . $address, $number, $reason, 5);
            expect(is_resource($socket), '无法建立并发授权连接');
            $sockets[] = $socket;
            stream_set_timeout($socket, 15);
            $body = json_encode($data === [] ? (object) [] : $data, JSON_THROW_ON_ERROR);
            $headers = '';
            foreach ($entry[4] ?? [] as $header => $value) {
                $headers .= $header . ': ' . $value . "\r\n";
            }
            $bytes = "{$method} {$path} HTTP/1.1\r\nHost: {$address}\r\nAuthorization: Bearer {$credential}\r\n" . $headers . "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
            expect(fwrite($socket, $bytes) === strlen($bytes), '并发授权请求写入失败');
        }
        usleep(100000);
        if ($beforeRelease !== null) {
            $beforeRelease();
        }
        if ($driver === 'sqlite') {
            $inspection->exec('COMMIT');
        } else {
            $inspection->commit();
        }
        $locked = false;
        $statuses = [];
        foreach ($sockets as $socket) {
            $response = stream_get_contents($socket);
            expect(preg_match('/^HTTP\/1\.1 (\d{3})/', $response, $matched) === 1, '并发授权响应无效');
            $statuses[] = (int) $matched[1];
        }
        return $statuses;
    } finally {
        if ($locked) {
            if ($driver === 'sqlite') {
                $inspection->exec('ROLLBACK');
            } elseif ($inspection->inTransaction()) {
                $inspection->rollBack();
            }
        }
        foreach ($sockets as $socket) {
            fclose($socket);
        }
    }
}

/** 复用设备与模拟身份；PDO仅构造本轮资源查询边界，协议行为另由真实MQTT验证。 */
function identityBrokerChecks(Closure $request, PDO $database, array $fixture, string $password): array
{
    $tenant = $fixture['tenant'];
    $other = $fixture['other_tenant'];
    $headers = ['X-Tenant-Id' => $tenant];
    $tokens = $fixture['tokens'];
    $path = '/customer/tenants/' . $tenant . '/broker';
    $call = static fn (string $method, string $url, string $token, array $scope = [], ?array $data = null, int $status = 200): array => $request($method, $url, $token, $scope, $data, $status);
    $call('GET', $path . '/resources/connections', $tokens['alice'], $headers, null, 403);
    $role = $call('POST', '/customer/roles', $tokens['bob'], $headers, ['name' => 'Broker只读', 'permissions' => ['identity.read', 'customer.broker.read', 'customer.devices.read']])['data'];
    $role = $call('POST', '/customer/roles/' . $role['id'] . '/status', $tokens['bob'], $headers, ['version' => 1, 'enabled' => true])['data'];
    $reader = $call('GET', '/customer/members?search=device-reader', $tokens['bob'], $headers)['data']['items'][0];
    $call('PUT', '/customer/members/roles', $tokens['bob'], $headers, ['members' => [['id' => $reader['id'], 'version' => $reader['version']]], 'roles' => [['id' => $role['id'], 'version' => $role['version']]]]);
    $call('GET', $path . '/audit', $tokens['alice'], $headers, null, 403);
    $call('GET', '/customer/tenants/' . $tenant . '/devices/' . $fixture['device']['id'] . '/current', $tokens['alice'], $headers, null, 403);
    $call(
        'POST',
        '/customer/tenants/' . $tenant . '/devices/' . $fixture['device']['id'] . '/commands',
        $tokens['alice'],
        $headers,
        ['command_id' => bin2hex(random_bytes(16)), 'identifier' => 'switch', 'values' => ['on' => true], 'version' => (int) $fixture['device']['version']],
        403
    );
    $call('GET', $path . '/resources/connections', $tokens['platform'], $headers, null, 401);
    $call('GET', '/admin/broker/resources/connections', $tokens['bob'], [], null, 401);
    $call('GET', $path . '/resources/connections', $tokens['bob'], ['X-Tenant-Id' => $other], null, 403);
    foreach (['X-Support-Id', 'X-Impersonation-Id', 'X-Identity-Realm'] as $header) {
        $call('GET', $path . '/resources/connections', $tokens['bob'], $headers + [$header => bin2hex(random_bytes(16))], null, 403);
    }
    $call('GET', '/admin/broker/resources/connections', $tokens['platform'], $headers, null, 403);
    foreach (['/iot/broker/resources/connections', '/iot/tenants/' . $tenant . '/broker/audit'] as $retired) {
        $call('GET', $retired, $tokens['platform'], [], null, 404);
    }
    // 只构造本轮查询边界；真实连接与协议状态仍由MQTT专项产生。
    $node = bin2hex(random_bytes(16));
    $owners = [bin2hex(random_bytes(16)), bin2hex(random_bytes(16)), bin2hex(random_bytes(16))];
    $database->prepare('INSERT INTO broker_resource_runs (slot, node_id, run_id, observed_at, expires_at, connections) VALUES (0, ?, ?, ?, ?, 3)')->execute(['app-broker-fixture', $node, time(), time() + 60]);
    try {
        foreach ($owners as $index => $id) {
            $scope = 'iot:' . ($index === 2 ? $other : $tenant);
            $database->prepare('INSERT INTO broker_resource_connections (owner_id, observation_run, client_id, client_hash, session_id, session_generation, node_id, node_run_id, node_generation, resource_scope, scope_hash, protocol, transport, durable, observed_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?, 1, ?, ?, 5, ?, 0, ?)')
                ->execute([$id, $node, $id, hash('sha256', $id), bin2hex(random_bytes(16)), 'app-broker-fixture', $node, $scope, hash('sha256', $scope), 'tls', time()]);
        }
        $first = $call('GET', $path . '/resources/connections?limit=1', $tokens['alice'], $headers);
        expect($first['has_more'] && $first['total'] === null && count($first['items']) === 1, 'Broker首个键集页错误');
        $next = $call('GET', $path . '/resources/connections?limit=1&cursor=' . rawurlencode($first['next_cursor']), $tokens['alice'], $headers);
        expect(!$next['has_more'] && $first['items'][0]['id'] !== $next['items'][0]['id'], 'Broker键集页重复或跨租户');
        expect(count($call('GET', '/admin/broker/resources/connections', $tokens['platform'])['items']) === 3, '平台元数据全局范围错误');
        $call('GET', '/customer/tenants/' . $other . '/broker/resources/connections/' . $owners[0], $tokens['bob'], ['X-Tenant-Id' => $other], null, 404);
        $call('GET', $path . '/resources/connections?limit=1&cursor=' . rawurlencode($first['next_cursor']), $fixture['simulated']['accessToken'], $headers, null, 400);
        $call('GET', $path . '/resources/connections/' . $owners[0], $fixture['simulated']['accessToken'], $headers);
        $target = $call('GET', $path . '/resources/connections/' . $owners[0], $tokens['bob'], $headers)['item'];
        $disconnectBody = ['session_id' => $target['session_id'], 'session_generation' => $target['session_generation'], 'node_id' => $target['node_id']];
        $call('POST', $path . '/resources/connections/' . $owners[0] . '/disconnect', $tokens['alice'], $headers, $disconnectBody, 403);
        $operationId = bin2hex(random_bytes(16));
        $accepted = $call('POST', $path . '/resources/connections/' . $owners[0] . '/disconnect', $tokens['bob'], $headers, $disconnectBody + ['operation_id' => $operationId]);
        expect($accepted['operation_id'] === $operationId && $accepted['outcome'] === 'pending' && $accepted['stage'] === 'executing'
            && $accepted['result'] === 'pending', '断开受理被当成已完成');
        $repeat = $call('POST', $path . '/resources/connections/' . $owners[0] . '/disconnect', $tokens['bob'], $headers, $disconnectBody + ['operation_id' => $operationId]);
        expect($repeat['operation_id'] === $operationId && $repeat['stage'] === $accepted['stage'] && $repeat['outcome'] === 'pending', '同标识重试改变了冻结结果');
        $call('POST', $path . '/resources/connections/' . $owners[0] . '/disconnect', $tokens['bob'], $headers, [
            'session_id' => $target['session_id'], 'session_generation' => $target['session_generation'] + 1, 'node_id' => $target['node_id'],
        ], 409);
        $call('POST', '/customer/tenants/' . $other . '/broker/resources/connections/' . $owners[0] . '/disconnect', $tokens['bob'], ['X-Tenant-Id' => $other], $disconnectBody, 404);
        $pending = $call('GET', $path . '/operations/' . $operationId, $tokens['bob'], $headers);
        expect($pending['outcome'] === 'pending' && $pending['result'] === 'pending', '未执行断开被标成成功');
        $call('GET', $path . '/operations/' . $operationId, '', $headers, null, 401);
        $disconnectEvents = $call('GET', $path . '/audit?action=broker.connection.disconnect&operation_id=' . $operationId, $tokens['bob'], $headers);
        expect($disconnectEvents['items'] !== [] && $disconnectEvents['items'][0]['result'] === 'pending'
            && $disconnectEvents['items'][0]['operation_id'] === $operationId, '断开审计把待处理写成成功或丢失操作标识');
        $encodedDisconnect = json_encode($disconnectEvents, JSON_THROW_ON_ERROR);
        expect(!str_contains($encodedDisconnect, $password) && !str_contains($encodedDisconnect, '"client_id"'), '断开审计包含秘密或客户端标识');
        $foreign = $call('GET', '/admin/broker/resources/connections/' . $owners[2], $tokens['platform'])['item'];
        $platformOp = $call('POST', '/admin/broker/resources/connections/' . $owners[2] . '/disconnect', $tokens['platform'], [], [
            'session_id' => $foreign['session_id'], 'session_generation' => $foreign['session_generation'], 'node_id' => $foreign['node_id'],
        ]);
        expect($platformOp['outcome'] === 'pending', '平台断开也应保持待处理直到节点执行');
        $call('GET', $path . '/operations/' . $platformOp['operation_id'], $tokens['bob'], $headers, null, 404);
        $sessionId = bin2hex(random_bytes(16));
        $terminateBody = ['session_generation' => 1, 'node_id' => 'app-broker-fixture', 'confirmed' => true];
        $call('POST', $path . '/resources/sessions/' . $sessionId . '/terminate', $tokens['alice'], $headers, $terminateBody, 403);
        $call('POST', $path . '/resources/sessions/' . $sessionId . '/terminate', $tokens['bob'], $headers, ['session_generation' => 1, 'node_id' => 'app-broker-fixture'], 422);
        $call('GET', $path . '/resources/sessions/' . $sessionId . '/termination-preview', $tokens['alice'], $headers, null, 503);
        $call('POST', $path . '/resources/sessions/' . $sessionId . '/terminate', $tokens['bob'], $headers, $terminateBody, 503);
        $retainedId = bin2hex(random_bytes(16));
        $clearBody = ['generation' => 1, 'confirmed' => true];
        $call('POST', $path . '/resources/retained/' . $retainedId . '/clear', $tokens['alice'], $headers, $clearBody, 403);
        $call('POST', $path . '/resources/retained/' . $retainedId . '/clear', $tokens['bob'], $headers, ['generation' => 1], 422);
        $call('GET', $path . '/resources/retained/' . $retainedId . '/clearance-preview', $tokens['alice'], $headers, null, 503);
        $call('POST', $path . '/resources/retained/' . $retainedId . '/clear', $tokens['bob'], $headers, $clearBody, 503);
        $quotas = $call('GET', $path . '/quotas', $tokens['alice'], $headers);
        expect(isset($quotas['limits'], $quotas['usage'], $quotas['current_version']), '额度只读查询失败');
        $call('POST', $path . '/quotas', $tokens['alice'], $headers, [
            'expected_version' => $quotas['current_version'], 'maximumConnections' => 1, 'confirmed' => true,
        ], 403);
        $call('POST', $path . '/quotas', $tokens['bob'], $headers, [
            'expected_version' => $quotas['current_version'], 'maximumConnections' => 1, 'confirmed' => true,
        ], 403);
        $platformQuotas = $call('GET', '/admin/broker/quotas', $tokens['platform']);
        $call('POST', '/admin/broker/quotas', $tokens['platform'], [], [
            'expected_version' => $platformQuotas['current_version'], 'maximumConnections' => 1,
        ], 422);
        $runtime = $call('GET', $path . '/runtime', $tokens['alice'], $headers);
        expect(isset($runtime['config'], $runtime['current_version'], $runtime['restart_required']), '运行配置只读查询失败');
        $call('POST', $path . '/runtime', $tokens['alice'], $headers, [
            'expected_version' => $runtime['current_version'], 'port' => 1884, 'confirmed' => true,
        ], 403);
        $call('POST', $path . '/runtime', $tokens['bob'], $headers, [
            'expected_version' => $runtime['current_version'], 'port' => 1884, 'confirmed' => true,
        ], 403);
        $platformRuntime = $call('GET', '/admin/broker/runtime', $tokens['platform']);
        $call('POST', '/admin/broker/runtime', $tokens['platform'], [], [
            'expected_version' => $platformRuntime['current_version'], 'port' => 1884,
        ], 422);
        $debug = $call('GET', $path . '/debug', $tokens['alice'], $headers);
        expect(array_key_exists('credential', $debug) && array_key_exists('transport', $debug), '租户调试查询失败');
        $call('POST', $path . '/debug', $tokens['alice'], $headers, [], 409);
        $call('POST', $path . '/debug/revoke', $tokens['alice'], $headers, [], 200);
        $call('GET', '/admin/broker/debug', $tokens['platform'], [], null, 403);
        $call('POST', '/admin/broker/debug', $tokens['platform'], [], [], 403);
        $event = $call('GET', $path . '/audit?action=broker.resource.read&subject_id=' . $owners[0], $tokens['bob'], $headers)['items'][0];
        expect($event['details']['source_session_id'] === $fixture['source']['identity']['session_id']
            && $event['details']['customer_id'] === $fixture['simulated']['identity']['customer_id']
            && $event['actor_id'] === $fixture['source']['identity']['actor_id'], 'Broker审计丢失真实来源或有效客户');
        $detail = $call('GET', $path . '/audit/' . $event['id'], $tokens['bob'], $headers)['item'];
        expect($call('GET', '/admin/broker/audit/customer/' . $event['id'], $tokens['platform'])['item'] === $detail
            && $detail['operation']['authorization']['required_action'] === 'customer.broker.read'
            && $detail['operation']['identity']['impersonation_id'] === $fixture['simulated']['identity']['impersonation_id'], '双端Broker详情或准确权限上下文错误');
        $call('GET', '/customer/tenants/' . $other . '/broker/audit/' . $event['id'], $tokens['bob'], ['X-Tenant-Id' => $other], null, 404);
        $audit = $call('GET', $path . '/audit?limit=1', $tokens['bob'], $headers);
        expect($audit['has_more'], 'Broker审计未产生分页');
        $call('GET', '/customer/tenants/' . $tenant . '/audit?limit=1&cursor=' . rawurlencode($audit['next_cursor']), $tokens['bob'], $headers, null, 400);
        foreach (['limit=101', 'limit=1&limit=2', 'fields=payload', 'cursor=invalid'] as $query) {
            $call('GET', $path . '/resources/connections?' . $query, $tokens['alice'], $headers, null, 400);
        }
        $role = $call('POST', '/customer/roles/' . $role['id'] . '/status', $tokens['bob'], $headers, ['version' => $role['version'], 'enabled' => false])['data'];
        $call('GET', $path . '/resources/connections/' . $owners[0], $tokens['alice'], $headers, null, 403);
        $role = $call('POST', '/customer/roles/' . $role['id'] . '/status', $tokens['bob'], $headers, ['version' => $role['version'], 'enabled' => true])['data'];
        $role = $call('PUT', '/customer/roles/' . $role['id'] . '/permissions', $tokens['bob'], $headers, ['version' => $role['version'], 'permissions' => ['identity.read', 'customer.broker.read', 'customer.devices.read', 'customer.audit.read']])['data'];
        $call('GET', $path . '/resources/connections?limit=1&cursor=' . rawurlencode($first['next_cursor']), $tokens['alice'], $headers, null, 400);
        $call('GET', $path . '/audit/' . $event['id'], $tokens['alice'], $headers);
        $role = $call('PUT', '/customer/roles/' . $role['id'] . '/permissions', $tokens['bob'], $headers, ['version' => $role['version'], 'permissions' => ['identity.read', 'customer.audit.read']])['data'];
        $call('GET', $path . '/audit', $tokens['alice'], $headers, null, 403);
        $ordinary = '/customer/tenants/' . $tenant . '/audit';
        expect($call('GET', $ordinary . '?action=broker.resource.read', $tokens['alice'], $headers)['items'] === [], '普通客户审计绕过Broker权限');
        $call('GET', $ordinary . '/' . $event['id'], $tokens['alice'], $headers, null, 404);
        expect($call('GET', $ordinary, $tokens['alice'], $headers)['items'] !== [], '资源权限过滤误删普通客户审计');
        $role = $call('PUT', '/customer/roles/' . $role['id'] . '/permissions', $tokens['bob'], $headers, ['version' => $role['version'], 'permissions' => ['identity.read', 'customer.broker.read', 'customer.devices.read']])['data'];
        $auditor = $call('POST', '/admin/users', $tokens['platform'], [], ['login' => 'broker-auditor', 'name' => '普通审计人员', 'password' => $password])['data'];
        $auditRole = $call('POST', '/admin/roles', $tokens['platform'], [], ['name' => '普通审计', 'permissions' => ['identity.read', 'admin.audit.read']])['data'];
        $auditRole = $call('POST', '/admin/roles/' . $auditRole['id'] . '/status', $tokens['platform'], [], ['version' => $auditRole['version'], 'enabled' => true])['data'];
        $call('PUT', '/admin/users/roles', $tokens['platform'], [], ['users' => [['id' => $auditor['id'], 'version' => $auditor['version']]], 'roles' => [['id' => $auditRole['id'], 'version' => $auditRole['version']]]]);
        $auditToken = $call('POST', '/admin/auth/login', '', [], ['login' => $auditor['login'], 'password' => $password])['data']['accessToken'];
        $call('GET', '/admin/broker/audit', $auditToken, [], null, 403);
        expect($call('GET', '/admin/audit?action=broker.resource.read', $auditToken)['items'] === [], '普通平台审计绕过Broker权限');
        $call('GET', '/admin/audit/customer/' . $event['id'], $auditToken, [], null, 404);
        expect($call('GET', '/admin/audit?action=identity.login', $auditToken)['items'] !== [], '资源权限过滤误删普通平台审计');
        $call('PUT', '/admin/roles/' . $auditRole['id'] . '/permissions', $tokens['platform'], [], ['version' => $auditRole['version'], 'permissions' => ['identity.read', 'admin.audit.read', 'admin.broker.read']]);
        expect($call('GET', '/admin/audit/customer/' . $event['id'], $auditToken)['item'] === $detail, '同时授权后普通审计详情未恢复');
        $call('POST', '/admin/auth/logout', $auditToken);
        $database->prepare('UPDATE broker_resource_runs SET expires_at = ? WHERE run_id = ?')->execute([time() - 1, $node]);
        $unknown = $call('GET', $path . '/resources/connections/' . $owners[0], $tokens['alice'], $headers)['item'];
        expect(!$unknown['connection_confirmed'] && $unknown['state'] === 'unknown', '过期连接被查询伪装为健康');
        $source = $call('POST', '/admin/auth/login', '', [], ['login' => 'same-login', 'password' => $password])['data'];
        $simulation = $call('POST', '/admin/customers/' . $fixture['simulated']['identity']['customer_id'] . '/impersonate', $source['accessToken'], [], ['version' => 1])['data'];
        $call('GET', $path . '/resources/nodes', $simulation['accessToken'], $headers);
        $call('POST', '/admin/auth/logout', $source['accessToken']);
        $call('GET', $path . '/resources/nodes', $simulation['accessToken'], $headers, null, 401);
        $call('GET', $path . '/audit', $simulation['accessToken'], $headers, null, 401);
    } finally {
        foreach ($owners as $id) {
            $database->prepare('DELETE FROM broker_connection_operations WHERE owner_id = ?')->execute([$id]);
            $database->prepare('DELETE FROM broker_resource_connections WHERE owner_id = ?')->execute([$id]);
        }
        $database->prepare('DELETE FROM broker_resource_runs WHERE run_id = ?')->execute([$node]);
    }
    $menus = $call('GET', '/customer/auth/me', $tokens['alice'], $headers)['data']['menus'];
    expect(in_array('/broker-resources', menuPaths($menus), true) && in_array('/broker-access', menuPaths($menus), true)
        && in_array('/broker-quotas', menuPaths($menus), true)
        && in_array('/broker-runtime', menuPaths($menus), true)
        && in_array('/broker-debug', menuPaths($menus), true)
        && !in_array('/broker-audit', menuPaths($menus), true), '资源菜单隐含审计权限');
    $listed = $call('GET', $path . '/access/principals', $tokens['alice'], $headers);
    expect(isset($listed['items'], $listed['current_version']), '只读授权列表失败');
    $call('POST', $path . '/access/principals', $tokens['alice'], $headers, [
        'name' => '拒绝写入', 'login' => 'denied-client', 'password' => bin2hex(random_bytes(16)),
        'enabled' => 1, 'expected_version' => $listed['current_version'],
        'grants' => [['topic' => 'iot/' . $tenant . '/', 'publish' => 1, 'subscribe' => 1, 'max_qos' => 0]],
    ], 403);
    return ['checks' => ['realm-and-tenant-isolation', 'independent-resource-audit-business-permissions', 'metadata-keyset-pages', 'exact-impersonation-audit',
        'dual-realm-audit-detail', 'ordinary-audit-cannot-bypass-broker-permission', 'cursor-source-query-permissions-binding', 'role-revocation', 'source-logout', 'expired-observation-unknown', 'old-entry-removed',
        'connection-disconnect-pending-retry-stale-and-cross-tenant', 'session-terminate-forbidden-unconfirmed-and-store-unavailable', 'retain-clear-forbidden-unconfirmed-and-store-unavailable', 'quota-tenant-write-forbidden-and-unconfirmed', 'runtime-tenant-write-forbidden-and-unconfirmed', 'debug-tenant-issue-without-wss-and-platform-forbidden'],
        'fixture' => ['tenant_b' => $other, 'tokens' => $tokens, 'reader_role' => $role]];
}

/** 复用真实设备与模拟装置；只用PDO构造保留边界及数据库持锁中断。 */
function identityAuditChecks(Closure $request, PDO $database, array $fixture, array $command, array $environment, string $password): array
{
    $tenant = $fixture['tenant'];
    $headers = ['X-Tenant-Id' => $tenant];
    $customer = $fixture['tokens']['bob'];
    $platform = $fixture['platform_token'];
    $simulated = $fixture['simulated']['accessToken'];
    $path = '/customer/tenants/' . $tenant . '/audit';
    $call = static fn (string $method, string $url, string $token, array $scope = [], ?array $data = null, int $status = 200): array => $request($method, $url, $token, $scope, $data, $status);
    $current = $call('GET', $fixture['path'], $customer, $headers)['data'];
    $call('PATCH', '/admin/devices/' . $current['id'], $platform, [], ['version' => $current['version'], 'name' => '审计平台真实资料修改']);
    $call('PATCH', $fixture['path'], $simulated, $headers, ['version' => $current['version'] + 1, 'name' => '审计模拟真实资料修改']);
    $events = $call('GET', $path . '?subject_id=' . $current['id'] . '&action=device.update&result=success', $customer, $headers)['items'];
    $event = array_values(array_filter($events, static fn (array $item): bool => ($item['details']['impersonation_id'] ?? '') === $fixture['simulated']['identity']['impersonation_id']))[0] ?? null;
    expect($event !== null && $event['actor_id'] === $fixture['source']['identity']['actor_id'] && $event['details']['customer_id'] === $fixture['simulated']['identity']['customer_id']
        && $event['details']['source_session_id'] === $fixture['source']['identity']['session_id'] && $event['tenant_id'] === $tenant, '新审计缺少真实人员、有效客户或准确模拟来源');
    $detail = $call('GET', $path . '/' . $event['id'], $customer, $headers)['item'];
    expect($detail === $call('GET', '/admin/audit/customer/' . $event['id'], $platform)['item'], '平台与客户对同一事实投影不同');
    expect(count($call('GET', '/admin/audit?subject_id=' . $current['id'] . '&action=device.update', $platform)['items']) > count($events), '平台审计未合并平台和客户真实动作');
    foreach ([$customer, $simulated] as $token) {
        $call('GET', '/admin/audit', $token, [], null, 401);
    }
    $call('GET', $path, '', $headers, null, 401);
    $call('GET', $path, $fixture['tokens']['alice'], $headers, null, 403);
    $call('GET', $path, $fixture['tokens']['outsider'], $headers, null, 403);
    $call('GET', $path, $platform, $headers, null, 401);
    $call('GET', $path, $customer, [], null, 403);
    $call('GET', '/admin/audit', $platform, $headers, null, 403);
    $call('GET', $path, $customer, $headers + ['X-Support-Id' => bin2hex(random_bytes(16))], null, 403);
    $otherPath = '/customer/tenants/' . $fixture['other_tenant'] . '/audit';
    $otherHeaders = ['X-Tenant-Id' => $fixture['other_tenant']];
    expect($call('GET', $otherPath . '/' . $event['id'], $customer, $otherHeaders, null, 404)['error'] === 'audit_not_found', '跨租户详情未拒绝');
    $first = $call('GET', $path . '?limit=1', $simulated, $headers);
    expect($first['total'] === null && $first['has_more'] && $first['next_cursor'] !== null, '新审计必须返回有界键集分页');
    $cursor = '?limit=1&cursor=' . rawurlencode($first['next_cursor']);
    expect($call('GET', $path . $cursor, $simulated, $headers)['items'][0]['id'] !== $first['items'][0]['id'], '相同时间记录跨页重复');
    expect($call('GET', $path . $cursor, $customer, $headers, null, 400)['error'] === 'audit_cursor_invalid', '普通会话复用了模拟游标');
    $call('GET', $otherPath . $cursor, $simulated, $otherHeaders, null, 400);
    $call('GET', $path . $cursor . '&action=device.update', $simulated, $headers, null, 400);
    foreach (['limit=101', 'page=2', 'actor_id=a&actor_id=b', 'from=%xx', 'cursor=!!!', 'result=ok'] as $invalid) {
        $call('GET', $path . '?' . $invalid, $customer, $headers, null, 400);
    }
    $call('GET', $path . '?from=100&to=99', $customer, $headers, null, 422);
    expect($call('GET', $path . '?to=1', $customer, $headers)['items'] === [], '空审计筛选必须返回空列表');
    $origin = $call('POST', '/admin/auth/login', '', [], ['login' => 'same-login', 'password' => $password])['data'];
    $second = $call('POST', '/admin/customers/' . $fixture['simulated']['identity']['customer_id'] . '/impersonate', $origin['accessToken'], [], ['version' => 1])['data'];
    $call('GET', $path . $cursor, $second['accessToken'], $headers, null, 400);
    $call('POST', '/admin/auth/logout', $origin['accessToken'], [], []);
    $call('GET', $path . '/' . $event['id'], $second['accessToken'], $headers, null, 401);
    $call('GET', $path, $customer, $headers);
    // 独立观察角色不含设备、遥测或控制权限；变更当前权限使原游标失效。
    $role = $call('POST', '/customer/roles', $customer, $headers, ['name' => '审计与运行只读', 'permissions' => ['identity.read', 'customer.audit.read', 'customer.operations.read']])['data'];
    $call('POST', '/customer/roles/' . $role['id'] . '/status', $customer, $headers, ['version' => 1, 'enabled' => true]);
    $call('POST', '/customer/members', $customer, $headers, ['login' => 'observation-only', 'new_customer' => true, 'account_name' => '独立观察', 'password' => $password, 'name' => '独立观察', 'roles' => [['id' => $role['id'], 'version' => 2]]]);
    $observer = $call('POST', '/customer/auth/login', '', [], ['login' => 'observation-only', 'password' => $password])['data']['accessToken'];
    $observerPage = $call('GET', $path . '?limit=1', $observer, $headers);
    $call('GET', '/customer/tenants/' . $tenant . '/operations', $observer, $headers);
    $call('GET', $fixture['path'], $observer, $headers, null, 403);
    $call('GET', $fixture['path'] . '/current', $observer, $headers, null, 403);
    $call('PUT', '/customer/roles/' . $role['id'] . '/permissions', $customer, $headers, ['version' => 2, 'permissions' => ['customer.audit.read', 'customer.operations.read']]);
    $call('GET', $path . '?limit=1&cursor=' . rawurlencode($observerPage['next_cursor']), $observer, $headers, null, 400);
    $call('PUT', '/customer/roles/' . $role['id'] . '/permissions', $customer, $headers, ['version' => 3, 'permissions' => ['customer.operations.read']]);
    $call('GET', $path . '/' . $event['id'], $observer, $headers, null, 403);
    $call('GET', '/iot/tenants/' . $tenant . '/audit', $customer, $headers, null, 404);
    // 相同时间、相同随机ID跨表仍必须稳定分页，且日志清理不触碰身份/恢复账本。
    $cutoff = time() - 180 * 86400;
    $ids = array_map(static fn (int $index): string => bin2hex(random_bytes(16)), range(0, 3));
    $facts = [];
    foreach (['app_installation', 'admin_sessions', 'customer_sessions', 'admin_broker_operations', 'iot_authorization_invalidations'] as $table) {
        $facts[$table] = $database->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
    foreach (['admin', 'customer'] as $realm) {
        $insert = $database->prepare('INSERT INTO ' . $realm . '_audit (id, tenant_id, actor_id, action, subject_id, result, details, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($ids as $index => $id) {
            $insert->execute([$id, $tenant, 'retention-fixture', 'fixture.retention', 'fixture', 'success', '{}', $cutoff - (3 - $index)]);
        }
        foreach (['pending', 'unknown', 'failed'] as $outcome) {
            $insert->execute([bin2hex(random_bytes(16)), $tenant, 'retention-fixture', 'fixture.outcome', 'fixture', $outcome, '{}', $cutoff + 3600]);
        }
    }
    $seen = [];
    $after = '';
    do {
        $batch = $call('GET', '/admin/audit?actor_id=retention-fixture&limit=1' . ($after === '' ? '' : '&cursor=' . rawurlencode($after)), $platform);
        foreach ($batch['items'] as $item) {
            $seen[] = $item['audit_realm'] . ':' . $item['id'];
        }
        $after = $batch['next_cursor'] ?? '';
        expect(count($seen) <= 14, '双表审计游标未前进');
    } while ($batch['has_more']);
    expect(count($seen) === 14 && count(array_unique($seen)) === 14, '双表相同事件ID发生遗漏或重复');
    foreach (['admin', 'customer'] as $realm) {
        $database->beginTransaction();
        $database->prepare('UPDATE ' . $realm . '_audit SET details = ? WHERE id = ?')->execute(['{}', $ids[0]]);
        $interrupted = new Process([...$command, 'app:audit-clean', $realm, '2'], dirname(__DIR__), $environment);
        try {
            usleep(300000);
            expect($interrupted->running(), '持锁清理必须等待真实数据库');
            $interrupted->stop(0);
        } finally {
            $interrupted->stop();
            $database->rollBack();
        }
        expect((int) $database->query('SELECT COUNT(*) FROM ' . $realm . '_audit WHERE created_at <= ' . $cutoff)->fetchColumn() === 4, '清理中断留下部分删除');
        $pruned = identityCommand([...$command, 'app:audit-clean', $realm, '2'], $environment)['data'];
        expect($pruned['deleted'] === 2 && $pruned['has_more'], '到期清理必须限定本批');
        $resumed = identityCommand([...$command, 'app:audit-clean', $realm, '2'], $environment)['data'];
        expect($resumed['deleted'] === 2 && !$resumed['has_more'], '新进程无法恢复到期清理');
        expect(identityCommand([...$command, 'app:audit-clean', $realm], $environment)['data']['deleted'] === 0, '清理重试不幂等');
    }
    foreach ($facts as $table => $count) {
        expect($database->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() === $count, '日志清理损坏身份/恢复事实：' . $table);
    }
    foreach (['pending', 'unknown', 'failed'] as $outcome) {
        $retained = $call('GET', '/admin/audit?actor_id=retention-fixture&result=' . $outcome, $platform)['items'];
        expect(count($retained) === 2 && $retained[0]['result'] === $outcome, '未到期未知或待处理事实被改写');
    }
    foreach ([$password, $customer, $platform, $simulated, $fixture['registration']['credential']['password']] as $secret) {
        expect(!str_contains(json_encode([$events, $detail, $retained], JSON_THROW_ON_ERROR), $secret), '审计泄漏令牌或设备秘密');
    }
    return ['checks' => ['real-admin-and-impersonated-events', 'exact-origin-projection', 'reauthorized-details', 'tenant-and-realm-isolation', 'session-permission-filter-bound-cursor',
        'strict-query', 'independent-read-nodes', 'same-id-cross-realm-order', 'source-revocation', '180-day-bounded-interrupted-cleanup', 'recovery-facts-retained', 'pending-unknown-preserved', 'no-secret-projection']];
}

/** 通过真实账号和授权入口验证来源、当前权限及独立会话；PDO只核对事实、注入期限和审计故障。 */
function identityImpersonationCases(callable $request, PDO $inspection, string $driver, string $address, string $admin, string $customer, string $password): array
{
    $adminCall = static fn (string $method, string $path, ?array $data = null, int $status = 200): array => $request($method, '/admin/' . $path, $admin, [], $data, $status)['data'] ?? [];
    $source = $adminCall('POST', 'users', ['login' => 'impersonation-agent', 'name' => '模拟验收管理人员', 'password' => $password]);
    $originRole = $adminCall('POST', 'roles', ['name' => '模拟资格', 'permissions' => ['identity.read', 'admin.customers.read', 'admin.customers.impersonate']]);
    $adminCall('POST', 'roles/' . $originRole['id'] . '/status', ['version' => 1, 'enabled' => true]);
    $adminCall('PUT', 'users/roles', ['users' => [['id' => $source['id'], 'version' => 1]], 'roles' => [['id' => $originRole['id'], 'version' => 2]]]);
    $sourceLogin = static fn (): array => $request('POST', '/admin/auth/login', '', [], ['login' => $source['login'], 'password' => $password], 200)['data'];
    $origin = $sourceLogin();
    $originToken = $origin['accessToken'];
    $tenants = [];
    foreach (['模拟验收甲', '模拟验收乙'] as $name) {
        $tenants[] = $adminCall('POST', 'tenants', ['id' => bin2hex(random_bytes(16)), 'name' => $name, 'new_customer' => false, 'owner_login' => 'same-login'])['id'];
    }
    $call = static fn (string $method, string $path, ?array $data = null, int $status = 200, ?string $as = null, ?string $scope = null): array => $request($method, '/customer/' . $path, $as ?? $customer, ['X-Tenant-Id' => $scope ?? $tenants[0]], $data, $status)['data'] ?? [];
    $catalog = $call('GET', 'roles')['catalog'];
    $fullRole = $call('POST', 'roles', ['name' => '完整模拟权限', 'permissions' => array_keys($catalog)]);
    $call('POST', 'roles/' . $fullRole['id'] . '/status', ['version' => 1, 'enabled' => true]);
    $member = $call('POST', 'members', ['login' => 'impersonation-customer', 'new_customer' => true, 'account_name' => '模拟目标客户', 'name' => '模拟成员甲', 'password' => $password, 'roles' => [['id' => $fullRole['id'], 'version' => 2]]]);
    $secondRole = array_column($call('GET', 'roles', null, 200, null, $tenants[1])['items'], null, 'name')['只读成员'];
    $call('POST', 'members', ['login' => 'impersonation-customer', 'new_customer' => false, 'name' => '模拟成员乙', 'roles' => [['id' => $secondRole['id'], 'version' => 1]]], 200, null, $tenants[1]);
    $target = $adminCall('GET', 'customers/' . $member['user_id'])['items'][0];
    $real = $request('POST', '/customer/auth/login', '', [], ['login' => $target['login'], 'password' => $password], 200)['data']['accessToken'];
    $start = static fn (string $as, int $status = 200, int $version = 1): array => $request('POST', '/admin/customers/' . $target['id'] . '/impersonate', $as, [], ['version' => $version], $status)['data'] ?? [];
    $start($real, 401);
    $start($originToken, 409, 99);
    $request('POST', '/admin/customers/' . str_repeat('f', 32) . '/impersonate', $originToken, [], ['version' => 1], 404);
    $request('POST', '/admin/customers/' . $target['id'] . '/impersonate', $originToken, [], ['version' => 1, 'actor_id' => $source['id']], 422);
    $first = $start($originToken);
    $simulated = $first['accessToken'];
    expect($first['expiresAt'] <= $origin['expiresAt'], '模拟期限超过来源会话');
    $sourceContext = $request('GET', '/admin/auth/me', $originToken, [], null, 200)['data']['identity'];
    $normal = $call('GET', 'auth/me', null, 200, $real);
    $acting = $call('GET', 'auth/me', null, 200, $simulated);
    expect($acting['permissions'] === $normal['permissions'] && $acting['menus'] === $normal['menus'], '模拟未完整采用目标当前权限');
    $context = $acting['identity'];
    expect($context['actor_id'] === $source['id'] && $context['customer_id'] === $target['id'] && $context['source_session_id'] === $sourceContext['session_id'] && $context['impersonation_id'] === $context['session_id'] && $context['tenant_id'] === $tenants[0], '模拟公开来源不完整');
    expect($context['key'] !== $normal['identity']['key'] && !str_contains(json_encode($context), $simulated) && !str_contains(json_encode($context), hash('sha256', $simulated)), '身份范围混用或泄漏认证信息');
    $other = $call('GET', 'auth/me', null, 200, $simulated, $tenants[1]);
    expect($other['identity']['key'] !== $context['key'] && $other['permissions'] === $call('GET', 'auth/me', null, 200, $real, $tenants[1])['permissions'], '切租户未切换范围和角色');
    $call('GET', 'roles', null, 403, $simulated, str_repeat('f', 32));
    $request('GET', '/admin/roles', $simulated, [], null, 401);
    foreach (['X-Support-Id', 'X-Impersonation-Id', 'X-Identity-Realm'] as $header) {
        $request('GET', '/customer/auth/me', $simulated, [$header => 'forged'], null, 403);
    }
    $request('POST', '/customer/account/password', $simulated, [], ['version' => 1, 'current_password' => $password, 'password' => $password . '-new'], 403);
    $written = $call('POST', 'roles', ['name' => '真实模拟操作', 'permissions' => []], 200, $simulated);
    $audit = $inspection->prepare("SELECT actor_id, tenant_id, details FROM customer_audit WHERE action = 'customer.roles.create' AND subject_id = ?");
    $audit->execute([$written['id']]);
    $event = $audit->fetch(PDO::FETCH_ASSOC);
    $audit->closeCursor();
    $details = json_decode($event['details'], true, 32, JSON_THROW_ON_ERROR);
    expect($event['actor_id'] === $source['id'] && $event['tenant_id'] === $tenants[0] && $details['customer_id'] === $target['id'] && $details['source_session_id'] === $context['source_session_id'] && $details['scope_key'] === $context['key'], '业务审计丢失真实来源或作用范围');
    $call('PUT', 'roles/' . $fullRole['id'] . '/permissions', ['version' => 2, 'permissions' => ['identity.read']]);
    $call('GET', 'roles', null, 403, $simulated);
    $call('PUT', 'roles/' . $fullRole['id'] . '/permissions', ['version' => 3, 'permissions' => array_keys($catalog)]);
    $call('POST', 'members/' . $member['id'] . '/status', ['version' => (int) $member['version'], 'enabled' => false]);
    $call('GET', 'auth/me', null, 403, $simulated);
    $call('GET', 'auth/me', null, 200, $simulated, $tenants[1]);
    $call('POST', 'members/' . $member['id'] . '/status', ['version' => (int) $member['version'] + 1, 'enabled' => true]);
    $adminCall('POST', 'tenants/' . $tenants[0] . '/status', ['version' => 1, 'enabled' => false]);
    $call('GET', 'auth/me', null, 403, $simulated);
    $adminCall('POST', 'tenants/' . $tenants[0] . '/status', ['version' => 2, 'enabled' => true]);
    $adminCall('PUT', 'roles/' . $originRole['id'] . '/permissions', ['version' => 2, 'permissions' => ['identity.read', 'admin.customers.read']]);
    $call('GET', 'auth/me', null, 401, $simulated);
    $start($originToken, 403);
    $adminCall('PUT', 'roles/' . $originRole['id'] . '/permissions', ['version' => 3, 'permissions' => ['identity.read', 'admin.customers.read', 'admin.customers.impersonate']]);
    $adminCall('POST', 'roles/' . $originRole['id'] . '/status', ['version' => 4, 'enabled' => false]);
    $call('GET', 'auth/me', null, 401, $simulated);
    $adminCall('POST', 'roles/' . $originRole['id'] . '/status', ['version' => 5, 'enabled' => true]);
    $request('POST', '/customer/auth/logout', $simulated, [], [], 200);
    $call('GET', 'auth/me', null, 401, $simulated);
    $call('GET', 'auth/me', null, 200, $real);
    $request('GET', '/admin/auth/me', $originToken, [], null, 200);
    $tokens = [];
    for ($index = 0; $index < 11; $index++) {
        $tokens[] = $start($originToken)['accessToken'];
    }
    $valid = 0;
    foreach ($tokens as $credential) {
        $check = $inspection->prepare('SELECT COUNT(*) FROM customer_sessions WHERE token_hash = ?');
        $check->execute([hash('sha256', $credential)]);
        $valid += (int) $check->fetchColumn();
        $check->closeCursor();
    }
    expect($valid === 10, '模拟会话预算未生效：' . $valid);
    $call('GET', 'auth/me', null, 200, $real);
    $latest = $tokens[10];
    $raced = identityCompete($inspection, $driver, $address, [
        ['POST', '/admin/auth/logout', $originToken, []],
        ['POST', '/customer/roles', $latest, ['name' => '并发退出模拟', 'permissions' => []], ['X-Tenant-Id' => $tenants[0]]],
    ]);
    expect($raced[0] === 200 && in_array($raced[1], [200, 401], true), '并发来源退出与变更没有确定终态');
    $call('POST', 'roles', ['name' => '退出后禁止写入', 'permissions' => []], 401, $latest);
    $call('GET', 'auth/me', null, 200, $real);
    $originToken = $sourceLogin()['accessToken'];
    $expiry = $start($originToken)['accessToken'];
    $inspection->prepare('UPDATE admin_sessions SET expires_at = ? WHERE token_hash = ?')->execute([time() - 1, hash('sha256', $originToken)]);
    $call('GET', 'auth/me', null, 401, $expiry);
    $originToken = $sourceLogin()['accessToken'];
    $active = $start($originToken)['accessToken'];
    $user = $adminCall('GET', 'users/' . $source['id'])['items'][0];
    $adminCall('POST', 'users/' . $source['id'] . '/status', ['version' => (int) $user['version'], 'enabled' => false]);
    $call('GET', 'auth/me', null, 401, $active);
    $adminCall('POST', 'users/' . $source['id'] . '/status', ['version' => (int) $user['version'] + 1, 'enabled' => true]);
    $originToken = $sourceLogin()['accessToken'];
    $active = $start($originToken)['accessToken'];
    $adminCall('DELETE', 'customers/' . $target['id'] . '/sessions', ['version' => 1]);
    $call('GET', 'auth/me', null, 401, $active);
    $call('GET', 'auth/me', null, 401, $real);
    $active = $start($originToken, 200, 2)['accessToken'];
    $adminCall('POST', 'customers/' . $target['id'] . '/status', ['version' => 2, 'enabled' => false]);
    $call('GET', 'auth/me', null, 401, $active);
    $start($originToken, 404, 3);
    $adminCall('POST', 'customers/' . $target['id'] . '/status', ['version' => 3, 'enabled' => true]);
    if ($driver === 'pgsql') {
        $inspection->exec("CREATE FUNCTION impersonation_failure() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF NEW.action = ''identity.impersonated'' THEN RAISE EXCEPTION ''controlled impersonation audit failure''; END IF; RETURN NEW; END'");
        $inspection->exec('CREATE TRIGGER impersonation_failure BEFORE INSERT ON admin_audit FOR EACH ROW EXECUTE FUNCTION impersonation_failure()');
    } elseif ($driver === 'mysql') {
        $inspection->exec("CREATE TRIGGER impersonation_failure BEFORE INSERT ON admin_audit FOR EACH ROW BEGIN IF NEW.action = 'identity.impersonated' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'controlled impersonation audit failure'; END IF; END");
    } else {
        $inspection->exec("CREATE TRIGGER impersonation_failure BEFORE INSERT ON admin_audit WHEN NEW.action = 'identity.impersonated' BEGIN SELECT RAISE(ABORT, 'controlled impersonation audit failure'); END");
    }
    try {
        $before = (int) $inspection->query('SELECT COUNT(*) FROM customer_sessions')->fetchColumn();
        $start($originToken, 500, 4);
        expect((int) $inspection->query('SELECT COUNT(*) FROM customer_sessions')->fetchColumn() === $before, '审计失败仍提交模拟会话');
    } finally {
        $inspection->exec('DROP TRIGGER impersonation_failure' . ($driver === 'pgsql' ? ' ON admin_audit' : ''));
        if ($driver === 'pgsql') {
            $inspection->exec('DROP FUNCTION impersonation_failure()');
        }
    }
    $request('POST', '/admin/auth/logout', $originToken, [], [], 200);
    foreach ($tenants as $index => $tenant) {
        $adminCall('POST', 'tenants/' . $tenant . '/status', ['version' => $index === 0 ? 3 : 1, 'enabled' => false]);
    }
    return ['scope_and_audit_provenance' => true, 'full_current_customer_permissions' => true, 'real_sessions_preserved' => true, 'source_and_target_revocations' => true, 'bounded_sessions' => $valid, 'exit_race_statuses' => $raced, 'audit_failure_rolled_back' => true];
}

/** 角色只经真实API创建及绑定，观察即时权限、范围、并发与失败回滚。 */
function identityRoleCases(callable $request, PDO $inspection, string $driver, string $address, string $admin, string $customer, string $password): array
{
    $tenants = [];
    foreach (['角色验收', '角色隔离'] as $name) {
        $tenants[] = $request('POST', '/admin/tenants', $admin, [], ['id' => bin2hex(random_bytes(16)), 'name' => $name, 'new_customer' => false, 'owner_login' => 'same-login'], 200)['data']['id'];
    }
    $tenant = $tenants[0];
    $headers = ['X-Tenant-Id' => $tenant];
    $call = static fn (string $method, string $path, ?array $data = null, int $status = 200, ?string $as = null, ?string $scope = null): array => $request($method, $path, $as ?? $customer, ['X-Tenant-Id' => $scope ?? $tenant], $data, $status)['data'] ?? [];
    $binding = static fn (array $row): array => ['id' => $row['id'], 'version' => (int) $row['version']];
    $role = static fn (string $id): array => $call('GET', '/customer/roles/' . $id)['items'][0];
    $login = static fn (string $name): string => $request('POST', '/customer/auth/login', '', [], ['login' => $name, 'password' => $password], 200)['data']['accessToken'];
    $catalog = $call('GET', '/customer/roles');
    expect(in_array('/roles', menuPaths($catalog['menus']), true) && !isset($catalog['catalog']['admin.roles.create']), '租户目录缺少菜单或包含平台节点');
    $highest = array_column($catalog['items'], null, 'name')['最高管理员'];
    $first = $call('POST', '/customer/roles', ['name' => '成员查询', 'permissions' => ['customer.members.read']]);
    $second = $call('POST', '/customer/roles', ['name' => '角色查询', 'permissions' => ['customer.roles.read']]);
    $other = $call('POST', '/customer/roles', ['name' => '成员查询', 'permissions' => ['customer.roles.read']], 200, null, $tenants[1]);
    expect((int) $first['enabled'] === 0 && $first['scope_id'] !== $other['scope_id'], '角色创建默认状态或租户归属错误');
    $call('POST', '/customer/roles', ['name' => '成员查询', 'permissions' => []], 409);
    foreach ([['*'], ['customer.*'], ['admin.roles.read'], ['customer.roles.read', 'customer.roles.read']] as $invalid) {
        $call('POST', '/customer/roles', ['name' => '非法节点', 'permissions' => $invalid], 422);
    }
    $call('POST', '/customer/roles', ['name' => '非法保护', 'permissions' => [], 'protected' => 1], 422);
    $worker = $call('POST', '/customer/members', ['login' => 'role-worker', 'new_customer' => true, 'account_name' => '角色验收客户', 'password' => $password, 'name' => '角色验收成员', 'roles' => [$binding($first), $binding($second)]]);
    $workerToken = $login('role-worker');
    $call('GET', '/customer/members', null, 403, $workerToken);
    $call('POST', '/customer/roles/' . $first['id'] . '/status', ['version' => 1, 'enabled' => true]);
    $call('GET', '/customer/members', null, 200, $workerToken);
    $call('GET', '/customer/roles', null, 403, $workerToken);
    $call('POST', '/customer/roles/' . $second['id'] . '/status', ['version' => 1, 'enabled' => true]);
    $effective = $call('GET', '/customer/auth/me', null, 200, $workerToken)['permissions'];
    sort($effective);
    expect($effective === ['customer.members.read', 'customer.roles.read'], '当前租户有效角色未取明确并集');
    $call('POST', '/customer/roles/' . $first['id'] . '/status', ['version' => 2, 'enabled' => false]);
    $call('GET', '/customer/members', null, 403, $workerToken);
    $call('GET', '/customer/roles', null, 200, $workerToken);
    $call('PUT', '/customer/roles/' . $second['id'] . '/permissions', ['version' => 2, 'permissions' => []]);
    $call('GET', '/customer/roles', null, 403, $workerToken);
    $call('PUT', '/customer/roles/' . $second['id'] . '/permissions', ['version' => 3, 'permissions' => ['customer.roles.read']]);
    $copy = $call('POST', '/customer/roles/' . $second['id'] . '/copy', ['version' => 4, 'name' => '查询副本']);
    expect((int) $copy['enabled'] === 0 && (int) $copy['protected'] === 0 && $copy['permissions'] === ['customer.roles.read'], '复制角色错误继承状态或缺权限');
    foreach ([['GET', '', null], ['PATCH', '', ['version' => 1, 'name' => '越界']], ['POST', '/copy', ['version' => 1, 'name' => '越界']], ['POST', '/status', ['version' => 1, 'enabled' => true]], ['PUT', '/permissions', ['version' => 1, 'permissions' => []]], ['DELETE', '', ['version' => 1]]] as [$method, $suffix, $data]) {
        $call($method, '/customer/roles/' . $other['id'] . $suffix, $data, 404);
    }
    $call('PUT', '/customer/members/roles', ['members' => [$binding($worker)], 'roles' => [$binding($other)]], 404);
    $call('GET', '/customer/roles', null, 403, $workerToken, $tenants[1]);
    $call('POST', '/customer/roles/' . $highest['id'] . '/status', ['version' => 1, 'enabled' => false], 409);
    $call('DELETE', '/customer/roles/' . $highest['id'], ['version' => 1], 409);
    $call('PUT', '/customer/roles/' . $highest['id'] . '/permissions', ['version' => 1, 'permissions' => ['identity.read']], 409);
    $limited = array_values(array_diff(array_keys($catalog['catalog']), ['customer.members.delete']));
    $delegatedRole = $call('POST', '/customer/roles', ['name' => '有限授权', 'permissions' => $limited]);
    $call('POST', '/customer/roles/' . $delegatedRole['id'] . '/status', ['version' => 1, 'enabled' => true]);
    $delegate = $call('POST', '/customer/members', ['login' => 'role-delegate', 'new_customer' => true, 'account_name' => '普通授权员', 'password' => $password, 'name' => '普通授权员', 'roles' => [['id' => $delegatedRole['id'], 'version' => 2]]]);
    $delegateToken = $login('role-delegate');
    $call('PUT', '/customer/roles/' . $second['id'] . '/permissions', ['version' => 4, 'permissions' => ['customer.members.delete']], 403, $delegateToken);
    $call('POST', '/customer/roles/' . $highest['id'] . '/copy', ['version' => 1, 'name' => '越权复制'], 403, $delegateToken);
    $powerful = $call('POST', '/customer/roles/' . $highest['id'] . '/copy', ['version' => 1, 'name' => '停用高权限']);
    foreach ([['POST', '/status', ['version' => 1, 'enabled' => true]], ['PATCH', '', ['version' => 1, 'name' => '接管角色']], ['DELETE', '', ['version' => 1]]] as [$method, $suffix, $data]) {
        $call($method, '/customer/roles/' . $powerful['id'] . $suffix, $data, 403, $delegateToken);
    }
    $call('PUT', '/customer/members/roles', ['members' => [$binding($worker)], 'roles' => [$binding($powerful)]], 403, $delegateToken);
    $versionRace = identityCompete($inspection, $driver, $address, [
        ['PATCH', '/customer/roles/' . $copy['id'], $customer, ['version' => 1, 'name' => '角色竞争甲'], $headers],
        ['PATCH', '/customer/roles/' . $copy['id'], $customer, ['version' => 1, 'name' => '角色竞争乙'], $headers],
    ]);
    $sorted = $versionRace;
    sort($sorted);
    expect($sorted === [200, 409], '角色并发版本未拒绝陈旧更新');
    $before = $role($second['id']);
    if ($driver === 'pgsql') {
        $inspection->exec("CREATE FUNCTION role_test_failure() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF NEW.action = ''customer.roles.delete'' THEN RAISE EXCEPTION ''controlled role audit failure''; END IF; RETURN NEW; END'");
        $inspection->exec('CREATE TRIGGER role_test_failure BEFORE INSERT ON customer_audit FOR EACH ROW EXECUTE FUNCTION role_test_failure()');
    } elseif ($driver === 'mysql') {
        $inspection->exec("CREATE TRIGGER role_test_failure BEFORE INSERT ON customer_audit FOR EACH ROW BEGIN IF NEW.action = 'customer.roles.delete' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'controlled role audit failure'; END IF; END");
    } else {
        $inspection->exec("CREATE TRIGGER role_test_failure BEFORE INSERT ON customer_audit WHEN NEW.action = 'customer.roles.delete' BEGIN SELECT RAISE(ABORT, 'controlled role audit failure'); END");
    }
    try {
        $call('DELETE', '/customer/roles/' . $second['id'], ['version' => 4], 500);
        expect($role($second['id']) === $before && (int) $call('GET', '/customer/members/' . $worker['id'])['items'][0]['version'] === 2, '角色删除审计失败未回滚关系和成员版本');
        $call('GET', '/customer/roles', null, 200, $workerToken);
    } finally {
        $inspection->exec('DROP TRIGGER role_test_failure' . ($driver === 'pgsql' ? ' ON customer_audit' : ''));
        if ($driver === 'pgsql') {
            $inspection->exec('DROP FUNCTION role_test_failure()');
        }
    }
    $deleteRace = identityCompete($inspection, $driver, $address, [
        ['DELETE', '/customer/roles/' . $second['id'], $customer, ['version' => 4], $headers],
        ['POST', '/customer/roles/' . $second['id'] . '/status', $customer, ['version' => 4, 'enabled' => false], $headers],
    ]);
    $sorted = $deleteRace;
    sort($sorted);
    expect(in_array($sorted, [[200, 404], [200, 409]], true), '并发删除和停用留下两个成功变更');
    $current = $call('GET', '/customer/roles?search=' . rawurlencode('角色查询'))['items'];
    if ($current !== []) {
        $call('DELETE', '/customer/roles/' . $second['id'], ['version' => (int) $current[0]['version']]);
    }
    $after = $call('GET', '/customer/members/' . $worker['id'])['items'][0];
    expect((int) $after['version'] === 3 && !in_array($second['id'], array_column($after['roles'], 'id'), true), '角色删除未清除关系或推进成员版本');
    $call('GET', '/customer/roles', null, 403, $workerToken);
    $call('PUT', '/customer/members/roles', ['members' => [$binding($worker)], 'roles' => []], 409);
    $initial = array_column($catalog['items'], null, 'name')['操作员'];
    $call('PATCH', '/customer/roles/' . $initial['id'], ['version' => 1, 'name' => '可管理的初始角色']);
    $call('DELETE', '/customer/roles/' . $initial['id'], ['version' => 2]);
    $owner = $call('GET', '/customer/members?search=same-login')['items'][0];
    $peer = $call('POST', '/customer/members', ['login' => 'role-highest-peer', 'new_customer' => true, 'account_name' => '另一最高管理员', 'password' => $password, 'name' => '另一最高管理员', 'roles' => [$binding($highest)]]);
    $peerToken = $login('role-highest-peer');
    $highestRace = identityCompete($inspection, $driver, $address, [
        ['PUT', '/customer/members/roles', $customer, ['members' => [$binding($owner)], 'roles' => []], $headers],
        ['DELETE', '/customer/members/' . $peer['id'], $peerToken, ['version' => 2], $headers],
    ]);
    $sorted = $highestRace;
    sort($sorted);
    expect($sorted === [200, 409], '并发解绑和删除突破最后最高管理员保护');
    $audit = $inspection->prepare("SELECT tenant_id, actor_id FROM customer_audit WHERE action = 'customer.roles.create' AND subject_id = ?");
    $audit->execute([$first['id']]);
    $event = $audit->fetch(PDO::FETCH_ASSOC);
    expect($event['tenant_id'] === $tenant && $event['actor_id'] === $owner['user_id'], '角色审计丢失租户或真实操作者');
    foreach ($tenants as $scope) {
        $request('POST', '/admin/tenants/' . $scope . '/status', $admin, [], ['version' => 1, 'enabled' => false], 200);
    }
    return ['version_statuses' => $versionRace, 'delete_status_statuses' => $deleteRace, 'highest_statuses' => $highestRace, 'current_scope_union_and_revocation' => true, 'indirect_escalation_denied' => true, 'deletion_failure_rolled_back' => true];
}

/** 真实租户成员边界、批量授权、原子创建和并发保护；PDO仅用于故障注入及事实核对。 */
function identityMemberCases(callable $request, PDO $inspection, string $driver, string $address, string $admin, string $customer, string $password, string $tenant): array
{
    $headers = ['X-Tenant-Id' => $tenant];
    $call = static fn (string $method, string $path, ?array $data = null, int $status = 200, ?string $as = null, ?string $scope = null): array => $request($method, $path, $as ?? $customer, ['X-Tenant-Id' => $scope ?? $tenant], $data, $status)['data'] ?? [];
    $member = static fn (string $id): array => $call('GET', '/customer/members/' . $id)['items'][0];
    $binding = static fn (array $row): array => ['id' => $row['id'], 'version' => (int) $row['version']];
    $login = static fn (string $name): string => $request('POST', '/customer/auth/login', '', [], ['login' => $name, 'password' => $password], 200)['data']['accessToken'];
    $roles = $call('GET', '/customer/roles')['items'];
    $byName = array_column($roles, null, 'name');
    $readonly = $binding($byName['只读成员']);
    $highest = $binding($byName['最高管理员']);
    expect($byName['只读成员']['permissions'] === ['identity.read'] && $byName['操作员']['permissions'] === ['identity.read'], '初始业务角色获得了成员管理权限');
    $owner = $call('GET', '/customer/members')['items'][0];
    $call('POST', '/customer/members/' . $owner['id'] . '/status', ['version' => 1, 'enabled' => false], 409);
    $call('DELETE', '/customer/members/' . $owner['id'], ['version' => 1], 409);
    $values = ['login' => 'member-client', 'new_customer' => true, 'account_name' => '全局姓名', 'password' => $password, 'name' => '本租户姓名', 'roles' => [$readonly]];
    $created = $call('POST', '/customer/members', $values);
    $id = $created['id'];
    $token = $login('member-client');
    expect($call('GET', '/customer/auth/me', null, 200, $token)['user']['name'] === '全局姓名', '成员姓名覆盖了全局姓名');
    $call('GET', '/customer/members', null, 403, $token);
    $call('PATCH', '/customer/members/' . $id, ['version' => 2, 'name' => '非法更新'], 403, $token);
    foreach (['password' => $password, 'login' => 'renamed', 'enabled' => false, 'user_id' => $owner['user_id']] as $field => $value) {
        $call('PATCH', '/customer/members/' . $id, ['version' => 2, 'name' => '越权', $field => $value], 422);
    }
    $request('GET', '/customer/members', $admin, $headers, null, 401);
    $request('GET', '/customer/members', $customer, [], null, 403);
    $request('GET', '/customer/members', $customer, $headers + ['X-Support-Id' => str_repeat('a', 32)], null, 403);
    $request('GET', '/iot/tenants/' . $tenant . '/members', $customer, $headers, null, 404);
    $otherTenant = $request('POST', '/admin/tenants', $admin, [], ['id' => bin2hex(random_bytes(16)), 'name' => '成员另一个租户', 'new_customer' => false, 'owner_login' => 'same-login'], 200)['data']['id'];
    $otherRoles = $call('GET', '/customer/roles', null, 200, null, $otherTenant)['items'];
    $otherReadonly = $binding(array_column($otherRoles, null, 'name')['只读成员']);
    $global = $inspection->query("SELECT * FROM customer_users WHERE login = 'member-client'")->fetch(PDO::FETCH_ASSOC);
    $existing = ['login' => 'member-client', 'new_customer' => false, 'name' => '另一租户姓名', 'roles' => [$otherReadonly]];
    $joined = $call('POST', '/customer/members', $existing, 200, null, $otherTenant);
    $call('POST', '/customer/members', $existing, 409, null, $otherTenant);
    foreach (['password' => $password, 'account_name' => '不能修改全局'] as $field => $value) {
        $call('POST', '/customer/members', $existing + [$field => $value], 422, null, $otherTenant);
    }
    expect($inspection->query("SELECT * FROM customer_users WHERE login = 'member-client'")->fetch(PDO::FETCH_ASSOC) === $global, '关联已有客户改变了全局资料或凭据');
    $call('GET', '/customer/members/' . $joined['id'], null, 404);
    $call('PATCH', '/customer/members/' . $joined['id'], ['version' => 2, 'name' => '串租户'], 404);
    $call('POST', '/customer/members', array_replace($values, ['login' => 'bad-role-client', 'roles' => [$otherReadonly]]), 404);
    expect((int) $inspection->query("SELECT COUNT(*) FROM customer_users WHERE login = 'bad-role-client'")->fetchColumn() === 0, '跨租户角色失败留下孤立客户');
    $before = $member($id);
    $call('PUT', '/customer/members/roles', ['members' => [$binding($before), $binding($joined)], 'roles' => []], 404);
    expect($member($id) === $before, '跨租户批量失败未整体回滚');
    $changed = $call('PATCH', '/customer/members/' . $id, ['version' => 2, 'name' => '本租户新姓名']);
    $call('PATCH', '/customer/members/' . $id, ['version' => 2, 'name' => '旧页面'], 409);
    $call('POST', '/customer/members/' . $id . '/status', ['version' => 3, 'enabled' => false]);
    $call('GET', '/customer/auth/me', null, 403, $token);
    $call('GET', '/customer/profile', null, 200, $token, $otherTenant);
    $call('POST', '/customer/members/' . $id . '/status', ['version' => 4, 'enabled' => true]);
    $call('DELETE', '/customer/members/' . $id, ['version' => 5]);
    $call('GET', '/customer/auth/me', null, 403, $token);
    $call('GET', '/customer/profile', null, 200, $token, $otherTenant);
    expect($inspection->query("SELECT * FROM customer_users WHERE login = 'member-client'")->fetch(PDO::FETCH_ASSOC) === $global, '成员资料、启停或移除修改全局账号');
    $call('GET', '/customer/members/' . $id, null, 404);
    $again = $call('POST', '/customer/members', array_replace($existing, ['roles' => [$readonly]]));
    expect($again['id'] !== $id, '重新加入复用了旧成员身份');
    $call('PATCH', '/customer/members/' . $id, ['version' => 5, 'name' => '旧页面'], 404);
    $concurrent = identityCompete($inspection, $driver, $address, [
        ['PATCH', '/customer/members/' . $again['id'], $customer, ['version' => 2, 'name' => '竞争甲'], $headers],
        ['PATCH', '/customer/members/' . $again['id'], $customer, ['version' => 2, 'name' => '竞争乙'], $headers],
    ]);
    $sorted = $concurrent;
    sort($sorted);
    expect($sorted === [200, 409], '成员版本并发未拒绝旧写入');
    $duplicate = array_replace($values, ['login' => 'concurrent-member']);
    $unique = identityCompete($inspection, $driver, $address, [['POST', '/customer/members', $customer, $duplicate, $headers], ['POST', '/customer/members', $customer, $duplicate, $headers]]);
    $sorted = $unique;
    sort($sorted);
    expect($sorted === [200, 409], '成员并发创建没有唯一结果');
    // 普通授权者夹具具有明确子集；不能通过启用、资料或角色操作接管最高管理员。
    $delegated = $call('POST', '/customer/members', array_replace($values, ['login' => 'delegated-member']));
    $delegateRole = bin2hex(random_bytes(16));
    $inspection->prepare('INSERT INTO customer_roles (id, scope_id, name, created_at) VALUES (?, ?, ?, ?)')->execute([$delegateRole, $tenant, '限定成员管理夹具', time()]);
    foreach (['identity.read', 'customer.members.read', 'customer.members.create', 'customer.members.update', 'customer.members.status', 'customer.members.delete', 'customer.roles.assign', 'customer.roles.read'] as $permission) {
        if ($permission !== 'customer.members.delete') {
            $inspection->prepare('INSERT INTO customer_role_permissions (role_id, permission) VALUES (?, ?)')->execute([$delegateRole, $permission]);
        }
    }
    $call('PUT', '/customer/members/roles', ['members' => [$binding($delegated)], 'roles' => [['id' => $delegateRole, 'version' => 1]]]);
    $delegate = $login('delegated-member');
    $call('PATCH', '/customer/members/' . $owner['id'], ['version' => 1, 'name' => '接管'], 403, $delegate);
    $call('POST', '/customer/members/' . $owner['id'] . '/status', ['version' => 1, 'enabled' => true], 403, $delegate);
    $call('PUT', '/customer/members/roles', ['members' => [$binding($member($again['id']))], 'roles' => [$highest]], 403, $delegate);
    $call('POST', '/customer/members', array_replace($values, ['login' => 'escalated-member', 'roles' => [$highest]]), 403, $delegate);
    expect((int) $inspection->query("SELECT COUNT(*) FROM customer_users WHERE login = 'escalated-member'")->fetchColumn() === 0, '越权创建保留了账号');
    $revokedToken = $login('delegated-member');
    $before = $member($again['id']);
    $revoked = identityCompete($inspection, $driver, $address, [['PATCH', '/customer/members/' . $again['id'], $revokedToken, ['version' => (int) $before['version'], 'name' => '撤权后修改'], $headers]], static function () use ($inspection, $revokedToken): void {
        $inspection->prepare('DELETE FROM customer_sessions WHERE token_hash = ?')->execute([hash('sha256', $revokedToken)]);
    });
    expect($revoked === [401] && $member($again['id']) === $before, '等待授权锁时未重验会话');
    $counts = static fn (): array => array_map(static fn (string $table): int => (int) $inspection->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(), ['customer_users', 'customer_members', 'customer_member_roles', 'customer_audit']);
    $beforeCounts = $counts();
    if ($driver === 'pgsql') {
        $inspection->exec("CREATE FUNCTION member_test_failure() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF NEW.action = ''authorization.assigned'' THEN RAISE EXCEPTION ''controlled member audit failure''; END IF; RETURN NEW; END'");
        $inspection->exec('CREATE TRIGGER member_test_failure BEFORE INSERT ON customer_audit FOR EACH ROW EXECUTE FUNCTION member_test_failure()');
    } elseif ($driver === 'mysql') {
        $inspection->exec("CREATE TRIGGER member_test_failure BEFORE INSERT ON customer_audit FOR EACH ROW BEGIN IF NEW.action = 'authorization.assigned' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'controlled member audit failure'; END IF; END");
    } else {
        $inspection->exec("CREATE TRIGGER member_test_failure BEFORE INSERT ON customer_audit WHEN NEW.action = 'authorization.assigned' BEGIN SELECT RAISE(ABORT, 'controlled member audit failure'); END");
    }
    try {
        $call('POST', '/customer/members', array_replace($values, ['login' => 'rollback-member']), 500);
        expect($counts() === $beforeCounts, '末条角色审计失败未回滚账号、成员、授权和先前审计');
    } finally {
        $inspection->exec('DROP TRIGGER member_test_failure' . ($driver === 'pgsql' ? ' ON customer_audit' : ''));
        if ($driver === 'pgsql') {
            $inspection->exec('DROP FUNCTION member_test_failure()');
        }
    }
    $second = $call('POST', '/customer/members', array_replace($values, ['login' => 'second-highest', 'roles' => [$highest]]));
    $secondToken = $login('second-highest');
    $highestRace = identityCompete($inspection, $driver, $address, [
        ['POST', '/customer/members/' . $owner['id'] . '/status', $customer, ['version' => 1, 'enabled' => false], $headers],
        ['POST', '/customer/members/' . $second['id'] . '/status', $secondToken, ['version' => 2, 'enabled' => false], $headers],
    ]);
    $sorted = $highestRace;
    sort($sorted);
    expect($sorted === [200, 409], '并发停用突破最后最高管理员保护');
    $inspection->prepare('UPDATE customer_members SET enabled = 1 WHERE id IN (?, ?)')->execute([$owner['id'], $second['id']]);
    $events = $inspection->prepare("SELECT actor_id, tenant_id, details FROM customer_audit WHERE subject_id = ? AND action = 'customer.members.create'");
    $events->execute([$again['id']]);
    $event = $events->fetch(PDO::FETCH_ASSOC);
    expect($event['actor_id'] === $owner['user_id'] && $event['tenant_id'] === $tenant && !str_contains($event['details'], $password), '成员审计丢失当前身份或包含口令');
    $request('POST', '/admin/tenants/' . $otherTenant . '/status', $admin, [], ['version' => 1, 'enabled' => false], 200);
    return ['version_statuses' => $concurrent, 'unique_statuses' => $unique, 'highest_statuses' => $highestRace, 'revoked_in_flight' => $revoked, 'atomic_creation_and_batch_rollback' => true, 'global_account_and_other_tenants_preserved' => true];
}

/** 客户全局账号及无租户角色的本人维护；真实请求覆盖版本竞争、撤权和审计回滚。 */
function identityCustomerCases(callable $request, PDO $inspection, string $driver, string $address, string $token, string $password, string $owner): array
{
    $call = static fn (string $method, string $path, ?array $data = null, int $status = 200, ?string $as = null): array => $request($method, $path, $as ?? $token, [], $data, $status)['data'] ?? [];
    $user = static fn (string $id): array => $call('GET', '/admin/customers/' . $id)['items'][0];
    $login = static fn (string $name, string $secret): string => $call('POST', '/customer/auth/login', ['login' => $name, 'password' => $secret], 200, '')['accessToken'];
    $account = $call('POST', '/admin/customers', ['login' => 'global-client', 'name' => '客户资料', 'password' => $password]);
    $other = $call('POST', '/admin/customers', ['login' => 'other-client', 'name' => '其他客户', 'password' => $password]);
    $id = $account['id'];
    $current = $user($id);
    expect(!isset($current['roles']) && !isset($current['password_hash']) && (int) $current['version'] === 1, '客户投影不能含平台角色或凭据');
    $page = $call('GET', '/admin/customers?search=global-client&per_page=1');
    expect((int) $page['total'] === 1 && count($page['items']) === 1 && in_array('/admin/customers', menuPaths($page['menus']), true), '客户筛选和固定菜单不一致');
    $call('GET', '/admin/customers/' . str_repeat('f', 32), null, 404);
    $sessions = [$login('global-client', $password), $login('global-client', $password)];
    $me = $call('GET', '/customer/auth/me', null, 200, $sessions[0]);
    expect($me['permissions'] === [] && in_array('/profile', menuPaths($me['menus']), true), '无角色客户必须保留个人账号入口');
    $call('GET', '/admin/customers', null, 401, $sessions[0]);
    $self = ['version' => 1, 'login' => 'global-client', 'name' => '本人资料', 'current_password' => $password];
    $call('PATCH', '/customer/account', $self + ['id' => $other['id']], 422, $sessions[0]);
    $request('PATCH', '/customer/account', $sessions[0], ['X-Tenant-Id' => str_repeat('a', 32)], $self, 403);
    $request('PATCH', '/customer/account', $sessions[0], ['X-Impersonation-Id' => str_repeat('a', 32)], $self, 403);
    $call('PATCH', '/customer/account', array_replace($self, ['current_password' => 'wrong-secret']), 403, $sessions[0]);
    expect((int) $user($id)['version'] === 1, '原密码错误仍修改了资料版本');
    $call('PATCH', '/customer/account', $self + ['enabled' => false], 422, $sessions[0]);
    $call('PATCH', '/customer/account', array_replace($self, ['login' => 'other-client']), 409, $sessions[0]);
    $updated = $call('PATCH', '/customer/account', array_replace($self, ['login' => 'global-renamed']), 200, $sessions[0]);
    expect((int) $updated['version'] === 2 && $updated['name'] === '本人资料', '本人资料未提交');
    $call('PATCH', '/customer/account', $self, 409, $sessions[0]);
    $call('GET', '/customer/auth/me', null, 200, $sessions[1]);
    $call('POST', '/customer/auth/login', ['login' => 'global-client', 'password' => $password], 401, '');
    $call('POST', '/customer/account/password', ['version' => 2, 'current_password' => $password, 'password' => $password . '-self'], 200, $sessions[0]);
    foreach ($sessions as $old) {
        $call('GET', '/customer/auth/me', null, 401, $old);
    }
    $call('POST', '/customer/auth/login', ['login' => 'global-renamed', 'password' => $password], 401, '');
    $sessions = [$login('global-renamed', $password . '-self'), $login('global-renamed', $password . '-self')];
    $call('PATCH', '/admin/customers/' . $id, ['version' => 3, 'login' => 'global-renamed', 'name' => '平台改资料', 'password' => $password], 422);
    $editor = $call('POST', '/admin/users', ['login' => 'customer-editor', 'name' => '客户资料编辑员', 'password' => $password]);
    $role = $call('POST', '/admin/roles', ['name' => '客户资料管理', 'permissions' => ['admin.customers.read', 'admin.customers.update']]);
    $role = $call('POST', '/admin/roles/' . $role['id'] . '/status', ['version' => 1, 'enabled' => true]);
    $call('PUT', '/admin/users/roles', ['users' => [['id' => $editor['id'], 'version' => 1]], 'roles' => [['id' => $role['id'], 'version' => 2]]]);
    $editorToken = $call('POST', '/admin/auth/login', ['login' => 'customer-editor', 'password' => $password], 200, '')['accessToken'];
    foreach (['password' => ['password' => $password], 'status' => ['enabled' => false], 'sessions' => []] as $action => $fields) {
        $call($action === 'sessions' ? 'DELETE' : 'POST', '/admin/customers/' . $id . '/' . $action, ['version' => 3] + $fields, 403, $editorToken);
    }
    $call('PATCH', '/admin/customers/' . $id, ['version' => 3, 'login' => 'global-renamed', 'name' => '平台改资料'], 200, $editorToken);
    $call('GET', '/customer/auth/me', null, 200, $sessions[0]);
    $versionStatuses = identityCompete($inspection, $driver, $address, [
        ['PATCH', '/admin/customers/' . $id, $token, ['version' => 4, 'login' => 'global-renamed', 'name' => '平台并发']],
        ['PATCH', '/customer/account', $sessions[0], ['version' => 4, 'login' => 'global-renamed', 'name' => '本人并发', 'current_password' => $password . '-self']],
    ]);
    sort($versionStatuses);
    expect($versionStatuses === [200, 409], '平台与本人并发写入未保护版本');
    $uniqueStatuses = identityCompete($inspection, $driver, $address, [
        ['POST', '/admin/customers', $token, ['login' => 'global-unique', 'name' => '并发一', 'password' => $password]],
        ['POST', '/admin/customers', $token, ['login' => 'global-unique', 'name' => '并发二', 'password' => $password]],
    ]);
    sort($uniqueStatuses);
    expect($uniqueStatuses === [200, 409] && (int) $call('GET', '/admin/customers?search=global-unique')['total'] === 1, '并发创建同名客户未确定拒绝');
    // 客户密码和会话删除必须随真实审计失败一起回滚。
    if ($driver === 'pgsql') {
        $inspection->exec("CREATE FUNCTION customer_test_failure() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF NEW.subject_id = ''{$id}'' THEN RAISE EXCEPTION ''controlled customer audit failure''; END IF; RETURN NEW; END'");
        $inspection->exec('CREATE TRIGGER customer_test_failure BEFORE INSERT ON admin_audit FOR EACH ROW EXECUTE FUNCTION customer_test_failure()');
    } elseif ($driver === 'mysql') {
        $inspection->exec("CREATE TRIGGER customer_test_failure BEFORE INSERT ON admin_audit FOR EACH ROW BEGIN IF NEW.subject_id = '{$id}' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'controlled customer audit failure'; END IF; END");
    } else {
        $inspection->exec("CREATE TRIGGER customer_test_failure BEFORE INSERT ON admin_audit WHEN NEW.subject_id = '{$id}' BEGIN SELECT RAISE(ABORT, 'controlled customer audit failure'); END");
    }
    $before = $user($id);
    try {
        $call('POST', '/admin/customers/' . $id . '/password', ['version' => (int) $before['version'], 'password' => $password . '-reset'], 500);
        expect($user($id) === $before, '审计失败没有回滚客户版本');
        foreach ($sessions as $active) {
            $call('GET', '/customer/auth/me', null, 200, $active);
        }
        $login('global-renamed', $password . '-self');
    } finally {
        $inspection->exec('DROP TRIGGER customer_test_failure' . ($driver === 'pgsql' ? ' ON admin_audit' : ''));
        if ($driver === 'pgsql') {
            $inspection->exec('DROP FUNCTION customer_test_failure()');
        }
    }
    $call('POST', '/admin/customers/' . $id . '/password', ['version' => (int) $before['version'], 'password' => $password . '-reset']);
    foreach ($sessions as $old) {
        $call('GET', '/customer/auth/me', null, 401, $old);
    }
    $call('POST', '/customer/auth/login', ['login' => 'global-renamed', 'password' => $password . '-self'], 401, '');
    foreach (['sessions', 'status'] as $action) {
        $sessions = [$login('global-renamed', $password . '-reset'), $login('global-renamed', $password . '-reset')];
        $call($action === 'sessions' ? 'DELETE' : 'POST', '/admin/customers/' . $id . '/' . $action, ['version' => (int) $user($id)['version']] + ($action === 'status' ? ['enabled' => false] : []));
        foreach ($sessions as $old) {
            $call('GET', '/customer/auth/me', null, 401, $old);
        }
    }
    $call('POST', '/customer/auth/login', ['login' => 'global-renamed', 'password' => $password . '-reset'], 401, '');
    $call('POST', '/admin/customers/' . $id . '/status', ['version' => (int) $user($id)['version'], 'enabled' => true]);
    $active = $login('global-renamed', $password . '-reset');
    $revoked = identityCompete($inspection, $driver, $address, [['PATCH', '/customer/account', $active, ['version' => (int) $user($id)['version'], 'login' => 'global-renamed', 'name' => '不能保存', 'current_password' => $password . '-reset']]], static function () use ($inspection, $active): void {
        $inspection->prepare('DELETE FROM customer_sessions WHERE token_hash = ?')->execute([hash('sha256', $active)]);
    });
    expect($revoked === [401] && $user($id)['name'] !== '不能保存', '排队期间撤销本人会话仍能写入');
    $highest = $call('GET', '/admin/customers?search=same-login')['items'][0];
    $call('POST', '/admin/customers/' . $highest['id'] . '/status', ['version' => (int) $highest['version'], 'enabled' => false], 409);
    expect((int) $user($highest['id'])['enabled'] === 1, '全局停用使有效租户失去最高管理员');
    $active = $login('global-renamed', $password . '-reset');
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $call('POST', '/customer/account/password', ['version' => (int) $user($id)['version'], 'current_password' => 'wrong-secret', 'password' => $password], 403, $active);
    }
    $call('POST', '/customer/account/password', ['version' => (int) $user($id)['version'], 'current_password' => $password . '-reset', 'password' => $password], 403, $active);
    $call('POST', '/customer/auth/login', ['login' => 'global-renamed', 'password' => $password . '-reset'], 401, '');
    $events = $inspection->query("SELECT actor_id, action, details FROM admin_audit WHERE subject_id = '{$id}' AND result = 'success'")->fetchAll(PDO::FETCH_ASSOC);
    expect(count($events) >= 6 && $events[0]['actor_id'] !== $id, '客户全局管理没有记录平台真实来源');
    $allAudit = json_encode([$events, $inspection->query('SELECT actor_id, action, details FROM customer_audit')->fetchAll(PDO::FETCH_ASSOC)], JSON_THROW_ON_ERROR);
    expect(!str_contains($allAudit, $password) && !str_contains($allAudit, $token) && !str_contains($allAudit, 'password_hash'), '客户审计包含秘密');
    $created = array_values(array_filter($events, static fn (array $event): bool => $event['action'] === 'identity.created'));
    expect(count($created) === 1 && $created[0]['actor_id'] === $owner && json_decode($created[0]['details'], true)['context'] === 'customer', '客户创建审计丢失账号域或平台来源');
    return ['version_statuses' => $versionStatuses, 'unique_statuses' => $uniqueStatuses, 'revoked_in_flight' => $revoked, 'audit_rollback' => true, 'multisession_revocation' => true, 'self_password_limit' => true];
}

/** 从真实平台创建到客户登录、切换和失效；SQL仅核对事实或建立故障/分页边界。 */
function identityTenantCases(callable $request, PDO $inspection, string $driver, string $address, string $token, string $password, string $actor): array
{
    $call = static fn (string $method, string $path, ?array $data = null, int $status = 200): array => $request($method, $path, $token, [], $data, $status)['data'] ?? [];
    $tenant = static fn (string $id): array => $call('GET', '/admin/tenants/' . $id)['items'][0];
    $values = ['id' => bin2hex(random_bytes(16)), 'name' => '客户甲的工作区', 'new_customer' => true, 'owner_login' => 'tenant-owner', 'owner_name' => '工作区管理员', 'owner_password' => $password];
    $first = $call('POST', '/admin/tenants', $values);
    $call('POST', '/admin/tenants', $values, 409);
    $call('POST', '/admin/tenants', array_replace($values, ['id' => bin2hex(random_bytes(16))]), 409);
    $call('POST', '/admin/tenants', array_replace($values, ['id' => bin2hex(random_bytes(16)), 'enabled' => false]), 422);
    $owner = $inspection->query("SELECT id, name, password_hash, enabled, version FROM customer_users WHERE login = 'tenant-owner'")->fetch(PDO::FETCH_ASSOC);
    $second = $call('POST', '/admin/tenants', ['id' => bin2hex(random_bytes(16)), 'name' => '同一客户的另一工作区', 'new_customer' => false, 'owner_login' => 'tenant-owner']);
    expect($inspection->query("SELECT id, name, password_hash, enabled, version FROM customer_users WHERE login = 'tenant-owner'")->fetch(PDO::FETCH_ASSOC) === $owner, '关联已有账号修改了全局身份');
    $call('POST', '/admin/tenants', ['id' => bin2hex(random_bytes(16)), 'name' => '不允许', 'new_customer' => false, 'owner_login' => 'tenant-owner', 'owner_password' => $password], 422);
    $call('POST', '/admin/tenants', ['id' => bin2hex(random_bytes(16)), 'name' => '不允许', 'new_customer' => false, 'owner_login' => 'not-existing'], 422);
    $call('GET', '/admin/tenants?unknown=value', null, 422);
    $customer = $request('POST', '/customer/auth/login', '', [], ['login' => 'tenant-owner', 'password' => $password], 200)['data']['accessToken'];
    $current = static fn (string $id, int $status = 200): array => $request('GET', '/customer/auth/me', $customer, ['X-Tenant-Id' => $id], null, $status)['data'] ?? [];
    $request('GET', '/admin/tenants', $customer, [], null, 401);
    $request('GET', '/admin/tenants', $token, ['X-Tenant-Id' => $first['id']], null, 403);
    $request('GET', '/customer/tenants', $customer, ['X-Support-Id' => str_repeat('f', 32)], null, 403);
    $limited = $request('POST', '/admin/auth/login', '', [], ['login' => 'team-first', 'password' => $password . '-new'], 200)['data']['accessToken'];
    $request('GET', '/admin/tenants', $limited, [], null, 403);
    $request('POST', '/admin/tenants', $limited, [], array_replace($values, ['id' => bin2hex(random_bytes(16)), 'owner_login' => 'denied-owner']), 403);
    foreach ([$first, $second] as $workspace) {
        $selected = $current($workspace['id']);
        expect($selected['tenant']['id'] === $workspace['id'] && in_array('identity.read', $selected['permissions'], true), '新客户无法进入对应工作区');
        $roles = $inspection->prepare('SELECT name, protected FROM customer_roles WHERE scope_id = ? ORDER BY name');
        $roles->execute([$workspace['id']]);
        $seeded = $roles->fetchAll(PDO::FETCH_ASSOC);
        expect(count($seeded) === 3 && array_sum(array_column($seeded, 'protected')) === 1, '初始角色或最高管理员保护缺失');
        expect($tenant($workspace['id'])['administrators'][0]['id'] === $owner['id'], '平台详情关联了错误客户');
    }
    $current(str_repeat('f', 32), 403);
    $edited = $call('PATCH', '/admin/tenants/' . $first['id'], ['version' => 1, 'name' => '已编辑租户']);
    expect((int) $edited['version'] === 2, '租户资料未推进版本');
    $call('PATCH', '/admin/tenants/' . $first['id'], ['version' => 1, 'name' => '陈旧资料'], 409);
    $call('POST', '/admin/tenants/' . $first['id'] . '/status', ['version' => 2, 'enabled' => false]);
    $current($first['id'], 403);
    $current($second['id']);
    $mine = $request('GET', '/customer/tenants', $customer, [], null, 200)['data'];
    expect(count($mine['items']) === 1 && $mine['items'][0]['id'] === $second['id'], '停用租户仍可选择或影响了其他租户');
    expect($inspection->query("SELECT id, name, password_hash, enabled, version FROM customer_users WHERE login = 'tenant-owner'")->fetch(PDO::FETCH_ASSOC) === $owner, '停用租户改变了客户全局身份');
    $disableMember = $inspection->prepare('UPDATE customer_members SET enabled = ? WHERE tenant_id = ?');
    $highestStatuses = identityCompete($inspection, $driver, $address, [
        ['POST', '/admin/tenants/' . $first['id'] . '/status', $token, ['version' => 3, 'enabled' => true]],
    ], static function () use ($disableMember, $first): void {
        $disableMember->execute([0, $first['id']]);
    });
    expect($highestStatuses === [409], '等待授权锁期间最高管理员失效仍启用了租户');
    $call('POST', '/admin/tenants/' . $first['id'] . '/status', ['version' => 3, 'enabled' => true], 409);
    expect((int) $tenant($first['id'])['enabled'] === 0 && (int) $tenant($first['id'])['version'] === 3, '没有有效最高管理员仍启用了租户');
    $disableMember->execute([1, $first['id']]);
    $call('POST', '/admin/tenants/' . $first['id'] . '/status', ['version' => 3, 'enabled' => true]);
    $concurrent = identityCompete($inspection, $driver, $address, [
        ['PATCH', '/admin/tenants/' . $first['id'], $token, ['version' => 4, 'name' => '竞争资料甲']],
        ['PATCH', '/admin/tenants/' . $first['id'], $token, ['version' => 4, 'name' => '竞争资料乙']],
    ]);
    $sorted = $concurrent;
    sort($sorted);
    expect($sorted === [200, 409], '租户并发版本冲突未拒绝');
    $duplicate = array_replace($values, ['id' => bin2hex(random_bytes(16)), 'name' => '并发创建', 'owner_login' => 'concurrent-tenant-owner']);
    $created = identityCompete($inspection, $driver, $address, [['POST', '/admin/tenants', $token, $duplicate], ['POST', '/admin/tenants', $token, $duplicate]]);
    $sorted = $created;
    sort($sorted);
    expect($sorted === [200, 409], '租户并发重复创建没有确定拒绝');
    expect(count($tenant($duplicate['id'])['administrators']) === 1, '并发创建留下了不完整的管理员绑定');
    $counts = static function () use ($inspection): array {
        $result = [];
        foreach (['iot_tenants', 'customer_users', 'customer_members', 'customer_roles', 'customer_role_permissions', 'customer_member_roles', 'admin_audit', 'customer_audit'] as $table) {
            $result[$table] = (int) $inspection->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        } return $result;
    };
    $before = $counts();
    if ($driver === 'pgsql') {
        $inspection->exec("CREATE FUNCTION tenant_test_failure() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF NEW.action = ''admin.tenants.create'' THEN RAISE EXCEPTION ''controlled tenant audit failure''; END IF; RETURN NEW; END'");
        $inspection->exec('CREATE TRIGGER tenant_test_failure BEFORE INSERT ON admin_audit FOR EACH ROW EXECUTE FUNCTION tenant_test_failure()');
    } elseif ($driver === 'mysql') {
        $inspection->exec("CREATE TRIGGER tenant_test_failure BEFORE INSERT ON admin_audit FOR EACH ROW BEGIN IF NEW.action = 'admin.tenants.create' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'controlled tenant audit failure'; END IF; END");
    } else {
        $inspection->exec("CREATE TRIGGER tenant_test_failure BEFORE INSERT ON admin_audit WHEN NEW.action = 'admin.tenants.create' BEGIN SELECT RAISE(ABORT, 'controlled tenant audit failure'); END");
    }
    try {
        $call('POST', '/admin/tenants', array_replace($values, ['id' => bin2hex(random_bytes(16)), 'owner_login' => 'rollback-tenant-owner']), 500);
        expect($counts() === $before, '租户创建失败留下了孤立账号、成员、角色或成功审计');
    } finally {
        $inspection->exec('DROP TRIGGER tenant_test_failure' . ($driver === 'pgsql' ? ' ON admin_audit' : ''));
        if ($driver === 'pgsql') {
            $inspection->exec('DROP FUNCTION tenant_test_failure()');
        }
    }
    $request('POST', '/customer/auth/login', '', [], ['login' => 'rollback-tenant-owner', 'password' => $password], 401);
    // 101份仅含成员的分页夹具验证选择资格不受首100条截断；不以此证明租户创建或角色初始化。
    $fixtures = [];
    $insertTenant = $inspection->prepare('INSERT INTO iot_tenants (id, name, created_at) VALUES (?, ?, ?)');
    $insertMember = $inspection->prepare('INSERT INTO customer_members (id, tenant_id, user_id, created_at) VALUES (?, ?, ?, ?)');
    try {
        for ($index = 1; $index <= 101; $index++) {
            $id = str_pad(dechex($index), 32, '0', STR_PAD_LEFT);
            $fixtures[] = $id;
            $insertTenant->execute([$id, '分页边界' . $index, time()]);
            $insertMember->execute([bin2hex(random_bytes(16)), $id, $owner['id'], time()]);
        }
        $selected = $current($second['id']);
        expect(count($selected['tenants']) === 100 && !in_array($second['id'], array_column($selected['tenants'], 'id'), true) && $selected['tenant']['id'] === $second['id'], '第100条以后的有效工作区无法选择');
        $page = $request('GET', '/customer/tenants?page=2&per_page=100', $customer, [], null, 200)['data'];
        expect((int) $page['total'] === 103 && count($page['items']) === 3, '客户工作区分页丢失成员关系');
    } finally {
        foreach ($fixtures as $id) {
            $inspection->prepare('DELETE FROM customer_members WHERE tenant_id = ?')->execute([$id]);
            $inspection->prepare('DELETE FROM iot_tenants WHERE id = ?')->execute([$id]);
        }
    }
    $audit = $inspection->prepare("SELECT actor_id, tenant_id FROM admin_audit WHERE action = 'admin.tenants.create' AND subject_id = ?");
    $audit->execute([$first['id']]);
    $event = $audit->fetch(PDO::FETCH_ASSOC);
    expect($event['actor_id'] === $actor && $event['tenant_id'] === $first['id'], '租户创建审计丢失真实平台来源');
    // 平台直接维护客户最高管理员：新增、原子替换、仅解绑、最后管理员保护和跨租户隔离。
    $adminDetail = $call('GET', '/admin/tenants/' . $first['id']);
    expect(array_key_exists('admin.tenants.administrators.manage', $adminDetail['catalog']), '平台目录缺少独立客户最高管理员权限');
    $globalBefore = $inspection->query("SELECT id, name, password_hash, enabled, version FROM customer_users WHERE login = 'tenant-owner'")->fetch(PDO::FETCH_ASSOC);
    $added = $call('POST', '/admin/customers', ['login' => 'tenant-managed', 'name' => '平台新增管理员', 'password' => $password]);
    $currentTenant = $tenant($first['id']);
    $call('POST', '/admin/tenants/' . $first['id'] . '/administrators', ['version' => (int) $currentTenant['version'], 'login' => 'tenant-managed', 'new_customer' => false]);
    $currentTenant = $tenant($first['id']);
    $addedAdmin = array_values(array_filter($currentTenant['administrators'], static fn (array $item): bool => $item['login'] === 'tenant-managed'))[0] ?? null;
    expect($addedAdmin !== null && $addedAdmin['id'] === $added['id'], '平台新增管理员没有建立准确成员关系');
    $currentTenant = $tenant($first['id']);
    $call('POST', '/admin/tenants/' . $first['id'] . '/administrators', ['version' => (int) $currentTenant['version'], 'login' => 'tenant-created-admin', 'new_customer' => true, 'owner_name' => '平台创建管理员', 'owner_password' => $password]);
    $currentTenant = $tenant($first['id']);
    $createdAdmin = array_values(array_filter($currentTenant['administrators'], static fn (array $item): bool => $item['login'] === 'tenant-created-admin'))[0] ?? null;
    expect($createdAdmin !== null, '平台新增客户账号没有建立最高管理员关系');
    $managedToken = $request('POST', '/customer/auth/login', '', [], ['login' => 'tenant-managed', 'password' => $password], 200)['data']['accessToken'];
    $managedContext = $request('GET', '/customer/auth/me', $managedToken, ['X-Tenant-Id' => $first['id']], null, 200)['data'];
    expect(in_array('customer.members.create', $managedContext['permissions'], true), '新增客户最高管理员后未取得当前租户权限');
    $role = $request('POST', '/customer/roles', $customer, ['X-Tenant-Id' => $first['id']], ['name' => '保留的其他角色', 'permissions' => ['identity.read', 'customer.roles.read']], 200)['data'];
    $role = $request('POST', '/customer/roles/' . $role['id'] . '/status', $customer, ['X-Tenant-Id' => $first['id']], ['version' => 1, 'enabled' => true], 200)['data'];
    $tenantRoles = $request('GET', '/customer/roles', $customer, ['X-Tenant-Id' => $first['id']], null, 200)['data']['items'];
    $highestRole = array_values(array_filter($tenantRoles, static fn (array $item): bool => $item['name'] === '最高管理员'))[0] ?? null;
    expect($highestRole !== null, '租户角色目录缺少最高管理员');
    $ownerMember = $request('GET', '/customer/members?search=tenant-owner', $customer, ['X-Tenant-Id' => $first['id']], null, 200)['data']['items'][0];
    $request('PUT', '/customer/members/roles', $customer, ['X-Tenant-Id' => $first['id']], ['members' => [['id' => $ownerMember['id'], 'version' => (int) $ownerMember['version']]], 'roles' => [['id' => $highestRole['id'], 'version' => (int) $highestRole['version']], ['id' => $role['id'], 'version' => (int) $role['version']]]], 200);
    expect(count(array_filter($tenant($second['id'])['administrators'], static fn (array $item): bool => $item['login'] === 'tenant-owner')) === 1, '客户角色分配误删了同一账号其他租户的角色绑定');
    $currentTenant = $tenant($first['id']);
    $ownerAdmin = array_values(array_filter($currentTenant['administrators'], static fn (array $item): bool => $item['login'] === 'tenant-owner'))[0] ?? null;
    expect($ownerAdmin !== null, '租户详情缺少原始最高管理员成员版本');
    $replacement = $call('POST', '/admin/customers', ['login' => 'tenant-replacement', 'name' => '平台替换管理员', 'password' => $password]);
    $currentTenant = $tenant($first['id']);
    $call('PUT', '/admin/tenants/' . $first['id'] . '/administrators', ['version' => (int) $currentTenant['version'], 'login' => 'tenant-replacement', 'new_customer' => false, 'replace_member_id' => $ownerAdmin['member_id'], 'replace_member_version' => (int) $ownerAdmin['member_version']]);
    $currentTenant = $tenant($first['id']);
    $replacementAdmin = array_values(array_filter($currentTenant['administrators'], static fn (array $item): bool => $item['login'] === 'tenant-replacement'))[0] ?? null;
    expect($replacementAdmin !== null && count(array_filter($currentTenant['administrators'], static fn (array $item): bool => $item['login'] === 'tenant-owner')) === 0, '原子替换没有准确调整最高管理员绑定');
    $oldContext = $request('GET', '/customer/auth/me', $customer, ['X-Tenant-Id' => $first['id']], null, 200)['data'];
    expect(!in_array('customer.members.create', $oldContext['permissions'], true), '被替换成员仍持有最高管理员权限');
    $ownerDetail = $request('GET', '/customer/members/' . $ownerAdmin['member_id'], $managedToken, ['X-Tenant-Id' => $first['id']], null, 200)['data']['items'][0];
    expect(in_array($role['id'], array_column($ownerDetail['roles'], 'id'), true), '替换最高管理员错误删除成员的其他角色');
    expect($inspection->query("SELECT id, name, password_hash, enabled, version FROM customer_users WHERE login = 'tenant-owner'")->fetch(PDO::FETCH_ASSOC) === $globalBefore, '平台更换最高管理员修改了客户全局账号');
    $currentTenant = $tenant($first['id']);
    $call('DELETE', '/admin/tenants/' . $first['id'] . '/administrators/' . $replacementAdmin['member_id'], ['version' => (int) $currentTenant['version'], 'member_version' => (int) $replacementAdmin['member_version']]);
    $currentTenant = $tenant($first['id']);
    expect(count(array_filter($currentTenant['administrators'], static fn (array $item): bool => $item['login'] === 'tenant-replacement')) === 0, '平台移除最高管理员未生效');
    $call('POST', '/admin/tenants/' . $first['id'] . '/administrators', ['version' => (int) $currentTenant['version'], 'login' => 'tenant-owner', 'new_customer' => false]);
    $currentTenant = $tenant($first['id']);
    $addedAdmin = array_values(array_filter($currentTenant['administrators'], static fn (array $item): bool => $item['login'] === 'tenant-managed'))[0] ?? null;
    $call('DELETE', '/admin/tenants/' . $first['id'] . '/administrators/' . $addedAdmin['member_id'], ['version' => (int) $currentTenant['version'], 'member_version' => (int) $addedAdmin['member_version']]);
    $currentTenant = $tenant($first['id']);
    $call('DELETE', '/admin/tenants/' . $first['id'] . '/administrators/' . $createdAdmin['member_id'], ['version' => (int) $currentTenant['version'], 'member_version' => (int) $createdAdmin['member_version']]);
    $currentTenant = $tenant($first['id']);
    $ownerAdmin = array_values(array_filter($currentTenant['administrators'], static fn (array $item): bool => $item['login'] === 'tenant-owner'))[0] ?? null;
    $call('DELETE', '/admin/tenants/' . $first['id'] . '/administrators/' . $ownerAdmin['member_id'], ['version' => (int) $currentTenant['version'], 'member_version' => (int) $ownerAdmin['member_version']], 409);
    $request('POST', '/admin/tenants/' . $first['id'] . '/administrators', $limited, [], ['version' => (int) $currentTenant['version'], 'login' => 'tenant-replacement', 'new_customer' => false], 403);
    $audit = $inspection->prepare("SELECT actor_id, tenant_id, subject_id FROM admin_audit WHERE action = 'admin.tenants.administrators.replace' AND tenant_id = ? ORDER BY created_at DESC LIMIT 1");
    $audit->execute([$first['id']]);
    $administratorAudit = $audit->fetch(PDO::FETCH_ASSOC);
    expect($administratorAudit['actor_id'] === $actor && $administratorAudit['tenant_id'] === $first['id'] && $administratorAudit['subject_id'] === $replacementAdmin['member_id'], '客户最高管理员审计缺少真实平台来源或目标成员');
    return ['version_statuses' => $concurrent, 'duplicate_statuses' => $created, 'highest_revoked_in_flight' => $highestStatuses, 'atomic_creation_rollback' => true, 'selection_beyond_100' => true, 'administrator_management' => true];
}

/** 只通过真实平台 HTTP 维护授权；PDO 用于失败注入、竞争屏障和最终事实核对。 */
function identityAdminCases(callable $request, PDO $inspection, string $driver, string $address, string $token, string $password, string $owner): array
{
    $call = static fn (string $method, string $path, ?array $data = null, int $status = 200, ?string $as = null): array => $request($method, $path, $as ?? $token, [], $data, $status)['data'] ?? [];
    $user = static fn (string $id): array => $call('GET', '/admin/users/' . $id)['items'][0];
    $role = static fn (string $id): array => $call('GET', '/admin/roles/' . $id)['items'][0];
    $rolePage = $call('GET', '/admin/roles');
    $highest = $rolePage['items'][0];
    $catalog = array_keys($rolePage['catalog']);
    expect(in_array('admin.roles.assign', $catalog, true) && in_array('/admin/tenants', menuPaths($rolePage['menus']), true), '平台固定节点与租户菜单不一致');
    $create = static fn (string $login): array => $call('POST', '/admin/users', ['login' => $login, 'name' => $login, 'password' => $password]);
    $bind = static fn (array $users, array $roles, int $status = 200, ?string $as = null): array => $call('PUT', '/admin/users/roles', [
        'users' => array_map(static fn (array $item): array => ['id' => $item['id'], 'version' => (int) $item['version']], $users),
        'roles' => array_map(static fn (array $item): array => ['id' => $item['id'], 'version' => (int) $item['version']], $roles),
    ], $status, $as);
    $compete = static fn (array $requests, ?Closure $beforeRelease = null): array => identityCompete($inspection, $driver, $address, $requests, $beforeRelease);
    $newRole = static function (string $name, array $permissions) use ($call): array {
        $created = $call('POST', '/admin/roles', ['name' => $name, 'permissions' => $permissions]);
        expect((int) $created['enabled'] === 0 && (int) $created['protected'] === 0, '新角色不能复制保护标记或隐式启用');
        return $call('POST', '/admin/roles/' . $created['id'] . '/status', ['version' => 1, 'enabled' => true]);
    };
    $first = $create('team-first');
    $second = $create('team-second');
    $delegate = $create('team-delegate');
    $first = $user($first['id']);
    $second = $user($second['id']);
    $delegate = $user($delegate['id']);
    $call('POST', '/admin/users', ['login' => 'team-first', 'name' => '重复', 'password' => $password], 409);
    $edited = $call('PATCH', '/admin/users/' . $first['id'], ['version' => 1, 'login' => 'team-first', 'name' => '新姓名']);
    expect((int) $edited['version'] === 2 && $edited['name'] === '新姓名', '资料修改没有推进版本');
    $call('PATCH', '/admin/users/' . $first['id'], ['version' => 1, 'login' => 'team-first', 'name' => '旧表单'], 409);
    $call('PATCH', '/admin/users/' . $first['id'], ['version' => 2, 'login' => 'team-first', 'name' => '资料', 'password' => $password], 422);
    $readRole = $newRole('人员查询', ['identity.read', 'admin.users.read']);
    $extraRole = $newRole('角色查询', ['admin.roles.read']);
    $delegated = $newRole('受限授权员', array_values(array_diff($catalog, ['admin.users.sessions'])));
    $highRole = $newRole('会话管理', ['admin.users.sessions']);
    $bind([$user($first['id']), $user($second['id'])], [$readRole, $extraRole]);
    $bind([$delegate], [$delegated]);
    $firstToken = $call('POST', '/admin/auth/login', ['login' => 'team-first', 'password' => $password], 200, '')['accessToken'];
    $delegateToken = $call('POST', '/admin/auth/login', ['login' => 'team-delegate', 'password' => $password], 200, '')['accessToken'];
    $effective = $call('GET', '/admin/auth/me', null, 200, $firstToken);
    expect(count($effective['permissions']) === 3 && count(menuLeafPaths($effective['menus'])) === 3 && in_array('/admin/identity', menuPaths($effective['menus']), true), '启用角色没有按当前作用域取并集或菜单分组');
    $call('POST', '/admin/users', ['login' => 'forbidden', 'name' => '不允许', 'password' => $password], 403, $firstToken);
    $call('DELETE', '/admin/users/' . $first['id'] . '/sessions', ['version' => (int) $user($first['id'])['version']], 403, $delegateToken);
    $call('POST', '/admin/roles', ['name' => '非法节点', 'permissions' => ['admin.*']], 422);
    $call('POST', '/admin/roles', ['name' => '越权角色', 'permissions' => ['admin.users.sessions']], 403, $delegateToken);
    $call('POST', '/admin/roles/' . $highest['id'] . '/copy', ['version' => 1, 'name' => '越权复制'], 403, $delegateToken);
    $call('PUT', '/admin/roles/' . $readRole['id'] . '/permissions', ['version' => 2, 'permissions' => ['identity.read', 'admin.users.sessions']], 403, $delegateToken);
    $call('POST', '/admin/roles/' . $highRole['id'] . '/status', ['version' => 2, 'enabled' => false]);
    $call('POST', '/admin/roles/' . $highRole['id'] . '/status', ['version' => 3, 'enabled' => true], 403, $delegateToken);
    $call('POST', '/admin/roles/' . $highRole['id'] . '/copy', ['version' => 3, 'name' => '停用复制旁路'], 403, $delegateToken);
    $bind([$user($second['id'])], [$role($highRole['id'])]);
    $call('POST', '/admin/users/' . $second['id'] . '/password', ['version' => (int) $user($second['id'])['version'], 'password' => $password . '-new'], 403, $delegateToken);
    $beforeFirst = $user($first['id']);
    $beforeSecond = $user($second['id']);
    $bind([$beforeFirst, $beforeSecond], [$readRole], 403, $delegateToken);
    expect($user($first['id'])['roles'] === $beforeFirst['roles'], '失败批量授权留下部分修改');
    $bind([$beforeFirst], [$role($highRole['id'])], 403, $delegateToken);
    $bind([$beforeFirst], [$readRole]);
    $beforeFirst = $user($first['id']);
    $beforeSecond = $user($second['id']);
    $faultSubject = $second['id'];
    if ($driver === 'pgsql') {
        $inspection->exec("CREATE FUNCTION app_test_batch_failure() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF NEW.subject_id = ''{$faultSubject}'' THEN RAISE EXCEPTION ''controlled batch audit failure''; END IF; RETURN NEW; END'");
        $inspection->exec('CREATE TRIGGER app_test_batch_failure BEFORE INSERT ON admin_audit FOR EACH ROW EXECUTE FUNCTION app_test_batch_failure()');
    } elseif ($driver === 'mysql') {
        $inspection->exec("CREATE TRIGGER app_test_batch_failure BEFORE INSERT ON admin_audit FOR EACH ROW BEGIN IF NEW.subject_id = '{$faultSubject}' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'controlled batch audit failure'; END IF; END");
    } else {
        $inspection->exec("CREATE TRIGGER app_test_batch_failure BEFORE INSERT ON admin_audit WHEN NEW.subject_id = '{$faultSubject}' BEGIN SELECT RAISE(ABORT, 'controlled batch audit failure'); END");
    }
    $auditBefore = (int) $inspection->query('SELECT COUNT(*) FROM admin_audit')->fetchColumn();
    try {
        $bind([$beforeFirst, $beforeSecond], [$extraRole], 500);
        expect($user($first['id']) === $beforeFirst && $user($second['id']) === $beforeSecond, '第二人审计失败未回滚整批版本和绑定');
        expect((int) $inspection->query('SELECT COUNT(*) FROM admin_audit')->fetchColumn() === $auditBefore, '失败批次留下成功审计');
    } finally {
        $inspection->exec('DROP TRIGGER app_test_batch_failure' . ($driver === 'pgsql' ? ' ON admin_audit' : ''));
        if ($driver === 'pgsql') {
            $inspection->exec('DROP FUNCTION app_test_batch_failure()');
        }
    }
    $conflictRequests = [];
    foreach (['并发资料一', '并发资料二'] as $newName) {
        $conflictRequests[] = ['PATCH', '/admin/users/' . $first['id'], $token, ['version' => (int) $beforeFirst['version'], 'login' => $beforeFirst['login'], 'name' => $newName]];
    }
    $versionStatuses = $compete($conflictRequests);
    sort($versionStatuses);
    expect($versionStatuses === [200, 409], '并发版本冲突必须只提交一次');
    $staleRole = $readRole;
    $staleRole['version'] = 1;
    $bind([$user($first['id'])], [$staleRole], 409);
    $copy = $call('POST', '/admin/roles/' . $readRole['id'] . '/copy', ['version' => 2, 'name' => '查询副本']);
    expect((int) $copy['enabled'] === 0 && $copy['permissions'] === $readRole['permissions'], '角色复制未保留权限或错误复制启用状态');
    $copy = $call('PATCH', '/admin/roles/' . $copy['id'], ['version' => 1, 'name' => '查询副本修改']);
    $copy = $call('PUT', '/admin/roles/' . $copy['id'] . '/permissions', ['version' => 2, 'permissions' => ['identity.read']]);
    $copy = $call('POST', '/admin/roles/' . $copy['id'] . '/status', ['version' => 3, 'enabled' => true]);
    $bind([$user($first['id'])], [$copy]);
    $call('GET', '/admin/users', null, 403, $firstToken);
    $call('DELETE', '/admin/roles/' . $copy['id'], ['version' => 4]);
    expect($user($first['id'])['roles'] === [], '删除角色留下悬空人员绑定');
    foreach (['DELETE' => '', 'POST' => '/status', 'PUT' => '/permissions'] as $method => $suffix) {
        $call($method, '/admin/roles/' . $highest['id'] . $suffix, ['version' => 1] + ($suffix === '/status' ? ['enabled' => false] : ($suffix === '/permissions' ? ['permissions' => []] : [])), 409);
    }
    $bind([$user($owner)], [], 409);
    $call('POST', '/admin/users/' . $owner . '/status', ['version' => (int) $user($owner)['version'], 'enabled' => false], 409);
    $bind([$user($first['id'])], [$readRole]);
    $current = $user($first['id']);
    $call('POST', '/admin/users/' . $first['id'] . '/password', ['version' => (int) $current['version'], 'password' => $password . '-new']);
    $call('GET', '/admin/auth/me', null, 401, $firstToken);
    $call('POST', '/admin/auth/login', ['login' => 'team-first', 'password' => $password], 401, '');
    $newToken = $call('POST', '/admin/auth/login', ['login' => 'team-first', 'password' => $password . '-new'], 200, '')['accessToken'];
    $call('DELETE', '/admin/users/' . $first['id'] . '/sessions', ['version' => (int) $user($first['id'])['version']]);
    $call('GET', '/admin/auth/me', null, 401, $newToken);
    $call('POST', '/admin/users/' . $first['id'] . '/status', ['version' => (int) $user($first['id'])['version'], 'enabled' => false]);
    $call('POST', '/admin/auth/login', ['login' => 'team-first', 'password' => $password . '-new'], 401, '');
    $call('POST', '/admin/users/' . $first['id'] . '/status', ['version' => (int) $user($first['id'])['version'], 'enabled' => true]);
    $disabled = $call('POST', '/admin/roles/' . $readRole['id'] . '/status', ['version' => 2, 'enabled' => false]);
    $newToken = $call('POST', '/admin/auth/login', ['login' => 'team-first', 'password' => $password . '-new'], 200, '')['accessToken'];
    expect($call('GET', '/admin/auth/me', null, 200, $newToken)['permissions'] === [], '角色停用没有影响旧会话');
    $call('POST', '/admin/roles/' . $readRole['id'] . '/status', ['version' => (int) $disabled['version'], 'enabled' => true]);
    $revokedStatuses = $compete([['POST', '/admin/users', $delegateToken, ['login' => 'revoked-in-flight', 'name' => '不能创建', 'password' => $password]]], static function () use ($inspection, $delegateToken): void {
        $inspection->prepare('DELETE FROM admin_sessions WHERE token_hash = ?')->execute([hash('sha256', $delegateToken)]);
    });
    expect($revokedStatuses === [401] && $call('GET', '/admin/users?search=revoked-in-flight')['total'] === 0, '排队期间撤销会话仍执行了授权写入');
    $secondOwner = $create('team-owner');
    $secondOwner = $user($secondOwner['id']);
    $bind([$secondOwner], [$highest]);
    $ownerToken = $call('POST', '/admin/auth/login', ['login' => 'team-owner', 'password' => $password], 200, '')['accessToken'];
    $ownerRequests = [];
    foreach ([[$user($owner), $token], [$user($secondOwner['id']), $ownerToken]] as [$target, $credential]) {
        $ownerRequests[] = ['POST', '/admin/users/' . $target['id'] . '/status', $credential, ['version' => (int) $target['version'], 'enabled' => false]];
    }
    $statuses = $compete($ownerRequests);
    $sorted = $statuses;
    sort($sorted);
    expect($sorted === [200, 409], '并发停用必须只允许一位最高管理员成功');
    if ($statuses[0] === 200) {
        $current = $call('GET', '/admin/users/' . $owner, null, 200, $ownerToken)['items'][0];
        $call('POST', '/admin/users/' . $owner . '/status', ['version' => (int) $current['version'], 'enabled' => true], 200, $ownerToken);
        $token = $call('POST', '/admin/auth/login', ['login' => 'same-login', 'password' => $password], 200, '')['accessToken'];
    }
    // 以下调用显式用恢复后的令牌，闭包原令牌可能因上面停用而失效。
    $currentOwner = $request('GET', '/admin/users/' . $secondOwner['id'], $token, [], null, 200)['data']['items'][0];
    if ((int) $currentOwner['enabled'] === 1) {
        $request('POST', '/admin/users/' . $secondOwner['id'] . '/status', $token, [], ['version' => (int) $currentOwner['version'], 'enabled' => false], 200);
    }
    $audit = $inspection->query("SELECT actor_id, action, details FROM admin_audit WHERE actor_id <> 'provisioning'")->fetchAll(PDO::FETCH_ASSOC);
    expect(count($audit) > 20 && !str_contains(json_encode($audit), $password) && !str_contains(json_encode($audit), $token), '管理审计缺失或泄漏凭据');
    foreach ($audit as $event) {
        if ($event['action'] === 'identity.created') {
            expect($event['actor_id'] === $owner && json_decode($event['details'], true)['reason'] === 'authorized-account', '页面创建人员的审计应记录真实授权员和来源');
        }
    }
    return ['token' => $token, 'concurrent_statuses' => $statuses, 'version_statuses' => $versionStatuses, 'revoked_in_flight_statuses' => $revokedStatuses, 'batch_audit_rollback' => true, 'audit_records' => count($audit)];
}

/**
 * 页面装置复用真实HTTP与当前驱动，临时独立Broker使用另一数据库；仅长文本阶段和旧历史是页面夹具。
 * PHP持有服务、数据库和支持授权，Node持有浏览器；任一失败均先结束子进程再清除本轮秘密和页面数据。
 */
function identityAuditBrowser(array $command, array $environment, string $base, string $driver, string $dist, array $fixture, callable $request): array
{
    $root = dirname(__DIR__);
    $brokerBase = $base . '/audit-broker';
    expect(!file_exists($brokerBase) && mkdir($brokerBase, 0700), '无法创建本轮独立Broker浏览器根');
    $brokerEnvironment = $environment;
    $brokerEnvironment['APP_BASE_PATH'] = $brokerBase;
    $brokerEnvironment['DB_SQLITE_FILE'] = 'audit-broker.sqlite';
    unset($brokerEnvironment['APP_API_TOKEN'], $brokerEnvironment['IOT_MQTT_COMMAND']);
    $iotDsn = $driver === 'sqlite' ? 'sqlite:' . $base . '/identity.sqlite' : $driver . ':host=' . $environment['DB_HOST'] . ';port=' . $environment['DB_PORT'] . ';dbname=' . $environment['DB_DATABASE'];
    $iotDatabase = null;
    $brokerDatabase = null;
    $brokerServer = null;
    $browser = null;
    $grant = null;
    $operationId = null;
    $databaseName = '';
    $databaseCreated = false;
    $fixturePath = $base . '/audit-browser-fixture.json';
    $tenant = $fixture['tenants']['primary']['id'];
    $tenantPath = '/iot/tenants/' . $tenant;
    $token = $fixture['tokens']['tenant'];
    $secrets = [$token, ...array_column($fixture['accounts'], 'password')];
    $report = ['status' => 'running', 'fixture_sources' => ['identity_and_resource_events' => 'real_http',
        'legacy_event' => 'database_page_fixture', 'different_async_stages_and_long_text' => 'database_page_fixture_only_not_state_machine_evidence'],
        'cleanup' => ['browser' => false, 'broker_http' => false, 'broker_database' => false, 'support' => false, 'fixture_secret' => false, 'async_page_fixture' => false]];
    try {
        $iotDatabase = new PDO($iotDsn, $environment['DB_USERNAME'] ?? null, $environment['DB_PASSWORD'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if ($driver !== 'sqlite') {
            $databaseName = 'type_audit_browser_' . bin2hex(random_bytes(6));
            $iotDatabase->exec('CREATE DATABASE ' . $databaseName);
            $databaseCreated = true;
            $brokerEnvironment['DB_DATABASE'] = $databaseName;
        }
        $install = new Process([...$command, 'broker:install'], $root, $brokerEnvironment);
        try {
            $installed = $install->wait(30);
            expect($installed->successful(), '浏览器独立Broker安装失败：' . $installed->stdout . $installed->stderr);
        } finally {
            $install->stop();
        }
        $account = identityCommand(
            [...$command, 'broker:user', $fixture['accounts']['standalone']['login'], '审计浏览器管理员'],
            $brokerEnvironment + ['BROKER_ADMIN_PASSWORD' => $fixture['accounts']['standalone']['password']]
        )['data'];
        $listener = stream_socket_server('tcp://127.0.0.1:0', $number, $message);
        expect(is_resource($listener), '无法选择审计浏览器Broker端口');
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $brokerEnvironment['APP_PORT'] = substr(strrchr($address, ':'), 1);
        $brokerEnvironment['APP_ALLOWED_HOSTS'] = $address;
        $brokerServer = new Process([...$command, 'broker:serve'], $root, $brokerEnvironment);
        $brokerClient = new HttpClient('http://' . $address);
        $ready = false;
        $deadline = microtime(true) + 10;
        do {
            expect($brokerServer->running(), '审计浏览器Broker提前退出：' . $brokerServer->stderr());
            try {
                $ready = $brokerClient->request('GET', '/readyz')->status === 200;
            } catch (RuntimeException) {
            }
            if (!$ready) {
                usleep(10000);
            }
        } while (!$ready && microtime(true) < $deadline);
        expect($ready, '审计浏览器Broker未就绪');
        $login = $brokerClient->request('POST', '/broker/auth/login', ['Content-Type' => 'application/json'], json_encode($fixture['accounts']['standalone'], JSON_THROW_ON_ERROR));
        expect($login->status === 200, '浏览器独立身份装置登录失败');
        $brokerToken = $login->json()['data']['accessToken'];
        $secrets[] = $brokerToken;
        $brokerHeaders = ['Authorization' => 'Bearer ' . $brokerToken];
        $events = $brokerClient->request('GET', '/broker/audit?action=identity.login&result=success', $brokerHeaders);
        expect($events->status === 200 && count($events->json()['items']) === 1, '浏览器独立身份事件应来自真实登录');
        $fixture['events']['standalone'] = $events->json()['items'][0]['id'];
        $brokerDsn = $driver === 'sqlite' ? 'sqlite:' . $brokerBase . '/audit-broker.sqlite' : $driver . ':host=' . $environment['DB_HOST'] . ';port=' . $environment['DB_PORT'] . ';dbname=' . $databaseName;
        $brokerDatabase = new PDO($brokerDsn, $environment['DB_USERNAME'] ?? null, $environment['DB_PASSWORD'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $fixture['events']['legacy'] = bin2hex(random_bytes(16));
        $brokerDatabase->prepare('INSERT INTO broker_audit (id, tenant_id, actor_id, action, subject_id, result, details, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$fixture['events']['legacy'], null, $account['id'], 'identity.login', 'audit-browser-legacy', 'success', '{"context":"identity"}', time()]);
        // 无筛选页至少21条由真实HTTP读取生成；不靠合成事件证明列表访问记录。
        for ($index = 0; $index < 21; $index++) {
            $request('GET', $tenantPath . '/broker/resources/connections', $token, $tenant, null, 200);
        }
        $identity = $request('GET', '/iot/auth/me', $token, $tenant, null, 200)['data'];
        $fixture['long_text'] = 'audit-browser-page-subject-' . str_repeat('x', 70);
        $operationId = bin2hex(random_bytes(16));
        $requestId = bin2hex(random_bytes(16));
        $context = ['operation_id' => $operationId, 'mode' => 'async', 'origin_request_id' => $requestId,
            'actor_id' => $identity['user']['id'], 'tenant_id' => $tenant, 'action' => 'browser.page_fixture', 'subject_id' => $fixture['long_text'],
            'authorization' => ['source' => 'tenant-member', 'role' => $identity['context']['role'], 'permissions' => $identity['context']['permissions'],
                'required_action' => 'audit.read', 'decision' => 'allowed', 'support_id' => null, 'support_version' => null, 'support_expires_at' => null],
            'target' => ['kind' => 'page_fixture', 'node_id' => '', 'node_run_id' => '', 'observation_run' => '', 'generation' => 0],
            'impact' => ['confirmed' => false, 'effect' => 'page_fixture', 'target_count' => 0, 'proof_hash' => '']];
        $contextJson = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $iotDatabase->beginTransaction();
        try {
            $iotDatabase->prepare('INSERT INTO iot_broker_operations (operation_id, context_json, context_hash, stage, result, version, receipts_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$operationId, $contextJson, hash('sha256', $contextJson), 'completed', 'success', 2, '{}', time(), time()]);
            foreach (['accepted' => 'pending', 'completed' => 'success'] as $stage => $result) {
                $eventId = bin2hex(random_bytes(16));
                $iotDatabase->prepare('INSERT INTO iot_audit (id, tenant_id, actor_id, action, subject_id, result, details, created_at, category, operation_id, event_key, request_id, stage) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$eventId, $tenant, $context['actor_id'], $context['action'], $context['subject_id'], $result,
                        '{"facts":{"reason":"browser_page_fixture"}}', time(), 'broker', $operationId, $stage, $requestId, $stage]);
                if ($stage === 'accepted') {
                    $fixture['events']['tenant'] = $eventId;
                }
            }
            $iotDatabase->commit();
        } catch (Throwable $failure) {
            $iotDatabase->rollBack();
            throw $failure;
        }
        $grant = $request('POST', $tenantPath . '/support-grants', $token, $tenant, [
            'id' => bin2hex(random_bytes(16)), 'login' => $fixture['accounts']['platform']['login'], 'duration_seconds' => 600, 'reason' => 'Broker审计页面真实支持撤销验收',
        ], 200)['data'];
        $fixture['support'] = ['id' => $grant['id'], 'version' => $grant['version']];
        $fixtureJson = json_encode($fixture, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        expect(file_put_contents($fixturePath, $fixtureJson) === strlen($fixtureJson) && chmod($fixturePath, 0600), '无法保存私有审计浏览器夹具');
        $browser = new Process(['node', $root . '/tests/broker-resources-browser.mjs', '--audit', $base, $dist,
            'http://' . $environment['APP_ALLOWED_HOSTS'], 'http://' . $address], $root, getenv());
        $browserResult = $browser->wait(240);
        expect($browserResult->successful(), 'Broker审计浏览器验收失败，见本轮browser-audit-report.json和audit-browser.log');
        $browserReport = json_decode((string) file_get_contents($base . '/browser-audit-report.json'), true, 64, JSON_THROW_ON_ERROR);
        expect(($browserReport['status'] ?? '') === 'passed', '审计浏览器报告未通过');
        $runnerReport = json_decode((string) file_get_contents($base . '/browser-audit-runner-report.json'), true, 64, JSON_THROW_ON_ERROR);
        expect(($runnerReport['status'] ?? '') === 'passed' && ($runnerReport['support_revoked'] ?? false) === true
            && ($runnerReport['cleanup'] ?? []) !== [] && !in_array(false, $runnerReport['cleanup'], true), '审计浏览器未确认真实支持撤销或完整回收');
        $report['cases'] = $browserReport['cases'];
        $report['node_cleanup'] = $runnerReport['cleanup'];
        $report['support_revoked_by_browser'] = true;
        $report['status'] = 'passed';
    } finally {
        $cleanupErrors = [];
        foreach (['browser' => $browser, 'broker_http' => $brokerServer] as $role => $process) {
            try {
                $process?->stop(5);
                $report['cleanup'][$role] = $process === null || !$process->running();
                expect($report['cleanup'][$role], '审计浏览器装置子进程未退出');
                if ($process !== null) {
                    $log = str_replace($secrets, '<REDACTED>', $process->stdout() . $process->stderr());
                    $file = $base . ($role === 'browser' ? '/audit-browser.log' : '/audit-broker-management.log');
                    expect(file_put_contents($file, $log) === strlen($log) && chmod($file, 0600), '无法保存浏览器装置脱敏日志');
                }
            } catch (Throwable $failure) {
                $cleanupErrors[] = $role . ':' . get_class($failure);
            }
        }
        try {
            if ($grant !== null && $iotDatabase !== null) {
                $query = $iotDatabase->prepare('SELECT version, revoked_at FROM iot_support_grants WHERE id = ?');
                $query->execute([$grant['id']]);
                $current = $query->fetch(PDO::FETCH_ASSOC);
                // SQLite读游标在外部HTTP写入前释放，避免旧快照阻止后续清理写入。
                $query->closeCursor();
                if ($current !== false && $current['revoked_at'] === null) {
                    $request('DELETE', $tenantPath . '/support-grants/' . $grant['id'], $token, $tenant, ['version' => (int) $current['version']], 200);
                }
            }
            $report['cleanup']['support'] = true;
            if ($operationId !== null && $iotDatabase !== null) {
                $iotDatabase->prepare('DELETE FROM iot_audit WHERE operation_id = ?')->execute([$operationId]);
                $iotDatabase->prepare('DELETE FROM iot_broker_operations WHERE operation_id = ?')->execute([$operationId]);
            }
            $report['cleanup']['async_page_fixture'] = true;
        } catch (Throwable $failure) {
            $cleanupErrors[] = 'iot_fixture:' . get_class($failure);
        }
        $brokerDatabase = null;
        try {
            if ($databaseCreated && $iotDatabase !== null) {
                expect($report['cleanup']['broker_http'], 'Broker服务未退出时保留本轮数据库现场');
                expect(preg_match('/^type_audit_browser_[a-f0-9]{12}$/D', $databaseName) === 1, '浏览器数据库清理目标不明确');
                $iotDatabase->exec('DROP DATABASE ' . $databaseName);
            }
            $report['cleanup']['broker_database'] = $driver !== 'sqlite';
        } catch (Throwable $failure) {
            $cleanupErrors[] = 'broker_database:' . get_class($failure);
        }
        $iotDatabase = null;
        try {
            expect(!file_exists($fixturePath) || unlink($fixturePath), '无法删除审计浏览器秘密夹具');
            $report['cleanup']['fixture_secret'] = true;
            expect($report['cleanup']['broker_http'], 'Broker服务未退出时保留本轮运行目录');
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($brokerBase, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                expect($entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()), '无法回收本轮Broker浏览器文件');
            }
            expect(rmdir($brokerBase), '无法回收本轮Broker浏览器目录');
            if ($driver === 'sqlite') {
                $report['cleanup']['broker_database'] = true;
            }
        } catch (Throwable $failure) {
            $cleanupErrors[] = 'fixture_files:' . get_class($failure);
        }
        if ($cleanupErrors !== [] || $report['status'] !== 'passed') {
            $report['status'] = 'failed';
        }
        $report['cleanup_errors'] = $cleanupErrors;
        file_put_contents($base . '/audit-browser-integration.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        expect($cleanupErrors === [], '审计浏览器装置清理未完成，见audit-browser-integration.json');
    }
    return $report;
}

$root = dirname(__DIR__);
$target = $argv[1] ?? '--php';
$driver = $argv[2] ?? 'sqlite';
expect(!in_array('--alarms', $argv, true) || in_array('--devices', $argv, true), '告警验收需要--devices，以新应用身份和真实角色装置运行');
expect(!in_array('--exports', $argv, true) || (in_array('--devices', $argv, true) && array_intersect($argv, ['--alarms', '--lifecycle', '--device-mqtt']) === []), '导出验收需要--devices，来源撤销使用独立装置');
expect(in_array($driver, ['sqlite', 'mysql', 'pgsql'], true), '身份验证需要明确的受支持驱动');
expect(
    in_array('--app', $argv, true) || in_array('--products', $argv, true) || in_array('--devices', $argv, true) || in_array('--broker', $argv, true),
    '旧 /iot/auth、migrate run 与支持授权已退出生产候选；新应用使用 --app/--products/--devices，独立 Broker 使用 --broker'
);
if ($target === '--php') {
    // 子进程继承 INI，不继承父进程的 -d；实际探测以免重复加载或遗漏静态模块。
    [$probeStatus, $loadedSwoole, $probeError] = execute([PHP_BINARY, '-r', 'echo extension_loaded("swoole") ? phpversion("swoole") : "";']);
    expect($probeStatus === 0 && $probeError === '', 'PHP 身份验收的基础运行配置无效：' . $probeError);
    $command = [PHP_BINARY];
    if ($loadedSwoole === '') {
        $swoole = getenv('TYPE_SWOOLE_MODULE');
        $moduleName = PHP_OS_FAMILY === 'Windows' ? 'php_swoole.dll' : 'swoole.so';
        $swoole = is_string($swoole) && $swoole !== '' ? $swoole : rtrim((string) ini_get('extension_dir'), '/\\') . '/' . $moduleName;
        expect(is_file($swoole), 'PHP 身份验收需要 TYPE_SWOOLE_MODULE 或 extension_dir 中匹配平台的 Swoole 模块');
        array_push($command, '-d', 'extension=' . $swoole);
    } else {
        expect(version_compare($loadedSwoole, '6.2', '>=') && version_compare($loadedSwoole, '7', '<'), 'PHP 身份验收需要 Swoole >=6.2 <7');
    }
    array_push($command, '-d', 'swoole.enable_library=On', $root . '/bin/typeapp');
} else {
    $command = nativeCommand($target);
}
$base = $root . '/build/iot-identity-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700, true), '无法创建身份测试数据根');
$noSource = in_array('--no-source', $argv, true);
if ($noSource) {
    expect($target !== '--php' && PHP_OS_FAMILY === 'Darwin', '本项无源码隔离使用macOS内核策略及原生产物');
    $runtime = $base . '/runtime';
    (new Type\Build\NativePackage())->create($target, $runtime);
    $built = json_decode(file_get_contents($target . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
    // 输入快照与主仓可位于不同目录；拒读目标取自产物的真实构建身份。
    $sourceRoot = realpath($built['identity']['description']['facts']['workspace']);
    expect(is_string($sourceRoot), '无源码验收必须能定位实际编译输入');
    $policy = ['sandbox-exec', '-f', $root . '/tests/fixtures/iot-no-source.sb'];
    foreach (['APP' => $sourceRoot . '/app', 'PLUGIN' => $sourceRoot . '/plugin', 'VENDOR' => $sourceRoot . '/vendor', 'CONFIG' => $sourceRoot . '/config',
        'COMPILER' => dirname($built['runtime-profile']['ini'], 2), 'COMPOSER' => $sourceRoot . '/composer.json'] as $role => $path) {
        array_push($policy, '-D', $role . '=' . $path);
    }
    $probeFiles = [$sourceRoot . '/app/main.php', $sourceRoot . '/plugin/type-core/src/Application.php', $sourceRoot . '/vendor/autoload.php', $sourceRoot . '/config/app.php'];
    foreach ($probeFiles as $probeFile) {
        expect(is_file($probeFile) && is_readable($probeFile), '拒读探针必须指向真实可读的编译输入');
    }
    $probe = 'foreach(array_slice($argv,1) as $path){if(@file_get_contents($path)!==false)throw new RuntimeException("source readable");} echo "source-denied\n";';
    expect(successful([...$policy, PHP_BINARY, '-n', '-r', $probe, ...$probeFiles], $runtime) === "source-denied\n", '无源码隔离策略未生效');
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
$environment['DB_SQLITE_FILE'] = 'identity.sqlite';
if ($driver !== 'sqlite') {
    $prefix = 'TYPE_' . strtoupper($driver) . '_';
    foreach (['HOST' => 'HOST', 'PORT' => 'PORT', 'DATABASE' => 'DATABASE', 'USERNAME' => 'USER', 'PASSWORD' => 'PASSWORD'] as $destination => $source) {
        $value = getenv($prefix . $source);
        expect(is_string($value), '数据库验证必须由隔离实例提供参数：' . $source);
        $environment['DB_' . $destination] = $value;
    }
}
$environment['APP_API_TOKEN'] = bin2hex(random_bytes(32));
$environment['APP_CACHE_ENABLED'] = 'false';
$environment['APP_DEBUG'] = 'true';
if (in_array('--operations', $argv, true) || in_array('--load-phases', $argv, true) || in_array('--broker-resources', $argv, true)) {
    $environment['IOT_MQTT_COMMAND'] = json_encode($command, JSON_THROW_ON_ERROR);
}
if (in_array('--operations', $argv, true)) {
    require_once __DIR__ . '/iot-operations.php';
}
if (in_array('--ha', $argv, true)) {
    expect(isset($GLOBALS['postgresHa']) && $GLOBALS['postgresHa'] instanceof PostgresHa, 'HA设备验收必须由专属隔离装置启动');
    $environment['DB_TLS_CA'] = (string) getenv('TYPE_PGSQL_CA');
    $environment['IOT_MQTT_STANDBY'] = (string) getenv('TYPE_PGSQL_STANDBY_NAMES');
}
if (in_array('--app', $argv, true) || in_array('--products', $argv, true) || in_array('--devices', $argv, true)) {
    expect(array_intersect($argv, ['--broker', '--broker-audit', '--ha', '--recovery', '--support', '--capacity', '--cluster']) === [], '新应用验收不能静默跳过尚未迁移的旧业务组合；按对应任务完成消费者迁移后再组合。');
    expect(!in_array('--broker-resources', $argv, true) || in_array('--devices', $argv, true), 'Broker集成验收需要新设备与模拟身份装置');
    expect(in_array('--devices', $argv, true) || array_intersect($argv, ['--audit', '--operations']) === [], '审计与观察验收需要新设备及模拟身份装置');
    expect(in_array('--devices', $argv, true) || array_intersect($argv, ['--business', '--history', '--aggregate', '--alarms']) === [], '遥测、告警、转移和指令需要新设备装置');
    expect(in_array('--devices', $argv, true) || array_intersect($argv, ['--lifecycle', '--device-mqtt', '--lifecycle-mqtt']) === [], '生命周期与MQTT需要新设备装置');
    $password = bin2hex(random_bytes(16));
    $credentials = ['APP_ADMIN_PASSWORD' => $password, 'APP_CUSTOMER_PASSWORD' => $password . '-customer'];
    $installCommand = [...$command, 'app:install', 'same-login', '平台管理员', 'same-login', '客户管理员', '初始租户'];
    $server = null;
    $inspection = null;
    $report = ['status' => 'running', 'driver' => $driver, 'native' => $target !== '--php',
        'binary_sha256' => $target === '--php' ? null : hash_file('sha256', $target), 'no_source' => $noSource, 'http_checks' => 0];
    try {
        foreach ([['migrate', 'run'], ['iot:user', 'legacy-user', '旧开通'], ['iot:support-clean']] as $legacy) {
            $retired = new Process([...$command, ...$legacy], $root, $environment + $credentials);
            try {
                $rejected = $retired->wait(30);
                $output = $rejected->stdout . $rejected->stderr;
                expect(!$rejected->successful(), '旧入口仍然有效：' . implode(' ', $legacy) . ' ' . $output);
                if ($legacy === ['migrate', 'run']) {
                    expect(str_contains($output, 'app:install'), 'migrate run 未指向 app:install');
                } else {
                    expect(str_contains($output, '未知应用命令'), '旧命令未被拒绝：' . implode(' ', $legacy));
                }
            } finally {
                $retired->stop();
            }
        }
        $report['retired_commands'] = ['migrate run', 'iot:user', 'iot:support-clean'];
        $dsn = $driver === 'sqlite' ? 'sqlite:' . $base . '/identity.sqlite' : $driver . ':host=' . $environment['DB_HOST'] . ';port=' . $environment['DB_PORT'] . ';dbname=' . $environment['DB_DATABASE'];
        $inspection = new PDO($dsn, $environment['DB_USERNAME'] ?? null, $environment['DB_PASSWORD'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $inspection->exec('CREATE TABLE app_test_existing (value INTEGER NOT NULL)');
        $inspection->exec('INSERT INTO app_test_existing (value) VALUES (73)');
        $existing = new Process($installCommand, $root, $environment + $credentials);
        try {
            $rejected = $existing->wait(30);
            expect(!$rejected->successful() && str_contains($rejected->stderr, 'TYPE_MIGRATION_NOT_EMPTY'), '已有外部模式没有拒绝初始化');
        } finally {
            $existing->stop();
        }
        expect((int) $inspection->query('SELECT value FROM app_test_existing')->fetchColumn() === 73, '初始化修改了已有数据');
        // 只移除本例刚创建的单张哨兵表；应用自身不能删除它。
        $inspection->exec('DROP TABLE app_test_existing');
        $installed = identityCommand($installCommand, $environment + $credentials)['data'];
        $initial = $inspection->query('SELECT installation_id FROM app_installation')->fetchColumn();
        expect($initial === $installed['installation_id'], '安装身份不一致');
        foreach (['admin', 'customer'] as $realm) {
            $row = $inspection->query('SELECT * FROM ' . $realm . '_users')->fetch(PDO::FETCH_ASSOC);
            expect(!array_key_exists('platform_admin', $row) && password_verify($credentials[$realm === 'admin' ? 'APP_ADMIN_PASSWORD' : 'APP_CUSTOMER_PASSWORD'], $row['password_hash']), '新账号域仍依赖平台标记或口令存储错误');
            $inspection->beginTransaction();
            try {
                $conflictSql = 'INSERT INTO ' . $realm . '_users (id, login, name, password_hash, created_at) SELECT ?, login, name, password_hash, created_at FROM ' . $realm . '_users';
                $inspection->prepare($conflictSql)->execute([bin2hex(random_bytes(16))]);
                throw new RuntimeException('唯一冲突未拒绝');
            } catch (PDOException $conflict) {
                $inspection->rollBack();
            }
            expect((int) $inspection->query('SELECT COUNT(*) FROM ' . $realm . '_users')->fetchColumn() === 1, '唯一冲突后保留了额外账号');
        }
        $repeat = new Process($installCommand, $root, $environment + $credentials);
        try {
            $rejected = $repeat->wait(30);
            expect(!$rejected->successful() && str_contains($rejected->stderr, 'TYPE_MIGRATION_NOT_EMPTY'), '重复安装没有在写入前拒绝');
        } finally {
            $repeat->stop();
        }
        expect($inspection->query('SELECT installation_id FROM app_installation')->fetchColumn() === $initial, '重复安装修改了原身份');
        $listener = stream_socket_server('tcp://127.0.0.1:0', $number, $error);
        expect(is_resource($listener), '无法分配双端端口');
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $environment['APP_PORT'] = substr(strrchr($address, ':'), 1);
        $environment['APP_ALLOWED_HOSTS'] = $address;
        $server = new Process([...$command, 'serve'], $root, $environment);
        $client = new HttpClient('http://' . $address);
        $deadline = microtime(true) + 15;
        do {
            expect($server->running(), '双端HTTP提前退出：' . $server->stderr());
            try {
                $ready = $client->request('GET', '/readyz')->status === 200;
            } catch (RuntimeException) {
                $ready = false;
            }
            if (!$ready) {
                usleep(10000);
            }
        } while (!$ready && microtime(true) < $deadline);
        expect($ready, '双端HTTP未就绪');
        $checks = 0;
        $request = static function (string $method, string $path, string $token, array $headers, ?array $data, int $expected) use ($client, &$checks, &$server): array {
            // 连续请求及时排空双输出；日志仍由 Process 保留，不能让管道背压阻塞服务。
            expect($server->running(), '双端HTTP提前退出：' . $server->stderr());
            $response = $client->request($method, $path, $headers + ($token === '' ? [] : ['Authorization' => 'Bearer ' . $token]) + ['Content-Type' => 'application/json'], $data === null ? '' : json_encode($data === [] ? (object) [] : $data, JSON_THROW_ON_ERROR));
            expect($response->status === $expected, '双端状态错误：' . $path . ' expected=' . $expected . ' actual=' . $response->status);
            expect(!str_contains($response->body, 'password_hash'), '双端响应包含凭据散列');
            foreach (['password', 'current_password', 'owner_password'] as $secretField) {
                if (isset($data[$secretField]) && $data[$secretField] !== '') {
                    expect(!str_contains($response->body, $data[$secretField]), '双端响应回显口令');
                }
            }
            $checks++;
            return json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
        };
        if ($driver === 'pgsql') {
            $inspection->exec("CREATE FUNCTION app_test_audit_failure() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''controlled audit failure''; END'");
            $inspection->exec('CREATE TRIGGER app_test_audit_failure BEFORE INSERT ON admin_audit FOR EACH ROW EXECUTE FUNCTION app_test_audit_failure()');
        } elseif ($driver === 'mysql') {
            $inspection->exec("CREATE TRIGGER app_test_audit_failure BEFORE INSERT ON admin_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'controlled audit failure'");
        } else {
            $inspection->exec("CREATE TRIGGER app_test_audit_failure BEFORE INSERT ON admin_audit BEGIN SELECT RAISE(ABORT, 'controlled audit failure'); END");
        }
        try {
            $request('POST', '/admin/auth/login', '', [], ['login' => 'same-login', 'password' => $password], 500);
            expect((int) $inspection->query('SELECT COUNT(*) FROM admin_sessions')->fetchColumn() === 0, '审计失败仍提交了登录会话');
        } finally {
            $inspection->exec('DROP TRIGGER app_test_audit_failure' . ($driver === 'pgsql' ? ' ON admin_audit' : ''));
            if ($driver === 'pgsql') {
                $inspection->exec('DROP FUNCTION app_test_audit_failure()');
            }
        }
        $request('POST', '/admin/auth/login', '', [], ['login' => 'same-login', 'password' => $password . '-customer'], 401);
        $request('POST', '/customer/auth/login', '', [], ['login' => 'same-login', 'password' => $password], 401);
        $adminToken = $request('POST', '/admin/auth/login', '', [], ['login' => 'same-login', 'password' => $password], 200)['data']['accessToken'];
        $customerToken = $request('POST', '/customer/auth/login', '', [], ['login' => 'same-login', 'password' => $password . '-customer'], 200)['data']['accessToken'];
        $tenantHeaders = ['X-Tenant-Id' => $installed['tenant_id']];
        $adminCurrent = $request('GET', '/admin/auth/me', $adminToken, [], null, 200)['data'];
        $customerCurrent = $request('GET', '/customer/auth/me', $customerToken, [], null, 200)['data'];
        expect(in_array('/admin/profile', menuPaths($adminCurrent['menus']), true) && in_array('/profile', menuPaths($customerCurrent['menus']), true) && $customerCurrent['tenant_id'] === $installed['tenant_id'], '双端菜单或当前租户错误');
        $publicSite = $request('GET', '/public/site', '', [], null, 200)['data'];
        expect($publicSite['name'] === 'TypeApp' && $publicSite['official_url'] === 'https://iots.top'
            && $publicSite['description'] === '物联中心管理平台' && $publicSite['theme']['mode'] === 'light'
            && $publicSite['preferences']['layout'] === 'sidebar-nav' && $publicSite['preferences']['breadcrumb']['enable'] === true, '默认站点配置未公开');
        $adminSite = $request('GET', '/admin/site', $adminToken, [], null, 200)['data'];
        expect($adminSite['version'] === 1 && in_array('admin.site.manage', $adminSite['permissions'], true)
            && in_array('/admin/site', menuPaths($adminSite['menus']), true), '管理端站点设置权限或菜单错误');
        $updatedSite = $request('PUT', '/admin/site', $adminToken, [], ['version' => 1, 'changes' => ['name' => 'TypeApp 物联中心', 'description' => '本地验收配置', 'layout_mode' => 'mixed-nav', 'sidebar_collapsed' => true, 'tabbar_enable' => true]], 200)['data'];
        expect($updatedSite['version'] === 2 && $updatedSite['name'] === 'TypeApp 物联中心'
            && $updatedSite['preferences']['layout'] === 'mixed-nav' && $updatedSite['preferences']['sidebar']['collapsed'] === true
            && $updatedSite['preferences']['tabbar']['enable'] === true, '站点配置更新未提交');
        $request('PUT', '/admin/site', $adminToken, [], ['version' => 2, 'changes' => ['sidebar_collapsed' => 'true']], 422, 'site_settings_value_invalid');
        $request('PUT', '/admin/site', $adminToken, [], ['version' => 2, 'changes' => ['layout_mode' => 'freeform']], 422, 'site_settings_value_invalid');
        $request('PUT', '/admin/site', $adminToken, [], ['version' => 1, 'changes' => ['name' => '过期写入']], 409, 'stale_version');
        $publicSite = $request('GET', '/public/site', '', [], null, 200)['data'];
        expect($publicSite['name'] === 'TypeApp 物联中心' && $publicSite['description'] === '本地验收配置', '公开站点配置未同步');
        $siteAudit = $inspection->query("SELECT action, result, details FROM admin_audit WHERE action = 'admin.site.update' ORDER BY created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        expect($siteAudit !== false && $siteAudit['result'] === 'success' && str_contains($siteAudit['details'], 'name'), '站点配置审计未落库');
        $request('GET', '/admin/profile', $adminToken, [], null, 200);
        $request('GET', '/customer/profile', $customerToken, $tenantHeaders, null, 200);
        $request('GET', '/admin/auth/me', $customerToken, [], null, 401);
        $request('GET', '/customer/auth/me', $adminToken, [], null, 401);
        $request('GET', '/admin/auth/me', $adminToken, $tenantHeaders, null, 403);
        $request('GET', '/customer/auth/me', $customerToken, ['X-Tenant-Id' => str_repeat('f', 32)], null, 403);
        $request('GET', '/customer/auth/me', $customerToken, ['X-Support-Id' => str_repeat('a', 32)], null, 403);
        $request('POST', '/admin/auth/login', '', [], ['login' => 'same-login', 'password' => $password, 'realm' => 'customer'], 422);
        $request('POST', '/iot/auth/login', '', [], ['login' => 'same-login', 'password' => $password], 404);
        $request('POST', '/iot/tenants/' . $installed['tenant_id'] . '/support-grants', $adminToken, $tenantHeaders, [
            'id' => bin2hex(random_bytes(16)), 'login' => 'same-login', 'duration_seconds' => 600, 'reason' => '旧支持不应存在',
        ], 404);
        foreach (['/iot/auth/me', '/iot/auth/login', '/iot/tenants', '/iot/tenants/' . $installed['tenant_id'] . '/support-grants', '/demo', '/users', '/broker/auth/me'] as $retired) {
            $request('GET', $retired, $adminToken, [], null, 404);
        }
        $productCatalog = $request('GET', '/customer/tenants/' . $installed['tenant_id'] . '/products', $customerToken, $tenantHeaders, null, 200);
        $deviceCatalog = $request('GET', '/customer/tenants/' . $installed['tenant_id'] . '/devices', $customerToken, $tenantHeaders, null, 200);
        expect(isset($productCatalog['items']) && isset($deviceCatalog['items']), '全新安装后产品或设备目录不可用');
        $report['iot_business_catalog'] = ['products' => count($productCatalog['items']), 'devices' => count($deviceCatalog['items'])];
        $inspection->exec('UPDATE admin_roles SET enabled = 0');
        expect($request('GET', '/admin/auth/me', $adminToken, [], null, 200)['data']['menus'] === [], '角色停用未清除菜单');
        $request('GET', '/admin/profile', $adminToken, [], null, 403);
        $inspection->exec('UPDATE admin_roles SET enabled = 1');
        $adminBinding = $inspection->query('SELECT user_id, role_id FROM admin_user_roles')->fetch(PDO::FETCH_ASSOC);
        $inspection->exec('DELETE FROM admin_user_roles');
        expect($request('GET', '/admin/auth/me', $adminToken, [], null, 200)['data']['permissions'] === [], '无角色不能取得权限');
        $request('GET', '/admin/profile', $adminToken, [], null, 403);
        $inspection->prepare('INSERT INTO admin_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$adminBinding['user_id'], $adminBinding['role_id']]);
        $management = identityAdminCases($request, $inspection, $driver, $address, $adminToken, $password, (string) $installed['admin']['id']);
        $adminToken = $management['token'];
        unset($management['token']);
        $report['admin_management'] = $management;
        $report['tenant_management'] = identityTenantCases($request, $inspection, $driver, $address, $adminToken, $password, (string) $installed['admin']['id']);
        $report['customer_management'] = identityCustomerCases($request, $inspection, $driver, $address, $adminToken, $password, (string) $installed['admin']['id']);
        $report['member_management'] = identityMemberCases($request, $inspection, $driver, $address, $adminToken, $customerToken, $password, $installed['tenant_id']);
        $report['role_management'] = identityRoleCases($request, $inspection, $driver, $address, $adminToken, $customerToken, $password);
        $report['impersonation'] = identityImpersonationCases($request, $inspection, $driver, $address, $adminToken, $customerToken, $password);
        if (in_array('--products', $argv, true)) {
            require __DIR__ . '/iot-products.php';
            $products = iotProductChecks($request, $inspection, $driver, $address, $adminToken, $password, $client);
            $beforeRestart = $server->stop(5);
            file_put_contents($base . '/http-before-product-restart.log', str_replace(array_values($credentials), '<REDACTED>', $server->stdout() . $server->stderr()));
            expect($beforeRestart->successful() && !$server->running(), '产品历史重启前HTTP未正常排空');
            $server = new Process([...$command, 'serve'], $root, $environment);
            $deadline = microtime(true) + 15;
            do {
                expect($server->running(), '产品重启HTTP提前退出');
                try {
                    $ready = $client->request('GET', '/readyz')->status === 200;
                } catch (RuntimeException) {
                    $ready = false;
                }
                if (!$ready) {
                    usleep(10000);
                }
            } while (!$ready && microtime(true) < $deadline);
            expect($ready, '产品重启未就绪');
            expect($request('GET', $products['path'], $products['token'], ['X-Tenant-Id' => $products['tenant']], null, 200)['data'] === $products['published'], '重启后精确历史定义或发布时间改变');
            unset($products['token']);
            $report['products'] = $products;
            $adminToken = $request('POST', '/admin/auth/login', '', [], ['login' => 'same-login', 'password' => $password], 200)['data']['accessToken'];
        }
        if (in_array('--devices', $argv, true)) {
            require __DIR__ . '/iot-devices.php';
            $deviceRequest = static function (string $method, string $url, string $token, ?string $tenant, ?array $data, int $status, string $code = '') use ($request): array {
                $response = $request($method, $url, $token, $tenant === null ? [] : ['X-Tenant-Id' => $tenant], $data, $status);
                expect($code === '' || ($response['error'] ?? '') === $code, '设备错误码不符：' . $url . ' actual=' . ($response['error'] ?? ''));
                return $response;
            };
            $deviceEvidence = iotDeviceChecks($request, $inspection, $driver, $address, $adminToken, $password);
            if (in_array('--broker-resources', $argv, true)) {
                $report['broker_resources'] = identityBrokerChecks($request, $inspection, $deviceEvidence, $password);
                $deviceEvidence['broker_resources'] = $report['broker_resources']['fixture'];
                unset($report['broker_resources']['fixture']);
                $environment['TYPE_BROKER_RESOURCE_PASSWORD'] = $password;
            }
            if (in_array('--audit', $argv, true)) {
                $report['audit'] = identityAuditChecks($request, $inspection, $deviceEvidence, $command, $environment, $password);
            }
            if (in_array('--operations', $argv, true)) {
                $report['operations'] = iotOperationsChecks($deviceRequest, $deviceEvidence['tokens'], $deviceEvidence['tenant'], $deviceEvidence['other_tenant'], $driver, $inspection, $deviceEvidence);
            }
            if (array_intersect($argv, ['--business', '--history', '--aggregate']) !== []) {
                $report['business'] = iotDeviceBusinessChecks($deviceRequest, $deviceEvidence['tokens'], $deviceEvidence['tenant'], $deviceEvidence['other_tenant'], $inspection, $environment, $deviceEvidence);
            }
            if (in_array('--history', $argv, true)) {
                require __DIR__ . '/iot-history.php';
                $report['history'] = iotHistoryChecks($deviceRequest, $deviceEvidence['tokens'], $deviceEvidence['tenant'], $deviceEvidence['other_tenant'], $inspection, $command, $environment, $base);
            }
            if (in_array('--aggregate', $argv, true)) {
                require __DIR__ . '/iot-aggregate.php';
                $report['aggregate'] = iotAggregateChecks($deviceRequest, $deviceEvidence['tokens'], $deviceEvidence['tenant'], $deviceEvidence['other_tenant'], $inspection, $command, $environment, $base);
            }
            if (in_array('--lifecycle', $argv, true)) {
                expect(!in_array('--alarms', $argv, true), '告警撤权装置与生命周期需分别使用独立来源');
                require __DIR__ . '/iot-lifecycle.php';
                $report['lifecycle'] = iotLifecycleChecks($deviceRequest, $deviceEvidence['tokens'], $deviceEvidence['tenant'], $deviceEvidence['other_tenant'], $inspection, $deviceEvidence, $environment, $base, $address, $password);
            }
            if (in_array('--device-mqtt', $argv, true)) {
                expect($driver === 'pgsql', '设备MQTT验证需要真实同步PostgreSQL');
                $report['device_mqtt'] = iotDeviceMqttChecks($deviceRequest, $deviceEvidence, $command, $environment, $base, $inspection);
            }
            if (in_array('--alarms', $argv, true)) {
                require __DIR__ . '/iot-alarms.php';
                $report['alarms'] = iotAlarmChecks($deviceRequest, $deviceEvidence['tokens'], $deviceEvidence['tenant'], $deviceEvidence['other_tenant'], $inspection, $command, $environment, $base, $deviceEvidence);
            }
            if (in_array('--exports', $argv, true)) {
                require __DIR__ . '/iot-exports.php';
                require_once __DIR__ . '/native-rollout-redis.php';
                $report['exports'] = iotExportChecks($deviceRequest, $deviceEvidence['tokens'], $deviceEvidence['tenant'], $deviceEvidence['other_tenant'], $inspection, $command, $environment, $base, $client, $deviceEvidence);
            }
            $deviceSnapshot = $deviceRequest('GET', $deviceEvidence['path'], $deviceEvidence['token'], $deviceEvidence['tenant'], null, 200)['data'];
            if (isset($report['exports'])) {
                foreach ([...array_values($deviceEvidence['tokens']), $deviceEvidence['simulated']['accessToken'], $deviceEvidence['source']['accessToken'], '=1+1'] as $secret) {
                    expect(!str_contains($server->stdout() . $server->stderr(), $secret), '普通导出日志泄漏令牌或遥测载荷');
                }
                $report['exports']['no_secret_payload_in_http_log'] = true;
            }
            $beforeRestart = $server->stop(5);
            file_put_contents($base . '/http-before-device-restart.log', str_replace(array_values($credentials), '<REDACTED>', $server->stdout() . $server->stderr()));
            expect($beforeRestart->successful() && !$server->running(), '设备重启前HTTP未正常排空');
            $server = new Process([...$command, 'serve'], $root, $environment);
            $deadline = microtime(true) + 15;
            do {
                expect($server->running(), '设备重启HTTP提前退出');
                try {
                    $ready = $client->request('GET', '/readyz')->status === 200;
                } catch (RuntimeException) {
                    $ready = false;
                }
                if (!$ready) {
                    usleep(10000);
                }
            } while (!$ready && microtime(true) < $deadline);
            expect($ready, '设备重启未就绪');
            expect($deviceRequest('GET', $deviceEvidence['path'], $deviceEvidence['token'], $deviceEvidence['tenant'], null, 200)['data'] === $deviceSnapshot, '重启改变了设备资产、归属或凭据状态');
            if (isset($report['alarms'])) {
                expect($deviceRequest('GET', $report['alarms']['path'], $deviceEvidence['tokens']['alice'], $deviceEvidence['tenant'], null, 200)['items'] === $report['alarms']['items'], 'HTTP重启后告警条件、样本与确认必须保持');
                $report['alarms']['restart_persisted'] = true;
            }
            if (isset($report['exports']['example_task'])) {
                $response = $client->request('GET', '/customer/tenants/' . $deviceEvidence['tenant'] . '/exports/' . $report['exports']['example_task'] . '/download', ['Authorization' => 'Bearer ' . $deviceEvidence['tokens']['bob'], 'X-Tenant-Id' => $deviceEvidence['tenant']]);
                expect($response->status === 200 && hash('sha256', $response->body) === $report['exports']['csv_sha256'], 'HTTP重启后导出内容或权限改变');
                $report['exports']['restart_persisted'] = true;
            }
            $report['devices'] = ['checks' => $deviceEvidence['checks'], 'restart_persisted' => true];
        }
        $inspection->exec('UPDATE customer_members SET enabled = 0');
        $request('GET', '/customer/profile', $customerToken, $tenantHeaders, null, 403);
        $inspection->exec('UPDATE customer_members SET enabled = 1');
        $inspection->exec('UPDATE customer_users SET enabled = 0');
        $request('GET', '/customer/auth/me', $customerToken, [], null, 401);
        $inspection->exec('UPDATE customer_users SET enabled = 1');
        $inspection->exec('UPDATE customer_sessions SET expires_at = 1');
        $request('GET', '/customer/auth/me', $customerToken, [], null, 401);
        $request('POST', '/admin/auth/logout', $adminToken, [], [], 200);
        $request('GET', '/admin/auth/me', $adminToken, [], null, 401);
        $browserOptions = array_values(array_filter($argv, static fn (string $value): bool => str_starts_with($value, '--browser-dist=')));
        if ($browserOptions !== []) {
            expect($driver === 'sqlite' && count($browserOptions) === 1, '双端浏览器只使用本轮SQLite装置');
            $browser = new Process(['node', $root . '/tests/app-identity-browser.mjs', $base, substr($browserOptions[0], 15), 'http://' . $address], $root, $environment + $credentials);
            try {
                $browserResult = $browser->wait(390);
                file_put_contents($base . '/browser.log', str_replace(array_values($credentials), '<REDACTED>', $browserResult->stdout . $browserResult->stderr));
                expect($browserResult->successful(), '双端浏览器失败，见browser-report.json');
                $report['browser'] = json_decode(file_get_contents($base . '/browser-report.json'), true, 32, JSON_THROW_ON_ERROR);
            } finally {
                $browser->stop();
            }
        }
        $report['http_checks'] = $checks;
        expect(!str_contains($server->stdout() . $server->stderr(), $password), '双端日志包含人员口令');
        $report['password_response_log_checks'] = true;
        $report['status'] = 'passed';
    } finally {
        if ($report['status'] !== 'passed') {
            $report['status'] = 'failed';
        }
        if ($server !== null) {
            $stopped = $server->stop(5);
            $report['cleanup'] = ['http_exited' => !$server->running(), 'exit_code' => $stopped->exitCode];
            if (!$stopped->successful() || $server->running()) {
                $report['status'] = 'failed';
            }
            file_put_contents($base . '/http.log', str_replace(array_values($credentials), '<REDACTED>', $server->stdout() . $server->stderr()));
        }
        $inspection = null;
        file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        expect($server === null || ($stopped->successful() && !$server->running()), '双端服务没有正常排空退出');
    }
    echo '双端身份 HTTP 通过：' . $base . "/verification.json\n";
    exit(0);
}
if (in_array('--broker', $argv, true)) {
    require_once __DIR__ . '/broker-observability.php';
    $observability = in_array('--broker-observability', $argv, true);
    $environment['BROKER_PROBE_TOKEN'] = bin2hex(random_bytes(32));
    if ($observability) {
        expect(
            $driver === 'pgsql' && ($GLOBALS['brokerObservabilitySync'] ?? null) instanceof PostgresSync,
            'Broker故障验证必须由专属真实同步主备装置启动'
        );
        $environment['BROKER_COMMAND'] = json_encode($command, JSON_THROW_ON_ERROR);
        $environment['BROKER_STANDBY_NAMES'] = 'broker_observe_sync';
    }
    $server = null;
    $node = null;
    $mqttClient = null;
    $nodeLogs = [];
    $crashedNode = null;
    $browser = null;
    $browserStage = null;
    $browserDist = '';
    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--browser-dist=')) {
            $browserDist = substr($argument, 15);
        }
    }
    expect($browserDist === '' || ($observability && is_file($browserDist . '/index.html')), '浏览器验收需要观测场景及本次独立前端产物');
    $checks = 0;
    $brokerReport = ['status' => 'running', 'scope' => 'broker-management', 'native' => $target !== '--php', 'driver' => $driver,
        'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'no_source' => $noSource, 'transport' => 'swoole'];
    try {
        $install = new Process([...$command, 'broker:install'], $root, $environment);
        try {
            $installed = $install->wait(30);
            expect($installed->successful(), '独立安装失败：' . $installed->stdout . $installed->stderr);
        } finally {
            $install->stop();
        }
        if ($observability) {
            $install = new Process([...$command, 'broker:store-install'], $root, $environment);
            try {
                $installed = $install->wait(30);
                expect($installed->successful(), '独立持久安装失败：' . $installed->stdout . $installed->stderr);
            } finally {
                $install->stop();
            }
        }
        $password = bin2hex(random_bytes(16));
        $account = identityCommand([...$command, 'broker:user', 'broker-admin', '独立管理员'], $environment + ['BROKER_ADMIN_PASSWORD' => $password])['data'];
        $repeat = new Process([...$command, 'broker:user', 'broker-admin', '不允许覆盖'], $root, $environment + ['BROKER_ADMIN_PASSWORD' => 'Different-secret-1234']);
        try {
            expect(!$repeat->wait(30)->successful(), '重复初始化不能覆盖原账号');
        } finally {
            $repeat->stop();
        }
        $addresses = [];
        for ($index = 0; $index < 2; $index++) {
            $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorText);
            expect(is_resource($listener), '无法选择独立管理测试端口');
            $addresses[] = stream_socket_get_name($listener, false);
            fclose($listener);
        }
        $environment['APP_PORT'] = substr(strrchr($addresses[0], ':'), 1);
        $environment['APP_ALLOWED_HOSTS'] = $addresses[0];
        unset($environment['APP_API_TOKEN']);
        $server = new Process([...$command, 'broker:serve'], $root, $environment);
        $client = new HttpClient('http://' . $addresses[0], $observability ? 15.0 : 3.0);
        $deadline = microtime(true) + 10;
        do {
            expect($server->running(), '独立管理提前退出：' . $server->stderr());
            try {
                if ($client->request('GET', '/readyz')->status === 200) {
                    break;
                }
            } catch (RuntimeException) {
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        if (in_array('--quarantine-only', $argv, true)) {
            expect($observability && $browserDist === '', '隔离专项需要Swoole同步存储装置，不能组合浏览器');
            $brokerReport['scope'] = 'broker-concurrent-quarantine';
            $brokerReport['concurrent_quarantine'] = brokerConcurrentQuarantine(
                $GLOBALS['brokerObservabilitySync'],
                $client,
                $environment['BROKER_PROBE_TOKEN'],
                $environment,
                $server,
                $checks,
                $base
            );
            $brokerReport['status'] = 'passed';
            $brokerReport['http_checks'] = $checks;
            echo 'Broker并发隔离专项通过：' . $base . "/verification.json\n";
            return;
        }
        $request = static function (string $method, string $path, string $token, ?array $data, int $status, array $extra = []) use ($client, $server, &$checks): array {
            $headers = ['Content-Type' => 'application/json'] + $extra;
            if ($token !== '') {
                $headers['Authorization'] = 'Bearer ' . $token;
            }
            $response = $client->request($method, $path, $headers, $data === null ? '' : json_encode($data === [] ? (object) [] : $data, JSON_THROW_ON_ERROR));
            expect($response->status === $status, $method . ' ' . $path . ' 预期 ' . $status . '，实际 ' . $response->status . ' ' . $response->body . ($response->status >= 500 ? ' ' . $server->stdout() . $server->stderr() : ''));
            $checks++;
            return $response->json();
        };
        $request('GET', '/broker/nodes', '', null, 401);
        $request('POST', '/iot/auth/login', '', ['login' => 'broker-admin', 'password' => $password], 404);
        $request('POST', '/broker/auth/login', '', ['login' => 'missing-user', 'password' => $password], 401);
        $request('POST', '/broker/auth/login', '', ['login' => 'broker-admin', 'password' => 'incorrect'], 401);
        $auth = $request('POST', '/broker/auth/login', '', ['login' => 'broker-admin', 'password' => $password], 200)['data'];
        $token = $auth['accessToken'];
        expect($auth['user']['id'] === $account['id'], '管理员初始化和登录身份不一致');
        $request('GET', '/broker/auth/me', $token, null, 200);
        $request('GET', '/broker/audit', '', null, 401);
        foreach (['X-Tenant-Id', 'X-Support-Id'] as $contextHeader) {
            $request('GET', '/broker/audit', $token, null, 403, [$contextHeader => bin2hex(random_bytes(16))]);
        }
        $identityAudit = $request('GET', '/broker/audit', $token, null, 200);
        expect($identityAudit['total'] === null && $identityAudit['limit'] === 20 && !$identityAudit['has_more']
            && count($identityAudit['items']) === 4, '独立初始化、两次拒绝与成功登录应保存四条有界审计');
        $identityIds = [];
        $auditCursor = null;
        do {
            $page = $request('GET', '/broker/audit?action=identity.login&limit=1' . ($auditCursor === null ? '' : '&cursor=' . rawurlencode($auditCursor)), $token, null, 200);
            expect(count($page['items']) === 1 && !isset($page['items'][0]['operation']) && !isset($page['items'][0]['details']['operation']), '事件列表只能返回脱敏事实');
            $identityIds[] = $page['items'][0]['id'];
            $auditCursor = $page['next_cursor'];
            expect(count($identityIds) <= 3, '键集分页必须有界结束');
        } while ($page['has_more']);
        expect(count($identityIds) === 3 && count(array_unique($identityIds)) === 3 && $auditCursor === null, '同秒登录事件跨页不能重复或遗漏');
        $loginAudit = $request('GET', '/broker/audit?action=identity.login&result=success&actor_id=' . $account['id'], $token, null, 200)['items'][0];
        $loginDetail = $request('GET', '/broker/audit/' . $loginAudit['id'], $token, null, 200)['item'];
        expect($loginDetail['operation']['mode'] === 'sync' && $loginDetail['operation']['current_stage'] === 'completed'
            && $loginDetail['operation']['authorization']['role'] === 'broker_admin'
            && $loginDetail['operation']['origin_request_id'] === $loginAudit['request_id'], '身份详情必须保存当时权限和原始请求关联');
        $exactAudit = $request('GET', '/broker/audit?operation_id=' . $loginAudit['operation_id'] . '&subject_id=' . $account['id']
            . '&stage=completed&from=' . $loginAudit['created_at'] . '&to=' . $loginAudit['created_at'], $token, null, 200);
        expect(array_column($exactAudit['items'], 'id') === [$loginAudit['id']], '操作、主体、阶段与UTC闭区间应同时生效');
        expect($request('GET', '/broker/audit?to=1', $token, null, 200)['items'] === [], '空时间范围应返回真实空事件');
        foreach (['limit=0', 'limit=101', 'limit=1&limit=2', 'action=&action=identity.login', 'unknown=1', 'stage=running',
            'result=ok', 'actor_id=' . str_repeat('a', 33), 'actor_id=%FF', 'actor_id=%ZZ', 'subject_id=%',
            'operation_id=invalid', 'cursor=invalid', 'from=-1', 'to=253402300800'] as $invalidAuditQuery) {
            $request('GET', '/broker/audit?' . $invalidAuditQuery, $token, null, 400);
        }
        $request('GET', '/broker/audit?from=100&to=99', $token, null, 422);
        $request('GET', '/broker/audit/invalid', $token, null, 400);
        $request('GET', '/broker/audit/' . str_repeat('0', 32), $token, null, 404);
        $auditPage = $request('GET', '/broker/audit?limit=1', $token, null, 200);
        foreach (['limit=2', 'limit=1&action=identity.login'] as $changedAuditQuery) {
            $request('GET', '/broker/audit?' . $changedAuditQuery . '&cursor=' . rawurlencode($auditPage['next_cursor']), $token, null, 400);
        }
        $forgedRequestId = bin2hex(random_bytes(16));
        $readResponse = $client->request('GET', '/broker/resources/connections', ['Authorization' => 'Bearer ' . $token, 'X-Request-Id' => $forgedRequestId]);
        expect($readResponse->status === 200 && $readResponse->json()['items'] === [], '审计读取装置应返回真实空连接');
        $readRequestId = $readResponse->header('X-Request-Id')[0] ?? '';
        expect(preg_match('/^[a-f0-9]{32}$/D', $readRequestId) === 1 && $readRequestId !== $forgedRequestId, '请求关联必须由服务器生成');
        $resourceAudit = $request('GET', '/broker/audit?action=broker.resource.read', $token, null, 200)['items'];
        expect(count($resourceAudit) === 1 && $resourceAudit[0]['request_id'] === $readRequestId
            && $resourceAudit[0]['details'] === ['reason' => 'metadata_read'], '真实资源读取必须关联当前请求并只返回脱敏事实');
        if (!$observability) {
            $unavailableResource = $request('GET', '/broker/resources/subscriptions/' . str_repeat('a', 64), $token, null, 503);
            expect($unavailableResource['error'] === 'broker_resource_store_unavailable', '持久查询归还连接后失败审计不能覆盖原503');
            $failedReads = $request('GET', '/broker/audit?action=broker.resource.read&result=failed', $token, null, 200)['items'];
            expect(count($failedReads) === 1 && $failedReads[0]['details'] === ['reason' => 'broker_resource_store_unavailable'], '独立持久查询失败必须保存脱敏失败事实');
        }
        $checks++;
        $probeToken = $environment['BROKER_PROBE_TOKEN'];
        foreach (['/broker/health/live', '/broker/health/ready', '/broker/metrics'] as $probePath) {
            $request('GET', $probePath, '', null, 401);
            $request('GET', $probePath, $token, null, 401);
            $request('GET', $probePath, bin2hex(random_bytes(32)), null, 401);
            $request('GET', $probePath, $probeToken, null, 403, ['X-Tenant-Id' => bin2hex(random_bytes(16))]);
            $request('GET', $probePath, $probeToken, null, 403, ['X-Support-Id' => bin2hex(random_bytes(16))]);
        }
        $request('GET', '/broker/nodes', $probeToken, null, 401);
        $request('GET', '/broker/auth/me', $probeToken, null, 401);
        $live = $request('GET', '/broker/health/live', $probeToken, null, 200)['data'];
        expect(
            $live['alive'] === true && $live['scope'] === 'management_http' && abs(time() - $live['observed_at']) <= 2,
            '存活探针必须明确仅观察管理HTTP及当前时间'
        );
        $emptyHealth = $request('GET', '/broker/health/ready', $probeToken, null, 503)['data'];
        expect($emptyHealth['ready'] === false && $emptyHealth['state'] === 'no_reporting_nodes'
            && ($observability || $emptyHealth['store']['state'] === 'unconfigured'), '没有实际节点不能报告接收就绪');
        $emptyMetrics = brokerMetrics($client->request('GET', '/broker/metrics', ['Authorization' => 'Bearer ' . $probeToken]));
        expect(
            $emptyMetrics['typeapp_broker_ready'] === 0 && !isset($emptyMetrics['typeapp_broker_connections{slot="0"}']),
            '空节点指标不能伪造连接样本'
        );
        $checks++;
        $request('GET', '/demo', $token, null, 404);
        $request('GET', '/broker/nodes', $token, null, 403, ['X-Tenant-Id' => bin2hex(random_bytes(16))]);
        $request('GET', '/broker/nodes?per_page=101', $token, null, 422);
        expect($request('GET', '/broker/nodes', $token, null, 200)['total'] === 0, '未启动 Broker 应显示真实空结果');
        $nodeEnvironment = $environment + ['BROKER_CLIENT_USERNAME' => 'broker-client', 'BROKER_CLIENT_PASSWORD' => bin2hex(random_bytes(16)),
            'BROKER_TOPIC_PREFIX' => 'broker-test/', 'BROKER_NODE_ID' => 'broker-functional' . ($browserDist !== '' ? '-' . str_repeat('node', 11) : ''), 'BROKER_PLAINTEXT' => 'true',
            'BROKER_PORT' => substr(strrchr($addresses[1], ':'), 1)];
        $node = new Process([...$command, 'broker:run'], $root, $nodeEnvironment);
        $deadline = microtime(true) + 10;
        do {
            expect($node->running(), '独立 Broker 提前退出：' . $node->stderr());
            $nodes = $request('GET', '/broker/nodes', $token, null, 200);
            if ($nodes['total'] === 1) {
                break;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        expect($nodes['total'] === 1 && $nodes['items'][0]['state'] === 'reporting' && $nodes['items'][0]['listener']['port'] === (int) $nodeEnvironment['BROKER_PORT'], '实际节点与监听器观察缺失');
        $mqttClient = new Type\Mqtt\Client('127.0.0.1', (int) $nodeEnvironment['BROKER_PORT'], 'broker-observation-client', 'broker-client', $nodeEnvironment['BROKER_CLIENT_PASSWORD'], sessionExpiry: 0, allowPlaintext: true);
        $mqttClient->connect(true);
        $deadline = microtime(true) + 7;
        do {
            try {
                $mqttClient->receive(0.05);
            } catch (Type\Mqtt\ProtocolError $protocolFailure) {
                throw new RuntimeException('节点连接被断开，原因：' . dechex($protocolFailure->reason), 0, $protocolFailure);
            }
            $nodes = $request('GET', '/broker/nodes?node_id=' . rawurlencode($nodeEnvironment['BROKER_NODE_ID']), $token, null, 200);
            if ($nodes['items'][0]['metrics']['connections'] === 1) {
                break;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        expect($nodes['items'][0]['metrics']['connections'] === 1, '节点连接计数未来自真实 MQTT 连接：' . json_encode($nodes, JSON_THROW_ON_ERROR) . $node->stdout() . $node->stderr());
        $resourceConnections = $request('GET', '/broker/resources/connections?limit=1', $token, null, 200);
        expect(count($resourceConnections['items']) === 1 && $resourceConnections['items'][0]['connection_confirmed'] === true
            && $resourceConnections['items'][0]['client_id'] === 'broker-observation-client', '独立资源必须来自成功CONNACK后的真实连接');
        $resourceDetail = $request('GET', '/broker/resources/connections/' . $resourceConnections['items'][0]['id'], $token, null, 200);
        expect($resourceDetail['found'] && $resourceDetail['item']['source'] === 'live_observation', '独立连接详情必须区分采样与持久所有者');
        $resourceNodes = $request('GET', '/broker/resources/nodes', $token, null, 200);
        expect(count($resourceNodes['items']) === 1 && $resourceNodes['items'][0]['connections'] === 1, '独立节点资源观察缺失');
        $request('GET', '/broker/resources/connections', '', null, 401);
        $request('GET', '/broker/resources/connections', $token, null, 403, ['X-Support-Id' => str_repeat('a', 32)]);
        $request('GET', '/broker/resources/connections?limit=101', $token, null, 400);
        $request('GET', '/broker/resources/connections?cursor=invalid', $token, null, 400);
        $request('GET', '/broker/resources/connections/' . str_repeat('0', 32), $token, null, 404);
        if (!$observability) {
            $request('GET', '/broker/resources/sessions', $token, null, 503);
        }
        expect(!str_contains(json_encode([$resourceConnections, $resourceDetail], JSON_THROW_ON_ERROR), $nodeEnvironment['BROKER_CLIENT_PASSWORD']), '资源详情不能输出接入秘密');
        $mqttClient->stop();
        if ($observability) {
            if ($browserDist !== '') {
                $browser = new Process(
                    ['node', $root . '/tests/broker-observability-browser.mjs', $base, $browserDist, 'http://' . $addresses[0]],
                    $root,
                    array_replace(getenv(), ['BROKER_BROWSER_PASSWORD' => $password])
                );
                $browserStage = static function (string $stage) use ($browser, $base): void {
                    // 原子替换小型阶段标记，避免浏览器读取写入中的JSON；不传递密码或令牌。
                    file_put_contents($base . '/browser-stage.tmp', json_encode(['stage' => $stage], JSON_THROW_ON_ERROR));
                    rename($base . '/browser-stage.tmp', $base . '/browser-stage.json');
                    $deadline = microtime(true) + 90;
                    do {
                        $acknowledged = is_file($base . '/browser-ack.json') ? json_decode((string) file_get_contents($base . '/browser-ack.json'), true) : null;
                        if (($acknowledged['stage'] ?? '') === $stage && ($acknowledged['status'] ?? '') === 'passed') {
                            return;
                        }
                        expect($browser->running(), 'Broker浏览器阶段提前退出：' . $stage);
                        usleep(50000);
                    } while (microtime(true) < $deadline);
                    throw new RuntimeException('Broker浏览器阶段超出有界等待：' . $stage);
                };
            }
            $brokerReport['observability'] = brokerObservability(
                $GLOBALS['brokerObservabilitySync'],
                $client,
                $request,
                $probeToken,
                $token,
                $nodeEnvironment,
                $node,
                $server,
                $checks,
                $base,
                $command,
                $browserStage
            );
        } else {
            $unconfigured = $request('GET', '/broker/health/ready', $probeToken, null, 503)['data'];
            expect(
                !$unconfigured['ready'] && $unconfigured['store']['state'] === 'unconfigured',
                '真实QoS0节点不能被当作可确认持久接收的就绪节点'
            );
        }
        expect($node->stop($observability ? 30.0 : 1.0)->successful(), '正常节点必须在有界排空后自行退出');
        $nodes = $request('GET', '/broker/nodes', $token, null, 200);
        expect($nodes['items'][0]['state'] === 'stopped', '正常停止未采样');
        $nodeLogs[] = $node->stdout() . $node->stderr();
        $previousRun = $nodes['items'][0]['run_id'];
        // 同节点重新启动只复用已正常停止的槽；运行身份必须改变。
        $node = new Process([...$command, 'broker:run'], $root, $nodeEnvironment);
        $deadline = microtime(true) + 10;
        do {
            expect($node->running(), '重启节点提前退出：' . $node->stderr());
            $nodes = $request('GET', '/broker/nodes?per_page=1', $token, null, 200);
            if ($nodes['items'][0]['run_id'] !== $previousRun && $nodes['items'][0]['state'] === 'reporting') {
                break;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        expect($nodes['total'] === 1 && $nodes['items'][0]['run_id'] !== $previousRun && $nodes['items'][0]['state'] === 'reporting', '重启未准确复用正常停止的运行槽');
        expect($request('GET', '/broker/nodes?page=2&per_page=1', $token, null, 200)['items'] === [], '分页越过实际节点应为空');
        expect($request('GET', '/broker/nodes?node_id=unknown-node', $token, null, 200)['total'] === 0, '节点筛选没有按实际标识限定');
        $encodedNodes = json_encode($nodes, JSON_THROW_ON_ERROR);
        expect(!str_contains($encodedNodes, $password) && !str_contains($encodedNodes, $token) && !str_contains($encodedNodes, $nodeEnvironment['BROKER_CLIENT_PASSWORD']), '节点观察泄漏身份秘密');
        expect($node->stop($observability ? 30.0 : 1.0)->successful(), '重启节点必须在有界排空后自行退出');
        expect($request('GET', '/broker/nodes', $token, null, 200)['items'][0]['state'] === 'stopped', '重启后的正常停止未采样');
        $nodeLogs[] = $node->stdout() . $node->stderr();
        $previousRun = $nodes['items'][0]['run_id'];
        // 直接持有本次子进程句柄以注入 SIGKILL，不能用正常关闭冒充崩溃。
        $crashedNode = proc_open([...$command, 'broker:run'], [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['file', $base . '/crash-node.log', 'a'], 2 => ['file', $base . '/crash-node.log', 'a']], $crashPipes, $root, $nodeEnvironment, ['bypass_shell' => true]);
        expect(is_resource($crashedNode), '无法启动崩溃观察节点');
        $deadline = microtime(true) + 10;
        do {
            expect(proc_get_status($crashedNode)['running'], '崩溃观察节点提前退出');
            $nodes = $request('GET', '/broker/nodes', $token, null, 200);
            if ($nodes['items'][0]['run_id'] !== $previousRun) {
                break;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        expect($nodes['items'][0]['run_id'] !== $previousRun && $nodes['items'][0]['state'] === 'reporting', '崩溃前缺少真实采样');
        $crashedRun = $nodes['items'][0]['run_id'];
        expect(proc_terminate($crashedNode, 9), '无法注入节点强制终止');
        $deadline = microtime(true) + 3;
        while (proc_get_status($crashedNode)['running'] && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(!proc_get_status($crashedNode)['running'], '强制终止后节点仍在运行');
        proc_close($crashedNode);
        $crashedNode = null;
        $deadline = microtime(true) + 18;
        do {
            $nodes = $request('GET', '/broker/nodes', $token, null, 200);
            if ($nodes['items'][0]['state'] === 'unreachable') {
                break;
            }
            usleep(200000);
        } while (microtime(true) < $deadline);
        expect($nodes['items'][0]['run_id'] === $crashedRun && $nodes['items'][0]['state'] === 'unreachable', '崩溃采样必须到期显示不可达，不能伪装正常停止');
        $staleMetrics = brokerMetrics($client->request('GET', '/broker/metrics', ['Authorization' => 'Bearer ' . $probeToken]));
        expect($staleMetrics['typeapp_broker_ready'] === 0 && $staleMetrics['typeapp_broker_node_reporting{slot="0"}'] === 0
            && !isset($staleMetrics['typeapp_broker_connections{slot="0"}']), '过期节点指标必须保留失联事实并停止输出旧连接量');
        $checks++;
        if ($observability) {
            $brokerReport['crashed_node_isolation'] = brokerIsolateObservedNode($GLOBALS['brokerObservabilitySync'], $command, $nodeEnvironment, $nodes['items'][0]);
            unset($brokerReport['crashed_node_isolation']['command']);
        }
        $node = new Process([...$command, 'broker:run'], $root, $nodeEnvironment);
        $deadline = microtime(true) + 10;
        do {
            expect($node->running(), '崩溃后新节点提前退出：' . $node->stderr());
            $nodes = $request('GET', '/broker/nodes?per_page=1', $token, null, 200);
            if ($nodes['total'] === ($observability ? 1 : 2)
                && (!$observability || ($nodes['items'][0]['run_id'] !== $crashedRun && $nodes['items'][0]['state'] === 'reporting'))) {
                break;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        $secondPage = $request('GET', '/broker/nodes?page=2&per_page=1', $token, null, 200);
        if ($observability) {
            expect($nodes['total'] === 1 && $nodes['items'][0]['run_id'] !== $crashedRun && $nodes['items'][0]['state'] === 'reporting'
                && $secondPage['items'] === [], '已证明隔离后新运行未正确回收采样槽');
        } else {
            expect($nodes['total'] === 2 && $nodes['items'][0]['run_id'] === $crashedRun && $nodes['items'][0]['state'] === 'unreachable'
                && $secondPage['items'][0]['run_id'] !== $crashedRun && $secondPage['items'][0]['state'] === 'reporting', '新运行覆盖了崩溃事实或相同节点分页不稳定');
        }
        expect($node->stop($observability ? 30.0 : 1.0)->successful(), '隔离恢复节点必须在有界排空后自行退出');
        $dsn = $driver === 'sqlite' ? 'sqlite:' . $base . '/identity.sqlite' : $driver . ':host=' . $environment['DB_HOST'] . ';port=' . $environment['DB_PORT'] . ';dbname=' . $environment['DB_DATABASE'];
        $inspection = new PDO($dsn, $environment['DB_USERNAME'] ?? null, $environment['DB_PASSWORD'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        try {
            $inspection->query('SELECT id FROM iot_users LIMIT 1');
            throw new RuntimeException('独立安装不能创建 IoT 人员表');
        } catch (PDOException) {
            $checks++;
        }
        $legacyAuditId = bin2hex(random_bytes(16));
        $legacyInsert = $inspection->prepare('INSERT INTO broker_audit (id, tenant_id, actor_id, action, subject_id, result, details, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $legacyInsert->execute([$legacyAuditId, null, $account['id'], 'identity.login', $account['id'], 'success', '{"context":"identity"}', time()]);
        $legacyDetail = $request('GET', '/broker/audit/' . $legacyAuditId, $token, null, 200)['item'];
        expect($legacyDetail['operation'] === null && $legacyDetail['operation_id'] === null && $legacyDetail['category'] === 'legacy'
            && $legacyDetail['details'] === ['context' => 'identity'], '旧独立身份审计必须可查，不能补造历史操作或权限');
        expect(
            (int) $inspection->query("SELECT COUNT(*) FROM broker_broker_operations o INNER JOIN broker_audit a ON a.operation_id = o.operation_id WHERE a.action LIKE 'identity.%' OR a.action = 'broker.resource.read'")->fetchColumn() === 0,
            '同步身份与轮询读取不能积累永久操作行'
        );
        $inspection->exec('UPDATE broker_users SET platform_admin = 0');
        $browserStage?->__invoke('denied');
        if ($browser !== null) {
            expect($browser->wait(10)->successful(), 'Broker浏览器验收没有正常结束');
            $brokerReport['browser'] = json_decode((string) file_get_contents($base . '/browser-report.json'), true, 64, JSON_THROW_ON_ERROR);
            expect(($brokerReport['browser']['status'] ?? '') === 'passed', 'Broker浏览器报告尚未通过');
        }
        $request('GET', '/broker/nodes', $token, null, 403);
        $request('GET', '/broker/auth/me', $token, null, 403);
        $request('GET', '/broker/audit?limit=1&cursor=' . rawurlencode($auditPage['next_cursor']), $token, null, 403);
        $request('GET', '/broker/audit/' . $loginAudit['id'], $token, null, 403);
        $request('POST', '/broker/auth/logout', $token, [], 200);
        $request('GET', '/broker/nodes', $token, null, 401);
        $audit = $inspection->query('SELECT action, result, details FROM broker_audit')->fetchAll(PDO::FETCH_ASSOC);
        expect(count(array_filter($audit, static fn (array $row): bool => $row['action'] === 'identity.login' && $row['result'] === 'denied')) === 2, '登录拒绝缺失审计');
        expect(!str_contains(json_encode($audit), $password) && !str_contains(json_encode($audit), $token), '管理审计泄漏秘密');
        $inspection = null;
        if ($observability) {
            $brokerReport['concurrent_quarantine'] = brokerConcurrentQuarantine(
                $GLOBALS['brokerObservabilitySync'],
                $client,
                $probeToken,
                $environment,
                $server,
                $checks,
                $base
            );
            $brokerReport['management_dependency_fault'] = brokerManagementUnavailable(
                $GLOBALS['brokerObservabilitySync'],
                $GLOBALS['brokerObservabilityDatabase'],
                $client,
                $probeToken,
                $checks
            );
        }
        $brokerReport['status'] = 'passed';
        $brokerReport['http_checks'] = $checks;
        $brokerReport['iot_tables_absent'] = true;
        $brokerReport['stopped_slot_reused_with_new_run'] = true;
        $brokerReport['crash_expires_without_reusing_unfenced_slot'] = true;
        $brokerReport['probe_identity_isolated'] = true;
        $brokerReport['stale_metrics_omitted'] = true;
        $brokerReport['audit'] = ['identity_and_resource_events' => true, 'legacy_identity_preserved' => true,
            'keyset_filters_and_authorization' => true, 'server_request_id' => true, 'sync_without_permanent_operations' => true];
    } catch (Throwable $failure) {
        $brokerReport['status'] = 'failed';
        $brokerReport['failure'] = ['type' => get_class($failure), 'file' => basename($failure->getFile()), 'line' => $failure->getLine()];
        throw $failure;
    } finally {
        $browser?->stop(5);
        if ($browser !== null) {
            file_put_contents($base . '/browser.log', str_replace([$password, $token ?? 'missing-token'], '<REDACTED>', $browser->stdout() . $browser->stderr()));
        }
        if (is_resource($crashedNode)) {
            proc_terminate($crashedNode, 9);
            proc_close($crashedNode);
        }
        $mqttClient?->stop();
        $node?->stop($observability ? 30.0 : 1.0);
        $server?->stop();
        file_put_contents($base . '/management.log', $server === null ? '' : $server->stdout() . $server->stderr());
        file_put_contents($base . '/node.log', implode("\n", $nodeLogs) . ($node === null ? '' : $node->stdout() . $node->stderr()));
        file_put_contents($base . '/verification.json', json_encode($brokerReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            if (in_array($entry->getPathname(), [$base . '/verification.json', $base . '/management.log', $base . '/node.log', $base . '/crash-node.log', $base . '/observability.json', $base . '/browser-report.json', $base . '/browser.log'], true)
                || ($entry->isFile() && preg_match('/^browser-[a-z0-9-]+\.png$/D', $entry->getFilename()) === 1)) {
                continue;
            }
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
    }
    echo '独立 Broker 管理通过：' . $base . "/verification.json\n";
    return;
}
$auditBrowserOptions = array_values(array_filter($argv, static fn (string $option): bool => str_starts_with($option, '--browser-dist=')));
expect(count($auditBrowserOptions) <= 1, '审计浏览器产物只能指定一次');
$auditBrowserDist = $auditBrowserOptions === [] ? '' : substr($auditBrowserOptions[0], 15);
expect(
    $auditBrowserOptions === [] || ($driver === 'sqlite' && in_array('--broker-audit', $argv, true) && $auditBrowserDist !== '' && is_file($auditBrowserDist . '/index.html')),
    'IoT浏览器装置需要sqlite、--broker-audit和本轮独立前端产物；三库HTTP另行验证'
);
identityCommand([...$command, 'migrate', 'run'], $environment);
if (in_array('--iot-audit-fence', $argv, true)) {
    require_once __DIR__ . '/broker-observability.php';
    expect(
        $driver === 'pgsql' && ($GLOBALS['brokerObservabilitySync'] ?? null) instanceof PostgresSync,
        'IoT隔离审计命令必须由专属真实同步主备装置启动'
    );
    $environment['IOT_MQTT_COMMAND'] = json_encode($command, JSON_THROW_ON_ERROR);
    $environment['IOT_MQTT_STANDBY'] = 'broker_observe_sync';
    $fenceReport = ['status' => 'running', 'native' => $target !== '--php', 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'),
        'driver' => $driver, 'binary_sha256' => $target === '--php' ? null : hash_file('sha256', $target),
        'no_source' => $noSource ? 'kernel-denied-production-and-generated-source' : 'not-verified'];
    try {
        $fenceReport['iot_fence_audit'] = iotFenceAuditCommands($GLOBALS['brokerObservabilitySync'], $command, $environment, $base);
        $fenceReport['status'] = 'passed';
    } catch (Throwable $failure) {
        $fenceReport['status'] = 'failed';
        $fenceReport['failure'] = ['type' => get_class($failure), 'message' => $failure->getMessage(), 'line' => $failure->getLine()];
        throw $failure;
    } finally {
        file_put_contents($base . '/verification.json', json_encode($fenceReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
    echo 'IoT节点隔离原生命令通过：' . $base . "/verification.json\n";
    return;
}
$password = bin2hex(random_bytes(16));
$accounts = [];
foreach (['platform', 'alice', 'bob', 'carol', 'outsider'] as $login) {
    $seedEnvironment = $environment + ['IOT_USER_PASSWORD' => $password];
    $accounts[$login] = identityCommand([...$command, 'iot:user', $login, '测试-' . $login, ...($login === 'platform' ? ['platform'] : [])], $seedEnvironment)['data'];
    expect(!str_contains(json_encode($accounts[$login]), $password), '账号初始化不应返回密码');
}
$listener = stream_socket_server('tcp://127.0.0.1:0', $number, $error);
expect(is_resource($listener), '无法选择身份测试端口');
$address = stream_socket_get_name($listener, false);
fclose($listener);
$environment['APP_PORT'] = substr(strrchr($address, ':'), 1);
$environment['APP_ALLOWED_HOSTS'] = $address;
$server = new Process([...$command, 'serve'], $root, $environment);
$client = new HttpClient('http://' . $address);
$checks = 0;
$request = static function (string $method, string $path, string $token, ?string $tenant, ?array $data, int $status, ?string $code = null, string $supportId = '') use ($client, &$checks): array {
    $headers = ['Content-Type' => 'application/json'];
    if ($token !== '') {
        $headers['Authorization'] = 'Bearer ' . $token;
    }
    if ($tenant !== null) {
        $headers['X-Tenant-Id'] = $tenant;
    }
    if ($supportId !== '') {
        $headers['X-Support-Id'] = $supportId;
    }
    $response = $client->request($method, $path, $headers, $data === null ? '' : json_encode($data === [] ? (object) [] : $data, JSON_THROW_ON_ERROR));
    $body = $response->json();
    expect($response->status === $status && ($code === null || ($body['error'] ?? '') === $code), $method . ' ' . $path . ' 预期 ' . $status . '/' . ($code ?? '') . '，实际 ' . $response->status . ' ' . $response->body);
    $checks++;
    return $body;
};
try {
    $ready = false;
    $deadline = microtime(true) + 10;
    do {
        expect($server->running(), '身份 HTTP 提前退出：' . $server->stderr());
        try {
            $ready = $client->request('GET', '/readyz')->status === 200;
        } catch (RuntimeException) {
        }
        if (!$ready) {
            usleep(10000);
        }
    } while (!$ready && microtime(true) < $deadline);
    expect($ready, '身份 HTTP 未就绪');
    $request('GET', '/demo', '', null, null, 401);
    $request('GET', '/demo', $environment['APP_API_TOKEN'], null, null, 200);
    $request('GET', '/iot/auth/me', $environment['APP_API_TOKEN'], null, null, 401);
    $request('POST', '/iot/auth/login', '', null, ['login' => 'not-there', 'password' => $password], 401, 'invalid_credentials');
    $tokens = [];
    foreach (array_keys($accounts) as $login) {
        $result = $request('POST', '/iot/auth/login', '', null, ['login' => $login, 'password' => $password], 200)['data'];
        expect($result['user']['id'] === $accounts[$login]['id'] && preg_match('/^[a-f0-9]{64}$/D', $result['accessToken']) === 1, '登录身份或令牌无效');
        $tokens[$login] = $result['accessToken'];
    }
    $request('GET', '/users', $tokens['alice'], null, null, 401);
    $request('POST', '/iot/tenants', $tokens['alice'], null, ['name' => '不允许', 'owner_login' => 'alice'], 403, 'platform_forbidden');
    $tenantA = $request('POST', '/iot/tenants', $tokens['platform'], null, ['name' => '租户甲', 'owner_login' => 'alice'], 201)['data']['id'];
    $tenantB = $request('POST', '/iot/tenants', $tokens['platform'], null, ['name' => '租户乙', 'owner_login' => 'bob'], 201)['data']['id'];
    expect($request('GET', '/iot/tenants?platform=1', $tokens['platform'], null, null, 200)['total'] === 2, '平台租户管理列表错误');
    expect($request('GET', '/iot/tenants', $tokens['platform'], null, null, 200)['total'] === 0, '平台不应自动成为租户成员');
    $mine = $request('GET', '/iot/tenants', $tokens['alice'], null, null, 200);
    expect($mine['total'] === 1 && $mine['items'][0]['id'] === $tenantA, '租户查询串用成员');
    $request('GET', '/iot/tenants?platform=1', $tokens['alice'], null, null, 403, 'platform_forbidden');
    $pathA = '/iot/tenants/' . $tenantA;
    $pathB = '/iot/tenants/' . $tenantB;
    $request('GET', $pathA . '/members', $tokens['alice'], null, null, 403, 'tenant_context_mismatch');
    $request('GET', $pathA . '/members', $tokens['alice'], $tenantB, null, 403, 'tenant_context_mismatch');
    $request('GET', $pathA . '/members', $tokens['platform'], $tenantA, null, 403, 'tenant_forbidden');
    $request('GET', $pathB . '/members', $tokens['alice'], $tenantB, null, 403, 'tenant_forbidden');
    $context = $request('GET', '/iot/auth/me', $tokens['alice'], $tenantA, null, 200)['data']['context'];
    expect($context['role'] === 'admin' && in_array('command.query', $context['permissions'], true), '管理员应包含操作权限');
    $bob = $request('POST', $pathA . '/members', $tokens['alice'], $tenantA, ['login' => 'bob', 'role' => 'readonly'], 201)['data'];
    $carol = $request('POST', $pathA . '/members', $tokens['alice'], $tenantA, ['login' => 'carol', 'role' => 'operator'], 201)['data'];
    $request('POST', $pathA . '/members', $tokens['alice'], $tenantA, ['login' => 'bob', 'role' => 'admin'], 409, 'member_exists');
    $request('POST', $pathA . '/members', $tokens['alice'], $tenantA, ['login' => 'missing', 'role' => 'admin'], 422, 'account_not_available');
    $request('POST', $pathA . '/members', $tokens['alice'], $tenantA, ['login' => 'outsider', 'role' => 'platform_admin'], 422);
    $members = $request('GET', $pathA . '/members?per_page=1', $tokens['alice'], $tenantA, null, 200);
    expect($members['total'] === 3 && count($members['items']) === 1 && $members['per_page'] === 1, '成员分页应有界');
    $request('GET', $pathA . '/members?per_page=101', $tokens['alice'], $tenantA, null, 422);
    $readonly = $request('GET', '/iot/auth/me', $tokens['bob'], $tenantA, null, 200)['data']['context'];
    expect($readonly['role'] === 'readonly' && !in_array('command.query', $readonly['permissions'], true) && in_array('command.read', $readonly['permissions'], true), '只读不能主动查询设备或合并其他租户管理员角色');
    $request('PATCH', $pathA . '/members/' . $carol['id'], $tokens['bob'], $tenantA, ['version' => 1, 'role' => 'admin'], 403, 'tenant_forbidden');
    $request('PATCH', $pathA . '/members/' . $carol['id'], $tokens['carol'], $tenantA, ['version' => 1, 'role' => 'admin'], 403, 'tenant_forbidden');
    $request('PATCH', $pathB . '/members/' . $bob['id'], $tokens['bob'], $tenantB, ['version' => 1, 'role' => 'admin'], 404, 'member_not_found');
    $changed = $request('PATCH', $pathA . '/members/' . $bob['id'], $tokens['alice'], $tenantA, ['version' => 1, 'role' => 'admin'], 200)['data'];
    expect($changed['version'] === 2, '成员更新应推进版本');
    $request('PATCH', $pathA . '/members/' . $bob['id'], $tokens['alice'], $tenantA, ['version' => 1, 'role' => 'readonly'], 409, 'stale_version');
    $request('DELETE', $pathA . '/members/' . $bob['id'], $tokens['alice'], $tenantA, ['version' => 1], 409, 'stale_version');
    $alice = $request('GET', $pathA . '/members?login=alice', $tokens['alice'], $tenantA, null, 200)['items'][0];
    $request('PATCH', $pathA . '/members/' . $alice['id'], $tokens['bob'], $tenantA, ['version' => 1, 'role' => 'readonly'], 200);
    $request('POST', $pathA . '/members', $tokens['alice'], $tenantA, ['login' => 'outsider', 'role' => 'admin'], 403, 'tenant_forbidden');
    $request('DELETE', $pathA . '/members/' . $bob['id'], $tokens['bob'], $tenantA, ['version' => 2], 409, 'last_tenant_admin');
    $request('PATCH', $pathA . '/members/' . $bob['id'], $tokens['bob'], $tenantA, ['version' => 2, 'role' => 'operator'], 409, 'last_tenant_admin');
    $request('DELETE', $pathA . '/members/' . $carol['id'], $tokens['bob'], $tenantA, ['version' => 1], 200);
    $request('GET', '/iot/auth/me', $tokens['carol'], $tenantA, null, 403, 'tenant_forbidden');
    if (in_array('--broker-audit', $argv, true)) {
        $brokerAuditPath = $pathA . '/broker/audit';
        expect($request('GET', '/iot/broker/audit', $tokens['platform'], null, null, 200)['items'] === [], '平台Broker审计不能混入旧IoT人员和成员事件');
        $request('GET', $brokerAuditPath, '', $tenantA, null, 401);
        $request('GET', $brokerAuditPath, $tokens['alice'], null, null, 403, 'tenant_context_mismatch');
        $request('GET', $brokerAuditPath, $tokens['alice'], $tenantB, null, 403, 'tenant_context_mismatch');
        $request('GET', $brokerAuditPath, $tokens['outsider'], $tenantA, null, 403, 'tenant_forbidden');
        $request('GET', $brokerAuditPath, $tokens['platform'], $tenantA, null, 403, 'tenant_forbidden');
        $request('GET', '/iot/broker/audit', $tokens['alice'], null, null, 403, 'platform_forbidden');
        $request('GET', '/iot/broker/audit', $tokens['platform'], $tenantA, null, 403, 'platform_forbidden');
        $request('GET', $pathA . '/broker/resources/connections', $tokens['alice'], $tenantA, null, 200);
        $request('GET', $pathA . '/broker/resources/connections', $tokens['bob'], $tenantA, null, 200);
        $request('GET', $pathB . '/broker/resources/connections', $tokens['bob'], $tenantB, null, 200);
        $request('GET', '/iot/broker/resources/connections', $tokens['platform'], null, null, 200);
        $tenantEvents = $request('GET', $brokerAuditPath, $tokens['alice'], $tenantA, null, 200);
        expect(count($tenantEvents['items']) === 2 && $tenantEvents['total'] === null
            && array_unique(array_column($tenantEvents['items'], 'tenant_id')) === [$tenantA], '租户Broker审计必须精确隔离当前租户');
        $tenantEvent = $tenantEvents['items'][0];
        $tenantDetail = $request('GET', $brokerAuditPath . '/' . $tenantEvent['id'], $tokens['alice'], $tenantA, null, 200)['item'];
        expect($tenantDetail['operation']['tenant_id'] === $tenantA && $tenantDetail['operation']['authorization']['source'] === 'tenant-member'
            && $tenantDetail['operation']['authorization']['support_id'] === null
            && $tenantDetail['operation']['current_result'] === 'success', '详情应保存当时唯一成员来源和已知同步结果');
        $request('GET', $pathB . '/broker/audit/' . $tenantEvent['id'], $tokens['bob'], $tenantB, null, 404, 'broker_audit_not_found');
        $platformEvents = $request('GET', '/iot/broker/audit', $tokens['platform'], null, null, 200);
        expect(
            count($platformEvents['items']) === 4 && array_unique(array_column($platformEvents['items'], 'category')) === ['broker']
            && count(array_filter($platformEvents['items'], static fn (array $row): bool => $row['tenant_id'] === null)) === 1,
            '平台只能查询明确Broker分类并保留平台与租户的真实归属'
        );
        $rolePage = $request('GET', $brokerAuditPath . '?limit=1', $tokens['alice'], $tenantA, null, 200);
        expect($rolePage['has_more'] && is_string($rolePage['next_cursor']), '权限变化测试需要真实下一页游标');
        $request('GET', $pathB . '/broker/audit?limit=1&cursor=' . rawurlencode($rolePage['next_cursor']), $tokens['bob'], $tenantB, null, 400, 'broker_audit_cursor_invalid');
        $changedRole = $request('PATCH', $pathA . '/members/' . $alice['id'], $tokens['bob'], $tenantA, ['version' => 2, 'role' => 'operator'], 200)['data'];
        $request('GET', $brokerAuditPath . '?limit=1&cursor=' . rawurlencode($rolePage['next_cursor']), $tokens['alice'], $tenantA, null, 400, 'broker_audit_cursor_invalid');
        $request('PATCH', $pathA . '/members/' . $alice['id'], $tokens['bob'], $tenantA, ['version' => $changedRole['version'], 'role' => 'readonly'], 200);
        $grant = $request('POST', $pathA . '/support-grants', $tokens['bob'], $tenantA, [
            'id' => bin2hex(random_bytes(16)), 'login' => 'platform', 'duration_seconds' => 120, 'reason' => 'Broker审计授权验收',
        ], 200)['data'];
        $supportPage = $request('GET', $brokerAuditPath . '?limit=1', $tokens['platform'], $tenantA, null, 200, null, $grant['id']);
        $request('GET', $brokerAuditPath . '/' . $tenantEvent['id'], $tokens['platform'], $tenantA, null, 200, null, $grant['id']);
        $request('GET', $brokerAuditPath . '?limit=1&cursor=' . rawurlencode($rolePage['next_cursor']), $tokens['platform'], $tenantA, null, 400, 'broker_audit_cursor_invalid', $grant['id']);
        $request('GET', '/iot/broker/audit', $tokens['platform'], null, null, 403, null, $grant['id']);
        $request('GET', $pathA . '/broker/resources/connections', $tokens['platform'], $tenantA, null, 200, null, $grant['id']);
        $supportEvents = $request('GET', $brokerAuditPath . '?actor_id=' . $accounts['platform']['id'], $tokens['bob'], $tenantA, null, 200)['items'];
        expect(count($supportEvents) === 1, 'Broker类别不能混入支持授权查询自身的旧IoT事件');
        $supportOperation = $request('GET', $brokerAuditPath . '/' . $supportEvents[0]['id'], $tokens['bob'], $tenantA, null, 200)['item']['operation'];
        expect($supportOperation['authorization']['source'] === 'tenant-support' && $supportOperation['authorization']['support_id'] === $grant['id']
            && $supportOperation['authorization']['support_version'] === $grant['version']
            && $supportOperation['authorization']['support_expires_at'] === $grant['expires_at'], '真实支持读取应冻结授权ID、版本和期限');
        $request('GET', '/iot/broker/resources/connections', $tokens['platform'], $tenantA, null, 403, 'platform_forbidden', $grant['id']);
        $deniedSupportEvents = $request('GET', $brokerAuditPath . '?actor_id=' . $accounts['platform']['id'] . '&result=denied', $tokens['bob'], $tenantA, null, 200)['items'];
        expect(count($deniedSupportEvents) === 1, '支持访问平台资源被拒必须保留403及唯一租户拒绝事件');
        $deniedSupportOperation = $request('GET', $brokerAuditPath . '/' . $deniedSupportEvents[0]['id'], $tokens['bob'], $tenantA, null, 200)['item']['operation'];
        expect($deniedSupportOperation['tenant_id'] === $tenantA && $deniedSupportOperation['authorization']['source'] === 'tenant-support'
            && $deniedSupportOperation['authorization']['support_id'] === $grant['id'] && $deniedSupportOperation['authorization']['permissions'] === []
            && $deniedSupportOperation['current_result'] === 'denied', '拒绝事件保留唯一支持来源但不能补造获准权限');
        $request('DELETE', $pathA . '/support-grants/' . $grant['id'], $tokens['bob'], $tenantA, ['version' => $grant['version']], 200);
        $request('GET', $brokerAuditPath . '?limit=1&cursor=' . rawurlencode($supportPage['next_cursor']), $tokens['platform'], $tenantA, null, 403, 'support_forbidden', $grant['id']);
        $request('GET', $brokerAuditPath . '/' . $tenantEvent['id'], $tokens['platform'], $tenantA, null, 403, 'support_forbidden', $grant['id']);
        $expiredGrant = $request('POST', $pathA . '/support-grants', $tokens['bob'], $tenantA, [
            'id' => bin2hex(random_bytes(16)), 'login' => 'platform', 'duration_seconds' => 120, 'reason' => 'Broker审计到期验收',
        ], 200)['data'];
        $request('GET', $brokerAuditPath . '?limit=1&cursor=' . rawurlencode($supportPage['next_cursor']), $tokens['platform'], $tenantA, null, 400, 'broker_audit_cursor_invalid', $expiredGrant['id']);
        $auditDsn = $driver === 'sqlite' ? 'sqlite:' . $base . '/identity.sqlite' : $driver . ':host=' . $environment['DB_HOST'] . ';port=' . $environment['DB_PORT'] . ';dbname=' . $environment['DB_DATABASE'];
        $auditDatabase = new PDO($auditDsn, $environment['DB_USERNAME'] ?? null, $environment['DB_PASSWORD'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $expireGrant = $auditDatabase->prepare('UPDATE iot_support_grants SET expires_at = ? WHERE id = ?');
        $expireGrant->execute([time() - 1, $expiredGrant['id']]);
        $request('GET', $brokerAuditPath . '/' . $tenantEvent['id'], $tokens['platform'], $tenantA, null, 403, 'support_forbidden', $expiredGrant['id']);
        expect((int) $auditDatabase->query('SELECT COUNT(*) FROM iot_broker_operations')->fetchColumn() === 0, 'IoT同步资源查询不能留下永久操作行');
        $storedBrokerAudit = json_encode($auditDatabase->query("SELECT details FROM iot_audit WHERE category = 'broker'")->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR);
        expect(!str_contains($storedBrokerAudit, $password) && !str_contains($storedBrokerAudit, $tokens['platform'])
            && !str_contains($storedBrokerAudit, 'password_hash'), 'Broker持久审计不得保存口令或人员令牌');
        $auditDatabase = null;
        $request('GET', $pathA . '/broker/resources/subscriptions/' . str_repeat('a', 64), $tokens['bob'], $tenantA, null, 503, 'broker_resource_store_unavailable');
        $failedBrokerReads = $request('GET', $brokerAuditPath . '?action=broker.resource.read&result=failed', $tokens['bob'], $tenantA, null, 200)['items'];
        expect(count($failedBrokerReads) === 1 && $failedBrokerReads[0]['details'] === ['reason' => 'broker_resource_store_unavailable'], 'IoT持久查询归还连接后仍须保留原503和失败审计');
        $brokerAuditEvidence = ['platform_category_and_tenant_isolation' => true, 'detail_reauthorized' => true,
            'cursor_bound_to_role_and_support' => true, 'revoked_and_expired_support_denied' => true, 'sync_without_permanent_operations' => true,
            'released_query_connection_reacquired_for_failure_audit' => true];
        $brokerAuditEvidence['rejected_platform_support_context_audited_with_verified_tenant'] = true;
        if ($auditBrowserDist !== '') {
            $platformEvent = array_values(array_filter($platformEvents['items'], static fn (array $row): bool => $row['tenant_id'] === null))[0];
            $otherEvent = $request('GET', $pathB . '/broker/audit', $tokens['bob'], $tenantB, null, 200)['items'][0];
            $brokerAuditEvidence['browser'] = identityAuditBrowser($command, $environment, $base, $driver, $auditBrowserDist, [
                'accounts' => ['standalone' => ['login' => 'audit-broker', 'password' => bin2hex(random_bytes(16))],
                    'tenant' => ['login' => 'bob', 'password' => $password], 'platform' => ['login' => 'platform', 'password' => $password]],
                'tenants' => ['primary' => ['id' => $tenantA, 'name' => '租户甲'], 'other' => ['id' => $tenantB, 'name' => '租户乙']],
                'events' => ['platform' => $platformEvent['id'], 'other' => $otherEvent['id']], 'tokens' => ['tenant' => $tokens['bob']],
            ], $request);
        }
    }
    if (in_array('--history', $argv, true)) {
        require __DIR__ . '/iot-history.php';
        $historyDsn = $driver === 'sqlite' ? 'sqlite:' . $base . '/identity.sqlite' : $driver . ':host=' . $environment['DB_HOST'] . ';port=' . $environment['DB_PORT'] . ';dbname=' . $environment['DB_DATABASE'];
        $historyDatabase = new PDO($historyDsn, $environment['DB_USERNAME'] ?? null, $environment['DB_PASSWORD'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $historyEvidence = iotHistoryChecks($request, $tokens, $tenantA, $tenantB, $historyDatabase, $command, $environment, $base);
        $historyDatabase = null;
    }
    if (in_array('--aggregate', $argv, true)) {
        require __DIR__ . '/iot-aggregate.php';
        $aggregateDsn = $driver === 'sqlite' ? 'sqlite:' . $base . '/identity.sqlite' : $driver . ':host=' . $environment['DB_HOST'] . ';port=' . $environment['DB_PORT'] . ';dbname=' . $environment['DB_DATABASE'];
        $aggregateDatabase = new PDO($aggregateDsn, $environment['DB_USERNAME'] ?? null, $environment['DB_PASSWORD'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $aggregateEvidence = iotAggregateChecks($request, $tokens, $tenantA, $tenantB, $aggregateDatabase, $command, $environment, $base);
        $aggregateDatabase = null;
    }
    if (in_array('--support', $argv, true)) {
        require __DIR__ . '/iot-support.php';
        require_once __DIR__ . '/native-rollout-redis.php';
        $supportDsn = $driver === 'sqlite' ? 'sqlite:' . $base . '/identity.sqlite' : $driver . ':host=' . $environment['DB_HOST'] . ';port=' . $environment['DB_PORT'] . ';dbname=' . $environment['DB_DATABASE'];
        $supportDatabase = new PDO($supportDsn, $environment['DB_USERNAME'] ?? null, $environment['DB_PASSWORD'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $supportEvidence = iotSupportChecks($request, $tokens, $tenantA, $tenantB, $supportDatabase, $command, $environment, $base, $client);
        $supportDatabase = null;
    }
    if (in_array('--history', $argv, true) || in_array('--aggregate', $argv, true) || in_array('--support', $argv, true)) {
        $server->stop();
        expect(!$server->running(), '持久化验证重启前HTTP未退出');
        $server = new Process([...$command, 'serve'], $root, $environment);
        $restarted = false;
        $restartDeadline = microtime(true) + 10;
        do {
            expect($server->running(), 'HTTP重启失败：' . $server->stderr());
            try {
                $restarted = $client->request('GET', '/readyz')->status === 200;
            } catch (RuntimeException) {
            }
            if (!$restarted) {
                usleep(10000);
            }
        } while (!$restarted && microtime(true) < $restartDeadline);
        expect($restarted, 'HTTP重启未就绪');
        if (in_array('--history', $argv, true)) {
            $restored = $request('GET', $historyEvidence['path'], $tokens['alice'], $tenantA, null, 200);
            expect($restored['items'] === $historyEvidence['items'], 'HTTP重启后原始值、模型、单位、归属和双时间必须保持');
        }
        if (in_array('--aggregate', $argv, true)) {
            $restoredMinutes = $request('GET', $aggregateEvidence['path'], $tokens['alice'], $tenantA, null, 200);
            expect($restoredMinutes['items'] === $aggregateEvidence['items'], 'HTTP重启后聚合、冻结模型及历史归属必须保持');
        }
        if (in_array('--support', $argv, true)) {
            $request('GET', '/iot/auth/me', $tokens['platform'], $tenantA, null, 403, 'support_forbidden', $supportEvidence['revoked_id']);
            expect($request('GET', '/iot/auth/me', $tokens['platform'], $tenantA, null, 200)['data']['context']['role'] === 'admin', '重启后撤销支持仍不能借成员权限恢复');
        }
    }
    if (in_array('--load-baseline', $argv, true)) {
        require __DIR__ . '/iot-load.php';
        $loadEvidence = iotLoadPrepare($base, 'http://' . $address, $password);
        if (in_array('--load-history', $argv, true)) {
            expect($driver === 'pgsql', '历史负载预置专项需要隔离PostgreSQL');
            $loadEvidence['history'] = iotLoadHistory($base, $environment, $password);
        }
    }
    $request('POST', '/iot/auth/logout', $tokens['bob'], null, [], 200);
    $request('GET', '/iot/auth/me', $tokens['bob'], null, null, 401);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $request('POST', '/iot/auth/login', '', null, ['login' => 'outsider', 'password' => 'wrong-password'], 401, 'invalid_credentials');
    }
    $request('POST', '/iot/auth/login', '', null, ['login' => 'outsider', 'password' => $password], 401, 'invalid_credentials');
    $dsn = $driver === 'sqlite' ? 'sqlite:' . $base . '/identity.sqlite' : $driver . ':host=' . $environment['DB_HOST'] . ';port=' . $environment['DB_PORT'] . ';dbname=' . $environment['DB_DATABASE'];
    $database = new PDO($dsn, $environment['DB_USERNAME'] ?? null, $environment['DB_PASSWORD'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $database->exec('UPDATE iot_sessions SET expires_at = 0');
    $request('GET', '/iot/auth/me', $tokens['alice'], null, null, 401);
    $stored = json_encode($database->query('SELECT * FROM iot_sessions')->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR);
    expect(!str_contains($stored, $tokens['alice']), '服务端会话应仅保存令牌散列');
    $database = null;
    expect(!in_array('--recovery', $argv, true), '恢复验收须接入当前身份和设备装置');
    $server->stop();
    expect(!$server->running(), '身份 HTTP 未正常退出');
    $report = ['status' => 'passed', 'native' => $target !== '--php', 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'driver' => $driver, 'http_checks' => $checks,
        'binary_sha256' => $target === '--php' ? null : hash_file('sha256', $target), 'server_stopped' => true, 'audit_retention' => in_array('--audit', $argv, true), 'no_source' => $noSource ? 'kernel-denied-production-and-generated-source' : 'not-verified'];
    $report['history'] = isset($historyEvidence) ? $historyEvidence['checks'] : null;
    $report['aggregate'] = isset($aggregateEvidence) ? $aggregateEvidence['checks'] : null;
    $report['support'] = $supportEvidence ?? null;
    $report['operations'] = $operationsEvidence ?? null;
    $report['broker_audit'] = $brokerAuditEvidence ?? null;
    $report['recovery'] = $recoveryEvidence ?? null;
    $report['load_baseline'] = $loadEvidence ?? null;
    file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    echo ($target === '--php' ? 'PHP' : '原生') . ' 身份与租户 HTTP ' . $checks . ' 项通过：' . $base . "/verification.json\n";
} catch (Throwable $failure) {
    fwrite(STDERR, $server->stderr());
    throw $failure;
} finally {
    $server->stop();
}
