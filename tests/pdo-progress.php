<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\BuildEnvironment;
use Type\Build\BuildPlatform;
use Type\Testing\Process;

// 诊断范围明确独立于应用候选；复用真实静态 SDK，生成新的消费者身份。
$root = dirname(__DIR__);
$driver = (string) getenv('TYPE_DB_PROBE_DRIVER');
expect(in_array($driver, ['mysql', 'pgsql'], true), '需要明确数据库驱动');
$work = $root . '/build/pdo-progress-' . $driver . '-' . bin2hex(random_bytes(5));
expect(mkdir($work . '/app', 0700, true), '无法创建 PDO 进度消费者');
$composer = ['name' => 'type-tests/pdo-progress', 'type' => 'project', 'license' => 'Apache-2.0',
    'require' => ['zoujingli/type-runtime' => '~1.0.0@dev'],
    'require-dev' => ['zoujingli/type-build' => '~1.0.0@dev', 'swoole/typephp' => '0.9.3', 'swoole/phpx' => '2.9.2'],
    'repositories' => [], 'autoload' => ['classmap' => ['app']], 'minimum-stability' => 'dev', 'prefer-stable' => true,
    'config' => ['allow-plugins' => false]];
foreach (['type-runtime', 'type-build'] as $package) {
    $composer['repositories'][] = ['type' => 'path', 'url' => '../../plugin/' . $package,
        'options' => ['symlink' => false, 'versions' => ['zoujingli/' . $package => '1.0.x-dev']]];
}
$application = json_decode(file_get_contents($root . '/docs/build-config/type-app.json'), true, 64, JSON_THROW_ON_ERROR);
$settings = ['name' => 'pdo-progress', 'entry' => 'app/main.php', 'sources' => ['app'],
    'threads' => ['pdo' => 'PdoProgressProbe::run'], 'build-profile' => $driver, 'build-profiles' => $application['build-profiles'],
    'output' => (new BuildPlatform())->output('build/native/type-app'), 'build-directory' => 'build/native/compiler',
    'runtime' => [PHP_OS_FAMILY => ['extensions' => ['swoole', 'pdo', 'pdo_' . $driver]]], 'compiler' => ['jobs' => 2]];
foreach (['composer.json' => $composer, 'type-app.json' => $settings] as $name => $value) {
    file_put_contents($work . '/' . $name, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
expect(copy($root . '/toolchain.lock.json', $work . '/toolchain.lock.json')
    && copy(__DIR__ . '/fixtures/compiled-pdo-progress.php', $work . '/app/main.php'), '无法复制锁与完整消费者');
$runner = new BuildEnvironment();
$environment = $runner->environment((string) getenv('PHP_HOME'), (string) getenv('PHPX_HOME'));
$environment['TYPE_STATIC_RUNTIME'] = (string) getenv('TYPE_STATIC_RUNTIME');
$composerBinary = (string) (getenv('TYPE_COMPOSER_PHAR') ?: getenv('COMPOSER_BINARY'));
expect(is_file($composerBinary) && is_file($environment['TYPE_STATIC_RUNTIME']), '需要明确 Composer 和静态 SDK');
file_put_contents($work . '/install.log', $runner->run([PHP_BINARY, $composerBinary, 'install', '--no-interaction',
    '--no-scripts', '--no-plugins', '--no-progress'], $work, $environment, 300));
file_put_contents($work . '/build.log', $runner->run([PHP_BINARY, $work . '/vendor/bin/type', $work . '/type-app.json'], $work, $environment, 900));
$binary = (new BuildPlatform())->output($work . '/build/native/type-app');
$report = ['driver' => $driver, 'sha256' => hash_file('sha256', $binary), 'scope' => 'pdo-progress-diagnostic', 'runs' => []];
foreach (['main', 'thread'] as $mode) {
    $process = new Process([$binary, $mode], $work);
    try {
        $result = $process->wait(20);
        file_put_contents($work . '/' . $mode . '.log', $result->stdout . $result->stderr);
        expect($result->successful() && $result->stderr === '', 'PDO 消费者运行失败：' . $mode);
        $report['runs'][$mode] = json_decode(trim($result->stdout), true, 32, JSON_THROW_ON_ERROR);
    } finally {
        $process->stop();
        file_put_contents($work . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    }
}
foreach ($report['runs'] as $mode => $run) {
    expect($run['pulses'] >= 3, '数据库等待没有推进同线程事件循环：' . $mode);
}
echo "PDO 主线程与业务线程等待进度通过。\n";
