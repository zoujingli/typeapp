<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require __DIR__ . '/http-support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$variant = $argv[2] ?? 'explicit';
expect(in_array($variant, ['explicit', 'attributes'], true), '路由验证只接受 explicit 或 attributes');
$settings = json_decode(file_get_contents($root . '/docs/build-config/type-routing-' . ($variant === 'attributes' ? 'attributes' : 'http') . '.json'), true, 512, JSON_THROW_ON_ERROR);
$compiler = new Type\Build\RouteCompiler();
$generated = $compiler->generate($root, $compiler->declarations($root, $settings['routing']), array_map(static fn (string $file): string => $root . '/' . $file, array_merge($settings['sources'], ['plugin/type-validate/src'])));
$generatedFile = tempnam(sys_get_temp_dir(), 'type_routes_');
$log = tmpfile();
expect($generatedFile !== false && $log !== false, '无法创建路由验证文件');
file_put_contents($generatedFile, $generated['code']);
require $generatedFile;
$messages = new Type\Core\Http\Message\Factory();
$links = new Type\Core\Http\Router($messages, $messages);
TypeApp\Generated\Routes::register($links, [TypeApp\RoutingExample\BooksController::class => static fn () => throw new RuntimeException('生成链接不能构造控制器')], [
    'group' => static fn () => throw new RuntimeException('生成链接不能构造中间件'),
    'route' => static fn () => throw new RuntimeException('生成链接不能构造中间件'),
]);
$address = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
expect(is_resource($address), '无法分配路由 HTTP 验证端口');
$port = (int) substr(strrchr(stream_socket_get_name($address, false), ':'), 1);
fclose($address);
$environment = getenv();
$environment['TYPE_HTTP_PORT'] = (string) $port;
$launcher = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; require ' . var_export($generatedFile, true)
    . '; require ' . var_export($root . '/examples/routing/Controllers.php', true)
    . '; require ' . var_export($root . '/examples/routing/Middleware.php', true)
    . '; require ' . var_export($root . '/examples/routing-http-command.php', true) . '; main($argc, $argv);';
$command = isset($argv[1]) && $argv[1] !== '--php' ? nativeCommand($argv[1]) : [PHP_BINARY, '-d', 'swoole.enable_library=On', '-r', $launcher];
$process = null;
$graceful = true;

/** 真实传输显式头部；重复头保持多行，验证接入没有先覆盖再宣称校验成功。 */
function inputHttp(int $port, string $method, string $path, string $body = '', array $headers = []): array
{
    $connection = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 3);
    expect(is_resource($connection), '无法连接输入验证服务');
    stream_set_timeout($connection, 3);
    $wire = $method . ' ' . $path . " HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\nContent-Length: " . strlen($body) . "\r\n";
    foreach ($headers as $line) {
        $wire .= $line . "\r\n";
    }
    $wire .= "\r\n" . $body;
    expect(fwrite($connection, $wire) === strlen($wire), '输入验证请求未完整写入');
    return receiveHttp($connection);
}

try {
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $log, 2 => $log], $pipes, null, $environment);
    expect(is_resource($process), '无法启动路由 HTTP 验证');
    $deadline = microtime(true) + 10;
    $ready = false;
    while (microtime(true) < $deadline) {
        expect(proc_get_status($process)['running'], '路由服务在就绪前退出');
        $connection = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.1);
        if (is_resource($connection)) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(10000);
    }
    expect($ready, '路由服务没有就绪');
    foreach ([['index', 'GET', []], ['create', 'GET', []], ['store', 'POST', []], ['show', 'GET', ['id' => 23]],
        ['edit', 'GET', ['id' => 23]], ['update', 'PATCH', ['id' => 23]], ['update', 'PUT', ['id' => 23]], ['destroy', 'DELETE', ['id' => 23]]] as [$action, $method, $parameters]) {
        $url = $links->url('api.books.' . $action, $parameters);
        [$status, $body, $headers] = httpRequest($port, $method, $url);
        $value = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        expect($status === 200 && $value['action'] === $action && $value['route'] === 'api.books.' . $action
            && $value['greeting'] === '生成路由' && $value['trace'] === 'GP' && str_contains($headers, 'x-return: pg'), '资源路由或中间件顺序错误：' . $body);
    }
    [$status, $body, $headers] = httpRequest($port, 'GET', $links->url('api.typed.show', ['id' => 23], ['id' => 99]));
    $value = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    expect($status === 200 && $value === ['action' => 'typed-show', 'id' => 23, 'greeting' => '生成路由']
        && str_contains($headers, 'content-type: application/json'), '整数路径参数或 query 覆盖规则错误：' . $body);
    [$status, $body] = httpRequest($port, 'POST', $links->url('api.typed.store'));
    $value = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    expect($status === 201 && $value['action'] === 'typed-store' && $value['greeting'] === '生成路由', '固定 201 业务结果错误：' . $body);
    [$status, $body] = httpRequest($port, 'DELETE', $links->url('api.typed.destroy', ['id' => 23]));
    expect($status === 204 && $body === '', 'void 动作没有返回 204 空正文');
    [$status, $body] = httpRequest($port, 'GET', $links->url('api.typed.search', ['term' => '甲 乙+%2F'], ['term' => '覆盖']));
    $value = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    expect($status === 200 && $value['term'] === '甲 乙+%2F', '字符串路径参数或 query 覆盖规则错误：' . $body);
    foreach (['甲 乙+%2F', 'another'] as $term) {
        $url = $links->url('api.lookup', ['term' => $term], ['query' => 'a b']);
        [$status, $body, $headers] = httpRequest($port, 'GET', $url);
        $value = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        expect($status === 200 && $value['parameters'] === ['term' => $term] && $value['trace'] === 'GPR'
            && str_contains($headers, 'x-return: rpg'), 'URL 编码往返或三级中间件顺序错误：' . $body);
    }
    [$status, $body, $headers] = httpRequest($port, 'POST', '/api/books/23');
    expect($status === 405 && $body === '{"error":"method_not_allowed"}' && str_contains($headers, 'allow: delete, get, head, patch, put'), '资源路由 405 错误');
    foreach (['/api/books/no-id', '/api/lookup/a%2Fb', '/api/absent'] as $path) {
        [$status, $body] = httpRequest($port, 'GET', $path);
        expect($status === 404 && $body === '{"error":"not_found"}', '不存在或不满足约束的路径必须返回 404');
    }
    [$status, $body] = httpRequest($port, 'GET', '/api/books/typed/999999999999999999999999999999999');
    expect($status === 422 && $body === '{"error":"route_parameter_invalid"}', '整数溢出没有返回稳定 422');
    [$status, $body] = httpRequest($port, 'HEAD', '/api/books/23');
    expect($status === 200 && $body === '', 'HEAD 必须复用 GET 路由并省略正文');
    [$status, $body] = inputHttp($port, 'GET', '/api/inputs?page=2');
    expect($status === 200 && json_decode($body, true) === ['page' => 2], '查询输入错误地要求 JSON 正文：' . $body);
    [$status, $body] = inputHttp($port, 'POST', '/api/inputs', '{"name":" 测试 ","note":null,"reference":92233720368547758080}', ['Content-Type: application/json']);
    expect($status === 201 && json_decode($body, true) === ['name' => '测试', 'note' => null, 'active' => true, 'reference' => '92233720368547758080'], '创建输入、大整数或默认值语义错误：' . $body);
    [$status, $body] = inputHttp($port, 'PATCH', '/api/inputs/23?id=99', '{"note":null}', ['Content-Type: application/json']);
    expect($status === 200 && json_decode($body, true) === ['id' => 23, 'hasNote' => true, 'values' => ['note' => null]], 'PATCH 没有保留 null、路径或默认值边界：' . $body);
    [$status, $body] = inputHttp($port, 'PATCH', '/api/inputs/23', '{}', ['Content-Type: application/json']);
    expect($status === 200 && json_decode($body, true) === ['id' => 23, 'hasNote' => false, 'values' => []], 'PATCH 缺失字段被改成了 null 或默认值：' . $body);
    [$status, $body] = inputHttp($port, 'GET', '/api/inputs/23/sources?id=99', '', ['x-ORIGIN: first', 'X-Tag: one', 'x-tag: two']);
    expect($status === 200 && json_decode($body, true) === ['values' => ['id' => 23, 'queryId' => 99, 'origin' => 'first', 'tags' => ['one', 'two']], 'trace' => 'GP'], '分源或 Header 大小写/多值列表错误：' . $body);
    $inputFailures = [
        ['GET', '/api/inputs?page=0', '', [], 422, 'validation_failed', ['page' => ['range']]],
        ['GET', '/api/inputs?page=1&page=2', '', [], 400, 'invalid_query', null],
        ['GET', '/api/inputs?a=1&b=2&c=3&d=4', '', [], 413, 'payload_too_large', null],
        ['GET', '/api/inputs?page=' . str_repeat('1', 130), '', [], 413, 'payload_too_large', null],
        ['GET', '/api/inputs?page=%XX', '', [], 400, 'invalid_query', null],
        ['POST', '/api/inputs', '', [], 422, 'validation_failed', ['name' => ['required']]],
        ['POST', '/api/inputs', ' ', ['Content-Type: application/json'], 400, 'invalid_json', ['body' => ['invalid_json']]],
        ['POST', '/api/inputs', '[]', ['Content-Type: application/json'], 400, 'invalid_json', ['body' => ['object_required']]],
        ['POST', '/api/inputs', '{"name":"first","name":"second"}', ['Content-Type: application/json'], 400, 'invalid_json', ['body' => ['duplicate_key']]],
        ['POST', '/api/inputs', '{"deep":{"a":{"b":{"c":1}}}}', ['Content-Type: application/json'], 400, 'invalid_json', ['body' => ['too_deep']]],
        ['POST', '/api/inputs', str_repeat('{"a":', 9) . '1' . str_repeat('}', 9), ['Content-Type: application/json'], 413, 'input_too_deep', null],
        ['POST', '/api/inputs', '{' . implode(',', array_map(static fn (int $index): string => '"f' . $index . '":0', range(1, 11))) . '}', ['Content-Type: application/json'], 413, 'too_many_fields', null],
        ['POST', '/api/inputs', str_repeat(' ', 129), ['Content-Type: application/json'], 413, 'payload_too_large', null],
        ['POST', '/api/inputs', '{"name":"test"}', ['Content-Type: text/plain'], 415, 'json_required', null],
        ['PATCH', '/api/inputs/1', '{"name":null}', ['Content-Type: application/json'], 422, 'validation_failed', ['name' => ['null_not_allowed']]],
        ['GET', '/api/inputs/23/sources', '', ['X-Origin: first', 'x-origin: second'], 422, 'validation_failed', ['origin' => ['multiple_values']]],
    ];
    foreach ($inputFailures as [$method, $path, $payload, $requestHeaders, $expectedStatus, $expectedError, $expectedFields]) {
        [$status, $body] = inputHttp($port, $method, $path, $payload, $requestHeaders);
        $value = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        expect($status === $expectedStatus && ($value['error'] ?? null) === $expectedError
            && ($expectedFields === null || ($value['fields'] ?? null) === $expectedFields), '类型化输入错误边界不匹配：' . $path . ' ' . $body);
    }
    $connections = [];
    foreach (range(1, 8) as $page) {
        $connections[$page] = sendHttp($port, 'GET', '/api/inputs?page=' . $page);
    }
    foreach ($connections as $page => $connection) {
        [$status, $body] = receiveHttp($connection);
        expect($status === 200 && json_decode($body, true) === ['page' => $page], '并发输入对象出现跨请求状态');
    }
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
        $graceful = !$state['running'];
        if (!$graceful) {
            proc_terminate($process, SIGKILL);
        }
        proc_close($process);
    }
    rewind($log);
    $output = stream_get_contents($log);
    fclose($log);
    unlink($generatedFile);
    if ($output !== '') {
        fwrite(STDERR, $output);
    }
    expect($graceful, '路由 HTTP 服务没有正常停止');
}
echo '路由真实 HTTP：' . $variant . "、资源方法、三级中间件、URL 往返和 404/405/HEAD 通过。\n";
