<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$driver = $argv[1] ?? 'mysql';
expect(in_array($driver, ['mysql', 'pgsql', 'sqlite'], true), '未知数据库消费目标');
$consumer = $root . '/build/' . $driver . '-consumer-' . bin2hex(random_bytes(4));
expect(mkdir($consumer . '/app', 0755, true), '无法创建数据库消费项目');
$repositories = [];
foreach (['type-runtime', 'type-orm', 'type-orm-' . $driver, 'type-build'] as $package) {
    $repositories[] = ['type' => 'path', 'url' => $root . '/plugin/' . $package,
        'options' => ['symlink' => false, 'versions' => ['zoujingli/' . $package => '1.0.x-dev']]];
}
$composer = ['name' => 'type-tests/' . $driver . '-consumer', 'type' => 'project', 'license' => 'Apache-2.0',
    'require' => ['zoujingli/type-orm-' . $driver => '~1.0.0@dev'],
    'require-dev' => ['zoujingli/type-build' => '~1.0.0@dev', 'swoole/typephp' => '0.9.0', 'swoole/phpx' => '2.9.0'],
    'repositories' => $repositories, 'minimum-stability' => 'dev', 'prefer-stable' => true, 'config' => ['allow-plugins' => false]];
file_put_contents($consumer . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
$settings = ['name' => $driver . '-consumer', 'entry' => 'app/main.php', 'output' => 'build/' . $driver . '/type-app', 'build-directory' => 'build/' . $driver . '/compiler'];
if ($driver === 'mysql' && extension_loaded('mysqlnd')) {
    // mysqlnd可为共享模块；使用现有运行声明明确记录本SDK的真实依赖。
    $settings['runtime'] = [PHP_OS_FAMILY => ['extensions' => ['mysqlnd']]];
}
file_put_contents($consumer . '/application.json', json_encode($settings, JSON_THROW_ON_ERROR));
expect(copy($root . '/examples/' . $driver . '-command.php', $consumer . '/app/main.php'), '无法复制数据命令');
expect(copy($root . '/toolchain.lock.json', $consumer . '/toolchain.lock.json'), '无法复制工具链约束');
successful([getenv('COMPOSER_BINARY') ?: 'composer', 'install', '--no-interaction', '--no-scripts', '--no-plugins', '--prefer-dist', '--no-progress'], $consumer);
$phpCommand = [PHP_BINARY, '-r', 'require ' . var_export($consumer . '/vendor/autoload.php', true)
    . ';require ' . var_export($consumer . '/app/main.php', true) . ';main($argc,$argv);'];
echo successful($phpCommand, $consumer);
successful([PHP_BINARY, $consumer . '/vendor/bin/type', $consumer . '/application.json'], $consumer);
$binary = $consumer . '/build/' . $driver . '/type-app';
$report = json_decode(file_get_contents($binary . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
$names = array_keys($report['production-packages']);
sort($names);
expect($names === ['zoujingli/type-orm', 'zoujingli/type-orm-' . $driver, 'zoujingli/type-runtime'], '数据消费混入了 HTTP 或其他驱动依赖');
foreach ($report['sources'] as $source) {
    expect(str_starts_with($source, $consumer . '/'), '数据消费仍依赖主仓生产源码');
}
echo successful([PHP_BINARY, $root . '/tests/database-native.php', $binary, $driver]);
if (getenv('TYPE_EXPECT_SELECTED_DRIVER_ONLY') === '1') {
    expect(PDO::getAvailableDrivers() === [$driver], '控制端没有使用真实单驱动 SDK');
    expect(json_decode(successful([$binary, 'test-drivers']), true, 512, JSON_THROW_ON_ERROR) === [$driver], '原生产物仍加载了未选数据库驱动');
    echo 'PHP与原生产物实际仅加载' . $driver . "，未安装其他数据库扩展仍可运行。\n";
}
if ($driver === 'sqlite' && getenv('TYPE_EXPECT_SQLITE_ONLY') === '1') {
    expect(PDO::getAvailableDrivers() === ['sqlite'], '控制端没有使用真实SQLite-only SDK');
    expect(json_decode(successful([$binary, 'test-drivers']), true, 512, JSON_THROW_ON_ERROR) === ['sqlite'], '原生产物仍加载了未选数据库驱动');
    echo "PHP与原生产物实际仅加载SQLite，未安装MySQL/PG驱动仍可运行。\n";
}
echo '仅安装 ORM 与 ' . $driver . " 驱动的独立原生命令验证通过。\n";
