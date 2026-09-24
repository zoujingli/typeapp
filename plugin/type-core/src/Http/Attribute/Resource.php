<?php

declare(strict_types=1);

namespace Type\Core\Http\Attribute;

/** 构建期把资源控制器的显式动作集展开为普通路由。 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class Resource
{
    /**
     * 声明资源路径和名称，only 仅公开列出的约定动作。
     * @param list<string> $only 需要生成路由的控制器动作名。
     * @param array<string, string> $constraints 参数名到匹配约束。
     * @param list<class-string> $middleware 按声明顺序调用的中间件类。
     */
    public function __construct(
        public string $path,
        public string $name,
        public string $parameter = 'id',
        public array $only = ['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'],
        public array $constraints = [],
        public array $middleware = []
    ) {
    }
}
