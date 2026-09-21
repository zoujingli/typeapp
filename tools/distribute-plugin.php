<?php

declare(strict_types=1);

use TypeApp\Distribution\Batch;
use TypeApp\Distribution\Process;

require __DIR__ . '/distribution/Process.php';
require __DIR__ . '/distribution/Batch.php';

/** 将已通过原生 CI 的固定主仓提交拆分到显式登记的公开子仓。 */
try {
    if (count($argv) !== 3 || !preg_match('/^[a-f0-9]{40}$/D', $argv[1]) || !preg_match('/^type-[a-z0-9-]+$/D', $argv[2])) {
        throw new InvalidArgumentException('用法：php tools/distribute-plugin.php <已验证的完整提交 SHA> <插件名>');
    }
    $root = dirname(__DIR__);
    $source = $argv[1];
    $packageName = $argv[2];
    $mapping = json_decode(file_get_contents($root . '/.github/distribution.json'), true, 512, JSON_THROW_ON_ERROR);
    $package = $mapping['packages'][$packageName] ?? throw new RuntimeException('插件未登记，不允许分发');
    if (($mapping['protocol'] ?? null) !== 1 || ($mapping['source-repository'] ?? null) !== 'zoujingli/typeapp'
        || ($package['prefix'] ?? null) !== 'plugin/' . $packageName
        || ($package['repository'] ?? null) !== 'zoujingli/' . $packageName
        || ($package['composer-name'] ?? null) !== 'zoujingli/' . $packageName
        || ($package['visibility'] ?? null) !== 'public' || ($package['branch'] ?? null) !== 'main') {
        throw new RuntimeException('分发映射不符合当前受控范围');
    }
    if (Process::output(['git', 'rev-parse', 'HEAD'], $root) !== $source) {
        throw new RuntimeException('当前检出必须与待分发的固定提交完全相同');
    }
    Process::output(['git', 'ls-files', '--error-unmatch', '.github/distribution.json', 'tools/distribute-plugin.php'], $root);
    if (Process::output(['git', 'status', '--porcelain', '--untracked-files=no'], $root) !== '') {
        throw new RuntimeException('工作区有已跟踪修改，拒绝分发');
    }
    Process::output(['git', 'merge-base', '--is-ancestor', $source, 'origin/main'], $root);

    $item = Batch::package($root, $source, $packageName, $mapping['packages']);
    $split = $item['split'];

    $evidence = Batch::nativeEvidence($root, $source);
    $actual = json_decode(Process::output(['gh', 'api', 'repos/' . $package['repository']], $root), true, 512, JSON_THROW_ON_ERROR);
    if (($actual['full_name'] ?? '') !== $package['repository'] || ($actual['private'] ?? null) !== false
        || ($actual['visibility'] ?? '') !== 'public' || ($actual['archived'] ?? true) !== false) {
        throw new RuntimeException('目标不是预期的公开仓库');
    }
    $remote = 'git@github.com:' . $package['repository'] . '.git';
    $branchRef = 'refs/heads/' . $package['branch'];
    $head = Process::output(['git', 'ls-remote', '--heads', $remote, $branchRef], $root);
    $previous = $head === '' ? null : explode("\t", $head)[0];
    if ($previous !== null && $previous !== $split) {
        Process::output(['git', 'fetch', '--no-tags', $remote, $branchRef], $root);
        [$status] = Process::run(['git', 'merge-base', '--is-ancestor', $previous, $split], $root);
        if ($status !== 0) {
            throw new RuntimeException('子仓历史发生偏离或分发顺序过期，拒绝覆盖');
        }
    }
    if ($previous !== $split) {
        Process::output(['git', 'push', $remote, $split . ':' . $branchRef], $root);
    }
    $published = Process::output(['git', 'ls-remote', '--heads', $remote, $branchRef], $root);
    if (explode("\t", $published)[0] !== $split) {
        throw new RuntimeException('分发后子仓提交与预期不一致');
    }
    $report = [
        'package' => $package['composer-name'], 'source-commit' => $source,
        'split-commit' => $split, 'repository' => $package['repository'],
        'branch' => $package['branch'], 'visibility' => 'public',
        'previous-commit' => $previous, 'native-ci' => $evidence,
        'git-version' => Process::output(['git', '--version'], $root),
        'already-current' => $previous === $split,
    ];
    $reportDirectory = $root . '/build/distribution';
    if (!is_dir($reportDirectory) && !mkdir($reportDirectory, 0755, true)) {
        throw new RuntimeException('无法创建分发报告目录');
    }
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (file_put_contents($reportDirectory . '/' . $packageName . '.json', $json) !== strlen($json)) {
        throw new RuntimeException('无法保存分发报告');
    }
    echo '公开插件分发并回读验证完成：' . $package['repository'] . ' @ ' . $split . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, '分发失败：' . $error->getMessage() . PHP_EOL);
    exit(1);
}
