<?php

declare(strict_types=1);

/**
 * 运行离线路由匹配、命名 URL 与中间件契约，全部通过才输出成功。
 *
 * @param list<string> $argv 程序路径与该示例的显式参数。
 */
function main(int $argc, array $argv): void
{
    TypeApp\RoutingExample\Exercise::run();
    echo "参数路由、命名 URL、中间件及匹配规则验证通过。\n";
}
