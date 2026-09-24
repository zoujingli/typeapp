<?php

declare(strict_types=1);

use Type\Testing\Process;

/** 通过已有公开策略与失效来源装配精确凭据场景，不给组件增加测试入口。 */
function mqttIdentityApplication(string $root): string
{
    $source = file_get_contents($root . '/examples/mqtt/main.php');
    $start = '$broker = new Broker(new ExampleMqttAccess(), new BrokerOptions(';
    $end = "    });\n    \$broker->serve";
    $entry = "function main(int \$argc, array \$argv): void\n{";
    expect(substr_count($source, $start) === 1 && substr_count($source, $end) === 1 && substr_count($source, $entry) === 1, '身份测试装配入口变化');
    $source = str_replace($entry, $entry . "\n    if (in_array('--identity-publish', \$argv, true)) {\n        mqttIdentityPublish();\n        return;\n    }", $source);
    $source = str_replace($start, "\$identityAccess = new IdentityTestAccess();\n    \$broker = new Broker(getenv('MQTT_TEST_LEGACY_POLICY') === '1' ? new LegacyIdentityTestAccess() : \$identityAccess, new BrokerOptions(", $source);
    return str_replace($end, "    }, observer: getenv('MQTT_TEST_RESOURCE_OBSERVER') === '1' ? new IdentityResourceTestObserver() : null, invalidations: \$identityAccess, disconnects: \$identityAccess, quotas: \$identityAccess);\n    \$broker->serve", $source) . <<<'PHP'


/** 消费公开观察接口记录真实事件，既有身份场景默认不启用。 */
final class IdentityResourceTestObserver implements \Type\Mqtt\ResourceConnectionObserver
{
    public function connected(string $clientId, string $username, string $ownerId, int $observedAt): void
    {
        throw new RuntimeException('resource_observer_legacy_callback_used');
    }
    public function connectedResource(array $resource, int $observedAt): void
    {
        $this->append(['event' => 'connected', 'resource' => $resource, 'observed_at' => $observedAt]);
    }
    public function subscriptionResource(string $ownerId, string $filter, ?array $subscription, int $observedAt): void
    {
        $this->append(['event' => 'subscription', 'owner_id' => $ownerId, 'filter' => $filter, 'subscription' => $subscription, 'observed_at' => $observedAt]);
    }
    public function disconnected(string $clientId, string $username, string $ownerId, int $observedAt, int $reason): void
    {
        $this->append(['event' => 'disconnected', 'owner_id' => $ownerId, 'observed_at' => $observedAt]);
    }
    public function heartbeat(int $observedAt): void {}
    public function stopped(int $observedAt): void {}
    private function append(array $event): void
    {
        $bytes = json_encode($event, JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents('identity-resource-observations.jsonl', $bytes, FILE_APPEND | LOCK_EX) !== strlen($bytes)) {
            throw new RuntimeException('resource_observer_append_failed');
        }
    }
}

/** 原有bool认证消费形式仍通过真实网络执行，不需要新身份方法。 */
final class LegacyIdentityTestAccess implements \Type\Mqtt\AccessPolicy
{
    private ExampleMqttAccess $example;
    public function __construct()
    {
        $this->example = new ExampleMqttAccess();
    }
    public function authenticate(ConnectPacket $connect, string $peer, bool $secure): bool
    {
        return $this->example->authenticate($connect, $peer, $secure);
    }
    public function authorize(ConnectPacket $connect, string $topic, string $action, int $qos): bool
    {
        return $this->example->authorize($connect, $topic, $action, $qos);
    }
}

/** 有界文件模拟消费者已持久接纳的撤权事实；Broker和Store仍执行真实关闭及同步清理。 */
final class IdentityTestAccess implements \Type\Mqtt\ResourceAccessPolicy, \Type\Mqtt\AuthorizationInvalidations, \Type\Mqtt\ConnectionDisconnects, \Type\Mqtt\QuotaUpdates
{
    private ExampleMqttAccess $example;
    private string $completed = '';
    private string $disconnected = '';
    private string $terminated = '';
    private string $cleared = '';
    private string $principal;
    public function __construct()
    {
        $this->example = new ExampleMqttAccess();
        $this->principal = (string) (getenv('MQTT_TEST_PRINCIPAL') ?: 'example');
    }
    public function authenticate(ConnectPacket $connect, string $peer, bool $secure): bool
    {
        return $this->authenticateIdentity($connect, $peer, $secure) !== null;
    }
    public function authenticateIdentity(ConnectPacket $connect, string $peer, bool $secure): ?AccessIdentity
    {
        $identity = $this->example->authenticateIdentity($connect, $peer, $secure);
        if ($identity !== null && $identity->principalId === 'example') {
            $identity = new AccessIdentity($this->principal, $identity->credentialId, $identity->credentialVersion, $identity->authenticationMethod);
        }
        return $identity !== null && $this->active($identity) ? $identity : null;
    }
    public function authorize(ConnectPacket $connect, string $topic, string $action, int $qos): bool
    {
        return $topic !== (string) getenv('MQTT_TEST_DENIED_TOPIC') && $this->example->authorize($connect, $topic, $action, $qos);
    }
    public function resourceScope(AccessIdentity $identity, ConnectPacket $connect): ?string
    {
        $scope = (string) getenv('MQTT_TEST_RESOURCE_SCOPE');
        return $scope === '' ? null : $scope;
    }
    public function authorizeIdentity(AccessIdentity $identity, ConnectPacket $connect, string $topic, string $action, int $qos): bool
    {
        if ($identity->principalId !== $this->principal || !$this->active($identity)) {
            return false;
        }
        $original = new AccessIdentity('example', $identity->credentialId, $identity->credentialVersion, $identity->authenticationMethod);
        return $this->authorize($connect, $topic, $action, $qos) && $this->example->authorizeIdentity($original, $connect, $topic, $action, $qos);
    }
    private function intent(): ?array
    {
        if (!is_file('identity-invalidation.json')) {
            return null;
        }
        $bytes = file_get_contents('identity-invalidation.json', false, null, 0, 2049);
        if ($bytes === false || strlen($bytes) > 2048) {
            throw new RuntimeException('identity_intent_invalid');
        }
        return json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
    }
    private function active(AccessIdentity $identity): bool
    {
        $intent = $this->intent();
        return $intent === null || !$identity->matches(AccessIdentity::fromData($intent['access_identity']));
    }
    public function nextInvalidation(): ?array
    {
        $intent = $this->intent();
        return $intent !== null && $intent['id'] !== $this->completed ? $intent : null;
    }
    public function invalidationCompleted(string $id): void
    {
        if (file_put_contents('identity-completed', $id) !== strlen($id)) {
            throw new RuntimeException('identity_completion_failed');
        }
        $this->completed = $id;
    }
    private function disconnectIntent(): ?array
    {
        if (!is_file('identity-disconnect.json')) {
            return null;
        }
        $bytes = file_get_contents('identity-disconnect.json', false, null, 0, 2049);
        if ($bytes === false || strlen($bytes) > 2048) {
            throw new RuntimeException('identity_disconnect_invalid');
        }
        return json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
    }
    public function nextDisconnect(): ?array
    {
        $intent = $this->disconnectIntent();
        return $intent !== null && $intent['id'] !== $this->disconnected ? $intent : null;
    }
    public function disconnectCompleted(string $id, string $outcome): bool
    {
        $bytes = $id . ':' . $outcome;
        if (file_put_contents('identity-disconnected', $bytes) !== strlen($bytes)) {
            throw new RuntimeException('identity_disconnect_completion_failed');
        }
        $this->disconnected = $id;
        return true;
    }
    public function nextTermination(): ?array
    {
        $intent = $this->terminationIntent();
        return $intent !== null && $intent['id'] !== $this->terminated ? $intent : null;
    }
    public function terminationCompleted(string $id, string $outcome): bool
    {
        $bytes = $id . ':' . $outcome;
        if (file_put_contents('identity-terminated', $bytes) !== strlen($bytes)) {
            throw new RuntimeException('identity_termination_completion_failed');
        }
        $this->terminated = $id;
        return true;
    }
    public function nextClearance(): ?array
    {
        $intent = $this->clearanceIntent();
        return $intent !== null && $intent['id'] !== $this->cleared ? $intent : null;
    }
    public function clearanceCompleted(string $id, string $outcome): bool
    {
        $bytes = $id . ':' . $outcome;
        if (file_put_contents('identity-cleared', $bytes) !== strlen($bytes)) {
            throw new RuntimeException('identity_clearance_completion_failed');
        }
        $this->cleared = $id;
        return true;
    }
    public function nextQuota(): ?array
    {
        $intent = $this->quotaIntent();
        if ($intent === null) {
            return null;
        }
        return ['version' => (int) $intent['version'], 'limits' => $intent['limits']];
    }
    private function quotaIntent(): ?array
    {
        if (!is_file('identity-quota.json')) {
            return null;
        }
        $bytes = file_get_contents('identity-quota.json', false, null, 0, 2049);
        if ($bytes === false || strlen($bytes) > 2048) {
            throw new RuntimeException('identity_quota_invalid');
        }
        $intent = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
        return is_array($intent) ? $intent : null;
    }
    private function clearanceIntent(): ?array
    {
        if (!is_file('identity-clear.json')) {
            return null;
        }
        $bytes = file_get_contents('identity-clear.json', false, null, 0, 2049);
        if ($bytes === false || strlen($bytes) > 2048) {
            throw new RuntimeException('identity_clear_invalid');
        }
        return json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
    }
    private function terminationIntent(): ?array
    {
        if (!is_file('identity-terminate.json')) {
            return null;
        }
        $bytes = file_get_contents('identity-terminate.json', false, null, 0, 2049);
        if ($bytes === false || strlen($bytes) > 2048) {
            throw new RuntimeException('identity_terminate_invalid');
        }
        return json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
    }
}

/** 安装后公开发布契约；没有连接时合法QoS0返回零，缺失或伪造身份仍须先被拒绝。 */
function mqttIdentityPublish(): void
{
    $access = new IdentityTestAccess();
    $connect = new ConnectPacket();
    $connect->version = 5;
    $connect->clientId = 'identity-public-publish';
    $connect->username = (string) (getenv('MQTT_USERNAME') ?: 'example');
    $connect->password = (string) getenv('MQTT_PASSWORD');
    $identity = $access->authenticateIdentity($connect, '127.0.0.1', false);
    if ($identity === null) {
        throw new RuntimeException('identity_publish_authentication_failed');
    }
    $broker = new Broker($access, new BrokerOptions(allowPlaintext: true));
    $message = new \Type\Mqtt\Message('example/public-publish', "\0\xffpayload");
    $refused = [];
    foreach (['missing', 'principal', 'credential', 'version', 'method', 'topic'] as $case) {
        $candidate = match ($case) {
            'missing' => null,
            'principal' => new AccessIdentity('untrusted-display', $identity->credentialId, $identity->credentialVersion),
            'credential' => new AccessIdentity($identity->principalId, 'untrusted-display', $identity->credentialVersion),
            'version' => new AccessIdentity($identity->principalId, $identity->credentialId, $identity->credentialVersion + 1),
            'method' => new AccessIdentity($identity->principalId, $identity->credentialId, $identity->credentialVersion, 'unverified'),
            default => $identity,
        };
        $reason = 0;
        try {
            $broker->publish($connect, $case === 'topic' ? new \Type\Mqtt\Message('denied/topic', 'x') : $message, $candidate);
        } catch (\Type\Mqtt\ProtocolError $failure) {
            $reason = $failure->reason;
        }
        if ($reason !== 0x87) {
            throw new RuntimeException('identity_publish_rejection_failed_' . $case);
        }
        $refused[] = $case;
    }
    // 成功认证后修改展示文字不赋权也不撤权；第三参数身份仍是授权事实。
    $connect->clientId = 'untrusted-display';
    $connect->username = 'untrusted-display';
    $connect->password = null;
    if ($broker->publish($connect, $message, $identity) !== 0) {
        throw new RuntimeException('identity_publish_empty_route_invalid');
    }
    $legacy = new Broker(new LegacyIdentityTestAccess(), new BrokerOptions(allowPlaintext: true));
    if ($legacy->publish($connect, $message) !== 0) {
        throw new RuntimeException('identity_publish_legacy_call_invalid');
    }
    $broker->stop();
    $legacy->stop();
    echo json_encode(['status' => 'passed', 'refused' => $refused, 'authenticated' => true, 'legacy_two_arguments' => true], JSON_THROW_ON_ERROR), "\n";
}
PHP;
}

/** 身份场景复用真实Broker入口；启动失败也回收本次进程。 */
function mqttIdentityStart(string $consumer, array $command, array $environment, int $port, ?string $certificate, string $node = 'default', bool $clustered = false): Process
{
    $environment['MQTT_CERTIFICATE'] = $certificate ?? '';
    $environment['MQTT_PRIVATE_KEY'] = $certificate === null ? '' : $consumer . '/private.pem';
    $broker = new Process([...$command, '--port=' . $port, '--node-id=' . $node, ...($certificate === null ? ['--plaintext'] : []), ...($clustered ? ['--clustered'] : [])], $consumer, $environment);
    try {
        mqttUntil(function () use ($broker, $port, $certificate): bool {
            expect($broker->running(), '兼容Broker提前退出：' . $broker->stderr());
            try {
                $probe = mqttSocket($port, $certificate);
                fclose($probe);
                return true;
            } catch (RuntimeException) {
                return false;
            }
        }, '兼容Broker未开始监听', 12.0);
        return $broker;
    } catch (Throwable $failure) {
        $broker->stop(12);
        throw $failure;
    }
}

/** 正常停止既检查实际退出，也检查网络、持久工作和会话清理。 */
function mqttIdentityStop(Process $broker): array
{
    $stopped = $broker->stop(12);
    expect($stopped->successful() && $stopped->stderr === '', '兼容Broker没有完整退出：' . $stopped->stderr);
    $statistics = json_decode($stopped->stdout, true, 32, JSON_THROW_ON_ERROR);
    foreach (['connections', 'subscriptions', 'bufferedBytes', 'pendingCommits', 'closingSessions', 'quarantinedCommits', 'pendingFences'] as $field) {
        expect($statistics[$field] === 0, '兼容场景退出未回收：' . $field);
    }
    expect($statistics['unknownCommits'] === 0, '兼容场景正常停止产生未知提交');
    return $statistics;
}

/** 原AccessPolicy四组合及迁移后的拒绝；观察真实客户端与同步备库中的必要持久事实。 */
function mqttIdentityCompatibilityCases(string $root, string $consumer, array $command, array $environment, PDO $standby): array
{
    require_once $root . '/tests/mqtt-cluster.php';
    $broker = null;
    $client = null;
    $query = null;
    $report = ['status' => 'running', 'runs' => []];
    $environment = array_replace($environment, ['MQTT_USERNAME' => 'example', 'MQTT_PASSWORD' => 'mqtt-test-secret',
        'MQTT_CREDENTIAL_ID' => 'example-account', 'MQTT_CREDENTIAL_VERSION' => '1', 'MQTT_TEST_PRINCIPAL' => 'example']);
    try {
        foreach ([false, true] as $tls) {
            foreach ([4, 5] as $version) {
                $port = mqttClusterPort();
                $certificate = $tls ? $consumer . '/certificate.pem' : null;
                $clientId = 'identity-compatibility-' . (int) $tls . '-' . $version;
                $query = $standby->prepare('SELECT id, principal, access_identity, subscriptions, '
                    . '(SELECT COUNT(*) FROM type_mqtt_deliveries d WHERE d.session_id = s.id AND d.state = \'pending\') AS pending '
                    . 'FROM type_mqtt_sessions s WHERE client_id = ?');
                $snapshot = null;
                $sessionId = '';
                $run = ['protocol' => $version, 'transport' => $tls ? 'tls' : 'tcp', 'statistics' => []];
                foreach (['legacy', 'migrated', 'downgraded'] as $phase) {
                    $environment['MQTT_TEST_LEGACY_POLICY'] = $phase === 'migrated' ? '0' : '1';
                    $broker = mqttIdentityStart($consumer, $command, $environment, $port, $certificate);
                    foreach ($phase === 'legacy' ? ['identity-seed', 'identity-cycle'] : [$phase === 'migrated' ? 'identity-cycle' : 'identity-downgrade'] as $mode) {
                        $client = new Process(['node', $root . '/tests/mqtt-standard-client.mjs', $consumer, (string) $port, $certificate ?? 'plain',
                            $mode, (string) $version, $clientId], $consumer, $environment);
                        $checked = $client->wait(25);
                        expect($checked->successful() && $checked->stderr === '', '旧调用兼容/迁移拒绝失败：' . $phase . ' ' . $checked->stderr);
                        $client = null;
                    }
                    $run['statistics'][] = mqttIdentityStop($broker);
                    $broker = null;
                    $query->execute([$clientId]);
                    $row = $query->fetch(PDO::FETCH_ASSOC);
                    expect(is_array($row) && (int) $row['pending'] === 2, '兼容场景丢失待恢复会话或积压');
                    if ($phase === 'legacy') {
                        expect($row['access_identity'] === null, '原AccessPolicy被隐式猜测为新身份');
                        $sessionId = $row['id'];
                    } elseif ($phase === 'migrated') {
                        expect($row['id'] === $sessionId && json_decode($row['access_identity'], true) == [
                            'principal_id' => 'example', 'credential_id' => 'example-account', 'credential_version' => 1, 'authentication_method' => 'connect',
                        ], '重新认证没有保留旧会话并建立准确身份');
                        $snapshot = $row;
                    } else {
                        expect($row === $snapshot, '拒绝旧策略回退后改变了原身份、订阅或待交付数据');
                    }
                }
                $run['legacy_tcp_tls'] = true;
                $run['migration_preserved_session'] = true;
                $run['downgrade_rejected_without_state_change'] = true;
                if ($tls && $version === 5) {
                    $environment['MQTT_TEST_LEGACY_POLICY'] = '0';
                    $environment['MQTT_TEST_PRINCIPAL'] = 'other-principal';
                    $broker = mqttIdentityStart($consumer, $command, $environment, $port, $certificate);
                    $client = new Process(['node', $root . '/tests/mqtt-standard-client.mjs', $consumer, (string) $port, $certificate,
                        'identity-foreign', '5', $clientId], $consumer, $environment);
                    $checked = $client->wait(25);
                    expect($checked->successful() && $checked->stderr === '', '跨主体隔离失败：' . $checked->stderr);
                    $client = null;
                    $run['statistics'][] = mqttIdentityStop($broker);
                    $broker = null;
                    $query->execute([$clientId]);
                    $row = $query->fetch(PDO::FETCH_ASSOC);
                    expect(is_array($row) && $row['id'] !== $sessionId
                        && json_decode($row['access_identity'], true)['principal_id'] === 'other-principal', '新主体继承了旧会话所有权');
                    expect((int) $row['pending'] === 0, '新主体会话仍有待恢复的旧积压');
                    $run['different_principal_fresh_session'] = true;
                }
                $terminated = (new Process([...$command, '--terminate-session=' . $clientId, '--actor=identity-compatibility'], $consumer, $environment))->wait(12);
                expect($terminated->successful() && $terminated->stderr === '', '兼容会话清理命令失败');
                $ended = json_decode($terminated->stdout, true, 16, JSON_THROW_ON_ERROR);
                expect($ended['state'] === 'committed' && $ended['released'] && $ended['value']['terminated'] && !$ended['value']['waiting'], '兼容会话清理缺少同步证明');
                $report['runs'][] = $run;
            }
        }
        $report['status'] = 'passed';
        return $report;
    } catch (Throwable $failure) {
        $report['status'] = 'failed';
        $report['failure'] = $failure->getMessage();
        throw $failure;
    } finally {
        $query = null;
        $cleanup = [];
        foreach (['client' => $client, 'broker' => $broker] as $name => $process) {
            if ($process !== null) {
                try {
                    $process->stop(12);
                } catch (Throwable $failure) {
                    $cleanup[] = $name . ': ' . $failure->getMessage();
                }
            }
        }
        if ($cleanup !== []) {
            $report['cleanup_failures'] = $cleanup;
            $report['status'] = 'failed';
        }
        file_put_contents($consumer . '/identity-compatibility.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        expect($cleanup === [], '兼容场景资源回收失败：' . implode('; ', $cleanup));
    }
}

/** 冻结旧节点后等实际worker退出，再硬停止；返回可核对的进程清理事实。 */
function mqttIdentityKill(Process $broker): array
{
    $pid = $broker->pid();
    expect(posix_kill($pid, SIGSTOP), '无法暂停本次测试的旧Broker');
    try {
        $workers = [];
        mqttUntil(static function () use ($pid, &$workers): bool {
            $workers = unixProcessStates($pid);
            foreach ($workers as $worker) {
                if (!str_starts_with($worker['state'], 'Z')) {
                    return false;
                }
            }
            return true;
        }, '旧Broker仍有执行中的worker，不能登记隔离', 12.0);
        expect(posix_kill($pid, SIGKILL), '无法硬停止本次测试的旧Broker');
        $killed = $broker->wait(5);
        expect(!$killed->successful() && !$killed->timedOut && !$broker->running(), '旧Broker没有实际硬退出');
        $owned = array_fill_keys([$pid, ...array_keys($workers)], true);
        mqttUntil(static fn (): bool => array_intersect_key(unixProcessStates(), $owned) === [], '旧Broker或其worker仍存在', 5.0);
        return ['pid' => $pid, 'workers' => array_keys($workers), 'processes_absent' => true,
            'exit_code' => $killed->exitCode, 'stdout' => $killed->stdout, 'stderr' => $killed->stderr];
    } catch (Throwable $failure) {
        if ($broker->running()) {
            posix_kill($pid, SIGCONT);
        }
        throw $failure;
    }
}

/** 真实旧调用产生会话、遗嘱及未完成fence；停机恢复旧表结构后从安装入口升级，不改写认证事实。 */
function mqttIdentityUpgradeCases(string $root, string $consumer, array $command, array $workerCommand, array $environment, PDO $primary, PDO $standby): array
{
    require_once $root . '/tests/mqtt-cluster.php';
    require_once $root . '/tests/mqtt-will.php';
    require_once $root . '/tests/mqtt-retained.php';
    $processes = [];
    $sockets = [];
    $report = ['status' => 'running', 'hard_stops' => []];
    $environment = array_replace($environment, ['MQTT_USERNAME' => 'example', 'MQTT_PASSWORD' => 'mqtt-test-secret',
        'MQTT_CREDENTIAL_ID' => 'example-account', 'MQTT_CREDENTIAL_VERSION' => '1', 'MQTT_TEST_PRINCIPAL' => 'example',
        'MQTT_TEST_LEGACY_POLICY' => '1', 'MQTT_TEST_DENIED_TOPIC' => '']);
    try {
        $ports = ['identity-old-a' => mqttClusterPort(), 'identity-old-b' => mqttClusterPort()];
        foreach ($ports as $node => $port) {
            $processes[$node] = mqttIdentityStart($consumer, $command, $environment, $port, null, $node, true);
        }
        foreach (['allowed', 'denied'] as $kind) {
            $socket = mqttSocket($ports['identity-old-a']);
            $sockets[] = $socket;
            mqttWrite($socket, mqttWillConnect(5, 'identity-old-will-' . $kind, 'example/identity-old-will/' . $kind, "\0\xfflegacy-" . $kind, 1, true, 0, 86400));
            mqttAck($socket, 5);
        }
        $old = mqttSocket($ports['identity-old-a']);
        $sockets[] = $old;
        mqttWrite($old, mqttSessionConnect(5, 'identity-old-fence'));
        mqttSessionAck($old, false);
        $runs = $standby->query('SELECT node_id, run_id FROM type_mqtt_nodes ORDER BY node_id')->fetchAll(PDO::FETCH_KEY_PAIR);
        $report['hard_stops'][] = mqttIdentityKill($processes['identity-old-a']);
        unset($processes['identity-old-a']);
        $next = mqttSocket($ports['identity-old-b']);
        $sockets[] = $next;
        mqttWrite($next, mqttSessionConnect(5, 'identity-old-fence'));
        mqttUntil(static fn (): bool => (int) $standby->query("SELECT COUNT(*) FROM type_mqtt_fences WHERE client_id = 'identity-old-fence'")->fetchColumn() === 1, '真实旧所有者没有产生待完成fence');
        mqttWillSilence($next, 0.2);
        $report['hard_stops'][] = mqttIdentityKill($processes['identity-old-b']);
        unset($processes['identity-old-b']);
        foreach ($sockets as $socket) {
            fclose($socket);
        }
        $sockets = [];
        $before = [];
        foreach (['type_mqtt_sessions', 'type_mqtt_wills', 'type_mqtt_fences'] as $table) {
            expect(
                (int) $primary->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() > 0
                && (int) $primary->query('SELECT COUNT(*) FROM ' . $table . ' WHERE access_identity IS NOT NULL')->fetchColumn() === 0,
                '旧表装置缺少实际数据或包含已迁移身份：' . $table
            );
            $before[$table] = $primary->query("SELECT (to_jsonb(t) - 'access_identity')::text AS row FROM " . $table . ' t ORDER BY 1')->fetchAll(PDO::FETCH_COLUMN);
        }
        // 仅去掉旧契约从未写入的NULL扩展列，重现缺少扩展列的表结构，原网络状态逐行保持不变。
        $primary->beginTransaction();
        foreach (array_keys($before) as $table) {
            $primary->exec('ALTER TABLE ' . $table . ' DROP COLUMN access_identity');
        }
        $primary->commit();
        expect((int) $standby->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_name IN ('type_mqtt_sessions', 'type_mqtt_wills', 'type_mqtt_fences') AND column_name = 'access_identity'")->fetchColumn() === 0, '旧表结构没有同步到备库');
        foreach ([1, 2] as $migration) {
            $installation = (new Process([...$command, '--install-store'], $consumer, $environment))->wait(12);
            expect($installation->successful() && $installation->stderr === '', '既有数据升级/重复安装失败：' . $installation->stderr);
            $installed = json_decode($installation->stdout, true, 16, JSON_THROW_ON_ERROR);
            expect($installed['state'] === 'committed' && $installed['released'], '旧表升级没有取得同步提交证明');
            foreach ($before as $table => $rows) {
                expect(
                    $standby->query("SELECT (to_jsonb(t) - 'access_identity')::text AS row FROM " . $table . ' t ORDER BY 1')->fetchAll(PDO::FETCH_COLUMN) === $rows
                    && (int) $standby->query('SELECT COUNT(*) FROM ' . $table . ' WHERE access_identity IS NOT NULL')->fetchColumn() === 0,
                    '升级改写了旧持久事实或猜测了身份：' . $table
                );
            }
        }
        $report['old_tables_preserved'] = array_map('count', $before);
        $identity = ['principal_id' => 'example', 'credential_id' => 'example-account', 'credential_version' => 1, 'authentication_method' => 'connect'];
        $intent = ['action' => 'session_terminate', 'client_id' => 'identity-old-fence', 'principal' => 'untrusted-display',
            'actor' => 'identity-upgrade', 'access_identity' => $identity];
        $unmatched = mqttClusterRequest($consumer, $workerCommand, $environment, $intent);
        expect($unmatched['state'] === 'committed' && !$unmatched['value']['terminated'] && !$unmatched['value']['waiting'], '旧NULL身份被展示文字或新快照隐式映射');
        $intent['principal'] = 'example';
        $waiting = mqttClusterRequest($consumer, $workerCommand, $environment, $intent);
        expect($waiting['state'] === 'committed' && $waiting['released'] && $waiting['value']['terminated'] && $waiting['value']['waiting'], '旧会话撤权没有保留待完成fence');
        foreach ($runs as $node => $runId) {
            $fenced = mqttClusterRequest($consumer, $workerCommand, $environment, ['action' => 'node_fence', 'node_id' => $node, 'node_run_id' => $runId,
                'actor' => 'identity-upgrade', 'proof_ref' => 'owned_process_exited_children_absent']);
            expect($fenced['state'] === 'committed' && $fenced['released'] && $fenced['value']['fenced'], '真实旧进程隔离没有同步登记');
        }
        $finished = mqttClusterRequest($consumer, $workerCommand, $environment, $intent);
        expect($finished['state'] === 'committed' && $finished['released'] && !$finished['value']['terminated'] && !$finished['value']['waiting'], '旧fence没有通过精确节点隔离完成');
        $report['legacy_fence'] = ['unmatched_display_preserved' => true, 'waited_for_exact_node_isolation' => true, 'completed' => true];
        $environment['MQTT_TEST_LEGACY_POLICY'] = '0';
        $environment['MQTT_TEST_DENIED_TOPIC'] = 'example/identity-old-will/denied';
        $port = mqttClusterPort();
        $processes['identity-new'] = mqttIdentityStart($consumer, $command, $environment, $port, null, 'identity-new', true);
        mqttUntil(static fn (): bool => (int) $standby->query("SELECT COUNT(*) FROM type_mqtt_will_audit WHERE client_id IN ('identity-old-will-allowed', 'identity-old-will-denied')")->fetchColumn() === 2, '旧NULL身份遗嘱没有在新策略下恢复处理');
        $outcomes = $standby->query("SELECT client_id, outcome FROM type_mqtt_will_audit WHERE client_id IN ('identity-old-will-allowed', 'identity-old-will-denied') ORDER BY client_id")->fetchAll(PDO::FETCH_KEY_PAIR);
        expect($outcomes === ['identity-old-will-allowed' => 'published', 'identity-old-will-denied' => 'denied'], '旧遗嘱没有按当前旧授权契约允许/拒绝');
        $subscriber = mqttWillSubscriber($port, 'identity-old-will-reader', 'example/identity-old-will/#');
        $sockets[] = $subscriber;
        $message = mqttRetainedRead($subscriber, 5);
        expect($message['topic'] === 'example/identity-old-will/allowed' && $message['payload'] === "\0\xfflegacy-allowed" && $message['retain'], '旧遗嘱恢复改变原二进制载荷或保留语义');
        mqttRetainedComplete($subscriber, $message);
        mqttWillSilence($subscriber, 0.3);
        expect((int) $standby->query("SELECT COUNT(*) FROM type_mqtt_retained WHERE topic = 'example/identity-old-will/denied'")->fetchColumn() === 0, '被拒绝的旧遗嘱进入了保留存储');
        $restored = mqttSocket($port);
        $sockets[] = $restored;
        mqttWrite($restored, mqttSessionConnect(5, 'identity-old-will-allowed'));
        mqttSessionAck($restored, true);
        $snapshot = json_decode($standby->query("SELECT access_identity FROM type_mqtt_sessions WHERE client_id = 'identity-old-will-allowed'")->fetchColumn(), true);
        expect($snapshot == $identity, '既有旧表会话没有在真实重新认证后迁移身份');
        foreach ($sockets as $socket) {
            mqttWrite($socket, "\xe0\0");
            expect(mqttRead($socket) === '', '旧状态恢复客户端没有正常关闭');
            fclose($socket);
        }
        $sockets = [];
        $report['statistics'] = mqttIdentityStop($processes['identity-new']);
        unset($processes['identity-new']);
        $report['legacy_wills'] = ['allowed_binary_retained' => true, 'current_denial_enforced' => true, 'session_reauthenticated' => true];
        $report['status'] = 'passed';
        return $report;
    } catch (Throwable $failure) {
        $report['status'] = 'failed';
        $report['failure'] = $failure->getMessage();
        throw $failure;
    } finally {
        if ($primary->inTransaction()) {
            $primary->rollBack();
        }
        foreach ($sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        $cleanup = [];
        foreach ($processes as $node => $process) {
            try {
                $stopped = $process->stop(12);
                $report['cleanup'][$node] = ['exit_code' => $stopped->exitCode, 'stdout' => $stopped->stdout, 'stderr' => $stopped->stderr];
            } catch (Throwable $failure) {
                $cleanup[] = $node . ': ' . $failure->getMessage();
            }
        }
        if ($cleanup !== []) {
            $report['cleanup_failures'] = $cleanup;
            $report['status'] = 'failed';
        }
        file_put_contents($consumer . '/identity-upgrade.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        expect($cleanup === [], '旧状态升级场景资源回收失败：' . implode('; ', $cleanup));
    }
}

/** 双版本TCP/TLS标准客户端、真实同步主备、原调用迁移和迟到凭据撤权。 */
function mqttIdentityCases(string $root, string $consumer, array $command, array $workerCommand, array $environment): array
{
    // 异常栈不能持有子场景的PDO参数，否则外层finally释放变量后仍会阻止数据库正常退出。
    ini_set('zend.exception_ignore_args', '1');
    require_once $root . '/tests/native-database.php';
    require_once $root . '/tests/postgres-sync.php';
    require_once $root . '/tests/mqtt-qos1.php';
    $tools = NativeDatabase::tools('pgsql', (string) (getenv('TYPE_PGSQL_TOOLS') ?: $root . '/.cache/macos-libpq/17.11'));
    $database = new NativeDatabase($consumer . '/identity-primary', 'pgsql', $tools);
    $sync = null;
    $broker = null;
    $client = null;
    $primary = null;
    $standby = null;
    $query = null;
    $report = ['status' => 'running', 'runs' => []];
    try {
        $sync = new PostgresSync($database, $consumer . '/identity-standby', $tools);
        $environment = array_replace($environment, $database->environment());
        $environment['MQTT_WORKER_COMMAND'] = json_encode($workerCommand, JSON_THROW_ON_ERROR);
        $installation = (new Process([...$command, '--install-store'], $consumer, $environment))->wait(12);
        expect($installation->successful() && $installation->stderr === '', '身份存储安装失败：' . $installation->stderr);
        $installed = json_decode($installation->stdout, true, 16, JSON_THROW_ON_ERROR);
        expect($installed['state'] === 'committed' && $installed['released'], '身份迁移未取得同步证明');
        $api = (new Process([...$command, '--identity-publish'], $consumer, $environment))->wait(12);
        expect($api->successful() && $api->stderr === '', '公开发布身份契约失败：' . $api->stderr);
        $report['public_publish'] = json_decode($api->stdout, true, 16, JSON_THROW_ON_ERROR);
        $primary = $sync->connection();
        $standby = $sync->standby();
        foreach ([false, true] as $tls) {
            foreach ([4, 5] as $version) {
                foreach (['identity-invalidation.json', 'identity-completed', 'identity-continue'] as $file) {
                    if (is_file($consumer . '/' . $file)) {
                        unlink($consumer . '/' . $file);
                    }
                }
                $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
                expect(is_resource($listener), '身份场景无法分配监听');
                $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
                fclose($listener);
                $certificate = $tls ? $consumer . '/certificate.pem' : null;
                $environment['MQTT_CERTIFICATE'] = $certificate ?? '';
                $environment['MQTT_PRIVATE_KEY'] = $tls ? $consumer . '/private.pem' : '';
                $clientId = 'identity-' . ($tls ? 'tls' : 'tcp') . '-' . $version;
                $legacy = !$tls && $version === 4;
                $oldIdentity = ['principal_id' => 'example', 'credential_id' => 'example-account', 'credential_version' => 1, 'authentication_method' => 'connect'];
                $newIdentity = ['principal_id' => 'example', 'credential_id' => $version === 4 ? 'example-account' : 'rotated-account',
                    'credential_version' => 2, 'authentication_method' => 'connect'];
                $sessionId = '';
                $generationStatistics = [];
                foreach ([1, 2] as $generation) {
                    $environment['MQTT_TEST_LEGACY_POLICY'] = $legacy && $generation === 1 ? '1' : '0';
                    $environment['MQTT_USERNAME'] = $generation === 2 && $version === 5 ? 'renamed-example' : 'example';
                    $environment['MQTT_PASSWORD'] = $generation === 1 ? 'mqtt-test-secret' : 'mqtt-rotated-secret';
                    $environment['MQTT_CREDENTIAL_ID'] = $generation === 1 ? 'example-account' : $newIdentity['credential_id'];
                    $environment['MQTT_CREDENTIAL_VERSION'] = (string) $generation;
                    $broker = new Process([...$command, '--port=' . $port, ...($tls ? [] : ['--plaintext'])], $consumer, $environment);
                    mqttUntil(function () use ($broker, $port, $certificate): bool {
                        expect($broker->running(), '身份Broker提前退出：' . $broker->stderr());
                        try {
                            $probe = mqttSocket($port, $certificate);
                            fclose($probe);
                            return true;
                        } catch (RuntimeException) {
                            return false;
                        }
                    }, '身份Broker未开始监听', 12.0);
                    $client = new Process(['node', $root . '/tests/mqtt-standard-client.mjs', $consumer, (string) $port, $certificate ?? 'plain',
                        $generation === 1 ? 'identity-seed' : 'identity-resume', (string) $version, $clientId], $consumer, $environment);
                    if ($generation === 1) {
                        $seeded = $client->wait(20);
                        expect($seeded->successful() && $seeded->stderr === '', '标准身份会话准备失败：' . $seeded->stderr);
                        $query = $standby->prepare('SELECT id, access_identity FROM type_mqtt_sessions WHERE client_id = ?');
                        $query->execute([$clientId]);
                        $row = $query->fetch(PDO::FETCH_ASSOC);
                        expect(is_array($row) && ($legacy ? $row['access_identity'] === null : json_decode($row['access_identity'], true) == $oldIdentity), '认证后的持久身份不符');
                        $sessionId = $row['id'];
                    } else {
                        mqttUntil(fn (): bool => str_contains($client->stdout(), 'identity-ready'), '换凭据没有恢复标准客户端会话：' . $client->stderr(), 20.0);
                        $query = $standby->prepare('SELECT id, access_identity FROM type_mqtt_sessions WHERE client_id = ?');
                        $query->execute([$clientId]);
                        $row = $query->fetch(PDO::FETCH_ASSOC);
                        expect(is_array($row) && $row['id'] === $sessionId && json_decode($row['access_identity'], true) == $newIdentity, '换凭据改变主体会话或未更新代次');
                        $oldIntent = ['id' => bin2hex(random_bytes(16)), 'client_id' => $clientId, 'principal' => 'example', 'actor' => 'identity-test', 'access_identity' => $oldIdentity];
                        file_put_contents($consumer . '/identity-invalidation.json', json_encode($oldIntent, JSON_THROW_ON_ERROR));
                        mqttUntil(fn (): bool => is_file($consumer . '/identity-completed') && file_get_contents($consumer . '/identity-completed') === $oldIntent['id'], '迟到旧凭据未完成精确清理', 12.0);
                        file_put_contents($consumer . '/identity-continue', 'continue');
                        mqttUntil(fn (): bool => str_contains($client->stdout(), 'identity-still-active'), '迟到旧撤权影响新连接：' . $client->stderr(), 12.0);
                        $newIntent = ['id' => bin2hex(random_bytes(16)), 'client_id' => $clientId, 'principal' => 'untrusted-display', 'actor' => 'identity-test', 'access_identity' => $newIdentity];
                        file_put_contents($consumer . '/identity-invalidation.json', json_encode($newIntent, JSON_THROW_ON_ERROR));
                        $closed = $client->wait(15);
                        expect($closed->successful() && $closed->stderr === '', '精确凭据撤权或错误凭据拒绝失败：' . $closed->stderr);
                        mqttUntil(fn (): bool => file_get_contents($consumer . '/identity-completed') === $newIntent['id'], '新凭据撤权未取得完成证明', 12.0);
                        $query->execute([$clientId]);
                        expect($query->fetch() === false, '精确撤权完成后仍保留目标会话');
                    }
                    $stopped = $broker->stop(12);
                    expect($stopped->successful() && $stopped->stderr === '', '身份Broker没有完整退出：' . $stopped->stderr);
                    $statistics = json_decode($stopped->stdout, true, 32, JSON_THROW_ON_ERROR);
                    foreach (['connections', 'subscriptions', 'bufferedBytes', 'pendingCommits', 'closingSessions', 'quarantinedCommits'] as $field) {
                        expect($statistics[$field] === 0, '身份场景退出未回收：' . $field);
                    }
                    expect($statistics['unknownCommits'] === 0, '身份场景正常停止产生未知提交');
                    $generationStatistics[$generation] = $statistics;
                    $broker = null;
                    $client = null;
                }
                $report['runs'][] = ['protocol' => $version, 'transport' => $tls ? 'tls' : 'tcp', 'legacy_migrated' => $legacy,
                    'persistent_identity' => true, 'old_invalidation_isolated' => true, 'exact_revocation' => true,
                    'statistics' => $statistics, 'generation_statistics' => $generationStatistics];
            }
        }
        foreach (['identity-invalidation.json', 'identity-completed', 'identity-continue'] as $file) {
            if (is_file($consumer . '/' . $file)) {
                unlink($consumer . '/' . $file);
            }
        }
        $report['compatibility'] = mqttIdentityCompatibilityCases($root, $consumer, $command, $environment, $standby);
        $report['resources'] = mqttResourceCases($root, $consumer, $command, $workerCommand, $environment, $primary);
        $report['disconnect'] = mqttAdministrativeDisconnectCases($root, $consumer, $command, $workerCommand, $environment, $standby);
        $report['upgrade'] = mqttIdentityUpgradeCases($root, $consumer, $command, $workerCommand, $environment, $primary, $standby);
        $report['status'] = 'passed';
        $report['replication'] = $sync->evidence();
        return $report;
    } catch (Throwable $failure) {
        $report['status'] = 'failed';
        $report['failure'] = $failure->getMessage();
        throw $failure;
    } finally {
        $cleanupFailures = [];
        foreach (['client' => $client, 'broker' => $broker] as $name => $process) {
            if ($process !== null) {
                try {
                    $stopped = $process->stop(12);
                    $report['cleanup'][$name] = ['exit_code' => $stopped->exitCode, 'stdout' => $stopped->stdout, 'stderr' => $stopped->stderr];
                } catch (Throwable $failure) {
                    $cleanupFailures[] = $name . ': ' . $failure->getMessage();
                }
            }
        }
        // PDOStatement同样持有连接，须在备库smart shutdown前释放。
        $query = null;
        $primary = null;
        $standby = null;
        foreach (['standby' => $sync, 'primary' => $database] as $name => $instance) {
            try {
                $instance?->close();
            } catch (Throwable $failure) {
                $cleanupFailures[] = $name . ': ' . $failure->getMessage();
            }
        }
        if ($cleanupFailures !== []) {
            $report['status'] = 'failed';
            $report['cleanup_failures'] = $cleanupFailures;
        }
        file_put_contents($consumer . '/identity.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        foreach (['identity-invalidation.json', 'identity-completed', 'identity-continue', 'identity-disconnect.json', 'identity-disconnected', 'identity-terminate.json', 'identity-terminated', 'identity-clear.json', 'identity-cleared'] as $file) {
            if (is_file($consumer . '/' . $file)) {
                unlink($consumer . '/' . $file);
            }
        }
        expect($cleanupFailures === [], '身份场景资源回收失败：' . implode('; ', $cleanupFailures));
    }
}

/** 管理断开发送 MQTT 5 0x98，保留仍有效持久会话；迟到旧 owner 不能打断新连接。 */
function mqttAdministrativeDisconnectCases(string $root, string $consumer, array $command, array $workerCommand, array $environment, PDO $standby): array
{
    require_once $root . '/tests/mqtt-cluster.php';
    $environment = array_replace($environment, ['MQTT_USERNAME' => 'example', 'MQTT_PASSWORD' => 'mqtt-test-secret',
        'MQTT_CREDENTIAL_ID' => 'example-account', 'MQTT_CREDENTIAL_VERSION' => '1', 'MQTT_TEST_PRINCIPAL' => 'example',
        'MQTT_TEST_LEGACY_POLICY' => '0', 'MQTT_TEST_DENIED_TOPIC' => '', 'MQTT_TEST_RESOURCE_SCOPE' => '', 'MQTT_TEST_RESOURCE_OBSERVER' => '1']);
    foreach (['identity-disconnect.json', 'identity-disconnected', 'identity-resource-observations.jsonl'] as $file) {
        if (is_file($consumer . '/' . $file)) {
            unlink($consumer . '/' . $file);
        }
    }
    $broker = null;
    $socket = null;
    $port = mqttClusterPort();
    try {
        $broker = mqttIdentityStart($consumer, $command, $environment, $port, null);
        $socket = mqttSocket($port);
        mqttWrite($socket, mqttSessionConnect(5, 'disconnect-admin'));
        mqttSessionAck($socket, false);
        mqttWrite($socket, mqttSubscription(5, 'example/disconnect-keep', 1, 1));
        expect(mqttRead($socket) === mqttSubscriptionAck(5, "\x01"), '管理断开前订阅失败');
        $owner = null;
        mqttUntil(function () use ($consumer, &$owner): bool {
            if (!is_file($consumer . '/identity-resource-observations.jsonl')) {
                return false;
            }
            foreach (file($consumer . '/identity-resource-observations.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $event = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
                if (($event['event'] ?? '') === 'connected' && ($event['resource']['client_id'] ?? '') === 'disconnect-admin') {
                    $owner = $event['resource'];
                    return true;
                }
            }
            return false;
        }, '管理断开没有真实连接观察', 8.0);
        expect(is_array($owner) && $owner['durable'] && $owner['session_generation'] > 0, '管理断开目标不是持久会话');
        $intent = ['id' => bin2hex(random_bytes(16)), 'owner_id' => $owner['owner_id'], 'session_id' => $owner['session_id'],
            'session_generation' => $owner['session_generation'], 'actor' => 'identity-test'];
        file_put_contents($consumer . '/identity-disconnect.json', json_encode($intent, JSON_THROW_ON_ERROR));
        expect(mqttRead($socket) === "\xe0\x02\x98\x00", '管理断开不是 MQTT 5 0x98');
        fclose($socket);
        $socket = null;
        mqttUntil(fn (): bool => is_file($consumer . '/identity-disconnected')
            && file_get_contents($consumer . '/identity-disconnected') === $intent['id'] . ':disconnected', '管理断开未取得完成证明', 12.0);
        $query = $standby->prepare('SELECT owner_id, expiry FROM type_mqtt_sessions WHERE client_id = ?');
        $query->execute(['disconnect-admin']);
        $session = $query->fetch(PDO::FETCH_ASSOC);
        expect(is_array($session) && $session['owner_id'] === null && (int) $session['expiry'] === 86400, '管理断开后持久会话被终止或期限被清零');
        $socket = mqttSocket($port);
        mqttWrite($socket, mqttSessionConnect(5, 'disconnect-admin'));
        mqttSessionAck($socket, true);
        $late = ['id' => bin2hex(random_bytes(16)), 'owner_id' => $owner['owner_id'], 'session_id' => $owner['session_id'],
            'session_generation' => $owner['session_generation'], 'actor' => 'identity-test'];
        file_put_contents($consumer . '/identity-disconnect.json', json_encode($late, JSON_THROW_ON_ERROR));
        mqttUntil(fn (): bool => is_file($consumer . '/identity-disconnected')
            && file_get_contents($consumer . '/identity-disconnected') === $late['id'] . ':missing', '迟到旧 owner 未按缺失完成', 12.0);
        mqttQuiet($socket);
        mqttWrite($socket, mqttSubscription(5, 'example/disconnect-keep', 1, 1));
        expect(mqttRead($socket) === mqttSubscriptionAck(5, "\x01"), '迟到旧断开影响了新连接');
        fclose($socket);
        $socket = null;
        mqttIdentityStop($broker);
        $broker = null;
        return ['status' => 'passed', 'reason_0x98' => true, 'session_kept' => true, 'late_owner_ignored' => true];
    } finally {
        if (is_resource($socket)) {
            fclose($socket);
        }
        if ($broker !== null) {
            mqttIdentityStop($broker);
        }
        foreach (['identity-disconnect.json', 'identity-disconnected', 'identity-resource-observations.jsonl'] as $file) {
            if (is_file($consumer . '/' . $file)) {
                unlink($consumer . '/' . $file);
            }
        }
        $cleaned = mqttClusterRequest($consumer, $workerCommand, $environment, ['action' => 'session_terminate', 'client_id' => 'disconnect-admin', 'actor' => 'disconnect-test']);
        expect($cleaned['state'] === 'committed', '管理断开测试会话清理未完成');
    }
}

/** 真实 MQTT 重认证绑定归属；资源 worker 的分页读取不推进任何协议状态。 */
function mqttResourceCases(string $root, string $consumer, array $command, array $workerCommand, array $environment, PDO $primary): array
{
    require_once $root . '/tests/mqtt-cluster.php';
    require_once $root . '/tests/mqtt-retained.php';
    $environment = array_replace($environment, ['MQTT_USERNAME' => 'example', 'MQTT_PASSWORD' => 'mqtt-test-secret',
        'MQTT_CREDENTIAL_ID' => 'example-account', 'MQTT_CREDENTIAL_VERSION' => '1', 'MQTT_TEST_PRINCIPAL' => 'example',
        'MQTT_TEST_LEGACY_POLICY' => '0', 'MQTT_TEST_DENIED_TOPIC' => '', 'MQTT_TEST_RESOURCE_SCOPE' => '', 'MQTT_TEST_RESOURCE_OBSERVER' => '1']);
    $authorization = ['all_metadata' => false, 'resource_scope' => 'iot:resource-a', 'topic_namespace' => 'example/resource-a'];
    $foreignAuthorization = ['all_metadata' => false, 'resource_scope' => 'iot:resource-b', 'topic_namespace' => 'example/resource-b'];
    $read = static function (array $request) use ($consumer, $workerCommand, $environment): array {
        $result = mqttClusterRequest($consumer, $workerCommand, $environment, $request);
        expect($result['state'] === 'committed' && $result['released'], '资源查询没有完整返回：' . json_encode($result));
        return $result['value'];
    };
    $broker = null;
    $sockets = [];
    $port = mqttClusterPort();
    try {
        $broker = mqttIdentityStart($consumer, $command, $environment, $port, null);
        $subscriber = mqttSocket($port);
        $sockets[] = $subscriber;
        mqttWrite($subscriber, mqttSessionConnect(5, 'resource-legacy'));
        mqttSessionAck($subscriber, false);
        foreach (['example/resource-a/one', 'example/resource-b/denied'] as $topic) {
            mqttWrite($subscriber, mqttSubscription(5, $topic, 1, 1));
            expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\x01"), '资源旧会话订阅失败');
        }
        mqttWrite($subscriber, "\xe0\0");
        expect(mqttRead($subscriber) === '', '资源旧会话没有正常断开');
        fclose($subscriber);
        $sockets = [];
        mqttIdentityStop($broker);
        $broker = null;
        $list = ['action' => 'resource_list', 'resource' => 'sessions', 'authorization' => $authorization, 'filters' => ['client_id' => 'resource-legacy']];
        expect($read($list)['items'] === [], '未归属会话泄漏给租户');
        $legacy = $read([...$list, 'authorization' => ['all_metadata' => true]])['items'];
        expect(count($legacy) === 1 && $legacy[0]['resource_scope'] === null, '全局元数据查询丢失旧会话');
        $environment['MQTT_TEST_RESOURCE_SCOPE'] = $authorization['resource_scope'];
        $environment['MQTT_TEST_DENIED_TOPIC'] = 'example/resource-b/denied';
        $broker = mqttIdentityStart($consumer, $command, $environment, $port, null);
        $subscriber = mqttSocket($port);
        $sockets[] = $subscriber;
        mqttWrite($subscriber, mqttSessionConnect(5, 'resource-legacy'));
        mqttSessionAck($subscriber, true);
        $bound = $read($list)['items'];
        expect(count($bound) === 1 && $bound[0]['id'] === $legacy[0]['id'], '真实重认证没有原子绑定旧会话');
        $subscriptions = $read(['action' => 'resource_list', 'resource' => 'subscriptions', 'authorization' => $authorization,
            'filters' => ['session_id' => $legacy[0]['id']]])['items'];
        expect(count($subscriptions) === 1 && $subscriptions[0]['filter'] === 'example/resource-a/one', '重新授权后的订阅投影不准确');
        foreach (['example/resource-a/#', '$share/resource-group/example/resource-a/#'] as $topic) {
            mqttWrite($subscriber, mqttSubscription(5, $topic, 1, 1));
            expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\x01"), '资源普通或共享订阅失败');
        }
        mqttWrite($subscriber, mqttSubscription(5, 'example/resource-a/transient', 1, 1));
        expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\x01"), '资源临时订阅未生效');
        mqttWrite($subscriber, mqttSubscription(5, 'example/resource-a/transient', 1, 0, false));
        expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\x00", 1, false), '资源取消订阅未生效');
        mqttWrite($subscriber, "\xe0\0");
        expect(mqttRead($subscriber) === '', '资源订阅者没有正常断开');
        fclose($subscriber);
        $sockets = [];
        foreach (['resource-second', 'resource-third'] as $clientId) {
            $socket = mqttSocket($port);
            $sockets[] = $socket;
            mqttWrite($socket, mqttSessionConnect(5, $clientId));
            mqttSessionAck($socket, false);
            mqttWrite($socket, "\xe0\0");
            expect(mqttRead($socket) === '', '资源分页会话没有正常断开');
            fclose($socket);
        }
        $sockets = [];
        $publisher = mqttSocket($port);
        $sockets[] = $publisher;
        mqttWrite($publisher, mqttConnect(5, 'resource-publisher'));
        mqttAck($publisher, 5);
        $packetId = 1;
        foreach (['example/resource-a/one', 'example/resource-a', 'example/resource-b/hidden'] as $topic) {
            mqttWrite($publisher, mqttRetainedPacket(5, $topic, 'resource-payload-secret', 1, $packetId));
            expect(mqttRead($publisher) === "\x40\x02" . pack('n', $packetId), '资源准备发布没有取得 PUBACK');
            $packetId++;
        }
        mqttWrite($publisher, "\xe0\0");
        expect(mqttRead($publisher) === '', '资源发布者没有正常断开');
        fclose($publisher);
        $sockets = [];
        mqttIdentityStop($broker);
        $broker = null;
        // 通过既有 worker 契约保存另一归属的离线状态，使范围筛选不能靠空数据通过。
        $foreign = ['action' => 'session_open', 'session_id' => bin2hex(random_bytes(16)), 'owner_id' => bin2hex(random_bytes(16)),
            'client_id' => 'resource-foreign', 'node_id' => 'default', 'protocol' => 5, 'clean_start' => true, 'expiry' => 86400,
            'principal' => 'example', 'resource_scope' => $foreignAuthorization['resource_scope'],
            'access_identity' => ['principal_id' => 'example', 'credential_id' => 'example-account', 'credential_version' => 1, 'authentication_method' => 'connect']];
        $foreignSession = $read($foreign);
        $read(['action' => 'session_save', 'session_id' => $foreignSession['session_id'], 'owner_id' => $foreign['owner_id'],
            'resource_scope' => $foreignAuthorization['resource_scope'], 'subscriptions' => [
                't:example/resource-b/#' => ['options' => 1, 'identifier' => 0],
                't:$share/resource-wide/example/#' => ['options' => 1, 'identifier' => 0],
            ]]);
        $read(['action' => 'session_close', 'session_id' => $foreignSession['session_id'], 'owner_id' => $foreign['owner_id'], 'expiry' => 86400, 'cause' => 'normal']);
        $foreignList = $read(['action' => 'resource_list', 'resource' => 'sessions', 'authorization' => $foreignAuthorization]);
        expect(count($foreignList['items']) === 1 && $foreignList['items'][0]['client_id'] === 'resource-foreign', '另一个归属不能查询自己的真实会话');
        $foreignSubscriptions = $read(['action' => 'resource_list', 'resource' => 'subscriptions', 'authorization' => $foreignAuthorization]);
        expect(
            count($foreignSubscriptions['items']) === 1 && $foreignSubscriptions['items'][0]['filter'] === 'example/resource-b/#',
            '跨命名空间的共享过滤器仅因相交便暴露给租户'
        );
        $allForeignSubscriptions = $read(['action' => 'resource_list', 'resource' => 'subscriptions', 'authorization' => ['all_metadata' => true],
            'filters' => ['session_id' => $foreignSession['session_id']]]);
        expect(count($allForeignSubscriptions['items']) === 2, '全局元数据缺少真实跨命名空间共享订阅');
        $before = [];
        foreach (['sessions', 'messages', 'deliveries', 'retained', 'retained_snapshots'] as $table) {
            $before[$table] = $primary->query('SELECT row_to_json(t)::text FROM type_mqtt_' . $table . ' t ORDER BY row_to_json(t)::text')->fetchAll(PDO::FETCH_COLUMN);
        }
        $base = ['action' => 'resource_list', 'resource' => 'sessions', 'authorization' => $authorization, 'limit' => 1];
        $page = $read($base);
        expect(count($page['items']) === 1 && $page['has_more'] && strlen($page['next_cursor']) <= 2048, '资源 keyset 第一页无效');
        $firstCursor = $page['next_cursor'];
        $seen = [];
        do {
            foreach ($page['items'] as $item) {
                expect(!in_array($item['id'], $seen, true), '资源分页重复会话');
                $seen[] = $item['id'];
            }
            $next = $page['next_cursor'];
            $page = $next === null ? ['items' => [], 'next_cursor' => null] : $read([...$base, 'cursor' => $next]);
        } while ($next !== null);
        expect(count($seen) === 3, '资源分页遗漏授权会话');
        $wrongKindCursor = json_decode(base64_decode($firstCursor, true), true, 4, JSON_THROW_ON_ERROR);
        $wrongKindCursor['after'] = 'm:' . str_repeat('a', 32);
        foreach ([['authorization' => $foreignAuthorization, 'cursor' => $firstCursor], ['limit' => 101], ['cursor' => str_repeat('x', 2049)],
            ['cursor' => base64_encode(json_encode($wrongKindCursor, JSON_THROW_ON_ERROR))],
            ['authorization' => ['all_metadata' => false]], ['filters' => ['unsafe_field' => 'x']]] as $invalid) {
            $result = mqttClusterRequest($consumer, $workerCommand, $environment, [...$base, ...$invalid]);
            expect($result['state'] === 'rejected', '资源非法额度或错用授权游标被接受');
        }
        $outside = $read(['action' => 'resource_detail', 'resource' => 'sessions', 'authorization' => $foreignAuthorization, 'id' => $legacy[0]['id']]);
        expect(!$outside['found'] && $outside['item'] === null, '范围外详情泄漏存在性');
        foreach (['sessions' => 'm:' . str_repeat('a', 32), 'subscriptions' => str_repeat('a', 32),
            'retained' => str_repeat('a', 33), 'backlog' => 'd:' . str_repeat('a', 32)] as $resource => $invalidId) {
            $invalid = mqttClusterRequest(
                $consumer,
                $workerCommand,
                $environment,
                ['action' => 'resource_detail', 'resource' => $resource, 'authorization' => $authorization, 'id' => $invalidId]
            );
            expect($invalid['state'] === 'rejected' && $invalid['reason'] === 0x83, '非法资源标识没有按输入错误拒绝：' . $resource);
        }
        $detail = $read(['action' => 'resource_detail', 'resource' => 'sessions', 'authorization' => $authorization, 'id' => $legacy[0]['id']]);
        expect(
            $detail['found'] && $detail['item']['counts']['subscriptions'] === 3 && $detail['item']['counts']['pending_messages'] >= 2,
            '会话详情缺少本范围内订阅或积压'
        );
        $retained = $read(['action' => 'resource_list', 'resource' => 'retained', 'authorization' => $authorization]);
        expect(count($retained['items']) === 2, 'Topic 命名空间没有同时覆盖路径本身和后代');
        foreach ($retained['items'] as $item) {
            expect($item['client_id'] === null, '保留元数据泄漏未证实归属的发布者');
            foreach (['payload', 'properties', 'password', 'private_key'] as $secret) {
                expect(!array_key_exists($secret, $item), '保留元数据泄漏敏感字段：' . $secret);
            }
        }
        $backlog = $read(['action' => 'resource_list', 'resource' => 'backlog', 'authorization' => $authorization]);
        expect(count($backlog['items']) >= 4 && in_array(true, array_column($backlog['items'], 'shared'), true), '资源积压遗漏共享未领取副本');
        foreach ($backlog['items'] as $item) {
            foreach (['payload', 'properties', 'password', 'private_key', 'shared_filter', 'group_id'] as $secret) {
                expect(!array_key_exists($secret, $item), '积压元数据泄漏敏感字段或跨范围共享组：' . $secret);
            }
        }
        $encoded = json_encode($backlog, JSON_THROW_ON_ERROR);
        expect(
            !str_contains($encoded, 'resource-payload-secret') && !str_contains($encoded, 'example/resource-b') && strlen($encoded) <= 1048576,
            '资源响应超预算或泄漏载荷/跨范围主题'
        );
        // 写入容量锁被其他事务占用时仍完成管理读取，证明没有误入协议或容量分支。
        $primary->beginTransaction();
        try {
            $primary->query('SELECT pg_advisory_xact_lock(1954115693, 1)');
            expect(count($read($base)['items']) === 1, '管理读取被全局写入容量锁阻断');
        } finally {
            $primary->rollBack();
        }
        foreach ($before as $table => $rows) {
            expect(
                $primary->query('SELECT row_to_json(t)::text FROM type_mqtt_' . $table . ' t ORDER BY row_to_json(t)::text')->fetchAll(PDO::FETCH_COLUMN) === $rows,
                '管理读取推进了协议状态：' . $table
            );
        }
        $environment['MQTT_TEST_RESOURCE_SCOPE'] = $foreignAuthorization['resource_scope'];
        $broker = mqttIdentityStart($consumer, $command, $environment, $port, null);
        $rejected = mqttSocket($port);
        $sockets[] = $rejected;
        mqttWrite($rejected, mqttSessionConnect(5, 'resource-legacy'));
        expect(mqttRead($rejected) === "\x20\x03\x00\x87\x00", '同一 ClientID 可以通过重连改变已有归属');
        fclose($rejected);
        $sockets = [];
        mqttIdentityStop($broker);
        $broker = null;
        expect($read($list)['items'][0]['id'] === $legacy[0]['id'], '拒绝归属迁移后丢失原会话');
        $environment['MQTT_WORKER_COMMAND'] = '[]';
        $broker = mqttIdentityStart($consumer, $command, $environment, $port, null);
        $volatile = mqttSocket($port);
        $sockets[] = $volatile;
        mqttWrite($volatile, mqttConnect(5, 'resource-volatile'));
        mqttAck($volatile, 5);
        mqttWrite($volatile, mqttSubscription(5, 'example/resource-b/volatile', 1, 0));
        expect(mqttRead($volatile) === mqttSubscriptionAck(5, "\x00"), '纯实时资源订阅失败');
        mqttWrite($volatile, "\xe0\0");
        expect(mqttRead($volatile) === '', '纯实时资源连接没有关闭');
        fclose($volatile);
        $sockets = [];
        mqttIdentityStop($broker);
        $broker = null;
        $events = array_map(
            static fn (string $line): array => json_decode($line, true, 16, JSON_THROW_ON_ERROR),
            file($consumer . '/identity-resource-observations.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        );
        $connections = array_values(array_filter($events, static fn (array $event): bool => $event['event'] === 'connected'));
        $legacyEvents = array_values(array_filter($connections, static fn (array $event): bool => $event['resource']['client_id'] === 'resource-legacy'));
        expect(
            count($legacyEvents) === 2 && $legacyEvents[0]['resource']['resource_scope'] === null
            && $legacyEvents[1]['resource']['resource_scope'] === $authorization['resource_scope']
            && $legacyEvents[1]['resource']['session_id'] === $legacy[0]['id'] && $legacyEvents[1]['resource']['durable'],
            '成功 CONNACK 资源观察不准确，或拒绝连接错误产生成功事实'
        );
        $volatileEvents = array_values(array_filter($connections, static fn (array $event): bool => $event['resource']['client_id'] === 'resource-volatile'));
        expect(
            count($volatileEvents) === 1 && !$volatileEvents[0]['resource']['durable'] && $volatileEvents[0]['resource']['session_generation'] === 0,
            '纯实时连接被误报为持久会话或没有真实观察'
        );
        $removed = array_values(array_filter($events, static fn (array $event): bool => $event['event'] === 'subscription'
            && $event['filter'] === 'example/resource-a/transient' && $event['subscription'] === null));
        expect(count($removed) === 1 && $removed[0]['owner_id'] === $legacyEvents[1]['resource']['owner_id'], '取消订阅缺少精确 owner 观察');
        return ['status' => 'passed', 'legacy_bound_after_reauthorization' => true, 'scope_change_rejected' => true,
            'keyset_and_budget' => true, 'shared_backlog_visible_without_group_leak' => true, 'read_only_under_capacity_lock' => true,
            'connack_and_volatile_observation' => true, 'subscription_delta_observation' => true];
    } finally {
        foreach ($sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        if ($broker !== null) {
            mqttIdentityStop($broker);
        }
        if (is_file($consumer . '/identity-resource-observations.jsonl')) {
            unlink($consumer . '/identity-resource-observations.jsonl');
        }
        foreach (['resource-legacy', 'resource-second', 'resource-third', 'resource-foreign'] as $clientId) {
            $cleaned = mqttClusterRequest($consumer, $workerCommand, $environment, ['action' => 'session_terminate', 'client_id' => $clientId, 'actor' => 'resource-test']);
            expect($cleaned['state'] === 'committed', '资源测试会话清理未完成');
        }
    }
}

/** 独立线编码，Clean Start 位和会话期限不调用生产编码器。 */
function mqttSessionConnect(int $version, string $id, bool $clean = false, ?int $expiry = 86400, string $extraProperties = '', int $keepalive = 10): string
{
    $properties = ($version === 5 && $expiry !== null ? "\x11" . pack('N', $expiry) : '') . $extraProperties;
    $body = mqttField('MQTT') . chr($version) . chr($clean ? 0xc2 : 0xc0) . pack('n', $keepalive)
        . ($version === 5 ? mqttLength(strlen($properties)) . $properties : '')
        . mqttField($id) . mqttField('example') . mqttField('mqtt-test-secret');
    return mqttPacket(0x10, $body);
}

/**
 * 读取成功 CONNACK，并验证 session_present 与预期一致；借用连接不在此关闭。
 *
 * @param resource $socket
 */
function mqttSessionAck(mixed $socket, bool $present): void
{
    $ack = mqttRead($socket);
    expect(
        strlen($ack) >= 4 && ord($ack[0]) === 0x20 && ord($ack[2]) === ($present ? 1 : 0) && ord($ack[3]) === 0,
        '会话 CONNACK 不符：' . bin2hex($ack)
    );
}

/** 完整订阅/退订先重置心跳，再等待同步持久结果；两次空闲都跨过此前的三秒截止。 */
function mqttSessionKeepaliveCases(int $port): int
{
    foreach ([4, 5] as $version) {
        $socket = mqttSocket($port);
        try {
            mqttWrite($socket, mqttSessionConnect($version, 'persistent-keepalive-' . $version, true, 0, '', 2));
            mqttSessionAck($socket, false);
            usleep(1800000);
            mqttWrite($socket, mqttSubscription($version, 'example/keepalive', 1, 1));
            expect(mqttRead($socket) === mqttSubscriptionAck($version, "\x01"), '心跳回归持久订阅未确认');
            usleep(1500000);
            mqttWrite($socket, "\xc0\0");
            expect(mqttRead($socket) === "\xd0\0", '持久 SUBSCRIBE 未重置 Keep Alive');
            usleep(1800000);
            mqttWrite($socket, mqttPacket(0xa2, pack('n', 2) . ($version === 5 ? "\0" : '') . mqttField('example/keepalive')));
            expect(mqttRead($socket) === mqttPacket(0xb0, pack('n', 2) . ($version === 5 ? "\0\0" : '')), '心跳回归持久退订未确认');
            usleep(1500000);
            mqttWrite($socket, "\xc0\0");
            expect(mqttRead($socket) === "\xd0\0", '持久 UNSUBSCRIBE 未重置 Keep Alive');
            mqttWrite($socket, "\xe0\0");
            expect(mqttRead($socket) === '', '心跳回归连接未正常退出');
        } finally {
            fclose($socket);
        }
    }
    return 4;
}

/** 保留快照进入持久会话后保留真实 RETAIN 与原始期限；重连接口重新建立别名。 */
function mqttSessionRetainedCases(int $port, PDO $standby): int
{
    require_once __DIR__ . '/mqtt-retained.php';
    $sockets = [];
    $cases = 0;
    try {
        foreach ([[4, 1], [5, 1], [5, 2]] as [$version, $qos]) {
            $id = 'retained-resume-' . $version . '-' . $qos;
            $topic = 'example/' . $id;
            $publisher = mqttSocket($port);
            $sockets[] = $publisher;
            mqttWrite($publisher, mqttConnect(5, $id . '-p'));
            mqttAck($publisher, 5);
            mqttRetainedPublish($publisher, 5, $topic, 'original-deadline', $qos, "\x02" . pack('N', 5));
            fclose($publisher);
            usleep(2100000);
            $subscriber = mqttSocket($port);
            $sockets[] = $subscriber;
            mqttWrite($subscriber, mqttSessionConnect($version, $id));
            mqttSessionAck($subscriber, false);
            mqttWrite($subscriber, mqttSubscription($version, $topic, 1, $qos));
            expect(mqttRead($subscriber) === mqttSubscriptionAck($version, chr($qos)), '组合保留订阅失败');
            $initial = mqttRetainedRead($subscriber, $version);
            expect($initial['retain'] && !$initial['duplicate'] && $initial['topic'] === $topic && $initial['qos'] === $qos, '初次保留交付标志错误');
            mqttWrite($subscriber, "\xe0\0");
            expect(mqttRead($subscriber) === '', '保留交换中断未关闭');
            fclose($subscriber);
            mqttUntil(fn (): bool => $standby->query("SELECT owner_id FROM type_mqtt_sessions WHERE client_id = '" . $id . "'")->fetchColumn() === null, '保留会话未离线');
            usleep(3200000);
            $subscriber = mqttSocket($port);
            $sockets[] = $subscriber;
            mqttWrite($subscriber, mqttSessionConnect(5, $id, false, 86400, "\x22\0\x01"));
            mqttSessionAck($subscriber, true);
            $restored = mqttRetainedRead($subscriber, 5);
            expect($restored['topic'] === $topic && $restored['id'] === $initial['id'] && $restored['qos'] === $qos
                && $restored['payload'] === 'original-deadline' && $restored['retain'] && $restored['duplicate'], '持久保留恢复丢失标志、标识或消息');
            expect($restored['properties'] === "\x02\0\0\0\0\x23\0\x01", '恢复刷新原始期限或未重新建立 Topic Alias：' . bin2hex($restored['properties']));
            mqttRetainedComplete($subscriber, $restored);
            mqttUntil(fn (): bool => (int) $standby->query("SELECT COUNT(*) FROM type_mqtt_deliveries WHERE client_id = '" . $id . "' AND state = 'pending'")->fetchColumn() === 0, '恢复保留交换没有持久终结');
            mqttRetainedQuiet($subscriber);
            mqttWrite($subscriber, mqttPacket(0xe0, "\0\x05\x11\0\0\0\0"));
            expect(mqttRead($subscriber) === '', '组合会话未清理');
            fclose($subscriber);
            $cases += 3;
        }
        foreach ([0, 8] as $rap) {
            $id = 'offline-rap-' . $rap;
            $topic = 'example/' . $id;
            $subscriber = mqttSocket($port);
            $sockets[] = $subscriber;
            mqttWrite($subscriber, mqttSessionConnect(5, $id));
            mqttSessionAck($subscriber, false);
            mqttWrite($subscriber, mqttSubscription(5, $topic, 1, 1 | $rap | 32));
            expect(mqttRead($subscriber) === mqttSubscriptionAck(5, "\x01"), '离线 RAP 订阅失败');
            mqttWrite($subscriber, "\xe0\0");
            expect(mqttRead($subscriber) === '', '离线 RAP 未断开');
            fclose($subscriber);
            mqttUntil(fn (): bool => $standby->query("SELECT owner_id FROM type_mqtt_sessions WHERE client_id = '" . $id . "'")->fetchColumn() === null, 'RAP 会话未离线');
            $publisher = mqttSocket($port);
            $sockets[] = $publisher;
            mqttWrite($publisher, mqttConnect(5, $id . '-p'));
            mqttAck($publisher, 5);
            mqttRetainedPublish($publisher, 5, $topic, 'offline-retain', 1);
            fclose($publisher);
            $subscriber = mqttSocket($port);
            $sockets[] = $subscriber;
            mqttWrite($subscriber, mqttSessionConnect(5, $id, false, 0));
            mqttSessionAck($subscriber, true);
            $delivery = mqttRetainedRead($subscriber, 5);
            expect($delivery['payload'] === 'offline-retain' && $delivery['retain'] === ($rap === 8) && !$delivery['duplicate'], '离线交付没有遵循持久订阅 RAP');
            mqttRetainedComplete($subscriber, $delivery);
            mqttRetainedQuiet($subscriber);
            fclose($subscriber);
            $cases++;
        }
        return $cases;
    } finally {
        foreach ($sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }
}

/**
 * 真实表锁固定读取或接收事务的在途阶段，再从公开停止入口排空；恢复观察仍走MQTT网络。
 * 不向生产Broker添加延迟开关，也不把数据库记录可见性当作原提交的成功证明。
 */
function mqttShutdownInFlightCases(string $root, string $consumer, array $command, array $environment, PDO $primary, PDO $observer, PDO $standby): array
{
    require_once $root . '/tests/mqtt-retained.php';
    require_once $root . '/tests/mqtt-cluster.php';
    $broker = null;
    $socket = null;
    $receiver = null;
    $report = ['status' => 'running', 'runs' => []];
    $locker = (int) $primary->query('SELECT pg_backend_pid()')->fetchColumn();
    try {
        foreach ([false, true] as $tls) {
            foreach (['session_next', 'retained_read', 'accept'] as $kind) {
                $clientId = 'shutdown-inflight-' . (int) $tls . '-' . $kind;
                $recipientId = $kind === 'accept' ? $clientId . '-receiver' : $clientId;
                $topic = 'example/' . $clientId;
                $payload = "\0\xffshutdown-" . $kind;
                $certificate = $tls ? $consumer . '/certificate.pem' : null;
                $port = mqttClusterPort();
                $broker = mqttIdentityStart($consumer, $command, $environment, $port, $certificate);
                $socket = mqttSocket($port, $certificate);
                mqttWrite($socket, mqttSessionConnect(5, $clientId, false, $kind === 'accept' ? 0 : 60));
                mqttSessionAck($socket, false);
                if ($kind === 'accept') {
                    // 离线持久接收者没有轮询事务，便于精确锁定发布者的accept而非session_next。
                    $receiver = mqttSocket($port, $certificate);
                    mqttWrite($receiver, mqttSessionConnect(5, $recipientId, false, 60));
                    mqttSessionAck($receiver, false);
                    mqttWrite($receiver, mqttSubscription(5, $topic, 1, 1));
                    expect(mqttRead($receiver) === mqttSubscriptionAck(5, "\x01"), '停机接收者订阅失败');
                    mqttWrite($receiver, "\xe0\0");
                    expect(mqttRead($receiver) === '', '停机接收者未关闭网络');
                    fclose($receiver);
                    $receiver = null;
                    mqttUntil(fn (): bool => $observer->query('SELECT owner_id IS NULL FROM type_mqtt_sessions WHERE client_id = '
                        . $observer->quote($recipientId))->fetchColumn() === true, '停机接收者未完成离线登记');
                } elseif ($kind === 'retained_read') {
                    mqttRetainedPublish($socket, 5, $topic, $payload, 1);
                } else {
                    mqttWrite($socket, mqttSubscription(5, $topic, 1, 1));
                    expect(mqttRead($socket) === mqttSubscriptionAck(5, "\x01"), '停机恢复订阅失败');
                    mqttWrite($socket, mqttReliablePublish(5, $topic, $payload, 17));
                    expect(mqttRead($socket) === "\x40\x02\0\x11", '停机前可靠消息未获同步确认');
                    $unacknowledged = mqttRetainedRead($socket, 5);
                    expect($unacknowledged['payload'] === $payload && $unacknowledged['qos'] === 1, '停机前没有真实未确认交付');
                }
                $table = match ($kind) {
                    'session_next' => 'type_mqtt_deliveries',
                    'retained_read' => 'type_mqtt_retained',
                    default => 'type_mqtt_messages',
                };
                $primary->beginTransaction();
                $primary->exec('LOCK TABLE ' . $table . ' IN ACCESS EXCLUSIVE MODE');
                if ($kind === 'retained_read') {
                    mqttWrite($socket, mqttSubscription(5, $topic, 1, 1));
                    expect(mqttRead($socket) === mqttSubscriptionAck(5, "\x01"), '停机保留订阅未获得SUBACK');
                } elseif ($kind === 'accept') {
                    mqttWrite($socket, mqttReliablePublish(5, $topic, $payload, 17));
                }
                $queryPrefix = match ($kind) {
                    'session_next' => 'SELECT d.*, m.topic,%',
                    'retained_read' => 'DELETE FROM type_mqtt_retained WHERE topic = %',
                    default => 'SELECT COUNT(*) AS messages, COALESCE(SUM(byte_size), 0) AS bytes FROM (SELECT publisher_session%',
                };
                $backend = mqttUntil(fn (): mixed => $observer->query("SELECT pid, application_name FROM pg_stat_activity WHERE application_name LIKE 'type_mqtt_%' "
                    . "AND wait_event_type = 'Lock' AND " . $locker . ' = ANY(pg_blocking_pids(pid)) AND query LIKE '
                    . $observer->quote($queryPrefix))->fetch(PDO::FETCH_ASSOC), '停机在途操作未进入准确数据库锁等待：' . $kind);
                $stoppingAt = microtime(true);
                expect(posix_kill($broker->pid(), SIGTERM), '无法停止本轮在途Broker');
                // 停止发生在持有真实锁时；旧实现此时会将该worker截止缩到零。
                usleep(250000);
                $primary->rollBack();
                $stopped = $broker->wait(12);
                $broker = null;
                fclose($socket);
                $socket = null;
                file_put_contents($consumer . '/' . $clientId . '.stdout.json', $stopped->stdout);
                file_put_contents($consumer . '/' . $clientId . '.stderr.log', $stopped->stderr);
                expect($stopped->successful() && $stopped->stderr === '', '停机在途Broker未正常退出');
                $statistics = json_decode($stopped->stdout, true, 32, JSON_THROW_ON_ERROR);
                $run = ['tls' => $tls, 'operation' => $kind, 'operation_id' => substr($backend['application_name'], strlen('type_mqtt_')),
                    'observed_wait' => 'Lock', 'stop_seconds' => microtime(true) - $stoppingAt, 'statistics' => $statistics];
                $report['runs'][$clientId] = $run;
                foreach (['connections', 'subscriptions', 'bufferedBytes', 'pendingCommits', 'closingSessions', 'quarantinedCommits', 'pendingFences'] as $field) {
                    expect($statistics[$field] === 0, '停机在途资源未回收：' . $kind . ' ' . $field);
                }
                expect(
                    $statistics['unknownCommits'] === 0 && $statistics['rejectedCommits'] === 0 && $run['stop_seconds'] < 5.0,
                    '正常停机打断已启动工作或超出预算：' . $kind
                );
                expect($standby->query('SELECT owner_id IS NULL FROM type_mqtt_sessions WHERE client_id = '
                    . $standby->quote($recipientId))->fetchColumn() === true, '在途排空后没有登记离线会话');
                $broker = mqttIdentityStart($consumer, $command, $environment, $port, $certificate);
                $socket = mqttSocket($port, $certificate);
                mqttWrite($socket, mqttSessionConnect(5, $recipientId, false, 60));
                mqttSessionAck($socket, true);
                $restored = mqttRetainedRead($socket, 5);
                expect($restored['topic'] === $topic && $restored['payload'] === $payload && $restored['qos'] === 1
                    && $restored['retain'] === ($kind === 'retained_read'), '停机后恢复丢失消息字节或语义：' . $kind);
                expect($restored['duplicate'] === ($kind === 'session_next'), '停机后恢复错误推进了交付阶段：' . $kind);
                if ($kind === 'session_next') {
                    expect($restored['id'] === $unacknowledged['id'], '停机改变未确认交付标识');
                }
                mqttRetainedComplete($socket, $restored);
                mqttUntil(fn (): bool => (int) $standby->query('SELECT COUNT(*) FROM type_mqtt_deliveries WHERE state = \'pending\' AND client_id = '
                    . $standby->quote($recipientId))->fetchColumn() === 0, '恢复后的实际PUBACK未终结交付');
                mqttWrite($socket, "\xe0\0");
                expect(mqttRead($socket) === '', '恢复客户端没有正常关闭');
                fclose($socket);
                $socket = null;
                $report['runs'][$clientId]['recovery_statistics'] = mqttIdentityStop($broker);
                $report['runs'][$clientId]['restored'] = ['binary_payload' => true, 'qos' => 1, 'retain' => $restored['retain'],
                    'duplicate' => $restored['duplicate'], 'acknowledged' => true];
                $broker = null;
            }
        }
        $report['status'] = 'passed';
        return $report;
    } catch (Throwable $failure) {
        $report['status'] = 'failed';
        $report['failure'] = $failure->getMessage();
        throw $failure;
    } finally {
        if ($primary->inTransaction()) {
            $primary->rollBack();
        }
        foreach ([$socket, $receiver] as $remaining) {
            if (is_resource($remaining)) {
                fclose($remaining);
            }
        }
        if ($broker !== null) {
            $stopped = $broker->stop(12);
            $report['cleanup'] = ['exit_code' => $stopped->exitCode, 'stdout' => $stopped->stdout, 'stderr' => $stopped->stderr];
        }
        file_put_contents($consumer . '/shutdown-inflight.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}

/** 用真实行锁定位已启动的结束事务；正常停机保留其原截止，实际同步故障仍明确未知。 */
function mqttSessionShutdownCases(string $root, string $consumer, array $command, array $workerCommand, array $environment): array
{
    ini_set('zend.exception_ignore_args', '1');
    require_once $root . '/tests/native-database.php';
    require_once $root . '/tests/postgres-sync.php';
    require_once $root . '/tests/mqtt-qos1.php';
    $tools = NativeDatabase::tools('pgsql', (string) (getenv('TYPE_PGSQL_TOOLS') ?: $root . '/.cache/macos-libpq/17.11'));
    $database = new NativeDatabase($consumer . '/shutdown-primary', 'pgsql', $tools);
    $sync = null;
    $process = null;
    $primary = null;
    $observer = null;
    $standby = null;
    $socket = null;
    $report = ['status' => 'running', 'runs' => []];
    try {
        $sync = new PostgresSync($database, $consumer . '/shutdown-standby', $tools);
        $environment = array_replace($environment, $database->environment());
        $environment['MQTT_WORKER_COMMAND'] = json_encode($workerCommand, JSON_THROW_ON_ERROR);
        $installed = (new Process([...$command, '--install-store'], $consumer, $environment))->wait(12);
        expect($installed->successful() && $installed->stderr === '', '停机会话安装失败');
        $installation = json_decode($installed->stdout, true, 32, JSON_THROW_ON_ERROR);
        expect($installation['state'] === 'committed' && $installation['released'], '停机会话安装未同步确认');
        $primary = $sync->connection();
        $observer = $sync->connection();
        $standby = $sync->standby();
        $locker = (int) $primary->query('SELECT pg_backend_pid()')->fetchColumn();
        foreach ([false, true] as $tls) {
            foreach ([[4, true, 0], [4, false, -1], [5, false, 0], [5, false, 60], [5, false, 4294967295], [5, false, 0, 'sync-loss']] as $case) {
                [$version, $clean, $expiry] = $case;
                $fault = ($case[3] ?? '') === 'sync-loss';
                $id = 'shutdown-' . ($tls ? 'tls' : 'tcp') . '-' . $version . '-' . ($clean ? 'clean' : (string) $expiry) . ($fault ? '-fault' : '');
                $certificate = $tls ? $consumer . '/certificate.pem' : null;
                $environment['MQTT_CERTIFICATE'] = $certificate ?? '';
                $environment['MQTT_PRIVATE_KEY'] = $tls ? $consumer . '/private.pem' : '';
                $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
                expect(is_resource($listener), '停机回归无法分配监听');
                $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
                fclose($listener);
                $process = new Process([...$command, '--port=' . $port, ...($tls ? [] : ['--plaintext'])], $consumer, $environment);
                $socket = mqttUntil(function () use ($process, $port, $certificate): mixed {
                    expect($process->running(), '停机回归 Broker 提前退出：' . $process->stderr());
                    try {
                        return mqttSocket($port, $certificate);
                    } catch (RuntimeException) {
                        return false;
                    }
                }, '停机回归 Broker 未启动');
                mqttWrite($socket, mqttSessionConnect($version, $id, $clean, $expiry));
                mqttSessionAck($socket, false);
                mqttDatabaseIdle($observer);
                $primary->beginTransaction();
                $locked = $primary->query("SELECT owner_id FROM type_mqtt_sessions WHERE client_id = '" . $id . "' FOR UPDATE")->fetchColumn();
                expect(is_string($locked) && $locked !== '', '没有锁定本轮真实会话');
                $closedAt = microtime(true);
                // 同时覆盖正常DISCONNECT和网络直接关闭，不依赖固定延迟猜测ending是否已启动。
                if ($clean || $expiry === 60) {
                    mqttWrite($socket, "\xe0\0");
                    expect(mqttRead($socket) === '', '停机前 DISCONNECT 未关闭网络');
                }
                fclose($socket);
                $socket = null;
                $backend = mqttUntil(fn (): mixed => $observer->query("SELECT pid, application_name FROM pg_stat_activity WHERE application_name LIKE 'type_mqtt_%' "
                    . "AND wait_event_type = 'Lock' AND " . $locker . ' = ANY(pg_blocking_pids(pid)) '
                    . "AND query LIKE 'SELECT * FROM type_mqtt_sessions WHERE client_id = % AND owner_id = % FOR UPDATE'")->fetch(PDO::FETCH_ASSOC), '会话结束没有进入可观察的真实行锁等待');
                $workerPid = (int) $backend['pid'];
                if ($fault) {
                    $standby = null;
                    $sync->stopStandby();
                    $primary->rollBack();
                    mqttUntil(fn (): bool => $observer->query('SELECT wait_event FROM pg_stat_activity WHERE pid = ' . $workerPid)->fetchColumn() === 'SyncRep', '结束事务未进入真实同步确认等待');
                }
                $stoppingAt = microtime(true);
                expect(posix_kill($process->pid(), SIGTERM), '无法停止本轮 Broker');
                if (!$fault) {
                    usleep(250000);
                    $primary->rollBack();
                }
                $result = $process->wait(12);
                $seconds = microtime(true) - $stoppingAt;
                file_put_contents($consumer . '/' . $id . '.stdout.json', $result->stdout);
                file_put_contents($consumer . '/' . $id . '.stderr.log', $result->stderr);
                expect($result->successful() && $result->stderr === '', '停机回归没有干净退出：' . $id . ' ' . $result->stderr);
                $statistics = json_decode($result->stdout, true, 32, JSON_THROW_ON_ERROR);
                foreach (['connections', 'subscriptions', 'bufferedBytes', 'pendingCommits', 'closingSessions', 'quarantinedCommits'] as $field) {
                    expect($statistics[$field] === 0, '停机未释放：' . $id . ' ' . $field);
                }
                mqttDatabaseIdle($observer);
                if ($fault) {
                    $sync->restartStandby();
                    $standby = $sync->standby();
                }
                $remaining = $standby->query("SELECT owner_id, expiry, EXTRACT(EPOCH FROM expires_at)::double precision AS expires_at FROM type_mqtt_sessions WHERE client_id = '" . $id . "'")->fetch(PDO::FETCH_ASSOC);
                $correctState = $expiry === 0 ? $remaining === false
                    : is_array($remaining) && $remaining['owner_id'] === null && (int) $remaining['expiry'] === $expiry
                        && ($expiry === 60 ? (float) $remaining['expires_at'] >= $closedAt + 60 && (float) $remaining['expires_at'] <= $stoppingAt + 60
                            : $remaining['expires_at'] === null);
                $passed = $fault ? $statistics['unknownCommits'] === 1 && $seconds < 11.0
                    : $statistics['unknownCommits'] === 0 && $correctState && $seconds < 5.0;
                $report['runs'][$id] = ['version' => $version, 'tls' => $tls, 'expiry' => $expiry,
                    'fault' => $fault, 'operation_id' => substr($backend['application_name'], strlen('type_mqtt_')),
                    'observed_wait' => $fault ? ['Lock', 'SyncRep'] : ['Lock'], 'stop_seconds' => $seconds,
                    'statistics' => $statistics, 'remaining_session' => $remaining, 'state_matches' => $correctState, 'passed' => $passed];
            }
        }
        $report['replication'] = $sync->evidence();
        $report['status'] = count(array_filter($report['runs'], fn (array $run): bool => !$run['passed'])) === 0 ? 'passed' : 'failed';
        expect($report['status'] === 'passed', '会话停机语义不符，保留逐轮结束事务、状态和统计证据');
        $report['in_flight'] = mqttShutdownInFlightCases($root, $consumer, $command, $environment, $primary, $observer, $standby);
        return $report;
    } catch (Throwable $failure) {
        $report['status'] = 'failed';
        $report['failure'] = $failure->getMessage();
        throw $failure;
    } finally {
        if ($primary?->inTransaction()) {
            $primary->rollBack();
        }
        if (is_resource($socket)) {
            fclose($socket);
        }
        $process?->stop(12);
        $primary = null;
        $observer = null;
        $standby = null;
        $sync?->close();
        $database->close();
        file_put_contents($consumer . '/session-shutdown.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
}

/**
 * 使用专属 PostgreSQL 主备和工作进程验证 MQTT 会话恢复，退出路径回收连接及测试服务。
 *
 * @param list<string> $command
 * @param list<string> $workerCommand
 * @param array<string, string> $environment
 * @return array{cases: int, subscription-cases: int, replication: array<string, mixed>, statistics: array<string, mixed>}
 */
function mqttSessionCases(string $root, string $consumer, array $command, array $workerCommand, array $environment): array
{
    ini_set('zend.exception_ignore_args', '1');
    require_once $root . '/tests/native-database.php';
    require_once $root . '/tests/postgres-sync.php';
    require_once $root . '/tests/mqtt-qos1.php';
    require_once $root . '/tests/mqtt-qos2.php';
    $tools = NativeDatabase::tools('pgsql', (string) (getenv('TYPE_PGSQL_TOOLS') ?: $root . '/.cache/macos-libpq/17.11'));
    $database = new NativeDatabase($consumer . '/session-primary', 'pgsql', $tools);
    $sync = null;
    $process = null;
    $primary = null;
    $standby = null;
    $sockets = [];
    $cases = 0;
    try {
        $sync = new PostgresSync($database, $consumer . '/session-standby', $tools);
        $environment = array_replace($environment, $database->environment());
        $environment['MQTT_CERTIFICATE'] = '';
        $environment['MQTT_PRIVATE_KEY'] = '';
        $environment['MQTT_WORKER_COMMAND'] = json_encode($workerCommand, JSON_THROW_ON_ERROR);
        $installed = (new Process([...$command, '--install-store'], $consumer, $environment))->wait(10);
        expect($installed->successful() && $installed->stderr === '', '会话安装失败：' . $installed->stderr);
        expect(json_decode($installed->stdout, true, 32, JSON_THROW_ON_ERROR)['state'] === 'committed', '会话安装没有同步确认');
        $primary = $sync->connection();
        $standby = $sync->standby();
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        fclose($listener);
        $start = function () use ($command, $consumer, $environment, $port): Process {
            $running = new Process([...$command, '--port=' . $port, '--plaintext'], $consumer, $environment);
            mqttUntil(function () use ($running, $port): bool {
                expect($running->running(), '会话 Broker 提前退出：' . $running->stderr());
                $probe = null;
                try {
                    $probe = mqttSocket($port);
                    stream_set_timeout($probe, 1);
                    mqttWrite($probe, mqttConnect(5, 'session-ready-probe'));
                    mqttAck($probe, 5);
                    mqttWrite($probe, "\xe0\0");
                    return true;
                } catch (RuntimeException) {
                    return false;
                } finally {
                    if (is_resource($probe)) {
                        fclose($probe);
                    }
                }
            }, '会话 Broker 未启动', 12.0);
            return $running;
        };
        $process = $start();
        $cases += mqttSessionKeepaliveCases($port);
        $subscriptionCases = 0;
        if (in_array('--subscriptions', $_SERVER['argv'], true)) {
            require_once __DIR__ . '/mqtt-subscriptions.php';
            $subscriptionCases = mqttSubscriptionDurableCases($port, $primary, $standby, function () use (&$process, $start): void {
                $result = $process->stop(12);
                expect($result->successful() && $result->stderr === '', '订阅重启前 Broker 没有完整退出');
                $statistics = json_decode($result->stdout, true, 32, JSON_THROW_ON_ERROR);
                foreach (['connections', 'subscriptions', 'bufferedBytes', 'retainedQueued', 'retainedPending', 'retainedBytes', 'pendingCommits', 'closingSessions'] as $field) {
                    expect($statistics[$field] === 0, '订阅重启前未释放：' . $field);
                }
                $process = $start();
            }, $consumer, array_replace($environment, ['MQTT_WORKER_COMMAND' => json_encode($command, JSON_THROW_ON_ERROR)]));
        }
        foreach ([4, 5] as $version) {
            $id = 'persistent-' . $version;
            $topic = 'example/session/' . $version;
            $subscriber = mqttSocket($port);
            $sockets[] = $subscriber;
            mqttWrite($subscriber, mqttSessionConnect($version, $id));
            mqttSessionAck($subscriber, false);
            mqttWrite($subscriber, mqttSubscription($version, $topic, 1, 2));
            expect(mqttRead($subscriber) === mqttSubscriptionAck($version, "\x02"), '持久订阅失败');
            expect(str_contains($standby->query("SELECT subscriptions::text FROM type_mqtt_sessions WHERE client_id = '" . $id . "'")->fetchColumn(), $topic), 'SUBACK 早于同步订阅');
            mqttWrite($subscriber, "\xe0\0");
            expect(mqttRead($subscriber) === '', 'DISCONNECT 未关闭');
            fclose($subscriber);
            mqttUntil(fn (): bool => $standby->query("SELECT owner_id FROM type_mqtt_sessions WHERE client_id = '" . $id . "'")->fetchColumn() === null, '断线未保存独立会话');
            $publisher = mqttSocket($port);
            $sockets[] = $publisher;
            mqttWrite($publisher, mqttConnect($version, 'session-publisher-' . $version));
            mqttAck($publisher, $version);
            mqttWrite($publisher, mqttReliablePublish($version, $topic, 'offline-one', 1));
            expect(mqttRead($publisher) === mqttPacket(0x40, pack('n', 1)), '离线 QoS 1 未接管');
            mqttWrite($publisher, mqttQos2Publish($version, $topic, 'offline-two', 2));
            expect(mqttRead($publisher) === mqttQos2Ack(0x50, 2), '离线 QoS 2 未接管');
            mqttWrite($publisher, mqttQos2Ack(0x62, 2));
            expect(mqttRead($publisher) === mqttQos2Ack(0x70, 2), '离线 QoS 2 未终结接收');
            fclose($publisher);
            $subscriber = mqttSocket($port);
            $sockets[] = $subscriber;
            mqttWrite($subscriber, mqttSessionConnect($version, $id));
            mqttSessionAck($subscriber, true);
            expect(mqttRead($subscriber) === mqttReliablePublish($version, $topic, 'offline-one', 1), '离线首次交付错误');
            expect(mqttRead($subscriber) === mqttQos2Publish($version, $topic, 'offline-two', 2), '离线 QoS 2 首次交付错误');
            mqttWrite($subscriber, mqttQos2Ack(0x50, 2));
            expect(mqttRead($subscriber) === mqttQos2Ack(0x62, 2), '恢复 QoS 2 未推进 PUBREL');
            mqttWrite($subscriber, "\xe0\0");
            expect(mqttRead($subscriber) === '', '恢复连接未关闭');
            fclose($subscriber);
            mqttDatabaseIdle($primary);
            $stopped = $process->stop(12);
            expect($stopped->successful() && $stopped->stderr === '', '持久 Broker 正常停止失败：' . $stopped->stderr);
            $process = $start();
            $subscriber = mqttSocket($port);
            $sockets[] = $subscriber;
            mqttWrite($subscriber, mqttSessionConnect($version, $id));
            mqttSessionAck($subscriber, true);
            $replay = [mqttRead($subscriber), mqttRead($subscriber)];
            expect(in_array(mqttReliablePublish($version, $topic, 'offline-one', 1, true), $replay, true), '重启未重传原 QoS 1 标识和 DUP');
            expect(in_array(mqttQos2Ack(0x62, 2), $replay, true), '重启退回 PUBLISH 或漏掉 PUBREL');
            mqttWrite($subscriber, mqttQos2Ack(0x40, 1) . mqttQos2Ack(0x70, 2));
            mqttQuiet($subscriber);
            mqttUntil(fn (): bool => (int) $standby->query("SELECT COUNT(*) FROM type_mqtt_deliveries WHERE client_id = '" . $id . "' AND state = 'pending'")->fetchColumn() === 0, '恢复确认未同步终结');
            fclose($subscriber);
            $clean = mqttSocket($port);
            $sockets[] = $clean;
            mqttWrite($clean, mqttSessionConnect($version, $id, true, 0));
            mqttSessionAck($clean, false);
            expect((int) $standby->query("SELECT COUNT(*) FROM type_mqtt_session_audit WHERE client_id = '" . $id . "' AND action = 'client_clean'")->fetchColumn() === 1, '客户端清理缺少审计');
            fclose($clean);
            $cases += 11;
        }
        foreach ([null, 0, 1] as $expiry) {
            $id = 'expiry-' . ($expiry === null ? 'missing' : $expiry);
            $socket = mqttSocket($port);
            $sockets[] = $socket;
            mqttWrite($socket, mqttSessionConnect(5, $id, false, $expiry));
            mqttSessionAck($socket, false);
            mqttWrite($socket, "\xe0\0");
            expect(mqttRead($socket) === '', '过期用例未关闭');
            fclose($socket);
            usleep(1200000);
            $reconnect = mqttSocket($port);
            $sockets[] = $reconnect;
            mqttWrite($reconnect, mqttSessionConnect(5, $id, false, $expiry));
            mqttSessionAck($reconnect, false);
            fclose($reconnect);
            $cases++;
        }
        // 已持久 PUBREC 的接收状态和未收到 PUBREC 的发送状态均跨硬停止恢复。
        foreach ([4, 5] as $version) {
            $expiry = $version === 5 ? 4294967295 : 86400;
            $publisher = mqttSocket($port);
            $subscriber = mqttSocket($port);
            array_push($sockets, $publisher, $subscriber);
            mqttWrite($publisher, mqttSessionConnect($version, 'crash-p-' . $version, false, $expiry));
            mqttWrite($subscriber, mqttSessionConnect($version, 'crash-s-' . $version, false, $expiry));
            mqttSessionAck($publisher, false);
            mqttSessionAck($subscriber, false);
            $topic = 'example/crash/' . $version;
            mqttWrite($subscriber, mqttSubscription($version, $topic, 1, 2));
            expect(mqttRead($subscriber) === mqttSubscriptionAck($version, "\x02"), '崩溃恢复订阅失败');
            mqttWrite($publisher, mqttQos2Publish($version, $topic, 'before-crash', 17));
            expect(mqttRead($publisher) === mqttQos2Ack(0x50, 17), '崩溃前没有 PUBREC');
            expect(mqttRead($subscriber) === mqttQos2Publish($version, $topic, 'before-crash', 1), '崩溃前没有 PUBLISH');
            expect(posix_kill($process->pid(), SIGKILL), '无法终止本轮 Broker');
            $process->wait(3);
            fclose($publisher);
            fclose($subscriber);
            $process = $start();
            if ($version === 5) {
                mqttUntil(fn (): bool => (int) $standby->query("SELECT COUNT(*) FROM type_mqtt_sessions WHERE client_id IN ('crash-p-5', 'crash-s-5') AND owner_id IS NULL")->fetchColumn() === 2, '无限期会话没有登记崩溃失联');
                expect((int) $standby->query("SELECT COUNT(*) FROM type_mqtt_sessions WHERE client_id IN ('crash-p-5', 'crash-s-5') AND expires_at IS NULL")->fetchColumn() === 2, 'MQTT 5 的 0xffffffff 在崩溃恢复时变成有限期限');
                $cases++;
            }
            $publisher = mqttSocket($port);
            $subscriber = mqttSocket($port);
            array_push($sockets, $publisher, $subscriber);
            mqttWrite($publisher, mqttSessionConnect($version, 'crash-p-' . $version, false, $expiry));
            mqttWrite($subscriber, mqttSessionConnect($version, 'crash-s-' . $version, false, $expiry));
            mqttSessionAck($publisher, true);
            mqttSessionAck($subscriber, true);
            expect(mqttRead($subscriber) === mqttQos2Publish($version, $topic, 'before-crash', 1, true), '硬重启未重传等待 PUBREC 的原交换');
            mqttWrite($publisher, mqttQos2Publish($version, $topic, 'duplicate', 17, true));
            expect(mqttRead($publisher) === mqttQos2Ack(0x50, 17), '恢复接收状态没有返回原 PUBREC');
            mqttQuiet($subscriber);
            mqttWrite($publisher, mqttQos2Ack(0x62, 17));
            expect(mqttRead($publisher) === mqttQos2Ack(0x70, 17), '恢复接收状态没有终结');
            mqttWrite($subscriber, mqttQos2Ack(0x50, 1));
            expect(mqttRead($subscriber) === mqttQos2Ack(0x62, 1), '恢复发送状态没有 PUBREL');
            mqttWrite($subscriber, mqttQos2Ack(0x70, 1));
            mqttQuiet($subscriber);
            expect((int) $standby->query("SELECT COUNT(*) FROM type_mqtt_messages WHERE topic = '" . $topic . "'")->fetchColumn() === 1, '崩溃恢复重复接管 QoS 2');
            fclose($publisher);
            fclose($subscriber);
            if ($version === 5) {
                mqttUntil(fn (): bool => (int) $standby->query("SELECT COUNT(*) FROM type_mqtt_sessions WHERE client_id IN ('crash-p-5', 'crash-s-5') AND owner_id IS NULL")->fetchColumn() === 2, '无限期会话未保存网络关闭');
                expect((int) $standby->query("SELECT COUNT(*) FROM type_mqtt_sessions WHERE client_id IN ('crash-p-5', 'crash-s-5') AND expires_at IS NULL")->fetchColumn() === 2, 'MQTT 5 的 0xffffffff 在关闭时变成有限期限');
                $cases++;
            }
            $cases += 5;
        }
        $old = mqttSocket($port);
        $sockets[] = $old;
        mqttWrite($old, mqttSessionConnect(5, 'takeover-persistent'));
        mqttSessionAck($old, false);
        mqttWrite($old, mqttSubscription(5, 'example/takeover', 1, 1));
        expect(mqttRead($old) === mqttSubscriptionAck(5, "\x01"), '接管订阅失败');
        $new = mqttSocket($port);
        $sockets[] = $new;
        mqttWrite($new, mqttSessionConnect(5, 'takeover-persistent'));
        expect(mqttRead($old) === "\xe0\x02\x8e\0", '接管未隔离旧连接');
        mqttSessionAck($new, true);
        mqttQuiet($new);
        fclose($old);
        fclose($new);
        $cases++;
        // MQTT 3.1.1 的无限会话不因内部更新时间超过一天而消失。
        $legacy = mqttSocket($port);
        $sockets[] = $legacy;
        mqttWrite($legacy, mqttSessionConnect(4, 'no-internal-ttl'));
        mqttSessionAck($legacy, false);
        mqttWrite($legacy, "\xe0\0");
        expect(mqttRead($legacy) === '', '旧版无限会话未关闭连接');
        fclose($legacy);
        mqttUntil(fn (): bool => $standby->query("SELECT owner_id FROM type_mqtt_sessions WHERE client_id = 'no-internal-ttl'")->fetchColumn() === null, '旧版会话没有离线');
        $primary->exec("UPDATE type_mqtt_sessions SET updated_at = clock_timestamp() - interval '48 hours' WHERE client_id = 'no-internal-ttl'");
        $legacy = mqttSocket($port);
        $sockets[] = $legacy;
        mqttWrite($legacy, mqttSessionConnect(4, 'no-internal-ttl'));
        mqttSessionAck($legacy, true);
        fclose($legacy);
        $cases++;
        $expiring = mqttSocket($port);
        $sockets[] = $expiring;
        mqttWrite($expiring, mqttSessionConnect(5, 'message-expiry'));
        mqttSessionAck($expiring, false);
        mqttWrite($expiring, mqttSubscription(5, 'example/message-expiry', 1, 1));
        expect(mqttRead($expiring) === mqttSubscriptionAck(5, "\x01"), '消息期限订阅失败');
        mqttWrite($expiring, "\xe0\0");
        expect(mqttRead($expiring) === '', '消息期限会话没有离线');
        fclose($expiring);
        $sender = mqttSocket($port);
        $sockets[] = $sender;
        mqttWrite($sender, mqttConnect(5, 'message-expiry-sender'));
        mqttAck($sender, 5);
        mqttWrite($sender, mqttReliablePublish(5, 'example/message-expiry', 'expires', 1, false, "\x02\0\0\0\x03"));
        expect(mqttRead($sender) === mqttQos2Ack(0x40, 1), '消息期限接管失败');
        expect((int) $standby->query("SELECT COUNT(*) FROM type_mqtt_deliveries WHERE client_id = 'message-expiry' AND state = 'pending'")->fetchColumn() === 1, '离线期限夹具未建立真实待交付状态');
        fclose($sender);
        // 等待已持久入队的消息过期；一秒夹具可能在首次接管前已经到期，无法观察恢复清理。
        usleep(3200000);
        $expiring = mqttSocket($port);
        $sockets[] = $expiring;
        mqttWrite($expiring, mqttSessionConnect(5, 'message-expiry'));
        mqttSessionAck($expiring, true);
        mqttUntil(fn (): bool => (int) $standby->query("SELECT COUNT(*) FROM type_mqtt_deliveries WHERE client_id = 'message-expiry' AND state = 'expired'")->fetchColumn() === 1, '离线到期消息未终结');
        mqttQuiet($expiring);
        mqttWrite($expiring, mqttPacket(0xe0, "\0\x05\x11\0\0\0\0"));
        expect(mqttRead($expiring) === '', 'DISCONNECT 未允许将期限修改为零');
        fclose($expiring);
        mqttUntil(fn (): bool => (int) $standby->query("SELECT COUNT(*) FROM type_mqtt_sessions WHERE client_id = 'message-expiry'")->fetchColumn() === 0, 'DISCONNECT 零期限没有清理');
        $cases += 2;
        $cases += mqttSessionRetainedCases($port, $standby);
        $standard = (new Process(['node', $root . '/tests/mqtt-standard-client.mjs', $consumer, (string) $port, 'plain', 'sessions'], $consumer, $environment))->wait(25);
        expect($standard->successful() && $standard->stderr === '', '标准客户端持久恢复失败：' . $standard->stderr);
        echo $standard->stdout;
        $cases += 4;
        $admin = mqttSocket($port);
        $sockets[] = $admin;
        mqttWrite($admin, mqttSessionConnect(5, 'admin-termination', false, 86400, "\x21\0\x01"));
        mqttSessionAck($admin, false);
        mqttWrite($admin, mqttSubscription(5, 'example/admin', 1, 1));
        expect(mqttRead($admin) === mqttSubscriptionAck(5, "\x01"), '管理员终止前订阅失败');
        $sender = mqttSocket($port);
        $sockets[] = $sender;
        mqttWrite($sender, mqttConnect(5, 'admin-window-sender'));
        mqttAck($sender, 5);
        mqttWrite($sender, mqttReliablePublish(5, 'example/admin', 'full-window', 1));
        expect(mqttRead($sender) === mqttQos2Ack(0x40, 1), '管理员满窗口用例没有接管');
        expect(mqttRead($admin) === mqttReliablePublish(5, 'example/admin', 'full-window', 1), '管理员用例未填满发送窗口');
        fclose($sender);
        $terminated = (new Process([...$command, '--terminate-session=admin-termination', '--actor=integration-admin'], $consumer, $environment))->wait(10);
        expect($terminated->successful() && json_decode($terminated->stdout, true, 32, JSON_THROW_ON_ERROR)['state'] === 'committed', '管理员终止未持久确认');
        expect(mqttRead($admin) === "\xe0\x02\x83\0", '管理员终止后满窗口的旧网络所有者仍有效');
        expect($standby->query("SELECT actor FROM type_mqtt_session_audit WHERE client_id = 'admin-termination'")->fetchColumn() === 'integration-admin', '管理员审计身份丢失');
        fclose($admin);
        $cases += 2;
        // 恢复旧会话时改成零期限仍需先排空旧队列；新消息不能越过它或被错误拒绝。
        $zero = mqttSocket($port);
        $sockets[] = $zero;
        mqttWrite($zero, mqttSessionConnect(5, 'restore-then-zero'));
        mqttSessionAck($zero, false);
        mqttWrite($zero, mqttSubscription(5, 'example/restore-zero', 1, 1));
        expect(mqttRead($zero) === mqttSubscriptionAck(5, "\x01"), '零期限恢复订阅失败');
        mqttWrite($zero, "\xe0\0");
        expect(mqttRead($zero) === '', '零期限恢复前没有离线');
        fclose($zero);
        $sender = mqttSocket($port);
        $sockets[] = $sender;
        mqttWrite($sender, mqttConnect(5, 'restore-zero-sender'));
        mqttAck($sender, 5);
        foreach ([1, 2] as $sequence) {
            mqttWrite($sender, mqttReliablePublish(5, 'example/restore-zero', 'queued-' . $sequence, $sequence));
            expect(mqttRead($sender) === mqttQos2Ack(0x40, $sequence), '零期限恢复的旧队列未保存');
        }
        $zero = mqttSocket($port);
        $sockets[] = $zero;
        mqttWrite($zero, mqttSessionConnect(5, 'restore-then-zero', false, 0, "\x21\0\x01"));
        mqttSessionAck($zero, true);
        expect(mqttRead($zero) === mqttReliablePublish(5, 'example/restore-zero', 'queued-1', 1), '零期限恢复未保留旧队列');
        mqttWrite($sender, mqttReliablePublish(5, 'example/restore-zero', 'queued-3', 3));
        expect(mqttRead($sender) === mqttQos2Ack(0x40, 3), '恢复期间零期限会话误拒新消息');
        foreach ([2, 3] as $sequence) {
            mqttWrite($zero, mqttQos2Ack(0x40, 1));
            expect(mqttRead($zero) === mqttReliablePublish(5, 'example/restore-zero', 'queued-' . $sequence, 1), '恢复期间新旧队列失序');
        }
        mqttWrite($zero, mqttQos2Ack(0x40, 1));
        mqttQuiet($zero);
        fclose($sender);
        fclose($zero);
        $cases += 3;
        $authorization = mqttSocket($port);
        $sockets[] = $authorization;
        mqttWrite($authorization, mqttSessionConnect(5, 'authorization-resume'));
        mqttSessionAck($authorization, false);
        mqttWrite($authorization, mqttSubscription(5, 'example/authorization', 1, 1));
        expect(mqttRead($authorization) === mqttSubscriptionAck(5, "\x01"), '恢复授权用例订阅失败');
        mqttWrite($authorization, "\xe0\0");
        expect(mqttRead($authorization) === '', '恢复授权用例未断线');
        fclose($authorization);
        mqttUntil(fn (): bool => $standby->query("SELECT owner_id FROM type_mqtt_sessions WHERE client_id = 'authorization-resume'")->fetchColumn() === null, '恢复授权会话未离线');
        // 受控旧状态夹具：存储中的订阅已不在当前 AccessPolicy 的授权范围内。
        $primary->exec("UPDATE type_mqtt_sessions SET subscriptions = '{\"t:denied/topic\":1}'::jsonb WHERE client_id = 'authorization-resume'");
        $bad = mqttSocket($port);
        $sockets[] = $bad;
        mqttWrite($bad, mqttConnect(5, 'authorization-resume', 10, '', 'revoked-password'));
        $denied = mqttRead($bad);
        expect(ord($denied[3]) === 0x86, '旧凭据恢复绕过 CONNECT 认证');
        fclose($bad);
        $authorization = mqttSocket($port);
        $sockets[] = $authorization;
        mqttWrite($authorization, mqttSessionConnect(5, 'authorization-resume'));
        mqttSessionAck($authorization, true);
        expect(json_decode($standby->query("SELECT subscriptions::text FROM type_mqtt_sessions WHERE client_id = 'authorization-resume'")->fetchColumn(), true) === [], '恢复重新激活了不再获准的订阅');
        mqttWrite($authorization, "\xe0\0");
        expect(mqttRead($authorization) === '', '授权恢复未关闭');
        fclose($authorization);
        mqttUntil(fn (): bool => $standby->query("SELECT owner_id FROM type_mqtt_sessions WHERE client_id = 'authorization-resume'")->fetchColumn() === null, '授权恢复未保存断线');
        $standby = null;
        $sync->stopStandby();
        $unavailable = mqttSocket($port);
        $sockets[] = $unavailable;
        mqttWrite($unavailable, mqttSessionConnect(5, 'authorization-resume'));
        $refused = mqttRead($unavailable);
        expect(ord($refused[3]) === 0x88, '缺同步证明仍确认会话恢复');
        fclose($unavailable);
        $sync->restartStandby();
        $standby = $sync->standby();
        $restored = mqttSocket($port);
        $sockets[] = $restored;
        mqttWrite($restored, mqttSessionConnect(5, 'authorization-resume', false, 0));
        mqttSessionAck($restored, true);
        mqttQuiet($restored);
        fclose($restored);
        $cases += 4;
        $result = $process->stop(12);
        expect($result->successful() && $result->stderr === '', '会话 Broker 资源清理失败：' . $result->stderr);
        return ['cases' => $cases, 'subscription-cases' => $subscriptionCases, 'replication' => $sync->evidence(), 'statistics' => json_decode($result->stdout, true, 32, JSON_THROW_ON_ERROR)];
    } finally {
        foreach ($sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        $process?->stop(12);
        $primary = null;
        $standby = null;
        $sync?->close();
        $database->close();
    }
}
