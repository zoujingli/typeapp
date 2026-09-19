<?php

declare(strict_types=1);

namespace Type\Orm;

use PDO;

interface Driver
{
    public function name(): string;
    public function identity(): array;

    public function connect(): PDO;
}
