<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

function integrationExecution(array $command, string $consumer): array
{
    $process = proc_open([...$command, '--report=' . $consumer . '/verification.json'], [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $consumer);
    expect(is_resource($process) && proc_close($process) === 0, '完整业务集成失败');
    return json_decode(file_get_contents($consumer . '/verification.json'), true, 512, JSON_THROW_ON_ERROR);
}

$root = dirname(__DIR__);
$driver = $argv[1] ?? 'sqlite';
$native = in_array('--native', $argv, true);
$remote = in_array('--remote', $argv, true);
$isolated = in_array('--isolated', $argv, true);
expect(!$isolated || $native, '无源码部署验收必须选择真实原生产物');
expect(in_array($driver, ['mysql', 'pgsql', 'sqlite'], true), '完整集成驱动无效');
$consumer = $root . '/build/integration-consumer-' . $driver . '-' . bin2hex(random_bytes(6));
mkdir($consumer, 0700, true);
$mapping = json_decode(file_get_contents($root . '/.github/distribution.json'), true, 512, JSON_THROW_ON_ERROR);
$batch = $remote ? json_decode(file_get_contents($root . '/build/distribution/batch-result.json'), true, 512, JSON_THROW_ON_ERROR) : null;
if ($remote) {
    expect($batch['complete'] === true, '完整消费必须使用已完成的真实批次');
}
$composer = ['name' => 'type-tests/full-integration', 'type' => 'project', 'license' => 'Apache-2.0', 'require' => [], 'require-dev' => [], 'repositories' => [],
    'autoload' => ['classmap' => ['examples/integration', 'examples/model/Drivers.php', 'examples/orm-suite/Schema.php', 'examples/outbox/Adapters.php', 'examples/coordination/QueueDispatchTask.php']],
    'minimum-stability' => 'dev', 'prefer-stable' => true, 'config' => ['allow-plugins' => false]];
foreach ($mapping['packages'] as $name => $package) {
    $composer[in_array($name, ['type-build', 'type-testing'], true) ? 'require-dev' : 'require'][$package['composer-name']] = $remote ? 'dev-main#' . $batch['items'][$name]['split'] : '~1.0.0@dev';
    $composer['repositories'][] = $remote ? ['type' => 'git', 'url' => 'https://github.com/' . $package['repository'] . '.git']
        : ['type' => 'path', 'url' => $root . '/' . $package['prefix'], 'options' => ['symlink' => false, 'versions' => [$package['composer-name'] => '1.0.x-dev']]];
}
$composer['require-dev']['swoole/typephp'] = '0.9.0';
$composer['require-dev']['swoole/phpx'] = '2.9.0';
$sources = ['examples/integration', 'examples/integration-command.php', 'examples/model/Drivers.php', 'examples/orm-suite/Schema.php', 'examples/orm-suite/Models.php', 'examples/outbox/Adapters.php', 'examples/coordination/QueueDispatchTask.php'];
foreach ($sources as $source) {
    $entries = is_file($root . '/' . $source) ? [$root . '/' . $source] : new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $source, FilesystemIterator::SKIP_DOTS));
    foreach ($entries as $entry) {
        $file = is_string($entry) ? $entry : $entry->getPathname();
        $relative = substr($file, strlen($root) + 1);
        if (!is_dir(dirname($consumer . '/' . $relative))) {
            mkdir(dirname($consumer . '/' . $relative), 0700, true);
        }
        copy($file, $consumer . '/' . $relative);
    }
}
file_put_contents($consumer . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
copy($root . '/toolchain.lock.json', $consumer . '/toolchain.lock.json');
$configuration = json_decode(file_get_contents($root . '/docs/build-config/type-integration.json'), true, 512, JSON_THROW_ON_ERROR);
// 独立消费配置位于新项目根目录，不能继承主仓文档目录的相对根。
unset($configuration['project-root']);
file_put_contents($consumer . '/type-app.json', json_encode($configuration, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
successful([getenv('COMPOSER_BINARY') ?: 'composer', 'install', '--no-scripts', '--no-plugins', '--no-interaction', '--prefer-dist', '--no-progress'], $consumer);
$lock = json_decode(file_get_contents($consumer . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
$packages = [];
foreach (array_merge($lock['packages'], $lock['packages-dev']) as $package) {
    if (!str_starts_with($package['name'], 'zoujingli/type-')) {
        continue;
    }
    $name = substr($package['name'], 10);
    $packages[$name] = $package['version'];
    if ($remote) {
        expect($package['source']['reference'] === $batch['items'][$name]['split'], '完整应用消费了批次外的插件提交');
    }
    expect(!is_link($consumer . '/vendor/' . $package['name']), '完整消费不能借用主仓符号链接');
}
expect(count($packages) === count($mapping['packages']), '完整集成必须独立安装分发清单内全部组件');
mkdir($consumer . '/tests', 0700);
copy($root . '/tests/integration-run.php', $consumer . '/tests/run.php');
copy($root . '/tests/support.php', $consumer . '/tests/support.php');
$build = null;
if ($native) {
    $started = hrtime(true);
    successful([PHP_BINARY, $consumer . '/vendor/bin/type', $consumer . '/type-app.json'], $consumer);
    $elapsed = (hrtime(true) - $started) / 1000000000;
    $buildReport = json_decode(file_get_contents($consumer . '/build/integration/type-app.build.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ($buildReport['sources'] as $source) {
        expect(str_starts_with($source, $consumer . '/'), '独立原生消费借用了项目外源码');
    }
    foreach ($composer['require'] as $name => $constraint) {
        expect(isset($buildReport['production-packages'][$name]), '生产插件未纳入完整编译：' . $name);
    }
    $build = ['elapsed_seconds' => $elapsed, 'bytes' => filesize($consumer . '/build/integration/type-app'), 'sha256' => $buildReport['sha256'],
        'build_id' => $buildReport['build-id'], 'source_count' => count($buildReport['sources']), 'production_packages' => $buildReport['production-packages']];
}
$execution = [PHP_BINARY, $consumer . '/tests/run.php', $driver, $native ? '--native' : '--php'];
$performance = null;
if ($isolated) {
    // chroot 不提供 /proc；同一 ELF 先在可测量的原生环境取样，不能把缺失 RSS 写成零或伪造挂载。
    $baseline = integrationExecution($execution, $consumer);
    expect($baseline['native'] === true && $baseline['deployment'] === 'development-runtime', '性能基线必须来自同一个实际原生产物');
    $performance = ['deployment' => $baseline['deployment'], 'artifact-sha256' => $build['sha256'],
        'measurements' => $baseline['measurements'], 'queue' => $baseline['queue'], 'worker' => $baseline['worker']];
    $sandbox = trim(successful(['bash', $root . '/tools/make-native-sandbox.sh', $consumer . '/build/integration/type-app'], $root));
    expect(str_starts_with($sandbox, $root . '/build/native-sandbox.') && is_dir($sandbox), '没有得到当前应用的隔离部署目录');
    array_push($execution, '--chroot', $sandbox);
}
$report = integrationExecution($execution, $consumer);
$report['remote'] = $remote;
$report['batch'] = $batch['id'] ?? null;
$report['installed-packages'] = $packages;
$report['build'] = $build;
if ($performance !== null) {
    $report['performance-baseline'] = $performance;
}
file_put_contents($consumer . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo '完整集成应用独立消费通过：' . $consumer . "\n";
