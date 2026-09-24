<?php

declare(strict_types=1);

namespace Type\Core;

use InvalidArgumentException;

/** 启动时取得的字符串配置快照，不在构建期读取秘密值。 */
final class Configuration
{
    private array $values;

    /**
     * 建立启动时的字符串快照，不接受对象或隐式类型转换。
     * @param array<string, string> $values 已解析的配置键值。
     */
    public function __construct(array $values)
    {
        $snapshot = [];
        foreach ($values as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new InvalidArgumentException('当前配置快照只接受字符串键和值');
            }
            $snapshot[$key] = (string) $value;
        }
        $this->values = $snapshot;
    }

    /**
     * 按声明读取进程环境，仅变量不存在时使用默认值，保留显式空字符串。
     * @param array<string, array{env: string, default: string}> $definitions 环境映射。
     */
    public static function fromEnvironment(array $definitions): Configuration
    {
        $values = [];
        foreach ($definitions as $key => $definition) {
            $value = getenv((string) $definition['env']);
            $values[$key] = $value === false ? (string) $definition['default'] : (string) $value;
        }

        return new Configuration($values);
    }

    /**
     * 按完整键名读取字符串，不解析点路径。
     * @throws InvalidArgumentException 配置键未声明。
     */
    public function text(string $key): string
    {
        if (!array_key_exists($key, $this->values)) {
            throw new InvalidArgumentException('配置项不存在：' . $key);
        }

        return (string) $this->values[$key];
    }
}
