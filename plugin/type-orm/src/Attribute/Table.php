<?php

declare(strict_types=1);

namespace Type\Orm\Attribute;

use Attribute;

/** 构建期模型表声明；键和生命周期参数引用 PHP 属性名。 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Table
{
    public function __construct(
        public string $name,
        public string $primary = 'id',
        public bool $generatedPrimary = true,
        public ?string $softDelete = null,
        public ?string $version = null,
        public string $database = 'default',
        public ?string $tenant = null
    ) {
    }
}
