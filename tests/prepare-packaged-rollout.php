<?php

declare(strict_types=1);

require __DIR__ . '/build-scenario.php';

use Type\Build\BuildPlatform;
use Type\Build\NativePackage;

$root = BuildPlatform::resolve(dirname(__DIR__));
$identity = $argv[1] ?? bin2hex(random_bytes(6));
expect(count($argv) <= 2 && preg_match('/^[a-f0-9]{12}$/D', $identity) === 1, '可选构建身份必须是12位小写十六进制，不能传入路径');
$base = $root . '/build/packaged-rollout-build-' . $identity;
expect(!file_exists($base) && !is_link($base), '不能覆盖既有双版本构建目录');
expect(mkdir($base, 0700), '无法创建双版本构建目录');
$relative = substr($base, strlen($root) + 1);
$variants = [];
foreach (['old' => '1.0.0', 'new' => '1.1.0'] as $variant => $version) {
    $file = $root . '/docs/build-config/type-rollout-' . $variant . '.json';
    $configuration = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    echo '正在全量编译发布演练 ' . $version . "。\n";
    ob_start();
    try {
        buildScenario($root, $file);
    } finally {
        file_put_contents($base . '/' . $variant . '.log', ob_get_clean());
    }
    $sourceArtifact = (new BuildPlatform())->output($root . '/' . $configuration['output']);
    $artifact = (new BuildPlatform())->output($base . '/' . $variant . '/type-app');
    // 固定本轮产物及资源；后续重建同名场景不会改变已经准备好的发布版本。
    foreach (['.resources', '.build.json', ''] as $suffix) {
        if (file_exists($sourceArtifact . $suffix)) {
            scenarioCopy($sourceArtifact . $suffix, $artifact . $suffix);
        }
    }
    $package = (new NativePackage())->create($artifact, $base . '/' . $variant . '/release');
    $release = (new NativePackage())->verify($package['directory'], $package['manifest-sha256']);
    expect($release['version'] === $version, '发布版本与实际构建不一致');
    $variants[$variant] = ['version' => $version, 'artifact' => substr($artifact, strlen($root) + 1), 'artifact-sha256' => hash_file('sha256', $artifact),
        'release' => $relative . '/' . $variant . '/release', 'release-sha256' => $package['manifest-sha256'], 'build-id' => $package['build-id']];
}
$report = ['status' => 'prepared-not-runtime-verified', 'platform' => PHP_OS_FAMILY, 'variants' => $variants];
file_put_contents($base . '/preparation.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo '双版本原生发布已构建，尚未演练：' . $base . "/preparation.json\n";
