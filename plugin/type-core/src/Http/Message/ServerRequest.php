<?php

declare(strict_types=1);

namespace Type\Core\Http\Message;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

final class ServerRequest extends Request implements ServerRequestInterface
{
    private array $serverParams;
    private array $cookies = [];
    private array $query = [];
    private array $uploads = [];
    private mixed $parsedBody = null;
    private array $attributes = [];

    public function __construct(string $method, UriInterface $uri, StreamInterface $body, array $serverParams = [])
    {
        parent::__construct($method, $uri, $body);
        $this->serverParams = $serverParams;
    }

    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    public function getCookieParams(): array
    {
        return $this->cookies;
    }

    public function withCookieParams(array $cookies): ServerRequestInterface
    {
        $copy = clone $this;
        $copy->cookies = $cookies;
        return $copy;
    }

    public function getQueryParams(): array
    {
        return $this->query;
    }

    public function withQueryParams(array $query): ServerRequestInterface
    {
        $copy = clone $this;
        $copy->query = $query;
        return $copy;
    }

    public function getUploadedFiles(): array
    {
        return $this->uploads;
    }

    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
    {
        $this->validateUploads($uploadedFiles);
        $copy = clone $this;
        $copy->uploads = $uploadedFiles;
        return $copy;
    }

    public function getParsedBody(): mixed
    {
        return $this->parsedBody;
    }

    public function withParsedBody($data): ServerRequestInterface
    {
        if ($data !== null && !is_array($data) && !is_object($data)) {
            throw new InvalidArgumentException('解析正文必须是数组、对象或 null');
        }
        $copy = clone $this;
        $copy->parsedBody = $data;
        return $copy;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $name, $default = null): mixed
    {
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    public function withAttribute(string $name, $value): ServerRequestInterface
    {
        $copy = clone $this;
        $copy->attributes[$name] = $value;
        return $copy;
    }

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
