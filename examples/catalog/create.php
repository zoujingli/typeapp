<?php

declare(strict_types=1);

// 使用明确选择的模板约束；来源由调用者配置，不把开发路径写入公开消费。
if (count($argv) < 2 || count($argv) > 5) {
    fwrite(STDERR, "用法：php examples/catalog/create.php <全新应用目录> [sqlite|mysql|pgsql] [模板目录] [开发工具项目根]\n");
    exit(1);
}
$source = $argv[4] ?? dirname(__DIR__, 2);
require $source . '/vendor/autoload.php';
$result = (new Type\Build\ProjectCreator())->create($argv[3] ?? $source . '/templates/type-project', $argv[1], $argv[2] ?? 'sqlite');
$project = $result['directory'];
$composer = json_decode(file_get_contents($project . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$constraint = $composer['require']['zoujingli/type-core'];
foreach (['type-redis', 'type-cache', 'type-queue', 'type-scheduler'] as $component) {
    $composer['require']['zoujingli/' . $component] = $constraint;
}
file_put_contents($project . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo "已按所选模板约束创建目录应用；请确认所选通道依赖来源后执行 composer install --no-scripts --no-plugins。\n";
