<?php

declare(strict_types=1);

namespace TypeApp\Example;

use RuntimeException;
use Type\Core\Command;
use Type\Core\Configuration;
use Type\Core\Listener;
use Type\Runtime\ManagedResource;

final class Greeting
{
    private string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function message(): string
    {
        return '你好，' . $this->name . '！';
    }
}

final class GreetCommand implements Command
{
    private Greeting $greeting;

    public function __construct(Greeting $greeting)
    {
        $this->greeting = $greeting;
    }

    public function run(Configuration $configuration, array $arguments): int
    {
        echo $this->greeting->message() . PHP_EOL;

        return ($arguments[0] ?? '') === 'status-23' ? 23 : 0;
    }
}

final class ExampleResource implements ManagedResource
{
    private string $name;
    private bool $failStart;
    private bool $failStop;

    public function __construct(string $name, bool $failStart, bool $failStop)
    {
        $this->name = $name;
        $this->failStart = $failStart;
        $this->failStop = $failStop;
    }

    public function start(): void
    {
        echo '开启：' . $this->name . PHP_EOL;
        if ($this->failStart) {
            throw new RuntimeException('启动失败：' . $this->name);
        }
    }

    public function stop(): void
    {
        echo '关闭：' . $this->name . PHP_EOL;
        if ($this->failStop) {
            throw new RuntimeException('停止失败：' . $this->name);
        }
    }
}

final class ExampleListener implements Listener
{
    private string $name;
    private bool $fail;

    public function __construct(string $name, bool $fail)
    {
        $this->name = $name;
        $this->fail = $fail;
    }

    public function handle(string $event, Configuration $configuration): void
    {
        echo '事件：' . $this->name . ':' . $event . PHP_EOL;
        if ($this->fail) {
            throw new RuntimeException('监听失败：' . $this->name);
        }
    }
}

final class FailingCommand implements Command
{
    public function run(Configuration $configuration, array $arguments): int
    {
        throw new RuntimeException('业务命令失败');
    }
}

final class SequenceValue
{
    private static int $next = 0;
    private int $value;

    public function __construct()
    {
        self::$next++;
        $this->value = self::$next;
    }

    public function value(): int
    {
        return $this->value;
    }
}

final class IdentityCommand implements Command
{
    private SequenceValue $singleton;
    private SequenceValue $execution;

    public function __construct(SequenceValue $singleton, SequenceValue $execution)
    {
        $this->singleton = $singleton;
        $this->execution = $execution;
    }

    public function run(Configuration $configuration, array $arguments): int
    {
        echo $this->singleton->value() . ':' . $this->execution->value() . PHP_EOL;

        return 0;
    }
}

final class BatchCommand implements Command
{
    public function run(Configuration $configuration, array $arguments): int
    {
        $application = new \Type\Generated\CommandApplication($configuration);
        $application->run('identity', []);
        $application->run('identity', []);

        return 0;
    }
}

final class SnapshotCommand implements Command
{
    public function run(Configuration $configuration, array $arguments): int
    {
        $before = $configuration->text('name');
        putenv('TYPE_APP_NAME=后来的环境值');
        echo $before . ':' . $configuration->text('name') . PHP_EOL;

        return 0;
    }
}

/** 通过命令当前作用域验证取消、截止、关闭拒绝及子任务的独立绑定与预算归还。 */
final class ScopeCommand implements Command
{
    public function run(Configuration $configuration, array $arguments): int
    {
        $cancellation = new \Type\Runtime\Cancellation();
        $commandScope = \Type\Runtime\ExecutionScope::current();
        $scope = new \Type\Runtime\ExecutionScope(cancellation: $cancellation);
        $scope->open(new ExampleResource('scope', false, false));
        $cancellation->cancel();
        $cancelled = false;
        try {
            $scope->assertActive();
        } catch (\Type\Runtime\TaskException $cancelError) {
            $cancelled = $cancelError->errorCode() === 'cancelled';
        }
        $scope->close();
        $closedRejected = false;
        try {
            $scope->assertActive();
        } catch (RuntimeException $closedError) {
            $closedRejected = true;
        }
        $expired = new \Type\Runtime\ExecutionScope(new \Type\Runtime\Deadline(0.0));
        $deadlineRejected = false;
        try {
            $expired->assertActive();
        } catch (\Type\Runtime\TaskException $deadlineError) {
            $deadlineRejected = $deadlineError->errorCode() === 'deadline_exceeded';
        }
        $expired->close();
        $parent = new \Type\Runtime\ExecutionScope();
        $childResult = $parent->spawn(static fn (\Type\Runtime\ExecutionScope $child): bool => \Type\Runtime\ExecutionScope::current() === $child)->await();
        $parent->close();
        if (!$cancelled || !$closedRejected || !$deadlineRejected || $childResult !== true || \Type\Runtime\ExecutionScope::current() !== $commandScope
            || $scope->state() !== 'closed' || $expired->state() !== 'closed' || $parent->state() !== 'closed' || $parent->activeTasks() !== 0) {
            throw new RuntimeException('作用域状态、子任务绑定或预算未恢复');
        }
        echo "取消、截止、关闭拒绝与子任务预算恢复通过。\n";
        return 0;
    }
}

/** 用于验证源码被解析时不会执行应用构造函数。 */
final class UnusedCommand implements Command
{
    public function __construct()
    {
        throw new RuntimeException('未启用的命令被构造');
    }

    public function run(Configuration $configuration, array $arguments): int
    {
        return 1;
    }
}
