<?php

declare(strict_types=1);

namespace Type\Orm;

use Type\Runtime\ExecutionScope;
use Type\Runtime\DeploymentBudget;

/** 命名数据源、主从端点与凭据代次的所有者；复用同一运行时资源池协议。 */
final class DatabaseManager
{
    private array $drivers = [];
    private array $readers = [];
    private array $databases = [];
    private array $retired = [];
    private int $capacity;
    private int $idleLimit;
    private bool $closed = false;
    private ?DeploymentBudget $budget;
    private int $waiterLimit;
    private float $waitSeconds;

    /**
     * 各命名连接与旧凭据代次共用预算；协程等待上限单位为秒。
     *
     * @param array<string, Driver|array{master: Driver, reader?: Driver|null}> $connections 命名端点或逻辑主从数据源。
     */
    public function __construct(array $connections, int $capacity = 4, int $idleLimit = 2, ?DeploymentBudget $budget = null, int $waiterLimit = 64, float $waitSeconds = 1.0)
    {
        if ($connections === [] || count($connections) > 64 || $capacity < 1 || $idleLimit < 0 || $idleLimit > $capacity
            || $waiterLimit < 0 || $waiterLimit > 65536 || !is_finite($waitSeconds) || $waitSeconds < 0 || $waitSeconds > 60) {
            throw new DatabaseException('命名数据库或容量无效');
        }
        foreach ($connections as $name => $configuration) {
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $name)) {
                throw new DatabaseException('数据库命名声明无效');
            }
            $driver = $configuration;
            if (is_array($configuration)) {
                if (array_diff(array_keys($configuration), ['master', 'reader']) !== [] || !isset($configuration['master'])) {
                    throw new DatabaseException('逻辑数据源只接受 master 和可选 reader');
                }
                $driver = $configuration['master'];
                $reader = $configuration['reader'] ?? null;
                if (!$driver instanceof Driver || $driver->identity()['role'] !== 'writer'
                    || ($reader !== null && (!$reader instanceof Driver || $reader->identity()['role'] !== 'reader'
                        || $driver->name() === 'sqlite' || $reader->name() !== $driver->name()
                        || $reader->identity()['database'] !== $driver->identity()['database']))) {
                    throw new DatabaseException('主从必须是同驱动同逻辑数据库的 writer/reader，SQLite 只使用单库');
                }
                if ($reader !== null) {
                    $this->readers[$name] = $name . ':reader';
                    $this->drivers[$name . ':reader'] = $reader;
                }
            }
            if (!$driver instanceof Driver) {
                throw new DatabaseException('数据库端点必须是显式驱动配置');
            }
            $this->drivers[$name] = $driver;
        }
        $this->capacity = $capacity;
        $this->idleLimit = $idleLimit;
        $this->budget = $budget;
        $this->waiterLimit = $waiterLimit;
        $this->waitSeconds = $waitSeconds;
    }

    /** @internal 每个执行作用域按逻辑数据源持有一个读写会话，实际操作才借用端点。 */
    public function session(ExecutionScope $scope, string $database = 'default'): ReadWriteSession
    {
        if ($this->closed || !isset($this->drivers[$database]) || str_contains($database, ':')
            || $this->drivers[$database]->identity()['role'] !== 'writer') {
            throw new ModelException('database_unavailable', '逻辑数据源不存在、不是主库或已关闭');
        }
        return new ReadWriteSession($this, $scope, $database, $this->readers[$database] ?? null);
    }

    /** null 沿用池等待配置；0 即时借用，不继承其他执行者的连接。 */
    public function connect(ExecutionScope $scope, string $name = 'default', ?float $waitSeconds = null): Connection
    {
        if ($this->closed || !isset($this->drivers[$name])) {
            throw new DatabaseException('数据库名称不存在或管理器已关闭');
        }
        $this->databases[$name] ??= new Database($this->drivers[$name], $this->capacity, $this->idleLimit, $this->budget, $this->waiterLimit, $this->waitSeconds);
        return $this->databases[$name]->connect($scope, $waitSeconds);
    }

    /** 同一命名连接最多保留两个活动旧代，轮换不改变已借出租约的身份。 */
    public function rotate(string $name, Driver $driver): void
    {
        if ($this->closed || !isset($this->drivers[$name]) || $driver->identity()['credential-generation'] <= $this->drivers[$name]->identity()['credential-generation']) {
            throw new DatabaseException('连接轮换必须明确增加凭据代次');
        }
        $previous = $this->drivers[$name]->identity();
        $replacement = $driver->identity();
        if ($driver->name() !== $this->drivers[$name]->name() || $replacement['database'] !== $previous['database']
            || $replacement['role'] !== $previous['role']) {
            throw new DatabaseException('凭据轮换不能改变逻辑数据库、驱动或主从职责');
        }
        $this->collect();
        if (count($this->retired[$name] ?? []) >= 2) {
            throw new DatabaseException('旧连接代次尚未排空，暂不能继续轮换');
        }
        if (isset($this->databases[$name])) {
            $this->databases[$name]->retire();
            $this->retired[$name][] = $this->databases[$name];
            unset($this->databases[$name]);
        }
        $this->drivers[$name] = $driver;
    }

    /**
     * 读取当前命名端点配置身份，不建立连接。
     *
     * @return array<string, mixed> 包含驱动、端点、逻辑库、角色与凭据代次，不包含密码。
     * @throws DatabaseException 名称未声明。
     */
    public function identity(string $name = 'default'): array
    {
        if (!isset($this->drivers[$name])) {
            throw new DatabaseException('数据库名称不存在');
        }
        return $this->drivers[$name]->identity();
    }

    /**
     * 收集已排空的旧代并返回活动池计数，不把旧代计为可借用端点。
     *
     * @return array{active: array<string, array<string, int|float>>, 'retired-generations': array<string, int>}
     */
    public function statistics(): array
    {
        $this->collect();
        $statistics = [];
        foreach ($this->databases as $name => $database) {
            $statistics[$name] = $database->statistics();
        }
        return ['active' => $statistics, 'retired-generations' => array_map('count', $this->retired)];
    }

    /** 关闭活动及退役代次的池，之后拒绝新借用；由应用生命周期统一调用。 */
    public function close(): void
    {
        $this->closed = true;
        foreach ($this->databases as $database) {
            $database->close();
        }
        foreach ($this->retired as $databases) {
            foreach ($databases as $database) {
                $database->close();
            }
        }
    }

    private function collect(): void
    {
        foreach ($this->retired as $name => $databases) {
            $remaining = [];
            foreach ($databases as $database) {
                if ($database->statistics()['created'] === 0) {
                    $database->close();
                } else {
                    $remaining[] = $database;
                }
            }
            $this->retired[$name] = $remaining;
        }
    }
}
