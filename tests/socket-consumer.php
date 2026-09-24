<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\BuildEnvironment;
use Type\Build\BuildPlatform;
use Type\Testing\Process;

$root = BuildPlatform::resolve(dirname(__DIR__));
$protocol = $protocol ?? 'tcp';
expect(in_array($protocol, ['tcp', 'udp'], true), '不支持的通信消费者');
$work = BuildPlatform::path($argv[1] ?? '');
$verify = ($argv[2] ?? '') === '--verify';
expect($argc === ($verify ? 3 : 2), '用法：php tests/' . $protocol . '-consumer.php <build 下独立消费者绝对目录> [--verify]');
expect(BuildPlatform::contains($root . '/build', $work) && !str_contains($work, '..'), '消费者必须在主仓 build 内');
$artifact = (new BuildPlatform())->output($work . '/build/native/type-app');
$runner = new BuildEnvironment();
$environment = $runner->environment((string) getenv('PHP_HOME'), (string) getenv('PHPX_HOME'));
// 安装与编译子进程使用已核验的 CLI 扩展配置；运行期仍由产物自己的探针选择模块。
$environment['PHPRC'] = php_ini_loaded_file() ?: '';
$environment['PHP_INI_SCAN_DIR'] = (string) (getenv('PHP_INI_SCAN_DIR') ?: '');
$environment['COMPOSER_CACHE_DIR'] = (string) (getenv('COMPOSER_CACHE_DIR') ?: $root . '/.cache/composer');
if (!$verify) {
    expect(!file_exists($work) && mkdir($work . '/app', 0700, true), '需要尚不存在的独立消费者目录');
    $composer = [
        'name' => 'type-tests/' . $protocol . '-consumer', 'type' => 'project', 'license' => 'Apache-2.0',
        'require' => ['zoujingli/type-core' => '~1.0.0@dev'],
        'require-dev' => ['zoujingli/type-build' => '~1.0.0@dev', 'swoole/typephp' => '0.9.3', 'swoole/phpx' => '2.9.2'],
        'repositories' => [], 'autoload' => ['classmap' => ['app']],
        'minimum-stability' => 'dev', 'prefer-stable' => true, 'config' => ['allow-plugins' => false],
    ];
    foreach (['type-core', 'type-runtime', 'type-build'] as $package) {
        $composer['repositories'][] = ['type' => 'path', 'url' => $root . '/plugin/' . $package,
            'options' => ['symlink' => false, 'versions' => ['zoujingli/' . $package => '1.0.x-dev']]];
    }
    $configuration = ['name' => $protocol . '-consumer', 'entry' => 'app/main.php', 'sources' => ['app'],
        'output' => (new BuildPlatform())->output('build/native/type-app'), 'build-directory' => 'build/native/compiler',
        'threads' => [$protocol => ucfirst($protocol) . 'Probe::run'], 'runtime' => [PHP_OS_FAMILY => ['extensions' => ['sockets', 'swoole']]],
        'compiler' => ['debug' => true, 'jobs' => 2]];
    $swooleModule = getenv('TYPE_SWOOLE_MODULE');
    if (is_string($swooleModule) && $swooleModule !== '') {
        $swooleModule = BuildPlatform::resolve($swooleModule);
        expect(is_file($swooleModule), '指定的 Swoole 模块不存在');
        expect(mkdir($work . '/modules', 0700), '无法创建独立模块目录');
        $moduleName = PHP_OS_FAMILY === 'Windows' ? 'php_swoole.dll' : 'swoole.so';
        expect(copy($swooleModule, $work . '/modules/' . $moduleName), '无法保全指定的 Swoole 模块');
        $configuration['runtime'][PHP_OS_FAMILY]['modules']['swoole'] = [
            'file' => 'modules/' . $moduleName, 'sha256' => hash_file('sha256', $swooleModule),
        ];
    }
    foreach (['composer.json' => $composer, 'type-app.json' => $configuration] as $file => $data) {
        file_put_contents($work . '/' . $file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
    expect(copy($root . '/toolchain.lock.json', $work . '/toolchain.lock.json'), '无法复制工具链锁');
    expect(copy(__DIR__ . '/fixtures/compiled-' . $protocol . '.php', $work . '/app/main.php'), '无法复制消费者入口');
    // 示例整体参与编译；只改入口名称以便同一产物从 example 命令调用。
    $example = str_replace('function main(', 'function ' . $protocol . 'ExampleMain(', (string) file_get_contents($root . '/examples/' . $protocol . '/main.php'), $count);
    expect($count === 1, '示例必须有唯一声明入口');
    file_put_contents($work . '/app/example.php', $example);
    $composerBinary = (string) (getenv('COMPOSER_BINARY') ?: trim(successful(['which', 'composer'])));
    expect(is_file($composerBinary), '需要 COMPOSER_BINARY 指向真实 Composer 脚本');
    file_put_contents($work . '/install.log', $runner->run([PHP_BINARY, $composerBinary, 'install', '--no-interaction',
        '--no-scripts', '--no-plugins', '--no-progress'], $work, $environment + ['COMPOSER_HOME' => $work . '/composer-home'], 300));
    foreach (['type-core', 'type-runtime'] as $package) {
        expect(!is_link($work . '/vendor/zoujingli/' . $package), '独立安装不得使用主仓软链接');
    }
    echo strtoupper($protocol) . " 独立消费安装完成，开始全量 AOT。\n";
    file_put_contents($work . '/build.log', $runner->run([PHP_BINARY, $work . '/vendor/bin/type', $work . '/type-app.json'], $work, $environment, 900));
}
$report = json_decode((string) file_get_contents($artifact . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
expect(hash_file('sha256', $artifact) === $report['sha256'], '原生产物摘要不符');
$packages = array_keys($report['production-packages']);
sort($packages);
expect($packages === ['psr/http-factory', 'psr/http-message', 'psr/http-server-handler', 'psr/http-server-middleware',
    'zoujingli/type-core', 'zoujingli/type-runtime'], '通信消费者必须全量编译安装的六个生产包');
foreach ($report['sources'] as $source) {
    expect(BuildPlatform::contains($work, $source), '消费者引用主仓生产输入');
}
$run = $work . '/run-' . bin2hex(random_bytes(5));
expect(mkdir($run . '/php.d', 0700, true), '无法创建运行目录');
expect(copy($artifact, (new BuildPlatform())->output($run . '/type-app')), '无法复制原产物');
chmod((new BuildPlatform())->output($run . '/type-app'), 0700);
expect(copy($report['runtime-profile']['ini'], $run . '/php.ini'), '无法复制原生运行配置');
$environment['PHPRC'] = $run . '/php.ini';
$environment['PHP_INI_SCAN_DIR'] = $run . '/php.d';
$policy = [];
if (PHP_OS_FAMILY === 'Darwin') {
    $policy = ['sandbox-exec', '-f', __DIR__ . '/fixtures/mqtt-no-source.sb'];
    foreach (['ROOT_APP' => $root . '/app', 'ROOT_PLUGIN' => $root . '/plugin', 'ROOT_EXAMPLE' => $root . '/examples',
        'ROOT_VENDOR' => $root . '/vendor', 'APP' => $work . '/app', 'VENDOR' => $work . '/vendor',
        'COMPILER' => $work . '/build/native/compiler', 'ROOT_COMPOSER' => $root . '/composer.json', 'COMPOSER' => $work . '/composer.json'] as $key => $path) {
        array_push($policy, '-D', $key . '=' . $path);
    }
    $probe = 'foreach (array_slice($argv, 1) as $file) { if (@file_get_contents($file) !== false) throw new RuntimeException("source readable"); } echo "source-denied\n";';
    expect(successful([...$policy, PHP_BINARY, '-n', '-r', $probe, $work . '/app/main.php', $work . '/vendor/autoload.php',
        $root . '/plugin/type-core/src/' . ucfirst($protocol) . 'Socket.php'], $run) === "source-denied\n", '无源码隔离没有生效');
}
$command = [...$policy, (new BuildPlatform())->output($run . '/type-app')];
$peer = null;
$application = null;
$results = [];
try {
    if ($protocol === 'tcp') {
        $certificateConfiguration = $run . '/certificate.cnf';
        file_put_contents($certificateConfiguration, "[req]\ndistinguished_name=dn\nx509_extensions=server\n[dn]\n[server]\nsubjectAltName=DNS:localhost,IP:127.0.0.1,IP:::1\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\n");
        $certificateOptions = ['config' => $certificateConfiguration, 'private_key_bits' => 2048, 'digest_alg' => 'sha256', 'x509_extensions' => 'server'];
        $key = openssl_pkey_new($certificateOptions);
        $request = openssl_csr_new(['commonName' => 'localhost'], $key, $certificateOptions);
        $certificate = openssl_csr_sign($request, null, $key, 1, $certificateOptions);
        expect($certificate !== false && openssl_x509_export($certificate, $pem) && openssl_pkey_export($key, $private), '无法生成独立测试证书');
        file_put_contents($run . '/certificate.pem', $pem);
        file_put_contents($run . '/key.pem', $private);
        chmod($run . '/key.pem', 0600);
    }
    $peer = new Process([(string) (getenv('NODE_BINARY') ?: 'node'), __DIR__ . '/fixtures/' . $protocol . '-peer.mjs', $run . '/peers.json'], $run);
    $deadline = microtime(true) + 5;
    while (!is_file($run . '/peers.json') && microtime(true) < $deadline && $peer->running()) {
        usleep(10000);
    }
    expect(is_file($run . '/peers.json'), '独立通信对端未就绪：' . $peer->stderr());
    foreach (['thread', 'thread', 'coroutine'] as $index => $mode) {
        $observation = $run . '/' . $index . '-' . $mode;
        expect(mkdir($observation, 0700), '无法准备原始证据目录');
        $application = new Process([...$command, $run . '/peers.json', $mode, $observation], $run, $environment);
        $execution = $application->wait(40);
        file_put_contents($observation . '/execution.json', json_encode(['exit' => $execution->exitCode, 'signal' => $execution->signal,
            'stdout' => $execution->stdout, 'stderr' => $execution->stderr, 'timed_out' => $execution->timedOut], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        expect($execution->successful() && $execution->stderr === '', '原生通信验收失败，见 ' . $observation);
        $result = json_decode(trim($execution->stdout), true, 512, JSON_THROW_ON_ERROR);
        expect($result['active_threads'] === 1 && $result['exits'] === ($mode === 'thread' ? [0, 0] : [0]), '线程或退出码不符');
        $workers = [];
        foreach ($mode === 'thread' ? ['left', 'right'] : ['main'] as $role) {
            $worker = json_decode((string) file_get_contents($observation . '/' . $role . '.json'), true, 512, JSON_THROW_ON_ERROR);
            expect($worker['checks'] >= 75 && $worker['coroutines'] === 0 && $worker['process'] === $result['process'], '通信场景不足或协程残留');
            expect(($worker['native_id'] !== $result['main_thread']) === ($mode === 'thread'), '业务线程身份不符');
            $workers[$role] = $worker;
        }
        expect($mode !== 'thread' || $workers['left']['native_id'] !== $workers['right']['native_id'], '两个业务线程身份相同');
        $results[] = ['mode' => $mode, 'result' => $result, 'workers' => $workers];
    }
    $peers = json_decode((string) file_get_contents($run . '/peers.json'), true, 512, JSON_THROW_ON_ERROR);
    $application = new Process([...$command, 'example', 'client', '127.0.0.1', (string) $peers[$protocol === 'udp' ? 'udp4' : 'echo4']['port'], 'example-echo'], $run, $environment);
    $exampleResult = $application->wait(8);
    file_put_contents($run . '/example.json', json_encode(['exit' => $exampleResult->exitCode, 'stdout' => $exampleResult->stdout,
        'stderr' => $exampleResult->stderr], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    expect($exampleResult->successful() && json_decode(trim($exampleResult->stdout), true, 512, JSON_THROW_ON_ERROR)['data'] === 'example-echo', '同一产物的示例客户端失败');
} finally {
    $application?->stop();
    if ($peer !== null) {
        $peer->stop();
        file_put_contents($run . '/peer.json', json_encode(['stdout' => $peer->stdout(), 'stderr' => $peer->stderr()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
    if (is_file($run . '/key.pem')) {
        expect(unlink($run . '/key.pem'), '无法回收独立通信测试私钥');
    }
}
file_put_contents($run . '/verification.json', json_encode(['build-id' => $report['build-id'], 'sha256' => $report['sha256'],
    'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'source_count' => count($report['sources']), 'packages' => $packages,
    'no_source' => PHP_OS_FAMILY === 'Darwin' ? 'kernel-denied' : 'not-verified', 'runs' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo strtoupper($protocol) . ' 六个生产包全量 AOT、双线程重建、主线程协程和独立对端验收通过：' . $run . "\n";
