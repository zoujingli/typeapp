<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-package-sandbox.php';

use Type\Cache\NamespaceStore;
use Type\Orm\Mysql\MysqlDriver;
use Type\Orm\Pgsql\PgsqlDriver;
use Type\Queue\Queue;
use Type\Redis\Purpose;
use Type\Redis\RedisConfiguration;
use Type\Redis\RedisManager;
use Type\Redis\StoragePolicy;
use Type\Runtime\ExecutionScope;
use Type\Testing\HttpClient;
use Type\Testing\Process;
use Type\Testing\ProcessResult;

$root = dirname(__DIR__);
$driver = $argv[1] ?? 'sqlite';
$native = in_array('--native', $argv, true);
expect(in_array($driver, ['mysql', 'pgsql', 'sqlite'], true), '发布演练驱动无效');
$sourceFiles = ['examples/model/Drivers.php', 'examples/outbox/Adapters.php', 'examples/rollout/Schema.php', 'examples/rollout/Codec.php',
    'examples/rollout/Publisher.php', 'examples/rollout/Endpoint.php', 'examples/rollout/Application.php'];
$commands = [];
$overrides = getenv('TYPE_ROLLOUT_COMMANDS');
$externalCommands = $overrides === false ? null : json_decode($overrides, true, 32, JSON_THROW_ON_ERROR);
expect($externalCommands === null || ($native && is_array($externalCommands) && isset($externalCommands['old'], $externalCommands['new'])), '原生发布命令覆盖必须同时指定新旧版本');
foreach (['old', 'new'] as $version) {
    $loader = 'require ' . var_export($root . '/vendor/autoload.php', true) . ';';
    foreach ($sourceFiles as $file) {
        $loader .= 'require ' . var_export($root . '/' . $file, true) . ';';
    }
    $loader .= 'require ' . var_export($root . '/examples/rollout-' . $version . '-command.php', true) . ';main($argc,$argv);';
    $commands[$version] = $externalCommands[$version] ?? ($native ? nativeCommand($root . '/build/rollout-' . $version . '/type-app') : [PHP_BINARY, '-d', 'swoole.enable_library=Off', '-r', $loader]);
}
$environment = getenv();
$delayInput = getenv('TYPE_ROLLOUT_DELAY_MS');
$delayMilliseconds = filter_var($delayInput === false ? '5000' : $delayInput, FILTER_VALIDATE_INT);
expect(is_int($delayMilliseconds) && $delayMilliseconds >= 1 && $delayMilliseconds <= 120000, '发布演练延迟必须在1至120000毫秒之间');
$environment['TYPE_MODEL_DRIVER'] = $driver;
$environment['TYPE_ROLLOUT_APP'] = 'type-rollout-' . bin2hex(random_bytes(6));
$dataParent = $root . '/build';
if (getenv('TYPE_ROLLOUT_DATA_PARENT') !== false) {
    $dataParent = realpath(getenv('TYPE_ROLLOUT_DATA_PARENT'));
    expect(
        $externalCommands !== null && is_string($dataParent)
        && preg_match('~^' . preg_quote($root, '~') . '/build/packaged-rollout-(?:sqlite|mysql|pgsql)-[a-f0-9]{12}/data$~D', $dataParent) === 1,
        '发布运行数据必须属于本轮控制器新建的专用目录'
    );
}
$directory = $dataParent . '/rollout-check-' . $driver . '-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$environment['TYPE_ROLLOUT_DATA_DIRECTORY'] = $directory;
$admin = null;
$createdDatabase = false;
$database = 'type_rollout_' . bin2hex(random_bytes(6));
$children = [];
$rolloutPackageProcesses = new WeakMap();
/** 只改变测试载体，参数保留数组；公开操作与断言不另做一套。 */
function rolloutCommand(array $command, array $environment, bool $server = false, ?string $processInfo = null): array
{
    if (isset($command['linux-package'])) {
        expect(PHP_OS_FAMILY === 'Linux' && isset($command['release-sha256']), 'Linux发布运行需要受信包身份');
        return ['/usr/bin/env', 'PATH=/usr/bin:/bin', 'TYPE_HTTP_DRIVER=stream', 'TYPE_HTTP_LISTEN=127.0.0.1',
            'TYPE_APP_RELEASE_SHA256=' . $command['release-sha256'],
            ...sandboxPackageCommand(dirname(__DIR__), $command['linux-package'], [$environment['TYPE_ROLLOUT_DATA_PARENT']], $processInfo)];
    }
    $selected = isset($command['command']) ? ($server ? ($command['server'] ?? $command['command']) : $command['command']) : $command;
    expect(is_array($selected) && array_is_list($selected) && $selected !== [], '发布命令必须是非空参数数组');
    foreach ($selected as &$argument) {
        expect(is_string($argument) && !str_contains($argument, "\0"), '发布命令参数无效');
        if (str_contains($argument, '{{port}}')) {
            $port = $environment['TYPE_HTTP_PORT'] ?? '';
            expect(ctype_digit($port) && (int) $port > 0 && (int) $port < 65536, '发布HTTP端口无效');
            $argument = str_replace('{{port}}', $port, $argument);
        }
        $argument = str_replace('{{data-directory}}', $environment['TYPE_ROLLOUT_DATA_DIRECTORY'], $argument);
    }
    unset($argument);
    return $selected;
}
/** Linux隔离长进程按bubblewrap记录的真实应用身份发信号，不能只停止sudo设置器。 */
function rolloutStop(Process $process, float $seconds): ProcessResult
{
    global $rolloutPackageProcesses;
    if (isset($rolloutPackageProcesses[$process])) {
        [$package, $processInfo] = $rolloutPackageProcesses[$process];
        return stopPackageProcess($process, $package, $processInfo, $seconds);
    }
    return $process->stop($seconds);
}
function rolloutCall(array $command, array $arguments, array $environment, bool $success = true): ProcessResult
{
    $process = new Process([...rolloutCommand($command, $environment), ...$arguments], null, $environment);
    try {
        $result = $process->wait(10);
        if ($success) {
            expect($result->successful(), '发布演练命令失败：' . $result->stderr);
        } return $result;
    } finally {
        $process->stop();
    }
}
function rolloutHttp(array $command, array $environment): array
{
    global $rolloutPackageProcesses;
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $environment['TYPE_HTTP_PORT'] = substr(strrchr($address, ':'), 1);
    $processInfo = isset($command['linux-package']) ? $environment['TYPE_ROLLOUT_DATA_DIRECTORY'] . '/http-' . bin2hex(random_bytes(6)) . '.json' : null;
    $process = new Process([...rolloutCommand($command, $environment, true, $processInfo), 'serve'], null, $environment);
    if ($processInfo !== null) {
        $rolloutPackageProcesses[$process] = [$command['linux-package'], $processInfo];
    }
    $client = new HttpClient('http://' . $address, 1);
    $deadline = microtime(true) + (isset($command['command']) ? 15 : 5);
    do {
        expect($process->running(), '发布演练 HTTP 提前退出：' . $process->stderr());
        try {
            if ($client->request('GET', '/readyz')->status === 200) {
                return [$process, $client];
            }
        } catch (RuntimeException) {
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    rolloutStop($process, 5);
    throw new RuntimeException('发布演练 HTTP 未按时就绪');
}
function rolloutWorker(array $command, array $environment): Process
{
    global $rolloutPackageProcesses;
    $processInfo = isset($command['linux-package']) ? $environment['TYPE_ROLLOUT_DATA_DIRECTORY'] . '/worker-' . bin2hex(random_bytes(6)) . '.json' : null;
    $process = new Process([...rolloutCommand($command, $environment, false, $processInfo), 'worker'], null, $environment);
    if ($processInfo !== null) {
        $rolloutPackageProcesses[$process] = [$command['linux-package'], $processInfo];
    }
    $deadline = microtime(true) + (isset($command['command']) ? 15 : 5);
    do {
        if (str_contains($process->stdout(), '"ready":true')) {
            return $process;
        } expect($process->running(), 'worker 提前退出：' . $process->stderr());
        usleep(10000);
    } while (microtime(true) < $deadline);
    rolloutStop($process, 5);
    throw new RuntimeException('worker 没有进入就绪');
}
try {
    $policy = StoragePolicy::verify(new RedisConfiguration((string) getenv('TYPE_REDIS_HOST'), (int) (getenv('TYPE_REDIS_PORT') ?: 6379)), new RedisConfiguration((string) getenv('TYPE_ROLLOUT_CACHE_HOST'), (int) (getenv('TYPE_ROLLOUT_CACHE_PORT') ?: 6379)));
    expect($policy['isolated'], '发布演练没有隔离缓存与可靠队列');
    if ($driver === 'sqlite') {
        $environment['TYPE_SQLITE_FILE'] = $directory . '/database.sqlite';
    } elseif ($driver === 'mysql') {
        $admin = (new MysqlDriver(getenv('TYPE_MYSQL_HOST') ?: '127.0.0.1', (int) (getenv('TYPE_MYSQL_PORT') ?: 3306), getenv('TYPE_MYSQL_DATABASE') ?: 'type_app_test', getenv('TYPE_MYSQL_USER') ?: 'root', getenv('TYPE_MYSQL_PASSWORD') ?: ''))->connect();
        $admin->exec('CREATE DATABASE ' . $database);
        $createdDatabase = true;
        $environment['TYPE_MYSQL_DATABASE'] = $database;
    } else {
        $admin = (new PgsqlDriver(getenv('TYPE_PGSQL_HOST') ?: '127.0.0.1', (int) (getenv('TYPE_PGSQL_PORT') ?: 5432), getenv('TYPE_PGSQL_DATABASE') ?: 'type_app_test', getenv('TYPE_PGSQL_USER') ?: 'type_app', getenv('TYPE_PGSQL_PASSWORD') ?: ''))->connect();
        $admin->exec('CREATE DATABASE ' . $database);
        $createdDatabase = true;
        $environment['TYPE_PGSQL_DATABASE'] = $database;
    }
    $old = $commands['old'];
    $new = $commands['new'];
    rolloutCall($old, ['migrate', '1'], $environment);
    rolloutCall($old, ['write', 'old-ready', '初始用户'], $environment);
    rolloutCall($old, ['write', 'delayed-old', '初始用户'], $environment);
    foreach (['invalid', '0', '-1', '120001'] as $invalidDelay) {
        $invalidRelay = rolloutCall($old, ['relay', $invalidDelay], $environment, false);
        expect(!$invalidRelay->successful() && str_contains($invalidRelay->stderr, '发布演练延迟'), '非法延迟未在投递前拒绝');
    }
    $relayStarted = microtime(true);
    rolloutCall($old, ['relay', (string) $delayMilliseconds], $environment);
    $oldWorker = rolloutWorker($old, $environment);
    $children[] = $oldWorker;
    [$oldHttp, $oldClient] = rolloutHttp($old, $environment);
    $children[] = $oldHttp;
    expect($oldClient->request('GET', '/user')->json()['name'] === '初始用户', '旧版读取初始用户失败');
    expect($oldClient->request('GET', '/user')->json()['cache_hit'], '旧版缓存未命中');
    $failed = rolloutCall($new, ['migrate', '2'], $environment, false);
    expect(!$failed->successful() && str_contains($failed->stderr, 'TYPE_MIGRATION_FAILED'), '迁移失败未被记录');
    expect($oldClient->request('GET', '/user?strong=1')->json()['name'] === '初始用户', '扩展迁移失败损坏了旧版读路径');
    expect(!rolloutCall($new, ['check'], $environment, false)->successful(), '新版在扩展未完成时仍可启动');
    rolloutCall($new, ['repair'], $environment);
    $bad = $environment;
    $bad['TYPE_HTTP_PORT'] = 'bad-port';
    expect(!rolloutCall($new, ['serve'], $bad, false)->successful(), '错误部署配置没有拒绝');
    expect($oldClient->request('GET', '/user?strong=1')->status === 200, '新版配置失败影响旧服务');
    $newWorker = rolloutWorker($new, $environment);
    $children[] = $newWorker;
    expect($oldWorker->running() && $newWorker->running(), '新旧 worker 没有共存');
    expect(!rolloutCall($old, ['consumer-check', '2'], $environment, false)->successful(), '旧消费者错误声明新消息兼容');
    rolloutCall($new, ['consumer-check', '2'], $environment);
    $oldStopped = rolloutStop($oldWorker, 2);
    expect($oldStopped->signal !== 9 && str_contains($oldStopped->stdout, '"ready":false'), '旧消费者没有先撤销就绪再排空');
    $stats = json_decode(rolloutCall($new, ['stats'], $environment)->stdout, true, 512, JSON_THROW_ON_ERROR);
    $consumerSwitchMilliseconds = (int) ((microtime(true) - $relayStarted) * 1000);
    expect($stats['queue']['delayed'] === 1, '旧延迟消息没有保留到消费者切换');
    [$newHttp, $newClient] = rolloutHttp($new, $environment);
    $children[] = $newHttp;
    expect(!$newClient->request('GET', '/user')->json()['cache_hit'], '新格式错误接受旧缓存');
    expect($newClient->request('GET', '/user')->json()['cache_hit'], '新格式缓存没有写入');
    expect(!$oldClient->request('GET', '/user')->json()['cache_hit'], '旧格式错误接受新版缓存');
    rolloutCall($new, ['write', 'new-ready', '升级用户'], $environment);
    rolloutCall($new, ['relay'], $environment);
    expect($oldClient->request('GET', '/user?strong=1')->json()['name'] === '升级用户' && $newClient->request('GET', '/user?strong=1')->json()['name'] === '升级用户', '兼容窗口中新旧应用结果不一致');
    expect(rolloutStop($oldHttp, 5)->signal !== 9 && rolloutStop($newHttp, 5)->signal !== 9, '切换应用时未能有限排空');
    [$rollbackHttp, $rollbackClient] = rolloutHttp($old, $environment);
    $children[] = $rollbackHttp;
    rolloutCall($old, ['write', 'rollback-ready', '回滚用户'], $environment);
    rolloutCall($old, ['relay'], $environment);
    expect($rollbackClient->request('GET', '/user?strong=1')->json()['name'] === '回滚用户', '兼容窗口内旧二进制不能恢复写入');
    rolloutCall($new, ['unknown'], $environment);
    $deadline = max(microtime(true) + 10, $relayStarted + $delayMilliseconds / 1000 + 30);
    do {
        $stats = json_decode(rolloutCall($new, ['stats'], $environment)->stdout, true, 512, JSON_THROW_ON_ERROR);
        if (count($stats['effects']) === 4 && $stats['queue']['quarantined'] === 1) {
            break;
        } usleep(50000);
    } while (microtime(true) < $deadline);
    expect(count($stats['effects']) === 4 && $stats['queue']['quarantined'] === 1 && $stats['queue']['delayed'] === 0, '跨版本延迟、Outbox 消费或未知版本隔离失败');
    $ids = array_column($stats['effects'], 'id');
    sort($ids);
    expect($ids === ['delayed-old', 'new-ready', 'old-ready', 'rollback-ready'], '业务效果缺失或重复');
    expect(rolloutStop($rollbackHttp, 5)->signal !== 9 && rolloutStop($newWorker, 2)->signal !== 9, '结构收缩前进程没有排空');
    rolloutCall($new, ['contract', '--old-processes-drained'], $environment);
    $rejected = rolloutCall($old, ['check'], $environment, false);
    expect(!$rejected->successful() && str_contains($rejected->stderr, '不支持当前数据库协议'), '破坏性收缩后仍允许旧版回滚');
    [$finalHttp, $finalClient] = rolloutHttp($new, $environment);
    $children[] = $finalHttp;
    expect($finalClient->request('GET', '/user?strong=1')->json()['name'] === '回滚用户', '收缩前回填丢失了旧版写入');
    expect(rolloutStop($finalHttp, 5)->signal !== 9, '最终进程未退出');
    $verification = ['driver' => $driver, 'native' => $native, 'packaged' => $externalCommands !== null,
        'delay-ms' => $delayMilliseconds, 'relay-start-to-consumer-switch-ms' => $consumerSwitchMilliseconds,
        'checks' => ['invalid-delay', 'expand-failure', 'repair', 'bad-config', 'mixed-workers', 'consumer-first', 'old-delayed', 'cache-versions', 'binary-rollback', 'unknown-message', 'contract-rejection'], 'effects' => $stats['effects']];
    echo $driver . " 新旧版本共存、迁移失败恢复、消费者先行、延迟与缓存格式、回滚窗口及收缩拒绝通过。\n";
} finally {
    foreach ($children as $child) {
        rolloutStop($child, 2);
    }
    if ($admin !== null && $createdDatabase) {
        $admin->exec('DROP DATABASE ' . $database);
    }
    $scope = new ExecutionScope();
    $manager = new RedisManager(['queue' => new RedisConfiguration((string) getenv('TYPE_REDIS_HOST'), (int) (getenv('TYPE_REDIS_PORT') ?: 6379)), 'cache' => new RedisConfiguration((string) getenv('TYPE_ROLLOUT_CACHE_HOST'), (int) (getenv('TYPE_ROLLOUT_CACHE_PORT') ?: 6379))]);
    try {
        $roots = ['queue' => (new Queue($manager->connection($scope, 'queue', Purpose::SCRIPT), $environment['TYPE_ROLLOUT_APP'], 'rollout'))->identity(),
            'cache' => (new NamespaceStore($manager->connection($scope, 'cache', Purpose::SCRIPT), $environment['TYPE_ROLLOUT_APP'], 'test', 'users'))->identity()];
        foreach ($roots as $name => $prefix) {
            $connection = (new RedisConfiguration((string) getenv($name === 'queue' ? 'TYPE_REDIS_HOST' : 'TYPE_ROLLOUT_CACHE_HOST'), (int) (getenv($name === 'queue' ? 'TYPE_REDIS_PORT' : 'TYPE_ROLLOUT_CACHE_PORT') ?: 6379)))->connect();
            $iterator = null;
            do {
                $keys = $connection->scan($iterator, $prefix . ':*', 100);
                if (is_array($keys) && $keys !== []) {
                    $connection->del($keys);
                }
            } while ($iterator !== 0);
            $connection->close();
        }
    } finally {
        $scope->close();
        $manager->close();
    }
}
file_put_contents($directory . '/verification.json', json_encode($verification, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
if (getenv('TYPE_ROLLOUT_REPORT') !== false) {
    file_put_contents(getenv('TYPE_ROLLOUT_REPORT'), json_encode(['report' => $directory . '/verification.json'], JSON_THROW_ON_ERROR));
}
