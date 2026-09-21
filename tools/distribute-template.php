<?php

declare(strict_types=1);

require __DIR__ . '/distribution/Process.php';
require __DIR__ . '/distribution/Batch.php';
require __DIR__ . '/distribution/Publisher.php';
use TypeApp\Distribution\Batch;
use TypeApp\Distribution\Process;
use TypeApp\Distribution\Publisher;

$report = null;
$reportFile = dirname(__DIR__) . '/build/distribution/template.json';
try {
    $root = dirname(__DIR__);
    $source = $argv[1] ?? '';
    $batchFile = $argv[2] ?? '';
    if (count($argv) !== 3 || !preg_match('/^[a-f0-9]{40}$/D', $source)) {
        throw new InvalidArgumentException('用法：php tools/distribute-template.php <固定 SHA> <已完成插件批次报告>');
    }
    $report = ['source' => $source, 'status' => 'running', 'stage' => 'preparation', 'checkout-verified' => false];
    Process::report($reportFile, $report);
    if (Process::output(['git', 'rev-parse', 'HEAD'], $root) !== $source || Process::output(['git', 'status', '--porcelain', '--untracked-files=no'], $root) !== '') {
        throw new RuntimeException('模板分发必须使用干净固定检出');
    }
    Process::output(['git', 'merge-base', '--is-ancestor', $source, 'origin/main'], $root);
    $batch = json_decode(file_get_contents($batchFile), true, 512, JSON_THROW_ON_ERROR);
    $packages = json_decode(Process::output(['git', 'show', $source . ':.github/distribution.json'], $root), true, 512, JSON_THROW_ON_ERROR);
    Batch::verifyReport($root, $source, $batch, $packages);
    $report['native-ci'] = Batch::nativeEvidence($root, $source);
    $mapping = json_decode(file_get_contents($root . '/.github/template-distribution.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($mapping['protocol'] ?? 0) !== 1 || ($mapping['source-repository'] ?? '') !== 'zoujingli/typeapp'
        || ($mapping['prefix'] ?? '') !== 'templates/type-project' || ($mapping['repository'] ?? '') !== 'zoujingli/type-project'
        || ($mapping['visibility'] ?? '') !== 'public' || ($mapping['branch'] ?? '') !== 'main') {
        throw new RuntimeException('模板映射超出既定范围');
    }
    $composer = json_decode(Process::output(['git', 'show', $source . ':templates/type-project/composer.json'], $root), true, 512, JSON_THROW_ON_ERROR);
    if (($composer['type'] ?? '') !== 'project' || ($composer['name'] ?? '') !== 'zoujingli/type-project'
        || ($composer['license'] ?? '') !== 'Apache-2.0'
        || !isset($composer['require-dev']['zoujingli/type-build'], $composer['require-dev']['zoujingli/type-testing'])) {
        throw new RuntimeException('模板包身份或开发依赖不正确');
    }
    foreach ($composer['repositories'] as $repository) {
        if ($repository['type'] !== 'git' || !preg_match('~^https://github\.com/zoujingli/type-[a-z0-9-]+\.git$~D', $repository['url'])) {
            throw new RuntimeException('模板携带本地或未允许的依赖地址');
        }
    }
    $files = explode("\n", Process::output(['git', 'ls-tree', '-r', '--name-only', $source . ':templates/type-project'], $root));
    foreach ($files as $file) {
        if (!preg_match('~^(?:\.gitignore|\.env\.example|README\.md|LICENSE|NOTICE|composer\.json|(?:configure|prepare|dev)\.php|(?:models|routes|type-app|toolchain\.lock)\.json|(?:app|config|scaffold|tests)/[A-Za-z0-9_./-]+|var/\.gitkeep)$~D', $file)) {
            throw new RuntimeException('模板出现未允许分发的文件：' . $file);
        }
    }
    $actual = json_decode(Process::output(['gh', 'api', 'repos/' . $mapping['repository']], $root), true, 512, JSON_THROW_ON_ERROR);
    if (($actual['full_name'] ?? '') !== $mapping['repository'] || ($actual['private'] ?? null) !== false
        || ($actual['visibility'] ?? '') !== 'public' || ($actual['archived'] ?? true) !== false) {
        throw new RuntimeException('目标不是预期的公开模板仓库');
    }
    $split = Process::output(['git', 'subtree', 'split', '--prefix=templates/type-project', '--ignore-joins', $source], $root);
    $tree = Process::output(['git', 'rev-parse', $source . ':templates/type-project'], $root);
    if (Process::output(['git', 'rev-parse', $split . '^{tree}'], $root) !== $tree) {
        throw new RuntimeException('模板拆分内容不一致');
    }
    $plan = ['id' => hash('sha256', $source . ':' . $tree), 'source' => $source, 'mode' => 'branch', 'version' => '',
        'items' => ['type-project' => ['package' => 'zoujingli/type-project', 'repository' => 'zoujingli/type-project', 'branch' => 'main', 'split' => $split]]];
    $report['stage'] = 'publish';
    Process::report($reportFile, $report);
    $report = array_replace($report, Publisher::publish($root, 'git@github.com:zoujingli/type-project.git', $plan, 'type-project'));
    $report['publish-status'] = $report['status'];
    $report['framework-batch'] = $batch['id'];
    $report['tree'] = $tree;
    if ($report['status'] === 'failed') {
        throw new RuntimeException($report['error']);
    }
    $clone = $root . '/build/template-remote-' . bin2hex(random_bytes(6));
    $report['status'] = 'verifying';
    $report['stage'] = 'checkout';
    $report['checkout-directory'] = substr($clone, strlen($root) + 1);
    Process::report($reportFile, $report);
    Process::output(['git', 'clone', '--no-checkout', 'https://github.com/zoujingli/type-project.git', $clone], $root);
    Process::output(['git', 'checkout', '--detach', $split], $clone);
    if (Process::output(['git', 'rev-parse', 'HEAD^{tree}'], $clone) !== $tree) {
        throw new RuntimeException('实际获取模板内容与计划不一致');
    }
    $report['status'] = $report['publish-status'];
    $report['stage'] = 'complete';
    $report['checkout-verified'] = true;
    Process::report($reportFile, $report);
    echo $clone . "\n";
} catch (Throwable $error) {
    if (is_array($report)) {
        $report['status'] = 'failed';
        $report['error'] = $error->getMessage();
        try {
            Process::report($reportFile, $report);
        } catch (Throwable $reportError) {
            fwrite(STDERR, '无法记录模板分发失败：' . $reportError->getMessage() . "\n");
        }
    }
    fwrite(STDERR, '模板分发失败：' . $error->getMessage() . "\n");
    exit(1);
}
