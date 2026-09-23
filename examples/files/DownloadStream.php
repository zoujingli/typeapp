<?php

declare(strict_types=1);

namespace TypeApp\FileExample;

use Psr\Http\Message\StreamInterface;
use Type\Runtime\ExecutionScope;

/** 通过真实 HTTP 观察响应流的关闭顺序、发送前后失败、截止和断开。 */
final class DownloadStream implements StreamInterface
{
    private StreamInterface $stream;
    private ExecutionScope $scope;
    private string $marker;
    private string $mode;
    private int $reads = 0;
    private bool $closed = false;
    public function __construct(StreamInterface $stream, ExecutionScope $scope, string $marker, string $mode)
    {
        $this->stream = $stream;
        $this->scope = $scope;
        $this->marker = $marker;
        $this->mode = $mode;
    }
    public function __toString(): string
    {
        return '';
    }
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        file_put_contents((string) getenv('TYPE_UPLOAD_TRACE'), 'stream:' . $this->marker . ':' . $this->scope->state() . ':' . $this->reads . "\n", FILE_APPEND | LOCK_EX);
        $this->stream->close();
        $this->closed = true;
    }
    public function detach(): mixed
    {
        return $this->stream->detach();
    }
    public function getSize(): ?int
    {
        return $this->stream->getSize();
    }
    public function tell(): int
    {
        return $this->stream->tell();
    }
    public function eof(): bool
    {
        return $this->stream->eof();
    }
    public function isSeekable(): bool
    {
        return $this->stream->isSeekable();
    }
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->stream->seek($offset, $whence);
    }
    public function rewind(): void
    {
        $this->stream->rewind();
    }
    public function isWritable(): bool
    {
        return false;
    }
    public function write(string $string): int
    {
        throw new \RuntimeException('下载只读');
    }
    public function isReadable(): bool
    {
        return $this->stream->isReadable();
    }
    public function read(int $length): string
    {
        $this->scope->assertActive();
        if ($this->mode === '/fail-stream' && $this->reads === 2) {
            throw new \RuntimeException('发送后磁盘读取失败');
        }
        if ($this->mode === '/fail-first') {
            throw new \RuntimeException('首次读取失败 password=download-first-secret');
        }
        if ($this->mode === '/disconnect' || $this->mode === '/timeout-stream') {
            $microseconds = $this->mode === '/disconnect' ? 5000 : 1050000;
            \Swoole\Coroutine::sleep($microseconds / 1000000.0);
            // 取消只会让休眠返回。截止已过时必须在写出第一块之前失败，避免半截分块响应。
            $this->scope->assertActive();
        }
        $this->reads++;
        return $this->stream->read($length);
    }
    public function getContents(): string
    {
        throw new \RuntimeException('验收禁止整流缓冲');
    }
    public function getMetadata(?string $key = null): mixed
    {
        return $this->stream->getMetadata($key);
    }
}
