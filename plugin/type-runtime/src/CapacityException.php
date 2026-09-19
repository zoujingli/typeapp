<?php

declare(strict_types=1);

namespace Type\Runtime;

/** 框架容量拒绝；同时识别显式文件候选在所属 PHP 线程抛出的原生拒绝。 */
final class CapacityException extends \RuntimeException
{
    /**
     * HTTP 等协议边界使用同一判定；不根据 errno 或任意异常的消息推断容量不足。
     * aio_capacity_exceeded 属于 TypeApp 文件补丁，不是上游 Swoole 的通用异常约定。
     */
    public static function matches(\Throwable $error): bool
    {
        return $error instanceof self
            || ($error instanceof \Swoole\Exception && $error->getMessage() === 'aio_capacity_exceeded');
    }
}
