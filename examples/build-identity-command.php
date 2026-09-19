<?php

declare(strict_types=1);

function main(int $argc, array $argv): void
{
    if ($argc === 2 && $argv[1] === '--check-runtime') {
        Type\Generated\BuildIdentity::verifyRuntime();
    }
    $identity = Type\Generated\BuildIdentity::info();
    echo json_encode(['build_id' => $identity['build-id'], 'application' => $identity['application'], 'version' => $identity['version'],
        'capabilities' => $identity['capabilities']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
}
