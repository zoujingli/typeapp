<?php

declare(strict_types=1);

/** 三库真实HTTP管理语义；受控无凭据装置只测试启用，不冒充Broker隔离证明。 */
function iotLifecycleChecks(Closure $request, array $tokens, string $tenantA, string $tenantB, PDO $database, array $evidence, array $environment, string $base, string $address, string $password): array
{
    $path = '/customer/tenants/' . $tenantA . '/devices';
    $admin = $tokens['bob'];
    $newDevice = static function (string $name) use ($request, $path, $admin, $tenantA, $evidence): array {
        return $request('POST', $path, $admin, $tenantA, ['name' => $name, 'product_id' => $evidence['device']['product_id'], 'model_version' => 1], 201)['data'];
    };
    $created = $newDevice('轮换测试');
    $id = $created['device']['id'];
    $payload = ['version' => 1, 'confirm_device_id' => $id];
    foreach (['alice', 'carol', 'outsider'] as $login) {
        $request('POST', $path . '/' . $id . '/rotate', $tokens[$login], $tenantA, $payload, 403);
    }
    $request('POST', '/customer/tenants/' . $tenantB . '/devices/' . $id . '/rotate', $admin, $tenantB, $payload, 404);
    $request('POST', $path . '/' . $id . '/rotate', $admin, $tenantA, array_replace($payload, ['confirm_device_id' => str_repeat('0', 32)]), 422, 'device_confirmation_required');
    $request('POST', $path . '/' . $id . '/rotate', $admin, $tenantA, $payload + ['lifecycle' => 'enabled'], 422, 'unexpected_field');
    $request('POST', $path . '/' . $id . '/rotate', $admin, $tenantA, array_replace($payload, ['version' => 2]), 409, 'stale_version');
    $rotated = $request('POST', $path . '/' . $id . '/rotate', $admin, $tenantA, $payload, 202)['data'];
    expect($rotated['device']['authorization']['status'] === 'pending' && $rotated['device']['authorization']['completed_at'] === null
        && $rotated['device']['lifecycle'] === 'inactive' && (int) $rotated['device']['version'] === 2, '轮换只能声明持久意图，不能伪造Broker完成或设备激活');
    expect($rotated['credential']['password'] !== $created['credential']['password'] && $rotated['credential']['username'] !== $created['credential']['username'], '轮换须产生独立秘密与主体');
    $credentials = $database->prepare('SELECT id, secret_hash, status, revoked_at FROM iot_device_credentials WHERE device_id = ? ORDER BY id');
    $credentials->execute([$id]);
    $stored = $credentials->fetchAll(PDO::FETCH_ASSOC);
    expect(count($stored) === 2 && count(array_filter($stored, static fn (array $row): bool => $row['status'] === 'active')) === 1, '必须只有一个当前凭据');
    foreach ($stored as $row) {
        if ($row['id'] === $rotated['credential']['id']) {
            expect($row['secret_hash'] === hash('sha256', $rotated['credential']['password']) && $row['revoked_at'] === null, '新秘密仅不可逆存储');
        } else {
            expect($row['status'] === 'revoked' && $row['revoked_at'] !== null, '旧凭据必须立即撤销');
        }
    }
    $request('POST', $path . '/' . $id . '/rotate', $admin, $tenantA, array_replace($payload, ['version' => 2]), 409, 'device_authorization_pending');
    $public = $request('GET', $path . '/' . $id, $tokens['alice'], $tenantA, null, 200)['data'];
    expect($public['credential_active'] === true && $public['authorization'] === $rotated['device']['authorization'], '普通详情必须明确显示当前凭据可用及未完成撤权');
    $auditQuery = $database->prepare('SELECT * FROM customer_audit WHERE subject_id = ?');
    $auditQuery->execute([$id]);
    $audit = $auditQuery->fetchAll(PDO::FETCH_ASSOC);
    foreach ([$created['credential']['password'], $rotated['credential']['password'], hash('sha256', $rotated['credential']['password'])] as $secret) {
        expect(!str_contains(json_encode([$public, $audit], JSON_THROW_ON_ERROR), $secret), '详情或审计泄漏凭据');
    }
    foreach (['revoke', 'disable', 'retire'] as $action) {
        $candidate = $newDevice('管理动作-' . $action);
        $deviceId = $candidate['device']['id'];
        $changed = $request('POST', $path . '/' . $deviceId . '/' . $action, $admin, $tenantA, ['version' => 1, 'confirm_device_id' => $deviceId], 202)['data']['device'];
        expect($changed['credential_active'] === false && $changed['authorization']['status'] === 'pending', '禁用/吊销/退役均须撤旧凭据并保留隔离意图');
        expect($changed['connection']['status'] === 'unknown', '管理动作不能伪造真实离线观察');
        expect($changed['lifecycle'] === ($action === 'revoke' ? 'inactive' : ($action === 'disable' ? 'disabled' : 'retired')), '生命周期错误');
        if ($action === 'retire') {
            $request('POST', $path . '/' . $deviceId . '/enable', $admin, $tenantA, ['version' => 2, 'confirm_device_id' => $deviceId], 409, 'device_retired');
            $request('DELETE', $path . '/' . $deviceId, $admin, $tenantA, null, 405);
        }
    }
    $empty = $newDevice('无有效凭据设备');
    $deviceId = $empty['device']['id'];
    // 装置预设凭据此前已经撤销、从未建立会话；此处没有写Broker完成事实。
    $statement = $database->prepare("UPDATE iot_device_credentials SET status = 'revoked', revoked_at = ? WHERE device_id = ?");
    $statement->execute([time(), $deviceId]);
    $request('POST', $path . '/' . $deviceId . '/revoke', $admin, $tenantA, ['version' => 1, 'confirm_device_id' => $deviceId], 409, 'device_credential_revoked');
    $disabled = $request('POST', $path . '/' . $deviceId . '/disable', $admin, $tenantA, ['version' => 1, 'confirm_device_id' => $deviceId], 200)['data']['device'];
    $enabled = $request('POST', $path . '/' . $deviceId . '/enable', $admin, $tenantA, ['version' => 2, 'confirm_device_id' => $deviceId], 200)['data']['device'];
    expect($disabled['lifecycle'] === 'disabled' && $enabled['lifecycle'] === 'enabled' && !$enabled['credential_active'], '启用不能复活已吊销凭据');
    $rekeyed = $request('POST', $path . '/' . $deviceId . '/rotate', $admin, $tenantA, ['version' => 3, 'confirm_device_id' => $deviceId], 200)['data'];
    expect($rekeyed['credential'] !== null && $rekeyed['device']['credential_active'], '无凭据设备可以显式重新授权');
    $checks = ['authorization-confirmation-version', 'single-current-secret-hash', 'pending-not-enforced', 'disable-revoke-retire', 'retired-no-reactivation', 'enable-no-revival'];
    // 两端每个敏感动作单独授予：没有查询权限也能对已知目标执行获准动作，其他节点保持拒绝。
    foreach (['admin', 'customer'] as $realm) {
        $actor = $realm === 'admin' ? $tokens['platform'] : $admin;
        $scope = $realm === 'admin' ? null : $tenantA;
        foreach (['enable', 'disable', 'retire', 'rotate', 'revoke'] as $action) {
            $role = $request('POST', '/' . $realm . '/roles', $actor, $scope, ['name' => '仅设备-' . $action, 'permissions' => [$realm . '.devices.' . $action]], 200)['data'];
            $request('POST', '/' . $realm . '/roles/' . $role['id'] . '/status', $actor, $scope, ['version' => 1, 'enabled' => true], 200);
            $login = 'device-' . $realm . '-' . $action;
            if ($realm === 'admin') {
                $person = $request('POST', '/admin/users', $actor, null, ['login' => $login, 'name' => $login, 'password' => $password], 200)['data'];
                $request('PUT', '/admin/users/roles', $actor, null, ['users' => [['id' => $person['id'], 'version' => 1]], 'roles' => [['id' => $role['id'], 'version' => 2]]], 200);
            } else {
                $request('POST', '/customer/members', $actor, $scope, ['login' => $login, 'new_customer' => true, 'account_name' => $login, 'password' => $password, 'name' => $login, 'roles' => [['id' => $role['id'], 'version' => 2]]], 200);
            }
            $token = $request('POST', '/' . $realm . '/auth/login', '', null, ['login' => $login, 'password' => $password], 200)['data']['accessToken'];
            $candidate = $newDevice('单节点-' . $realm . '-' . $action);
            $targetId = $candidate['device']['id'];
            $target = $realm === 'admin' ? '/admin/devices/' . $targetId : $path . '/' . $targetId;
            $payload = ['version' => 1, 'confirm_device_id' => $targetId];
            $request('GET', $target, $token, $scope, null, 403, 'permission_denied');
            $request('POST', $target . '/' . ($action === 'rotate' ? 'disable' : 'rotate'), $token, $scope, $payload, 403, 'permission_denied');
            $request('PATCH', $target, $token, $scope, ['version' => 1, 'name' => '越权资料'], 403, 'permission_denied');
            if ($action === 'enable') {
                // 装置中没有网络会话；只验证重新启用不复活此前吊销的凭据。
                $database->prepare("UPDATE iot_device_credentials SET status = 'revoked', revoked_at = ? WHERE device_id = ?")->execute([time(), $targetId]);
                $request('POST', $path . '/' . $targetId . '/disable', $admin, $tenantA, $payload, 200);
                $payload['version'] = 2;
            }
            $changed = $request('POST', $target . '/' . $action, $token, $scope, $payload, $action === 'enable' ? 200 : 202)['data']['device'];
            expect((int) $changed['version'] === $payload['version'] + 1, '独立敏感节点没有推进准确版本');
        }
    }
    $raced = $newDevice('等待设备锁的准确版本');
    $racedId = $raced['device']['id'];
    if ($environment['DB_DRIVER'] !== 'sqlite') {
        $clientCode = 'require $argv[1]."/vendor/autoload.php"; $client=new Type\\Testing\\HttpClient(getenv("TEST_LIFECYCLE_ORIGIN"),10); $response=$client->request("POST",getenv("TEST_LIFECYCLE_PATH"),["Content-Type"=>"application/json","Authorization"=>"Bearer ".getenv("TEST_LIFECYCLE_TOKEN"),"X-Tenant-Id"=>getenv("TEST_LIFECYCLE_TENANT")],getenv("TEST_LIFECYCLE_PAYLOAD")); $body=$response->json(); echo json_encode(["status"=>$response->status,"error"=>$body["error"]??null]);';
        $database->beginTransaction();
        $process = null;
        try {
            $database->prepare('UPDATE iot_devices SET version = version + 1 WHERE id = ?')->execute([$racedId]);
            $process = new Type\Testing\Process([PHP_BINARY, '-r', $clientCode, dirname(__DIR__)], dirname(__DIR__), array_replace($environment, [
                'TEST_LIFECYCLE_ORIGIN' => 'http://' . $address, 'TEST_LIFECYCLE_PATH' => $path . '/' . $racedId . '/rotate',
                'TEST_LIFECYCLE_TOKEN' => $admin, 'TEST_LIFECYCLE_TENANT' => $tenantA,
                'TEST_LIFECYCLE_PAYLOAD' => json_encode(['version' => 1, 'confirm_device_id' => $racedId], JSON_THROW_ON_ERROR)]));
            usleep(350000);
            expect($process->running(), '管理请求必须等待真实设备事务锁');
            $database->commit();
            $result = $process->wait(12);
            expect($result->successful(), '释放设备锁后请求异常');
            expect(json_decode($result->stdout, true, 8, JSON_THROW_ON_ERROR) === ['status' => 409, 'error' => 'stale_version'], '管理请求不能沿用等待设备锁之前的版本');
        } finally {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            $process?->stop();
        }
        $current = $request('GET', $path . '/' . $racedId, $admin, $tenantA, null, 200)['data'];
        expect($current['version'] === 2 && $current['authorization']['id'] === null, '旧版本请求产生了副作用');
        $checks[] = 'current-device-version-after-row-lock';
    }
    return $checks;
}

/** 通过既有接收公共接口准备三库历史；网络认证与同步回执由TLS专项另外验证。 */
function iotLifecycleHistory(array $registration, array $environment, string $base, string $sequence): array
{
    $driver = $environment['DB_DRIVER'];
    $adapter = match ($driver) {
        'mysql' => new Type\Orm\Mysql\MysqlDriver($environment['DB_HOST'], (int) $environment['DB_PORT'], $environment['DB_DATABASE'], $environment['DB_USERNAME'], $environment['DB_PASSWORD']),
        'pgsql' => new Type\Orm\Pgsql\PgsqlDriver($environment['DB_HOST'], (int) $environment['DB_PORT'], $environment['DB_DATABASE'], $environment['DB_USERNAME'], $environment['DB_PASSWORD']),
        default => new Type\Orm\Sqlite\SqliteDriver($base . '/identity.sqlite', 1000, true),
    };
    $pool = new Type\Orm\Database($adapter, 1, 1);
    $scope = new Type\Runtime\ExecutionScope();
    try {
        $connection = $pool->connect($scope);
        $device = $registration['device'];
        $received = time() - 2;
        $payload = json_encode(['app_version' => 1, 'type' => 'telemetry', 'device_id' => $device['id'], 'ownership_id' => $device['ownership_id'],
            'model_version' => 1, 'sequence' => $sequence, 'sampled_at' => $received, 'values' => ['temperature' => 21.5]], JSON_THROW_ON_ERROR);
        return $connection->transaction(static fn (Type\Orm\Connection $transaction): array => app\iot\service\IngestionService::accept(
            $transaction,
            $registration['credential']['topics']['publish'],
            $payload,
            1,
            $received
        ), $driver === 'sqlite' ? 'immediate' : 'default');
    } finally {
        $scope->close();
        $pool->close();
    }
}

/** 真实接收与退役业务组合，须独立验证遥测、生命周期与资源清理。 */
function iotRetirementHistoryChecks(Closure $request, array $tokens, string $tenantA, string $tenantB, PDO $database, array $candidate, array $environment, string $base): void
{
    $admin = $tokens['bob'];
    $path = '/customer/tenants/' . $tenantA . '/devices';
    $deviceId = $candidate['device']['id'];
    $fact = iotLifecycleHistory($candidate, $environment, $base, '1');
    expect($fact['code'] === 'accepted', '退役前必须通过真实接收服务保存可查询历史');
    $current = $request('GET', $path . '/' . $deviceId, $admin, $tenantA, null, 200)['data'];
    $request('POST', $path . '/' . $deviceId . '/retire', $admin, $tenantA, ['version' => $current['version'], 'confirm_device_id' => $deviceId], 202);
    $history = $request('GET', $path . '/' . $deviceId . '/history', $tokens['alice'], $tenantA, null, 200);
    expect($history['total'] === 1 && $history['items'][0]['message_id'] === $fact['message_id']
        && $history['items'][0]['ownership_id'] === $candidate['device']['ownership_id'], '退役后真实历史与原归属须继续保留');
    $request('GET', '/customer/tenants/' . $tenantB . '/devices/' . $deviceId . '/history', $admin, $tenantB, null, 404, 'device_not_found');
    $request('GET', $path . '/' . $deviceId . '/history', $tokens['platform'], $tenantA, null, 403, 'tenant_forbidden');
    expect(iotLifecycleHistory($candidate, $environment, $base, '2')['code'] === 'device_unavailable', '退役后不得接纳新的业务事实');
    expect($request('GET', $path . '/' . $deviceId . '/history', $tokens['alice'], $tenantA, null, 200)['total'] === 1, '被拒绝的新上报不得污染保留历史');
}
