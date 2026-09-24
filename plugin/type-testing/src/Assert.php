<?php

declare(strict_types=1);

namespace Type\Testing;

/** 在公开行为边界执行严格断言，失败统一交给 Suite 收集。 */
final class Assert
{
    /** @throws AssertionFailed 条件为 false；说明应避免包含凭据或业务秘密。 */
    public static function true(bool $condition, string $message = '断言条件不成立'): void
    {
        if (!$condition) {
            throw new AssertionFailed($message);
        }
    }
    /** @throws AssertionFailed 类型或值不完全相同；不做数字字符串等隐式转换。 */
    public static function same(mixed $expected, mixed $actual, string $message = '实际值与预期值或类型不一致'): void
    {
        self::true($expected === $actual, $message);
    }
    /**
     * 执行一次操作并返回符合预期类型的真实异常，便于进一步检查错误码。
     * @param \Closure(): mixed $operation 零参数执行并观察指定异常。
     * @param class-string<\Throwable> $class 允许的异常类或其父类。
     * @throws AssertionFailed 没有异常或异常类型不匹配。
     */
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
