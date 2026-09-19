<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
$root = dirname(__DIR__);
$consumer = $argv[1];
foreach (['queue-lease-command.php', 'queue-retry-command.php'] as $entry) {
    $program = 'require ' . var_export($consumer . '/vendor/autoload.php', true) . ';';
    if ($entry === 'queue-retry-command.php') {
        $program .= 'require ' . var_export($root . '/examples/queue/Increment.php', true) . '; require '
            . var_export($root . '/examples/queue/RetryJobs.php', true) . ';';
    }
    $program .= 'require ' . var_export($root . '/examples/' . $entry, true) . '; main($argc, $argv);';
    echo successful([PHP_BINARY, '-r', $program]);
}
