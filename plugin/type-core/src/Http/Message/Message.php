<?php

declare(strict_types=1);

namespace Type\Core\Http\Message;

use InvalidArgumentException;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;

/** PSR-7 消息的不可变元数据基础；with 方法克隆消息，但正文流仍按引用共享。 */
abstract class Message implements MessageInterface
{
    protected string $protocol = '1.1';
    protected array $headers = [];
    protected array $headerNames = [];
    protected StreamInterface $body;

    /** 绑定正文流，不复制其内容；流的关闭责任由消息使用者明确管理。 */
    public function __construct(StreamInterface $body)
    {
        $this->body = $body;
    }

    /** 返回不含 HTTP/ 前缀的协议版本。 */
    public function getProtocolVersion(): string
    {
        return $this->protocol;
    }

    /** 验证版本格式并返回消息副本，原消息保持不变。 */
    public function withProtocolVersion(string $version): MessageInterface
    {
        if (!preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $version)) {
            throw new InvalidArgumentException('HTTP 协议版本无效');
        }
        $copy = clone $this;
        $copy->protocol = $version;
        return $copy;
    }

    /**
     * 返回保留原名称大小写的响应头快照。
     * @return array<string, list<string>>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /** 以不区分大小写的名称检查消息头是否存在。 */
    public function hasHeader(string $name): bool
    {
        return isset($this->headerNames[strtolower($name)]);
    }

    /**
     * 以不区分大小写的名称读取全部同名值，缺失返回空列表。
     * @return list<string>
     */
    public function getHeader(string $name): array
    {
        $original = $this->headerNames[strtolower($name)] ?? null;
        return $original === null ? [] : $this->headers[$original];
    }

    /** 以逗号连接同名头值；Set-Cookie 等不可合并头应使用 getHeader()。 */
    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    /**
     * 返回替换该头全部值的副本，拒绝非法名称或控制字符。
     * @param string|int|float|list<string|int|float> $value 单值或非空值列表。
     */
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

    /**
     * 返回追加同名头值的副本，保留已有头名称的大小写。
     * @param string|int|float|list<string|int|float> $value 单值或非空值列表。
     */
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

    /** 以不区分大小写的名称删除指定头，始终返回副本。 */
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

    /** 返回共享的可变正文流；读取和定位会影响其他持有者。 */
    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    /** 在副本中绑定新正文流，不关闭原流，也不复制新流内容。 */
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
