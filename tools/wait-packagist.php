<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/distribution/Process.php';
require __DIR__ . '/release/Plan.php';
require __DIR__ . '/release/Packagist.php';

use TypeApp\Distribution\Process;
use TypeApp\Release\Packagist;

try {
    $batch = json_decode((string) file_get_contents($argv[1] ?? ''), true, 64, JSON_THROW_ON_ERROR);
    if (($batch['mode'] ?? '') !== 'tag' || ($batch['complete'] ?? false) !== true) {
        throw new RuntimeException('Packagist验收只接受已完成tag批次');
    }
    $items = $batch['items'];
    if (isset($argv[2])) {
        $template = json_decode((string) file_get_contents($argv[2]), true, 64, JSON_THROW_ON_ERROR);
        if (($template['version'] ?? '') !== $batch['version'] || ($template['source'] ?? '') !== $batch['source'] || !($template['checkout-verified'] ?? false)) {
            throw new RuntimeException('模板与组件索引版本不一致');
        }
        $items['type-project'] = $template;
    }
    Process::report(
        dirname(__DIR__) . '/build/distribution/packagist-' . count($items) . '.json',
        ['source' => $batch['source'], 'version' => $batch['version'], 'items' => Packagist::wait($items, $batch['version'])]
    );
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
