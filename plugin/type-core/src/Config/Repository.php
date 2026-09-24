<?php

declare(strict_types=1);

namespace Type\Core\Config;

use InvalidArgumentException;
use RuntimeException;

/** 只保存数组、标量和 null 的深层配置快照；不共享调用者的引用或对象。 */
final class Repository
{
    private array $values;

    /** @throws InvalidArgumentException 配置包含对象、资源、非法键或超过 64 层。 */
    public function __construct(#[\SensitiveParameter] array $values)
    {
        $this->values = self::snapshot($values, 0);
    }

    /** 键存在且值为 null 时仍返回 true。 */
    public function has(string $key): bool
    {
        return $this->lookup($key)['exists'];
    }

    /**
     * default 只用于不存在的路径，不能覆盖已声明的 null。
     *
     * @throws InvalidArgumentException 点路径格式无效。
     */
    public function get(string $key, #[\SensitiveParameter] mixed $default = null): mixed
    {
        $result = $this->lookup($key);

        return $result['exists'] ? $result['value'] : $default;
    }

    /** @throws InvalidArgumentException 路径缺失或值不是字符串。 */
    public function text(string $key): string
    {
        $value = $this->required($key);
        if (!is_string($value)) {
            throw new InvalidArgumentException('配置项必须是字符串：' . $key);
        }

        return (string) $value;
    }

    /** @throws InvalidArgumentException 路径缺失或值不是整数；不做隐式转换。 */
    public function integer(string $key): int
    {
        $value = $this->required($key);
        if (!is_int($value)) {
            throw new InvalidArgumentException('配置项必须是整数：' . $key);
        }

        return (int) $value;
    }

    /** @throws InvalidArgumentException 路径缺失或值不是布尔值。 */
    public function boolean(string $key): bool
    {
        $value = $this->required($key);
        if (!is_bool($value)) {
            throw new InvalidArgumentException('配置项必须是布尔值：' . $key);
        }

        return (bool) $value;
    }

    /** @throws InvalidArgumentException 路径缺失或值不是数组。 */
    public function array(string $key): array
    {
        $value = $this->required($key);
        if (!is_array($value)) {
            throw new InvalidArgumentException('配置项必须是数组：' . $key);
        }

        return $value;
    }

    private function required(string $key): mixed
    {
        $result = $this->lookup($key);
        if (!$result['exists']) {
            throw new InvalidArgumentException('配置项不存在：' . $key);
        }

        return $result['value'];
    }

    /** @return array{exists: bool, value: mixed} */
    private function lookup(string $key): array
    {
        if ($key === '' || strlen($key) > 1024 || str_contains($key, "\0")) {
            throw new InvalidArgumentException('配置点路径无效');
        }
        $segments = explode('.', $key);
        if (count($segments) > 64 || in_array('', $segments, true)) {
            throw new InvalidArgumentException('配置点路径含空段或超过 64 层');
        }
        $cursor = $this->values;
        $last = count($segments) - 1;
        foreach ($segments as $segmentIndex => $segmentName) {
            if (!array_key_exists((string) $segmentName, $cursor)) {
                return ['exists' => false, 'value' => null];
            }
            $child = $cursor[(string) $segmentName];
            if ($segmentIndex === $last) {
                return ['exists' => true, 'value' => $child];
            }
            if (!is_array($child)) {
                return ['exists' => false, 'value' => null];
            }
            $cursor = $child;
        }

        return ['exists' => false, 'value' => null];
    }

    private static function snapshot(#[\SensitiveParameter] array $values, int $depth): array
    {
        if ($depth >= 64) {
            throw new InvalidArgumentException('配置数组超过 64 层或包含循环引用');
        }
        $result = [];
        foreach ($values as $entryKey => $entryValue) {
            if (is_string($entryKey) && ($entryKey === '' || str_contains($entryKey, '.') || str_contains($entryKey, "\0"))) {
                throw new InvalidArgumentException('配置数组键不能为空或含点、空字节');
            }
            if (is_array($entryValue)) {
                $result[$entryKey] = self::snapshot($entryValue, $depth + 1);
            } elseif ($entryValue === null || is_scalar($entryValue)) {
                if (is_float($entryValue) && !is_finite($entryValue)) {
                    throw new InvalidArgumentException('配置浮点值必须有限');
                }
                $result[$entryKey] = $entryValue;
            } else {
                throw new InvalidArgumentException('配置只接受数组、标量和 null');
            }
        }

        return $result;
    }

    /** 仅展示配置段名称，所有值脱敏，避免调试输出泄露秘密。 */
    public function __debugInfo(): array
    {
        return ['sections' => array_keys($this->values), 'values' => '[REDACTED]'];
    }

    /** @throws RuntimeException 配置快照可能含秘密，禁止序列化保存或转移。 */
    public function __serialize(): array
    {
        throw new RuntimeException('配置快照不允许序列化');
    }

    /** @throws RuntimeException 配置必须由启动声明建立，禁止反序列化恢复。 */
    public function __unserialize(#[\SensitiveParameter] array $data): void
    {
        throw new RuntimeException('配置快照不允许反序列化');
    }
}
