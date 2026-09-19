<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';
require __DIR__ . '/postgres-sync.php';

use app\iot\service\DeviceService;
use app\iot\service\IngestionService;
use Type\Mqtt\Client;
use Type\Mqtt\Message;
use Type\Testing\Process;

/** 整份Broker示例与生产IoT域一起编译；只在公开AccessPolicy边界提供隔离测试身份。 */
function simulatorApplication(string $root): string
{
    return str_replace(['function main(', 'new ExampleMqttAccess()'], ['function brokerMain(', 'new SimulatorTestAccess()'], file_get_contents($root . '/examples/mqtt/main.php')) . <<<'PHP'


/** 隔离装置的设备/回执发送者凭据；不替代正式平台接收与持久证明。 */
final class SimulatorTestAccess implements Type\Mqtt\AccessPolicy
{
    public function authenticate(Type\Mqtt\ConnectPacket $connect, string $peer, bool $secure): bool
    {
        $identity = preg_match('/^[a-f0-9]{32}$/D', $connect->clientId) && $connect->username === $connect->clientId . ':' . str_repeat('d', 32)
            && $connect->keepAlive === 30 && ($connect->properties[0x11] ?? null) === 86400;
        return $secure && $connect->version === 5 && ($identity || ($connect->clientId === 'simulator-verifier' && $connect->username === 'verifier'))
            && $connect->password !== null && hash_equals((string) getenv('SIMULATOR_SECRET'), $connect->password);
    }

    public function authorize(Type\Mqtt\ConnectPacket $connect, string $topic, string $action, int $qos): bool
    {
        if (!preg_match('~^iot/b{32}/devices/[a-f0-9]{32}/epochs/c{32}/(?:up|down)$~D', $topic)) {
            return false;
        }
        if ($connect->clientId === 'simulator-verifier') {
            return str_ends_with($topic, $action === 'publish' ? '/down' : '/up');
        }
        $topics = app\iot\service\DeviceService::topics(['id' => $connect->clientId, 'tenant_id' => str_repeat('b', 32), 'ownership_id' => str_repeat('c', 32)]);
        return $topic === $topics[$action === 'publish' ? 'publish' : 'subscribe'];
    }
}

function simulatorDevice(): void
{
    $device = (string) getenv('SIMULATOR_DEVICE');
    $buffer = new app\iot\service\DeviceBuffer((string) getenv('SIMULATOR_DIRECTORY') . '/' . $device . '.sqlite', $device, str_repeat('c', 32), 8640, 8640000, 1);
    $simulator = new app\iot\service\DeviceSimulator($buffer, str_repeat('b', 32), (int) getenv('SIMULATOR_MODEL'), str_repeat('d', 32),
        (string) getenv('SIMULATOR_SECRET'), '127.0.0.1', (int) getenv('SIMULATOR_PORT'), (string) getenv('SIMULATOR_CA'), (string) getenv('SIMULATOR_PEER'));
    $original = [];
    $error = '';
    $result = null;
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function (int $signal, array $information) use ($simulator): void { $simulator->stop(); });
    try {
        for ($index = 0; $index < (int) getenv('SIMULATOR_SEED'); $index++) {
            $values = (object) ['temperature' => 20.0 + $index, 'note' => '固定原件'];
            if ((string) getenv('SIMULATOR_LARGE') === 'yes') {
                $envelope = ['app_version' => 1, 'type' => 'telemetry', 'device_id' => $device, 'ownership_id' => str_repeat('c', 32),
                    'model_version' => (int) getenv('SIMULATOR_MODEL'), 'sequence' => (string) ($index + 1), 'sampled_at' => 1, 'values' => $values];
                $values->note .= str_repeat('x', 16384 - strlen(json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)));
            }
            $original[] = $simulator->enqueue('telemetry', 1, $values);
        }
        echo json_encode(['initial' => $buffer->pending(100)], JSON_THROW_ON_ERROR), "\n";
        $result = $simulator->run(20.0, (float) getenv('SIMULATOR_RECEIPT_SECONDS'));
    } catch (Throwable $failure) {
        $error = $failure->getMessage();
    } finally {
        pcntl_signal(SIGTERM, SIG_DFL);
        $simulator->close();
    }
    echo json_encode(['result' => $result, 'error' => $error, 'statistics' => $simulator->statistics(),
        'pending' => $buffer->pending(100), 'exceptions' => $buffer->exceptions()], JSON_THROW_ON_ERROR), "\n";
    $buffer->close();
}

function main(int $argc, array $argv): void
{
    if (in_array('--simulator', $argv, true)) {
        simulatorDevice();
        return;
    }
    brokerMain($argc, $argv);
}
PHP;
}

function simulatorTopics(string $device): array
{
    return DeviceService::topics(['id' => $device, 'tenant_id' => str_repeat('b', 32), 'ownership_id' => str_repeat('c', 32)]);
}

function simulatorReceipt(string $payload, string $code = 'accepted'): array
{
    $envelope = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
    return ['app_version' => 1, 'type' => 'ingestion_receipt', 'device_id' => $envelope['device_id'], 'ownership_id' => $envelope['ownership_id'],
        'sequence' => $envelope['sequence'], 'content_hash' => IngestionService::contentHash($payload),
        'status' => $code === 'accepted' ? 'accepted' : 'rejected', 'code' => $code, 'received_at' => 1800000000];
}

/** 只等待本次设备明确输出已持久入队的原件，不推测睡眠时长代表连接/提交完成。 */
function simulatorInitial(Process $device): array
{
    $until = microtime(true) + 10.0;
    do {
        $output = $device->stdout();
        if (str_contains($output, "\n")) {
            return json_decode(explode("\n", $output)[0], true, 32, JSON_THROW_ON_ERROR)['initial'];
        }
        expect($device->running(), '模拟器在准备缓存前退出：' . $device->stderr());
        usleep(10000);
    } while (microtime(true) < $until);
    throw new RuntimeException('模拟器没有报告本地入队');
}

function simulatorFinished(Process $device, string $evidence): array
{
    $result = $device->wait(25);
    file_put_contents($evidence, $result->stdout . $result->stderr);
    expect($result->successful() && $result->stderr === '', '模拟器角色异常退出：' . $result->stderr);
    $lines = explode("\n", trim($result->stdout));
    $observation = json_decode($lines[count($lines) - 1], true, 32, JSON_THROW_ON_ERROR);
    $network = $observation['statistics']['network'];
    expect(!$observation['statistics']['running'] && $observation['statistics']['closed'] && !$network['connected']
        && $network['buffered_bytes'] === 0 && $network['queued'] === 0 && $network['unacknowledged'] === 0, '模拟器未归还网络资源');
    return $observation;
}

$root = dirname(__DIR__);
$native = in_array('--native', $argv, true);
$base = $root . '/build/iot-device-simulator-' . bin2hex(random_bytes(6));
expect(mkdir($base . '/app/iot', 0700, true), '无法创建独立模拟器验收目录');
$sourceCount = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app/iot', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $source) {
    $target = $base . '/app/iot' . substr($source->getPathname(), strlen($root . '/app/iot'));
    if ($source->isDir()) {
        expect(mkdir($target, 0700), '无法复制IoT生产目录');
    } else {
        expect(copy($source->getPathname(), $target), '无法复制IoT生产实现');
        $sourceCount++;
    }
}
file_put_contents($base . '/app/main.php', simulatorApplication($root));
$composer = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$toolchain = json_decode(file_get_contents($root . '/toolchain.lock.json'), true, 512, JSON_THROW_ON_ERROR);
$repositories = $composer['repositories'];
$repositories[0]['url'] = '../../plugin/*';
$repositories[0]['options']['symlink'] = false;
$configuration = ['name' => 'type-tests/device-simulator', 'type' => 'project', 'license' => 'Apache-2.0', 'require' => $composer['require'],
    'require-dev' => ['zoujingli/type-build' => '~1.0.0@dev', 'swoole/typephp' => $toolchain['typephp']['version'], 'swoole/phpx' => $toolchain['phpx']['version']],
    'autoload' => ['psr-4' => ['app\\iot\\' => 'app/iot/'], 'classmap' => ['app/main.php']], 'repositories' => $repositories,
    'minimum-stability' => 'dev', 'prefer-stable' => true, 'config' => ['allow-plugins' => false]];
file_put_contents($base . '/composer.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
copy($root . '/toolchain.lock.json', $base . '/toolchain.lock.json');
successful([getenv('COMPOSER_BINARY') ?: 'composer', 'install', '--no-interaction', '--no-plugins', '--no-scripts', '--no-progress'], $base);
expect(!is_link($base . '/vendor/zoujingli/type-mqtt'), '模拟器验收不能借用主仓组件软链接');
$command = [PHP_BINARY, '-r', 'require "vendor/autoload.php"; require "app/main.php"; main($argc, $argv);', '--'];
$workerCommand = $command;
$report = null;
$noSource = 'not-verified';
if ($native) {
    file_put_contents($base . '/type-app.json', json_encode(['name' => 'iot-device-simulator', 'entry' => 'app/main.php', 'sources' => ['app'],
        'output' => 'build/native/type-app', 'build-directory' => 'build/native/compiler'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    file_put_contents($base . '/build.log', successful([PHP_BINARY, $base . '/vendor/bin/type', $base . '/type-app.json'], $base));
    $report = json_decode(file_get_contents($base . '/build/native/type-app.build.json'), true, 512, JSON_THROW_ON_ERROR);
    $command = nativeCommand($base . '/build/native/type-app');
    $workerCommand = $command;
    if (PHP_OS_FAMILY === 'Darwin') {
        expect(mkdir($base . '/runtime', 0700), '无法准备搬迁原生目录');
        copy($base . '/build/native/type-app', $base . '/runtime/type-app');
        chmod($base . '/runtime/type-app', 0700);
        copy($report['runtime-profile']['ini'], $base . '/runtime/php.ini');
        $policy = ['sandbox-exec', '-f', $root . '/tests/fixtures/mqtt-no-source.sb'];
        foreach (['ROOT_APP' => $root . '/app', 'ROOT_PLUGIN' => $root . '/plugin', 'ROOT_EXAMPLE' => $root . '/examples', 'ROOT_VENDOR' => $root . '/vendor',
            'APP' => $base . '/app', 'VENDOR' => $base . '/vendor', 'COMPILER' => $base . '/build/native/compiler',
            'ROOT_COMPOSER' => $root . '/composer.json', 'COMPOSER' => $base . '/composer.json'] as $name => $path) {
            array_push($policy, '-D', $name . '=' . $path);
        }
        $probe = 'foreach (array_slice($argv, 1) as $path) { if (@file_get_contents($path) !== false) { throw new RuntimeException("source-readable"); } } echo "denied\n";';
        expect(successful([...$policy, PHP_BINARY, '-n', '-r', $probe, $root . '/app/iot/service/DeviceSimulator.php', $base . '/app/main.php', $base . '/vendor/autoload.php'], $base) === "denied\n", '模拟器禁读源码探针失败');
        $workerCommand = ['env', 'PHPRC=' . $base . '/runtime/php.ini', 'PHP_INI_SCAN_DIR=', $base . '/runtime/type-app'];
        $command = [...$policy, ...$workerCommand];
        $noSource = 'kernel-denied-production-and-generated-source';
    }
}

$certificateConfiguration = $base . '/certificate.cnf';
file_put_contents($certificateConfiguration, "[req]\ndistinguished_name=dn\nx509_extensions=server\n[dn]\n[server]\nsubjectAltName=IP:127.0.0.1\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\n");
$certificateOptions = ['config' => $certificateConfiguration, 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
$key = openssl_pkey_new($certificateOptions);
$request = openssl_csr_new(['commonName' => '127.0.0.1'], $key, $certificateOptions);
$certificate = openssl_csr_sign($request, null, $key, 1, $certificateOptions);
openssl_x509_export($certificate, $certificatePem);
openssl_pkey_export($key, $privatePem);
file_put_contents($base . '/certificate.pem', $certificatePem);
file_put_contents($base . '/private.pem', $privatePem);
chmod($base . '/private.pem', 0600);
$tools = NativeDatabase::tools('pgsql', (string) getenv('TYPE_PGSQL_TOOLS'));
$database = new NativeDatabase($base . '/primary', 'pgsql', $tools);
$sync = null;
$broker = null;
$device = null;
$verifier = null;
$checks = [];
try {
    $sync = new PostgresSync($database, $base . '/standby', $tools);
    $environment = array_replace(getenv(), $database->environment());
    $environment['MQTT_WORKER_COMMAND'] = json_encode($workerCommand, JSON_THROW_ON_ERROR);
    $environment['MQTT_CERTIFICATE'] = $base . '/certificate.pem';
    $environment['MQTT_PRIVATE_KEY'] = $base . '/private.pem';
    $environment['MQTT_PRIVATE_KEY_PASSPHRASE'] = '';
    $environment['SIMULATOR_DIRECTORY'] = $base;
    $environment['SIMULATOR_CA'] = $base . '/certificate.pem';
    $environment['SIMULATOR_PEER'] = '';
    $environment['SIMULATOR_SECRET'] = bin2hex(random_bytes(32));
    $environment['SIMULATOR_MODEL'] = '7';
    $environment['SIMULATOR_RECEIPT_SECONDS'] = '5';
    $installed = (new Process([...$command, '--install-store'], $base, $environment))->wait(10);
    expect($installed->successful() && $installed->stderr === '' && json_decode($installed->stdout, true, 32, JSON_THROW_ON_ERROR)['state'] === 'committed', '模拟器Broker安装失败：' . $installed->stderr);
    $listener = stream_socket_server('tcp://127.0.0.1:0', $number, $error);
    $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
    fclose($listener);
    $environment['SIMULATOR_PORT'] = (string) $port;
    $startBroker = static function () use ($command, $base, $environment, $port): Process {
        $process = new Process([...$command, '--port=' . $port], $base, $environment);
        $until = microtime(true) + 10.0;
        do {
            expect($process->running(), '模拟器Broker提前退出：' . $process->stderr());
            $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $number, $error, 0.1);
            if (is_resource($probe)) {
                fclose($probe);
                return $process;
            }
            usleep(10000);
        } while (microtime(true) < $until);
        $process->stop();
        throw new RuntimeException('模拟器Broker启动超时');
    };
    $broker = $startBroker();
    $verifier = new Client('127.0.0.1', $port, 'simulator-verifier', 'verifier', $environment['SIMULATOR_SECRET'], $base . '/certificate.pem');
    $verifier->connect(true);
    $startDevice = static function (string $name, int $seed = 1, array $overrides = []) use ($command, $base, $environment): Process {
        return new Process([...$command, '--simulator'], $base, array_replace($environment, ['SIMULATOR_DEVICE' => md5($name), 'SIMULATOR_SEED' => (string) $seed], $overrides));
    };
    $observations = [];
    $up = static function (string $name) use ($verifier, &$observations): string {
        $until = microtime(true) + 8.0;
        do {
            $delivery = $verifier->receive(max(0.001, $until - microtime(true)));
            expect($delivery !== null && $delivery['message']->qos === 1, '没有收到对应模拟器原件：' . $name);
            $envelope = json_decode($delivery['message']->payload, true, 32, JSON_THROW_ON_ERROR);
            $verifier->acknowledge($delivery['receipt']);
            if (($envelope['type'] ?? '') === 'device_status') {
                expect($delivery['message']->topic === simulatorTopics($envelope['device_id'])['publish']
                    && strlen($delivery['message']->payload) <= 2048 && is_int($envelope['values']['not_admitted'])
                    && is_bool($envelope['values']['full']), '状态没有使用本设备同一连接的有界QoS1上行');
                $observations[$envelope['device_id']] = $envelope;
                continue;
            }
            expect($delivery['message']->topic === simulatorTopics(md5($name))['publish'], '收到其他模拟器原件：' . $name);
            return $delivery['message']->payload;
        } while (microtime(true) < $until);
        throw new RuntimeException('状态观察后没有原始上报：' . $name);
    };
    $reply = static function (string $name, array $receipt, bool $retain = false, ?string $wire = null) use ($verifier): void {
        $verifier->publish(new Message(simulatorTopics(md5($name))['subscribe'], $wire ?? json_encode($receipt, JSON_THROW_ON_ERROR), '', 1, 0, $retain));
    };

    $name = 'lost-business-receipt';
    $verifier->subscribe(simulatorTopics(md5($name))['publish']);
    $device = $startDevice($name, 1, ['SIMULATOR_RECEIPT_SECONDS' => '1']);
    $original = simulatorInitial($device);
    $payload = $up($name);
    expect($payload === $original[0]['payload'], '首次发布改变入队原字节');
    $waiting = simulatorFinished($device, $base . '/lost-receipt.log');
    expect($waiting['error'] === 'device_simulator_receipt_timeout' && $waiting['pending'] === $original
        && !$waiting['statistics']['network']['publication_unknown'] && $waiting['statistics']['buffer']['accepted_total'] === 0, 'PUBACK或超时错误删除本地缓存');
    $checks[] = 'tls-puback-is-not-business-receipt';
    $device = $startDevice($name, 0, ['SIMULATOR_MODEL' => '8']);
    expect(simulatorInitial($device) === $original && $up($name) === $payload, '重启改写旧模型或原字节');
    $reply($name, simulatorReceipt($payload));
    $restored = simulatorFinished($device, $base . '/replayed.log');
    expect($restored['error'] === '' && $restored['pending'] === [] && $restored['result']['accepted'] === 1
        && $restored['statistics']['buffer']['accepted_total'] === 1, '合法accepted未清理一次');
    $checks[] = 'cross-process-replay-keeps-identity-model-and-bytes';

    $reply($name, simulatorReceipt($payload));
    $device = $startDevice($name, 0);
    expect(simulatorInitial($device) === [], '终局记录重新进入待补传集合');
    $duplicate = simulatorFinished($device, $base . '/duplicate-terminal.log');
    expect($duplicate['error'] === '' && $duplicate['result']['accepted'] === 0 && $duplicate['statistics']['buffer']['accepted_total'] === 1, '终局回执重放被重复计数');
    $receiptProbe = new Client('127.0.0.1', $port, md5($name), md5($name) . ':' . str_repeat('d', 32), $environment['SIMULATOR_SECRET'], $base . '/certificate.pem');
    try {
        $receiptProbe->connect(false);
        expect($receiptProbe->receive(1.0) === null, '缓存已空时没有确认重复终局回执');
    } finally {
        $receiptProbe->close();
    }
    $checks[] = 'empty-cache-acknowledges-terminal-replay-once';

    foreach (['wrong-device', 'wrong-hash', 'duplicate-key', 'retained'] as $invalid) {
        $verifier->subscribe(simulatorTopics(md5($invalid))['publish']);
        $device = $startDevice($invalid);
        $original = simulatorInitial($device);
        $payload = $up($invalid);
        $receipt = simulatorReceipt($payload);
        if ($invalid === 'wrong-device') {
            $receipt['device_id'] = str_repeat('0', 32);
        }
        if ($invalid === 'wrong-hash') {
            $receipt['content_hash'] = str_repeat('0', 64);
        }
        $wire = $invalid === 'duplicate-key' ? '{"device_id":"' . str_repeat('0', 32) . '",' . substr(json_encode($receipt, JSON_THROW_ON_ERROR), 1) : null;
        $reply($invalid, $receipt, $invalid === 'retained', $wire);
        $rejected = simulatorFinished($device, $base . '/' . $invalid . '.log');
        expect($rejected['error'] !== '' && $rejected['pending'] === $original && $rejected['statistics']['buffer']['accepted_total'] === 0, '非法回执清理本地记录：' . $invalid);
        $checks[] = 'reject-' . $invalid;
    }

    $name = 'permanent-rejections';
    $verifier->subscribe(simulatorTopics(md5($name))['publish']);
    $device = $startDevice($name, 2);
    simulatorInitial($device);
    foreach (['sample_expired', 'clock_ahead'] as $reason) {
        $reply($name, simulatorReceipt($up($name), $reason));
    }
    $rejections = simulatorFinished($device, $base . '/permanent.log');
    expect($rejections['error'] === '' && $rejections['pending'] === [] && $rejections['result']['rejected'] === 2
        && $rejections['statistics']['buffer']['accepted_total'] === 0 && $rejections['statistics']['buffer']['exceptions_dropped'] === 1
        && count($rejections['exceptions']) === 1, '永久拒绝伪装成功或异常无限增长');
    $checks[] = 'terminal-rejection-and-bounded-exception-overflow';

    $name = 'maximum-payload';
    $verifier->subscribe(simulatorTopics(md5($name))['publish']);
    $device = $startDevice($name, 1, ['SIMULATOR_LARGE' => 'yes']);
    $original = simulatorInitial($device);
    $payload = $up($name);
    expect(strlen($payload) === 16384 && $payload === $original[0]['payload'], '最大载荷没有按原字节上网');
    $reply($name, simulatorReceipt($payload));
    expect(simulatorFinished($device, $base . '/maximum.log')['error'] === '', '16KiB回执闭环失败');
    $checks[] = '16k-original-payload-over-tls';

    $name = 'local-receipt-write-failure';
    $verifier->subscribe(simulatorTopics(md5($name))['publish']);
    $device = $startDevice($name);
    $original = simulatorInitial($device);
    $payload = $up($name);
    $local = new PDO('sqlite:' . $base . '/' . md5($name) . '.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $local->exec("CREATE TRIGGER reject_receipt_delete BEFORE DELETE ON device_buffer_messages BEGIN SELECT RAISE(ABORT, 'receipt-write-failed'); END");
    $reply($name, simulatorReceipt($payload));
    $writeFailure = simulatorFinished($device, $base . '/local-failure.log');
    expect($writeFailure['error'] !== '' && $writeFailure['pending'] === $original && $writeFailure['statistics']['buffer']['accepted_total'] === 0, '本地回执事务失败仍然清理');
    $local->exec('DROP TRIGGER reject_receipt_delete');
    $local = null;
    $device = $startDevice($name, 0);
    expect(simulatorInitial($device) === $original && $up($name) === $payload, '本地回执失败后未保留原件');
    // 不再发送业务回执：Broker必须恢复前次未获MQTT确认的原回执。
    $recovered = simulatorFinished($device, $base . '/local-recovery.log');
    expect($recovered['error'] === '' && $recovered['result']['accepted'] === 1 && $recovered['pending'] === [], '回执在本地事务之前已被MQTT确认');
    $checks[] = 'local-commit-precedes-downlink-mqtt-ack';

    foreach (['signal-stop', 'hard-kill'] as $name) {
        $verifier->subscribe(simulatorTopics(md5($name))['publish']);
        $device = $startDevice($name);
        $original = simulatorInitial($device);
        $payload = $up($name);
        expect(is_int($device->pid()) && posix_kill($device->pid(), $name === 'hard-kill' ? SIGKILL : SIGTERM), '无法停止本次模拟器');
        if ($name === 'signal-stop') {
            $stopped = simulatorFinished($device, $base . '/stopped.log');
            expect($stopped['error'] === '' && $stopped['result']['stopped'] && $stopped['pending'] === $original, '停止未返回或错误清理缓存');
        } else {
            $device->wait(5);
        }
        $device = $startDevice($name, 0);
        expect(simulatorInitial($device) === $original && $up($name) === $payload, '停止/强杀后改写缓存身份');
        $reply($name, simulatorReceipt($payload));
        expect(simulatorFinished($device, $base . '/' . $name . '-recovery.log')['pending'] === [], '停止/强杀后无法恢复补传');
        $checks[] = $name . '-retains-cache-and-recovers';
    }

    foreach (['wrong-hostname', 'untrusted-ca'] as $name) {
        $device = $startDevice($name, 1, $name === 'wrong-hostname' ? ['SIMULATOR_PEER' => 'wrong.invalid'] : ['SIMULATOR_CA' => '']);
        $original = simulatorInitial($device);
        $tlsFailure = simulatorFinished($device, $base . '/' . $name . '.log');
        expect($tlsFailure['error'] === 'mqtt_client_tls_failed' && $tlsFailure['pending'] === $original, '无效TLS证书或主机名被接受');
        $checks[] = 'reject-' . $name;
    }

    $name = 'network-disconnect';
    $verifier->subscribe(simulatorTopics(md5($name))['publish']);
    $device = $startDevice($name);
    $original = simulatorInitial($device);
    $payload = $up($name);
    $lostBroker = $broker->stop(12);
    expect($lostBroker->successful() && $lostBroker->stderr === '', '网络断开装置未停止自己的Broker');
    $lostNetwork = simulatorFinished($device, $base . '/network-disconnect.log');
    expect($lostNetwork['error'] !== '' && $lostNetwork['pending'] === $original, '网络失联自动重试或清理了缓存');
    $verifier->close(false);
    $broker = $startBroker();
    $verifier->connect(true);
    $verifier->subscribe(simulatorTopics(md5($name))['publish']);
    $device = $startDevice($name, 0);
    expect(simulatorInitial($device) === $original && $up($name) === $payload, '网络恢复后原件被改写');
    $reply($name, simulatorReceipt($payload));
    expect(simulatorFinished($device, $base . '/network-recovery.log')['pending'] === [], '网络恢复后不能显式补传');
    $checks[] = 'network-disconnect-and-explicit-recovery';
    $verifier->close();
    $stoppedBroker = $broker->stop(12);
    expect($stoppedBroker->successful() && $stoppedBroker->stderr === '', '模拟器Broker未完整停止：' . $stoppedBroker->stderr);
    $brokerStatistics = json_decode($stoppedBroker->stdout, true, 32, JSON_THROW_ON_ERROR);
    foreach (['connections', 'subscriptions', 'bufferedBytes', 'pendingCommits', 'closingSessions'] as $field) {
        expect($brokerStatistics[$field] === 0, '模拟器Broker停止后未回收：' . $field);
    }
    expect(count($observations) >= 5, '真实TLS链路未观察到多个设备缓存状态');
    $checks[] = 'bounded-status-same-tls-topic';
    $evidence = ['mode' => $native ? 'aot' : 'php', 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'no-source-runtime' => $noSource,
        'observed-devices' => count($observations),
        'iot-sources' => $sourceCount, 'checks' => $checks, 'broker-statistics' => $brokerStatistics,
        'build' => $report === null ? null : array_intersect_key($report, array_flip(['build-id', 'sha256', 'typephp', 'phpx', 'production-packages']))];
    file_put_contents($base . '/verification.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    echo ($native ? '原生' : 'PHP') . '设备模拟器真实TLS验证通过：' . substr($base, strlen($root) + 1) . "\n";
} finally {
    $device?->stop();
    $verifier?->close(false);
    $broker?->stop();
    $sync?->close();
    $database->close();
    expect(unlink($base . '/private.pem'), '本次隔离TLS私钥未清理');
}
