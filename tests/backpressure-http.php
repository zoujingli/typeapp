<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require __DIR__ . '/http-support.php';
$root = dirname(__DIR__);
$binary = $argv[1] ?? '--php';
$command = $binary === '--php' ? [PHP_BINARY, '-d', 'swoole.enable_library=Off', '-r', 'require ' . var_export($root . '/vendor/autoload.php', true)
    . '; require ' . var_export($root . '/examples/backpressure/Endpoint.php', true) . '; require ' . var_export($root . '/examples/backpressure-http-command.php', true) . '; main($argc, $argv);'] : nativeCommand($binary);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
$port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
fclose($socket);
$trace = tempnam(sys_get_temp_dir(), 'type_pressure_trace_');
$logs = tempnam(sys_get_temp_dir(), 'type_pressure_log_');
$output = tmpfile();
$environment = getenv();
$environment['TYPE_HTTP_PORT'] = (string) $port;
$environment['TYPE_BACKPRESSURE_TRACE'] = $trace;
$environment['TYPE_BACKPRESSURE_LOG'] = $logs;
$process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output], $pipes, $root, $environment);
function pressureWait(string $trace, int $lines): void
{
    $deadline = microtime(true) + 3;
    do {
        if (count(file($trace, FILE_IGNORE_NEW_LINES)) >= $lines) {
            return;
        } usleep(1000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('慢 SQL 没有进入在途状态');
}
try {
    $ready = false;
    $deadline = microtime(true) + 10;
    do {
        expect(proc_get_status($process)['running'], '负载测试服务提前退出');
        $connection = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.1);
        if (is_resource($connection)) {
            fclose($connection);
            $ready = true;
            break;
        } usleep(20000);
    } while (microtime(true) < $deadline);
    expect($ready, '负载测试服务没有就绪');
    [$status, $body] = httpRequest($port, 'GET', '/state');
    expect($status === 200, '负载状态入口失败：' . $body);
    $baseline = json_decode($body, true);
    $one = sendHttp($port, 'GET', '/slow');
    $two = sendHttp($port, 'GET', '/alternate');
    pressureWait($trace, 2);
    [$status, $body] = httpRequest($port, 'GET', '/quick');
    expect($status === 503 && str_contains($body, 'resource_capacity_exceeded'), '跨身份连接绕过共享容量：' . $body);
    expect(receiveHttp($one)[0] === 200 && receiveHttp($two)[0] === 200, '正常慢 SQL 被提前释放');
    for ($round = 0; $round < 8; $round++) {
        $connections = [];
        for ($i = 0; $i < 48; $i++) {
            $connections[] = sendHttp($port, 'GET', '/hold');
        }
        [$status, $body] = httpRequest($port, 'GET', '/readyz');
        expect($status === 503 && $body === '{"ready":false}', '超载时就绪仍为真');
        [$status, $body] = httpRequest($port, 'GET', '/livez');
        expect($status === 200 && $body === '{"live":true}', '存活探针被入口额度耗尽');
        $success = 0;
        $rejected = 0;
        foreach ($connections as $connection) {
            [$status] = receiveHttp($connection);
            if ($status === 200) {
                $success++;
            } elseif ($status === 503) {
                $rejected++;
            }
        }
        expect($success === 4 && $rejected === 44, '在途请求没有按固定容量拒绝');
    }
    [$status, $body] = httpRequest($port, 'GET', '/state');
    $state = json_decode($body, true);
    expect(
        $status === 200 && $state['http']['peak_in_flight'] === 4 && $state['http']['rejected'] >= 352 && $state['budget']['allocated'] === 0,
        '压力恢复后仍有未归还资源或缺失拒绝计数'
    );
    expect($state['memory'] - $baseline['memory'] < 16777216 && $state['deployment']['maximum_application_connections'] + $state['deployment']['administration_reserve'] <= 60, '稳态内存或部署总连接预算无界');
    [$status] = httpRequest($port, 'GET', '/deadline');
    expect($status === 504, '原截止预算没有在延迟操作完成后生效');
    [$status] = httpRequest($port, 'GET', '/quick');
    expect($status === 200, '慢依赖恢复后服务未恢复');
    [$status, $body] = httpRequest($port, 'GET', '/leak');
    expect($status === 200, '没有进入超限清理路径');
    $oldPid = json_decode($body, true)['pid'];
    $deadline = microtime(true) + 8;
    $restarted = false;
    do {
        usleep(100000);
        $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.1);
        if (!is_resource($probe)) {
            continue;
        } fclose($probe);
        try {
            [$status, $body] = httpRequest($port, 'GET', '/state');
            $fresh = json_decode($body, true);
        } catch (Throwable) {
            continue;
        }
        if ($status === 200 && $fresh['pid'] !== $oldPid) {
            $restarted = true;
            break;
        }
    } while (microtime(true) < $deadline);
    expect($restarted, '清理超限的 worker 没有被监督器终止并替换');
    $hold = sendHttp($port, 'GET', '/hold');
    usleep(10000);
    [$status, $body] = httpRequest($port, 'GET', '/stop');
    expect($status === 200 && !json_decode($body, true)['ready'], '主动排空没有先撤销就绪');
    [$status] = httpRequest($port, 'GET', '/readyz');
    expect($status === 503, '排空时仍报告就绪');
    [$status] = httpRequest($port, 'GET', '/quick');
    expect($status === 503, '排空时仍接收新业务请求');
    expect(receiveHttp($hold)[0] === 200, '排空中断了预算内的在途请求');
    $deadline = microtime(true) + 5;
    do {
        $state = proc_get_status($process);
        if (!$state['running']) {
            break;
        } usleep(10000);
    } while (microtime(true) < $deadline);
    expect(!$state['running'], '排空后服务没有有限退出');
    expect(str_contains(file_get_contents($logs), '接收请求'), '负载与请求路径没有关联日志');
    echo "HTTP 负载验证通过：跨身份共享连接预算、384 请求压力、探针分离、截止恢复、清理监督替换和有限排空。\n";
} finally {
    if (proc_get_status($process)['running']) {
        proc_terminate($process, SIGTERM);
    }
    $deadline = microtime(true) + 3;
    do {
        $state = proc_get_status($process);
        if (!$state['running']) {
            break;
        } usleep(10000);
    } while (microtime(true) < $deadline);
    if ($state['running']) {
        proc_terminate($process, SIGKILL);
    } proc_close($process);
    rewind($output);
    $text = stream_get_contents($output);
    fclose($output);
    if ($text !== '') {
        fwrite(STDERR, $text);
    }
    unlink($trace);
    unlink($logs);
}
