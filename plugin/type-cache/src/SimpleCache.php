<?php

declare(strict_types=1);

namespace Type\Cache;

use DateInterval;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/** 通过命名空间代次和签名载荷实现 PSR-16；Redis 故障不会自动降级成功。 */
final class SimpleCache implements CacheInterface
{
    private NamespaceStore $store;
    private SignedSerializer $serializer;
    private ?int $defaultTtl;

    /** 绑定命名空间与受信序列化策略；默认 TTL 单位秒，null 表示永久。 */
    public function __construct(NamespaceStore $store, SignedSerializer $serializer, ?int $defaultTtlSeconds = null)
    {
        $this->store = $store;
        $this->serializer = $serializer;
        $this->defaultTtl = $defaultTtlSeconds;
        $this->milliseconds($defaultTtlSeconds);
    }

    /** 返回已验证缓存值；缺失、签名或解码失败使用默认值，命中的 null 保持为 null。 */
    public function get(string $key, mixed $default = null): mixed
    {
        $item = $this->item($key);
        return $item->hit() ? $item->value() : $default;
    }

    /** 按载荷完整性判断命中，不保证随后读取时键仍存在。 */
    public function has(string $key): bool
    {
        return $this->item($key)->hit();
    }

    /** 按 PSR-16 TTL 写入；null 沿用默认，非正 TTL 删除，代次变化可返回 false。 */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        return $this->setMultiple([$key => $value], $ttl);
    }

    /** 删除当前命名空间的单个合法键，键不存在仍返回成功。 */
    public function delete(string $key): bool
    {
        $this->key($key);
        return $this->store->delete([$key]);
    }
    /** 切换命名空间代次使旧数据不可见；物理清理由 collect 或数据 TTL 完成。 */
    public function clear(): bool
    {
        $this->store->clear();
        return true;
    }

    /**
     * 批量读取并按键返回业务值或默认值；使用同一次代次快照。
     *
     * @param iterable<string> $keys
     * @return iterable<array-key, mixed> 整数字符串键遵循 PHP 数组键转换。
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $list = $this->keyList($keys);
        $snapshot = $this->store->read($list);
        $result = [];
        foreach ($list as $index => $key) {
            $item = $this->decode($snapshot['values'][$index], $snapshot['generation'], $key);
            $result[$key] = $item->hit() ? $item->value() : $default;
        }
        return $result;
    }

    /**
     * 先校验与编码整个批次再写入当前代，最多 1000 项；TTL 单位遵循 PSR-16。
     *
     * @param iterable<array-key, mixed> $values 整数键转换为对应字符串缓存键。
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $keys = [];
        $items = [];
        foreach ($values as $key => $value) {
            if (is_int($key)) {
                $key = (string) $key;
            }
            $this->key($key);
            $keys[] = $key;
            $items[] = $value;
            if (count($keys) > 1000) {
                throw new InvalidCacheArgument('缓存批次超过 1000 项');
            }
        }
        $milliseconds = $this->milliseconds($ttl);
        if ($milliseconds !== null && $milliseconds <= 0) {
            return $this->store->delete($keys);
        }
        $generation = $this->store->read([])['generation'];
        $payloads = [];
        foreach ($items as $index => $value) {
            $payloads[] = $this->serializer->encode($value, $this->store->identity(), $generation, $keys[$index]);
        }
        return $this->store->write($generation, $keys, $payloads, $milliseconds);
    }

    /**
     * 按当前代批量删除；非法键或超出 1000 项时拒绝。
     *
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        return $this->store->delete($this->keyList($keys));
    }
    /** 使用 1 至 1000 个索引或旧代标记的预算回收，返回实际移除的缓存键数。 */
    public function collect(int $limit = 100): int
    {
        return $this->store->collect($limit);
    }

    private function item(string $key): CacheItem
    {
        $this->key($key);
        $snapshot = $this->store->read([$key]);
        return $this->decode($snapshot['values'][0], $snapshot['generation'], $key);
    }

    private function decode(mixed $payload, string $generation, string $key): CacheItem
    {
        if (!is_string($payload)) {
            return new CacheItem(false);
        }
        try {
            return new CacheItem(true, $this->serializer->decode($payload, $this->store->identity(), $generation, $key));
        } catch (Throwable) {
            return new CacheItem(false);
        }
    }

    private function key(mixed $key): void
    {
        if (!is_string($key) || $key === '' || strlen($key) > 1024 || preg_match('/[{}()\/@:\\\\\x00]/', $key)) {
            throw new InvalidCacheArgument('PSR-16 缓存键为空、超长或包含保留字符');
        }
    }

    private function keyList(iterable $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $this->key($key);
            $result[] = $key;
            if (count($result) > 1000) {
                throw new InvalidCacheArgument('缓存批次超过 1000 项');
            }
        }
        return $result;
    }

    private function milliseconds(null|int|DateInterval $ttl): ?int
    {
        if ($ttl instanceof DateInterval) {
            $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
            try {
                $milliseconds = ceil(((float) $now->add($ttl)->format('U.u') - (float) $now->format('U.u')) * 1000);
            } catch (Throwable $error) {
                throw new InvalidCacheArgument('缓存 DateInterval 无效', 0, $error);
            }
            if (!is_finite($milliseconds) || $milliseconds > PHP_INT_MAX) {
                throw new InvalidCacheArgument('缓存 TTL 超出原生整数范围');
            }
            return $milliseconds <= 0 ? 0 : (int) $milliseconds;
        }
        $ttl ??= $this->defaultTtl;
        if ($ttl === null) {
            return null;
        }
        if ($ttl > intdiv(PHP_INT_MAX, 1000)) {
            throw new InvalidCacheArgument('缓存 TTL 超出原生整数范围');
        }
        return $ttl <= 0 ? 0 : $ttl * 1000;
    }
}
