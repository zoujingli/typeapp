<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require __DIR__ . '/http-support.php';

$binary = $argv[1] ?? dirname(__DIR__) . '/build/http/type-app';
$mode = $binary === '--php' ? 'PHP' : '原生';
if ($mode === 'PHP') {
    $root = dirname(__DIR__);
    $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; require '
        . var_export($root . '/examples/http/Handlers.php', true) . '; require '
        . var_export($root . '/examples/validation/UserInput.php', true) . '; require '
        . var_export($root . '/examples/validation/Handler.php', true) . '; require '
        . var_export($root . '/examples/http-command.php', true) . '; main($argc, $argv);';
    $command = [PHP_BINARY, '-d', 'swoole.enable_library=On', '-r', $launcher];
} else {
    $command = nativeCommand($binary);
}
$address = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
expect(is_resource($address), '无法分配 HTTP 验证端口');
$port = (int) substr(strrchr(stream_socket_get_name($address, false), ':'), 1);
fclose($address);
$trace = tempnam(sys_get_temp_dir(), 'type_http_trace_');
$log = tmpfile();
expect($trace !== false && $log !== false, '无法创建 HTTP 验证日志');
$environment = getenv();
$environment['TYPE_HTTP_PORT'] = (string) $port;
$environment['TYPE_HTTP_TRACE'] = $trace;
$process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $log, 2 => $log], $pipes, null, $environment);
expect(is_resource($process), '无法启动 HTTP 原生产物');

try {
    $ready = false;
    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline) {
        expect(proc_get_status($process)['running'], 'HTTP 进程在就绪前退出');
        $connection = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.1);
        if (is_resource($connection)) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(20000);
    }
    expect($ready, 'HTTP 原生服务没有按时就绪');
    [$status, $body, $headers] = httpRequest($port, 'GET', '/health', 'first');
    expect($status === 200, '健康请求状态错误：' . $body);
    expect(json_decode($body, true, 512, JSON_THROW_ON_ERROR) === [
        'message' => '原生 HTTP', 'marker' => 'first', 'trace' => 'AB', 'calls' => 1, 'handler_calls' => 1,
    ], '注入处理器或中间件顺序错误：' . $body);
    expect(str_contains($headers, 'x-middleware-a: 1') && str_contains($headers, 'x-middleware-b: 1'), '中间件响应未返回');
    $connections = [];
    for ($index = 0; $index < 24; $index++) {
        $connections[$index] = sendHttp($port, 'GET', '/health', 'request-' . $index);
    }
    foreach ($connections as $index => $connection) {
        [$status, $body] = receiveHttp($connection);
        $value = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        expect($status === 200 && $value['marker'] === 'request-' . $index && $value['trace'] === 'AB'
            && $value['calls'] === 1 && $value['handler_calls'] === 1, '并发请求发生可变状态污染：' . $body);
    }
    [$status, $body] = httpRequest($port, 'GET', '/absent');
    expect($status === 404 && $body === '{"error":"not_found"}', '404 路径错误');
    [$status, $body, $headers] = httpRequest($port, 'POST', '/health');
    expect($status === 405 && $body === '{"error":"method_not_allowed"}' && str_contains($headers, 'allow: get'), '405 或允许方法错误');
    [$status, $body] = httpRequest($port, 'GET', '/fail');
    expect($status === 500 && $body === '{"error":"internal_error"}', '异常转换泄露内部信息或状态错误');
    [$status, $body] = httpRequest($port, 'POST', '/validate?page=2', '', '{"name":"开发者","age":20}');
    expect($status === 200 && json_decode($body, true) === ['data' => ['name' => '开发者', 'age' => 20, 'page' => 2]], 'HTTP DTO 创建校验错误：' . $status . ' ' . $body);
    [$status, $body] = httpRequest($port, 'PATCH', '/validate', '', '{"email":null}');
    expect($status === 200 && json_decode($body, true) === ['data' => ['email' => null]], 'HTTP PATCH 覆盖了缺失字段');
    foreach ([['{bad', 400, 'invalid_json'], ['{"name":"","age":-1}', 422, 'validation_failed'],
        ['{"name":"' . str_repeat('x', 2050) . '"}', 413, 'payload_too_large']] as [$input, $expectedStatus, $expectedCode]) {
        [$status, $body] = httpRequest($port, 'POST', '/validate', '', $input);
        expect($status === $expectedStatus && json_decode($body, true)['error'] === $expectedCode, 'HTTP 输入错误状态不稳定');
    }
    $slow = sendHttp($port, 'GET', '/health', 'draining');
    $deadline = microtime(true) + 3;
    while (!str_contains(file_get_contents($trace), 'open:draining') && microtime(true) < $deadline) {
        usleep(1000);
    }
    expect(str_contains(file_get_contents($trace), 'open:draining'), '停止测试未进入在途请求');
    proc_terminate($process, SIGTERM);
    [$status, $body] = receiveHttp($slow);
    expect($status === 200 && json_decode($body, true)['marker'] === 'draining', '正常停止中断了在途请求');
} finally {
    if (proc_get_status($process)['running']) {
        proc_terminate($process, SIGTERM);
    }
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
    rewind($log);
    $output = stream_get_contents($log);
    fclose($log);
    $events = file($trace, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    unlink($trace);
    expect(!$state['running'], 'HTTP 服务未能限时正常停止：' . $output);
    if ($output !== '') {
        fwrite(STDERR, $output);
    }
}
$opened = [];
foreach ($events as $event) {
    [$action, $marker] = explode(':', $event, 2);
    if ($action === 'open') {
        expect(!isset($opened[$marker]), '请求资源重复打开：' . $marker);
        $opened[$marker] = true;
    } else {
        expect(isset($opened[$marker]), '请求资源清理顺序错误：' . $marker);
        unset($opened[$marker]);
    }
}
expect($opened === [] && count($events) === 54, '正常、异常或停止路径有请求资源没有清理');
echo $mode . " HTTP：健康响应、并发隔离、输入校验、404/405、错误转换及停止清理通过。\n";
