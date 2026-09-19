<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
$root = dirname(__DIR__);
$directory = $root . '/build/serialization-callbacks';
expect(is_dir($directory) || mkdir($directory, 0700, true), '无法创建序列化原生检查目录');
$binary = $directory . '/serialization_callbacks';
successful([PHP_BINARY, $root . '/vendor/bin/type-compiler', __DIR__ . '/fixtures/serialization-callbacks.php',
    '--mode', 'bin', '--output', $binary, '--build-dir', $directory . '/compiler', '-O2', '--job', '2', '--no-progress', '--no-color'], $root);
[$status, $stdout, $stderr] = execute(nativeCommand($binary), $root);
expect(
    $status === 0 && $stderr === '' && $stdout === "原生 JSON、序列化与反序列化的 27 次回调异常及成功路径检查通过。\n",
    '序列化回调的原生异常边界验收失败：' . $stdout . $stderr
);
echo $stdout;
