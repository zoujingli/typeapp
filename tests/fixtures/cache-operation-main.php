<?php

declare(strict_types=1);

use Type\Cache\JsonCodec;
use Type\Cache\NamespaceStore;
use Type\Cache\TypedCache;
use Type\Redis\RedisConfiguration;
use Type\Redis\Purpose;
use Type\Redis\RedisManager;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\ExecutionScope;
use TypeTests\CacheOperations\Service;

/** 标准入口转换后验证原 Service，不安装或探测 ORM。 */
function main(int $argc, array $argv): void
{
    CoroutineRuntime::enableIo();
    CoroutineRuntime::run(static function (): void {
        $manager = new RedisManager(['default' => new RedisConfiguration((string) (getenv('TYPE_REDIS_HOST') ?: '127.0.0.1'), (int) (getenv('TYPE_REDIS_PORT') ?: 6379))], [Purpose::SCRIPT => 1]);
        $scope = new ExecutionScope();
        try {
            $scope->run(static function (ExecutionScope $current) use ($manager): void {
                $redis = $manager->connection($current, 'default', Purpose::SCRIPT);
                $cache = new TypedCache(new NamespaceStore($redis, 'cache-operation-' . bin2hex(random_bytes(8)), 'test', 'v1'), JsonCodec::data());
                try {
                    $service = new Service();
                    if ($service->indirect($cache, 1) !== 'value-1' || $service->read($cache, 1) !== 'value-1' || $service->loads !== 1
                        || $service->read($cache, 0) !== null || $service->indirect($cache, 0) !== null || $service->loads !== 2) {
                        throw new RuntimeException('原 Service 缓存调用或 null 命中失败');
                    }
                    $manual = new Service();
                    if ($manual->read($cache, 1) !== 'value-1' || $manual->loads !== 0) {
                        throw new RuntimeException('手动构造绕过了声明');
                    }
                    $manual->evict($cache, 1);
                    if ($manual->indirect($cache, 1) !== 'value-1' || $manual->loads !== 1) {
                        throw new RuntimeException('缓存失效声明没有执行');
                    }
                } finally {
                    $cache->clear();
                }
            });
        } finally {
            $scope->close();
            $manager->close();
        }
    });
    echo "原 Service 缓存声明独立消费、类内互调与手动构造通过。\n";
}
