<?php

declare(strict_types=1);

use Type\Cache\JsonCodec;
use Type\Cache\NamespaceStore;
use Type\Cache\TypedCache;
use Type\Redis\Purpose;
use Type\Redis\RedisConfiguration;
use Type\Redis\RedisManager;
use Type\Runtime\ExecutionScope;
use TypeApp\CacheExample\Profile;

/**
 * 将当前示例的行为断言转为明确失败，避免只输出成功文字而忽略实际状态。
 *
 * @throws \RuntimeException 条件不成立。
 */
function cacheExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * 以专属缓存命名空间验证类型、TTL、回源、代次隔离与有界回收。
 *
 * @param list<string> $argv 程序路径与该示例的显式参数。
 */
function main(int $argc, array $argv): void
{
    $manager = new RedisManager(['default' => new RedisConfiguration(
        (string) (getenv('TYPE_REDIS_HOST') ?: '127.0.0.1'),
        (int) (getenv('TYPE_REDIS_PORT') ?: 6379)
    )], [Purpose::SCRIPT => 4]);
    $scope = new ExecutionScope();
    $application = (string) (getenv('TYPE_CACHE_APPLICATION') ?: 'cache-test-' . bin2hex(random_bytes(8)));
    try {
        $redis = $manager->connection($scope, 'default', Purpose::SCRIPT);
        $store = new NamespaceStore($redis, $application, 'test', 'v1');
        $cache = new TypedCache($store, JsonCodec::data(), 1000, 10000);
        if (($argv[1] ?? '') === 'clear') {
            echo $cache->clear() . PHP_EOL;
            return;
        }
        if (($argv[1] ?? '') === 'collect') {
            $cache->collect(1000);
            return;
        }
        cacheExpect(!$cache->get('missing')->hit(), '缺失缓存被报告命中');
        $cache->put('null', null);
        cacheExpect($cache->get('null')->hit() && $cache->get('null')->value() === null, 'null 没有与未命中区分');
        $profileCodec = new JsonCodec('profile-v1', static function (mixed $value): array {
            if (!$value instanceof Profile) {
                throw new InvalidArgumentException('需要 Profile DTO');
            }
            return $value->toArray();
        }, static fn (mixed $value): Profile => Profile::fromData($value));
        $profileCache = new TypedCache(new NamespaceStore($manager->connection($scope, 'default', Purpose::SCRIPT), $application, 'test', 'profile-v1'), $profileCodec);
        $profileCache->put('user', new Profile(7, '开发者'));
        $profile = $profileCache->get('user')->value();
        cacheExpect($profile instanceof Profile && $profile->id() === 7 && $profile->name() === '开发者', '显式 DTO codec 没有往返');
        $cache->putMany(['a', 'b', 'c'], [['name' => '中文', 'count' => 3, 'ratio' => 1.0], false, '']);
        $items = $cache->getMany(['a', 'b', 'c']);
        cacheExpect($items[0]->value() === ['name' => '中文', 'count' => 3, 'ratio' => 1.0] && $items[1]->value() === false && $items[2]->value() === '', '批量数据类型往返失败');
        $rejected = false;
        try {
            $cache->put('object', new stdClass());
        } catch (InvalidArgumentException) {
            $rejected = true;
        }
        cacheExpect($rejected, '受限 JSON 缓存错误接受对象');
        $cache->put('short', '过期', 30);
        usleep(60000);
        cacheExpect(!$cache->get('short')->hit(), '有限 TTL 没有过期');
        $cache->put('deleted', '内容');
        $cache->put('deleted', '内容', 0);
        cacheExpect(!$cache->get('deleted')->hit(), '零 TTL 没有删除');
        $calls = 0;
        $loader = static function () use (&$calls): array {
            $calls++;
            return ['id' => 1];
        };
        cacheExpect($cache->remember('origin', $loader) === ['id' => 1] && $cache->remember('origin', $loader) === ['id' => 1]
            && $calls === 1, '缓存回源策略错误');
        $cache->remember('origin', $loader, null, true);
        cacheExpect($calls === 2, '强制回源没有绕过缓存');
        $snapshot = $store->read([]);
        $cache->clear();
        cacheExpect(!$store->write($snapshot['generation'], ['old-writer'], ['json-data-v1\n1'], 1000) && !$cache->get('a')->hit(), 'clear 后旧写入污染新代次');
        $value = $cache->remember('cross-clear', static function () use ($cache): string {
            $cache->clear();
            return '旧回源';
        });
        cacheExpect($value === '旧回源' && !$cache->get('cross-clear')->hit(), '跨 clear 回源写入了新代次');
        $other = new TypedCache(new NamespaceStore($manager->connection($scope, 'default', Purpose::SCRIPT), $application, 'another-env', 'v1'), JsonCodec::data());
        $other->put('key', '隔离');
        $cache->put('key', '当前环境');
        $cache->clear();
        cacheExpect($other->get('key')->value() === '隔离', '命名空间清理影响其他环境');
        $snapshot = $store->read([]);
        $store->write($snapshot['generation'], ['bad'], ['future-format\n{}'], 1000);
        cacheExpect(!$cache->get('bad')->hit(), '未知格式没有当作未命中');
        $cache->put('gc1', '回收1', 10000);
        $cache->put('gc2', '回收2', 10000);
        $cache->clear();
        $removed = 0;
        for ($index = 0; $index < 20; $index++) {
            $removed += $cache->collect(2);
        }
        cacheExpect($removed >= 2, '旧代缓存没有被有界回收');
        $other->clear();
        $other->collect();
        $profileCache->clear();
        $profileCache->collect();
        echo "类型化缓存、缺失值、TTL、回源、代次隔离与有界回收通过。\n";
    } finally {
        $scope->close();
        $manager->close();
    }
}
