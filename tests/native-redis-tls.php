<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';

use Type\Build\ArtifactManifest;
use Type\Build\BuildPlatform;
use Type\Testing\Process;

$root = BuildPlatform::resolve(dirname(__DIR__));
expect(
    in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true) && posix_geteuid() > 0 && $argc === 3,
    '用法：非root Linux/macOS PHP tests/native-redis-tls.php <原生redis-server> <TLS原生产物>'
);
$serverBinary = realpath($argv[1]);
$artifact = realpath($argv[2]);
expect(is_string($serverBinary) && is_executable($serverBinary)
    && BuildPlatform::format($serverBinary) === (PHP_OS_FAMILY === 'Linux' ? 'ELF' : 'Mach-O'), '需要本平台原生redis-server');
expect(is_string($artifact) && class_exists(Redis::class), '需要TLS产物及Redis控制器扩展');
(new BuildPlatform())->assertArtifact($artifact);
$manifest = (new ArtifactManifest())->read($artifact);
$built = json_decode(file_get_contents($artifact . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
expect($manifest['runtime']['os'] === PHP_OS_FAMILY && $manifest['runtime']['architecture'] === php_uname('m')
    && hash_file('sha256', $artifact) === $built['sha256'], 'TLS产物平台、架构或摘要与构建记录不符');
$base = $root . '/build/native-redis-tls-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '无法创建本轮私有TLS目录');
$environment = array_replace((new BuildPlatform())->environment(getenv('PHP_HOME') ?: '', getenv('PHPX_HOME') ?: ''), [
    'PATH' => getenv('PATH') ?: '', 'PHPRC' => getenv('PHPRC') ?: '', 'PHP_INI_SCAN_DIR' => getenv('PHP_INI_SCAN_DIR') ?: '',
]);
$openssl = getenv('TYPE_OPENSSL_BINARY') ?: 'openssl';
$server = null;
$report = ['status' => 'running', 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'execution' => 'native',
    'php' => PHP_VERSION, 'redis-extension' => phpversion('redis'), 'server-sha256' => hash_file('sha256', $serverBinary),
    'artifact-sha256' => $built['sha256'], 'build-id' => $built['build-id'],
    'build-report-sha256' => hash_file('sha256', $artifact . '.build.json'), 'steps' => [], 'owned-server-stopped' => false];
try {
    // 保留实际证书工具版本；证书日志不包含通过 -keyout 写入的私钥内容。
    $report['openssl'] = trim(nativeDatabaseCommand([$openssl, 'version'], $environment, [], $base . '/openssl.log', 10));
    $commands = [
        [$openssl, 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', $base . '/ca.key', '-out', $base . '/ca.pem', '-subj', '/CN=Type-Native-Redis-CA', '-days', '2'],
        [$openssl, 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', $base . '/wrong-ca.key', '-out', $base . '/wrong-ca.pem', '-subj', '/CN=Type-Native-Wrong-CA', '-days', '2'],
        [$openssl, 'req', '-newkey', 'rsa:2048', '-nodes', '-keyout', $base . '/server.key', '-out', $base . '/server.csr', '-subj', '/CN=type-native-redis', '-addext', 'subjectAltName=IP:127.0.0.1'],
        [$openssl, 'x509', '-req', '-in', $base . '/server.csr', '-CA', $base . '/ca.pem', '-CAkey', $base . '/ca.key', '-CAcreateserial', '-days', '2', '-copy_extensions', 'copy', '-out', $base . '/server.pem'],
    ];
    foreach ($commands as $index => $command) {
        nativeDatabaseCommand($command, $environment, [], $base . '/certificate-' . $index . '.log', 30);
    }
    foreach (['ca.key', 'wrong-ca.key', 'server.key'] as $private) {
        expect(chmod($base . '/' . $private, 0600), '无法保护本轮TLS私钥');
    }
    $listener = stream_socket_server('tcp://127.0.0.1:0', $number, $message);
    expect(is_resource($listener), '无法选择本轮TLS端口');
    $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
    fclose($listener);
    $server = new Process([$serverBinary, '--bind', '127.0.0.1', '::1', '--protected-mode', 'yes', '--port', '0', '--tls-port', (string) $port,
        '--tls-cert-file', $base . '/server.pem', '--tls-key-file', $base . '/server.key', '--tls-ca-cert-file', $base . '/ca.pem',
        '--tls-auth-clients', 'no', '--daemonize', 'no', '--dir', $base, '--pidfile', $base . '/redis.pid', '--save', '',
        '--appendonly', 'no', '--maxmemory', '16mb', '--maxmemory-policy', 'noeviction'], $base, $environment);
    $ready = false;
    $until = hrtime(true) + 10000000000;
    do {
        expect($server->running(), 'Redis TLS启动失败：' . $server->stderr());
        $client = new Redis();
        try {
            // 明确覆盖peer_name只用于证明错误主机名确实到达本轮服务器，业务拒绝断言仍检查原始主机名。
            if (@$client->connect(
                'tls://localhost',
                $port,
                0.2,
                null,
                0,
                1.0,
                ['stream' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => '127.0.0.1', 'cafile' => $base . '/ca.pem']]
            )) {
                $info = $client->info('server');
                $directory = $client->config('GET', 'dir');
                expect(
                    $client->ping() === true && (int) $info['process_id'] === $server->pid()
                    && realpath($directory['dir']) === $base && (int) trim(file_get_contents($base . '/redis.pid')) === $server->pid(),
                    '错误主机名没有到达本轮相同Redis进程和数据根'
                );
                $report['server'] = ['pid' => $server->pid(), 'version' => $info['redis_version'], 'port' => $port,
                    'wrong-host-connected-to-owned-server' => true, 'pidfile-matches-server' => true, 'data-root-matches' => true];
                $ready = true;
            }
        } catch (RedisException) {
        } finally {
            if ($client->isConnected()) {
                $client->close();
            }
        }
        if (!$ready) {
            usleep(20000);
        }
    } while (!$ready && hrtime(true) < $until);
    expect($ready, 'Redis TLS没有在期限内就绪');
    $environment = array_replace($environment, ['TYPE_TLS_PORT' => (string) $port, 'TYPE_TLS_HOST' => '127.0.0.1',
        'TYPE_TLS_WRONG_HOST' => 'localhost', 'TYPE_TLS_CA' => $base . '/ca.pem', 'TYPE_TLS_WRONG_CA' => $base . '/wrong-ca.pem']);
    foreach (['php' => '--php', 'native' => $artifact] as $mode => $target) {
        $runEnvironment = $environment;
        if ($mode === 'native') {
            $runEnvironment['TYPE_NATIVE_PHP_INI'] = $built['runtime-profile']['ini'];
        }
        $log = $base . '/' . $mode . '.log';
        echo nativeDatabaseCommand([PHP_BINARY, $root . '/tests/tls.php', $target, 'redis'], $runEnvironment, [], $log, 60);
        $report['steps'][] = ['mode' => $mode, 'status' => 'passed', 'log' => basename($log), 'sha256' => hash_file('sha256', $log)];
    }
    expect(hash_file('sha256', $artifact) === $report['artifact-sha256'], 'TLS验收期间产物发生变化');
    $report['status'] = 'passed';
} finally {
    try {
        if ($server !== null) {
            $stopped = $server->stop(10);
            $log = $stopped->stdout . $stopped->stderr;
            expect(file_put_contents($base . '/server.log', $log) === strlen($log), '无法保存本轮Redis退出日志');
            $report['server-log-sha256'] = hash_file('sha256', $base . '/server.log');
            $report['owned-server-stopped'] = $stopped->successful() && !$server->running();
            expect($report['owned-server-stopped'], '本轮Redis TLS服务器未正常退出');
        }
    } catch (Throwable $cleanup) {
        $report['status'] = 'failed';
        throw $cleanup;
    } finally {
        $report['private-keys-removed'] = true;
        foreach (['ca.key', 'wrong-ca.key', 'server.key'] as $private) {
            if (is_file($base . '/' . $private) && !unlink($base . '/' . $private)) {
                $report['private-keys-removed'] = false;
                $report['status'] = 'failed';
            }
        }
        if ($report['status'] !== 'passed') {
            $report['status'] = 'failed';
        }
        file_put_contents($base . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
}
expect($report['status'] === 'passed', '原生Redis TLS或私钥清理失败');
echo '原生Redis PHP/AOT TLS验收通过：' . substr($base, strlen($root) + 1) . "/verification.json\n";
