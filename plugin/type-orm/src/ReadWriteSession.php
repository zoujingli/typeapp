<?php

declare(strict_types=1);

namespace Type\Orm;

use Closure;
use Type\Runtime\ExecutionScope;

/** 一个执行作用域的读写选择与粘滞状态，不放进全局配置。 */
final class ReadWriteSession
{
    private DatabaseManager $manager;
    private ExecutionScope $scope;
    private string $primaryName;
    private string $replicaName;
    private ?Connection $primary = null;
    private ?Connection $replica = null;
    private bool $sticky = false;
    private string $outcome = TransactionOutcome::NOT_STARTED;

    public function __construct(DatabaseManager $manager, ExecutionScope $scope, string $primary = 'primary', string $replica = 'replica')
    {
        $writer = $manager->identity($primary);
        $reader = $manager->identity($replica);
        if ($writer['role'] !== 'writer' || $reader['role'] !== 'reader' || $writer['driver'] !== $reader['driver']) {
            throw new DatabaseException('主从连接必须是同驱动的 writer 与 reader 身份');
        }
        $this->manager = $manager;
        $this->scope = $scope;
        $this->primaryName = $primary;
        $this->replicaName = $replica;
    }

    public function read(bool $strong = false): Connection
    {
        $this->scope->assertActive();
        $this->certain();
        if ($strong || $this->sticky) {
            return $this->primary();
        }
        $this->replica ??= $this->manager->connect($this->scope, $this->replicaName);
        return $this->replica;
    }

    public function write(): Connection
    {
        $this->scope->assertActive();
        $this->certain();
        $this->sticky = true;
        return $this->primary();
    }

    /** @param Closure(Connection): mixed $operation 接收实际主库事务连接。 */
    public function transaction(Closure $operation): mixed
    {
        $connection = $this->write();
        try {
            return $connection->transaction($operation);
        } finally {
            $this->outcome = $connection->transactionOutcome();
        }
    }

    /** 提交未知后显式建立新的主库连接供业务按操作 ID 对账，不修改未知事实。 */
    public function reconcile(): Connection
    {
        $this->scope->assertActive();
        if ($this->primary !== null) {
            if ($this->primary->transactionDepth() > 0) {
                throw new DatabaseException('活动事务中不能切换对账连接');
            }
            $this->primary->close();
            $this->primary = null;
        }
        $this->sticky = true;
        return $this->primary();
    }

    public function outcome(): string
    {
        if ($this->outcome !== TransactionOutcome::UNKNOWN && $this->primary !== null) {
            $this->outcome = $this->primary->transactionOutcome();
        }
        return $this->outcome;
    }

    private function primary(): Connection
    {
        $this->primary ??= $this->manager->connect($this->scope, $this->primaryName);
        return $this->primary;
    }

    private function certain(): void
    {
        if ($this->outcome() === TransactionOutcome::UNKNOWN) {
            throw new TransactionException(TransactionOutcome::UNKNOWN, '先前提交结果未知，请通过 reconcile 显式对账');
        }
    }
}
