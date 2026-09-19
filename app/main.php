<?php

declare(strict_types=1);

use app\common\bootstrap\Application;

/**
 * 原生应用的唯一全局入口，不加载 PHP 源码或开发生成器。
 *
 * @param list<string> $argv 操作系统提供的完整命令参数。
 */
function main(int $argc, array $argv): void
{
    $GLOBALS['type_app_exit_status'] = Application::run($argv, false);
}
