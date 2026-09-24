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

    /**
     * 绑定作用域及已脱敏的关联副本；由作用域负责启动和清除。
     *
     * @param array<array-key, mixed> $values 已通过 Formatter 处理的关联。
     */
    public function __construct(ExecutionScope $scope, array $values)
    {
        $this->scope = $scope;
        $this->values = $values;
    }
    /**
     * 仅在所属作用域有效时激活；已经关闭的绑定不可重新使用。
     *
     * @throws RuntimeException 绑定已退出。
     */
    public function start(): void
    {
        if ($this->closed || $this->scope === null) {
            throw new RuntimeException('日志上下文已经退出');
        }
        $this->scope->assertActive();
        $this->active = true;
    }
    /** 由所属执行者清除全部关联并失效绑定；旧 Logger 不能继续输出。 */
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
    /**
     * 验证当前执行者与作用域后读取关联，不提供跨请求共享入口。
     *
     * @return array<array-key, mixed> 当前作用域的安全关联。
     * @throws RuntimeException 绑定未激活或已退出。
     */
    public function values(): array
    {
        if (!$this->active || $this->scope === null) {
            throw new RuntimeException('日志上下文未处于活动状态');
        }
        $this->scope->assertActive();
        return $this->values;
    }
}
