<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/plugin/type-build/src/SwooleFeatureSelection.php';
require dirname(__DIR__) . '/plugin/type-build/src/SwooleStaticModule.php';
require dirname(__DIR__) . '/plugin/type-build/src/RuntimeIni.php';

use Type\Build\RuntimeIni;
use Type\Build\SwooleFeatureSelection;
use Type\Build\SwooleStaticModule;

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

$modules = ['sockets' => '/tmp/sockets.so', 'pdo' => '/tmp/pdo.so', 'pdo_pgsql' => '/tmp/pdo_pgsql.so', 'curl' => '/tmp/curl.so', 'swoole' => '/tmp/swoole.so'];
expect($selection->sharedModulesBeforeSwoole(['--enable-sockets', '--enable-swoole-pgsql', '--enable-swoole-sqlite'], $modules) === ['sockets', 'pdo', 'pdo_pgsql'], '共享 sockets 与 PDO 模块没有按 Swoole 之前的顺序选出');
expect($selection->sharedModulesBeforeSwoole(['--enable-sockets'], ['swoole' => '/tmp/swoole.so']) === [], '没有对应模块文件时仍登记了 sockets 或 PDO');
expect($selection->sharedModulesBeforeSwoole(['--enable-sockets'], ['sockets' => '/tmp/sockets.so', 'swoole' => '/tmp/swoole.so']) === ['sockets'], 'sockets 模块存在时没有排在 Swoole 之前');

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
    $probe = "swoole.enable_library=On\nswoole.enable_fiber_mock=On\nextension_dir=\nextension=\"/sdk/curl.so\"\nextension=\"/sdk/sockets.so\"\nextension=\"/sdk/pdo_pgsql.so\"\nextension=\"/sdk/swoole.so\"\n";
    expect(file_put_contents($ini, $probe) === strlen($probe), '无法写入探针配置');
    $product = (new RuntimeIni())->withoutExtensions($ini, ['swoole', 'sockets', 'pdo_pgsql'], '/sdk');
    expect(str_contains($product, 'swoole.enable_library=On') && str_contains($product, 'swoole.enable_fiber_mock=On'), '静态链接后丢掉了官方库和纤程模拟开关');
    expect(str_contains($product, 'extension_dir="/sdk"'), '产物配置没有给官方 php_load_extension 提供 extension_dir');
    expect(str_contains($product, 'curl.so') && !str_contains($product, 'swoole.so') && !str_contains($product, 'pdo_pgsql.so') && !str_contains($product, 'sockets.so'), '原生配置仍加载已经链入的模块');
    $release = (new RuntimeIni())->generate(['curl.so'], 'lib');
    expect(!str_contains($release, 'swoole') || str_contains($release, 'swoole.enable_library=On'), '发布配置意外写入了动态 Swoole');
    expect(!preg_match('/^extension=.*swoole/m', $release), '发布配置仍包含 extension=swoole');
} finally {
    if (is_file($ini)) {
        unlink($ini);
    }
}

$objectRoot = sys_get_temp_dir() . '/swoole-objects-' . bin2hex(random_bytes(4));
expect(mkdir($objectRoot . '/ext-src/.libs', 0700, true) && mkdir($objectRoot . '/src/os/.libs', 0700, true)
    && mkdir($objectRoot . '/thirdparty/php85/pdo_pgsql/.libs', 0700, true) && mkdir($objectRoot . '/.libs', 0700, true), '无法创建目标文件探测目录');
try {
    expect(file_put_contents($objectRoot . '/ext-src/.libs/php_swoole.o', 'o') === 1, '无法写入扩展目标');
    expect(file_put_contents($objectRoot . '/src/os/.libs/async_thread.o', 'o') === 1, '无法写入运行时目标');
    expect(file_put_contents($objectRoot . '/thirdparty/php85/pdo_pgsql/.libs/pgsql_driver.o', 'o') === 1, '无法写入已打开的可选块目标');
    expect(file_put_contents($objectRoot . '/.libs/swoole.o', 'combined') === 8, '无法写入整模块目标');
    $listed = [];
    $scanner = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($objectRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($scanner as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'o') {
            continue;
        }
        $path = str_replace('\\', '/', $file->getPathname());
        if (!str_contains($path, '/.libs/') || $file->getFilename() === 'swoole.o') {
            continue;
        }
        $listed[] = $path;
    }
    sort($listed);
    expect(count($listed) === 3, 'phpize 分文件目标没有按各目录 .libs 收集');
    expect(!in_array($objectRoot . '/.libs/swoole.o', $listed, true), '整模块 swoole.o 被和分文件目标叠在一起');
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($objectRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($objectRoot);
}

$registrar = SwooleStaticModule::registrarSource(['sockets', 'pdo', 'pdo_pgsql']);
expect(str_contains($registrar, 'php_load_extension(name, MODULE_PERSISTENT, 0)'), '登记器没有调用官方 php_load_extension');
expect(str_contains($registrar, 'type_app_load_shared("sockets"'), '登记器没有在静态 Swoole 之前加载 sockets');
expect(str_contains($registrar, 'type_app_load_shared("pdo_pgsql"'), '登记器没有在静态 Swoole 之前加载 pdo_pgsql');
expect(str_contains($registrar, '#include "ext/standard/dl.h"'), '登记器没有包含官方 dl.h');
expect(str_contains($registrar, 'extern zend_module_entry swoole_module_entry'), '登记器没有声明静态 Swoole 入口');
expect(str_contains($registrar, 'zend_register_internal_module(&swoole_module_entry)'), '登记器没有把静态 Swoole 登记为内置模块');
expect(!str_contains($registrar, 'pdo_pgsql_module_entry') && !str_contains($registrar, 'pdo_module_entry') && !str_contains($registrar, 'sockets_module_entry'), '登记器仍引用共享模块里不可见的入口符号');
$rejected = false;
try {
    SwooleStaticModule::registrarSource(['redis']);
} catch (RuntimeException $error) {
    $rejected = str_contains($error->getMessage(), '没有可登记的模块入口');
}
expect($rejected, '未知共享模块被写进登记器');

$linkRoot = sys_get_temp_dir() . '/swoole-link-' . bin2hex(random_bytes(4));
expect(mkdir($linkRoot, 0700, true), '无法创建链接记号探测目录');
try {
    $sockets = $linkRoot . '/sockets.so';
    $pdo = $linkRoot . '/pdo.so';
    expect(file_put_contents($sockets, 'so') === 2 && file_put_contents($pdo, 'so') === 2, '无法写入共享模块占位文件');
    $tokens = SwooleStaticModule::sharedModuleLinkTokens([$sockets, $pdo]);
    expect($tokens !== [], '共享模块链接记号为空');
    if (PHP_OS_FAMILY === 'Darwin') {
        expect($tokens[0] === '-Wl,-rpath,' . $linkRoot && in_array($sockets, $tokens, true) && !in_array('-Wl,--no-as-needed', $tokens, true), 'Darwin 没有按绝对路径保留 sockets');
    } else {
        expect($tokens[0] === '-Wl,--no-as-needed' && $tokens[count($tokens) - 1] === '-Wl,--as-needed', 'GNU ld 没有强制保留当时看不到引用的共享模块');
        expect(in_array('-l:sockets.so', $tokens, true) && in_array('-l:pdo.so', $tokens, true), 'GNU ld 没有按 SONAME 链入 sockets 与 PDO');
    }
} finally {
    foreach ([$linkRoot . '/sockets.so', $linkRoot . '/pdo.so'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($linkRoot);
}

$script = (string) file_get_contents(dirname(__DIR__) . '/tools/prepare-swoole-module.sh');
expect(str_contains($script, 'strip --strip-debug') && str_contains($script, 'strip -S'), '静态目标没有去掉调试段');

echo "Swoole 使用选择、符号检查与发布配置通过\n";
