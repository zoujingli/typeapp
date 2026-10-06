<?php

declare(strict_types=1);

namespace TypeApp\Optional;

/** 组件默认服务由独立应用同名显式服务覆盖。 */
final class DefaultGreeting
{
    /** 返回组件默认文字，应用可选择自己的配置服务。 */
    public function message(): string
    {
        return '组件默认问候';
    }
}
