<?php

declare(strict_types=1);

namespace app\broker\service;

use Type\Orm\Connection;

/** 独立 Broker 只暴露恢复启动门与只读进度；状态机仍由公共恢复核对拥有。 */
final class RecoveryService
{
    /**
     * 独立节点与管理 HTTP 启动门。
     *
     * @throws \Type\Core\Http\HttpError 恢复核对尚未结束。
     */
    public static function ready(Connection $connection): void
    {
        \app\iot\service\RecoveryService::ready($connection, 'broker');
    }

    /**
     * 只读进度；不含主体指纹或秘密。
     *
     * @return array<string, mixed>
     */
    public static function status(Connection $connection, string $host): array
    {
        return \app\iot\service\RecoveryService::status($connection, $host);
    }
}
