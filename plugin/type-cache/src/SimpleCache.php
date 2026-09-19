<?php

declare(strict_types=1);

namespace Type\Cache;

use DateInterval;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use Throwable;

final class SimpleCache implements CacheInterface
{
    private NamespaceStore $store;
    private SignedSerializer $serializer;
    private ?int $defaultTtl;

    public function __construct(NamespaceStore $store, SignedSerializer $serializer, ?int $defaultTtlSeconds = null)
    {
        $this->store = $store;
        $this->serializer = $serializer;
        $this->defaultTtl = $defaultTtlSeconds;
        $this->milliseconds($defaultTtlSeconds);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $item = $this->item($key);
        return $item->hit() ? $item->value() : $default;
    }

    public function has(string $key): bool
    {
        return $this->item($key)->hit();
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        return $this->setMultiple([$key => $value], $ttl);
    }

    public function delete(string $key): bool
    {
        $this->key($key);
        return $this->store->delete([$key]);
    }
    public function clear(): bool
    {
        $this->store->clear();
        return true;
    }

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

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->store->delete($this->keyList($keys));
    }
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
