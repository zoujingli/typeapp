<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require __DIR__ . '/http-support.php';

$root = dirname(__DIR__);
$binary = $argv[1] ?? '--php';
$command = $binary === '--php' ? [PHP_BINARY, '-d', 'swoole.enable_library=On', '-r',
    'require ' . var_export($root . '/vendor/autoload.php', true) . '; require ' . var_export($root . '/examples/files/Endpoint.php', true)
    . '; require ' . var_export($root . '/examples/files/DownloadStream.php', true) . '; require ' . var_export($root . '/examples/file-http-command.php', true) . '; main($argc, $argv);'] : nativeCommand($binary);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
$port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
fclose($socket);
$directory = sys_get_temp_dir() . '/type_files_' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$download = tempnam(sys_get_temp_dir(), 'type_download_');
$trace = tempnam(sys_get_temp_dir(), 'type_files_trace_');
$log = tmpfile();
$file = fopen($download, 'wb');
for ($i = 0; $i < 512; $i++) {
    fwrite($file, str_repeat('0123456789abcdef', 1024));
} fclose($file);
$environment = getenv();
$environment['TYPE_HTTP_PORT'] = (string) $port;
$environment['TYPE_UPLOAD_DIRECTORY'] = $directory;
$environment['TYPE_DOWNLOAD_FILE'] = $download;
$environment['TYPE_UPLOAD_TRACE'] = $trace;
$process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $log, 2 => $log], $pipes, $root, $environment);
function uploadRequest(int $port, string $path, string $content, string $type = 'application/octet-stream'): array
{
    $connection = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 3);
    stream_set_timeout($connection, 3);
    $wire = 'POST ' . $path . " HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\nContent-Type: " . $type . "\r\nContent-Length: " . strlen($content) . "\r\n\r\n" . $content;
    $offset = 0;
    while ($offset < strlen($wire)) {
        $written = fwrite($connection, substr($wire, $offset));
        expect($written > 0, '上传写入失败');
        $offset += $written;
    }
    return receiveHttp($connection);
}
function multipart(array $parts): string
{
    $body = '';
    foreach ($parts as [$name, $filename, $value]) {
        $body .= "--TypeBoundary\r\nContent-Disposition: form-data; name=\"" . $name . '"' . ($filename === null ? '' : '; filename="' . $filename . '"')
            . "\r\n" . ($filename === null ? '' : "Content-Type: application/x-php\r\n") . "\r\n" . $value . "\r\n";
    }
    return $body . "--TypeBoundary--\r\n";
}
try {
    $ready = false;
    $deadline = microtime(true) + 10;
    do {
        expect(proc_get_status($process)['running'], '文件 HTTP 服务提前退出');
        $connection = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.1);
        if (is_resource($connection)) {
            fclose($connection);
            $ready = true;
            break;
        } usleep(20000);
    } while (microtime(true) < $deadline);
    expect($ready, '文件服务未就绪');
    foreach (['application/octet-stream', 'multipart/form-data; boundary=TypeBoundary'] as $type) {
        $connection = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 3);
        stream_set_timeout($connection, 3);
        fwrite($connection, "POST /save HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\nContent-Type: " . $type . "\r\nContent-Length: 262145\r\n\r\n");
        [$status] = receiveHttp($connection);
        expect($status === 413, '读取前声明大小上限被绕过：' . $type);
    }
    [$status, $body] = uploadRequest($port, '/save', '上传内容');
    $key = json_decode($body, true)['keys'][0] ?? '';
    expect($status === 200 && preg_match('/^[a-f0-9]{32}$/D', $key), '服务端键生成失败：' . $body);
    [$status, $body] = httpRequest($port, 'GET', '/file?key=' . $key);
    expect($status === 200 && $body === '上传内容', '保存后读取错误');
    [$status] = httpRequest($port, 'GET', '/file?key=..%2Fsecret.php');
    expect($status === 400, '存储键路径穿越没有拒绝');
    $payload = "binary\0data\r\n--OtherBoundary";
    [$status, $body] = uploadRequest($port, '/save', multipart([['title', null, '中文表单'], ['files[]', '../../shell.php', $payload]]), 'multipart/form-data; boundary=TypeBoundary');
    $value = json_decode($body, true);
    expect($status === 200 && $value['fields'] === ['title' => '中文表单'], 'multipart 解析失败：' . $status . ' ' . $body);
    [$status, $body] = httpRequest($port, 'GET', '/file?key=' . $value['keys'][0]);
    expect($status === 200 && $body === $payload, 'multipart 二进制内容被改写');
    foreach (['/temporary' => 200, '/fail-upload' => 500] as $path => $expected) {
        [$status] = uploadRequest($port, $path, str_repeat('x', 32768));
        expect($status === $expected, '临时上传响应错误');
        [$status, $body] = httpRequest($port, 'GET', '/stats');
        expect(json_decode($body, true)['pending'] === 0, '未保存上传没有清理');
    }
    [$status] = uploadRequest($port, '/save', str_repeat('x', 131073));
    expect($status === 413, '单文件超量没有拒绝');
    [$status] = uploadRequest($port, '/form', '{"a":1,"b":2,"c":3,"d":4,"e":5}', 'application/json');
    expect($status === 413, 'JSON 字段上限无效');
    [$status] = uploadRequest($port, '/form', '{"a":[[[[]]]]}', 'application/json');
    expect($status === 413, 'JSON 深度上限无效');
    [$status] = uploadRequest($port, '/form', 'a=1&b=2&c=3&d=4&e=5', 'application/x-www-form-urlencoded');
    expect($status === 413, '表单字段上限无效');
    [$status, $body] = uploadRequest($port, '/form', 'tags[]=a&tags[]=b&title=%E4%B8%AD%E6%96%87', 'application/x-www-form-urlencoded');
    expect($status === 200 && json_decode($body, true)['fields'] === ['tags' => ['a', 'b'], 'title' => '中文'], '标准列表表单错误');
    [$status] = uploadRequest($port, '/save', multipart([['files[]', 'a', 'a'], ['files[]', 'b', 'b'], ['files[]', 'c', 'c']]), 'multipart/form-data; boundary=TypeBoundary');
    expect($status === 413, '文件数量上限无效');
    [$status] = uploadRequest($port, '/form', multipart([['title', null, str_repeat('x', 1025)]]), 'multipart/form-data; boundary=TypeBoundary');
    expect($status === 413, '字段字节上限无效');
    [$status] = uploadRequest($port, '/save', str_repeat('a', 131072));
    expect($status === 200, '配额内上传失败');
    [$status, $body] = uploadRequest($port, '/save', str_repeat('b', 100000));
    expect($status === 507 && str_contains($body, 'upload_disk_quota'), '总磁盘配额没有生效');
    [$status, $body] = httpRequest($port, 'GET', '/stats');
    expect(json_decode($body, true)['pending'] === 0, '超额上传遗留临时文件');
    [$status, $body] = httpRequest($port, 'GET', '/download', 'complete');
    expect($status === 200 && strlen($body) === 8388608 && hash('sha256', $body) === hash_file('sha256', $download), '大文件流式下载内容错误');
    [$status, $body, $headers] = httpRequest($port, 'HEAD', '/download', 'head');
    expect($status === 200 && $body === '' && str_contains($headers, 'content-length: 8388608'), 'HEAD 下载响应错误');
    [$status, $body] = httpRequest($port, 'GET', '/fail-first', 'first-failed');
    expect($status === 500 && $body === '{"error":"internal_error"}', '首次输出前失败没有安全错误响应');
    [$status, $body] = httpRequest($port, 'GET', '/timeout-stream', 'timed-out');
    expect($status === 504 && $body === '{"error":"deadline_exceeded"}', '响应首次读取超过截止后仍返回成功');
    $connection = sendHttp($port, 'GET', '/fail-stream', 'failed');
    $wire = stream_get_contents($connection);
    fclose($connection);
    expect(substr_count($wire, 'HTTP/1.1') === 1 && str_starts_with($wire, 'HTTP/1.1 200') && !str_contains($wire, 'internal_error') && !str_ends_with($wire, "0\r\n\r\n"), '发送后异常产生第二份响应或假成功');
    // 64MiB超过两种引擎的发送缓冲；保持连接但停止读取，截止须在客户端断开前回收响应流。
    $file = fopen($download, 'r+b');
    expect(ftruncate($file, 67108864), '无法准备发送背压文件');
    fclose($file);
    $connection = sendHttp($port, 'GET', '/download', 'stalled-reader');
    $prefix = fread($connection, 1024);
    expect($prefix !== '', '慢读客户端下载未开始');
    $deadline = microtime(true) + 3;
    do {
        $events = file_get_contents($trace);
        if (str_contains($events, 'scope:stalled-reader:closing')) {
            break;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    expect(preg_match('/stream:stalled-reader:active:(\d+)\nscope:stalled-reader:closing/', $events, $match) === 1
        && (int) $match[1] > 0 && (int) $match[1] < 4096, '停止读取后没有在截止内结束，或未触发真实发送背压：' . ($match[1] ?? '未关闭'));
    $received = strlen($prefix);
    while (!feof($connection)) {
        $chunk = fread($connection, 65536);
        expect($chunk !== false && ($chunk !== '' || feof($connection)), '截止后连接没有结束');
        $received += strlen($chunk);
    }
    fclose($connection);
    expect($received < 67108864 && substr_count($prefix, 'HTTP/1.1') === 1
        && str_starts_with($prefix, 'HTTP/1.1 200'), '发送截止后伪装完整下载');
    $file = fopen($download, 'r+b');
    expect(ftruncate($file, 8388608), '无法恢复普通下载文件');
    fclose($file);
    $connection = sendHttp($port, 'GET', '/disconnect', 'disconnect');
    expect(fread($connection, 1024) !== '', '中断下载未开始');
    fclose($connection);
    $connection = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 3);
    fwrite($connection, "POST /save HTTP/1.1\r\nHost: localhost\r\nContent-Length: 100000\r\n\r\npartial");
    fclose($connection);
    $deadline = microtime(true) + 3;
    do {
        $events = file_get_contents($trace);
        if (str_contains($events, 'scope:disconnect:closing')) {
            break;
        } usleep(10000);
    } while (microtime(true) < $deadline);
    foreach (['complete', 'head', 'first-failed', 'timed-out', 'failed', 'disconnect'] as $marker) {
        expect(preg_match('/stream:' . $marker . ':active:(\d+)\nscope:' . $marker . ':closing/', $events, $match) === 1, '响应流未在作用域关闭前清理：' . $marker);
        if ($marker === 'disconnect') {
            expect((int) $match[1] < 512, '客户端断开后仍读取完整文件');
        }
    }
    [$status, $body] = httpRequest($port, 'GET', '/stats');
    expect($status === 200 && json_decode($body, true)['pending'] === 0, '中断后服务不可用或文件未清理');
    if (getenv('TYPE_HTTP_UPLOAD_TEMP')) {
        expect(glob(getenv('TYPE_HTTP_UPLOAD_TEMP') . '/swoole.upfile.*') === [], '接入层临时上传没有清理');
    }
    echo "文件 HTTP（Swoole）：服务端键、multipart、输入与磁盘配额、8 MiB 下载、HEAD、中断、真实发送背压、发送前后异常和截止清理通过。\n";
} finally {
    proc_terminate($process, SIGTERM);
    $deadline = microtime(true) + 5;
    do {
        $state = proc_get_status($process);
        if (!$state['running']) {
            break;
        } usleep(20000);
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
    foreach (new DirectoryIterator($directory) as $entry) {
        if ($entry->isFile()) {
            unlink($entry->getPathname());
        }
    }
    rmdir($directory);
    unlink($download);
    unlink($trace);
}
