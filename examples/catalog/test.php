<?php

declare(strict_types=1);

$root = getenv('TYPE_APP_TEST_DIRECTORY') ?: dirname(__DIR__);
require $root . '/vendor/autoload.php';

use Type\Testing\Assert;
use Type\Testing\HttpClient;
use Type\Testing\Process;
use Type\Testing\Suite;

/** 公开测试的外部控制入口显式传入数组命令；不依赖主仓或隐式运行器。 */
function catalogCommand(array $fallback, array $environment, bool $server = false, ?string $control = null): array
{
    $key = $control ?? ($server && isset($environment['TYPE_APP_SERVER_COMMAND']) ? 'TYPE_APP_SERVER_COMMAND' : 'TYPE_APP_COMMAND');
    if (!isset($environment[$key])) {
        return $fallback;
    }
    $command = json_decode($environment[$key], true, 512, JSON_THROW_ON_ERROR);
    Assert::true(is_array($command) && array_is_list($command) && $command !== [], '目录验收命令必须是非空数组');
    foreach ($command as $index => $argument) {
        Assert::true(is_string($argument) && !str_contains($argument, "\0"), '目录验收命令参数无效');
        $command[$index] = $argument = str_replace('{{test}}', 'catalog', $argument);
        if (str_contains($argument, '{{port}}')) {
            $port = $environment['APP_PORT'] ?? '';
            Assert::true(ctype_digit($port) && (int) $port > 0 && (int) $port <= 65535, '目录验收发布端口无效');
            $command[$index] = str_replace('{{port}}', $port, $argument);
        }
    }
    return $command;
}

/** 控制钩子与真实服务退出共享五秒预算，保留正常及异常退出回执。 */
function catalogStop(Process $process, string $root, array $settings): void
{
    $deadline = microtime(true) + 5;
    try {
        if ($process->running() && isset($settings['TYPE_APP_STOP_COMMAND'])) {
            $stopper = new Process(catalogCommand([], $settings, false, 'TYPE_APP_STOP_COMMAND'), $root, $settings);
            try {
                $signalled = $stopper->wait(max(0, $deadline - microtime(true)));
                Assert::true($signalled->successful(), '目录停止控制失败：' . $signalled->stderr);
            } finally {
                $stopper->stop();
            }
            $process->wait(max(0, $deadline - microtime(true)));
        }
    } finally {
        $result = $process->stop(max(0, $deadline - microtime(true)));
        $secrets = array_values(array_filter([$settings['DB_PASSWORD'] ?? '', $settings['APP_API_TOKEN'] ?? ''], static fn (string $secret): bool => $secret !== ''));
        echo 'server-stop ' . json_encode(['test' => 'catalog', 'exit-code' => $result->exitCode,
            'timed-out' => $result->timedOut, 'output-exceeded' => $result->outputExceeded, 'signal' => $result->signal,
            'stdout' => str_replace($secrets, '<REDACTED>', $result->stdout), 'stderr' => str_replace($secrets, '<REDACTED>', $result->stderr)], JSON_THROW_ON_ERROR) . "\n";
        Assert::true($result->successful(), '目录服务器未以成功状态正常停止，见 server-stop 回执');
    }
}

// 并发请求控制器复用同一公开客户端，令牌只通过进程环境传递。
if (($argv[1] ?? '') === '--ensure-request') {
    $client = new HttpClient((string) getenv('TYPE_CATALOG_URL'), 5);
    $response = $client->request('POST', '/products/ensure', [
        'Authorization' => 'Bearer ' . getenv('APP_API_TOKEN'), 'Content-Type' => 'application/json', 'X-Tenant' => 'catalog-a',
    ], '{"code":"contended","name":"竞争创建"}');
    Assert::same(201, $response->status, $response->body);
    echo json_encode($response->json(), JSON_THROW_ON_ERROR);
    exit(0);
}

$binary = getenv('TYPE_APP_BINARY');
$command = $binary ? [$binary] : [PHP_BINARY, $root . '/dev.php'];
$environment = getenv();
$suite = new Suite();
$suite->test('目录第二模块的身份、输入、关系、唯一身份与驱动冲突语义', static function () use ($root, $command, $environment): void {
    $migrate = new Process([...catalogCommand($command, $environment), 'migrate', 'run'], $root, $environment);
    try {
        Assert::true($migrate->wait(15)->successful(), '目录迁移失败');
    } finally {
        $migrate->stop();
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    Assert::true(is_resource($socket));
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $settings = $environment;
    $settings['APP_PORT'] = substr(strrchr($address, ':'), 1);
    $settings['APP_ALLOWED_HOSTS'] = $address;
    $settings['APP_API_TOKEN'] = str_repeat('catalog-test-', 4);
    $settings['TYPE_CATALOG_URL'] = 'http://' . $address;
    $server = new Process([...catalogCommand($command, $settings, true), 'serve'], $root, $settings);
    try {
        $client = new HttpClient('http://' . $address, 5);
        $ready = false;
        $deadline = microtime(true) + 10;
        do {
            try {
                $ready = $client->request('GET', '/readyz')->status === 200;
            } catch (RuntimeException) {
            }
            if ($ready || !$server->running()) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        Assert::true($ready, '目录HTTP未就绪：' . $server->stderr());
        if (isset($settings['TYPE_APP_OBSERVE_COMMAND'])) {
            $observer = new Process(catalogCommand([], $settings, false, 'TYPE_APP_OBSERVE_COMMAND'), $root, $settings);
            try {
                Assert::true($observer->wait(5)->successful(), '目录部署边界检查失败');
            } finally {
                $observer->stop();
            }
        }
        $authorization = ['Authorization' => 'Bearer ' . $settings['APP_API_TOKEN'], 'Content-Type' => 'application/json'];
        $headers = $authorization + ['X-Tenant' => 'catalog-a'];
        Assert::same(401, $client->request('GET', '/products')->status);
        Assert::same(403, $client->request('GET', '/products', $authorization)->status);
        Assert::same(403, $client->request('GET', '/products', $authorization + ['X-Tenant' => 'catalog-b'])->status);
        Assert::same(403, $client->request('GET', '/catalog-admin', $headers)->status);
        $created = $client->request('POST', '/products', $headers, '{"code":"first","name":"目录商品","note":"保留备注","tenant_id":"catalog-b","id":9999}');
        Assert::same(201, $created->status, $created->body);
        $product = $created->json();
        $id = $product['id'];
        Assert::true($id !== 9999 && !isset($product['tenant_id']) && $product['created_at'] > 0 && $product['updated_at'] >= $product['created_at']);
        Assert::same(422, $client->request('POST', '/products', $headers, '{"code":"bad","name":null}')->status);
        $missing = $client->request('PATCH', '/products/' . $id, $headers, '{}');
        Assert::same(200, $missing->status, $missing->body);
        Assert::same('保留备注', $missing->json()['note']);
        $null = $client->request('PATCH', '/products/' . $id, $headers, '{"note":null}');
        Assert::same(200, $null->status, $null->body);
        Assert::same(null, $null->json()['note']);
        Assert::same('目录商品', $null->json()['name']);
        Assert::same(422, $client->request('PATCH', '/products/' . $id, $headers, '{"name":null}')->status);
        $search = $client->request('GET', '/products?name=' . rawurlencode('目录商品'), $headers);
        Assert::same(200, $search->status, $search->body);
        Assert::same([$id], array_column($search->json()['data'], 'id'));
        Assert::same([], $client->request('GET', '/products?name=' . rawurlencode("' OR 1=1 --"), $headers)->json()['data']);
        $relation = $client->request('POST', '/products/' . $id . '/labels', $headers, '{}');
        Assert::same(200, $relation->status, $relation->body);
        Assert::true($relation->json()['invalidated']);
        Assert::same('featured', $relation->json()['product']['labels'][0]['code']);
        $first = $client->request('POST', '/products/import', $headers, '{}');
        $second = $client->request('POST', '/products/import', $headers, '{}');
        Assert::same(200, $first->status, $first->body);
        Assert::same(200, $second->status, $second->body);
        Assert::same(1, $first->json()['affected']);
        Assert::same($second->json()['driver'] === 'mysql' ? 2 : 1, $second->json()['affected']);
        $workers = [new Process([PHP_BINARY, __FILE__, '--ensure-request'], $root, $settings), new Process([PHP_BINARY, __FILE__, '--ensure-request'], $root, $settings)];
        try {
            $ids = [];
            foreach ($workers as $worker) {
                $result = $worker->wait(10);
                Assert::true($result->successful(), '竞争创建失败：' . $result->stdout . $result->stderr);
                $ids[] = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR)['id'];
            }
            Assert::same($ids[0], $ids[1], 'firstOrCreate没有收敛到同一身份');
        } finally {
            foreach ($workers as $worker) {
                $worker->stop();
            }
        }
        Assert::same(404, $client->request('GET', '/products/999999', $headers)->status);
    } finally {
        catalogStop($server, $root, $settings);
    }
});
$status = $suite->run();
foreach ($suite->results() as $result) {
    echo ($result['passed'] ? '通过' : '失败') . '：' . $result['name'] . ($result['passed'] ? '' : '，' . $result['message']) . "\n";
}
exit($status);
