<?php

declare(strict_types=1);

namespace Type\Core\Http;

final class Tenant
{
    private string $id;
    private string $connection;
    private string $cacheNamespace;
    public function __construct(string $id, string $connection, string $cacheNamespace)
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $id) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $connection) || $cacheNamespace === '') {
            throw new \InvalidArgumentException('租户身份或资源映射无效');
        }
        $this->id = $id;
        $this->connection = $connection;
        $this->cacheNamespace = $cacheNamespace;
    }
    public function id(): string
    {
        return $this->id;
    }
    public function connection(): string
    {
        return $this->connection;
    }
    public function cacheNamespace(): string
    {
        return $this->cacheNamespace;
    }
}
