<?php

declare(strict_types=1);

use Type\Cache\CacheReader;
use Type\Cache\JsonCodec;
use Type\Cache\NamespaceStore;
use Type\Cache\TypedCache;
use Type\Orm\DatabaseManager;
use Type\Orm\ReadWriteSession;
use Type\Redis\Purpose;
use Type\Redis\RedisConfiguration;
use Type\Redis\RedisException;
use Type\Redis\RedisManager;
use Type\Runtime\ExecutionScope;
use TypeApp\ConsistencyExample\Inventory;
use TypeApp\ModelExample\Drivers;

/**
 * 将当前示例的行为断言转为明确失败，避免只输出成功文字而忽略实际状态。
 *
 * @throws \RuntimeException 条件不成立。
 */
function consistencyExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * 组合独立 Redis 与数据库验证回填交错、提交失效和回滚保留。
 *
 * @param list<string> $argv 程序路径与该示例的显式参数。
 */
function main(int $argc, array $argv): void
{
    $driver = (string) ($argv[1] ?? 'sqlite');
    $primary = (string) getenv($driver === 'sqlite' ? 'TYPE_PRIMARY_FILE' : 'TYPE_PRIMARY_DATABASE');
    $databases = new DatabaseManager(['primary' => Drivers::create($driver, $primary)]);
    $redis = new RedisManager(['default' => new RedisConfiguration((string) (getenv('TYPE_REDIS_HOST') ?: '127.0.0.1'), (int) (getenv('TYPE_REDIS_PORT') ?: 6379))]);
    $scope = new ExecutionScope();
    $otherScope = new ExecutionScope();
    try {
        $connection = $databases->connect($scope, 'primary');
        $connection->raw('CREATE TABLE type_inventory (id INTEGER PRIMARY KEY, amount INTEGER)');
        $connection->table('type_inventory')->insert(['id' => 1, 'amount' => 1]);
        $script = $redis->connection($scope, 'default', Purpose::SCRIPT);
        $cache = new TypedCache(new NamespaceStore($script, 'consistency-' . bin2hex(random_bytes(8)), 'test', 'inventory-v1'), JsonCodec::data(), 100, 1000);
        $session = new ReadWriteSession($databases, $scope, 'primary', null);
        $inventory = new Inventory($session, $cache);
        // 真实交错：先读旧值、再提交新值并失效，最后旧读取回填。
        $stale = $cache->remember('inventory-1', static function () use ($session, $inventory): int {
            $value = (int) $session->read()->table('type_inventory')->where('id', '=', 1)->first()['amount'];
            $inventory->change(1, 2);
            return $value;
        });
        consistencyExpect($stale === 1 && $inventory->amount(1) === 1 && $inventory->amount(1, true) === 2, '交错读取没有满足最终一致/强一致边界');
        try {
            $inventory->change(1, 3, true);
        } catch (RuntimeException $error) {
            consistencyExpect($error->getMessage() === '回滚库存', '回滚原错误丢失');
        }
        consistencyExpect($cache->get('inventory-1')->hit() && $cache->get('inventory-1')->value() === 1 && $inventory->amount(1, true) === 2, '回滚错误失效缓存或改变主库');
        usleep(150000);
        consistencyExpect($inventory->amount(1) === 2, '旧回填没有在有限 TTL 内消失');
        $cache->delete('inventory-1');
        $other = new Inventory(new ReadWriteSession($databases, $otherScope, 'primary', null), $cache);
        consistencyExpect($other->amount(1) === 2 && $other->amount(1, true) === 2, '清除旧缓存后未从主库取得新值');
        $attempted = 0;
        try {
            (new CacheReader($cache, true))->read('source-error', static function (bool $strong) use (&$attempted): int {
                $attempted++;
                throw new RedisException('source_failed', 'UNKNOWN', '回源本身失败');
            });
        } catch (RedisException) {
        }
        consistencyExpect($attempted === 1, '把回源异常误判为缓存错误并自动重试');
        // 杀死专属测试 Redis 会话，不能伪装缓存始终可用。
        $clientId = $script->identity();
        $admin = new Redis();
        $admin->connect((string) (getenv('TYPE_REDIS_HOST') ?: '127.0.0.1'), (int) (getenv('TYPE_REDIS_PORT') ?: 6379));
        $admin->rawCommand('CLIENT', 'KILL', 'ID', $clientId);
        $admin->close();
        $calls = 0;
        $fallback = new CacheReader($cache, true);
        consistencyExpect($fallback->read('failure-key', static function (bool $strong) use (&$calls): int {
            $calls++;
            return 7;
        }) === 7 && $calls === 1, '缓存故障回源重复执行或没有明确策略');
        $rejected = false;
        try {
            (new CacheReader($cache))->read('failure-key', static fn (bool $strong): int => 8);
        } catch (RedisException) {
            $rejected = true;
        }
        consistencyExpect($rejected, '默认缓存错误被静默吞掉');
        consistencyExpect((new CacheReader($cache))->read('failure-key', static fn (bool $strong): int => $strong ? 9 : 0, true) === 9, '强一致受缓存故障影响');
        echo "缓存回填交错、提交失效、回滚保留、TTL 与强一致回源通过。\n";
    } finally {
        $scope->close();
        $otherScope->close();
        $databases->close();
        $redis->close();
    }
}
