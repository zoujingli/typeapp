<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\RuntimeProfile;

$root = dirname(__DIR__);
$configuration = realpath($argv[1] ?? '');
$name = $argv[2] ?? '';
$module = realpath($argv[3] ?? '');
expect($argc === 4 && in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true) && $configuration !== false
    && $module !== false && preg_match('/^[a-z_][a-z0-9_]*$/D', $name) === 1, '需要Unix SDK配置、扩展名和对应真实共享模块');
expect(extension_loaded($name), 'CLI必须真实具备被验证扩展');
$phpxHome = getenv('PHPX_HOME') ?: '';
expect($phpxHome !== '', '真实embed对照需要PHPX_HOME');
$base = $root . '/build/toolchain-runtime-' . bin2hex(random_bytes(6));
expect(mkdir($base . '/extensions', 0700, true), '无法创建独立SDK模块测试目录');
$sourceDirectory = trim(successful([$configuration, '--extension-dir']));
$originalHash = hash_file('sha256', $module);
$otherModules = [];
foreach (scandir($sourceDirectory) as $filename) {
    if ($filename !== $name . '.so' && str_ends_with($filename, '.so') && is_file($sourceDirectory . '/' . $filename)) {
        $source = realpath($sourceDirectory . '/' . $filename);
        expect(symlink($source, $base . '/extensions/' . $filename), '无法准备其余SDK扩展');
        $otherModules[$filename] = $source;
    }
}
$isolatedConfig = $base . '/php-config';
file_put_contents($isolatedConfig, "#!/bin/sh\ncase \"\$1\" in\n--extension-dir) printf '%s\\n' " . escapeshellarg($base . '/extensions')
    . ";;\n*) exec " . escapeshellarg($configuration) . " \"\$@\";;\nesac\n");
chmod($isolatedConfig, 0700);
$select = [PHP_BINARY, $root . '/tools/configure-toolchain.php', $base . '/sdk', $isolatedConfig];
$initialSdk = trim(successful($select));
$profile = new RuntimeProfile();
$missing = false;
try {
    $profile->prepare($root, $base . '/missing', $initialSdk, $phpxHome, [$name]);
} catch (RuntimeException $failure) {
    $missing = str_contains($failure->getMessage(), '实际embed缺少运行扩展：' . $name);
}
expect($missing, '对照必须复现CLI可用、embed所需共享模块不在SDK目录的场景');
$copy = $base . '/module.so';
expect(copy($module, $copy), '无法准备本轮模块副本');
$selected = trim(successful([...$select, $name . '=' . $copy]));
expect($selected !== $initialSdk && trim(successful([...$select, $name . '=' . $copy])) === $selected, '模块SDK身份不独立或重复选择不稳定');
$extensions = trim(successful([$selected . '/bin/php-config', '--extension-dir']));
expect(str_starts_with($extensions, $selected . '/') && !is_link($extensions . '/' . $name . '.so')
    && hash_file('sha256', $extensions . '/' . $name . '.so') === $originalHash, 'SDK没有保存显式模块的独立副本');
foreach ($otherModules as $filename => $source) {
    expect(!is_link($extensions . '/' . $filename) && hash_file('sha256', $extensions . '/' . $filename) === hash_file('sha256', $source), 'SDK丢失原有共享模块');
}
$identity = json_decode(file_get_contents($selected . '/identity.json'), true, 512, JSON_THROW_ON_ERROR);
expect($identity['extension-files'][$name . '.so']['sha256'] === $originalHash, 'SDK身份遗漏新增模块的字节摘要');
$functions = $name === 'pcntl' ? ['pcntl_signal', 'pcntl_async_signals', 'pcntl_signal_get_handler'] : [];
$actual = $profile->prepare($root, $base . '/resolved', $selected, $phpxHome, [$name], [PHP_OS_FAMILY => ['functions' => $functions]]);
expect($actual['extensions'][$name] === phpversion($name) && $actual['module-sha256'][$name] === $originalHash, '实际embed模块、版本或摘要不匹配');
file_put_contents($base . '/invalid.so', 'not-a-native-module');
foreach (['invalid-name=' . $copy, $name . '=/missing/module.so', $name . '=' . $base . '/invalid.so'] as $invalid) {
    [$status] = execute([...$select, $invalid]);
    expect($status !== 0, 'SDK接受非法模块候选');
}
[$duplicate] = execute([...$select, $name . '=' . $copy, $name . '=' . $copy]);
expect($duplicate !== 0, '重复扩展名没有拒绝');
file_put_contents($copy, 'identity-change', FILE_APPEND);
$changed = trim(successful([...$select, $name . '=' . $copy]));
expect($changed !== $selected, '模块字节改变后仍复用了旧SDK身份');
expect(copy($module, $copy), '无法恢复本轮模块副本');
expect(unlink($extensions . '/' . $name . '.so') && symlink($base . '/invalid.so', $extensions . '/' . $name . '.so'), '无法注入本轮SDK链接篡改');
[$tampered] = execute([...$select, $name . '=' . $copy]);
expect($tampered !== 0, '被替换的SDK扩展链接仍可复用');
expect(hash_file('sha256', $module) === $originalHash && !file_exists($base . '/extensions/' . $name . '.so'), '原SDK或原扩展字节被修改');
file_put_contents($base . '/verification.json', json_encode(['platform' => PHP_OS_FAMILY, 'extension' => $name, 'module-sha256' => $originalHash,
    'checks' => ['cli-only-module-reproduced', 'explicit-sdk-module-view', 'original-extensions-preserved', 'deterministic-module-identity',
        'real-embed-abi-version-and-functions', 'invalid-input-rejected', 'duplicate-rejected', 'module-bytes-change-identity', 'link-tampering-rejected', 'original-sdk-preserved']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'SDK模块衔接与真实embed对照通过：' . $base . "/verification.json\n";
