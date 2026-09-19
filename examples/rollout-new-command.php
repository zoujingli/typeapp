<?php

declare(strict_types=1);

function main(int $argc, array $argv): void
{
    \TypeApp\Rollout\Application::run(2, $argv);
}
