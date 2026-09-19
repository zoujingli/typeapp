<?php

declare(strict_types=1);

namespace Type\Core\Http;

final class CanonicalRequest
{
    private string $uri;
    private string $path;
    private string $query;
    private array $segments;
    private string $clientIp;

    public function __construct(string $uri, string $path, string $query, array $segments, string $clientIp)
    {
        $this->uri = $uri;
        $this->path = $path;
        $this->query = $query;
        $this->segments = $segments;
        $this->clientIp = $clientIp;
    }
    public function uri(): string
    {
        return $this->uri;
    }
    public function path(): string
    {
        return $this->path;
    }
    public function query(): string
    {
        return $this->query;
    }
    public function segments(): array
    {
        return $this->segments;
    }
    public function clientIp(): string
    {
        return $this->clientIp;
    }
}
