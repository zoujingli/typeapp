<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require __DIR__ . '/http-support.php';

$root = dirname(__DIR__);
foreach (['HOST', 'PORT', 'DATABASE', 'USER', 'PASSWORD'] as $key) {
    $value = getenv('TYPE_MYSQL_' . $key);
    expect(is_string($value) && $value !== '', 'HTTP子任务验收需要显式专用MySQL连接：' . $key);
}
if (isset($argv[1]) && $argv[1] !== '--php') {
    $command = nativeCommand($argv[1]);
} else {
    $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; require ' . var_export($root . '/examples/tasks/Http.php', true)
        . '; require ' . var_export($root . '/examples/task-http-command.php', true) . '; main($argc, $argv);';
    $command = [PHP_BINARY, '-d', 'swoole.enable_library=On', '-r', $launcher];
}
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
expect(is_resource($socket), '无法分配子任务请求端口');
$port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
fclose($socket);
$trace = tempnam(sys_get_temp_dir(), 'type_task_http_');
$log = tmpfile();
expect($trace !== false && $log !== false, '无法准备子任务请求日志');
$environment = getenv();
$environment['TYPE_TASK_TRACE'] = $trace;
$environment['TYPE_HTTP_PORT'] = (string) $port;
$process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $log, 2 => $log], $pipes, null, $environment);
expect(is_resource($process), '无法启动子任务 HTTP');
try {
    $deadline = microtime(true) + 10;
    $ready = false;
    while (microtime(true) < $deadline) {
        expect(proc_get_status($process)['running'], '子任务 HTTP 提前退出');
        $connection = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.1);
        if (is_resource($connection)) {
            fclose($connection);
            $ready = true;
            break;
        } usleep(10000);
    }
    expect($ready, '子任务 HTTP 未就绪');
    $timeout = sendHttp($port, 'GET', '/timeout');
    $deadline = microtime(true) + 5;
    while (!str_contains(file_get_contents($trace), 'open:timeout') && microtime(true) < $deadline) {
        usleep(1000);
    }
    usleep(40000);
    [$status, $body] = httpRequest($port, 'GET', '/probe');
    expect($status === 503, '超时等待者提前归还了活动 SQL 连接：' . $body);
    [$status, $body] = receiveHttp($timeout);
    expect($status === 503 && json_decode($body, true)['error'] === 'task_timeout', 'HTTP 超时没有明确结果：' . json_encode(['status' => $status, 'body' => $body], JSON_THROW_ON_ERROR));
    $deadline = microtime(true) + 5;
    do {
        [$status, $body] = httpRequest($port, 'GET', '/stats');
        $statistics = json_decode($body, true);
        if ($statistics['leased'] === 0) {
            break;
        } usleep(10000);
    } while (microtime(true) < $deadline);
    expect($statistics['leased'] === 0 && str_contains(file_get_contents($trace), 'close:timeout'), '超时请求没有完成资源清理');
    $disconnect = sendHttp($port, 'GET', '/disconnect');
    $deadline = microtime(true) + 5;
    while (!str_contains(file_get_contents($trace), 'open:disconnect') && microtime(true) < $deadline) {
        usleep(1000);
    }
    fclose($disconnect);
    $deadline = microtime(true) + 5;
    while (!str_contains(file_get_contents($trace), 'close:disconnect') && microtime(true) < $deadline) {
        usleep(10000);
    }
    expect(str_contains(file_get_contents($trace), 'close:disconnect'), '客户端断开后的子任务没有清理');
    [$status, $body] = httpRequest($port, 'GET', '/probe');
    expect($status === 200 && (int) json_decode($body, true)['marker'] === 7, '断开请求污染后续结果');
    echo "HTTP 超时、活动连接容量、客户端断开与后续请求恢复通过。\n";
} finally {
    if (proc_get_status($process)['running']) {
        proc_terminate($process, 15);
    }
    $deadline = microtime(true) + 10;
    do {
        $state = proc_get_status($process);
        if (!$state['running']) {
            break;
        } usleep(10000);
    } while (microtime(true) < $deadline);
    if ($state['running']) {
        proc_terminate($process, 9);
    }
    proc_close($process);
    rewind($log);
    $output = stream_get_contents($log);
    fclose($log);
    unlink($trace);
    if ($output !== '') {
        fwrite(STDERR, $output);
    }
    expect(!$state['running'], '子任务 HTTP 未能正常停止');
}
