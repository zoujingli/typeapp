<?php

declare(strict_types=1);

require dirname(__DIR__) . '/plugin/type-build/src/BundledSwoole.php';

try {
    $module = (new Type\Build\BundledSwoole())->select();
    echo $module['file'], "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
