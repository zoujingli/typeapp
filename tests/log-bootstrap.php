<?php

declare(strict_types=1);

$logAutoload = getenv('TYPE_LOG_AUTOLOAD');
$logLoader = require $logAutoload ?: dirname(__DIR__) . '/vendor/autoload.php';
// 聚合仓尚未登记新插件时，仅测试入口补充本地 PSR-4；独立消费者验证真实 Composer 安装。
if (!$logAutoload && !isset($logLoader->getPrefixesPsr4()['Type\\Log\\'])) {
    $logLoader->addPsr4('Type\\Log\\', dirname(__DIR__) . '/plugin/type-log/src');
}
