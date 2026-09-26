<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/distribution/Process.php';

use Type\Build\BuildIdentity;
use Type\Build\EmbeddedResourceCompiler;
use TypeApp\Distribution\Process;

// 构建端共享前端身份；验证完整目录，额外文件也会使身份不匹配。
try {
    $root = dirname(__DIR__);
    $operation = $argv[1] ?? '';
    $path = $argv[2] ?? '';
    if (count($argv) !== 3 || !in_array($operation, ['record', 'verify'], true)) {
        throw new InvalidArgumentException('用法：php tools/frontend-resources.php <record|verify> <清单文件>');
    }
    $compiler = new EmbeddedResourceCompiler();
    $files = $compiler->manifest($compiler->collect($root, [['source' => 'web/dist', 'target' => 'web']]));
    foreach (['index.html', 'LICENSE', 'NOTICE', 'UPSTREAM.md'] as $required) {
        if (!isset($files['web/' . $required])) {
            throw new RuntimeException('前端缺少入口或许可材料：' . $required);
        }
    }
    $lock = $root . '/web/pnpm-lock.yaml';
    if (!is_file($lock) || !is_readable($lock) || is_link($lock)) {
        throw new RuntimeException('前端资源身份需要可读的普通依赖锁文件');
    }
    $lockDigest = hash_file('sha256', $lock);
    if (!is_string($lockDigest) || !preg_match('/^[a-f0-9]{64}$/D', $lockDigest)) {
        throw new RuntimeException('无法读取前端依赖锁文件摘要');
    }
    $record = ['protocol' => 1, 'source' => Process::output(['git', 'rev-parse', 'HEAD'], $root),
        'lock-sha256' => $lockDigest, 'files' => $files];
    if ($operation === 'record') {
        Process::report($path, $record);
    } else {
        $expected = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (BuildIdentity::digest($expected) !== BuildIdentity::digest($record)) {
            throw new RuntimeException('共享前端与固定源码、锁文件或资源字节不一致');
        }
    }
    echo '前端资源已校验：' . count($files) . '个文件，' . array_sum(array_column($files, 'bytes')) . "字节\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
