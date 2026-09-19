<?php

declare(strict_types=1);

namespace Type\Redis;

/** 在实际写入目标中原子校验执行权限后运行可信脚本；实现不得只做先查后写。 */
interface ScriptGuard
{
    public function execute(RedisConnection $target, string $script, array $keys, array $arguments): mixed;
}
