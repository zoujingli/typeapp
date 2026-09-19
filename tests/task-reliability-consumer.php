<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
$root = dirname(__DIR__);
$native = ($argv[1] ?? '') === '--native';
$consumer = $root . '/build/reliability-consumer-' . bin2hex(random_bytes(6));
expect(mkdir($consumer . '/app', 0755, true), '无法建立任务可靠性消费项目');
$repositories = [];
foreach (['type-runtime', 'type-redis', 'type-scheduler', 'type-queue', 'type-log', 'type-build'] as $package) {
    $repositories[] = ['type' => 'path', 'url' => '../../plugin/' . $package,
        'options' => ['symlink' => false, 'versions' => ['zoujingli/' . $package => '1.0.x-dev']]];
}
$composer = ['name' => 'type-tests/reliability-consumer', 'type' => 'project', 'license' => 'Apache-2.0',
    'require' => ['zoujingli/type-scheduler' => '~1.0.0@dev', 'zoujingli/type-queue' => '~1.0.0@dev', 'zoujingli/type-log' => '~1.0.0@dev'],
    'repositories' => $repositories, 'minimum-stability' => 'dev', 'prefer-stable' => true, 'config' => ['allow-plugins' => false]];
if ($native) {
    $composer['require-dev'] = ['zoujingli/type-build' => '~1.0.0@dev', 'swoole/typephp' => '0.9.0', 'swoole/phpx' => '2.9.0'];
}
file_put_contents($consumer . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
foreach (['examples/task-reliability-command.php' => 'main.php', 'examples/reliability/Tasks.php' => 'Tasks.php', 'examples/scheduler/Tasks.php' => 'Clock.php'] as $from => $to) {
    expect(copy($root . '/' . $from, $consumer . '/app/' . $to), '无法复制可靠存储验证入口');
}
file_put_contents($consumer . '/run.php', <<<'PHP'
<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/app/Tasks.php';
require __DIR__ . '/app/Clock.php';
require __DIR__ . '/app/main.php';
main($argc, $argv);
PHP);
successful([getenv('COMPOSER_BINARY') ?: 'composer', 'install', '--no-interaction', '--no-scripts', '--no-plugins', '--prefer-dist', '--no-progress'], $consumer);
if ($native) {
    expect(copy($root . '/toolchain.lock.json', $consumer . '/toolchain.lock.json'), '无法复制工具链约束');
    $settings = ['name' => 'reliability-consumer', 'entry' => 'app/main.php', 'sources' => ['app/Tasks.php', 'app/Clock.php'],
        'output' => 'build/type-app', 'build-directory' => 'build/compiler'];
    file_put_contents($consumer . '/application.json', json_encode($settings, JSON_THROW_ON_ERROR));
    successful([PHP_BINARY, $consumer . '/vendor/bin/type', $consumer . '/application.json'], $consumer);
}
echo $consumer . PHP_EOL;
