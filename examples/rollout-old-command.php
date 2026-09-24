<?php

declare(strict_types=1);

/**
 * 启动旧版发布角色，和新版共用兼容演练协议及外置配置。
 *
 * @param list<string> $argv 程序路径与该示例的显式参数。
 */
function main(int $argc, array $argv): void
{
    \TypeApp\Rollout\Application::run(1, $argv);
}
