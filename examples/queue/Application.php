<?php

declare(strict_types=1);

namespace TypeApp\QueueExample;

/** 队列基础设施仍由显式启动入口持有，业务 Job 使用生成依赖图。 */
final class Application
{
    /** @param list<string> $arguments 完整程序参数。 */
    public static function run(array $arguments, bool $development): void
    {
        \queueMain(count($arguments), $arguments);
    }
}
