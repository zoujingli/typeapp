<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Core\Application;
use Type\Core\Command;
use Type\Core\Configuration;
use Type\Core\Events;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\ExecutionScope;
use Type\Runtime\ManagedResource;

/** 通过公开执行结果观察关闭失败的隔离，不连接外部资源。 */
final class ExecutionServiceProbe implements Command, ManagedResource
{
    public int $starts = 0;
    public int $stops = 0;
    public bool $failStop = false;
    public ExecutionScope $scope;

    public function __construct()
    {
        $this->scope = ExecutionScope::current();
    }

    public function run(Configuration $configuration, array $arguments): int
    {
        expect(ExecutionScope::current() === $this->scope, '命令在构造作用域之外执行');
        return 7;
    }

    public function start(): void
    {
        $this->starts++;
    }

    public function stop(): void
    {
        $this->stops++;
        if ($this->failStop) {
            throw new RuntimeException('probe_stop_failed');
        }
    }
}

CoroutineRuntime::run(static function (): void {
    $outer = new ExecutionScope();
    try {
        $outer->run(static function (ExecutionScope $parent): void {
            $first = $parent->service('probe', static fn (): ExecutionServiceProbe => new ExecutionServiceProbe());
            expect($first === $parent->service('probe', static fn (): ExecutionServiceProbe => new ExecutionServiceProbe()), '同作用域没有复用服务');
            $parent->open($first);
            $parent->open($first);
            expect($first->starts === 1, '同资源被重复启动');
            $children = [];
            for ($index = 0; $index < 2; $index++) {
                $children[] = $parent->spawn(static function (ExecutionScope $child): int {
                    expect($child->binding('tenant_id') === null && $child->binding('identity_id') === null, '子任务隐式继承父身份');
                    $value = $child->service('probe', static fn (): ExecutionServiceProbe => new ExecutionServiceProbe());
                    $child->open($value);
                    \Swoole\Coroutine::sleep(0.001);
                    expect($value->scope === $child && $value->starts === 1, '并发服务串用父实例');
                    return spl_object_id($child);
                });
            }
            expect($children[0]->await() !== $children[1]->await(), '并发子作用域没有隔离');
            expect($first->stops === 0, '子作用域释放了父资源');
            $broken = $parent->spawn(static function (ExecutionScope $child): void {
                $child->cancellation()->subscribe(static function (): void {
                    throw new RuntimeException('child_cleanup_failed');
                });
                throw new RuntimeException('child_operation_failed');
            });
            try {
                $broken->await();
                throw new LogicException('子任务双重失败未传播');
            } catch (RuntimeException $error) {
                expect(str_contains($error->getMessage(), 'child_operation_failed') && str_contains($error->getMessage(), 'child_cleanup_failed'), '子任务丢失业务或清理错误');
            }
            expect($broken->finished(), '已完成清理的失败子任务没有退出');

            $runner = new Application();
            $configuration = new Configuration([]);
            $created = [];
            $factory = static function (ExecutionScope $scope) use (&$created): Command {
                $probe = $scope->service('command', static fn (): ExecutionServiceProbe => new ExecutionServiceProbe());
                $created[] = $probe;
                return $probe;
            };
            $resources = static fn (ExecutionScope $scope): array => [$scope->service('command', static fn (): ExecutionServiceProbe => new ExecutionServiceProbe())];
            $events = static fn (ExecutionScope $scope): Events => new Events();
            expect($runner->runFactories($factory, $configuration, [], $resources, $events) === 7, '命令退出码改变');
            expect($runner->runFactories($factory, $configuration, [], $resources, $events) === 7, '连续命令不能执行');
            expect($created[0] !== $created[1] && $created[0]->stops === 1 && $created[1]->stops === 1, '命令服务或资源生命周期串用');
            expect(ExecutionScope::current() === $parent, '命令执行后没有恢复外层');

            $failed = new ExecutionScope();
            $reference = $failed->run(static function (ExecutionScope $scope): WeakReference {
                $probe = $scope->service('failed', static fn (): ExecutionServiceProbe => new ExecutionServiceProbe());
                $probe->failStop = true;
                $scope->open($probe);
                return WeakReference::create($probe);
            });
            try {
                $failed->close();
                throw new RuntimeException('停止失败未传播');
            } catch (RuntimeException $error) {
                expect(str_contains($error->getMessage(), 'probe_stop_failed'), '丢失原始停止错误');
            }
            expect($failed->state() === 'closing' && $reference->get() !== null, '未完成资源被提前释放');
            $reference->get()->failStop = false;
            $failed->close();
            expect($failed->state() === 'closed' && $reference->get() === null, '真实关闭后仍持有服务');
            try {
                $failed->service('late', static fn (): ExecutionServiceProbe => new ExecutionServiceProbe());
                throw new LogicException('关闭后的作用域接受了服务');
            } catch (RuntimeException $error) {
                expect(str_contains($error->getMessage(), '已经关闭'), '关闭后的服务拒绝原因错误');
            }
        }, ['tenant_id' => 'parent-tenant', 'identity_id' => 'parent-identity']);
    } finally {
        $outer->close();
    }
});
echo "执行服务复用、并发隔离、构造绑定、命令恢复及真实关闭释放通过。\n";
