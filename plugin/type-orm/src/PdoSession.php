<?php

declare(strict_types=1);

namespace Type\Orm;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;
use Type\Runtime\ReusableResource;

/** @internal 原生 PDO 句柄封装，不经 Connection 公共接口向外泄露。 */
final class PdoSession implements ReusableResource
{
    private ?PDO $pdo;
    private Driver $driver;
    private bool $reusable = true;
    private ?PDOStatement $streamStatement = null;
    private int $streamId = 0;
    private int $streamSequence = 0;
    private int $streamBatchSize = 250;
    private array $streamBuffer = [];
    private int $streamOffset = 0;
    private string $streamName = '';
    private bool $streamTransaction = false;
    private ?bool $mysqlBuffered = null;

    /** 建立并拥有物理 PDO；构造失败不向资源池交付可借用会话。 */
    public function __construct(Driver $driver)
    {
        $this->driver = $driver;
        $this->pdo = $driver->connect();
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * 准备并执行已校验 SQL，一次性取完关联数组，finally 关闭语句游标。
     *
     * @param array<int|string, scalar|null> $parameters
     * @return list<array<string, mixed>>
     */
    public function query(string $sql, array $parameters): array
    {
        $this->assertIdle();
        try {
            $statement = $this->connection()->prepare($sql);
            if ($statement === false) {
                throw new DatabaseException('数据库未能准备查询');
            }
            try {
                $this->bind($statement, $parameters);
                $statement->execute();

                return $statement->fetchAll(PDO::FETCH_ASSOC);
            } finally {
                $statement->closeCursor();
            }
        } catch (PDOException $error) {
            $this->reusable = false;
            throw new DatabaseException('数据库查询失败，SQLSTATE：' . $error->getCode(), 0, $error);
        }
    }

    /** 返回当前物理会话所属驱动，不改变选路。 */
    public function driverName(): string
    {
        return $this->driver->name();
    }

    /** 在无活动结果流时读取服务器版本。 */
    public function serverVersion(): string
    {
        $this->assertIdle();
        return (string) $this->connection()->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    /**
     * 准备、绑定并执行语句，返回驱动原始影响行数；SQL 错误使会话退役。
     *
     * @param array<int|string, scalar|null> $parameters
     */
    public function execute(string $sql, array $parameters): int
    {
        $this->assertIdle();
        try {
            $statement = $this->connection()->prepare($sql);
            if ($statement === false) {
                throw new DatabaseException('数据库未能准备写入');
            }
            try {
                $this->bind($statement, $parameters);
                $statement->execute();

                return $statement->rowCount();
            } finally {
                $statement->closeCursor();
            }
        } catch (PDOException $error) {
            $this->reusable = false;
            throw new DatabaseException('数据库执行失败，SQLSTATE：' . $error->getCode(), 0, $error);
        }
    }

    /** 读取当前物理连接生成的主键，活动流期间拒绝额外操作。 */
    public function lastInsertId(): string
    {
        $this->assertIdle();
        return (string) $this->connection()->lastInsertId();
    }

    /** 拒绝在未关闭结果流时插入其他数据库操作。 */
    public function assertIdle(): void
    {
        if ($this->streamId !== 0) {
            throw new DatabaseException('结果流尚未关闭，本数据库租约只允许继续读取或关闭该流');
        }
    }

    /**
     * 在事务外建立专用流：MySQL 非缓冲、PostgreSQL 游标、SQLite 逐行读取。
     *
     * @param array<int|string, scalar|null> $parameters
     * @param int $batchSize PostgreSQL 游标每批获取行数，范围 1 至 1000。
     * @return int 本会话生成的游标身份。
     */
    public function openStream(string $sql, array $parameters, int $batchSize): int
    {
        $this->assertIdle();
        if ($batchSize < 1 || $batchSize > 1000) {
            throw new DatabaseException('流批次大小必须在 1 至 1000 之间');
        }
        $pdo = $this->connection();
        if ($pdo->inTransaction()) {
            throw new DatabaseException('流式读取必须使用事务外的独立连接');
        }
        $this->streamId = ++$this->streamSequence;
        $this->streamBatchSize = $batchSize;
        try {
            if ($this->driverName() === 'mysql') {
                $attribute = constant('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY');
                $this->mysqlBuffered = (bool) $pdo->getAttribute($attribute);
                $pdo->setAttribute($attribute, false);
            }
            if ($this->driverName() === 'pgsql') {
                $pdo->beginTransaction();
                $this->streamTransaction = true;
                $name = 'type_read_cursor_' . $this->streamId;
                $statement = $pdo->prepare('DECLARE ' . $name . ' NO SCROLL CURSOR FOR ' . $sql);
                $this->bind($statement, $parameters);
                $statement->execute();
                $statement->closeCursor();
                $this->streamName = $name;
            } else {
                $this->streamStatement = $pdo->prepare($sql);
                $this->bind($this->streamStatement, $parameters);
                $this->streamStatement->execute();
            }
            return $this->streamId;
        } catch (Throwable $error) {
            $this->reusable = false;
            $this->closeStream($this->streamId);
            throw new DatabaseException('数据库结果流未能启动', 0, $error);
        }
    }

    /**
     * 读取指定活动流的一行，结果耗尽返回 null，失败禁止会话复用。
     *
     * @return array<string, mixed>|null
     */
    public function fetchStream(int $id): ?array
    {
        if (!$this->streamActive($id)) {
            throw new DatabaseException('数据库结果流已经关闭');
        }
        try {
            if ($this->streamName !== '') {
                if ($this->streamOffset >= count($this->streamBuffer)) {
                    $this->streamBuffer = [];
                    $this->streamOffset = 0;
                    $statement = $this->pdo->query('FETCH FORWARD ' . $this->streamBatchSize . ' FROM ' . $this->streamName);
                    try {
                        $this->streamBuffer = $statement->fetchAll(PDO::FETCH_ASSOC);
                    } finally {
                        $statement->closeCursor();
                    }
                }
                return $this->streamBuffer[$this->streamOffset++] ?? null;
            }
            $row = $this->streamStatement->fetch(PDO::FETCH_ASSOC);
            return $row === false ? null : $row;
        } catch (PDOException $error) {
            $this->reusable = false;
            throw new DatabaseException('数据库结果流读取失败，SQLSTATE：' . $error->getCode(), 0, $error);
        }
    }

    /** 核对游标身份是否仍是本会话当前活动流。 */
    public function streamActive(int $id): bool
    {
        return $id !== 0 && $this->streamId === $id;
    }

    /** 关闭语句与流自有事务，恢复 MySQL 缓冲选项；清理失败隔离物理连接。 */
    public function closeStream(int $id): void
    {
        if (!$this->streamActive($id)) {
            return;
        }
        try {
            $this->streamStatement?->closeCursor();
            $this->streamStatement = null;
            if ($this->streamName !== '' && $this->pdo !== null && $this->pdo->inTransaction()) {
                $this->pdo->exec('CLOSE ' . $this->streamName);
            }
            // 流拥有的只读事务不传播到下一次操作；中断也走相同收尾。
            if ($this->streamTransaction && $this->pdo !== null && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($this->mysqlBuffered !== null && $this->pdo !== null) {
                $this->pdo->setAttribute(constant('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY'), $this->mysqlBuffered);
            }
        } catch (Throwable $error) {
            $this->reusable = false;
            $this->streamStatement = null;
            $this->pdo = null;
            throw new DatabaseException('数据库结果流关闭失败，会话已隔离', 0, $error);
        } finally {
            $this->streamId = 0;
            $this->streamName = '';
            $this->streamBuffer = [];
            $this->streamOffset = 0;
            $this->streamTransaction = false;
            $this->mysqlBuffered = null;
        }
    }

    private function bind(PDOStatement $statement, array $parameters): void
    {
        foreach ($parameters as $key => $value) {
            if ($value !== null && !is_scalar($value)) {
                throw new DatabaseException('数据库参数只接受标量或 NULL');
            }
            $type = PDO::PARAM_STR;
            if ($value === null) {
                $type = PDO::PARAM_NULL;
            } elseif (is_bool($value)) {
                $type = PDO::PARAM_BOOL;
            } elseif (is_int($value)) {
                $type = PDO::PARAM_INT;
            }
            $parameter = is_int($key) ? $key + 1 : $key;
            $statement->bindValue($parameter, $value, $type);
        }
    }

    /** 开始最外层事务；immediate 只允许 SQLite，嵌套由上层保存点负责。 */
    public function begin(string $mode = 'default'): void
    {
        $this->assertIdle();
        $pdo = $this->connection();
        if ($pdo->inTransaction()) {
            throw new DatabaseException('当前入口不允许隐式嵌套事务');
        }
        if (!in_array($mode, ['default', 'immediate'], true) || ($mode === 'immediate' && $this->driverName() !== 'sqlite')) {
            throw new DatabaseException('事务模式无效，immediate 仅用于 SQLite');
        }
        try {
            if ($mode === 'immediate') {
                $started = $pdo->exec('BEGIN IMMEDIATE') !== false;
            } else {
                $started = $pdo->beginTransaction();
            }
            if (!$started) {
                $this->reusable = false;
                throw new DatabaseException('数据库事务未能开始');
            }
        } catch (PDOException $error) {
            $this->reusable = false;
            throw new DatabaseException('数据库事务未能开始', 0, $error);
        }
    }

    /** 建立 Connection 生成的受信保存点名；不得传入外部任意文本。 */
    public function savepoint(string $name): void
    {
        $this->execute('SAVEPOINT ' . $name, []);
    }

    /** 释放上层生成的受信保存点，成功不代表外层事务已提交。 */
    public function releaseSavepoint(string $name): void
    {
        $this->execute('RELEASE SAVEPOINT ' . $name, []);
    }

    /** 回滚并释放指定保存点，不撤销更外层的事务。 */
    public function rollbackTo(string $name): void
    {
        $this->execute('ROLLBACK TO SAVEPOINT ' . $name, []);
        $this->releaseSavepoint($name);
    }

    /** 确认最外层提交；失败使会话退役并保留未知结果供上层处理。 */
    public function commit(): void
    {
        $this->assertIdle();
        try {
            if ($this->pdo === null || !$this->pdo->commit()) {
                $this->reusable = false;
                throw new DatabaseException('数据库提交未确认，不允许自动重试');
            }
        } catch (PDOException $error) {
            $this->reusable = false;
            throw new DatabaseException('数据库提交结果未知，不允许自动重试', 0, $error);
        }
    }

    /** 关闭流并回滚活动事务；没有活动事务时返回 false。 */
    public function rollback(): bool
    {
        $this->closeStream($this->streamId);
        if ($this->pdo !== null && $this->pdo->inTransaction()) {
            return $this->pdo->rollBack();
        }
        return false;
    }

    /** 禁止会话重新入池，当前持有者仍负责事务和资源收尾。 */
    public function retire(): void
    {
        $this->reusable = false;
    }

    /** 回滚残余状态并由驱动证明会话干净；不能证明时释放 PDO 并返回 false。 */
    public function reset(): bool
    {
        $clean = false;
        try {
            $this->rollback();
            if ($this->reusable && $this->pdo !== null) {
                $clean = $this->driver->reset($this->pdo);
            }
            return $clean;
        } finally {
            if (!$clean) {
                $this->reusable = false;
                $this->pdo = null;
            }
        }
    }

    /** 池丢弃会话时关闭游标与 PDO；返回才表示底层句柄已释放。 */
    public function close(): void
    {
        try {
            $this->closeStream($this->streamId);
        } finally {
            $this->pdo = null;
        }
    }

    /** 保证物理会话已建立，供借出后及取消检查之前的初始化使用。 */
    public function initialize(): void
    {
        $this->connection();
    }

    private function connection(): PDO
    {
        $this->pdo ??= $this->driver->connect();
        return $this->pdo;
    }
}
