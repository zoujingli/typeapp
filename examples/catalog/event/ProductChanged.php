<?php

declare(strict_types=1);

namespace app\catalog\event;

/** 同步事件是当前调用的值；本对象不表示持久投递或跨进程保证。 */
final class ProductChanged
{
    /** @var list<bool> 监听执行时是否仍处于 catalog 事务。 */
    public array $transactions = [];

    /** 商品 ID 已由数据库写入确定，phase 说明调用者选择的时机。 */
    public function __construct(public int $productId, public string $phase)
    {
    }
}
