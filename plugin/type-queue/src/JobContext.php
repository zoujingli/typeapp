<?php

declare(strict_types=1);

namespace Type\Queue;

use Type\Runtime\ExecutionScope;

/** 把本次投递租约与执行作用域绑定，提供稳定消息身份和有效性检查。 */
final class JobContext
{
    private ExecutionScope $scope;
    private Reservation $reservation;
    /** 将租约绑定到本次作用域；同一租约不得改绑其他执行预算。 */
    public function __construct(ExecutionScope $scope, Reservation $reservation)
    {
        $this->scope = $scope;
        $this->reservation = $reservation;
        $reservation->bindScope($scope);
    }
    /** 返回本次任务作用域；任务资源登记于此，由 Worker 在确认前收尾。 */
    public function scope(): ExecutionScope
    {
        return $this->scope;
    }
    /** 返回业务消息及稳定 ID，不使用每次重投变化的 Stream ID 代替业务身份。 */
    public function message(): Message
    {
        return $this->reservation->message();
    }
    /** 返回当前投递租约，供显式续租及同 Redis 受管副作用使用。 */
    public function reservation(): Reservation
    {
        return $this->reservation;
    }
    /**
     * 同时检查执行截止与当前租约；检查不替代写入目标的原子防重。
     *
     * @throws QueueException 租约已失效。
     */
    public function assertActive(): void
    {
        $this->scope->assertActive();
        $this->reservation->assertOwned();
    }
}
