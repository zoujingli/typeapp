<?php

declare(strict_types=1);

namespace Type\Orm\Attribute;

use Attribute;

/** 单个目标模型；键名引用模型属性，未加载关系不查询数据库。 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class HasOne
{
    /** @param class-string<\Type\Orm\Model> $target 直接编译的目标模型。 */
    public function __construct(public string $target, public string $foreignKey, public string $localKey = 'id')
    {
    }
}
