<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/native-database.php';

$root = dirname(__DIR__);
$base = $root . '/build/iot-ingestion-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700, true), '无法创建隔离接收测试根');
$requested = $argv[1] ?? 'sqlite';
$native = in_array('--native', $argv, true);
expect(in_array($requested, ['sqlite', 'mysql', 'pgsql', 'all'], true) && array_diff(array_slice($argv, 2), ['--native']) === [], '用法：php tests/iot-ingestion.php [sqlite|mysql|pgsql|all] [--native]');
$fixture = $root . '/tests/fixtures/iot-ingestion-cases.php';
expect(copy($fixture, $base . '/fixture.php'), '无法保全本轮接收输入');
$command = [PHP_BINARY, '-r', 'require $argv[1]; require $argv[2]; main(2, [$argv[2], $argv[3]]);', $root . '/vendor/autoload.php', $base . '/fixture.php'];
$report = ['status' => 'running', 'execution' => $native ? 'native-public-services' : 'php-public-services',
    'fixture-sha256' => hash_file('sha256', $fixture), 'drivers' => [], 'synchronous-durability' => '由完整MQTT同步接收验收证明，本脚本不声明'];
if ($native) {
    $relative = substr($base, strlen($root) + 1);
    // 复用独立消费装置：完整业务应用和全部生产依赖实际安装，不排除主应用自动加载声明以绕过编译清单。
    expect(mkdir($base . '/app', 0700, true), '无法建立独立接收应用');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $source) {
        $target = $base . '/app' . substr($source->getPathname(), strlen($root . '/app'));
        expect($source->isDir() ? mkdir($target, 0700) : copy($source->getPathname(), $target), '无法复制完整业务应用');
    }
    copy($fixture, $base . '/app/main.php');
    $rootComposer = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $toolchain = json_decode(file_get_contents($root . '/toolchain.lock.json'), true, 512, JSON_THROW_ON_ERROR);
    $repositories = $rootComposer['repositories'];
    $repositories[0]['url'] = '../../plugin/*';
    $repositories[0]['options']['symlink'] = false;
    $composer = ['name' => 'type-tests/iot-ingestion', 'type' => 'project', 'license' => 'Apache-2.0', 'require' => $rootComposer['require'],
        'require-dev' => ['zoujingli/type-build' => '~1.0.0@dev', 'swoole/typephp' => $toolchain['typephp']['version'], 'swoole/phpx' => $toolchain['phpx']['version']],
        'autoload' => ['psr-4' => ['app\\' => 'app/'], 'classmap' => ['app/main.php']], 'repositories' => $repositories,
        'minimum-stability' => 'dev', 'prefer-stable' => true, 'config' => ['allow-plugins' => false]];
    file_put_contents($base . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    copy($root . '/toolchain.lock.json', $base . '/toolchain.lock.json');
    successful([getenv('COMPOSER_BINARY') ?: 'composer', 'install', '--no-interaction', '--no-plugins', '--no-scripts', '--no-progress'], $base);
    $configuration = ['name' => 'iot-ingestion-verification', 'entry' => 'app/main.php', 'sources' => ['app'],
        'output' => 'build/native/type-app', 'build-directory' => 'build/native/compiler'];
    file_put_contents($base . '/type-app.json', json_encode($configuration, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents($base . '/build.log', successful([PHP_BINARY, $base . '/vendor/bin/type', $base . '/type-app.json'], $base));
    (new Type\Build\NativePackage())->create($base . '/build/native/type-app', $base . '/runtime');
    $command = [$base . '/runtime/run'];
    $report['artifact-sha256'] = hash_file('sha256', $base . '/build/native/type-app');
    $report['build-report'] = $relative . '/build/native/type-app.build.json';
    $report['no-source'] = 'not-verified';
    if (PHP_OS_FAMILY === 'Darwin') {
        $policy = file_get_contents($root . '/tests/fixtures/iot-no-source.sb') . "\n(deny file-read-data (literal (param \"FIXTURE\")) (subpath (param \"TEST_APP\")) (subpath (param \"TEST_VENDOR\")))\n";
        file_put_contents($base . '/no-source.sb', $policy);
        $sandbox = ['sandbox-exec', '-f', $base . '/no-source.sb'];
        foreach (['APP' => $root . '/app', 'PLUGIN' => $root . '/plugin', 'VENDOR' => $root . '/vendor',
            'CONFIG' => $root . '/config', 'COMPILER' => $base . '/build/native/compiler', 'COMPOSER' => $root . '/composer.json', 'FIXTURE' => $fixture,
            'TEST_APP' => $base . '/app', 'TEST_VENDOR' => $base . '/vendor'] as $label => $path) {
            array_push($sandbox, '-D', $label . '=' . $path);
        }
        $probe = 'foreach (array_slice($argv, 1) as $file) { if (@file_get_contents($file) !== false) { throw new RuntimeException("source-readable"); } } echo "denied\n";';
        expect(successful([...$sandbox, PHP_BINARY, '-n', '-r', $probe, $root . '/app/iot/service/IngestionService.php', $root . '/vendor/autoload.php', $fixture,
            $base . '/app/main.php', $base . '/vendor/autoload.php'], $root) === "denied\n", '禁读接收源码装置没有生效');
        $command = [...$sandbox, ...$command];
        $report['no-source'] = 'kernel-denied-production-and-generated-source';
    }
}
try {
    foreach ($requested === 'all' ? ['sqlite', 'mysql', 'pgsql'] : [$requested] as $name) {
        $tools = $name === 'sqlite' ? [] : NativeDatabase::tools($name, (string) getenv('TYPE_' . strtoupper($name) . '_TOOLS'));
        $server = new NativeDatabase($base . '/' . $name, $name, $tools);
        try {
            $environment = array_replace(getenv(), $server->environment(), ['INGESTION_SQLITE' => $base . '/sqlite/data.sqlite', 'INGESTION_STATUS_CACHE' => $base . '/' . $name . '/status.sqlite']);
            $secrets = $name === 'sqlite' ? [] : [$environment['TYPE_' . strtoupper($name) . '_PASSWORD']];
            $result = nativeDatabaseCommand([...$command, $name], $environment, $secrets, $base . '/' . $name . '.log', 90);
            $report['drivers'][$name] = json_decode(trim($result), true, 32, JSON_THROW_ON_ERROR);
        } finally {
            $server->close();
            $report['drivers'][$name]['database'] = $server->evidence();
        }
        echo $name . "接收公开服务与资源退出通过。\n";
    }
    $report['status'] = 'passed';
} finally {
    if ($report['status'] !== 'passed') {
        $report['status'] = 'failed';
    }
    file_put_contents($base . '/verification.json', json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}
echo '接收验证证据：' . $base . "/verification.json\n";
