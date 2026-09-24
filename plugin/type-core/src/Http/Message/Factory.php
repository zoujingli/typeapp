<?php

declare(strict_types=1);

namespace Type\Core\Http\Message;

use InvalidArgumentException;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;

/** 共享的 PSR-17 工厂，创建消息值对象及有明确所有者的正文流。 */
final class Factory implements
    StreamFactoryInterface,
    UriFactoryInterface,
    RequestFactoryInterface,
    ResponseFactoryInterface,
    ServerRequestFactoryInterface,
    UploadedFileFactoryInterface
{
    /** 把已打开的流包装为上传对象；大小以字节计，客户端元数据不视为可信。 */
    public function createUploadedFile(
        StreamInterface $stream,
        ?int $size = null,
        int $error = UPLOAD_ERR_OK,
        ?string $clientFilename = null,
        ?string $clientMediaType = null
    ): UploadedFileInterface {
        return new UploadedFile($stream, $size, $error, $clientFilename, $clientMediaType);
    }

    /**
     * 创建带独立空正文流的服务端请求，不读取 PHP 请求全局变量。
     * @param string|UriInterface $uri 原始 URI 或已解析值对象。
     * @param array<string, mixed> $serverParams 宿主提供的元数据。
     */
    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
    {
        return new ServerRequest($method, $this->requestUri($uri), $this->createStream(), $serverParams);
    }

    /**
     * 创建带独立空正文流的请求，并由 URI 初始化 Host。
     * @param string|UriInterface $uri 原始 URI 或已解析值对象。
     */
    public function createRequest(string $method, $uri): RequestInterface
    {
        return new Request($method, $this->requestUri($uri), $this->createStream());
    }

    /** 创建带独立空正文流的响应；状态码范围为 100 至 599。 */
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new Response($this->createStream(), $code, $reasonPhrase);
    }

    /** 解析并规范 URI，非法结构或控制字符会被拒绝。 */
    public function createUri(string $uri = ''): UriInterface
    {
        return new Uri($uri);
    }

    /** 完整写入初始内容并回到起点，返回流的关闭责任交给调用方。 */
    public function createStream(string $content = ''): StreamInterface
    {
        $resource = fopen('php://temp', 'w+b');
        if ($resource === false) {
            throw new RuntimeException('无法创建正文流');
        }
        $stream = new Stream($resource);
        $length = strlen($content);
        $offset = 0;
        while ($offset < $length) {
            $written = $stream->write(substr($content, $offset));
            if ($written === 0) {
                throw new RuntimeException('正文流写入没有进展');
            }
            $offset += $written;
        }
        $stream->rewind();
        return $stream;
    }

    /** 按明确模式打开文件并移交句柄所有权；写模式是否覆盖内容遵循 fopen 语义。 */
    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        if (!preg_match('/^[rwaxc][bte+]*$/D', $mode)) {
            throw new InvalidArgumentException('流打开模式无效');
        }
        if ($filename === '' || str_contains($filename, "\0")) {
            throw new RuntimeException('流文件名无效');
        }
        $resource = @fopen($filename, $mode);
        if ($resource === false) {
            throw new RuntimeException('无法打开流文件');
        }
        return new Stream($resource);
    }

    /**
     * 接管可读流资源，不复制内容；原持有者不得再独立关闭该句柄。
     * @param resource $resource 已打开且允许读取的流。
     */
    public function createStreamFromResource($resource): StreamInterface
    {
        if (!is_resource($resource) || get_resource_type($resource) !== 'stream'
            || strpbrk((string) stream_get_meta_data($resource)['mode'], 'r+') === false) {
            throw new InvalidArgumentException('流工厂需要可读的流资源');
        }
        return new Stream($resource);
    }

    private function requestUri(mixed $uri): UriInterface
    {
        if ($uri instanceof UriInterface) {
            return $uri;
        }
        if (!is_string($uri)) {
            throw new InvalidArgumentException('请求 URI 必须是字符串或 UriInterface');
        }
        return $this->createUri($uri);
    }
}
