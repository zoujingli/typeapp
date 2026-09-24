<?php

declare(strict_types=1);

namespace Type\Core\Http\Message;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

/** 服务端请求的独立快照，显式携带解析输入和中间件属性，不读取全局变量。 */
final class ServerRequest extends Request implements ServerRequestInterface
{
    private array $serverParams;
    private array $cookies = [];
    private array $query = [];
    private array $uploads = [];
    private mixed $parsedBody = null;
    private array $attributes = [];

    /**
     * 保存宿主传入的请求元数据，查询、Cookie、上传和解析正文需另行设置。
     * @param array<string, mixed> $serverParams 宿主创建时的服务器参数快照。
     */
    public function __construct(string $method, UriInterface $uri, StreamInterface $body, array $serverParams = [])
    {
        parent::__construct($method, $uri, $body);
        $this->serverParams = $serverParams;
    }

    /**
     * 返回宿主创建请求时的服务器参数，不在读取时访问全局变量。
     * @return array<string, mixed>
     */
    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    /**
     * 返回显式解析的 Cookie 快照，不重新解释 Cookie 头。
     * @return array<string, mixed>
     */
    public function getCookieParams(): array
    {
        return $this->cookies;
    }

    /**
     * 在副本中设置已解析 Cookie，不修改原始 Cookie 头。
     * @param array<string, mixed> $cookies 已解析的 Cookie 参数。
     */
    public function withCookieParams(array $cookies): ServerRequestInterface
    {
        $copy = clone $this;
        $copy->cookies = $cookies;
        return $copy;
    }

    /**
     * 返回显式解析的查询快照，不重新解析当前 URI。
     * @return array<string, mixed>
     */
    public function getQueryParams(): array
    {
        return $this->query;
    }

    /**
     * 在副本中替换查询快照，不修改 URI 中的原始查询文本。
     * @param array<string, mixed> $query 已验证的查询参数。
     */
    public function withQueryParams(array $query): ServerRequestInterface
    {
        $copy = clone $this;
        $copy->query = $query;
        return $copy;
    }

    /**
     * 返回上传对象树，上传流仍受请求或其资源所有者管理。
     * @return array<string|int, UploadedFileInterface|array>
     */
    public function getUploadedFiles(): array
    {
        return $this->uploads;
    }

    /**
     * 验证上传树只含数组及上传对象，再返回设置该树的副本。
     * @param array<string|int, UploadedFileInterface|array> $uploadedFiles 已解析上传树。
     */
    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
    {
        $this->validateUploads($uploadedFiles);
        $copy = clone $this;
        $copy->uploads = $uploadedFiles;
        return $copy;
    }

    /** 返回显式设置的解析正文；null 表示未设置或显式空值，不触发解码。 */
    public function getParsedBody(): mixed
    {
        return $this->parsedBody;
    }

    /**
     * 接受数组、对象或 null 并返回副本，不改变原始正文流。
     * @param array|object|null $data 已解析正文。
     */
    public function withParsedBody($data): ServerRequestInterface
    {
        if ($data !== null && !is_array($data) && !is_object($data)) {
            throw new InvalidArgumentException('解析正文必须是数组、对象或 null');
        }
        $copy = clone $this;
        $copy->parsedBody = $data;
        return $copy;
    }

    /**
     * 返回处理链附加的属性快照，对象值按 PHP 对象引用语义保留。
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /** 只在属性不存在时返回默认值，已声明 null 与缺失严格区分。 */
    public function getAttribute(string $name, $default = null): mixed
    {
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    /** 在副本中设置属性，不自动克隆其中的对象或资源。 */
    public function withAttribute(string $name, $value): ServerRequestInterface
    {
        $copy = clone $this;
        $copy->attributes[$name] = $value;
        return $copy;
    }

    /** 返回移除该属性的副本；缺失属性也不会改变原请求。 */
    public function withoutAttribute(string $name): ServerRequestInterface
    {
        $copy = clone $this;
        unset($copy->attributes[$name]);
        return $copy;
    }

    private function validateUploads(array $files): void
    {
        foreach ($files as $file) {
            if (is_array($file)) {
                $this->validateUploads($file);
            } elseif (!$file instanceof UploadedFileInterface) {
                throw new InvalidArgumentException('上传文件树只允许数组和 UploadedFileInterface');
            }
        }
    }
}
