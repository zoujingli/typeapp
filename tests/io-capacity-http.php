<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require __DIR__ . '/http-support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Testing\Process;
use Type\Build\BuildEnvironment;
use Type\Build\BuildPlatform;

$root = dirname(__DIR__);
$work = BuildPlatform::path($argv[1] ?? '');
$verify = ($argv[2] ?? '') === '--verify';
expect(
    $argc === ($verify ? 3 : 2) && BuildPlatform::contains($root . '/build', $work) && !str_contains($work, '..'),
    '用法：php tests/io-capacity-http.php <build 下独立消费者绝对目录> [--verify]'
);
$runner = new BuildEnvironment();
$environment = $runner->environment((string) getenv('PHP_HOME'), (string) getenv('PHPX_HOME'));
if (!$verify) {
    expect(!file_exists($work) && mkdir($work . '/app', 0700, true), '需要尚不存在的消费者目录');
    $composer = ['name' => 'type-tests/io-capacity-http', 'type' => 'project', 'license' => 'Apache-2.0',
        'require' => [], 'require-dev' => ['zoujingli/type-build' => '~1.0.0@dev', 'swoole/typephp' => '0.9.0', 'swoole/phpx' => '2.9.0'],
        'repositories' => [], 'autoload' => ['classmap' => ['app']], 'minimum-stability' => 'dev', 'prefer-stable' => true,
        'config' => ['allow-plugins' => false]];
    // Composer 路径以消费者为基准，保留嵌套目录和含空格路径的可迁移性。
    $projectPath = str_repeat('../', substr_count(substr($work, strlen($root) + 1), '/') + 1);
    foreach (['type-core', 'type-runtime', 'type-log', 'type-orm', 'type-validate', 'type-build'] as $package) {
        if ($package !== 'type-build') {
            $composer['require']['zoujingli/' . $package] = '~1.0.0@dev';
        }
        $composer['repositories'][] = ['type' => 'path', 'url' => $projectPath . 'plugin/' . $package,
            'options' => ['symlink' => false, 'versions' => ['zoujingli/' . $package => '1.0.x-dev']]];
    }
    $configuration = ['name' => 'io-capacity-http', 'entry' => 'app/main.php', 'sources' => ['app'],
        'output' => (new BuildPlatform())->output('build/native/type-app'), 'build-directory' => 'build/native/compiler',
        'runtime' => [PHP_OS_FAMILY => ['extensions' => ['swoole']]], 'compiler' => ['debug' => true, 'jobs' => 2]];
    foreach (['composer.json' => $composer, 'type-app.json' => $configuration] as $name => $value) {
        file_put_contents($work . '/' . $name, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
    expect(copy($root . '/toolchain.lock.json', $work . '/toolchain.lock.json')
        && copy(__DIR__ . '/fixtures/io-capacity-http.php', $work . '/app/main.php')
        && copy($root . '/app/common/middleware/ApiErrors.php', $work . '/app/ApiErrors.php'), '无法复制完整消费者输入');
    $composerBinary = (string) (getenv('COMPOSER_BINARY') ?: trim(successful(['which', 'composer'])));
    expect(is_file($composerBinary), '需要真实的 Composer 脚本');
    file_put_contents($work . '/install.log', $runner->run([PHP_BINARY, $composerBinary, 'install', '--no-interaction',
        '--no-scripts', '--no-plugins', '--no-progress'], $work, $environment + ['COMPOSER_HOME' => $work . '/composer-home'], 300));
    foreach (array_keys($composer['require']) as $package) {
        expect(!is_link($work . '/vendor/' . $package), '独立消费不能使用主仓组件软链接');
    }
    file_put_contents($work . '/build.log', $runner->run([PHP_BINARY, $work . '/vendor/bin/type', $work . '/type-app.json'], $work, $environment, 900));
}
$binary = (new BuildPlatform())->output($work . '/build/native/type-app');
$report = json_decode(file_get_contents($binary . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
expect(hash_file('sha256', $binary) === $report['sha256'], '消费者产物摘要不符');
$packages = array_keys($report['production-packages']);
sort($packages);
expect($packages === ['psr/http-factory', 'psr/http-message', 'psr/http-server-handler', 'psr/http-server-middleware', 'psr/log',
    'zoujingli/type-core', 'zoujingli/type-log', 'zoujingli/type-orm', 'zoujingli/type-runtime', 'zoujingli/type-validate'], '需要完整编译十个生产包');
foreach ($report['sources'] as $source) {
    expect(BuildPlatform::contains($work, $source), '消费者不能引用主仓生产输入');
}
expect(is_dir($work . '/php.d') || mkdir($work . '/php.d', 0700), '无法建立空扫描目录');
expect(copy($report['runtime-profile']['ini'], $work . '/run.ini'), '无法保存运行配置');
$environment['PHPRC'] = $work . '/run.ini';
$environment['PHP_INI_SCAN_DIR'] = $work . '/php.d';
$policy = [];
if (PHP_OS_FAMILY === 'Darwin') {
    $policy = ['sandbox-exec', '-f', __DIR__ . '/fixtures/mqtt-no-source.sb'];
    foreach (['ROOT_APP' => $root . '/app', 'ROOT_PLUGIN' => $root . '/plugin', 'ROOT_EXAMPLE' => $root . '/tests',
        'ROOT_VENDOR' => $root . '/vendor', 'APP' => $work . '/app', 'VENDOR' => $work . '/vendor',
        'COMPILER' => $work . '/build/native/compiler', 'ROOT_COMPOSER' => $root . '/composer.json', 'COMPOSER' => $work . '/composer.json'] as $key => $path) {
        array_push($policy, '-D', $key . '=' . $path);
    }
    $probe = 'foreach (array_slice($argv, 1) as $file) { if (@file_get_contents($file) !== false) throw new RuntimeException("source readable"); } echo "source-denied\n";';
    expect(successful([...$policy, PHP_BINARY, '-n', '-r', $probe, $work . '/app/main.php', $work . '/vendor/autoload.php',
        $root . '/app/common/middleware/ApiErrors.php'], $work) === "source-denied\n", '无源码隔离没有生效');
}
$file = $work . '/oversized.bin';
$resource = fopen($file, 'x+b');
expect($resource !== false && ftruncate($resource, 16777216) && fclose($resource), '无法建立超量真实文件');
$results = [];
try {
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
    fclose($listener);
    $environment['TYPE_HTTP_PORT'] = (string) $port;
    $environment['TYPE_CAPACITY_FILE'] = $file;
    $server = new Process([...$policy, $binary], $work, $environment);
    try {
        $deadline = microtime(true) + 10;
        do {
            expect($server->running(), 'HTTP 容量服务提前退出：' . $server->stderr() . $server->stdout());
            $connection = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        foreach (['/native', '/framework', '/api/native', '/api/framework', '/metadata', '/first',
            '/unrelated-runtime', '/unrelated-swoole', '/api/unrelated-runtime', '/api/unrelated-swoole'] as $path) {
            [$status, $body, $headers] = httpRequest($port, 'GET', $path);
            $capacity = !str_contains($path, 'unrelated');
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            expect(
                $status === ($capacity ? 503 : 500)
                && $data['error'] === ($capacity ? 'resource_capacity_exceeded' : 'internal_error')
                && str_contains($headers, 'retry-after: 1') === $capacity,
                'Swoole 错误响应不符：' . $path . ' ' . $status . ' ' . $body
            );
            expect(!str_contains($body, 'private-') && !str_contains($body, $root), '响应泄漏内部信息');
            if (str_starts_with($path, '/api/')) {
                expect(preg_match('/^[a-f0-9]{32}$/D', $data['request_id'] ?? '') === 1, '应用请求关联丢失');
            }
            [$recovered, $content] = httpRequest($port, 'GET', '/healthy');
            expect($recovered === 200 && $content === 'healthy', '拒绝后服务不能继续处理请求');
            $results[] = ['transport' => 'swoole', 'path' => $path, 'status' => $status, 'error' => $data['error']];
        }
        [$status, $body, $headers] = httpRequest($port, 'HEAD', '/metadata');
        expect($status === 503 && $body === '' && str_contains($headers, 'retry-after: 1'), 'HEAD 元信息容量拒绝丢失');
        $connection = sendHttp($port, 'GET', '/later');
        $wire = stream_get_contents($connection);
        fclose($connection);
        expect(str_starts_with($wire, 'HTTP/1.1 200') && substr_count($wire, 'HTTP/1.1') === 1
            && !str_contains($wire, 'resource_capacity_exceeded') && !str_ends_with($wire, "0\r\n\r\n")
            && strlen($wire) > 16384 && strlen($wire) < 32768, '首块后拒绝伪装完整响应或拼接第二份响应');
        echo "Swoole：原生容量拒绝、应用错误边界、发送前后与恢复通过。\n";
    } finally {
        $stopped = $server->stop(2.0);
        file_put_contents($work . '/swoole.stdout.log', $server->stdout());
        file_put_contents($work . '/swoole.stderr.log', $server->stderr());
        file_put_contents($work . '/swoole.exit.json', json_encode(['exit' => $stopped->exitCode,
            'signal' => $stopped->signal, 'timed-out' => $stopped->timedOut], JSON_THROW_ON_ERROR) . "\n");
        expect(!$server->running(), '本轮服务未退出');
        $released = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error);
        expect(is_resource($released), '本轮 HTTP 监听端口未释放');
        fclose($released);
    }
    file_put_contents($work . '/http-evidence.json', json_encode(['build-id' => $report['build-id'], 'sha256' => $report['sha256'],
        'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'source-denied' => $policy !== [],
        'results' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
} finally {
    unlink($file);
}
