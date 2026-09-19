<?php

declare(strict_types=1);

namespace Type\Cache;

final class CacheItem
{
    private bool $hit;
    private mixed $value;

    public function __construct(bool $hit, mixed $value = null)
    {
        $this->hit = $hit;
        $this->value = $value;
    }
    public function hit(): bool
    {
        return $this->hit;
    }
    public function value(): mixed
    {
        if (!$this->hit) {
            throw new CacheException('未命中的缓存没有值');
        }
        return $this->value;
    }
}
