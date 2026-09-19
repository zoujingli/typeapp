<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\BuildPlatform;
use Type\Build\NativePackage;
use Type\Build\ServiceDefinition;

expect(PHP_OS_FAMILY === 'Linux', 'systemd准备需要真正的Linux工具链');
$root = BuildPlatform::resolve(dirname(__DIR__));
$base = BuildPlatform::resolve($argv[1] ?? '');
$user = $argv[2] ?? '';
$targetBase = $argv[3] ?? $base;
expect(str_starts_with($base, $root . '/build/service-linux-') && dirname($base) === $root . '/build', '只接受本轮专用服务测试目录');
$relative = substr($base, strlen($root) + 1);
$configuration = json_decode(file_get_contents($root . '/docs/build-config/type-app.json'), true, 512, JSON_THROW_ON_ERROR);
$configuration['output'] = $relative . '/type-app';
$configuration['build-directory'] = $relative . '/compiler';
$configuration['cache-directory'] = $relative . '/cache';
$module = getenv('TYPE_TEST_PCNTL_MODULE');
if ($module !== false) {
    expect(is_file($module), '显式测试PCNTL模块不存在');
    $configuration['runtime']['Linux']['modules']['pcntl'] = ['file' => $module, 'sha256' => hash_file('sha256', $module)];
}
$file = $base . '/type.json';
expect(!file_exists($file), '不能覆盖已经准备的服务构建');
file_put_contents($file, json_encode($configuration, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
echo "正在构建systemd验收的完整标准应用。\n";
[$buildStatus, $buildOutput, $buildErrors] = execute([PHP_BINARY, $root . '/vendor/bin/type', 'build', $file], $root);
file_put_contents($base . '/compile.log', $buildOutput . $buildErrors);
expect($buildStatus === 0, 'systemd原生构建失败，完整日志见：' . $base . '/compile.log');
echo "完整应用编译通过，详细输出已保存在本轮compile.log。\n";
$artifact = $root . '/' . $configuration['output'];
$package = (new NativePackage())->create($artifact, $base . '/release', $root . '/.env.example');
expect(mkdir($base . '/data', 0700), '无法创建本轮系统服务数据根');
$specification = ['name' => 'typeappsystemd' . bin2hex(random_bytes(6)), 'release-directory' => $targetBase . '/release',
    'runtime-directory' => $targetBase . '/data', 'user' => $user, 'stop-seconds' => 10, 'restart-seconds' => 1];
$service = (new ServiceDefinition())->create($package['directory'], $base . '/definition', $package['manifest-sha256'], $specification);
$prepared = ['status' => 'prepared-not-runtime-verified', 'artifact-sha256' => hash_file('sha256', $artifact),
    'build-id' => $package['build-id'], 'service-manifest' => $targetBase . '/definition/service.json',
    'service-sha256' => $service['manifest-sha256'], 'release-sha256' => $package['manifest-sha256']];
file_put_contents($base . '/preparation.json', json_encode($prepared, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo json_encode($prepared, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
