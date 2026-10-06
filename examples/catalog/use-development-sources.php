<?php

declare(strict_types=1);

// 显式选择开发候选来源；公开包消费不执行此脚本。
if (count($argv) !== 2 || !is_file($argv[1] . '/composer.json')) {
    fwrite(STDERR, "用法：php examples/catalog/use-development-sources.php <新应用目录>\n");
    exit(1);
}
$project = realpath($argv[1]);
$source = dirname(__DIR__, 2);
$manifest = $project . '/composer.json';
$composer = json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
$constraint = $composer['require']['zoujingli/type-core'];
if ($constraint !== '1.0.x-dev' || is_file($project . '/composer.lock')) {
    throw new RuntimeException('只允许在首次开发候选安装前声明来源；不能转换已发布约束或覆盖已有锁');
}
$composer['repositories'] = [];
foreach (glob($source . '/plugin/type-*/composer.json') as $file) {
    $package = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $composer['repositories'][] = ['type' => 'path', 'url' => dirname($file),
        'options' => ['symlink' => false, 'versions' => [$package['name'] => $constraint]]];
}
file_put_contents($manifest, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo "已显式选择当前开发候选的组件复制来源；尚未安装依赖。\n";
