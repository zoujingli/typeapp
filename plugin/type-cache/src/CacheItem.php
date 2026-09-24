<?php

declare(strict_types=1);

namespace Type\Cache;

/** 类型化缓存读取结果，显式区分未命中和命中的 null。 */
final class CacheItem
{
    private bool $hit;
    private mixed $value;

    /** 同时保存命中标记和值，允许缓存命中的值本身为 null。 */
    public function __construct(bool $hit, mixed $value = null)
    {
        $this->hit = $hit;
        $this->value = $value;
    }
    /** 只检查是否命中，不使用值是否为 null 判断缺失。 */
    public function hit(): bool
    {
        return $this->hit;
    }
    /** 返回业务值；调用者应先检查 hit，未命中时的 null 不代表已缓存。 */
    public function value(): mixed
    {
        if (!$this->hit) {
            throw new CacheException('未命中的缓存没有值');
        }
        return $this->value;
    }
}
