<?php

declare(strict_types=1);

function main(int $argc, array $argv): void
{
    TypeApp\RoutingExample\Exercise::run();
    echo "参数路由、命名 URL、中间件及匹配规则验证通过。\n";
}
