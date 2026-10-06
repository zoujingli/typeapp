<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/distribution/Process.php';
require __DIR__ . '/distribution/Batch.php';
require __DIR__ . '/release/TutorialEvidence.php';

use TypeApp\Distribution\Batch;
use TypeApp\Distribution\Process;
use TypeApp\Release\TutorialEvidence;

try {
    $root = dirname(__DIR__);
    [$mode, $source, $file, $version] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? null];
    if (!in_array($mode, ['candidate', 'public'], true) || preg_match('/^[a-f0-9]{40}$/D', $source) !== 1
        || ($mode === 'candidate' ? $version !== null : !is_string($version))) {
        throw new InvalidArgumentException('用法：php tools/verify-tutorial.php candidate|public <固定源码SHA> <报告JSON> [公开准确版本tag]');
    }
    $mapping = json_decode(Process::output(['git', 'show', $source . ':.github/distribution.json'], $root), true, 512, JSON_THROW_ON_ERROR);
    $plan = Batch::plan($root, $source, $mode === 'candidate' ? 'branch' : 'tag', $version ?? '', $mapping);
    $split = Process::output(['git', 'subtree', 'split', '--prefix=templates/type-project', '--ignore-joins', $source], $root);
    TutorialEvidence::verify(
        $file,
        $source,
        $mode === 'candidate' ? 'fixed-candidate' : 'packagist-tag',
        $version,
        $plan['items'] + ['type-project' => ['split' => $split]]
    );
    echo "教程三profile同产物准入通过。\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
