<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$native = ($argv[1] ?? '') === '--native';
$consumer = $root . '/build/coordination-consumer-' . bin2hex(random_bytes(6));
expect(mkdir($consumer . '/app', 0755, true), '无法创建调度队列组合项目');
$repositories = [];
foreach (['type-runtime', 'type-redis', 'type-scheduler', 'type-queue', 'type-build'] as $package) {
    $repositories[] = ['type' => 'path', 'url' => $root . '/plugin/' . $package,
        'options' => ['symlink' => false, 'versions' => ['zoujingli/' . $package => '1.0.x-dev']]];
}
$composer = ['name' => 'type-tests/coordination-consumer', 'type' => 'project', 'license' => 'Apache-2.0',
    'require' => ['zoujingli/type-scheduler' => '~1.0.0@dev', 'zoujingli/type-queue' => '~1.0.0@dev'],
    'repositories' => $repositories, 'minimum-stability' => 'dev', 'prefer-stable' => true, 'config' => ['allow-plugins' => false]];
if ($native) {
    $composer['require-dev'] = ['zoujingli/type-build' => '~1.0.0@dev', 'swoole/typephp' => '0.9.3', 'swoole/phpx' => '2.9.2'];
}
file_put_contents($consumer . '/composer.json', json_encode($composer, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
foreach (['examples/scheduler-coordination-command.php' => 'main.php', 'examples/scheduler/Tasks.php' => 'Clock.php',
    'examples/coordination/QueueDispatchTask.php' => 'QueueDispatchTask.php', 'examples/coordination/ReportTask.php' => 'ReportTask.php',
    'examples/queue/Increment.php' => 'Increment.php'] as $from => $to) {
    expect(copy($root . '/' . $from, $consumer . '/app/' . $to), '无法复制调度组合示例');
}
file_put_contents($consumer . '/run.php', <<<'PHP'
<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/app/Clock.php';
require __DIR__ . '/app/QueueDispatchTask.php';
require __DIR__ . '/app/ReportTask.php';
require __DIR__ . '/app/main.php';
main($argc, $argv);
exit((int) ($GLOBALS['type_app_exit_status'] ?? 0));
PHP);
successful([getenv('COMPOSER_BINARY') ?: 'composer', 'install', '--no-interaction', '--no-scripts', '--no-plugins', '--prefer-dist', '--no-progress'], $consumer);
echo successful([PHP_BINARY, $root . '/tests/scheduler-coordination.php', $consumer . '/vendor/autoload.php']);
echo successful([PHP_BINARY, $root . '/tests/scheduler.php', $consumer . '/vendor/autoload.php']);
echo successful([PHP_BINARY, '-r', 'require ' . var_export($consumer . '/vendor/autoload.php', true) . '; require '
    . var_export($root . '/examples/queue-lease-command.php', true) . '; main($argc, $argv);']);
echo successful([PHP_BINARY, $root . '/tests/scheduler-coordination-process.php', $consumer . '/vendor/autoload.php', $consumer . '/run.php']);
if ($native) {
    expect(copy($root . '/toolchain.lock.json', $consumer . '/toolchain.lock.json'), '无法复制工具链约束');
    $settings = ['name' => 'coordination-consumer', 'entry' => 'app/main.php', 'sources' => ['app/Clock.php', 'app/QueueDispatchTask.php', 'app/ReportTask.php'],
        'output' => 'build/type-app', 'build-directory' => 'build/compiler'];
    file_put_contents($consumer . '/application.json', json_encode($settings, JSON_THROW_ON_ERROR));
    successful([PHP_BINARY, $consumer . '/vendor/bin/type', $consumer . '/application.json'], $consumer);
    $build = json_decode(file_get_contents($consumer . '/build/type-app.build.json'), true, 512, JSON_THROW_ON_ERROR);
    expect(hash_file('sha256', $consumer . '/build/type-app') === $build['sha256'], '调度组合产物与构建记录不符');
    $previousIni = getenv('TYPE_NATIVE_PHP_INI');
    putenv('TYPE_NATIVE_PHP_INI=' . $build['runtime-profile']['ini']);
    try {
        echo successful([PHP_BINARY, $root . '/tests/scheduler-coordination-process.php', $consumer . '/vendor/autoload.php', $consumer . '/build/type-app', '--native']);
    } finally {
        putenv($previousIni === false ? 'TYPE_NATIVE_PHP_INI' : 'TYPE_NATIVE_PHP_INI=' . $previousIni);
    }
}
$report = ['status' => 'passed', 'native' => $native, 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'),
    'composer-lock-sha256' => hash_file('sha256', $consumer . '/composer.lock'),
    'checks' => ['independent-install', 'php-coordination', 'php-scheduler', 'php-queue-lease', 'php-multiprocess']];
if ($native) {
    $report['checks'][] = 'native-multiprocess-sigstop-takeover-and-fencing';
    $report['artifact'] = ['sha256' => $build['sha256'], 'build-id' => $build['build-id'],
        'build-report-sha256' => hash_file('sha256', $consumer . '/build/type-app.build.json'), 'source-count' => count($build['sources']),
        'production-packages' => $build['production-packages']];
}
file_put_contents($consumer . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo '调度与队列显式组合安装验证通过：' . $consumer . "/verification.json\n";
