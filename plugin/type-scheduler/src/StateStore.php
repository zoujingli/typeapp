<?php

declare(strict_types=1);

namespace Type\Scheduler;

/** 同步执行者持有 acquire 至 release；持久化失败必须抛出，不能宣称成功。 */
interface StateStore
{
    /** 取得一整个 tick 的独占执行权；冲突或存储不可用必须明确失败。 */
    public function acquire(): void;
    /**
     * 在持有执行权时读取已验证状态；损坏不能作为首次运行重置。
     *
     * @return array{protocol: int, cursors: array<string, int>, records: list<array<string, mixed>>}
     */
    public function load(): array;
    /**
     * 在仍持有执行权时持久化状态；返回表示存储接口已确认写入。
     *
     * @param array{protocol: int, cursors: array<string, int>, records: list<array<string, mixed>>} $state 游标使用 UTC Unix 秒。
     */
    public function save(array $state): void;
    /** 释放当前执行者持有权，不得误删其他执行者的锁。 */
    public function release(): void;
}
