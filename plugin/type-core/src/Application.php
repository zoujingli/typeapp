<?php

declare(strict_types=1);

namespace Type\Core;

use Closure;
use RuntimeException;
use Throwable;
use Type\Runtime\ExecutionScope;

/** 打开本次命令需要的资源，保留原始失败，再执行完整的逆序清理。 */
final class Application
{
    /** 清理失败的作用域继续隔离持有服务；已闭合项在下一次调用时释放。 */
    private array $pendingScopes = [];

    /** 命令及其资源须在同一 Swoole 协程内装配；运行期间绑定独立作用域。 */
    public function run(Command $command, Configuration $configuration, array $arguments, array $resources, Events $events): int
    {
        return $this->runFactories(
            static fn (ExecutionScope $scope): Command => $command,
            $configuration,
            $arguments,
            static fn (ExecutionScope $scope): array => $resources,
            static fn (ExecutionScope $scope): Events => $events
        );
    }

    /**
     * 在作用域绑定后构造命令、监听器和资源；工厂只在本次执行中调用一次。
     * 清理未完成时保留作用域并拒绝本实例的新命令；真实关闭后下一次调用恢复。
     *
     * @param Closure(ExecutionScope): Command $commandFactory 命令工厂。
     * @param Closure(ExecutionScope): array $resourcesFactory 当前命令的资源工厂。
     * @param Closure(ExecutionScope): Events $eventsFactory 当前命令的监听器工厂。
     */
    public function runFactories(Closure $commandFactory, Configuration $configuration, array $arguments, Closure $resourcesFactory, Closure $eventsFactory): int
    {
        $this->pendingScopes = array_values(array_filter($this->pendingScopes, static fn (ExecutionScope $pending): bool => $pending->state() !== 'closed'));
        if ($this->pendingScopes !== []) {
            throw new RuntimeException('上次命令作用域尚未真实收尾，当前装配实例拒绝新工作');
        }
        $scope = new ExecutionScope();
        $failures = [];
        $status = 0;
        try {
            $status = $scope->run(static function (ExecutionScope $current) use ($commandFactory, $configuration, $arguments, $resourcesFactory, $eventsFactory): int {
                $command = $commandFactory($current);
                $resources = $resourcesFactory($current);
                $events = $eventsFactory($current);
                if (!$command instanceof Command || !$events instanceof Events || !is_array($resources)) {
                    throw new RuntimeException('命令装配工厂返回值无效');
                }
                foreach ($resources as $resource) {
                    $current->open($resource);
                }
                $events->dispatch('ready', $configuration);
                $result = $command->run($configuration, $arguments);
                $events->dispatch('completed', $configuration);
                return $result;
            });
        } catch (Throwable $error) {
            $failures[] = $error;
        }

        try {
            $scope->close();
        } catch (Throwable $error) {
            $failures[] = $error;
        }
        if ($scope->state() !== 'closed') {
            $this->pendingScopes[] = $scope;
        }
        if (count($failures) === 1) {
            throw $failures[0];
        }
        if (count($failures) > 1) {
            throw new RuntimeException($failures[0]->getMessage() . '；' . $failures[1]->getMessage(), 0, $failures[0]);
        }

        return $status;
    }
}
