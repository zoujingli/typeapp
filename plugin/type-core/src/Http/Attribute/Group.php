<?php

declare(strict_types=1);

namespace Type\Core\Http\Attribute;

/** 控制器的构建期路由分组声明，生产请求不通过反射读取。 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Group
{
    /**
     * 为控制器路由声明共同前缀、约束与中间件。
     * @param list<class-string> $middleware 按声明顺序调用的中间件类。
     * @param array<string, string> $constraints 参数名到匹配约束。
     */
    public function __construct(
        public string $prefix = '',
        public string $namePrefix = '',
        public array $middleware = [],
        public array $constraints = []
    ) {
    }
}
