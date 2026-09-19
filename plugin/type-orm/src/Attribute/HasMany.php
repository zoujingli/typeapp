<?php

declare(strict_types=1);

namespace Type\Orm\Attribute;

use Attribute;

/** 目标模型列表，使用同一声明进行批量加载、过滤与统计。 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class HasMany
{
    /** @param class-string<\Type\Orm\Model> $target 直接编译的目标模型。 */
    public function __construct(public string $target, public string $foreignKey, public string $localKey = 'id')
    {
    }
}
