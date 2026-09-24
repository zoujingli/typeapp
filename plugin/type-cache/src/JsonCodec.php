<?php

declare(strict_types=1);

namespace Type\Cache;

use Closure;
use InvalidArgumentException;
use Throwable;

/** DTO 使用显式读写工厂；不会从载荷动态加载类。 */
final class JsonCodec implements Codec
{
    private string $format;
    private Closure $encode;
    private Closure $decode;

    /**
     * @param Closure(mixed): mixed $encode 接收业务值并返回 JSON 数据。
     * @param Closure(mixed): mixed $decode 接收解析后的 JSON 数据并返回业务值。
     */
    public function __construct(string $format, Closure $encode, Closure $decode)
    {
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$/D', $format)) {
            throw new InvalidArgumentException('codec 格式标识无效');
        }
        $this->format = $format;
        $this->encode = $encode;
        $this->decode = $decode;
    }

    /** 构造只接受 null、标量和数组的 JSON 编解码器；对象须使用显式工厂。 */
    public static function data(string $format = 'json-data-v1'): JsonCodec
    {
        $validate = static function (mixed $value): mixed {
            self::validateData($value, 0);
            return $value;
        };
        return new JsonCodec($format, $validate, $validate);
    }

    /** 返回与缓存载荷一起保存的格式身份，用于拒绝旧格式读取。 */
    public function format(): string
    {
        return $this->format;
    }

    /** 先执行显式转换，再校验 JSON 数据类型及深度；失败不写入缓存。 */
    public function encode(mixed $value): string
    {
        $data = ($this->encode)($value);
        self::validateData($data, 0);
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
    }

    /** 校验 JSON 后交给显式业务工厂；失败转换为 CacheException。 */
    public function decode(string $payload): mixed
    {
        try {
            $data = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
            self::validateData($data, 0);
            return ($this->decode)($data);
        } catch (Throwable $error) {
            throw new CacheException('缓存载荷与声明的 JSON DTO 格式不兼容', 0, $error);
        }
    }

    private static function validateData(mixed $value, int $depth): void
    {
        if ($depth > 24) {
            throw new InvalidArgumentException('JSON 缓存深度超限');
        }
        if (is_array($value)) {
            foreach ($value as $entry) {
                self::validateData($entry, $depth + 1);
            }
            return;
        }
        if ($value !== null && !is_string($value) && !is_int($value) && !is_bool($value) && (!is_float($value) || !is_finite($value))) {
            throw new InvalidArgumentException('JSON 缓存只接受标量、null 和数组，DTO 请配置显式 codec');
        }
    }
}
