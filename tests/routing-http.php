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
$generated = $compiler->generate($root, $compiler->declarations($root, $settings['routing']), array_map(static fn (string $file): string => $root . '/' . $file, $settings['sources']));
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
$command = isset($argv[1]) && $argv[1] !== '--php' ? nativeCommand($argv[1]) : [PHP_BINARY, '-d', 'swoole.enable_library=Off', '-r', $launcher];
$process = null;
$graceful = true;
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
    [$status, $body] = httpRequest($port, 'HEAD', '/api/books/23');
    expect($status === 200 && $body === '', 'HEAD 必须复用 GET 路由并省略正文');
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
