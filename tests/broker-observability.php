<?php

declare(strict_types=1);

use Type\Mqtt\Client;
use Type\Mqtt\Message;
use Type\Mqtt\ProtocolError;
use Type\Testing\HttpClient;
use Type\Testing\HttpResponse;
use Type\Testing\Process;

/** 从真实抓取响应核对Prometheus文本与有界slot标签，返回实际样本而非实现内部状态。 */
function brokerMetrics(HttpResponse $response): array
{
    expect($response->status === 200 && str_starts_with($response->header('content-type')[0] ?? '', 'text/plain; version=0.0.4')
        && $response->header('cache-control') === ['no-store'], 'Broker指标必须返回受控且不缓存的Prometheus文本');
    $samples = [];
    $types = [];
    foreach (explode("\n", trim($response->body)) as $line) {
        if (str_starts_with($line, '# TYPE ')) {
            expect(
                preg_match('/^# TYPE (typeapp_broker_[a-z0-9_]+) (?:gauge|counter)$/D', $line, $match) === 1,
                'Broker指标类型声明无效'
            );
            expect(!isset($types[$match[1]]), 'Broker指标类型不能重复声明');
            $types[$match[1]] = true;
            continue;
        }
        if (str_starts_with($line, '# HELP ')) {
            continue;
        }
        expect(
            preg_match('/^(typeapp_broker_[a-z0-9_]+)(\{slot="(?:[0-9]|[12][0-9]|3[01])"\})? ([0-9]+)$/D', $line, $match) === 1,
            'Broker指标包含非法数值或无界标签'
        );
        $series = $match[1] . ($match[2] ?? '');
        expect(isset($types[$match[1]]) && !isset($samples[$series]), 'Broker指标样本缺少类型或重复');
        $samples[$series] = (int) $match[3];
    }
    expect(
        count($samples) <= 4096 && isset($samples['typeapp_broker_ready'], $samples['typeapp_broker_store_available']),
        'Broker指标缺少可用性样本或超过固定节点指标预算'
    );
    return $samples;
}

/** 两个真实HTTP客户端同时查询同一宿主，验证节点查询不长期占住重复管理租约。 */
function brokerConcurrentReads(array $environment, string $token): array
{
    $clientCode = 'require getenv("BROKER_TEST_ROOT")."/vendor/autoload.php";'
        . '$client=new Type\\Testing\\HttpClient("http://127.0.0.1:".getenv("APP_PORT"),15.0);'
        . '$response=$client->request("GET","/broker/nodes",["Authorization"=>"Bearer ".getenv("BROKER_TEST_TOKEN")]);'
        . 'echo json_encode(["status"=>$response->status,"body"=>$response->json()],JSON_THROW_ON_ERROR);';
    $clients = [];
    $results = [];
    try {
        for ($index = 0; $index < 2; $index++) {
            $clients[] = new Process(
                [PHP_BINARY, '-n', '-d', 'zend.exception_ignore_args=1', '-r', $clientCode],
                dirname(__DIR__),
                $environment + ['BROKER_TEST_ROOT' => dirname(__DIR__), 'BROKER_TEST_TOKEN' => $token]
            );
        }
        foreach ($clients as $client) {
            $result = $client->wait(20);
            expect($result->successful(), '并发HTTP查询客户端未正常退出');
            $results[] = json_decode($result->stdout, true, 64, JSON_THROW_ON_ERROR);
        }
        return $results;
    } finally {
        foreach ($clients as $client) {
            $client->stop();
        }
    }
}

/**
 * 同一应用与节点上的真实持久队列、慢确认和同步依赖故障；调用方持有主备和服务生命周期。
 *
 * @param Closure(string,string,string,?array,int,array=): array $request 已登录宿主的真实HTTP入口。
 * @param array<string,string> $environment 当前节点受控测试环境。
 * @param Closure(string): void|null $browserStage 可选浏览器阶段；只协调测试装置，不改变生产响应。
 * @return array<string,mixed> 不含令牌、凭据和完整消息载荷的原始观察。
 */
function brokerObservability(
    PostgresSync $sync,
    HttpClient $http,
    Closure $request,
    string $probeToken,
    string $adminToken,
    array $environment,
    Process &$node,
    Process $server,
    int &$checks,
    string $base,
    array $command,
    ?Closure $browserStage = null
): array {
    $publisher = null;
    $consumer = null;
    $denied = null;
    $standbyStopped = false;
    $payload = 'broker-observe-private-' . bin2hex(random_bytes(16)) . str_repeat('x', 4000);
    $topic = $environment['BROKER_TOPIC_PREFIX'] . 'slow-consumer';
    $snapshots = [];
    $health = static function () use ($http, $probeToken, &$checks): array {
        $response = $http->request('GET', '/broker/health/ready', ['Authorization' => 'Bearer ' . $probeToken]);
        expect(in_array($response->status, [200, 503], true), '就绪探针未返回明确可用/不可用状态');
        $data = $response->json()['data'];
        expect(($response->status === 200) === $data['ready'], '就绪HTTP状态与正文相互矛盾');
        $checks++;
        return $data;
    };
    $metrics = static function () use ($http, $probeToken, &$checks): array {
        $checks++;
        return brokerMetrics($http->request('GET', '/broker/metrics', ['Authorization' => 'Bearer ' . $probeToken]));
    };
    $await = static function (Closure $condition, float $seconds = 12.0) use ($health): array {
        $deadline = microtime(true) + $seconds;
        do {
            $snapshot = $health();
            if ($condition($snapshot)) {
                return $snapshot;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Broker观察没有在预算内达到真实状态：' . json_encode($snapshot, JSON_THROW_ON_ERROR));
    };
    try {
        $snapshots['healthy'] = $await(static fn (array $data): bool => $data['ready']);
        expect(
            $snapshots['healthy']['store']['state'] === 'available' && $snapshots['healthy']['store']['metrics']['pendingMessages'] === 0,
            '持久节点必须取得当次同步证明且初始队列真实为空'
        );
        $snapshots['concurrent_reads'] = brokerConcurrentReads($environment, $adminToken);
        $checks += 2;
        foreach ($snapshots['concurrent_reads'] as $concurrent) {
            expect($concurrent['status'] === 200 && ($concurrent['body']['health']['ready'] ?? false), '两个并发节点查询不能耗尽四连接池');
        }
        $browserStage?->__invoke('healthy');
        $denied = new Client(
            '127.0.0.1',
            (int) $environment['BROKER_PORT'],
            'broker-observe-denied',
            $environment['BROKER_CLIENT_USERNAME'],
            'deliberately-wrong-password',
            sessionExpiry: 0,
            allowPlaintext: true
        );
        try {
            $denied->connect(true);
            throw new RuntimeException('错误MQTT凭据被允许');
        } catch (ProtocolError $failure) {
            expect(in_array($failure->reason, [0x86, 0x87], true), 'MQTT认证拒绝未保留标准原因码');
        }
        $denied->stop();
        $consumer = new Client(
            '127.0.0.1',
            (int) $environment['BROKER_PORT'],
            'broker-observe-slow',
            $environment['BROKER_CLIENT_USERNAME'],
            $environment['BROKER_CLIENT_PASSWORD'],
            keepAlive: 120,
            sessionExpiry: 600,
            receiveMaximum: 2,
            allowPlaintext: true
        );
        $publisher = new Client(
            '127.0.0.1',
            (int) $environment['BROKER_PORT'],
            'broker-observe-publisher',
            $environment['BROKER_CLIENT_USERNAME'],
            $environment['BROKER_CLIENT_PASSWORD'],
            keepAlive: 120,
            sessionExpiry: 0,
            allowPlaintext: true
        );
        $consumer->connect(true);
        expect($consumer->subscribe($topic) === 1, '慢消费者必须获得实际QoS1订阅');
        $publisher->connect(true);
        $snapshots['publications'] = [];
        for ($index = 0; $index < 6; $index++) {
            $publicationStarted = microtime(true);
            $snapshots['publishing'] = $index;
            expect(
                $publisher->publish(new Message($topic, $payload . ':' . $index, qos: 1)) === 0,
                '同步依赖健康时发布未获得实际持久PUBACK'
            );
            $snapshots['publications'][] = ['index' => $index, 'seconds' => microtime(true) - $publicationStarted];
        }
        // 有意暂缓两个已交付消息的业务确认，Broker只能占用客户端声明的两个在途槽。
        $held = [];
        $snapshots['received'] = [];
        for ($index = 0; $index < 2; $index++) {
            $held[] = $consumer->receive(5.0);
            expect($held[$index] !== null && $held[$index]['receipt'] !== '', '慢消费样本缺少实际待确认消息');
            $snapshots['received'][] = ['expected' => $index, 'actual' => substr($held[$index]['message']->payload, strlen($payload)), 'duplicate' => $held[$index]['duplicate']];
            expect($held[$index]['message']->payload === $payload . ':' . $index, '慢消费初始消息顺序异常');
        }
        expect(
            $consumer->receive(0.2) === null && $consumer->statistics()['unacknowledged'] === 2,
            'Broker越过客户端Receive Maximum投递'
        );
        $snapshots['slow_consumer'] = $await(static fn (array $data): bool => $data['ready']
            && ($data['store']['metrics']['pendingMessages'] ?? 0) >= 6
            && ($data['nodes'][0]['metrics']['outgoingExchanges'] ?? 0) === 2
            && ($data['nodes'][0]['metrics']['authenticationRefusals'] ?? 0) >= 1);
        $slow = $snapshots['slow_consumer'];
        expect(
            $slow['store']['metrics']['pendingBytes'] >= strlen($payload) * 6
            && $slow['store']['metrics']['pendingMessages'] <= $slow['store']['metrics']['maximumPendingMessages']
            && $slow['store']['metrics']['pendingBytes'] <= $slow['store']['metrics']['maximumPendingBytes'],
            '慢消费积压或全局预算不对应实际持久队列'
        );
        $nodeMetrics = $slow['nodes'][0]['metrics'];
        expect(
            $nodeMetrics['connections'] <= $nodeMetrics['maximumConnections'] && $nodeMetrics['pendingCommits'] <= 32
            && $nodeMetrics['processMemoryBytes'] > 0 && $nodeMetrics['processPeakMemoryBytes'] >= $nodeMetrics['processMemoryBytes'],
            'Broker连接、worker或内存统计未遵守既有预算'
        );
        $snapshots['slow_metrics'] = $metrics();
        expect(
            $snapshots['slow_metrics']['typeapp_broker_store_pending_messages'] >= 6
            && $snapshots['slow_metrics']['typeapp_broker_outgoing_exchanges{slot="0"}'] === 2
            && $snapshots['slow_metrics']['typeapp_broker_observed_sum_outgoing_exchanges'] === 2,
            'Prometheus节点、总体和持久积压没有对应真实慢消费'
        );
        $browserStage?->__invoke('slow_consumer');
        foreach ($held as $message) {
            $consumer->acknowledge($message['receipt']);
        }
        for ($index = 2; $index < 6; $index++) {
            $receiveStarted = microtime(true);
            $message = $consumer->receive(5.0);
            $snapshots['received'][] = ['expected' => $index, 'actual' => $message === null ? null : substr($message['message']->payload, strlen($payload)),
                'duplicate' => $message['duplicate'] ?? null, 'wait_seconds' => microtime(true) - $receiveStarted];
            expect(
                $message !== null && $message['message']->payload === $payload . ':' . $index,
                '恢复确认后消息丢失或顺序异常：' . json_encode($snapshots['received'], JSON_THROW_ON_ERROR)
            );
            $consumer->acknowledge($message['receipt']);
        }
        $snapshots['drained'] = $await(static fn (array $data): bool => $data['ready']
            && ($data['store']['metrics']['pendingMessages'] ?? -1) === 0
            && ($data['store']['metrics']['pendingBytes'] ?? -1) === 0
            && ($data['nodes'][0]['metrics']['outgoingExchanges'] ?? -1) === 0);
        $consumer->close();
        $publisher->close();
        $sync->stopStandby();
        $standbyStopped = true;
        $started = microtime(true);
        $snapshots['standby_unavailable'] = $health();
        $elapsed = microtime(true) - $started;
        expect(
            !$snapshots['standby_unavailable']['ready'] && $snapshots['standby_unavailable']['store']['state'] !== 'available'
            && $snapshots['standby_unavailable']['store']['metrics'] === null && $elapsed < 12.0,
            '真实同步备库故障时仍报告可确认接收或返回旧存储样本'
        );
        $request('GET', '/broker/health/live', $probeToken, null, 200);
        $snapshots['fault_metrics'] = $metrics();
        expect($snapshots['fault_metrics']['typeapp_broker_ready'] === 0 && $snapshots['fault_metrics']['typeapp_broker_store_available'] === 0
            && !isset($snapshots['fault_metrics']['typeapp_broker_store_pending_messages']), '依赖故障指标伪造存储可用或沿用旧队列');
        $browserStage?->__invoke('standby_unavailable');
        $sync->restartStandby();
        $standbyStopped = false;
        // 失去同步证明会停止节点；先核实旧进程及工作进程退出，再使用公开维护命令恢复。
        $node->stop(15);
        expect(!$node->running(), '恢复前旧节点没有退出');
        $oldLogs = $node->stdout() . $node->stderr();
        $observation = $request('GET', '/broker/nodes', $adminToken, null, 200)['items'][0];
        $snapshots['isolation'] = brokerIsolateObservedNode($sync, $command, $environment, $observation);
        expect($request('GET', '/broker/nodes', $adminToken, null, 200)['items'][0]['state'] === 'isolated', '明确硬隔离没有独立观察状态');
        $browserStage?->__invoke('isolated');
        $node = new Process([...$command, 'broker:run'], dirname(__DIR__), $environment);
        $snapshots['recovered'] = $await(static fn (array $data): bool => $data['ready'], 20.0);
        expect($node->running() && $server->running(), '依赖恢复后新的运行宿主未保持存活');
        $newObservation = $request('GET', '/broker/nodes', $adminToken, null, 200)['items'][0];
        $registry = identityCommand([...$command, 'broker:nodes'], $environment);
        $newRegistered = array_values(array_filter($registry['nodes'], static fn (array $registered): bool => $registered['node_id'] === $observation['node_id']));
        expect(count($newRegistered) === 1 && $newRegistered[0]['state'] === 'active' && $newObservation['run_id'] !== $observation['run_id']
            && (int) $newRegistered[0]['generation'] === $snapshots['isolation']['generation'] + 1, '恢复后必须取得新节点运行及代次');
        $staleFence = identityCommand($snapshots['isolation']['command'], $environment);
        $reconciled = identityCommand([...$command, 'broker:node-fence-result', $snapshots['isolation']['operation_id']], $environment);
        foreach ([$staleFence, $reconciled] as $original) {
            expect($original['operation_id'] === $snapshots['isolation']['operation_id'] && $original['stage'] === 'completed'
                && $original['result'] === 'success' && $original['fenced'] && $original['observation_isolated'] && $original['expired'], '新运行启动后旧动作必须返回已过期事件的原成功事实');
        }
        $registryAfter = identityCommand([...$command, 'broker:nodes'], $environment);
        $registeredAfter = array_values(array_filter($registryAfter['nodes'], static fn (array $registered): bool => $registered['node_id'] === $observation['node_id']));
        $observedAfter = $request('GET', '/broker/nodes', $adminToken, null, 200)['items'][0];
        expect($registeredAfter === $newRegistered && $observedAfter['run_id'] === $newObservation['run_id'] && $observedAfter['state'] === 'reporting', '旧动作重试或对账误伤同名节点的新运行');
        $inspection = $sync->connection();
        try {
            $afterRestart = brokerFenceAuditState($inspection, $snapshots['isolation']['operation_id']);
            expect($afterRestart['events'] === [] && $afterRestart['operation'] === $snapshots['isolation']['operation_fact']
                && $afterRestart['store'] === $snapshots['isolation']['store_fact'], '新运行后的旧动作重试重建了过期日志或改写安全事实');
        } finally {
            $inspection = null;
        }
        $snapshots['isolation']['new_run_protected'] = true;
        $checks += 5;
        unset($snapshots['isolation']['command']);
        $browserStage?->__invoke('recovered');
        $encoded = json_encode($snapshots, JSON_THROW_ON_ERROR);
        $logs = $server->stdout() . $server->stderr() . $oldLogs . $node->stdout() . $node->stderr();
        foreach ([$probeToken, $adminToken, $environment['BROKER_CLIENT_PASSWORD'], $payload] as $secret) {
            expect(!str_contains($encoded, $secret) && !str_contains($logs, $secret), '观察数据或日志泄漏秘密及全量载荷');
        }
        $structured = false;
        foreach (explode("\n", $logs) as $line) {
            $record = json_decode($line, true);
            if (is_array($record) && ($record['level'] ?? '') === 'warning' && isset($record['context']['state'], $record['context']['store_state'])) {
                $structured = true;
            }
        }
        expect($structured, '真实不就绪请求没有结构化状态日志');
        $request('GET', '/broker/nodes', $adminToken, null, 200);
        return ['status' => 'passed', 'messages' => 6, 'receive_maximum' => 2, 'fault_response_seconds' => $elapsed,
            'snapshots' => $snapshots, 'structured_log' => true, 'no_secrets_or_full_payloads' => true];
    } catch (Throwable $failure) {
        $snapshots['failure'] = ['type' => get_class($failure), 'file' => basename($failure->getFile()), 'line' => $failure->getLine(),
            'reason' => $failure instanceof ProtocolError ? $failure->reason : null, 'observed_at' => time()];
        $failureDatabase = null;
        try {
            $failureDatabase = $sync->connection();
            $snapshots['failure_backends'] = $failureDatabase->query('SELECT pid, application_name, state, wait_event_type, wait_event, '
                . 'EXTRACT(EPOCH FROM clock_timestamp() - query_start) AS query_seconds, pg_blocking_pids(pid) AS blockers '
                . 'FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() ORDER BY pid')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $databaseFailure) {
            $snapshots['failure_backends_error'] = get_class($databaseFailure);
        } finally {
            $failureDatabase = null;
        }
        try {
            $snapshots['failure_health'] = $health();
        } catch (Throwable $observationFailure) {
            $snapshots['failure_observation'] = get_class($observationFailure);
        }
        throw $failure;
    } finally {
        $denied?->stop();
        $consumer?->stop();
        $publisher?->stop();
        if ($standbyStopped) {
            $sync->restartStandby();
        }
        file_put_contents($base . '/observability.json', json_encode($snapshots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
}

/** 对比数据库中的原事件与操作/隔离安全事实；时间变化属于可观察的幂等退化。 */
function brokerFenceAuditState(PDO $inspection, string $operationId, string $realm = 'broker'): array
{
    expect(in_array($realm, ['broker', 'iot'], true), '隔离审计装置需要明确宿主');
    $id = $inspection->quote($operationId);
    return ['events' => $inspection->query('SELECT * FROM ' . $realm . '_audit WHERE operation_id = ' . $id . ' ORDER BY created_at, id')->fetchAll(PDO::FETCH_ASSOC),
        'operation' => $inspection->query('SELECT * FROM ' . $realm . '_broker_operations WHERE operation_id = ' . $id)->fetch(PDO::FETCH_ASSOC),
        'store' => $inspection->query('SELECT * FROM type_mqtt_node_audit WHERE operation_id = ' . $id)->fetch(PDO::FETCH_ASSOC)];
}

/** 两个真实原命令均在首次受理INSERT等待后放行，验证唯一执行回执和可恢复的同ID结果。 */
function brokerFenceConcurrentCommands(PostgresSync $sync, array $arguments, array $environment): array
{
    $operationId = bin2hex(random_bytes(16));
    $arguments[count($arguments) - 1] = $operationId;
    $locker = $sync->connection();
    // 单独的自动提交连接保证每次活动查询都取得新快照，不在持锁事务内缓存后端状态。
    $inspection = $sync->connection();
    $processes = [];
    $responses = [];
    try {
        $locker->beginTransaction();
        $locker->exec('LOCK TABLE broker_broker_operations IN SHARE MODE');
        $lockerPid = (int) $locker->query('SELECT pg_backend_pid()')->fetchColumn();
        for ($index = 0; $index < 2; $index++) {
            $processes[] = new Process($arguments, dirname(__DIR__), $environment);
        }
        $deadline = microtime(true) + 12;
        $waiting = [];
        do {
            foreach ($processes as $process) {
                expect($process->running(), '同ID原命令在共同首次受理窗口前退出：' . $process->stderr());
            }
            $waiting = $inspection->query('SELECT DISTINCT a.pid FROM pg_locks l JOIN pg_stat_activity a ON a.pid = l.pid '
                . 'WHERE a.datname = current_database() AND a.usename = session_user AND ' . $lockerPid . ' = ANY(pg_blocking_pids(a.pid)) '
                . "AND l.relation = 'broker_broker_operations'::regclass AND NOT l.granted AND a.query ILIKE 'INSERT INTO%'")->fetchAll(PDO::FETCH_COLUMN);
            if (count($waiting) === 2) {
                break;
            }
            usleep(5000);
        } while (microtime(true) < $deadline);
        expect(
            count($waiting) === 2 && brokerFenceAuditState($inspection, $operationId)['operation'] === false,
            '没有证实两个命令同时通过原操作不存在检查并等待首次受理'
        );
        $locker->commit();
        foreach ($processes as $process) {
            $result = $process->wait(12);
            expect(!$result->timedOut, '同ID并发原命令超出有界等待');
            $response = json_decode($result->stdout, true, 32, JSON_THROW_ON_ERROR);
            expect(
                $response['operation_id'] === $operationId && in_array($response['stage'], ['completed', 'unknown'], true)
                && $response['result'] === ($response['stage'] === 'completed' ? 'success' : 'unknown')
                && $result->successful() === ($response['stage'] === 'completed')
                && $response['fenced'] === $result->successful() && $response['observation_isolated'] === $result->successful()
                && preg_match('/^[a-f0-9]{32}$/D', $response['event_id'] ?? '') === 1,
                '同ID并发首次受理冲突、丢失原事件回执或伪造确定结果'
            );
            $responses[] = $response;
        }
        // 原命令重试只能对账；无论并发查询先后，最终均复用同一完成回执。
        $completed = identityCommand($arguments, $environment);
        expect($completed['operation_id'] === $operationId && $completed['stage'] === 'completed' && $completed['result'] === 'success'
            && $completed['fenced'] && $completed['observation_isolated'], '并发受理后的原动作无法按同ID恢复完成');
        $state = brokerFenceAuditState($inspection, $operationId);
        $context = json_decode($state['operation']['context_json'], true, 16, JSON_THROW_ON_ERROR);
        $stages = array_count_values(array_column($state['events'], 'stage'));
        expect(
            $context['operation_id'] === $operationId && $context['origin_request_id'] === $operationId
            && $state['operation']['stage'] === 'completed' && $state['operation']['result'] === 'success'
            && ($stages['accepted'] ?? 0) === 1 && ($stages['executing'] ?? 0) === 1 && ($stages['completed'] ?? 0) === 1
            && ($stages['unknown'] ?? 0) <= 1 && count($state['events']) === (int) $state['operation']['version']
            && is_array($state['store']) && $state['store']['operation_id'] === $operationId,
            '并发原命令没有共享冻结身份、唯一阶段事件和持久隔离事实'
        );
        foreach ($responses as $response) {
            $matching = array_values(array_filter($state['events'], static fn (array $event): bool => $event['id'] === $response['event_id']));
            expect(count($matching) === 1 && $matching[0]['stage'] === $response['stage'], '并发命令返回的原回执不能追溯到实际阶段事件');
            if ($response['stage'] === 'completed') {
                expect($response === $completed, '并发成功请求未复用同一完成回执');
            }
        }
        expect(
            identityCommand($arguments, $environment) === $completed && brokerFenceAuditState($inspection, $operationId) === $state,
            '并发受理恢复后重试刷新了原事件或安全事实'
        );
        return ['operation_id' => $operationId, 'blocked_first_acceptances' => count($waiting), 'responses' => $responses,
            'origin_request_id' => $context['origin_request_id'], 'completed_event_id' => $completed['event_id'], 'single_store_fact' => true];
    } finally {
        try {
            if ($locker->inTransaction()) {
                $locker->rollBack();
            }
        } finally {
            foreach ($processes as $process) {
                $process->stop();
            }
        }
        $locker = null;
        $inspection = null;
    }
}

/** 已完成动作的真实查询遇到同步故障，只追加此次未知事件；原操作与完成回执保持终态。 */
function brokerFenceLateUnknown(PostgresSync $sync, array $command, array $arguments, array $environment): array
{
    $operationId = bin2hex(random_bytes(16));
    $arguments[count($arguments) - 1] = $operationId;
    $completed = identityCommand($arguments, $environment);
    $inspection = $sync->connection();
    $standby = $sync->standby();
    $process = null;
    $paused = false;
    try {
        $before = brokerFenceAuditState($inspection, $operationId);
        $stages = array_column($before['events'], 'stage');
        sort($stages);
        expect(
            $completed['operation_id'] === $operationId && $completed['stage'] === 'completed' && $completed['result'] === 'success'
            && $stages === ['accepted', 'completed', 'executing'] && (int) $before['operation']['version'] === 3,
            '迟到未知回归必须先由真实原命令完成且此前没有unknown阶段'
        );
        $standby->query('SELECT pg_wal_replay_pause()')->fetchColumn();
        $paused = true;
        $resultCommand = [...$command, 'broker:node-fence-result', $operationId];
        $process = new Process($resultCommand, dirname(__DIR__), $environment);
        $result = $process->wait(12);
        expect(!$result->successful() && !$result->timedOut, '完成后的查询同步故障未返回有界未知结果');
        $unknown = json_decode($result->stdout, true, 32, JSON_THROW_ON_ERROR);
        expect(
            $unknown['operation_id'] === $operationId && $unknown['stage'] === 'unknown' && $unknown['result'] === 'unknown'
            && !$unknown['fenced'] && !$unknown['observation_isolated'] && $unknown['event_id'] !== $completed['event_id'],
            '迟到结果查询丢失原操作或把查询未知冒充原完成事件'
        );
        $after = brokerFenceAuditState($inspection, $operationId);
        expect(
            $after['operation']['stage'] === 'completed' && $after['operation']['result'] === 'success'
            && (int) $after['operation']['version'] === 4 && count($after['events']) === 4
            && $after['operation']['context_json'] === $before['operation']['context_json']
            && $after['operation']['context_hash'] === $before['operation']['context_hash'] && $after['store'] === $before['store'],
            '迟到unknown降级已完成动作、改变冻结上下文或重执行隔离'
        );
        $late = array_values(array_filter($after['events'], static fn (array $event): bool => $event['id'] === $unknown['event_id']));
        expect(
            count($late) === 1 && $late[0]['stage'] === 'unknown' && $late[0]['result'] === 'unknown'
            && $late[0]['request_id'] !== $operationId
            && json_decode($late[0]['details'], true, 8, JSON_THROW_ON_ERROR)['facts'] === ['reason' => 'result_unconfirmed'],
            '迟到未知事件未关联独立查询请求或缺少准确未知原因'
        );
        foreach ($before['events'] as $event) {
            expect(in_array($event, $after['events'], true), '迟到查询修改了原受理、执行或完成事件');
        }
    } finally {
        try {
            if ($paused) {
                $standby->query('SELECT pg_wal_replay_resume()')->fetchColumn();
            }
        } finally {
            $process?->stop();
        }
        expect($standby->query('SELECT pg_is_wal_replay_paused()')->fetchColumn() === false, '迟到未知回归未恢复备库回放');
        $standby = null;
    }
    try {
        foreach ([$arguments, $resultCommand] as $retry) {
            expect(
                identityCommand($retry, $environment) === $completed && brokerFenceAuditState($inspection, $operationId) === $after,
                '迟到未知恢复后未返回原完成回执或刷新阶段事实'
            );
        }
        return ['operation_id' => $operationId, 'query_stage' => $unknown['stage'], 'operation_stage' => $after['operation']['stage'],
            'unknown_event_id' => $unknown['event_id'], 'completed_event_id' => $completed['event_id'], 'standby_replay_resumed' => true];
    } finally {
        $inspection = null;
    }
}

/** 原命令已受理并等待真实表锁后暂停回放，令隔离写入形成未知，再恢复主备供原ID对账。 */
function brokerFenceUnknownCommand(PostgresSync $sync, array $arguments, array $environment, string $operationId, string $realm = 'broker'): array
{
    expect(in_array($realm, ['broker', 'iot'], true), '隔离未知结果装置需要明确宿主');
    $locker = $sync->connection();
    $inspection = $sync->connection();
    $standby = $sync->standby();
    $process = null;
    $paused = false;
    try {
        $locker->beginTransaction();
        // 不阻挡node_statistics和原事实查询，仅让新的隔离事实INSERT等待。
        $locker->exec('LOCK TABLE type_mqtt_node_audit IN SHARE MODE');
        $process = new Process($arguments, dirname(__DIR__), $environment);
        $deadline = microtime(true) + 12;
        $origin = '';
        $waiting = false;
        do {
            expect($process->running(), '隔离原命令在形成真实故障窗口前退出：' . $process->stdout() . $process->stderr());
            $row = $inspection->query('SELECT context_json, stage FROM ' . $realm . '_broker_operations WHERE operation_id = ' . $inspection->quote($operationId))->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && $row['stage'] === 'executing') {
                $origin = json_decode($row['context_json'], true, 16, JSON_THROW_ON_ERROR)['origin_request_id'];
                // pg_stat_activity在事务内缓存快照，独立自动提交连接才能看到刚启动的worker。
                $waiting = (int) $inspection->query('SELECT COUNT(*) FROM pg_locks l JOIN pg_stat_activity a ON a.pid = l.pid '
                    . 'WHERE a.datname = current_database() AND a.usename = session_user AND a.application_name = ' . $inspection->quote('type_mqtt_' . $origin)
                    . " AND l.relation = 'type_mqtt_node_audit'::regclass AND NOT l.granted")->fetchColumn() === 1;
                if ($waiting) {
                    break;
                }
            }
            usleep(5000);
        } while (microtime(true) < $deadline);
        expect($waiting, '隔离原命令未在有界窗口内进入准确持久写入等待');
        $standby->query('SELECT pg_wal_replay_pause()')->fetchColumn();
        $paused = true;
        $locker->commit();
        $result = $process->wait(12);
        expect(!$result->successful() && !$result->timedOut, '同步故障必须返回非零未知结果，不能超出命令预算');
        $unknown = json_decode($result->stdout, true, 32, JSON_THROW_ON_ERROR);
        expect($unknown['operation_id'] === $operationId && $unknown['stage'] === 'unknown' && $unknown['result'] === 'unknown'
            && !$unknown['fenced'] && !$unknown['observation_isolated'], '同步证明缺失时原命令丢失操作ID或伪造确定结果');
        $fact = $locker->query('SELECT operation_id FROM type_mqtt_node_audit WHERE operation_id = ' . $locker->quote($operationId))->fetchColumn();
        expect($fact === $operationId, '没有形成实际本地提交而同步结果未知的隔离事实');
        return ['operation_id' => $operationId, 'origin_request_id' => $origin, 'stage' => $unknown['stage'],
            'result' => $unknown['result'], 'exit_code' => $result->exitCode, 'local_fact_not_treated_as_success' => true];
    } finally {
        try {
            if ($locker->inTransaction()) {
                $locker->rollBack();
            }
        } finally {
            try {
                if ($paused) {
                    $standby->query('SELECT pg_wal_replay_resume()')->fetchColumn();
                }
            } finally {
                $process?->stop();
            }
        }
        expect($standby->query('SELECT pg_is_wal_replay_paused()')->fetchColumn() === false, '隔离原命令故障回归未恢复备库回放');
        $locker = null;
        $inspection = null;
        $standby = null;
    }
}

/**
 * IoT公开原生命令及真实同步worker验证；节点仅由公开node_open/node_close登记，不构造动作或审计行。
 * 调用方已经迁移独立IoT数据库，持有主备生命周期；本函数保留成功/失败观察并回收自己的节点登记和后端。
 */
function iotFenceAuditCommands(PostgresSync $sync, array $command, array $environment, string $base): array
{
    require_once __DIR__ . '/mqtt-cluster.php';
    $root = dirname(__DIR__);
    $inspection = $sync->connection();
    $nodeId = 'iot-audit-fence';
    $runId = bin2hex(random_bytes(16));
    $activeRun = '';
    $report = ['status' => 'running', 'realm' => 'iot', 'node_preparation' => 'native-public-node-open-and-close-without-network-listener',
        'cleanup' => ['node_registration_closed' => false, 'workers_absent' => false, 'standby_replay_resumed' => false]];
    $worker = static function (array $request) use ($root, $command, $environment): array {
        $result = mqttClusterRequest($root, [...$command, 'iot:mqtt-store'], $environment, $request);
        expect($result['state'] === 'committed' && $result['released'] && $result['proof']['wal_lsn'] !== ''
            && $result['proof']['replicas'] !== [], 'IoT节点准备缺少同步提交与worker回收证明');
        return $result['value'];
    };
    try {
        $install = new Process([...$command, 'iot:mqtt-install'], $root, $environment);
        try {
            $installed = $install->wait(30);
            expect($installed->successful() && str_contains($installed->stdout, '同步提交证明'), 'IoT MQTT原生安装未取得真实同步证明');
        } finally {
            $install->stop();
        }
        $opened = $worker(['action' => 'node_open', 'node_id' => $nodeId, 'node_run_id' => $runId]);
        $activeRun = $runId;
        expect($opened['generation'] === 1, 'IoT新节点不是第一代');
        expect($worker(['action' => 'node_close', 'node_id' => $nodeId, 'node_run_id' => $runId]) === ['fenced' => true], 'IoT原生node_close缺少明确完成事实');
        $activeRun = '';
        $registered = identityCommand([...$command, 'iot:mqtt-nodes'], $environment);
        expect(count($registered['nodes']) === 1 && $registered['nodes'][0]['node_id'] === $nodeId
            && $registered['nodes'][0]['run_id'] === $runId && (int) $registered['nodes'][0]['generation'] === 1
            && $registered['nodes'][0]['state'] === 'fenced', 'IoT节点命令没有取得已回收旧运行');
        $operationId = bin2hex(random_bytes(16));
        $proof = 'owned-native-worker-reaped-' . bin2hex(random_bytes(12));
        $arguments = [...$command, 'iot:mqtt-fence', $nodeId, $runId, 'iot-audit-test', $proof, $operationId];
        $resultCommand = [...$command, 'iot:mqtt-fence-result', $operationId];
        $completed = identityCommand($arguments, $environment);
        expect($completed['operation_id'] === $operationId && $completed['stage'] === 'completed' && $completed['result'] === 'success'
            && $completed['fenced'] && !$completed['observation_isolated'] && !$completed['expired'], 'IoT正常隔离未完成或冒充独立宿主采样隔离');
        $original = brokerFenceAuditState($inspection, $operationId, 'iot');
        $report['completed_state'] = $original;
        $stages = array_column($original['events'], 'stage');
        sort($stages);
        expect($stages === ['accepted', 'completed', 'executing'] && (int) $original['operation']['version'] === 3, 'IoT正常原命令没有完整且唯一的三阶段事件');
        $context = json_decode($original['operation']['context_json'], true, 16, JSON_THROW_ON_ERROR);
        expect($context['operation_id'] === $operationId && $context['origin_request_id'] === $operationId
            && $context['actor_id'] === 'iot-audit-test' && $context['tenant_id'] === null && $context['subject_id'] === $runId
            && $context['authorization']['source'] === 'operator-command' && $context['authorization']['decision'] === 'allowed'
            && $context['authorization']['required_action'] === 'broker.node_fence' && $context['authorization']['support_id'] === null
            && $context['target'] === ['generation' => 1, 'kind' => 'node', 'node_id' => $nodeId, 'node_run_id' => $runId, 'observation_run' => '']
            && $context['impact']['confirmed'] && $context['impact']['target_count'] === 1
            && $context['impact']['proof_hash'] === hash('sha256', $proof), 'IoT操作未冻结独立命令身份、准确代次及空观察身份');
        foreach ($original['events'] as $event) {
            expect($event['tenant_id'] === null && $event['category'] === 'broker' && $event['action'] === 'broker.node_fence'
                && $event['actor_id'] === 'iot-audit-test' && $event['subject_id'] === $runId
                && $event['result'] === ($event['stage'] === 'completed' ? 'success' : 'pending'), 'IoT隔离事件的身份、分类或结果不符');
            if ($event['stage'] === 'completed') {
                $facts = json_decode($event['details'], true, 8, JSON_THROW_ON_ERROR)['facts'];
                expect($facts === ['observation_isolated' => false, 'resources_released' => true, 'store_confirmed' => true]
                    && $event['id'] === $completed['event_id'], 'IoT完成回执缺少同步/回收依据或伪造独立节点观察');
            }
        }
        expect($original['store']['observation_run'] === '' && $original['store']['proof_ref'] === 'sha256:' . hash('sha256', $proof)
            && !str_contains(json_encode($original, JSON_THROW_ON_ERROR), $proof), 'IoT原始确认依据泄露或污染独立观察身份');
        foreach ([$arguments, $resultCommand] as $retry) {
            expect(
                identityCommand($retry, $environment) === $completed && brokerFenceAuditState($inspection, $operationId, 'iot') === $original,
                'IoT原动作重试或结果查询改变事件ID、时间或安全依据'
            );
        }
        foreach ([1 => 'different-node', 2 => bin2hex(random_bytes(16)), 3 => 'different-actor', 4 => 'different-proof'] as $offset => $value) {
            $conflict = $arguments;
            $conflict[count($command) + $offset] = $value;
            $process = new Process($conflict, $root, $environment);
            try {
                $rejected = $process->wait(12);
                expect(!$rejected->successful() && !$rejected->timedOut && str_contains($rejected->stderr, 'broker_operation_conflict'), 'IoT同ID冲突没有明确拒绝');
            } finally {
                $process->stop();
            }
            expect(brokerFenceAuditState($inspection, $operationId, 'iot') === $original, 'IoT同ID冲突改写原审计依据');
        }
        $report['completed'] = ['operation_id' => $operationId, 'event_id' => $completed['event_id'], 'event_count' => 3,
            'original_events_and_timestamps_unchanged' => true, 'conflicting_fields_rejected' => 4, 'observation_run' => '', 'observation_isolated' => false];
        $unknownId = bin2hex(random_bytes(16));
        $unknownArguments = $arguments;
        $unknownArguments[count($unknownArguments) - 1] = $unknownId;
        $report['unknown'] = brokerFenceUnknownCommand($sync, $unknownArguments, $environment, $unknownId, 'iot');
        $unknownState = brokerFenceAuditState($inspection, $unknownId, 'iot');
        $report['unknown_state'] = $unknownState;
        expect($unknownState['operation']['stage'] === 'unknown' && $unknownState['operation']['result'] === 'unknown'
            && count($unknownState['events']) === 3 && is_array($unknownState['store']), 'IoT同步未知被误判成功或没有保留可对账事实');
        $unknownResult = [...$command, 'iot:mqtt-fence-result', $unknownId];
        $resolved = identityCommand($unknownResult, $environment);
        expect($resolved['operation_id'] === $unknownId && $resolved['stage'] === 'completed' && $resolved['result'] === 'success'
            && $resolved['fenced'] && !$resolved['observation_isolated'], 'IoT未知原ID没有在同步恢复及原worker释放后完成');
        $resolvedState = brokerFenceAuditState($inspection, $unknownId, 'iot');
        $report['resolved_state'] = $resolvedState;
        expect($resolvedState['operation']['stage'] === 'completed' && (int) $resolvedState['operation']['version'] === 4
            && count($resolvedState['events']) === 4 && $resolvedState['store'] === $unknownState['store'], 'IoT未知恢复重复隔离或没有保留原安全事实');
        foreach ($unknownState['events'] as $event) {
            expect(in_array($event, $resolvedState['events'], true), 'IoT未知恢复修改了原阶段事件');
        }
        $newRun = bin2hex(random_bytes(16));
        $reopened = $worker(['action' => 'node_open', 'node_id' => $nodeId, 'node_run_id' => $newRun]);
        $activeRun = $newRun;
        expect($reopened['generation'] === 2, 'IoT同名新运行没有形成新代次');
        $newRegistry = identityCommand([...$command, 'iot:mqtt-nodes'], $environment);
        expect($newRegistry['nodes'][0]['run_id'] === $newRun && $newRegistry['nodes'][0]['state'] === 'active', 'IoT新运行未处于独立活动状态');
        foreach ([$unknownArguments, $unknownResult] as $retry) {
            expect(identityCommand($retry, $environment) === $resolved && brokerFenceAuditState($inspection, $unknownId, 'iot') === $resolvedState
                && identityCommand([...$command, 'iot:mqtt-nodes'], $environment) === $newRegistry, 'IoT旧动作重试影响新运行或刷新原事实');
        }
        // 仅将本轮四条真实事件推至保留期外；操作和持久隔离事实不直接写入。
        $expire = $inspection->prepare('UPDATE iot_audit SET created_at = ? WHERE operation_id = ?');
        $expire->execute([time() - 180 * 86400 - 1, $unknownId]);
        expect($expire->rowCount() === 4, 'IoT清理夹具未准确限定四条真实阶段事件');
        $pruned = identityCommand([...$command, 'iot:audit-clean', '2'], $environment)['data'];
        $resumed = identityCommand([...$command, 'iot:audit-clean', '2'], $environment)['data'];
        expect($pruned['deleted'] === 2 && $pruned['has_more'] && $resumed['deleted'] === 2 && !$resumed['has_more'], 'IoT清理未遵守有界批次和可继续边界');
        $retained = brokerFenceAuditState($inspection, $unknownId, 'iot');
        expect($retained['events'] === [] && $retained['operation'] === $resolvedState['operation'] && $retained['store'] === $resolvedState['store'], 'IoT到期清理删除或改写安全依据');
        foreach ([$unknownArguments, $unknownResult] as $retry) {
            expect(identityCommand($retry, $environment) === array_replace($resolved, ['expired' => true])
                && brokerFenceAuditState($inspection, $unknownId, 'iot') === $retained
                && identityCommand([...$command, 'iot:mqtt-nodes'], $environment) === $newRegistry, 'IoT清理后重试重建旧日志、丢失原回执或影响新运行');
        }
        expect(brokerFenceAuditState($inspection, $operationId, 'iot') === $original, 'IoT定向清理误删未到期正常操作');
        $report['reconciled'] = ['operation_id' => $unknownId, 'event_id' => $resolved['event_id'], 'event_count_before_cleanup' => 4,
            'store_fact_unchanged' => true, 'old_events_unchanged' => true, 'audit_events_pruned' => 4, 'expired_receipt_retained' => true,
            'new_run_unchanged' => true, 'new_generation' => 2];
        $report['status'] = 'passed';
    } catch (Throwable $failure) {
        $report['status'] = 'failed';
        $report['failure'] = ['type' => get_class($failure), 'message' => $failure->getMessage(), 'line' => $failure->getLine()];
        throw $failure;
    } finally {
        try {
            if ($activeRun !== '') {
                expect($worker(['action' => 'node_close', 'node_id' => $nodeId, 'node_run_id' => $activeRun]) === ['fenced' => true], 'IoT新节点登记未回收');
            }
            $report['cleanup']['node_registration_closed'] = true;
            $report['cleanup']['workers_absent'] = (int) $inspection->query("SELECT COUNT(*) FROM pg_stat_activity WHERE datname = current_database() AND application_name LIKE 'type_mqtt_%'")->fetchColumn() === 0;
            $standby = $sync->standby();
            $report['cleanup']['standby_replay_resumed'] = $standby->query('SELECT pg_is_wal_replay_paused()')->fetchColumn() === false;
            expect(!in_array(false, $report['cleanup'], true), 'IoT隔离验收仍有未回收worker或未恢复备库');
        } catch (Throwable $cleanupFailure) {
            $report['status'] = 'failed';
            $report['cleanup_failure'] = ['type' => get_class($cleanupFailure), 'message' => $cleanupFailure->getMessage()];
            throw $cleanupFailure;
        } finally {
            $inspection = null;
            $standby = null;
            file_put_contents($base . '/iot-fence-audit.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        }
    }
    return $report;
}

/** 调用者已停止准确旧进程；验证原动作未知对账、冻结上下文、重复语义及180天日志回收边界。 */
function brokerIsolateObservedNode(PostgresSync $sync, array $command, array $environment, array $observation): array
{
    $inspection = $sync->connection();
    try {
        $deadline = microtime(true) + 12;
        do {
            $workers = (int) $inspection->query("SELECT COUNT(*) FROM pg_stat_activity WHERE datname = current_database() AND application_name LIKE 'type_mqtt_%'")->fetchColumn();
            if ($workers === 0) {
                break;
            }
            usleep(50000);
        } while (microtime(true) < $deadline);
        expect($workers === 0, '登记隔离前仍有未回收的持久后端');
    } finally {
        $inspection = null;
    }
    $registry = identityCommand([...$command, 'broker:nodes'], $environment);
    $registered = array_values(array_filter($registry['nodes'], static fn (array $node): bool => $node['node_id'] === $observation['node_id']));
    expect(count($registered) === 1, '缺少准确持久运行身份');
    $operationId = bin2hex(random_bytes(16));
    $proof = 'owned-process-and-workers-reaped-' . bin2hex(random_bytes(12));
    $arguments = [...$command, 'broker:node-fence', $observation['node_id'], $registered[0]['run_id'], $observation['run_id'], 'broker-test', $proof, $operationId];
    $wrong = $arguments;
    $wrong[count($command) + 3] = bin2hex(random_bytes(16));
    $wrong[count($wrong) - 1] = bin2hex(random_bytes(16));
    $rejected = new Process($wrong, dirname(__DIR__), $environment);
    try {
        expect(!$rejected->wait(12)->successful(), '错误采样运行不能登记隔离');
    } finally {
        $rejected->stop();
    }
    $unknown = brokerFenceUnknownCommand($sync, $arguments, $environment, $operationId);
    $resultCommand = [...$command, 'broker:node-fence-result', $operationId];
    $fenced = identityCommand($resultCommand, $environment);
    expect($fenced['fenced'] && $fenced['observation_isolated'] && $fenced['operation_id'] === $operationId
        && $fenced['stage'] === 'completed' && $fenced['result'] === 'success' && !$fenced['expired'], '隔离原ID对账缺少明确成功结果');
    $inspection = $sync->connection();
    try {
        $original = brokerFenceAuditState($inspection, $operationId);
        expect(is_array($original['operation']) && is_array($original['store']), '隔离成功缺少应用操作或持久安全事实');
        $stages = array_column($original['events'], 'stage');
        sort($stages);
        expect($stages === ['accepted', 'completed', 'executing', 'unknown'], '原动作未知恢复必须保留四个独立阶段事件');
        $results = ['accepted' => 'pending', 'executing' => 'pending', 'unknown' => 'unknown', 'completed' => 'success'];
        foreach ($original['events'] as $event) {
            expect($event['category'] === 'broker' && $event['event_key'] === $event['stage'] && $event['result'] === $results[$event['stage']]
                && preg_match('/^[a-f0-9]{32}$/D', $event['request_id']) === 1, '阶段审计的分类、结果或请求关联不符合原动作事实');
            if ($event['stage'] === 'completed') {
                $facts = json_decode($event['details'], true, 8, JSON_THROW_ON_ERROR)['facts'];
                expect($facts['store_confirmed'] && $facts['resources_released'] && $facts['observation_isolated'], '完成事件没有保留同步、资源释放和观察隔离依据');
            }
        }
        expect($original['operation']['stage'] === 'completed' && $original['operation']['result'] === 'success'
            && (int) $original['operation']['version'] === 4, '未知恢复后的操作当前结果没有单调推进至完成');
        $context = json_decode($original['operation']['context_json'], true, 16, JSON_THROW_ON_ERROR);
        expect($context['operation_id'] === $operationId && $context['origin_request_id'] === $unknown['origin_request_id']
            && $context['authorization']['source'] === 'operator-command' && $context['authorization']['decision'] === 'allowed'
            && $context['authorization']['required_action'] === 'broker.node_fence' && $context['actor_id'] === 'broker-test'
            && $context['target']['node_id'] === $observation['node_id'] && $context['target']['node_run_id'] === $registered[0]['run_id']
            && $context['target']['observation_run'] === $observation['run_id'] && $context['target']['generation'] === (int) $registered[0]['generation']
            && $context['impact']['proof_hash'] === hash('sha256', $proof) && $context['impact']['confirmed'] === true
            && $context['impact']['target_count'] === 1, '节点动作审计没有冻结准确权限、目标代次和确认摘要');
        expect($original['store']['proof_ref'] === 'sha256:' . hash('sha256', $proof)
            && !str_contains(json_encode($original, JSON_THROW_ON_ERROR), $proof), '确认依据原文进入应用审计或持久隔离事实');
        foreach ([$arguments, $resultCommand] as $repeatCommand) {
            expect(identityCommand($repeatCommand, $environment) === $fenced, '原命令重试和结果查询未返回同一原始回执');
            expect(brokerFenceAuditState($inspection, $operationId) === $original, '重复原动作增加审计、刷新时间或改写安全事实');
        }
        foreach ([1 => 'different-node', 2 => bin2hex(random_bytes(16)), 3 => bin2hex(random_bytes(16)), 4 => 'different-actor', 5 => 'different-proof'] as $offset => $value) {
            $conflict = $arguments;
            $conflict[count($command) + $offset] = $value;
            $process = new Process($conflict, dirname(__DIR__), $environment);
            try {
                expect(!$process->wait(12)->successful(), '原操作ID接受了不同节点、运行、人员或依据');
            } finally {
                $process->stop();
            }
            expect(brokerFenceAuditState($inspection, $operationId) === $original, '同ID冲突请求修改了原事件或操作事实');
        }
        // 只预置本轮四条事件的到期时间；原操作回执与持久隔离记录保持原字节。
        $expire = $inspection->prepare('UPDATE broker_audit SET created_at = ? WHERE operation_id = ?');
        $expire->execute([time() - 180 * 86400 - 1, $operationId]);
        expect($expire->rowCount() === 4, '审计保留期装置没有准确限定本轮阶段事件');
        $interrupted = null;
        try {
            $inspection->beginTransaction();
            $inspection->exec('UPDATE broker_audit SET details = details WHERE operation_id = ' . $inspection->quote($operationId));
            $lockerPid = (int) $inspection->query('SELECT pg_backend_pid()')->fetchColumn();
            $interrupted = new Process([...$command, 'broker:audit-clean', '2'], dirname(__DIR__), $environment);
            $blocked = false;
            $deadline = microtime(true) + 3;
            do {
                // 保持锁事务，同时丢弃统计快照以观察随后启动的清理进程。
                $inspection->query('SELECT pg_stat_clear_snapshot()')->fetchColumn();
                $blocked = (int) $inspection->query('SELECT COUNT(*) FROM pg_stat_activity WHERE datname = current_database() '
                    . 'AND usename = session_user AND ' . $lockerPid . ' = ANY(pg_blocking_pids(pid))')->fetchColumn() > 0;
                if ($blocked) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline && $interrupted->running());
            expect($blocked, '清理中断回归没有进入真实数据库等待');
            $interrupted->stop(1);
        } finally {
            $interrupted?->stop();
            if ($inspection->inTransaction()) {
                $inspection->rollBack();
            }
        }
        expect(count(brokerFenceAuditState($inspection, $operationId)['events']) === 4, '清理失败留下部分删除或丢失未完成批次');
        $pruned = identityCommand([...$command, 'broker:audit-clean', '2'], $environment)['data'];
        expect($pruned['deleted'] === 2 && $pruned['has_more'], '独立审计清理未遵守有界批次');
        $resumed = identityCommand([...$command, 'broker:audit-clean', '2'], $environment)['data'];
        expect($resumed['deleted'] === 2 && !$resumed['has_more'], '独立审计剩余到期事件没有清理完成');
        $retained = brokerFenceAuditState($inspection, $operationId);
        expect($retained['events'] === [] && $retained['operation'] === $original['operation'] && $retained['store'] === $original['store'], '180天清理删除或改写操作安全事实');
        foreach ([$arguments, $resultCommand] as $repeatCommand) {
            $expired = identityCommand($repeatCommand, $environment);
            expect($expired === array_replace($fenced, ['expired' => true]), '过期事件重试未返回原回执及明确过期标记');
            expect(brokerFenceAuditState($inspection, $operationId) === $retained, '180天后重试重建旧日志或刷新操作/隔离事实');
        }
        expect(identityCommand([...$command, 'broker:audit-clean', '2'], $environment)['data']['deleted'] === 0, '到期审计清理重试不幂等');
    } finally {
        $inspection = null;
    }
    $concurrent = brokerFenceConcurrentCommands($sync, $arguments, $environment);
    $lateUnknown = brokerFenceLateUnknown($sync, $command, $arguments, $environment);
    return ['generation' => (int) $registered[0]['generation'], 'old_process_and_workers_absent' => true,
        'wrong_observation_rejected' => true, 'fenced' => true, 'operation_id' => $operationId, 'unknown' => $unknown,
        'concurrent_first_acceptance' => $concurrent, 'late_query_unknown' => $lateUnknown,
        'audit_events_pruned' => 4, 'operation_fact' => $retained['operation'], 'store_fact' => $retained['store'], 'command' => $arguments];
}

/**
 * 两个真实探针交错完成时，未证明资源清理的隔离不能被先前已启动的成功读取解除。
 * 调用方须先停止本轮节点和浏览器，保持同一个Swoole管理宿主及主备存活；本函数不重启宿主。
 * 只临时改变本轮隔离数据库角色的LOGIN，原有管理连接和第二个存储连接保持可用。
 *
 * @param array<string,string> $environment 与管理宿主一致的受控测试环境，令牌仅通过子进程环境传递。
 * @return array<string,mixed> 无密码、令牌或SQL载荷的响应、准确worker身份和清理事实。
 */
function brokerConcurrentQuarantine(PostgresSync $sync, HttpClient $http, string $probeToken, array $environment, Process $server, int &$checks, string $base): array
{
    $root = dirname(__DIR__);
    expect(($environment['DB_HOST'] ?? '') === '127.0.0.1', '并发隔离回归需要本机PostgreSQL');
    $inspection = $sync->connection();
    $standby = $sync->standby();
    $role = (string) $inspection->query('SELECT session_user')->fetchColumn();
    $dataDirectory = realpath((string) $inspection->query('SHOW data_directory')->fetchColumn());
    expect($role === ($environment['DB_USERNAME'] ?? '') && preg_match('/^[a-z_][a-z0-9_]*$/D', $role) === 1
        && $inspection->query('SELECT current_database()')->fetchColumn() === ($environment['DB_DATABASE'] ?? '')
        && (string) $inspection->query('SHOW port')->fetchColumn() === ($environment['DB_PORT'] ?? '')
        && is_string($dataDirectory) && str_starts_with($dataDirectory, $root . '/build/broker-observability-'), '不能修改不属于本轮观测装置的数据库角色');
    $roleSql = '"' . $role . '"';
    expect($inspection->query('SELECT rolcanlogin FROM pg_roles WHERE rolname = session_user')->fetchColumn() === true, '测试角色初始LOGIN状态异常');
    $locker = (int) $inspection->query('SELECT pg_backend_pid()')->fetchColumn();
    $workerQuery = $inspection->prepare('SELECT pid, application_name, client_port, wait_event, query_start::text FROM pg_stat_activity '
        . "WHERE datname = current_database() AND usename = session_user AND application_name ~ '^type_mqtt_[a-f0-9]{32}$' "
        . "AND backend_type = 'client backend'");
    $waitQuery = $inspection->prepare('SELECT pid, application_name, client_port, query_start::text FROM pg_stat_activity '
        . "WHERE datname = current_database() AND usename = session_user AND application_name ~ '^type_mqtt_[a-f0-9]{32}$' "
        . "AND wait_event_type = 'Lock' AND wait_event = 'advisory' AND ? = ANY(pg_blocking_pids(pid)) ORDER BY query_start, pid");
    $waiters = static function () use ($waitQuery, $locker): array {
        $waitQuery->execute([$locker]);
        return $waitQuery->fetchAll(PDO::FETCH_ASSOC);
    };
    $clientCode = 'require getenv("BROKER_TEST_ROOT")."/vendor/autoload.php";'
        . '$response=(new Type\\Testing\\HttpClient("http://127.0.0.1:".getenv("APP_PORT"),12.0))'
        . '->request("GET","/broker/health/ready",["Authorization"=>"Bearer ".getenv("BROKER_TEST_TOKEN")]);'
        . 'echo json_encode(["status"=>$response->status,"data"=>$response->json()["data"]],JSON_THROW_ON_ERROR);';
    $clients = [];
    $backends = [];
    $initialDescendants = [];
    $serverDescendants = static function () use ($server): array {
        $owner = $server->pid();
        expect($owner !== null, '并发隔离期间管理宿主提前退出');
        $states = unixProcessStates();
        $descendants = [];
        foreach ($states as $pid => $state) {
            $ancestor = $state['parent'];
            for ($depth = 0; $depth < 8 && $ancestor !== $owner && isset($states[$ancestor]); $depth++) {
                $ancestor = $states[$ancestor]['parent'];
            }
            if ($ancestor === $owner) {
                $descendants[] = $pid;
            }
        }
        return $descendants;
    };
    $locked = false;
    $loginDisabled = false;
    $replayPaused = false;
    $report = ['status' => 'running', 'stage' => 'baseline'];
    try {
        $workerQuery->execute();
        expect($workerQuery->fetchAll(PDO::FETCH_ASSOC) === [], '并发隔离前节点、浏览器或存储工作仍未排空');
        $baseline = $http->request('GET', '/broker/health/ready', ['Authorization' => 'Bearer ' . $probeToken]);
        $checks++;
        $report['baseline'] = $baseline->json()['data']['store'];
        expect($report['baseline']['state'] === 'available' && !$report['baseline']['quarantined'], '并发隔离前存储没有取得真实同步证明');
        $initialDescendants = $serverDescendants();
        $initialLogBytes = strlen($server->stdout());
        $inspection->query('SELECT pg_advisory_lock(1954115693, 1)')->fetchColumn();
        $locked = true;
        $report['stage'] = 'first_waiter';
        $started = microtime(true);
        $clientEnvironment = array_replace($environment, ['BROKER_TEST_ROOT' => $root, 'BROKER_TEST_TOKEN' => $probeToken]);
        $clients['first'] = new Process([PHP_BINARY, '-n', '-d', 'zend.exception_ignore_args=1', '-r', $clientCode], $root, $clientEnvironment);
        $deadline = microtime(true) + 2;
        do {
            $rows = $waiters();
            if (count($rows) === 1) {
                break;
            }
            expect($clients['first']->running(), '首个探针尚未进入锁等待便结束');
            usleep(5000);
        } while (microtime(true) < $deadline);
        expect(count($rows) === 1, '没有定位首个真实探针的事务锁等待');
        $backends['first'] = $rows[0];
        // 第二个HTTP客户端在定位首个本地worker期间启动，不延长生产的一秒锁等待预算。
        $clients['second'] = new Process([PHP_BINARY, '-n', '-d', 'zend.exception_ignore_args=1', '-r', $clientCode], $root, $clientEnvironment);
        $report['stage'] = 'worker_identity';
        $clientPort = (int) $backends['first']['client_port'];
        expect($clientPort > 0 && $clientPort <= 65535, '首个探针缺少本机TCP连接身份');
        $sockets = new Process(['lsof', '-nP', '-a', '-iTCP:' . $clientPort, '-sTCP:ESTABLISHED', '-Fpn'], $root, null, 65536);
        try {
            $socketResult = $sockets->wait(1);
            expect($socketResult->successful(), '不能核对首个存储连接的本地进程');
        } finally {
            $sockets->stop();
        }
        $matches = [];
        $candidate = 0;
        foreach (explode("\n", $socketResult->stdout) as $line) {
            if (preg_match('/^p([1-9][0-9]*)$/D', $line, $match) === 1) {
                $candidate = (int) $match[1];
            } elseif ($line === 'n127.0.0.1:' . $clientPort . '->127.0.0.1:' . $environment['DB_PORT']) {
                $matches[$candidate] = true;
            }
        }
        expect(count($matches) === 1 && !isset($matches[0]), '首个数据库连接没有唯一的本地worker');
        $workerPid = (int) array_key_first($matches);
        $processes = unixProcessStates();
        $ownerPid = $server->pid();
        $ancestor = $workerPid;
        for ($depth = 0; $depth < 8 && $ancestor !== $ownerPid && isset($processes[$ancestor]); $depth++) {
            $ancestor = $processes[$ancestor]['parent'];
        }
        expect($ownerPid !== null && $ancestor === $ownerPid && $workerPid !== $ownerPid, '准确存储worker不属于本轮管理宿主');
        $identity = new Process(['ps', '-p', (string) $workerPid, '-o', 'command='], $root, null, 65536);
        try {
            $identityResult = $identity->wait(1);
            expect($identityResult->successful() && preg_match('/(?:^|\s)broker:store(?:\s|$)/', $identityResult->stdout) === 1
                && str_contains($identityResult->stdout, '--store-worker-pipe'), '准确本地进程不是公开broker:store入口');
        } finally {
            $identity->stop();
        }
        $report['stage'] = 'concurrent_waiters';
        $deadline = microtime(true) + 0.5;
        do {
            $rows = $waiters();
            if (count($rows) === 2) {
                break;
            }
            expect($clients['first']->running() && $clients['second']->running(), '两个探针未形成真实并发锁等待');
            usleep(5000);
        } while (microtime(true) < $deadline);
        expect(count($rows) === 2 && $rows[0]['application_name'] === $backends['first']['application_name'], '首个探针已越过一秒锁等待窗口，未形成目标交错');
        $backends['second'] = $rows[1];
        $report['backends'] = $backends;
        $report['worker_fault'] = ['pid' => $workerPid, 'owner_pid' => $ownerPid, 'client_port' => $clientPort, 'signal' => 'SIGKILL'];
        // 第二项读取必须能越过一秒锁截止；用真实备库暂停暂缓WAL证明，生产工作截止不变。
        $standby->query('SELECT pg_wal_replay_pause()')->fetchColumn();
        $replayPaused = true;
        $inspection->exec("SET synchronous_commit = 'local'");
        $inspection->exec('ALTER ROLE ' . $roleSql . ' NOLOGIN');
        $loginDisabled = true;
        expect(posix_kill($workerPid, SIGKILL), '无法终止已核对身份的首个本地存储worker');
        expect($inspection->query('SELECT pg_advisory_unlock(1954115693, 1)')->fetchColumn() === true, '未释放本轮事务阻塞锁');
        $locked = false;
        $report['stage'] = 'first_quarantine';
        $deadline = microtime(true) + 3;
        $firstLogged = false;
        do {
            // Swoole可能稍后才关闭响应socket；用公开结构化日志确认第一项结果已经形成。
            foreach (explode("\n", substr($server->stdout(), $initialLogBytes)) as $logLine) {
                $event = json_decode($logLine, true);
                if (is_array($event) && in_array($event['context']['store_state'] ?? '', ['unknown', 'quarantined'], true)) {
                    $firstLogged = true;
                }
            }
            if ($firstLogged) {
                break;
            }
            usleep(5000);
        } while (microtime(true) < $deadline);
        expect($firstLogged, '首个探针没有在真实清理失败后形成公开故障日志');
        $workerQuery->execute();
        $rows = $workerQuery->fetchAll(PDO::FETCH_ASSOC);
        $stillWaiting = array_values(array_filter($rows, static fn (array $row): bool => $row['application_name'] === $backends['second']['application_name']));
        $report['second_pending_after_first_response'] = count($stillWaiting) === 1;
        expect(count($stillWaiting) === 1 && $clients['second']->running(), '首个隔离返回前第二个探针已结束，未形成目标交错');
        $inspection->exec('ALTER ROLE ' . $roleSql . ' LOGIN');
        $loginDisabled = false;
        $standby->query('SELECT pg_wal_replay_resume()')->fetchColumn();
        $replayPaused = false;
        $report['stage'] = 'late_success';
        $second = $clients['second']->wait(5);
        expect($second->successful(), '第二个已连接的探针没有在解锁后完成');
        $first = $clients['first']->wait(3);
        $report['first_client'] = ['exit_code' => $first->exitCode, 'timed_out' => $first->timedOut, 'signal' => $first->signal];
        expect($first->successful(), '首个测试客户端没有正常退出');
        $report['first'] = json_decode($first->stdout, true, 64, JSON_THROW_ON_ERROR);
        $checks++;
        expect($report['first']['status'] === 503 && $report['first']['data']['store']['quarantined'] === true
            && $report['first']['data']['store']['metrics'] === null, '真实cleanup无法连接后首个探针没有保留隔离');
        $report['second'] = json_decode($second->stdout, true, 64, JSON_THROW_ON_ERROR);
        $report['interleaving_seconds'] = microtime(true) - $started;
        $checks++;
        expect(
            $report['second']['status'] === 503 && $report['second']['data']['store']['quarantined'] === true
            && $report['second']['data']['store']['state'] === 'quarantined' && $report['second']['data']['store']['metrics'] === null,
            '先前已启动的成功探针清除了已建立的资源隔离或报告存储可用'
        );
        // 外部监督只清理本轮两项准确后端；这不改变此前未知结果，也不重置HTTP进程的隔离。
        $terminate = $inspection->prepare('SELECT pg_terminate_backend(pid, 500) FROM pg_stat_activity WHERE datname = current_database() '
            . 'AND usename = session_user AND pid = ? AND application_name = ? AND client_port = ?');
        foreach ($backends as $backend) {
            $terminate->execute([$backend['pid'], $backend['application_name'], $backend['client_port']]);
        }
        $report['stage'] = 'retained_quarantine';
        $inspection->query('SELECT pg_advisory_lock(1954115693, 1)')->fetchColumn();
        $locked = true;
        for ($index = 0; $index < 2; $index++) {
            $clients['retained_' . $index] = new Process([PHP_BINARY, '-n', '-d', 'zend.exception_ignore_args=1', '-r', $clientCode], $root, $clientEnvironment);
            $retained = $clients['retained_' . $index];
            $deadline = microtime(true) + 3;
            $observedWorkers = [];
            do {
                $workerQuery->execute();
                $observedWorkers = [...$observedWorkers, ...$workerQuery->fetchAll(PDO::FETCH_ASSOC)];
                if (!$retained->running()) {
                    break;
                }
                usleep(5000);
            } while (microtime(true) < $deadline);
            $retainedResult = $retained->wait(1);
            expect($retainedResult->successful(), '隔离后的探针没有在预算内返回');
            $retainedData = json_decode($retainedResult->stdout, true, 64, JSON_THROW_ON_ERROR);
            $report['retained'][] = ['response' => $retainedData, 'observed_workers' => $observedWorkers];
            $checks++;
            expect($retainedData['status'] === 503 && $retainedData['data']['store']['quarantined'] === true
                && $retainedData['data']['store']['state'] === 'quarantined' && $retainedData['data']['store']['metrics'] === null
                && $observedWorkers === [], '后续探针自动解除隔离或重新启动持久worker');
        }
        $report['status'] = 'passed';
    } catch (Throwable $failure) {
        $report['status'] = 'failed';
        $report['failure'] = ['type' => get_class($failure), 'message' => $failure->getMessage()];
        throw $failure;
    } finally {
        $cleanup = [];
        try {
            if ($replayPaused) {
                $standby->query('SELECT pg_wal_replay_resume()')->fetchColumn();
            }
            expect($standby->query('SELECT pg_is_wal_replay_paused()')->fetchColumn() === false, '测试备库回放未恢复');
            $report['standby_replay_resumed'] = true;
        } catch (Throwable $failure) {
            $cleanup[] = 'replay: ' . get_class($failure);
        }
        try {
            if ($loginDisabled) {
                $inspection->exec('ALTER ROLE ' . $roleSql . ' LOGIN');
            }
            expect($inspection->query('SELECT rolcanlogin FROM pg_roles WHERE rolname = session_user')->fetchColumn() === true, '测试角色LOGIN未恢复');
            $report['login_restored'] = true;
        } catch (Throwable $failure) {
            $cleanup[] = 'login: ' . get_class($failure);
        }
        try {
            if ($locked) {
                expect($inspection->query('SELECT pg_advisory_unlock(1954115693, 1)')->fetchColumn() === true, '本轮advisory lock未释放');
            }
            $report['advisory_lock_released'] = true;
        } catch (Throwable $failure) {
            $cleanup[] = 'lock: ' . get_class($failure);
        }
        foreach ($clients as $name => $client) {
            try {
                $client->stop();
                $report['clients_stopped'][$name] = !$client->running();
            } catch (Throwable $failure) {
                $cleanup[] = 'client: ' . $name;
            }
        }
        try {
            $deadline = microtime(true) + 12;
            do {
                $workerQuery->execute();
                $remaining = $workerQuery->fetchAll(PDO::FETCH_ASSOC);
                $remainingProcesses = $clients === [] ? [] : array_values(array_diff($serverDescendants(), $initialDescendants));
                if ($remaining === [] && $remainingProcesses === []) {
                    break;
                }
                usleep(50000);
            } while (microtime(true) < $deadline);
            $report['remaining_backends'] = $remaining;
            $report['remaining_worker_processes'] = $remainingProcesses;
            expect($remaining === [] && $remainingProcesses === [], '测试探针本地worker或持久后端没有排空');
        } catch (Throwable $failure) {
            $cleanup[] = 'backend: ' . get_class($failure);
        }
        $report['cleanup_failures'] = $cleanup;
        if ($cleanup !== []) {
            $report['status'] = 'failed';
        }
        $snapshots = is_file($base . '/observability.json') ? json_decode((string) file_get_contents($base . '/observability.json'), true, 64, JSON_THROW_ON_ERROR) : [];
        $snapshots['concurrent_quarantine'] = $report;
        file_put_contents($base . '/observability.json', json_encode($snapshots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        $waiters = null;
        $waitQuery = null;
        $workerQuery = null;
        $inspection = null;
        $standby = null;
        expect($cleanup === [], '并发隔离回归资源清理失败：' . implode('; ', $cleanup));
    }
    return $report;
}

/** 验收最后停止本轮主备，确认存活探针不借用数据库成功来掩盖就绪失败。 */
function brokerManagementUnavailable(PostgresSync $sync, NativeDatabase $database, HttpClient $http, string $probeToken, int &$checks): array
{
    $sync->close();
    // 清理可能仍存在的本测试库连接，再停止其主库，避免smart shutdown等待它们。
    $inspection = $sync->connection();
    try {
        $terminated = $inspection->query('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = current_database() '
            . "AND pid <> pg_backend_pid() AND backend_type = 'client backend'")->fetchAll(PDO::FETCH_COLUMN);
        expect(!in_array(false, $terminated, true), '隔离管理数据库连接没有终止');
    } finally {
        $inspection = null;
    }
    $database->close();
    $started = microtime(true);
    $live = $http->request('GET', '/broker/health/live', ['Authorization' => 'Bearer ' . $probeToken]);
    expect($live->status === 200 && $live->json()['data']['alive'] === true, '数据库退出后管理HTTP仍存活时不能伪报进程死亡');
    $ready = $http->request('GET', '/broker/health/ready', ['Authorization' => 'Bearer ' . $probeToken]);
    expect(
        $ready->status === 503 && $ready->json()['data']['state'] === 'management_unavailable' && $ready->json()['data']['ready'] === false,
        '真实管理数据库不可用时必须明确不就绪'
    );
    $metrics = $http->request('GET', '/broker/metrics', ['Authorization' => 'Bearer ' . $probeToken]);
    expect(
        $metrics->status === 503 && str_contains($metrics->body, "\ntypeapp_broker_ready 0\n")
        && !str_contains($metrics->body, 'typeapp_broker_connections{') && !str_contains($metrics->body, 'typeapp_broker_store_pending_messages '),
        '管理数据库故障抓取不能伪造节点和存储实时样本'
    );
    $checks += 3;
    return ['live_status' => $live->status, 'ready_status' => $ready->status, 'metrics_status' => $metrics->status,
        'state' => $ready->json()['data']['state'], 'terminated_management_connections' => count($terminated), 'elapsed_seconds' => microtime(true) - $started];
}

// 本文件直接执行时拥有真实主备装置；被人员测试加载时仅提供上述公开响应断言。
if (realpath($argv[0] ?? '') === __FILE__) {
    require_once __DIR__ . '/support.php';
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    require_once __DIR__ . '/native-database.php';
    require_once __DIR__ . '/postgres-sync.php';
    $testRoot = dirname(__DIR__);
    $testArguments = $argv;
    $databaseTools = NativeDatabase::tools('pgsql', (string) getenv('TYPE_PGSQL_TOOLS'));
    $fixtureRoot = $testRoot . '/build/broker-observability-' . bin2hex(random_bytes(6));
    expect(mkdir($fixtureRoot, 0700), '无法创建Broker观测验收根');
    $fixtureDatabase = null;
    $fixtureSync = null;
    try {
        $fixtureDatabase = new NativeDatabase($fixtureRoot . '/primary', 'pgsql', $databaseTools);
        $fixtureSync = new PostgresSync($fixtureDatabase, $fixtureRoot . '/standby', $databaseTools, 'broker_observe_sync');
        $GLOBALS['brokerObservabilitySync'] = $fixtureSync;
        $GLOBALS['brokerObservabilityDatabase'] = $fixtureDatabase;
        foreach ($fixtureDatabase->environment() as $key => $value) {
            if (str_starts_with($key, 'TYPE_PGSQL_')) {
                putenv($key . '=' . $value);
            }
        }
        $fixtureMode = in_array('--iot-audit-fence', $testArguments, true) ? [] : ['--broker', '--broker-observability'];
        $argv = [__DIR__ . '/iot-identity.php', $testArguments[1] ?? '--php', 'pgsql', ...$fixtureMode, ...array_slice($testArguments, 2)];
        require __DIR__ . '/iot-identity.php';
    } finally {
        $fixtureSync?->close();
        $fixtureDatabase?->close();
        file_put_contents($fixtureRoot . '/replication.json', json_encode($fixtureSync?->evidence(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        unset($GLOBALS['brokerObservabilitySync'], $GLOBALS['brokerObservabilityDatabase']);
    }
}
