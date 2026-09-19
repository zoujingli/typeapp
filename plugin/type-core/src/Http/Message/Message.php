<?php

declare(strict_types=1);

namespace Type\Core\Http\Message;

use InvalidArgumentException;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;

abstract class Message implements MessageInterface
{
    protected string $protocol = '1.1';
    protected array $headers = [];
    protected array $headerNames = [];
    protected StreamInterface $body;

    public function __construct(StreamInterface $body)
    {
        $this->body = $body;
    }

    public function getProtocolVersion(): string
    {
        return $this->protocol;
    }

    public function withProtocolVersion(string $version): MessageInterface
    {
        if (!preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $version)) {
            throw new InvalidArgumentException('HTTP 协议版本无效');
        }
        $copy = clone $this;
        $copy->protocol = $version;
        return $copy;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headerNames[strtolower($name)]);
    }

    public function getHeader(string $name): array
    {
        $original = $this->headerNames[strtolower($name)] ?? null;
        return $original === null ? [] : $this->headers[$original];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): MessageInterface
    {
        $this->validateName($name);
        $values = $this->normalizeValues($value);
        $copy = clone $this;
        $lower = strtolower($name);
        $existing = $copy->headerNames[$lower] ?? null;
        if ($existing !== null) {
            unset($copy->headers[$existing]);
        }
        $copy->headerNames[$lower] = $name;
        $copy->headers[$name] = $values;
        return $copy;
    }

    public function withAddedHeader(string $name, $value): MessageInterface
    {
        $this->validateName($name);
        $values = $this->normalizeValues($value);
        $copy = clone $this;
        $lower = strtolower($name);
        $original = (string) ($copy->headerNames[$lower] ?? $name);
        $copy->headerNames[$lower] = $original;
        $copy->headers[$original] = array_merge($copy->headers[$original] ?? [], $values);
        return $copy;
    }

    public function withoutHeader(string $name): MessageInterface
    {
        $copy = clone $this;
        $lower = strtolower($name);
        $original = $copy->headerNames[$lower] ?? null;
        if ($original !== null) {
            unset($copy->headers[$original], $copy->headerNames[$lower]);
        }
        return $copy;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        $copy = clone $this;
        $copy->body = $body;
        return $copy;
    }

    private function validateName(string $name): void
    {
        if (!preg_match('/^[!#$%&\x27*+.^_`|~0-9A-Za-z-]+$/D', $name)) {
            throw new InvalidArgumentException('HTTP 消息头名称无效');
        }
    }

    private function normalizeValues(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        if ($values === []) {
            throw new InvalidArgumentException('HTTP 消息头值不能为空数组');
        }
        $normalized = [];
        foreach ($values as $entry) {
            if (!is_string($entry) && !is_int($entry) && !is_float($entry)) {
                throw new InvalidArgumentException('HTTP 消息头值必须是字符串或数字');
            }
            $entry = (string) $entry;
            if (preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $entry)) {
                throw new InvalidArgumentException('HTTP 消息头值不允许控制字符');
            }
            $normalized[] = trim($entry, " \t");
        }
        return $normalized;
    }
}
