<?php

declare(strict_types=1);

namespace TypeApp\SchedulerExample;

/** 调度基础设施由角色入口持有，时间计划与 Task 工厂由构建生成。 */
final class Application
{
    /** @param list<string> $arguments 完整程序参数。 */
    public static function run(array $arguments, bool $development): void
    {
        \schedulerMain(count($arguments), $arguments);
    }
}
