<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/UpgradeBaseline.php';

use Type\Testing\Process;
use TypeApp\Release\UpgradeBaseline;

// 下载只用于测试控制端；部署程序不需要 GitHub CLI、源码或旧程序。
$root = dirname(__DIR__, 2);
$profile = getenv('TYPEAPP_BUILD_PROFILE') ?: '';
$platform = match (PHP_OS_FAMILY) {
    'Darwin' => 'macos-arm64', 'Windows' => 'windows-x64',
    'Linux' => in_array(php_uname('m'), ['arm64', 'aarch64'], true) ? 'linux-arm64' : 'linux-x64',
    default => throw new RuntimeException('升级基线平台无效'),
};
$expected = UpgradeBaseline::expected($platform, $profile);
$directory = $root . '/build/upgrade-baseline';
if (!is_dir($directory) && !mkdir($directory, 0700)) {
    throw new RuntimeException('无法创建升级基线目录');
}
foreach (['release-manifest.json', $expected['file']] as $name) {
    if (file_exists($directory . '/' . $name)) {
        continue;
    }
    $process = new Process(['gh', 'release', 'download', $expected['version'], '--repo', 'zoujingli/typeapp', '--pattern', $name, '--dir', $directory], $root);
    try {
        $result = $process->wait(180);
        if (!$result->successful()) {
            throw new RuntimeException('公开升级基线下载失败：' . $name . ' ' . $result->stderr);
        }
    } finally {
        $process->stop();
    }
}
$program = $directory . '/' . $expected['file'];
// Release 下载只传输字节；先恢复普通文件的执行位，符号链接仍交由身份门拒绝。
if (PHP_OS_FAMILY !== 'Windows' && is_file($program) && !is_link($program) && !chmod($program, 0555)) {
    throw new RuntimeException('无法设置基线程序执行权限');
}
UpgradeBaseline::verify($directory, $platform, $profile);
echo '已核对公开升级基线：' . $expected['version'] . ' ' . $platform . '/' . $profile . "\n";
