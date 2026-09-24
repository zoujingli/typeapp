<?php

declare(strict_types=1);

namespace TypeApp\ConsistencyExample;

use Type\Cache\CacheReader;
use Type\Cache\TypedCache;
use Type\Orm\Connection;
use Type\Orm\ReadWriteSession;

/** 用库存值演示读写会话、缓存回源与提交后失效之间的边界。 */
final class Inventory
{
    private ReadWriteSession $database;
    private TypedCache $cache;
    private CacheReader $reader;
    /** 注入读写会话与缓存，明确是否允许缓存失败回退至数据库。 */
    public function __construct(ReadWriteSession $database, TypedCache $cache, bool $fallback = false)
    {
        $this->database = $database;
        $this->cache = $cache;
        $this->reader = new CacheReader($cache, $fallback);
    }
    /** 按 strong 选择强一致回源或缓存读取，返回库存整数。 */
    public function amount(int $id, bool $strong = false): int
    {
        return (int) $this->reader->read('inventory-' . $id, function (bool $strong) use ($id): int {
            return (int) $this->database->read($strong)->table('type_inventory')->where('id', '=', $id)->first()['amount'];
        }, $strong);
    }
    /** 在事务内修改库存并登记提交后失效；rollback 模式验证缓存不提前删除。 */
    public function change(int $id, int $amount, bool $rollback = false): void
    {
        $this->database->transaction(function (Connection $transaction) use ($id, $amount, $rollback): void {
            $transaction->table('type_inventory')->where('id', '=', $id)->update(['amount' => $amount]);
            $transaction->afterCommit(fn (): bool => $this->cache->delete('inventory-' . $id));
            if ($rollback) {
                throw new \RuntimeException('回滚库存');
            }
        });
    }
}
