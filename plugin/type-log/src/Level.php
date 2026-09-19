<?php

declare(strict_types=1);

namespace Type\Log;

use Psr\Log\InvalidArgumentException;

final class Level
{
    public static function weight(mixed $level): int
    {
        $levels = ['debug' => 100, 'info' => 200, 'notice' => 250, 'warning' => 300,
            'error' => 400, 'critical' => 500, 'alert' => 550, 'emergency' => 600];
        if (!is_string($level) || !isset($levels[$level])) {
            throw new InvalidArgumentException('日志级别必须是 PSR-3 定义的级别');
        }
        return $levels[$level];
    }
}
