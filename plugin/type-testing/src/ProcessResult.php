<?php

declare(strict_types=1);

namespace Type\Testing;

final class ProcessResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly bool $timedOut,
        public readonly bool $outputExceeded,
        public readonly ?int $signal
    ) {
    }
    public function successful(): bool
    {
        return $this->exitCode === 0 && !$this->timedOut && !$this->outputExceeded && $this->signal === null;
    }
}
