<?php

declare(strict_types=1);

namespace Type\Core;

use RuntimeException;
use Throwable;
use Type\Runtime\ExecutionScope;

/** 打开本次命令需要的资源，保留原始失败，再执行完整的逆序清理。 */
final class Application
{
    /** 命令及其资源须在同一 Swoole 协程内装配；运行期间绑定独立作用域。 */
    public function run(Command $command, Configuration $configuration, array $arguments, array $resources, Events $events): int
    {
        $scope = new ExecutionScope();
        $failures = [];
        $status = 0;
        try {
            $status = $scope->run(static function (ExecutionScope $current) use ($command, $configuration, $arguments, $resources, $events): int {
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
        if (count($failures) === 1) {
            throw $failures[0];
        }
        if (count($failures) > 1) {
            throw new RuntimeException($failures[0]->getMessage() . '；' . $failures[1]->getMessage(), 0, $failures[0]);
        }

        return $status;
    }
}
