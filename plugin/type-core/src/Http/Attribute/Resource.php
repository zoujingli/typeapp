<?php

declare(strict_types=1);

namespace Type\Core\Http\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class Resource
{
    public function __construct(
        public string $path,
        public string $name,
        public string $parameter = 'id',
        public array $only = ['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'],
        public array $constraints = [],
        public array $middleware = []
    ) {
    }
}
