<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/distribution/Process.php';
require __DIR__ . '/distribution/Batch.php';
require __DIR__ . '/release/Plan.php';
require __DIR__ . '/release/TutorialEvidence.php';

use TypeApp\Distribution\Batch;
use TypeApp\Distribution\Process;
use TypeApp\Release\Plan;
use TypeApp\Release\TutorialEvidence;

$reportFile = null;
try {
    [$mode, $source, $directory, $run, $attempt, $version] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? '', $argv[5] ?? '', $argv[6] ?? null];
    if (!in_array($mode, ['candidate', 'public'], true) || !preg_match('/^[a-f0-9]{40}$/D', $source)
        || !preg_match('/^[1-9][0-9]*$/D', $run) || !preg_match('/^[1-9][0-9]*$/D', $attempt)
        || ($mode === 'candidate' ? $version !== null : $version === null) || !is_dir($directory . '/inputs')) {
        throw new InvalidArgumentException('用法：php tools/collect-tutorial.php candidate|public <源码SHA> <含inputs的报告目录> <run> <attempt> [公开tag]');
    }
    if ($version !== null) {
        Plan::version($version);
    }
    $reportFile = $directory . '/verification.json';
    $report = ['protocol' => 1, 'kind' => 'tutorial-delivery-matrix', 'status' => 'running', 'source' => $source,
        'channel' => $mode === 'candidate' ? 'fixed-candidate' : 'packagist-tag', 'version' => $version, 'run' => $run, 'attempt' => $attempt, 'reports' => []];
    Process::report($reportFile, $report);
    $root = dirname(__DIR__);
    $mapping = json_decode(Process::output(['git', 'show', $source . ':.github/distribution.json'], $root), true, 512, JSON_THROW_ON_ERROR);
    $plan = Batch::plan($root, $source, $mode === 'candidate' ? 'branch' : 'tag', $version ?? '', $mapping);
    $items = $plan['items'] + ['type-project' => ['split' => Process::output(['git', 'subtree', 'split', '--prefix=templates/type-project', '--ignore-joins', $source], $root)]];
    foreach (['linux-x64', 'linux-arm64', 'macos-arm64', 'windows-x64'] as $platform) {
        foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
            $path = 'inputs/tutorial-' . $mode . '-' . $platform . '-' . $driver . '-' . $attempt . '/verification.json';
            if (!is_file($directory . '/' . $path)) {
                throw new RuntimeException('缺少同轮教程artifact：' . $platform . '/' . $driver);
            }
            $report['reports'][$platform . '/' . $driver] = ['file' => $path, 'sha256' => hash_file('sha256', $directory . '/' . $path)];
        }
    }
    $report['status'] = 'passed';
    $pending = $directory . '/pending.json';
    Process::report($pending, $report);
    try {
        TutorialEvidence::matrix($pending, $source, $report['channel'], $version, $items, $run, $attempt);
        Process::report($reportFile, $report);
    } finally {
        unlink($pending);
    }
    echo "教程四平台十二profile原始证据核对通过。\n";
} catch (Throwable $error) {
    if ($reportFile !== null) {
        $report['status'] = 'failed';
        $report['failure'] = $error->getMessage();
        Process::report($reportFile, $report);
    }
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
