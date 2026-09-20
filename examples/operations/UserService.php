<?php

declare(strict_types=1);

namespace TypeApp\Operations;

use RuntimeException;
use Type\Cache\Attribute\Cacheable;
use Type\Cache\Attribute\CacheEvict;
use Type\Cache\TypedCache;
use Type\Orm\Attribute\Transactional;
use Type\Orm\Db;

/** 普通业务对象：只有显式生成的 UserOperations 才提供事务和缓存语义。 */
final class UserService
{
    private int $loads = 0;
    private int $changes = 0;

    /** 示例表仅归当前连接所有，三库的重复消费均不遗留业务表。 */
    public function initialize(): void
    {
        Db::connection('default', true)->execute('CREATE TEMPORARY TABLE operation_users (id INTEGER PRIMARY KEY, name VARCHAR(100) NOT NULL)');
    }

    #[Transactional]
    public function create(int $id, string $name, bool $fail = false): void
    {
        Db::connection('default', true)->execute('INSERT INTO operation_users (id, name) VALUES (?, ?)', [$id, $name]);
        if ($fail) {
            throw new RuntimeException('预期业务失败');
        }
    }

    /** 返回明确的缺失状态；普通未标注的方法仍保留完全相同的类型。 */
    public function read(int $id): ?string
    {
        $rows = Db::connection('default', true)->query('SELECT name FROM operation_users WHERE id = ?', [$id]);
        return $rows === [] ? null : (string) $rows[0]['name'];
    }

    #[Cacheable(cache: 'cache', key: 'user:{id}', ttlMilliseconds: 60000)]
    public function cached(TypedCache $cache, int $id): ?string
    {
        $this->loads++;
        return $this->read($id);
    }

    #[Cacheable(cache: 'cache', key: 'short-user:{id}', ttlMilliseconds: 25)]
    public function shortLived(TypedCache $cache, int $id): ?string
    {
        $this->loads++;
        return $this->read($id);
    }

    #[Cacheable(cache: 'cache', key: 'failure:{id}', ttlMilliseconds: 60000)]
    public function failing(TypedCache $cache, int $id): string
    {
        $this->loads++;
        throw new RuntimeException('预期回源失败');
    }

    #[Cacheable(cache: 'cache', key: 'typed:{value}', ttlMilliseconds: 60000)]
    public function typedKey(TypedCache $cache, int|float|string|bool|null $value): string
    {
        $this->loads++;
        return get_debug_type($value) . ':' . json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    #[Cacheable(cache: 'cache', key: 'tenant-user:{tenant}:{id}', ttlMilliseconds: 60000)]
    public function tenantKey(TypedCache $cache, ?string $tenant, int $id): string
    {
        $this->loads++;
        return json_encode([$tenant, $id], JSON_THROW_ON_ERROR);
    }

    /** 中文说明和默认参数在公开组合对象上保持可见。 */
    public function greet(string $name = '开发者', int $repeat = 1): string
    {
        return str_repeat('你好，' . $name . '！', $repeat);
    }

    public function collision(string $_type_operation_service): string
    {
        return $_type_operation_service;
    }

    #[Transactional]
    #[CacheEvict(cache: 'cache', key: 'user:{id}')]
    public function rename(TypedCache $cache, int $id, string $name, bool $fail = false): string
    {
        $this->changes++;
        Db::connection('default', true)->execute('UPDATE operation_users SET name = ? WHERE id = ?', [$name, $id]);
        if ($fail) {
            throw new RuntimeException('预期修改失败');
        }
        return $name;
    }

    #[Transactional]
    #[CacheEvict(cache: 'cache', all: true)]
    public function clearAfterWrite(TypedCache $cache, int $id, array $data): void
    {
        Db::connection('default', true)->execute('UPDATE operation_users SET name = ? WHERE id = ?', [(string) $data['name'], $id]);
    }

    #[CacheEvict(cache: 'cache', key: 'user:{id}')]
    public function evict(TypedCache $cache, int $id, bool $fail = false): void
    {
        if ($fail) {
            throw new RuntimeException('预期清理失败');
        }
    }

    public function loads(): int
    {
        return $this->loads;
    }

    /** 返回业务修改方法实际进入次数，用于核验未知提交不会重做业务。 */
    public function changes(): int
    {
        return $this->changes;
    }
}
