<?php

declare(strict_types=1);

namespace Type\Core;

interface Command
{
    public function run(Configuration $configuration, array $arguments): int;
}
