<?php

declare(strict_types=1);

/** 真实首次接收事实配合应用HTTP及独立消费者，三数据库和无源码原生产物共用。 */
function iotAlarmChecks(Closure $request, array $tokens, string $tenantA, string $tenantB, PDO $database, array $command, array $environment, string $base, array $fixture): array
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
        $tenantVersion = $connection->table('iot_tenants')->where('id', '=', $tenantA)->first()['version'];
        $path = '/customer/tenants/' . $tenantA;
        $admin = $tokens['bob'];
        $viewer = $tokens['alice'];
        iotAlarmRole($request, $admin, $tenantA, 'device-reader', ['customer.devices.read', 'customer.alarms.read', 'customer.notifications.read']);
        $request('GET', $path . '/alarms', $tokens['carol'], $tenantA, null, 403);
        $request('GET', $path . '/notifications', $tokens['carol'], $tenantA, null, 403);
        $product = $request('POST', $path . '/products', $admin, $tenantA, ['name' => '阈值告警产品'], 201)['data'];
        $definition = ['properties' => [
            ['identifier' => 'temperature', 'name' => '温度', 'type' => 'number', 'required' => false, 'unit' => '°C'],
            ['identifier' => 'counter', 'name' => '计数', 'type' => 'integer', 'required' => false],
            ['identifier' => 'note', 'name' => '文本', 'type' => 'string', 'required' => false],
        ], 'events' => [], 'commands' => []];
        $models = $path . '/products/' . $product['id'] . '/models';
        $request('POST', $models, $admin, $tenantA, ['definition' => $definition], 201);
        $request('POST', $models . '/1/publish', $admin, $tenantA, ['version' => 1], 200);
        $registered = $request('POST', $path . '/devices', $admin, $tenantA, ['name' => '阈值验收设备', 'product_id' => $product['id'], 'model_version' => 1], 201)['data'];
        $device = $registered['device'];
        $topic = $registered['credential']['topics']['publish'];
        $rules = $path . '/alarm-rules';
        $alarms = $path . '/alarms';
        $now = time() - 600;
        $sequence = 0;
        $clock = $now;
        $send = static function (array $values, array $options = []) use ($connection, $device, $topic, &$sequence, &$clock, $driver): array {
            $sequence++;
            $clock++;
            $received = $options['received_at'] ?? $clock;
            $message = ['app_version' => 1, 'type' => 'telemetry', 'device_id' => $device['id'], 'ownership_id' => $device['ownership_id'],
                'model_version' => 1, 'sequence' => $options['sequence'] ?? (string) $sequence, 'sampled_at' => $options['sampled_at'] ?? $received, 'values' => $values];
            return $connection->transaction(static fn (Type\Orm\Connection $transaction): array => app\iot\service\IngestionService::accept(
                $transaction,
                $topic,
                json_encode($message, JSON_THROW_ON_ERROR),
                1,
                $received
            ), $connection->transactionDepth() === 0 && $driver === 'sqlite' ? 'immediate' : 'default');
        };
        $run = static fn (int $batch = 100): array => identityCommand([...$command, 'iot:alarm', (string) $batch], $environment)['data'];
        // 历史/聚合组合装置保留的待办使用真实消费者排空，避免前序设备占满本场景单次100条预算。
        for ($drain = 0; $drain < 100; $drain++) {
            if (!$run()['has_more']) {
                break;
            }
        }
        expect($drain < 100, '前序隔离装置的告警待办未在预算内排空');
        $list = static fn (array $filters = []): array => $request('GET', $alarms . '?' . http_build_query($filters), $viewer, $tenantA, null, 200);
        $ruleData = ['name' => '温度闭区间', 'device_id' => $device['id'], 'field' => 'temperature', 'lower' => 0, 'upper' => 10, 'hysteresis' => 2];
        foreach ([['lower' => null, 'upper' => null], ['hysteresis' => -1], ['hysteresis' => 6], ['lower' => 11], ['lower' => '0'], ['enabled' => 'yes']] as $invalid) {
            $request('POST', $rules, $admin, $tenantA, array_replace($ruleData, $invalid), 422);
        }
        $request('POST', $rules, $admin, $tenantA, array_replace($ruleData, ['field' => 'note']), 422, 'alarm_numeric_field_required');
        $request('POST', $rules, $viewer, $tenantA, $ruleData, 403);
        $request('POST', '/customer/tenants/' . $tenantB . '/alarm-rules', $admin, $tenantB, $ruleData, 404, 'device_not_found');
        $rule = $request('POST', $rules, $admin, $tenantA, $ruleData, 201)['data'];
        $rulePath = $rules . '/' . $rule['id'];
        $version = static fn (): array => $request('GET', $rulePath . '/versions', $viewer, $tenantA, null, 200);
        $count = static fn (): int => $version()['items'][0]['trigger_count'];
        $send(['temperature' => 10]);
        $send(['temperature' => 0]);
        $run();
        expect($list()['total'] === 0 && $count() === 0, '阈值等号不得触发，数值零合法');
        $first = $send(['temperature' => 11]);
        expect($first['code'] === 'accepted' && $list()['total'] === 0, '回执不等待alarm消费者');
        $send(['temperature' => 12]);
        $run();
        expect($count() === 2 && $list()['total'] === 0, '两次超限不得提前触发');
        $send(['temperature' => 13]);
        $run();
        $active = $list(['status' => 'active'])['items'][0];
        expect($active['rule_version'] === 1 && (float) $active['definition']['hysteresis'] === 2.0 && (float) $active['trigger']['value'] === 13.0, '第三个严格超限样本保存冻结规则');
        $request('POST', $alarms . '/' . $active['id'] . '/acknowledge', $viewer, $tenantA, [], 403);
        $acknowledged = $request('POST', $alarms . '/' . $active['id'] . '/acknowledge', $fixture['simulated']['accessToken'], $tenantA, [], 200)['data'];
        $repeated = $request('POST', $alarms . '/' . $active['id'] . '/acknowledge', $admin, $tenantA, [], 200)['data'];
        expect($acknowledged['status'] === 'active' && $acknowledged['ended_at'] === null && $acknowledged['acknowledged_at'] !== null
            && $repeated['acknowledged_at'] === $acknowledged['acknowledged_at'] && $repeated['acknowledged_by'] === $acknowledged['acknowledged_by'], '确认独立、首次人员时间稳定且重复幂等');
        $send(['temperature' => 14]);
        $send(['temperature' => 15]);
        $send(['note' => '缺测不会恢复']);
        $run();
        expect($list()['total'] === 1 && $list(['status' => 'active'])['total'] === 1, '持续超限和缺测不得复制或恢复活动告警');
        $send(['temperature' => 8]);
        $send(['temperature' => 2]);
        $send(['temperature' => 9]);
        $run();
        expect($list(['status' => 'active'])['total'] === 1 && $version()['items'][0]['recovery_count'] === 0, '回差灰区中断恢复计数');
        $send(['temperature' => 2]);
        $send(['temperature' => 8]);
        $send(['temperature' => 5]);
        $run();
        $ended = $list()['items'][0];
        expect($ended['end_reason'] === 'value_recovered' && (float) $ended['recovery']['value'] === 5.0 && $ended['ended_at'] >= $ended['created_at'], '闭恢复区间连续三次保存恢复双时间');
        expect($ended['acknowledged_at'] === $acknowledged['acknowledged_at'] && $ended['acknowledged_by'] === $acknowledged['acknowledged_by'], '恢复不得覆盖人员确认');
        $send(['temperature' => -1]);
        $send(['temperature' => -2]);
        $clock += 30;
        $send(['temperature' => -3]);
        $run();
        expect($count() === 1 && $list()['total'] === 1, '相邻有效样本间隔31秒重置触发');
        $clock += 29;
        $send(['temperature' => -4]);
        $send(['temperature' => -5]);
        $run();
        expect($list(['status' => 'active'])['total'] === 1, '相邻有效样本间隔30秒仍连续');
        $send(['temperature' => 5]);
        $send(['temperature' => 5]);
        $clock += 30;
        $send(['temperature' => 5]);
        $run();
        expect($version()['items'][0]['recovery_count'] === 1, '恢复同样在31秒中断后重新计数');
        $send(['temperature' => 5]);
        $send(['temperature' => 5]);
        $run();
        $send(['temperature' => 20], ['sampled_at' => $clock - 30]);
        $send(['temperature' => 20], ['sampled_at' => $clock + 7]);
        $run();
        expect($count() === 0, '首次采样窗之外的旧补传和未来值不进入告警');
        $sameSecond = $clock + 10;
        $same = $send(['temperature' => 20], ['received_at' => $sameSecond, 'sampled_at' => $sameSecond]);
        $sameSequence = (string) $sequence;
        $send(['temperature' => 20], ['received_at' => $sameSecond, 'sampled_at' => $sameSecond]);
        $send(['temperature' => 20], ['received_at' => $sameSecond, 'sampled_at' => $sameSecond]);
        $duplicate = $send(['temperature' => 20], ['sequence' => $sameSequence, 'received_at' => $sameSecond, 'sampled_at' => $sameSecond]);
        expect($duplicate['receipt'] === $same['receipt'], '同内容重发复用首次接收事实');
        $batch = $run(1);
        expect($batch['completed'] === 1 && $batch['has_more'] && $count() === 1, '同秒消息首批按序号处理一个样本');
        $run();
        expect($list(['status' => 'active'])['total'] === 1 && $run()['completed'] === 0, '同秒三样本不因消息摘要顺序漏计且重复执行幂等');
        expect(app\iot\service\IngestionService::pending($connection, 'aggregate', 1) !== [], '告警完成不占用聚合完成事实');
        $changed = $request('PATCH', $rulePath, $admin, $tenantA, ['version' => 1, 'name' => '第二版本', 'upper' => 100], 200)['data'];
        expect($changed['version'] === 2 && $list(['status' => 'active'])['total'] === 0 && $version()['total'] === 2, '发布新版本结束旧活动并保留版本历史');
        $previous = $request('GET', $alarms . '/' . $active['id'], $viewer, $tenantA, null, 200)['data'];
        expect($previous['definition']['name'] === '温度闭区间' && $previous['end_reason'] === 'value_recovered', '规则更新不得改写既有恢复原因和条件');
        expect(count(array_filter($list()['items'], static fn (array $row): bool => $row['end_reason'] === 'rule_changed')) === 1, '规则变更有独立结束原因');
        $request('PATCH', $rulePath, $admin, $tenantA, ['version' => 1, 'name' => '过期编辑', 'upper' => 1], 409, 'stale_version');
        $clock += 10;
        $send(['temperature' => 101]);
        $send(['temperature' => 102]);
        $send(['temperature' => 103]);
        $request('PATCH', $rulePath, $admin, $tenantA, ['version' => 2, 'name' => '暂停规则', 'upper' => 100, 'enabled' => false], 200);
        expect($list()['total'] === 3, '修改规则不在HTTP内同步消费旧积压');
        $run();
        $oldVersion = array_values(array_filter($list()['items'], static fn (array $row): bool => $row['rule_version'] === 2));
        expect(count($oldVersion) === 1 && $oldVersion[0]['end_reason'] === 'rule_disabled' && $oldVersion[0]['definition']['upper'] === 100, '积压按旧规则触发后以停用原因结束');
        $send(['temperature' => 1000]);
        $send(['temperature' => 1000]);
        $send(['temperature' => 1000]);
        $run();
        expect($list()['total'] === 4 && $list(['status' => 'active'])['total'] === 0, '停用版本不计数');
        $request('PATCH', $rulePath, $admin, $tenantA, ['version' => 3, 'name' => '恢复启用', 'lower' => -1, 'upper' => 1, 'hysteresis' => 1], 200);
        $send(['temperature' => 10], ['sequence' => '10000000000000000000000000000000000000']);
        $send(['temperature' => 10], ['sequence' => '10000000000000000000000000000000000001']);
        $third = $send(['temperature' => 10], ['sequence' => '10000000000000000000000000000000000002']);
        $database->beginTransaction();
        $lock = $database->prepare('UPDATE iot_devices SET name = name WHERE id = ?');
        $lock->execute([$device['id']]);
        $workerA = new Type\Testing\Process([...$command, 'iot:alarm'], $root, $environment);
        $workerB = new Type\Testing\Process([...$command, 'iot:alarm'], $root, $environment);
        try {
            usleep(250000);
            expect($workerA->running() && $workerB->running(), '两个真实告警进程须等待设备写锁');
            $workerA->stop(0);
            $database->rollBack();
            expect($workerB->wait(10)->successful(), '杀死争用进程后独立消费者可继续');
        } finally {
            $workerA->stop();
            $workerB->stop();
            if ($database->inTransaction()) {
                $database->rollBack();
            }
        }
        $huge = $list(['status' => 'active'])['items'][0];
        expect($huge['trigger']['sequence'] === '10000000000000000000000000000000000002' && $huge['trigger']['message_id'] === $third['message_id'] && $run()['completed'] === 0, '38位序号和中断重试不丢第三样本');
        $send(['temperature' => 0]);
        $run();
        expect($list(['status' => 'active'])['total'] === 1 && $version()['items'][0]['recovery_count'] === 0, '旧序号实时补传不推进告警恢复');
        foreach (['03', '04', '05'] as $suffix) {
            $send(['temperature' => 0], ['sequence' => '100000000000000000000000000000000000' . $suffix]);
        }
        $run();
        expect($list(['status' => 'active'])['total'] === 0, '闭区间退化为零点仍可三次恢复');
        foreach ([$tokens['outsider']] as $outsider) {
            $request('GET', $rules, $outsider, $tenantA, null, 403);
            $request('GET', $alarms, $outsider, $tenantA, null, 403);
        }
        $request('GET', $alarms, '', $tenantA, null, 401);
        $request('GET', $alarms, $tokens['platform'], $tenantA, null, 401);
        $request('GET', '/customer/tenants/' . $tenantB . '/alarms/' . $active['id'], $admin, $tenantB, null, 404);
        $request('GET', '/customer/tenants/' . $tenantB . '/alarm-rules/' . $rule['id'] . '/versions', $admin, $tenantB, null, 404);
        foreach (['unknown=1', 'status=confirmed', 'from=2&to=1', 'per_page=101', 'device_id=wrong'] as $invalid) {
            $request('GET', $alarms . '?' . $invalid, $viewer, $tenantA, null, 422);
        }
        $request('GET', $rules . '?page=1&page=2', $viewer, $tenantA, null, 400);
        $nameFiltered = $request('GET', $rules . '?name=' . rawurlencode('温度'), $viewer, $tenantA, null, 200);
        expect($nameFiltered['total'] === 0, '规则名称筛选不可误匹配冻结属性名称');
        expect($list(['per_page' => 1, 'page' => 1])['items'][0]['id'] !== $list(['per_page' => 1, 'page' => 2])['items'][0]['id'], '规则及告警采用有界稳定分页');
        // 新规则不追认发布前事实；首次实时区间的两个端点均包含在内。
        foreach (['06', '07', '08'] as $suffix) {
            $send(['counter' => 10], ['sequence' => '100000000000000000000000000000000000' . $suffix]);
        }
        $counterRule = $request('POST', $rules, $admin, $tenantA, ['name' => '整数边界规则', 'device_id' => $device['id'], 'field' => 'counter', 'upper' => 5], 201)['data'];
        $run();
        expect($list(['rule_id' => $counterRule['id']])['total'] === 0 && $counterRule['definition']['hysteresis'] === 0, '新规则从序号边界后生效，默认回差为零');
        foreach (['09', '10', '11'] as $suffix) {
            $send(['counter' => 10], ['sequence' => '100000000000000000000000000000000000' . $suffix, 'sampled_at' => $clock + 1 - 30]);
        }
        $run();
        expect($list(['rule_id' => $counterRule['id'], 'status' => 'active'])['total'] === 1, '首次接收前30秒端点仍是有效实时数据');
        foreach (['12', '13', '14'] as $suffix) {
            $send(['counter' => 5], ['sequence' => '100000000000000000000000000000000000' . $suffix, 'sampled_at' => $clock + 1 + 5]);
        }
        $run();
        expect($list(['rule_id' => $counterRule['id'], 'status' => 'active'])['total'] === 0, '首次接收后5秒端点及零回差阈值等号允许恢复');
        // 发布请求先等待设备锁；释放前提交真实接收、连续状态和活动告警，验证不是等待前的MySQL旧快照。
        $publisher = null;
        try {
            $connection->transaction(static function (Type\Orm\Connection $transaction) use ($send, &$clock, &$publisher, $counterRule, $path, $admin, $tenantA, $root, $environment): void {
                $clock += 10;
                foreach (['15', '16', '17'] as $suffix) {
                    $send(['counter' => 10], ['sequence' => '100000000000000000000000000000000000' . $suffix]);
                }
                if ($transaction->driverName() !== 'sqlite') {
                    app\iot\service\AlarmService::run($transaction);
                }
                $clientCode = 'require $argv[1]."/vendor/autoload.php"; $client=new Type\\Testing\\HttpClient(getenv("TEST_ALARM_ORIGIN")); $response=$client->request("PATCH",getenv("TEST_ALARM_PATH"),["Content-Type"=>"application/json","Authorization"=>"Bearer ".getenv("TEST_ALARM_TOKEN"),"X-Tenant-Id"=>getenv("TEST_ALARM_TENANT")],json_encode(["version"=>1,"name"=>"锁后发布规则","upper"=>100])); if($response->status!==200)throw new RuntimeException($response->body); echo $response->body;';
                $publisher = new Type\Testing\Process([PHP_BINARY, '-r', $clientCode, $root], $root, array_replace($environment, [
                    'TEST_ALARM_ORIGIN' => 'http://127.0.0.1:' . $environment['APP_PORT'], 'TEST_ALARM_PATH' => $path . '/alarm-rules/' . $counterRule['id'],
                    'TEST_ALARM_TOKEN' => $admin, 'TEST_ALARM_TENANT' => $tenantA]));
                usleep(350000);
                expect($publisher->running(), '并发规则发布必须在设备锁处等待');
            }, $driver === 'sqlite' ? 'immediate' : 'default');
            $published = $publisher->wait(10);
            expect($published->successful(), '释放设备锁后发布应成功：' . $published->stderr);
            $publishedRule = json_decode($published->stdout, true, 32, JSON_THROW_ON_ERROR)['data'];
            expect($publishedRule['starts_after'] === '10000000000000000000000000000000000017', '版本边界须包含等待设备锁期间提交的最新序号');
            $run();
            $afterLock = $list(['rule_id' => $counterRule['id']]);
            expect($afterLock['total'] === 2 && count(array_filter($afterLock['items'], static fn (array $row): bool => $row['end_reason'] === 'rule_changed')) === 1
                && $list(['status' => 'active'])['total'] === 0, '新规则须结束等待设备锁期间创建的旧活动，不能留下孤立活动告警');
        } finally {
            $publisher?->stop();
        }
        // 模拟来源在等待授权锁时退出，不允许重复确认。
        $revoked = identityCompete($database, $driver, '127.0.0.1:' . $environment['APP_PORT'], [
            ['POST', $alarms . '/' . $active['id'] . '/acknowledge', $fixture['simulated']['accessToken'], [], ['X-Tenant-Id' => $tenantA]],
        ], static function () use ($database, $fixture): void {
            $database->prepare('DELETE FROM admin_sessions WHERE id = ?')->execute([$fixture['source']['identity']['session_id']]);
        });
        expect($revoked === [401], '等待期间模拟来源退出必须拒绝重复确认');
        $auditRows = $connection->table('customer_audit')->where('action', '=', 'alarm.acknowledged')->where('result', '=', 'success')->where('subject_id', '=', $active['id'])->get();
        expect(count($auditRows) === 1 && $auditRows[0]['actor_id'] === $fixture['source']['identity']['actor_id'], '首次确认只保留真实管理人员的一条成功审计');
        $origin = json_decode($auditRows[0]['details'], true, 16, JSON_THROW_ON_ERROR);
        expect($origin['customer_id'] === $fixture['simulated']['identity']['customer_id']
            && $origin['source_session_id'] === $fixture['source']['identity']['session_id']
            && $origin['impersonation_id'] === $fixture['simulated']['identity']['impersonation_id'], '模拟确认来源必须精确持久保存');
        $request('POST', $rules, $admin, $tenantA, $ruleData + ['tenant_id' => $tenantB], 422, 'unexpected_field');
        $request('POST', $alarms . '/' . $active['id'] . '/acknowledge', $admin, $tenantA, ['ids' => [$active['id']]], 422, 'unexpected_field');
        $noticeChecks = iotNoticeChecks($request, $tokens, $tenantA, $tenantB, $database, $connection, $command, $environment, $base, $send, $run, $list);
        $roles = $request('GET', '/customer/roles?search=device-operator-alarms', $admin, $tenantA, null, 200)['data']['items'];
        $writeRole = $roles[0];
        $request('PUT', '/customer/roles/' . $writeRole['id'] . '/permissions', $admin, $tenantA, ['version' => $writeRole['version'], 'permissions' => ['customer.alarms.manage']], 200);
        $writeRule = $request('POST', $rules, $tokens['carol'], $tenantA, array_replace($ruleData, ['name' => '仅发布权限规则']), 201)['data'];
        $request('GET', $rules, $tokens['carol'], $tenantA, null, 403);
        // 告警确认、规则新版本和通知意图必须随审计失败一并回滚。
        $unconfirmed = array_values(array_filter($list()['items'], static fn (array $row): bool => $row['acknowledged_at'] === null))[0];
        $outboxBefore = (int) $connection->table('iot_notice_outbox')->aggregate('COUNT');
        if ($driver === 'pgsql') {
            $database->exec("CREATE FUNCTION alarm_audit_failure() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''controlled alarm audit failure''; END'");
            $database->exec('CREATE TRIGGER alarm_audit_failure BEFORE INSERT ON customer_audit FOR EACH ROW EXECUTE FUNCTION alarm_audit_failure()');
        } elseif ($driver === 'mysql') {
            $database->exec("CREATE TRIGGER alarm_audit_failure BEFORE INSERT ON customer_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'controlled alarm audit failure'");
        } else {
            $database->exec("CREATE TRIGGER alarm_audit_failure BEFORE INSERT ON customer_audit BEGIN SELECT RAISE(ABORT, 'controlled alarm audit failure'); END");
        }
        try {
            $request('POST', $alarms . '/' . $unconfirmed['id'] . '/acknowledge', $admin, $tenantA, [], 500);
            $request('PATCH', $rules . '/' . $writeRule['id'], $tokens['carol'], $tenantA, ['version' => 1, 'name' => '不得保存的版本', 'upper' => 20], 500);
        } finally {
            $database->exec('DROP TRIGGER alarm_audit_failure' . ($driver === 'pgsql' ? ' ON customer_audit' : ''));
            if ($driver === 'pgsql') {
                $database->exec('DROP FUNCTION alarm_audit_failure()');
            }
        }
        expect($request('GET', $alarms . '/' . $unconfirmed['id'], $viewer, $tenantA, null, 200)['data']['acknowledged_at'] === null
            && $request('GET', $rules . '/' . $writeRule['id'] . '/versions', $viewer, $tenantA, null, 200)['total'] === 1
            && (int) $connection->table('iot_notice_outbox')->aggregate('COUNT') === $outboxBefore, '审计失败必须回滚确认、规则版本和通知意图');
        expect($connection->table('iot_tenants')->where('id', '=', $tenantA)->first()['version'] === $tenantVersion, '告警和确认不能推进租户资料版本');
        return ['path' => $alarms, 'items' => $list()['items'], 'checks' => ['strict_thresholds', 'closed_hysteresis', 'three_samples', 'gap_reset', 'missing_not_recovery',
            'first_receipt_window', 'same_second_sequence', 'decimal38', 'independent_consumer', 'version_boundary_backlog', 'disabled_version', 'worker_kill_retry', 'version_device_lock_snapshot',
            'authorization', 'pagination', 'independent_write_and_read_nodes', 'impersonation_origin_and_revocation', 'audit_failure_atomicity', 'unknown_batch_rejected', ...$noticeChecks]];
    } finally {
        $scope->close();
        $pool->close();
    }
}

/** 同一已编译HTTP/后台入口验收确认竞态、真实Redis重试和保留；PHP只编排隔离资源及观察持久结果。 */
function iotNoticeChecks(Closure $request, array $tokens, string $tenantA, string $tenantB, PDO $database, Type\Orm\Connection $connection, array $command, array $environment, string $base, Closure $send, Closure $run, Closure $list): array
{
    require_once __DIR__ . '/native-rollout-redis.php';
    $root = dirname(__DIR__);
    $path = '/customer/tenants/' . $tenantA;
    $admin = $tokens['bob'];
    $assignment = iotAlarmRole($request, $admin, $tenantA, 'device-operator', ['customer.alarms.acknowledge']);
    $operator = $assignment['member'];
    $request('GET', $path . '/alarms', $tokens['carol'], $tenantA, null, 403);
    foreach (['18', '19', '20'] as $suffix) {
        $send(['counter' => 1000], ['sequence' => '100000000000000000000000000000000000' . $suffix]);
    }
    $run();
    $active = $list(['status' => 'active'])['items'][0];
    foreach (['21', '22', '23'] as $suffix) {
        $send(['counter' => 0], ['sequence' => '100000000000000000000000000000000000' . $suffix]);
    }
    $database->beginTransaction();
    $lock = $database->prepare('UPDATE iot_alarms SET status = status WHERE id = ?');
    $lock->execute([$active['id']]);
    $clientCode = 'require $argv[1]."/vendor/autoload.php"; $client=new Type\\Testing\\HttpClient(getenv("TEST_NOTICE_ORIGIN")); $response=$client->request("POST",getenv("TEST_NOTICE_PATH"),["Content-Type"=>"application/json","Authorization"=>"Bearer ".getenv("TEST_NOTICE_TOKEN"),"X-Tenant-Id"=>getenv("TEST_NOTICE_TENANT")],"{}"); echo $response->body; if($response->status!==200)exit(1);';
    $actor = new Type\Testing\Process([PHP_BINARY, '-r', $clientCode, $root], $root, array_replace($environment, ['TEST_NOTICE_ORIGIN' => 'http://127.0.0.1:' . $environment['APP_PORT'],
        'TEST_NOTICE_PATH' => $path . '/alarms/' . $active['id'] . '/acknowledge', 'TEST_NOTICE_TOKEN' => $tokens['carol'], 'TEST_NOTICE_TENANT' => $tenantA]));
    $recovery = new Type\Testing\Process([...$command, 'iot:alarm'], $root, $environment);
    try {
        usleep(250000);
        expect($actor->running() && $recovery->running(), '真实确认与恢复进程同时等待告警写锁');
        $database->commit();
        expect($actor->wait(10)->successful() && $recovery->wait(10)->successful(), '并发确认与恢复必须都完成');
    } finally {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        $actor->stop();
        $recovery->stop();
    }
    $after = $request('GET', $path . '/alarms/' . $active['id'], $tokens['alice'], $tenantA, null, 200)['data'];
    expect($after['status'] === 'ended' && $after['end_reason'] === 'value_recovered' && $after['acknowledged_by'] === $operator['user_id'], '恢复与操作员确认分别留下完整事实');
    $request('POST', '/customer/tenants/' . $tenantB . '/alarms/' . $active['id'] . '/acknowledge', $admin, $tenantB, [], 404);
    $request('PUT', '/customer/roles/' . $assignment['role']['id'] . '/permissions', $admin, $tenantA, ['version' => 2, 'permissions' => []], 200);
    $request('POST', $path . '/alarms/' . $active['id'] . '/acknowledge', $tokens['carol'], $tenantA, [], 403);
    $audit = $connection->table('customer_audit')->where('tenant_id', '=', $tenantA)->where('action', '=', 'alarm.acknowledged')->where('result', '=', 'success')->aggregate('COUNT');
    expect((int) $audit === 2, '重复确认不得重复成功审计，两个真实首次确认分别保留');

    $redisServer = getenv('TYPE_REDIS_SERVER');
    expect(is_string($redisServer) && is_file($redisServer), '通知验收需要TYPE_REDIS_SERVER指向同平台原生Redis');
    $redis = new NativeRolloutRedis($base . '/notice-redis', $redisServer);
    $redisEnvironment = $redis->environment();
    $noticeEnvironment = array_replace($environment, ['IOT_NOTICES_REDIS_HOST' => $redisEnvironment['TYPE_REDIS_HOST'], 'IOT_NOTICES_REDIS_PORT' => $redisEnvironment['TYPE_REDIS_PORT'], 'IOT_NOTICES_NAMESPACE' => 'notice-' . basename($base)]);
    $work = static fn (int $batch = 100): array => identityCommand([...$command, 'iot:notices', (string) $batch], $noticeEnvironment)['data'];
    $clean = static fn (int $batch = 100): array => identityCommand([...$command, 'iot:notices-clean', (string) $batch], $noticeEnvironment)['data'];
    $noticeList = static fn (string $query = ''): array => $request('GET', $path . '/notifications' . $query, $tokens['alice'], $tenantA, null, 200);
    $redisManager = null;
    $redisScope = new Type\Runtime\ExecutionScope();
    try {
        expect($noticeList()['total'] === 0, '队列未投递前不伪造通知读模型');
        $failed = new Type\Testing\Process([...$command, 'iot:notices'], $root, array_replace($noticeEnvironment, ['IOT_NOTICES_REDIS_PORT' => '1']));
        try {
            expect(!$failed->wait(10)->successful() && $noticeList()['total'] === 0, '真实Redis不可达时意图保留且不伪造成功');
        } finally {
            $failed->stop();
        }
        $expected = (int) $connection->table('iot_notice_outbox')->aggregate('COUNT');
        $driver = $connection->driverName();
        if ($driver === 'pgsql') {
            $database->exec("CREATE FUNCTION notice_test_failure() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''notice_test_failure''; END'");
            $database->exec('CREATE TRIGGER notice_test_failure BEFORE INSERT ON iot_notifications FOR EACH ROW EXECUTE FUNCTION notice_test_failure()');
        } elseif ($driver === 'mysql') {
            $database->exec("CREATE TRIGGER notice_test_failure BEFORE INSERT ON iot_notifications FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'notice_test_failure'");
        } else {
            $database->exec("CREATE TRIGGER notice_test_failure BEFORE INSERT ON iot_notifications BEGIN SELECT RAISE(ABORT, 'notice_test_failure'); END");
        }
        try {
            $retry = $work(1);
            expect($retry['worker']['failed'] === 1 && $retry['worker']['retried'] === 1 && $noticeList()['total'] === 0, '真实数据库投影失败须回滚并由Queue重试');
        } finally {
            $database->exec('DROP TRIGGER notice_test_failure' . ($driver === 'pgsql' ? ' ON iot_notifications' : ''));
            if ($driver === 'pgsql') {
                $database->exec('DROP FUNCTION notice_test_failure()');
            }
        }
        usleep(1200000);
        $work();
        expect($noticeList()['total'] === $expected && $work()['processed'] === 0, '失败恢复后每个触发/结束意图恰有一个通知且重复工作为空');
        expect($noticeList('?kind=triggered')['total'] * 2 === $expected && $noticeList('?kind=ended')['total'] * 2 === $expected, '真实触发和恢复/规则结束分别通知');
        $request('GET', $path . '/notifications', $tokens['outsider'], $tenantA, null, 403);
        $request('GET', $path . '/notifications', $tokens['platform'], $tenantA, null, 401);
        $request('GET', $path . '/notifications', $tokens['carol'], $tenantA, null, 403);
        $request('PUT', '/customer/roles/' . $assignment['role']['id'] . '/permissions', $admin, $tenantA, ['version' => 3, 'permissions' => ['customer.notifications.read']], 200);
        expect($request('GET', $path . '/notifications', $tokens['carol'], $tenantA, null, 200)['total'] === $expected, '独立通知权限不隐式要求告警查询权限');
        $request('GET', $path . '/alarms', $tokens['carol'], $tenantA, null, 403);
        $request('GET', $path . '/notifications?kind=bad', $admin, $tenantA, null, 422);
        $request('GET', $path . '/notifications?per_page=101', $admin, $tenantA, null, 422);
        expect($request('GET', '/customer/tenants/' . $tenantB . '/notifications', $admin, $tenantB, null, 200)['total'] === 0, '通知不泄露跨租户历史');
        expect($noticeList('?per_page=1&page=1')['items'][0]['id'] !== $noticeList('?per_page=1&page=2')['items'][0]['id'], '通知分页排序稳定');
        $redisManager = new Type\Redis\RedisManager(['notices' => new Type\Redis\RedisConfiguration('127.0.0.1', (int) $redisEnvironment['TYPE_REDIS_PORT'])]);
        $noticeRedis = $redisManager->connection($redisScope, 'notices', Type\Redis\Purpose::SCRIPT);
        $queue = new Type\Queue\Queue($noticeRedis, $noticeEnvironment['IOT_NOTICES_NAMESPACE'], 'notices', 60000, 10000);
        $record = $connection->table('iot_notice_outbox')->orderBy('id')->first();
        $payload = json_decode($record['payload'], true, 16, JSON_THROW_ON_ERROR);
        $queue->publish(new Type\Queue\Message($record['id'], 'iot.notice', 1, $payload, ['tenant_id' => $tenantA]));
        $work();
        expect($noticeList()['total'] === $expected, '旧队列交付重复不会复制已消费通知');
        $shortQueue = new Type\Queue\Queue($noticeRedis, $noticeEnvironment['IOT_NOTICES_NAMESPACE'], 'notices', 60000, 10000, 1);
        $shortQueue->publish(new Type\Queue\Message($record['id'], 'iot.notice', 1, $payload, ['tenant_id' => $tenantA]));
        $quarantined = $shortQueue->reserve('notice-retention-test');
        expect($quarantined !== null, '建立真实有期隔离消息');
        $quarantined->quarantine('bounded-retention-check');
        usleep(1200000);
        expect($work()['quarantine_collected'] === 1 && $noticeList()['total'] === $expected, '工作角色有界回收已到期隔离消息并释放队列容量');
        // 删除唯一测试投影和消费凭据，模拟事务未提交；发布事实存在而队列内容已丢失。
        $connection->table('iot_notifications')->where('id', '=', $record['id'])->delete();
        $connection->table('iot_notice_outbox')->where('id', '=', $record['id'])->update(['consumed_receipt' => null, 'consumed_at' => null, 'published_at' => (time() - 61) * 1000]);
        expect($work()['replayed'] === 1 && $noticeList()['total'] === $expected, '已发布但无效果凭据的意图从数据库恢复');
        $redis->crashAndRestartReliable();
        expect($work()['processed'] === 0 && $noticeList()['total'] === $expected, '真实Redis重启不重复通知效果');
        $redisScope->close();
        $redisManager->close();
        $redisScope = new Type\Runtime\ExecutionScope();
        $redisManager = new Type\Redis\RedisManager(['notices' => new Type\Redis\RedisConfiguration('127.0.0.1', (int) $redisEnvironment['TYPE_REDIS_PORT'])]);
        $queue = new Type\Queue\Queue($redisManager->connection($redisScope, 'notices', Type\Redis\Purpose::SCRIPT), $noticeEnvironment['IOT_NOTICES_NAMESPACE'], 'notices', 60000, 10000);

        $before = time() - 15552000;
        $endedRows = $connection->table('iot_alarms')->where('status', '=', 'ended')->orderBy('id')->get();
        foreach (array_slice($endedRows, 0, 3) as $old) {
            $connection->table('iot_alarms')->where('id', '=', $old['id'])->update(['ended_at' => $before - 10]);
        }
        $oldActive = $endedRows[3];
        $connection->table('iot_alarms')->where('id', '=', $oldActive['id'])->update(['status' => 'active', 'ended_at' => null, 'created_at' => $before - 86400]);
        $connection->table('iot_alarms')->where('id', '=', $endedRows[4]['id'])->update(['created_at' => $before - 86400, 'ended_at' => time()]);
        $expiredNotices = $connection->table('iot_notifications')->orderBy('id')->limit(3)->get();
        foreach ($expiredNotices as $expired) {
            $connection->table('iot_notifications')->where('id', '=', $expired['id'])->update(['created_at' => $before - 10]);
        }
        $database->beginTransaction();
        $database->exec('UPDATE iot_alarms SET status = status');
        $interrupted = new Type\Testing\Process([...$command, 'iot:notices-clean', '2'], $root, $noticeEnvironment);
        try {
            usleep(200000);
            expect($interrupted->running(), '清理等待真实写锁');
            $interrupted->stop(0);
        } finally {
            $database->rollBack();
            $interrupted->stop();
        }
        $firstClean = $clean(2);
        $nextClean = $clean(2);
        expect($firstClean['alarms_deleted'] === 2 && $firstClean['notices_deleted'] === 2 && $nextClean['alarms_deleted'] === 1 && $nextClean['notices_deleted'] === 1, '180天清理每类有界且中断后继续');
        expect($clean()['alarms_deleted'] === 0 && $clean()['notices_deleted'] === 0 && $connection->table('iot_alarms')->where('id', '=', $oldActive['id'])->first() !== null
            && $connection->table('iot_alarms')->where('id', '=', $endedRows[4]['id'])->first() !== null, '活动告警和刚结束旧告警不按发生时间删除');
        expect($noticeList()['total'] === $expected - 3, '通知与告警独立清理，不随关联告警级联删除');
        $queue->publish(new Type\Queue\Message($record['id'], 'iot.notice', 1, $payload, ['tenant_id' => $tenantA]));
        $work();
        expect($noticeList()['total'] === $expected - 3, '已清理通知不会由旧重复交付复活');
        $connection->table('iot_alarms')->where('id', '=', $oldActive['id'])->update(['status' => 'ended', 'ended_at' => time()]);
        return ['acknowledge_independent_idempotent', 'operator_recovery_race', 'acknowledge_audit_revocation', 'notice_outbox_queue_retry', 'notice_replay_deduplication',
            'notice_redis_restart', 'notice_authorization_pagination', 'independent_180day_retention', 'bounded_cleanup_kill_resume'];
    } finally {
        $redisScope->close();
        $redisManager?->close();
        $redis->close();
    }
}

/** 使用真实角色与成员分配入口，角色名称不参与授权。 */
function iotAlarmRole(Closure $request, string $admin, string $tenant, string $login, array $permissions): array
{
    $role = $request('POST', '/customer/roles', $admin, $tenant, ['name' => $login . '-alarms', 'permissions' => $permissions], 200)['data'];
    $request('POST', '/customer/roles/' . $role['id'] . '/status', $admin, $tenant, ['version' => 1, 'enabled' => true], 200);
    $member = $request('GET', '/customer/members?search=' . $login, $admin, $tenant, null, 200)['data']['items'][0];
    $request('PUT', '/customer/members/roles', $admin, $tenant, ['members' => [['id' => $member['id'], 'version' => (int) $member['version']]],
        'roles' => [['id' => $role['id'], 'version' => 2]]], 200);
    return ['role' => $role, 'member' => $member];
}
