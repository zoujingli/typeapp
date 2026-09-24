<?php

declare(strict_types=1);

namespace Type\Core\Http\Message;

use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/** PSR-7 请求值对象，方法、目标与 URI 修改均返回副本。 */
class Request extends Message implements RequestInterface
{
    private string $method;
    private UriInterface $uri;
    private ?string $target = null;

    /** 校验方法并由 URI 初始化 Host，正文流仍由调用方按生命周期管理。 */
    public function __construct(string $method, UriInterface $uri, StreamInterface $body)
    {
        parent::__construct($body);
        $this->validateMethod($method);
        $this->method = $method;
        $this->uri = $uri;
        $this->updateHost($uri);
    }

    /** 优先返回显式目标，否则由 URI 路径和查询生成 origin-form，空路径按 /。 */
    public function getRequestTarget(): string
    {
        if ($this->target !== null) {
            return $this->target;
        }
        $path = $this->uri->getPath();
        $query = $this->uri->getQuery();
        return ($path === '' ? '/' : $path) . ($query === '' ? '' : '?' . $query);
    }

    /** 设置显式请求目标并返回副本，拒绝空值、空白和控制字符。 */
    public function withRequestTarget(string $requestTarget): RequestInterface
    {
        if ($requestTarget === '' || preg_match('/[\x00-\x20\x7f]/', $requestTarget)) {
            throw new InvalidArgumentException('HTTP 请求目标无效');
        }
        $copy = clone $this;
        $copy->target = $requestTarget;
        return $copy;
    }

    /** 返回原样保留大小写的合法 HTTP 方法。 */
    public function getMethod(): string
    {
        return $this->method;
    }

    /** 验证方法 token 并返回副本，不自动改写大小写。 */
    public function withMethod(string $method): RequestInterface
    {
        $this->validateMethod($method);
        $copy = clone $this;
        $copy->method = $method;
        return $copy;
    }

    /** 返回当前不可变 URI 值对象。 */
    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    /** 替换 URI 并返回副本；默认更新 Host，preserveHost 只保留已有非空 Host。 */
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
