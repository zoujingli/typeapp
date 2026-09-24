<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\ArtifactManifest;
use Type\Build\BuildEnvironment;
use Type\Build\BundledSwoole;
use Type\Build\RuntimeProfile;

$root = dirname(__DIR__);
$phpHome = getenv('PHP_HOME') ?: '';
$phpxHome = getenv('PHPX_HOME') ?: '';
expect($phpHome !== '' && $phpxHome !== '', '运行探针测试需要对应原生SDK');
$base = $root . '/build/runtime-profile-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700, true), '无法创建本轮运行探针目录');
$profile = new RuntimeProfile();
$first = $profile->prepare($root, $base . '/base', $phpHome, $phpxHome, ['json'], [PHP_OS_FAMILY => ['functions' => ['json_encode']]]);
expect($first['module-files'] === [] && isset($first['extensions']['json']), '实际embed内置扩展被错误当作共享库');
$firstHashes = array_map(static fn (string $file): string => hash_file('sha256', $file), $first['files']);
$repeat = $profile->prepare($root, $base . '/base', $phpHome, $phpxHome, ['json'], [PHP_OS_FAMILY => ['functions' => ['json_encode']]]);
expect($repeat === $first, '同一运行配置的探测不稳定');
expect(array_map(static fn (string $file): string => hash_file('sha256', $file), $repeat['files']) === $firstHashes, '重复探针生成改变了身份输入字节');
$rejected = false;
try {
    $profile->prepare($root, $base . '/missing-function', $phpHome, $phpxHome, [], [PHP_OS_FAMILY => ['functions' => ['type_profile_not_a_real_function']]]);
} catch (RuntimeException $error) {
    $rejected = str_contains($error->getMessage(), 'type_profile_not_a_real_function');
}
expect($rejected, '缺失运行函数没有在编译前明确失败');
$manifest = ['runtime' => ['php' => PHP_VERSION, 'zts' => (bool) PHP_ZTS, 'architecture' => php_uname('m'), 'os' => PHP_OS_FAMILY,
    'extensions' => [], 'functions' => ['type_profile_not_a_real_function']], 'native-libraries' => []];
$rejected = false;
try {
    (new ArtifactManifest())->verifyRuntime($manifest);
} catch (RuntimeException $error) {
    $rejected = str_contains($error->getMessage(), '声明函数');
}
expect($rejected, '运行验证忽略了构建时声明的函数');
// 成功退出但写出启动警告，也不能作为扩展加载成功。
$environment = (new BuildEnvironment())->environment($phpHome, $phpxHome);
$rejected = false;
try {
    (new BuildEnvironment())->run([PHP_BINARY, '-r', 'fwrite(STDERR,"profile-startup-warning");echo "ok";'], $root, $environment, 10, null, true);
} catch (RuntimeException $error) {
    $rejected = str_contains($error->getMessage(), 'profile-startup-warning');
}
expect($rejected, '探针的成功退出掩盖了启动警告');
$checks = ['real-embed', 'builtin-not-loaded-twice', 'deterministic', 'required-function-rejection', 'runtime-function-gate', 'warning-rejection'];
$swooleRuntime = [];
$swooleModule = getenv('TYPE_SWOOLE_MODULE');
if (is_string($swooleModule) && $swooleModule !== '') {
    expect(is_file($swooleModule), '指定的 Swoole 模块不存在');
    $swooleRuntime[PHP_OS_FAMILY]['modules']['swoole'] = ['file' => $swooleModule, 'sha256' => hash_file('sha256', $swooleModule)];
}
$swoole = $profile->prepare($root, $base . '/swoole', $phpHome, $phpxHome, ['swoole'], $swooleRuntime);
expect(array_key_exists('curl', $swoole['extensions']), 'Swoole运行配置必须同时包含curl扩展');
if (isset($swoole['module-files']['curl'], $swoole['module-files']['swoole'])) {
    $iniText = (string) file_get_contents($swoole['ini']);
    expect(
        strpos($iniText, $swoole['module-files']['curl']) < strpos($iniText, $swoole['module-files']['swoole']),
        'curl必须在Swoole之前加载'
    );
}
foreach ((new ReflectionExtension('swoole'))->getDependencies() as $dependency => $kind) {
    if (str_starts_with($kind, 'Required')) {
        expect(array_key_exists(strtolower($dependency), $swoole['extensions']), 'Swoole 必需扩展未纳入原生产物身份：' . $dependency);
    }
}
$checks[] = 'swoole-required-extension-dependencies';
// 模块加载成功仍可能缺少延迟绑定符号；实际走连接失败路径，必须得到 PDO 异常而非崩溃。
$pgsql = $profile->prepare($root, $base . '/pgsql-rejection', $phpHome, $phpxHome, ['swoole', 'pdo_pgsql'], $swooleRuntime);
$rejection = <<<'PHP'
try {
    new PDO('pgsql:host=127.0.0.1;port=0;dbname=postgres;connect_timeout=1', 'probe', 'probe');
    exit(1);
} catch (PDOException $error) {
    if (($error->errorInfo[0] ?? null) !== '08006') {
        throw $error;
    }
    echo 'pgsql-connection-rejected';
}
PHP;
$rejectionOutput = (new BuildEnvironment())->run([PHP_BINARY, '-c', $pgsql['ini'], '-r', $rejection], $root, $environment, 10, null, true);
expect($rejectionOutput === 'pgsql-connection-rejected', 'PostgreSQL 连接拒绝路径没有正常返回');
$checks[] = 'pgsql-connection-rejection-without-crash';
// 在真实 embed 上分别观察默认、环境候选和显式声明的优先级。
$bundled = (new BundledSwoole())->select($root);
if ($bundled !== null && isset($swoole['module-files']['swoole'])) {
    $previous = getenv('TYPE_SWOOLE_MODULE');
    try {
        putenv('TYPE_SWOOLE_MODULE');
        $default = $profile->prepare($root, $base . '/bundled-default', $phpHome, $phpxHome, ['swoole']);
        expect($default['module-files']['swoole'] === $bundled['file'], '默认构建没有选择项目内置 Swoole');
        expect(in_array($bundled['manifest'], $default['files'], true), '内置清单没有纳入构建身份');
        $copy = $base . '/swoole.' . (PHP_OS_FAMILY === 'Windows' ? 'dll' : 'so');
        expect(copy($bundled['file'], $copy), '无法准备模块选择对照');
        putenv('TYPE_SWOOLE_MODULE=' . $copy);
        $environmentSelection = $profile->prepare($root, $base . '/bundled-environment', $phpHome, $phpxHome, ['swoole']);
        expect($environmentSelection['module-files']['swoole'] === $copy, '显式环境模块没有优先于内置模块');
        $declared = $profile->prepare($root, $base . '/bundled-declaration', $phpHome, $phpxHome, ['swoole'], [
            PHP_OS_FAMILY => ['modules' => ['swoole' => ['file' => $bundled['file'], 'sha256' => $bundled['sha256']]]],
        ]);
        expect($declared['module-files']['swoole'] === $bundled['file'], 'runtime.modules 没有优先于环境模块');
        $checks[] = 'bundled-default-and-explicit-priority';
    } finally {
        putenv($previous === false ? 'TYPE_SWOOLE_MODULE' : 'TYPE_SWOOLE_MODULE=' . $previous);
    }
}
$otherPlatform = PHP_OS_FAMILY === 'Linux' ? 'Darwin' : 'Linux';
$skipped = $profile->prepare($root, $base . '/inactive', $phpHome, $phpxHome, ['json'], [
    $otherPlatform => ['modules' => ['pcntl' => ['file' => '/not-present/inactive-platform.so', 'sha256' => str_repeat('0', 64)]]],
    PHP_OS_FAMILY => ['modules' => ['json' => ['file' => '/not-present/unneeded-builtin-fallback.so', 'sha256' => str_repeat('0', 64)]]],
]);
expect($skipped['module-files'] === [], '非当前平台或内置扩展的候选被不必要地加载');
$checks[] = 'inactive-platform-and-builtin-fallback-not-read';
if (PHP_OS_FAMILY === 'Linux') {
    $module = getenv('TYPE_TEST_PCNTL_MODULE') ?: $root . '/.cache/embed-runtime/8.5.10/pcntl.so';
    expect(is_file($module), 'Linux对照需要已经按锁定SDK编译的共享PCNTL');
    $definition = ['Linux' => ['extensions' => ['pcntl'], 'functions' => ['pcntl_signal', 'pcntl_async_signals', 'pcntl_fork'],
        'modules' => ['pcntl' => ['file' => $module, 'sha256' => hash_file('sha256', $module)]]]];
    $resolved = $profile->prepare($root, $base . '/pcntl', $phpHome, $phpxHome, ['json'], $definition);
    expect($resolved['extensions']['pcntl'] === '8.5.10', '实际embed PCNTL版本不符');
    $inventory = json_decode(file_get_contents($base . '/pcntl/profile.json'), true, 512, JSON_THROW_ON_ERROR);
    if (!isset($inventory['base-extensions']['pcntl'])) {
        expect(isset($resolved['module-files']['pcntl']), '未内置PCNTL的embed没有选择共享模块');
        $bad = $definition;
        $bad['Linux']['modules']['pcntl']['sha256'] = str_repeat('0', 64);
        $rejected = false;
        try {
            $profile->prepare($root, $base . '/bad-digest', $phpHome, $phpxHome, [], $bad);
        } catch (RuntimeException $error) {
            $rejected = str_contains($error->getMessage(), '摘要');
        }
        expect($rejected, '运行模块候选没有按准确摘要拒绝');
        $wrongModule = $definition;
        $wrongModule['Linux']['modules']['pcntl'] = ['file' => $phpHome . '/lib/libphp.so', 'sha256' => hash_file('sha256', $phpHome . '/lib/libphp.so')];
        $rejected = false;
        try {
            $profile->prepare($root, $base . '/not-an-extension', $phpHome, $phpxHome, [], $wrongModule);
        } catch (RuntimeException) {
            $rejected = true;
        }
        expect($rejected, '同平台但不是正确PHP扩展的库被视为可用');
    }
    $checks[] = 'pcntl-embed-abi-and-functions';
    $checks[] = 'selected-module-sha256';
}
file_put_contents($base . '/verification.json', json_encode(['platform' => PHP_OS_FAMILY, 'php' => PHP_VERSION, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
echo '真实embed、模块选择、ABI、函数与警告拒绝通过：' . $base . "/verification.json\n";
