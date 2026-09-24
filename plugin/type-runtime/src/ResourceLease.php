<?php

declare(strict_types=1);

namespace Type\Runtime;

use Closure;
use RuntimeException;

/** 作用域独占的池租约；借用资源不跨执行者，归还不早于实际操作完成。 */
final class ResourceLease implements ManagedResource
{
    private ResourcePool $pool;
    private ExecutionScope $scope;
    private int $id;
    private bool $released = false;

    /** @internal 仅由池为已分配的资源创建，调用者通过 ResourcePool::borrow() 借用。 */
    public function __construct(ResourcePool $pool, ExecutionScope $scope, int $id)
    {
        $this->pool = $pool;
        $this->scope = $scope;
        $this->id = $id;
    }

    /** 登记租约时检查作用域仍可用；物理资源由池负责创建。 */
    public function start(): void
    {
        $this->scope->assertActive();
    }

    /** 返回归还意图是否已登记；不等于底层资源已完成关闭或复用。 */
    public function released(): bool
    {
        return $this->released;
    }

    /**
     * 在所有者作用域内取得资源；有在途操作时应使用 hold() 管理持有期。
     * @throws RuntimeException 作用域不可用、租约已归还或资源不可借用。
     */
    public function resource(): ReusableResource
    {
        $this->scope->assertActive();
        if ($this->released) {
            throw new RuntimeException('资源租约已经归还');
        }

        return $this->pool->resource($this->id);
    }

    /** 幂等登记归还；池继续持有仍在途的操作，不能据此转移资源所有权。 */
    public function stop(): void
    {
        if (!$this->released) {
            $this->scope->assertOwner();
            $this->released = true;
            $this->pool->release($this->id);
        }
    }

    /**
     * 操作退出前继续占用池容量；关闭意图不会提前复用活动资源。
     * @param Closure(ReusableResource): mixed $operation 接收本租约持有的资源。
     */
    public function hold(Closure $operation): mixed
    {
        $this->resource();
        return $this->pool->hold($this->id, $operation);
    }

    /** 租约不能序列化到其他线程或进程；跨线程只发送业务数据。 */
    public function __serialize(): array
    {
        throw new TaskException('resource_transfer_forbidden', '资源租约不能跨线程或进程传递');
    }

    /** 独占租约不能复制，重复所有者会破坏归还时机。 */
    public function __clone(): void
    {
        throw new TaskException('resource_transfer_forbidden', '资源租约不能复制');
    }
}
