<?php

declare(strict_types=1);

namespace Type\Testing;

/** 真实 HTTP 测试响应快照；重复头保留列表，正文按收到的字节保存。 */
final class HttpResponse
{
    /** @param array<string, list<string>> $headers 由测试客户端规范化为小写名称的响应头。 */
    public function __construct(public readonly int $status, public readonly array $headers, public readonly string $body)
    {
    }
    /** @throws \JsonException 正文不是有效 JSON；JSON null 正常返回 null。 */
    public function json(): mixed
    {
        return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
    }
    /**
     * 不区分大小写读取同名响应头，缺失返回空列表。
     * @return list<string>
     */
    public function header(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }
}
