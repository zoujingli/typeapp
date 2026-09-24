<?php

declare(strict_types=1);

namespace Type\Cache;

use Closure;
use InvalidArgumentException;
use Throwable;

/** 以显式 codec 和有限毫秒 TTL 保存业务数据；并发回源不互斥。 */
final class TypedCache
{
    private NamespaceStore $store;
    private Codec $codec;
    private int $ttl;
    private int $maximumTtl;

    /**
     * 配置有限 TTL 缓存；期限单位均为毫秒，默认值必须为正且不超过最大值。
     *
     * @throws InvalidArgumentException 默认期限或上限无效；最大期限不能超过一年。
     */
    public function __construct(NamespaceStore $store, Codec $codec, int $ttlMilliseconds = 60000, int $maximumTtlMilliseconds = 86400000)
    {
        if ($ttlMilliseconds < 1 || $maximumTtlMilliseconds < $ttlMilliseconds || $maximumTtlMilliseconds > 31536000000) {
            throw new InvalidArgumentException('类型化缓存必须使用有限且有上限的 TTL');
        }
        $this->store = $store;
        $this->codec = $codec;
        $this->ttl = $ttlMilliseconds;
        $this->maximumTtl = $maximumTtlMilliseconds;
    }

    /** 读取单值并区分未命中与命中的 null；格式不符或解码失败作为未命中。 */
    public function get(string $key): CacheItem
    {
        return $this->getMany([$key])[0];
    }

    /**
     * 按输入位置返回读取结果，重复键仍保留各自位置。
     *
     * @param list<string> $keys
     * @return list<CacheItem>
     */
    public function getMany(array $keys): array
    {
        $snapshot = $this->store->read($keys);
        $items = [];
        foreach ($snapshot['values'] as $value) {
            $items[] = $this->decode($value);
        }
        return $items;
    }

    /** 编码后写入有限 TTL 值；非正 TTL 删除，读取代次过期时返回 false。 */
    public function put(string $key, mixed $value, ?int $ttlMilliseconds = null): bool
    {
        return $this->putMany([$key], [$value], $ttlMilliseconds);
    }

    /**
     * 先编码整批值，再在同一代写入；键和值须逐位置对应。
     *
     * @param list<string> $keys
     * @param list<mixed> $values
     * @param int|null $ttlMilliseconds null 沿用默认，超过最大值拒绝。
     */
    public function putMany(array $keys, array $values, ?int $ttlMilliseconds = null): bool
    {
        if (count($keys) !== count($values) || !array_is_list($values)) {
            throw new InvalidArgumentException('缓存键和值必须对应');
        }
        $payloads = [];
        foreach ($values as $value) {
            $payloads[] = $this->encode($value);
        }
        $ttl = $this->ttl($ttlMilliseconds);
        if ($ttl <= 0) {
            return $this->store->delete($keys);
        }
        $snapshot = $this->store->read([]);
        return $this->store->write($snapshot['generation'], $keys, $payloads, $ttl);
    }

    /**
     * 命中直接返回；回源异常不缓存，旧代结果不回填新代，不提供并发回源互斥。
     *
     * @param Closure(): mixed $loader 零参数回源，依赖通过显式捕获传入。
     * @param bool $bypass 为 true 时只回源，不读取或回填缓存。
     */
    public function remember(string $key, Closure $loader, ?int $ttlMilliseconds = null, bool $bypass = false): mixed
    {
        if ($bypass) {
            return $loader();
        }
        $snapshot = $this->store->read([$key]);
        $item = $this->decode($snapshot['values'][0]);
        if ($item->hit()) {
            return $item->value();
        }
        $value = $loader();
        $ttl = $this->ttl($ttlMilliseconds);
        if ($ttl > 0) {
            $this->store->write($snapshot['generation'], [$key], [$this->encode($value)], $ttl);
        }
        return $value;
    }

    /** 删除当前代中的键，不影响同名的其他应用或格式命名空间。 */
    public function delete(string $key): bool
    {
        return $this->store->delete([$key]);
    }
    /**
     * 批量删除当前代的键，已缺失的键不构成失败。
     *
     * @param list<string> $keys
     */
    public function deleteMany(array $keys): bool
    {
        return $this->store->delete($keys);
    }
    /** 切换代次立即失效；返回新代身份，旧数据留待有界回收。 */
    public function clear(): string
    {
        return $this->store->clear();
    }
    /** 回收旧代，limit 限制本次索引及代次处理量，返回实际删除键数。 */
    public function collect(int $limit = 100): int
    {
        return $this->store->collect($limit);
    }

    private function ttl(?int $value): int
    {
        $value ??= $this->ttl;
        if ($value > $this->maximumTtl) {
            throw new InvalidArgumentException('缓存 TTL 超出配置上限');
        }
        return $value;
    }

    private function encode(mixed $value): string
    {
        return $this->codec->format() . "\n" . $this->codec->encode($value);
    }

    private function decode(mixed $payload): CacheItem
    {
        $prefix = $this->codec->format() . "\n";
        if (!is_string($payload) || !str_starts_with($payload, $prefix)) {
            return new CacheItem(false);
        }
        try {
            return new CacheItem(true, $this->codec->decode(substr($payload, strlen($prefix))));
        } catch (Throwable) {
            return new CacheItem(false);
        }
    }
}
