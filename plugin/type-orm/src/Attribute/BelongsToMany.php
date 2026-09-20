<?php

declare(strict_types=1);

namespace Type\Orm\Attribute;

use Attribute;

/** 中间表关系；模型键使用属性名，中间表键与投影使用实际列名。 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class BelongsToMany
{
    /** @param class-string<\Type\Orm\Model> $target 直接编译的目标模型。 */
    public function __construct(
        public string $target,
        public string $table,
        public string $sourcePivotKey,
        public string $targetPivotKey,
        public string $sourceKey = 'id',
        public string $targetKey = 'id',
        public array $pivotFields = [],
        public ?string $pivotTenant = null
    ) {
    }
}
