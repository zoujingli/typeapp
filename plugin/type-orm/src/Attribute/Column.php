<?php

declare(strict_types=1);

namespace Type\Orm\Attribute;

use Attribute;

/** 补充数据库列、精确类型与赋值/输出策略；类型和可空性首先由 PHP 属性声明确定。 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Column
{
    public function __construct(
        public ?string $name = null,
        public ?string $type = null,
        public ?bool $fillable = null,
        public bool $visible = true,
        public ?bool $required = null,
        public int $precision = 65,
        public ?int $scale = null
    ) {
    }
}
