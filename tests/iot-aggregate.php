<?php

declare(strict_types=1);

/** 真实接收服务准备事实；聚合、清理和读取统一由实际应用命令/HTTP执行，三库及无源码原生共用。 */
function iotAggregateChecks(Closure $request, array $tokens, string $tenantA, string $tenantB, PDO $database, array $command, array $environment, string $base): array
{
    $root = dirname(__DIR__);
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
        $path = '/customer/tenants/' . $tenantA;
        $admin = $tokens['bob'];
        $viewer = $tokens['alice'];
        $product = $request('POST', $path . '/products', $admin, $tenantA, ['name' => '分钟统计产品'], 201)['data'];
        $definition = ['properties' => [
            ['identifier' => 'temperature', 'name' => '温度', 'type' => 'number', 'required' => false, 'unit' => '°C'],
            ['identifier' => 'counter', 'name' => '计数器', 'type' => 'integer', 'required' => false, 'unit' => '次'],
            ['identifier' => 'note', 'name' => '备注', 'type' => 'string', 'required' => false, 'max_length' => 4096],
            ['identifier' => 'relay', 'name' => '继电器', 'type' => 'boolean', 'required' => false],
            ['identifier' => 'mode', 'name' => '模式', 'type' => 'enum', 'required' => false, 'values' => ['auto', 'manual']],
        ], 'events' => [['identifier' => 'fault', 'name' => '故障', 'parameters' => [['identifier' => 'code', 'name' => '故障码', 'type' => 'integer', 'required' => true]]]], 'commands' => []];
        $models = $path . '/products/' . $product['id'] . '/models';
        $request('POST', $models, $admin, $tenantA, ['definition' => $definition], 201);
        $request('POST', $models . '/1/publish', $admin, $tenantA, ['version' => 1], 200);
        $registered = $request('POST', $path . '/devices', $admin, $tenantA, ['name' => '分钟测试设备', 'product_id' => $product['id'], 'model_version' => 1], 201)['data'];
        $device = $registered['device'];
        $history = $path . '/devices/' . $device['id'] . '/history';
        $topic = $registered['credential']['topics']['publish'];
        $received = time() - 2;
        $minute = intdiv($received - 300, 60) * 60;
        $send = static function (string $sequence, int $sampled, array $values, array $options = []) use ($connection, $device, $topic, $received, $driver): array {
            $message = ['app_version' => 1, 'type' => $options['type'] ?? 'telemetry', 'device_id' => $device['id'], 'ownership_id' => $options['ownership_id'] ?? $device['ownership_id'],
                'model_version' => $options['model_version'] ?? 1, 'sequence' => $sequence, 'sampled_at' => $sampled, 'values' => $values];
            if ($message['type'] === 'event') {
                $message['identifier'] = 'fault';
            }
            return $connection->transaction(static fn (Type\Orm\Connection $transaction): array => app\iot\service\IngestionService::accept(
                $transaction,
                $topic,
                json_encode($message, JSON_THROW_ON_ERROR),
                1,
                $options['received_at'] ?? $received
            ), $driver === 'sqlite' ? 'immediate' : 'default');
        };
        $run = static fn (int $batch = 100): array => identityCommand([...$command, 'iot:aggregate', (string) $batch], $environment)['data'];
        $query = static fn (array $filters = []): array => $request('GET', $history . '?' . http_build_query(['view' => 'minutes'] + $filters), $viewer, $tenantA, null, 200);
        $curveFilters = ['view' => 'minute_curve', 'from' => $minute, 'to' => $minute + 179, 'product_id' => $product['id'], 'model_version' => 1, 'ownership_id' => $device['ownership_id'], 'field' => 'temperature'];
        $curve = static fn (array $changes = []): array => $request('GET', $history . '?' . http_build_query(array_replace($curveFilters, $changes)), $viewer, $tenantA, null, 200)['data'];
        $first = $send('10', $minute + 5, ['temperature' => 10, 'counter' => 0, 'relay' => false, 'mode' => 'auto', 'note' => str_repeat('长属性', 100)]);
        $send('11', $minute + 59, ['temperature' => 30]);
        $send('9', $minute + 59, ['temperature' => 20]);
        $send('12', $minute + 60, ['temperature' => 50, 'counter' => 4]);
        $send('13', $minute + 121, ['note' => '此分钟无数值']);
        $send('14', $minute + 122, ['code' => 99], ['type' => 'event']);
        expect($first['receipt']['status'] === 'accepted' && $query()['total'] === 0, '接收凭据应先存在，不等待分钟统计');
        $duplicate = $send('10', $minute + 5, ['temperature' => 10, 'counter' => 0, 'relay' => false, 'mode' => 'auto', 'note' => str_repeat('长属性', 100)]);
        expect($duplicate['receipt'] === $first['receipt'], '重复接收仍复用原始凭据');
        $batch = $run(2);
        expect($batch['examined'] === 2 && $batch['completed'] === 2 && $batch['has_more'], '聚合须遵守明确批次');
        while ($run()['has_more']) {
        }
        $rows = $query(['from' => $minute, 'to' => $minute + 179, 'sort' => 'sampled_asc']);
        expect($rows['total'] === 2 && $rows['per_page'] === 20 && $rows['items'][0]['window_start'] === $minute && $rows['items'][0]['window_end'] === $minute + 60, 'UTC边界必须使用半开整分钟');
        $statistics = $rows['items'][0]['fields']['temperature'];
        expect($statistics['count'] === 3 && (float) $statistics['sum'] === 60.0 && (float) $statistics['avg'] === 20.0
            && (float) $statistics['min'] === 10.0 && (float) $statistics['max'] === 30.0 && (float) $statistics['last'] === 30.0 && $statistics['last_sequence'] === '11', '六项函数和同采样秒序号顺序必须准确');
        expect(array_keys($rows['items'][0]['fields']) === ['temperature', 'counter'] && $rows['items'][0]['fields']['counter']['count'] === 1
            && (float) $rows['items'][0]['fields']['counter']['sum'] === 0.0, '零是有效样本，字符串布尔枚举不进入数值统计');
        // 组合运行时其他设备也可能留下合法告警待办；只核对本设备六条事实没有被聚合角色消费。
        $ownAlarmPending = $connection->query("SELECT COUNT(*) AS total FROM iot_ingestion_facts f WHERE f.device_id = ? AND NOT EXISTS (SELECT 1 FROM iot_ingestion_completed c WHERE c.message_id = f.message_id AND c.consumer = 'alarm')", [$device['id']]);
        expect((int) $ownAlarmPending[0]['total'] === 6
            && app\iot\service\IngestionService::pending($connection, 'aggregate', 100) === [] && $run()['completed'] === 0, '消费者独立完成且重试不重复累加');
        $plotted = $curve();
        expect($plotted['granularity_seconds'] === 60 && count($plotted['points']) === 3 && $plotted['points'][2]['value'] === null
            && $plotted['points'][2]['statistics'] === null && $plotted['property']['unit'] === '°C', '缺测必须为空且保留冻结单位和真实粒度：' . json_encode($plotted, JSON_THROW_ON_ERROR));
        foreach (['count' => 3, 'min' => 10, 'max' => 30, 'sum' => 60, 'avg' => 20, 'last' => 30] as $stat => $expected) {
            expect((float) $curve(['stat' => $stat])['points'][0]['value'] === (float) $expected, '每个统计函数均应可查询：' . $stat);
        }
        $current = $request('GET', $path . '/devices/' . $device['id'] . '/current', $viewer, $tenantA, null, 200)['data'];
        expect($send('8', $minute + 30, ['temperature' => -10])['code'] === 'accepted', '合法迟到必须接受');
        $run();
        $late = $curve()['points'][0]['statistics'];
        expect($late['count'] === 4 && (float) $late['sum'] === 50.0 && (float) $late['avg'] === 12.5 && (float) $late['last'] === 30.0, '迟到修正历史但不改较新last');
        $afterLate = $request('GET', $path . '/devices/' . $device['id'] . '/current', $viewer, $tenantA, null, 200)['data'];
        expect($current['fields'] === $afterLate['fields'] && $current['sequence'] === $afterLate['sequence'], '旧序号补传和聚合不能回退当前字段');
        $send('10000000000000000000000000000000000000', $minute + 2, ['temperature' => 7]);
        $run();
        expect($curve()['points'][0]['sequence'] === '11', 'last首先按采样时间，不能只按最大序号');
        $send('10000000000000000000000000000000000001', $minute + 59, ['temperature' => 35]);
        $run();
        expect($curve()['points'][0]['sequence'] === '10000000000000000000000000000000000001', '同秒last保留38位十进制序号，不转浮点');
        expect($send('10', $minute + 5, ['temperature' => 999])['code'] === 'content_conflict'
            && $send('15', $received - 172801, ['temperature' => 999])['code'] === 'sample_expired' && $run()['completed'] === 0, '冲突和超48小时消息不得进入聚合');
        $weighted = $curve(['from' => $minute - 3000 * 60, 'to' => $minute + 179]);
        $nonempty = array_values(array_filter($weighted['points'], static fn (array $point): bool => $point['value'] !== null));
        expect($weighted['granularity_seconds'] === 120 && count($weighted['points']) <= 2000 && count($nonempty) === 1
            && $nonempty[0]['statistics']['count'] === 7 && abs($nonempty[0]['value'] - 142.0 / 7.0) < 0.00001
            && (float) $nonempty[0]['statistics']['last'] === 50.0, '降密度按总sum/count而非平均值平均；last跨分钟保持采样顺序');
        $request('GET', $history . '?view=minutes', '', $tenantA, null, 401);
        foreach ([$tokens['outsider'], $tokens['platform']] as $outsider) {
            $request('GET', $history . '?view=minutes', $outsider, $tenantA, null, $outsider === $tokens['platform'] ? 401 : 403);
            $request('GET', $history . '?' . http_build_query($curveFilters), $outsider, $tenantA, null, $outsider === $tokens['platform'] ? 401 : 403);
        }
        $request('GET', '/customer/tenants/' . $tenantB . '/devices/' . $device['id'] . '/history?view=minutes', $admin, $tenantB, null, 404);
        foreach (['view=minutes&page=2', 'view=minutes&per_page=101', 'view=minutes&sort=received_asc', 'view=minutes&stat=avg', 'view=minutes&extra=1', 'view=minutes&from=1&to=' . $received,
            'view=minutes&cursor=bad', 'view=minute_curve&stat=wrong', 'view=minute_curve&field=temperature'] as $invalid) {
            $request('GET', $history . '?' . $invalid, $viewer, $tenantA, null, 422);
        }
        $request('GET', $history . '?view=minutes&page=1&page=2', $viewer, $tenantA, null, 400);
        foreach (['note', 'relay', 'mode', 'unknown'] as $field) {
            $request('GET', $history . '?' . http_build_query(array_replace($curveFilters, ['field' => $field])), $viewer, $tenantA, null, 422, 'history_numeric_field_required');
        }
        expect($query(['field' => 'note'])['total'] === 0, '非数值属性仅保留原始值');
        foreach (['sampled_asc', 'sampled_desc'] as $sort) {
            $one = $query(['per_page' => 1, 'sort' => $sort]);
            $two = $query(['per_page' => 1, 'sort' => $sort, 'page' => 2, 'cursor' => $one['next_cursor']]);
            expect($one['items'][0]['id'] !== $two['items'][0]['id'], '分钟按窗口与ID稳定键集翻页');
            $request('GET', $history . '?' . http_build_query(['view' => 'minutes', 'per_page' => 2, 'page' => 2, 'cursor' => $one['next_cursor']]), $viewer, $tenantA, null, 422);
        }
        // 两个实际工作进程争用同一事实和分钟行；中断一个后另一个完成，不能累计两次。
        $concurrent = $send('16', $minute + 20, ['temperature' => 8]);
        $database->beginTransaction();
        $lock = $database->prepare('UPDATE iot_minute_aggregates SET fields_json = fields_json WHERE id = ?');
        $lock->execute([$rows['items'][0]['id']]);
        $workerA = new Type\Testing\Process([...$command, 'iot:aggregate'], $root, $environment);
        $workerB = new Type\Testing\Process([...$command, 'iot:aggregate'], $root, $environment);
        try {
            usleep(250000);
            expect($workerA->running() && $workerB->running(), '聚合进程应在真实写锁处等待');
            $workerA->stop(0);
            $database->rollBack();
            expect($workerB->wait(10)->successful(), '中断另一工作进程后待处理事实应可恢复');
        } finally {
            $workerA->stop();
            $workerB->stop();
            if ($database->inTransaction()) {
                $database->rollBack();
            }
        }
        expect($curve()['points'][0]['statistics']['count'] === 7 && $run()['completed'] === 0, '争用及中断重试只累加一次');
        // 用受控首次接收时间准备8天前的合法历史；聚合不依赖原始保留期继续存在。
        $oldMinute = intdiv($received - 8 * 86400, 60) * 60;
        $old = $send('17', $oldMinute + 5, ['temperature' => 3], ['received_at' => $oldMinute + 10]);
        $run();
        expect(identityCommand([...$command, 'iot:history-clean'], $environment)['data']['blocked'] === 1, 'alarm未完成时不能因聚合完成而删原始');
        $connection->table('iot_ingestion_completed')->insert(['message_id' => $old['message_id'], 'consumer' => 'alarm', 'completed_at' => time()]);
        expect(identityCommand([...$command, 'iot:history-clean'], $environment)['data']['deleted'] === 1, '独立完成事实齐备后原始按7天清理');
        expect($curve(['from' => $oldMinute, 'to' => $oldMinute + 59])['raw_count'] === 1
            && $request('GET', $history . '?' . http_build_query(['from' => $oldMinute, 'to' => $oldMinute + 59]), $viewer, $tenantA, null, 200)['total'] === 0, '原始已物理删除后分钟仍可查');
        // 2001个真实合法分钟位于48小时窗口内；每次最多消费100条，查询不加载全部原始事实。
        for ($index = 0; $index < 2001; $index++) {
            $sampled = $minute - (2100 - $index) * 60;
            expect($send((string) (1000 + $index), $sampled, ['temperature' => 2])['code'] === 'accepted', '密集分钟必须是合法接收');
        }
        $rounds = 0;
        do {
            $batch = $run();
            expect($batch['examined'] <= 100, '每次消费预算不能因积压增长');
            $rounds++;
        } while ($batch['has_more'] && $rounds < 30);
        expect(!$batch['has_more'] && $rounds === 21, '2001个待处理分钟必须分21批完成');
        $dense = $curve(['from' => $minute - 2100 * 60, 'to' => $minute - 99 * 60 - 1]);
        expect($dense['minute_count'] === 2001 && $dense['raw_count'] === 2001 && $dense['granularity_seconds'] === 120
            && count($dense['points']) <= 2000 && array_sum(array_column($dense['points'], 'count')) === 2001, '三库SQL降密度不丢失有效统计数量');
        $ninety = $curve(['from' => $received - 90 * 86400, 'to' => $received]);
        expect($ninety['granularity_seconds'] % 60 === 0 && count($ninety['points']) <= 2000, '90天最多2000点且粒度为整分钟倍数');
        $changed = $definition;
        $changed['properties'][0]['unit'] = '°F';
        $request('POST', $models, $admin, $tenantA, ['definition' => $changed], 201);
        $request('POST', $models . '/2/publish', $admin, $tenantA, ['version' => 1], 200);
        $connection->table('iot_devices')->where('id', '=', $device['id'])->update(['model_version' => 2]);
        $send('4000', $minute + 5, ['temperature' => 86], ['model_version' => 2]);
        $run();
        $fahrenheit = $curve(['model_version' => 2]);
        expect($fahrenheit['raw_count'] === 1 && $fahrenheit['property']['unit'] === '°F' && (float) $fahrenheit['points'][0]['value'] === 86.0
            && $curve()['raw_count'] === 8, '同一设备不同版本与单位不得混合');
        $changed['properties'][0] = ['identifier' => 'temperature', 'name' => '温度描述', 'type' => 'string', 'required' => false];
        $request('POST', $models, $admin, $tenantA, ['definition' => $changed], 201);
        $request('POST', $models . '/3/publish', $admin, $tenantA, ['version' => 1], 200);
        $connection->table('iot_devices')->where('id', '=', $device['id'])->update(['model_version' => 3]);
        expect($send('4001', $minute + 5, ['temperature' => '正常'], ['model_version' => 3])['code'] === 'accepted', '新版本类型变化仍按绑定契约接收');
        $run();
        expect($query(['model_version' => 3])['total'] === 0, '字符串版本不能重用旧数值统计');
        $request('GET', $history . '?' . http_build_query(array_replace($curveFilters, ['model_version' => 3])), $viewer, $tenantA, null, 422, 'history_numeric_field_required');
        $connection->table('iot_devices')->where('id', '=', $device['id'])->update(['model_version' => 2]);
        // 到期窗口作为先前已经消费的持久状态装置；第二行锁等待处中断，首行删除也须回滚。
        $cutoff = time() - 90 * 86400;
        $expiredEnd = intdiv($cutoff, 60) * 60;
        $expired = [];
        $insert = $database->prepare('INSERT INTO iot_minute_aggregates (id, tenant_id, device_id, product_id, model_version, ownership_id, window_start, window_end, fields_json, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        for ($index = 0; $index < 3; $index++) {
            $id = hash('sha256', 'expired-minute-' . $index);
            $expired[] = $id;
            $end = $expiredEnd - (3 - $index) * 60;
            $insert->execute([$id, $tenantA, $device['id'], $product['id'], 1, $device['ownership_id'], $end - 60, $end, '{}', $end]);
        }
        $edgeId = hash('sha256', 'retained-minute-edge');
        $edgeEnd = $expiredEnd + 120;
        $insert->execute([$edgeId, $tenantA, $device['id'], $product['id'], 1, $device['ownership_id'], $edgeEnd - 60, $edgeEnd, json_encode(['temperature' => $statistics], JSON_THROW_ON_ERROR), $edgeEnd]);
        expect($query(['from' => $cutoff - 300, 'to' => $edgeEnd])['total'] === 1, '分钟按窗口结束计时：结束尚未满90天的边界窗口仍可查询');
        $outside = $send('4002', $expiredEnd - 60, ['temperature' => 5], ['model_version' => 2, 'received_at' => $expiredEnd - 50]);
        $run();
        expect($connection->table('iot_ingestion_completed')->where('message_id', '=', $outside['message_id'])->where('consumer', '=', 'aggregate')->first() !== null
            && $query(['model_version' => 2, 'from' => $cutoff - 300, 'to' => $edgeEnd])['total'] === 0, '停机积压超出90天的窗口按保留策略处理完成，不能复活过期统计');
        $database->beginTransaction();
        $lock->execute([$expired[1]]);
        $cleaner = new Type\Testing\Process([...$command, 'iot:aggregate-clean', '3'], $root, $environment);
        try {
            usleep(250000);
            expect($cleaner->running(), '清理需等待真实第二行写锁');
            $cleaner->stop(0);
        } finally {
            $cleaner->stop();
            $database->rollBack();
        }
        $countExpired = $database->prepare('SELECT COUNT(*) FROM iot_minute_aggregates WHERE window_end <= ?');
        $countExpired->execute([$cutoff]);
        expect((int) $countExpired->fetchColumn() === 3, '清理进程中断必须回滚本批已删除行');
        $countExpired->closeCursor();
        $cleaned = identityCommand([...$command, 'iot:aggregate-clean', '2'], $environment)['data'];
        expect($cleaned['deleted'] === 2 && $cleaned['has_more'] && identityCommand([...$command, 'iot:aggregate-clean'], $environment)['data']['deleted'] === 1
            && identityCommand([...$command, 'iot:aggregate-clean'], $environment)['data']['deleted'] === 0, '清理重启按预算恢复且幂等');
        foreach ([['iot:aggregate', '101'], ['iot:aggregate', '0'], ['iot:aggregate', '1', 'alarm'], ['iot:aggregate-clean', '1001']] as $arguments) {
            $invalid = new Type\Testing\Process([...$command, ...$arguments], $root, $environment);
            try {
                expect(!$invalid->wait(10)->successful(), '非法批次和消费者覆盖参数必须失败');
            } finally {
                $invalid->stop();
            }
        }
        // 仅准备转移后的合法设备关系，验证原始已删除后的旧租户分钟授权；不声明完成转移流程。
        $otherProduct = $request('POST', '/customer/tenants/' . $tenantB . '/products', $admin, $tenantB, ['name' => '分钟转入产品'], 201)['data'];
        $otherModels = '/customer/tenants/' . $tenantB . '/products/' . $otherProduct['id'] . '/models';
        $request('POST', $otherModels, $admin, $tenantB, ['definition' => $changed], 201);
        $request('POST', $otherModels . '/1/publish', $admin, $tenantB, ['version' => 1], 200);
        $connection->table('iot_devices')->where('id', '=', $device['id'])->update(['tenant_id' => $tenantB, 'product_id' => $otherProduct['id'], 'model_version' => 1, 'ownership_id' => bin2hex(random_bytes(16))]);
        expect($request('GET', '/customer/tenants/' . $tenantB . '/devices/' . $device['id'] . '/history?view=minutes', $admin, $tenantB, null, 200)['total'] === 0
            && $curve(['from' => $oldMinute, 'to' => $oldMinute + 59])['raw_count'] === 1, '新归属不得读取原租户分钟历史');
        $finalPath = $history . '?' . http_build_query(['view' => 'minutes', 'from' => $oldMinute, 'to' => $oldMinute + 59]);
        $final = $request('GET', $finalPath, $viewer, $tenantA, null, 200);
        return ['path' => $finalPath, 'items' => $final['items'], 'checks' => ['six-statistics', 'utc-minute-boundary', 'missing-not-zero', 'independent-consumer', 'late-correction', 'duplicate-retry', 'decimal-sequence-last',
            'weighted-rollup-2000-points', 'strict-query-and-authorization', 'worker-contention-interruption-restart', 'raw-expired-minute-readable', '2001-minutes-bounded-batches', '90-days', 'model-unit-isolation', 'cleanup-interruption-restart', 'historical-tenant-ownership']];
    } finally {
        $scope->close();
        $pool->close();
    }
}
