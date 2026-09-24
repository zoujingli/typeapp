<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$consumer = $root . '/build/cache-consumer-' . bin2hex(random_bytes(5));
expect(mkdir($consumer . '/app', 0755, true), '无法准备缓存消费项目');
$repositories = [];
foreach (['type-cache', 'type-redis', 'type-runtime', 'type-build'] as $package) {
    $repositories[] = ['type' => 'path', 'url' => '../../plugin/' . $package,
        'options' => ['symlink' => false, 'versions' => ['zoujingli/' . $package => '1.0.x-dev']]];
}
$composer = ['name' => 'type-tests/cache-consumer', 'type' => 'project', 'license' => 'Apache-2.0',
    'require' => ['zoujingli/type-cache' => '~1.0.0@dev'],
    'require-dev' => ['zoujingli/type-build' => '~1.0.0@dev', 'swoole/typephp' => '0.9.3', 'swoole/phpx' => '2.9.2'],
    'repositories' => $repositories, 'minimum-stability' => 'dev', 'prefer-stable' => true, 'config' => ['allow-plugins' => false]];
file_put_contents($consumer . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
copy($root . '/examples/psr-cache-command.php', $consumer . '/app/main.php');
copy($root . '/examples/cache/SerializableNote.php', $consumer . '/app/SerializableNote.php');
copy($root . '/toolchain.lock.json', $consumer . '/toolchain.lock.json');
$settings = json_decode(file_get_contents($root . '/docs/build-config/type-psr-cache.json'), true, 512, JSON_THROW_ON_ERROR);
unset($settings['project-root']);
$settings['entry'] = 'app/main.php';
$settings['sources'] = ['app/SerializableNote.php'];
file_put_contents($consumer . '/application.json', json_encode($settings, JSON_THROW_ON_ERROR));
successful([getenv('COMPOSER_BINARY') ?: 'composer', 'install', '--no-interaction', '--no-scripts', '--no-plugins', '--prefer-dist', '--no-progress'], $consumer);
$installed = json_decode(file_get_contents($consumer . '/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
$names = array_column($installed['packages'], 'name');
expect(!in_array('zoujingli/type-core', $names, true) && !in_array('zoujingli/type-orm', $names, true), '独立缓存错误依赖其他框架入口');
$launcher = 'require ' . var_export($consumer . '/vendor/autoload.php', true)
    . '; require ' . var_export($consumer . '/vendor/swoole/typephp/src/polyfills.php', true)
    . '; require ' . var_export($consumer . '/app/SerializableNote.php', true)
    . '; require ' . var_export($consumer . '/app/main.php', true) . '; main($argc, $argv);';
$command = [PHP_BINARY, '-r', $launcher];
if (($argv[1] ?? '') === '--native') {
    successful([PHP_BINARY, $consumer . '/vendor/bin/type', $consumer . '/application.json'], $consumer);
    $command = nativeCommand($consumer . '/build/psr-cache/type-app');
}
expect(successful($command) === "PSR-16 类型、对象、TTL、非法键、签名与永久数据回收通过。\n", '独立缓存消费行为不符');
echo "PSR-16 独立 Composer 安装与真实 Redis adapter 验证通过。\n";
