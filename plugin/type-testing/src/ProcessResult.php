<?php

declare(strict_types=1);

namespace Type\Testing;

/** 子进程回收后的有界输出及退出快照，超时和输出超量分别保留。 */
final class ProcessResult
{
    /** 保存原始退出信息；stdout/stderr 已受 Process 的总输出字节预算约束。 */
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly bool $timedOut,
        public readonly bool $outputExceeded,
        public readonly ?int $signal
    ) {
    }
    /** 仅退出码为零、无终止信号且没有超时或输出超量时返回 true。 */
    public function successful(): bool
    {
        return $this->exitCode === 0 && !$this->timedOut && !$this->outputExceeded && $this->signal === null;
    }
}
