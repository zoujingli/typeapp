<?php

declare(strict_types=1);

namespace Type\Core\Http;

/** 固定租户到连接和缓存命名空间的可信映射，不从请求直接构造资源名。 */
final class Tenant
{
    private string $id;
    private string $connection;
    private string $cacheNamespace;
    /** 验证租户标识及资源映射格式，连接名指向应用已声明的连接配置。 */
    public function __construct(string $id, string $connection, string $cacheNamespace)
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $id) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $connection) || $cacheNamespace === '') {
            throw new \InvalidArgumentException('租户身份或资源映射无效');
        }
        $this->id = $id;
        $this->connection = $connection;
        $this->cacheNamespace = $cacheNamespace;
    }
    /** 返回授权检查使用的租户标识。 */
    public function id(): string
    {
        return $this->id;
    }
    /** 返回固定数据库连接配置名，不包含连接凭据。 */
    public function connection(): string
    {
        return $this->connection;
    }
    /** 返回该租户独立的缓存命名空间。 */
    public function cacheNamespace(): string
    {
        return $this->cacheNamespace;
    }
}
