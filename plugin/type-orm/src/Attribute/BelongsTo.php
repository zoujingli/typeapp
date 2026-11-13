<?php

declare(strict_types=1);

namespace Type\Orm\Attribute;

use Type\Orm\Model;

/** 当前模型外键指向目标键，空外键对应已加载的 null。 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class BelongsTo
{
    /** @param class-string<Model> $target 直接编译的目标模型。 */
    public function __construct(public string $target, public string $foreignKey, public string $ownerKey = 'id') {}
}
