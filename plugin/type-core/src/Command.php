<?php

declare(strict_types=1);

namespace Type\Core;

/** 应用命令的显式入口，由 Application 负责启动和结束通知。 */
interface Command
{
    /**
     * 执行命令并返回操作系统退出码，0 表示成功。
     * @param list<string> $arguments 命令调用方传入的完整参数。
     */
    public function run(Configuration $configuration, array $arguments): int;
}
