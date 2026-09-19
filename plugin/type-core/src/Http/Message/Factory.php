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

final class Factory implements
    StreamFactoryInterface,
    UriFactoryInterface,
    RequestFactoryInterface,
    ResponseFactoryInterface,
    ServerRequestFactoryInterface,
    UploadedFileFactoryInterface
{
    public function createUploadedFile(
        StreamInterface $stream,
        ?int $size = null,
        int $error = UPLOAD_ERR_OK,
        ?string $clientFilename = null,
        ?string $clientMediaType = null
    ): UploadedFileInterface {
        return new UploadedFile($stream, $size, $error, $clientFilename, $clientMediaType);
    }

    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
    {
        return new ServerRequest($method, $this->requestUri($uri), $this->createStream(), $serverParams);
    }

    public function createRequest(string $method, $uri): RequestInterface
    {
        return new Request($method, $this->requestUri($uri), $this->createStream());
    }

    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new Response($this->createStream(), $code, $reasonPhrase);
    }

    public function createUri(string $uri = ''): UriInterface
    {
        return new Uri($uri);
    }

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
