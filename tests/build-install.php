<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__);
$work = $root . '/build/install-policy-' . bin2hex(random_bytes(5));
expect(mkdir($work, 0755, true), '无法准备隔离安装项目');
$composerBinary = getenv('COMPOSER_BINARY') ?: '';
expect(is_file($composerBinary), '隔离安装验收需要显式COMPOSER_BINARY文件');
$composer = ['name' => 'type-tests/build-install', 'license' => 'Apache-2.0',
    'require' => ['type-tests/build-plugin' => '1.0.0'],
    'repositories' => [['type' => 'path', 'url' => '../../tests/fixtures/build-plugin', 'options' => ['symlink' => false]], ['packagist.org' => false]],
    'config' => ['allow-plugins' => true], 'scripts' => ['post-install-cmd' => '@php -r "file_put_contents(\'script-ran.txt\',\'unexpected\');"']];
file_put_contents($work . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$environment = new Type\Build\BuildEnvironment();
$setupEnvironment = $environment->environment();
$setupEnvironment['COMPOSER_HOME'] = $work . '/setup-composer';
$environment->run([PHP_BINARY, $composerBinary, 'update', '--no-install', '--no-plugins', '--no-scripts', '--no-interaction', '--no-progress'], $work, $setupEnvironment, 60);
$environment->installLocked($work, $composerBinary);
expect(is_file($work . '/vendor/type-tests/build-plugin/src/Activation.php'), '真实 Composer 插件没有被安装');
expect(!is_file($work . '/vendor/type-tests/build-plugin/src/activated.txt') && !is_file($work . '/script-ran.txt'), '隔离安装执行了插件或项目脚本');
$withoutLock = $work . '/without-lock';
mkdir($withoutLock);
copy($work . '/composer.json', $withoutLock . '/composer.json');
$rejected = false;
try {
    $environment->installLocked($withoutLock, $composerBinary);
} catch (RuntimeException $error) {
    $rejected = true;
}
expect($rejected && !is_dir($withoutLock . '/vendor'), '缺少锁文件时隐式解析升级了依赖');
file_put_contents($work . '/auth.json', '{}');
$rejected = false;
try {
    $environment->installLocked($work, $composerBinary);
} catch (RuntimeException $error) {
    $rejected = true;
}
expect($rejected, '隔离安装读取了项目内认证文件');
echo "隔离 Composer 安装、锁文件要求、禁用真实插件和安装脚本通过。\n";
