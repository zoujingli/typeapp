<?php

declare(strict_types=1);

namespace Type\Redis;

use InvalidArgumentException;
use Type\Runtime\ExecutionScope;
use Type\Runtime\ResourcePool;

/** 按端点与用途复用运行时资源池；各次业务借用由 ExecutionScope 独立拥有。 */
final class RedisManager
{
    private array $configurations = [];
    private array $capacities;
    private array $pools = [];
    private ?int $processId = null;
    private bool $closed = false;

    /**
     * 声明端点与各用途容量，首次借用才创建池。
     *
     * @param array<string, RedisConfiguration> $configurations 1 至 64 个命名端点。
     * @param array<string, int> $capacities 以 Purpose 常量为键，各容量为 1 至 1024。
     */
    public function __construct(array $configurations, array $capacities = [])
    {
        if ($configurations === [] || count($configurations) > 64) {
            throw new InvalidArgumentException('Redis 必须声明 1 至 64 个命名连接');
        }
        foreach ($configurations as $name => $configuration) {
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $name) || !$configuration instanceof RedisConfiguration) {
                throw new InvalidArgumentException('Redis 命名连接无效');
            }
            $this->configurations[$name] = $configuration;
        }
        $defaults = [Purpose::COMMAND => 4, Purpose::BLOCKING => 1, Purpose::PIPELINE => 1, Purpose::TRANSACTION => 1, Purpose::SCRIPT => 1];
        foreach ($capacities as $purpose => $capacity) {
            if (!isset($defaults[$purpose]) || !is_int($capacity) || $capacity < 1 || $capacity > 1024) {
                throw new InvalidArgumentException('Redis 用途容量无效');
            }
            $defaults[$purpose] = $capacity;
        }
        $this->capacities = $defaults;
    }

    /** 协程借用默认有界等待，传入 0 可显式即时拒绝；不改变各用途的独立容量。 */
    public function connection(ExecutionScope $scope, string $name = 'default', string $purpose = Purpose::COMMAND, ?float $waitSeconds = null): RedisConnection
    {
        $scope->assertActive();
        if ($this->closed || !isset($this->configurations[$name]) || !isset($this->capacities[$purpose])) {
            throw new RedisException('invalid_connection', 'NOT_STARTED', 'Redis 连接名称、用途不存在或管理器已关闭');
        }
        $this->assertProcess();
        $key = $name . ':' . $purpose;
        if (!isset($this->pools[$key])) {
            $configuration = $this->configurations[$name];
            $capacity = $this->capacities[$purpose];
            $this->pools[$key] = new ResourcePool(
                static fn (): RedisSession => new RedisSession($configuration),
                $capacity,
                $purpose === Purpose::COMMAND ? min(2, $capacity) : 0
            );
        }
        return new RedisConnection($this->pools[$key]->borrow($scope, $waitSeconds), $purpose);
    }

    /**
     * 读取当前进程各命名连接、用途池的计数；未借用用途不创建池。
     *
     * @return array<string, array<string, int|float>>
     */
    public function statistics(): array
    {
        if ($this->processId !== null) {
            $this->assertProcess();
        }
        $statistics = [];
        foreach ($this->pools as $name => $pool) {
            $statistics[$name] = $pool->statistics();
        }
        return $statistics;
    }

    /** 拒绝后续借用并关闭本管理器创建的池；应由工作进程所有者调用。 */
    public function close(): void
    {
        if ($this->processId !== null) {
            $this->assertProcess();
        }
        $this->closed = true;
        foreach ($this->pools as $pool) {
            $pool->close();
        }
    }

    private function assertProcess(): void
    {
        if ($this->processId === null) {
            $this->processId = (int) getmypid();
        }
        if ($this->processId !== (int) getmypid()) {
            throw new RedisException('wrong_process', 'NOT_STARTED', 'Redis 连接池只能在创建它的工作进程内使用');
        }
    }
}
