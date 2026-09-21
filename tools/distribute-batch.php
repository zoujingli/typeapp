<?php

declare(strict_types=1);

require __DIR__ . '/distribution/Process.php';
require __DIR__ . '/distribution/Batch.php';
require __DIR__ . '/distribution/Publisher.php';

use TypeApp\Distribution\Batch;
use TypeApp\Distribution\Process;
use TypeApp\Distribution\Publisher;

try {
    $root = dirname(__DIR__);
    $operation = $argv[1] ?? '';
    $source = $argv[2] ?? '';
    $mode = $argv[3] ?? '';
    $version = $argv[4] ?? '';
    if (!in_array($operation, ['plan', 'publish', 'collect'], true) || !preg_match('/^[a-f0-9]{40}$/D', $source)) {
        throw new InvalidArgumentException('用法：php tools/distribute-batch.php <plan|publish|collect> <完整 SHA> <branch|tag> <版本或空字符串> [插件名]');
    }
    if (Process::output(['git', 'rev-parse', 'HEAD'], $root) !== $source || Process::output(['git', 'status', '--porcelain', '--untracked-files=no'], $root) !== '') {
        throw new RuntimeException('批次必须使用干净的固定源码检出');
    }
    Process::output(['git', 'merge-base', '--is-ancestor', $source, 'origin/main'], $root);
    $evidence = Batch::nativeEvidence($root, $source);
    $mapping = json_decode(Process::output(['git', 'show', $source . ':.github/distribution.json'], $root), true, 512, JSON_THROW_ON_ERROR);
    $plan = Batch::plan($root, $source, $mode, $version, $mapping);
    $plan['native-ci'] = $evidence;
    $directory = $root . '/build/distribution';
    if ($operation === 'plan') {
        Process::report($directory . '/batch-plan.json', $plan);
        $matrix = [];
        foreach ($plan['items'] as $name => $item) {
            $matrix[] = ['name' => $name, 'secret' => $item['write-secret']];
        }
        echo json_encode(['include' => $matrix], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    } elseif ($operation === 'publish') {
        $name = $argv[5] ?? '';
        $item = $plan['items'][$name] ?? throw new RuntimeException('插件未登记');
        $actual = json_decode(Process::output(['gh', 'api', 'repos/' . $item['repository']], $root), true, 512, JSON_THROW_ON_ERROR);
        if (($actual['full_name'] ?? '') !== $item['repository'] || ($actual['private'] ?? null) !== false
            || ($actual['visibility'] ?? '') !== 'public' || ($actual['archived'] ?? true) !== false) {
            throw new RuntimeException('目标不是预期的公开仓库，停止分发');
        }
        $report = Publisher::publish($root, 'git@github.com:' . $item['repository'] . '.git', $plan, $name);
        $report['native-ci'] = $evidence;
        $report['visibility'] = 'public';
        Process::report($directory . '/items/' . $name . '.json', $report);
        echo $name . '：' . $report['status'] . "\n";
        if ($report['status'] === 'failed') {
            fwrite(STDERR, $report['error'] . "\n");
            exit(1);
        }
    } else {
        $reports = [];
        foreach ($plan['items'] as $name => $item) {
            $path = $directory . '/items/' . $name . '.json';
            if (is_file($path)) {
                $reports[$name] = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            }
        }
        $report = Batch::collect($plan, $reports);
        $report['native-ci'] = $evidence;
        Process::report($directory . '/batch-result.json', $report);
        echo '分发批次 ' . $plan['id'] . '：' . ($report['complete'] ? '全部回读一致' : '部分未完成，可重跑补齐') . "\n";
        if (!$report['complete']) {
            exit(1);
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, '分发批次失败：' . $error->getMessage() . "\n");
    exit(1);
}
