<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Testing\HttpClient;
use Type\Testing\Process;

// 测试控制器可运行于另一宿主，但这些绝对部署路径必须同时可访问；应用本身不加载控制器/PHP。
$prefix = json_decode(getenv('TYPE_SERVICE_COMMAND_PREFIX') ?: '[]', true, 32, JSON_THROW_ON_ERROR);
expect(is_array($prefix) && array_is_list($prefix), '服务控制命令前缀必须是参数数组');
foreach ($prefix as $argument) {
    expect(is_string($argument) && !str_contains($argument, "\0"), '服务控制命令前缀无效');
}
$manifestFile = realpath($argv[1] ?? '');
$digest = $argv[2] ?? '';
expect($manifestFile !== false && preg_match('/^[a-f0-9]{64}$/D', $digest) === 1 && hash_file('sha256', $manifestFile) === $digest, '需要受信服务生成记录');
$record = json_decode(file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR);
expect($record['manager'] === 'systemd' && $record['platform'] === 'Linux', '需要Linux systemd配置');
expect(($record['scope'] ?? null) === 'user', '该验收入口只测试systemd用户作用域，系统作用域另验');
$name = $record['name'];
expect(preg_match('/^typeappsystemd[a-f0-9]{12}$/D', $name) === 1, '不能接管非本轮生成的测试服务');
$unit = $name . '.service';
$descriptor = dirname($manifestFile) . '/' . $record['descriptor'];
expect(hash_file('sha256', $descriptor) === $record['files'][$record['descriptor']], '服务描述已被修改');
$release = $record['release-directory'];
$runtime = $record['runtime-directory'];
expect(is_dir($runtime) && !file_exists($runtime . '/.env') && !file_exists($runtime . '/var/app.sqlite'), '只能使用本轮空白运行根');
expect(hash_file('sha256', $release . '/release.json') === $record['release-sha256'], '运行发布包不匹配服务身份');

function systemdRemote(array $command, array $prefix): array
{
    $process = new Process([...$prefix, ...$command]);
    try {
        $result = $process->wait(20);
        expect(!$result->timedOut, '系统服务控制命令超时');
        return ['code' => $result->exitCode, 'stdout' => $result->stdout, 'stderr' => $result->stderr];
    } finally {
        $process->stop();
    }
}

function systemdProperty(string $unit, string $property, array $prefix): string
{
    $result = systemdRemote(['systemctl', '--user', 'show', $unit, '--property=' . $property, '--value'], $prefix);
    expect($result['code'] === 0, '无法读取系统服务属性：' . $property . '：' . $result['stderr']);
    return trim($result['stdout']);
}

function systemdReady(string $unit, HttpClient $client, array $prefix, int $previous = 0): int
{
    $deadline = microtime(true) + 30;
    do {
        $pid = (int) systemdProperty($unit, 'MainPID', $prefix);
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
    throw new RuntimeException('systemd原生应用没有就绪或恢复');
}

$existing = systemdRemote(['systemctl', '--user', 'show', $unit, '--property=LoadState', '--value'], $prefix);
expect(trim($existing['stdout']) === 'not-found', '不能覆盖已存在的systemd服务或未连接的管理器');
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
expect(is_resource($listener), '无法选择本轮服务端口');
$address = stream_socket_get_name($listener, false);
fclose($listener);
$port = substr(strrchr($address, ':'), 1);
$token = 'systemd-test-' . bin2hex(random_bytes(20));
file_put_contents($runtime . '/.env', "APP_ENV=production\nAPP_DEBUG=true\nAPP_LISTEN=127.0.0.1\nAPP_PORT=" . $port . "\nAPP_ALLOWED_HOSTS=" . $address
    . "\nAPP_CACHE_ENABLED=false\nDB_DRIVER=sqlite\nDB_SQLITE_FILE=var/app.sqlite\nAPP_API_TOKEN=" . $token . "\n");
chmod($runtime . '/.env', 0600);
$linked = false;
try {
    $password = bin2hex(random_bytes(16));
    $migration = systemdRemote(['env', 'APP_BASE_PATH=' . $runtime, 'APP_ENV=production', 'APP_DEBUG=false', 'TYPE_APP_RELEASE_SHA256=' . $record['release-sha256'],
        'APP_ADMIN_PASSWORD=' . $password, 'APP_CUSTOMER_PASSWORD=' . $password . '-customer',
        $release . '/run', 'app:install', 'service-admin', '服务管理员', 'service-customer', '服务客户', '服务租户'], $prefix);
    expect($migration['code'] === 0, 'Linux发布包显式迁移失败：' . $migration['stderr']);
    // 旧版systemd-analyze会绑定/替换用户管理器socket（上游#36540）。解析器只能接触本轮私有运行目录。
    $controlRuntime = systemdRemote(['printenv', 'XDG_RUNTIME_DIR'], $prefix);
    expect($controlRuntime['code'] === 0 && str_starts_with(trim($controlRuntime['stdout']), '/'), '无法定位用户管理器运行目录');
    $controlSocket = trim($controlRuntime['stdout']) . '/systemd/private';
    $socketBefore = systemdRemote(['stat', '-c', '%i', $controlSocket], $prefix);
    expect($socketBefore['code'] === 0, '无法核对原用户管理器控制socket');
    $analysisRuntime = $runtime . '/analyze-' . bin2hex(random_bytes(6));
    expect(mkdir($analysisRuntime, 0700), '无法隔离systemd解析器的运行目录');
    try {
        $syntax = systemdRemote(['env', 'XDG_RUNTIME_DIR=' . $analysisRuntime, 'DBUS_SESSION_BUS_ADDRESS=unix:path=' . $analysisRuntime . '/no-user-bus',
            'DBUS_SYSTEM_BUS_ADDRESS=unix:path=' . $analysisRuntime . '/no-system-bus', 'systemd-analyze', '--user', 'verify', $descriptor], $prefix);
        expect($syntax['code'] === 0, 'systemd拒绝服务配置：' . $syntax['stderr']);
        $socketAfter = systemdRemote(['stat', '-c', '%i', $controlSocket], $prefix);
        expect($socketAfter['code'] === 0 && $socketAfter['stdout'] === $socketBefore['stdout'], '解析器改动了原用户管理器socket');
    } finally {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($analysisRuntime, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($analysisRuntime);
    }
    $link = systemdRemote(['systemctl', '--user', '--runtime', 'link', $descriptor], $prefix);
    expect($link['code'] === 0, '无法临时链接服务：' . $link['stderr']);
    $linked = true;
    expect(systemdRemote(['systemctl', '--user', 'daemon-reload'], $prefix)['code'] === 0, '无法加载本轮服务定义');
    $start = systemdRemote(['systemctl', '--user', 'start', $unit], $prefix);
    expect($start['code'] === 0, 'systemd启动失败：' . $start['stderr']);
    $client = new HttpClient('http://' . $address, 1);
    $pid = systemdReady($unit, $client, $prefix);
    $command = systemdRemote(['cat', '/proc/' . $pid . '/cmdline'], $prefix);
    expect($command['code'] === 0 && in_array($release . '/bin/app', explode("\0", $command['stdout']), true), 'systemd未运行发布包中的原生应用');
    $identity = systemdRemote(['cat', '/proc/' . $pid . '/status'], $prefix);
    $expectedUser = systemdRemote(['id', '-u', $record['user']], $prefix);
    expect($expectedUser['code'] === 0 && preg_match('/^Uid:\s+([0-9]+)/m', $identity['stdout'], $userMatch) === 1
        && $userMatch[1] !== '0' && $userMatch[1] === trim($expectedUser['stdout']), 'systemd应用没有使用声明的非root账号');
    expect($client->request('GET', '/admin/users')->status === 401, '系统服务丢失授权');
    $login = $client->request('POST', '/admin/auth/login', ['Content-Type' => 'application/json'], json_encode([
        'login' => 'service-admin', 'password' => $password,
    ], JSON_THROW_ON_ERROR));
    expect($login->status === 200, 'systemd管理员登录失败');
    $headers = ['Authorization' => 'Bearer ' . $login->json()['data']['accessToken'], 'Content-Type' => 'application/json'];
    if (getenv('TYPE_TEMPLATE_EXPECTED_MESSAGE') !== false) {
        expect($client->request('GET', '/', $headers)->json()['message'] === getenv('TYPE_TEMPLATE_EXPECTED_MESSAGE'), 'systemd没有运行接入时修改的业务');
    }
    $created = $client->request('POST', '/admin/users', $headers, json_encode([
        'login' => 'systemd-user', 'name' => '系统服务验收', 'password' => $password,
    ], JSON_THROW_ON_ERROR));
    expect($created->status === 200, '系统服务业务写入失败');
    expect(systemdRemote(['systemctl', '--user', 'kill', '--signal=SIGKILL', '--kill-whom=main', $unit], $prefix)['code'] === 0, '不能对本轮主进程注入崩溃');
    $replacement = systemdReady($unit, $client, $prefix, $pid);
    $listed = $client->request('GET', '/admin/users?search=systemd-user', $headers);
    expect($listed->status === 200 && $listed->json()['data']['total'] === 1, 'systemd重启后丢失外部数据');
    $restarts = (int) systemdProperty($unit, 'NRestarts', $prefix);
    expect($restarts >= 1, '没有记录服务管理器自动重启');
    expect(systemdRemote(['systemctl', '--user', 'stop', $unit], $prefix)['code'] === 0, '系统服务停止失败');
    expect(systemdProperty($unit, 'MainPID', $prefix) === '0' && systemdProperty($unit, 'ExecMainStatus', $prefix) === '0'
        && systemdProperty($unit, 'Result', $prefix) === 'success', 'systemd没有观察到正常零状态退出');
    $journal = systemdRemote(['journalctl', '--user', '--unit=' . $unit, '--no-pager', '--lines=100'], $prefix);
    expect($journal['code'] === 0 && !str_contains($journal['stdout'], $token), '系统日志不可读取或泄漏令牌');
    $verified = ['platform' => 'Linux', 'manager' => 'systemd', 'service-sha256' => $digest, 'release-sha256' => $record['release-sha256'],
        'build-id' => $record['build-id'], 'pid' => $pid, 'replacement-pid' => $replacement, 'port' => (int) $port, 'restart-count' => $restarts,
        'checks' => ['native-loader-and-app', 'systemd-verify', 'analyzer-runtime-isolated', 'unprivileged-user', 'private-external-config', 'production-debug-override', 'health-auth-crud', 'crash-restart', 'persistent-data', 'normal-stop', 'journal-redaction']];
} finally {
    if ($linked) {
        systemdRemote(['systemctl', '--user', 'stop', $unit], $prefix);
        systemdRemote(['systemctl', '--user', '--runtime', 'disable', $unit], $prefix);
        systemdRemote(['systemctl', '--user', 'reset-failed', $unit], $prefix);
        systemdRemote(['systemctl', '--user', 'daemon-reload'], $prefix);
        expect(systemdProperty($unit, 'LoadState', $prefix) === 'not-found', '未清理本轮systemd运行期链接');
    }
    unlink($runtime . '/.env');
}
expect(!file_exists($release . '/var/app.sqlite'), '系统服务把数据库写回发布目录');
$closedListener = stream_socket_server('tcp://' . $address, $errno, $error);
expect(is_resource($closedListener), 'systemd停止后端口仍被占用');
fclose($closedListener);
foreach ([$pid, $replacement] as $finishedPid) {
    expect(systemdRemote(['test', '!', '-e', '/proc/' . $finishedPid], $prefix)['code'] === 0, 'systemd结束后本轮应用PID仍存在');
}
$verified['checks'][] = 'pids-and-port-released';
$verified['checks'][] = 'runtime-unit-removed';
$verified['checks'][] = 'temporary-secret-removed';
$report = dirname($runtime) . '/verification.json';
file_put_contents($report, json_encode($verified, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
echo 'Linux原生systemd服务、业务、崩溃恢复、正常停止和卸载通过：' . $report . "\n";
