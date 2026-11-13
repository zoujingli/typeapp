<?php

declare(strict_types=1);
use TypeApp\Rollout\Application;

/**
 * 启动新版发布角色，验证与旧版并行时的 schema、消息和缓存兼容。
 *
 * @param list<string> $argv 程序路径与该示例的显式参数
 */
function main(int $argc, array $argv): void
{
    Application::run(2, $argv);
}
