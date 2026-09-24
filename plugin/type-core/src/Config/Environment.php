<?php

declare(strict_types=1);

namespace Type\Core\Config;

use InvalidArgumentException;
use RuntimeException;

/** 只读环境配置；dotenv 是数据，不会写回进程环境或执行其中的表达式。 */
final class Environment
{
    private const MAXIMUM_FILE_BYTES = 1048576;

    /** @var array<string, string> */
    private array $values;

    /** @var array<string, array<string, mixed>> 只保留已消费声明的类型与来源，不保留其值。 */
    private array $declarations = [];
    private bool $processEnvironment;

    private function __construct(#[\SensitiveParameter] array $values, bool $processEnvironment = true)
    {
        $this->values = $values;
        $this->processEnvironment = $processEnvironment;
    }

    /**
     * null 表示仅使用进程环境；显式文件不存在时使用空 dotenv 快照。
     *
     * @throws RuntimeException 文件不可读、过大或格式不合法；错误不包含配置值。
     */
    public static function load(?string $file = null): self
    {
        if ($file === null) {
            return new self([]);
        }
        if ($file === '' || str_contains($file, "\0") || str_contains($file, '://')) {
            throw new RuntimeException('dotenv 必须是本地文件路径');
        }
        if (!file_exists($file) && !is_link($file)) {
            return new self([]);
        }
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException('dotenv 文件不可读取');
        }
        $contents = @file_get_contents($file, false, null, 0, self::MAXIMUM_FILE_BYTES + 1);
        if ($contents === false || strlen($contents) > self::MAXIMUM_FILE_BYTES) {
            throw new RuntimeException('dotenv 文件不可读取或超过 1 MiB 限制');
        }

        return self::parse($contents);
    }

    /**
     * 解析已由文件所有者取得的有界快照；关闭进程覆盖只用于检查文件自身，不改变全局环境。
     * @throws RuntimeException 文本过大或格式非法；错误不包含配置值。
     */
    public static function parse(#[\SensitiveParameter] string $contents, bool $processEnvironment = true): self
    {
        if (strlen($contents) > self::MAXIMUM_FILE_BYTES) {
            throw new RuntimeException('dotenv 超过 1 MiB 限制');
        }
        $values = [];
        $lines = preg_split('/\r\n|\r|\n/', $contents);
        if ($lines === false) {
            throw new RuntimeException('dotenv 无法拆分物理行');
        }
        foreach ($lines as $lineIndex => $lineText) {
            if (str_contains((string) $lineText, "\0") || preg_match('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/', (string) $lineText) === 1) {
                throw new RuntimeException('dotenv 第 ' . ((int) $lineIndex + 1) . ' 行格式含非法控制字符');
            }
            $line = ltrim((string) $lineText, " \t");
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (strlen($line) > 16384 || str_contains($line, "\0")
                || preg_match('/^(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_]*)[ \t]*=[ \t]*(.*)$/D', $line, $matches) !== 1) {
                throw new RuntimeException('dotenv 第 ' . ((int) $lineIndex + 1) . ' 行格式无效');
            }
            $key = (string) $matches[1];
            if (array_key_exists($key, $values)) {
                throw new RuntimeException('dotenv 第 ' . ((int) $lineIndex + 1) . ' 行重复声明键：' . $key);
            }
            if (count($values) >= 1024) {
                throw new RuntimeException('dotenv 键数量超过 1024 项限制');
            }
            $values[$key] = self::parseValue((string) $matches[2], $key, (int) $lineIndex + 1);
        }

        return new self($values, $processEnvironment);
    }

    private static function parseValue(#[\SensitiveParameter] string $raw, string $key, int $line): string
    {
        if ($raw === '') {
            return '';
        }
        $quote = $raw[0] === "'" || $raw[0] === '"' ? $raw[0] : '';
        $start = $quote === '' ? 0 : 1;
        $length = strlen($raw);
        $value = '';
        $pendingWhitespace = '';
        for ($index = $start; $index < $length; $index++) {
            $character = $raw[$index];
            if ($quote !== '' && $character === $quote) {
                $tail = trim(substr($raw, $index + 1));
                if ($tail !== '' && !str_starts_with($tail, '#')) {
                    throw new RuntimeException('dotenv 第 ' . $line . ' 行引号后有非法内容，键：' . $key);
                }

                return $value;
            }
            if ($quote === '' && $character === '#' && ($index === 0 || $raw[$index - 1] === ' ' || $raw[$index - 1] === "\t")) {
                return $value;
            }
            if ($quote === '' && ($character === ' ' || $character === "\t")) {
                $pendingWhitespace .= $character;
                continue;
            }
            $value .= $pendingWhitespace;
            $pendingWhitespace = '';
            if ($character === '\\') {
                $index++;
                if ($index >= $length) {
                    throw new RuntimeException('dotenv 第 ' . $line . ' 行转义不完整，键：' . $key);
                }
                $escaped = $raw[$index];
                if ($quote === "'") {
                    if ($escaped !== "'" && $escaped !== '\\') {
                        $value .= '\\' . $escaped;
                    } else {
                        $value .= $escaped;
                    }
                } elseif ($escaped === 'n' && $quote === '"') {
                    $value .= "\n";
                } elseif ($escaped === 'r' && $quote === '"') {
                    $value .= "\r";
                } elseif ($escaped === 't' && $quote === '"') {
                    $value .= "\t";
                } elseif (in_array($escaped, ['\\', '"', "'", '$', '#', ' ', "\t"], true)) {
                    $value .= $escaped;
                } else {
                    throw new RuntimeException('dotenv 第 ' . $line . ' 行不支持该转义，键：' . $key);
                }
                continue;
            }
            if ($quote !== "'" && ($character === '`' || ($character === '$' && $index + 1 < $length
                && preg_match('/[A-Za-z_({]/', $raw[$index + 1]) === 1))) {
                throw new RuntimeException('dotenv 第 ' . $line . ' 行不支持插值或命令表达式，键：' . $key);
            }
            if ($quote === '' && ($character === "'" || $character === '"')) {
                throw new RuntimeException('dotenv 第 ' . $line . ' 行引号必须包围整个值，键：' . $key);
            }
            $value .= $character;
        }
        if ($quote !== '') {
            throw new RuntimeException('dotenv 第 ' . $line . ' 行引号未闭合，键：' . $key);
        }

        return $value;
    }

    /**
     * default 只接受 null 或标量。null 默认值在缺失时返回 null，有值时返回原始字符串。
     * 其余默认值决定 bool/int/float/string 转换；存在的空字符串不会回退。
     *
     * @throws InvalidArgumentException 键、默认类型或值的转换无效；错误只标明键。
     */
    public function get(string $key, #[\SensitiveParameter] mixed $default = null): mixed
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) !== 1) {
            throw new InvalidArgumentException('环境配置键无效');
        }
        if ($default !== null && !is_scalar($default)) {
            throw new InvalidArgumentException('环境默认值必须是标量或 null：' . $key);
        }
        if (is_float($default) && !is_finite($default)) {
            throw new InvalidArgumentException('环境默认浮点值必须有限：' . $key);
        }
        $processValue = $this->processEnvironment ? getenv($key) : false;
        $this->declarations[$key] = [
            'type' => $default === null ? 'nullable-string' : get_debug_type($default),
            'source' => $processValue !== false ? 'process' : (array_key_exists($key, $this->values) ? 'file' : 'default'),
            'file_present' => array_key_exists($key, $this->values),
            'read_only' => $processValue !== false,
            'reason' => $processValue !== false ? 'process_environment_requires_operator_restart' : null,
        ];
        if ($processValue === false && !array_key_exists($key, $this->values)) {
            return $default;
        }
        $text = $processValue === false ? (string) $this->values[$key] : (string) $processValue;
        if ($default === null || is_string($default)) {
            return $text;
        }
        if (is_bool($default)) {
            $normalized = strtolower($text);
            if (in_array($normalized, ['true', 'yes', 'on', '1'], true)) {
                return true;
            }
            if (in_array($normalized, ['false', 'no', 'off', '0'], true)) {
                return false;
            }
        } elseif (is_int($default)) {
            if (preg_match('/^[+-]?(?:0|[1-9][0-9]*)$/D', $text) === 1) {
                $integer = filter_var($text, FILTER_VALIDATE_INT);
                if ($integer !== false) {
                    return (int) $integer;
                }
            }
        } elseif (is_float($default)) {
            if (preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/D', $text) === 1) {
                $number = (float) $text;
                if (is_finite($number)) {
                    return $number;
                }
            }
        }

        throw new InvalidArgumentException('环境配置值无法转换为默认值类型：' . $key);
    }

    /** @return array{fields: array<string, array<string, mixed>>, undeclared: list<string>} 不返回默认值或秘密。 */
    public function describe(): array
    {
        $fields = $this->declarations;
        ksort($fields, SORT_STRING);
        $undeclared = array_values(array_diff(array_keys($this->values), array_keys($fields)));
        sort($undeclared, SORT_STRING);
        return ['fields' => $fields, 'undeclared' => $undeclared];
    }

    /** 仅展示环境键名，隐藏所有值，避免调试输出泄露秘密。 */
    public function __debugInfo(): array
    {
        return ['keys' => array_keys($this->values), 'values' => '[REDACTED]'];
    }

    /** @throws RuntimeException 环境快照含启动秘密，禁止序列化保存或转移。 */
    public function __serialize(): array
    {
        throw new RuntimeException('环境配置不允许序列化');
    }

    /** @throws RuntimeException 环境快照必须由启动配置加载，禁止反序列化恢复。 */
    public function __unserialize(#[\SensitiveParameter] array $data): void
    {
        throw new RuntimeException('环境配置不允许反序列化');
    }
}
