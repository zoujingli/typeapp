<?php

declare(strict_types=1);

/**
 * 历史HTTP和实际清理命令通过身份三库装置执行；SQL仅准备已持久事实和故障注入。
 * 接收去重与同步回执另由iot-ingestion和MQTT验收证明，本装置不冒充协议接收链路。
 */
function iotHistoryChecks(Closure $request, array $tokens, string $tenantA, string $tenantB, PDO $database, array $command, array $environment, string $base): array
{
    $root = dirname(__DIR__);
    $driver = $environment['DB_DRIVER'];
    $path = '/customer/tenants/' . $tenantA;
    $admin = $tokens['bob'];
    $viewer = $tokens['alice'];
    $product = $request('POST', $path . '/products', $admin, $tenantA, ['name' => '历史温度产品'], 201)['data'];
    $definition = ['properties' => [
        ['identifier' => 'temperature', 'name' => '温度', 'type' => 'number', 'required' => false, 'unit' => '°C'],
        ['identifier' => 'note', 'name' => '备注', 'type' => 'string', 'required' => false, 'max_length' => 4096],
        ['identifier' => 'relay', 'name' => '继电器', 'type' => 'boolean', 'required' => false],
    ], 'events' => [], 'commands' => []];
    $models = $path . '/products/' . $product['id'] . '/models';
    $request('POST', $models, $admin, $tenantA, ['definition' => $definition], 201);
    $request('POST', $models . '/1/publish', $admin, $tenantA, ['version' => 1], 200);
    $device = $request('POST', $path . '/devices', $admin, $tenantA, ['name' => '历史测试设备', 'product_id' => $product['id'], 'model_version' => 1], 201)['data']['device'];
    $history = $path . '/devices/' . $device['id'] . '/history';
    $now = time() - 5;
    $long = str_repeat('长文本<literal>&"', 100);
    $ledger = $database->prepare('INSERT INTO iot_ingestion (message_id, tenant_id, device_id, ownership_id, sequence, content_hash, status, code, received_at, receipt_proof_nonce, receipt_requested_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $facts = $database->prepare('INSERT INTO iot_ingestion_facts (message_id, tenant_id, device_id, ownership_id, product_id, model_version, sequence, type, identifier, sampled_at, received_at, values_json, current_advanced) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $completed = $database->prepare('INSERT INTO iot_ingestion_completed (message_id, consumer, completed_at) VALUES (?, ?, ?)');
    $seed = static function (string $sequence, int $sampled, int $received, array $values, array $options = []) use ($ledger, $facts, $completed, $tenantA, $product, $device): string {
        $ownership = $options['ownership_id'] ?? $device['ownership_id'];
        $id = hash('sha256', $device['id'] . ':' . $ownership . ':' . $sequence);
        $status = $options['status'] ?? 'accepted';
        $ledger->execute([$id, $tenantA, $device['id'], $ownership, $sequence, hash('sha256', $sequence), $status,
            $status === 'accepted' ? 'accepted' : 'invalid_model_values', $received, bin2hex(random_bytes(16)), $received]);
        if ($status === 'accepted') {
            $facts->execute([$id, $tenantA, $device['id'], $ownership, $product['id'], $options['model_version'] ?? 1, $sequence,
                $options['type'] ?? 'telemetry', '', $sampled, $received, json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 0]);
            foreach ($options['consumers'] ?? ['current'] as $consumer) {
                $completed->execute([$id, $consumer, $received]);
            }
        }
        return $id;
    };
    $seeded = [];
    for ($i = 1; $i <= 25; $i++) {
        $seeded[] = $seed((string) $i, $now - 60, $now - 30, ['temperature' => $i * 0.5, 'note' => $i === 1 ? $long : '记录' . $i, 'relay' => $i % 2 === 0]);
    }
    $seed('26', $now - 20, $now - 10, ['note' => '缺测']);
    $seed('27', $now - 10, $now - 5, ['temperature' => 20]);
    $seed('28', $now - 100, $now - 5, ['temperature' => 888], ['type' => 'event']);
    $first = $request('GET', $history, $viewer, $tenantA, null, 200);
    expect($first['total'] === 27 && count($first['items']) === 20 && $first['per_page'] === 20 && $first['sort'] === 'sampled_desc'
        && $first['window']['to'] - $first['window']['from'] === 86400, '默认应为单设备24小时、20条、明确采样降序');
    expect($first['items'][0]['sequence'] === '27' && $first['items'][1]['values'] === ['note' => '缺测'], '原始事实保留缺失属性和类型');
    $request('GET', $history, '', $tenantA, null, 401);
    $request('GET', $history, $admin, $tenantB, null, 403, 'tenant_context_mismatch');
    $request('GET', $history, $tokens['outsider'], $tenantA, null, 403, 'permission_denied');
    $request('GET', $history, $tokens['platform'], $tenantA, null, 401, 'unauthenticated');
    $request('GET', '/customer/tenants/' . $tenantB . '/devices/' . $device['id'] . '/history', $admin, $tenantB, null, 404, 'device_not_found');
    foreach (['page=0', 'page=2', 'per_page=101', 'per_page=-1', 'sort=invalid', 'tenant_id=' . $tenantB, 'type=event', 'field=x%27', 'from=2&to=1', 'cursor=bad', 'from=1&to=' . $now] as $invalid) {
        $request('GET', $history . '?' . $invalid, $viewer, $tenantA, null, 422);
    }
    $request('GET', $history . '?page=1&page=2', $viewer, $tenantA, null, 400);
    // 新上报不能插入已经固定接收边界的后续页；同采样秒采用消息ID键集排序。
    $seed('29', $now - 50, time(), ['temperature' => 29]);
    $second = $request('GET', $history . '?' . http_build_query(['page' => 2, 'cursor' => $first['next_cursor']]), $viewer, $tenantA, null, 200);
    expect(count($second['items']) === 7 && $second['next_cursor'] === null
        && array_intersect(array_column($first['items'], 'message_id'), array_column($second['items'], 'message_id')) === [], '并发新增与相同时间不应让翻页重复或跳入新记录');
    $request('GET', $history . '?' . http_build_query(['page' => 2, 'per_page' => 50, 'cursor' => $first['next_cursor']]), $viewer, $tenantA, null, 422, 'history_cursor_invalid');
    $cursor = json_decode(base64_decode(strtr($first['next_cursor'], '-_', '+/')), true, 4, JSON_THROW_ON_ERROR);
    $cursor['snapshot'] = time() - 901;
    $request('GET', $history . '?' . http_build_query(['page' => 2, 'cursor' => base64_encode(json_encode($cursor, JSON_THROW_ON_ERROR))]), $viewer, $tenantA, null, 409, 'history_snapshot_expired');
    $cursor['snapshot'] = $first['window']['snapshot'];
    $cursor['time'] = 0;
    $request('GET', $history . '?' . http_build_query(['page' => 2, 'cursor' => base64_encode(json_encode($cursor, JSON_THROW_ON_ERROR))]), $viewer, $tenantA, null, 422, 'history_page_out_of_range');
    $scope = ['from' => $now - 3600, 'to' => $now, 'product_id' => $product['id'], 'model_version' => 1, 'ownership_id' => $device['ownership_id']];
    $records = $request('GET', $history . '?' . http_build_query($scope + ['per_page' => 100, 'sort' => 'sampled_asc']), $viewer, $tenantA, null, 200);
    $one = array_values(array_filter($records['items'], static fn (array $row): bool => $row['sequence'] === '1'))[0];
    expect($one['values']['note'] === $long && $one['model'] === $definition && $one['values']['relay'] === false
        && $one['sampled_at'] === $now - 60 && $one['received_at'] === $now - 30, '长文本、布尔、单位、双时间、冻结模型须准确返回');
    $chartParams = $scope + ['view' => 'curve', 'field' => 'temperature'];
    $curve = $request('GET', $history . '?' . http_build_query($chartParams), $viewer, $tenantA, null, 200)['data'];
    expect($curve['granularity_seconds'] === 0 && $curve['function'] === 'raw' && $curve['property']['unit'] === '°C'
        && count(array_filter($curve['points'], static fn (array $point): bool => $point['value'] === null)) === 1, '低密度曲线需保留缺测null及冻结单位');
    $request('GET', $history . '?view=curve&field=temperature', $viewer, $tenantA, null, 422, 'history_curve_scope_required');
    foreach (['note', 'relay', 'missing'] as $field) {
        $request('GET', $history . '?' . http_build_query(array_replace($chartParams, ['field' => $field])), $viewer, $tenantA, null, 422, 'history_numeric_field_required');
    }
    foreach (['sampled_asc', 'sampled_desc', 'received_asc', 'received_desc'] as $sort) {
        $sorted = $request('GET', $history . '?' . http_build_query($scope + ['sort' => $sort, 'per_page' => 2]), $viewer, $tenantA, null, 200);
        $next = $request('GET', $history . '?' . http_build_query($scope + ['sort' => $sort, 'per_page' => 2, 'page' => 2, 'cursor' => $sorted['next_cursor']]), $viewer, $tenantA, null, 200);
        expect(array_intersect(array_column($sorted['items'], 'message_id'), array_column($next['items'], 'message_id')) === [], '每种允许排序都应采用稳定键集');
    }
    $changed = $definition;
    $changed['properties'][0]['unit'] = '°F';
    $request('POST', $models, $admin, $tenantA, ['definition' => $changed], 201);
    $request('POST', $models . '/2/publish', $admin, $tenantA, ['version' => 1], 200);
    $seed('30', $now - 1, $now - 1, ['temperature' => 90], ['model_version' => 2]);
    $versionTwo = $request('GET', $history . '?' . http_build_query(array_replace($chartParams, ['model_version' => 2])), $viewer, $tenantA, null, 200)['data'];
    expect($versionTwo['raw_count'] === 1 && $versionTwo['property']['unit'] === '°F' && (float) $versionTwo['points'][0]['value'] === 90.0, '不同模型与单位禁止混图');
    $database->beginTransaction();
    try {
        for ($i = 1000; $i < 3001; $i++) {
            $seed((string) $i, $now - 7200 + ($i % 10), $now - 5, ['temperature' => 10]);
        }
        $database->commit();
    } catch (Throwable $failure) {
        $database->rollBack();
        throw $failure;
    }
    $denseParams = array_replace($chartParams, ['from' => $now - 8000, 'to' => $now - 4000]);
    $dense = $request('GET', $history . '?' . http_build_query($denseParams), $viewer, $tenantA, null, 200)['data'];
    expect($dense['raw_count'] === 2001 && $dense['granularity_seconds'] === 3 && $dense['function'] === 'display_average' && count($dense['points']) <= 2000
        && array_sum(array_column($dense['points'], 'count')) === 2001
        && count(array_filter($dense['points'], static fn (array $point): bool => $point['value'] === null)) > 1000, '密集数据必须在数据库有界分桶，缺测不补零，真实粒度可见');
    foreach ($dense['points'] as $point) {
        expect($point['value'] === null || (float) $point['value'] === 10.0, '三个真实数据库均应计算数值平均');
    }
    $rawDense = $request('GET', $history . '?' . http_build_query(array_replace($denseParams, ['view' => 'records', 'per_page' => 100])), $viewer, $tenantA, null, 200);
    expect($rawDense['total'] === 2001 && count($rawDense['items']) === 100 && is_string($rawDense['next_cursor']), '图表降采样不能替代原始分页');
    $cutoff = time() - 7 * 86400;
    $late = $seed('4000', $cutoff - 169000, $cutoff + 3600, ['temperature' => -3]);
    $lateResult = $request('GET', $history . '?' . http_build_query(['from' => $cutoff - 169001, 'to' => $cutoff - 168999]), $viewer, $tenantA, null, 200);
    expect($lateResult['total'] === 1 && $lateResult['items'][0]['message_id'] === $late, '48小时补报的七天保留必须按首次接收计时');
    $readyConsumers = ['current', 'aggregate', 'alarm'];
    $expired = [
        $seed('5001', $cutoff - 20, $cutoff - 20, ['temperature' => 1], ['consumers' => $readyConsumers]),
        $seed('5002', $cutoff - 19, $cutoff - 19, ['temperature' => 2], ['consumers' => $readyConsumers]),
        $seed('5003', $cutoff - 18, $cutoff - 18, ['temperature' => 3], ['consumers' => ['current', 'alarm']]),
        $seed('5004', $cutoff, $cutoff, ['temperature' => 4], ['status' => 'rejected']),
    ];
    $projection = $database->prepare('INSERT INTO iot_current_data (device_id, ownership_id, model_version, sequence, sampled_at, received_at, fields_json) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $projection->execute([$device['id'], $device['ownership_id'], 1, '5001', $cutoff - 20, $cutoff - 20, '{"temperature":{"identifier":"temperature","value":1,"sequence":"5001","sampled_at":1,"received_at":1}}']);
    expect($request('GET', $history . '?' . http_build_query(['from' => $cutoff - 60, 'to' => $cutoff]), $viewer, $tenantA, null, 200)['total'] === 0, '已到接收保留期限的原始记录不能继续查询');
    $countExpired = static function () use ($database, $cutoff): int {
        $query = $database->prepare('SELECT COUNT(*) FROM iot_ingestion WHERE received_at <= ?');
        $query->execute([$cutoff]);
        return (int) $query->fetchColumn();
    };
    $database->beginTransaction();
    $lock = $database->prepare('UPDATE iot_ingestion SET code = code WHERE message_id = ?');
    $lock->execute([$expired[1]]);
    $interrupted = new Type\Testing\Process([...$command, 'iot:history-clean', '3'], $root, $environment);
    try {
        usleep(300000);
        expect($interrupted->running(), '清理应在真实数据库行锁处等待');
        $interrupted->stop(0);
        expect(!$interrupted->running(), '被中断的清理进程必须退出');
    } finally {
        $interrupted->stop();
        $database->rollBack();
    }
    expect($countExpired() === 4, '清理中断后已执行的第一条删除也必须回滚');
    $firstClean = identityCommand([...$command, 'iot:history-clean', '2'], $environment)['data'];
    expect($firstClean['examined'] === 2 && $firstClean['deleted'] === 2 && $firstClean['blocked'] === 0 && $firstClean['has_more'], '重启清理必须遵守每批预算');
    $nextClean = identityCommand([...$command, 'iot:history-clean', '2', $firstClean['next_cursor']], $environment)['data'];
    expect($nextClean['deleted'] === 1 && $nextClean['blocked'] === 1 && !$nextClean['has_more'] && $countExpired() === 1, '未完成aggregate必须保留；拒绝账本独立到期回收');
    $blockedClean = identityCommand([...$command, 'iot:history-clean'], $environment)['data'];
    expect($blockedClean['deleted'] === 0 && $blockedClean['blocked'] === 1, '不能因消费者尚未实现而自动跳过完成条件');
    $completed->execute([$expired[2], 'aggregate', time()]);
    expect(identityCommand([...$command, 'iot:history-clean'], $environment)['data']['deleted'] === 1
        && identityCommand([...$command, 'iot:history-clean'], $environment)['data']['deleted'] === 0, '补齐消费事实后新清理进程恢复且幂等');
    expect((int) $database->query('SELECT COUNT(*) FROM iot_current_data')->fetchColumn() >= 1, '历史清理不得删除已有当前投影');
    $stillLate = $database->prepare('SELECT COUNT(*) FROM iot_ingestion_facts WHERE message_id = ?');
    $stillLate->execute([$late]);
    expect((int) $stillLate->fetchColumn() === 1, '补报尚未到接收保留期限不得清理');
    $stillLate->closeCursor();
    foreach ([['1001'], ['0'], ['2', 'invalid'], ['1', '', 'alarm']] as $arguments) {
        $invalid = new Type\Testing\Process([...$command, 'iot:history-clean', ...$arguments], $root, $environment);
        try {
            expect(!$invalid->wait(10)->successful(), '清理参数错误必须非零退出，不能开放消费者删减参数');
        } finally {
            $invalid->stop();
        }
    }
    // 此处只准备历史归属的读取装置；正式审批、隔离和切换由转移服务及MQTT用例证明。
    $otherProduct = $request('POST', '/customer/tenants/' . $tenantB . '/products', $admin, $tenantB, ['name' => '转入产品'], 201)['data'];
    $otherModels = '/customer/tenants/' . $tenantB . '/products/' . $otherProduct['id'] . '/models';
    $request('POST', $otherModels, $admin, $tenantB, ['definition' => $changed], 201);
    $request('POST', $otherModels . '/1/publish', $admin, $tenantB, ['version' => 1], 200);
    $transfer = $database->prepare('UPDATE iot_devices SET tenant_id = ?, product_id = ?, ownership_id = ? WHERE id = ?');
    $transfer->execute([$tenantB, $otherProduct['id'], bin2hex(random_bytes(16)), $device['id']]);
    expect($request('GET', '/customer/tenants/' . $tenantB . '/devices/' . $device['id'] . '/history', $admin, $tenantB, null, 200)['total'] === 0, '新租户不能读取原租户历史');
    $finalPath = $history . '?' . http_build_query($scope + ['per_page' => 100]);
    $afterTransfer = $request('GET', $finalPath, $viewer, $tenantA, null, 200);
    expect($afterTransfer['total'] >= 27 && $afterTransfer['items'][0]['tenant_id'] === $tenantA && $afterTransfer['items'][0]['model'] === $definition, '原租户可按当时产品和模型查询转移前历史');
    return ['path' => $finalPath, 'items' => $afterTransfer['items'], 'checks' => ['default-24h', 'stable-four-sorts', 'strict-query', 'tenant-transfer', 'frozen-model-units', 'null-gaps', 'bounded-2000-buckets', 'raw-paging', 'received-retention', 'fixed-consumers', 'cleanup-process-interruption-restart', 'current-preserved']];
}
