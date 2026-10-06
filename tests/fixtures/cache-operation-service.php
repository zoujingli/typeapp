<?php

declare(strict_types=1);

namespace TypeTests\CacheOperations;

use Type\Cache\Attribute\Cacheable;
use Type\Cache\Attribute\CacheEvict;
use Type\Cache\TypedCache;

/** 仅安装缓存组件的声明服务，保持原类型及手动构造。 */
final class Service
{
    public int $loads = 0;

    #[Cacheable(cache: 'cache', key: 'value:{id}')]
    public function read(TypedCache $cache, int $id): ?string
    {
        $this->loads++;
        return $id === 0 ? null : 'value-' . $id;
    }

    public function indirect(TypedCache $cache, int $id): ?string
    {
        return $this->read($cache, $id);
    }

    #[CacheEvict(cache: 'cache', key: 'value:{id}')]
    public function evict(TypedCache $cache, int $id): void
    {
    }
}
