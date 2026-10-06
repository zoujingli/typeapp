<?php

declare(strict_types=1);

namespace Type\Cache\Attribute;

/** 成功后失效；同逻辑数据源存在活动事务时只在最外层确认提交后执行。 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class CacheEvict
{
    /** database 省略时沿用同方法 Transactional 的数据源，其他情况使用 default。 */
    public function __construct(public string $cache, public string $key = '', public bool $all = false, public string $database = 'default')
    {
    }
}
