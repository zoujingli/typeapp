<?php

declare(strict_types=1);

require __DIR__ . '/support.php';

$root = dirname(__DIR__);
$driver = $argv[1] ?? 'sqlite';
expect(in_array($driver, ['mysql', 'pgsql', 'sqlite'], true), '未知迁移消费驱动');
$consumer = $root . '/build/migrations-' . $driver . '-consumer-' . bin2hex(random_bytes(4));
expect(mkdir($consumer . '/app', 0755, true), '无法创建独立迁移消费项目');
$repositories = [];
foreach (['type-runtime', 'type-orm', 'type-orm-' . $driver, 'type-build'] as $package) {
    $repositories[] = ['type' => 'path', 'url' => $root . '/plugin/' . $package,
        'options' => ['symlink' => false, 'versions' => ['zoujingli/' . $package => '1.0.x-dev']]];
}
$composer = ['name' => 'type-tests/migrations-' . $driver, 'type' => 'project', 'license' => 'Apache-2.0',
    'require' => ['zoujingli/type-orm-' . $driver => '~1.0.0@dev'],
    'require-dev' => ['zoujingli/type-build' => '~1.0.0@dev', 'swoole/typephp' => '0.9.0', 'swoole/phpx' => '2.9.0'],
    'repositories' => $repositories, 'minimum-stability' => 'dev', 'prefer-stable' => true, 'config' => ['allow-plugins' => false]];
file_put_contents($consumer . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
$settings = ['name' => 'migrations-' . $driver . '-consumer', 'entry' => 'app/main.php', 'sources' => ['app/Plan.php', 'app/DriverFactory.php'],
    'output' => 'build/type-app', 'build-directory' => 'build/compiler'];
if ($driver === 'mysql' && extension_loaded('mysqlnd')) {
    $settings['runtime'] = [PHP_OS_FAMILY => ['extensions' => ['mysqlnd']]];
}
file_put_contents($consumer . '/application.json', json_encode($settings, JSON_THROW_ON_ERROR));
expect(copy($root . '/examples/migration-command.php', $consumer . '/app/main.php'), '无法复制迁移入口');
expect(copy($root . '/examples/migrations/Plan.php', $consumer . '/app/Plan.php'), '无法复制迁移定义');
expect(copy($root . '/toolchain.lock.json', $consumer . '/toolchain.lock.json'), '无法复制工具链约束');
$driverSource = match ($driver) {
    'mysql' => <<<'PHP'
new \Type\Orm\Mysql\MysqlDriver(getenv('TYPE_MYSQL_HOST') ?: '127.0.0.1', (int) (getenv('TYPE_MYSQL_PORT') ?: '3306'),
    getenv('TYPE_MYSQL_DATABASE') ?: 'type_app_test', getenv('TYPE_MYSQL_USER') ?: 'root', getenv('TYPE_MYSQL_PASSWORD') ?: '')
PHP,
    'pgsql' => <<<'PHP'
new \Type\Orm\Pgsql\PgsqlDriver(getenv('TYPE_PGSQL_HOST') ?: '127.0.0.1', (int) (getenv('TYPE_PGSQL_PORT') ?: '5432'),
    getenv('TYPE_PGSQL_DATABASE') ?: 'type_app_test', getenv('TYPE_PGSQL_USER') ?: 'type_app', getenv('TYPE_PGSQL_PASSWORD') ?: '')
PHP,
    'sqlite' => <<<'PHP'
new \Type\Orm\Sqlite\SqliteDriver(getenv('TYPE_SQLITE_FILE') ?: sys_get_temp_dir() . '/type-app-migrations.sqlite', 100, true)
PHP,
};
file_put_contents($consumer . '/app/DriverFactory.php', "<?php\ndeclare(strict_types=1);\nnamespace TypeApp\\Migrations;\nfinal class DriverFactory { public static function create(): \\Type\\Orm\\Driver { return " . $driverSource . "; } }\n");
successful([getenv('COMPOSER_BINARY') ?: 'composer', 'install', '--no-interaction', '--no-scripts', '--no-plugins', '--prefer-dist', '--no-progress'], $consumer);
$previousConsumer = getenv('TYPE_MIGRATION_CONSUMER_ROOT');
putenv('TYPE_MIGRATION_CONSUMER_ROOT=' . $consumer);
try {
    echo successful([PHP_BINARY, $root . '/tests/migrations-native.php', '--php', $driver]);
} finally {
    putenv($previousConsumer === false ? 'TYPE_MIGRATION_CONSUMER_ROOT' : 'TYPE_MIGRATION_CONSUMER_ROOT=' . $previousConsumer);
}
successful([PHP_BINARY, $consumer . '/vendor/bin/type', $consumer . '/application.json'], $consumer);
$binary = $consumer . '/build/type-app';
$report = json_decode(file_get_contents($binary . '.build.json'), true, 512, JSON_THROW_ON_ERROR);
$names = array_keys($report['production-packages']);
sort($names);
expect($names === ['zoujingli/type-orm', 'zoujingli/type-orm-' . $driver, 'zoujingli/type-runtime'], '独立迁移混入了 core、HTTP、Redis 或其他驱动');
foreach ($report['sources'] as $source) {
    expect(str_starts_with($source, $consumer . '/'), '独立迁移仍在使用主仓生产源码');
}
echo successful([PHP_BINARY, $root . '/tests/migrations-native.php', $binary, $driver]);
echo '仅安装 ORM 与 ' . $driver . " 驱动的独立迁移验证通过。\n";
