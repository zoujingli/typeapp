<?php

declare(strict_types=1);

/** 真实HTTP创建、Redis后台命令和文件下载验证；PDO只准备边界/故障装置，不代替导出执行。 */
function iotExportChecks(Closure $request, array $tokens, string $tenantA, string $tenantB, PDO $database, array $command, array $environment, string $base, Type\Testing\HttpClient $client, array $fixture): array
{
    $redis = new NativeRolloutRedis($base . '/redis', (string) getenv('TYPE_REDIS_SERVER'));
    $queueEnvironment = $redis->environment();
    $workerEnvironment = $environment + ['IOT_EXPORT_REDIS_HOST' => $queueEnvironment['TYPE_REDIS_HOST'],
        'IOT_EXPORT_REDIS_PORT' => $queueEnvironment['TYPE_REDIS_PORT'], 'IOT_EXPORT_NAMESPACE' => basename($base)];
    $checks = [];
    try {
        $path = '/customer/tenants/' . $tenantA;
        $admin = $tokens['bob'];
        $viewer = $tokens['alice'];
        $operator = $tokens['carol'];
        iotExportRole($request, $admin, $tenantA, 'device-reader', ['identity.read', 'customer.devices.read', 'customer.telemetry.read']);
        $permissions = ['customer.exports.read', 'customer.exports.create', 'customer.exports.cancel', 'customer.exports.download'];
        $assignment = iotExportRole($request, $admin, $tenantA, 'device-operator', $permissions);
        $role = $assignment['role'];
        $product = $request('POST', $path . '/products', $admin, $tenantA, ['name' => 'CSV边界与中文单位'], 201)['data'];
        $definition = ['properties' => [
            ['identifier' => 'temperature', 'name' => '温度', 'type' => 'number', 'required' => false, 'unit' => '°C'],
            ['identifier' => 'note', 'name' => '=HYPERLINK("https://invalid")', 'type' => 'string', 'required' => false, 'max_length' => 4096],
        ], 'events' => [], 'commands' => []];
        $models = $path . '/products/' . $product['id'] . '/models';
        $request('POST', $models, $admin, $tenantA, ['definition' => $definition], 201);
        $request('POST', $models . '/1/publish', $admin, $tenantA, ['version' => 1], 200);
        $registered = $request('POST', $path . '/devices', $admin, $tenantA, ['name' => 'CSV测试设备', 'product_id' => $product['id'], 'model_version' => 1], 201)['data'];
        $device = $registered['device'];
        $createPath = $path . '/devices/' . $device['id'] . '/exports';
        $historyPath = $path . '/devices/' . $device['id'] . '/history';
        $jobsPath = $path . '/exports';
        $received = time() - 2;
        $sampled = $received - 300;
        $ledger = $database->prepare('INSERT INTO iot_ingestion (message_id, tenant_id, device_id, ownership_id, sequence, content_hash, status, code, received_at, receipt_proof_nonce, receipt_requested_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $facts = $database->prepare('INSERT INTO iot_ingestion_facts (message_id, tenant_id, device_id, ownership_id, product_id, model_version, sequence, type, identifier, sampled_at, received_at, values_json, current_advanced) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $database->beginTransaction();
        for ($index = 0; $index < 205; $index++) {
            $id = hash('sha256', 'csv-fixture:' . $device['id'] . ':' . $index);
            $sequence = '10000000000000000000000000000000000' . str_pad((string) $index, 3, '0', STR_PAD_LEFT);
            $ledger->execute([$id, $tenantA, $device['id'], $device['ownership_id'], $sequence, str_repeat('a', 64), 'accepted', 'accepted', $received, str_repeat('b', 32), $received]);
            $facts->execute([$id, $tenantA, $device['id'], $device['ownership_id'], $product['id'], 1, $sequence, 'telemetry', '', $sampled + $index, $received,
                json_encode(['temperature' => $index / 10, 'note' => "=1+1\n\"逗号,\"@公式"], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 1]);
        }
        $database->commit();
        $filters = ['kind' => 'records', 'from' => $sampled - 1, 'to' => $received, 'sort' => 'sampled_asc', 'timezone' => 'Asia/Shanghai'];
        $create = static fn (array $changes = [], string $token = ''): array => $request('POST', $createPath, $token === '' ? $admin : $token, $tenantA, array_replace($filters, ['id' => bin2hex(random_bytes(16))], $changes), 202)['data'];
        $jobs = static fn (string $token = ''): array => $request('GET', $jobsPath . '?per_page=100', $token === '' ? $admin : $token, $tenantA, null, 200)['items'];
        $job = static function (string $id, string $token = '') use ($jobs): array {
            foreach ($jobs($token) as $item) {
                if ($item['id'] === $id) {
                    return $item;
                }
            }
            throw new RuntimeException('导出任务未返回');
        };
        $run = static fn (int $steps = 100): array => identityCommand([...$command, 'iot:exports', (string) $steps], $workerEnvironment)['data'];
        $download = static fn (string $id): Type\Testing\HttpResponse => $client->request('GET', $jobsPath . '/' . $id . '/download', ['Authorization' => 'Bearer ' . $admin, 'X-Tenant-Id' => $tenantA]);
        if (in_array('--io-export-baseline', $GLOBALS['argv'], true)) {
            return iotExportIoBaseline($create, $job, $run, $download, $database, $command, $workerEnvironment, $base, $tenantA);
        }
        $input = $filters + ['id' => bin2hex(random_bytes(16))];
        $request('POST', $createPath, $viewer, $tenantA, $input, 403);
        $request('GET', $jobsPath, $viewer, $tenantA, null, 403);
        $request('POST', $createPath, $tokens['platform'], $tenantA, $input, 401);
        $request('POST', $createPath, $admin, $tenantB, $input, 403);
        $request('POST', '/iot/tenants/' . $tenantA . '/devices/' . $device['id'] . '/exports', $admin, $tenantA, $input, 404);
        foreach ([['page' => 2], ['cursor' => 'fake'], ['timezone' => 'invalid-zone'], ['to' => $sampled - 10], ['sort' => 'injection']] as $invalid) {
            $request('POST', $createPath, $admin, $tenantA, array_replace($input, $invalid), 422);
        }
        $request('POST', $createPath . '?cursor=foreign', $admin, $tenantA, $input, 422, 'export_filter_invalid');
        foreach (['X-Support-Id', 'X-Impersonation-Id', 'X-Identity-Realm'] as $header) {
            $rejected = $client->request('GET', $jobsPath, ['Authorization' => 'Bearer ' . $admin, 'X-Tenant-Id' => $tenantA, $header => 'foreign']);
            expect($rejected->status === 403 && $rejected->json()['error'] === 'identity_context_invalid', '导出不接受伪造身份来源头');
        }
        $created = $create();
        $retry = $create(['id' => $created['id']]);
        expect($retry === $created, '同一请求重试必须返回原任务和快照');
        $request('POST', $createPath, $admin, $tenantA, array_replace($input, ['id' => $created['id'], 'timezone' => 'UTC']), 409, 'export_identity_conflict');
        $request('POST', $createPath, $operator, $tenantA, array_replace($input, ['id' => $created['id']]), 409, 'export_identity_conflict');
        $count = $database->prepare("SELECT COUNT(*) FROM customer_audit WHERE subject_id = ? AND action = 'export.create'");
        $count->execute([$created['id']]);
        expect((int) $count->fetchColumn() === 1, '相同请求不能重复写创建审计');
        $count->closeCursor();
        $checks[] = 'idempotent-request-snapshot-audit-and-conflict';
        expect($created['status'] === 'queued' && $created['total_rows'] === 205 && $created['completed_rows'] === 0, '创建只能排队且冻结全部筛选，不能只导出当前20条页面');
        $request('GET', $jobsPath . '/' . $created['id'] . '/download', $admin, $tenantA, null, 409, 'export_not_ready');
        expect($request('GET', $historyPath . '?' . http_build_query(['from' => $sampled - 1, 'to' => $received, 'per_page' => 1]), $viewer, $tenantA, null, 200)['total'] === 205, '导出与页面筛选不一致');
        $run(1);
        $partial = $job($created['id']);
        expect($partial['status'] === 'running' && $partial['completed_rows'] === 100, '后台单次分块应有界且进度持久');
        $file = $base . '/storage/exports/' . $created['id'] . '.csv';
        file_put_contents($file, 'uncommitted-tail', FILE_APPEND);
        $redis->crashAndRestartReliable();
        $run();
        $complete = $job($created['id']);
        $csv = $download($created['id']);
        expect($complete['status'] === 'succeeded' && $complete['completed_rows'] === 205 && $csv->status === 200
            && strlen($csv->body) === $complete['file_bytes'] && !str_contains($csv->body, 'uncommitted-tail'), '队列重启与新进程应从确认偏移恢复，不能保留未提交尾部');
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, substr($csv->body, 3));
        rewind($stream);
        $decoded = [];
        while (($record = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $decoded[] = $record;
        }
        fclose($stream);
        expect(count($decoded) === 206 && count($decoded[0]) === 14 && str_contains($decoded[1][11], '=1+1') && str_contains($decoded[1][12], '°C')
            && str_starts_with($decoded[1][9], "'100000") && $decoded[1][13] === "'Asia/Shanghai", 'CSV需保留中文单位、大序号、公式原文、完整行数和时区');
        foreach ($decoded as $line) {
            foreach ($line as $cell) {
                expect(str_starts_with($cell, "'"), '每格必须显式安全文本');
            }
        }
        $request('GET', $jobsPath . '/' . $created['id'] . '/download?from=1', $admin, $tenantA, null, 422, 'export_filter_invalid');
        $request('GET', '/customer/tenants/' . $tenantB . '/exports/' . $created['id'] . '/download', $admin, $tenantB, null, 404);
        $checks[] = 'raw-filter-all-pages-csv-text-timezone-units-redis-crash-offset-recovery';

        $cancelled = $create();
        $run(1);
        $request('POST', $jobsPath . '/' . $cancelled['id'] . '/cancel', $admin, $tenantA, [], 200);
        $request('POST', $jobsPath . '/' . $cancelled['id'] . '/cancel', $admin, $tenantA, [], 200);
        $run();
        expect($job($cancelled['id'])['status'] === 'cancelled' && !is_file($base . '/storage/exports/' . $cancelled['id'] . '.csv'), '取消后文件不能残留或被旧消息重新创建');
        $request('GET', $jobsPath . '/' . $cancelled['id'] . '/download', $admin, $tenantA, null, 409);
        $checks[] = 'cancel-running-cleans-file-and-rejects-stale-work';

        $resumed = $create();
        $run(1);
        $request('POST', $jobsPath . '/' . $resumed['id'] . '/resume', $admin, $tenantA, [], 409);
        $database->prepare('UPDATE iot_exports SET updated_at = ? WHERE id = ?')->execute([time() - 61, $resumed['id']]);
        $request('POST', $jobsPath . '/' . $resumed['id'] . '/resume', $operator, $tenantA, [], 404);
        $request('POST', $jobsPath . '/' . $resumed['id'] . '/resume', $admin, $tenantA, [], 200);
        $run();
        expect($job($resumed['id'])['status'] === 'succeeded' && $download($resumed['id'])->body === $csv->body, '显式恢复必须保留原始快照和进度，旧步骤不能重复追加');
        $checks[] = 'explicit-resume-fences-old-step';

        $revoked = $create([], $operator);
        $request('GET', $historyPath, $operator, $tenantA, null, 403);
        $request('GET', $jobsPath . '/' . $created['id'] . '/download', $operator, $tenantA, null, 404);
        $request('POST', $jobsPath . '/' . $revoked['id'] . '/cancel', $admin, $tenantA, [], 404);
        expect($jobs($operator)[0]['id'] === $revoked['id'] && count($jobs($operator)) === 1, '任务列表必须隔离准确客户来源');
        $run(1);
        $progress = $job($revoked['id'], $operator);
        expect($progress['completed_rows'] === 100, '独立导出创建权限应能在没有遥测读取节点时执行');
        $role = $request('PUT', '/customer/roles/' . $role['id'] . '/permissions', $admin, $tenantA, ['version' => $role['version'], 'permissions' => ['customer.exports.read']], 200)['data'];
        $request('GET', $jobsPath . '/' . $created['id'] . '/download', $operator, $tenantA, null, 403);
        $request('POST', $jobsPath . '/' . $revoked['id'] . '/cancel', $operator, $tenantA, [], 403);
        $request('POST', $jobsPath . '/' . $revoked['id'] . '/resume', $operator, $tenantA, [], 403);
        $run();
        $stopped = $job($revoked['id'], $operator);
        expect($stopped['status'] === 'failed' && $stopped['error_code'] === 'export_permission_revoked' && $stopped['completed_rows'] === $progress['completed_rows']
            && $stopped['file_bytes'] === $progress['file_bytes'] && !is_file($base . '/storage/exports/' . $revoked['id'] . '.csv'), '撤权后下一块停止，既有提交事实不伪造回滚');
        $checks[] = 'current-permission-at-create-work-and-every-download';

        if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            // 清理已选中的旧到期候选必须在拿锁后重新读取成功生成的新保留期限。
            $database->prepare('UPDATE iot_exports SET expires_at = ? WHERE id = ?')->execute([time() - 1, $created['id']]);
            $database->beginTransaction();
            $database->prepare('UPDATE iot_exports SET expires_at = ? WHERE id = ?')->execute([time() + 86400, $created['id']]);
            $cleaner = new Type\Testing\Process([...$command, 'iot:exports-clean', '100'], dirname(__DIR__), $workerEnvironment);
            try {
                usleep(500000);
                expect($cleaner->running(), '真实清理进程应等待导出行锁');
                $database->commit();
                $cleaned = $cleaner->wait(30);
                expect($cleaned->successful(), $cleaned->stderr);
            } finally {
                if ($database->inTransaction()) {
                    $database->rollBack();
                }
                $cleaner->stop();
            }
            expect($download($created['id'])->body === $csv->body, '等待期间已成功生成的文件不能被旧候选清理');

            $role = $request('PUT', '/customer/roles/' . $role['id'] . '/permissions', $admin, $tenantA, ['version' => $role['version'], 'permissions' => $permissions], 200)['data'];
            $waiting = $create([], $operator);
            $database->beginTransaction();
            $database->exec('UPDATE app_installation SET schema_version = schema_version WHERE id = 1');
            $processor = new Type\Testing\Process([...$command, 'iot:exports', '100'], dirname(__DIR__), $workerEnvironment);
            try {
                usleep(500000);
                expect($processor->running(), '真实导出进程应等待租户授权锁');
                $database->prepare('DELETE FROM customer_role_permissions WHERE role_id = ? AND permission = ?')->execute([$role['id'], 'customer.exports.create']);
                $database->commit();
                $processed = $processor->wait(30);
                expect($processed->successful(), $processed->stderr);
            } finally {
                if ($database->inTransaction()) {
                    $database->rollBack();
                }
                $processor->stop();
            }
            expect($job($waiting['id'], $operator)['error_code'] === 'export_permission_revoked', '等待授权锁后必须读取新权限，不能沿用MySQL旧快照');
            $checks[] = 'real-cleaner-expiry-and-worker-revocation-lock-races';
        }

        // 普通/模拟来源不能接管彼此的任务；退出专用会话和撤销来源分别阻止新效果。
        $simulated = $fixture['simulated']['accessToken'];
        $simulatedTask = $create([], $simulated);
        $run();
        $simulatedCsv = $client->request('GET', $jobsPath . '/' . $simulatedTask['id'] . '/download', ['Authorization' => 'Bearer ' . $simulated, 'X-Tenant-Id' => $tenantA]);
        expect($simulatedCsv->status === 200 && $simulatedCsv->body === $csv->body, '模拟导出必须交付相同私有快照');
        $request('GET', $jobsPath . '/' . $simulatedTask['id'] . '/download', $admin, $tenantA, null, 404);
        $request('GET', $jobsPath . '/' . $created['id'] . '/download', $simulated, $tenantA, null, 404);
        $exitTask = $create([], $simulated);
        $request('POST', '/customer/auth/logout', $simulated, null, [], 200);
        $run();
        $stored = $database->prepare('SELECT * FROM iot_exports WHERE id = ?');
        $stored->execute([$exitTask['id']]);
        $exited = $stored->fetch(PDO::FETCH_ASSOC);
        $stored->closeCursor();
        expect($exited['error_code'] === 'export_permission_revoked' && (int) $exited['completed_rows'] === 0
            && !is_file($base . '/storage/exports/' . $exitTask['id'] . '.csv'), '模拟退出后未发生的新输出必须停止');
        $request('GET', $jobsPath . '/' . $simulatedTask['id'] . '/download', $simulated, $tenantA, null, 401);
        $replacement = $request('POST', '/admin/customers/' . $fixture['simulated']['identity']['customer_id'] . '/impersonate', $fixture['source']['accessToken'], null, ['version' => 1], 200)['data']['accessToken'];
        $request('GET', $jobsPath . '/' . $simulatedTask['id'] . '/download', $replacement, $tenantA, null, 404);
        $sourceTask = $create([], $replacement);
        $run(1);
        $sourceProgress = $job($sourceTask['id'], $replacement);
        $request('POST', '/admin/auth/logout', $fixture['source']['accessToken'], null, [], 200);
        $run();
        $stored->execute([$sourceTask['id']]);
        $stoppedSource = $stored->fetch(PDO::FETCH_ASSOC);
        $stored->closeCursor();
        expect($stoppedSource['error_code'] === 'export_permission_revoked' && (int) $stoppedSource['completed_rows'] === $sourceProgress['completed_rows']
            && !is_file($base . '/storage/exports/' . $sourceTask['id'] . '.csv'), '管理来源退出不能继续下一块或回退其他会话');
        $audit = $database->prepare("SELECT * FROM customer_audit WHERE subject_id = ? AND action = 'export.stopped'");
        $audit->execute([$sourceTask['id']]);
        $event = $audit->fetch(PDO::FETCH_ASSOC);
        $audit->closeCursor();
        $details = json_decode($event['details'], true, 8, JSON_THROW_ON_ERROR);
        expect($event['actor_id'] === $fixture['source']['identity']['actor_id'] && $details['source_session_id'] === $fixture['source']['identity']['session_id']
            && $details['customer_id'] === $fixture['simulated']['identity']['customer_id'], '后台停止审计丢失真实管理来源');
        foreach ([$simulatedTask, $exitTask, $sourceTask] as $temporary) {
            $database->prepare('UPDATE iot_exports SET expires_at = ? WHERE id = ?')->execute([time() - 1, $temporary['id']]);
        }
        identityCommand([...$command, 'iot:exports-clean', '100'], $workerEnvironment);
        $checks[] = 'exact-session-normal-impersonation-isolation-exit-source-revocation-and-origin-audit';

        $driver = $database->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $database->exec("CREATE FUNCTION export_audit_failure() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''controlled export audit failure''; END'");
            $database->exec('CREATE TRIGGER export_audit_failure BEFORE INSERT ON customer_audit FOR EACH ROW EXECUTE FUNCTION export_audit_failure()');
        } elseif ($driver === 'mysql') {
            $database->exec("CREATE TRIGGER export_audit_failure BEFORE INSERT ON customer_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'controlled export audit failure'");
        } else {
            $database->exec("CREATE TRIGGER export_audit_failure BEFORE INSERT ON customer_audit BEGIN SELECT RAISE(ABORT, 'controlled export audit failure'); END");
        }
        $failedId = bin2hex(random_bytes(16));
        try {
            $request('POST', $createPath, $admin, $tenantA, $filters + ['id' => $failedId], 500);
        } finally {
            $database->exec('DROP TRIGGER export_audit_failure' . ($driver === 'pgsql' ? ' ON customer_audit' : ''));
            if ($driver === 'pgsql') {
                $database->exec('DROP FUNCTION export_audit_failure()');
            }
        }
        $stored->execute([$failedId]);
        $snapshot = $database->prepare('SELECT COUNT(*) FROM iot_export_rows WHERE export_id = ?');
        $snapshot->execute([$failedId]);
        expect($stored->fetch() === false && (int) $snapshot->fetchColumn() === 0, '审计失败必须同时回滚导出及快照');
        $stored->closeCursor();
        $snapshot->closeCursor();
        $checks[] = 'audit-failure-rolls-back-task-and-snapshot';

        $minute = intdiv($sampled, 60) * 60;
        $minuteInsert = $database->prepare('INSERT INTO iot_minute_aggregates (id, tenant_id, device_id, product_id, model_version, ownership_id, window_start, window_end, fields_json, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $statistics = ['count' => 2, 'min' => 10.0, 'max' => 30.0, 'sum' => 40.0, 'last' => 30.0, 'last_sampled_at' => $minute + 5, 'last_sequence' => '2'];
        $minuteId = hash('sha256', 'csv-minute:' . $device['id']);
        $minuteInsert->execute([$minuteId, $tenantA, $device['id'], $product['id'], 1, $device['ownership_id'], $minute, $minute + 60, json_encode(['temperature' => $statistics]), $received]);
        $minuteJob = $create(['kind' => 'minutes', 'from' => $minute, 'to' => $minute + 59, 'field' => 'temperature']);
        $database->prepare('DELETE FROM iot_minute_aggregates WHERE id = ?')->execute([$minuteId]);
        $run();
        $minuteCsv = $download($minuteJob['id']);
        expect($job($minuteJob['id'])['total_rows'] === 1 && str_contains($minuteCsv->body, '""avg"":20.0')
            && str_contains($minuteCsv->body, '分钟统计'), '分钟导出须保留六项统计，源清理后仍用已冻结快照');
        $checks[] = 'minute-statistics-and-source-retention-snapshot';

        // 设备排空/隔离证明仅为本项HTTP边界装置；实际归属必须经公开审批及推进入口，MQTT专项另验。
        $queuedBeforeTransfer = $create();
        $target = $request('POST', '/admin/tenants', $tokens['platform'], null, ['id' => bin2hex(random_bytes(16)), 'name' => '导出隔离接收组织', 'new_customer' => false, 'owner_login' => 'device-outsider'], 200)['data']['id'];
        $targetPath = '/customer/tenants/' . $target;
        $node = 'export-transfer-' . $device['id'];
        $runId = bin2hex(random_bytes(16));
        $database->prepare('UPDATE iot_devices SET lifecycle = ? WHERE id = ?')->execute(['enabled', $device['id']]);
        $database->prepare('INSERT INTO iot_broker_observations (node_id, run_id, observed_at, expires_at) VALUES (?, ?, ?, ?)')->execute([$node, $runId, time(), time() + 3600]);
        $database->prepare('UPDATE iot_device_connections SET status = ?, node_id = ?, run_id = ?, observed_at = ? WHERE device_id = ?')->execute(['online', $node, $runId, time(), $device['id']]);
        $transfer = $request('POST', $path . '/transfers', $admin, $tenantA, ['transfer_id' => bin2hex(random_bytes(16)), 'device_id' => $device['id'], 'target_tenant_id' => $target, 'version' => $device['version']], 202)['data'];
        $transferPath = $targetPath . '/transfers/' . $transfer['id'];
        $accepted = $request('POST', $transferPath, $tokens['outsider'], $target, ['action' => 'accept', 'decision_id' => bin2hex(random_bytes(16)), 'copy_name' => '转入后独立导出模型', 'version' => $transfer['device_version']], 202)['data'];
        $receipt = ['supported' => true, 'model_pending' => false, 'pending_count' => 0, 'pending_command_receipts' => 0, 'unresolved_commands' => 0, 'boundary_sequence' => '0'];
        $database->prepare('UPDATE iot_transfers SET device_status = ?, device_status_at = ?, device_status_sequence = ?, last_attempt_at = ?, next_attempt_at = NULL WHERE id = ?')
            ->execute([json_encode($receipt, JSON_THROW_ON_ERROR), time(), '1', time(), $transfer['id']]);
        $switch = ['action' => 'switch', 'switch_id' => bin2hex(random_bytes(16)), 'version' => $accepted['device_version']];
        $isolating = $request('POST', $transferPath, $tokens['outsider'], $target, $switch, 202)['data'];
        expect($isolating['status'] === 'isolating' && $isolating['credential'] === null, '导出回归不得跳过旧授权隔离阶段');
        $database->prepare('UPDATE iot_authorization_invalidations SET completed_at = ?, node_id = ? WHERE id = ?')->execute([time(), $node, $isolating['isolation_id']]);
        $activating = $request('POST', $transferPath, $tokens['outsider'], $target, $switch, 202)['data'];
        expect($activating['status'] === 'activating' && $activating['new_ownership_id'] !== $device['ownership_id'], '未通过真实推进入口激活新归属');
        $run();
        expect($job($queuedBeforeTransfer['id'])['status'] === 'succeeded' && $download($queuedBeforeTransfer['id'])->body === $csv->body
            && $download($created['id'])->body === $csv->body && $download($minuteJob['id'])->body === $minuteCsv->body, '转移改变原租户已生成或排队的原始/分钟导出快照');
        $afterTransfer = $create();
        $run();
        expect($download($afterTransfer['id'])->body === $csv->body, '原租户不能继续按原归属创建完整历史导出');
        $targetJob = $request('POST', $targetPath . '/devices/' . $device['id'] . '/exports', $tokens['outsider'], $target, $input, 202)['data'];
        expect($targetJob['total_rows'] === 0, '新租户导出泄漏原阶段事实');
        $run();
        $request('GET', $targetPath . '/exports/' . $created['id'] . '/download', $tokens['outsider'], $target, null, 404);
        $request('GET', $jobsPath . '/' . $created['id'] . '/download', $tokens['outsider'], $tenantA, null, 403);
        $request('GET', $targetPath . '/exports/' . $targetJob['id'] . '/download', $admin, $target, null, 403);
        $targetCsv = $request('GET', $targetPath . '/exports', $tokens['outsider'], $target, null, 200)['items'];
        expect(count($targetCsv) === 1 && $targetCsv[0]['id'] === $targetJob['id'] && $targetCsv[0]['status'] === 'succeeded', '转移将源租户任务列表或下载权限赋予目标租户');
        $checks[] = 'public-ownership-transfer-retains-queued-and-completed-raw-minute-exports-with-current-permissions';
        if (in_array('--export-transfers-only', $GLOBALS['argv'], true)) {
            return ['scope' => 'ownership-transfer-exports', 'checks' => $checks, 'redis' => $redis->evidence(), 'example_task' => $created['id'], 'rows' => 205, 'csv_sha256' => hash('sha256', $csv->body)];
        }

        $expired = $create();
        $database->prepare('UPDATE iot_exports SET expires_at = ? WHERE id = ?')->execute([time() - 1, $expired['id']]);
        $request('GET', $jobsPath . '/' . $expired['id'] . '/download', $admin, $tenantA, null, 410, 'export_expired');
        $run();
        identityCommand([...$command, 'iot:exports-clean', '100'], $workerEnvironment);
        expect($job($expired['id'])['status'] === 'expired' && !is_file($base . '/storage/exports/' . $expired['id'] . '.csv'), '24小时到期应拒绝下载并回收');
        $checks[] = 'expiry-and-bounded-cleanup';

        $limitJob = $create();
        $database->prepare('UPDATE iot_exports SET file_bytes = 104857600 WHERE id = ?')->execute([$limitJob['id']]);
        $run();
        expect($job($limitJob['id'])['status'] === 'failed' && !is_file($base . '/storage/exports/' . $limitJob['id'] . '.csv'), '实际字节触限必须失败并清理，不能截断成功');
        $checks[] = 'runtime-byte-limit-fails-without-partial-download';

        $broken = $create();
        $brokenEnvironment = array_replace($workerEnvironment, ['IOT_EXPORT_DIRECTORY' => 'history-is-a-file']);
        file_put_contents($base . '/history-is-a-file', 'fixture');
        identityCommand([...$command, 'iot:exports', '100'], $brokenEnvironment);
        expect($job($broken['id'])['status'] === 'failed' && $job($broken['id'])['error_code'] === 'export_storage_unavailable', '确定存储失败必须显示失败，不能无限排队');
        $checks[] = 'real-filesystem-failure';

        // 真实队列入口争用持久并发额度；固定已在运行的任务是受控外部状态，不替代被测领取逻辑。
        $occupied = [$create(), $create()];
        $waiting = $create();
        foreach ($occupied as $item) {
            $database->prepare("UPDATE iot_exports SET status = 'running', step = 1 WHERE id = ?")->execute([$item['id']]);
        }
        $run();
        expect($job($waiting['id'])['status'] === 'queued', '同租户已有两个运行任务时第三个不能领取');
        $observed = (int) $database->query("SELECT COUNT(*) FROM iot_exports WHERE status = 'running'")->fetchColumn();
        expect($observed === 2, '每租户并行数超限');
        foreach ([...$occupied, $waiting] as $item) {
            $request('POST', $jobsPath . '/' . $item['id'] . '/cancel', $admin, $tenantA, [], 200);
        }
        $globalIds = [];
        for ($index = 0; $index < 10; $index++) {
            $globalId = bin2hex(random_bytes(16));
            $globalIds[] = $globalId;
            $database->prepare("INSERT INTO iot_exports (id, tenant_id, device_id, actor_id, source_context, scope_key, request_hash, kind, filters_json, timezone, status, error_code, total_rows, completed_rows, estimated_bytes, file_bytes, file_hash, step, last_time, last_id, created_at, updated_at, expires_at) SELECT ?, ?, device_id, actor_id, source_context, scope_key, request_hash, kind, filters_json, timezone, 'running', '', total_rows, 0, estimated_bytes, 0, '', 0, 0, '', created_at, updated_at, expires_at FROM iot_exports WHERE id = ?")
                ->execute([$globalId, $tenantB, $created['id']]);
        }
        $globallyWaiting = $create();
        $run();
        expect($job($globallyWaiting['id'])['status'] === 'queued', '全局已有十个运行任务时其他租户不能越过额度');
        foreach ($globalIds as $globalId) {
            $database->prepare('DELETE FROM iot_exports WHERE id = ?')->execute([$globalId]);
        }
        $database->prepare('UPDATE iot_exports SET updated_at = ? WHERE id = ?')->execute([time() - 61, $globallyWaiting['id']]);
        $request('POST', $jobsPath . '/' . $globallyWaiting['id'] . '/resume', $admin, $tenantA, [], 200);
        $run();
        expect($job($globallyWaiting['id'])['status'] === 'succeeded', '额度释放后可恢复生成');
        $checks[] = 'tenant-two-global-ten-and-recover-after-capacity-release';

        // 100000与100001真实事实分别通过创建/拒绝入口，固定最小合法模型以单独检查行数门槛。
        $limitProduct = $request('POST', $path . '/products', $admin, $tenantA, ['name' => '十万行边界'], 201)['data'];
        $limitModels = $path . '/products/' . $limitProduct['id'] . '/models';
        $request('POST', $limitModels, $admin, $tenantA, ['definition' => ['properties' => [['identifier' => 'a', 'name' => 'a', 'type' => 'integer', 'required' => false]], 'events' => [], 'commands' => []]], 201);
        $request('POST', $limitModels . '/1/publish', $admin, $tenantA, ['version' => 1], 200);
        $limitDevice = $request('POST', $path . '/devices', $admin, $tenantA, ['name' => '十万行设备', 'product_id' => $limitProduct['id'], 'model_version' => 1], 201)['data']['device'];
        $database->beginTransaction();
        for ($start = 0; $start <= 100000; $start += 1000) {
            $ledgerValues = [];
            $factValues = [];
            $ledgerBindings = [];
            $factBindings = [];
            for ($index = $start; $index < min(100001, $start + 1000); $index++) {
                $factId = hash('sha256', $limitDevice['id'] . ':' . $index);
                $ledgerValues[] = '(?,?,?,?,?,?,?,?,?,?,?)';
                array_push($ledgerBindings, $factId, $tenantA, $limitDevice['id'], $limitDevice['ownership_id'], (string) $index, str_repeat('a', 64), 'accepted', 'accepted', $received, str_repeat('b', 32), $received);
                $factValues[] = '(?,?,?,?,?,?,?,?,?,?,?,?,?)';
                array_push($factBindings, $factId, $tenantA, $limitDevice['id'], $limitDevice['ownership_id'], $limitProduct['id'], 1, (string) $index, 'telemetry', '', $sampled, $received, '{"a":1}', 1);
            }
            $database->prepare('INSERT INTO iot_ingestion (message_id, tenant_id, device_id, ownership_id, sequence, content_hash, status, code, received_at, receipt_proof_nonce, receipt_requested_at) VALUES ' . implode(',', $ledgerValues))->execute($ledgerBindings);
            $database->prepare('INSERT INTO iot_ingestion_facts (message_id, tenant_id, device_id, ownership_id, product_id, model_version, sequence, type, identifier, sampled_at, received_at, values_json, current_advanced) VALUES ' . implode(',', $factValues))->execute($factBindings);
        }
        $database->commit();
        $limitPath = $path . '/devices/' . $limitDevice['id'] . '/exports';
        $limitInput = $filters + ['id' => bin2hex(random_bytes(16))];
        $request('POST', $limitPath, $admin, $tenantA, $limitInput, 422, 'export_limit_exceeded');
        $database->prepare('DELETE FROM iot_ingestion_facts WHERE message_id = ?')->execute([hash('sha256', $limitDevice['id'] . ':100000')]);
        $maximum = $request('POST', $limitPath, $admin, $tenantA, $limitInput, 202)['data'];
        expect($maximum['total_rows'] === 100000, '恰好100000行的合法小记录应允许创建');
        $request('POST', $jobsPath . '/' . $maximum['id'] . '/cancel', $admin, $tenantA, [], 200);
        $beforeCleanup = (int) $database->query("SELECT COUNT(*) FROM iot_export_rows WHERE export_id = '" . $maximum['id'] . "'")->fetchColumn();
        identityCommand([...$command, 'iot:exports-clean', '100'], $workerEnvironment);
        $afterCleanup = (int) $database->query("SELECT COUNT(*) FROM iot_export_rows WHERE export_id = '" . $maximum['id'] . "'")->fetchColumn();
        expect($beforeCleanup - $afterCleanup === 1000, '大快照的清理应有界且留下可恢复剩余事实');
        $database->prepare('UPDATE iot_ingestion_facts SET values_json = ? WHERE device_id = ?')->execute(['{"a":' . str_repeat(' ', 512) . '1}', $limitDevice['id']]);
        $request('POST', $limitPath, $admin, $tenantA, array_replace($limitInput, ['id' => bin2hex(random_bytes(16))]), 422, 'export_limit_exceeded');
        $checks[] = '100000-row-boundary-estimated-bytes-and-1000-row-cleanup';

        $retained = (int) $database->query("SELECT COUNT(*) FROM iot_exports WHERE tenant_id = '" . $tenantA . "' AND expires_at > " . time())->fetchColumn();
        for ($index = $retained; $index < 20; $index++) {
            $create(['field' => 'absent']);
        }
        $request('POST', $createPath, $admin, $tenantA, array_replace($filters, ['id' => bin2hex(random_bytes(16))]), 429, 'export_capacity_exceeded');
        $checks[] = 'bounded-retained-task-capacity';
        return ['checks' => $checks, 'redis' => $redis->evidence(), 'example_task' => $created['id'], 'rows' => 205, 'csv_sha256' => hash('sha256', $csv->body)];
    } finally {
        $redis->close();
    }
}

/** 使用真实RBAC接口准备独立导出权限，不依赖角色名称或旧固定职位。 */
function iotExportRole(Closure $request, string $admin, string $tenant, string $login, array $permissions): array
{
    $role = $request('POST', '/customer/roles', $admin, $tenant, ['name' => $login . '-exports', 'permissions' => $permissions], 200)['data'];
    $role = $request('POST', '/customer/roles/' . $role['id'] . '/status', $admin, $tenant, ['version' => 1, 'enabled' => true], 200)['data'];
    $member = $request('GET', '/customer/members?search=' . $login, $admin, $tenant, null, 200)['data']['items'][0];
    $request('PUT', '/customer/members/roles', $admin, $tenant, ['members' => [['id' => $member['id'], 'version' => (int) $member['version']]], 'roles' => [['id' => $role['id'], 'version' => 2]]], 200);
    return ['role' => $role, 'member' => $member];
}

/**
 * 复用真实导出全链路；两个独立锁装置只用于定位，不能混入正常耗时分位数。
 * 文件阶段观察到的是刷盘后的事务边界，不把事务年龄或命令时间冒充单次fsync耗时。
 * @param Closure(array, string): array $create
 * @param Closure(string): array $job
 * @param Closure(int): array $run
 * @param Closure(string): Type\Testing\HttpResponse $download
 */
function iotExportIoBaseline(Closure $create, Closure $job, Closure $run, Closure $download, PDO $database, array $command, array $environment, string $base, string $tenant): array
{
    expect($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql', '导出事务阶段基线需要本轮隔离PostgreSQL');
    $samples = filter_var(getenv('TYPE_IO_SAMPLES') ?: '30', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2, 'max_range' => 100]]);
    $warmup = filter_var(getenv('TYPE_IO_WARMUP') === false ? '5' : getenv('TYPE_IO_WARMUP'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 20]]);
    expect(is_int($samples) && is_int($warmup), '导出采样次数或预热次数无效');
    $report = ['protocol' => 1, 'status' => 'running', 'scope' => 'public-export-io-baseline', 'rows' => 205,
        'seed' => 'existing-csv-fixture-205-v1', 'samples' => $samples, 'warmup' => $warmup, 'concurrency' => 1,
        'controller_sha256' => hash_file('sha256', __FILE__), 'observations' => [], 'lock_cases' => [],
        'limitations' => ['命令时间包括启动、建连、Outbox、Redis及全部三步导出，不是纯文件耗时。',
            '锁装置各运行一次并单独报告；事务年龄包含SQL、CSV、文件和到观察时刻的等待。',
            '内容在本轮固定并逐次核对；设备身份和时间由现有隔离装置创建，跨轮不能直接比较CSV摘要。',
            '仅PostgreSQL；未测单次fsync精确耗时、峰值资源和受控容量，不能据此宣称优化收益。']];
    $probe = new PDO(
        'pgsql:host=' . $environment['DB_HOST'] . ';port=' . $environment['DB_PORT'] . ';dbname=' . $environment['DB_DATABASE'],
        $environment['DB_USERNAME'],
        $environment['DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $probe->exec("SET statement_timeout = '3s'");
    $gateId = random_int(1, 2147483647);
    $controllerPid = (int) $database->query('SELECT pg_backend_pid()')->fetchColumn();
    $worker = null;
    $triggerInstalled = false;
    $advisoryHeld = false;
    $expectedHash = null;
    $finish = static function (string $id) use ($job, $download, &$expectedHash): array {
        $completed = $job($id);
        $response = $download($id);
        expect($completed['status'] === 'succeeded' && $completed['completed_rows'] === 205 && $response->status === 200
            && strlen($response->body) === $completed['file_bytes'], '基线导出未完整交付205行');
        $hash = hash('sha256', $response->body);
        $expectedHash ??= $hash;
        expect($hash === $expectedHash, '相同快照的导出内容或排序改变');
        $csv = fopen('php://temp', 'w+b');
        expect(is_resource($csv), '无法核对导出CSV');
        $semantic = hash_init('sha256');
        try {
            expect(str_starts_with($response->body, "\xEF\xBB\xBF"), 'CSV缺少UTF-8 BOM');
            fwrite($csv, substr($response->body, 3));
            rewind($csv);
            expect(count(fgetcsv($csv, null, ',', '"', '')) === 14, 'CSV表头列数改变');
            $rows = 0;
            while (($row = fgetcsv($csv, null, ',', '"', '')) !== false) {
                expect(count($row) === 14 && $row[9] === "'10000000000000000000000000000000000" . str_pad((string) $rows, 3, '0', STR_PAD_LEFT), 'CSV行数、顺序或大序号改变');
                foreach ($row as $cell) {
                    expect(str_starts_with($cell, "'"), 'CSV单元格安全文本语义改变');
                }
                // 跨轮比较稳定业务内容；本轮完整摘要另保留身份和时间字段。
                hash_update($semantic, json_encode([$row[0], $row[4], $row[9], $row[10], $row[11], $row[12], $row[13]], JSON_THROW_ON_ERROR) . "\n");
                $rows++;
            }
            expect($rows === 205, 'CSV未包含完整205条快照');
        } finally {
            fclose($csv);
        }
        return ['bytes' => strlen($response->body), 'sha256' => $hash, 'semantic_sha256' => hash_final($semantic)];
    };
    $clean = static function (string $id) use ($database, $command, $environment, $base): void {
        $database->prepare('UPDATE iot_exports SET expires_at = 0 WHERE id = ?')->execute([$id]);
        identityCommand([...$command, 'iot:exports-clean', '100'], $environment);
        clearstatcache(true, $base . '/storage/exports/' . $id . '.csv');
        expect(!is_file($base . '/storage/exports/' . $id . '.csv'), '本轮已结束的导出文件未清理');
    };
    try {
        for ($index = 0; $index < $warmup + $samples; $index++) {
            $started = hrtime(true);
            $created = $create();
            $createdAt = hrtime(true);
            expect($created['status'] === 'queued' && $created['total_rows'] === 205, '基线没有通过公开入口冻结完整快照');
            $run();
            $finishedAt = hrtime(true);
            $content = $finish($created['id']);
            $downloadedAt = hrtime(true);
            $report['observations'][] = ['warmup' => $index < $warmup, 'create_ms' => ($createdAt - $started) / 1e6,
                'worker_command_ms' => ($finishedAt - $createdAt) / 1e6, 'verify_and_download_ms' => ($downloadedAt - $finishedAt) / 1e6,
                'total_ms' => ($downloadedAt - $started) / 1e6, 'content' => $content];
            $clean($created['id']);
        }
        foreach (['before_file', 'after_fsync'] as $stage) {
            $created = $create();
            $id = $created['id'];
            $file = $base . '/storage/exports/' . $id . '.csv';
            if ($stage === 'before_file') {
                $database->beginTransaction();
                $database->prepare('SELECT id FROM iot_tenants WHERE id = ? FOR UPDATE')->execute([$tenant]);
            } else {
                $database->query('SELECT pg_advisory_lock(275002, ' . $gateId . ')')->closeCursor();
                $advisoryHeld = true;
                $database->exec('CREATE FUNCTION type_io_export_gate() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN '
                    . 'IF NEW.id = ' . $database->quote($id) . ' AND NEW.completed_rows > OLD.completed_rows THEN '
                    . 'PERFORM pg_advisory_xact_lock(275002, ' . $gateId . '); END IF; RETURN NEW; END $$');
                $triggerInstalled = true;
                $database->exec('CREATE TRIGGER type_io_export_gate BEFORE UPDATE ON iot_exports FOR EACH ROW EXECUTE FUNCTION type_io_export_gate()');
            }
            $worker = new Type\Testing\Process([...$command, 'iot:exports', '1'], dirname(__DIR__), $environment);
            $started = hrtime(true);
            $until = $started + 8_000_000_000;
            $waiting = false;
            do {
                expect($worker->running(), '导出在预期锁等待前退出：' . $worker->stderr() . $worker->stdout());
                $waiting = $probe->query('SELECT pid, wait_event, EXTRACT(EPOCH FROM clock_timestamp() - xact_start) * 1000 AS transaction_age_ms '
                    . 'FROM pg_stat_activity WHERE ' . $controllerPid . ' = ANY(pg_blocking_pids(pid)) AND wait_event_type = \'Lock\'')->fetch(PDO::FETCH_ASSOC);
                if (is_array($waiting)) {
                    break;
                }
                usleep(2000);
            } while (hrtime(true) < $until);
            expect(is_array($waiting), '导出未到达真实数据库锁边界');
            $observed = hrtime(true);
            clearstatcache(true, $file);
            $fileBytes = is_file($file) ? filesize($file) : 0;
            $progress = $probe->prepare('SELECT completed_rows FROM iot_exports WHERE id = ?');
            $progress->execute([$id]);
            expect((int) $progress->fetchColumn() === 0, '文件持久化之前或提交之前可见进度被提前推进');
            $tenantLocked = null;
            if ($stage === 'before_file') {
                expect($fileBytes === 0, '等待租户锁期间不应已经创建导出文件');
            } else {
                expect($waiting['wait_event'] === 'advisory' && $fileBytes > 0, '未观察到刷盘完成后且进度未提交的文件');
                try {
                    $probe->prepare('SELECT id FROM iot_tenants WHERE id = ? FOR UPDATE NOWAIT')->execute([$tenant]);
                    $tenantLocked = false;
                } catch (PDOException $conflict) {
                    expect($conflict->getCode() === '55P03', '租户锁探测发生非锁冲突错误');
                    $tenantLocked = true;
                }
                expect($tenantLocked, '当前基线的导出文件工作应仍受租户事务锁保护');
            }
            usleep(50000);
            $releasing = hrtime(true);
            if ($stage === 'before_file') {
                $database->commit();
            } else {
                $database->query('SELECT pg_advisory_unlock(275002, ' . $gateId . ')')->closeCursor();
                $advisoryHeld = false;
            }
            $result = $worker->wait(15);
            $exitedAt = hrtime(true);
            expect($result->successful(), '释放锁后导出未完成：' . $result->stderr . $result->stdout);
            $worker = null;
            expect($job($id)['completed_rows'] === 100, '单步导出越过100行边界或未提交进度');
            $report['lock_cases'][$stage] = ['wait_event' => $waiting['wait_event'], 'transaction_age_at_observation_ms' => (float) $waiting['transaction_age_ms'],
                'start_to_observation_ms' => ($observed - $started) / 1e6, 'observed_hold_ms' => ($releasing - $observed) / 1e6,
                'release_to_command_exit_ms' => ($exitedAt - $releasing) / 1e6, 'file_bytes_before_commit' => $fileBytes,
                'visible_completed_rows_before_commit' => 0, 'tenant_locked_after_file' => $tenantLocked];
            if ($triggerInstalled) {
                $database->exec('DROP TRIGGER type_io_export_gate ON iot_exports');
                $database->exec('DROP FUNCTION type_io_export_gate()');
                $triggerInstalled = false;
            }
            $run();
            $finish($id);
            $clean($id);
        }
        foreach (['create_ms', 'worker_command_ms', 'verify_and_download_ms', 'total_ms'] as $metric) {
            $values = array_column(array_slice($report['observations'], $warmup), $metric);
            sort($values, SORT_NUMERIC);
            foreach (['p50' => 0.5, 'p95' => 0.95, 'p99' => 0.99] as $name => $percentile) {
                $report['percentiles'][$metric][$name] = $values[(int) ceil($samples * $percentile) - 1];
            }
        }
        $report['status'] = 'passed';
    } finally {
        // 装置的锁和触发器均位于专用实例；释放后才停止仍可能阻塞的worker。
        try {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            if ($advisoryHeld) {
                $database->query('SELECT pg_advisory_unlock(275002, ' . $gateId . ')')->closeCursor();
            }
        } finally {
            $worker?->stop();
            if ($triggerInstalled) {
                $database->exec('DROP TRIGGER IF EXISTS type_io_export_gate ON iot_exports');
                $database->exec('DROP FUNCTION IF EXISTS type_io_export_gate()');
            }
            $probe = null;
            if ($report['status'] !== 'passed') {
                $report['status'] = 'failed';
            }
            file_put_contents($base . '/io-export-baseline.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        }
    }
    return $report;
}
