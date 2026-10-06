<?php

declare(strict_types=1);

// 安装前显式选择生产驱动，继承模板的组件批次与稳定性策略。
$driver = $argv[1] ?? 'sqlite';
if (count($argv) > 2 || !in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
    fwrite(STDERR, "用法：php configure.php <mysql|pgsql|sqlite>\n");
    exit(1);
}
if (is_dir(__DIR__ . '/vendor') || is_file(__DIR__ . '/composer.lock')) {
    fwrite(STDERR, "请在首次安装之前选择驱动；已有应用应通过代码审查调整依赖和连接配置。\n");
    exit(1);
}
$composerFile = __DIR__ . '/composer.json';
$target = __DIR__ . '/app/common/database/DatabaseFactory.php';
$source = __DIR__ . '/scaffold/' . $driver . '.php';
foreach ([$composerFile, $target, $source] as $file) {
    if (!is_file($file) || is_link($file) || str_replace('\\', '/', (string) realpath($file)) !== str_replace('\\', '/', $file)) {
        throw new RuntimeException('模板配置只接受项目内的普通文件');
    }
}
$originalComposer = file_get_contents($composerFile);
$originalDriver = file_get_contents($target);
$driverSource = file_get_contents($source);
$composer = json_decode($originalComposer, true, 512, JSON_THROW_ON_ERROR);
$drivers = array_intersect_key($composer['require'] ?? [], array_flip([
    'zoujingli/type-orm-mysql', 'zoujingli/type-orm-pgsql', 'zoujingli/type-orm-sqlite',
]));
if (count($drivers) !== 1 || !is_string(current($drivers)) || trim(current($drivers)) === '') {
    throw new RuntimeException('模板必须声明唯一的数据库驱动版本约束');
}
$constraint = current($drivers);
foreach (['mysql', 'pgsql', 'sqlite'] as $name) {
    unset($composer['require']['zoujingli/type-orm-' . $name]);
}
$composer['require']['zoujingli/type-orm-' . $driver] = $constraint;
foreach ($composer['repositories'] ?? [] as $index => $repository) {
    if (preg_match('~/type-orm-(mysql|pgsql|sqlite)\.git$~', $repository['url'])) {
        $composer['repositories'][$index]['url'] = 'https://github.com/zoujingli/type-orm-' . $driver . '.git';
    }
}
$json = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
$staged = [];
$changedDriver = false;
try {
    // 全部内容先在对应文件系统写完；第二个替换失败时恢复原驱动。
    foreach ([[$target, $driverSource], [$composerFile, $json], [$target, $originalDriver]] as [$path, $content]) {
        $temporary = tempnam(dirname($path), '.configure-');
        if ($temporary === false) {
            throw new RuntimeException('无法暂存应用驱动配置');
        }
        $staged[] = $temporary;
        if (realpath(dirname($temporary)) !== realpath(dirname($path))
            || file_put_contents($temporary, $content) !== strlen($content) || !chmod($temporary, fileperms($path) & 0777)) {
            throw new RuntimeException('无法完整写入应用驱动配置');
        }
    }
    if (file_get_contents($composerFile) !== $originalComposer || file_get_contents($target) !== $originalDriver) {
        throw new RuntimeException('应用配置在准备期间已变化');
    }
    if (!rename($staged[0], $target)) {
        throw new RuntimeException('无法配置应用驱动');
    }
    $changedDriver = true;
    if (!rename($staged[1], $composerFile)) {
        throw new RuntimeException('无法完整写入项目依赖声明');
    }
    $changedDriver = false;
} finally {
    if ($changedDriver && !rename($staged[2], $target)) {
        throw new RuntimeException('依赖写入失败，原驱动保留在 ' . basename($staged[2]) . '，请恢复后重试');
    }
    foreach ($staged as $temporary) {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}
echo '应用已选择 ' . $driver . "，下一步显式运行 Composer 安装。\n";
