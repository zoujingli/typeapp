<?php

declare(strict_types=1);

namespace Type\Cache;

/** @internal 只检查 PHP 序列化语法和类身份，检查阶段不构造对象。 */
final class SerializedPayload
{
    private string $payload;
    private array $classes;
    private int $position = 0;
    private int $nodes = 0;

    /**
     * 接收已取得的载荷和受信类型列表，本阶段不调用反序列化钩子。
     *
     * @param list<class-string> $classes
     */
    public function __construct(string $payload, array $classes)
    {
        $this->payload = $payload;
        $this->classes = $classes;
    }

    /**
     * 验证完整载荷、深度、节点数及对象类型；尾随数据或未知类型明确拒绝。
     *
     * @throws CacheException 载荷不满足受信序列化格式。
     */
    public function verify(): void
    {
        $this->value(0);
        if ($this->position !== strlen($this->payload)) {
            throw new CacheException('序列化载荷包含尾随数据');
        }
    }

    private function value(int $depth): void
    {
        if ($depth > 64 || ++$this->nodes > 100000 || $this->position >= strlen($this->payload)) {
            throw new CacheException('序列化载荷超出限制');
        }
        $type = $this->payload[$this->position++];
        if ($type === 'N') {
            $this->literal(';');
            return;
        }
        $this->literal(':');
        if (in_array($type, ['b', 'i', 'd', 'R', 'r'], true)) {
            $end = strpos($this->payload, ';', $this->position);
            if ($end === false || $end - $this->position > 128) {
                throw new CacheException('序列化标量无效');
            }
            $token = substr($this->payload, $this->position, $end - $this->position);
            $valid = $type === 'b' ? in_array($token, ['0', '1'], true)
                : ($type === 'd' ? preg_match('/^(?:NAN|-?INF|[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[Ee][+-]?\d+)?)$/D', $token)
                    : preg_match($type === 'i' ? '/^-?\d+$/D' : '/^[1-9]\d*$/D', $token));
            if (!$valid) {
                throw new CacheException('序列化标量格式无效');
            }
            $this->position = $end + 1;
            return;
        }
        if ($type === 's' || $type === 'E') {
            $text = $this->sizedString();
            $this->literal(';');
            if ($type === 'E') {
                $this->allowed(explode(':', $text, 2)[0]);
            }
            return;
        }
        if ($type === 'O' || $type === 'C') {
            $class = $this->sizedString();
            $this->allowed($class);
            $this->literal(':');
            $count = $this->number();
            $this->literal(':');
            $this->literal('{');
            if ($type === 'C') {
                if ($count > strlen($this->payload) - $this->position) {
                    throw new CacheException('自定义对象载荷长度错误');
                }
                $this->position += $count;
            } else {
                if ($count > 50000) {
                    throw new CacheException('对象字段数量超限');
                }
                for ($index = 0; $index < $count * 2; $index++) {
                    $this->value($depth + 1);
                }
            }
            $this->literal('}');
            return;
        }
        if ($type === 'a') {
            $count = $this->number();
            $this->literal(':');
            $this->literal('{');
            if ($count > 50000) {
                throw new CacheException('序列化数组数量超限');
            }
            for ($index = 0; $index < $count * 2; $index++) {
                $this->value($depth + 1);
            }
            $this->literal('}');
            return;
        }
        throw new CacheException('未知序列化类型');
    }

    private function sizedString(): string
    {
        $length = $this->number();
        $this->literal(':');
        $this->literal('"');
        if ($length > strlen($this->payload) - $this->position) {
            throw new CacheException('序列化字符串长度错误');
        }
        $value = substr($this->payload, $this->position, $length);
        $this->position += $length;
        $this->literal('"');
        return $value;
    }

    private function number(): int
    {
        $start = $this->position;
        while ($this->position < strlen($this->payload) && ctype_digit($this->payload[$this->position])) {
            $this->position++;
        }
        $digits = substr($this->payload, $start, $this->position - $start);
        if ($digits === '' || strlen($digits) > 8) {
            throw new CacheException('序列化长度无效');
        }
        return (int) $digits;
    }

    private function literal(string $value): void
    {
        if (($this->payload[$this->position] ?? '') !== $value) {
            throw new CacheException('序列化语法无效');
        }
        $this->position++;
    }

    private function allowed(string $class): void
    {
        if (!in_array($class, $this->classes, true) || !class_exists($class, false)) {
            throw new CacheException('序列化对象类型未登记或未加载');
        }
    }
}
