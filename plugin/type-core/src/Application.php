<?php

declare(strict_types=1);

namespace Type\Core;

use RuntimeException;
use Throwable;
use Type\Runtime\ExecutionScope;

/** 打开本次命令需要的资源，保留原始失败，再执行完整的逆序清理。 */
final class Application
{
    public function run(Command $command, Configuration $configuration, array $arguments, array $resources, Events $events): int
    {
        $scope = new ExecutionScope();
        $failures = [];
        $status = 0;
        try {
            foreach ($resources as $resource) {
                $scope->open($resource);
            }
            $events->dispatch('ready', $configuration);
            $status = $command->run($configuration, $arguments);
            $events->dispatch('completed', $configuration);
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
