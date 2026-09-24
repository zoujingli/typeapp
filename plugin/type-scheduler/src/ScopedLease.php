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

    /** 将共享组租约限制在本次任务作用域内，避免旧上下文借用后续任务权限。 */
    public function __construct(ExecutionLease $lease, ExecutionScope $scope)
    {
        $this->lease = $lease;
        $this->scope = $scope;
    }

    /** 仅在所属作用域有效时返回当前租约代次。 */
    public function generation(): string
    {
        $this->scope->assertActive();
        return $this->lease->generation();
    }

    /** 先验证作用域预算，再核对存储中的实际租约持有权。 */
    public function assertOwned(): void
    {
        $this->scope->assertActive();
        $this->lease->assertOwned();
    }

    /** 仅为仍活动的任务续租，不延长任务作用域截止。 */
    public function renew(): void
    {
        $this->scope->assertActive();
        $this->lease->renew();
    }

    /**
     * 在作用域有效且底层支持 ScriptGuard 时执行受租约保护的 Redis 脚本。
     *
     * @param list<string> $keys 可信脚本所需键列表。
     * @param list<string|int|float> $arguments 脚本的标量参数列表。
     * @throws LeaseException 底层租约不支持 Redis 原子保护，或目标/持有权不匹配。
     */
    public function execute(RedisConnection $target, string $script, array $keys, array $arguments): mixed
    {
        $this->scope->assertActive();
        if (!$this->lease instanceof ScriptGuard) {
            throw new LeaseException('target_mismatch', '当前租约不能原子保护 Redis 目标');
        }
        return $this->lease->execute($target, $script, $keys, $arguments);
    }
}
