<?php

declare(strict_types=1);

namespace Type\Core;

use InvalidArgumentException;

/** 启动时取得的字符串配置快照，不在构建期读取秘密值。 */
final class Configuration
{
    private array $values;

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

    public static function fromEnvironment(array $definitions): Configuration
    {
        $values = [];
        foreach ($definitions as $key => $definition) {
            $value = getenv((string) $definition['env']);
            $values[$key] = $value === false ? (string) $definition['default'] : (string) $value;
        }

        return new Configuration($values);
    }

    public function text(string $key): string
    {
        if (!array_key_exists($key, $this->values)) {
            throw new InvalidArgumentException('配置项不存在：' . $key);
        }

        return (string) $this->values[$key];
    }
}
