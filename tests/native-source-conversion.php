<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
$root = dirname(__DIR__);
$directory = $root . '/build/source-conversion';
expect(is_dir($directory) || mkdir($directory, 0700, true), '无法创建原生转换检查目录');
// 真实生产类进入同一编译入口；捕获编译器对异常属性赋值的误判。
successful([PHP_BINARY, $root . '/vendor/bin/type-compiler', $root . '/plugin/type-runtime/src/ManagedTask.php',
    '--dry', '--mode', 'lib', '--output', $directory . '/managed-task', '--build-dir', $directory, '--no-progress', '--no-color'], $root);
expect(is_file($directory . '/managed_task.stub.php'), '原生转换没有生成公开声明');
echo "受管任务生产源码的原生转换检查通过。\n";
