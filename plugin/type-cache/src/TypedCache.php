<?php

declare(strict_types=1);

namespace Type\Cache;

use Closure;
use InvalidArgumentException;
use Throwable;

final class TypedCache
{
    private NamespaceStore $store;
    private Codec $codec;
    private int $ttl;
    private int $maximumTtl;

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

    public function get(string $key): CacheItem
    {
        return $this->getMany([$key])[0];
    }

    public function getMany(array $keys): array
    {
        $snapshot = $this->store->read($keys);
        $items = [];
        foreach ($snapshot['values'] as $value) {
            $items[] = $this->decode($value);
        }
        return $items;
    }

    public function put(string $key, mixed $value, ?int $ttlMilliseconds = null): bool
    {
        return $this->putMany([$key], [$value], $ttlMilliseconds);
    }

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

    /** @param Closure(): mixed $loader 零参数回源，依赖通过显式捕获传入。 */
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

    public function delete(string $key): bool
    {
        return $this->store->delete([$key]);
    }
    public function deleteMany(array $keys): bool
    {
        return $this->store->delete($keys);
    }
    public function clear(): string
    {
        return $this->store->clear();
    }
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
