<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$native = ($argv[1] ?? '') === '--native';
$consumer = $root . '/build/scheduler-consumer-' . bin2hex(random_bytes(6));
expect(mkdir($consumer . '/app', 0755, true), '无法建立独立调度消费项目');
$repositories = [];
foreach (['type-runtime', 'type-redis', 'type-scheduler', 'type-build'] as $package) {
    $repositories[] = ['type' => 'path', 'url' => $root . '/plugin/' . $package,
        'options' => ['symlink' => false, 'versions' => ['zoujingli/' . $package => '1.0.x-dev']]];
}
$composer = ['name' => 'type-tests/scheduler-consumer', 'type' => 'project', 'license' => 'Apache-2.0',
    'require' => ['zoujingli/type-scheduler' => '~1.0.0@dev'], 'repositories' => $repositories,
    'minimum-stability' => 'dev', 'prefer-stable' => true, 'config' => ['allow-plugins' => false]];
if ($native) {
    $composer['require-dev'] = ['zoujingli/type-build' => '~1.0.0@dev', 'swoole/typephp' => '0.9.0', 'swoole/phpx' => '2.9.0'];
}
file_put_contents($consumer . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
expect(copy($root . '/examples/scheduler-command.php', $consumer . '/app/main.php'), '无法复制调度命令');
expect(copy($root . '/examples/scheduler/Tasks.php', $consumer . '/app/Tasks.php'), '无法复制编译注册任务');
file_put_contents($consumer . '/run.php', <<<'PHP'
<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/app/Tasks.php';
require __DIR__ . '/app/main.php';
main($argc, $argv);
exit((int) ($GLOBALS['type_app_exit_status'] ?? 0));
PHP);
successful([getenv('COMPOSER_BINARY') ?: 'composer', 'install', '--no-interaction', '--no-scripts', '--no-plugins', '--prefer-dist', '--no-progress'], $consumer);
$installed = json_decode(file_get_contents($consumer . '/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
$names = array_column($installed['packages'], 'name');
expect(in_array('dragonmantank/cron-expression', $names, true) && in_array('psr/clock', $names, true), '调度没有安装锁定 Cron 库与 PSR 时钟');
expect(!in_array('zoujingli/type-core', $names, true) && !in_array('zoujingli/type-orm', $names, true) && !in_array('zoujingli/type-queue', $names, true), '独立调度混入 core、ORM 或 queue');
echo successful([PHP_BINARY, $root . '/tests/scheduler.php', $consumer . '/vendor/autoload.php']);
echo successful([PHP_BINARY, $root . '/tests/scheduler-command.php', $consumer . '/run.php']);
if ($native) {
    expect(copy($root . '/toolchain.lock.json', $consumer . '/toolchain.lock.json'), '无法复制工具链约束');
    $settings = ['name' => 'scheduler-consumer', 'entry' => 'app/main.php', 'sources' => ['app/Tasks.php'],
        'output' => 'build/type-app', 'build-directory' => 'build/compiler',
        'runtime' => ['Linux' => ['extensions' => ['posix']], 'Darwin' => ['extensions' => ['posix']]]];
    file_put_contents($consumer . '/application.json', json_encode($settings, JSON_THROW_ON_ERROR));
    successful([PHP_BINARY, $consumer . '/vendor/bin/type', $consumer . '/application.json'], $consumer);
    $build = json_decode(file_get_contents($consumer . '/build/type-app.build.json'), true, 512, JSON_THROW_ON_ERROR);
    expect(hash_file('sha256', $consumer . '/build/type-app') === $build['sha256'], '独立调度产物与构建记录不符');
    $previousIni = getenv('TYPE_NATIVE_PHP_INI');
    putenv('TYPE_NATIVE_PHP_INI=' . $build['runtime-profile']['ini']);
    try {
        echo successful([PHP_BINARY, $root . '/tests/scheduler-command.php', $consumer . '/build/type-app', '--native']);
    } finally {
        putenv($previousIni === false ? 'TYPE_NATIVE_PHP_INI' : 'TYPE_NATIVE_PHP_INI=' . $previousIni);
    }
}
$report = ['status' => 'passed', 'native' => $native, 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'),
    'composer-lock-sha256' => hash_file('sha256', $consumer . '/composer.lock'),
    'checks' => ['independent-install', 'cron-and-clock', 'no-core-orm-queue', 'php-scheduler', 'php-command']];
if ($native) {
    $report['checks'][] = 'native-command-and-sigkill-recovery';
    $report['artifact'] = ['sha256' => $build['sha256'], 'build-id' => $build['build-id'],
        'build-report-sha256' => hash_file('sha256', $consumer . '/build/type-app.build.json'), 'source-count' => count($build['sources']),
        'production-packages' => $build['production-packages']];
}
file_put_contents($consumer . '/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo '独立 Composer 调度消费验证通过：' . $consumer . "/verification.json\n";
