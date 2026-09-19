<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$consumer = $root . '/build/redis-consumer-' . bin2hex(random_bytes(5));
expect(mkdir($consumer . '/app', 0755, true), '无法准备 Redis 独立消费项目');
$repositories = [];
foreach (['type-redis', 'type-runtime', 'type-build'] as $package) {
    $repositories[] = ['type' => 'path', 'url' => $root . '/plugin/' . $package,
        'options' => ['symlink' => false, 'versions' => ['zoujingli/' . $package => '1.0.x-dev']]];
}
$composer = ['name' => 'type-tests/redis-consumer', 'type' => 'project', 'license' => 'Apache-2.0',
    'require' => ['zoujingli/type-redis' => '~1.0.0@dev'],
    'require-dev' => ['zoujingli/type-build' => '~1.0.0@dev', 'swoole/typephp' => '0.9.0', 'swoole/phpx' => '2.9.0'],
    'repositories' => $repositories, 'minimum-stability' => 'dev', 'prefer-stable' => true, 'config' => ['allow-plugins' => false]];
file_put_contents($consumer . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
copy($root . '/examples/redis-command.php', $consumer . '/app/main.php');
copy($root . '/toolchain.lock.json', $consumer . '/toolchain.lock.json');
$configuration = json_decode(file_get_contents($root . '/docs/build-config/type-redis.json'), true, 512, JSON_THROW_ON_ERROR);
unset($configuration['project-root']);
$configuration['entry'] = 'app/main.php';
file_put_contents($consumer . '/application.json', json_encode($configuration, JSON_THROW_ON_ERROR));
successful([getenv('COMPOSER_BINARY') ?: 'composer', 'install', '--no-interaction', '--no-scripts', '--no-plugins', '--prefer-dist', '--no-progress'], $consumer);
$installed = json_decode(file_get_contents($consumer . '/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
$names = array_column($installed['packages'], 'name');
expect(!in_array('zoujingli/type-core', $names, true) && !in_array('zoujingli/type-orm', $names, true), '独立 Redis 错误依赖 HTTP 或 ORM');
$launcher = 'require ' . var_export($consumer . '/vendor/autoload.php', true) . '; require '
    . var_export($consumer . '/app/main.php', true) . '; main($argc, $argv);';
$command = [PHP_BINARY, '-r', $launcher, '--'];
expect(str_contains(successful([...$command, '--help']), 'Redis 验证命令'), '独立 Redis 帮助入口失败');
if (($argv[1] ?? '') === '--native') {
    successful([PHP_BINARY, $consumer . '/vendor/bin/type', $consumer . '/application.json'], $consumer);
    $command = nativeCommand($consumer . '/build/redis/type-app');
}
expect(successful($command) === "Redis 命名用途、租约、pipeline、事务、超时与恢复通过。\n", '独立 Redis 行为不符');
echo "Redis 独立 Composer 消费与公共行为验证通过。\n";
