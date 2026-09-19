<?php

declare(strict_types=1);

namespace Type\Core\Http\Message;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

final class Stream implements StreamInterface
{
    private mixed $resource;

    public function __construct(mixed $resource)
    {
        if (!is_resource($resource) || get_resource_type($resource) !== 'stream') {
            throw new InvalidArgumentException('正文必须使用有效的流资源');
        }
        $this->resource = $resource;
    }

    public function __destruct()
    {
        $this->close();
    }

    public function __toString(): string
    {
        try {
            if ($this->isSeekable()) {
                $this->rewind();
            }
            return $this->getContents();
        } catch (Throwable $error) {
            return '';
        }
    }

    /** 真实关闭返回后才分离流；关闭或缓冲刷新失败向显式调用者报告。 */
    public function close(): void
    {
        if (is_resource($this->resource)) {
            $closed = fclose($this->resource);
            $this->resource = null;
            if (!$closed) {
                throw new RuntimeException('无法确认流已成功刷新并关闭');
            }
        }
        $this->resource = null;
    }

    public function detach(): mixed
    {
        $resource = $this->resource;
        $this->resource = null;
        return $resource;
    }

    public function getSize(): ?int
    {
        if (!is_resource($this->resource)) {
            return null;
        }
        $stat = fstat($this->resource);
        return $stat === false ? null : (int) $stat['size'];
    }

    public function tell(): int
    {
        $this->assertOpen();
        $position = ftell($this->resource);
        if ($position === false) {
            throw new RuntimeException('无法读取流位置');
        }
        return $position;
    }

    public function eof(): bool
    {
        return !is_resource($this->resource) || feof($this->resource);
    }

    public function isSeekable(): bool
    {
        return is_resource($this->resource) && (bool) $this->getMetadata('seekable');
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if (!$this->isSeekable() || !in_array($whence, [SEEK_SET, SEEK_CUR, SEEK_END], true)
            || @fseek($this->resource, $offset, $whence) !== 0) {
            throw new RuntimeException('流不支持指定位置');
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return is_resource($this->resource) && strpbrk((string) $this->getMetadata('mode'), 'waxc+') !== false;
    }

    public function write(string $string): int
    {
        if (!$this->isWritable()) {
            throw new RuntimeException('流不可写');
        }
        $written = @fwrite($this->resource, $string);
        if ($written === false) {
            throw new RuntimeException('无法写入流');
        }
        return $written;
    }

    public function isReadable(): bool
    {
        return is_resource($this->resource) && strpbrk((string) $this->getMetadata('mode'), 'r+') !== false;
    }

    public function read(int $length): string
    {
        if ($length < 0 || !$this->isReadable()) {
            throw new RuntimeException('流不可读或读取长度无效');
        }
        if ($length === 0) {
            return '';
        }
        $content = @fread($this->resource, $length);
        if ($content === false) {
            throw new RuntimeException('无法读取流');
        }
        return $content;
    }

    public function getContents(): string
    {
        if (!$this->isReadable()) {
            throw new RuntimeException('流不可读');
        }
        $content = @stream_get_contents($this->resource);
        if ($content === false) {
            throw new RuntimeException('无法读取流内容');
        }
        return $content;
    }

    public function getMetadata(?string $key = null): mixed
    {
        $metadata = is_resource($this->resource) ? stream_get_meta_data($this->resource) : [];
        return $key === null ? $metadata : ($metadata[$key] ?? null);
    }

    private function assertOpen(): void
    {
        if (!is_resource($this->resource)) {
            throw new RuntimeException('流已经关闭或分离');
        }
    }
}
