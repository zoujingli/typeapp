<?php

declare(strict_types=1);

use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgument;
use Type\Cache\NamespaceStore;
use Type\Cache\SignedSerializer;
use Type\Cache\SimpleCache;
use Type\Redis\Purpose;
use Type\Redis\RedisConfiguration;
use Type\Redis\RedisManager;
use Type\Runtime\ExecutionScope;
use TypeApp\CacheExample\SerializableNote;

function psrCacheExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function main(int $argc, array $argv): void
{
    $manager = new RedisManager(['default' => new RedisConfiguration(
        (string) (getenv('TYPE_REDIS_HOST') ?: '127.0.0.1'),
        (int) (getenv('TYPE_REDIS_PORT') ?: 6379)
    )], [Purpose::SCRIPT => 3]);
    $scope = new ExecutionScope();
    try {
        $script = $manager->connection($scope, 'default', Purpose::SCRIPT);
        $store = new NamespaceStore($script, 'type_psr_test_' . bin2hex(random_bytes(8)), 'test', 'signed-v1');
        $secret = random_bytes(32);
        $serializer = new SignedSerializer($secret, [SerializableNote::class, stdClass::class, DateTimeImmutable::class]);
        $cache = new SimpleCache($store, $serializer);
        psrCacheExpect($cache instanceof CacheInterface, '缓存没有实现 PSR-16');
        $values = [null, false, true, 0, -10, PHP_INT_MAX, 1.0, '1', '', "\0binary", ['a' => null, 7 => false], INF, -INF, NAN];
        foreach ($values as $index => $value) {
            psrCacheExpect($cache->set('value' . $index, $value) && $cache->has('value' . $index), 'PSR 值没有保存');
            $read = $cache->get('value' . $index, 'default');
            psrCacheExpect(is_float($value) && is_nan($value) ? is_float($read) && is_nan($read) : $read === $value, 'PSR 值类型不精确：' . $index);
        }
        psrCacheExpect($cache->get('absent', 'default') === 'default' && !$cache->has('absent'), 'PSR 未命中默认值错误');
        $object = new SerializableNote('内容');
        $cache->set('object', $object);
        $copy = $cache->get('object');
        psrCacheExpect($copy instanceof SerializableNote && $copy !== $object && $copy->text() === '内容' && SerializableNote::$awakened === 1, '对象没有精确往返');
        $date = new DateTimeImmutable('2026-09-09T03:04:05.123456+08:00');
        $cache->set('date', $date);
        psrCacheExpect($cache->get('date') == $date, '内部日期对象没有往返');
        $recursive = new stdClass();
        $recursive->self = $recursive;
        $cache->set('recursive', $recursive);
        $read = $cache->get('recursive');
        psrCacheExpect($read->self === $read, '循环对象引用丢失');
        $array = \std::any([]);
        $array['self'] = &$array;
        $cache->set('recursive-array', $array);
        $read = $cache->get('recursive-array');
        psrCacheExpect(isset($read['self']['self']), '循环数组引用没有往返');
        unset($array, $read);
        $cache->setMultiple(['one' => 1, 'two' => 2], new DateInterval('PT2S'));
        psrCacheExpect($cache->getMultiple(['one', 'two', 'three'], false) === ['one' => 1, 'two' => 2, 'three' => false], 'PSR 批量读取错误');
        $cache->deleteMultiple(['one', 'two']);
        psrCacheExpect(!$cache->has('one') && !$cache->has('two'), 'PSR 批量删除失败');
        $cache->set('zero', 1, 0);
        $cache->set('negative', 1, -1);
        $interval = new DateInterval('PT1S');
        $interval->invert = 1;
        $cache->set('interval', 1, $interval);
        psrCacheExpect(!$cache->has('zero') && !$cache->has('negative') && !$cache->has('interval'), '非正 TTL 没有删除');
        $cache->set('expires', 1, 1);
        usleep(1100000);
        psrCacheExpect(!$cache->has('expires'), 'PSR 秒 TTL 没有过期');
        $resource = tmpfile();
        $rejected = false;
        try {
            $cache->set('resource', ['nested' => $resource]);
        } catch (PsrInvalidArgument) {
            $rejected = true;
        }
        fclose($resource);
        psrCacheExpect($rejected, '资源被错误转换为缓存整数');
        foreach (['', 'a{', 'a}', 'a(', 'a)', 'a/', 'a\\', 'a@', 'a:'] as $key) {
            $rejected = false;
            try {
                $cache->get($key);
            } catch (PsrInvalidArgument) {
                $rejected = true;
            }
            psrCacheExpect($rejected, '非法 PSR 键未拒绝');
        }
        $snapshot = $store->read(['object']);
        $raw = $snapshot['values'][0];
        $awake = SerializableNote::$awakened;
        $tampered = substr($raw, 0, -1) . '!';
        $store->write($snapshot['generation'], ['object'], [$tampered], null);
        psrCacheExpect($cache->get('object', 'miss') === 'miss' && SerializableNote::$awakened === $awake, '篡改载荷在验签前执行了对象代码');
        $store->write($snapshot['generation'], ['moved'], [$raw], null);
        psrCacheExpect(!$cache->has('moved') && SerializableNote::$awakened === $awake, '跨键搬运的签名被接受');
        $cache->clear();
        $newGeneration = $store->read([])['generation'];
        $store->write($newGeneration, ['object'], [$raw], null);
        psrCacheExpect(!$cache->has('object') && SerializableNote::$awakened === $awake, '旧代载荷重放被接受');
        $untrusted = new SimpleCache($store, new SignedSerializer(random_bytes(32), [SerializableNote::class]));
        $cache->set('object', new SerializableNote('新值'));
        psrCacheExpect(!$untrusted->has('object'), '不同密钥或类型策略读取了对象');
        $cache->clear();
        $removed = 0;
        for ($index = 0; $index < 10; $index++) {
            $removed += $cache->collect(10);
        }
        psrCacheExpect($removed >= 15, '永久 TTL 旧代数据没有回收');
        echo "PSR-16 类型、对象、TTL、非法键、签名与永久数据回收通过。\n";
    } finally {
        $scope->close();
        $manager->close();
    }
}
