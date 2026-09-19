<?php

declare(strict_types=1);

namespace Type\Orm;

use Type\Runtime\ExecutionScope;
use Type\Runtime\ResourcePool;
use Type\Runtime\DeploymentBudget;
use Type\Runtime\ReusableResource;

final class Database
{
    private ?ResourcePool $pool = null;
    private Driver $driver;
    private int $capacity;
    private int $idleLimit;
    private bool $closed = false;
    private bool $retired = false;
    private ?DeploymentBudget $budget;
    private int $waiterLimit;
    private float $waitSeconds;

    /** 池在当前线程首次借用时创建；等待单位为秒，0 禁止排队。 */
    public function __construct(Driver $driver, int $capacity = 4, int $idleLimit = 2, ?DeploymentBudget $budget = null, int $waiterLimit = 64, float $waitSeconds = 1.0)
    {
        if ($capacity < 1 || $idleLimit < 0 || $idleLimit > $capacity || $waiterLimit < 0 || $waiterLimit > 65536
            || !is_finite($waitSeconds) || $waitSeconds < 0 || $waitSeconds > 60) {
            throw new DatabaseException('数据库池容量无效');
        }
        $this->driver = $driver;
        $this->capacity = $capacity;
        $this->idleLimit = $idleLimit;
        $this->budget = $budget;
        $this->waiterLimit = $waiterLimit;
        $this->waitSeconds = $waitSeconds;
    }

    /** 本次等待只能缩短池上限，0 立即拒绝；连接仍属于传入作用域。 */
    public function connect(ExecutionScope $scope, ?float $waitSeconds = null): Connection
    {
        if ($this->closed || $this->retired) {
            throw new DatabaseException('数据库池已经关闭或退役');
        }
        if ($this->pool === null) {
            $driver = $this->driver;
            $this->pool = new ResourcePool(
                static fn (): PdoSession => new PdoSession($driver),
                $this->capacity,
                $this->idleLimit,
                $this->budget?->poolBudget(),
                $this->waiterLimit,
                $this->waitSeconds
            );
        }
        $lease = $this->pool->borrow($scope, $waitSeconds);
        try {
            $lease->hold(static function (ReusableResource $resource): void {
                if (!$resource instanceof PdoSession) {
                    throw new DatabaseException('数据库租约类型错误');
                }
                $resource->initialize();
            });
            $scope->assertActive();
        } catch (\Throwable $error) {
            $lease->stop();
            throw $error;
        }
        return new Connection($lease, $this->driver->identity());
    }

    public function statistics(): array
    {
        return $this->pool === null ? ['capacity' => $this->capacity, 'created' => 0, 'leased' => 0, 'idle' => 0, 'idle_limit' => $this->idleLimit,
            'waiters' => 0, 'waiter_limit' => $this->waiterLimit, 'borrowed' => 0, 'rejected' => 0, 'cleanup_failures' => 0,
            'wait_seconds' => 0.0, 'in_flight' => 0, 'quarantined' => 0, 'closing' => 0] : $this->pool->statistics();
    }

    public function close(): void
    {
        $this->closed = true;
        $this->pool?->close();
    }

    public function retire(): void
    {
        $this->retired = true;
        $this->pool?->retire();
    }
    public function identity(): array
    {
        return $this->driver->identity();
    }
}
