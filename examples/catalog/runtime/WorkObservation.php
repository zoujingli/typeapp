<?php

declare(strict_types=1);

namespace app\catalog\runtime;

use Closure;
use Type\Runtime\ExecutionScope;
use Type\Runtime\ManagedResource;

/** 仅供显式可靠性演练观察任务收尾；不参与正常业务或改变任务确认协议。 */
final class WorkObservation implements ManagedResource
{
    public array $beforeClose = [];
    public int $starts = 0;
    public int $stops = 0;
    public bool $closed = false;
    public bool $failClose = false;
    public ?object $role = null;

    /**
     * @param array<string,int|string> $identity 本次消息尝试或计划身份，不承载权限。
     * @param Closure(): array<string,mixed> $observe 只读取外层队列/持锁计划及资源计数。
     */
    public function __construct(public ExecutionScope $scope, public array $identity, private Closure $observe)
    {
    }

    /** 资源真实登记于当前工作作用域，不能借用命令的外层作用域。 */
    public function start(): void
    {
        if (ExecutionScope::current() !== $this->scope) {
            throw new \RuntimeException('catalog_work_scope_mismatch');
        }
        $this->starts++;
    }

    /**
     * 先观察仍未确认的工作，再报告收尾；显式失败时由原作用域继续持有。
     * @throws \RuntimeException 演练要求暂时保留未完成资源。
     */
    public function stop(): void
    {
        if ($this->closed) {
            return;
        }
        $this->stops++;
        if ($this->beforeClose === []) {
            $this->beforeClose = ($this->observe)();
        }
        if ($this->failClose) {
            throw new \RuntimeException('catalog_work_cleanup_pending');
        }
        $this->closed = true;
    }
}
