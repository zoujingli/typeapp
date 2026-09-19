<?php

declare(strict_types=1);

namespace Type\Cache;

use Closure;
use Type\Redis\RedisException;

/** 明确选择强一致回源以及缓存故障时的回源策略。 */
final class CacheReader
{
    private TypedCache $cache;
    private bool $fallback;
    public function __construct(TypedCache $cache, bool $fallbackOnRedisFailure = false)
    {
        $this->cache = $cache;
        $this->fallback = $fallbackOnRedisFailure;
    }
    /** @param Closure(bool): mixed $source 接收本次是否要求强一致读取。 */
    public function read(string $key, Closure $source, bool $strong = false): mixed
    {
        if ($strong) {
            return $source(true);
        }
        $loaded = false;
        $attempted = false;
        $value = null;
        try {
            return $this->cache->remember($key, static function () use ($source, &$loaded, &$attempted, &$value): mixed {
                $attempted = true;
                $value = $source(false);
                $loaded = true;
                return $value;
            });
        } catch (RedisException $error) {
            if (!$this->fallback || ($attempted && !$loaded)) {
                throw $error;
            }
            return $loaded ? $value : $source(false);
        }
    }
}
