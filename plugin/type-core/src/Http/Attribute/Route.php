<?php

declare(strict_types=1);

namespace Type\Core\Http\Attribute;

/** 构建期的显式 HTTP 路由声明，转换为已编译 register() 调用。 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Route
{
    /**
     * 声明一个路径的允许方法、可选名称和处理链。
     * @param list<string> $methods 允许的 HTTP 方法。
     * @param array<string, string> $constraints 参数名到匹配约束。
     * @param list<class-string> $middleware 按声明顺序调用的中间件类。
     */
    public function __construct(
        public string $path,
        public array $methods = ['GET'],
        public ?string $name = null,
        public array $constraints = [],
        public array $middleware = []
    ) {
    }
}
