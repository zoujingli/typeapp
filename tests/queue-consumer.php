<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$consumer = $root . '/build/queue-consumer-' . bin2hex(random_bytes(5));
expect(mkdir($consumer . '/app', 0755, true), '无法准备队列消费项目');
$native = ($argv[1] ?? '') === '--native';
$receipt = ['status' => 'running', 'native' => $native];
try {
    $repositories = [];
    foreach (['type-queue', 'type-redis', 'type-runtime', 'type-core', 'type-build'] as $package) {
        $repositories[] = ['type' => 'path', 'url' => $root . '/plugin/' . $package,
            'options' => ['symlink' => false, 'versions' => ['zoujingli/' . $package => '1.0.x-dev']]];
    }
    $composer = ['name' => 'type-tests/queue-consumer', 'type' => 'project', 'license' => 'Apache-2.0',
        'require' => ['zoujingli/type-queue' => '~1.0.0@dev', 'zoujingli/type-core' => '~1.0.0@dev'],
        'require-dev' => ['zoujingli/type-build' => '~1.0.0@dev', 'swoole/typephp' => testToolchainVersion('typephp'), 'swoole/phpx' => testToolchainVersion('phpx')],
        'repositories' => $repositories, 'minimum-stability' => 'dev', 'prefer-stable' => true, 'config' => ['allow-plugins' => false]];
    file_put_contents($consumer . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    copy($root . '/examples/queue-command.php', $consumer . '/app/main.php');
    copy($root . '/examples/queue/Increment.php', $consumer . '/app/Increment.php');
    copy($root . '/examples/queue/Application.php', $consumer . '/app/Application.php');
    copy($root . '/toolchain.lock.json', $consumer . '/toolchain.lock.json');
    $settings = json_decode(file_get_contents($root . '/docs/build-config/type-queue.json'), true, 512, JSON_THROW_ON_ERROR);
    unset($settings['project-root']);
    $settings['application']['enabled'] = ['type-tests/queue-consumer'];
    $settings['sources'] = ['app'];
    file_put_contents($consumer . '/application.json', json_encode($settings, JSON_THROW_ON_ERROR));
    successful([getenv('COMPOSER_BINARY') ?: 'composer', 'install', '--no-interaction', '--no-scripts', '--no-plugins', '--prefer-dist', '--no-progress'], $consumer);
    $installed = json_decode(file_get_contents($consumer . '/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
    $names = array_column($installed['packages'], 'name');
    expect(!in_array('zoujingli/type-orm', $names, true) && !in_array('zoujingli/type-scheduler', $names, true), '队列应用混入 ORM 或 Scheduler');
    $inspection = json_decode(successful([PHP_BINARY, $consumer . '/vendor/bin/type', 'inspect-application', $consumer . '/application.json', '--json'], $consumer), true, 512, JSON_THROW_ON_ERROR);
    expect(count($inspection['jobs']) === 1 && $inspection['schedules'] === [], '公开检查入口没有报告 Job 装配根');
    if ($native) {
        successful([PHP_BINARY, $consumer . '/vendor/bin/type', $consumer . '/application.json'], $consumer);
        $command = nativeCommand($consumer . '/build/queue/type-app');
        $receipt['build'] = json_decode(file_get_contents($consumer . '/build/queue/type-app.build.json'), true, 512, JSON_THROW_ON_ERROR);
    } else {
        $launcher = 'require "vendor/autoload.php"; (new Type\\Build\\DevelopmentBuilder())->loadConfiguration("application.json"); main($argc,$argv);';
        $command = [PHP_BINARY, '-r', $launcher, '--'];
    }
    expect(str_contains(successful([...$command, '--help'], $consumer), '队列验证'), '独立队列帮助入口失败');
    expect(successful($command, $consumer) === "Streams 任务注册、独立作用域、幂等消费、原子确认与停止通过。\n", '独立队列行为失败');
    $evidence = $consumer . '.evidence';
    expect(mkdir($evidence, 0700), '无法保全独立队列验收身份');
    $preserved = ['composer.json', 'composer.lock', 'toolchain.lock.json', 'application.json', 'app/main.php', 'app/Increment.php', 'app/Application.php'];
    if ($native) {
        $preserved = [...$preserved, 'build/queue/type-app.build.json', 'build/queue/compiler/project.yml', 'build/queue/compiler/assembled-application.php'];
    }
    foreach ($preserved as $source) {
        $destination = $evidence . '/' . basename($source);
        expect(copy($consumer . '/' . $source, $destination)
            && hash_file('sha256', $consumer . '/' . $source) === hash_file('sha256', $destination), '独立队列验收材料保全失败：' . $source);
    }
    $receipt['evidence'] = substr($evidence, strlen($root) + 1);
    $receipt['status'] = 'passed';
    $receipt['composer-lock-sha256'] = hash_file('sha256', $consumer . '/composer.lock');
    $receipt['checks'] = ['independent-install', 'generated-job-factory', 'constructor-scope-and-resource', 'redis-idempotency', 'cleanup-before-ack', 'lease-recovery'];
    echo "队列独立 Composer 安装、任务自动装配与真实 Redis 消费通过。\n";
} finally {
    if ($receipt['status'] !== 'passed') {
        $receipt['status'] = 'failed';
    }
    file_put_contents($consumer . '.verification.json', json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    if ($receipt['status'] === 'passed') {
        removeTestDirectory($consumer);
    } else {
        fwrite(STDERR, '独立队列失败复现保留：' . $consumer . "\n");
    }
}
