<?php

declare(strict_types=1);

/** 无通信历史的转配经真实 HTTP 完成；持久隔离另由 MQTT 同步装置验证，不能互相替代。 */
function iotReassignmentChecks(Closure $request, PDO $database, string $driver, string $address, string $platform, string $password): array
{
    $call = static fn (string $method, string $path, string $token, ?array $body = null, int $status = 200, array $headers = [], string $error = ''): array
        => $request($method, $path, $token, $headers, $body, $status, $error);
    $call('GET', '/admin/reassignments', $platform);
    $source = bin2hex(random_bytes(16));
    $target = bin2hex(random_bytes(16));
    $other = bin2hex(random_bytes(16));
    foreach ([$source => 'relation-source', $target => 'relation-target', $other => 'relation-other'] as $tenant => $login) {
        $call('POST', '/admin/tenants', $platform, ['id' => $tenant, 'name' => $login, 'new_customer' => true,
            'owner_login' => $login, 'owner_name' => $login, 'owner_password' => $password]);
    }
    $sourceToken = $call('POST', '/customer/auth/login', '', ['login' => 'relation-source', 'password' => $password])['data']['accessToken'];
    $targetToken = $call('POST', '/customer/auth/login', '', ['login' => 'relation-target', 'password' => $password])['data']['accessToken'];
    $otherToken = $call('POST', '/customer/auth/login', '', ['login' => 'relation-other', 'password' => $password])['data']['accessToken'];
    $sourceHeaders = ['X-Tenant-Id' => $source];
    $targetHeaders = ['X-Tenant-Id' => $target];
    $enrollment = $call('POST', '/admin/device-authorizations', $platform, ['id' => bin2hex(random_bytes(16)), 'tenant_id' => $source,
        'name' => '转配验证资格', 'capacity' => 3, 'register_expires_at' => time() + 3600], 201)['data'];
    $register = static fn (string $name): array => $call('POST', '/public/devices/register', '', ['id' => bin2hex(random_bytes(16)), 'tenant_id' => $source,
        'device_id' => bin2hex(random_bytes(16)), 'name' => $name, 'code' => $enrollment['code']], 201)['data'];
    $registered = $register('未接入转配设备');
    $device = $registered['device'];
    $path = '/admin/devices/' . $device['id'] . '/usage/';
    $call('POST', $path . 'expiry', $sourceToken, ['version' => 1, 'expires_at' => time() + 60], 401);
    $call('POST', $path . 'expiry', $platform, ['version' => 1, 'expires_at' => time() - 1], 422, [], 'device_expiry_invalid');
    $expires = time() + 600;
    $saved = $call('POST', $path . 'expiry', $platform, ['version' => 1, 'expires_at' => $expires])['data'];
    expect($saved['ownership']['expires_at'] === $expires && $saved['device_version'] === 2 && !isset($saved['ownership']['expiry_context']), '使用期限未独立保存或泄漏来源内部字段');
    $saved = $call('POST', $path . 'expiry', $platform, ['version' => 2, 'expires_at' => null])['data'];
    expect($saved['ownership']['expires_at'] === null && $saved['device_version'] === 3, '明确取消期限失败');
    $app = $call('POST', '/customer/tenants/' . $source . '/applications', $sourceToken, ['id' => bin2hex(random_bytes(16)), 'name' => '未接入旧应用'], 201, $sourceHeaders)['data'];
    $grant = $call(
        'POST',
        '/customer/tenants/' . $source . '/applications/' . $app['application']['id'] . '/devices/' . $device['id'],
        $sourceToken,
        ['ownership_id' => $device['ownership_id'], 'version' => 0, 'receive' => true, 'send' => true, 'subscribe' => true],
        200,
        $sourceHeaders
    )['data'];
    $input = ['id' => bin2hex(random_bytes(16)), 'version' => 3, 'target_tenant_id' => $target];
    $call('POST', $path . 'reassign', $platform, array_replace($input, ['target_tenant_id' => $source]), 422, [], 'reassignment_target_unavailable');
    $call('POST', $path . 'reassign', $platform, $input, 403, $sourceHeaders, 'identity_context_invalid');
    $created = $call('POST', $path . 'reassign', $platform, $input, 202)['data'];
    expect($created['status'] === 'isolating' && !$created['requires_store'] && $created['target_tenant_id'] === $target, '平台请求没有保持真实隔离阶段');
    expect($call('POST', $path . 'reassign', $platform, $input, 202)['data'] === $created, '重试修改了原转配');
    $call('POST', $path . 'reassign', $platform, array_replace($input, ['target_tenant_id' => $other]), 409, [], 'reassignment_request_conflict');
    $old = $call('GET', '/admin/devices/' . $device['id'], $platform)['data'];
    expect($old['usage']['ended_at'] !== null && !$old['usage']['available'] && !$old['credential_active'], '已受理后没有立即结束旧关系和凭据');
    $call('POST', '/admin/devices/' . $device['id'] . '/enable', $platform, ['version' => $old['version'], 'confirm_device_id' => $device['id']], 409, [], 'device_ownership_unavailable');
    $call('POST', '/admin/devices/' . $device['id'] . '/rotate', $platform, ['version' => $old['version'], 'confirm_device_id' => $device['id']], 409, [], 'device_ownership_unavailable');
    $call('POST', '/admin/devices/' . $device['id'] . '/retire', $platform, ['version' => $old['version'], 'confirm_device_id' => $device['id']], 409, [], 'reassignment_in_progress');
    $grants = $call('GET', '/customer/tenants/' . $source . '/applications/' . $app['application']['id'] . '/devices', $sourceToken, null, 200, $sourceHeaders);
    expect(!$grants['items'][0]['receive'] && !$grants['items'][0]['send'] && !$grants['items'][0]['subscribe'] && !$grants['items'][0]['current'], '旧关系授权没有撤销');
    $adminPath = '/admin/reassignments/' . $input['id'];
    $targetPath = '/customer/tenants/' . $target . '/reassignments/' . $input['id'];
    $call('GET', '/customer/tenants/' . $other . '/reassignments/' . $input['id'], $otherToken, null, 404, ['X-Tenant-Id' => $other], 'reassignment_not_found');
    $accept = ['action' => 'accept', 'version' => 1, 'id' => bin2hex(random_bytes(16))];
    $call('POST', $targetPath, $targetToken, $accept, 409, $targetHeaders, 'reassignment_isolation_pending');
    $ready = $call('POST', $adminPath, $platform, ['action' => 'advance', 'version' => 1])['data'];
    expect($ready['status'] === 'awaiting_acceptance' && $ready['new_ownership_id'] === null, '无通信历史也须等待目标客户确认');
    $call('POST', '/customer/tenants/' . $source . '/reassignments/' . $input['id'], $sourceToken, $accept, 403, $sourceHeaders, 'reassignment_target_required');
    $accept['version'] = $ready['version'];
    $completed = $call('POST', $targetPath, $targetToken, $accept, 200, $targetHeaders)['data'];
    expect($completed['operation']['status'] === 'completed' && $completed['credential'] !== null && !$completed['replayed'], '目标确认没有一次性交付独立新身份');
    $replay = $call('POST', $targetPath, $targetToken, $accept, 200, $targetHeaders)['data'];
    expect($replay['replayed'] && $replay['credential'] === null, '重复确认回显或重发了秘密');
    $call('POST', $targetPath, $targetToken, array_replace($accept, ['id' => bin2hex(random_bytes(16))]), 409, $targetHeaders, 'reassignment_request_conflict');
    $new = $call('GET', '/customer/tenants/' . $target . '/devices/' . $device['id'], $targetToken, null, 200, $targetHeaders)['data'];
    expect($new['ownership_id'] !== $device['ownership_id'] && $new['tenant_id'] === $target && $new['usage']['available'] && $new['usage']['expires_at'] === null, '新关系继承了旧期限、归属或状态');
    $call('GET', '/customer/tenants/' . $source . '/devices/' . $device['id'], $sourceToken, null, 404, $sourceHeaders, 'device_not_found');
    expect(!str_contains(json_encode($call('GET', $adminPath, $platform), JSON_THROW_ON_ERROR), $completed['credential']['password']), '普通转配查询泄漏一次性凭据');
    $history = $database->prepare('SELECT COUNT(*) FROM iot_device_ownerships WHERE device_id = ?');
    $history->execute([$device['id']]);
    expect((int) $history->fetchColumn() === 2, '重试创建了多个使用关系');
    $history->closeCursor();
    $second = $register('版本竞争设备')['device'];
    $race = identityCompete($database, $driver, $address, [
        ['POST', '/admin/devices/' . $second['id'] . '/usage/reassign', $platform, ['id' => bin2hex(random_bytes(16)), 'version' => 1, 'target_tenant_id' => $target]],
        ['POST', '/admin/devices/' . $second['id'] . '/usage/reassign', $platform, ['id' => bin2hex(random_bytes(16)), 'version' => 1, 'target_tenant_id' => $other]],
    ]);
    sort($race);
    expect($race === [202, 409], '并发转配创建了多个当前意图');
    $auditDevice = $register('审计回滚设备')['device'];
    if ($driver === 'pgsql') {
        $database->exec("CREATE FUNCTION reassignment_test_failure() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF NEW.action = ''reassignment.end'' THEN RAISE EXCEPTION ''controlled reassignment audit failure''; END IF; RETURN NEW; END'");
        $database->exec('CREATE TRIGGER reassignment_test_failure BEFORE INSERT ON admin_audit FOR EACH ROW EXECUTE FUNCTION reassignment_test_failure()');
    } elseif ($driver === 'mysql') {
        $database->exec("CREATE TRIGGER reassignment_test_failure BEFORE INSERT ON admin_audit FOR EACH ROW BEGIN IF NEW.action = 'reassignment.end' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'controlled reassignment audit failure'; END IF; END");
    } else {
        $database->exec("CREATE TRIGGER reassignment_test_failure BEFORE INSERT ON admin_audit WHEN NEW.action = 'reassignment.end' BEGIN SELECT RAISE(ABORT, 'controlled reassignment audit failure'); END");
    }
    $snapshot = static function () use ($database): array {
        $rows = [];
        foreach (['iot_devices', 'iot_device_ownerships', 'iot_device_credentials', 'iot_device_reassignments', 'iot_application_grants', 'broker_access_revisions'] as $table) {
            $rows[$table] = $database->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        }
        return $rows;
    };
    $before = $snapshot();
    $end = ['id' => bin2hex(random_bytes(16)), 'version' => 1];
    try {
        $call('POST', '/admin/devices/' . $auditDevice['id'] . '/usage/end', $platform, $end, 500);
        expect($snapshot() === $before, '审计失败留下了已结束关系、撤销凭据或部分转配');
    } finally {
        $database->exec('DROP TRIGGER reassignment_test_failure' . ($driver === 'pgsql' ? ' ON admin_audit' : ''));
        if ($driver === 'pgsql') {
            $database->exec('DROP FUNCTION reassignment_test_failure()');
        }
    }
    $ended = $call('POST', '/admin/devices/' . $auditDevice['id'] . '/usage/end', $platform, $end, 202)['data'];
    $ended = $call('POST', '/admin/reassignments/' . $ended['id'], $platform, ['action' => 'advance', 'version' => $ended['version']])['data'];
    expect($ended['status'] === 'ended' && $ended['new_ownership_id'] === null, '结束使用不应自动建立新归属');
    return ['unconnected_evidence_only' => true, 'usage_expiry_separate' => true, 'old_relationship_closed' => true, 'old_grants_revoked' => true,
        'target_confirmation_required' => true, 'new_identity_once' => true, 'cross_tenant_denied' => true, 'concurrent_versions' => $race, 'audit_atomicity' => true];
}

/** 双节点真实协议场景；测试进程只暂停/终止自己启动的节点，退出路径始终解除暂停。 */
function iotReassignmentMqttChecks(array $command, array $environment, string $base, PDO $database, \Type\Testing\Process $first): array
{
    $root = dirname(__DIR__);
    $listener = stream_socket_server('tcp://127.0.0.1:0', $number, $reason);
    expect(is_resource($listener), '无法分配第二节点端口');
    $port = substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
    fclose($listener);
    $node = 'reassignment-beta';
    $second = null;
    $client = null;
    $frozen = false;
    $gate = $base . '/reassignment-stage';
    $wait = static function (Closure $condition, string $message, int $seconds = 25): void {
        $until = microtime(true) + $seconds;
        do {
            if ($condition()) {
                return;
            }
            usleep(30000);
        } while (microtime(true) < $until);
        throw new RuntimeException($message);
    };
    $launch = static function () use ($environment, $command, $root, $port, $node, $database, $wait): \Type\Testing\Process {
        $wait(static function () use ($database, $node): bool {
            $query = $database->prepare('SELECT expires_at FROM broker_resource_runs WHERE node_id = ?');
            $query->execute([$node]);
            $expires = $query->fetchColumn();
            return $expires === false || (int) $expires <= time();
        }, '旧运行观察未在有效期内结束');
        return new \Type\Testing\Process([...$command, 'iot:mqtt'], $root, array_replace($environment, ['IOT_MQTT_NODE_ID' => $node, 'IOT_MQTT_PORT' => $port]));
    };
    $startNode = static function (\Type\Testing\Process $process) use ($wait, $port): void {
        $wait(static function () use ($process, $port): bool {
            expect($process->running(), '第二节点提前退出：' . $process->stderr());
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $number, $reason, 0.1);
            if (!is_resource($socket)) {
                return false;
            }
            fclose($socket);
            return true;
        }, '第二节点没有就绪');
    };
    try {
        $second = $launch();
        $startNode($second);
        $client = new \Type\Testing\Process(
            ['node', $root . '/tests/iot-device-client.mjs', 'reassignments'],
            $root,
            array_replace($environment, ['TYPE_REASSIGNMENT_GATE' => $gate, 'TYPE_REASSIGNMENT_PORT' => $port])
        );
        $until = microtime(true) + 360;
        $processed = [];
        while ($client->running()) {
            expect(microtime(true) < $until, '转配组合场景超过总预算');
            $phase = is_file($gate . '.json') ? json_decode((string) file_get_contents($gate . '.json'), true, 8, JSON_THROW_ON_ERROR) : null;
            if ($phase !== null && !isset($processed[$phase['stage']])) {
                if ($phase['stage'] === 'freeze') {
                    expect(posix_kill($second->pid(), SIGSTOP), '无法暂停旧连接所在节点');
                    $frozen = true;
                } elseif ($phase['stage'] === 'restart') {
                    $operation = $database->prepare('SELECT status, isolation_id FROM iot_device_reassignments WHERE id = ?');
                    $operation->execute([$phase['id']]);
                    $record = $operation->fetch(PDO::FETCH_ASSOC);
                    expect($record['status'] === 'isolating' && $record['isolation_id'] !== null, '冻结节点期间错误交接了设备');
                    $active = $database->prepare('SELECT COUNT(*) FROM type_mqtt_sessions WHERE client_id = ? AND node_id = ?');
                    $active->execute([$phase['device'], $node]);
                    expect((int) $active->fetchColumn() === 1, '装置没有真实跨节点旧会话');
                    $run = $database->prepare('SELECT run_id FROM type_mqtt_nodes WHERE node_id = ?');
                    $run->execute([$node]);
                    $runId = $run->fetchColumn();
                    expect(is_string($runId), '待隔离节点缺少固定运行身份');
                    $parent = $second->pid();
                    $states = unixProcessStates();
                    $workers = unixProcessStates($parent);
                    do {
                        $previousCount = count($workers);
                        foreach ($states as $pid => $state) {
                            if (isset($workers[$state['parent']])) {
                                $workers[$pid] = $state;
                            }
                        }
                    } while (count($workers) !== $previousCount);
                    // 持久授权 worker 不会因父进程暂停而自然退出；硬故障必须包含本轮已确认的进程树。
                    foreach (array_reverse(array_keys($workers)) as $pid) {
                        expect(posix_kill($pid, SIGKILL) || !isset(unixProcessStates()[$pid]), '无法隔离本轮节点子进程');
                    }
                    expect(posix_kill($parent, SIGKILL), '无法中断本轮旧节点');
                    $frozen = false;
                    $stopped = $second->wait(10);
                    expect(!$stopped->successful() && !$stopped->timedOut && !$second->running(), '旧节点没有真正退出');
                    $owned = array_fill_keys([$parent, ...array_keys($workers)], true);
                    $wait(static fn (): bool => array_intersect_key(unixProcessStates(), $owned) === [], '旧节点或子进程尚未回收', 5);
                    file_put_contents($base . '/reassignment-interrupted-node.log', $second->stdout() . $second->stderr());
                    $proof = identityCommand([...$command, 'iot:mqtt-fence', $node, $runId, 'reassignment-test', 'owned_process_exited_children_absent'], $environment);
                    expect($proof['fenced'], '硬隔离登记没有同步完成');
                    file_put_contents($base . '/reassignment-fence-proof.json', json_encode(['run_id' => $runId, 'all_owned_pids_absent' => true,
                        'proof' => $proof], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
                    $second = $launch();
                    $startNode($second);
                } elseif ($phase['stage'] === 'replay') {
                    // 重放同一旧隔离意图，观察真实 Broker 不能关闭新关系的连接。
                    $old = $database->prepare('SELECT isolation_id FROM iot_device_reassignments WHERE id = ?');
                    $old->execute([$phase['id']]);
                    $isolation = $old->fetchColumn();
                    expect(is_string($isolation), '缺少原隔离身份');
                    $database->prepare('UPDATE iot_authorization_invalidations SET completed_at = NULL, node_id = NULL WHERE id = ?')->execute([$isolation]);
                    $wait(static function () use ($database, $isolation): bool {
                        $query = $database->prepare('SELECT completed_at FROM iot_authorization_invalidations WHERE id = ?');
                        $query->execute([$isolation]);
                        return $query->fetchColumn() !== null;
                    }, '迟到旧隔离意图未被执行');
                } else {
                    throw new RuntimeException('未知转配测试阶段');
                }
                $processed[$phase['stage']] = true;
                file_put_contents($gate . '.ack', $phase['stage']);
            }
            usleep(30000);
        }
        $completed = $client->wait(1);
        expect($completed->successful(), '真实转配客户端失败：' . $completed->stderr . $completed->stdout . $first->stderr() . $second->stderr());
        $statistics = [];
        foreach (['first' => $first, 'second' => $second] as $name => $process) {
            $result = $process->stop(20);
            file_put_contents($base . '/reassignment-' . $name . '.log', $result->stdout . $result->stderr);
            expect($result->successful(), '转配节点没有正常退出：' . $name);
            $statistics[$name] = json_decode(trim($result->stdout), true, 32, JSON_THROW_ON_ERROR);
            foreach (['connections', 'pendingCommits', 'closingSessions', 'pendingFences', 'observationFailures', 'invalidationFailures'] as $key) {
                expect($statistics[$name][$key] === 0, '转配节点退出计数异常：' . $name . '/' . $key);
            }
        }
        return ['client' => 'MQTT.js 5.15.0', 'tls' => true, 'checks' => json_decode(trim($completed->stdout), true, 16, JSON_THROW_ON_ERROR), 'statistics' => $statistics, 'faults' => array_keys($processed)];
    } finally {
        if ($frozen && $second !== null && $second->running()) {
            posix_kill($second->pid(), SIGCONT);
        }
        $client?->stop(5);
        if ($client !== null) {
            file_put_contents($base . '/reassignment-client.log', $client->stdout() . $client->stderr());
        }
        $second?->stop(15);
        $first->stop(15);
        if ($second !== null) {
            file_put_contents($base . '/reassignment-second-final.log', $second->stdout() . $second->stderr());
        }
    }
}
