<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Testing\HttpClient;
use Type\Testing\Process;

/** 观察真实HTTP而不是仅等待一个新PID。 */
function watchResponse(Process $watcher, HttpClient $client, string $expected): array
{
    $until = microtime(true) + 15;
    do {
        expect($watcher->running(), 'watch提前退出：' . $watcher->stderr());
        try {
            $response = $client->request('GET', '/');
            if ($response->status === 200 && ($response->json()['value'] ?? null) === $expected) {
                return $response->json();
            }
        } catch (RuntimeException) {
        }
        usleep(50000);
    } while (microtime(true) < $until);
    throw new RuntimeException('重载后HTTP没有返回预期版本：' . $watcher->stderr());
}

$root = dirname(__DIR__);
$directory = $root . '/build/watch-test-' . bin2hex(random_bytes(6));
expect(mkdir($directory . '/src', 0700, true), '无法创建独立watch项目');
// 只验证监督行为；依赖安装与模板消费有独立验收。
expect(symlink('../../vendor', $directory . '/vendor'), '无法为测试引用已安装依赖');
file_put_contents($directory . '/composer.json', '{"name":"type-tests/watch","require":{"zoujingli/type-core":"1.0.x-dev"}}');
copy($root . '/composer.lock', $directory . '/composer.lock');
$configuration = ['name' => 'watch-test', 'entry' => 'src/main.php', 'sources' => ['src'], 'output' => 'build/app', 'build-directory' => 'build/compiler',
    'development' => ['entry' => 'dev.php', 'output' => 'build/development', 'check' => ['check'], 'watch' => ['metadata.json']]];
file_put_contents($directory . '/metadata.json', '{}');
file_put_contents($directory . '/type-app.json', json_encode($configuration, JSON_THROW_ON_ERROR));
file_put_contents($directory . '/dev.php', '<?php require __DIR__."/vendor/autoload.php"; require __DIR__."/src/Business.php"; require __DIR__."/src/main.php"; main($argc,$argv);');
$source = $directory . '/src/Business.php';
$business = '<?php declare(strict_types=1); final class WatchBusiness { public static function value(): string { return "VERSION"; } }';
file_put_contents($source, str_replace('VERSION', 'one', $business));
file_put_contents($directory . '/src/main.php', <<<'PHP'
<?php
declare(strict_types=1);
final class WatchHandler implements \Psr\Http\Server\RequestHandlerInterface
{
    public function __construct(private string $label) {}
    public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        $factory = new \Type\Core\Http\Message\Factory();
        return $factory->createResponse()->withBody($factory->createStream(json_encode(['value'=>WatchBusiness::value().':'.$this->label, 'pid'=>getmypid()], JSON_THROW_ON_ERROR)));
    }
}
function main(int $argc, array $argv): void
{
    $environment = \Type\Core\Config\Environment::load(getenv('APP_BASE_PATH').'/.env');
    if (($argv[1] ?? '') === 'check') {
        if (is_file(__DIR__.'/../hold-check')) {
            file_put_contents(__DIR__.'/../checking', (string)getmypid());
            usleep(20000000);
        }
        echo "check-ok\n";
        return;
    }
    $factory = new \Type\Core\Http\Message\Factory();
    $server = new \Type\Core\Http\SwooleServer(new WatchHandler((string)$environment->get('WATCH_LABEL','none')), $factory, $factory, $factory);
    $server->serve('127.0.0.1', (int)getenv('TYPE_WATCH_PORT'));
}
PHP);
file_put_contents($directory . '/.env', "WATCH_LABEL=alpha\n");
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
$address = stream_socket_get_name($listener, false);
fclose($listener);
$environment = getenv();
$environment['APP_BASE_PATH'] = $directory;
$environment['TYPE_WATCH_PORT'] = substr(strrchr($address, ':'), 1);
$watcher = new Process([PHP_BINARY, $root . '/vendor/bin/type', 'watch', $directory . '/type-app.json'], $directory, $environment);
$client = new HttpClient('http://' . $address, 0.5);
try {
    $first = watchResponse($watcher, $client, 'one:alpha');
    $time = filemtime($source);
    file_put_contents($source, str_replace('VERSION', 'two', $business));
    touch($source, $time);
    $second = watchResponse($watcher, $client, 'two:alpha');
    expect($second['pid'] !== $first['pid'], '修改源码没有更换进程');
    file_put_contents($source, '<?php function invalid( {');
    $until = microtime(true) + 10;
    while (!str_contains($watcher->stderr(), 'input rejected') && microtime(true) < $until) {
        usleep(50000);
    }
    expect(str_contains($watcher->stderr(), 'input rejected'), '无效输入没有报告');
    expect(watchResponse($watcher, $client, 'two:alpha')['pid'] === $second['pid'], '无效修改替换了可运行的旧进程');
    file_put_contents($source, str_replace('VERSION', 'three', $business));
    $third = watchResponse($watcher, $client, 'three:alpha');
    expect($third['pid'] !== $second['pid'], '修复源码后没有恢复重载');
    file_put_contents($directory . '/.env', 'invalid dotenv secret-watch-sentinel');
    $until = microtime(true) + 10;
    while (!str_contains($watcher->stderr(), '开发应用检查失败') && microtime(true) < $until) {
        usleep(50000);
    }
    expect(str_contains($watcher->stderr(), '开发应用检查失败') && watchResponse($watcher, $client, 'three:alpha')['pid'] === $third['pid'], '非法运行配置替换了旧服务');
    expect(!str_contains($watcher->stdout() . $watcher->stderr(), 'secret-watch-sentinel'), 'watch错误泄漏了环境配置值');
    file_put_contents($directory . '/.env', "WATCH_LABEL=beta\n");
    $fourth = watchResponse($watcher, $client, 'three:beta');
    expect($fourth['pid'] !== $third['pid'], '运行配置变化没有重启');

    // 原声明文件已不存在时，必须先接受新配置，不能一直对缓存旧路径求指纹。
    rename($directory . '/metadata.json', $directory . '/renamed.json');
    $configuration['development']['watch'] = ['renamed.json'];
    file_put_contents($directory . '/type-app.json', json_encode($configuration, JSON_THROW_ON_ERROR));
    file_put_contents($source, str_replace('VERSION', 'renamed', $business));
    $renamed = watchResponse($watcher, $client, 'renamed:beta');
    expect($renamed['pid'] !== $fourth['pid'], '更新声明后没有摆脱已删除的旧监听路径');
    unlink($directory . '/renamed.json');
    $until = microtime(true) + 5;
    while (!str_contains($watcher->stderr(), 'watch输入缺失') && microtime(true) < $until) {
        usleep(50000);
    }
    expect(str_contains($watcher->stderr(), 'watch输入缺失'), '监听路径删除没有明确拒绝');
    expect(watchResponse($watcher, $client, 'renamed:beta')['pid'] === $renamed['pid'], '缺失输入替换了在用进程');
    $configuration['development']['watch'] = [];
    file_put_contents($directory . '/type-app.json', json_encode($configuration, JSON_THROW_ON_ERROR));
    file_put_contents($source, str_replace('VERSION', 'repaired', $business));
    expect(watchResponse($watcher, $client, 'repaired:beta')['pid'] !== $renamed['pid'], '撤回缺失路径后无法恢复重载');
    file_put_contents($directory . '/hold-check', 'test-only');
    file_put_contents($source, str_replace('VERSION', 'four', $business));
    $until = microtime(true) + 10;
    while (!is_file($directory . '/checking') && microtime(true) < $until) {
        usleep(50000);
    }
    expect(is_file($directory . '/checking'), '未进入本轮延迟检查夹具');
    $checkPid = (int) file_get_contents($directory . '/checking');
    $stopStarted = microtime(true);
    $stopped = $watcher->stop(10);
    expect($stopped->successful(), '监督器没有正常停止：' . $stopped->stderr);
    expect(microtime(true) - $stopStarted < 3, '停止没有取消仍在运行的准备/检查子进程');
    if (function_exists('posix_kill')) {
        expect(!posix_kill($checkPid, 0), '退出后遗留检查子进程');
    }
    $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.2);
    expect($connection === false, 'watch退出后遗留HTTP子进程');
    echo "watch真实HTTP、保留mtime修改、无效输入保留、声明改名/删除恢复、环境更新与子进程清理通过。\n";
} finally {
    $watcher->stop(10);
    unlink($directory . '/.env');
}
file_put_contents($directory . '/verification.json', json_encode(
    ['platform' => PHP_OS_FAMILY, 'php' => PHP_VERSION,
    'http-processes' => [$first['pid'], $second['pid'], $third['pid'], $fourth['pid'], $renamed['pid']],
    'cancelled-check-pid' => $checkPid, 'port' => $environment['TYPE_WATCH_PORT'], 'temporary-env-removed' => !file_exists($directory . '/.env'),
    'checks' => ['actual-http', 'mtime-preserving-source-change', 'invalid-input-retains-service', 'dotenv-failure-redacted',
        'dotenv-reload', 'declaration-rename', 'missing-input-retains-service', 'repaired-declaration-reloads', 'pending-check-cancelled', 'http-child-removed']],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
));
echo 'watch验收记录：' . $directory . "/verification.json\n";
