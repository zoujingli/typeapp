<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require __DIR__ . '/http-support.php';

function pressureUpload(int $port, string $path, string $content, bool $complete = true): mixed
{
    $socket = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 2);
    expect(is_resource($socket), '无法建立受控上传连接');
    stream_set_timeout($socket, 2);
    $part = "--CapacityBoundary\r\nContent-Disposition: form-data; name=\"files[]\"; filename=\"bounded.bin\"\r\nContent-Type: application/octet-stream\r\n\r\n";
    $body = $part . $content . ($complete ? "\r\n--CapacityBoundary--\r\n" : '');
    $length = strlen($body) + ($complete ? 0 : 4096);
    $wire = 'POST ' . $path . " HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\nContent-Type: multipart/form-data; boundary=CapacityBoundary\r\nContent-Length: " . $length . "\r\n\r\n" . $body;
    $sent = 0;
    while ($sent < strlen($wire)) {
        $written = @fwrite($socket, substr($wire, $sent, 16384));
        if ($written === false || $written === 0) {
            break;
        }
        $sent += $written;
    }
    return $socket;
}

function pressureClose(mixed $socket): int
{
    try {
        $wire = @stream_get_contents($socket);
        expect(!stream_get_meta_data($socket)['timed_out'], '磁盘压力后连接没有在预算内返回或关闭');
        if ($wire === false || $wire === '') {
            return 0;
        }
        expect(preg_match('/^HTTP\/1\.[01] (\d{3})/', $wire, $match) === 1, '压力响应不是明确 HTTP 状态或连接拒绝');
        return (int) $match[1];
    } finally {
        fclose($socket);
    }
}

function pressureFill(string $path): void
{
    $handle = fopen($path, 'xb');
    expect(is_resource($handle), '无法在专属测试卷创建占位文件');
    try {
        // 文件系统可以拒绝整块扩展而仍剩余不足一块的空间，继续实际写到 ENOSPC。
        foreach ([16384, 512, 1] as $bytes) {
            while (@fwrite($handle, str_repeat('p', $bytes)) === $bytes) {
            }
        }
        $failure = error_get_last();
        expect(is_array($failure) && str_contains($failure['message'], 'errno=28'), '专属卷没有实际拒绝单字节写入并报告 ENOSPC');
    } finally {
        fclose($handle);
    }
    clearstatcache();
    // macOS FAT 的 statfs 可能仍报告保留簇可用，以真实单字节 ENOSPC 证明满盘。
}

$root = dirname(__DIR__);
$binary = $argv[1] ?? '--php';
$incoming = getenv('TYPE_HTTP_UPLOAD_TEMP');
$storageRoot = getenv('TYPE_UPLOAD_FAULT_DIRECTORY');
foreach ([$incoming, $storageRoot] as $directory) {
    $entries = is_string($directory) && is_dir($directory) ? array_values(array_diff(scandir($directory), ['.', '..'])) : [];
    $empty = $entries === [] || (PHP_OS_FAMILY === 'Darwin' && $entries === ['.fseventsd']
        && is_dir($directory . '/.fseventsd') && !is_link($directory . '/.fseventsd'));
    expect(is_string($directory) && is_dir($directory) && !is_link($directory) && disk_total_space($directory) <= 2097152
        && disk_total_space($directory) >= 262144 && $empty, '上传压力验收要求两个空的专属小容量文件系统（macOS仅允许系统事件元数据）');
}
$command = $binary === '--php' ? [PHP_BINARY, '-d', 'swoole.enable_library=On', '-r',
    'require ' . var_export($root . '/vendor/autoload.php', true) . ';require ' . var_export($root . '/examples/files/Endpoint.php', true)
    . ';require ' . var_export($root . '/examples/files/DownloadStream.php', true) . ';require ' . var_export($root . '/examples/file-http-command.php', true) . ';main($argc,$argv);'] : nativeCommand($binary);
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
expect(is_resource($listener), '无法分配上传压力端口');
$port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
fclose($listener);
$storage = $storageRoot . '/storage';
expect(mkdir($storage, 0700), '无法建立专属上传目录');
$trace = tempnam(sys_get_temp_dir(), 'type_pressure_trace_');
$download = tempnam(sys_get_temp_dir(), 'type_pressure_download_');
$log = tmpfile();
$environment = getenv();
$environment['TYPE_HTTP_PORT'] = (string) $port;
$environment['TYPE_UPLOAD_DIRECTORY'] = $storage;
$environment['TYPE_UPLOAD_TRACE'] = $trace;
$environment['TYPE_DOWNLOAD_FILE'] = $download;
$process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $log, 2 => $log], $pipes, $root, $environment);
expect(is_resource($process), '无法启动原生上传服务');
$sockets = [];
$incomingPadding = $incoming . '/capacity-padding';
$storagePadding = $storageRoot . '/capacity-padding';
try {
    $ready = false;
    $deadline = microtime(true) + 10;
    do {
        expect(proc_get_status($process)['running'], '上传服务在就绪前退出');
        $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.1);
        if (is_resource($probe)) {
            fclose($probe);
            $ready = true;
            break;
        } usleep(20000);
    } while (microtime(true) < $deadline);
    expect($ready, '上传压力服务没有就绪');
    expect(pressureClose(pressureUpload($port, '/temporary', str_repeat('w', 32768))) === 200, '压力前上传未通过');
    clearstatcache();
    $incomingBaseline = (int) disk_total_space($incoming) - (int) disk_free_space($incoming);
    $peak = 0;
    for ($index = 0; $index < 24; $index++) {
        $sockets[] = pressureUpload($port, '/temporary', str_repeat('x', 98304), false);
    }
    $deadline = microtime(true) + 0.3;
    do {
        clearstatcache();
        $peak = max($peak, (int) disk_total_space($incoming) - (int) disk_free_space($incoming) - $incomingBaseline);
        usleep(1000);
    } while (microtime(true) < $deadline);
    foreach ($sockets as $socket) {
        fclose($socket);
    } $sockets = [];
    $deadline = microtime(true) + 3;
    do {
        if (glob($incoming . '/swoole.upfile.*') === []) {
            break;
        } usleep(10000);
    } while (microtime(true) < $deadline);
    expect(glob($incoming . '/swoole.upfile.*') === [] && $peak <= disk_total_space($incoming), '并发中断越过暂存硬容量或遗留无主上传');
    pressureFill($incomingPadding);
    $statuses = [];
    for ($index = 0; $index < 8; $index++) {
        $sockets[] = pressureUpload($port, '/temporary', str_repeat('f', 65536));
    }
    foreach ($sockets as $socket) {
        $statuses[] = pressureClose($socket);
    } $sockets = [];
    // 原始正文仍完整时可由有界内存解析接管；200 必须另以完整内容读回证明，不能静默截断。
    foreach ($statuses as $status) {
        expect(
            $status === 0 || $status >= 400 || ($status === 200 && $peak === 0 && glob($incoming . '/swoole.upfile.*') === []),
            '满盘期间既未拒绝，也未保持无暂存的有界路径，状态=' . json_encode($statuses) . '，暂存峰值=' . $peak
        );
    }
    expect(glob(sys_get_temp_dir() . '/swoole.upfile.*') === [], '受限接入卷满盘后回退到默认临时目录');
    if (in_array(200, $statuses, true)) {
        $payload = str_repeat('verified-binary-', 4096);
        [$status, $body] = receiveHttp(pressureUpload($port, '/save', $payload));
        $key = json_decode($body, true)['keys'][0] ?? '';
        expect($status === 200 && preg_match('/^[a-f0-9]{32}$/D', $key) === 1, '接入卷满盘时成功返回却没有保存完整文件');
        [$status, $body] = httpRequest($port, 'GET', '/file?key=' . $key);
        expect($status === 200 && $body === $payload, '接入卷满盘后的成功响应对应截断或损坏的内容');
    }
    unlink($incomingPadding);
    $deadline = microtime(true) + 3;
    do {
        if (glob($incoming . '/swoole.upfile.*') === []) {
            break;
        } usleep(10000);
    } while (microtime(true) < $deadline);
    expect(glob($incoming . '/swoole.upfile.*') === [], '满盘失败后接入临时文件没有清理');
    expect(pressureClose(pressureUpload($port, '/temporary', str_repeat('r', 32768))) === 200, '释放接入卷后上传没有恢复');
    pressureFill($storagePadding);
    expect(pressureClose(pressureUpload($port, '/temporary', str_repeat('s', 32768))) === 507, '存储卷真实写满后没有返回 507');
    unlink($storagePadding);
    [$status, $body] = httpRequest($port, 'GET', '/stats');
    expect($status === 200 && json_decode($body, true)['pending'] === 0, '满盘后上传存储残留 pending 文件');
    expect(pressureClose(pressureUpload($port, '/temporary', str_repeat('r', 32768))) === 200, '释放存储卷后服务没有恢复');
    echo '上传硬配额：24 个并发中断、8 个接入满盘请求、存储 507 与恢复通过；原有文件系统占用 ' . $incomingBaseline
        . ' 字节，新增暂存峰值 ' . $peak . ' 字节，接入状态 ' . json_encode($statuses) . "。\n";
} finally {
    foreach ($sockets as $socket) {
        if (is_resource($socket)) {
            fclose($socket);
        }
    }
    if (proc_get_status($process)['running']) {
        proc_terminate($process, SIGTERM);
    }
    $deadline = microtime(true) + 5;
    do {
        $state = proc_get_status($process);
        if (!$state['running']) {
            break;
        } usleep(10000);
    } while (microtime(true) < $deadline);
    if ($state['running']) {
        proc_terminate($process, SIGKILL);
    } proc_close($process);
    rewind($log);
    $output = stream_get_contents($log);
    fclose($log);
    if ($output !== '') {
        fwrite(STDERR, $output);
    }
    foreach ([$incomingPadding, $storagePadding] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    foreach (new DirectoryIterator($storage) as $file) {
        if ($file->isFile()) {
            unlink($file->getPathname());
        }
    } rmdir($storage);
    unlink($trace);
    unlink($download);
}
