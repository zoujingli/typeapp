<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Build\NativePackage;
use Type\Testing\Process;

expect(PHP_OS_FAMILY === 'Linux' && $argc === 2, '需要Linux真实原生产物验证发布工具定位');
$root = dirname(__DIR__);
$base = $root . '/build/package-toolchain-' . bin2hex(random_bytes(6));
expect(mkdir($base, 0700), '不能覆盖已有发布验证目录');
$package = (new NativePackage())->create($argv[1], $base . '/release', $root . '/.env.example');
$release = (new NativePackage())->verify($package['directory'], $package['manifest-sha256']);
$process = new Process([$package['directory'] . '/run', 'help'], $package['directory'], ['PATH' => '/usr/bin:/bin']);
try {
    $result = $process->wait(20);
    expect($result->successful() && $result->stderr === '' && str_contains($result->stdout, 'verify-runtime') && str_contains($result->stdout, 'migrate'), '脱离SDK环境的标准应用发布启动失败：' . $result->stdout . $result->stderr);
} finally {
    $process->stop();
}
foreach ($release['files'] as $path => $entry) {
    expect(!in_array(basename($path), ['readelf', 'gcc', 'g++', 'php', 'composer'], true), '构建工具进入了发布目录');
}
file_put_contents($base . '/verification.json', json_encode(['package' => $package, 'artifact-sha256' => $release['artifact']['sha256'],
    'system-readelf-present' => is_executable('/usr/bin/readelf'), 'checks' => ['package-created', 'manifest-verified', 'launcher-without-sdk-env', 'no-build-tools']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
echo '原生发布工具定位与脱离SDK环境启动通过：' . $base . "/verification.json\n";
