<?php

declare(strict_types=1);

namespace Type\Log;

use Psr\Log\InvalidArgumentException;

/** 将 PSR-3 八个标准级别映射为可比较的权重。 */
final class Level
{
    /**
     * 严格接受标准小写级别，不隐式转换数字或对象。
     *
     * @throws InvalidArgumentException 级别不是有效的 PSR-3 字符串。
     */
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
