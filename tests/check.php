<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Runtime\Arguments;

$root = dirname(__DIR__);
$files = [];
foreach (['app', 'config', 'plugin', 'examples', 'tests', 'tools', 'templates'] as $directory) {
    if (!is_dir($root . '/' . $directory)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}
$files[] = $root . '/plugin/type-build/bin/type';
$files[] = $root . '/plugin/type-build/bin/type-compiler';
$files[] = $root . '/bin/typeapp';
$files[] = $root . '/bin/typeapp-prepare';
foreach ($files as $file) {
    successful([PHP_BINARY, '-l', $file]);
}

$arguments = new Arguments(['command', '--name', '开发者', '--repeat=2', '--help'], ['name', 'repeat'], ['help']);
expect($arguments->text('name', '') === '开发者', '空格形式文本选项读取失败');
expect($arguments->integer('repeat', 1, 1, 5) === 2, '整数选项读取失败');
expect($arguments->has('help'), '开关选项读取失败');
foreach ([['command', '--name'], ['command', '--unknown'], ['command', '--name=a', '--name=b'], ['command', '--help=yes']] as $invalid) {
    $failed = false;
    try {
        new Arguments($invalid, ['name', 'repeat'], ['help']);
    } catch (InvalidArgumentException) {
        $failed = true;
    }
    expect($failed, '非法命令参数没有拒绝');
}
foreach (['0', '6', 'abc', '999999999999999999999999999999999'] as $invalidInteger) {
    $failed = false;
    try {
        $arguments = new Arguments(['command', '--repeat=' . $invalidInteger], ['repeat'], []);
        $arguments->integer('repeat', 1, 1, 5);
    } catch (InvalidArgumentException) {
        $failed = true;
    }
    expect($failed, '越界或非整数选项没有拒绝');
}
echo 'PHP 语法和命令参数公共接口检查通过，共检查 ' . count($files) . " 个文件。\n";
if (is_file(__DIR__ . '/distribution.php')) {
    echo successful([PHP_BINARY, __DIR__ . '/distribution.php'], $root);
}
if (is_file(__DIR__ . '/docs-consistency.php')) {
    echo successful([PHP_BINARY, __DIR__ . '/docs-consistency.php'], $root);
}
if (is_file(__DIR__ . '/docs-deployment.php')) {
    echo successful([PHP_BINARY, __DIR__ . '/docs-deployment.php'], $root);
}
