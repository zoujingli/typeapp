<?php

declare(strict_types=1);

namespace Type\Scheduler;

use RuntimeException;
use Type\Runtime\ExecutionOwner;

/** 本地单机状态存储；稳定锁文件与原子替换状态文件分开。 */
final class FileStateStore implements StateStore
{
    private string $filename;
    private mixed $lock = null;
    private ?ExecutionOwner $owner = null;

    /**
     * 登记已存在本地目录中的状态文件；当前路径语义要求 / 开头的绝对路径。
     *
     * @throws RuntimeException 路径、父目录或空字节不符合要求。
     */
    public function __construct(string $filename)
    {
        if (!str_starts_with($filename, '/') || !is_dir(dirname($filename)) || str_contains($filename, "\0")) {
            throw new RuntimeException('TYPE_SCHEDULER_STORE：状态文件需要存在的本地目录与绝对路径');
        }
        $this->filename = $filename;
    }

    /**
     * 非阻塞获取稳定 .lock 文件的独占锁，与可原子替换的状态文件分离。
     *
     * @throws RuntimeException 当前已持有、其他执行者占用或文件不可用。
     */
    public function acquire(): void
    {
        if ($this->lock !== null) {
            throw new RuntimeException('TYPE_SCHEDULER_BUSY：状态存储已被当前执行占用');
        }
        $lock = fopen($this->filename . '.lock', 'c+b');
        if ($lock === false) {
            throw new RuntimeException('TYPE_SCHEDULER_STORE：无法打开状态锁');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('TYPE_SCHEDULER_BUSY：已有本地调度进程执行');
        }
        $this->owner = new ExecutionOwner();
        $this->lock = $lock;
    }

    /**
     * 持锁读取至多 16 MiB 的状态；文件不存在才返回首次运行状态，损坏直接失败。
     *
     * @return array{protocol: int, cursors: array<string, int>, records: list<array<string, mixed>>}
     */
    public function load(): array
    {
        $this->assertOwner();
        if (!is_file($this->filename)) {
            return ['protocol' => 1, 'cursors' => [], 'records' => []];
        }
        $json = file_get_contents($this->filename, false, null, 0, 16777217);
        if ($json === false) {
            throw new RuntimeException('TYPE_SCHEDULER_STORE：状态文件读取失败或超过 16 MiB');
        }
        return StateCodec::decode($json);
    }

    /**
     * 持锁写同目录临时文件并同步，再原子替换；失败保留原状态并清理临时文件。
     *
     * @param array{protocol: int, cursors: array<string, int>, records: list<array<string, mixed>>} $state 待保存的完整状态。
     * @throws RuntimeException 临时写入、同步或替换失败。
     */
    public function save(array $state): void
    {
        $this->assertOwner();
        $json = StateCodec::encode($state);
        $temporary = tempnam(dirname($this->filename), '.type-scheduler-');
        if ($temporary === false) {
            throw new RuntimeException('TYPE_SCHEDULER_STORE：无法建立状态临时文件');
        }
        $stream = null;
        try {
            $stream = fopen($temporary, 'wb');
            if ($stream === false || fwrite($stream, $json) !== strlen($json) || !fflush($stream) || !fsync($stream)) {
                throw new RuntimeException('TYPE_SCHEDULER_STORE：无法持久化状态，停止调度');
            }
            fclose($stream);
            $stream = null;
            if (!rename($temporary, $this->filename)) {
                throw new RuntimeException('TYPE_SCHEDULER_STORE：无法原子替换状态文件');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** 由持有执行者释放稳定锁并关闭句柄；未持有时重复调用无副作用。 */
    public function release(): void
    {
        if ($this->lock === null) {
            return;
        }
        $this->assertOwner();
        flock($this->lock, LOCK_UN);
        fclose($this->lock);
        $this->lock = null;
        $this->owner = null;
    }

    private function assertOwner(): void
    {
        if ($this->owner === null || $this->lock === null) {
            throw new RuntimeException('TYPE_SCHEDULER_STORE：状态操作需要先取得执行锁');
        }
        $this->owner->assertCurrent();
    }
}
