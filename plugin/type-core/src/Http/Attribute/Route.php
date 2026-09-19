<?php

declare(strict_types=1);

namespace Type\Core\Http\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Route
{
    public function __construct(
        public string $path,
        public array $methods = ['GET'],
        public ?string $name = null,
        public array $constraints = [],
        public array $middleware = []
    ) {
    }
}
