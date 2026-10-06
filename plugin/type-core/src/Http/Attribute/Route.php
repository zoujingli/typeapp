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
     * @param int|null $status 固定成功状态；数组使用有正文的 2xx，void 只能使用 204。
     * @param array{maxBytes?: int, maxDepth?: int, maxQueryBytes?: int, maxQueryFields?: int, scenario?: string, patch?: bool} $input 已校验输入策略，PATCH 默认部分更新；预算取声明与接入层的较小值。
     */
    public function __construct(
        public string $path,
        public array $methods = ['GET'],
        public ?string $name = null,
        public array $constraints = [],
        public array $middleware = [],
        public ?int $status = null,
        public array $input = []
    ) {
    }
}
