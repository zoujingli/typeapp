<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
$root = dirname(__DIR__);
$consumer = $root . '/build/log-consumer-' . bin2hex(random_bytes(5));
expect(mkdir($consumer . '/app', 0755, true), '无法准备日志独立消费项目');
$repositories = [];
foreach (['type-log', 'type-runtime', 'type-build'] as $package) {
    $repositories[] = ['type' => 'path', 'url' => '../../plugin/' . $package,
        'options' => ['symlink' => false, 'versions' => ['zoujingli/' . $package => '1.0.x-dev']]];
}
$composer = ['name' => 'type-tests/log-consumer', 'type' => 'project', 'license' => 'Apache-2.0',
    'require' => ['zoujingli/type-log' => '~1.0.0@dev'],
    'require-dev' => ['zoujingli/type-build' => '~1.0.0@dev', 'swoole/typephp' => '0.9.3', 'swoole/phpx' => '2.9.2'],
    'repositories' => $repositories, 'minimum-stability' => 'dev', 'prefer-stable' => true, 'config' => ['allow-plugins' => false]];
file_put_contents($consumer . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
copy($root . '/examples/log-command.php', $consumer . '/app/main.php');
copy($root . '/examples/log-behavior-command.php', $consumer . '/app/behavior.php');
copy($root . '/toolchain.lock.json', $consumer . '/toolchain.lock.json');
$configuration = json_decode(file_get_contents($root . '/docs/build-config/type-log.json'), true, 512, JSON_THROW_ON_ERROR);
unset($configuration['project-root']);
$configuration['entry'] = 'app/main.php';
file_put_contents($consumer . '/application.json', json_encode($configuration, JSON_THROW_ON_ERROR));
successful([getenv('COMPOSER_BINARY') ?: 'composer', 'install', '--no-interaction', '--no-scripts', '--no-plugins', '--prefer-dist', '--no-progress'], $consumer);
$installed = json_decode(file_get_contents($consumer . '/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
$names = array_column($installed['packages'], 'name');
expect(in_array('psr/log', $names, true) && array_intersect(['zoujingli/type-core', 'zoujingli/type-orm', 'zoujingli/type-redis'], $names) === [], '日志独立消费依赖错误');
if (($argv[1] ?? '') === '--native') {
    successful([PHP_BINARY, $consumer . '/vendor/bin/type', $consumer . '/application.json'], $consumer);
    $command = nativeCommand($consumer . '/build/log/type-app');
} else {
    $command = [PHP_BINARY, '-r', 'require "vendor/autoload.php"; require "app/main.php"; main($argc, $argv);'];
}
$wire = successful($command, $consumer);
$rows = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), explode("\n", rtrim($wire, "\n")));
expect(count($rows) === 2 && $rows[0]['message'] === '你好，开发者' && $rows[1]['context']['password'] === '[REDACTED]', '独立安装的日志输出不符合要求');
expect(successful([PHP_BINARY, '-r', 'require "vendor/autoload.php"; require "vendor/swoole/typephp/src/polyfills.php"; require "app/behavior.php"; main($argc, $argv);'], $consumer)
    === "日志文件、多通道、作用域隔离、任意上下文与脱敏通过。\n", '独立日志公开行为失败');
echo "日志独立 Composer 安装、PSR-3 与源码适配声明通过。\n";
