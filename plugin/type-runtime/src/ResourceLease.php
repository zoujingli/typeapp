<?php

declare(strict_types=1);

namespace Type\Runtime;

use Closure;
use RuntimeException;

final class ResourceLease implements ManagedResource
{
    private ResourcePool $pool;
    private ExecutionScope $scope;
    private int $id;
    private bool $released = false;

    public function __construct(ResourcePool $pool, ExecutionScope $scope, int $id)
    {
        $this->pool = $pool;
        $this->scope = $scope;
        $this->id = $id;
    }

    public function start(): void
    {
        $this->scope->assertActive();
    }

    public function resource(): ReusableResource
    {
        $this->scope->assertActive();
        if ($this->released) {
            throw new RuntimeException('资源租约已经归还');
        }

        return $this->pool->resource($this->id);
    }

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
