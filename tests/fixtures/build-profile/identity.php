<?php

declare(strict_types=1);

namespace Type\Generated;

/** 独立测试进程中的生成身份夹具，不进入生产源码清单。 */
final class BuildIdentity
{
    public static array $profile = [];

    public static function info(): array
    {
        return ['profile' => self::$profile];
    }
}
