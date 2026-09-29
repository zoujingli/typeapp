<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\BuildEnvironment;
use Type\Build\RuntimeProfile;
use Type\Build\StaticRuntimeSdk;

// 使用真实 embed 的模块表和 ELF/Mach-O/PE 加载项核对 SDK；不借用宿主 CLI 扩展推断目标能力。
$root = dirname(__DIR__);
expect($argc === 2, '用法：php tests/static-runtime-sdk.php <静态SDK清单>');
$sdk = new StaticRuntimeSdk($argv[1]);
$sdkProfile = $sdk->identity()['profile'] ?? 'all';
expect(is_string($sdkProfile) && in_array($sdkProfile, ['sqlite', 'mysql', 'pgsql', 'all'], true), '静态 SDK 缺少有效 profile');
$databaseExtensions = match ($sdkProfile) {
    'sqlite' => ['pdo_sqlite'],
    'mysql' => ['pdo_mysql'],
    'pgsql' => ['pdo_pgsql'],
    default => ['pdo_mysql', 'pdo_pgsql', 'pdo_sqlite'],
};
$sdkFeatures = $sdk->identity()['features'] ?? [];
expect(is_array($sdkFeatures) && array_filter($sdkFeatures, 'is_string') === $sdkFeatures, '静态 SDK 功能清单无效');
$optionalExtensions = in_array('redis', $sdkFeatures, true) ? ['redis'] : [];
if ($sdkProfile === 'all') {
    array_push($optionalExtensions, 'session', 'tokenizer');
}
$work = $root . '/build/static-sdk-probe-' . bin2hex(random_bytes(6));
expect(mkdir($work, 0700, true), '无法创建 SDK 探针目录');
$profile = (new RuntimeProfile())->prepare(
    $root,
    $work . '/runtime',
    getenv('PHP_HOME') ?: '',
    getenv('PHPX_HOME') ?: '',
    ['ctype', 'curl', 'filter', 'iconv', 'mbstring', 'openssl', 'pdo', ...$databaseExtensions,
        ...$optionalExtensions, 'sockets', 'swoole', ...(PHP_OS_FAMILY === 'Windows' ? [] : ['pcntl'])],
    [],
    $sdk
);
expect($profile['module-files'] === [] && $profile['module-sha256'] === [], '静态 embed 加载了外置扩展');
if ($sdkProfile !== 'all') {
    Type\Build\BuildProfile::assertExtensions(array_keys($profile['extensions']), ['database' => $sdkProfile, 'features' => $sdkFeatures]);
}
$libraries = StaticRuntimeSdk::verifyArtifact($profile['probe'], new BuildEnvironment(), ['PATH' => (string) getenv('PATH')]);
$record = ['platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'),
    'sdk-manifest-sha256' => hash_file('sha256', $sdk->manifestPath()), 'probe-sha256' => hash_file('sha256', $profile['probe']),
    'extensions' => $profile['extensions'], 'module-files' => $profile['module-files'], 'system-libraries' => $libraries];
file_put_contents($work . '/verification.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo '静态 SDK 真实 embed 验收通过：' . $work . "/verification.json\n";
