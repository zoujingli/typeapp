<?php

declare(strict_types=1);

namespace Type\Cache\Attribute;

/** 成功后失效；与 Transactional 组合时只在最外层事务提交确认后执行。 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class CacheEvict
{
    public function __construct(public string $cache, public string $key = '', public bool $all = false)
    {
    }
}
