<?php

declare(strict_types=1);

namespace Type\Core;

use Closure;
use RuntimeException;
use Type\Runtime\ExecutionScope;

/** 当前执行作用域内的同步业务事件入口；监听表由构建器生成，不提供运行时注册。 */
final class BusinessEvents
{
    /**
     * @internal 只由生成装配构造；事件类型与直接监听调用已经过静态校验。
     * @param Closure(object): void $dispatch 固定监听表的直接调用入口。
     */
    public function __construct(private ExecutionScope $scope, private Closure $dispatch)
    {
    }

    /**
     * 按声明顺序同步调用监听者；无监听的已声明事件无操作，异常中止后续调用并直接传播。
     *
     * 不建立事务、不提交、不重试或持久投递。提交后语义由调用方显式使用 Db::afterCommit。
     * @throws RuntimeException 入口已离开所属作用域；关闭、取消及截止仍由作用域检查拒绝。
     * @throws \Throwable 事件未声明、监听构造或监听方法失败。
     */
    public function dispatch(object $event): void
    {
        $this->scope->assertActive();
        if (ExecutionScope::current() !== $this->scope) {
            throw new RuntimeException('业务事件入口只能在所属执行作用域使用');
        }
        ($this->dispatch)($event);
    }
}
