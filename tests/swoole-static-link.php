<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/plugin/type-build/src/SwooleFeatureSelection.php';
require dirname(__DIR__) . '/plugin/type-build/src/RuntimeIni.php';

use Type\Build\RuntimeIni;
use Type\Build\SwooleFeatureSelection;

$selection = new SwooleFeatureSelection();
$threadOnly = $selection->select([], [], [], true);
expect($threadOnly === ['--enable-sockets', '--enable-cares', '--enable-swoole-thread'], '线程应用必须固定带上 sockets、cares 和 thread');
expect(!in_array('--enable-mysqlnd', $threadOnly, true) && !in_array('--enable-swoole-pgsql', $threadOnly, true)
    && !in_array('--enable-swoole-sqlite', $threadOnly, true) && !in_array('--enable-swoole-curl', $threadOnly, true), '未使用的可选块进入了线程链接开关');

$drivers = $selection->select([], [], ['pdo_mysql', 'pdo_pgsql', 'pdo_sqlite'], true);
expect($drivers === ['--enable-sockets', '--enable-mysqlnd', '--enable-cares', '--enable-swoole-thread', '--enable-swoole-pgsql', '--enable-swoole-sqlite'], '运行声明中的 PDO 驱动没有按稳定顺序打开对应开关');

$sqlite = $selection->select(
    ['Swoole\\Coroutine\\SQLite', 'Swoole\\ConnectionPool', 'App\\Worker'],
    [],
    [],
    true,
    ['Swoole\\Coroutine\\SQLite' => 'swoole', 'Swoole\\ConnectionPool' => false, 'App\\Worker' => false]
);
expect($sqlite === ['--enable-sockets', '--enable-cares', '--enable-swoole-thread', '--enable-swoole-sqlite'], '已加载的协程 SQLite 类没有映射到官方开关');
expect($selection->select(['Swoole\\ConnectionPool'], [], [], false, []) === [], '官方 PHP 库 ConnectionPool 被当成了 configure 块');

$rejected = false;
try {
    $selection->select(['Swoole\\Coroutine\\Redis'], [], [], true, []);
} catch (RuntimeException $error) {
    $rejected = str_contains($error->getMessage(), '超集模块没有该符号');
}
expect($rejected, '超集模块没有的 Redis 符号被静默忽略');

$rejected = false;
try {
    $selection->select(['Swoole\\Foo'], [], [], false, []);
} catch (RuntimeException $error) {
    $rejected = str_contains($error->getMessage(), '超集模块没有该符号');
}
expect($rejected, '超集模块没有的 Swoole 类被静默忽略');

expect($selection->select(['App\\Worker'], ['strlen'], [], false, ['App\\Worker' => false, 'strlen' => 'standard']) === [], '没有 Swoole 使用的普通应用被加上了链接开关');

$modules = ['pdo' => '/tmp/pdo.so', 'pdo_pgsql' => '/tmp/pdo_pgsql.so', 'curl' => '/tmp/curl.so', 'swoole' => '/tmp/swoole.so'];
expect($selection->sharedModulesBeforeSwoole(['--enable-swoole-pgsql', '--enable-swoole-sqlite'], $modules) === ['pdo', 'pdo_pgsql'], '共享 PDO 模块没有按 Swoole 之前的顺序选出');
expect($selection->sharedModulesBeforeSwoole(['--enable-sockets'], ['swoole' => '/tmp/swoole.so']) === [], '没有对应模块文件时仍登记了 PDO');

$rejected = false;
try {
    $selection->assertRuntimeCoverage(['--enable-swoole-pgsql'], []);
} catch (RuntimeException $error) {
    $rejected = str_contains($error->getMessage(), '运行声明缺少 pdo_pgsql');
}
expect($rejected, '统计要求 PostgreSQL 时没有拒绝缺失的运行声明');

$listing = "000000 T php_curl_multi_ce\n                 U php_curl_multi_ce\n                 U curl_multi_ce\n";
expect(!SwooleFeatureSelection::referencesUndefinedSymbol("                 U php_curl_multi_ce\n", 'curl_multi_ce'), 'php_curl_multi_ce 被误认成 curl_multi_ce');
expect(!SwooleFeatureSelection::referencesUndefinedSymbol("                 U _php_curl_multi_ce\n", 'curl_multi_ce'), 'Mach-O 的 _php_curl_multi_ce 被误认成 curl_multi_ce');
expect(SwooleFeatureSelection::referencesUndefinedSymbol($listing, 'curl_multi_ce'), '未定义的 curl_multi_ce 没有被识别');
expect(SwooleFeatureSelection::referencesUndefinedSymbol("                 U _curl_multi_ce\n", 'curl_multi_ce'), 'Mach-O 未定义符号 _curl_multi_ce 没有被识别');

$libraries = $selection->productLibraries([
    ['name' => 'libphp.so', 'path' => '/lib/libphp.so'],
    ['name' => 'swoole.so', 'path' => '/lib/swoole.so'],
    ['name' => 'php_swoole.dll', 'path' => '/lib/php_swoole.dll'],
    ['name' => 'pdo_pgsql.so', 'path' => '/lib/pdo_pgsql.so'],
]);
expect(array_column($libraries, 'name') === ['libphp.so', 'pdo_pgsql.so'], '发布运行库仍包含 swoole 共享模块');

$ini = tempnam(sys_get_temp_dir(), 'swoole-ini-');
expect(is_string($ini), '无法创建运行配置临时文件');
try {
    $probe = "swoole.enable_library=On\nswoole.enable_fiber_mock=On\nextension=\"/sdk/curl.so\"\nextension=\"/sdk/pdo_pgsql.so\"\nextension=\"/sdk/swoole.so\"\n";
    expect(file_put_contents($ini, $probe) === strlen($probe), '无法写入探针配置');
    $product = (new RuntimeIni())->withoutExtensions($ini, ['swoole', 'pdo_pgsql']);
    expect(str_contains($product, 'swoole.enable_library=On') && str_contains($product, 'swoole.enable_fiber_mock=On'), '静态链接后丢掉了官方库和纤程模拟开关');
    expect(str_contains($product, 'curl.so') && !str_contains($product, 'swoole.so') && !str_contains($product, 'pdo_pgsql.so'), '原生配置仍加载已经链入的模块');
    $release = (new RuntimeIni())->generate(['curl.so'], 'lib');
    expect(!str_contains($release, 'swoole') || str_contains($release, 'swoole.enable_library=On'), '发布配置意外写入了动态 Swoole');
    expect(!preg_match('/^extension=.*swoole/m', $release), '发布配置仍包含 extension=swoole');
} finally {
    if (is_file($ini)) {
        unlink($ini);
    }
}

echo "Swoole 使用选择、符号检查与发布配置通过\n";
