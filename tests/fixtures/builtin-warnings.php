<?php

declare(strict_types=1);

/** 对照普通内置调用的完整警告消息、异常传播及恢复后的后续调用。 */
final class BuiltinWarnings
{
    /** @return array{value: mixed, warnings: list<array{int, string}>} */
    public static function collect(Closure $operation): array
    {
        $warnings = [];
        set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$warnings): bool {
            $warnings[] = [$severity, $message];
            return true;
        });
        try {
            return ['value' => $operation(), 'warnings' => $warnings];
        } finally {
            restore_error_handler();
        }
    }

    /** @return array{type: string, severity: int, message: string} */
    public static function throwing(): array
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            gzdecode('not-gzip');
            throw new LogicException('损坏的 gzip 没有抛出异常');
        } catch (ErrorException $error) {
            return ['type' => get_class($error), 'severity' => $error->getSeverity(), 'message' => $error->getMessage()];
        } finally {
            restore_error_handler();
        }
    }

    /** @return array<string, mixed> 不归一消息前缀，也不将异常策略安装到生产应用。 */
    public static function inspect(): array
    {
        return [
            'direct' => self::collect(static fn (): string|false => gzdecode('not-gzip')),
            'dynamic' => self::collect(static fn (): mixed => call_user_func('gzdecode', 'not-gzip')),
            'inflate' => self::collect(static fn (): string|false => gzinflate('not-deflate')),
            'unpack' => self::collect(static fn (): array|false => unpack('N', "\x01")),
            'hex' => self::collect(static fn (): string|false => hex2bin('xy')),
            'user' => self::collect(static fn (): bool => trigger_error('business warning', E_USER_WARNING)),
            'exception' => self::throwing(),
            'after' => self::collect(static fn (): string|false => gzdecode('not-gzip')),
            'valid' => gzdecode(gzencode('valid')),
        ];
    }
}

/** 每次迭代恢复错误处理器，异常后仍按相同规则调用原生函数。 */
function main(): void
{
    for ($iteration = 0; $iteration < 2; $iteration++) {
        echo json_encode(BuiltinWarnings::inspect(), JSON_THROW_ON_ERROR), "\n";
    }
}
