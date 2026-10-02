<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\BuildEnvironment;
use Type\Build\BuildPlatform;
use Type\Testing\Process;

// 消费者身份独立于应用候选；静态 SDK 与本机共享 embed 的结果分别记录。
$root = dirname(__DIR__);
$driver = (string) getenv('TYPE_DB_PROBE_DRIVER');
expect(in_array($driver, ['mysql', 'pgsql'], true), '需要明确数据库驱动');
$work = $root . '/build/pdo-progress-' . $driver . '-' . bin2hex(random_bytes(5));
expect(mkdir($work . '/app', 0700, true), '无法创建 PDO 进度消费者');
$composer = ['name' => 'type-tests/pdo-progress', 'type' => 'project', 'license' => 'Apache-2.0',
    'require' => ['zoujingli/type-runtime' => '~1.0.0@dev'],
    'require-dev' => ['zoujingli/type-build' => '~1.0.0@dev', 'swoole/typephp' => testToolchainVersion('typephp'), 'swoole/phpx' => testToolchainVersion('phpx')],
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
$environment['PHPRC'] = php_ini_loaded_file() ?: '';
$environment['PHP_INI_SCAN_DIR'] = (string) (getenv('PHP_INI_SCAN_DIR') ?: '');
$environment['TYPE_STATIC_RUNTIME'] = (string) getenv('TYPE_STATIC_RUNTIME');
expect(mkdir($work . '/composer-home', 0700), '无法创建独立 Composer 配置目录');
$environment['COMPOSER_HOME'] = $work . '/composer-home';
$environment['COMPOSER_CACHE_DIR'] = $root . '/.cache/composer';
$composerBinary = (string) (getenv('TYPE_COMPOSER_PHAR') ?: getenv('COMPOSER_BINARY'));
expect(is_file($composerBinary), '需要明确 Composer');
expect($environment['TYPE_STATIC_RUNTIME'] === '' || is_file($environment['TYPE_STATIC_RUNTIME']), '显式静态 SDK 不存在');
file_put_contents($work . '/install.log', $runner->run([PHP_BINARY, $composerBinary, 'install', '--no-interaction',
    '--no-scripts', '--no-plugins', '--no-progress'], $work, $environment, 300));
file_put_contents($work . '/build.log', $runner->run([PHP_BINARY, $work . '/vendor/bin/type', $work . '/type-app.json'], $work, $environment, 900));
$binary = (new BuildPlatform())->output($work . '/build/native/type-app');
$report = ['driver' => $driver, 'sha256' => hash_file('sha256', $binary), 'scope' => 'pdo-progress-regression',
    'runtime' => $environment['TYPE_STATIC_RUNTIME'] === '' ? 'shared' : 'static', 'runs' => []];
$runtimeEnvironment = getenv();
if ($environment['TYPE_STATIC_RUNTIME'] === '') {
    // 使用编译器实际核验的模块和线程配置，不能拿宿主 PHP 配置代替 embed。
    $runtimeEnvironment['PHPRC'] = $work . '/build/native/compiler/runtime-profile/native.ini';
    $runtimeEnvironment['PHP_INI_SCAN_DIR'] = $work . '/build/native/compiler/runtime-profile/php.d';
}
foreach (['main', 'thread', 'preflight-thread'] as $mode) {
    $process = new Process([$binary, $mode], $work, $runtimeEnvironment);
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
echo "PDO 主线程、业务线程及启动检查后的等待进度通过。\n";
