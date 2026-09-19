<?php

declare(strict_types=1);

namespace Type\Orm\Attribute;

/** 构建期声明：仅显式生成并调用的操作对象才执行事务，不拦截原方法。 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Transactional
{
    public function __construct(public string $connection = 'connection')
    {
    }
}
