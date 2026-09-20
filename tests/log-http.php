<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require __DIR__ . '/http-support.php';
$root = dirname(__DIR__);
$file = tempnam(sys_get_temp_dir(), 'type_http_log_');
$log = tmpfile();
expect($file !== false && $log !== false, '无法准备 HTTP 日志验证');
$address = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
expect(is_resource($address), '无法分配 HTTP 日志验证端口');
$port = (int) substr(strrchr(stream_socket_get_name($address, false), ':'), 1);
fclose($address);
$environment = getenv();
$environment['TYPE_HTTP_PORT'] = (string) $port;
$environment['TYPE_HTTP_LOG_FILE'] = $file;
$launcher = 'require ' . var_export($root . '/tests/log-bootstrap.php', true) . '; require '
    . var_export($root . '/examples/log/Http.php', true) . '; require '
    . var_export($root . '/examples/log-http-command.php', true) . '; main($argc, $argv);';
$command = isset($argv[1]) && $argv[1] !== '--php' ? nativeCommand($argv[1]) : [PHP_BINARY, '-d', 'swoole.enable_library=Off', '-r', $launcher];
$process = null;
try {
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $log, 2 => $log], $pipes, null, $environment);
    expect(is_resource($process), '无法启动日志 HTTP 服务');
    $ready = false;
    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline) {
        expect(proc_get_status($process)['running'], '日志 HTTP 服务提前退出');
        $connection = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.1);
        if (is_resource($connection)) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(10000);
    }
    expect($ready, '日志 HTTP 服务没有就绪');
    $connections = [];
    for ($index = 0; $index < 24; $index++) {
        $connections[$index] = sendHttp($port, 'GET', '/log', 'request-' . $index);
    }
    foreach ($connections as $index => $connection) {
        [$status, $body] = receiveHttp($connection);
        expect($status === 200 && json_decode($body, true) === ['marker' => 'request-' . $index], '并发日志请求失败');
    }
    [$status, $body] = httpRequest($port, 'GET', '/previous', 'request-next');
    expect($status === 200 && json_decode($body, true) === ['previous_rejected' => true], 'HTTP 退出后仍可使用旧 Logger');
    [$status, $body] = httpRequest($port, 'GET', '/fail', 'request-fail');
    expect($status === 500 && $body === '{"error":"internal_error"}', '日志异常请求错误响应泄漏');
} finally {
    if (is_resource($process)) {
        proc_terminate($process, SIGTERM);
        $deadline = microtime(true) + 10;
        do {
            $state = proc_get_status($process);
            if (!$state['running']) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        if ($state['running']) {
            proc_terminate($process, SIGKILL);
        }
        proc_close($process);
    }
    rewind($log);
    $output = stream_get_contents($log);
    fclose($log);
    $wire = (string) file_get_contents($file);
    unlink($file);
    if ($output !== '') {
        fwrite(STDERR, $output);
    }
    expect(!isset($state) || !$state['running'], '日志 HTTP 服务没有正常停止');
}
$rows = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), explode("\n", rtrim($wire, "\n")));
expect(count($rows) === 76, 'HTTP 日志数量错误或退出时丢失记录');
$seen = [];
foreach ($rows as $row) {
    expect($row['build_id'] === 'http-build-one', '构建信息没有关联');
    $id = $row['correlation']['request_id'];
    $seen[$id][] = $row['message'];
    if ($row['message'] === 'request-step') {
        expect($row['context']['marker'] === $id && $row['context']['password'] === '[REDACTED]', '并发请求继承了其他日志上下文');
    }
    expect($row['message'] !== 'expired-request', '失效 Logger 产生了日志');
}
for ($index = 0; $index < 24; $index++) {
    expect($seen['request-' . $index] === ['request-start', 'request-step', 'request-complete'], '请求日志顺序或关联错误');
}
expect($seen['request-next'] === ['request-start', 'request-complete'] && $seen['request-fail'] === ['request-start', 'request-failed'], '新请求或异常日志路径错误');
foreach (['http-context-secret', 'http-step-secret', 'http-exception-secret'] as $secret) {
    expect(!str_contains($wire, $secret), 'HTTP 日志敏感值没有脱敏');
}
echo "日志真实 HTTP（Swoole）：24 个同时连接的独立上下文、旧请求失效、异常脱敏与停止清理通过。\n";
