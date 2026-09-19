<?php

declare(strict_types=1);

namespace Type\Core\Http\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Group
{
    public function __construct(
        public string $prefix = '',
        public string $namePrefix = '',
        public array $middleware = [],
        public array $constraints = []
    ) {
    }
}
