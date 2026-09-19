<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require __DIR__ . '/http-support.php';

function trustRequest(int $port, string $path, array $headers = [], string $method = 'GET', string $body = ''): array
{
    $connection = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 3);
    expect(is_resource($connection), '无法发起信任验证请求');
    stream_set_timeout($connection, 3);
    $headers += ['Host' => 'localhost', 'Connection' => 'close', 'Authorization' => 'Bearer valid-test-token', 'Content-Type' => 'application/json'];
    $headers['Content-Length'] = strlen($body);
    $wire = $method . ' ' . $path . " HTTP/1.1\r\n";
    foreach ($headers as $name => $value) {
        $wire .= $name . ': ' . $value . "\r\n";
    }
    $wire .= "\r\n" . $body;
    expect(fwrite($connection, $wire) === strlen($wire), '信任请求未写完');
    return receiveHttp($connection);
}

$root = dirname(__DIR__);
if (isset($argv[1]) && $argv[1] !== '--php') {
    $command = nativeCommand($argv[1]);
} else {
    $launcher = 'require ' . var_export($root . '/vendor/autoload.php', true)
        . '; require ' . var_export($root . '/examples/trust/Endpoint.php', true)
        . '; require ' . var_export($root . '/examples/trust-http-command.php', true) . '; main($argc, $argv);';
    $command = [PHP_BINARY, '-d', 'swoole.enable_library=Off', '-r', $launcher];
}
foreach ([false, true] as $trusted) {
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect(is_resource($listener), '无法分配测试端口');
    $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
    fclose($listener);
    $log = tmpfile();
    expect($log !== false, '无法准备测试日志');
    $environment = getenv();
    $environment['TYPE_HTTP_PORT'] = (string) $port;
    $environment['TYPE_TRUST_PROXY'] = $trusted ? '1' : '0';
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $log, 2 => $log], $pipes, null, $environment);
    expect(is_resource($process), '无法启动 HTTP 信任入口');
    try {
        $deadline = microtime(true) + 10;
        $ready = false;
        while (microtime(true) < $deadline) {
            expect(proc_get_status($process)['running'], '信任入口提前退出');
            $connection = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                $ready = true;
                break;
            }
            usleep(10000);
        }
        expect($ready, '信任入口未就绪');
        [$status, $body] = trustRequest($port, '/%73ecure?q=a+b&items[]=x&items[]=y', ['X-Forwarded-Host' => 'public.example',
            'X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.8']);
        $result = json_decode($body, true);
        expect($status === 200 && $result['uri'] === ($trusted ? 'https://public.example' : 'http://localhost') . '/secure?q=a%20b&items%5B%5D=x&items%5B%5D=y'
            && $result['client'] === ($trusted ? '203.0.113.8' : '127.0.0.1') && !$result['forwarded_visible'], '代理或规范化路径不正确：' . $body);
        [$status, $body] = trustRequest($port, '/secure', ['Host' => 'localhost:80']);
        expect($status === 200 && json_decode($body, true)['uri'] === 'http://localhost/secure', '默认端口规范化不一致');
        [$status, $body] = trustRequest($port, '/secure', ['X-Forwarded-For' => '198.51.100.2, 127.0.0.2, 127.0.0.1']);
        expect($status === 200 && json_decode($body, true)['client'] === ($trusted ? '127.0.0.2' : '127.0.0.1'), '代理链没有在最近不可信地址停止');
        [$status] = trustRequest($port, '/secure', ['Forwarded' => 'host=evil.example']);
        expect($status === ($trusted ? 400 : 200), '未声明转发协议被混用');
        [$status, $body] = trustRequest($port, '/secure', ['Host' => 'evil.example']);
        expect($status === 400, '伪造 Host 未拒绝');
        foreach ([['X-Forwarded-Host' => 'public.example'], ['X-Forwarded-Proto' => 'https'],
            ['X-Forwarded-Host' => 'public.example', 'X-Forwarded-Proto' => 'file'],
            ['X-Forwarded-For' => 'not-an-address']] as $invalidForwarding) {
            [$status] = trustRequest($port, '/secure', $invalidForwarding);
            expect($status === ($trusted ? 400 : 200), '代理来源不完整或协议冲突没有按信任边界处理');
        }
        foreach (['/secure', '/livez'] as $upgradePath) {
            [$status, $body] = trustRequest($port, $upgradePath, ['Upgrade' => 'type-unknown-protocol', 'Connection' => 'upgrade']);
            expect($status === 501 && json_decode($body, true)['error'] === 'upgrade_not_supported', '不支持的协议升级进入了业务或探针成功路径');
        }
        foreach (['/secure/../secure', '/secure%2f', '/secure%5c', '/secure%GG', '//secure', '/secure?role=a&role=b'] as $path) {
            [$status, $body] = trustRequest($port, $path);
            expect($status === 400, '歧义路径或重复标量未拒绝：' . $path . ' ' . $body);
        }
        [$status, $body, $headers] = trustRequest($port, '/secure', ['Authorization' => 'Bearer wrong', 'Origin' => 'https://client.example']);
        expect($status === 401 && str_contains($headers, 'www-authenticate: bearer') && str_contains($headers, 'access-control-allow-origin: https://client.example'), '认证失败未区别或 CORS 丢失');
        [$status, $body] = trustRequest($port, '/secure', ['Authorization' => 'Bearer blocked-test-token']);
        expect($status === 403 && json_decode($body, true)['error'] === 'forbidden', '授权失败与认证失败未区分');
        [$status, $body, $headers] = trustRequest($port, '/secure', ['Origin' => 'https://client.example', 'Authorization' => '',
            'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'authorization, content-type'], 'OPTIONS');
        expect($status === 204 && str_contains($headers, 'access-control-allow-credentials: true'), '预检错误要求身份或凭据规则不符');
        [$status] = trustRequest($port, '/secure', ['Origin' => 'https://client.example', 'Access-Control-Request-Method' => 'DELETE', 'Authorization' => ''], 'OPTIONS');
        expect($status === 403, '预检允许了未声明方法');
        [$status] = trustRequest($port, '/secure', ['Origin' => 'https://evil.example']);
        expect($status === 403, 'CORS 未拒绝未知来源');
        [$status, $body] = trustRequest($port, '/secure', [], 'POST', '{"name":"开发者"}');
        expect($status === 200 && json_decode($body, true)['body'] === '{"name":"开发者"}', '正文读取不能重放');
        [$status, $body] = trustRequest($port, '/secure', [], 'POST', '{}');
        expect($status === 422 && json_decode($body, true)['error'] === 'validation_failed', '校验状态被鉴权替代');
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
        if ($output !== '') {
            fwrite(STDERR, $output);
        }
        expect(!$state['running'], '信任验证服务没有正常停止');
    }
}
echo 'HTTP 信任（' . (getenv('TYPE_HTTP_DRIVER') ?: 'swoole') . "）：代理 Host、规范化路径、签名、身份、CORS 与正文重放检查通过。\n";
