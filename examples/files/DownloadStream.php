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
    /** 包装响应流和所属 Scope，以模式控制发送前后故障；close() 负责关闭底层流。 */
    public function __construct(StreamInterface $stream, ExecutionScope $scope, string $marker, string $mode)
    {
        $this->stream = $stream;
        $this->scope = $scope;
        $this->marker = $marker;
        $this->mode = $mode;
    }
    /** 故意不提供整流字符串内容，迫使响应路径使用分块 read()。 */
    public function __toString(): string
    {
        return '';
    }
    /** 幂等记录关闭时的 Scope 状态与读取次数，再关闭底层流。 */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        file_put_contents((string) getenv('TYPE_UPLOAD_TRACE'), 'stream:' . $this->marker . ':' . $this->scope->state() . ':' . $this->reads . "\n", FILE_APPEND | LOCK_EX);
        $this->stream->close();
        $this->closed = true;
    }
    /**
     * 移交底层资源，后续资源关闭责任转给接收方。
     *
     * @return resource|null 分离后的底层句柄。
     */
    public function detach(): mixed
    {
        return $this->stream->detach();
    }
    /** 返回底层已知字节数，未知时保持 null。 */
    public function getSize(): ?int
    {
        return $this->stream->getSize();
    }
    /** 返回底层当前字节偏移，不主动移动游标。 */
    public function tell(): int
    {
        return $this->stream->tell();
    }
    /** 沿用底层 EOF 状态，不把空块等同文件末尾。 */
    public function eof(): bool
    {
        return $this->stream->eof();
    }
    /** 按底层流能力报告是否支持定位。 */
    public function isSeekable(): bool
    {
        return $this->stream->isSeekable();
    }
    /** 按字节偏移与 whence 定位，底层不支持时传播失败。 */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->stream->seek($offset, $whence);
    }
    /** 将底层游标移回开头，不重置本包装器的故障计数。 */
    public function rewind(): void
    {
        $this->stream->rewind();
    }
    /** 下载包装器始终只读，即使底层句柄可写。 */
    public function isWritable(): bool
    {
        return false;
    }
    /**
     * 拒绝任何写入，以免下载演练意外修改原文件。
     *
     * @throws \RuntimeException 每次调用均拒绝。
     */
    public function write(string $string): int
    {
        throw new \RuntimeException('下载只读');
    }
    /** 沿用底层流的可读状态。 */
    public function isReadable(): bool
    {
        return $this->stream->isReadable();
    }
    /** 先检查 Scope，再按模式触发延迟或读取故障；最多读取 length 字节。 */
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
    /**
     * 拒绝一次读取全部内容，以验证 HTTP 路径不会整流缓冲。
     *
     * @throws \RuntimeException 此演练禁止整流读取。
     */
    public function getContents(): string
    {
        throw new \RuntimeException('验收禁止整流缓冲');
    }
    /** 读取底层元数据；null 选择完整映射，不改变资源归属。 */
    public function getMetadata(?string $key = null): mixed
    {
        return $this->stream->getMetadata($key);
    }
}
