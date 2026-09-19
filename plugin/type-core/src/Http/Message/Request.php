<?php

declare(strict_types=1);

namespace Type\Core\Http\Message;

use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

class Request extends Message implements RequestInterface
{
    private string $method;
    private UriInterface $uri;
    private ?string $target = null;

    public function __construct(string $method, UriInterface $uri, StreamInterface $body)
    {
        parent::__construct($body);
        $this->validateMethod($method);
        $this->method = $method;
        $this->uri = $uri;
        $this->updateHost($uri);
    }

    public function getRequestTarget(): string
    {
        if ($this->target !== null) {
            return $this->target;
        }
        $path = $this->uri->getPath();
        $query = $this->uri->getQuery();
        return ($path === '' ? '/' : $path) . ($query === '' ? '' : '?' . $query);
    }

    public function withRequestTarget(string $requestTarget): RequestInterface
    {
        if ($requestTarget === '' || preg_match('/[\x00-\x20\x7f]/', $requestTarget)) {
            throw new InvalidArgumentException('HTTP 请求目标无效');
        }
        $copy = clone $this;
        $copy->target = $requestTarget;
        return $copy;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): RequestInterface
    {
        $this->validateMethod($method);
        $copy = clone $this;
        $copy->method = $method;
        return $copy;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface
    {
        $copy = clone $this;
        $copy->uri = $uri;
        if (!$preserveHost || $copy->getHeaderLine('Host') === '') {
            $copy->updateHost($uri);
        }
        return $copy;
    }

    private function validateMethod(string $method): void
    {
        if (!preg_match('/^[!#$%&\x27*+.^_`|~0-9A-Za-z-]+$/D', $method)) {
            throw new InvalidArgumentException('HTTP 方法无效');
        }
    }

    private function updateHost(UriInterface $uri): void
    {
        if ($uri->getHost() === '') {
            return;
        }
        $port = $uri->getPort();
        $host = $uri->getHost() . ($port === null ? '' : ':' . $port);
        $previous = $this->headerNames['host'] ?? null;
        if ($previous !== null) {
            unset($this->headers[$previous]);
        }
        $this->headers = array_merge(['Host' => [$host]], $this->headers);
        $this->headerNames['host'] = 'Host';
    }
}
