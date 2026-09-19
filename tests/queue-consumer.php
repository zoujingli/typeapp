<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$consumer = $root . '/build/queue-consumer-' . bin2hex(random_bytes(5));
expect(mkdir($consumer . '/app', 0755, true), '无法准备队列消费项目');
$repositories = [];
foreach (['type-queue', 'type-redis', 'type-runtime', 'type-build'] as $package) {
    $repositories[] = ['type' => 'path', 'url' => $root . '/plugin/' . $package,
        'options' => ['symlink' => false, 'versions' => ['zoujingli/' . $package => '1.0.x-dev']]];
}
$composer = ['name' => 'type-tests/queue-consumer', 'type' => 'project', 'license' => 'Apache-2.0',
    'require' => ['zoujingli/type-queue' => '~1.0.0@dev'],
    'require-dev' => ['zoujingli/type-build' => '~1.0.0@dev', 'swoole/typephp' => '0.9.0', 'swoole/phpx' => '2.9.0'],
    'repositories' => $repositories, 'minimum-stability' => 'dev', 'prefer-stable' => true, 'config' => ['allow-plugins' => false]];
file_put_contents($consumer . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
copy($root . '/examples/queue-command.php', $consumer . '/app/main.php');
copy($root . '/examples/queue/Increment.php', $consumer . '/app/Increment.php');
copy($root . '/toolchain.lock.json', $consumer . '/toolchain.lock.json');
$settings = json_decode(file_get_contents($root . '/docs/build-config/type-queue.json'), true, 512, JSON_THROW_ON_ERROR);
unset($settings['project-root']);
$settings['entry'] = 'app/main.php';
$settings['sources'] = ['app/Increment.php'];
file_put_contents($consumer . '/application.json', json_encode($settings, JSON_THROW_ON_ERROR));
successful([getenv('COMPOSER_BINARY') ?: 'composer', 'install', '--no-interaction', '--no-scripts', '--no-plugins', '--prefer-dist', '--no-progress'], $consumer);
$installed = json_decode(file_get_contents($consumer . '/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
$names = array_column($installed['packages'], 'name');
expect(!in_array('zoujingli/type-core', $names, true) && !in_array('zoujingli/type-orm', $names, true), '队列独立安装混入 HTTP 或 ORM');
if (($argv[1] ?? '') === '--native') {
    successful([PHP_BINARY, $consumer . '/vendor/bin/type', $consumer . '/application.json'], $consumer);
    $command = nativeCommand($consumer . '/build/queue/type-app');
} else {
    $generator = 'require "vendor/autoload.php"; $settings=json_decode(file_get_contents("application.json"),true,512,JSON_THROW_ON_ERROR);'
        . 'file_put_contents("app/Jobs.php",(new Type\\Build\\JobCompiler())->generate($settings["queue"]));';
    successful([PHP_BINARY, '-r', $generator], $consumer);
    $launcher = 'require "vendor/autoload.php"; require "app/Jobs.php"; require "app/Increment.php"; require "app/main.php"; main($argc,$argv);';
    $command = [PHP_BINARY, '-r', $launcher];
}
expect(successful($command, $consumer) === "Streams 任务注册、独立作用域、幂等消费、原子确认与停止通过。\n", '独立队列行为失败');
echo "队列独立 Composer 安装、任务生成与真实 Redis 消费通过。\n";
