<?php

declare(strict_types=1);

namespace TypeApp\Example;

use RuntimeException;
use Type\Core\Command;
use Type\Core\Configuration;
use Type\Core\Listener;
use Type\Runtime\ManagedResource;

/** 展示配置值经容器装配进入命令依赖，不直接读取全局环境。 */
final class Greeting
{
    private string $name;

    /** 保存已解析的姓名配置。 */
    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /** 返回固定格式的问候，供命令输出与构建入口验收。 */
    public function message(): string
    {
        return '你好，' . $this->name . '！';
    }
}

/** 最小命令示例，输出注入依赖并支持一个显式非零退出码。 */
final class GreetCommand implements Command
{
    private Greeting $greeting;

    /** 注入已装配的问候服务，不在运行时解析类名。 */
    public function __construct(Greeting $greeting)
    {
        $this->greeting = $greeting;
    }

    /**
     * 输出问候；status-23 参数用于验证退出码原样传递。
     *
     * @param list<string> $arguments 当前命令收到的显式参数。
     */
    public function run(Configuration $configuration, array $arguments): int
    {
        echo $this->greeting->message() . PHP_EOL;

        return ($arguments[0] ?? '') === 'status-23' ? 23 : 0;
    }
}

/** 以可控启动/停止失败验证组件生命周期与逆序清理。 */
final class ExampleResource implements ManagedResource
{
    private string $name;
    private bool $failStart;
    private bool $failStop;

    /** 记录资源名与两阶段故障开关，不提前执行生命周期。 */
    public function __construct(string $name, bool $failStart, bool $failStop)
    {
        $this->name = $name;
        $this->failStart = $failStart;
        $this->failStop = $failStop;
    }

    /** 输出开启标记并按开关注入启动失败。 */
    public function start(): void
    {
        echo '开启：' . $this->name . PHP_EOL;
        if ($this->failStart) {
            throw new RuntimeException('启动失败：' . $this->name);
        }
    }

    /** 输出关闭标记并按开关注入停止失败。 */
    public function stop(): void
    {
        echo '关闭：' . $this->name . PHP_EOL;
        if ($this->failStop) {
            throw new RuntimeException('停止失败：' . $this->name);
        }
    }
}

/** 观察显式生命周期事件顺序，支持故意失败的监听器。 */
final class ExampleListener implements Listener
{
    private string $name;
    private bool $fail;

    /** 固定监听器身份及故障开关。 */
    public function __construct(string $name, bool $fail)
    {
        $this->name = $name;
        $this->fail = $fail;
    }

    /** 输出事件身份并按开关抛错，供入口验证监听失败策略。 */
    public function handle(string $event, Configuration $configuration): void
    {
        echo '事件：' . $this->name . ':' . $event . PHP_EOL;
        if ($this->fail) {
            throw new RuntimeException('监听失败：' . $this->name);
        }
    }
}

/** 故意失败的业务命令，检验宿主仍执行资源清理。 */
final class FailingCommand implements Command
{
    /**
     * 抛出预期业务异常，不返回伪造成功状态。
     *
     * @throws RuntimeException 每次调用均失败。
     * @param list<string> $arguments 当前命令收到的显式参数。
     */
    public function run(Configuration $configuration, array $arguments): int
    {
        throw new RuntimeException('业务命令失败');
    }
}

/** 以单调实例序号区分单例与执行级依赖的生命周期。 */
final class SequenceValue
{
    private static int $next = 0;
    private int $value;

    /** 每次构造分配新序号，供跨命令实例隔离比较。 */
    public function __construct()
    {
        self::$next++;
        $this->value = self::$next;
    }

    /** 返回当前实例固定序号，读取本身不增加计数。 */
    public function value(): int
    {
        return $this->value;
    }
}

/** 同时观察容器单例和执行级实例是否按预期共享。 */
final class IdentityCommand implements Command
{
    private SequenceValue $singleton;
    private SequenceValue $execution;

    /** 显式注入两种生命周期的值，不自行从全局容器查找。 */
    public function __construct(SequenceValue $singleton, SequenceValue $execution)
    {
        $this->singleton = $singleton;
        $this->execution = $execution;
    }

    /**
     * 输出单例与执行级序号，外部验收比较连续两次命令。
     *
     * @param list<string> $arguments 当前命令收到的显式参数。
     */
    public function run(Configuration $configuration, array $arguments): int
    {
        echo $this->singleton->value() . ':' . $this->execution->value() . PHP_EOL;

        return 0;
    }
}

/** 在同一应用对象中连续运行两次命令，检验每次执行上下文独立。 */
final class BatchCommand implements Command
{
    /**
     * 连续调用两次 identity，保留共同应用容器但建立独立执行。
     *
     * @param list<string> $arguments 当前命令收到的显式参数。
     */
    public function run(Configuration $configuration, array $arguments): int
    {
        $application = new \Type\Generated\CommandApplication($configuration);
        $application->run('identity', []);
        $application->run('identity', []);

        return 0;
    }
}

/** 验证启动配置为快照，之后修改进程环境不会重写既有值。 */
final class SnapshotCommand implements Command
{
    /**
     * 保存配置值后修改演练环境，再输出同一配置对象的读取结果。
     *
     * @param list<string> $arguments 当前命令收到的显式参数。
     */
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
    /**
     * 创建子作用域并取消，验证命令 Scope 保留与资源清理边界。
     *
     * @param list<string> $arguments 当前命令收到的显式参数。
     */
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
    /**
     * 故意拒绝构造，证明未启用命令不会被提前实例化。
     *
     * @throws RuntimeException 若生成入口错误实例化此类则立即失败。
     */
    public function __construct()
    {
        throw new RuntimeException('未启用的命令被构造');
    }

    /**
     * 为接口提供失败状态；正常演练不应构造或执行此命令。
     *
     * @param list<string> $arguments 当前命令收到的显式参数。
     */
    public function run(Configuration $configuration, array $arguments): int
    {
        return 1;
    }
}
