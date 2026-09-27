<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Composer\InstalledVersions;
use Type\Build\BuildLock;
use Type\Build\BuildPlatform;
use Type\Build\DependencyNotices;
use Type\Build\StaticRuntimeSdk;
use Type\Testing\Process;

// 本脚本属于 macOS SDK 制备入口：登记本轮构建的归档、目标头文件和许可原文。
// 不能用清单生成或归档后缀代替最终应用的静态加载及业务验收。
if ($argc !== 3 || PHP_OS_FAMILY !== 'Darwin' || php_uname('m') !== 'arm64' || PHP_VERSION !== '8.5.10' || !PHP_ZTS) {
    throw new InvalidArgumentException('用法：锁定 PHP 8.5.10 ZTS static-runtime-manifest.php <制备工作目录> <PostgreSQL静态SDK根目录>');
}
$root = dirname(__DIR__);
$work = BuildPlatform::resolve($argv[1]);
$pgsql = BuildPlatform::resolve($argv[2]);
$sdk = $work . '/sdk';
$php = $work . '/src/php-8.5.10';
$phpx = $work . '/src/phpx';
$materials = $root . '/plugin/type-build/resources/swoole/licenses';
$notices = [];
$dependencies = [];
$archives = [];
$minimum = getenv('MACOSX_DEPLOYMENT_TARGET') ?: '15.0';
if (preg_match('/^[1-9][0-9]*\.[0-9]+(?:\.[0-9]+)?$/D', $minimum) !== 1) {
    throw new RuntimeException('macOS 最低版本声明无效');
}

/** 仅复制已校验的普通文件，保留上游字节；许可路径是相对 SDK 的逻辑标识。 */
function staticSdkDocument(string $sdk, string $source, string $relative): array
{
    BuildLock::path($source);
    if (!is_file($source) || str_contains($relative, '..') || str_starts_with($relative, '/')) {
        throw new RuntimeException('静态 SDK 材料路径无效');
    }
    $destination = $sdk . '/licenses/' . $relative;
    if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0700, true)) {
        throw new RuntimeException('无法创建许可材料目录');
    }
    if (!copy($source, $destination) || hash_file('sha256', $source) !== hash_file('sha256', $destination)) {
        throw new RuntimeException('许可原文复制失败');
    }
    return ['file' => 'licenses/' . $relative, 'sha256' => hash_file('sha256', $destination)];
}

/** 归档按完整路径复制和登记，不让后续链接器按库名选择共享版本。 */
function staticSdkArchive(string $sdk, string $source): array
{
    BuildLock::path($source);
    if (!is_file($source) || file_get_contents($source, false, null, 0, 8) !== "!<arch>\n") {
        throw new RuntimeException('不是静态归档：' . basename($source));
    }
    $destination = $sdk . '/lib/' . basename($source);
    if (realpath($source) !== realpath($destination) && !copy($source, $destination)) {
        throw new RuntimeException('静态归档复制失败');
    }
    return ['file' => 'lib/' . basename($source), 'sha256' => hash_file('sha256', $destination)];
}

foreach (['libphpx.a', 'libphp.a'] as $name) {
    $archives[] = staticSdkArchive($sdk, $sdk . '/lib/' . $name);
}
$documents = [];
foreach (['LICENSE', 'TSRM/LICENSE', 'Zend/LICENSE', 'Zend/asm/LICENSE', 'ext/date/lib/LICENSE.rst',
    'ext/opcache/jit/ir/LICENSE', 'ext/mbstring/libmbfl/LICENSE', 'ext/standard/libavifinfo/LICENSE',
    'ext/lexbor/LICENSE', 'ext/uri/uriparser/COPYING.BSD-3-Clause', 'ext/pcre/pcre2lib/pcre2.h'] as $name) {
    $documents[] = staticSdkDocument($sdk, $php . '/' . $name, 'php/' . $name);
}
$documents[] = staticSdkDocument($sdk, $php . '/ext/redis/LICENSE', 'phpredis/LICENSE');
foreach (['LICENSE', 'thirdparty/nlohmann/LICENSE.MIT', 'thirdparty/php/LICENSE', 'thirdparty/php/ssh2/LICENSE',
    'thirdparty/hiredis/COPYING', 'thirdparty/boost/asm/LICENSE', 'thirdparty/nghttp2/COPYING',
    'thirdparty/nghttp2/LICENSE', 'thirdparty/llhttp/LICENSE-MIT', 'thirdparty/llhttp/LICENSE'] as $name) {
    $documents[] = staticSdkDocument($sdk, $php . '/ext/swoole/' . $name, 'swoole/' . $name);
}
$notices['libphp.a'] = ['component' => 'PHP、Swoole、phpredis 及随附代码',
    'version' => 'PHP 8.5.10; Swoole 6.2.1; phpredis 6.3.0',
    'license' => ['PHP-3.01', 'BSD-3-Clause', 'BSD-2-Clause', 'MIT', 'Apache-2.0', 'BSL-1.0'], 'files' => $documents];
$documents = [];
foreach (['LICENSE', 'thirdparty/mpdecimal/COPYRIGHT.txt', 'thirdparty/wren-gc/LICENSE'] as $name) {
    $documents[] = staticSdkDocument($sdk, $phpx . '/' . $name, 'phpx/' . $name);
}
$notices['libphpx.a'] = ['component' => 'PHPX、mpdecimal、wren-gc', 'version' => 'PHPX 2.9.2',
    'license' => ['Apache-2.0', 'BSD-2-Clause', 'MIT'], 'files' => $documents];

foreach ([
    'gmp' => [['libgmpxx.a', 'libgmp.a'], 'LGPL-3.0-or-later OR GPL-2.0-or-later', ['COPYING', 'COPYING.LESSERv3']],
    'mpfr' => [['libmpfr.a'], 'LGPL-3.0-or-later', ['COPYING', 'COPYING.LESSER']],
    'openssl@3' => [['libssl.a', 'libcrypto.a'], 'Apache-2.0', ['LICENSE.txt']],
    'c-ares' => [['libcares.a'], 'MIT', ['LICENSE.md']],
    'brotli' => [['libbrotlienc.a', 'libbrotlidec.a', 'libbrotlicommon.a'], 'MIT', ['LICENSE']],
    'sqlite' => [['libsqlite3.a'], 'public-domain', []],
] as $formula => [$names, $license, $files]) {
    $resolved = (new Process(['brew', '--prefix', $formula]))->wait(10);
    if (!$resolved->successful()) {
        throw new RuntimeException('无法定位静态依赖：' . $formula);
    }
    $prefix = BuildPlatform::resolve(trim($resolved->stdout));
    $dependencies[$formula] = ['version' => basename($prefix), 'receipt-sha256' => hash_file('sha256', $prefix . '/INSTALL_RECEIPT.json')];
    $documents = [];
    foreach ($files as $name) {
        $documents[] = staticSdkDocument($sdk, $prefix . '/' . $name, $formula . '/' . $name);
    }
    if ($formula === 'sqlite') {
        // 该原文明确标注 3.53.3，禁止用于其他 SQLite 版本。
        if (basename($prefix) !== '3.53.3') {
            throw new RuntimeException('SQLite 静态依赖升级后需重新核对许可原文');
        }
        $documents[] = staticSdkDocument($sdk, $materials . '/sqlite/NOTICE.txt', 'sqlite/NOTICE.txt');
    }
    foreach ($names as $name) {
        $archives[] = staticSdkArchive($sdk, $prefix . '/lib/' . $name);
        $notices[$name] = ['component' => $formula, 'version' => basename($prefix), 'license' => $license, 'files' => $documents];
    }
}
foreach (['libpq.a', 'libpgcommon_shlib.a', 'libpgport_shlib.a'] as $name) {
    $archives[] = staticSdkArchive($sdk, $pgsql . '/lib/' . $name);
    $notices[$name] = ['component' => 'PostgreSQL client', 'version' => '17.11', 'license' => 'PostgreSQL',
        'files' => [staticSdkDocument($sdk, $materials . '/postgresql/COPYRIGHT', 'postgresql/COPYRIGHT')]];
}
$archiveTargets = [];
foreach ($archives as $archive) {
    // PHP 归档包含数千个对象；逐对象加载命令约数 MiB，仍保留明确输出上限。
    $load = (new Process(['/usr/bin/otool', '-l', $sdk . '/' . $archive['file']], null, null, 16777216))->wait(30);
    if (!$load->successful() || preg_match_all('/^\s+minos (\d+\.\d+(?:\.\d+)?)$/m', $load->stdout, $targets) === 0) {
        throw new RuntimeException('无法确认静态归档的最低 macOS 版本：' . $archive['file']);
    }
    $archiveTargets[$archive['file']] = array_values(array_unique($targets[1]));
    foreach ($targets[1] as $target) {
        if (version_compare($target, $minimum, '>')) {
            throw new RuntimeException('归档 ' . $archive['file'] . ' 要求 macOS ' . $target . '，不能声明支持 ' . $minimum);
        }
    }
}
$headers = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sdk . '/include/php', FilesystemIterator::SKIP_DOTS)) as $file) {
    BuildLock::path($file->getPathname());
    if ($file->isFile()) {
        $relative = substr($file->getPathname(), strlen($sdk) + 1);
        $headers[$relative] = ['file' => $relative, 'sha256' => hash_file('sha256', $file->getPathname())];
    }
}
ksort($headers);
$manifest = ['protocol' => 1, 'php' => PHP_VERSION, 'zts' => (bool) PHP_ZTS, 'debug' => (bool) PHP_DEBUG,
    'integer-size' => PHP_INT_SIZE, 'os' => PHP_OS_FAMILY, 'architecture' => php_uname('m'),
    'archives' => $archives, 'headers' => array_values($headers), 'notices' => $notices, 'patches' => [],
    'sources' => [
        'php' => ['version' => '8.5.10', 'archive-sha256' => '6a8bebaa4d5a979a38db29a9373e9851f60c6b11f72172c585947e78f3081957'],
        'redis' => ['version' => '6.3.0', 'archive-sha256' => '0d5141f634bd1db6c1ddcda053d25ecf2c4fc1c395430d534fd3f8d51dd7f0b5'],
        'swoole' => ['reference' => '0f3bee2f0ed8704ce33a336e7feabb0115411dd7', 'archive-sha256' => 'b830fc102797143dd94a7603400a203e0d2228bd222c71a12c27d6fe62dac3ea'],
        'phpx' => ['reference' => InstalledVersions::getReference('swoole/phpx')],
    ], 'dependency-inputs' => $dependencies,
    'preparation' => ['script-sha256' => hash_file('sha256', __DIR__ . '/prepare-static-macos.sh'), 'manifest-script-sha256' => hash_file('sha256', __FILE__),
        'php-header-patch-sha256' => hash_file('sha256', __DIR__ . '/php-hash-cxx.patch'), 'minimum-macos' => $minimum,
        'archive-minimum-macos' => $archiveTargets],
];
foreach (['SwooleThreadSource', 'SwooleHttpSource', 'SwooleSocketSource', 'SwooleStaticSource', 'PhpxThreadSource'] as $patch) {
    $manifest['patches'][$patch] = hash_file('sha256', $root . '/plugin/type-build/src/' . $patch . '.php');
}
$path = $sdk . '/manifest.json';
file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$validated = new StaticRuntimeSdk($path);
$libraries = array_map(static fn (string $file): array => ['name' => basename($file), 'path' => $file, 'sha256' => hash_file('sha256', $file)], $validated->archives());
(new DependencyNotices())->collect($work . '/notice-verification', [], $libraries, ['native' => ['Darwin' => $validated->notices()], 'require-complete' => true]);
echo '静态 SDK 输入和许可材料已登记；需继续完成真实应用验收。' . PHP_EOL;
