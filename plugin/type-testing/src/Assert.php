<?php

declare(strict_types=1);

namespace Type\Testing;

final class Assert
{
    public static function true(bool $condition, string $message = '断言条件不成立'): void
    {
        if (!$condition) {
            throw new AssertionFailed($message);
        }
    }
    public static function same(mixed $expected, mixed $actual, string $message = '实际值与预期值或类型不一致'): void
    {
        self::true($expected === $actual, $message);
    }
    /** @param \Closure(): mixed $operation 零参数执行并观察指定异常。 */
    public static function throws(\Closure $operation, string $class, string $message = '没有抛出预期异常'): \Throwable
    {
        try {
            $operation();
        } catch (\Throwable $error) {
            if (is_a($error, $class)) {
                return $error;
            } throw new AssertionFailed($message . '：' . get_class($error), 0, $error);
        }
        throw new AssertionFailed($message);
    }
}
