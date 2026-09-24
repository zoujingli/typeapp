<?php

declare(strict_types=1);

namespace Type\Runtime;

use InvalidArgumentException;

/** 为命令提供显式选项、重复检查与严格的整数读取。 */
final class Arguments
{
    private array $values = [];

    private array $flags = [];

    /**
     * 按白名单解析完整命令参数，拒绝重复、未知、缺值及空值选项。
     *
     * @param list<string> $argv 包含程序名的命令参数。
     * @param list<string> $valueOptions 必须携带非空值的选项名，不含前导短横线。
     * @param list<string> $switches 不接受值的开关名。
     * @throws InvalidArgumentException 参数不满足上述约束。
     */
    public function __construct(array $argv, array $valueOptions, array $switches)
    {
        for ($index = 1; $index < count($argv); $index++) {
            $argument = (string) $argv[$index];
            if (!str_starts_with($argument, '--')) {
                throw new InvalidArgumentException('不支持位置参数：' . $argument);
            }

            $parts = explode('=', substr($argument, 2), 2);
            $name = (string) $parts[0];
            if (array_key_exists($name, $this->values) || array_key_exists($name, $this->flags)) {
                throw new InvalidArgumentException('选项不能重复：--' . $name);
            }

            if (in_array($name, $switches, true)) {
                if (count($parts) !== 1) {
                    throw new InvalidArgumentException('开关不能带值：--' . $name);
                }
                $this->flags[$name] = true;
                continue;
            }

            if (!in_array($name, $valueOptions, true)) {
                throw new InvalidArgumentException('未知选项：--' . $name);
            }

            $value = '';
            if (count($parts) === 2) {
                $value = (string) $parts[1];
            } else {
                $index++;
                if ($index >= count($argv) || str_starts_with((string) $argv[$index], '--')) {
                    throw new InvalidArgumentException('选项缺少值：--' . $name);
                }
                $value = (string) $argv[$index];
            }
            if ($value === '') {
                throw new InvalidArgumentException('选项值不能为空：--' . $name);
            }
            $this->values[$name] = $value;
        }
    }

    /** 检查调用者是否显式提供选项或开关，不读取默认值。 */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->flags) || array_key_exists($name, $this->values);
    }

    /** 读取值选项；仅未提供时返回默认文本，开关应使用 has()。 */
    public function text(string $name, string $default): string
    {
        return array_key_exists($name, $this->values) ? (string) $this->values[$name] : $default;
    }

    /**
     * 读取闭区间内的整数；显式输入和默认值接受相同校验。
     * @throws InvalidArgumentException 文本不是整数或超出上下限。
     */
    public function integer(string $name, int $default, int $minimum, int $maximum): int
    {
        $raw = $this->text($name, (string) $default);
        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException('选项必须是范围内的整数：--' . $name);
        }

        return (int) $value;
    }
}
