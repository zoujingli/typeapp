<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Composer\InstalledVersions;
use Type\Build\BuildLock;
use Type\Build\BuildPlatform;
use Type\Build\DependencyNotices;
use Type\Build\StaticRuntimeSdk;
use Type\Testing\Process;

// Unix SDK 制备入口：登记本轮构建的归档、目标头文件和许可原文。
// 不能用清单生成或归档后缀代替最终应用的静态加载及业务验收。
if (($argc !== 3 && $argc !== 4) || !in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true) || PHP_VERSION !== '8.5.10' || !PHP_ZTS
    || (PHP_OS_FAMILY === 'Darwin' && php_uname('m') !== 'arm64')
    || (PHP_OS_FAMILY === 'Linux' && !in_array(php_uname('m'), ['x86_64', 'aarch64'], true))) {
    throw new InvalidArgumentException('用法：锁定 PHP 8.5.10 ZTS static-runtime-manifest.php <制备工作目录> <PostgreSQL静态SDK根目录> [sqlite|mysql|pgsql|all]');
}
$root = dirname(__DIR__);
$work = BuildPlatform::resolve($argv[1]);
$pgsql = BuildPlatform::resolve($argv[2]);
$profile = $argv[3] ?? 'all';
if (!in_array($profile, ['sqlite', 'mysql', 'pgsql', 'all'], true)) {
    throw new InvalidArgumentException('静态 SDK profile 无效：' . $profile);
}
$featureResult = (new Process([PHP_BINARY, $root . '/tools/build-profile.php', $profile]))->wait(10);
if (!$featureResult->successful()) {
    throw new RuntimeException('无法核对 SDK 功能闭包：' . $featureResult->stderr);
}
$features = explode(',', trim($featureResult->stdout));
$redisEnabled = in_array('redis', $features, true);
$intlEnabled = in_array('intl', $features, true);
$sdk = $work . '/sdk';
$php = $work . '/src/php-8.5.10';
$phpx = $work . '/src/phpx';
$materials = $root . '/plugin/type-build/resources/swoole/LICENSES';
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
    // 仅清理任务 SDK 副本中的调试信息，保留重链接必需的全局符号。
    $strip = PHP_OS_FAMILY === 'Darwin' ? ['/usr/bin/strip', '-S', $destination] : ['strip', '--strip-debug', $destination];
    $result = (new Process($strip))->wait(120);
    if (!$result->successful()) {
        throw new RuntimeException('静态归档调试信息清理失败：' . $result->stderr);
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
if ($redisEnabled) {
    $documents[] = staticSdkDocument($sdk, $php . '/ext/redis/LICENSE', 'phpredis/LICENSE');
}
foreach (['LICENSE', 'thirdparty/nlohmann/LICENSE.MIT', 'thirdparty/php/LICENSE', 'thirdparty/php/ssh2/LICENSE',
    'thirdparty/hiredis/COPYING', 'thirdparty/boost/asm/LICENSE', 'thirdparty/nghttp2/COPYING',
    'thirdparty/nghttp2/LICENSE', 'thirdparty/llhttp/LICENSE-MIT', 'thirdparty/llhttp/LICENSE'] as $name) {
    $documents[] = staticSdkDocument($sdk, $php . '/ext/swoole/' . $name, 'swoole/' . $name);
}
$notices['libphp.a'] = ['component' => $redisEnabled ? 'PHP、Swoole、phpredis 及随附代码' : 'PHP、Swoole 及随附代码',
    'version' => $redisEnabled ? 'PHP 8.5.10; Swoole 6.3.0RC1; phpredis 6.3.0' : 'PHP 8.5.10; Swoole 6.3.0RC1',
    'license' => ['PHP-3.01', 'BSD-3-Clause', 'BSD-2-Clause', 'MIT', 'Apache-2.0', 'BSL-1.0'], 'files' => $documents];
$documents = [];
foreach (['LICENSE', 'thirdparty/mpdecimal/COPYRIGHT.txt', 'thirdparty/wren-gc/LICENSE'] as $name) {
    $documents[] = staticSdkDocument($sdk, $phpx . '/' . $name, 'phpx/' . $name);
}
$notices['libphpx.a'] = ['component' => 'PHPX、mpdecimal、wren-gc', 'version' => 'PHPX 2.9.3',
    'license' => ['Apache-2.0', 'BSD-2-Clause', 'MIT'], 'files' => $documents];

$formulas = [
    'gmp' => [['libgmpxx.a', 'libgmp.a'], 'LGPL-3.0-or-later OR GPL-2.0-or-later', ['COPYING', 'COPYING.LESSERv3']],
    'mpfr' => [['libmpfr.a'], 'LGPL-3.0-or-later', ['COPYING', 'COPYING.LESSER']],
    'openssl@3' => [['libssl.a', 'libcrypto.a'], 'Apache-2.0', ['LICENSE.txt']],
    'c-ares' => [['libcares.a'], 'MIT', ['LICENSE.md']],
    'brotli' => [['libbrotlienc.a', 'libbrotlidec.a', 'libbrotlicommon.a'], 'MIT', ['LICENSE']],
    'sqlite' => [['libsqlite3.a'], 'public-domain', []],
];
if ($profile !== 'all' && $profile !== 'sqlite') {
    unset($formulas['sqlite']);
}
foreach (PHP_OS_FAMILY === 'Darwin' ? $formulas : [] as $formula => [$names, $license, $files]) {
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
        // SQLite 随实际 SDK 安装的公开头文件包含原始 public-domain 声明。
        // 保留整份原文及摘要，不能给新归档沿用旧版本 NOTICE。
        $sqliteHeader = (string) file_get_contents($prefix . '/include/sqlite3.h');
        if (!str_contains(substr($sqliteHeader, 0, 2048), 'disclaims copyright to this source code')
            || !str_contains($sqliteHeader, '#define SQLITE_VERSION        "' . preg_replace('/_\d+$/', '', basename($prefix)) . '"')) {
            throw new RuntimeException('SQLite SDK 版本或许可原文需要重新核对');
        }
        $documents[] = staticSdkDocument($sdk, $prefix . '/include/sqlite3.h', 'sqlite/sqlite3.h');
    }
    foreach ($names as $name) {
        $archives[] = staticSdkArchive($sdk, $prefix . '/lib/' . $name);
        $notices[$name] = ['component' => $formula, 'version' => basename($prefix), 'license' => $license, 'files' => $documents];
    }
}
if (PHP_OS_FAMILY === 'Linux') {
    $machine = (new Process(['gcc', '-print-multiarch']))->wait(10);
    $triplet = trim($machine->stdout);
    if (!$machine->successful() || !in_array($triplet, ['x86_64-linux-gnu', 'aarch64-linux-gnu'], true)) {
        throw new RuntimeException('无法定位 Linux 原生归档目录');
    }
    // 使用准确归档，不传 -l 名称；Debian/Ubuntu 的实际包版本与许可原文随输入登记。
    $packages = [
        'libgmp-dev' => [['libgmpxx.a', 'libgmp.a'], 'LGPL-3.0-or-later OR GPL-2.0-or-later'],
        'libmpfr-dev' => [['libmpfr.a'], 'LGPL-3.0-or-later'],
        'libssl-dev' => [['libssl.a', 'libcrypto.a'], 'Apache-2.0'],
        'libbrotli-dev' => [['libbrotlienc.a', 'libbrotlidec.a', 'libbrotlicommon.a'], 'MIT'],
        'libsqlite3-dev' => [['libsqlite3.a'], 'public-domain'],
        'zlib1g-dev' => [['libz.a'], 'Zlib'],
        'libnghttp2-dev' => [['libnghttp2.a'], 'MIT'],
    ];
    if ($intlEnabled) {
        $packages['libicu-dev'] = [['libicuuc.a', 'libicudata.a'], 'MIT'];
    }
    if ($profile !== 'all' && $profile !== 'sqlite') {
        unset($packages['libsqlite3-dev']);
    }
    $common = [];
    foreach (['Apache-2.0', 'GPL-2', 'GPL-3', 'LGPL-2', 'LGPL-2.1', 'LGPL-3'] as $name) {
        $common[] = staticSdkDocument($sdk, '/usr/share/common-licenses/' . $name, 'debian/common/' . $name);
    }
    foreach ($packages as $package => [$names, $license]) {
        $query = (new Process(['dpkg-query', '-W', '-f=${Version}', $package]))->wait(10);
        if (!$query->successful() || trim($query->stdout) === '') {
            throw new RuntimeException('缺少 Linux 静态依赖包：' . $package);
        }
        $version = trim($query->stdout);
        // 发行版文档目录可能是同源码包的符号链接；先解析真实普通文件再作字节校验。
        $copyright = realpath('/usr/share/doc/' . $package . '/copyright');
        if ($copyright === false) {
            throw new RuntimeException('缺少 Linux 静态依赖许可：' . $package);
        }
        $document = staticSdkDocument($sdk, $copyright, 'debian/' . $package . '/copyright');
        $dependencies[$package] = ['version' => $version, 'copyright-sha256' => $document['sha256']];
        foreach ($names as $name) {
            $archives[] = staticSdkArchive($sdk, '/usr/lib/' . $triplet . '/' . $name);
            $notices[$name] = ['component' => $package, 'version' => $version, 'license' => $license, 'files' => [$document, ...$common]];
        }
    }
    $archives[] = staticSdkArchive($sdk, $pgsql . '/lib/libcurl.a');
    $notices['libcurl.a'] = ['component' => 'curl', 'version' => '8.22.0', 'license' => 'curl',
        'files' => [staticSdkDocument($sdk, $work . '/src/curl-8.22.0/COPYING', 'curl/COPYING')]];
    $archives[] = staticSdkArchive($sdk, $pgsql . '/lib/libcares.a');
    $notices['libcares.a'] = ['component' => 'c-ares', 'version' => '1.34.8', 'license' => 'MIT',
        'files' => [staticSdkDocument($sdk, $work . '/src/c-ares-1.34.8/LICENSE.md', 'c-ares/LICENSE.md')]];
}
if ($profile === 'all' || $profile === 'pgsql') {
    foreach (['libpq.a', 'libpgcommon_shlib.a', 'libpgport_shlib.a'] as $name) {
        $archives[] = staticSdkArchive($sdk, $pgsql . '/lib/' . $name);
        $notices[$name] = ['component' => 'PostgreSQL client', 'version' => '17.11', 'license' => 'PostgreSQL',
            'files' => [staticSdkDocument($sdk, PHP_OS_FAMILY === 'Linux' ? $work . '/src/postgresql-17.11/COPYRIGHT' : $materials . '/postgresql/COPYRIGHT', 'postgresql/COPYRIGHT')]];
    }
}
$archiveTargets = [];
foreach (PHP_OS_FAMILY === 'Darwin' ? $archives : [] as $archive) {
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
$manifest = ['protocol' => 1, 'profile' => $profile, 'php' => PHP_VERSION, 'zts' => (bool) PHP_ZTS, 'debug' => (bool) PHP_DEBUG,
    'integer-size' => PHP_INT_SIZE, 'os' => PHP_OS_FAMILY, 'architecture' => php_uname('m'),
    'archives' => $archives, 'headers' => array_values($headers), 'notices' => $notices, 'patches' => [],
    'sources' => [
        'php' => ['version' => '8.5.10', 'archive-sha256' => '6a8bebaa4d5a979a38db29a9373e9851f60c6b11f72172c585947e78f3081957'],
        'swoole' => StaticRuntimeSdk::swooleSource(),
        'phpx' => ['reference' => InstalledVersions::getReference('swoole/phpx')],
    ], 'dependency-inputs' => $dependencies, 'features' => $features,
    'preparation' => ['script-sha256' => hash_file('sha256', __DIR__ . (PHP_OS_FAMILY === 'Darwin' ? '/prepare-static-macos.sh' : '/prepare-static-linux.sh')), 'manifest-script-sha256' => hash_file('sha256', __FILE__),
        'php-header-patch-sha256' => hash_file('sha256', __DIR__ . '/php-hash-cxx.patch'), 'minimum-macos' => $minimum,
        'archive-minimum-macos' => $archiveTargets],
];
if (PHP_OS_FAMILY === 'Linux') {
    unset($manifest['preparation']['minimum-macos'], $manifest['preparation']['archive-minimum-macos']);
    $manifest['sources']['curl'] = ['version' => '8.22.0', 'archive-sha256' => 'f7ef3ae8a22e521f289803fe93543eb64c329b58aa73a9e224dfd915a2a5f4f7'];
    $manifest['sources']['c-ares'] = ['version' => '1.34.8', 'archive-sha256' => 'c222b6d681096f9444d2c4863d2c1174019e27cacca0a4a5c114d36dd7d7bf78'];
}
if ($redisEnabled) {
    $manifest['sources']['redis'] = ['version' => '6.3.0', 'archive-sha256' => '0d5141f634bd1db6c1ddcda053d25ecf2c4fc1c395430d534fd3f8d51dd7f0b5'];
}
if (is_dir($work . '/src/postgresql-17.11')) {
    $manifest['sources']['postgresql'] = ['version' => '17.11', 'archive-sha256' => 'dd27f2b3c59e73ed14aa3324901242bf69a032a6347805f274e6260322d42979'];
}
foreach (['SwooleThreadSource', 'SwooleHttpSource', 'SwooleSocketSource', 'SwooleStaticSource', 'PhpxThreadSource'] as $patch) {
    $manifest['patches'][$patch] = hash_file('sha256', $root . '/plugin/type-build/src/' . $patch . '.php');
}
$path = $sdk . '/manifest.json';
file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$validated = new StaticRuntimeSdk($path);
$libraries = array_map(static fn (string $file): array => ['name' => basename($file), 'path' => $file, 'sha256' => hash_file('sha256', $file)], $validated->archives());
(new DependencyNotices())->collect($work . '/notice-verification', [], $libraries, ['native' => [PHP_OS_FAMILY => $validated->notices()], 'require-complete' => true]);
echo '静态 SDK 输入和许可材料已登记；需继续完成真实应用验收。' . PHP_EOL;
