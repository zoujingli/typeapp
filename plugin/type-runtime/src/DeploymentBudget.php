<?php

declare(strict_types=1);

namespace Type\Runtime;

/** 同一服务端连接域的部署上限；包括所有角色、最大副本与滚动增量。 */
final class DeploymentBudget
{
    private int $perProcess;
    private int $perThread;
    private array $plan;
    private ?ResourceBudget $budget = null;
    private ?ExecutionOwner $owner = null;

    /**
     * 所有角色使用同一部署计划，每线程只获得其中一份，不复制整个进程额度。
     * @param int $threadsPerProcess 最大同时使用连接的线程数，含主线程和未 join 的旧代。
     */
    public function __construct(int $serverLimit, int $replicas, int $surge, int $processesPerReplica, int $administrationReserve, int $threadsPerProcess = 1)
    {
        foreach ([$serverLimit, $replicas, $processesPerReplica, $threadsPerProcess] as $value) {
            if ($value < 1 || $value > 1000000) {
                throw new \InvalidArgumentException('部署容量参数无效');
            }
        }
        if ($surge < 0 || $surge > 1000000 || $administrationReserve < 0 || $administrationReserve >= $serverLimit) {
            throw new \InvalidArgumentException('管理预留或滚动增量无效');
        }
        $totalProcesses = ($replicas + $surge) * $processesPerReplica;
        $this->perProcess = intdiv($serverLimit - $administrationReserve, $totalProcesses);
        $this->perThread = intdiv($this->perProcess, $threadsPerProcess);
        if ($this->perThread < 1) {
            throw new CapacityException('连接上限不能覆盖部署进程、线程数与管理预留');
        }
        $this->plan = ['server_limit' => $serverLimit, 'replicas' => $replicas, 'surge' => $surge, 'processes_per_replica' => $processesPerReplica,
            'administration_reserve' => $administrationReserve, 'per_process' => $this->perProcess,
            'threads_per_process' => $threadsPerProcess, 'per_thread' => $this->perThread,
            'maximum_application_connections' => $this->perThread * $threadsPerProcess * $totalProcesses];
    }
    /** 每线程只创建一个实例，并注入该服务端连接域的全部池及凭据代次。 */
    public function poolBudget(): ResourceBudget
    {
        $this->owner ??= new ExecutionOwner(false);
        $this->owner->assertCurrent();
        $this->budget ??= new ResourceBudget($this->perThread);
        return $this->budget;
    }
    /**
     * 返回部署容量分配快照，不代表服务端实际连接数。
     * @return array<string, int>
     */
    public function statistics(): array
    {
        return $this->plan;
    }
}
