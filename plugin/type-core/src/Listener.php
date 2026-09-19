<?php

declare(strict_types=1);

namespace Type\Core;

interface Listener
{
    public function handle(string $event, Configuration $configuration): void;
}
