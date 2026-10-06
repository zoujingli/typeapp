<?php

declare(strict_types=1);

namespace Type\Orm\Attribute;

/** 标准入口在加载前转换原 Service 方法，普通调用与类内互调执行同一事务声明。 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Transactional
{
    /** 指定生成方法体使用的逻辑数据源，复用既有保存点与提交结果语义。 */
    public function __construct(public string $database = 'default')
    {
    }
}
