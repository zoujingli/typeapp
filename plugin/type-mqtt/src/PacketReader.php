<?php

declare(strict_types=1);

namespace Type\Mqtt;

/** @internal 只在一个完整报文内推进游标，长度越界立即拒绝。 */
final class PacketReader
{
    private int $offset = 0;
    private string $forwardProperties = '';
    private int $expiryOffset = -1;

    /** 原始字节不经过 JSON 或文本转码。 */
    public function __construct(private string $bytes)
    {
    }

    /** 剩余字节数用于拒绝额外载荷。 */
    public function remaining(): int
    {
        return strlen($this->bytes) - $this->offset;
    }

    /** 读取网络序无符号整数，字节数只允许 1、2 或 4。 */
    public function integer(int $length): int
    {
        if (!in_array($length, [1, 2, 4], true) || $this->remaining() < $length) {
            throw new ProtocolError();
        }
        $value = 0;
        for ($index = 0; $index < $length; $index++) {
            $value = $value * 256 + ord($this->bytes[$this->offset++]);
        }
        return $value;
    }

    /** MQTT 5 的 Variable Byte Integer 必须采用最短编码且至多四字节。 */
    public function variable(): int
    {
        $value = 0;
        $multiplier = 1;
        for ($index = 0; $index < 4; $index++) {
            $byte = $this->integer(1);
            $value += ($byte & 127) * $multiplier;
            if (($byte & 128) === 0) {
                if ($index > 0 && $byte === 0) {
                    throw new ProtocolError();
                }
                return $value;
            }
            $multiplier *= 128;
        }
        throw new ProtocolError();
    }

    /** 读取有长度前缀的二进制字段；零字节是合法二进制内容。 */
    public function binary(): string
    {
        return $this->take($this->integer(2));
    }

    /** UTF-8 必须完整、无空字符和代理码点；不删除 BOM 或改写客户端标识。 */
    public function text(): string
    {
        $value = $this->binary();
        if (str_contains($value, "\0") || preg_match('//u', $value) !== 1) {
            throw new ProtocolError();
        }
        return $value;
    }

    /** 提取指定字节数并保留精确边界。 */
    public function take(int $length): string
    {
        if ($length < 0 || $this->remaining() < $length) {
            throw new ProtocolError();
        }
        $result = substr($this->bytes, $this->offset, $length);
        $this->offset += $length;
        return $result;
    }

    /**
     * @return array<int, mixed> 合法属性值；User Property 保留顺序和重复项。
     * @throws ProtocolError 属性不属于报文、重复单值属性或非法属性值。
     */
    public function properties(string $packet): array
    {
        $properties = new self($this->take($this->variable()));
        $this->forwardProperties = '';
        $this->expiryOffset = -1;
        $values = [];
        $allowed = match ($packet) {
            'connect' => [0x11, 0x21, 0x27, 0x22, 0x19, 0x17, 0x26, 0x15, 0x16],
            'will' => [0x18, 0x01, 0x02, 0x03, 0x08, 0x09, 0x26],
            'disconnect' => [0x11, 0x1f, 0x26],
            'publish' => [0x01, 0x02, 0x03, 0x08, 0x09, 0x23, 0x26],
            'subscribe' => [0x0b, 0x26],
            'unsubscribe' => [0x26],
            'puback', 'pubrec', 'pubrel', 'pubcomp' => [0x1f, 0x26],
            default => throw new \InvalidArgumentException('未知 MQTT 属性上下文'),
        };
        while ($properties->remaining() > 0) {
            $start = $properties->offset;
            $identifier = $properties->variable();
            if (!in_array($identifier, $allowed, true) || ($identifier !== 0x26 && array_key_exists($identifier, $values))) {
                throw new ProtocolError(0x82);
            }
            if ($identifier === 0x26) {
                $values[$identifier][] = [$properties->text(), $properties->text()];
            } elseif (in_array($identifier, [0x01, 0x17, 0x19], true)) {
                $valueByte = $properties->integer(1);
                if ($valueByte > 1) {
                    throw new ProtocolError(0x82);
                }
                $values[$identifier] = $valueByte;
            } elseif ($identifier === 0x0b) {
                $subscription = $properties->variable();
                if ($subscription === 0) {
                    throw new ProtocolError(0x82);
                }
                $values[$identifier] = $subscription;
            } elseif (in_array($identifier, [0x21, 0x22, 0x23], true)) {
                $valueShort = $properties->integer(2);
                if ($identifier === 0x21 && $valueShort === 0) {
                    throw new ProtocolError(0x82);
                }
                $values[$identifier] = $valueShort;
            } elseif (in_array($identifier, [0x02, 0x11, 0x18, 0x27], true)) {
                $valueLong = $properties->integer(4);
                if ($identifier === 0x27 && $valueLong === 0) {
                    throw new ProtocolError(0x82);
                }
                $values[$identifier] = $valueLong;
            } elseif (in_array($identifier, [0x09, 0x16], true)) {
                $values[$identifier] = $properties->binary();
            } else {
                $valueText = $properties->text();
                if ($identifier === 0x08 && ($valueText === '' || strpbrk($valueText, '+#') !== false)) {
                    throw new ProtocolError(0x82);
                }
                $values[$identifier] = $valueText;
            }
            if ($identifier !== 0x23 && $identifier !== 0x18) {
                if ($identifier === 0x02) {
                    $this->expiryOffset = strlen($this->forwardProperties) + 1;
                }
                $this->forwardProperties .= substr($properties->bytes, $start, $properties->offset - $start);
            }
        }
        if ($packet === 'connect' && isset($values[0x16]) && !isset($values[0x15])) {
            throw new ProtocolError(0x82);
        }
        return $values;
    }

    /** 返回原始顺序属性并剥除 Topic Alias 和遗嘱调度属性；可只改写已存在的四字节消息期限。 */
    public function forwardedProperties(?int $expiry = null): string
    {
        if ($expiry !== null && $this->expiryOffset >= 0) {
            return substr_replace($this->forwardProperties, pack('N', $expiry), $this->expiryOffset, 4);
        }
        return $this->forwardProperties;
    }

    /** 生成合法最短 Remaining Length/Property Length。 */
    public static function encodeVariable(int $value): string
    {
        if ($value < 0 || $value > 268435455) {
            throw new \InvalidArgumentException('MQTT 变长整数越界');
        }
        $result = '';
        do {
            $byte = $value % 128;
            $value = intdiv($value, 128);
            $result .= chr($value > 0 ? ($byte | 128) : $byte);
        } while ($value > 0);
        return $result;
    }
}
