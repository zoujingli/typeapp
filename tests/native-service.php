<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\BuildPlatform;
use Type\Build\NativePackage;
use Type\Testing\HttpClient;
use Type\Testing\Process;

expect(PHP_OS_FAMILY === 'Darwin', '该入口只验证macOS launchd，其他管理器必须执行对应原生验收');
$root = BuildPlatform::resolve(dirname(__DIR__));
expect(in_array($argc, [1, 2, 4], true), '用法：PHP tests/native-service.php [原生产物 [已验证发布目录 受信SHA256]]');
$artifact = realpath($argv[1] ?? $root . '/build/app/type-app');
expect(is_string($artifact), '需要真实原生产物');
$base = $root . '/build/service-native-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建本轮服务验收目录');
if ($argc === 4) {
    $releaseDirectory = BuildPlatform::resolve($argv[2]);
    $release = (new NativePackage())->verify($releaseDirectory, $argv[3]);
    expect($release['artifact']['sha256'] === hash_file('sha256', $artifact), '服务发布与指定产物身份不一致');
    $package = ['directory' => $releaseDirectory, 'manifest-sha256' => $argv[3]];
} else {
    $package = (new NativePackage())->create($artifact, $base . '/native release', $root . '/.env.example');
}
$runtime = $base . '/private data';
expect(mkdir($runtime, 0700), '无法创建独立运行数据根');
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
expect(is_resource($listener), '无法预留本轮服务端口');
$address = stream_socket_get_name($listener, false);
fclose($listener);
$port = substr(strrchr($address, ':'), 1);
$token = 'native-service-test-' . bin2hex(random_bytes(20));
$configuration = "APP_ENV=production\nAPP_DEBUG=true\nAPP_LISTEN=127.0.0.1\nAPP_PORT=" . $port . "\nAPP_ALLOWED_HOSTS=" . $address
    . "\nAPP_CACHE_ENABLED=false\nDB_DRIVER=sqlite\nDB_SQLITE_FILE=var/app.sqlite\nAPP_API_TOKEN=" . $token . "\n";
file_put_contents($runtime . '/.env', $configuration);
chmod($runtime . '/.env', 0600);
$environment = ['PATH' => '/usr/bin:/bin', 'APP_BASE_PATH' => $runtime, 'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'TYPE_APP_RELEASE_SHA256' => $package['manifest-sha256']];
$migration = new Process([$package['directory'] . '/run', 'migrate', 'run'], $package['directory'], $environment);
try {
    $migrated = $migration->wait(15);
    expect($migrated->successful(), '服务前显式迁移失败：' . $migrated->stderr);
} finally {
    $migration->stop();
}
$name = 'typeappservice' . bin2hex(random_bytes(6));
$target = 'gui/' . posix_geteuid() . '/' . $name;
$settings = ['name' => $name, 'runtime-directory' => $runtime, 'user' => posix_getpwuid(posix_geteuid())['name'], 'restart-seconds' => 1, 'stop-seconds' => 10];
file_put_contents($base . '/service-spec.json', json_encode($settings, JSON_THROW_ON_ERROR));
$service = json_decode(successful([PHP_BINARY, $root . '/vendor/bin/type', 'service', $package['directory'], $base . '/service-spec.json', $base . '/definition', $package['manifest-sha256']]), true, 512, JSON_THROW_ON_ERROR);
foreach (['service.json', basename($service['descriptor']), 'SERVICE.md'] as $file) {
    expect(!str_contains(file_get_contents($service['directory'] . '/' . $file), $token), '服务描述泄漏了运行秘密');
}

function serviceControl(array $arguments): array
{
    $process = new Process(['/bin/launchctl', ...$arguments]);
    try {
        $result = $process->wait(10);
        expect(!$result->timedOut, 'launchctl没有在控制预算内完成');
        return ['code' => $result->exitCode, 'stdout' => $result->stdout, 'stderr' => $result->stderr];
    } finally {
        $process->stop();
    }
}

function servicePid(string $target): int
{
    $state = serviceControl(['print', $target]);
    return $state['code'] === 0 && preg_match('/\bpid = ([0-9]+)/', $state['stdout'], $match) === 1 ? (int) $match[1] : 0;
}

function readyService(string $target, HttpClient $client, int $previous = 0): int
{
    $deadline = microtime(true) + 20;
    do {
        $pid = servicePid($target);
        if ($pid > 0 && $pid !== $previous) {
            try {
                if ($client->request('GET', '/readyz')->status === 200) {
                    return $pid;
                }
            } catch (RuntimeException) {
            }
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('launchd原生服务没有就绪或没有完成崩溃恢复');
}

expect(serviceControl(['print', $target])['code'] !== 0, '不能接管已经存在的launchd作业');
$loaded = false;
$client = new HttpClient('http://' . $address, 1);
try {
    $bootstrap = serviceControl(['bootstrap', 'gui/' . posix_geteuid(), $service['descriptor']]);
    expect($bootstrap['code'] === 0, 'launchd注册失败：' . $bootstrap['stderr']);
    $loaded = true;
    $pid = readyService($target, $client);
    $command = trim(successful(['/bin/ps', '-p', (string) $pid, '-o', 'comm=']));
    expect($command === $package['directory'] . '/bin/app', 'launchd主进程不是发布包中的原生应用：' . $command);
    expect((int) trim(successful(['/bin/ps', '-p', (string) $pid, '-o', 'uid='])) === posix_geteuid() && posix_geteuid() !== 0, 'launchd应用没有以预期非root账号运行');
    expect($client->request('GET', '/users')->status === 401, '服务入口丢失授权');
    $headers = ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'];
    if (getenv('TYPE_TEMPLATE_EXPECTED_MESSAGE') !== false) {
        expect($client->request('GET', '/', $headers)->json()['message'] === getenv('TYPE_TEMPLATE_EXPECTED_MESSAGE'), 'launchd没有运行接入时修改的业务');
    }
    expect($client->request('POST', '/users', $headers, '{"name":"服务验收","age":25}')->status === 201, '服务模式CRUD失败');
    expect(serviceControl(['kill', 'SIGKILL', $target])['code'] === 0, '无法对本轮作业注入崩溃');
    $replacement = readyService($target, $client, $pid);
    expect($client->request('GET', '/users', $headers)->json()['total'] === 1, '崩溃重启丢失外部数据库');
    expect(serviceControl(['kill', 'SIGTERM', $target])['code'] === 0, '无法向本轮作业发送正常停止');
    $deadline = microtime(true) + 10;
    while (servicePid($target) !== 0 && microtime(true) < $deadline) {
        usleep(100000);
    }
    expect(servicePid($target) === 0, '原生服务没有响应正常停止');
    $stopped = serviceControl(['print', $target]);
    expect(preg_match('/last exit code = 0\b/', $stopped['stdout']) === 1, '服务不是正常零状态退出');
    $deadline = microtime(true) + 3;
    do {
        expect(servicePid($target) === 0, '正常停止的服务被错误重新启动');
        usleep(100000);
    } while (microtime(true) < $deadline);
    foreach ([$runtime . '/' . $name . '.stdout.log', $runtime . '/' . $name . '.stderr.log'] as $log) {
        expect(is_file($log) && (fileperms($log) & 0077) === 0, '服务日志没有采用私有权限');
        expect(!str_contains(file_get_contents($log), $token), '服务日志泄漏运行令牌');
    }
    (new NativePackage())->verify($package['directory'], $package['manifest-sha256']);
    $record = ['platform' => PHP_OS_FAMILY, 'manager' => 'launchd', 'release-sha256' => $package['manifest-sha256'],
        'service-sha256' => $service['manifest-sha256'], 'artifact-sha256' => hash_file('sha256', $artifact),
        'pid' => $pid, 'replacement-pid' => $replacement, 'port' => (int) $port,
        'checks' => ['public-cli', 'real-native-process', 'private-external-config', 'production-debug-override', 'health-auth-crud', 'crash-restart', 'data-survives', 'sigterm-exit-zero', 'no-success-restart', 'private-logs', 'release-unchanged']];
} finally {
    if ($loaded) {
        serviceControl(['bootout', $target]);
        expect(serviceControl(['print', $target])['code'] !== 0, '未清理本轮launchd作业');
    }
    unlink($runtime . '/.env');
}
expect(!file_exists($package['directory'] . '/var/app.sqlite'), '数据库写回了不可变发布目录');
$closedListener = stream_socket_server('tcp://' . $address, $errno, $error);
expect(is_resource($closedListener), 'launchd停止后端口仍被占用');
fclose($closedListener);
expect(!posix_kill($pid, 0) && !posix_kill($replacement, 0), 'launchd卸载后本轮应用PID仍存在');
$record['checks'][] = 'pids-and-port-released';
$record['checks'][] = 'job-unloaded';
$record['checks'][] = 'temporary-secret-removed';
file_put_contents($base . '/verification.json', json_encode($record, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
echo '真实launchd原生服务、崩溃恢复、外部数据、正常停止及清理通过：' . $base . "/verification.json\n";
