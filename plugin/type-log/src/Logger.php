<?php

declare(strict_types=1);

namespace Type\Log;

use Psr\Log\AbstractLogger;
use Stringable;

/** 绑定单个执行作用域的 PSR-3 日志入口；作用域退出后不可复用。 */
final class Logger extends AbstractLogger
{
    private LogManager $manager;
    private LogContext $context;
    private string $name;

    /**
     * 组合已注册通道与日志绑定；业务通过 LogManager::logger() 创建。
     *
     * @internal
     */
    public function __construct(LogManager $manager, LogContext $context, string $name)
    {
        $manager->assertChannel($name);
        $this->manager = $manager;
        $this->context = $context;
        $this->name = $name;
    }

    /**
     * 返回共享当前作用域绑定的新通道视图，不复制或延长作用域。
     *
     * @throws \InvalidArgumentException 通道未注册。
     */
    public function channel(string $name): Logger
    {
        return new Logger($this->manager, $this->context, $name);
    }

    /**
     * 校验作用域后提交日志；积压和输出失败通过管理器计数观测。
     *
     * @param mixed $level PSR-3 标准小写级别。
     * @param array<array-key, mixed> $context 本条日志的业务字段，输出前会复制并脱敏。
     * @throws \Psr\Log\InvalidArgumentException 日志级别非法。
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->manager->write($this->name, $level, $message, $context, $this->context->values());
    }
}
