<?php

declare(strict_types=1);

namespace Type\Core\Http;

/** 经 RequestPolicy 规范化后的请求身份值，用于签名和可信路径读取。 */
final class CanonicalRequest
{
    private string $uri;
    private string $path;
    private string $query;
    private array $segments;
    private string $clientIp;

    /**
     * 保存已完成来源和编码校验的请求快照；构造器本身不授予信任。
     * @param list<string> $segments 已解码并验证的路径段。
     */
    public function __construct(string $uri, string $path, string $query, array $segments, string $clientIp)
    {
        $this->uri = $uri;
        $this->path = $path;
        $this->query = $query;
        $this->segments = $segments;
        $this->clientIp = $clientIp;
    }
    /** 返回规范化的完整 URI，包含经过白名单验证的来源。 */
    public function uri(): string
    {
        return $this->uri;
    }
    /** 返回已按路径段重新编码的绝对路径。 */
    public function path(): string
    {
        return $this->path;
    }
    /** 返回已规范化的查询文本，不包含前导问号。 */
    public function query(): string
    {
        return $this->query;
    }
    /**
     * 返回已解码的路径段，根路径为空列表。
     * @return list<string>
     */
    public function segments(): array
    {
        return $this->segments;
    }
    /** 返回按显式可信代理规则解析的客户端 IP。 */
    public function clientIp(): string
    {
        return $this->clientIp;
    }
}
