<?php

declare(strict_types=1);

use Type\Testing\Process;

/** 独立测试消费者通过公开策略允许套件Topic；组件和示例本身不增加测试开关。 */
function mqttConformanceApplication(string $root): string
{
    $source = file_get_contents($root . '/examples/mqtt/main.php');
    expect(substr_count($source, 'new ExampleMqttAccess()') === 1, '独立授权装配入口变化');
    return str_replace('new ExampleMqttAccess()', 'new ConformanceTestAccess()', $source) . <<<'PHP'


/** 只安装于隔离测试应用；仍使用示例凭据，允许标准套件的非业务Topic。 */
final class ConformanceTestAccess implements \Type\Mqtt\AccessPolicy
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
        return $action !== 'subscribe' || $topic !== 'test/nosubscribe';
    }
}
PHP;
}

/** 真实同步主备、完整安装产物及TLS入口；工具失败保留报告，绝不改成协议通过。 */
function mqttConformanceCases(string $root, string $consumer, array $command, array $workerCommand, array $environment): array
{
    require_once $root . '/tests/mqtt-qos1.php';
    require_once $root . '/tests/native-database.php';
    require_once $root . '/tests/postgres-sync.php';
    $tools = NativeDatabase::tools('pgsql', (string) getenv('TYPE_PGSQL_TOOLS'));
    $database = new NativeDatabase($consumer . '/conformance-primary', 'pgsql', $tools);
    $sync = null;
    $broker = null;
    $client = null;
    try {
        $sync = new PostgresSync($database, $consumer . '/conformance-standby', $tools);
        $environment = array_replace($environment, $database->environment());
        $environment['MQTT_CERTIFICATE'] = $consumer . '/certificate.pem';
        $environment['MQTT_PRIVATE_KEY'] = $consumer . '/private.pem';
        $environment['MQTT_WORKER_COMMAND'] = json_encode($workerCommand, JSON_THROW_ON_ERROR);
        $installation = new Process([...$command, '--install-store'], $consumer, $environment);
        try {
            $installed = $installation->wait(15);
            expect($installed->successful() && $installed->stderr === '', '一致性装置迁移失败：' . $installed->stderr);
            $proof = json_decode($installed->stdout, true, 32, JSON_THROW_ON_ERROR);
            expect($proof['state'] === 'committed' && $proof['released'], '一致性装置没有同步安装证明');
        } finally {
            $installation->stop();
        }
        $listener = stream_socket_server('tcp://127.0.0.1:0', $number, $error);
        $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        fclose($listener);
        $broker = new Process([...$command, '--port=' . $port], $consumer, $environment);
        mqttUntil(function () use ($port, $broker, $consumer): bool {
            expect($broker->running(), '一致性Broker提前退出：' . $broker->stderr());
            try {
                $socket = mqttSocket($port, $consumer . '/certificate.pem');
                mqttWrite($socket, mqttConnect(5, 'conformance-ready'));
                mqttAck($socket, 5);
                fclose($socket);
                return true;
            } catch (RuntimeException) {
                return false;
            }
        }, '一致性Broker未就绪', 10.0);
        $client = new Process([getenv('TYPE_PYTHON') ?: 'python3', $root . '/tests/mqtt-conformance.py', 'run',
            '--port', (string) $port, '--ca', $consumer . '/certificate.pem', '--output', $consumer . '/conformance',
            '--paho', getenv('TYPE_PAHO_TESTING') ?: $root . '/build/paho-testing',
            '--mosquitto', getenv('TYPE_MOSQUITTO_SOURCE') ?: $root . '/build/mosquitto',
            '--mosquitto-bin', getenv('TYPE_MOSQUITTO_BIN') ?: $root . '/build/mosquitto-build/client',
            '--select', getenv('TYPE_MQTT_CONFORMANCE_TOOLS') ?: 'cli,paho,mosquitto,probe'], $consumer, $environment);
        $result = $client->wait(1800);
        file_put_contents($consumer . '/conformance-client.stdout.log', $result->stdout);
        file_put_contents($consumer . '/conformance-client.stderr.log', $result->stderr);
        expect($result->exitCode === 0 || $result->exitCode === 1, '一致性工具未正常形成报告：' . $result->stderr);
        $report = json_decode(file_get_contents($consumer . '/conformance/report.json'), true, 512, JSON_THROW_ON_ERROR);
        $stopped = $broker->stop(15);
        file_put_contents($consumer . '/conformance-broker.stdout.log', $stopped->stdout);
        file_put_contents($consumer . '/conformance-broker.stderr.log', $stopped->stderr);
        expect($stopped->successful() && $stopped->stderr === '', '一致性Broker未正常退出：' . $stopped->stderr);
        $statistics = json_decode($stopped->stdout, true, 512, JSON_THROW_ON_ERROR);
        expect($statistics['connections'] === 0 && $statistics['pendingCommits'] === 0 && $statistics['quarantinedCommits'] === 0, '一致性资源未归零');
    } finally {
        $client?->stop();
        $broker?->stop(15);
        $sync?->close();
        $database->close();
    }
    return ['status' => $report['status'], 'report' => 'conformance/report.json', 'sha256' => hash_file('sha256', $consumer . '/conformance/report.json'),
        'statistics' => $statistics, 'replication' => $sync->evidence()];
}
