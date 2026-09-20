<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Testing\Process;

/**
 * 独立消费 type-core WebSocket 示例：全量 AOT、无源码运行、明文 WS 与 WSS 回显。
 */

function wsNativeExtensionArgs(array $extensions): array
{
    $arguments = [];
    $directory = (string) ini_get('extension_dir');
    foreach ($extensions as $extension) {
        if (extension_loaded($extension)) {
            continue;
        }
        $module = $directory . '/' . $extension . '.so';
        if ($extension === 'swoole') {
            $module = (string) (getenv('TYPE_SWOOLE_MODULE') ?: $module);
        }
        if (!is_file($module)) {
            continue;
        }
        $arguments[] = '-d';
        $arguments[] = 'extension=' . $module;
        if ($extension === 'swoole') {
            $arguments[] = '-d';
            $arguments[] = 'swoole.enable_library=On';
        }
    }
    return $arguments;
}

function wsNativePhpxHome(string $consumer): string
{
    $source = getenv('PHPX_HOME');
    expect(is_string($source) && is_dir($source . '/include') && is_dir($source . '/lib'), '原生编译需要 PHPX_HOME');
    $home = $consumer . '/phpx-home';
    expect(mkdir($home, 0700), '无法创建 PHPX 适配目录');
    foreach (['bin', 'lib', 'src', 'build', 'thirdparty'] as $part) {
        if (file_exists($source . '/' . $part)) {
            expect(symlink($source . '/' . $part, $home . '/' . $part), '无法链接 PHPX ' . $part);
        }
    }
    expect(mkdir($home . '/include', 0700), '无法创建 PHPX 头文件目录');
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source . '/include', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($files as $file) {
        $relative = substr($file->getPathname(), strlen(rtrim($source, '/') . '/include') + 1);
        $target = $home . '/include/' . $relative;
        if ($file->isDir()) {
            expect(mkdir($target, 0700), '无法复制 PHPX 头目录');
            continue;
        }
        expect(copy($file->getPathname(), $target), '无法复制 PHPX 头文件');
    }
    $locked = dirname(__DIR__) . '/vendor/swoole/phpx/include';
    foreach (['phpx_exception_policy.h', 'phpx_cast_policy.h'] as $header) {
        expect(is_file($locked . '/' . $header) && copy($locked . '/' . $header, $home . '/include/' . $header), '无法覆盖 PHPX 策略头文件');
    }
    $phpx = (string) file_get_contents($home . '/include/phpx.h');
    if (!str_contains($phpx, 'phpx_exception_policy.h')) {
        $needle = "#include \"phpx_native_gc.h\"\n";
        expect(str_contains($phpx, $needle), 'PHPX 头文件缺少 native_gc 插入点');
        $phpx = str_replace($needle, $needle . "#include \"phpx_exception_policy.h\"\n#include \"phpx_cast_policy.h\"\n", $phpx);
        expect(file_put_contents($home . '/include/phpx.h', $phpx) !== false, '无法写入 PHPX 头文件适配');
    }
    return $home;
}

function wsNativePort(): int
{
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect(is_resource($listener), '无法分配原生 WebSocket 端口');
    $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
    fclose($listener);
    return $port;
}

function wsNativeReady(Process $process, int $port, string $cafile = ''): void
{
    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline) {
        expect($process->running(), '原生产物 WebSocket 服务提前退出：' . $process->stderr());
        if ($cafile === '') {
            $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.2);
        } else {
            $probe = @stream_socket_client('ssl://127.0.0.1:' . $port, $errno, $error, 0.2, STREAM_CLIENT_CONNECT, stream_context_create([
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => '127.0.0.1', 'cafile' => $cafile],
            ]));
        }
        if (is_resource($probe)) {
            fclose($probe);
            return;
        }
        usleep(20000);
    }
    throw new RuntimeException('原生产物 WebSocket 服务未就绪：' . $process->stderr());
}

function wsNativeHandshake(int $port, bool $tls, string $cafile = ''): array
{
    if ($tls) {
        $connection = stream_socket_client('ssl://127.0.0.1:' . $port, $errno, $error, 5, STREAM_CLIENT_CONNECT, stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => '127.0.0.1', 'cafile' => $cafile],
        ]));
    } else {
        $connection = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 5);
    }
    expect(is_resource($connection), '无法连接原生产物 WebSocket 服务');
    stream_set_timeout($connection, 5);
    $key = base64_encode(random_bytes(16));
    $wire = "GET /ws HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
        . "Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n";
    expect(fwrite($connection, $wire) === strlen($wire), '原生产物握手请求未写完');
    $header = '';
    while (!feof($connection)) {
        $line = fgets($connection, 1024);
        if ($line === false) {
            break;
        }
        $header .= $line;
        if ($line === "\r\n") {
            break;
        }
    }
    return [$connection, $header, $key];
}

function wsNativeFrame(string $payload): string
{
    $length = strlen($payload);
    $header = chr(0x82) . chr(0x80 | $length);
    $mask = random_bytes(4);
    $masked = '';
    for ($i = 0; $i < $length; $i++) {
        $masked .= $payload[$i] ^ $mask[$i % 4];
    }
    return $header . $mask . $masked;
}

/** 逐文件回读生产 PHP 归档后移除源码，保留原产物、依赖身份和运行证据。 */
function wsNativePreserveSources(string $consumer): array
{
    $archive = $consumer . '/source-inputs.tar';
    $snapshot = new PharData($archive);
    $hashes = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($consumer, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->isFile() && !$file->isLink() && strtolower($file->getExtension()) === 'php') {
            $relative = substr($file->getPathname(), strlen($consumer) + 1);
            $hashes[$relative] = hash_file('sha256', $file->getPathname());
            $snapshot->addFile($file->getPathname(), $relative);
        }
    }
    expect($hashes !== [], 'WebSocket 消费者缺少编译输入');
    $snapshot->compress(Phar::GZ);
    unset($snapshot);
    $restored = new PharData($archive . '.gz');
    foreach ($hashes as $relative => $hash) {
        expect(isset($restored[$relative]) && hash('sha256', $restored[$relative]->getContent()) === $hash, 'WebSocket 输入归档回读失败');
    }
    unset($restored);
    unlink($archive);
    foreach ($hashes as $relative => $hash) {
        expect(unlink($consumer . '/' . $relative), '无法移除已保全的 PHP 输入');
    }
    $record = ['archive' => 'source-inputs.tar.gz', 'sha256' => hash_file('sha256', $archive . '.gz'), 'sources' => $hashes];
    file_put_contents($consumer . '/source-inputs.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    return ['archive_sha256' => $record['sha256'], 'removed_php_files' => count($hashes)];
}

$root = dirname(__DIR__);
$consumer = $root . '/build/websocket-native-' . bin2hex(random_bytes(5));
expect(mkdir($consumer . '/app', 0700, true), '无法创建独立 WebSocket 消费者目录');
$verified = [];
$failure = null;
$previousPhpx = getenv('PHPX_HOME');
try {
    $toolchain = json_decode((string) file_get_contents($root . '/toolchain.lock.json'), true, 512, JSON_THROW_ON_ERROR);
    $repositories = [];
    foreach (['type-core', 'type-runtime', 'type-build'] as $package) {
        $repositories[] = ['type' => 'path', 'url' => '../../plugin/' . $package,
            'options' => ['symlink' => false, 'versions' => ['zoujingli/' . $package => '1.0.x-dev']]];
    }
    $composer = ['name' => 'type-tests/websocket-consumer', 'type' => 'project', 'license' => 'Apache-2.0',
        'require' => ['zoujingli/type-core' => '~1.0.0@dev'],
        'require-dev' => ['zoujingli/type-build' => '~1.0.0@dev', 'swoole/typephp' => $toolchain['typephp']['version'], 'swoole/phpx' => $toolchain['phpx']['version']],
        'autoload' => ['classmap' => ['app/main.php']], 'repositories' => $repositories,
        'minimum-stability' => 'dev', 'prefer-stable' => true, 'config' => ['allow-plugins' => false]];
    file_put_contents($consumer . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    copy($root . '/examples/websocket/main.php', $consumer . '/app/main.php');
    copy($root . '/toolchain.lock.json', $consumer . '/toolchain.lock.json');
    $module = (string) (getenv('TYPE_SWOOLE_MODULE') ?: ini_get('extension_dir') . '/swoole.so');
    expect(is_file($module), '原生 WebSocket 编译需要匹配 SDK 的 Swoole 模块');
    expect(copy($module, $consumer . '/swoole.so'), '无法固定 Swoole 运行模块');
    $hash = hash_file('sha256', $consumer . '/swoole.so');
    expect(is_string($hash) && $hash !== '', '无法计算 Swoole 模块摘要');
    file_put_contents($consumer . '/type-app.json', json_encode([
        'name' => 'type-websocket', 'entry' => 'app/main.php', 'sources' => ['app/main.php'],
        'output' => 'build/websocket/type-app', 'build-directory' => 'build/websocket/compiler',
        'runtime' => [PHP_OS_FAMILY => [
            'extensions' => ['mysqlnd', 'sockets', 'swoole'],
            'modules' => ['swoole' => ['file' => 'swoole.so', 'sha256' => $hash]],
        ]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    successful([(string) (getenv('COMPOSER_BINARY') ?: 'composer'), 'install', '--no-interaction', '--no-scripts', '--no-plugins', '--no-progress'], $consumer);
    expect(!is_link($consumer . '/vendor/zoujingli/type-core') && !is_link($consumer . '/vendor/zoujingli/type-runtime'), '独立 WebSocket 消费不能使用主仓生产软链接');
    putenv('PHPX_HOME=' . wsNativePhpxHome($consumer));
    $buildOutput = successful([PHP_BINARY, ...wsNativeExtensionArgs(['pdo_pgsql']), $consumer . '/vendor/bin/type', $consumer . '/type-app.json'], $consumer);
    file_put_contents($consumer . '/build.log', $buildOutput);
    $report = json_decode((string) file_get_contents($consumer . '/build/websocket/type-app.build.json'), true, 512, JSON_THROW_ON_ERROR);
    $production = array_keys($report['production-packages']);
    sort($production);
    expect($production === ['psr/http-factory', 'psr/http-message', 'psr/http-server-handler', 'psr/http-server-middleware',
        'zoujingli/type-core', 'zoujingli/type-runtime'], '独立 WebSocket 编译生产依赖不完整：' . implode(',', $production));
    foreach ($report['sources'] as $source) {
        expect(str_starts_with($source, $consumer . '/'), '独立 WebSocket 仍编译主仓源码');
    }
    $verified['native'] = [
        'artifact' => $report['sha256'], 'build_id' => $report['build-id'],
        'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'),
        'php' => PHP_VERSION, 'swoole' => $report['runtime-profile']['extensions']['swoole'],
        'swoole_module_sha256' => $hash, 'source_count' => count($report['sources']),
    ];
    $command = nativeCommand($consumer . '/build/websocket/type-app');
    $runtime = $consumer . '/runtime';
    expect(mkdir($runtime, 0700), '无法创建 WebSocket 无源码运行目录');
    copy($consumer . '/build/websocket/type-app', $runtime . '/type-app');
    chmod($runtime . '/type-app', 0700);
    expect(copy($report['runtime-profile']['ini'], $runtime . '/php.ini'), '无法保全原生运行配置');
    expect(copy(dirname($report['runtime-profile']['ini']) . '/profile.json', $runtime . '/profile.json'), '无法保全原生模块探测报告');
    if (PHP_OS_FAMILY === 'Darwin') {
        $policy = ['sandbox-exec', '-f', $root . '/tests/fixtures/mqtt-no-source.sb'];
        foreach (['ROOT_APP' => $root . '/app', 'ROOT_PLUGIN' => $root . '/plugin', 'ROOT_EXAMPLE' => $root . '/examples',
            'ROOT_VENDOR' => $root . '/vendor', 'APP' => $consumer . '/app', 'VENDOR' => $consumer . '/vendor',
            'COMPILER' => $consumer . '/build/websocket/compiler', 'ROOT_COMPOSER' => $root . '/composer.json',
            'COMPOSER' => $consumer . '/composer.json'] as $role => $path) {
            array_push($policy, '-D', $role . '=' . $path);
        }
        $probe = 'foreach (array_slice($argv, 1) as $file) { if (@file_get_contents($file) !== false) { throw new RuntimeException("生产源码仍可读"); } } echo "source-denied\n";';
        expect(successful([...$policy, PHP_BINARY, '-n', '-r', $probe, $root . '/plugin/type-core/src/WebSocket/Server.php',
            $consumer . '/app/main.php', $consumer . '/vendor/autoload.php', $root . '/composer.json'], $runtime) === "source-denied\n", 'WebSocket 无源码边界未生效');
        $command = [...$policy, 'env', 'PHPRC=' . $runtime . '/php.ini', 'PHP_INI_SCAN_DIR=', $runtime . '/type-app'];
    }
    $environment = getenv();
    expect(is_array($environment), '无法读取原生 WebSocket 测试环境');
    unset($environment['PHPRC'], $environment['PHP_INI_SCAN_DIR']);
    $verified['source_removal'] = wsNativePreserveSources($consumer);

    $wsPort = wsNativePort();
    $wsProcess = new Process([...$command, 'server', '127.0.0.1', (string) $wsPort], $runtime, $environment);
    $socket = null;
    try {
        wsNativeReady($wsProcess, $wsPort);
        [$socket, $header, $key] = wsNativeHandshake($wsPort, false);
        expect(str_contains($header, '101'), '原生产物 WS 握手失败：' . $header);
        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        expect(str_contains($header, $accept), '原生产物 Sec-WebSocket-Accept 与标准计算不一致');
        fwrite($socket, wsNativeFrame('native-ws'));
        $echoed = '';
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline && !str_contains($echoed, 'native-ws')) {
            $chunk = fread($socket, 1024);
            if ($chunk === false || $chunk === '') {
                usleep(10000);
                continue;
            }
            $echoed .= $chunk;
        }
        expect(str_contains($echoed, 'native-ws'), '原生产物 WS 回显失败：' . bin2hex(substr($echoed, 0, 24)));
        fclose($socket);
        $stopped = $wsProcess->wait(5);
        expect($stopped->successful(), '原生产物 WS 服务停止失败：' . $stopped->stderr);
        $verified['ws'] = true;
    } finally {
        if (is_resource($socket)) {
            fclose($socket);
        }
        $wsProcess->stop();
        file_put_contents($consumer . '/ws.stdout.log', $wsProcess->stdout());
        file_put_contents($consumer . '/ws.stderr.log', $wsProcess->stderr());
    }

    $certificateConfiguration = $consumer . '/certificate.cnf';
    file_put_contents($certificateConfiguration, "[req]\ndistinguished_name=dn\nx509_extensions=server\n[dn]\n[server]\nsubjectAltName=IP:127.0.0.1\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\nextendedKeyUsage=serverAuth\n");
    $certificateOptions = ['config' => $certificateConfiguration, 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
    $keyMaterial = openssl_pkey_new($certificateOptions);
    $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $keyMaterial, $certificateOptions);
    $certificateObject = openssl_csr_sign($csr, null, $keyMaterial, 1, $certificateOptions);
    expect($keyMaterial !== false && $csr !== false && $certificateObject !== false, '无法生成原生 WSS 证书');
    expect(openssl_x509_export($certificateObject, $certificatePem) && openssl_pkey_export($keyMaterial, $privatePem, null, $certificateOptions), '无法导出原生 WSS 证书');
    file_put_contents($consumer . '/certificate.pem', $certificatePem);
    file_put_contents($consumer . '/private.pem', $privatePem);
    chmod($consumer . '/private.pem', 0600);

    $wssPort = wsNativePort();
    $wssProcess = new Process([
        ...$command, 'server', '127.0.0.1', (string) $wssPort, $consumer . '/certificate.pem', $consumer . '/private.pem',
    ], $runtime, $environment);
    $wss = null;
    try {
        wsNativeReady($wssProcess, $wssPort, $consumer . '/certificate.pem');
        [$wss, $wssHeader] = wsNativeHandshake($wssPort, true, $consumer . '/certificate.pem');
        expect(str_contains($wssHeader, '101'), '原生产物 WSS 握手失败：' . $wssHeader);
        fwrite($wss, wsNativeFrame('native-wss'));
        $wssEcho = '';
        $echoDeadline = microtime(true) + 5;
        while (microtime(true) < $echoDeadline && !str_contains($wssEcho, 'native-wss')) {
            $chunk = fread($wss, 1024);
            if ($chunk === false || $chunk === '') {
                usleep(10000);
                continue;
            }
            $wssEcho .= $chunk;
        }
        expect(str_contains($wssEcho, 'native-wss'), '原生产物 WSS 回显失败：' . bin2hex(substr($wssEcho, 0, 24)));
        fclose($wss);
        $wssStopped = $wssProcess->wait(5);
        expect($wssStopped->successful(), '原生产物 WSS 服务停止失败：' . $wssStopped->stderr);
        $verified['wss'] = true;
    } finally {
        if (is_resource($wss)) {
            fclose($wss);
        }
        $wssProcess->stop();
        file_put_contents($consumer . '/wss.stdout.log', $wssProcess->stdout());
        file_put_contents($consumer . '/wss.stderr.log', $wssProcess->stderr());
    }
    foreach (['app', 'vendor', 'build/websocket/compiler', 'phpx-home'] as $part) {
        if (is_dir($consumer . '/' . $part)) {
            removeTestDirectory($consumer . '/' . $part);
        }
    }
} catch (Throwable $error) {
    $failure = $error;
    throw $error;
} finally {
    if (is_string($previousPhpx) && $previousPhpx !== '') {
        putenv('PHPX_HOME=' . $previousPhpx);
    } else {
        putenv('PHPX_HOME');
    }
    if (is_file($consumer . '/private.pem')) {
        expect(unlink($consumer . '/private.pem'), '无法回收 WSS 测试私钥');
    }
    file_put_contents($consumer . '/verification.json', json_encode([
        'ok' => $failure === null, 'verified' => $verified, 'error' => $failure?->getMessage(),
        'private_key_removed' => !is_file($consumer . '/private.pem'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

echo json_encode(['ok' => true, 'verified' => $verified, 'evidence' => $consumer . '/verification.json'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), "\n";
