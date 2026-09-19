<?php

declare(strict_types=1);

namespace Type\Log;

use RuntimeException;
use Type\Runtime\ExecutionScope;
use Type\Runtime\ManagedResource;

/** 作用域退出主动清除关联数据；持有旧 Logger 也不能继续输出。 */
final class LogContext implements ManagedResource
{
    private ?ExecutionScope $scope;
    private array $values;
    private bool $active = false;
    private bool $closed = false;

    public function __construct(ExecutionScope $scope, array $values)
    {
        $this->scope = $scope;
        $this->values = $values;
    }
    public function start(): void
    {
        if ($this->closed || $this->scope === null) {
            throw new RuntimeException('日志上下文已经退出');
        }
        $this->scope->assertActive();
        $this->active = true;
    }
    public function stop(): void
    {
        if ($this->scope !== null) {
            $this->scope->assertOwner();
        }
        $this->values = [];
        $this->scope = null;
        $this->active = false;
        $this->closed = true;
    }
    public function values(): array
    {
        if (!$this->active || $this->scope === null) {
            throw new RuntimeException('日志上下文未处于活动状态');
        }
        $this->scope->assertActive();
        return $this->values;
    }
}
