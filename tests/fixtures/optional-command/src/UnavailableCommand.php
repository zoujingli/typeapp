<?php

declare(strict_types=1);

namespace TypeApp\Optional;

use RuntimeException;
use Type\Core\Command;
use Type\Core\Configuration;

final class UnavailableCommand implements Command
{
    public function __construct()
    {
        throw new RuntimeException('可选外部服务不可用');
    }

    public function run(Configuration $configuration, array $arguments): int
    {
        return 1;
    }
}
