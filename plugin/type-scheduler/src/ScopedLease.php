<?php

declare(strict_types=1);

namespace Type\Scheduler;

use Type\Redis\RedisConnection;
use Type\Redis\ScriptGuard;
use Type\Runtime\ExecutionScope;

/** 每次任务包装当前代次；旧 TaskContext 不能使用后续任务的共享存储租约。 */
final class ScopedLease implements ExecutionLease, ScriptGuard
{
    private ExecutionLease $lease;
    private ExecutionScope $scope;

    public function __construct(ExecutionLease $lease, ExecutionScope $scope)
    {
        $this->lease = $lease;
        $this->scope = $scope;
    }

    public function generation(): string
    {
        $this->scope->assertActive();
        return $this->lease->generation();
    }

    public function assertOwned(): void
    {
        $this->scope->assertActive();
        $this->lease->assertOwned();
    }

    public function renew(): void
    {
        $this->scope->assertActive();
        $this->lease->renew();
    }

    public function execute(RedisConnection $target, string $script, array $keys, array $arguments): mixed
    {
        $this->scope->assertActive();
        if (!$this->lease instanceof ScriptGuard) {
            throw new LeaseException('target_mismatch', '当前租约不能原子保护 Redis 目标');
        }
        return $this->lease->execute($target, $script, $keys, $arguments);
    }
}
