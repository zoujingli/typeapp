<?php

declare(strict_types=1);

namespace Type\Orm;

use Closure;
use Throwable;
use Type\Runtime\ResourceLease;
use Type\Runtime\ReusableResource;
use Type\Runtime\ExecutionScope;

/** 执行作用域中的数据库租约；集中管理参数绑定、事务事实、模型失效与查询诊断。 */
final class Connection
{
    private ResourceLease $lease;
    private int $reads = 0;
    private int $writes = 0;
    private array $readParameterSizes = [];
    private array $transactions = [];
    private bool $rollbackOnly = false;
    private string $outcome = TransactionOutcome::NOT_STARTED;
    private array $identity;
    private array $listeners = [];
    private int $listenerSequence = 0;
    private bool $notifying = false;

    /**
     * @internal 绑定作用域已借出的 PDO 租约及端点身份。
     *
     * @param array<string, mixed> $identity
     */
    public function __construct(ResourceLease $lease, array $identity = [])
    {
        $this->lease = $lease;
        $this->identity = $identity;
    }

    /**
     * 返回借出时的身份快照，后续凭据轮换不会改变已借出租约。
     *
     * @return array<string, mixed> 包含驱动、端点、逻辑库、角色与凭据代次，不包含密码。
     */
    public function identity(): array
    {
        return $this->identity;
    }

    /**
     * 执行参数化查询并读取全部行；大结果应选择 stream 或分页。
     *
     * @param array<int|string, scalar|null> $parameters 位置参数从零编号，命名参数保留键名。
     * @return list<array<string, mixed>>
     */
    public function query(string $sql, array $parameters = []): array
    {
        $this->recordRead(count($parameters));
        return $this->operation(static fn (PdoSession $session): array => $session->query($sql, $parameters), $sql, $parameters);
    }

    /** 为基础设施或跨模型投影建立不可变表查询；业务实体优先使用 Model。 */
    public function table(string $table, string $alias = ''): Query
    {
        return new Query($this, $table, $alias);
    }

    /** 从当前租约读取方言名称，已归还或错误执行者的租约会被拒绝。 */
    public function driverName(): string
    {
        return $this->session()->driverName();
    }

    /** 读取实际服务器版本供能力选择；活跃结果流期间不能插入该操作。 */
    public function serverVersion(): string
    {
        return $this->session()->serverVersion();
    }

    /** 返回实际解析到的表列，包含临时表遮蔽；标识符始终校验。 */
    public function columns(string $table): array
    {
        $session = $this->session();
        $driver = $session->driverName();
        $dialect = new SqlDialect($driver, $session->serverVersion());
        $quoted = $dialect->identifier($table);
        if ($driver === 'mysql') {
            $sql = 'SHOW FULL COLUMNS FROM ' . $quoted;
            $parameters = [];
        } elseif ($driver === 'pgsql') {
            $sql = 'SELECT a.attname AS name, format_type(a.atttypid, a.atttypmod) AS type, a.attnotnull AS not_null, '
                . 'EXISTS (SELECT 1 FROM pg_index i WHERE i.indrelid = a.attrelid AND i.indisprimary AND a.attnum = ANY(i.indkey)) AS primary_key '
                . 'FROM pg_attribute a WHERE a.attrelid = to_regclass(?) AND a.attnum > 0 AND NOT a.attisdropped';
            $parameters = [$quoted];
        } else {
            $parts = explode('.', $table);
            $sql = 'SELECT name, type, "notnull" AS not_null, pk AS primary_key FROM pragma_table_info(' . (count($parts) === 1 ? '?' : '?, ?') . ')';
            $parameters = count($parts) === 1 ? [$table] : [$parts[1], $parts[0]];
        }
        $this->recordRead(count($parameters));
        $rows = $this->operation(static fn (PdoSession $resource): array => $resource->query($sql, $parameters), $sql, $parameters, 'metadata');
        $columns = [];
        foreach ($rows as $row) {
            $primary = $driver === 'mysql' ? $row['Key'] === 'PRI' : in_array($row['primary_key'], [true, 1, '1', 't'], true);
            $notNull = $driver === 'mysql' ? $row['Null'] === 'NO' : in_array($row['not_null'], [true, 1, '1', 't'], true);
            if ($driver === 'sqlite') {
                $primary = (int) $row['primary_key'] > 0;
                $notNull = $notNull || ($primary && strtoupper((string) $row['type']) === 'INTEGER');
            }
            $columns[] = ['name' => (string) ($driver === 'mysql' ? $row['Field'] : $row['name']),
                'type' => (string) ($driver === 'mysql' ? $row['Type'] : $row['type']), 'primary' => $primary, 'nullable' => !$notNull];
        }
        return $columns;
    }

    /** @internal 核验实际解析到的 MySQL 表及会话，避免非事务表留下部分集合写入。 */
    public function assertAtomicWriteStorage(string $table): void
    {
        if ($this->driverName() !== 'mysql') {
            return;
        }
        $dialect = new SqlDialect('mysql', $this->serverVersion());
        $sql = 'SHOW CREATE TABLE ' . $dialect->identifier($table);
        $this->recordRead(0);
        $schema = $this->operation(static fn (PdoSession $session): array => $session->query($sql, []), $sql, [], 'metadata');
        $mode = $this->query('SELECT @@SESSION.sql_mode AS modes')[0]['modes'];
        if (count($schema) !== 1 || preg_match('/^\) ENGINE=InnoDB\b/m', (string) ($schema[0]['Create Table'] ?? '')) !== 1
            || preg_match('/(?:^|,)(?:STRICT_ALL_TABLES|STRICT_TRANS_TABLES)(?:,|$)/', $mode) !== 1) {
            throw new ModelException('unsafe_batch_storage', 'MySQL 集合写入需要 InnoDB 事务表及严格 SQL 模式');
        }
    }

    /** 流关闭前独占本租约；事务内应使用普通查询或在独立连接导出。 */
    public function stream(string $sql, array $parameters = [], int $batchSize = 250, int $maxRowBytes = 1048576): RowStream
    {
        if ($batchSize < 1 || $batchSize > 1000 || $maxRowBytes < 1 || $maxRowBytes > 16777216) {
            throw new DatabaseException('流批次必须在 1 至 1000 行，单行大小必须在 1 字节至 16 MiB 之间');
        }
        if ($this->transactions !== [] || SqlStatement::operation($sql) !== 'SELECT') {
            throw new DatabaseException('流式读取只接受事务外的单条 SELECT');
        }
        $this->guardTransactionSql($sql);
        $session = $this->session();
        $this->recordRead(count($parameters));
        $id = $this->operation(static fn (PdoSession $session): int => $session->openStream($sql, $parameters, $batchSize), $sql, $parameters, 'stream-open');
        return new RowStream($this->lease, $session, $id, $maxRowBytes);
    }

    /**
     * 执行参数化语句并返回数据库报告的影响行数，遵守事务与只读角色检查。
     *
     * @param array<int|string, scalar|null> $parameters
     * @return int 各数据库保留各自的匹配行/改变行语义。
     */
    public function execute(string $sql, array $parameters = []): int
    {
        $this->writes++;
        return $this->operation(static fn (PdoSession $session): int => $session->execute($sql, $parameters), $sql, $parameters);
    }

    /**
     * 执行原生 SQL，并在归还时退役物理会话。
     *
     * 原生语句可能改变服务器端变量、临时对象、命名锁或触发器状态，
     * Connection 无法从 SQL 文本证明这些副作用已经恢复；退役保证未知状态不会进入空闲池。
     */
    public function raw(string $sql, array $parameters = []): int
    {
        $this->writes++;
        return $this->operation(static fn (PdoSession $session): int => $session->execute($sql, $parameters), $sql, $parameters, 'raw', true);
    }

    /** 原生查询结果不代表没有副作用，归还租约时同样退役物理会话。 */
    public function rawQuery(string $sql, array $parameters = []): array
    {
        $this->recordRead(count($parameters));
        return $this->operation(static fn (PdoSession $session): array => $session->query($sql, $parameters), $sql, $parameters, 'raw-query', true);
    }

    /** 读取同一物理会话最近生成的主键文本；需在租约归还之前调用。 */
    public function lastInsertId(): string
    {
        return $this->session()->lastInsertId();
    }

    /** @param Closure(Connection): mixed $operation 始终接收当前事务连接，异常时回滚。 */
    public function transaction(Closure $operation, string $mode = 'default'): mixed
    {
        $this->session()->assertIdle();
        return $this->lease->hold(function (ReusableResource $resource) use ($operation, $mode): mixed {
            if (!$resource instanceof PdoSession) {
                throw new DatabaseException('租约中没有数据库会话');
            }
            return $this->runTransaction($resource, $operation, $mode);
        });
    }

    private function runTransaction(PdoSession $session, Closure $operation, string $mode): mixed
    {
        $depth = count($this->transactions);
        if ($this->rollbackOnly || ($depth > 0 && $mode !== 'default')) {
            throw new DatabaseException('失效事务不能继续嵌套，内层也不能改变事务模式');
        }
        $savepoint = 'type_sp_' . $depth;
        if ($depth === 0) {
            $this->rollbackOnly = false;
            $this->outcome = TransactionOutcome::NOT_STARTED;
            if (!in_array($mode, ['default', 'immediate', 'schema'], true) || ($mode === 'schema' && $session->driverName() === 'mysql')) {
                throw new TransactionException(TransactionOutcome::NOT_STARTED, '事务模式无效，MySQL DDL 必须独立执行');
            }
            try {
                $session->begin($mode === 'schema' ? 'default' : $mode);
            } catch (Throwable $error) {
                throw new TransactionException(TransactionOutcome::NOT_STARTED, $error->getMessage(), $error);
            }
            $this->outcome = TransactionOutcome::ACTIVE;
        } else {
            try {
                $session->savepoint($savepoint);
            } catch (Throwable $error) {
                $session->retire();
                $this->rollbackOnly = true;
                $this->outcome = TransactionOutcome::UNKNOWN;
                $this->invalidateAllModels();
                throw new TransactionException(TransactionOutcome::UNKNOWN, '保存点未能建立，事务状态需要核对', $error);
            }
        }
        $schema = $depth === 0 ? $mode === 'schema' : $this->transactions[$depth - 1]['schema'];
        $this->transactions[] = ['models' => [], 'callbacks' => [], 'schema' => $schema];
        try {
            $result = $operation($this);
            // 关闭作用域或租约表示取消；持有中的会话仍可安全回滚。
            $this->lease->resource();
            if ($this->rollbackOnly) {
                throw new DatabaseException('事务已经失效，只允许回滚');
            }
        } catch (Throwable $error) {
            $frame = array_pop($this->transactions);
            $this->invalidateModels($frame['models']);
            try {
                if ($depth === 0) {
                    if (!$session->rollback()) {
                        throw new DatabaseException('事务已经不活动，无法确认本次回滚');
                    }
                } else {
                    $session->rollbackTo($savepoint);
                }
            } catch (Throwable $cleanupError) {
                $session->retire();
                $this->rollbackOnly = true;
                $this->outcome = TransactionOutcome::UNKNOWN;
                $this->invalidateAllModels();
                throw new TransactionException(TransactionOutcome::UNKNOWN, $error->getMessage() . '；回滚无法确认', $error);
            }
            if ($depth === 0) {
                $this->outcome = TransactionOutcome::ROLLED_BACK;
            }
            throw $error;
        }
        try {
            if ($depth === 0) {
                $session->commit();
            } else {
                $session->releaseSavepoint($savepoint);
            }
        } catch (Throwable $error) {
            $frame = array_pop($this->transactions);
            $this->invalidateModels($frame['models']);
            $session->retire();
            $this->rollbackOnly = true;
            $this->outcome = TransactionOutcome::UNKNOWN;
            $this->invalidateAllModels();
            throw new TransactionException(TransactionOutcome::UNKNOWN, '数据库提交或保存点结果未知，不允许自动重试', $error);
        }
        $frame = array_pop($this->transactions);
        foreach ($frame['models'] as $model) {
            $this->trackModel($model);
        }
        if ($depth > 0) {
            array_push($this->transactions[$depth - 1]['callbacks'], ...$frame['callbacks']);
        } else {
            // 先固定提交事实，再运行外部效果；回调失败不会重新执行事务体。
            $this->outcome = TransactionOutcome::COMMITTED;
            $errors = [];
            foreach ($frame['callbacks'] as $callback) {
                try {
                    $callback();
                } catch (Throwable $error) {
                    $errors[] = $error;
                }
            }
            if ($errors !== []) {
                throw new AfterCommitException($errors);
            }
        }
        return $result;
    }

    /** 返回当前连接活动事务帧数；内层帧对应保存点。 */
    public function transactionDepth(): int
    {
        return count($this->transactions);
    }
    /** 返回当前连接最近的事务事实；提交后回调里的新事务可以推进该状态。 */
    public function transactionOutcome(): string
    {
        return $this->outcome;
    }

    /** @param Closure(): mixed $callback 提交确认后零参数调用，返回值忽略。 */
    public function afterCommit(Closure $callback): void
    {
        $this->session();
        if ($this->transactions === []) {
            throw new TransactionException(TransactionOutcome::NOT_STARTED, '提交后回调必须在活动事务中登记');
        }
        $this->transactions[count($this->transactions) - 1]['callbacks'][] = $callback;
    }

    private function guardTransactionSql(string $sql): void
    {
        if (($this->identity['role'] ?? 'writer') === 'reader' && !in_array(SqlStatement::operation($sql), ['SELECT', 'SHOW'], true)) {
            throw new DatabaseException('只读身份只接受查询操作，不能修改会话或写入数据');
        }
        if ($this->transactions === []) {
            return;
        }
        $operation = SqlStatement::operation($sql);
        if (in_array($operation, ['SELECT', 'INSERT', 'UPDATE', 'DELETE'], true)) {
            return;
        }
        if ($this->transactions[count($this->transactions) - 1]['schema']
            && preg_match('/^\s*(CREATE\s+(TABLE|(?:TEMP|TEMPORARY)\s+TABLE|INDEX|UNIQUE\s+INDEX)\b|ALTER\s+TABLE\b|DROP\s+(TABLE|INDEX)\b)/i', $sql)) {
            return;
        }
        throw new DatabaseException('普通事务只接受受管查询和数据写入；DDL 使用独立迁移入口');
    }

    /** @internal 水合、保存、删除均登记；内层成功后合并至父事务。 */
    public function trackModel(Model $model): void
    {
        if ($this->transactions === []) {
            return;
        }
        $this->session();
        $index = count($this->transactions) - 1;
        if (!in_array($model, $this->transactions[$index]['models'], true)) {
            $this->transactions[$index]['models'][] = $model;
        }
    }

    private function invalidateModels(array $models): void
    {
        foreach ($models as $model) {
            $model->invalidate();
        }
    }

    private function invalidateAllModels(): void
    {
        foreach ($this->transactions as $frame) {
            $this->invalidateModels($frame['models']);
        }
    }

    /** 检查租约是否已归还，不能据此绕过执行者或作用域检查。 */
    public function released(): bool
    {
        return $this->lease->released();
    }

    /** 停止当前租约，后续操作拒绝；持有中的原生操作完成前不会转借会话。 */
    public function close(): void
    {
        if ($this->notifying) {
            throw new DatabaseException('查询监听不能关闭正在通知的连接');
        }
        foreach ($this->listeners as $listener) {
            $listener->stop();
        }
        $this->lease->stop();
    }

    /**
     * 注册作用域监听；默认不记录 SQL 字面值或绑定值，记录 SQL 摘要以关联显式预览。
     * @param Closure(QueryEvent): mixed|null $listener 只处理事件，不能重入当前连接。
     */
    public function listen(ExecutionScope $scope, ?Closure $listener = null, int $maxRecords = 100, int $maxBytes = 65536, bool $includeValues = false, float $slowMilliseconds = 100.0): QueryLog
    {
        $this->session();
        if (count($this->listeners) >= 16) {
            throw new DatabaseException('同一连接最多注册十六个查询监听');
        }
        $id = ++$this->listenerSequence;
        $log = new QueryLog($listener, function () use ($id): void {
            unset($this->listeners[$id]);
        }, $maxRecords, $maxBytes, $includeValues, $slowMilliseconds);
        $scope->open($log);
        $this->listeners[$id] = $log;
        return $log;
    }

    /** 当前逻辑连接的调用统计；只保留最近 128 次读取的参数数量。 */
    public function statistics(): array
    {
        return ['read_attempts' => $this->reads, 'write_attempts' => $this->writes, 'recent_read_parameter_sizes' => $this->readParameterSizes];
    }

    private function recordRead(int $parameters): void
    {
        $this->reads++;
        $this->readParameterSizes[] = $parameters;
        if (count($this->readParameterSizes) > 128) {
            array_shift($this->readParameterSizes);
        }
    }

    private function session(): PdoSession
    {
        if ($this->notifying) {
            throw new DatabaseException('查询监听不能重入当前连接');
        }
        if ($this->rollbackOnly) {
            throw new DatabaseException('事务已经失效，只允许退出并回滚');
        }
        $resource = $this->lease->resource();
        if (!$resource instanceof PdoSession) {
            throw new DatabaseException('租约中没有数据库会话');
        }

        return $resource;
    }

    private function operation(Closure $operation, string $sql, array $parameters, string $phase = 'statement', bool $retire = false): mixed
    {
        $started = hrtime(true);
        $success = false;
        $errorClass = null;
        try {
            if ($phase !== 'metadata') {
                $this->guardTransactionSql($sql);
            }
            $this->session();
            $result = $this->lease->hold(function (ReusableResource $resource) use ($operation, $retire): mixed {
                if (!$resource instanceof PdoSession) {
                    throw new DatabaseException('租约中没有数据库会话');
                }
                try {
                    $result = $operation($resource);
                    try {
                        $this->lease->resource();
                    } catch (Throwable $error) {
                        $resource->retire();
                        throw $error;
                    }
                    return $result;
                } finally {
                    if ($retire) {
                        $resource->retire();
                    }
                }
            });
            $success = true;
            return $result;
        } catch (Throwable $error) {
            $errorClass = get_class($error);
            throw $error;
        } finally {
            $this->notifyQuery($sql, $parameters, $started, $success, $errorClass, $phase);
        }
    }

    private function notifyQuery(string $sql, array $parameters, int $started, bool $success, ?string $errorClass, string $phase): void
    {
        if ($this->notifying || $this->listeners === []) {
            return;
        }
        $this->notifying = true;
        try {
            $match = [];
            preg_match('/^\s*([A-Za-z]+)/', $sql, $match);
            $event = ['connection' => hash('sha256', serialize($this->identity)), 'driver' => $this->identity['driver'] ?? '',
                'operation' => strtoupper($match[1] ?? 'UNKNOWN'), 'duration_ms' => (hrtime(true) - $started) / 1000000.0,
                'success' => $success, 'error_class' => $errorClass, 'transaction_depth' => count($this->transactions),
                'transaction_outcome' => $this->outcome, 'phase' => $phase];
            foreach ($this->listeners as $listener) {
                try {
                    $listener->record($event, $sql, $parameters);
                } catch (Throwable) {
                    // 诊断实现自身失败也不能改变已经发生的数据库结果。
                }
            }
        } finally {
            $this->notifying = false;
        }
    }
}
