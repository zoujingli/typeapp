<?php

declare(strict_types=1);

namespace Type\Runtime;

/** 可直接转换为公开边界响应的失败契约；业务异常不应自行依赖 HTTP。 */
interface PublicFailureInterface extends FailureInterface
{
    /** 返回边界适配器使用的状态码，不代表领域异常必须依赖 HTTP。 */
    public function status(): int;

    /** 返回不含秘密和原始输入值的公开响应体。 @return array<string, mixed> */
    public function body(): array;
}
