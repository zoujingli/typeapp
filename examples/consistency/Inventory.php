<?php

declare(strict_types=1);

namespace TypeApp\ConsistencyExample;

use Type\Cache\CacheReader;
use Type\Cache\TypedCache;
use Type\Orm\Connection;
use Type\Orm\ReadWriteSession;

final class Inventory
{
    private ReadWriteSession $database;
    private TypedCache $cache;
    private CacheReader $reader;
    public function __construct(ReadWriteSession $database, TypedCache $cache, bool $fallback = false)
    {
        $this->database = $database;
        $this->cache = $cache;
        $this->reader = new CacheReader($cache, $fallback);
    }
    public function amount(int $id, bool $strong = false): int
    {
        return (int) $this->reader->read('inventory-' . $id, function (bool $strong) use ($id): int {
            return (int) $this->database->read($strong)->table('type_inventory')->where('id', '=', $id)->first()['amount'];
        }, $strong);
    }
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
