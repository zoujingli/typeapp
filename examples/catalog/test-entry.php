<?php

declare(strict_types=1);
use Type\Testing\Process;

$root = getenv('TYPE_APP_TEST_DIRECTORY') ?: dirname(__DIR__);
require $root . '/vendor/autoload.php';
foreach (['smoke.php', 'catalog.php', 'catalog-reliability.php'] as $file) {
    $process = new Process([PHP_BINARY, $root . '/tests/' . $file], $root, getenv(), 2097152);
    try {
        $result = $process->wait(120);
        echo $result->stdout;
        if (!$result->successful()) {
            fwrite(STDERR, $result->stderr);
            exit(1);
        }
    } finally {
        $process->stop();
    }
}
