<?php

declare(strict_types=1);

namespace Type\Log;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** 非阻塞流、有界内存和有限排空；输出失败通过计数呈现，不递归写错误日志。 */
final class Output
{
    private mixed $stream = null;
    private string $destination;
    private bool $ownsStream = true;
    private ?bool $wasBlocking = null;
    private ?int $process = null;
    private bool $stopped = false;
    private bool $failed = false;
    private bool $draining = false;
    private array $queue = [];
    private int $head = 0;
    private int $offset = 0;
    private int $bytes = 0;
    private int $maxRecords;
    private int $maxBytes;
    private int $maxRecordBytes;
    private array $counts = ['accepted' => 0, 'written' => 0, 'dropped_full' => 0, 'dropped_oversize' => 0,
        'dropped_failure' => 0, 'dropped_stop' => 0, 'write_failures' => 0, 'drain_timeouts' => 0, 'partial_records' => 0,
        'high_water_records' => 0, 'high_water_bytes' => 0];

    private function __construct(string $destination, int $maxRecords, int $maxBytes, int $maxRecordBytes)
    {
        if ($maxRecords < 1 || $maxRecords > 100000 || $maxBytes < 256 || $maxBytes > 67108864
            || $maxRecordBytes < 256 || $maxRecordBytes > $maxBytes) {
            throw new InvalidArgumentException('日志输出容量无效');
        }
        $this->destination = $destination;
        $this->maxRecords = $maxRecords;
        $this->maxBytes = $maxBytes;
        $this->maxRecordBytes = $maxRecordBytes;
    }

    /**
     * 建立延迟打开的 stdout 输出；条数、总字节与单条字节均有上限。
     *
     * @throws InvalidArgumentException 容量参数越界。
     */
    public static function stdout(int $maxRecords = 1024, int $maxBytes = 1048576, int $maxRecordBytes = 4096): Output
    {
        return new Output('php://stdout', $maxRecords, $maxBytes, $maxRecordBytes);
    }

    /**
     * 建立本地文件追加输出；父目录由应用准备，实际打开时拒绝符号链接和非普通文件。
     *
     * @param string $path 以 / 开头的本地绝对路径，不接受 URL 包装器。
     * @throws InvalidArgumentException 路径或容量参数无效。
     */
    public static function file(string $path, int $maxRecords = 1024, int $maxBytes = 1048576, int $maxRecordBytes = 4096): Output
    {
        if (!str_starts_with($path, '/') || str_contains($path, "\0") || str_contains($path, '://')) {
            throw new InvalidArgumentException('日志文件需要显式本地绝对路径');
        }
        return new Output($path, $maxRecords, $maxBytes, $maxRecordBytes);
    }

    /** 接管非阻塞写模式；closeStream=false 时停止后恢复调用者原有的阻塞模式。 */
    public static function stream(
        mixed $stream,
        int $maxRecords = 1024,
        int $maxBytes = 1048576,
        int $maxRecordBytes = 4096,
        bool $closeStream = false
    ): Output {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('日志输出需要有效流资源');
        }
        $output = new Output('', $maxRecords, $maxBytes, $maxRecordBytes);
        $output->stream = $stream;
        $output->ownsStream = $closeStream;
        $output->process = (int) getmypid();
        $output->prepareStream();
        return $output;
    }

    /**
     * 接纳一条完整记录到内存；满载、过大、停止或失败时整体丢弃并累计计数。
     *
     * @return bool true 仅表示已入缓冲，不表示持久写出。
     */
    public function enqueue(string $line): bool
    {
        $this->assertProcess();
        if ($this->stopped) {
            $this->counts['dropped_stop']++;
            return false;
        }
        if ($this->failed) {
            $this->counts['dropped_failure']++;
            return false;
        }
        $length = strlen($line);
        if ($length > $this->maxRecordBytes) {
            $this->counts['dropped_oversize']++;
            return false;
        }
        if (count($this->queue) >= $this->maxRecords || $this->bytes + $length > $this->maxBytes) {
            $this->counts['dropped_full']++;
            return false;
        }
        $this->queue[] = $line;
        $this->bytes += $length;
        $this->counts['accepted']++;
        $this->counts['high_water_records'] = max($this->counts['high_water_records'], count($this->queue));
        $this->counts['high_water_bytes'] = max($this->counts['high_water_bytes'], $this->bytes);
        return true;
    }

    /**
     * 在给定秒数内尝试写出缓冲，保留部分写出的偏移；失败使输出退役。
     *
     * @return bool 本次调用结束时缓冲是否为空。
     * @throws InvalidArgumentException 秒数非有限值或不在 0 至 60 之间。
     */
    public function drain(float $seconds = 0.0): bool
    {
        $this->assertProcess();
        if (!is_finite($seconds) || $seconds < 0 || $seconds > 60) {
            throw new InvalidArgumentException('日志排空预算需要在 0 到 60 秒之间');
        }
        if ($this->draining || $this->stopped || $this->failed) {
            return $this->queue === [];
        }
        $deadline = hrtime(true) / 1e9 + $seconds;
        $this->draining = true;
        try {
            while ($this->queue !== []) {
                if (!$this->ensureOpen()) {
                    return false;
                }
                $line = $this->queue[$this->head];
                try {
                    $written = @fwrite($this->stream, substr($line, $this->offset));
                } catch (Throwable $error) {
                    $written = false;
                }
                if ($written === false) {
                    $this->fail();
                    return false;
                }
                if ($written > 0) {
                    $this->offset += $written;
                    if ($this->offset === strlen($line)) {
                        $this->bytes -= (int) strlen($line);
                        unset($this->queue[$this->head++]);
                        $this->offset = 0;
                        $this->counts['written']++;
                        if ($this->queue === []) {
                            $this->queue = [];
                            $this->head = 0;
                        } elseif ($this->head >= $this->maxRecords) {
                            $this->queue = array_values($this->queue);
                            $this->head = 0;
                        }
                    }
                }
                if ($this->queue === []) {
                    return true;
                }
                $remaining = $deadline - hrtime(true) / 1e9;
                if ($remaining <= 0) {
                    if ($seconds > 0) {
                        $this->counts['drain_timeouts']++;
                    } return false;
                }
                if ($written === 0) {
                    $read = [];
                    $write = [$this->stream];
                    $except = [];
                    try {
                        $ready = @stream_select($read, $write, $except, 0, (int) min(1000000, ceil($remaining * 1e6)));
                    } catch (Throwable $error) {
                        $ready = false;
                    }
                    if ($ready === false) {
                        $this->fail();
                        return false;
                    }
                }
            }
            return true;
        } finally {
            $this->draining = false;
        }
    }

    /** 先按秒数预算排空，再丢弃剩余记录；关闭自有流或恢复外借流的阻塞模式。 */
    public function stop(float $seconds = 0.25): void
    {
        $this->assertProcess();
        if ($this->stopped) {
            return;
        }
        $this->drain($seconds);
        if ($this->offset > 0) {
            $this->counts['partial_records']++;
        }
        $this->counts['dropped_stop'] += count($this->queue);
        $this->queue = [];
        $this->head = 0;
        $this->offset = 0;
        $this->bytes = 0;
        $this->stopped = true;
        $this->release();
    }

    /**
     * 读取输出累计计数与当前积压；部分写入不计作完整 written。
     *
     * @return array<string, int|bool> 累计数量、积压条数/字节及 failed/stopped 状态。
     */
    public function stats(): array
    {
        $this->assertProcess();
        return $this->counts + ['pending_records' => count($this->queue), 'pending_bytes' => $this->bytes,
            'failed' => $this->failed, 'stopped' => $this->stopped];
    }

    private function assertProcess(): void
    {
        if ($this->process === null) {
            $this->process = (int) getmypid();
        } elseif ($this->process !== (int) getmypid()) {
            throw new RuntimeException('日志输出不能跨进程复用，请在工作进程内创建');
        }
    }

    private function ensureOpen(): bool
    {
        if (is_resource($this->stream)) {
            return true;
        }
        try {
            if ($this->destination !== 'php://stdout') {
                $stat = @lstat($this->destination);
                if ($stat !== false && ($stat['mode'] & 0170000) !== 0100000) {
                    $this->fail();
                    return false;
                }
            }
            $this->stream = @fopen($this->destination, 'ab');
            if (!is_resource($this->stream)) {
                $this->fail();
                return false;
            }
            $this->prepareStream();
            return true;
        } catch (Throwable $error) {
            $this->fail();
            return false;
        }
    }

    private function prepareStream(): void
    {
        $metadata = stream_get_meta_data($this->stream);
        if (strpbrk((string) $metadata['mode'], 'waxc+') === false
            || !in_array($metadata['stream_type'], ['STDIO', 'tcp_socket', 'unix_socket', 'generic_socket'], true)) {
            throw new InvalidArgumentException('日志只接受可写本地文件、标准输出或原生 socket 流');
        }
        $this->wasBlocking = (bool) ($metadata['blocked'] ?? true);
        if (!stream_set_blocking($this->stream, false)) {
            throw new InvalidArgumentException('日志输出不能设置非阻塞模式');
        }
        stream_set_write_buffer($this->stream, 0);
    }

    private function fail(): void
    {
        $this->counts['write_failures']++;
        if ($this->offset > 0) {
            $this->counts['partial_records']++;
        }
        $this->counts['dropped_failure'] += count($this->queue);
        $this->queue = [];
        $this->head = 0;
        $this->offset = 0;
        $this->bytes = 0;
        $this->failed = true;
        $this->release();
    }

    private function release(): void
    {
        if (is_resource($this->stream)) {
            try {
                if ($this->ownsStream) {
                    @fclose($this->stream);
                } elseif ($this->wasBlocking !== null) {
                    @stream_set_blocking($this->stream, $this->wasBlocking);
                }
            } catch (Throwable $error) { /* 输出已经退役，不向业务路径递归报告。 */
            }
        }
        $this->stream = null;
    }
}
