<?php

declare(strict_types=1);

namespace Type\Orm\Attribute;

/** 构建期声明：仅显式生成并调用的操作对象才执行事务，不拦截原方法。 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Transactional
{
    /** 指定生成事务入口使用的逻辑数据源，不在原方法外安装运行时拦截。 */
    public function __construct(public string $database = 'default')
    {
    }
}
