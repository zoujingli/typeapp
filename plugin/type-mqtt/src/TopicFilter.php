<?php

declare(strict_types=1);

namespace Type\Mqtt;

/** @internal Broker 与持久队列共用的过滤器语义；不拥有连接、权限或存储。 */
final class TopicFilter
{
    /** 校验普通过滤器，保留空层与原始 UTF-8。 */
    public static function validate(string $filter): void
    {
        if ($filter === '' || strlen($filter) > 65535 || str_contains($filter, "\0") || preg_match('//u', $filter) !== 1) {
            throw new ProtocolError(0x82);
        }
        $levels = explode('/', $filter);
        foreach ($levels as $position => $level) {
            if ((str_contains($level, '+') && $level !== '+')
                || (str_contains($level, '#') && ($level !== '#' || $position !== count($levels) - 1))) {
                throw new ProtocolError(0x82);
            }
        }
    }

    /** 输入须已经校验；+ 匹配一个含空值的层，末尾 # 也匹配零层；首层通配不匹配 $ Topic。 */
    public static function matches(string $filter, string $topic): bool
    {
        if ($topic[0] === '$' && ($filter[0] === '+' || $filter[0] === '#')) {
            return false;
        }
        $levels = explode('/', $topic);
        $filters = explode('/', $filter);
        foreach ($filters as $position => $level) {
            if ($level === '#') {
                return true;
            }
            if (!isset($levels[$position]) || ($level !== '+' && $level !== $levels[$position])) {
                return false;
            }
        }
        return count($filters) === count($levels);
    }

    /** 校验完整共享身份并只去除一次外层；普通订阅返回原过滤器。 */
    public static function actual(string $filter): string
    {
        self::validate($filter);
        if (!str_starts_with($filter, '$share/')) {
            return $filter;
        }
        $parts = explode('/', $filter, 3);
        if (count($parts) !== 3 || $parts[1] === '' || strpbrk($parts[1], '+#') !== false || $parts[2] === '') {
            throw new ProtocolError(0x82);
        }
        self::validate($parts[2]);
        return $parts[2];
    }

    /** @return array{options:int,identifier:int} 旧安装的整数选项仍可恢复，缺省没有订阅标识。 */
    public static function subscription(mixed $value): array
    {
        return is_int($value) ? ['options' => $value, 'identifier' => 0] : $value;
    }

    /**
     * 同一接收者的重叠订阅合并一份交付；先排除 No Local，再取最高 QoS、任一 RAP 和每个匹配标识。
     * @param array<string,int|array{options:int,identifier:int}> $subscriptions 已验证且获过滤器授权的订阅。
     * @return array{qos:int,retain:bool,identifiers:list<int>} qos=-1 表示没有匹配；相同标识的多次出现不得去重。
     */
    public static function select(array $subscriptions, string $topic, bool $sameClient): array
    {
        $qos = -1;
        $retain = false;
        $identifiers = [];
        foreach ($subscriptions as $key => $value) {
            $subscription = self::subscription($value);
            $options = $subscription['options'];
            if (str_starts_with(substr($key, 2), '$share/') || ($sameClient && ($options & 4) !== 0) || !self::matches(substr($key, 2), $topic)) {
                continue;
            }
            $qos = max($qos, $options & 3);
            $retain = $retain || ($options & 8) !== 0;
            if ($subscription['identifier'] > 0) {
                $identifiers[] = $subscription['identifier'];
            }
        }
        return ['qos' => $qos, 'retain' => $retain, 'identifiers' => $identifiers];
    }
}
