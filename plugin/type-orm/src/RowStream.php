<?php

declare(strict_types=1);

namespace Type\Orm;

use Closure;
use Throwable;
use Type\Runtime\ExecutionOwner;
use Type\Runtime\ResourceLease;
use Type\Runtime\ReusableResource;

/** 显式关闭的逐行结果；不依赖生成器垃圾回收释放数据库游标。 */
final class RowStream
{
    private bool $closed = false;
    private int $rows = 0;
    private ExecutionOwner $owner;

    /** @internal 绑定已打开游标与所属租约；maxRowBytes 为单行字节上限。 */
    public function __construct(private ResourceLease $lease, private PdoSession $session, private int $id, private int $maxRowBytes)
    {
        $this->owner = new ExecutionOwner();
        if ($maxRowBytes < 1 || $maxRowBytes > 16777216) {
            $this->session->closeStream($this->id);
            throw new DatabaseException('流单行大小必须在 1 字节至 16 MiB 之间');
        }
    }

    /**
     * 读取一行，结束返回 null；超限或异常关闭游标，不能跨执行者读取。
     *
     * @return array<string, mixed>|null
     */
    public function next(): ?array
    {
        $this->owner->assertCurrent();
        if ($this->closed) {
            return null;
        }
        try {
            $row = $this->lease->hold(function (ReusableResource $resource): ?array {
                if ($resource !== $this->session) {
                    throw new DatabaseException('流所属的数据库租约已经改变');
                }
                $row = $this->session->fetchStream($this->id);
                $this->lease->resource();
                return $row;
            });
            if ($row === null) {
                $this->close();
                return null;
            }
            $bytes = 0;
            foreach ($row as $value) {
                if (is_resource($value)) {
                    throw new DatabaseException('流式导出不接受隐式 LOB 资源');
                }
                $bytes += strlen((string) $value);
            }
            if ($bytes > $this->maxRowBytes) {
                throw new DatabaseException('流式读取超过声明的单行大小');
            }
            $this->rows++;
            return $row;
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    /**
     * 成功、异常、提前退出均关闭游标。
     * @param Closure(array): mixed $consumer 接收当前行；可返回 void，仅 false 提前退出。
     */
    public function each(Closure $consumer): int
    {
        try {
            while (($row = $this->next()) !== null) {
                if ($consumer($row) === false) {
                    break;
                }
            }
            return $this->rows;
        } finally {
            $this->close();
        }
    }

    /** 显式关闭游标并恢复流占用的会话状态；调用必须来自原执行者。 */
    public function close(): void
    {
        $this->owner->assertCurrent();
        if (!$this->closed) {
            $this->closed = true;
            $this->session->closeStream($this->id);
        }
    }

    /** 检查本包装或底层游标是否已关闭，不再推进读取。 */
    public function closed(): bool
    {
        return $this->closed || !$this->session->streamActive($this->id);
    }
    /** 返回本流已成功交付的行数，不执行总数查询。 */
    public function rows(): int
    {
        return $this->rows;
    }
}
