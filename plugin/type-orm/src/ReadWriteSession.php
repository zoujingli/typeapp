<?php

declare(strict_types=1);

namespace Type\Orm;

use Closure;
use Type\Runtime\ExecutionScope;

/** 一个执行作用域的主从选择；只有活动事务固定主库，普通写入不改变后续读取。 */
final class ReadWriteSession
{
    private DatabaseManager $manager;
    private ExecutionScope $scope;
    private string $primaryName;
    private ?string $replicaName;
    private ?Connection $primary = null;
    private ?Connection $replica = null;
    private string $outcome = TransactionOutcome::NOT_STARTED;

    /** 绑定当前作用域及同逻辑库的主从名称，配置验证不借用物理连接。 */
    public function __construct(DatabaseManager $manager, ExecutionScope $scope, string $primary = 'primary', ?string $replica = 'replica')
    {
        $writer = $manager->identity($primary);
        $reader = $replica === null ? null : $manager->identity($replica);
        if ($writer['role'] !== 'writer' || ($reader !== null && ($reader['role'] !== 'reader' || $writer['driver'] !== $reader['driver']
            || $writer['database'] !== $reader['database'] || $writer['driver'] === 'sqlite'))) {
            throw new DatabaseException('主从连接必须是同驱动的 writer 与 reader 身份');
        }
        $this->manager = $manager;
        $this->scope = $scope;
        $this->primaryName = $primary;
        $this->replicaName = $replica;
    }

    /** 事务内或 strong=true 选择主库，其余选择已配置的从库；从库失败不静默转投。 */
    public function read(bool $strong = false): Connection
    {
        $this->scope->assertActive();
        $this->certain();
        if ($strong || $this->replicaName === null || $this->inTransaction()) {
            return $this->primary();
        }
        if ($this->replica !== null && $this->replica->released()) {
            $this->replica = null;
        }
        try {
            $this->replica ??= $this->manager->connect($this->scope, $this->replicaName);
        } catch (DatabaseException $error) {
            throw new ModelException('reader_unavailable', '已配置的只读端点不可用，不转投主库', $error);
        }
        return $this->replica;
    }

    /** 取得当前作用域的主库租约；先前结果 UNKNOWN 时拒绝继续业务写入。 */
    public function write(): Connection
    {
        $this->scope->assertActive();
        $this->certain();
        return $this->primary();
    }

    /** @param Closure(Connection): mixed $operation 接收实际主库事务连接。 */
    public function transaction(Closure $operation, string $mode = 'default'): mixed
    {
        $connection = $this->write();
        try {
            return $connection->transaction($operation, $mode);
        } finally {
            if ($this->outcome !== TransactionOutcome::UNKNOWN) {
                $this->outcome = $connection->transactionOutcome();
            }
        }
    }

    /** 提交未知后显式建立新的主库连接供业务按操作 ID 对账，不修改未知事实。 */
    public function reconcile(): Connection
    {
        $this->scope->assertActive();
        $this->outcome();
        if ($this->primary !== null) {
            if ($this->primary->transactionDepth() > 0) {
                throw new DatabaseException('活动事务中不能切换对账连接');
            }
            $this->primary->close();
            $this->primary = null;
        }
        return $this->primary();
    }

    /** 读取并保留已观察到的 UNKNOWN；显式对账不会抹去该事实。 */
    public function outcome(): string
    {
        if ($this->outcome !== TransactionOutcome::UNKNOWN && $this->primary !== null) {
            $this->outcome = $this->primary->transactionOutcome();
        }
        return $this->outcome;
    }

    /** 检查本作用域主库是否处于活动事务，不因普通写入改变后续读路由。 */
    public function inTransaction(): bool
    {
        $this->scope->assertActive();
        return $this->primary !== null && $this->primary->transactionDepth() > 0;
    }

    private function primary(): Connection
    {
        // 显式 close() 只归还当前租约；同一作用域的下一次读写必须重新领取，不能复用已归还连接。
        if ($this->primary !== null && $this->primary->released()) {
            if ($this->primary->transactionDepth() > 0) {
                throw new DatabaseException('活动事务的连接已经归还');
            }
            $this->primary = null;
        }
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
