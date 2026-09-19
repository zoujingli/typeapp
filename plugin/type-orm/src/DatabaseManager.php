<?php

declare(strict_types=1);

namespace Type\Orm;

use Type\Runtime\ExecutionScope;
use Type\Runtime\DeploymentBudget;

final class DatabaseManager
{
    private array $drivers = [];
    private array $databases = [];
    private array $retired = [];
    private int $capacity;
    private int $idleLimit;
    private bool $closed = false;
    private ?DeploymentBudget $budget;
    private int $waiterLimit;
    private float $waitSeconds;

    /** 各命名连接与旧凭据代次共用预算；协程等待上限单位为秒。 */
    public function __construct(array $connections, int $capacity = 4, int $idleLimit = 2, ?DeploymentBudget $budget = null, int $waiterLimit = 64, float $waitSeconds = 1.0)
    {
        if ($connections === [] || count($connections) > 64 || $capacity < 1 || $idleLimit < 0 || $idleLimit > $capacity
            || $waiterLimit < 0 || $waiterLimit > 65536 || !is_finite($waitSeconds) || $waitSeconds < 0 || $waitSeconds > 60) {
            throw new DatabaseException('命名数据库或容量无效');
        }
        foreach ($connections as $name => $driver) {
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $name) || !$driver instanceof Driver) {
                throw new DatabaseException('数据库命名声明无效');
            }
            $this->drivers[$name] = $driver;
        }
        $this->capacity = $capacity;
        $this->idleLimit = $idleLimit;
        $this->budget = $budget;
        $this->waiterLimit = $waiterLimit;
        $this->waitSeconds = $waitSeconds;
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

    public function identity(string $name = 'default'): array
    {
        if (!isset($this->drivers[$name])) {
            throw new DatabaseException('数据库名称不存在');
        }
        return $this->drivers[$name]->identity();
    }

    public function statistics(): array
    {
        $this->collect();
        $statistics = [];
        foreach ($this->databases as $name => $database) {
            $statistics[$name] = $database->statistics();
        }
        return ['active' => $statistics, 'retired-generations' => array_map('count', $this->retired)];
    }

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
