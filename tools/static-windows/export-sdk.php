<?php

declare(strict_types=1);

// 仅导出本轮已实际链接并独立运行的归档；不把构建宿主 DLL 混入目标 SDK。
if (($argc !== 4 && $argc !== 5) || PHP_OS_FAMILY !== 'Windows' || PHP_VERSION !== '8.5.10' || !PHP_ZTS) {
    throw new InvalidArgumentException('需要 Windows PHP 8.5.10 ZTS、运行库工作目录、依赖目录、依赖验证报告和可选 profile');
}
$root = dirname(__DIR__, 2);
$work = str_replace('\\', '/', (string) realpath($argv[1]));
$dependencies = str_replace('\\', '/', (string) realpath($argv[2]));
$sdk = $work . '/sdk';
$php = $work . '/php-8.5.10';
$phpx = $work . '/phpx-0dfa613d2057dcd4aa319ec9b6816f68df2403e4';
if ($work === '' || $dependencies === '' || file_exists($sdk) || is_link($sdk)) {
    throw new RuntimeException('SDK 导出需要有效的任务输入和未使用的目标目录');
}
$runtime = json_decode((string) file_get_contents($work . '/evidence/verification.json'), true, 64, JSON_THROW_ON_ERROR);
$values = json_decode((string) file_get_contents($work . '/phpx-static/evidence/verification.json'), true, 64, JSON_THROW_ON_ERROR);
$dependencyReport = json_decode((string) file_get_contents($argv[3]), true, 64, JSON_THROW_ON_ERROR);
$profile = $argv[4] ?? 'all';
if (!in_array($profile, ['sqlite', 'mysql', 'pgsql', 'all'], true)) {
    throw new InvalidArgumentException('Windows 静态 SDK profile 无效：' . $profile);
}
require_once $root . '/plugin/type-build/src/BuildProfile.php';
$configuration = getenv('TYPEAPP_BUILD_CONFIGURATION') ?: $root . '/docs/build-config/type-app.json';
$profiles = \Type\Build\BuildProfile::resolve(json_decode((string) file_get_contents($configuration), true, 64, JSON_THROW_ON_ERROR));
$features = $profile === 'all' ? array_values(array_unique(array_merge(...array_column($profiles['profiles'], 'features'))))
    : ($profiles['profiles'][$profile]['features'] ?? throw new RuntimeException('未知 SDK profile'));
sort($features);
if (($runtime['profile'] ?? null) !== $profile || ($values['profile'] ?? null) !== $profile || ($runtime['features'] ?? null) !== $features) {
    throw new RuntimeException('探针 profile 或功能闭包与导出身份不一致');
}
$redisEnabled = in_array('redis', $features, true);
if (($runtime['passed'] ?? false) !== true || ($values['passed'] ?? false) !== true
    || ($dependencyReport['passed'] ?? false) !== true || $runtime['php'] !== PHP_VERSION || $runtime['zts'] !== true
    || $dependencyReport['triplet'] !== 'x64-typeapp-static') {
    throw new RuntimeException('完整静态运行库和 PHPX 必须先通过本轮原生探针');
}

/** 复制普通文件并逐字节验证；目标路径全部来自脚本或已审计的 SDK 相对路径。 */
function exportSdkFile(string $source, string $sdk, string $relative): array
{
    if (!is_file($source) || is_link($source) || str_contains($relative, '..')
        || preg_match('~^[a-zA-Z0-9_+./-]+$~D', $relative) !== 1 || str_starts_with($relative, '/')) {
        throw new RuntimeException('静态 SDK 输入不是普通文件或路径无效：' . $relative);
    }
    $target = $sdk . '/' . $relative;
    if (file_exists($target) || (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true))
        || !copy($source, $target) || hash_file('sha256', $source) !== hash_file('sha256', $target)) {
        throw new RuntimeException('静态 SDK 文件重复或复制校验失败：' . $relative);
    }
    return ['file' => $relative, 'sha256' => hash_file('sha256', $target)];
}

/** 头文件保持原有层级；拒绝目录链接，避免构建输入跟随到任务树外。 */
function exportSdkHeaders(string $source, string $sdk, string $prefix): array
{
    $headers = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $entry) {
        if ($entry->isLink()) {
            throw new RuntimeException('静态 SDK 头文件不能经过符号链接');
        }
        if ($entry->isFile() && preg_match('/\.(?:h|hh|hpp|inl|inc)$/D', $entry->getFilename()) === 1) {
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($source) + 1));
            $headers[] = exportSdkFile($entry->getPathname(), $sdk, $prefix . '/' . $relative);
        }
    }
    return $headers;
}

$archives = [];
$locations = [
    'typeapp-static.lib' => $php . '/x64/Release_TS/typeapp-static.lib',
    'phpx.lib' => $work . '/phpx-static/build/lib/Release/phpx.lib',
    'libmpdec-4.0.1.lib' => $phpx . '/thirdparty/mpdecimal/libmpdec/libmpdec-4.0.1.lib',
    'libmpdec++-4.0.1.lib' => $phpx . '/thirdparty/mpdecimal/libmpdec++/libmpdec++-4.0.1.lib',
];
foreach ($dependencyReport['libraries'] as $library) {
    if (preg_match('/^[a-zA-Z0-9_+.-]+\.lib$/D', $library['file']) !== 1 || isset($locations[$library['file']])) {
        throw new RuntimeException('静态依赖归档名称无效或重复');
    }
    if (($profile !== 'pgsql' && $profile !== 'all') && preg_match('/(?i)(?:^|[-_])(?:lib)?pq(?:[-_.]|$)|pgcommon|pgport/', $library['file'])) {
        continue;
    }
    if (($profile !== 'sqlite' && $profile !== 'all') && preg_match('/(?i)sqlite3/', $library['file'])) {
        continue;
    }
    $locations[$library['file']] = $dependencies . '/lib/' . $library['file'];
}
$verified = array_column($values['archives'], 'sha256', 'file');
if (count($verified) !== count($locations) || array_diff_key($locations, $verified) !== []) {
    throw new RuntimeException('导出归档与实际 PHPX 链接输入不一致');
}
foreach ($locations as $name => $source) {
    if (file_get_contents($source, false, null, 0, 8) !== "!<arch>\n" || hash_file('sha256', $source) !== $verified[$name]) {
        throw new RuntimeException('已验证的静态归档发生变化：' . $name);
    }
    $archives[] = exportSdkFile($source, $sdk, 'lib/' . $name);
}
$headers = exportSdkHeaders($php, $sdk, 'include/php');
array_push($headers, ...exportSdkHeaders($phpx . '/include', $sdk, 'include/phpx'));
array_push($headers, ...exportSdkHeaders($phpx . '/src/misc', $sdk, 'include/phpx/misc'));
array_push($headers, ...exportSdkHeaders($dependencies . '/include', $sdk, 'include/dependencies'));
array_push($headers, ...exportSdkHeaders($phpx . '/thirdparty/wren-gc/include', $sdk, 'include/dependencies'));
$headers[] = exportSdkFile($phpx . '/thirdparty/mpdecimal/libmpdec/mpdecimal.h', $sdk, 'include/dependencies/mpdecimal.h');
$headers[] = exportSdkFile($phpx . '/thirdparty/mpdecimal/libmpdec++/decimal.hh', $sdk, 'include/dependencies/decimal.hh');
usort($headers, static fn (array $left, array $right): int => strcmp($left['file'], $right['file']));

$documents = [];
foreach (['LICENSE', 'TSRM/LICENSE', 'Zend/LICENSE', 'Zend/asm/LICENSE', 'ext/date/lib/LICENSE.rst',
    'ext/opcache/jit/ir/LICENSE', 'ext/mbstring/libmbfl/LICENSE', 'ext/standard/libavifinfo/LICENSE',
    'ext/lexbor/LICENSE', 'ext/uri/uriparser/COPYING.BSD-3-Clause', 'ext/pcre/pcre2lib/pcre2.h'] as $name) {
    $documents[] = exportSdkFile($php . '/' . $name, $sdk, 'licenses/php/' . $name);
}
if ($redisEnabled) {
    $documents[] = exportSdkFile($php . '/ext/redis/LICENSE', $sdk, 'licenses/phpredis/LICENSE');
}
foreach (['LICENSE', 'thirdparty/nlohmann/LICENSE.MIT', 'thirdparty/php/LICENSE', 'thirdparty/php/ssh2/LICENSE',
    'thirdparty/hiredis/COPYING', 'thirdparty/boost/asm/LICENSE', 'thirdparty/nghttp2/COPYING',
    'thirdparty/nghttp2/LICENSE', 'thirdparty/llhttp/LICENSE-MIT', 'thirdparty/llhttp/LICENSE'] as $name) {
    $documents[] = exportSdkFile($php . '/ext/swoole/' . $name, $sdk, 'licenses/swoole/' . $name);
}
$notices = ['typeapp-static.lib' => ['component' => $redisEnabled ? 'PHP、Swoole、phpredis 及随附代码' : 'PHP、Swoole 及随附代码',
    'version' => $redisEnabled ? 'PHP 8.5.10; Swoole 6.2.1; phpredis 6.3.0' : 'PHP 8.5.10; Swoole 6.2.1',
    'license' => ['PHP-3.01', 'BSD-3-Clause', 'BSD-2-Clause', 'MIT', 'Apache-2.0', 'BSL-1.0'], 'files' => $documents]];
$phpxLicense = exportSdkFile($phpx . '/LICENSE', $sdk, 'licenses/phpx/LICENSE');
$gcLicense = exportSdkFile($phpx . '/thirdparty/wren-gc/LICENSE', $sdk, 'licenses/phpx/wren-gc/LICENSE');
$decimalLicense = exportSdkFile($phpx . '/thirdparty/mpdecimal/COPYRIGHT.txt', $sdk, 'licenses/phpx/mpdecimal/COPYRIGHT.txt');
$notices['phpx.lib'] = ['component' => 'PHPX、wren-gc', 'version' => 'PHPX 2.9.2',
    'license' => ['Apache-2.0', 'MIT'], 'files' => [$phpxLicense, $gcLicense]];
foreach (['libmpdec-4.0.1.lib', 'libmpdec++-4.0.1.lib'] as $name) {
    $notices[$name] = ['component' => 'mpdecimal', 'version' => '4.0.1', 'license' => 'BSD-2-Clause', 'files' => [$decimalLicense]];
}
// vcpkg 的安装文件清单给出真实归档归属，SPDX 材料给出本次版本和许可声明。
foreach (glob(dirname($dependencies) . '/vcpkg/info/*_x64-typeapp-static.list') ?: [] as $list) {
    $owned = [];
    foreach (file($list, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('~^x64-typeapp-static/lib/([a-zA-Z0-9_+.-]+\.lib)$~D', $line, $match) === 1) {
            $owned[] = $match[1];
        }
    }
    if ($owned === []) {
        continue;
    }
    $package = explode('_', basename($list), 2)[0];
    $spdx = json_decode((string) file_get_contents($dependencies . '/share/' . $package . '/vcpkg.spdx.json'), true, 64, JSON_THROW_ON_ERROR);
    $metadata = $spdx['packages'][0];
    if ($metadata['name'] !== $package || !is_string($metadata['versionInfo'] ?? null)) {
        throw new RuntimeException('依赖归档来源元数据不一致');
    }
    $license = $metadata['licenseConcluded'];
    // libiconv 的 vcpkg 端口没有填写 SPDX 表达式，保留实际 LGPL 原文并明确许可。
    if ($package === 'libiconv') {
        $license = 'LGPL-2.1-or-later';
    } elseif ($license === 'NOASSERTION' || str_starts_with($license, 'LicenseRef-')) {
        throw new RuntimeException('静态依赖许可尚未明确：' . $package);
    }
    $document = exportSdkFile($dependencies . '/share/' . $package . '/copyright', $sdk, 'licenses/vcpkg/' . $package . '/copyright');
    foreach ($owned as $name) {
        if (!isset($locations[$name])) {
            continue;
        }
        if (isset($notices[$name])) {
            throw new RuntimeException('依赖许可引用了未知归档或归属重复：' . $name);
        }
        $notices[$name] = ['component' => $package, 'version' => $metadata['versionInfo'], 'license' => $license, 'files' => [$document]];
    }
}
if (array_diff_key($locations, $notices) !== []) {
    throw new RuntimeException('静态运行库尚有缺少许可材料的归档');
}
$manifest = ['protocol' => 1, 'profile' => $profile, 'features' => $features, 'php' => PHP_VERSION, 'zts' => (bool) PHP_ZTS, 'debug' => (bool) PHP_DEBUG,
    'integer-size' => PHP_INT_SIZE, 'os' => PHP_OS_FAMILY, 'architecture' => php_uname('m'),
    'archives' => $archives, 'headers' => $headers, 'notices' => $notices, 'patches' => [],
    'preparation' => ['compiler' => 'MSVC x64', 'crt' => 'static', 'dependency-source' => $dependencyReport['source'],
        'dependency-manifest-sha256' => $dependencyReport['manifest_sha256'], 'dependency-triplet-sha256' => $dependencyReport['triplet_sha256'],
        'runtime-probe-sha256' => $runtime['artifact_sha256'], 'phpx-probe-sha256' => $values['program_sha256']]];
foreach (['SwooleThreadSource', 'SwooleHttpSource', 'SwooleSocketSource', 'SwooleStaticSource', 'SwooleWindowsSource', 'PhpxThreadSource'] as $patch) {
    $manifest['patches'][$patch] = hash_file('sha256', $root . '/plugin/type-build/src/' . $patch . '.php');
}
foreach (['tools/probe-static-windows.ps1', 'tools/static-windows/build-phpx.ps1',
    'tools/static-windows/phpx/CMakeLists.txt', 'tools/static-windows/prepare-extensions.php', 'tools/static-windows/export-sdk.php'] as $script) {
    $manifest['preparation']['scripts'][$script] = hash_file('sha256', $root . '/' . $script);
}
foreach (array_values(array_filter(['php.tar.xz', 'swoole.tar.gz', $redisEnabled ? 'redis.tar.gz' : null, 'phpx.tar.gz'])) as $source) {
    $manifest['sources'][$source] = ['sha256' => hash_file('sha256', $work . '/' . $source)];
}
foreach (['adaptations.json', 'extension-adaptations.json', 'phpx-adaptations.json'] as $report) {
    $manifest['adaptations'][$report] = json_decode((string) file_get_contents($work . '/evidence/' . $report), true, 64, JSON_THROW_ON_ERROR);
}
$encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($sdk . '/manifest.json', $encoded) !== strlen($encoded)) {
    throw new RuntimeException('无法完整写入静态 SDK 身份');
}
foreach (['BuildPlatform', 'BuildLock', 'StaticRuntimeSdk'] as $class) {
    require $root . '/plugin/type-build/src/' . $class . '.php';
}
new Type\Build\StaticRuntimeSdk($sdk . '/manifest.json');
echo 'Windows 静态 SDK 已导出；应用 AOT 与部署尚须独立验收。' . PHP_EOL;
