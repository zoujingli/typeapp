<?php

declare(strict_types=1);

namespace Type\Core\Http\Message;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

/** 拥有底层 PHP 流的 PSR-7 正文适配器；显式关闭或分离决定资源归属。 */
final class Stream implements StreamInterface
{
    private mixed $resource;

    /**
     * 接管有效流资源，关闭此对象也会关闭该句柄；原调用方不再并行管理它。
     * @param resource $resource 已打开的 PHP stream 资源。
     */
    public function __construct(mixed $resource)
    {
        if (!is_resource($resource) || get_resource_type($resource) !== 'stream') {
            throw new InvalidArgumentException('正文必须使用有效的流资源');
        }
        $this->resource = $resource;
    }

    /** 释放仍由本对象持有的流；需要确认刷新结果时应提前显式 close()。 */
    public function __destruct()
    {
        $this->close();
    }

    /** 可定位时从头读取全部内容；读取失败返回空文本，流位置不会恢复。 */
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

    /**
     * 分离句柄但不关闭，关闭责任转交调用者。
     * @return resource|null
     */
    public function detach(): mixed
    {
        $resource = $this->resource;
        $this->resource = null;
        return $resource;
    }

    /** 返回底层流报告的字节大小，已关闭或无法取得状态时返回 null。 */
    public function getSize(): ?int
    {
        if (!is_resource($this->resource)) {
            return null;
        }
        $stat = fstat($this->resource);
        return $stat === false ? null : (int) $stat['size'];
    }

    /** 返回当前字节偏移；已关闭或底层不支持定位时抛出 RuntimeException。 */
    public function tell(): int
    {
        $this->assertOpen();
        $position = ftell($this->resource);
        if ($position === false) {
            throw new RuntimeException('无法读取流位置');
        }
        return $position;
    }

    /** 已关闭、已分离或底层读取到末尾时返回 true。 */
    public function eof(): bool
    {
        return !is_resource($this->resource) || feof($this->resource);
    }

    /** 仅有效句柄声明支持定位时返回 true。 */
    public function isSeekable(): bool
    {
        return is_resource($this->resource) && (bool) $this->getMetadata('seekable');
    }

    /** 按字节偏移定位；不支持定位、参考位置非法或底层失败时抛出 RuntimeException。 */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if (!$this->isSeekable() || !in_array($whence, [SEEK_SET, SEEK_CUR, SEEK_END], true)
            || @fseek($this->resource, $offset, $whence) !== 0) {
            throw new RuntimeException('流不支持指定位置');
        }
    }

    /** 定位到流起点，失败语义与 seek(0) 相同。 */
    public function rewind(): void
    {
        $this->seek(0);
    }

    /** 按有效句柄的打开模式判断是否允许写入。 */
    public function isWritable(): bool
    {
        return is_resource($this->resource) && strpbrk((string) $this->getMetadata('mode'), 'waxc+') !== false;
    }

    /** 写入字节并返回实际数量，允许短写；调用方负责循环直至完成或停止。 */
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

    /** 按有效句柄的打开模式判断是否允许读取。 */
    public function isReadable(): bool
    {
        return is_resource($this->resource) && strpbrk((string) $this->getMetadata('mode'), 'r+') !== false;
    }

    /** 最多读取指定字节数，0 返回空文本；负数或不可读流会抛出 RuntimeException。 */
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

    /** 读取当前位置到末尾的内容；调用方须先约束可读取数据量。 */
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

    /** 返回全部流元数据或指定键，缺失键返回 null，关闭流的全部元数据为空数组。 */
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
