<?php

declare(strict_types=1);

namespace Type\Scheduler;

/** 同步执行者持有 acquire 至 release；持久化失败必须抛出，不能宣称成功。 */
interface StateStore
{
    public function acquire(): void;
    public function load(): array;
    public function save(array $state): void;
    public function release(): void;
}
