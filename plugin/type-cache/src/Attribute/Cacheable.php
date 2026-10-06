<?php

declare(strict_types=1);

namespace Type\Cache\Attribute;

/** 构建期缓存声明；cache 指向 TypedCache 参数，key 明确覆盖全部业务标量参数。 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Cacheable
{
    /** 原方法由标准入口转换；同逻辑数据源的活动事务绕过共享缓存且不填充。 */
    public function __construct(public string $cache, public string $key, public int $ttlMilliseconds = 60000, public string $database = 'default')
    {
    }
}
