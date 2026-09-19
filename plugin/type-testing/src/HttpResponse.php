<?php

declare(strict_types=1);

namespace Type\Testing;

final class HttpResponse
{
    public function __construct(public readonly int $status, public readonly array $headers, public readonly string $body)
    {
    }
    public function json(): mixed
    {
        return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
    }
    public function header(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }
}
