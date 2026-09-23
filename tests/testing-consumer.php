<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
$root = dirname(__DIR__);
$consumer = $root . '/build/testing-consumer-' . bin2hex(random_bytes(6));
expect(mkdir($consumer . '/tests', 0700, true), '无法创建测试插件独立消费项目');
$composer = ['name' => 'type-tests/testing-consumer', 'type' => 'project', 'license' => 'Apache-2.0',
    'require' => ['zoujingli/type-testing' => '~1.0.0@dev'], 'repositories' => [], 'minimum-stability' => 'dev',
    # 独立消费只验证插件闭包；锁定 PHP 尚未挂上受控 Swoole 时，用平台声明放行 ext-swoole。
    'config' => ['allow-plugins' => false, 'platform' => [
        'ext-swoole' => '6.2.1', 'ext-redis' => false, 'ext-pdo_mysql' => false, 'ext-pdo_pgsql' => false, 'ext-pdo_sqlite' => false,
    ]]];
foreach (['type-testing', 'type-runtime'] as $name) {
    $composer['repositories'][] = ['type' => 'path', 'url' => $root . '/plugin/' . $name, 'options' => ['symlink' => false, 'versions' => ['zoujingli/' . $name => '1.0.x-dev']]];
}
file_put_contents($consumer . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
successful([getenv('COMPOSER_BINARY') ?: 'composer', 'install', '--no-interaction', '--no-scripts', '--no-plugins', '--prefer-dist', '--no-progress'], $consumer);
$installed = json_decode(file_get_contents($consumer . '/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
$names = array_column($installed['packages'], 'name');
sort($names);
expect($names === ['zoujingli/type-runtime', 'zoujingli/type-testing'], '测试工具引入了不需要的运行插件');
copy($root . '/tests/testing.php', $consumer . '/tests/testing.php');
echo successful([PHP_BINARY, $consumer . '/tests/testing.php'], $consumer);
copy($root . '/tests/support.php', $consumer . '/tests/support.php');
copy($root . '/tests/testing-portable.php', $consumer . '/tests/testing-portable.php');
echo successful([PHP_BINARY, $consumer . '/tests/testing-portable.php'], $consumer);
echo '测试插件独立 Composer 消费通过：' . $consumer . "\n";
