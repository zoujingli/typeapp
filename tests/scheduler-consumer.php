<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$native = ($argv[1] ?? '') === '--native';
$consumer = $root . '/build/scheduler-consumer-' . bin2hex(random_bytes(6));
expect(mkdir($consumer . '/app', 0755, true), '无法建立独立调度消费项目');
$report = ['status' => 'running', 'native' => $native, 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m')];
try {
    $repositories = [];
    foreach (['type-runtime', 'type-redis', 'type-scheduler', 'type-core', 'type-build'] as $package) {
        $repositories[] = ['type' => 'path', 'url' => $root . '/plugin/' . $package,
            'options' => ['symlink' => false, 'versions' => ['zoujingli/' . $package => '1.0.x-dev']]];
    }
    $composer = ['name' => 'type-tests/scheduler-consumer', 'type' => 'project', 'license' => 'Apache-2.0',
        'require' => ['zoujingli/type-scheduler' => '~1.0.0@dev', 'zoujingli/type-core' => '~1.0.0@dev'],
        'require-dev' => ['zoujingli/type-build' => '~1.0.0@dev', 'swoole/typephp' => testToolchainVersion('typephp'), 'swoole/phpx' => testToolchainVersion('phpx')],
        'repositories' => $repositories, 'minimum-stability' => 'dev', 'prefer-stable' => true, 'config' => ['allow-plugins' => false]];
    file_put_contents($consumer . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    expect(copy($root . '/examples/scheduler-command.php', $consumer . '/app/main.php'), '无法复制调度命令');
    expect(copy($root . '/examples/scheduler/Tasks.php', $consumer . '/app/Tasks.php'), '无法复制编译注册任务');
    expect(copy($root . '/examples/scheduler/Application.php', $consumer . '/app/Application.php'), '无法复制调度启动入口');
    expect(copy($root . '/toolchain.lock.json', $consumer . '/toolchain.lock.json'), '无法复制工具链约束');
    $settings = json_decode(file_get_contents($root . '/docs/build-config/type-scheduler.json'), true, 512, JSON_THROW_ON_ERROR);
    unset($settings['project-root']);
    $settings['application']['enabled'] = ['type-tests/scheduler-consumer'];
    $settings['sources'] = ['app'];
    $settings['output'] = 'build/type-app';
    $settings['build-directory'] = 'build/compiler';
    $settings['runtime'] = ['Linux' => ['extensions' => ['posix']], 'Darwin' => ['extensions' => ['posix']]];
    file_put_contents($consumer . '/application.json', json_encode($settings, JSON_THROW_ON_ERROR));
    file_put_contents($consumer . '/run.php', <<<'LAUNCHER'
<?php
require __DIR__ . '/vendor/autoload.php';
(new Type\Build\DevelopmentBuilder())->loadConfiguration(__DIR__ . '/application.json');
main($argc, $argv);
exit((int) ($GLOBALS['type_app_exit_status'] ?? 0));
LAUNCHER);
    successful([getenv('COMPOSER_BINARY') ?: 'composer', 'install', '--no-interaction', '--no-scripts', '--no-plugins', '--prefer-dist', '--no-progress'], $consumer);
    $installed = json_decode(file_get_contents($consumer . '/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
    $names = array_column($installed['packages'], 'name');
    expect(in_array('dragonmantank/cron-expression', $names, true) && in_array('psr/clock', $names, true), '调度没有安装锁定 Cron 库与 PSR 时钟');
    expect(!in_array('zoujingli/type-orm', $names, true) && !in_array('zoujingli/type-queue', $names, true), '调度应用混入 ORM 或 queue');
    $inspection = json_decode(successful([PHP_BINARY, $consumer . '/vendor/bin/type', 'inspect-application', $consumer . '/application.json', '--json'], $consumer), true, 512, JSON_THROW_ON_ERROR);
    expect(count($inspection['schedules']) === 2 && $inspection['jobs'] === [], '公开检查入口没有报告调度装配根');
    echo successful([PHP_BINARY, $root . '/tests/scheduler.php', $consumer . '/vendor/autoload.php']);
    echo successful([PHP_BINARY, $root . '/tests/scheduler-command.php', $consumer . '/run.php']);
    if ($native) {
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
        $report['artifact'] = $build;
    }
    $evidence = $consumer . '.evidence';
    expect(mkdir($evidence, 0700), '无法保全独立调度验收身份');
    $preserved = ['composer.json', 'composer.lock', 'toolchain.lock.json', 'application.json', 'app/main.php', 'app/Tasks.php', 'app/Application.php'];
    if ($native) {
        $preserved = [...$preserved, 'build/type-app.build.json', 'build/compiler/project.yml', 'build/compiler/assembled-application.php'];
    }
    foreach ($preserved as $source) {
        $destination = $evidence . '/' . basename($source);
        expect(copy($consumer . '/' . $source, $destination)
            && hash_file('sha256', $consumer . '/' . $source) === hash_file('sha256', $destination), '独立调度验收材料保全失败：' . $source);
    }
    $report['evidence'] = substr($evidence, strlen($root) + 1);
    $report['status'] = 'passed';
    $report['composer-lock-sha256'] = hash_file('sha256', $consumer . '/composer.lock');
    $report['checks'] = ['independent-install', 'cron-and-clock', 'no-orm-queue', 'php-scheduler', 'generated-task-factory', 'constructor-scope', 'php-command-and-sigkill-recovery'];
    if ($native) {
        $report['checks'][] = 'native-command-and-sigkill-recovery';
    }
    echo '独立 Composer 调度自动装配验证通过：' . $consumer . ".verification.json\n";
} finally {
    if ($report['status'] !== 'passed') {
        $report['status'] = 'failed';
    }
    file_put_contents($consumer . '.verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    if ($report['status'] === 'passed') {
        removeTestDirectory($consumer);
    } else {
        fwrite(STDERR, '独立调度失败复现保留：' . $consumer . "\n");
    }
}
