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

    public function __construct(Driver $driver)
    {
        $this->driver = $driver;
        $this->pdo = $driver->connect();
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

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

    public function driverName(): string
    {
        return $this->driver->name();
    }

    public function serverVersion(): string
    {
        $this->assertIdle();
        return (string) $this->connection()->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

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

    public function lastInsertId(): string
    {
        $this->assertIdle();
        return (string) $this->connection()->lastInsertId();
    }

    public function assertIdle(): void
    {
        if ($this->streamId !== 0) {
            throw new DatabaseException('结果流尚未关闭，本数据库租约只允许继续读取或关闭该流');
        }
    }

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

    public function streamActive(int $id): bool
    {
        return $id !== 0 && $this->streamId === $id;
    }

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

    public function savepoint(string $name): void
    {
        $this->execute('SAVEPOINT ' . $name, []);
    }

    public function releaseSavepoint(string $name): void
    {
        $this->execute('RELEASE SAVEPOINT ' . $name, []);
    }

    public function rollbackTo(string $name): void
    {
        $this->execute('ROLLBACK TO SAVEPOINT ' . $name, []);
        $this->releaseSavepoint($name);
    }

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

    public function rollback(): bool
    {
        $this->closeStream($this->streamId);
        if ($this->pdo !== null && $this->pdo->inTransaction()) {
            return $this->pdo->rollBack();
        }
        return false;
    }

    public function retire(): void
    {
        $this->reusable = false;
    }

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
